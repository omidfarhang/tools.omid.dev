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

$destinationLabel = static function (string $behavior): string {
    return match ($behavior) {
        'save_to_cloud', 'save_to_dropbox' => 'Cloud',
        'save_to_both' => 'Disk + Cloud',
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
    <a class="breadcrumb-dash" href="/admin/users.php">&larr; Users</a>
<?php else: ?>
    <a class="breadcrumb-dash" href="/admin/">&larr; Admin</a>
<?php endif; ?>
<div class="page-header">
    <?php if ($filterUser !== null): ?>
        <p class="page-lede">
            Finished jobs for <strong><?= e((string) $filterUser['username']) ?></strong>, including soft-removed rows.
            <a href="/admin/files.php?user_id=<?= $filterUserId ?>">Files</a>
            ·
            <a href="/admin/history.php">All history</a>
        </p>
    <?php else: ?>
        <p class="page-lede">Finished download and cloud jobs across all users. Soft-removed rows stay visible here.</p>
    <?php endif; ?>
</div>

<div class="panel panel-flush">
    <div class="panel-head d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h2 class="panel-title mb-0"><?= e($panelTitle) ?></h2>
        <span class="text-muted small">
            <?= count($entries) ?> shown
            <?php if ($hiddenCount > 0): ?>
                · <?= $hiddenCount ?> hidden by user<?= $filterUser !== null ? '' : 's' ?>
            <?php endif; ?>
        </span>
    </div>
    <?php if (!$entries): ?>
        <div class="empty-state">
            <strong>No history yet</strong>
            <?php if ($filterUser !== null): ?>
                This user has no finished download or cloud jobs.
            <?php else: ?>
                Nothing has been downloaded or sent to cloud.
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-striped align-middle mb-0">
                <thead>
                <tr>
                    <th>When</th>
                    <?php if ($filterUser === null): ?>
                        <th>User</th>
                    <?php endif; ?>
                    <th>Source</th>
                    <th>Name</th>
                    <th>Destination</th>
                    <th>Status</th>
                    <th></th>
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
                        $rowId = 'admin-dl-' . (int) $job['id'];
                        ?>
                        <tr class="<?= $isHidden ? 'table-secondary' : '' ?> <?= $hasCloud ? 'history-row-toggle' : '' ?>"
                            <?= $hasCloud ? 'data-bs-toggle="collapse" data-bs-target="#' . e($rowId) . '" role="button" aria-expanded="false"' : '' ?>>
                            <td class="small text-muted"><?= e((string) ($job['updated_at'] ?? $job['created_at'])) ?></td>
                            <?php if ($filterUser === null): ?>
                                <td class="small"><?= e($owner !== '' ? $owner : '—') ?></td>
                            <?php endif; ?>
                            <td class="mono small" title="<?= e($sourceUrl) ?>"><?= e($truncateUrl($sourceUrl)) ?></td>
                            <td class="mono"><?= e($name !== '' ? $name : '—') ?></td>
                            <td><?= e($destinationLabel((string) ($job['behavior'] ?? ''))) ?></td>
                            <td>
                                <span class="badge text-bg-<?= e($statusBadge($status)) ?>"><?= e($status) ?></span>
                                <?php if ($isHidden): ?>
                                    <span class="badge text-bg-secondary ms-1" title="Hidden at <?= e((string) $job['hidden_at']) ?>">Hidden</span>
                                <?php endif; ?>
                                <?php if ($status === 'failed' && !empty($job['error'])): ?>
                                    <div class="text-muted small mt-1"><?= e((string) $job['error']) ?></div>
                                <?php elseif ($hasCloud): ?>
                                    <div class="text-muted small mt-1">Cloud details ↓</div>
                                <?php endif; ?>
                            </td>
                            <td class="text-end small text-muted">#<?= (int) $job['id'] ?></td>
                        </tr>
                        <?php if ($hasCloud): ?>
                            <tr class="collapse" id="<?= e($rowId) ?>">
                                <td colspan="<?= $colSpan ?>" class="bg-white">
                                    <div class="p-3 small">
                                        <div class="fw-semibold mb-2">Cloud job</div>
                                        <dl class="row mb-0">
                                            <dt class="col-sm-2 text-muted">Provider</dt>
                                            <dd class="col-sm-10"><?= e((string) ($job['cloud_provider'] ?? 'dropbox')) ?></dd>
                                            <dt class="col-sm-2 text-muted">Status</dt>
                                            <dd class="col-sm-10">
                                                <span class="badge text-bg-<?= e($statusBadge($cloudStatus !== '' ? $cloudStatus : 'pending')) ?>">
                                                    <?= e($cloudStatus !== '' ? $cloudStatus : '—') ?>
                                                </span>
                                            </dd>
                                            <dt class="col-sm-2 text-muted">Path</dt>
                                            <dd class="col-sm-10 mono"><?= e($cloudPath !== '' ? $cloudPath : '—') ?></dd>
                                            <?php if (!empty($job['cloud_error'])): ?>
                                                <dt class="col-sm-2 text-muted">Error</dt>
                                                <dd class="col-sm-10"><?= e((string) $job['cloud_error']) ?></dd>
                                            <?php endif; ?>
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
                        ?>
                        <tr class="<?= $isHidden ? 'table-secondary' : '' ?>">
                            <td class="small text-muted"><?= e((string) ($job['updated_at'] ?? $job['created_at'])) ?></td>
                            <?php if ($filterUser === null): ?>
                                <td class="small"><?= e($owner !== '' ? $owner : '—') ?></td>
                            <?php endif; ?>
                            <td class="small text-muted">Cloud send</td>
                            <td class="mono"><?= e($name !== '' ? $name : '—') ?></td>
                            <td>Cloud</td>
                            <td>
                                <span class="badge text-bg-<?= e($statusBadge($status)) ?>"><?= e($status) ?></span>
                                <?php if ($isHidden): ?>
                                    <span class="badge text-bg-secondary ms-1" title="Hidden at <?= e((string) $job['hidden_at']) ?>">Hidden</span>
                                <?php endif; ?>
                                <?php if (!empty($job['error']) && in_array($status, ['failed', 'skipped'], true)): ?>
                                    <div class="text-muted small mt-1"><?= e((string) $job['error']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-end small mono" title="<?= e($path) ?>"><?= e($path !== '' ? $truncateUrl($path, 32) : '—') ?></td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php
View::render($pageTitle, ob_get_clean(), $admin);
