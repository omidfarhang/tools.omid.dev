<?php

declare(strict_types=1);

namespace App;

final class DropboxClient
{
    public function __construct(
        private array $user,
        private ?UserRepository $users = null,
        private ?DropboxOAuth $oauth = null,
    ) {
        $this->users = $users ?? new UserRepository();
        $this->oauth = $oauth ?? new DropboxOAuth();
    }

    public function isConnected(): bool
    {
        return $this->users->hasDropbox($this->user);
    }

    /** @return array{success:bool, async_job_id?:string, filename?:string, message?:string, error?:string} */
    public function saveUrl(string $url, ?string $filename = null): array
    {
        $token = $this->accessToken();
        if ($token === null) {
            return ['success' => false, 'message' => 'Dropbox is not connected.', 'error' => 'not_connected'];
        }

        if ($filename === null || $filename === '') {
            $filename = basename(parse_url($url, PHP_URL_PATH) ?: 'file.bin');
        }
        $filename = basename($filename);

        $payload = json_encode([
            'path' => '/' . $filename,
            'url' => $url,
        ]);

        $ch = curl_init('https://api.dropboxapi.com/2/files/save_url');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $payload,
        ]);
        $response = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = is_string($response) ? json_decode($response, true) : null;
        if ($http === 200 && is_array($decoded) && isset($decoded['async_job_id'])) {
            return [
                'success' => true,
                'async_job_id' => (string) $decoded['async_job_id'],
                'filename' => $filename,
                'message' => 'File upload to Dropbox started successfully!',
            ];
        }

        return [
            'success' => false,
            'error' => is_array($decoded) ? ($decoded['error_summary'] ?? 'Unknown error') : 'Unknown error',
            'message' => 'Failed to start Dropbox upload',
        ];
    }

    /** @return array{success:bool, status?:string, data?:array, error?:string} */
    public function checkJobStatus(string $asyncJobId): array
    {
        $token = $this->accessToken();
        if ($token === null) {
            return ['success' => false, 'error' => 'Dropbox is not connected.'];
        }

        $ch = curl_init('https://api.dropboxapi.com/2/files/save_url/check_job_status');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode(['async_job_id' => $asyncJobId]),
        ]);
        $response = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = is_string($response) ? json_decode($response, true) : null;
        if ($http === 200 && is_array($decoded)) {
            return [
                'success' => true,
                'status' => (string) ($decoded['.tag'] ?? 'unknown'),
                'data' => $decoded,
            ];
        }

        return [
            'success' => false,
            'error' => is_array($decoded) ? ($decoded['error_summary'] ?? 'Unknown error') : 'Unknown error',
        ];
    }

    private function accessToken(): ?string
    {
        $fresh = $this->users->findById((int) $this->user['id']);
        if ($fresh === null) {
            return null;
        }
        $this->user = $fresh;

        if (!empty($this->user['dropbox_access_token'])) {
            $expires = $this->user['dropbox_token_expires_at'] ?? null;
            if ($expires === null || strtotime((string) $expires) > time() + 60) {
                return (string) $this->user['dropbox_access_token'];
            }
        }

        $refresh = $this->user['dropbox_refresh_token'] ?? null;
        if (!$refresh) {
            return !empty($this->user['dropbox_access_token'])
                ? (string) $this->user['dropbox_access_token']
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
            (int) $this->user['id'],
            (string) ($this->user['dropbox_account_id'] ?? $result['account_id'] ?? ''),
            (string) $result['access_token'],
            $result['refresh_token'] ?? null,
            $expiresAt
        );

        $this->user = $this->users->findById((int) $this->user['id']) ?? $this->user;

        return (string) $result['access_token'];
    }
}
