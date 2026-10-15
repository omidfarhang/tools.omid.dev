<?php

declare(strict_types=1);

namespace App\Cloud\GoogleDrive;

use App\Cloud\CloudJobFinalizer;
use App\Cloud\CloudJobRepository;
use App\Cloud\CloudProvider;
use App\Config;
use App\FileRepository;
use App\GoogleDriveOAuth;
use App\UserRepository;

final class GoogleDriveProvider implements CloudProvider
{
    private const FOLDER_MIME = 'application/vnd.google-apps.folder';
    private const UPLOAD_URL = 'https://www.googleapis.com/upload/drive/v3/files';
    private const FILES_URL = 'https://www.googleapis.com/drive/v3/files';

    public function __construct(
        private ?UserRepository $users = null,
        private ?GoogleDriveOAuth $oauth = null,
        private ?CloudJobRepository $jobs = null,
        private ?CloudJobFinalizer $finalizer = null,
        private ?FileRepository $files = null,
    ) {
        $this->users = $users ?? new UserRepository();
        $this->oauth = $oauth ?? new GoogleDriveOAuth();
        $this->jobs = $jobs ?? new CloudJobRepository();
        $this->finalizer = $finalizer ?? new CloudJobFinalizer(jobs: $this->jobs);
        $this->files = $files ?? new FileRepository();
    }

    public function id(): string
    {
        return 'google_drive';
    }

    public function label(): string
    {
        return 'Google Drive';
    }

    public function isConfigured(): bool
    {
        return $this->oauth->isConfigured();
    }

    public function isConnected(array $user): bool
    {
        return $this->users->hasGoogleDrive($user);
    }

    public function connectUrl(bool $reconnect = false): string
    {
        return '/oauth/google_drive/start.php' . ($reconnect ? '?reconnect=1' : '');
    }

    public function disconnect(int $userId): void
    {
        $this->users->clearGoogleDrive($userId);
    }

    public function connectionDetails(array $user): array
    {
        $details = [];
        if (!empty($user['google_drive_display_name'])) {
            $details['display_name'] = (string) $user['google_drive_display_name'];
        }
        if (!empty($user['google_drive_email'])) {
            $details['email'] = (string) $user['google_drive_email'];
        }
        if (!empty($user['google_drive_account_id'])) {
            $details['account_id'] = (string) $user['google_drive_account_id'];
        }

        return $details;
    }

    /**
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

        $this->users->saveGoogleDriveTokens(
            (int) $user['id'],
            (string) ($account['account_id'] ?? $user['google_drive_account_id'] ?? ''),
            $token,
            null,
            $user['google_drive_token_expires_at'] ?? null,
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
            return ['success' => false, 'message' => 'Google Drive is not connected.', 'error' => 'not_connected'];
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

        $asyncId = 'gdrive-' . $jobId;
        $this->jobs->markInProgress($jobId, $asyncId, $desiredPath);

        @set_time_limit(0);

        $resolved = $this->resolveTarget($user, $folder, $filename, $conflict);
        if (!$resolved['ok']) {
            $this->jobs->markFailed($jobId, $resolved['error'] ?? 'Could not resolve Google Drive path');
            return [
                'success' => false,
                'job_id' => $jobId,
                'async_job_id' => $asyncId,
                'filename' => $filename,
                'remote_path' => $desiredPath,
                'status' => 'failed',
                'message' => 'Failed to start Google Drive upload',
                'error' => $resolved['error'] ?? 'path_error',
            ];
        }

        if (!empty($resolved['skipped'])) {
            $reason = $resolved['error'] ?? 'File already exists in Google Drive';
            $this->jobs->markSkipped($jobId, $desiredPath, $reason);
            return [
                'success' => true,
                'job_id' => $jobId,
                'async_job_id' => $asyncId,
                'filename' => $filename,
                'remote_path' => $desiredPath,
                'status' => 'skipped',
                'message' => $reason,
            ];
        }

        $remotePath = (string) $resolved['path'];
        $parentId = (string) $resolved['parent_id'];
        $uploadName = (string) $resolved['filename'];
        $existingId = isset($resolved['existing_id']) ? (string) $resolved['existing_id'] : null;

        $local = $this->resolveLocalUploadSource($user, $url, $fileId);
        if (!$local['ok']) {
            $this->jobs->markFailed($jobId, $local['error'] ?? 'Could not fetch file for upload');
            return [
                'success' => false,
                'job_id' => $jobId,
                'async_job_id' => $asyncId,
                'filename' => $filename,
                'remote_path' => $remotePath,
                'status' => 'failed',
                'message' => 'Failed to start Google Drive upload',
                'error' => $local['error'] ?? 'fetch_error',
            ];
        }

        $uploaded = $this->uploadFile(
            $user,
            $local['path'],
            $uploadName,
            $parentId,
            $local['mime'] ?? 'application/octet-stream',
            $existingId
        );
        if (!empty($local['cleanup'])) {
            @unlink($local['path']);
        }

        if (!$uploaded['ok']) {
            $this->jobs->markFailed($jobId, $uploaded['error'] ?? 'Upload failed');
            return [
                'success' => false,
                'job_id' => $jobId,
                'async_job_id' => $asyncId,
                'filename' => $filename,
                'remote_path' => $remotePath,
                'status' => 'failed',
                'message' => 'Failed to upload to Google Drive',
                'error' => $uploaded['error'] ?? 'upload_error',
            ];
        }

        $job = $this->jobs->findById($jobId);
        $deletedLocal = false;
        if ($job !== null) {
            $this->jobs->markComplete($jobId, $remotePath);
            $deletedLocal = $this->finalizer->onComplete($job);
            $job = $this->jobs->findById($jobId);
        }

        return [
            'success' => true,
            'job_id' => $jobId,
            'async_job_id' => $asyncId,
            'filename' => $filename,
            'remote_path' => $remotePath,
            'status' => 'complete',
            'message' => 'File uploaded to Google Drive successfully!',
            'deleted_local' => $deletedLocal,
            'job' => $job,
        ];
    }

    public function checkJobStatus(array $user, string $asyncJobId): array
    {
        $job = $this->jobs->findByAsyncJobId($asyncJobId);
        if ($job === null || (string) ($job['provider'] ?? '') !== $this->id()) {
            return ['success' => false, 'error' => 'Unknown Google Drive job.'];
        }
        if ((int) ($job['user_id'] ?? 0) !== (int) ($user['id'] ?? 0)) {
            return ['success' => false, 'error' => 'Unknown Google Drive job.'];
        }

        $status = (string) ($job['status'] ?? 'unknown');

        return [
            'success' => true,
            'status' => $status,
            'data' => [
                'path_display' => (string) ($job['result_path'] ?? $job['remote_path'] ?? ''),
            ],
            'job' => $job,
            'deleted_local' => false,
        ];
    }

    public static function buildPath(string $folder, string $filename): string
    {
        $filename = basename(str_replace('\\', '/', $filename));
        if ($filename === '' || $filename === '.' || $filename === '..') {
            $filename = 'file.bin';
        }
        $folder = UserRepository::normalizeFolder($folder);

        return $folder === '' ? '/' . $filename : '/' . $folder . '/' . $filename;
    }

    /**
     * @return array{ok:bool, path?:string, parent_id?:string, filename?:string, existing_id?:string, skipped?:bool, error?:string}
     */
    private function resolveTarget(array $user, string $folder, string $filename, string $conflict): array
    {
        $parent = $this->resolveFolderId($user, $folder);
        if ($parent === null) {
            return ['ok' => false, 'error' => 'Could not resolve Google Drive folder'];
        }

        $desiredPath = self::buildPath($folder, $filename);
        $existing = $this->findFileInFolder($user, $parent, $filename);
        if ($existing === null) {
            return ['ok' => false, 'error' => 'Could not check Google Drive path'];
        }

        if ($existing === false) {
            return [
                'ok' => true,
                'path' => $desiredPath,
                'parent_id' => $parent,
                'filename' => $filename,
            ];
        }

        if ($conflict === 'skip') {
            return [
                'ok' => true,
                'skipped' => true,
                'path' => $desiredPath,
                'error' => 'File already exists in Google Drive',
            ];
        }

        if ($conflict === 'overwrite') {
            return [
                'ok' => true,
                'path' => $desiredPath,
                'parent_id' => $parent,
                'filename' => $filename,
                'existing_id' => $existing['id'],
            ];
        }

        $next = $this->nextAvailableName($user, $parent, $filename);
        if ($next === null) {
            return ['ok' => false, 'error' => 'Could not find an available Google Drive filename'];
        }

        return [
            'ok' => true,
            'path' => self::buildPath($folder, $next),
            'parent_id' => $parent,
            'filename' => $next,
        ];
    }

    private function nextAvailableName(array $user, string $parentId, string $filename): ?string
    {
        $dot = strrpos($filename, '.');
        if ($dot !== false && $dot > 0) {
            $stem = substr($filename, 0, $dot);
            $ext = substr($filename, $dot);
        } else {
            $stem = $filename;
            $ext = '';
        }

        for ($i = 1; $i <= 99; $i++) {
            $candidate = $stem . ' (' . $i . ')' . $ext;
            $exists = $this->findFileInFolder($user, $parentId, $candidate);
            if ($exists === false) {
                return $candidate;
            }
            if ($exists === null) {
                return null;
            }
        }

        return null;
    }

    private function resolveFolderId(array $user, string $folder): ?string
    {
        $folder = UserRepository::normalizeFolder($folder);
        if ($folder === '') {
            return 'root';
        }

        $parentId = 'root';
        foreach (explode('/', $folder) as $segment) {
            $found = $this->findFolderInParent($user, $parentId, $segment);
            if ($found === null) {
                return null;
            }
            if ($found === false) {
                $created = $this->createFolder($user, $parentId, $segment);
                if ($created === null) {
                    return null;
                }
                $parentId = $created;
            } else {
                $parentId = $found;
            }
        }

        return $parentId;
    }

    /** @return string|false|null folder id, false if missing, null on error */
    private function findFolderInParent(array $user, string $parentId, string $name): string|false|null
    {
        $token = $this->accessToken($user);
        if ($token === null) {
            return null;
        }

        $q = sprintf(
            "mimeType='%s' and name='%s' and '%s' in parents and trashed=false",
            self::FOLDER_MIME,
            $this->escapeDriveQuery($name),
            $this->escapeDriveQuery($parentId)
        );
        $url = self::FILES_URL . '?' . http_build_query([
            'q' => $q,
            'spaces' => 'drive',
            'pageSize' => 1,
            'fields' => 'files(id,name)',
        ]);

        $result = $this->apiGet($url, $token);
        if (!$result['ok'] || !is_array($result['data'])) {
            return null;
        }

        $files = $result['data']['files'] ?? [];
        if (!is_array($files) || $files === []) {
            return false;
        }

        $id = (string) ($files[0]['id'] ?? '');

        return $id !== '' ? $id : null;
    }

    private function createFolder(array $user, string $parentId, string $name): ?string
    {
        $token = $this->accessToken($user);
        if ($token === null) {
            return null;
        }

        $result = $this->apiJson(
            self::FILES_URL,
            [
                'name' => $name,
                'mimeType' => self::FOLDER_MIME,
                'parents' => [$parentId],
            ],
            $token
        );

        if (!$result['ok'] || !is_array($result['data']) || empty($result['data']['id'])) {
            return null;
        }

        return (string) $result['data']['id'];
    }

    /**
     * @return array{id:string}|false|null
     */
    private function findFileInFolder(array $user, string $parentId, string $name): array|false|null
    {
        $token = $this->accessToken($user);
        if ($token === null) {
            return null;
        }

        $q = sprintf(
            "mimeType!='%s' and name='%s' and '%s' in parents and trashed=false",
            self::FOLDER_MIME,
            $this->escapeDriveQuery($name),
            $this->escapeDriveQuery($parentId)
        );
        $url = self::FILES_URL . '?' . http_build_query([
            'q' => $q,
            'spaces' => 'drive',
            'pageSize' => 1,
            'fields' => 'files(id,name)',
        ]);

        $result = $this->apiGet($url, $token);
        if (!$result['ok'] || !is_array($result['data'])) {
            return null;
        }

        $files = $result['data']['files'] ?? [];
        if (!is_array($files) || $files === []) {
            return false;
        }

        $id = (string) ($files[0]['id'] ?? '');
        if ($id === '') {
            return null;
        }

        return ['id' => $id];
    }

    /**
     * Prefer an owned host file when file_id is set; otherwise download the URL to temp.
     *
     * @return array{ok:bool, path?:string, mime?:string, cleanup?:bool, error?:string}
     */
    private function resolveLocalUploadSource(array $user, string $url, ?int $fileId): array
    {
        if ($fileId !== null && $fileId > 0) {
            $file = $this->files->findById($fileId);
            if ($file !== null && (int) ($file['user_id'] ?? 0) === (int) $user['id']) {
                $path = FileRepository::absolutePath((string) $file['stored_name']);
                if ($path !== null && is_file($path)) {
                    $mime = 'application/octet-stream';
                    if (function_exists('mime_content_type')) {
                        $detected = @mime_content_type($path);
                        if (is_string($detected) && $detected !== '') {
                            $mime = $detected;
                        }
                    }

                    return ['ok' => true, 'path' => $path, 'mime' => $mime, 'cleanup' => false];
                }
            }
        }

        $downloaded = $this->downloadUrlToTemp($url);
        if (!$downloaded['ok']) {
            return $downloaded;
        }
        $downloaded['cleanup'] = true;

        return $downloaded;
    }

    /** @return array{ok:bool, path?:string, mime?:string, error?:string} */
    private function downloadUrlToTemp(string $url): array
    {
        $dir = Config::tempDir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'Temp directory unavailable'];
        }

        $path = $dir . '/gdrive-upload-' . bin2hex(random_bytes(8));
        $fp = fopen($path, 'wb');
        if ($fp === false) {
            return ['ok' => false, 'error' => 'Could not create temp file'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FAILONERROR => false,
        ]);
        $ok = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if ($ok === false || $http < 200 || $http >= 300) {
            @unlink($path);
            return ['ok' => false, 'error' => $err !== '' ? $err : ('Fetch failed (HTTP ' . $http . ')')];
        }

        $mime = 'application/octet-stream';
        if ($contentType !== '') {
            $mime = trim(explode(';', $contentType, 2)[0]);
            if ($mime === '') {
                $mime = 'application/octet-stream';
            }
        } elseif (function_exists('mime_content_type')) {
            $detected = @mime_content_type($path);
            if (is_string($detected) && $detected !== '') {
                $mime = $detected;
            }
        }

        return ['ok' => true, 'path' => $path, 'mime' => $mime];
    }

    /** @return array{ok:bool, file_id?:string, error?:string} */
    private function uploadFile(
        array $user,
        string $localPath,
        string $filename,
        string $parentId,
        string $mime,
        ?string $existingId,
    ): array {
        $token = $this->accessToken($user);
        if ($token === null) {
            return ['ok' => false, 'error' => 'not_connected'];
        }

        $size = filesize($localPath);
        if ($size === false) {
            return ['ok' => false, 'error' => 'Could not read temp file size'];
        }

        $metadata = [
            'name' => $filename,
        ];
        if ($existingId === null) {
            $metadata['parents'] = [$parentId];
        }

        $initUrl = self::UPLOAD_URL . '?uploadType=resumable&fields=id,name';
        if ($existingId !== null) {
            $initUrl = self::UPLOAD_URL . '/' . rawurlencode($existingId) . '?uploadType=resumable&fields=id,name';
        }

        $ch = curl_init($initUrl);
        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json; charset=UTF-8',
            'X-Upload-Content-Type: ' . $mime,
            'X-Upload-Content-Length: ' . (string) $size,
        ];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $existingId !== null ? 'PATCH' : 'POST',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($metadata),
        ]);
        $response = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($response === false || ($http !== 200 && $http !== 201)) {
            $body = is_string($response) ? substr($response, $headerSize) : '';
            $decoded = json_decode($body, true);
            $apiErr = is_array($decoded)
                ? (string) ($decoded['error']['message'] ?? $decoded['error'] ?? 'resumable init failed')
                : ($err ?: 'resumable init failed');

            return ['ok' => false, 'error' => $apiErr];
        }

        $headerBlob = substr((string) $response, 0, $headerSize);
        $sessionUrl = null;
        foreach (explode("\r\n", $headerBlob) as $line) {
            if (stripos($line, 'Location:') === 0) {
                $sessionUrl = trim(substr($line, strlen('Location:')));
                break;
            }
        }
        if ($sessionUrl === null || $sessionUrl === '') {
            return ['ok' => false, 'error' => 'Missing Google Drive upload session URL'];
        }

        $fp = fopen($localPath, 'rb');
        if ($fp === false) {
            return ['ok' => false, 'error' => 'Could not open temp file for upload'];
        }

        $ch = curl_init($sessionUrl);
        curl_setopt_array($ch, [
            CURLOPT_PUT => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_INFILE => $fp,
            CURLOPT_INFILESIZE => $size,
            CURLOPT_HTTPHEADER => [
                'Content-Type: ' . $mime,
                'Content-Length: ' . (string) $size,
            ],
        ]);
        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        $decoded = is_string($body) ? json_decode($body, true) : null;
        if ($http < 200 || $http >= 300 || !is_array($decoded) || empty($decoded['id'])) {
            $apiErr = is_array($decoded)
                ? (string) ($decoded['error']['message'] ?? $decoded['error'] ?? 'upload failed')
                : ($err ?: 'upload failed');

            return ['ok' => false, 'error' => $apiErr];
        }

        return ['ok' => true, 'file_id' => (string) $decoded['id']];
    }

    private function escapeDriveQuery(string $value): string
    {
        return str_replace(["\\", "'"], ["\\\\", "\\'"], $value);
    }

    /** @return array{ok:bool, data?:array, error?:string} */
    private function apiGet(string $url, string $token): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
            ],
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
            $error = (string) ($decoded['error']['message'] ?? $decoded['error'] ?? 'Unknown error');
        }

        return ['ok' => false, 'error' => $error, 'data' => is_array($decoded) ? $decoded : null];
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
        if (($http === 200 || $http === 201) && is_array($decoded)) {
            return ['ok' => true, 'data' => $decoded];
        }

        $error = 'Unknown error';
        if (is_array($decoded)) {
            $error = (string) ($decoded['error']['message'] ?? $decoded['error'] ?? 'Unknown error');
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

        if (!empty($user['google_drive_access_token'])) {
            $expires = $user['google_drive_token_expires_at'] ?? null;
            if ($expires === null || strtotime((string) $expires) > time() + 60) {
                return (string) $user['google_drive_access_token'];
            }
        }

        $refresh = $user['google_drive_refresh_token'] ?? null;
        if (!$refresh) {
            return !empty($user['google_drive_access_token'])
                ? (string) $user['google_drive_access_token']
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

        $this->users->saveGoogleDriveTokens(
            (int) $user['id'],
            (string) ($user['google_drive_account_id'] ?? ''),
            (string) $result['access_token'],
            $result['refresh_token'] ?? null,
            $expiresAt
        );

        $user = $this->users->findById((int) $user['id']) ?? $user;

        return (string) $result['access_token'];
    }
}
