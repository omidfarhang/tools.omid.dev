<?php

declare(strict_types=1);

namespace App\Cloud;

interface CloudProvider
{
    public function id(): string;

    public function isConfigured(): bool;

    public function isConnected(array $user): bool;

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
