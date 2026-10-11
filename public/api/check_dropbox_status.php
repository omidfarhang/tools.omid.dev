<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Auth;
use App\Cloud\CloudSendService;
use App\Csrf;

header('Content-Type: application/json');

$user = Auth::attemptCliToken();
if ($user === null) {
    if (!Auth::check()) {
        json_response(['success' => false, 'error' => 'Unauthorized'], 401);
    }
    $token = $_POST['_csrf'] ?? '';
    if (!Csrf::validate(is_string($token) ? $token : null)) {
        json_response(['success' => false, 'error' => 'Invalid CSRF'], 403);
    }
    $user = Auth::user();
}

$asyncJobId = (string) ($_POST['async_job_id'] ?? '');
if ($asyncJobId === '') {
    json_response(['success' => false, 'error' => 'No async_job_id provided'], 400);
}

$providerId = (string) ($_POST['provider'] ?? 'dropbox');
if ($providerId === '') {
    $providerId = 'dropbox';
}

$cloud = CloudSendService::create();
json_response($cloud->checkStatus($user, $asyncJobId, $providerId));
