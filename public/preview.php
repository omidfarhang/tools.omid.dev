<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Auth;
use App\FileRepository;
use App\MimeType;

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

$originalName = (string) $file['original_name'];
$mime = MimeType::detect($path, $originalName);
$kind = MimeType::previewKind($mime);
$size = (int) filesize($path);

if ($kind === null) {
    http_response_code(415);
    echo 'Preview not available for this file type.';
    exit;
}

if ($kind === 'text' && !MimeType::isTextPreviewAllowed($mime, $size)) {
    http_response_code(413);
    echo 'File too large to preview as text.';
    exit;
}

if ($kind === 'text' && !str_contains($mime, 'charset=')) {
    $mime .= '; charset=utf-8';
}

$safeName = str_replace(['"', "\r", "\n"], '', $originalName);

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) $size);
header('Content-Disposition: inline; filename="' . $safeName . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
exit;
