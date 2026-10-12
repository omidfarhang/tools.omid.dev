<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Auth;
use App\FileRepository;
use App\View;

$admin = Auth::requireAdmin();

$sortInfo = FileRepository::normalizeSort(
    isset($_GET['sort']) ? (string) $_GET['sort'] : null,
    isset($_GET['dir']) ? (string) $_GET['dir'] : null,
    true
);
$sort = $sortInfo['sort'];
$dir = $sortInfo['dir'];

$files = (new FileRepository())->allWithOwner($sort, $dir);

$mode = 'admin';
$redirect = '/admin/files.php';
$cloudConnected = false;
$prefs = ['folder' => '', 'conflict' => 'rename'];

ob_start();
?>
<a class="breadcrumb-dash" href="/admin/">&larr; Admin</a>
<div class="page-header">
    <p class="page-lede">Every file on the host, with owner and public-link controls.</p>
</div>

<div class="panel panel-flush">
    <?php if (!$files): ?>
        <div class="panel-head d-flex justify-content-between align-items-center">
            <h2 class="panel-title mb-0">All files</h2>
        </div>
        <div class="empty-state">
            <strong>No files stored</strong>
            Nothing has been downloaded yet.
        </div>
    <?php else: ?>
        <?php
        $panelTitle = 'All files';
        include dirname(__DIR__, 2) . '/templates/partials/files_table.php';
        ?>
    <?php endif; ?>
</div>

<?php
if ($files) {
    include dirname(__DIR__, 2) . '/templates/partials/files_modals.php';
    include dirname(__DIR__, 2) . '/templates/partials/files_js.php';
}
View::render('Admin Files', ob_get_clean(), $admin);
