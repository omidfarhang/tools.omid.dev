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
    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS dropbox_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    file_id INTEGER NULL,
    async_job_id TEXT NULL,
    filename TEXT NOT NULL,
    dropbox_path TEXT NOT NULL,
    conflict_mode TEXT NOT NULL DEFAULT 'rename',
    delete_after INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'pending',
    error TEXT NULL,
    result_path TEXT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE SET NULL
);
SQL);

        self::ensureColumn($pdo, 'users', 'dropbox_default_behavior', "dropbox_default_behavior TEXT NOT NULL DEFAULT 'save_to_disk'");
        self::ensureColumn($pdo, 'users', 'dropbox_folder', "dropbox_folder TEXT NOT NULL DEFAULT 'Tools Center'");
        self::ensureColumn($pdo, 'users', 'dropbox_conflict', "dropbox_conflict TEXT NOT NULL DEFAULT 'rename'");
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
