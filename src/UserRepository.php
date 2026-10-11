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

    public function count(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }
}
