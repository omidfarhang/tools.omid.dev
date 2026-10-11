<?php

declare(strict_types=1);

namespace App;

use PDO;

final class UserRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::pdo();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findByUsername(string $username): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE username = :u');
        $stmt->execute([':u' => $username]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->db->query(
            'SELECT * FROM users ORDER BY is_active ASC, created_at DESC'
        )->fetchAll();
    }

    public function create(string $username, string $password, string $role = 'user', bool $active = true): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO users (username, password_hash, role, is_active) VALUES (:u, :p, :r, :a)'
        );
        $stmt->execute([
            ':u' => $username,
            ':p' => password_hash($password, PASSWORD_DEFAULT),
            ':r' => $role === 'admin' ? 'admin' : 'user',
            ':a' => $active ? 1 : 0,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** Self-registration: always role=user and inactive until admin approves. */
    public function register(string $username, string $password): int
    {
        return $this->create($username, $password, 'user', false);
    }

    public function countPending(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM users WHERE is_active = 0')->fetchColumn();
    }

    public function setActive(int $id, bool $active): void
    {
        $stmt = $this->db->prepare('UPDATE users SET is_active = :a WHERE id = :id');
        $stmt->execute([':a' => $active ? 1 : 0, ':id' => $id]);
    }

    public function setPassword(int $id, string $password): void
    {
        $stmt = $this->db->prepare('UPDATE users SET password_hash = :p WHERE id = :id');
        $stmt->execute([
            ':p' => password_hash($password, PASSWORD_DEFAULT),
            ':id' => $id,
        ]);
    }

    public function setRole(int $id, string $role): void
    {
        $stmt = $this->db->prepare('UPDATE users SET role = :r WHERE id = :id');
        $stmt->execute([
            ':r' => $role === 'admin' ? 'admin' : 'user',
            ':id' => $id,
        ]);
    }

    public function saveDropboxTokens(
        int $userId,
        string $accountId,
        string $accessToken,
        ?string $refreshToken,
        ?string $expiresAt
    ): void {
        $stmt = $this->db->prepare(
            'UPDATE users SET
                dropbox_account_id = :aid,
                dropbox_access_token = :at,
                dropbox_refresh_token = COALESCE(:rt, dropbox_refresh_token),
                dropbox_token_expires_at = :exp
             WHERE id = :id'
        );
        $stmt->execute([
            ':aid' => $accountId,
            ':at' => $accessToken,
            ':rt' => $refreshToken,
            ':exp' => $expiresAt,
            ':id' => $userId,
        ]);
    }

    public function clearDropbox(int $userId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE users SET
                dropbox_account_id = NULL,
                dropbox_access_token = NULL,
                dropbox_refresh_token = NULL,
                dropbox_token_expires_at = NULL
             WHERE id = :id'
        );
        $stmt->execute([':id' => $userId]);
    }

    public function hasDropbox(array $user): bool
    {
        return !empty($user['dropbox_access_token']) || !empty($user['dropbox_refresh_token']);
    }

    public static function normalizeBehavior(string $behavior): string
    {
        return match ($behavior) {
            'save_to_dropbox', 'save_to_cloud' => 'save_to_cloud',
            'save_to_both' => 'save_to_both',
            default => 'save_to_disk',
        };
    }

    public static function normalizeConflict(string $conflict): string
    {
        return match ($conflict) {
            'overwrite', 'skip' => $conflict,
            default => 'rename',
        };
    }

    public static function normalizeFolder(string $folder): string
    {
        $folder = str_replace('\\', '/', trim($folder));
        $folder = trim($folder, '/');
        $parts = [];
        foreach (explode('/', $folder) as $part) {
            $part = trim($part);
            if ($part === '' || $part === '.' || $part === '..') {
                continue;
            }
            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    /** @return array{behavior:string,folder:string,conflict:string} */
    public static function cloudPrefs(array $user): array
    {
        return [
            'behavior' => self::normalizeBehavior((string) ($user['dropbox_default_behavior'] ?? 'save_to_disk')),
            'folder' => self::normalizeFolder((string) ($user['dropbox_folder'] ?? 'Tools Center')),
            'conflict' => self::normalizeConflict((string) ($user['dropbox_conflict'] ?? 'rename')),
        ];
    }

    /** @deprecated Use cloudPrefs() */
    public static function dropboxPrefs(array $user): array
    {
        return self::cloudPrefs($user);
    }

    public function updateCloudPrefs(int $userId, string $behavior, string $folder, string $conflict): void
    {
        $stmt = $this->db->prepare(
            'UPDATE users SET
                dropbox_default_behavior = :b,
                dropbox_folder = :f,
                dropbox_conflict = :c
             WHERE id = :id'
        );
        $stmt->execute([
            ':b' => self::normalizeBehavior($behavior),
            ':f' => self::normalizeFolder($folder),
            ':c' => self::normalizeConflict($conflict),
            ':id' => $userId,
        ]);
    }

    /** @deprecated Use updateCloudPrefs() */
    public function updateDropboxPrefs(int $userId, string $behavior, string $folder, string $conflict): void
    {
        $this->updateCloudPrefs($userId, $behavior, $folder, $conflict);
    }

    public function count(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }
}
