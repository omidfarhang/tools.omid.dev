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
<div class="page-header">
    <p class="page-lede">System overview and shortcuts for user and file management.</p>
</div>

<?php if ($pending > 0): ?>
    <div class="status-callout">
        <?= (int) $pending ?> account<?= $pending === 1 ? '' : 's' ?> waiting for approval.
        <a href="/admin/users.php">Review users</a>
    </div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card">
            <div class="stat-label">Users</div>
            <div class="stat-value"><?= (int) $users->count() ?></div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card">
            <div class="stat-label">Pending approval</div>
            <div class="stat-value"><?= (int) $pending ?></div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card">
            <div class="stat-label">Files</div>
            <div class="stat-value"><?= (int) $files->count() ?></div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card">
            <div class="stat-label">Active public links</div>
            <div class="stat-value"><?= (int) $shares->countActive() ?></div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-md-6">
        <a class="action-tile" href="/admin/users.php">
            <div class="action-tile-title">Manage users</div>
            <p class="action-tile-desc">Create accounts, approve registrations, reset passwords, and set roles.</p>
        </a>
    </div>
    <div class="col-md-6">
        <a class="action-tile" href="/admin/files.php">
            <div class="action-tile-title">All files</div>
            <p class="action-tile-desc">Browse every stored file, revoke public links, and delete as needed.</p>
        </a>
    </div>
    <div class="col-md-6">
        <a class="action-tile" href="/admin/settings/">
            <div class="action-tile-title">Settings</div>
            <p class="action-tile-desc">yt-dlp cookies and options, Dropbox app credentials, and other site config.</p>
        </a>
    </div>
</div>
<?php
View::render('Admin', ob_get_clean(), $user);
