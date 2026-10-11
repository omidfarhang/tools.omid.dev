<?php

declare(strict_types=1);

namespace App;

use App\Cloud\Dropbox\DropboxProvider;

/**
 * Back-compat wrapper around DropboxProvider for older call sites.
 */
final class DropboxClient
{
    private DropboxProvider $provider;

    public function __construct(
        private array $user,
        private ?UserRepository $users = null,
        ?DropboxProvider $provider = null,
    ) {
        $this->users = $users ?? new UserRepository();
        $this->provider = $provider ?? new DropboxProvider($this->users);
    }

    public function isConnected(): bool
    {
        return $this->provider->isConnected($this->user);
    }

    public static function normalizeFolder(string $folder): string
    {
        return DropboxProvider::normalizeFolder($folder);
    }

    public static function buildPath(string $folder, string $filename): string
    {
        return DropboxProvider::buildPath($folder, $filename);
    }

    /**
     * @return array{success:bool, job_id?:int, async_job_id?:string, filename?:string, remote_path?:string, dropbox_path?:string, status?:string, message?:string, error?:string}
     */
    public function startJob(
        string $url,
        ?string $filename,
        string $folder,
        string $conflict,
        bool $deleteAfter,
        ?int $fileId = null,
    ): array {
        $result = $this->provider->startJob($this->user, $url, $filename, $folder, $conflict, $deleteAfter, $fileId);
        if (isset($result['remote_path'])) {
            $result['dropbox_path'] = $result['remote_path'];
        }

        return $result;
    }

    /** @deprecated Use startJob(); kept for any callers expecting the old shape. */
    public function saveUrl(string $url, ?string $filename = null): array
    {
        $prefs = UserRepository::cloudPrefs($this->user);
        $result = $this->startJob($url, $filename, $prefs['folder'], $prefs['conflict'], false, null);
        if ($result['success'] && ($result['status'] ?? '') === 'skipped') {
            return [
                'success' => false,
                'message' => $result['message'] ?? 'Skipped',
                'error' => 'skipped',
            ];
        }

        return $result;
    }

    /**
     * @return array{success:bool, status?:string, data?:array, error?:string, job?:array, deleted_local?:bool}
     */
    public function checkJobStatus(string $asyncJobId): array
    {
        return $this->provider->checkJobStatus($this->user, $asyncJobId);
    }
}
