<?php
/** @var list<array<string, mixed>> $dropboxJobs */
/** @var bool $dropboxConnected */

if (empty($dropboxJobs)) {
    return;
}
?>
<div class="panel panel-flush mt-3" id="dropbox-jobs-panel">
    <div class="panel-head d-flex justify-content-between align-items-center">
        <h2 class="panel-title mb-0">Recent Dropbox jobs</h2>
        <span class="text-muted small"><?= count($dropboxJobs) ?> recent</span>
    </div>
    <div class="table-responsive">
        <table class="table table-striped align-middle mb-0">
            <thead>
            <tr>
                <th>File</th>
                <th>Path</th>
                <th>Status</th>
                <th>When</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($dropboxJobs as $job): ?>
                <?php
                $status = (string) ($job['status'] ?? 'pending');
                $badge = match ($status) {
                    'complete' => 'success',
                    'failed' => 'danger',
                    'skipped' => 'warning',
                    'in_progress' => 'info',
                    default => 'secondary',
                };
                $path = (string) ($job['result_path'] ?? $job['dropbox_path'] ?? '');
                ?>
                <tr data-job-id="<?= (int) $job['id'] ?>"
                    data-async-job-id="<?= e((string) ($job['async_job_id'] ?? '')) ?>"
                    data-status="<?= e($status) ?>">
                    <td class="mono"><?= e((string) $job['filename']) ?></td>
                    <td class="mono small"><?= e($path) ?></td>
                    <td>
                        <span class="badge text-bg-<?= $badge ?> job-status-badge"><?= e($status) ?></span>
                        <?php if (!empty($job['error']) && in_array($status, ['failed', 'skipped'], true)): ?>
                            <div class="text-muted small mt-1"><?= e((string) $job['error']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="small text-muted"><?= e((string) $job['updated_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
