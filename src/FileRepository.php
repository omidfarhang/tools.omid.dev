<?php

declare(strict_types=1);

namespace App;

use PDO;

final class FileRepository
{
    private PDO $db;

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

    /** @return list<array<string, mixed>> */
    public function forUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT f.*,
                (SELECT s.token FROM shares s WHERE s.file_id = f.id AND s.revoked_at IS NULL ORDER BY s.id DESC LIMIT 1) AS share_token
             FROM files f
             WHERE f.user_id = :uid
             ORDER BY f.created_at DESC'
        );
        $stmt->execute([':uid' => $userId]);

        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function allWithOwner(): array
    {
        return $this->db->query(
            'SELECT f.*, u.username AS owner_username,
                (SELECT s.token FROM shares s WHERE s.file_id = f.id AND s.revoked_at IS NULL ORDER BY s.id DESC LIMIT 1) AS share_token
             FROM files f
             JOIN users u ON u.id = f.user_id
             ORDER BY f.created_at DESC'
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
