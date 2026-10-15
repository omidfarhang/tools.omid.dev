<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/src/bootstrap.php';

use App\Auth;
use App\GoogleDriveOAuth;

Auth::requireLogin();
$oauth = new GoogleDriveOAuth();
if (!$oauth->isConfigured()) {
    flash('error', 'Google Drive app is not configured.');
    redirect('/user/');
}

$reconnect = isset($_GET['reconnect']) && (string) $_GET['reconnect'] !== '0';
$state = bin2hex(random_bytes(16));
$_SESSION['google_drive_oauth_state'] = $state;
$_SESSION['google_drive_oauth_reconnect'] = $reconnect;
// Always prompt consent so Google returns a refresh token for offline uploads.
redirect($oauth->authorizeUrl($state, true));
