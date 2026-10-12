<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Auth;
use App\Cloud\CloudSendService;
use App\Csrf;
use App\DownloadJobDispatcher;
use App\DownloadJobRepository;

header('Content-Type: application/json');

if (!Auth::check()) {
    json_response(['success' => false, 'error' => 'Unauthorized'], 401);
}

$token = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!Csrf::validate(is_string($token) ? $token : null)) {
    json_response(['success' => false, 'error' => 'Invalid CSRF'], 403);
}

$user = Auth::user();
if ($user === null) {
    json_response(['success' => false, 'error' => 'Unauthorized'], 401);
}

DownloadJobDispatcher::dispatchPending(5);

$jobRepo = new DownloadJobRepository();
$active = $jobRepo->activeForUser((int) $user['id']);
$cloud = CloudSendService::create();

foreach ($active as $job) {
    $asyncId = (string) ($job['cloud_async_job_id'] ?? '');
    $cloudStatus = (string) ($job['cloud_status'] ?? '');
    if ($asyncId !== '' && in_array($cloudStatus, ['pending', 'in_progress'], true)) {
        $provider = (string) ($job['cloud_provider'] ?? 'dropbox');
        $cloud->checkStatus($user, $asyncId, $provider !== '' ? $provider : 'dropbox');
    }
}

$jobs = $jobRepo->homeForUser((int) $user['id'], 10);
$activeCount = 0;
foreach ($jobs as $job) {
    if (DownloadJobRepository::isActiveRow($job)) {
        $activeCount++;
    }
}

json_response([
    'success' => true,
    'active_count' => $activeCount,
    'jobs' => array_map(static function (array $job): array {
        return [
            'id' => (int) $job['id'],
            'source_url' => (string) $job['source_url'],
            'requested_filename' => $job['requested_filename'] ?? null,
            'result_filename' => $job['result_filename'] ?? null,
            'behavior' => (string) $job['behavior'],
            'status' => (string) $job['status'],
            'error' => $job['error'] ?? null,
            'file_id' => isset($job['file_id']) && $job['file_id'] !== null ? (int) $job['file_id'] : null,
            'cloud_job_id' => isset($job['cloud_job_id']) && $job['cloud_job_id'] !== null ? (int) $job['cloud_job_id'] : null,
            'cloud_status' => $job['cloud_status'] ?? null,
            'cloud_async_job_id' => $job['cloud_async_job_id'] ?? null,
            'cloud_provider' => $job['cloud_provider'] ?? null,
            'cloud_path' => $job['cloud_result_path'] ?? $job['cloud_remote_path'] ?? null,
            'cloud_error' => $job['cloud_error'] ?? null,
            'updated_at' => (string) ($job['updated_at'] ?? $job['created_at'] ?? ''),
            'active' => DownloadJobRepository::isActiveRow($job),
        ];
    }, $jobs),
]);
