<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Auth;
use App\Csrf;
use App\DiskOverview;
use App\UserRepository;
use App\View;

$admin = Auth::requireAdmin();
$disk = new DiskOverview();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'delete_orphans') {
        $names = $_POST['orphans'] ?? [];
        if (!is_array($names)) {
            $names = [];
        }
        $names = array_values(array_filter(
            array_map(static fn ($n): string => (string) $n, $names),
            static fn (string $n): bool => $n !== ''
        ));
        if ($names === []) {
            flash('error', 'Select at least one orphan file to delete.');
        } else {
            $deleted = $disk->deleteOrphans($names);
            flash(
                'success',
                $deleted === 1
                    ? 'Deleted 1 orphan file.'
                    : 'Deleted ' . $deleted . ' orphan files.'
            );
        }
    } elseif ($action === 'delete_all_orphans') {
        $deleted = $disk->deleteAllOrphans();
        flash(
            'success',
            $deleted === 0
                ? 'No orphan files to delete.'
                : ($deleted === 1
                    ? 'Deleted 1 orphan file.'
                    : 'Deleted ' . $deleted . ' orphan files.')
        );
    }

    redirect('/admin/disk.php');
}

$usage = $disk->usage();
$orphans = $disk->orphans();
$failed = $disk->failedJobCounts();
$orphanBytes = array_sum(array_column($orphans, 'size'));

ob_start();
?>
<p class="text-secondary mb-3">Tracked storage, orphan temp files, and failed job counts.</p>

<div class="row row-deck row-cards mb-3">
    <div class="col-sm-6 col-xl-3">
        <div class="card">
            <div class="card-body">
                <div class="subheader">Tracked storage</div>
                <div class="h1 mb-0 mono"><?= e(human_filesize($usage['total_bytes'])) ?></div>
                <div class="text-secondary mt-1"><?= (int) $usage['total_files'] ?> file<?= $usage['total_files'] === 1 ? '' : 's' ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card">
            <div class="card-body">
                <div class="subheader">Orphan temp files</div>
                <div class="h1 mb-0"><?= count($orphans) ?></div>
                <div class="text-secondary mt-1 mono"><?= e(human_filesize($orphanBytes)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card">
            <div class="card-body">
                <div class="subheader">Failed downloads</div>
                <div class="h1 mb-0"><?= (int) $failed['download_jobs'] ?></div>
                <div class="mt-1">
                    <a href="/admin/history.php">View history</a>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card">
            <div class="card-body">
                <div class="subheader">Failed cloud jobs</div>
                <div class="h1 mb-0"><?= (int) $failed['cloud_jobs'] ?></div>
                <div class="mt-1">
                    <a href="/admin/history.php">View history</a>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header">
        <h2 class="card-title mb-0">Usage by user</h2>
    </div>
    <?php if ($usage['users'] === []): ?>
        <div class="empty">
            <p class="empty-title">No users</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table card-table table-sm align-middle mb-0">
                <thead>
                <tr>
                    <th>User</th>
                    <th class="text-end">Files</th>
                    <th class="text-end">Used</th>
                    <th class="text-end">Quota</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($usage['users'] as $u): ?>
                    <tr>
                        <td>
                            <a href="/admin/files.php?user_id=<?= (int) $u['id'] ?>" class="fw-semibold text-reset">
                                <?= e($u['username']) ?>
                            </a>
                        </td>
                        <td class="text-end mono"><?= (int) $u['file_count'] ?></td>
                        <td class="text-end mono"><?= e(human_filesize($u['used_bytes'])) ?></td>
                        <td class="text-end mono"><?= e(UserRepository::formatQuotaLabel($u['quota_bytes'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h2 class="card-title mb-0">Orphan temp files</h2>
        <?php if ($orphans !== []): ?>
            <form method="post" class="m-0"
                  onsubmit="return confirm('Delete all orphan temp files? This cannot be undone.');">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="delete_all_orphans">
                <button type="submit" class="btn btn-sm btn-outline-danger">Delete all orphans</button>
            </form>
        <?php endif; ?>
    </div>
    <?php if ($orphans === []): ?>
        <div class="empty">
            <p class="empty-title">No orphans</p>
            <p class="empty-subtitle text-secondary">Every file in the temp directory is tracked in the database.</p>
        </div>
    <?php else: ?>
        <form method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="delete_orphans">
            <div class="table-responsive">
                <table class="table card-table table-sm align-middle mb-0">
                    <thead>
                    <tr>
                        <th class="w-1">
                            <input type="checkbox" class="form-check-input m-0" id="orphan-check-all"
                                   title="Select all" aria-label="Select all orphans">
                        </th>
                        <th>Filename</th>
                        <th class="text-end">Size</th>
                        <th class="text-end">Modified</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($orphans as $orphan): ?>
                        <tr>
                            <td>
                                <input type="checkbox" class="form-check-input m-0 orphan-check"
                                       name="orphans[]" value="<?= e($orphan['name']) ?>"
                                       aria-label="Select <?= e($orphan['name']) ?>">
                            </td>
                            <td class="mono filename text-break"><?= e($orphan['name']) ?></td>
                            <td class="text-end mono"><?= e(human_filesize($orphan['size'])) ?></td>
                            <td class="text-end text-secondary small">
                                <?php if ($orphan['mtime'] > 0): ?>
                                    <?= e(date('Y-m-d H:i', $orphan['mtime'])) ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer d-flex justify-content-between align-items-center">
                <span class="text-secondary small"><?= count($orphans) ?> orphan<?= count($orphans) === 1 ? '' : 's' ?> · <?= e(human_filesize($orphanBytes)) ?></span>
                <button type="submit" class="btn btn-danger"
                        onclick="return confirm('Delete selected orphan files? This cannot be undone.');">
                    Delete selected
                </button>
            </div>
        </form>
        <script>
            (function () {
                var all = document.getElementById('orphan-check-all');
                if (!all) return;
                all.addEventListener('change', function () {
                    document.querySelectorAll('.orphan-check').forEach(function (el) {
                        el.checked = all.checked;
                    });
                });
            })();
        </script>
    <?php endif; ?>
</div>
<?php
View::render('Admin · Disk', ob_get_clean(), $admin);
