<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Auth;
use App\DropboxClient;
use App\UserRepository;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'POST required'], 405);
}

$user = Auth::attemptCliToken();
if ($user === null) {
    json_response(['success' => false, 'error' => 'Unauthorized'], 401);
}

$url = trim((string) ($_POST['url'] ?? ''));
$filename = trim((string) ($_POST['filename'] ?? ''));
if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
    json_response(['success' => false, 'error' => 'Valid url required'], 400);
}

$client = new DropboxClient($user);
if (!$client->isConnected()) {
    json_response(['success' => false, 'error' => 'CLI user has not connected Dropbox'], 400);
}

$prefs = UserRepository::dropboxPrefs($user);
$result = $client->startJob(
    $url,
    $filename !== '' ? $filename : null,
    $prefs['folder'],
    $prefs['conflict'],
    false,
    null
);
json_response($result, $result['success'] ? 200 : 400);
