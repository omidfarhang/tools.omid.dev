<?php

declare(strict_types=1);

namespace App\Cloud;

use App\FileRepository;
use App\ShareService;

/**
 * Shared side effects when a cloud upload job reaches complete.
 */
final class CloudJobFinalizer
{
    public function __construct(
        private ?ShareService $shares = null,
        private ?FileRepository $files = null,
        private ?CloudJobRepository $jobs = null,
    ) {
        $this->shares = $shares ?? new ShareService();
        $this->files = $files ?? new FileRepository();
        $this->jobs = $jobs ?? new CloudJobRepository();
    }

    /**
     * Revoke cloud_fetch shares, then optionally delete the local file.
     *
     * @param array<string, mixed> $job cloud_jobs row (pre-markComplete snapshot is fine)
     * @return bool whether the local file was deleted
     */
    public function onComplete(array $job): bool
    {
        if (empty($job['file_id'])) {
            return false;
        }

        $fileId = (int) $job['file_id'];
        $this->shares->revokeCloudFetchForFile($fileId);

        if (empty($job['delete_after'])) {
            return false;
        }

        if (!$this->deleteLocalFile($fileId, (int) $job['user_id'])) {
            return false;
        }

        $this->jobs->clearFileId((int) $job['id']);

        return true;
    }

    private function deleteLocalFile(int $fileId, int $userId): bool
    {
        $file = $this->files->findById($fileId);
        if ($file === null || (int) $file['user_id'] !== $userId) {
            return false;
        }

        $path = FileRepository::absolutePath((string) $file['stored_name']);
        if ($path !== null && is_file($path)) {
            unlink($path);
        }
        $this->files->delete($fileId);

        return true;
    }
}
