<?php

declare(strict_types=1);

namespace App;

use PDO;

final class DownloadJobRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::pdo();
    }

    /**
     * @param array{
     *   user_id:int,
     *   source_url:string,
     *   requested_filename?:?string,
     *   behavior:string,
     *   yt_format?:string,
     *   cloud_folder?:?string,
     *   cloud_conflict?:?string,
     *   delete_after?:bool
     * } $data
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO download_jobs
                (user_id, source_url, requested_filename, behavior, yt_format,
                 cloud_folder, cloud_conflict, delete_after, status)
             VALUES
                (:uid, :url, :req, :beh, :yt, :folder, :conflict, :da, :st)'
        );
        $stmt->execute([
            ':uid' => $data['user_id'],
            ':url' => $data['source_url'],
            ':req' => $data['requested_filename'] ?? null,
            ':beh' => $data['behavior'],
            ':yt' => $data['yt_format'] ?? 'best',
            ':folder' => $data['cloud_folder'] ?? null,
            ':conflict' => $data['cloud_conflict'] ?? null,
            ':da' => !empty($data['delete_after']) ? 1 : 0,
            ':st' => 'pending',
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM download_jobs WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function claim(int $id): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE download_jobs SET
                status = 'in_progress',
                error = NULL,
                updated_at = datetime('now')
             WHERE id = :id AND status = 'pending'"
        );
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function markComplete(int $id, ?string $resultFilename, ?int $fileId, ?int $cloudJobId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE download_jobs SET
                status = 'complete',
                result_filename = :res,
                file_id = :fid,
                cloud_job_id = :cjid,
                error = NULL,
                updated_at = datetime('now')
             WHERE id = :id"
        );
        $stmt->execute([
            ':res' => $resultFilename,
            ':fid' => $fileId,
            ':cjid' => $cloudJobId,
            ':id' => $id,
        ]);
    }

    public function markFailed(int $id, string $error, ?string $resultFilename = null, ?int $fileId = null, ?int $cloudJobId = null): void
    {
        $stmt = $this->db->prepare(
            "UPDATE download_jobs SET
                status = 'failed',
                error = :err,
                result_filename = COALESCE(:res, result_filename),
                file_id = COALESCE(:fid, file_id),
                cloud_job_id = COALESCE(:cjid, cloud_job_id),
                updated_at = datetime('now')
             WHERE id = :id"
        );
        $stmt->execute([
            ':err' => $error,
            ':res' => $resultFilename,
            ':fid' => $fileId,
            ':cjid' => $cloudJobId,
            ':id' => $id,
        ]);
    }

    private function jobSelectSql(): string
    {
        return 'SELECT d.*,
                    cj.status AS cloud_status,
                    cj.async_job_id AS cloud_async_job_id,
                    cj.provider AS cloud_provider,
                    cj.result_path AS cloud_result_path,
                    cj.remote_path AS cloud_remote_path,
                    cj.error AS cloud_error,
                    cj.filename AS cloud_filename
             FROM download_jobs d
             LEFT JOIN cloud_jobs cj ON cj.id = d.cloud_job_id';
    }

    public static function isActiveRow(array $job): bool
    {
        $status = (string) ($job['status'] ?? '');
        $cloudStatus = (string) ($job['cloud_status'] ?? '');

        return in_array($status, ['pending', 'in_progress'], true)
            || ($status === 'complete' && in_array($cloudStatus, ['pending', 'in_progress'], true));
    }

    /** @return list<array<string, mixed>> */
    public function activeForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            $this->jobSelectSql() . '
             WHERE d.user_id = :uid
               AND (
                    d.status IN (\'pending\', \'in_progress\')
                    OR (
                        d.status = \'complete\'
                        AND cj.status IN (\'pending\', \'in_progress\')
                    )
               )
             ORDER BY d.id DESC'
        );
        $stmt->execute([':uid' => $userId]);

        return $stmt->fetchAll();
    }

    /**
     * Latest jobs for the Home panel (active and recent finished together).
     *
     * @return list<array<string, mixed>>
     */
    public function homeForUser(int $userId, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        $stmt = $this->db->prepare(
            $this->jobSelectSql() . '
             WHERE d.user_id = :uid
             ORDER BY d.id DESC
             LIMIT ' . $limit
        );
        $stmt->execute([':uid' => $userId]);

        return $stmt->fetchAll();
    }

    /** @return list<int> */
    public function pendingIds(int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        $stmt = $this->db->query(
            "SELECT id FROM download_jobs WHERE status = 'pending' ORDER BY id ASC LIMIT " . $limit
        );
        if ($stmt === false) {
            return [];
        }

        return array_map(static fn (array $row): int => (int) $row['id'], $stmt->fetchAll());
    }

    /** @return list<array<string, mixed>> */
    public function historyForUser(int $userId, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->db->prepare(
            "SELECT d.*,
                    cj.status AS cloud_status,
                    cj.provider AS cloud_provider,
                    cj.result_path AS cloud_result_path,
                    cj.remote_path AS cloud_remote_path,
                    cj.error AS cloud_error,
                    cj.filename AS cloud_filename,
                    cj.updated_at AS cloud_updated_at
             FROM download_jobs d
             LEFT JOIN cloud_jobs cj ON cj.id = d.cloud_job_id
             WHERE d.user_id = :uid
               AND d.status IN ('complete', 'failed')
             ORDER BY d.id DESC
             LIMIT " . $limit
        );
        $stmt->execute([':uid' => $userId]);

        return $stmt->fetchAll();
    }
}
