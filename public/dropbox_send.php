<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Auth;
use App\Csrf;
use App\DropboxClient;
use App\FileRepository;
use App\ShareService;
use App\UserRepository;

$user = Auth::requireLogin();
$users = new UserRepository();
$user = $users->findById((int) $user['id']) ?? $user;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/files.php');
}

Csrf::requireValid();

$fileId = (int) ($_POST['file_id'] ?? 0);
$prefs = UserRepository::dropboxPrefs($user);
$folder = UserRepository::normalizeFolder((string) ($_POST['dropbox_folder'] ?? $prefs['folder']));
$conflict = UserRepository::normalizeConflict((string) ($_POST['dropbox_conflict'] ?? $prefs['conflict']));
$deleteAfter = isset($_POST['delete_after']) && (string) $_POST['delete_after'] === '1';

$files = new FileRepository();
$file = $files->findById($fileId);
if ($file === null || (int) $file['user_id'] !== (int) $user['id']) {
    flash('error', 'File not found.');
    redirect('/files.php');
}

$dropbox = new DropboxClient($user);
if (!$dropbox->isConnected()) {
    flash('error', 'Connect your Dropbox account first (Account page).');
    redirect('/user/');
}

$shares = new ShareService();
$token = $shares->enable($fileId, (int) $user['id']);
$fetchUrl = ShareService::publicUrl($token);

$sent = $dropbox->startJob(
    $fetchUrl,
    (string) $file['original_name'],
    $folder,
    $conflict,
    $deleteAfter,
    $fileId
);

if (!$sent['success']) {
    flash('error', ($sent['message'] ?? 'Dropbox failed') . (isset($sent['error']) ? ' — ' . $sent['error'] : ''));
    redirect('/files.php');
}

if (($sent['status'] ?? '') === 'skipped') {
    flash('success', $sent['message'] ?? 'Skipped — file already exists in Dropbox.');
    redirect('/files.php');
}

$_SESSION['_dropbox_monitor'] = [
    'asyncJobId' => $sent['async_job_id'] ?? null,
    'filename' => $sent['filename'] ?? $file['original_name'],
    'jobId' => $sent['job_id'] ?? null,
];

flash('success', 'Sending to Dropbox started.');
redirect('/files.php');
