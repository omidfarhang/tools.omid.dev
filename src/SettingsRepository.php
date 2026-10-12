<?php

declare(strict_types=1);

namespace App;

use PDO;

final class SettingsRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::pdo();
    }

    public function exists(string $key): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM app_settings WHERE key = :k LIMIT 1');
        $stmt->execute([':k' => $key]);

        return $stmt->fetchColumn() !== false;
    }

    public function get(string $key): ?string
    {
        $stmt = $this->db->prepare('SELECT value FROM app_settings WHERE key = :k');
        $stmt->execute([':k' => $key]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        return (string) $row['value'];
    }

    public function getUpdatedAt(string $key): ?string
    {
        $stmt = $this->db->prepare('SELECT updated_at FROM app_settings WHERE key = :k');
        $stmt->execute([':k' => $key]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        return (string) $row['updated_at'];
    }

    public function set(string $key, string $value): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO app_settings (key, value, updated_at) VALUES (:k, :v, datetime(\'now\'))
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = datetime(\'now\')'
        );
        $stmt->execute([':k' => $key, ':v' => $value]);
    }

    public function delete(string $key): void
    {
        $stmt = $this->db->prepare('DELETE FROM app_settings WHERE key = :k');
        $stmt->execute([':k' => $key]);
    }
}
