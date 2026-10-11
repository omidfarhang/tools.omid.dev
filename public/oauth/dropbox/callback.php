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
unset($_SESSION['dropbox_oauth_state']);

if ($state === '' || $expected === '' || !hash_equals($expected, $state)) {
    flash('error', 'Invalid OAuth state.');
    redirect('/user/');
}

if (!empty($_GET['error'])) {
    flash('error', 'Dropbox authorization denied.');
    redirect('/user/');
}

$code = (string) ($_GET['code'] ?? '');
if ($code === '') {
    flash('error', 'Missing authorization code.');
    redirect('/user/');
}

$result = $oauth->exchangeCode($code);
if (!$result['ok']) {
    flash('error', 'Dropbox connect failed: ' . ($result['error'] ?? 'unknown'));
    redirect('/user/');
}

$expiresAt = null;
if (!empty($result['expires_in'])) {
    $expiresAt = gmdate('Y-m-d H:i:s', time() + (int) $result['expires_in']);
}

(new UserRepository())->saveDropboxTokens(
    (int) $user['id'],
    (string) ($result['account_id'] ?? ''),
    (string) $result['access_token'],
    $result['refresh_token'] ?? null,
    $expiresAt
);

flash('success', 'Dropbox connected.');
redirect('/user/');
