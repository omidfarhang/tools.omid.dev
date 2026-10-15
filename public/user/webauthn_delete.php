<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Auth;
use App\Csrf;
use App\TrustedDeviceRepository;
use App\UserRepository;
use App\WebAuthnCredentialRepository;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

$user = Auth::requireLogin();
$body = read_json_body();
Csrf::requireValidToken(isset($body['_csrf']) && is_string($body['_csrf']) ? $body['_csrf'] : null);

$password = isset($body['password']) && is_string($body['password']) ? $body['password'] : '';
$hash = (string) ($user['password_hash'] ?? '');
if ($password === '' || $hash === '' || !password_verify($password, $hash)) {
    json_response(['ok' => false, 'error' => 'Current password is incorrect.'], 400);
}

$credentialPk = (int) ($body['id'] ?? 0);
if ($credentialPk <= 0) {
    json_response(['ok' => false, 'error' => 'Invalid passkey.'], 400);
}

$repo = new WebAuthnCredentialRepository();
if (!$repo->deleteForUser((int) $user['id'], $credentialPk)) {
    json_response(['ok' => false, 'error' => 'Passkey not found.'], 404);
}

$fresh = (new UserRepository())->findById((int) $user['id']) ?? $user;
if (!Auth::mfaRequired($fresh)) {
    (new TrustedDeviceRepository())->revokeAll((int) $user['id']);
}

json_response(['ok' => true]);
