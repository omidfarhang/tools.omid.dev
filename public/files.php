<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Auth;
use App\Cloud\CloudSendService;
use App\Csrf;
use App\FileRepository;
use App\ShareService;
use App\UserRepository;
use App\View;

$user = Auth::requireLogin();
$users = new UserRepository();
$user = $users->findById((int) $user['id']) ?? $user;
$files = (new FileRepository())->forUser((int) $user['id']);
$cloud = CloudSendService::create();
$cloudConnected = $cloud->registry()->anyConnected($user);
$prefs = UserRepository::cloudPrefs($user);

$cloudJob = null;
if (!empty($_SESSION['_cloud_monitor'])) {
    $cloudJob = $_SESSION['_cloud_monitor'];
    unset($_SESSION['_cloud_monitor']);
} elseif (!empty($_SESSION['_dropbox_monitor'])) {
    // Legacy session key from older send flow.
    $cloudJob = $_SESSION['_dropbox_monitor'];
    $cloudJob['provider'] = $cloudJob['provider'] ?? 'dropbox';
    unset($_SESSION['_dropbox_monitor']);
}

ob_start();
?>
<div class="page-header">
    <p class="page-lede">Download files from the host or toggle a public link to share without login.</p>
</div>

<div id="cloud-status" class="alert d-none" role="alert">
    <div id="cloud-message"></div>
    <div id="cloud-progress" class="progress mt-2 d-none">
        <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width:100%"></div>
    </div>
</div>

<?php if (!$cloudConnected): ?>
    <div class="status-callout">
        Tip: <a href="/user/">Connect Dropbox</a> to send files to your cloud.
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
                        <td class="text-end" style="min-width:12rem">
                            <div class="d-flex flex-column align-items-end gap-2">
                                <?php if ($cloudConnected): ?>
                                    <details class="w-100 text-start">
                                        <summary class="btn btn-sm btn-outline-primary">Send to cloud</summary>
                                        <form method="post" action="/dropbox_send.php" class="border rounded p-2 mt-2 bg-white">
                                            <?= Csrf::field() ?>
                                            <input type="hidden" name="file_id" value="<?= (int) $file['id'] ?>">
                                            <div class="mb-2">
                                                <label class="form-label small mb-1" for="folder-<?= (int) $file['id'] ?>">Folder</label>
                                                <input class="form-control form-control-sm mono" type="text"
                                                       name="cloud_folder" id="folder-<?= (int) $file['id'] ?>"
                                                       value="<?= e($prefs['folder']) ?>">
                                            </div>
                                            <div class="mb-2">
                                                <label class="form-label small mb-1" for="conflict-<?= (int) $file['id'] ?>">If exists</label>
                                                <select class="form-select form-select-sm" name="cloud_conflict" id="conflict-<?= (int) $file['id'] ?>">
                                                    <option value="rename" <?= $prefs['conflict'] === 'rename' ? 'selected' : '' ?>>Rename</option>
                                                    <option value="overwrite" <?= $prefs['conflict'] === 'overwrite' ? 'selected' : '' ?>>Overwrite</option>
                                                    <option value="skip" <?= $prefs['conflict'] === 'skip' ? 'selected' : '' ?>>Skip</option>
                                                </select>
                                            </div>
                                            <div class="form-check mb-2">
                                                <input class="form-check-input" type="checkbox" value="1" name="delete_after"
                                                       id="delete-<?= (int) $file['id'] ?>">
                                                <label class="form-check-label small" for="delete-<?= (int) $file['id'] ?>">
                                                    Delete from host after success
                                                </label>
                                            </div>
                                            <button class="btn btn-sm btn-primary" type="submit">Send</button>
                                        </form>
                                    </details>
                                <?php endif; ?>
                                <a class="btn btn-sm btn-outline-danger"
                                   href="/delete.php?id=<?= (int) $file['id'] ?>&_csrf=<?= e(Csrf::token()) ?>"
                                   onclick="return confirm('Delete this file?');">Delete</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php
include dirname(__DIR__) . '/templates/partials/cloud_monitor_js.php';
View::render('My Files', ob_get_clean(), $user);
