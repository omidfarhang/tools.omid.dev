<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Auth;
use App\Csrf;
use App\FileRepository;

$user = Auth::requireLogin();

$token = $_GET['_csrf'] ?? '';
if (!Csrf::validate(is_string($token) ? $token : null)) {
    http_response_code(403);
    echo 'Invalid CSRF token.';
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
$repo = new FileRepository();
$file = $repo->findById($id);

if ($file === null) {
    flash('error', 'File not found.');
    redirect('/files.php');
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
redirect($isAdmin && !$isOwner ? '/admin/files.php' : '/files.php');
