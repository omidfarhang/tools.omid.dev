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

$label = isset($body['label']) && is_string($body['label']) ? $body['label'] : '';
$credential = $body['credential'] ?? null;
if (!is_array($credential)) {
    json_response(['ok' => false, 'error' => 'Missing credential.'], 400);
}

try {
    $credentialJson = json_encode($credential, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    json_response(['ok' => false, 'error' => 'Invalid credential payload.'], 400);
}

$result = (new WebAuthnService())->completeRegistration($user, $credentialJson, $label);
if (!$result['ok']) {
    json_response($result, 400);
}

json_response($result);
