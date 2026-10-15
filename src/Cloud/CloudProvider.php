<?php

declare(strict_types=1);

namespace App\Cloud;

interface CloudProvider
{
    public function id(): string;

    /** Human-readable name for UI (e.g. "Dropbox"). */
    public function label(): string;

    public function isConfigured(): bool;

    public function isConnected(array $user): bool;

    /** Relative URL to start (or re-start) OAuth for this provider. */
    public function connectUrl(bool $reconnect = false): string;

    /** Clear stored credentials for this user. */
    public function disconnect(int $userId): void;

    /**
     * Display fields for the Account page when connected.
     *
     * @return array{display_name?:string, email?:string, account_id?:string}
     */
    public function connectionDetails(array $user): array;

    /**
     * Refresh stored account profile fields when supported.
     *
     * @param array<string, mixed> $user
     * @return array{ok:bool, email?:string, display_name?:string, error?:string}
     */
    public function syncAccountInfo(array &$user): array;

    /**
     * @return array{success:bool, job_id?:int, async_job_id?:string, filename?:string, remote_path?:string, status?:string, message?:string, error?:string}
     */
    public function startJob(
        array $user,
        string $url,
        ?string $filename,
        string $folder,
        string $conflict,
        bool $deleteAfter,
        ?int $fileId = null,
    ): array;

    /**
     * @return array{success:bool, status?:string, data?:array, error?:string, job?:array, deleted_local?:bool}
     */
    public function checkJobStatus(array $user, string $asyncJobId): array;
}
