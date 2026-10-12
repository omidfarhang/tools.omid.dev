<?php
/**
 * @var string $mode 'user'|'admin'
 * @var string $panelTitle
 * @var list<array<string, mixed>> $files
 * @var string $sort
 * @var string $dir ASC|DESC
 * @var string $redirect
 * @var bool $cloudConnected
 * @var array{folder?:string, conflict?:string} $prefs
 */
$mode = $mode ?? 'user';
$isAdmin = $mode === 'admin';
$panelTitle = $panelTitle ?? ($isAdmin ? 'All files' : 'Your files');
$redirect = $redirect ?? ($isAdmin ? '/admin/files.php' : '/files.php');
$cloudConnected = $cloudConnected ?? false;
$prefs = $prefs ?? ['folder' => '', 'conflict' => 'rename'];
$sort = $sort ?? 'date';
$dir = $dir ?? 'DESC';

$sortUrl = static function (string $col) use ($redirect, $sort, $dir): string {
    $nextDir = ($sort === $col && $dir === 'ASC') ? 'DESC' : 'ASC';
    if ($sort !== $col) {
        $nextDir = $col === 'date' ? 'DESC' : 'ASC';
    }
    return $redirect . '?sort=' . rawurlencode($col) . '&dir=' . rawurlencode(strtolower($nextDir));
};

$sortMark = static function (string $col) use ($sort, $dir): string {
    if ($sort !== $col) {
        return '';
    }
    return $dir === 'ASC' ? ' ↑' : ' ↓';
};
?>
<div class="panel-head files-panel-head">
    <h2 class="panel-title mb-0"><?= e($panelTitle) ?></h2>
    <div class="files-panel-tools">
        <label class="visually-hidden" for="files-search">Search files</label>
        <input type="search" id="files-search" class="files-search"
               placeholder="<?= $isAdmin ? 'Filter…' : 'Filter…' ?>"
               data-files-search autocomplete="off">
        <span class="files-count" data-files-count><?= count($files) ?></span>
    </div>
</div>

<div class="files-selection-bar d-none" data-files-bulk>
    <span class="files-selection-label" data-files-bulk-count>0 selected</span>
    <div class="files-selection-actions">
        <button type="button" class="btn btn-sm btn-outline-secondary" data-bulk-revoke>Disable links</button>
        <button type="button" class="btn btn-sm btn-outline-danger" data-bulk-delete>Delete</button>
    </div>
</div>

<div class="files-table-scroll">
    <table class="table table-striped align-middle mb-0 files-table" data-files-table>
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
            $owner = $isAdmin ? (string) ($file['owner_username'] ?? '') : '';
            $searchHaystack = strtolower($name . ' ' . $owner);
            ?>
            <tr data-file-row data-search="<?= e($searchHaystack) ?>">
                <td class="files-col-check">
                    <input type="checkbox" class="form-check-input" name="ids[]" value="<?= $fileId ?>"
                           data-file-check aria-label="Select <?= e($name) ?>">
                </td>
                <td class="files-col-name">
                    <a class="filename" href="/download.php?id=<?= $fileId ?>" title="<?= e($name) ?>"><?= e($name) ?></a>
                </td>
                <?php if ($isAdmin): ?>
                    <td class="files-col-owner"><?= e($owner) ?></td>
                <?php endif; ?>
                <td class="files-col-size mono"><?= e(human_filesize((int) $file['size_bytes'])) ?></td>
                <td class="files-col-date mono"><?= e((string) $file['created_at']) ?></td>
                <td class="files-col-share">
                    <?php if ($shareUrl): ?>
                        <span class="badge files-share-badge files-share-public">Public</span>
                    <?php else: ?>
                        <span class="badge files-share-badge files-share-private">Private</span>
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
                            <li>
                                <a class="dropdown-item" href="/download.php?id=<?= $fileId ?>">Download</a>
                            </li>
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
                                    <form method="post" action="/share.php">
                                        <?= \App\Csrf::field() ?>
                                        <input type="hidden" name="file_id" value="<?= $fileId ?>">
                                        <input type="hidden" name="action" value="enable">
                                        <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
                                        <button class="dropdown-item" type="submit">Make public</button>
                                    </form>
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
                                        <a class="dropdown-item" href="/user/">Connect Dropbox…</a>
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
