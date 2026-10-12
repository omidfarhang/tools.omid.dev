<?php

declare(strict_types=1);

namespace App\Cloud;

use App\Database;
use PDO;

final class CloudJobRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::pdo();
    }

    /**
     * @param array{user_id:int,provider?:string,file_id?:?int,filename:string,remote_path:string,conflict_mode:string,delete_after:bool,status?:string,async_job_id?:?string,error?:?string} $data
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO cloud_jobs
                (user_id, provider, file_id, async_job_id, filename, remote_path, conflict_mode, delete_after, status, error)
             VALUES
                (:uid, :prov, :fid, :aj, :fn, :path, :cm, :da, :st, :err)'
        );
        $stmt->execute([
            ':uid' => $data['user_id'],
            ':prov' => $data['provider'] ?? 'dropbox',
            ':fid' => $data['file_id'] ?? null,
            ':aj' => $data['async_job_id'] ?? null,
            ':fn' => $data['filename'],
            ':path' => $data['remote_path'],
            ':cm' => $data['conflict_mode'],
            ':da' => !empty($data['delete_after']) ? 1 : 0,
            ':st' => $data['status'] ?? 'pending',
            ':err' => $data['error'] ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM cloud_jobs WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findByAsyncJobId(string $asyncJobId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM cloud_jobs WHERE async_job_id = :aj ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':aj' => $asyncJobId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function recentForUser(int $userId, int $limit = 15): array
    {
        $limit = max(1, min(50, $limit));
        $stmt = $this->db->prepare(
            'SELECT * FROM cloud_jobs WHERE user_id = :uid ORDER BY id DESC LIMIT ' . $limit
        );
        $stmt->execute([':uid' => $userId]);

        return $stmt->fetchAll();
    }

    /**
     * Cloud jobs that are not already linked from a download job (e.g. My Files → Send to cloud).
     *
     * @return list<array<string, mixed>>
     */
    public function recentUnlinkedForUser(int $userId, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->db->prepare(
            'SELECT cj.*
             FROM cloud_jobs cj
             WHERE cj.user_id = :uid
               AND NOT EXISTS (
                    SELECT 1 FROM download_jobs d
                    WHERE d.cloud_job_id = cj.id
               )
             ORDER BY cj.id DESC
             LIMIT ' . $limit
        );
        $stmt->execute([':uid' => $userId]);

        return $stmt->fetchAll();
    }

    public function markInProgress(int $id, string $asyncJobId, string $remotePath): void
    {
        $stmt = $this->db->prepare(
            'UPDATE cloud_jobs SET
                async_job_id = :aj,
                remote_path = :path,
                status = :st,
                error = NULL,
                updated_at = datetime(\'now\')
             WHERE id = :id'
        );
        $stmt->execute([
            ':aj' => $asyncJobId,
            ':path' => $remotePath,
            ':st' => 'in_progress',
            ':id' => $id,
        ]);
    }

    public function markSkipped(int $id, string $remotePath, string $reason): void
    {
        $stmt = $this->db->prepare(
            'UPDATE cloud_jobs SET
                remote_path = :path,
                status = :st,
                error = :err,
                updated_at = datetime(\'now\')
             WHERE id = :id'
        );
        $stmt->execute([
            ':path' => $remotePath,
            ':st' => 'skipped',
            ':err' => $reason,
            ':id' => $id,
        ]);
    }

    public function markFailed(int $id, string $error): void
    {
        $stmt = $this->db->prepare(
            'UPDATE cloud_jobs SET
                status = :st,
                error = :err,
                updated_at = datetime(\'now\')
             WHERE id = :id'
        );
        $stmt->execute([
            ':st' => 'failed',
            ':err' => $error,
            ':id' => $id,
        ]);
    }

    public function markComplete(int $id, ?string $resultPath): void
    {
        $stmt = $this->db->prepare(
            'UPDATE cloud_jobs SET
                status = :st,
                result_path = :rp,
                error = NULL,
                updated_at = datetime(\'now\')
             WHERE id = :id'
        );
        $stmt->execute([
            ':st' => 'complete',
            ':rp' => $resultPath,
            ':id' => $id,
        ]);
    }

    public function clearFileId(int $id): void
    {
        $stmt = $this->db->prepare(
            'UPDATE cloud_jobs SET file_id = NULL, updated_at = datetime(\'now\') WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
    }
}
