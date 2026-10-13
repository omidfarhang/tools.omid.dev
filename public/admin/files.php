<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Auth;
use App\FileRepository;
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

$sortInfo = FileRepository::normalizeSort(
    isset($_GET['sort']) ? (string) $_GET['sort'] : null,
    isset($_GET['dir']) ? (string) $_GET['dir'] : null,
    true
);
$sort = $sortInfo['sort'];
$dir = $sortInfo['dir'];

$fileRepo = new FileRepository();
if ($filterUser !== null) {
    $files = $fileRepo->forUserWithOwner($filterUserId, $sort, $dir);
    $redirect = '/admin/files.php?user_id=' . $filterUserId;
    $panelTitle = 'Files · ' . (string) $filterUser['username'];
    $pageTitle = 'User files';
} else {
    $files = $fileRepo->allWithOwner($sort, $dir);
    $redirect = '/admin/files.php';
    $panelTitle = 'All files';
    $pageTitle = 'Admin Files';
}

$mode = 'admin';
$cloudConnected = false;
$prefs = ['folder' => '', 'conflict' => 'rename'];

ob_start();
?>
<?php if ($filterUser !== null): ?>
    <a class="d-inline-block mb-3" href="/admin/users.php">&larr; Users</a>
<?php else: ?>
    <a class="d-inline-block mb-3" href="/admin/">&larr; Admin</a>
<?php endif; ?>
<?php if ($filterUser !== null): ?>
    <p class="text-secondary mb-3">
        Host files for <strong><?= e((string) $filterUser['username']) ?></strong>.
        <a href="/admin/history.php?user_id=<?= $filterUserId ?>">History</a>
        ·
        <a href="/admin/files.php">All files</a>
    </p>
<?php else: ?>
    <p class="text-secondary mb-3">Every file on the host, with owner and public-link controls.</p>
<?php endif; ?>


<div class="card">
    <?php if (!$files): ?>
        <div class="card-header d-flex justify-content-between align-items-center">
            <h2 class="card-title mb-0"><?= e($panelTitle) ?></h2>
        </div>
        <div class="empty">
            <p class="empty-title">No files stored</p>
            <p class="empty-subtitle text-secondary"><?php if ($filterUser !== null): ?>
                This user has nothing on the host yet.
            <?php else: ?>
                Nothing has been downloaded yet.
            <?php endif; ?></p>
        </div>
    <?php else: ?>
        <?php
        include dirname(__DIR__, 2) . '/templates/partials/files_table.php';
        ?>
    <?php endif; ?>
</div>

<?php
if ($files) {
    include dirname(__DIR__, 2) . '/templates/partials/files_modals.php';
    include dirname(__DIR__, 2) . '/templates/partials/files_js.php';
}
View::render($pageTitle, ob_get_clean(), $admin);
