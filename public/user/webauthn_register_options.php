<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Auth;
use App\Csrf;
use App\WebAuthnService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

$user = Auth::requireLogin();
$body = read_json_body();
Csrf::requireValidToken(isset($body['_csrf']) && is_string($body['_csrf']) ? $body['_csrf'] : null);

try {
    $options = (new WebAuthnService())->creationOptions($user);
    json_response($options);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => 'Could not create passkey options.'], 500);
}
