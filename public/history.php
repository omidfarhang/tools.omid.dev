<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Auth;
use App\DownloadHistoryRepository;
use App\UserRepository;
use App\View;

$user = Auth::requireLogin();
$users = new UserRepository();
$user = $users->findById((int) $user['id']) ?? $user;
$entries = (new DownloadHistoryRepository())->forUser((int) $user['id']);

$destinationLabel = static function (string $behavior): string {
    return match ($behavior) {
        'save_to_cloud', 'save_to_dropbox' => 'Cloud',
        'save_to_both' => 'Disk + Cloud',
        default => 'Disk',
    };
};

$statusBadge = static function (string $status): string {
    return match ($status) {
        'success' => 'success',
        'failed' => 'danger',
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
    <p class="page-lede">Recent URL pulls for this account.</p>
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
            <table class="table table-striped align-middle mb-0">
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
                    <?php
                    $status = (string) ($entry['status'] ?? 'success');
                    $name = (string) ($entry['result_filename'] ?? $entry['requested_filename'] ?? '');
                    $sourceUrl = (string) ($entry['source_url'] ?? '');
                    $cloudPath = (string) ($entry['cloud_result_path'] ?? $entry['cloud_remote_path'] ?? '');
                    $cloudStatus = (string) ($entry['cloud_status'] ?? '');
                    $fileId = isset($entry['file_id']) && $entry['file_id'] !== null ? (int) $entry['file_id'] : null;
                    ?>
                    <tr>
                        <td class="small text-muted"><?= e((string) $entry['created_at']) ?></td>
                        <td class="mono small" title="<?= e($sourceUrl) ?>"><?= e($truncateUrl($sourceUrl)) ?></td>
                        <td class="mono"><?= e($name !== '' ? $name : '—') ?></td>
                        <td><?= e($destinationLabel((string) ($entry['behavior'] ?? ''))) ?></td>
                        <td>
                            <span class="badge text-bg-<?= e($statusBadge($status)) ?>"><?= e($status) ?></span>
                            <?php if ($status === 'failed' && !empty($entry['error'])): ?>
                                <div class="text-muted small mt-1"><?= e((string) $entry['error']) ?></div>
                            <?php elseif ($cloudStatus !== ''): ?>
                                <div class="text-muted small mt-1">Cloud: <?= e($cloudStatus) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-end small">
                            <?php if ($fileId !== null): ?>
                                <a href="/download.php?id=<?= $fileId ?>">File</a>
                            <?php endif; ?>
                            <?php if ($cloudPath !== ''): ?>
                                <?php if ($fileId !== null): ?> · <?php endif; ?>
                                <span class="mono text-muted" title="<?= e($cloudPath) ?>"><?= e($truncateUrl($cloudPath, 40)) ?></span>
                            <?php endif; ?>
                            <?php if ($fileId === null && $cloudPath === ''): ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php
View::render('History', ob_get_clean(), $user);
