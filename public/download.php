<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Auth;
use App\FileRepository;

$user = Auth::requireLogin();
$id = (int) ($_GET['id'] ?? 0);
$file = (new FileRepository())->findById($id);

if ($file === null) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$isOwner = (int) $file['user_id'] === (int) $user['id'];
$isAdmin = ($user['role'] ?? '') === 'admin';
if (!$isOwner && !$isAdmin) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$path = FileRepository::absolutePath((string) $file['stored_name']);
if ($path === null || !is_file($path)) {
    http_response_code(404);
    echo 'File missing on disk.';
    exit;
}

header('Content-Type: application/octet-stream');
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: attachment; filename="' . str_replace('"', '', (string) $file['original_name']) . '"');
readfile($path);
exit;
