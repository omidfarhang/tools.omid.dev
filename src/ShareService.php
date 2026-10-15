<?php

declare(strict_types=1);

namespace App;

use PDO;

final class ShareService
{
    private PDO $db;

    /** SQL fragment: share row is still usable (not expired / not exhausted). */
    private const ACTIVE_LIMITS_SQL = '(expires_at IS NULL OR expires_at > datetime(\'now\'))
             AND (max_downloads IS NULL OR download_count < max_downloads)';

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::pdo();
    }

    public function activeTokenForFile(int $fileId): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT token FROM shares
             WHERE file_id = :fid AND revoked_at IS NULL
               AND ' . self::ACTIVE_LIMITS_SQL . '
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':fid' => $fileId]);
        $token = $stmt->fetchColumn();

        return $token !== false ? (string) $token : null;
    }

    /**
     * @param 'user'|'cloud_fetch' $purpose
     */
    public function enable(
        int $fileId,
        int $userId,
        ?string $expiresAt = null,
        ?int $maxDownloads = null,
        string $purpose = 'user',
    ): string {
        $existing = $this->activeTokenForFile($fileId);
        if ($existing !== null) {
            return $existing;
        }

        // Drop expired/exhausted rows so only one non-revoked share exists per file.
        $this->revokeForFile($fileId);

        if ($purpose !== 'user' && $purpose !== 'cloud_fetch') {
            $purpose = 'user';
        }
        if ($maxDownloads !== null && $maxDownloads < 1) {
            $maxDownloads = null;
        }

        $token = bin2hex(random_bytes(32));
        $stmt = $this->db->prepare(
            'INSERT INTO shares (file_id, token, created_by, expires_at, max_downloads, download_count, purpose)
             VALUES (:fid, :t, :uid, :exp, :max, 0, :purpose)'
        );
        $stmt->execute([
            ':fid' => $fileId,
            ':t' => $token,
            ':uid' => $userId,
            ':exp' => $expiresAt,
            ':max' => $maxDownloads,
            ':purpose' => $purpose,
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

    /**
     * Revoke ephemeral shares created only so a cloud provider can fetch the file.
     * User-enabled shares (purpose=user) are left alone.
     */
    public function revokeCloudFetchForFile(int $fileId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE shares SET revoked_at = datetime(\'now\')
             WHERE file_id = :fid AND revoked_at IS NULL AND purpose = \'cloud_fetch\''
        );
        $stmt->execute([':fid' => $fileId]);
    }

    public function findActiveByToken(string $token): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT s.*, f.stored_name, f.original_name, f.size_bytes, f.user_id
             FROM shares s
             JOIN files f ON f.id = s.file_id
             WHERE s.token = :t AND s.revoked_at IS NULL
               AND ' . self::ACTIVE_LIMITS_SQL
        );
        $stmt->execute([':t' => $token]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Atomically increment download_count when the share is still within limits.
     */
    public function recordDownload(int $shareId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE shares SET download_count = download_count + 1
             WHERE id = :id AND revoked_at IS NULL
               AND ' . self::ACTIVE_LIMITS_SQL
        );
        $stmt->execute([':id' => $shareId]);

        return $stmt->rowCount() > 0;
    }

    public function countActive(): int
    {
        return (int) $this->db->query(
            'SELECT COUNT(*) FROM shares WHERE revoked_at IS NULL AND ' . self::ACTIVE_LIMITS_SQL
        )->fetchColumn();
    }

    /**
     * Map UI preset to absolute UTC datetime string for SQLite, or null for never.
     */
    public static function expiresAtFromPreset(string $preset): ?string
    {
        $seconds = match ($preset) {
            '1h' => 3600,
            '24h' => 86400,
            '7d' => 604800,
            default => null,
        };
        if ($seconds === null) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', time() + $seconds);
    }

    public static function publicUrl(string $token): string
    {
        return Config::baseUrl() . '/s/' . rawurlencode($token);
    }

    /**
     * Shared SELECT fragment for listing files with active share metadata.
     */
    public static function fileListShareSelectSql(): string
    {
        $limits = self::ACTIVE_LIMITS_SQL;

        return "(SELECT s.token FROM shares s WHERE s.file_id = f.id AND s.revoked_at IS NULL AND {$limits} ORDER BY s.id DESC LIMIT 1) AS share_token,
                (SELECT s.expires_at FROM shares s WHERE s.file_id = f.id AND s.revoked_at IS NULL AND {$limits} ORDER BY s.id DESC LIMIT 1) AS share_expires_at,
                (SELECT s.max_downloads FROM shares s WHERE s.file_id = f.id AND s.revoked_at IS NULL AND {$limits} ORDER BY s.id DESC LIMIT 1) AS share_max_downloads,
                (SELECT s.download_count FROM shares s WHERE s.file_id = f.id AND s.revoked_at IS NULL AND {$limits} ORDER BY s.id DESC LIMIT 1) AS share_download_count";
    }
}
