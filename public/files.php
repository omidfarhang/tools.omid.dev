<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Auth;
use App\Csrf;
use App\FileRepository;
use App\ShareService;
use App\UserRepository;
use App\View;

$user = Auth::requireLogin();
$files = (new FileRepository())->forUser((int) $user['id']);
$dropboxConnected = (new UserRepository())->hasDropbox($user);

ob_start();
?>
<div class="page-header">
    <p class="page-lede">Download files from the host or toggle a public link to share without login.</p>
</div>

<?php if (!$dropboxConnected): ?>
    <div class="status-callout">
        Tip: <a href="/user/">Connect Dropbox</a> to send files to your account.
    </div>
<?php endif; ?>

<div class="panel panel-flush">
    <div class="panel-head d-flex justify-content-between align-items-center">
        <h2 class="panel-title mb-0">Your files</h2>
        <span class="text-muted small"><?= count($files) ?> file<?= count($files) === 1 ? '' : 's' ?></span>
    </div>
    <?php if (!$files): ?>
        <div class="empty-state">
            <strong>No files yet</strong>
            Pull a URL from the <a href="/">Download</a> page to get started.
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-striped align-middle mb-0">
                <thead>
                <tr>
                    <th>#</th>
                    <th>File</th>
                    <th>Size</th>
                    <th>Date</th>
                    <th>Public link</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($files as $i => $file): ?>
                    <?php
                    $shareUrl = !empty($file['share_token'])
                        ? ShareService::publicUrl((string) $file['share_token'])
                        : null;
                    ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td>
                            <a class="filename" href="/download.php?id=<?= (int) $file['id'] ?>"><?= e($file['original_name']) ?></a>
                        </td>
                        <td><?= e(human_filesize((int) $file['size_bytes'])) ?></td>
                        <td><?= e($file['created_at']) ?></td>
                        <td style="min-width:14rem">
                            <?php if ($shareUrl): ?>
                                <div class="d-flex flex-column gap-1">
                                    <div class="input-group input-group-sm">
                                        <input class="form-control mono" readonly value="<?= e($shareUrl) ?>" id="share-<?= (int) $file['id'] ?>">
                                        <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('share-<?= (int) $file['id'] ?>').value)">Copy</button>
                                    </div>
                                    <form method="post" action="/share.php">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="file_id" value="<?= (int) $file['id'] ?>">
                                        <input type="hidden" name="action" value="revoke">
                                        <button class="btn btn-sm btn-outline-danger" type="submit">Disable public link</button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <form method="post" action="/share.php">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="file_id" value="<?= (int) $file['id'] ?>">
                                    <input type="hidden" name="action" value="enable">
                                    <button class="btn btn-sm btn-outline-primary" type="submit">Make public</button>
                                </form>
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
View::render('My Files', ob_get_clean(), $user);
