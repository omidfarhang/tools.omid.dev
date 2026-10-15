<?php

declare(strict_types=1);

namespace App\Cloud;

use App\ShareService;
use App\UserRepository;

/**
 * Shared helpers for starting cloud uploads from web/CLI entrypoints.
 */
final class CloudSendService
{
    private CloudRegistry $registry;

    public function __construct(?CloudRegistry $registry = null)
    {
        $this->registry = $registry ?? CloudRegistry::default();
    }

    public static function create(): self
    {
        return new self();
    }

    public function registry(): CloudRegistry
    {
        return $this->registry;
    }

    public function provider(string $id = 'dropbox'): ?CloudProvider
    {
        return $this->registry->get($id);
    }

    /**
     * @return array{success:bool, job_id?:int, async_job_id?:string, filename?:string, remote_path?:string, status?:string, message?:string, error?:string}
     */
    public function startForUser(
        array $user,
        string $url,
        ?string $filename,
        string $folder,
        string $conflict,
        bool $deleteAfter,
        ?int $fileId = null,
        string $providerId = 'dropbox',
    ): array {
        $provider = $this->provider($providerId);
        if ($provider === null) {
            return ['success' => false, 'message' => 'Unknown cloud provider.', 'error' => 'unknown_provider'];
        }

        return $provider->startJob($user, $url, $filename, $folder, $conflict, $deleteAfter, $fileId);
    }

    /**
     * Enable share link and send an existing local file to the cloud provider.
     *
     * @param array<string, mixed> $file file row
     * @return array{success:bool, job_id?:int, async_job_id?:string, filename?:string, remote_path?:string, status?:string, message?:string, error?:string}
     */
    public function sendLocalFile(
        array $user,
        array $file,
        string $folder,
        string $conflict,
        bool $deleteAfter,
        string $providerId = 'dropbox',
    ): array {
        $shares = new ShareService();
        $token = $shares->enable((int) $file['id'], (int) $user['id'], purpose: 'cloud_fetch');
        $fetchUrl = ShareService::publicUrl($token);

        return $this->startForUser(
            $user,
            $fetchUrl,
            (string) $file['original_name'],
            $folder,
            $conflict,
            $deleteAfter,
            (int) $file['id'],
            $providerId
        );
    }

    /**
     * @return array{success:bool, status?:string, data?:array, error?:string, job?:array, deleted_local?:bool}
     */
    public function checkStatus(array $user, string $asyncJobId, string $providerId = 'dropbox'): array
    {
        $provider = $this->provider($providerId);
        if ($provider === null) {
            return ['success' => false, 'error' => 'Unknown cloud provider.'];
        }

        return $provider->checkJobStatus($user, $asyncJobId);
    }

    /** @return array{behavior:string,folder:string,conflict:string} */
    public static function prefs(array $user): array
    {
        return UserRepository::cloudPrefs($user);
    }
}
