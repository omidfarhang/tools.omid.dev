<?php

declare(strict_types=1);

namespace App;

use PDO;

final class DownloadHistoryRepository
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
     *   result_filename?:?string,
     *   behavior:string,
     *   status:string,
     *   error?:?string,
     *   file_id?:?int,
     *   cloud_job_id?:?int
     * } $data
     */
    public function record(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO download_history
                (user_id, source_url, requested_filename, result_filename, behavior, status, error, file_id, cloud_job_id)
             VALUES
                (:uid, :url, :req, :res, :beh, :st, :err, :fid, :cjid)'
        );
        $stmt->execute([
            ':uid' => $data['user_id'],
            ':url' => $data['source_url'],
            ':req' => $data['requested_filename'] ?? null,
            ':res' => $data['result_filename'] ?? null,
            ':beh' => $data['behavior'],
            ':st' => $data['status'],
            ':err' => $data['error'] ?? null,
            ':fid' => $data['file_id'] ?? null,
            ':cjid' => $data['cloud_job_id'] ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** @return list<array<string, mixed>> */
    public function forUser(int $userId, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->db->prepare(
            'SELECT h.*,
                    cj.status AS cloud_status,
                    cj.result_path AS cloud_result_path,
                    cj.remote_path AS cloud_remote_path,
                    cj.error AS cloud_error
             FROM download_history h
             LEFT JOIN cloud_jobs cj ON cj.id = h.cloud_job_id
             WHERE h.user_id = :uid
             ORDER BY h.id DESC
             LIMIT ' . $limit
        );
        $stmt->execute([':uid' => $userId]);

        return $stmt->fetchAll();
    }
}
