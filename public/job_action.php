<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Auth;
use App\Csrf;
use App\DownloadJobDispatcher;
use App\DownloadJobRepository;

$user = Auth::requireLogin();
Csrf::requireValid();

$action = (string) ($_POST['action'] ?? '');
$id = (int) ($_POST['id'] ?? 0);
$return = (string) ($_POST['return'] ?? '/');
if ($return === '' || !str_starts_with($return, '/') || str_starts_with($return, '//')) {
    $return = '/';
}

$jobs = new DownloadJobRepository();
$job = $id > 0 ? $jobs->findById($id) : null;
if ($job === null) {
    flash('error', 'Job not found.');
    redirect($return);
}

$isAdmin = ($user['role'] ?? '') === 'admin';
$ownerId = (int) $job['user_id'];
if ($ownerId !== (int) $user['id'] && !$isAdmin) {
    flash('error', 'You cannot modify that job.');
    redirect($return);
}

if ($action === 'cancel') {
    if ($jobs->cancel($id, $ownerId)) {
        flash('success', 'Job cancelled.');
    } else {
        flash('error', 'Could not cancel that job.');
    }
    redirect($return);
}

if ($action === 'retry') {
    if ($jobs->retry($id, $ownerId)) {
        flash('success', 'Download re-queued.');
        DownloadJobDispatcher::dispatch($id);
    } else {
        flash('error', 'Could not retry that job.');
    }
    redirect($return);
}

flash('error', 'Unknown action.');
redirect($return);
