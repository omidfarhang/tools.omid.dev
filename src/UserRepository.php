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

    /**
     * All users with disk usage and finished download counts for admin listing.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->db->query(
            "SELECT u.*,
                COALESCE(f.file_count, 0) AS file_count,
                COALESCE(f.used_bytes, 0) AS used_bytes,
                COALESCE(d.download_count, 0) AS download_count
             FROM users u
             LEFT JOIN (
                SELECT user_id,
                       COUNT(*) AS file_count,
                       SUM(size_bytes) AS used_bytes
                FROM files
                GROUP BY user_id
             ) f ON f.user_id = u.id
             LEFT JOIN (
                SELECT user_id, COUNT(*) AS download_count
                FROM download_jobs
                WHERE status IN ('complete', 'failed', 'cancelled')
                GROUP BY user_id
             ) d ON d.user_id = u.id
             ORDER BY u.is_active ASC, u.created_at DESC"
        )->fetchAll();
    }

    public function create(
        string $username,
        string $password,
        string $role = 'user',
        bool $active = true,
        ?int $quotaBytes = null,
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO users (username, password_hash, role, is_active, quota_bytes)
             VALUES (:u, :p, :r, :a, :q)'
        );
        $stmt->execute([
            ':u' => $username,
            ':p' => password_hash($password, PASSWORD_DEFAULT),
            ':r' => $role === 'admin' ? 'admin' : 'user',
            ':a' => $active ? 1 : 0,
            ':q' => $quotaBytes,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** Self-registration: always role=user and inactive until admin approves. */
    public function register(string $username, string $password): int
    {
        return $this->create($username, $password, 'user', false, $this->defaultQuotaBytes());
    }

    /** Site default for new users; null = unlimited. */
    public function defaultQuotaBytes(): ?int
    {
        $raw = (new SettingsRepository($this->db))->get(ManagedSettings::DEFAULT_USER_QUOTA_BYTES);
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!ctype_digit($raw)) {
            return null;
        }

        return (int) $raw;
    }

    public function usedBytes(int $userId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(size_bytes), 0) FROM files WHERE user_id = :uid'
        );
        $stmt->execute([':uid' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    /** Null = unlimited. */
    public static function effectiveQuotaBytes(array $user): ?int
    {
        if (!array_key_exists('quota_bytes', $user) || $user['quota_bytes'] === null || $user['quota_bytes'] === '') {
            return null;
        }

        return (int) $user['quota_bytes'];
    }

    public function wouldExceedQuota(array $user, int $additionalBytes): bool
    {
        $quota = self::effectiveQuotaBytes($user);
        if ($quota === null) {
            return false;
        }

        return $this->usedBytes((int) $user['id']) + max(0, $additionalBytes) > $quota;
    }

    public function setQuota(int $id, ?int $quotaBytes): void
    {
        $stmt = $this->db->prepare('UPDATE users SET quota_bytes = :q WHERE id = :id');
        $stmt->execute([
            ':q' => $quotaBytes,
            ':id' => $id,
        ]);
    }

    /**
     * Parse admin MiB input. Empty → null (unlimited). Invalid → false.
     *
     * @return int|null|false
     */
    public static function parseQuotaMibInput(?string $raw): int|null|false
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        if (!ctype_digit($raw)) {
            return false;
        }
        $mib = (int) $raw;
        if ($mib === 0) {
            return null;
        }

        return $mib * 1024 * 1024;
    }

    public static function quotaBytesToMib(?int $bytes): string
    {
        if ($bytes === null) {
            return '';
        }

        return (string) (int) round($bytes / (1024 * 1024));
    }

    public static function formatQuotaLabel(?int $quotaBytes): string
    {
        return $quotaBytes === null ? 'Unlimited' : human_filesize($quotaBytes);
    }

    public static function formatUsageLabel(int $usedBytes, ?int $quotaBytes): string
    {
        return human_filesize($usedBytes) . ' / ' . self::formatQuotaLabel($quotaBytes);
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

    /** Deletes an inactive user. Related DB rows cascade; caller should remove disk files first. */
    public function deleteInactive(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM users WHERE id = :id AND is_active = 0');
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() > 0;
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
        ?string $expiresAt,
        ?string $email = null,
        ?string $displayName = null,
    ): void {
        $stmt = $this->db->prepare(
            'UPDATE users SET
                dropbox_account_id = :aid,
                dropbox_access_token = :at,
                dropbox_refresh_token = COALESCE(:rt, dropbox_refresh_token),
                dropbox_token_expires_at = :exp,
                dropbox_email = COALESCE(:email, dropbox_email),
                dropbox_display_name = COALESCE(:dn, dropbox_display_name)
             WHERE id = :id'
        );
        $stmt->execute([
            ':aid' => $accountId,
            ':at' => $accessToken,
            ':rt' => $refreshToken,
            ':exp' => $expiresAt,
            ':email' => $email !== null && $email !== '' ? $email : null,
            ':dn' => $displayName !== null && $displayName !== '' ? $displayName : null,
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
                dropbox_token_expires_at = NULL,
                dropbox_email = NULL,
                dropbox_display_name = NULL
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

    /**
     * Normalize destination ids from form/prefs.
     * "disk" = local host; any other id is a cloud provider id.
     *
     * @param list<mixed> $raw
     * @param list<string>|null $allowedProviders Provider ids that may be selected (null = any non-empty id)
     * @return list<string>
     */
    public static function normalizeDestinations(array $raw, ?array $allowedProviders = null): array
    {
        $out = [];
        $seen = [];
        foreach ($raw as $item) {
            if (!is_string($item) && !is_int($item)) {
                continue;
            }
            $id = strtolower(trim((string) $item));
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            if ($id === 'disk' || $id === 'local') {
                $seen['disk'] = true;
                $out[] = 'disk';
                continue;
            }
            if ($allowedProviders !== null && !in_array($id, $allowedProviders, true)) {
                continue;
            }
            if (!preg_match('/^[a-z][a-z0-9_-]*$/', $id)) {
                continue;
            }
            $seen[$id] = true;
            $out[] = $id;
        }

        return $out;
    }

    /**
     * @param list<string> $destinations
     */
    public static function destinationsToJson(array $destinations): string
    {
        $normalized = self::normalizeDestinations($destinations);
        if ($normalized === []) {
            $normalized = ['disk'];
        }

        return json_encode(array_values($normalized), JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<string>
     */
    public static function destinationsFromUser(array $user): array
    {
        $raw = $user['default_destinations'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $dest = self::normalizeDestinations($decoded);
                if ($dest !== []) {
                    return $dest;
                }
            }
        }

        return match (self::normalizeBehavior((string) ($user['dropbox_default_behavior'] ?? 'save_to_disk'))) {
            'save_to_cloud' => ['dropbox'],
            'save_to_both' => ['disk', 'dropbox'],
            default => ['disk'],
        };
    }

    /**
     * Legacy single behavior derived from destination list (for older callers).
     *
     * @param list<string> $destinations
     */
    public static function behaviorFromDestinations(array $destinations): string
    {
        $dest = self::normalizeDestinations($destinations);
        $hasDisk = in_array('disk', $dest, true);
        $hasCloud = false;
        foreach ($dest as $id) {
            if ($id !== 'disk') {
                $hasCloud = true;
                break;
            }
        }
        if ($hasDisk && $hasCloud) {
            return 'save_to_both';
        }
        if ($hasCloud) {
            return 'save_to_cloud';
        }

        return 'save_to_disk';
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

    /** @return array{behavior:string,destinations:list<string>,folder:string,conflict:string} */
    public static function cloudPrefs(array $user): array
    {
        $destinations = self::destinationsFromUser($user);

        return [
            'behavior' => self::behaviorFromDestinations($destinations),
            'destinations' => $destinations,
            'folder' => self::normalizeFolder((string) ($user['dropbox_folder'] ?? '')),
            'conflict' => self::normalizeConflict((string) ($user['dropbox_conflict'] ?? 'rename')),
        ];
    }

    /** @deprecated Use cloudPrefs() */
    public static function dropboxPrefs(array $user): array
    {
        return self::cloudPrefs($user);
    }

    public function updateDefaultBehavior(int $userId, string $behavior): void
    {
        $destinations = match (self::normalizeBehavior($behavior)) {
            'save_to_cloud' => ['dropbox'],
            'save_to_both' => ['disk', 'dropbox'],
            default => ['disk'],
        };
        $this->updateDefaultDestinations($userId, $destinations);
    }

    /**
     * @param list<string> $destinations
     */
    public function updateDefaultDestinations(int $userId, array $destinations): void
    {
        $normalized = self::normalizeDestinations($destinations);
        if ($normalized === []) {
            $normalized = ['disk'];
        }
        $stmt = $this->db->prepare(
            'UPDATE users SET
                default_destinations = :d,
                dropbox_default_behavior = :b
             WHERE id = :id'
        );
        $stmt->execute([
            ':d' => self::destinationsToJson($normalized),
            ':b' => self::behaviorFromDestinations($normalized),
            ':id' => $userId,
        ]);
    }

    public function updateCloudDestinationPrefs(int $userId, string $folder, string $conflict): void
    {
        $stmt = $this->db->prepare(
            'UPDATE users SET
                dropbox_folder = :f,
                dropbox_conflict = :c
             WHERE id = :id'
        );
        $stmt->execute([
            ':f' => self::normalizeFolder($folder),
            ':c' => self::normalizeConflict($conflict),
            ':id' => $userId,
        ]);
    }

    public function updateCloudPrefs(int $userId, string $behavior, string $folder, string $conflict): void
    {
        $this->updateDefaultBehavior($userId, $behavior);
        $this->updateCloudDestinationPrefs($userId, $folder, $conflict);
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
