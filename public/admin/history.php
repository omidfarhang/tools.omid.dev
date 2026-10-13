<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Auth;
use App\Cloud\CloudJobRepository;
use App\DownloadJobRepository;
use App\UserRepository;
use App\View;

$admin = Auth::requireAdmin();

$filterUserId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
$filterUser = null;
if ($filterUserId > 0) {
    $filterUser = (new UserRepository())->findById($filterUserId);
    if ($filterUser === null) {
        flash('error', 'User not found.');
        redirect('/admin/users.php');
    }
}

$scopeUserId = $filterUser !== null ? $filterUserId : null;
$downloadJobs = (new DownloadJobRepository())->historyAll($scopeUserId);
$standaloneCloud = (new CloudJobRepository())->recentUnlinkedAll($scopeUserId);

$entries = [];
foreach ($downloadJobs as $job) {
    $entries[] = [
        'kind' => 'download',
        'sort' => (string) ($job['updated_at'] ?? $job['created_at'] ?? ''),
        'job' => $job,
    ];
}
foreach ($standaloneCloud as $cloudJob) {
    $entries[] = [
        'kind' => 'cloud',
        'sort' => (string) ($cloudJob['updated_at'] ?? $cloudJob['created_at'] ?? ''),
        'job' => $cloudJob,
    ];
}

usort($entries, static function (array $a, array $b): int {
    return strcmp($b['sort'], $a['sort']);
});
$entries = array_slice($entries, 0, 200);

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
        'save_to_both' => 'Disk + ' . $cloud,
        default => 'Disk',
    };
};

$statusBadge = static function (string $status): string {
    return match ($status) {
        'complete', 'success' => 'success',
        'failed' => 'danger',
        'skipped' => 'warning',
        'in_progress' => 'info',
        'pending' => 'secondary',
        default => 'secondary',
    };
};

$truncateUrl = static function (string $url, int $max = 56): string {
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
        . ' data-fail-reason="' . e($error) . '"'
        . ' onclick="event.stopPropagation()">'
        . 'Reason</button>';
};

$hiddenCount = 0;
foreach ($entries as $entry) {
    if (!empty($entry['job']['hidden_at'])) {
        $hiddenCount++;
    }
}

$pageTitle = $filterUser !== null ? 'User history' : 'Admin History';
$panelTitle = $filterUser !== null
    ? 'History · ' . (string) $filterUser['username']
    : 'All history';

ob_start();
?>
<?php if ($filterUser !== null): ?>
    <a class="d-inline-block mb-3" href="/admin/users.php">&larr; Users</a>
<?php else: ?>
    <a class="d-inline-block mb-3" href="/admin/">&larr; Admin</a>
<?php endif; ?>
<?php if ($filterUser !== null): ?>
    <p class="text-secondary mb-3">
        Finished jobs for <strong><?= e((string) $filterUser['username']) ?></strong>, including soft-removed rows.
        <a href="/admin/files.php?user_id=<?= $filterUserId ?>">Files</a>
        ·
        <a href="/admin/history.php">All history</a>
    </p>
<?php else: ?>
    <p class="text-secondary mb-3">Finished download and cloud jobs across all users. Soft-removed rows stay visible here.</p>
<?php endif; ?>


<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h2 class="card-title mb-0"><?= e($panelTitle) ?></h2>
        <span class="text-muted small">
            <?= count($entries) ?> shown
            <?php if ($hiddenCount > 0): ?>
                · <?= $hiddenCount ?> hidden by user<?= $filterUser !== null ? '' : 's' ?>
            <?php endif; ?>
        </span>
    </div>
    <?php if (!$entries): ?>
        <div class="empty">
            <p class="empty-title">No history yet</p>
            <p class="empty-subtitle text-secondary"><?php if ($filterUser !== null): ?>
                This user has no finished download or cloud jobs.
            <?php else: ?>
                Nothing has been downloaded or sent to cloud.
            <?php endif; ?></p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table card-table table-striped align-middle mb-0">
                <thead>
                <tr>
                    <th>When</th>
                    <?php if ($filterUser === null): ?>
                        <th>User</th>
                    <?php endif; ?>
                    <th>Name</th>
                    <th>Source</th>
                    <th>Destination</th>
                    <th>Status</th>
                    <th class="w-1"></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($entries as $entry): ?>
                    <?php
                    $job = $entry['job'];
                    $isHidden = !empty($job['hidden_at']);
                    $owner = (string) ($job['owner_username'] ?? '');
                    $colSpan = $filterUser !== null ? 6 : 7;
                    ?>
                    <?php if ($entry['kind'] === 'download'): ?>
                        <?php
                        $status = (string) ($job['status'] ?? 'complete');
                        $name = (string) ($job['result_filename'] ?? $job['requested_filename'] ?? '');
                        $sourceUrl = (string) ($job['source_url'] ?? '');
                        $hasCloud = !empty($job['cloud_job_id']);
                        $cloudPath = (string) ($job['cloud_result_path'] ?? $job['cloud_remote_path'] ?? '');
                        $cloudStatus = (string) ($job['cloud_status'] ?? '');
                        $cloudProvider = (string) ($job['cloud_provider'] ?? 'dropbox');
                        $rowId = 'admin-dl-' . (int) $job['id'];
                        $error = (string) ($job['error'] ?? '');
                        ?>
                        <tr class="<?= $isHidden ? 'table-secondary' : '' ?> <?= $hasCloud ? 'history-row-toggle' : '' ?>"
                            <?= $hasCloud ? 'data-bs-toggle="collapse" data-bs-target="#' . e($rowId) . '" role="button" aria-expanded="false"' : '' ?>>
                            <td class="small text-muted text-nowrap"><?= e((string) ($job['updated_at'] ?? $job['created_at'])) ?></td>
                            <?php if ($filterUser === null): ?>
                                <td class="small"><?= e($owner !== '' ? $owner : '—') ?></td>
                            <?php endif; ?>
                            <td class="mono"><?= e($name !== '' ? $name : '—') ?></td>
                            <td class="mono small" title="<?= e($sourceUrl) ?>"><?= e($truncateUrl($sourceUrl)) ?></td>
                            <td>
                                <?= e($destinationLabel((string) ($job['behavior'] ?? ''), $hasCloud ? $cloudProvider : null)) ?>
                                <?php if ($hasCloud): ?>
                                    <div class="text-muted small">Cloud details ↓</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge text-bg-<?= e($statusBadge($status)) ?>"><?= e($status) ?></span>
                                <?php if ($isHidden): ?>
                                    <span class="badge text-bg-secondary ms-1" title="Hidden at <?= e((string) $job['hidden_at']) ?>">Hidden</span>
                                <?php endif; ?>
                                <?= $failReasonButton($error, $status) ?>
                            </td>
                            <td class="text-end small text-muted">#<?= (int) $job['id'] ?></td>
                        </tr>
                        <?php if ($hasCloud): ?>
                            <tr class="collapse" id="<?= e($rowId) ?>">
                                <td colspan="<?= $colSpan ?>" class="bg-white">
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
                                        </dl>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php
                        $status = (string) ($job['status'] ?? 'pending');
                        $name = (string) ($job['filename'] ?? '');
                        $path = (string) ($job['result_path'] ?? $job['remote_path'] ?? '');
                        $provider = (string) ($job['provider'] ?? 'dropbox');
                        $error = (string) ($job['error'] ?? '');
                        ?>
                        <tr class="<?= $isHidden ? 'table-secondary' : '' ?>">
                            <td class="small text-muted text-nowrap"><?= e((string) ($job['updated_at'] ?? $job['created_at'])) ?></td>
                            <?php if ($filterUser === null): ?>
                                <td class="small"><?= e($owner !== '' ? $owner : '—') ?></td>
                            <?php endif; ?>
                            <td class="mono"><?= e($name !== '' ? $name : '—') ?></td>
                            <td class="small"><?= e($providerLabel($provider)) ?> send</td>
                            <td class="mono small" title="<?= e($path) ?>"><?= e($path !== '' ? $truncateUrl($path, 40) : $providerLabel($provider)) ?></td>
                            <td>
                                <span class="badge text-bg-<?= e($statusBadge($status)) ?>"><?= e($status) ?></span>
                                <?php if ($isHidden): ?>
                                    <span class="badge text-bg-secondary ms-1" title="Hidden at <?= e((string) $job['hidden_at']) ?>">Hidden</span>
                                <?php endif; ?>
                                <?= $failReasonButton($error, $status) ?>
                            </td>
                            <td class="text-end small text-muted">#<?= (int) $job['id'] ?></td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
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
View::render($pageTitle, ob_get_clean(), $admin);
