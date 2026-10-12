<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Auth;
use App\Cloud\CloudJobRepository;
use App\DownloadJobRepository;
use App\UserRepository;
use App\View;

$user = Auth::requireLogin();
$users = new UserRepository();
$user = $users->findById((int) $user['id']) ?? $user;

$downloadJobs = (new DownloadJobRepository())->historyForUser((int) $user['id']);
$standaloneCloud = (new CloudJobRepository())->recentUnlinkedForUser((int) $user['id']);

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
$entries = array_slice($entries, 0, 50);

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

$truncateUrl = static function (string $url, int $max = 64): string {
    if (strlen($url) <= $max) {
        return $url;
    }

    return substr($url, 0, $max - 1) . '…';
};

ob_start();
?>
<div class="page-header">
    <p class="page-lede">Recent URL pulls and standalone cloud uploads for this account.</p>
</div>

<div class="panel panel-flush">
    <div class="panel-head d-flex justify-content-between align-items-center">
        <h2 class="panel-title mb-0">History</h2>
        <span class="text-muted small"><?= count($entries) ?> recent</span>
    </div>
    <?php if (!$entries): ?>
        <div class="empty-state">
            <strong>No downloads yet</strong>
            Start a pull from the <a href="/">Download</a> page.
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-striped align-middle mb-0" id="history-table">
                <thead>
                <tr>
                    <th>When</th>
                    <th>Source</th>
                    <th>Name</th>
                    <th>Destination</th>
                    <th>Status</th>
                    <th></th>
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
                        $rowId = 'dl-' . (int) $job['id'];
                        ?>
                        <tr class="<?= $hasCloud ? 'history-row-toggle' : '' ?>"
                            <?= $hasCloud ? 'data-bs-toggle="collapse" data-bs-target="#' . e($rowId) . '" role="button" aria-expanded="false"' : '' ?>>
                            <td class="small text-muted"><?= e((string) ($job['updated_at'] ?? $job['created_at'])) ?></td>
                            <td class="mono small" title="<?= e($sourceUrl) ?>"><?= e($truncateUrl($sourceUrl)) ?></td>
                            <td class="mono"><?= e($name !== '' ? $name : '—') ?></td>
                            <td><?= e($destinationLabel((string) ($job['behavior'] ?? ''))) ?></td>
                            <td>
                                <span class="badge text-bg-<?= e($statusBadge($status)) ?>"><?= e($status) ?></span>
                                <?php if ($status === 'failed' && !empty($job['error'])): ?>
                                    <div class="text-muted small mt-1"><?= e((string) $job['error']) ?></div>
                                <?php elseif ($hasCloud): ?>
                                    <div class="text-muted small mt-1">Cloud details ↓</div>
                                <?php endif; ?>
                            </td>
                            <td class="text-end small">
                                <?php if ($fileId !== null): ?>
                                    <a href="/download.php?id=<?= $fileId ?>" onclick="event.stopPropagation()">File</a>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if ($hasCloud): ?>
                            <tr class="collapse" id="<?= e($rowId) ?>">
                                <td colspan="6" class="bg-white">
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
                        ?>
                        <tr>
                            <td class="small text-muted"><?= e((string) ($job['updated_at'] ?? $job['created_at'])) ?></td>
                            <td class="small text-muted">Cloud send</td>
                            <td class="mono"><?= e($name !== '' ? $name : '—') ?></td>
                            <td>Cloud</td>
                            <td>
                                <span class="badge text-bg-<?= e($statusBadge($status)) ?>"><?= e($status) ?></span>
                                <?php if (!empty($job['error']) && in_array($status, ['failed', 'skipped'], true)): ?>
                                    <div class="text-muted small mt-1"><?= e((string) $job['error']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-end small mono" title="<?= e($path) ?>"><?= e($path !== '' ? $truncateUrl($path, 40) : '—') ?></td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php
View::render('History', ob_get_clean(), $user);
