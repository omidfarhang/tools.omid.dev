<?php

declare(strict_types=1);

namespace App;

use App\Cloud\CloudJobRepository;
use PDO;

/**
 * Combined download + standalone-cloud history with SQL filter and pagination.
 */
final class HistoryService
{
    private PDO $db;
    private DownloadJobRepository $downloads;
    private CloudJobRepository $cloudJobs;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::pdo();
        $this->downloads = new DownloadJobRepository($this->db);
        $this->cloudJobs = new CloudJobRepository($this->db);
    }

    /**
     * User-facing history (excludes soft-hidden rows).
     *
     * @return array{items:list<array{kind:string, sort:string, job:array<string, mixed>}>, total:int, page:int, per_page:int, total_pages:int}
     */
    public function forUser(int $userId, string $q = '', int $page = 1, int $perPage = Pager::DEFAULT_PER_PAGE): array
    {
        return $this->timeline($userId, false, false, $q, $page, $perPage);
    }

    /**
     * Admin history (includes soft-hidden rows). When $userId is set, scopes to that user.
     *
     * @return array{items:list<array{kind:string, sort:string, job:array<string, mixed>}>, total:int, page:int, per_page:int, total_pages:int}
     */
    public function forAdmin(?int $userId = null, string $q = '', int $page = 1, int $perPage = Pager::DEFAULT_PER_PAGE): array
    {
        return $this->timeline($userId, true, true, $q, $page, $perPage);
    }

    /**
     * 1-based page that contains a download job in the user timeline, or null if missing / hidden / filtered out.
     */
    public function pageForDownloadJobForUser(
        int $jobId,
        int $userId,
        string $q = '',
        int $perPage = Pager::DEFAULT_PER_PAGE
    ): ?int {
        return $this->pageForDownloadJob($jobId, $userId, false, false, $q, $perPage);
    }

    /**
     * 1-based page that contains a download job in the admin timeline, or null if missing / filtered out.
     */
    public function pageForDownloadJobForAdmin(
        int $jobId,
        ?int $userId = null,
        string $q = '',
        int $perPage = Pager::DEFAULT_PER_PAGE
    ): ?int {
        return $this->pageForDownloadJob($jobId, $userId, true, true, $q, $perPage);
    }

    private function pageForDownloadJob(
        int $jobId,
        ?int $userId,
        bool $includeHidden,
        bool $withOwner,
        string $q,
        int $perPage
    ): ?int {
        $built = $this->buildTimelineUnion($userId, $includeHidden, $withOwner, $q);
        $norm = Pager::normalize(1, $perPage);
        $params = $built['params'];
        $params[':jid'] = $jobId;

        $stmt = $this->db->prepare(
            "SELECT ranked.page FROM (
                SELECT kind, row_id,
                       ((ROW_NUMBER() OVER (ORDER BY sort_at DESC, row_id DESC) - 1) / {$norm['per_page']}) + 1 AS page
                FROM ({$built['union']}) timeline
             ) ranked
             WHERE ranked.kind = 'download' AND ranked.row_id = :jid"
        );
        $stmt->execute($params);
        $page = $stmt->fetchColumn();

        return $page === false ? null : (int) $page;
    }

    /**
     * @return array{items:list<array{kind:string, sort:string, job:array<string, mixed>}>, total:int, page:int, per_page:int, total_pages:int}
     */
    private function timeline(
        ?int $userId,
        bool $includeHidden,
        bool $withOwner,
        string $q,
        int $page,
        int $perPage
    ): array {
        $norm = Pager::normalize($page, $perPage);
        $built = $this->buildTimelineUnion($userId, $includeHidden, $withOwner, $q);
        $union = $built['union'];
        $params = $built['params'];

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM ({$union}) timeline");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        if ($norm['page'] > 1 && $total > 0) {
            $maxPage = (int) ceil($total / $norm['per_page']);
            if ($norm['page'] > $maxPage) {
                $norm = Pager::normalize($maxPage, $norm['per_page']);
            }
        }

        $pageStmt = $this->db->prepare(
            "SELECT kind, row_id, sort_at FROM ({$union}) timeline
             ORDER BY sort_at DESC, row_id DESC
             LIMIT {$norm['per_page']} OFFSET {$norm['offset']}"
        );
        $pageStmt->execute($params);
        $rows = $pageStmt->fetchAll();

        $downloadIds = [];
        $cloudIds = [];
        foreach ($rows as $row) {
            if ($row['kind'] === 'download') {
                $downloadIds[] = (int) $row['row_id'];
            } else {
                $cloudIds[] = (int) $row['row_id'];
            }
        }

        $downloadsById = $this->downloads->findHistoryByIds($downloadIds, $withOwner);
        $cloudById = $this->cloudJobs->findUnlinkedByIds($cloudIds, $withOwner);

        $items = [];
        foreach ($rows as $row) {
            $id = (int) $row['row_id'];
            if ($row['kind'] === 'download') {
                if (!isset($downloadsById[$id])) {
                    continue;
                }
                $items[] = [
                    'kind' => 'download',
                    'sort' => (string) $row['sort_at'],
                    'job' => $downloadsById[$id],
                ];
            } elseif (isset($cloudById[$id])) {
                $items[] = [
                    'kind' => 'cloud',
                    'sort' => (string) $row['sort_at'],
                    'job' => $cloudById[$id],
                ];
            }
        }

        return Pager::result($items, $total, $norm['page'], $norm['per_page']);
    }

    /**
     * @return array{union:string, params:array<string, mixed>}
     */
    private function buildTimelineUnion(
        ?int $userId,
        bool $includeHidden,
        bool $withOwner,
        string $q
    ): array {
        $q = Pager::searchQuery($q);
        $params = [];

        $downloadWhere = ["d.status IN ('complete', 'failed', 'cancelled')"];
        $cloudWhere = ['NOT EXISTS (SELECT 1 FROM download_jobs dx WHERE dx.cloud_job_id = cj.id)'];

        if ($userId !== null) {
            $downloadWhere[] = 'd.user_id = :uid';
            $cloudWhere[] = 'cj.user_id = :uid';
            $params[':uid'] = $userId;
        }
        if (!$includeHidden) {
            $downloadWhere[] = 'd.hidden_at IS NULL';
            $cloudWhere[] = 'cj.hidden_at IS NULL';
        }

        $downloadJoin = '';
        $cloudJoin = '';
        if ($q !== '') {
            $params[':q'] = Pager::likeContains($q);
            $downloadNameFilter = "(
                IFNULL(d.source_url, '') LIKE :q ESCAPE '\\'
                OR IFNULL(d.result_filename, '') LIKE :q ESCAPE '\\'
                OR IFNULL(d.requested_filename, '') LIKE :q ESCAPE '\\'
            )";
            $cloudNameFilter = "(
                IFNULL(cj.filename, '') LIKE :q ESCAPE '\\'
                OR IFNULL(cj.remote_path, '') LIKE :q ESCAPE '\\'
                OR IFNULL(cj.result_path, '') LIKE :q ESCAPE '\\'
            )";
            if ($withOwner) {
                $downloadJoin = 'JOIN users u ON u.id = d.user_id';
                $cloudJoin = 'JOIN users u ON u.id = cj.user_id';
                $downloadWhere[] = "({$downloadNameFilter} OR IFNULL(u.username, '') LIKE :q ESCAPE '\\')";
                $cloudWhere[] = "({$cloudNameFilter} OR IFNULL(u.username, '') LIKE :q ESCAPE '\\')";
            } else {
                $downloadWhere[] = $downloadNameFilter;
                $cloudWhere[] = $cloudNameFilter;
            }
        }

        $downloadSql = implode(' AND ', $downloadWhere);
        $cloudSql = implode(' AND ', $cloudWhere);

        $union = "SELECT 'download' AS kind, d.id AS row_id,
                         COALESCE(d.updated_at, d.created_at, '') AS sort_at
                  FROM download_jobs d
                  {$downloadJoin}
                  WHERE {$downloadSql}
                  UNION ALL
                  SELECT 'cloud' AS kind, cj.id AS row_id,
                         COALESCE(cj.updated_at, cj.created_at, '') AS sort_at
                  FROM cloud_jobs cj
                  {$cloudJoin}
                  WHERE {$cloudSql}";

        return ['union' => $union, 'params' => $params];
    }
}
