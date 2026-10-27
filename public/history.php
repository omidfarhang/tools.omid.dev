<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Auth;
use App\Csrf;
use App\DownloadJobRepository;
use App\HistoryService;
use App\Pager;
use App\UserRepository;
use App\View;

$user = Auth::requireLogin();
$users = new UserRepository();
$user = $users->findById((int) $user['id']) ?? $user;
$userId = (int) $user['id'];

$req = Pager::requestParams();
$q = $req['q'];
$history = new HistoryService();
$jobRepo = new DownloadJobRepository();

$highlightJobId = isset($_GET['download_job']) ? (int) $_GET['download_job'] : 0;
if ($highlightJobId > 0) {
    $job = $jobRepo->findById($highlightJobId);
    if ($job === null || (int) ($job['user_id'] ?? 0) !== $userId) {
        flash('error', 'History item not found.');
        redirect(Pager::url('/history.php', [
            'q' => $q !== '' ? $q : null,
            'page' => $req['page'] > 1 ? $req['page'] : null,
        ]));
    }
    $targetPage = $history->pageForDownloadJobForUser($highlightJobId, $userId, $q);
    if ($targetPage === null) {
        flash('error', 'History item not found.');
        redirect(Pager::url('/history.php', [
            'q' => $q !== '' ? $q : null,
            'page' => $req['page'] > 1 ? $req['page'] : null,
        ]));
    }
    if ($targetPage !== $req['page']) {
        redirect(Pager::url('/history.php', [
            'q' => $q !== '' ? $q : null,
            'page' => $targetPage > 1 ? $targetPage : null,
            'download_job' => $highlightJobId,
        ]));
    }
} else {
    $highlightJobId = 0;
}

$result = $history->forUser($userId, $q, $req['page']);
$entries = $result['items'];
$pagerPath = '/history.php';
$pagerQuery = [
    'q' => $q !== '' ? $q : null,
    'download_job' => $highlightJobId > 0 ? $highlightJobId : null,
];
$pagerTotal = $result['total'];
$pagerPage = $result['page'];
$pagerTotalPages = $result['total_pages'];
$pagerPerPage = $result['per_page'];
$historyReturn = Pager::url($pagerPath, $pagerQuery, [
    'page' => $pagerPage > 1 ? $pagerPage : null,
]);

$providerLabel = static function (string $provider): string {
    return match ($provider) {
        'dropbox' => 'Dropbox',
        'google_drive' => 'Google Drive',
        default => $provider !== '' ? ucfirst(str_replace('_', ' ', $provider)) : 'Cloud',
    };
};

$destinationLabel = static function (string $behavior, ?string $provider = null) use ($providerLabel): string {
    $cloud = $provider !== null && $provider !== ''
        ? $providerLabel($provider)
        : 'Cloud';

    return match ($behavior) {
        'save_to_cloud', 'save_to_dropbox' => $cloud,
        'save_to_both' => 'Local + ' . $cloud,
        default => 'Local',
    };
};

$statusBadge = static function (string $status): string {
    return match ($status) {
        'complete', 'success' => 'success',
        'failed' => 'danger',
        'cancelled' => 'secondary',
        'skipped' => 'warning',
        'in_progress' => 'info',
        'pending' => 'secondary',
        default => 'secondary',
    };
};

$truncateUrl = static function (string $url, int $max = 64): string {
    if (strlen($url) <= $max) {
        return $url;
    }

    return substr($url, 0, $max - 1) . '…';
};

$hasFailReason = static function (string $error, string $status): bool {
    return $error !== '' && in_array($status, ['failed', 'skipped'], true);
};

ob_start();
?>
<p class="text-secondary mb-3">Recent URL pulls and standalone cloud uploads for this account.</p>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h2 class="card-title mb-0">History</h2>
        <div class="d-flex align-items-center gap-2">
            <form method="get" action="<?= e($pagerPath) ?>" class="d-flex align-items-center gap-2">
                <label class="visually-hidden" for="history-search">Search history</label>
                <input type="search" name="q" id="history-search" class="form-control form-control-sm"
                       style="width:12rem" placeholder="Search…" value="<?= e($q) ?>" autocomplete="off">
            </form>
            <span class="text-muted small"><?= e((string) $pagerTotal) ?> total</span>
        </div>
    </div>
    <?php if ($pagerTotal === 0 && $q === ''): ?>
        <div class="empty">
            <p class="empty-title">No downloads yet</p>
            <p class="empty-subtitle text-secondary">Start a pull from the <a href="/">Download</a> page.</p>
        </div>
    <?php elseif ($pagerTotal === 0): ?>
        <div class="empty">
            <p class="empty-title">No matching history</p>
            <p class="empty-subtitle text-secondary">Try a different search, or <a href="<?= e($pagerPath) ?>">clear search</a>.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table card-table table-striped align-middle mb-0" id="history-table">
                <thead>
                <tr>
                    <th>When</th>
                    <th>Name</th>
                    <th>Source</th>
                    <th>Destination</th>
                    <th>Status</th>
                    <th class="w-1"></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($entries as $entry): ?>
                    <?php if ($entry['kind'] === 'download'): ?>
                        <?php
                        $job = $entry['job'];
                        $status = (string) ($job['status'] ?? 'complete');
                        $name = (string) ($job['result_filename'] ?? $job['requested_filename'] ?? '');
                        $sourceUrl = (string) ($job['source_url'] ?? '');
                        $fileId = isset($job['file_id']) && $job['file_id'] !== null ? (int) $job['file_id'] : null;
                        $jobId = (int) $job['id'];
                        $hasCloud = !empty($job['cloud_job_id']);
                        $cloudPath = (string) ($job['cloud_result_path'] ?? $job['cloud_remote_path'] ?? '');
                        $cloudStatus = (string) ($job['cloud_status'] ?? '');
                        $cloudProvider = (string) ($job['cloud_provider'] ?? 'dropbox');
                        $rowId = 'dl-' . $jobId;
                        $error = (string) ($job['error'] ?? '');
                        $isHighlight = $highlightJobId > 0 && $highlightJobId === $jobId;
                        $canRetry = DownloadJobRepository::canRetry($job);
                        $canHide = DownloadJobRepository::canHide($job);
                        $displayName = $name !== '' ? $name : 'file';
                        $showFailReason = $hasFailReason($error, $status);
                        $cloudError = (string) ($job['cloud_error'] ?? '');
                        $showCloudFailReason = $hasCloud && $hasFailReason($cloudError, $cloudStatus !== '' ? $cloudStatus : 'pending');
                        ?>
                        <tr id="<?= e($rowId) ?>"<?= $isHighlight ? ' class="history-row-highlight"' : '' ?>>
                            <td class="small text-muted text-nowrap"><?= e((string) ($job['updated_at'] ?? $job['created_at'])) ?></td>
                            <td class="mono">
                                <?php if ($fileId !== null): ?>
                                    <a class="filename" href="/files.php?file=<?= $fileId ?>"><?= e($displayName) ?></a>
                                <?php else: ?>
                                    <?= e($name !== '' ? $name : '—') ?>
                                <?php endif; ?>
                            </td>
                            <td class="mono small" title="<?= e($sourceUrl) ?>"><?= e($truncateUrl($sourceUrl)) ?></td>
                            <td<?= $hasCloud
                                ? ' class="history-row-toggle" data-bs-toggle="collapse" data-bs-target="#' . e($rowId) . '-cloud" role="button" aria-expanded="false" style="cursor:pointer"'
                                : '' ?>>
                                <?= e($destinationLabel((string) ($job['behavior'] ?? ''), $hasCloud ? $cloudProvider : null)) ?>
                                <?php if ($hasCloud): ?>
                                    <div class="text-muted small">Cloud details ↓</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge text-bg-<?= e($statusBadge($status)) ?>"><?= e($status) ?></span>
                            </td>
                            <td class="text-end">
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-secondary" type="button"
                                            data-bs-toggle="dropdown"
                                            aria-expanded="false" aria-label="Actions for <?= e($displayName) ?>">
                                        ···
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <?php if ($fileId !== null): ?>
                                            <li>
                                                <a class="dropdown-item" href="/download.php?id=<?= $fileId ?>">Download</a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="/files.php?file=<?= $fileId ?>">View in files</a>
                                            </li>
                                        <?php endif; ?>
                                        <?php if ($sourceUrl !== ''): ?>
                                            <li>
                                                <button type="button" class="dropdown-item" data-copy-source
                                                        data-source-url="<?= e($sourceUrl) ?>">Copy source URL</button>
                                            </li>
                                        <?php endif; ?>
                                        <?php if ($showFailReason): ?>
                                            <li>
                                                <button type="button" class="dropdown-item text-danger"
                                                        data-bs-toggle="modal" data-bs-target="#history-fail-modal"
                                                        data-fail-reason="<?= e($error) ?>">View fail reason</button>
                                            </li>
                                        <?php endif; ?>
                                        <?php if ($showCloudFailReason): ?>
                                            <li>
                                                <button type="button" class="dropdown-item text-danger"
                                                        data-bs-toggle="modal" data-bs-target="#history-fail-modal"
                                                        data-fail-reason="<?= e($cloudError) ?>">View cloud fail reason</button>
                                            </li>
                                        <?php endif; ?>
                                        <?php if ($canRetry): ?>
                                            <li>
                                                <form method="post" action="/job_action.php">
                                                    <?= Csrf::field() ?>
                                                    <input type="hidden" name="action" value="retry">
                                                    <input type="hidden" name="id" value="<?= $jobId ?>">
                                                    <input type="hidden" name="return" value="<?= e($historyReturn) ?>">
                                                    <button type="submit" class="dropdown-item">Retry</button>
                                                </form>
                                            </li>
                                        <?php endif; ?>
                                        <?php if ($canHide): ?>
                                            <?php if ($fileId !== null || $sourceUrl !== '' || $showFailReason || $showCloudFailReason || $canRetry): ?>
                                                <li><hr class="dropdown-divider"></li>
                                            <?php endif; ?>
                                            <li>
                                                <form method="post" action="/history_hide.php">
                                                    <?= Csrf::field() ?>
                                                    <input type="hidden" name="kind" value="download">
                                                    <input type="hidden" name="id" value="<?= $jobId ?>">
                                                    <input type="hidden" name="return" value="<?= e($historyReturn) ?>">
                                                    <button type="submit" class="dropdown-item text-danger">Remove from history</button>
                                                </form>
                                            </li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                        <?php if ($hasCloud): ?>
                            <tr class="collapse" id="<?= e($rowId) ?>-cloud">
                                <td colspan="6" class="bg-white">
                                    <div class="p-3 small">
                                        <div class="fw-semibold mb-2"><?= e($providerLabel($cloudProvider)) ?> job</div>
                                        <dl class="row mb-0">
                                            <dt class="col-sm-2 text-muted">Status</dt>
                                            <dd class="col-sm-10">
                                                <span class="badge text-bg-<?= e($statusBadge($cloudStatus !== '' ? $cloudStatus : 'pending')) ?>">
                                                    <?= e($cloudStatus !== '' ? $cloudStatus : '—') ?>
                                                </span>
                                            </dd>
                                            <dt class="col-sm-2 text-muted">Path</dt>
                                            <dd class="col-sm-10 mono"><?= e($cloudPath !== '' ? $cloudPath : '—') ?></dd>
                                            <?php if (!empty($job['cloud_updated_at'])): ?>
                                                <dt class="col-sm-2 text-muted">Updated</dt>
                                                <dd class="col-sm-10"><?= e((string) $job['cloud_updated_at']) ?></dd>
                                            <?php endif; ?>
                                        </dl>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php
                        $job = $entry['job'];
                        $status = (string) ($job['status'] ?? 'pending');
                        $name = (string) ($job['filename'] ?? '');
                        $path = (string) ($job['result_path'] ?? $job['remote_path'] ?? '');
                        $provider = (string) ($job['provider'] ?? 'dropbox');
                        $error = (string) ($job['error'] ?? '');
                        $canHideCloud = in_array($status, ['complete', 'failed', 'skipped', 'cancelled'], true);
                        $showFailReason = $hasFailReason($error, $status);
                        $displayName = $name !== '' ? $name : $providerLabel($provider);
                        $showCloudMenu = $canHideCloud || $showFailReason;
                        ?>
                        <tr>
                            <td class="small text-muted text-nowrap"><?= e((string) ($job['updated_at'] ?? $job['created_at'])) ?></td>
                            <td class="mono"><?= e($name !== '' ? $name : '—') ?></td>
                            <td class="small"><?= e($providerLabel($provider)) ?> send</td>
                            <td class="mono small" title="<?= e($path) ?>"><?= e($path !== '' ? $truncateUrl($path, 40) : $providerLabel($provider)) ?></td>
                            <td>
                                <span class="badge text-bg-<?= e($statusBadge($status)) ?>"><?= e($status) ?></span>
                            </td>
                            <td class="text-end">
                                <?php if ($showCloudMenu): ?>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary" type="button"
                                                data-bs-toggle="dropdown"
                                                aria-expanded="false" aria-label="Actions for <?= e($displayName) ?>">
                                            ···
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <?php if ($showFailReason): ?>
                                                <li>
                                                    <button type="button" class="dropdown-item text-danger"
                                                            data-bs-toggle="modal" data-bs-target="#history-fail-modal"
                                                            data-fail-reason="<?= e($error) ?>">View fail reason</button>
                                                </li>
                                            <?php endif; ?>
                                            <?php if ($canHideCloud): ?>
                                                <?php if ($showFailReason): ?>
                                                    <li><hr class="dropdown-divider"></li>
                                                <?php endif; ?>
                                                <li>
                                                    <form method="post" action="/history_hide.php">
                                                        <?= Csrf::field() ?>
                                                        <input type="hidden" name="kind" value="cloud">
                                                        <input type="hidden" name="id" value="<?= (int) $job['id'] ?>">
                                                        <input type="hidden" name="return" value="<?= e($historyReturn) ?>">
                                                        <button type="submit" class="dropdown-item text-danger">Remove from history</button>
                                                    </form>
                                                </li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php include dirname(__DIR__) . '/templates/partials/pager.php'; ?>
    <?php endif; ?>
</div>

<div class="modal fade" id="history-fail-modal" tabindex="-1" aria-labelledby="history-fail-modal-label" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="history-fail-modal-label">Fail reason</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <pre class="mb-0 small text-wrap" id="history-fail-modal-body" style="white-space: pre-wrap; word-break: break-word;"></pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    var table = document.getElementById('history-table');
    if (table && typeof tabler !== 'undefined') {
        table.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(function (el) {
            tabler.Dropdown.getOrCreateInstance(el, {
                popperConfig: function (defaultConfig) {
                    return Object.assign({}, defaultConfig, { strategy: 'fixed' });
                }
            });
        });
        table.addEventListener('click', function (e) {
            var copyBtn = e.target.closest('[data-copy-source]');
            if (!copyBtn) return;
            var url = copyBtn.getAttribute('data-source-url') || '';
            if (!url || !navigator.clipboard) return;
            navigator.clipboard.writeText(url).then(function () {
                var prev = copyBtn.textContent;
                copyBtn.textContent = 'Copied';
                setTimeout(function () { copyBtn.textContent = prev; }, 1200);
            });
        });
    }

    var modal = document.getElementById('history-fail-modal');
    if (!modal) return;
    modal.addEventListener('show.bs.modal', function (event) {
        var btn = event.relatedTarget;
        var body = document.getElementById('history-fail-modal-body');
        if (!body) return;
        body.textContent = btn && btn.getAttribute('data-fail-reason') ? btn.getAttribute('data-fail-reason') : '';
    });
})();
</script>
<?php include dirname(__DIR__) . '/templates/partials/row_highlight_js.php'; ?>
<?php
View::render('History', ob_get_clean(), $user);
