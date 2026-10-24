<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Auth;
use App\FileRepository;
use App\Pager;
use App\UserRepository;
use App\View;

$admin = Auth::requireAdmin();

$req = Pager::requestParams(true);
$filterUserId = $req['user_id'] ?? 0;
$filterUser = null;
if ($filterUserId > 0) {
    $filterUser = (new UserRepository())->findById($filterUserId);
    if ($filterUser === null) {
        flash('error', 'User not found.');
        redirect('/admin/users.php');
    }
}

$sortInfo = FileRepository::normalizeSort($req['sort'], $req['dir'], true);
$sort = $sortInfo['sort'];
$dir = $sortInfo['dir'];
$q = $req['q'];

$fileRepo = new FileRepository();
$pagerPath = '/admin/files.php';
$scopeUserId = $filterUser !== null ? $filterUserId : null;

$highlightFileId = isset($_GET['file']) ? (int) $_GET['file'] : 0;
if ($highlightFileId > 0) {
    $targetPage = $fileRepo->pageForFile(
        $highlightFileId,
        $scopeUserId,
        $sort,
        $dir,
        $q,
        Pager::DEFAULT_PER_PAGE,
        true
    );
    if ($targetPage === null) {
        flash('error', 'File not found.');
        redirect(Pager::url($pagerPath, [
            'user_id' => $scopeUserId,
            'sort' => $sort,
            'dir' => strtolower($dir),
            'q' => $q !== '' ? $q : null,
            'page' => $req['page'] > 1 ? $req['page'] : null,
        ]));
    }
    if ($targetPage !== $req['page']) {
        redirect(Pager::url($pagerPath, [
            'user_id' => $scopeUserId,
            'sort' => $sort,
            'dir' => strtolower($dir),
            'q' => $q !== '' ? $q : null,
            'page' => $targetPage > 1 ? $targetPage : null,
            'file' => $highlightFileId,
        ]));
    }
} else {
    $highlightFileId = 0;
}

if ($filterUser !== null) {
    $result = $fileRepo->forUserPaginated($filterUserId, $sort, $dir, $q, $req['page'], Pager::DEFAULT_PER_PAGE, true);
    $pagerQuery = [
        'user_id' => $filterUserId,
        'sort' => $sort,
        'dir' => strtolower($dir),
        'q' => $q !== '' ? $q : null,
        'file' => $highlightFileId > 0 ? $highlightFileId : null,
    ];
    $panelTitle = 'Files · ' . (string) $filterUser['username'];
    $pageTitle = 'User files';
} else {
    $result = $fileRepo->allWithOwnerPaginated($sort, $dir, $q, $req['page']);
    $pagerQuery = [
        'sort' => $sort,
        'dir' => strtolower($dir),
        'q' => $q !== '' ? $q : null,
        'file' => $highlightFileId > 0 ? $highlightFileId : null,
    ];
    $panelTitle = 'All files';
    $pageTitle = 'Files';
}

$files = $result['items'];
$redirect = Pager::url($pagerPath, $pagerQuery, ['page' => $result['page'] > 1 ? $result['page'] : null]);
$pagerTotal = $result['total'];
$pagerPage = $result['page'];
$pagerTotalPages = $result['total_pages'];
$pagerPerPage = $result['per_page'];

$mode = 'admin';
$cloudConnected = false;
$prefs = ['folder' => '', 'conflict' => 'rename'];

ob_start();
?>
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
    <?php if ($pagerTotal === 0 && $q === ''): ?>
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
$breadcrumbs = $filterUser !== null
    ? [
        ['label' => 'Admin', 'href' => '/admin/'],
        ['label' => 'Users', 'href' => '/admin/users.php'],
        ['label' => (string) $filterUser['username'] . ' · Files'],
    ]
    : [
        ['label' => 'Admin', 'href' => '/admin/'],
        ['label' => 'Files'],
    ];
View::render($pageTitle, ob_get_clean(), $admin, $breadcrumbs);
