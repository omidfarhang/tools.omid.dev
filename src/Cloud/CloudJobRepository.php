<?php

declare(strict_types=1);

namespace App\Cloud;

use App\Database;
use App\Pager;
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
    public function recentUnlinkedForUser(int $userId, int $limit = 50, string $q = '', int $offset = 0): array
    {
        $page = $offset > 0 ? (int) floor($offset / max(1, $limit)) + 1 : 1;

        return $this->recentUnlinkedForUserPaginated($userId, $q, $page, $limit)['items'];
    }

    /**
     * @return array{items:list<array<string, mixed>>, total:int, page:int, per_page:int, total_pages:int}
     */
    public function recentUnlinkedForUserPaginated(
        int $userId,
        string $q = '',
        int $page = 1,
        int $perPage = Pager::DEFAULT_PER_PAGE
    ): array {
        $norm = Pager::normalize($page, $perPage);
        $q = Pager::searchQuery($q);
        $where = "cj.user_id = :uid
               AND cj.hidden_at IS NULL
               AND NOT EXISTS (
                    SELECT 1 FROM download_jobs d
                    WHERE d.cloud_job_id = cj.id
               )";
        $params = [':uid' => $userId];
        if ($q !== '') {
            $where .= " AND (
                IFNULL(cj.filename, '') LIKE :q ESCAPE '\\'
                OR IFNULL(cj.remote_path, '') LIKE :q ESCAPE '\\'
                OR IFNULL(cj.result_path, '') LIKE :q ESCAPE '\\'
            )";
            $params[':q'] = Pager::likeContains($q);
        }

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM cloud_jobs cj WHERE {$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        if ($norm['page'] > 1 && $total > 0) {
            $maxPage = (int) ceil($total / $norm['per_page']);
            if ($norm['page'] > $maxPage) {
                $norm = Pager::normalize($maxPage, $norm['per_page']);
            }
        }

        $stmt = $this->db->prepare(
            "SELECT cj.*
             FROM cloud_jobs cj
             WHERE {$where}
             ORDER BY cj.id DESC
             LIMIT {$norm['per_page']} OFFSET {$norm['offset']}"
        );
        $stmt->execute($params);

        return Pager::result($stmt->fetchAll(), $total, $norm['page'], $norm['per_page']);
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    public function findUnlinkedByIds(array $ids, bool $withOwner = false): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $ownerSelect = $withOwner ? ', u.username AS owner_username' : '';
        $ownerJoin = $withOwner ? 'JOIN users u ON u.id = cj.user_id' : '';
        $sql = "SELECT cj.*{$ownerSelect}
             FROM cloud_jobs cj
             {$ownerJoin}
             WHERE cj.id IN ({$placeholders})
               AND NOT EXISTS (
                    SELECT 1 FROM download_jobs d
                    WHERE d.cloud_job_id = cj.id
               )";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($ids);
        $byId = [];
        foreach ($stmt->fetchAll() as $row) {
            $byId[(int) $row['id']] = $row;
        }

        return $byId;
    }

    /**
     * Soft-hide a finished standalone cloud job from the owning user's History.
     */
    public function hideForUser(int $id, int $userId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE cloud_jobs SET
                hidden_at = datetime('now'),
                updated_at = datetime('now')
             WHERE id = :id
               AND user_id = :uid
               AND hidden_at IS NULL
               AND status IN ('complete', 'failed', 'skipped', 'cancelled')
               AND NOT EXISTS (
                    SELECT 1 FROM download_jobs d
                    WHERE d.cloud_job_id = :cjid
               )"
        );
        $stmt->execute([':id' => $id, ':uid' => $userId, ':cjid' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Unlinked cloud jobs for admin history (includes soft-hidden rows).
     * When $userId is set, scopes to that user.
     *
     * @return list<array<string, mixed>>
     */
    public function recentUnlinkedAll(?int $userId = null, int $limit = 200, string $q = '', int $offset = 0): array
    {
        $page = $offset > 0 ? (int) floor($offset / max(1, $limit)) + 1 : 1;

        return $this->recentUnlinkedAllPaginated($userId, $q, $page, $limit)['items'];
    }

    /**
     * @return array{items:list<array<string, mixed>>, total:int, page:int, per_page:int, total_pages:int}
     */
    public function recentUnlinkedAllPaginated(
        ?int $userId = null,
        string $q = '',
        int $page = 1,
        int $perPage = Pager::DEFAULT_PER_PAGE
    ): array {
        $norm = Pager::normalize($page, $perPage);
        $q = Pager::searchQuery($q);
        $where = 'NOT EXISTS (
                    SELECT 1 FROM download_jobs d
                    WHERE d.cloud_job_id = cj.id
               )';
        $params = [];
        if ($userId !== null) {
            $where .= ' AND cj.user_id = :uid';
            $params[':uid'] = $userId;
        }
        if ($q !== '') {
            $where .= " AND (
                IFNULL(cj.filename, '') LIKE :q ESCAPE '\\'
                OR IFNULL(cj.remote_path, '') LIKE :q ESCAPE '\\'
                OR IFNULL(cj.result_path, '') LIKE :q ESCAPE '\\'
                OR IFNULL(u.username, '') LIKE :q ESCAPE '\\'
            )";
            $params[':q'] = Pager::likeContains($q);
        }

        $countStmt = $this->db->prepare(
            "SELECT COUNT(*) FROM cloud_jobs cj JOIN users u ON u.id = cj.user_id WHERE {$where}"
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
            "SELECT cj.*, u.username AS owner_username
             FROM cloud_jobs cj
             JOIN users u ON u.id = cj.user_id
             WHERE {$where}
             ORDER BY cj.id DESC
             LIMIT {$norm['per_page']} OFFSET {$norm['offset']}"
        );
        $stmt->execute($params);

        return Pager::result($stmt->fetchAll(), $total, $norm['page'], $norm['per_page']);
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
             WHERE id = :id
               AND status IN (\'pending\', \'in_progress\')'
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
             WHERE id = :id
               AND status IN (\'pending\', \'in_progress\')'
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
             WHERE id = :id
               AND status IN (\'pending\', \'in_progress\')'
        );
        $stmt->execute([
            ':st' => 'failed',
            ':err' => $error,
            ':id' => $id,
        ]);
    }

    /**
     * Local cancel only — does not abort a remote provider job.
     */
    public function cancel(int $id, int $userId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE cloud_jobs SET
                status = 'cancelled',
                error = 'Cancelled by user.',
                updated_at = datetime('now')
             WHERE id = :id
               AND user_id = :uid
               AND status IN ('pending', 'in_progress')"
        );
        $stmt->execute([':id' => $id, ':uid' => $userId]);

        return $stmt->rowCount() > 0;
    }

    public function markComplete(int $id, ?string $resultPath): void
    {
        $stmt = $this->db->prepare(
            'UPDATE cloud_jobs SET
                status = :st,
                result_path = :rp,
                error = NULL,
                updated_at = datetime(\'now\')
             WHERE id = :id
               AND status IN (\'pending\', \'in_progress\')'
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
