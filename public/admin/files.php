<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Auth;
use App\Csrf;
use App\FileRepository;
use App\ShareService;
use App\View;

$admin = Auth::requireAdmin();
$files = (new FileRepository())->allWithOwner();

ob_start();
?>
<a class="breadcrumb-dash" href="/admin/">&larr; Admin</a>
<div class="page-header">
    <p class="page-lede">Every file on the host, with owner and public-link controls.</p>
</div>

<div class="panel panel-flush">
    <div class="panel-head d-flex justify-content-between align-items-center">
        <h2 class="panel-title mb-0">All files</h2>
        <span class="text-muted small"><?= count($files) ?> file<?= count($files) === 1 ? '' : 's' ?></span>
    </div>
    <?php if (!$files): ?>
        <div class="empty-state">
            <strong>No files stored</strong>
            Nothing has been downloaded yet.
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Owner</th>
                    <th>File</th>
                    <th>Size</th>
                    <th>Public link</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($files as $file): ?>
                    <?php $shareUrl = !empty($file['share_token']) ? ShareService::publicUrl((string) $file['share_token']) : null; ?>
                    <tr>
                        <td><?= (int) $file['id'] ?></td>
                        <td><?= e($file['owner_username']) ?></td>
                        <td><a class="filename" href="/download.php?id=<?= (int) $file['id'] ?>"><?= e($file['original_name']) ?></a></td>
                        <td><?= e(human_filesize((int) $file['size_bytes'])) ?></td>
                        <td>
                            <?php if ($shareUrl): ?>
                                <div class="d-flex flex-wrap align-items-center gap-2">
                                    <code class="small mono"><?= e($shareUrl) ?></code>
                                    <form method="post" action="/share.php" class="d-inline">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="file_id" value="<?= (int) $file['id'] ?>">
                                        <input type="hidden" name="action" value="revoke">
                                        <input type="hidden" name="redirect" value="/admin/files.php">
                                        <button class="btn btn-sm btn-outline-danger" type="submit">Revoke</button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-danger"
                               href="/delete.php?id=<?= (int) $file['id'] ?>&_csrf=<?= e(Csrf::token()) ?>"
                               onclick="return confirm('Delete this file?');">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php
View::render('Admin Files', ob_get_clean(), $admin);
