<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Auth;
use App\Csrf;
use App\FileRepository;

$user = Auth::requireLogin();
Csrf::requireValid();

$id = (int) ($_POST['id'] ?? 0);
$redirect = (string) ($_POST['redirect'] ?? '/files.php');
$redirect = $redirect === '/admin/files.php' ? '/admin/files.php' : '/files.php';

$repo = new FileRepository();
$file = $repo->findById($id);

if ($file === null) {
    flash('error', 'File not found.');
    redirect($redirect);
}

$isOwner = (int) $file['user_id'] === (int) $user['id'];
$isAdmin = ($user['role'] ?? '') === 'admin';
if (!$isOwner && !$isAdmin) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$path = FileRepository::absolutePath((string) $file['stored_name']);
if ($path !== null && is_file($path)) {
    unlink($path);
}
$repo->delete($id);

flash('success', 'File deleted.');
redirect($isAdmin && !$isOwner ? '/admin/files.php' : $redirect);
