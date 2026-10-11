<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/src/bootstrap.php';

use App\Auth;
use App\DropboxOAuth;

Auth::requireLogin();
$oauth = new DropboxOAuth();
if (!$oauth->isConfigured()) {
    flash('error', 'Dropbox app is not configured.');
    redirect('/user/');
}

$state = bin2hex(random_bytes(16));
$_SESSION['dropbox_oauth_state'] = $state;
redirect($oauth->authorizeUrl($state));
