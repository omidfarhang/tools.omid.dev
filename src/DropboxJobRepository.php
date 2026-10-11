<?php

declare(strict_types=1);

namespace App;

use App\Cloud\CloudJobRepository;
use PDO;

/**
 * Back-compat alias for CloudJobRepository (Dropbox-only callers).
 */
final class DropboxJobRepository
{
    private CloudJobRepository $jobs;

    public function __construct(?PDO $db = null)
    {
        $this->jobs = new CloudJobRepository($db);
    }

    /** @param array{user_id:int,file_id?:?int,filename:string,dropbox_path?:string,remote_path?:string,conflict_mode:string,delete_after:bool,status?:string,async_job_id?:?string,error?:?string} $data */
    public function create(array $data): int
    {
        if (!isset($data['remote_path']) && isset($data['dropbox_path'])) {
            $data['remote_path'] = $data['dropbox_path'];
            unset($data['dropbox_path']);
        }
        $data['provider'] = $data['provider'] ?? 'dropbox';

        return $this->jobs->create($data);
    }

    public function findById(int $id): ?array
    {
        return $this->aliasPath($this->jobs->findById($id));
    }

    public function findByAsyncJobId(string $asyncJobId): ?array
    {
        return $this->aliasPath($this->jobs->findByAsyncJobId($asyncJobId));
    }

    /** @return list<array<string, mixed>> */
    public function recentForUser(int $userId, int $limit = 15): array
    {
        return array_map(fn (array $row) => $this->aliasPath($row) ?? $row, $this->jobs->recentForUser($userId, $limit));
    }

    public function markInProgress(int $id, string $asyncJobId, string $dropboxPath): void
    {
        $this->jobs->markInProgress($id, $asyncJobId, $dropboxPath);
    }

    public function markSkipped(int $id, string $dropboxPath, string $reason): void
    {
        $this->jobs->markSkipped($id, $dropboxPath, $reason);
    }

    public function markFailed(int $id, string $error): void
    {
        $this->jobs->markFailed($id, $error);
    }

    public function markComplete(int $id, ?string $resultPath): void
    {
        $this->jobs->markComplete($id, $resultPath);
    }

    public function clearFileId(int $id): void
    {
        $this->jobs->clearFileId($id);
    }

    private function aliasPath(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        if (!isset($row['dropbox_path']) && isset($row['remote_path'])) {
            $row['dropbox_path'] = $row['remote_path'];
        }

        return $row;
    }
}
