<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Auth;
use App\AuthLog;
use App\ClientIp;
use App\Csrf;
use App\LoginRateLimiter;
use App\WebAuthnService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

if (Auth::check()) {
    json_response(['ok' => false, 'error' => 'Already signed in.'], 400);
}

$user = Auth::pendingTotpUser();
if ($user === null) {
    json_response(['ok' => false, 'error' => 'No pending login. Sign in again.'], 401);
}

$body = read_json_body();
Csrf::requireValidToken(isset($body['_csrf']) && is_string($body['_csrf']) ? $body['_csrf'] : null);

$username = (string) $user['username'];
$ip = ClientIp::resolve();
$limiter = new LoginRateLimiter();
if ($limiter->isLimited($ip, $username)) {
    AuthLog::write('LOCKOUT', $ip, $username, 'login');
    json_response(['ok' => false, 'error' => 'Too many attempts. Try again in a few minutes.'], 429);
}

$result = (new WebAuthnService())->assertionOptions((int) $user['id']);
if (isset($result['ok']) && $result['ok'] === false) {
    json_response($result, 400);
}

json_response($result);
