<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Admin disk inventory: tracked usage, orphan temp files, failed job counts.
 */
final class DiskOverview
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::pdo();
    }

    /**
     * @return array{
     *   total_bytes: int,
     *   total_files: int,
     *   users: list<array{id:int, username:string, used_bytes:int, file_count:int, quota_bytes:?int}>
     * }
     */
    public function usage(): array
    {
        $rows = $this->db->query(
            "SELECT u.id, u.username, u.quota_bytes,
                COALESCE(f.file_count, 0) AS file_count,
                COALESCE(f.used_bytes, 0) AS used_bytes
             FROM users u
             LEFT JOIN (
                SELECT user_id,
                       COUNT(*) AS file_count,
                       SUM(size_bytes) AS used_bytes
                FROM files
                GROUP BY user_id
             ) f ON f.user_id = u.id
             ORDER BY used_bytes DESC, u.username ASC"
        )->fetchAll();

        $users = [];
        $totalBytes = 0;
        $totalFiles = 0;
        foreach ($rows as $row) {
            $used = (int) ($row['used_bytes'] ?? 0);
            $count = (int) ($row['file_count'] ?? 0);
            $totalBytes += $used;
            $totalFiles += $count;
            $quota = $row['quota_bytes'] ?? null;
            $users[] = [
                'id' => (int) $row['id'],
                'username' => (string) $row['username'],
                'used_bytes' => $used,
                'file_count' => $count,
                'quota_bytes' => ($quota === null || $quota === '') ? null : (int) $quota,
            ];
        }

        return [
            'total_bytes' => $totalBytes,
            'total_files' => $totalFiles,
            'users' => $users,
        ];
    }

    /**
     * Files in the temp dir that are not referenced by files.stored_name.
     *
     * @return list<array{name:string, size:int, mtime:int}>
     */
    public function orphans(): array
    {
        $tracked = $this->trackedStoredNames();
        $dir = Config::tempDir();
        if (!is_dir($dir)) {
            return [];
        }

        $entries = @scandir($dir);
        if ($entries === false) {
            return [];
        }

        $orphans = [];
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (isset($tracked[$name])) {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            if (!is_file($path) || is_link($path)) {
                continue;
            }
            $size = filesize($path);
            $mtime = filemtime($path);
            $orphans[] = [
                'name' => $name,
                'size' => $size === false ? 0 : (int) $size,
                'mtime' => $mtime === false ? 0 : (int) $mtime,
            ];
        }

        usort($orphans, static fn (array $a, array $b): int => $b['size'] <=> $a['size']);

        return $orphans;
    }

    /**
     * @return array{download_jobs: int, cloud_jobs: int}
     */
    public function failedJobCounts(): array
    {
        $download = (int) $this->db->query(
            "SELECT COUNT(*) FROM download_jobs WHERE status = 'failed'"
        )->fetchColumn();
        $cloud = (int) $this->db->query(
            "SELECT COUNT(*) FROM cloud_jobs WHERE status = 'failed'"
        )->fetchColumn();

        return [
            'download_jobs' => $download,
            'cloud_jobs' => $cloud,
        ];
    }

    /**
     * Delete orphan files by basename. Names not currently orphaned are skipped.
     *
     * @param list<string> $names
     * @return int Number of files removed
     */
    public function deleteOrphans(array $names): int
    {
        if ($names === []) {
            return 0;
        }

        $allowed = [];
        foreach ($this->orphans() as $orphan) {
            $allowed[$orphan['name']] = true;
        }

        $deleted = 0;
        foreach ($names as $raw) {
            $name = basename((string) $raw);
            if ($name === '' || $name === '.' || $name === '..' || !isset($allowed[$name])) {
                continue;
            }
            $path = FileRepository::absolutePath($name);
            if ($path === null || !is_file($path)) {
                continue;
            }
            if (@unlink($path)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /** Delete every current orphan. @return int Number of files removed */
    public function deleteAllOrphans(): int
    {
        $names = array_map(
            static fn (array $o): string => $o['name'],
            $this->orphans()
        );

        return $this->deleteOrphans($names);
    }

    /** @return array<string, true> */
    private function trackedStoredNames(): array
    {
        $stmt = $this->db->query('SELECT stored_name FROM files');
        $tracked = [];
        while ($row = $stmt->fetch()) {
            $name = basename((string) ($row['stored_name'] ?? ''));
            if ($name !== '') {
                $tracked[$name] = true;
            }
        }

        return $tracked;
    }
}
