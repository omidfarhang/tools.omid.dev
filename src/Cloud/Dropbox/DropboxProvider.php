<?php

declare(strict_types=1);

namespace App\Cloud\Dropbox;

use App\Cloud\CloudJobFinalizer;
use App\Cloud\CloudJobRepository;
use App\Cloud\CloudProvider;
use App\DropboxOAuth;
use App\UserRepository;

final class DropboxProvider implements CloudProvider
{
    public function __construct(
        private ?UserRepository $users = null,
        private ?DropboxOAuth $oauth = null,
        private ?CloudJobRepository $jobs = null,
        private ?CloudJobFinalizer $finalizer = null,
    ) {
        $this->users = $users ?? new UserRepository();
        $this->oauth = $oauth ?? new DropboxOAuth();
        $this->jobs = $jobs ?? new CloudJobRepository();
        $this->finalizer = $finalizer ?? new CloudJobFinalizer(jobs: $this->jobs);
    }

    public function id(): string
    {
        return 'dropbox';
    }

    public function isConfigured(): bool
    {
        return $this->oauth->isConfigured();
    }

    public function isConnected(array $user): bool
    {
        return $this->users->hasDropbox($user);
    }

    /**
     * Refresh stored Dropbox email / display name via account_info.read.
     *
     * @param array<string, mixed> $user
     * @return array{ok:bool, email?:string, display_name?:string, error?:string}
     */
    public function syncAccountInfo(array &$user): array
    {
        $token = $this->accessToken($user);
        if ($token === null) {
            return ['ok' => false, 'error' => 'not_connected'];
        }

        $account = $this->oauth->getCurrentAccount($token);
        if (!$account['ok']) {
            return ['ok' => false, 'error' => $account['error'] ?? 'Account info failed'];
        }

        $email = ($account['email'] ?? '') !== '' ? (string) $account['email'] : null;
        $displayName = ($account['display_name'] ?? '') !== '' ? (string) $account['display_name'] : null;

        $this->users->saveDropboxTokens(
            (int) $user['id'],
            (string) ($account['account_id'] ?? $user['dropbox_account_id'] ?? ''),
            $token,
            null,
            $user['dropbox_token_expires_at'] ?? null,
            $email,
            $displayName
        );

        $user = $this->users->findById((int) $user['id']) ?? $user;

        return [
            'ok' => true,
            'email' => $email ?? '',
            'display_name' => $displayName ?? '',
        ];
    }

    public function startJob(
        array $user,
        string $url,
        ?string $filename,
        string $folder,
        string $conflict,
        bool $deleteAfter,
        ?int $fileId = null,
    ): array {
        if (!$this->isConnected($user)) {
            return ['success' => false, 'message' => 'Dropbox is not connected.', 'error' => 'not_connected'];
        }

        if ($filename === null || $filename === '') {
            $filename = basename(parse_url($url, PHP_URL_PATH) ?: 'file.bin');
        }
        $filename = basename(str_replace('\\', '/', $filename));
        if ($filename === '' || $filename === '.' || $filename === '..') {
            $filename = 'file.bin';
        }

        $conflict = UserRepository::normalizeConflict($conflict);
        $desiredPath = self::buildPath($folder, $filename);

        $jobId = $this->jobs->create([
            'user_id' => (int) $user['id'],
            'provider' => $this->id(),
            'file_id' => $fileId,
            'filename' => $filename,
            'remote_path' => $desiredPath,
            'conflict_mode' => $conflict,
            'delete_after' => $deleteAfter && $fileId !== null,
            'status' => 'pending',
        ]);

        $resolved = $this->resolveTargetPath($user, $desiredPath, $conflict);
        if (!$resolved['ok']) {
            $this->jobs->markFailed($jobId, $resolved['error'] ?? 'Could not resolve Dropbox path');
            return [
                'success' => false,
                'job_id' => $jobId,
                'filename' => $filename,
                'remote_path' => $desiredPath,
                'status' => 'failed',
                'message' => 'Failed to start Dropbox upload',
                'error' => $resolved['error'] ?? 'path_error',
            ];
        }

        if (!empty($resolved['skipped'])) {
            $reason = $resolved['error'] ?? 'File already exists in Dropbox';
            $this->jobs->markSkipped($jobId, $desiredPath, $reason);
            return [
                'success' => true,
                'job_id' => $jobId,
                'filename' => $filename,
                'remote_path' => $desiredPath,
                'status' => 'skipped',
                'message' => $reason,
            ];
        }

        $path = (string) $resolved['path'];
        $sent = $this->saveUrlAtPath($user, $url, $path);
        if (!$sent['success']) {
            $this->jobs->markFailed($jobId, (string) ($sent['error'] ?? 'Unknown error'));
            return [
                'success' => false,
                'job_id' => $jobId,
                'filename' => $filename,
                'remote_path' => $path,
                'status' => 'failed',
                'message' => $sent['message'] ?? 'Failed to start Dropbox upload',
                'error' => $sent['error'] ?? 'Unknown error',
            ];
        }

        $asyncId = (string) $sent['async_job_id'];
        $this->jobs->markInProgress($jobId, $asyncId, $path);

        return [
            'success' => true,
            'job_id' => $jobId,
            'async_job_id' => $asyncId,
            'filename' => $filename,
            'remote_path' => $path,
            'status' => 'in_progress',
            'message' => 'File upload to Dropbox started successfully!',
        ];
    }

    public function checkJobStatus(array $user, string $asyncJobId): array
    {
        $token = $this->accessToken($user);
        if ($token === null) {
            return ['success' => false, 'error' => 'Dropbox is not connected.'];
        }

        $decoded = $this->apiJson(
            'https://api.dropboxapi.com/2/files/save_url/check_job_status',
            ['async_job_id' => $asyncJobId],
            $token
        );

        if (!$decoded['ok'] || !is_array($decoded['data'])) {
            return [
                'success' => false,
                'error' => $decoded['error'] ?? 'Unknown error',
            ];
        }

        $data = $decoded['data'];
        $status = (string) ($data['.tag'] ?? 'unknown');
        $job = $this->jobs->findByAsyncJobId($asyncJobId);
        $deletedLocal = false;

        if ($job !== null) {
            if ($status === 'complete') {
                $resultPath = null;
                if (isset($data['path_display']) && is_string($data['path_display'])) {
                    $resultPath = $data['path_display'];
                } elseif (isset($data['path_lower']) && is_string($data['path_lower'])) {
                    $resultPath = $data['path_lower'];
                }
                if (($job['status'] ?? '') !== 'complete') {
                    $this->jobs->markComplete((int) $job['id'], $resultPath);
                    $deletedLocal = $this->finalizer->onComplete($job);
                }
                $job = $this->jobs->findById((int) $job['id']);
            } elseif ($status === 'failed') {
                $err = is_array($data['error'] ?? null)
                    ? (string) ($data['error']['.tag'] ?? 'failed')
                    : (string) ($data['error_summary'] ?? 'failed');
                if (($job['status'] ?? '') !== 'failed') {
                    $this->jobs->markFailed((int) $job['id'], $err);
                }
                $job = $this->jobs->findById((int) $job['id']);
            } elseif ($status === 'in_progress' && ($job['status'] ?? '') === 'pending') {
                $this->jobs->markInProgress((int) $job['id'], $asyncJobId, (string) $job['remote_path']);
                $job = $this->jobs->findById((int) $job['id']);
            }
        }

        return [
            'success' => true,
            'status' => $status,
            'data' => $data,
            'job' => $job,
            'deleted_local' => $deletedLocal,
        ];
    }

    public static function normalizeFolder(string $folder): string
    {
        return UserRepository::normalizeFolder($folder);
    }

    public static function buildPath(string $folder, string $filename): string
    {
        $filename = basename(str_replace('\\', '/', $filename));
        if ($filename === '' || $filename === '.' || $filename === '..') {
            $filename = 'file.bin';
        }
        $folder = self::normalizeFolder($folder);

        return $folder === '' ? '/' . $filename : '/' . $folder . '/' . $filename;
    }

    /** @return array{ok:bool, path?:string, skipped?:bool, error?:string} */
    private function resolveTargetPath(array $user, string $desiredPath, string $conflict): array
    {
        $exists = $this->pathExists($user, $desiredPath);
        if ($exists === null) {
            return ['ok' => false, 'error' => 'Could not check Dropbox path'];
        }

        if (!$exists) {
            return ['ok' => true, 'path' => $desiredPath];
        }

        if ($conflict === 'skip') {
            return ['ok' => true, 'skipped' => true, 'path' => $desiredPath, 'error' => 'File already exists in Dropbox'];
        }

        if ($conflict === 'overwrite') {
            if (!$this->deletePath($user, $desiredPath)) {
                return ['ok' => false, 'error' => 'Could not overwrite existing Dropbox file'];
            }

            return ['ok' => true, 'path' => $desiredPath];
        }

        $path = $this->nextAvailablePath($user, $desiredPath);
        if ($path === null) {
            return ['ok' => false, 'error' => 'Could not find an available Dropbox filename'];
        }

        return ['ok' => true, 'path' => $path];
    }

    private function nextAvailablePath(array $user, string $desiredPath): ?string
    {
        $dir = dirname($desiredPath);
        $base = basename($desiredPath);
        $dot = strrpos($base, '.');
        if ($dot !== false && $dot > 0) {
            $stem = substr($base, 0, $dot);
            $ext = substr($base, $dot);
        } else {
            $stem = $base;
            $ext = '';
        }
        $prefix = $dir === '/' ? '/' : rtrim($dir, '/') . '/';

        for ($i = 1; $i <= 99; $i++) {
            $candidate = $prefix . $stem . ' (' . $i . ')' . $ext;
            $exists = $this->pathExists($user, $candidate);
            if ($exists === false) {
                return $candidate;
            }
            if ($exists === null) {
                return null;
            }
        }

        return null;
    }

    private function pathExists(array $user, string $path): ?bool
    {
        $token = $this->accessToken($user);
        if ($token === null) {
            return null;
        }

        $ch = curl_init('https://api.dropboxapi.com/2/files/get_metadata');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'path' => $path,
                'include_deleted' => false,
            ]),
        ]);
        $response = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http === 200) {
            return true;
        }

        $decoded = is_string($response) ? json_decode($response, true) : null;
        $tag = is_array($decoded) ? ($decoded['error']['.tag'] ?? null) : null;
        if ($http === 409 && $tag === 'path') {
            $pathTag = $decoded['error']['path']['.tag'] ?? null;
            if ($pathTag === 'not_found') {
                return false;
            }
        }

        $summary = is_array($decoded) ? (string) ($decoded['error_summary'] ?? '') : '';
        if ($http === 409 && str_contains($summary, 'not_found')) {
            return false;
        }

        return null;
    }

    private function deletePath(array $user, string $path): bool
    {
        $token = $this->accessToken($user);
        if ($token === null) {
            return false;
        }

        $result = $this->apiJson(
            'https://api.dropboxapi.com/2/files/delete_v2',
            ['path' => $path],
            $token
        );

        if ($result['ok']) {
            return true;
        }

        $summary = (string) ($result['error'] ?? '');

        return str_contains($summary, 'not_found');
    }

    /** @return array{success:bool, async_job_id?:string, message?:string, error?:string} */
    private function saveUrlAtPath(array $user, string $url, string $path): array
    {
        $token = $this->accessToken($user);
        if ($token === null) {
            return ['success' => false, 'message' => 'Dropbox is not connected.', 'error' => 'not_connected'];
        }

        $result = $this->apiJson(
            'https://api.dropboxapi.com/2/files/save_url',
            ['path' => $path, 'url' => $url],
            $token
        );

        if ($result['ok'] && is_array($result['data']) && isset($result['data']['async_job_id'])) {
            return [
                'success' => true,
                'async_job_id' => (string) $result['data']['async_job_id'],
                'message' => 'File upload to Dropbox started successfully!',
            ];
        }

        return [
            'success' => false,
            'error' => $result['error'] ?? 'Unknown error',
            'message' => 'Failed to start Dropbox upload',
        ];
    }

    /** @return array{ok:bool, data?:array, error?:string} */
    private function apiJson(string $endpoint, array $payload, string $token): array
    {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);
        $response = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = is_string($response) ? json_decode($response, true) : null;
        if ($http === 200 && is_array($decoded)) {
            return ['ok' => true, 'data' => $decoded];
        }

        $error = 'Unknown error';
        if (is_array($decoded)) {
            $error = (string) ($decoded['error_summary'] ?? $decoded['error'] ?? 'Unknown error');
        }

        return ['ok' => false, 'error' => $error, 'data' => is_array($decoded) ? $decoded : null];
    }

    /** @param array<string, mixed> $user */
    private function accessToken(array &$user): ?string
    {
        $fresh = $this->users->findById((int) $user['id']);
        if ($fresh === null) {
            return null;
        }
        $user = $fresh;

        if (!empty($user['dropbox_access_token'])) {
            $expires = $user['dropbox_token_expires_at'] ?? null;
            if ($expires === null || strtotime((string) $expires) > time() + 60) {
                return (string) $user['dropbox_access_token'];
            }
        }

        $refresh = $user['dropbox_refresh_token'] ?? null;
        if (!$refresh) {
            return !empty($user['dropbox_access_token'])
                ? (string) $user['dropbox_access_token']
                : null;
        }

        $result = $this->oauth->refresh((string) $refresh);
        if (!$result['ok']) {
            return null;
        }

        $expiresAt = null;
        if (!empty($result['expires_in'])) {
            $expiresAt = gmdate('Y-m-d H:i:s', time() + (int) $result['expires_in']);
        }

        $this->users->saveDropboxTokens(
            (int) $user['id'],
            (string) ($user['dropbox_account_id'] ?? $result['account_id'] ?? ''),
            (string) $result['access_token'],
            $result['refresh_token'] ?? null,
            $expiresAt
        );

        $user = $this->users->findById((int) $user['id']) ?? $user;

        return (string) $result['access_token'];
    }
}
