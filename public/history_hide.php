<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Auth;
use App\Cloud\CloudJobRepository;
use App\Csrf;
use App\DownloadJobRepository;

$user = Auth::requireLogin();
Csrf::requireValid();

$kind = (string) ($_POST['kind'] ?? '');
$id = (int) ($_POST['id'] ?? 0);
$userId = (int) $user['id'];
$return = (string) ($_POST['return'] ?? '/history.php');
if ($return === '' || !str_starts_with($return, '/') || str_starts_with($return, '//')) {
    $return = '/history.php';
}

$ok = false;
if ($id > 0 && $kind === 'download') {
    $ok = (new DownloadJobRepository())->hideForUser($id, $userId);
} elseif ($id > 0 && $kind === 'cloud') {
    $ok = (new CloudJobRepository())->hideForUser($id, $userId);
}

if ($ok) {
    flash('success', 'Removed from your history.');
} else {
    flash('error', 'Could not remove that history entry.');
}

redirect($return);
