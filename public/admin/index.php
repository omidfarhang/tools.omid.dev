<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Auth;
use App\FileRepository;
use App\ShareService;
use App\UserRepository;
use App\View;

$user = Auth::requireAdmin();
$users = new UserRepository();
$files = new FileRepository();
$shares = new ShareService();
$pending = $users->countPending();

ob_start();
?>
<div class="page-header mb-3">
    <div class="text-secondary">System overview and shortcuts for user and file management.</div>
</div>

<?php if ($pending > 0): ?>
    <div class="alert alert-warning" role="alert">
        <?= (int) $pending ?> account<?= $pending === 1 ? '' : 's' ?> waiting for approval.
        <a href="/admin/users.php">Review users</a>
    </div>
<?php endif; ?>

<div class="row row-deck row-cards mb-3">
    <div class="col-sm-6 col-xl-3">
        <div class="card">
            <div class="card-body">
                <div class="subheader">Users</div>
                <div class="h1 mb-0"><?= (int) $users->count() ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card">
            <div class="card-body">
                <div class="subheader">Pending approval</div>
                <div class="h1 mb-0"><?= (int) $pending ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card">
            <div class="card-body">
                <div class="subheader">Files</div>
                <div class="h1 mb-0"><?= (int) $files->count() ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card">
            <div class="card-body">
                <div class="subheader">Active public links</div>
                <div class="h1 mb-0"><?= (int) $shares->countActive() ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row row-cards">
    <div class="col-md-6">
        <a class="card-link" href="/admin/users.php">
            <div class="card-body">
                <h3 class="card-title">Manage users</h3>
                <div class="text-secondary">Create accounts, approve registrations, reset passwords, and set roles.</div>
            </div>
        </a>
    </div>
    <div class="col-md-6">
        <a class="card-link" href="/admin/files.php">
            <div class="card-body">
                <h3 class="card-title">All files</h3>
                <div class="text-secondary">Browse every stored file, revoke public links, and delete as needed.</div>
            </div>
        </a>
    </div>
    <div class="col-md-6">
        <a class="card-link" href="/admin/settings/">
            <div class="card-body">
                <h3 class="card-title">Settings</h3>
                <div class="text-secondary">yt-dlp cookies and options, Dropbox app credentials, and other site config.</div>
            </div>
        </a>
    </div>
</div>
<?php
View::render('Admin', ob_get_clean(), $user);
