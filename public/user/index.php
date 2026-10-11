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
<h1>Account</h1>
<p class="text-muted">Signed in as <strong><?= e($user['username']) ?></strong> (<?= e($user['role']) ?>).</p>

<h2 class="h4 mt-4">Dropbox</h2>
<?php if (!$oauth->isConfigured()): ?>
    <div class="alert alert-warning">Dropbox app credentials are not configured on this server.</div>
<?php elseif ($connected): ?>
    <div class="alert alert-success">
        Dropbox connected<?= $user['dropbox_account_id'] ? ' (account ' . e((string) $user['dropbox_account_id']) . ')' : '' ?>.
    </div>
    <form method="post">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="disconnect_dropbox">
        <button class="btn btn-outline-danger" type="submit">Disconnect Dropbox</button>
    </form>
<?php else: ?>
    <p>Connect your own Dropbox to use “Save To Dropbox”.</p>
    <a class="btn btn-primary" href="/oauth/dropbox/start.php">Connect Dropbox</a>
<?php endif; ?>

<p class="mt-4"><a href="/files.php">Manage my files &amp; public links</a></p>
<?php
View::render('Account', ob_get_clean(), $user);
