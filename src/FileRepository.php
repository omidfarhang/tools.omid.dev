<?php

declare(strict_types=1);

namespace App;

use PDO;

final class FileRepository
{
    private PDO $db;

    /** @var array<string, string> */
    private const SORT_COLUMNS = [
        'name' => 'f.original_name',
        'size' => 'f.size_bytes',
        'date' => 'f.created_at',
        'owner' => 'u.username',
    ];

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::pdo();
    }

    public function create(int $userId, string $storedName, string $originalName, int $sizeBytes, ?string $sourceUrl): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO files (user_id, stored_name, original_name, size_bytes, source_url)
             VALUES (:uid, :sn, :on, :sz, :su)'
        );
        $stmt->execute([
            ':uid' => $userId,
            ':sn' => $storedName,
            ':on' => $originalName,
            ':sz' => $sizeBytes,
            ':su' => $sourceUrl,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM files WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function rename(int $id, string $originalName): void
    {
        $stmt = $this->db->prepare('UPDATE files SET original_name = :on WHERE id = :id');
        $stmt->execute([
            ':on' => $originalName,
            ':id' => $id,
        ]);
    }

    /**
     * @return array{sort:string, dir:string}
     */
    public static function normalizeSort(?string $sort, ?string $dir, bool $allowOwner = false): array
    {
        $allowed = ['name', 'size', 'date'];
        if ($allowOwner) {
            $allowed[] = 'owner';
        }
        $sortKey = is_string($sort) && in_array($sort, $allowed, true) ? $sort : 'date';
        $dirKey = strtolower((string) $dir) === 'asc' ? 'ASC' : 'DESC';

        return ['sort' => $sortKey, 'dir' => $dirKey];
    }

    private function orderClause(string $sort, string $dir): string
    {
        $column = self::SORT_COLUMNS[$sort] ?? self::SORT_COLUMNS['date'];

        return $column . ' ' . $dir . ', f.id ' . $dir;
    }

    /** Latest download job that produced this file (if any). */
    private static function downloadJobIdSelectSql(): string
    {
        return '(SELECT d.id FROM download_jobs d
                 WHERE d.file_id = f.id
                 ORDER BY d.id DESC LIMIT 1) AS download_job_id';
    }

    /** @return list<array<string, mixed>> */
    public function forUser(int $userId, string $sort = 'date', string $dir = 'DESC'): array
    {
        $order = $this->orderClause($sort, $dir);
        $shareSelect = ShareService::fileListShareSelectSql();
        $jobSelect = self::downloadJobIdSelectSql();
        $stmt = $this->db->prepare(
            "SELECT f.*,
                {$shareSelect},
                {$jobSelect}
             FROM files f
             WHERE f.user_id = :uid
             ORDER BY {$order}"
        );
        $stmt->execute([':uid' => $userId]);

        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function forUserWithOwner(int $userId, string $sort = 'date', string $dir = 'DESC'): array
    {
        $order = $this->orderClause($sort, $dir);
        $shareSelect = ShareService::fileListShareSelectSql();
        $jobSelect = self::downloadJobIdSelectSql();
        $stmt = $this->db->prepare(
            "SELECT f.*, u.username AS owner_username,
                {$shareSelect},
                {$jobSelect}
             FROM files f
             JOIN users u ON u.id = f.user_id
             WHERE f.user_id = :uid
             ORDER BY {$order}"
        );
        $stmt->execute([':uid' => $userId]);

        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function allWithOwner(string $sort = 'date', string $dir = 'DESC'): array
    {
        $order = $this->orderClause($sort, $dir);
        $shareSelect = ShareService::fileListShareSelectSql();
        $jobSelect = self::downloadJobIdSelectSql();

        return $this->db->query(
            "SELECT f.*, u.username AS owner_username,
                {$shareSelect},
                {$jobSelect}
             FROM files f
             JOIN users u ON u.id = f.user_id
             ORDER BY {$order}"
        )->fetchAll();
    }

    /**
     * @return array{items:list<array<string, mixed>>, total:int, page:int, per_page:int, total_pages:int}
     */
    public function forUserPaginated(
        int $userId,
        string $sort = 'date',
        string $dir = 'DESC',
        string $q = '',
        int $page = 1,
        int $perPage = Pager::DEFAULT_PER_PAGE,
        bool $withOwner = false
    ): array {
        $norm = Pager::normalize($page, $perPage);
        $order = $this->orderClause($sort, $dir);
        $shareSelect = ShareService::fileListShareSelectSql();
        $jobSelect = self::downloadJobIdSelectSql();
        $ownerSelect = $withOwner ? ', u.username AS owner_username' : '';
        $ownerJoin = $withOwner ? 'JOIN users u ON u.id = f.user_id' : '';

        $where = 'f.user_id = :uid';
        $params = [':uid' => $userId];
        $q = Pager::searchQuery($q);
        if ($q !== '') {
            $where .= ' AND (f.original_name LIKE :q ESCAPE \'\\\' OR IFNULL(f.source_url, \'\') LIKE :q ESCAPE \'\\\')';
            $params[':q'] = Pager::likeContains($q);
        }

        $countStmt = $this->db->prepare(
            "SELECT COUNT(*) FROM files f {$ownerJoin} WHERE {$where}"
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
            "SELECT f.*{$ownerSelect},
                {$shareSelect},
                {$jobSelect}
             FROM files f
             {$ownerJoin}
             WHERE {$where}
             ORDER BY {$order}
             LIMIT {$norm['per_page']} OFFSET {$norm['offset']}"
        );
        $stmt->execute($params);

        return Pager::result($stmt->fetchAll(), $total, $norm['page'], $norm['per_page']);
    }

    /**
     * @return array{items:list<array<string, mixed>>, total:int, page:int, per_page:int, total_pages:int}
     */
    public function allWithOwnerPaginated(
        string $sort = 'date',
        string $dir = 'DESC',
        string $q = '',
        int $page = 1,
        int $perPage = Pager::DEFAULT_PER_PAGE
    ): array {
        $norm = Pager::normalize($page, $perPage);
        $order = $this->orderClause($sort, $dir);
        $shareSelect = ShareService::fileListShareSelectSql();
        $jobSelect = self::downloadJobIdSelectSql();

        $where = '1=1';
        $params = [];
        $q = Pager::searchQuery($q);
        if ($q !== '') {
            $where = '(f.original_name LIKE :q ESCAPE \'\\\' OR IFNULL(f.source_url, \'\') LIKE :q ESCAPE \'\\\')';
            $params[':q'] = Pager::likeContains($q);
        }

        $countStmt = $this->db->prepare(
            "SELECT COUNT(*) FROM files f JOIN users u ON u.id = f.user_id WHERE {$where}"
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
            "SELECT f.*, u.username AS owner_username,
                {$shareSelect},
                {$jobSelect}
             FROM files f
             JOIN users u ON u.id = f.user_id
             WHERE {$where}
             ORDER BY {$order}
             LIMIT {$norm['per_page']} OFFSET {$norm['offset']}"
        );
        $stmt->execute($params);

        return Pager::result($stmt->fetchAll(), $total, $norm['page'], $norm['per_page']);
    }

    /**
     * 1-based page that contains $fileId under the same filters/sort as the list,
     * or null if the file is missing / out of scope / filtered out by search.
     */
    public function pageForFile(
        int $fileId,
        ?int $userId = null,
        string $sort = 'date',
        string $dir = 'DESC',
        string $q = '',
        int $perPage = Pager::DEFAULT_PER_PAGE,
        bool $withOwner = false
    ): ?int {
        $file = $this->findById($fileId);
        if ($file === null) {
            return null;
        }
        if ($userId !== null && (int) $file['user_id'] !== $userId) {
            return null;
        }

        $norm = Pager::normalize(1, $perPage);
        $order = $this->orderClause($sort, $dir);
        // Owner sort needs the users join; admin all-files lists always join for consistency with list queries.
        $needsOwnerJoin = $withOwner || $userId === null || $sort === 'owner';
        $ownerJoin = $needsOwnerJoin ? 'JOIN users u ON u.id = f.user_id' : '';

        $whereParts = [];
        $params = [];
        if ($userId !== null) {
            $whereParts[] = 'f.user_id = :uid';
            $params[':uid'] = $userId;
        }
        $q = Pager::searchQuery($q);
        if ($q !== '') {
            $whereParts[] = '(f.original_name LIKE :q ESCAPE \'\\\' OR IFNULL(f.source_url, \'\') LIKE :q ESCAPE \'\\\')';
            $params[':q'] = Pager::likeContains($q);
        }
        $where = $whereParts === [] ? '1=1' : implode(' AND ', $whereParts);

        $params[':fid'] = $fileId;
        $stmt = $this->db->prepare(
            "SELECT ranked.page FROM (
                SELECT f.id,
                       ((ROW_NUMBER() OVER (ORDER BY {$order}) - 1) / {$norm['per_page']}) + 1 AS page
                FROM files f
                {$ownerJoin}
                WHERE {$where}
             ) ranked
             WHERE ranked.id = :fid"
        );
        $stmt->execute($params);
        $page = $stmt->fetchColumn();

        return $page === false ? null : (int) $page;
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM files WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public function count(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM files')->fetchColumn();
    }

    public static function absolutePath(string $storedName): ?string
    {
        $base = realpath(Config::tempDir());
        if ($base === false) {
            return null;
        }
        $name = basename($storedName);
        $path = $base . DIRECTORY_SEPARATOR . $name;
        $real = realpath($path);
        if ($real === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $real;
    }
}
