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
<h1>Admin</h1>
<?php if ($pending > 0): ?>
    <div class="alert alert-warning">
        <?= (int) $pending ?> account<?= $pending === 1 ? '' : 's' ?> waiting for approval.
        <a href="/admin/users.php">Review users</a>
    </div>
<?php endif; ?>
<div class="row g-3 mt-2">
    <div class="col-md-3">
        <div class="border rounded p-3">
            <div class="text-muted">Users</div>
            <div class="fs-3"><?= (int) $users->count() ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="border rounded p-3">
            <div class="text-muted">Pending approval</div>
            <div class="fs-3"><?= (int) $pending ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="border rounded p-3">
            <div class="text-muted">Files</div>
            <div class="fs-3"><?= (int) $files->count() ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="border rounded p-3">
            <div class="text-muted">Active public links</div>
            <div class="fs-3"><?= (int) $shares->countActive() ?></div>
        </div>
    </div>
</div>
<ul class="mt-4">
    <li><a href="/admin/users.php">Manage users</a></li>
    <li><a href="/admin/files.php">All files</a></li>
</ul>
<?php
View::render('Admin', ob_get_clean(), $user);
