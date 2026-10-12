<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Auth;
use App\Csrf;
use App\FileRepository;
use App\ShareService;

$user = Auth::requireLogin();
Csrf::requireValid();

$action = (string) ($_POST['action'] ?? '');
$redirect = (string) ($_POST['redirect'] ?? '/files.php');
$redirect = $redirect === '/admin/files.php' ? '/admin/files.php' : '/files.php';
$rawIds = $_POST['ids'] ?? [];
if (!is_array($rawIds)) {
    $rawIds = [];
}
$ids = array_values(array_unique(array_filter(array_map('intval', $rawIds), static fn (int $id): bool => $id > 0)));

if ($ids === [] || !in_array($action, ['delete', 'revoke'], true)) {
    flash('error', 'No files selected.');
    redirect($redirect);
}

$repo = new FileRepository();
$shares = new ShareService();
$isAdmin = ($user['role'] ?? '') === 'admin';
$done = 0;
$skipped = 0;

foreach ($ids as $id) {
    $file = $repo->findById($id);
    if ($file === null) {
        $skipped++;
        continue;
    }

    $isOwner = (int) $file['user_id'] === (int) $user['id'];
    if (!$isOwner && !$isAdmin) {
        $skipped++;
        continue;
    }

    if ($action === 'delete') {
        $path = FileRepository::absolutePath((string) $file['stored_name']);
        if ($path !== null && is_file($path)) {
            unlink($path);
        }
        $repo->delete($id);
        $done++;
        continue;
    }

    $shares->revokeForFile($id);
    $done++;
}

if ($action === 'delete') {
    flash('success', $done === 1 ? 'Deleted 1 file.' : "Deleted {$done} files." . ($skipped > 0 ? " Skipped {$skipped}." : ''));
} else {
    flash('success', $done === 1 ? 'Revoked 1 public link.' : "Revoked {$done} public links." . ($skipped > 0 ? " Skipped {$skipped}." : ''));
}

redirect($redirect);
