<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Auth;
use App\Csrf;
use App\DropboxOAuth;
use App\UserRepository;
use App\View;

$user = Auth::requireLogin();
$users = new UserRepository();
$user = $users->findById((int) $user['id']) ?? $user;
$oauth = new DropboxOAuth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'disconnect_dropbox') {
        $users->clearDropbox((int) $user['id']);
        flash('success', 'Dropbox disconnected.');
        redirect('/user/');
    }
}

$connected = $users->hasDropbox($user);

ob_start();
?>
<div class="page-header">
    <p class="page-lede">Profile and Dropbox connection for this account.</p>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="panel h-100">
            <h2 class="panel-title">Profile</h2>
            <dl class="mb-0">
                <dt class="text-muted small">Username</dt>
                <dd class="mb-3 fw-semibold"><?= e($user['username']) ?></dd>
                <dt class="text-muted small">Role</dt>
                <dd class="mb-0"><span class="user-chip"><span class="user-chip-dot" aria-hidden="true"></span><?= e($user['role']) ?></span></dd>
            </dl>
            <p class="mt-4 mb-0"><a href="/files.php">Manage my files &amp; public links</a></p>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="panel h-100">
            <h2 class="panel-title">Dropbox</h2>
            <?php if (!$oauth->isConfigured()): ?>
                <div class="alert alert-warning mb-0">Dropbox app credentials are not configured on this server.</div>
            <?php elseif ($connected): ?>
                <div class="alert alert-success">
                    Dropbox connected<?= $user['dropbox_account_id'] ? ' (account <span class="mono">' . e((string) $user['dropbox_account_id']) . '</span>)' : '' ?>.
                </div>
                <form method="post">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="disconnect_dropbox">
                    <button class="btn btn-outline-danger" type="submit">Disconnect Dropbox</button>
                </form>
            <?php else: ?>
                <p class="page-lede mb-3">Connect your own Dropbox to use “Save To Dropbox”.</p>
                <a class="btn btn-primary" href="/oauth/dropbox/start.php">Connect Dropbox</a>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php
View::render('Account', ob_get_clean(), $user);
