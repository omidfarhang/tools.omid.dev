<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Auth;
use App\Csrf;
use App\Download\FilenameHelper;
use App\FileRepository;

$user = Auth::requireLogin();
Csrf::requireValid();

$id = (int) ($_POST['id'] ?? 0);
$name = trim((string) ($_POST['original_name'] ?? ''));
$redirect = sanitize_files_redirect(isset($_POST['redirect']) ? (string) $_POST['redirect'] : null);

$repo = new FileRepository();
$file = $repo->findById($id);

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

if ($name === '') {
    flash('error', 'Enter a valid filename.');
    redirect($redirect);
}

$sanitized = FilenameHelper::sanitize($name);

$repo->rename($id, $sanitized);
flash('success', 'File renamed.');
redirect($redirect);
