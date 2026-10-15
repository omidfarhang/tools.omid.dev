<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/src/bootstrap.php';

use App\Auth;
use App\GoogleDriveOAuth;
use App\UserRepository;

$user = Auth::requireLogin();
$oauth = new GoogleDriveOAuth();

$state = (string) ($_GET['state'] ?? '');
$expected = (string) ($_SESSION['google_drive_oauth_state'] ?? '');
$reconnect = !empty($_SESSION['google_drive_oauth_reconnect']);
unset($_SESSION['google_drive_oauth_state'], $_SESSION['google_drive_oauth_reconnect']);

if ($state === '' || $expected === '' || !hash_equals($expected, $state)) {
    flash('error', 'Invalid OAuth state.');
    redirect('/user/cloud.php');
}

if (!empty($_GET['error'])) {
    flash('error', $reconnect ? 'Google Drive reconnection denied.' : 'Google Drive authorization denied.');
    redirect('/user/cloud.php');
}

$code = (string) ($_GET['code'] ?? '');
if ($code === '') {
    flash('error', 'Missing authorization code.');
    redirect('/user/cloud.php');
}

$result = $oauth->exchangeCode($code);
if (!$result['ok']) {
    flash('error', ($reconnect ? 'Google Drive reconnect failed: ' : 'Google Drive connect failed: ') . ($result['error'] ?? 'unknown'));
    redirect('/user/cloud.php');
}

$expiresAt = null;
if (!empty($result['expires_in'])) {
    $expiresAt = gmdate('Y-m-d H:i:s', time() + (int) $result['expires_in']);
}

$account = $oauth->getCurrentAccount((string) $result['access_token']);
$accountId = '';
$email = null;
$displayName = null;
if ($account['ok']) {
    $accountId = (string) ($account['account_id'] ?? '');
    $email = ($account['email'] ?? '') !== '' ? (string) $account['email'] : null;
    $displayName = ($account['display_name'] ?? '') !== '' ? (string) $account['display_name'] : null;
}

(new UserRepository())->saveGoogleDriveTokens(
    (int) $user['id'],
    $accountId,
    (string) $result['access_token'],
    $result['refresh_token'] ?? null,
    $expiresAt,
    $email,
    $displayName
);

flash('success', $reconnect ? 'Google Drive reconnected.' : 'Google Drive connected.');
redirect('/user/cloud.php');
