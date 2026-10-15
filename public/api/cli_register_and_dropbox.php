<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Auth;
use App\Cloud\CloudSendService;
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

$sizeBytes = (int) filesize($path);
$users = new UserRepository();
$user = $users->findById((int) $user['id']) ?? $user;
if ($users->wouldExceedQuota($user, $sizeBytes)) {
    $used = $users->usedBytes((int) $user['id']);
    $quota = UserRepository::effectiveQuotaBytes($user);
    json_response([
        'success' => false,
        'error' => sprintf(
            'Storage quota exceeded (%s). Delete files or ask an admin to raise your limit.',
            UserRepository::formatUsageLabel($used, $quota)
        ),
    ], 400);
}

$files = new FileRepository();
$fileId = $files->create(
    (int) $user['id'],
    $filename,
    $filename,
    $sizeBytes,
    null
);

$shares = new ShareService();
$token = $shares->enable($fileId, (int) $user['id'], purpose: 'cloud_fetch');
$publicUrl = ShareService::publicUrl($token);

$cloud = CloudSendService::create();
$provider = $cloud->resolveProvider($user);
if ($provider === null || !$provider->isConnected($user)) {
    json_response([
        'success' => false,
        'error' => 'CLI user has not connected a cloud account',
        'file_id' => $fileId,
        'share_url' => $publicUrl,
    ], 400);
}

$prefs = UserRepository::cloudPrefs($user);
$result = $cloud->startForUser(
    $user,
    $publicUrl,
    $filename,
    $prefs['folder'],
    $prefs['conflict'],
    false,
    $fileId,
    $provider->id()
);
$result['file_id'] = $fileId;
$result['share_url'] = $publicUrl;
json_response($result, $result['success'] ? 200 : 400);
