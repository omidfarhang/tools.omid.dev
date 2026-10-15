<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/src/bootstrap.php';

use App\Auth;
use App\DropboxOAuth;
use App\UserRepository;

$user = Auth::requireLogin();
$oauth = new DropboxOAuth();

$state = (string) ($_GET['state'] ?? '');
$expected = (string) ($_SESSION['dropbox_oauth_state'] ?? '');
$reconnect = !empty($_SESSION['dropbox_oauth_reconnect']);
unset($_SESSION['dropbox_oauth_state'], $_SESSION['dropbox_oauth_reconnect']);

if ($state === '' || $expected === '' || !hash_equals($expected, $state)) {
    flash('error', 'Invalid OAuth state.');
    redirect('/user/cloud.php');
}

if (!empty($_GET['error'])) {
    flash('error', $reconnect ? 'Dropbox reconnection denied.' : 'Dropbox authorization denied.');
    redirect('/user/cloud.php');
}

$code = (string) ($_GET['code'] ?? '');
if ($code === '') {
    flash('error', 'Missing authorization code.');
    redirect('/user/cloud.php');
}

$result = $oauth->exchangeCode($code);
if (!$result['ok']) {
    flash('error', ($reconnect ? 'Dropbox reconnect failed: ' : 'Dropbox connect failed: ') . ($result['error'] ?? 'unknown'));
    redirect('/user/cloud.php');
}

$expiresAt = null;
if (!empty($result['expires_in'])) {
    $expiresAt = gmdate('Y-m-d H:i:s', time() + (int) $result['expires_in']);
}

$account = $oauth->getCurrentAccount((string) $result['access_token']);
$accountId = (string) ($result['account_id'] ?? '');
$email = null;
$displayName = null;
if ($account['ok']) {
    if (($account['account_id'] ?? '') !== '') {
        $accountId = (string) $account['account_id'];
    }
    $email = ($account['email'] ?? '') !== '' ? (string) $account['email'] : null;
    $displayName = ($account['display_name'] ?? '') !== '' ? (string) $account['display_name'] : null;
}

(new UserRepository())->saveDropboxTokens(
    (int) $user['id'],
    $accountId,
    (string) $result['access_token'],
    $result['refresh_token'] ?? null,
    $expiresAt,
    $email,
    $displayName
);

flash('success', $reconnect ? 'Dropbox reconnected.' : 'Dropbox connected.');
redirect('/user/cloud.php');
