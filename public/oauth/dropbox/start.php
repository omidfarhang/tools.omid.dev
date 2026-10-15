<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/src/bootstrap.php';

use App\Auth;
use App\DropboxOAuth;

Auth::requireLogin();
$oauth = new DropboxOAuth();
if (!$oauth->isConfigured()) {
    flash('error', 'Dropbox app is not configured.');
    redirect('/user/cloud.php');
}

$reconnect = isset($_GET['reconnect']) && (string) $_GET['reconnect'] !== '0';
$state = bin2hex(random_bytes(16));
$_SESSION['dropbox_oauth_state'] = $state;
$_SESSION['dropbox_oauth_reconnect'] = $reconnect;
redirect($oauth->authorizeUrl($state, $reconnect));
