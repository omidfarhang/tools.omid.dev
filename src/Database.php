<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $path = (string) Config::get('db_path');
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        self::$pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        self::$pdo->exec('PRAGMA foreign_keys = ON');
        self::$pdo->exec('PRAGMA journal_mode = WAL');
        self::migrate(self::$pdo);
        self::seedAdmin(self::$pdo);

        return self::$pdo;
    }

    private static function migrate(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL CHECK(role IN ('admin', 'user')),
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    dropbox_account_id TEXT NULL,
    dropbox_access_token TEXT NULL,
    dropbox_refresh_token TEXT NULL,
    dropbox_token_expires_at TEXT NULL
);

CREATE TABLE IF NOT EXISTS files (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    stored_name TEXT NOT NULL,
    original_name TEXT NOT NULL,
    size_bytes INTEGER NOT NULL DEFAULT 0,
    source_url TEXT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS shares (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    file_id INTEGER NOT NULL,
    token TEXT NOT NULL UNIQUE,
    created_by INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    revoked_at TEXT NULL,
    expires_at TEXT NULL,
    max_downloads INTEGER NULL,
    download_count INTEGER NOT NULL DEFAULT 0,
    purpose TEXT NOT NULL DEFAULT 'user',
    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS cloud_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    provider TEXT NOT NULL DEFAULT 'dropbox',
    file_id INTEGER NULL,
    async_job_id TEXT NULL,
    filename TEXT NOT NULL,
    remote_path TEXT NOT NULL,
    conflict_mode TEXT NOT NULL DEFAULT 'rename',
    delete_after INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'pending',
    error TEXT NULL,
    result_path TEXT NULL,
    hidden_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS download_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    source_url TEXT NOT NULL,
    requested_filename TEXT NULL,
    result_filename TEXT NULL,
    behavior TEXT NOT NULL,
    yt_format TEXT NOT NULL DEFAULT 'best',
    force_ytdlp INTEGER NOT NULL DEFAULT 0,
    cloud_folder TEXT NULL,
    cloud_conflict TEXT NULL,
    delete_after INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'pending',
    error TEXT NULL,
    file_id INTEGER NULL,
    cloud_job_id INTEGER NULL,
    hidden_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE SET NULL,
    FOREIGN KEY (cloud_job_id) REFERENCES cloud_jobs(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS app_settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL,
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
SQL);

        self::ensureColumn($pdo, 'users', 'dropbox_default_behavior', "dropbox_default_behavior TEXT NOT NULL DEFAULT 'save_to_disk'");
        self::ensureColumn($pdo, 'users', 'dropbox_folder', "dropbox_folder TEXT NOT NULL DEFAULT ''");
        self::ensureColumn($pdo, 'users', 'dropbox_conflict', "dropbox_conflict TEXT NOT NULL DEFAULT 'rename'");
        self::ensureColumn($pdo, 'users', 'dropbox_email', 'dropbox_email TEXT NULL');
        self::ensureColumn($pdo, 'users', 'dropbox_display_name', 'dropbox_display_name TEXT NULL');
        self::ensureColumn($pdo, 'users', 'quota_bytes', 'quota_bytes INTEGER NULL');
        self::ensureColumn($pdo, 'download_jobs', 'force_ytdlp', 'force_ytdlp INTEGER NOT NULL DEFAULT 0');
        self::ensureColumn($pdo, 'download_jobs', 'hidden_at', 'hidden_at TEXT NULL');
        self::ensureColumn($pdo, 'cloud_jobs', 'hidden_at', 'hidden_at TEXT NULL');
        self::ensureColumn($pdo, 'shares', 'expires_at', 'expires_at TEXT NULL');
        self::ensureColumn($pdo, 'shares', 'max_downloads', 'max_downloads INTEGER NULL');
        self::ensureColumn($pdo, 'shares', 'download_count', 'download_count INTEGER NOT NULL DEFAULT 0');
        self::ensureColumn($pdo, 'shares', 'purpose', "purpose TEXT NOT NULL DEFAULT 'user'");
        self::migrateDropboxJobs($pdo);
        self::migrateBehaviorValues($pdo);
        self::migrateDefaultCloudFolder($pdo);
        self::migrateDownloadHistory($pdo);
        $settings = new SettingsRepository($pdo);
        (new YtDlpCookieStore($settings))->importLegacyFileIfNeeded();
        ManagedSettings::importFromEnvIfMissing($settings);
    }

    private static function migrateDownloadHistory(PDO $pdo): void
    {
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='download_history'")->fetchAll();
        if ($tables === []) {
            return;
        }

        $count = (int) $pdo->query('SELECT COUNT(*) FROM download_jobs')->fetchColumn();
        if ($count === 0) {
            $pdo->exec(
                "INSERT INTO download_jobs
                    (id, user_id, source_url, requested_filename, result_filename, behavior,
                     status, error, file_id, cloud_job_id, created_at, updated_at)
                 SELECT
                    id, user_id, source_url, requested_filename, result_filename, behavior,
                    CASE WHEN status = 'success' THEN 'complete' ELSE status END,
                    error, file_id, cloud_job_id, created_at, created_at
                 FROM download_history"
            );
        }

        $pdo->exec('DROP TABLE IF EXISTS download_history');
    }

    private static function migrateDropboxJobs(PDO $pdo): void
    {
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='dropbox_jobs'")->fetchAll();
        if ($tables === []) {
            return;
        }

        $count = (int) $pdo->query('SELECT COUNT(*) FROM cloud_jobs')->fetchColumn();
        if ($count === 0) {
            $pdo->exec(
                "INSERT INTO cloud_jobs
                    (id, user_id, provider, file_id, async_job_id, filename, remote_path, conflict_mode,
                     delete_after, status, error, result_path, created_at, updated_at)
                 SELECT
                    id, user_id, 'dropbox', file_id, async_job_id, filename, dropbox_path, conflict_mode,
                    delete_after, status, error, result_path, created_at, updated_at
                 FROM dropbox_jobs"
            );
        }

        $pdo->exec('DROP TABLE IF EXISTS dropbox_jobs');
    }

    private static function migrateBehaviorValues(PDO $pdo): void
    {
        $pdo->exec(
            "UPDATE users SET dropbox_default_behavior = 'save_to_cloud'
             WHERE dropbox_default_behavior = 'save_to_dropbox'"
        );
    }

    private static function migrateDefaultCloudFolder(PDO $pdo): void
    {
        // Former product default was a "Tools Center" subfolder; root is preferred.
        $pdo->exec(
            "UPDATE users SET dropbox_folder = ''
             WHERE dropbox_folder = 'Tools Center'"
        );
    }

    private static function ensureColumn(PDO $pdo, string $table, string $column, string $ddl): void
    {
        $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
        if ($stmt === false) {
            return;
        }
        foreach ($stmt->fetchAll() as $col) {
            if (($col['name'] ?? '') === $column) {
                return;
            }
        }
        $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $ddl);
    }

    private static function seedAdmin(PDO $pdo): void
    {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($count > 0) {
            return;
        }

        $username = (string) Config::get('admin_username');
        $password = (string) Config::get('admin_password');
        if ($username === '' || $password === '') {
            $username = 'admin';
            $password = 'admin';
        }

        $stmt = $pdo->prepare(
            'INSERT INTO users (username, password_hash, role, is_active) VALUES (:u, :p, :r, 1)'
        );
        $stmt->execute([
            ':u' => $username,
            ':p' => password_hash($password, PASSWORD_DEFAULT),
            ':r' => 'admin',
        ]);
    }
}
