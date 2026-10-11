<?php

declare(strict_types=1);

namespace App;

use PDO;

final class ShareService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::pdo();
    }

    public function activeTokenForFile(int $fileId): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT token FROM shares WHERE file_id = :fid AND revoked_at IS NULL ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':fid' => $fileId]);
        $token = $stmt->fetchColumn();

        return $token !== false ? (string) $token : null;
    }

    public function enable(int $fileId, int $userId): string
    {
        $existing = $this->activeTokenForFile($fileId);
        if ($existing !== null) {
            return $existing;
        }

        $token = bin2hex(random_bytes(32));
        $stmt = $this->db->prepare(
            'INSERT INTO shares (file_id, token, created_by) VALUES (:fid, :t, :uid)'
        );
        $stmt->execute([
            ':fid' => $fileId,
            ':t' => $token,
            ':uid' => $userId,
        ]);

        return $token;
    }

    public function revokeForFile(int $fileId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE shares SET revoked_at = datetime(\'now\') WHERE file_id = :fid AND revoked_at IS NULL'
        );
        $stmt->execute([':fid' => $fileId]);
    }

    public function findActiveByToken(string $token): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT s.*, f.stored_name, f.original_name, f.size_bytes, f.user_id
             FROM shares s
             JOIN files f ON f.id = s.file_id
             WHERE s.token = :t AND s.revoked_at IS NULL'
        );
        $stmt->execute([':t' => $token]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function countActive(): int
    {
        return (int) $this->db->query(
            'SELECT COUNT(*) FROM shares WHERE revoked_at IS NULL'
        )->fetchColumn();
    }

    public static function publicUrl(string $token): string
    {
        return Config::baseUrl() . '/s/' . rawurlencode($token);
    }
}
