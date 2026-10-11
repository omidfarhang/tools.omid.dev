<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Auth;
use App\DropboxClient;
use App\FileRepository;
use App\ShareService;
use App\UserRepository;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'POST required'], 405);
}

$user = Auth::attemptCliToken();
if ($user === null) {
    json_response(['success' => false, 'error' => 'Unauthorized'], 401);
}

$filename = basename((string) ($_POST['filename'] ?? ''));
if ($filename === '' || $filename === '.' || $filename === '..') {
    json_response(['success' => false, 'error' => 'filename required'], 400);
}

$path = FileRepository::absolutePath($filename);
if ($path === null || !is_file($path)) {
    json_response(['success' => false, 'error' => 'File not found in temp storage'], 404);
}

$files = new FileRepository();
$fileId = $files->create(
    (int) $user['id'],
    $filename,
    $filename,
    (int) filesize($path),
    null
);

$shares = new ShareService();
$token = $shares->enable($fileId, (int) $user['id']);
$publicUrl = ShareService::publicUrl($token);

$client = new DropboxClient($user);
if (!$client->isConnected()) {
    json_response([
        'success' => false,
        'error' => 'CLI user has not connected Dropbox',
        'file_id' => $fileId,
        'share_url' => $publicUrl,
    ], 400);
}

$prefs = UserRepository::dropboxPrefs($user);
$result = $client->startJob(
    $publicUrl,
    $filename,
    $prefs['folder'],
    $prefs['conflict'],
    false,
    $fileId
);
$result['file_id'] = $fileId;
$result['share_url'] = $publicUrl;
json_response($result, $result['success'] ? 200 : 400);
