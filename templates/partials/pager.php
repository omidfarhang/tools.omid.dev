<?php
/**
 * Tabler pagination for list pages.
 *
 * @var string $pagerPath Path without query (e.g. /files.php)
 * @var array<string, scalar|null> $pagerQuery Base query params (q, sort, dir, user_id, …) without page
 * @var int $pagerPage
 * @var int $pagerTotalPages
 * @var int $pagerTotal
 * @var int|null $pagerPerPage
 */
$pagerPath = $pagerPath ?? '/';
$pagerQuery = $pagerQuery ?? [];
$pagerPage = max(1, (int) ($pagerPage ?? 1));
$pagerTotalPages = max(1, (int) ($pagerTotalPages ?? 1));
$pagerTotal = max(0, (int) ($pagerTotal ?? 0));
$pagerPerPage = (int) ($pagerPerPage ?? \App\Pager::DEFAULT_PER_PAGE);

if ($pagerTotalPages <= 1) {
    return;
}

$window = 2;
$start = max(1, $pagerPage - $window);
$end = min($pagerTotalPages, $pagerPage + $window);

$from = (($pagerPage - 1) * $pagerPerPage) + 1;
$to = min($pagerTotal, $pagerPage * $pagerPerPage);
?>
<div class="card-footer d-flex align-items-center flex-wrap gap-2 justify-content-between">
    <p class="m-0 text-secondary small">
        <?= e((string) $from) ?>–<?= e((string) $to) ?> of <?= e((string) $pagerTotal) ?>
    </p>
    <nav aria-label="Pagination">
        <ul class="pagination pagination-sm m-0">
            <li class="page-item<?= $pagerPage <= 1 ? ' disabled' : '' ?>">
                <?php if ($pagerPage <= 1): ?>
                    <span class="page-link" tabindex="-1" aria-disabled="true" aria-label="Previous page">&lsaquo;</span>
                <?php else: ?>
                    <a class="page-link" href="<?= e(\App\Pager::url($pagerPath, $pagerQuery, ['page' => $pagerPage - 1])) ?>" aria-label="Previous page">&lsaquo;</a>
                <?php endif; ?>
            </li>
            <?php if ($start > 1): ?>
                <li class="page-item">
                    <a class="page-link" href="<?= e(\App\Pager::url($pagerPath, $pagerQuery, ['page' => 1])) ?>">1</a>
                </li>
                <?php if ($start > 2): ?>
                    <li class="page-item disabled"><span class="page-link">…</span></li>
                <?php endif; ?>
            <?php endif; ?>
            <?php for ($p = $start; $p <= $end; $p++): ?>
                <li class="page-item<?= $p === $pagerPage ? ' active' : '' ?>">
                    <?php if ($p === $pagerPage): ?>
                        <span class="page-link" aria-current="page"><?= $p ?></span>
                    <?php else: ?>
                        <a class="page-link" href="<?= e(\App\Pager::url($pagerPath, $pagerQuery, ['page' => $p])) ?>"><?= $p ?></a>
                    <?php endif; ?>
                </li>
            <?php endfor; ?>
            <?php if ($end < $pagerTotalPages): ?>
                <?php if ($end < $pagerTotalPages - 1): ?>
                    <li class="page-item disabled"><span class="page-link">…</span></li>
                <?php endif; ?>
                <li class="page-item">
                    <a class="page-link" href="<?= e(\App\Pager::url($pagerPath, $pagerQuery, ['page' => $pagerTotalPages])) ?>"><?= $pagerTotalPages ?></a>
                </li>
            <?php endif; ?>
            <li class="page-item<?= $pagerPage >= $pagerTotalPages ? ' disabled' : '' ?>">
                <?php if ($pagerPage >= $pagerTotalPages): ?>
                    <span class="page-link" tabindex="-1" aria-disabled="true" aria-label="Next page">&rsaquo;</span>
                <?php else: ?>
                    <a class="page-link" href="<?= e(\App\Pager::url($pagerPath, $pagerQuery, ['page' => $pagerPage + 1])) ?>" aria-label="Next page">&rsaquo;</a>
                <?php endif; ?>
            </li>
        </ul>
    </nav>
</div>
