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

    /** @return list<array<string, mixed>> */
    public function forUser(int $userId, string $sort = 'date', string $dir = 'DESC'): array
    {
        $order = $this->orderClause($sort, $dir);
        $shareSelect = ShareService::fileListShareSelectSql();
        $stmt = $this->db->prepare(
            "SELECT f.*,
                {$shareSelect}
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
        $stmt = $this->db->prepare(
            "SELECT f.*, u.username AS owner_username,
                {$shareSelect}
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

        return $this->db->query(
            "SELECT f.*, u.username AS owner_username,
                {$shareSelect}
             FROM files f
             JOIN users u ON u.id = f.user_id
             ORDER BY {$order}"
        )->fetchAll();
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
