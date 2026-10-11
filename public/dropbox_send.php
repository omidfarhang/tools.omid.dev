<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Auth;
use App\Cloud\CloudSendService;
use App\Csrf;
use App\FileRepository;
use App\UserRepository;

$user = Auth::requireLogin();
$users = new UserRepository();
$user = $users->findById((int) $user['id']) ?? $user;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/files.php');
}

Csrf::requireValid();

$fileId = (int) ($_POST['file_id'] ?? 0);
$prefs = UserRepository::cloudPrefs($user);
$folder = UserRepository::normalizeFolder((string) ($_POST['dropbox_folder'] ?? $_POST['cloud_folder'] ?? $prefs['folder']));
$conflict = UserRepository::normalizeConflict((string) ($_POST['dropbox_conflict'] ?? $_POST['cloud_conflict'] ?? $prefs['conflict']));
$deleteAfter = isset($_POST['delete_after']) && (string) $_POST['delete_after'] === '1';

$files = new FileRepository();
$file = $files->findById($fileId);
if ($file === null || (int) $file['user_id'] !== (int) $user['id']) {
    flash('error', 'File not found.');
    redirect('/files.php');
}

$cloud = CloudSendService::create();
$provider = $cloud->provider('dropbox');
if ($provider === null || !$provider->isConnected($user)) {
    flash('error', 'Connect your Dropbox account first (Account page).');
    redirect('/user/');
}

$sent = $cloud->sendLocalFile($user, $file, $folder, $conflict, $deleteAfter, 'dropbox');

if (!$sent['success']) {
    flash('error', ($sent['message'] ?? 'Cloud upload failed') . (isset($sent['error']) ? ' — ' . $sent['error'] : ''));
    redirect('/files.php');
}

if (($sent['status'] ?? '') === 'skipped') {
    flash('success', $sent['message'] ?? 'Skipped — file already exists in cloud.');
    redirect('/files.php');
}

$_SESSION['_cloud_monitor'] = [
    'asyncJobId' => $sent['async_job_id'] ?? null,
    'filename' => $sent['filename'] ?? $file['original_name'],
    'jobId' => $sent['job_id'] ?? null,
    'provider' => 'dropbox',
];

flash('success', 'Sending to cloud started.');
redirect('/files.php');
