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

$contentType = $mime;
if ($kind === 'text' && !str_contains($contentType, 'charset=')) {
    $contentType .= '; charset=utf-8';
}

$safeName = str_replace(['"', "\r", "\n"], '', $originalName);

header('Content-Type: ' . $contentType);
header('Content-Disposition: inline; filename="' . $safeName . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

$needsRanges = $kind === 'audio' || $kind === 'video';
if ($needsRanges) {
    header('Accept-Ranges: bytes');
}

$rangeHeader = $_SERVER['HTTP_RANGE'] ?? '';
if ($needsRanges && is_string($rangeHeader) && preg_match('/^bytes=(\d*)-(\d*)$/', $rangeHeader, $m) === 1) {
    $start = $m[1] !== '' ? (int) $m[1] : 0;
    $end = $m[2] !== '' ? (int) $m[2] : ($size - 1);

    if ($size === 0 || $start >= $size || $start > $end) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }

    $end = min($end, $size - 1);
    $length = $end - $start + 1;

    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    header('Content-Length: ' . (string) $length);

    $fp = fopen($path, 'rb');
    if ($fp === false) {
        http_response_code(500);
        echo 'Could not read file.';
        exit;
    }
    fseek($fp, $start);
    $remaining = $length;
    while ($remaining > 0 && !feof($fp)) {
        $chunk = fread($fp, min(8192, $remaining));
        if ($chunk === false || $chunk === '') {
            break;
        }
        echo $chunk;
        $remaining -= strlen($chunk);
    }
    fclose($fp);
    exit;
}

header('Content-Length: ' . (string) $size);
readfile($path);
exit;
