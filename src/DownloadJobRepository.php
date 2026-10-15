<?php

declare(strict_types=1);

namespace App;

use App\Cloud\CloudJobRepository;
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
     *   yt_audio_only?:bool,
     *   yt_subs?:?string,
     *   force_ytdlp?:bool,
     *   cloud_folder?:?string,
     *   cloud_conflict?:?string,
     *   delete_after?:bool
     * } $data
     */
    public function create(array $data): int
    {
        $subs = $data['yt_subs'] ?? null;
        if ($subs !== null && !in_array($subs, ['none', 'en', 'all'], true)) {
            $subs = null;
        }
        if ($subs === 'none') {
            $subs = null;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO download_jobs
                (user_id, source_url, requested_filename, behavior, yt_format, yt_audio_only, yt_subs,
                 force_ytdlp, cloud_folder, cloud_conflict, delete_after, status)
             VALUES
                (:uid, :url, :req, :beh, :yt, :audio, :subs, :fy, :folder, :conflict, :da, :st)'
        );
        $stmt->execute([
            ':uid' => $data['user_id'],
            ':url' => $data['source_url'],
            ':req' => $data['requested_filename'] ?? null,
            ':beh' => $data['behavior'],
            ':yt' => $data['yt_format'] ?? 'best',
            ':audio' => !empty($data['yt_audio_only']) ? 1 : 0,
            ':subs' => $subs,
            ':fy' => !empty($data['force_ytdlp']) ? 1 : 0,
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
             WHERE id = :id AND status = 'in_progress'"
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
             WHERE id = :id AND status = 'in_progress'"
        );
        $stmt->execute([
            ':err' => $error,
            ':res' => $resultFilename,
            ':fid' => $fileId,
            ':cjid' => $cloudJobId,
            ':id' => $id,
        ]);
    }

    public function isCancelled(int $id): bool
    {
        $job = $this->findById($id);

        return $job !== null && (string) ($job['status'] ?? '') === 'cancelled';
    }

    /**
     * Cancel a pending/in-progress download, or an active linked cloud upload.
     * Soft-cancel for in_progress: status flips to cancelled; the processor stops between steps.
     */
    public function cancel(int $id, int $userId): bool
    {
        $stmt = $this->db->prepare(
            $this->jobSelectSql() . '
             WHERE d.id = :id AND d.user_id = :uid'
        );
        $stmt->execute([':id' => $id, ':uid' => $userId]);
        $job = $stmt->fetch();
        if ($job === false) {
            return false;
        }

        $status = (string) ($job['status'] ?? '');
        $cloudStatus = (string) ($job['cloud_status'] ?? '');
        $cloudJobId = isset($job['cloud_job_id']) && $job['cloud_job_id'] !== null
            ? (int) $job['cloud_job_id']
            : null;

        if (in_array($status, ['pending', 'in_progress'], true)) {
            $upd = $this->db->prepare(
                "UPDATE download_jobs SET
                    status = 'cancelled',
                    error = 'Cancelled by user.',
                    updated_at = datetime('now')
                 WHERE id = :id
                   AND user_id = :uid
                   AND status IN ('pending', 'in_progress')"
            );
            $upd->execute([':id' => $id, ':uid' => $userId]);
            if ($upd->rowCount() < 1) {
                return false;
            }
            if ($cloudJobId !== null) {
                (new CloudJobRepository($this->db))->cancel($cloudJobId, $userId);
            }

            return true;
        }

        if (
            $status === 'complete'
            && $cloudJobId !== null
            && in_array($cloudStatus, ['pending', 'in_progress'], true)
        ) {
            return (new CloudJobRepository($this->db))->cancel($cloudJobId, $userId);
        }

        return false;
    }

    /**
     * Reset a failed job to pending for another attempt.
     */
    public function retry(int $id, int $userId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE download_jobs SET
                status = 'pending',
                error = NULL,
                result_filename = NULL,
                file_id = NULL,
                cloud_job_id = NULL,
                hidden_at = NULL,
                updated_at = datetime('now')
             WHERE id = :id
               AND user_id = :uid
               AND status = 'failed'"
        );
        $stmt->execute([':id' => $id, ':uid' => $userId]);

        return $stmt->rowCount() > 0;
    }

    public static function canCancel(array $job): bool
    {
        $status = (string) ($job['status'] ?? '');
        if (in_array($status, ['pending', 'in_progress'], true)) {
            return true;
        }
        $cloudStatus = (string) ($job['cloud_status'] ?? '');

        return $status === 'complete' && in_array($cloudStatus, ['pending', 'in_progress'], true);
    }

    public static function canRetry(array $job): bool
    {
        return (string) ($job['status'] ?? '') === 'failed';
    }

    public static function canHide(array $job): bool
    {
        return in_array((string) ($job['status'] ?? ''), ['complete', 'failed', 'cancelled'], true);
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
               AND d.hidden_at IS NULL
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
    public function historyForUser(int $userId, int $limit = 50, string $q = '', int $offset = 0): array
    {
        $page = $offset > 0 ? (int) floor($offset / max(1, $limit)) + 1 : 1;

        return $this->historyForUserPaginated($userId, $q, $page, $limit)['items'];
    }

    /**
     * @return array{items:list<array<string, mixed>>, total:int, page:int, per_page:int, total_pages:int}
     */
    public function historyForUserPaginated(
        int $userId,
        string $q = '',
        int $page = 1,
        int $perPage = Pager::DEFAULT_PER_PAGE
    ): array {
        $norm = Pager::normalize($page, $perPage);
        $q = Pager::searchQuery($q);
        $where = "d.user_id = :uid
               AND d.status IN ('complete', 'failed', 'cancelled')
               AND d.hidden_at IS NULL";
        $params = [':uid' => $userId];
        if ($q !== '') {
            $where .= " AND (
                IFNULL(d.source_url, '') LIKE :q ESCAPE '\\'
                OR IFNULL(d.result_filename, '') LIKE :q ESCAPE '\\'
                OR IFNULL(d.requested_filename, '') LIKE :q ESCAPE '\\'
            )";
            $params[':q'] = Pager::likeContains($q);
        }

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM download_jobs d WHERE {$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        if ($norm['page'] > 1 && $total > 0) {
            $maxPage = (int) ceil($total / $norm['per_page']);
            if ($norm['page'] > $maxPage) {
                $norm = Pager::normalize($maxPage, $norm['per_page']);
            }
        }

        $stmt = $this->db->prepare(
            $this->historySelectSql(false) . "
             WHERE {$where}
             ORDER BY d.id DESC
             LIMIT {$norm['per_page']} OFFSET {$norm['offset']}"
        );
        $stmt->execute($params);

        return Pager::result($stmt->fetchAll(), $total, $norm['page'], $norm['per_page']);
    }

    /**
     * Hydrate finished download jobs by id (preserves cloud join columns).
     *
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    public function findHistoryByIds(array $ids, bool $withOwner = false): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = $this->historySelectSql($withOwner) . "
             WHERE d.id IN ({$placeholders})
               AND d.status IN ('complete', 'failed', 'cancelled')";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($ids);
        $byId = [];
        foreach ($stmt->fetchAll() as $row) {
            $byId[(int) $row['id']] = $row;
        }

        return $byId;
    }

    private function historySelectSql(bool $withOwner): string
    {
        $ownerSelect = $withOwner ? ', u.username AS owner_username' : '';
        $ownerJoin = $withOwner ? 'JOIN users u ON u.id = d.user_id' : '';

        return "SELECT d.*{$ownerSelect},
                    cj.status AS cloud_status,
                    cj.provider AS cloud_provider,
                    cj.result_path AS cloud_result_path,
                    cj.remote_path AS cloud_remote_path,
                    cj.error AS cloud_error,
                    cj.filename AS cloud_filename,
                    cj.updated_at AS cloud_updated_at
             FROM download_jobs d
             {$ownerJoin}
             LEFT JOIN cloud_jobs cj ON cj.id = d.cloud_job_id";
    }

    /**
     * Soft-hide a finished download job from the owning user's History / Home lists.
     */
    public function hideForUser(int $id, int $userId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE download_jobs SET
                hidden_at = datetime('now'),
                updated_at = datetime('now')
             WHERE id = :id
               AND user_id = :uid
               AND hidden_at IS NULL
               AND status IN ('complete', 'failed', 'cancelled')"
        );
        $stmt->execute([':id' => $id, ':uid' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Finished download jobs for admin (includes soft-hidden rows).
     * When $userId is set, scopes to that user.
     *
     * @return list<array<string, mixed>>
     */
    public function historyAll(?int $userId = null, int $limit = 200, string $q = '', int $offset = 0): array
    {
        $page = $offset > 0 ? (int) floor($offset / max(1, $limit)) + 1 : 1;

        return $this->historyAllPaginated($userId, $q, $page, $limit)['items'];
    }

    /**
     * @return array{items:list<array<string, mixed>>, total:int, page:int, per_page:int, total_pages:int}
     */
    public function historyAllPaginated(
        ?int $userId = null,
        string $q = '',
        int $page = 1,
        int $perPage = Pager::DEFAULT_PER_PAGE
    ): array {
        $norm = Pager::normalize($page, $perPage);
        $q = Pager::searchQuery($q);
        $where = "d.status IN ('complete', 'failed', 'cancelled')";
        $params = [];
        if ($userId !== null) {
            $where .= ' AND d.user_id = :uid';
            $params[':uid'] = $userId;
        }
        if ($q !== '') {
            $where .= " AND (
                IFNULL(d.source_url, '') LIKE :q ESCAPE '\\'
                OR IFNULL(d.result_filename, '') LIKE :q ESCAPE '\\'
                OR IFNULL(d.requested_filename, '') LIKE :q ESCAPE '\\'
                OR IFNULL(u.username, '') LIKE :q ESCAPE '\\'
            )";
            $params[':q'] = Pager::likeContains($q);
        }

        $countStmt = $this->db->prepare(
            "SELECT COUNT(*) FROM download_jobs d JOIN users u ON u.id = d.user_id WHERE {$where}"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        if ($norm['page'] > 1 && $total > 0) {
            $maxPage = (int) ceil($total / $norm['per_page']);
            if ($norm['page'] > $maxPage) {
                $norm = Pager::normalize($maxPage, $norm['per_page']);
            }
        }

        $stmt = $this->db->prepare(
            $this->historySelectSql(true) . "
             WHERE {$where}
             ORDER BY d.id DESC
             LIMIT {$norm['per_page']} OFFSET {$norm['offset']}"
        );
        $stmt->execute($params);

        return Pager::result($stmt->fetchAll(), $total, $norm['page'], $norm['per_page']);
    }
}
