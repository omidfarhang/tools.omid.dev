<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Auth;
use App\Csrf;
use App\FileRepository;
use App\ShareService;

$user = Auth::requireLogin();
Csrf::requireValid();

$fileId = (int) ($_POST['file_id'] ?? 0);
$action = (string) ($_POST['action'] ?? '');
$redirect = sanitize_files_redirect(isset($_POST['redirect']) ? (string) $_POST['redirect'] : null);

$files = new FileRepository();
$file = $files->findById($fileId);
if ($file === null) {
    flash('error', 'File not found.');
    redirect($redirect);
}

$isOwner = (int) $file['user_id'] === (int) $user['id'];
$isAdmin = ($user['role'] ?? '') === 'admin';
if (!$isOwner && !$isAdmin) {
    flash('error', 'Forbidden.');
    redirect('/files.php');
}

$shares = new ShareService();
if ($action === 'enable') {
    if (!$isOwner && !$isAdmin) {
        flash('error', 'Forbidden.');
        redirect('/files.php');
    }
    $token = $shares->enable($fileId, (int) $user['id']);
    flash('success', 'Public link enabled: ' . ShareService::publicUrl($token));
} elseif ($action === 'revoke') {
    $shares->revokeForFile($fileId);
    flash('success', 'Public link disabled.');
}

redirect($redirect);
