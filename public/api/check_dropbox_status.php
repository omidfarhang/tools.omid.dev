<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Auth;
use App\Csrf;
use App\DropboxClient;

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

$client = new DropboxClient($user);
json_response($client->checkJobStatus($asyncJobId));
