<?php

declare(strict_types=1);

namespace App;

use PDO;

final class LoginRateLimiter
{
    public const REGISTER_KEY = '__register__';

    private const DEFAULT_MAX_ATTEMPTS = 10;
    private const DEFAULT_WINDOW_MINUTES = 15;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::pdo();
    }

    public function maxAttempts(): int
    {
        $n = (int) Config::get(ManagedSettings::LOGIN_MAX_ATTEMPTS, self::DEFAULT_MAX_ATTEMPTS);

        return max(1, min(1000, $n > 0 ? $n : self::DEFAULT_MAX_ATTEMPTS));
    }

    public function windowMinutes(): int
    {
        $n = (int) Config::get(ManagedSettings::LOGIN_WINDOW_MINUTES, self::DEFAULT_WINDOW_MINUTES);

        return max(1, min(1440, $n > 0 ? $n : self::DEFAULT_WINDOW_MINUTES));
    }

    public function isLimited(string $ip, string $username): bool
    {
        $since = $this->windowStart();
        $max = $this->maxAttempts();

        if ($this->countByIp($ip, $since) >= $max) {
            return true;
        }

        $userKey = $this->normalizeUsername($username);
        if ($userKey !== '' && $this->countByUsername($userKey, $since) >= $max) {
            return true;
        }

        return false;
    }

    public function recordFailure(string $ip, string $username): void
    {
        $this->prune();

        $stmt = $this->db->prepare(
            'INSERT INTO login_attempts (ip, username) VALUES (:ip, :u)'
        );
        $stmt->execute([
            ':ip' => $ip,
            ':u' => $this->normalizeUsername($username),
        ]);
    }

    public function clear(string $ip, string $username): void
    {
        $userKey = $this->normalizeUsername($username);
        $stmt = $this->db->prepare(
            'DELETE FROM login_attempts WHERE ip = :ip OR (username != \'\' AND username = :u)'
        );
        $stmt->execute([
            ':ip' => $ip,
            ':u' => $userKey,
        ]);
    }

    public function clearByIp(string $ip): int
    {
        $ip = trim($ip);
        if ($ip === '') {
            return 0;
        }
        $stmt = $this->db->prepare('DELETE FROM login_attempts WHERE ip = :ip');
        $stmt->execute([':ip' => $ip]);

        return $stmt->rowCount();
    }

    public function clearByUsername(string $username): int
    {
        $userKey = $this->normalizeUsername($username);
        if ($userKey === '') {
            return 0;
        }
        $stmt = $this->db->prepare(
            'DELETE FROM login_attempts WHERE username = :u'
        );
        $stmt->execute([':u' => $userKey]);

        return $stmt->rowCount();
    }

    /**
     * Keys currently at or over the failure threshold within the window.
     *
     * @return list<array{type: 'ip'|'username', key: string, count: int, latest: string}>
     */
    public function listActiveBlocks(): array
    {
        $since = $this->windowStart();
        $max = $this->maxAttempts();
        $out = [];

        $ipStmt = $this->db->prepare(
            'SELECT ip AS key, COUNT(*) AS cnt, MAX(created_at) AS latest
             FROM login_attempts
             WHERE created_at >= :since
             GROUP BY ip
             HAVING COUNT(*) >= :max
             ORDER BY latest DESC'
        );
        $ipStmt->execute([':since' => $since, ':max' => $max]);
        foreach ($ipStmt->fetchAll() as $row) {
            $out[] = [
                'type' => 'ip',
                'key' => (string) $row['key'],
                'count' => (int) $row['cnt'],
                'latest' => (string) $row['latest'],
            ];
        }

        $userStmt = $this->db->prepare(
            'SELECT username AS key, COUNT(*) AS cnt, MAX(created_at) AS latest
             FROM login_attempts
             WHERE created_at >= :since AND username != \'\'
             GROUP BY username
             HAVING COUNT(*) >= :max
             ORDER BY latest DESC'
        );
        $userStmt->execute([':since' => $since, ':max' => $max]);
        foreach ($userStmt->fetchAll() as $row) {
            $out[] = [
                'type' => 'username',
                'key' => (string) $row['key'],
                'count' => (int) $row['cnt'],
                'latest' => (string) $row['latest'],
            ];
        }

        return $out;
    }

    public static function formatBlockKey(string $type, string $key): string
    {
        if ($type === 'username' && $key === self::REGISTER_KEY) {
            return '(register)';
        }

        return $key;
    }

    private function countByIp(string $ip, string $since): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM login_attempts WHERE ip = :ip AND created_at >= :since'
        );
        $stmt->execute([':ip' => $ip, ':since' => $since]);

        return (int) $stmt->fetchColumn();
    }

    private function countByUsername(string $username, string $since): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM login_attempts WHERE username = :u AND created_at >= :since'
        );
        $stmt->execute([':u' => $username, ':since' => $since]);

        return (int) $stmt->fetchColumn();
    }

    private function prune(): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM login_attempts WHERE created_at < :since'
        );
        $stmt->execute([':since' => $this->windowStart()]);
    }

    private function windowStart(): string
    {
        return gmdate('Y-m-d H:i:s', time() - ($this->windowMinutes() * 60));
    }

    private function normalizeUsername(string $username): string
    {
        $username = trim($username);
        if ($username === self::REGISTER_KEY) {
            return self::REGISTER_KEY;
        }
        if ($username === '' || !preg_match('/^[a-zA-Z0-9._-]{1,32}$/', $username)) {
            return '';
        }

        return $username;
    }
}
