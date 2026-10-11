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
<h1>My Files</h1>
<p class="text-muted">Toggle a public link to share a file without login.</p>

<table class="table table-striped align-middle">
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
    <?php if (!$files): ?>
        <tr><td colspan="6" class="text-muted">No files yet.</td></tr>
    <?php endif; ?>
    <?php foreach ($files as $i => $file): ?>
        <?php
        $shareUrl = !empty($file['share_token'])
            ? ShareService::publicUrl((string) $file['share_token'])
            : null;
        ?>
        <tr>
            <th><?= $i + 1 ?></th>
            <td>
                <a href="/download.php?id=<?= (int) $file['id'] ?>"><?= e($file['original_name']) ?></a>
            </td>
            <td><?= e(human_filesize((int) $file['size_bytes'])) ?></td>
            <td><?= e($file['created_at']) ?></td>
            <td style="min-width:14rem">
                <?php if ($shareUrl): ?>
                    <div class="input-group input-group-sm">
                        <input class="form-control" readonly value="<?= e($shareUrl) ?>" id="share-<?= (int) $file['id'] ?>">
                        <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('share-<?= (int) $file['id'] ?>').value)">Copy</button>
                    </div>
                    <form method="post" action="/share.php" class="mt-1">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="file_id" value="<?= (int) $file['id'] ?>">
                        <input type="hidden" name="action" value="revoke">
                        <button class="btn btn-sm btn-outline-danger" type="submit">Disable public link</button>
                    </form>
                <?php else: ?>
                    <form method="post" action="/share.php">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="file_id" value="<?= (int) $file['id'] ?>">
                        <input type="hidden" name="action" value="enable">
                        <button class="btn btn-sm btn-outline-primary" type="submit">Make public</button>
                    </form>
                <?php endif; ?>
            </td>
            <td>
                <a class="btn btn-sm btn-outline-danger"
                   href="/delete.php?id=<?= (int) $file['id'] ?>&_csrf=<?= e(Csrf::token()) ?>"
                   onclick="return confirm('Delete this file?');">Delete</a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php if (!$dropboxConnected): ?>
    <p class="small text-muted">Tip: <a href="/user/">Connect Dropbox</a> to send files to your account.</p>
<?php endif; ?>
<?php
View::render('My Files', ob_get_clean(), $user);
