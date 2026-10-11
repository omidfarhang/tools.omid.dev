<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\FileRepository;
use App\ShareService;

$token = (string) ($_GET['token'] ?? '');
if ($token === '' || !preg_match('/^[a-f0-9]{64}$/i', $token)) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$share = (new ShareService())->findActiveByToken($token);
if ($share === null) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$path = FileRepository::absolutePath((string) $share['stored_name']);
if ($path === null || !is_file($path)) {
    http_response_code(404);
    echo 'File missing.';
    exit;
}

header('Content-Type: application/octet-stream');
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: attachment; filename="' . str_replace('"', '', (string) $share['original_name']) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
