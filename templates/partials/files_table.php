<?php
/**
 * @var string $mode 'user'|'admin'
 * @var string $panelTitle
 * @var string|null $panelMeta Optional mono label (e.g. storage used / quota)
 * @var list<array<string, mixed>> $files
 * @var string $sort
 * @var string $dir ASC|DESC
 * @var string $redirect
 * @var bool $cloudConnected
 * @var array{folder?:string, conflict?:string} $prefs
 * @var string $q Search query
 * @var int $pagerTotal
 * @var int $pagerPage
 * @var int $pagerTotalPages
 * @var int $pagerPerPage
 * @var string $pagerPath
 * @var array<string, scalar|null> $pagerQuery
 */
$mode = $mode ?? 'user';
$isAdmin = $mode === 'admin';
$panelTitle = $panelTitle ?? ($isAdmin ? 'All files' : 'Your files');
$panelMeta = isset($panelMeta) && is_string($panelMeta) && $panelMeta !== '' ? $panelMeta : null;
$redirect = $redirect ?? ($isAdmin ? '/admin/files.php' : '/files.php');
$cloudConnected = $cloudConnected ?? false;
$prefs = $prefs ?? ['folder' => '', 'conflict' => 'rename'];
$sort = $sort ?? 'date';
$dir = $dir ?? 'DESC';
$q = $q ?? '';
$highlightFileId = isset($highlightFileId) ? (int) $highlightFileId : 0;
$historyBasePath = $isAdmin ? '/admin/history.php' : '/history.php';
$historyUserId = $isAdmin && isset($filterUserId) && (int) $filterUserId > 0 ? (int) $filterUserId : null;
$pagerTotal = (int) ($pagerTotal ?? count($files));
$pagerPage = (int) ($pagerPage ?? 1);
$pagerTotalPages = (int) ($pagerTotalPages ?? 1);
$pagerPerPage = (int) ($pagerPerPage ?? \App\Pager::DEFAULT_PER_PAGE);
$truncateUrl = static function (string $url, int $max = 48): string {
    if (strlen($url) <= $max) {
        return $url;
    }

    return substr($url, 0, $max - 1) . '…';
};
$parts = parse_url($redirect);
$pagerPath = $pagerPath ?? (is_array($parts) && isset($parts['path']) ? (string) $parts['path'] : $redirect);
$pagerQuery = $pagerQuery ?? [];
if ($pagerQuery === []) {
    if (is_array($parts) && !empty($parts['query'])) {
        parse_str((string) $parts['query'], $pagerQuery);
    }
    $pagerQuery['sort'] = $sort;
    $pagerQuery['dir'] = strtolower($dir);
    if ($q !== '') {
        $pagerQuery['q'] = $q;
    }
}

$sortUrl = static function (string $col) use ($pagerPath, $pagerQuery, $sort, $dir): string {
    $nextDir = ($sort === $col && $dir === 'ASC') ? 'DESC' : 'ASC';
    if ($sort !== $col) {
        $nextDir = $col === 'date' ? 'DESC' : 'ASC';
    }

    return \App\Pager::url($pagerPath, $pagerQuery, [
        'sort' => $col,
        'dir' => strtolower($nextDir),
        'page' => null,
    ]);
};

$sortMark = static function (string $col) use ($sort, $dir): string {
    if ($sort !== $col) {
        return '';
    }
    return $dir === 'ASC' ? ' ↑' : ' ↓';
};
?>
<div class="card-header files-card-header">
    <div class="d-flex align-items-center flex-wrap gap-2 min-w-0">
        <h2 class="card-title mb-0"><?= e($panelTitle) ?></h2>
        <?php if ($panelMeta !== null): ?>
            <span class="text-secondary small mono"><?= e($panelMeta) ?></span>
        <?php endif; ?>
    </div>
    <div class="files-panel-tools">
        <form method="get" action="<?= e($pagerPath) ?>" class="d-flex align-items-center gap-2">
            <?php foreach ($pagerQuery as $key => $value): ?>
                <?php if ($key === 'q' || $key === 'page' || $value === null || $value === '') {
                    continue;
                } ?>
                <input type="hidden" name="<?= e((string) $key) ?>" value="<?= e((string) $value) ?>">
            <?php endforeach; ?>
            <label class="visually-hidden" for="files-search">Search files</label>
            <input type="search" name="q" id="files-search" class="form-control form-control-sm files-search"
                   placeholder="Search…"
                   value="<?= e($q) ?>"
                   autocomplete="off">
        </form>
        <span class="files-count"><?= e((string) $pagerTotal) ?></span>
    </div>
</div>

<?php if ($files !== []): ?>
<div class="files-selection-bar" data-files-bulk>
    <span class="files-selection-label" data-files-bulk-count>0 selected</span>
    <button type="button" class="btn btn-sm btn-outline-secondary" data-bulk-revoke disabled>Disable links</button>
    <button type="button" class="btn btn-sm btn-outline-danger" data-bulk-delete disabled>Delete</button>
</div>

<div class="files-table-scroll">
    <table class="table card-table table-striped align-middle mb-0 files-table" data-files-table>
        <thead>
        <tr>
            <th class="files-col-check">
                <input type="checkbox" class="form-check-input" data-files-select-all aria-label="Select all">
            </th>
            <th>
                <a class="files-sort-link" href="<?= e($sortUrl('name')) ?>">File<?= e($sortMark('name')) ?></a>
            </th>
            <?php if ($isAdmin): ?>
                <th>
                    <a class="files-sort-link" href="<?= e($sortUrl('owner')) ?>">Owner<?= e($sortMark('owner')) ?></a>
                </th>
            <?php endif; ?>
            <th class="files-col-size">
                <a class="files-sort-link" href="<?= e($sortUrl('size')) ?>">Size<?= e($sortMark('size')) ?></a>
            </th>
            <th class="files-col-date">
                <a class="files-sort-link" href="<?= e($sortUrl('date')) ?>">Date<?= e($sortMark('date')) ?></a>
            </th>
            <th class="files-col-share">Share</th>
            <th class="files-col-actions"></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($files as $file): ?>
            <?php
            $fileId = (int) $file['id'];
            $name = (string) $file['original_name'];
            $shareToken = !empty($file['share_token']) ? (string) $file['share_token'] : null;
            $shareUrl = $shareToken !== null ? \App\ShareService::publicUrl($shareToken) : null;
            $shareExpiresAt = !empty($file['share_expires_at']) ? (string) $file['share_expires_at'] : null;
            $shareMaxDownloads = isset($file['share_max_downloads']) && $file['share_max_downloads'] !== null && $file['share_max_downloads'] !== ''
                ? (int) $file['share_max_downloads']
                : null;
            $shareDownloadCount = (int) ($file['share_download_count'] ?? 0);
            $shareRemaining = $shareMaxDownloads !== null ? max(0, $shareMaxDownloads - $shareDownloadCount) : null;
            $owner = $isAdmin ? (string) ($file['owner_username'] ?? '') : '';
            $previewKind = \App\MimeType::previewKindFromName($name);
            $canPreview = $previewKind !== null
                && ($previewKind !== 'text' || (int) $file['size_bytes'] <= \App\MimeType::TEXT_PREVIEW_MAX_BYTES);
            $sourceUrl = trim((string) ($file['source_url'] ?? ''));
            $downloadJobId = isset($file['download_job_id']) && $file['download_job_id'] !== null && $file['download_job_id'] !== ''
                ? (int) $file['download_job_id']
                : null;
            $isHighlight = $highlightFileId > 0 && $highlightFileId === $fileId;
            $historyHref = null;
            if ($downloadJobId !== null) {
                $historyQuery = ['download_job' => $downloadJobId];
                if ($historyUserId !== null) {
                    $historyQuery['user_id'] = $historyUserId;
                }
                $historyHref = \App\Pager::url($historyBasePath, $historyQuery);
            }
            ?>
            <tr id="file-<?= $fileId ?>" data-file-row<?= $isHighlight ? ' class="files-row-highlight"' : '' ?>>
                <td class="files-col-check">
                    <input type="checkbox" class="form-check-input" name="ids[]" value="<?= $fileId ?>"
                           data-file-check aria-label="Select <?= e($name) ?>">
                </td>
                <td class="files-col-name">
                    <?php if ($canPreview): ?>
                        <button type="button" class="filename btn btn-link p-0 align-baseline"
                                title="<?= e($name) ?>"
                                data-action-preview
                                data-file-id="<?= $fileId ?>"
                                data-file-name="<?= e($name) ?>"
                                data-preview-kind="<?= e($previewKind) ?>"><?= e($name) ?></button>
                    <?php else: ?>
                        <a class="filename" href="/download.php?id=<?= $fileId ?>" title="<?= e($name) ?>"><?= e($name) ?></a>
                    <?php endif; ?>
                    <?php if ($sourceUrl !== ''): ?>
                        <div class="files-source mono text-secondary small" title="<?= e($sourceUrl) ?>"><?= e($truncateUrl($sourceUrl)) ?></div>
                    <?php endif; ?>
                </td>
                <?php if ($isAdmin): ?>
                    <td class="files-col-owner"><?= e($owner) ?></td>
                <?php endif; ?>
                <td class="files-col-size mono"><?= e(human_filesize((int) $file['size_bytes'])) ?></td>
                <td class="files-col-date mono"><?= e((string) $file['created_at']) ?></td>
                <td class="files-col-share">
                    <?php if ($shareUrl): ?>
                        <span class="badge bg-teal-lt text-teal">Public</span>
                        <?php if ($shareExpiresAt !== null): ?>
                            <span class="badge bg-azure-lt text-azure" title="Expires <?= e($shareExpiresAt) ?> UTC">
                                until <?= e(substr($shareExpiresAt, 0, 16)) ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($shareRemaining !== null): ?>
                            <span class="badge bg-orange-lt text-orange">
                                <?= $shareRemaining === 1 ? '1 left' : $shareRemaining . ' left' ?>
                            </span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="badge bg-secondary-lt">Private</span>
                    <?php endif; ?>
                </td>
                <td class="files-col-actions text-end">
                    <div class="dropdown">
                        <button class="btn btn-sm btn-outline-secondary files-actions-btn" type="button"
                                data-bs-toggle="dropdown"
                                aria-expanded="false" aria-label="Actions for <?= e($name) ?>">
                            ···
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <?php if ($canPreview): ?>
                                <li>
                                    <button type="button" class="dropdown-item" data-action-preview
                                            data-file-id="<?= $fileId ?>"
                                            data-file-name="<?= e($name) ?>"
                                            data-preview-kind="<?= e($previewKind) ?>">Preview</button>
                                </li>
                            <?php endif; ?>
                            <li>
                                <a class="dropdown-item" href="/download.php?id=<?= $fileId ?>">Download</a>
                            </li>
                            <?php if ($historyHref !== null): ?>
                                <li>
                                    <a class="dropdown-item" href="<?= e($historyHref) ?>">View in history</a>
                                </li>
                            <?php endif; ?>
                            <li>
                                <?php if ($shareUrl): ?>
                                    <button type="button" class="dropdown-item" data-copy-share
                                            data-share-url="<?= e($shareUrl) ?>">Copy public link</button>
                                <?php else: ?>
                                    <button type="button" class="dropdown-item disabled" disabled>Copy public link</button>
                                <?php endif; ?>
                            </li>
                            <li>
                                <?php if ($shareUrl): ?>
                                    <form method="post" action="/share.php">
                                        <?= \App\Csrf::field() ?>
                                        <input type="hidden" name="file_id" value="<?= $fileId ?>">
                                        <input type="hidden" name="action" value="revoke">
                                        <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
                                        <button class="dropdown-item" type="submit">Disable public link</button>
                                    </form>
                                <?php else: ?>
                                    <button type="button" class="dropdown-item" data-action-share
                                            data-file-id="<?= $fileId ?>"
                                            data-file-name="<?= e($name) ?>">Make public…</button>
                                <?php endif; ?>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <button type="button" class="dropdown-item" data-action-rename
                                        data-file-id="<?= $fileId ?>"
                                        data-file-name="<?= e($name) ?>">Rename…</button>
                            </li>
                            <?php if (!$isAdmin): ?>
                                <li>
                                    <?php if ($cloudConnected): ?>
                                        <button type="button" class="dropdown-item" data-action-cloud
                                                data-file-id="<?= $fileId ?>"
                                                data-file-name="<?= e($name) ?>">Send to cloud…</button>
                                    <?php else: ?>
                                        <a class="dropdown-item" href="/user/cloud.php">Connect Dropbox…</a>
                                    <?php endif; ?>
                                </li>
                            <?php endif; ?>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <button type="button" class="dropdown-item text-danger" data-action-delete
                                        data-file-id="<?= $fileId ?>"
                                        data-file-name="<?= e($name) ?>">Delete…</button>
                            </li>
                        </ul>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<form method="post" action="/bulk_files.php" class="d-none" data-bulk-form>
    <?= \App\Csrf::field() ?>
    <input type="hidden" name="action" value="" data-bulk-action>
    <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
    <div data-bulk-ids></div>
</form>
<?php elseif ($q !== ''): ?>
    <div class="empty">
        <p class="empty-title">No matching files</p>
        <p class="empty-subtitle text-secondary">Try a different search, or <a href="<?= e(\App\Pager::url($pagerPath, $pagerQuery, ['q' => null, 'page' => null])) ?>">clear search</a>.</p>
    </div>
<?php endif; ?>
<?php
include __DIR__ . '/pager.php';
?>
