<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Remember-this-device tokens that skip MFA for a limited time after password login.
 * Cookie format: `{selector}.{validator}` (hex). Only SHA-256(validator) is stored.
 */
final class TrustedDeviceRepository
{
    public const COOKIE_NAME = 'tools_2fa_device';
    public const TTL_DAYS = 30;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::pdo();
    }

    public function isTrusted(int $userId): bool
    {
        $parsed = self::parseCookie($_COOKIE[self::COOKIE_NAME] ?? null);
        if ($parsed === null) {
            return false;
        }

        $this->pruneExpired();

        $stmt = $this->db->prepare(
            'SELECT id, token_hash, expires_at FROM trusted_devices
             WHERE user_id = :uid AND selector = :sel LIMIT 1'
        );
        $stmt->execute([
            ':uid' => $userId,
            ':sel' => $parsed['selector'],
        ]);
        $row = $stmt->fetch();
        if ($row === false) {
            return false;
        }

        $expires = strtotime((string) $row['expires_at'] . ' UTC');
        if ($expires === false || $expires < time()) {
            $this->deleteById((int) $row['id']);

            return false;
        }

        $expected = (string) $row['token_hash'];
        $actual = hash('sha256', $parsed['validator']);
        if (!hash_equals($expected, $actual)) {
            return false;
        }

        $touch = $this->db->prepare(
            'UPDATE trusted_devices SET last_used_at = datetime(\'now\') WHERE id = :id'
        );
        $touch->execute([':id' => (int) $row['id']]);

        return true;
    }

    /**
     * Issue a new device cookie (sets HTTP cookie). Returns false if headers already sent.
     */
    public function remember(int $userId, string $userAgent): void
    {
        $this->pruneExpired();

        $selector = bin2hex(random_bytes(16));
        $validator = bin2hex(random_bytes(32));
        $expiresAt = gmdate('Y-m-d H:i:s', time() + (self::TTL_DAYS * 86400));
        $label = self::summarizeUserAgent($userAgent);

        $stmt = $this->db->prepare(
            'INSERT INTO trusted_devices (user_id, selector, token_hash, label, expires_at)
             VALUES (:uid, :sel, :hash, :label, :exp)'
        );
        $stmt->execute([
            ':uid' => $userId,
            ':sel' => $selector,
            ':hash' => hash('sha256', $validator),
            ':label' => $label,
            ':exp' => $expiresAt,
        ]);

        self::setCookie($selector . '.' . $validator, self::TTL_DAYS * 86400);
    }

    public function clearCookie(): void
    {
        self::setCookie('', -42000);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForUser(int $userId): array
    {
        $this->pruneExpired();
        $stmt = $this->db->prepare(
            'SELECT id, label, created_at, expires_at, last_used_at
             FROM trusted_devices
             WHERE user_id = :uid AND expires_at > datetime(\'now\')
             ORDER BY COALESCE(last_used_at, created_at) DESC'
        );
        $stmt->execute([':uid' => $userId]);

        return $stmt->fetchAll();
    }

    public function revoke(int $userId, int $deviceId): bool
    {
        $selStmt = $this->db->prepare(
            'SELECT selector FROM trusted_devices WHERE id = :id AND user_id = :uid'
        );
        $selStmt->execute([':id' => $deviceId, ':uid' => $userId]);
        $selector = $selStmt->fetchColumn();
        if ($selector === false) {
            return false;
        }

        $stmt = $this->db->prepare(
            'DELETE FROM trusted_devices WHERE id = :id AND user_id = :uid'
        );
        $stmt->execute([':id' => $deviceId, ':uid' => $userId]);
        if ($stmt->rowCount() === 0) {
            return false;
        }

        $parsed = self::parseCookie($_COOKIE[self::COOKIE_NAME] ?? null);
        if ($parsed !== null && hash_equals((string) $selector, $parsed['selector'])) {
            $this->clearCookie();
        }

        return true;
    }

    public function revokeAll(int $userId): int
    {
        $stmt = $this->db->prepare('DELETE FROM trusted_devices WHERE user_id = :uid');
        $stmt->execute([':uid' => $userId]);
        $n = $stmt->rowCount();
        if ($n > 0) {
            $this->clearCookie();
        }

        return $n;
    }

    public function pruneExpired(): void
    {
        $this->db->exec("DELETE FROM trusted_devices WHERE expires_at <= datetime('now')");
    }

    private function deleteById(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM trusted_devices WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    /**
     * @return array{selector: string, validator: string}|null
     */
    private static function parseCookie(mixed $raw): ?array
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $parts = explode('.', $raw, 2);
        if (count($parts) !== 2) {
            return null;
        }
        [$selector, $validator] = $parts;
        if (!preg_match('/^[a-f0-9]{32}$/', $selector) || !preg_match('/^[a-f0-9]{64}$/', $validator)) {
            return null;
        }

        return ['selector' => $selector, 'validator' => $validator];
    }

    private static function setCookie(string $value, int $lifetimeSeconds): void
    {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        setcookie(self::COOKIE_NAME, $value, [
            'expires' => time() + $lifetimeSeconds,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function summarizeUserAgent(string $ua): string
    {
        $ua = trim(preg_replace('/\s+/', ' ', $ua) ?? '');
        if ($ua === '') {
            return 'Unknown device';
        }
        if (strlen($ua) > 120) {
            return substr($ua, 0, 117) . '...';
        }

        return $ua;
    }
}
