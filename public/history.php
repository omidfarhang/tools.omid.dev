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

$req = Pager::requestParams();
$q = $req['q'];
$result = (new HistoryService())->forUser((int) $user['id'], $q, $req['page']);
$entries = $result['items'];
$pagerPath = '/history.php';
$pagerQuery = ['q' => $q !== '' ? $q : null];
$pagerTotal = $result['total'];
$pagerPage = $result['page'];
$pagerTotalPages = $result['total_pages'];
$pagerPerPage = $result['per_page'];

$providerLabel = static function (string $provider): string {
    return match ($provider) {
        'dropbox' => 'Dropbox',
        default => $provider !== '' ? ucfirst($provider) : 'Cloud',
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

$failReasonButton = static function (string $error, string $status): string {
    if ($error === '' || !in_array($status, ['failed', 'skipped'], true)) {
        return '';
    }

    return '<button type="button" class="btn btn-link btn-sm text-danger p-0 align-baseline ms-1"'
        . ' data-bs-toggle="modal" data-bs-target="#history-fail-modal"'
        . ' data-fail-reason="' . e($error) . '">'
        . 'Reason</button>';
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
                        $hasCloud = !empty($job['cloud_job_id']);
                        $cloudPath = (string) ($job['cloud_result_path'] ?? $job['cloud_remote_path'] ?? '');
                        $cloudStatus = (string) ($job['cloud_status'] ?? '');
                        $cloudProvider = (string) ($job['cloud_provider'] ?? 'dropbox');
                        $rowId = 'dl-' . (int) $job['id'];
                        $error = (string) ($job['error'] ?? '');
                        ?>
                        <tr>
                            <td class="small text-muted text-nowrap"><?= e((string) ($job['updated_at'] ?? $job['created_at'])) ?></td>
                            <td class="mono">
                                <?php if ($fileId !== null): ?>
                                    <a class="filename" href="/download.php?id=<?= $fileId ?>"><?= e($name !== '' ? $name : 'file') ?></a>
                                <?php else: ?>
                                    <?= e($name !== '' ? $name : '—') ?>
                                <?php endif; ?>
                            </td>
                            <td class="mono small" title="<?= e($sourceUrl) ?>"><?= e($truncateUrl($sourceUrl)) ?></td>
                            <td<?= $hasCloud
                                ? ' class="history-row-toggle" data-bs-toggle="collapse" data-bs-target="#' . e($rowId) . '" role="button" aria-expanded="false" style="cursor:pointer"'
                                : '' ?>>
                                <?= e($destinationLabel((string) ($job['behavior'] ?? ''), $hasCloud ? $cloudProvider : null)) ?>
                                <?php if ($hasCloud): ?>
                                    <div class="text-muted small">Cloud details ↓</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge text-bg-<?= e($statusBadge($status)) ?>"><?= e($status) ?></span>
                                <?= $failReasonButton($error, $status) ?>
                            </td>
                            <td class="text-end small">
                                <div class="d-inline-flex align-items-center gap-2">
                                    <?php if (DownloadJobRepository::canRetry($job)): ?>
                                        <form method="post" action="/job_action.php" class="d-inline">
                                            <?= Csrf::field() ?>
                                            <input type="hidden" name="action" value="retry">
                                            <input type="hidden" name="id" value="<?= (int) $job['id'] ?>">
                                            <input type="hidden" name="return" value="/">
                                            <button type="submit" class="btn btn-link btn-sm p-0 align-baseline">Retry</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" action="/history_hide.php" class="d-inline">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="kind" value="download">
                                        <input type="hidden" name="id" value="<?= (int) $job['id'] ?>">
                                        <button type="submit" class="btn btn-link btn-sm text-muted p-0 align-baseline">Remove</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php if ($hasCloud): ?>
                            <tr class="collapse" id="<?= e($rowId) ?>">
                                <td colspan="6" class="bg-white">
                                    <div class="p-3 small">
                                        <div class="fw-semibold mb-2"><?= e($providerLabel($cloudProvider)) ?> job</div>
                                        <dl class="row mb-0">
                                            <dt class="col-sm-2 text-muted">Status</dt>
                                            <dd class="col-sm-10">
                                                <span class="badge text-bg-<?= e($statusBadge($cloudStatus !== '' ? $cloudStatus : 'pending')) ?>">
                                                    <?= e($cloudStatus !== '' ? $cloudStatus : '—') ?>
                                                </span>
                                                <?= $failReasonButton((string) ($job['cloud_error'] ?? ''), $cloudStatus !== '' ? $cloudStatus : 'pending') ?>
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
                        ?>
                        <tr>
                            <td class="small text-muted text-nowrap"><?= e((string) ($job['updated_at'] ?? $job['created_at'])) ?></td>
                            <td class="mono"><?= e($name !== '' ? $name : '—') ?></td>
                            <td class="small"><?= e($providerLabel($provider)) ?> send</td>
                            <td class="mono small" title="<?= e($path) ?>"><?= e($path !== '' ? $truncateUrl($path, 40) : $providerLabel($provider)) ?></td>
                            <td>
                                <span class="badge text-bg-<?= e($statusBadge($status)) ?>"><?= e($status) ?></span>
                                <?= $failReasonButton($error, $status) ?>
                            </td>
                            <td class="text-end small">
                                <?php if (in_array($status, ['complete', 'failed', 'skipped'], true)): ?>
                                    <form method="post" action="/history_hide.php" class="d-inline">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="kind" value="cloud">
                                        <input type="hidden" name="id" value="<?= (int) $job['id'] ?>">
                                        <button type="submit" class="btn btn-link btn-sm text-muted p-0 align-baseline">Remove</button>
                                    </form>
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
<?php
View::render('History', ob_get_clean(), $user);
