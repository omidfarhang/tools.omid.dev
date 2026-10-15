<?php

declare(strict_types=1);

namespace App;

/**
 * Shared list pagination + query-string helpers for files / history pages.
 */
final class Pager
{
    public const DEFAULT_PER_PAGE = 50;

    /**
     * @return array{page:int, per_page:int, offset:int}
     */
    public static function normalize(?int $page, ?int $perPage = null): array
    {
        $perPage = $perPage ?? self::DEFAULT_PER_PAGE;
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page ?? 1);

        return [
            'page' => $page,
            'per_page' => $perPage,
            'offset' => ($page - 1) * $perPage,
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array{items:list<array<string, mixed>>, total:int, page:int, per_page:int, total_pages:int}
     */
    public static function result(array $items, int $total, int $page, int $perPage): array
    {
        $total = max(0, $total);
        $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 1;
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    public static function searchQuery(?string $q): string
    {
        $q = trim((string) $q);
        if (strlen($q) > 200) {
            $q = substr($q, 0, 200);
        }

        return $q;
    }

    /**
     * Escape a user search string for use with SQLite LIKE … ESCAPE '\'.
     */
    public static function likeContains(string $q): string
    {
        $escaped = str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $q
        );

        return '%' . $escaped . '%';
    }

    /**
     * Build a path?query URL, dropping empty values and resetting page when omitted.
     *
     * @param array<string, scalar|null> $base
     * @param array<string, scalar|null> $overrides
     */
    public static function url(string $path, array $base, array $overrides = []): string
    {
        $query = array_merge($base, $overrides);
        $parts = [];
        foreach ($query as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if ($key === 'page' && (int) $value <= 1) {
                continue;
            }
            $parts[$key] = $value;
        }

        if ($parts === []) {
            return $path;
        }

        return $path . '?' . http_build_query($parts);
    }

    /**
     * Current list query params from $_GET (q / page / sort / dir / optional user_id).
     *
     * @return array{q:string, page:int, sort:?string, dir:?string, user_id:?int}
     */
    public static function requestParams(bool $allowUserId = false): array
    {
        $q = self::searchQuery(isset($_GET['q']) ? (string) $_GET['q'] : null);
        $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
        $sort = isset($_GET['sort']) ? (string) $_GET['sort'] : null;
        $dir = isset($_GET['dir']) ? (string) $_GET['dir'] : null;
        $userId = null;
        if ($allowUserId && isset($_GET['user_id']) && (int) $_GET['user_id'] > 0) {
            $userId = (int) $_GET['user_id'];
        }

        return [
            'q' => $q,
            'page' => $page,
            'sort' => $sort,
            'dir' => $dir,
            'user_id' => $userId,
        ];
    }
}
