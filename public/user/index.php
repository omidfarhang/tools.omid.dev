<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Auth;
use App\Cloud\CloudRegistry;
use App\Csrf;
use App\UserRepository;
use App\View;

$user = Auth::requireLogin();
$users = new UserRepository();
$user = $users->findById((int) $user['id']) ?? $user;
$registry = CloudRegistry::default();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'disconnect_cloud' || $action === 'disconnect_dropbox') {
        $providerId = (string) ($_POST['provider'] ?? 'dropbox');
        $provider = $registry->get($providerId);
        if ($provider === null) {
            flash('error', 'Unknown cloud provider.');
        } else {
            $provider->disconnect((int) $user['id']);
            flash('success', $provider->label() . ' disconnected.');
        }
        redirect('/user/');
    }
    if ($action === 'save_download_prefs') {
        $users->updateDefaultBehavior(
            (int) $user['id'],
            (string) ($_POST['cloud_default_behavior'] ?? $_POST['dropbox_default_behavior'] ?? 'save_to_disk')
        );
        flash('success', 'Download preference saved.');
        redirect('/user/');
    }
    if ($action === 'save_dropbox_prefs' || $action === 'save_cloud_prefs') {
        $users->updateCloudDestinationPrefs(
            (int) $user['id'],
            (string) ($_POST['cloud_folder'] ?? $_POST['dropbox_folder'] ?? ''),
            (string) ($_POST['cloud_conflict'] ?? $_POST['dropbox_conflict'] ?? 'rename')
        );
        flash('success', 'Cloud defaults saved.');
        redirect('/user/');
    }
    if ($action === 'change_password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['new_password_confirm'] ?? '');
        $hash = (string) ($user['password_hash'] ?? '');
        if ($current === '' || $new === '' || $confirm === '') {
            flash('error', 'All password fields are required.');
        } elseif ($hash === '' || !password_verify($current, $hash)) {
            flash('error', 'Current password is incorrect.');
        } elseif (strlen($new) < 8) {
            flash('error', 'New password must be at least 8 characters.');
        } elseif ($new !== $confirm) {
            flash('error', 'New passwords do not match.');
        } else {
            $users->setPassword((int) $user['id'], $new);
            flash('success', 'Password updated.');
        }
        redirect('/user/');
    }
}

$user = $users->findById((int) $user['id']) ?? $user;
foreach ($registry->all() as $provider) {
    if ($provider->isConnected($user)) {
        $details = $provider->connectionDetails($user);
        if (empty($details['email'])) {
            $provider->syncAccountInfo($user);
            $user = $users->findById((int) $user['id']) ?? $user;
        }
    }
}
$prefs = UserRepository::cloudPrefs($user);
$usedBytes = $users->usedBytes((int) $user['id']);
$quotaBytes = UserRepository::effectiveQuotaBytes($user);
$providers = $registry->all();

ob_start();
?>
<p class="text-secondary mb-3">Profile and cloud connections for this account.</p>

<div class="row row-cards">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-body">
            <h2 class="card-title">Profile</h2>
            <dl class="mb-0">
                <dt class="text-secondary">Username</dt>
                <dd class="mb-3"><?= e($user['username']) ?></dd>
                <dt class="text-secondary">Role</dt>
                <dd class="mb-3"><span class="badge bg-azure-lt text-azure"><?= e($user['role']) ?></span></dd>
                <dt class="text-secondary">Storage</dt>
                <dd class="mb-0"><span class="mono"><?= e(UserRepository::formatUsageLabel($usedBytes, $quotaBytes)) ?></span></dd>
            </dl>

            <form method="post" class="mt-4" autocomplete="off">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="change_password">
                <h3 class="h6 mb-3">Change password</h3>
                <div class="mb-3">
                    <label class="form-label" for="current_password">Current password</label>
                    <input class="form-control" type="password" name="current_password" id="current_password"
                           required autocomplete="current-password">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="new_password">New password</label>
                    <input class="form-control" type="password" name="new_password" id="new_password"
                           required minlength="8" autocomplete="new-password">
                    <div class="form-text">At least 8 characters.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="new_password_confirm">Confirm new password</label>
                    <input class="form-control" type="password" name="new_password_confirm" id="new_password_confirm"
                           required minlength="8" autocomplete="new-password">
                </div>
                <button class="btn btn-outline-primary" type="submit">Update password</button>
            </form>

            <form method="post" class="mt-4">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="save_download_prefs">
                <div class="mb-3">
                    <label for="cloud_default_behavior" class="form-label">Preferred download action</label>
                    <select class="form-select" name="cloud_default_behavior" id="cloud_default_behavior">
                        <option value="save_to_disk" <?= $prefs['behavior'] === 'save_to_disk' ? 'selected' : '' ?>>Save To Disk</option>
                        <option value="save_to_cloud" <?= $prefs['behavior'] === 'save_to_cloud' ? 'selected' : '' ?>>Save To Cloud</option>
                        <option value="save_to_both" <?= $prefs['behavior'] === 'save_to_both' ? 'selected' : '' ?>>Save To Disk + Cloud</option>
                    </select>
                    <div class="form-text">Highlights the primary button on the home page. Cloud options need a connected provider. Auto-delete is chosen per job, not here.</div>
                </div>
                <button class="btn btn-primary" type="submit">Save preference</button>
            </form>

            <p class="mt-4 mb-0"><a href="/files.php">Manage my files &amp; public links</a></p>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <?php foreach ($providers as $i => $provider): ?>
            <?php
            $configured = $provider->isConfigured();
            $connected = $provider->isConnected($user);
            $details = $provider->connectionDetails($user);
            $label = $provider->label();
            $folderId = 'cloud_folder_' . $provider->id();
            $conflictId = 'cloud_conflict_' . $provider->id();
            ?>
            <div class="card<?= $i < count($providers) - 1 ? ' mb-3' : '' ?>">
                <div class="card-body">
                <h2 class="card-title"><?= e($label) ?></h2>
                <?php if (!$configured): ?>
                    <div class="alert alert-warning mb-0"><?= e($label) ?> app credentials are not configured. An admin must set them under Settings → Cloud.</div>
                <?php elseif ($connected): ?>
                    <div class="alert alert-success mb-3"><?= e($label) ?> connected.</div>
                    <dl class="mb-4">
                        <?php if (!empty($details['display_name'])): ?>
                            <dt class="text-muted small">Name</dt>
                            <dd class="mb-3 fw-semibold"><?= e((string) $details['display_name']) ?></dd>
                        <?php endif; ?>
                        <?php if (!empty($details['email'])): ?>
                            <dt class="text-muted small">Email</dt>
                            <dd class="mb-3"><span class="mono"><?= e((string) $details['email']) ?></span></dd>
                        <?php endif; ?>
                        <?php if (!empty($details['account_id'])): ?>
                            <dt class="text-muted small">Account ID</dt>
                            <dd class="mb-0"><span class="mono"><?= e((string) $details['account_id']) ?></span></dd>
                        <?php endif; ?>
                    </dl>

                    <form method="post" class="mb-4">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="save_cloud_prefs">
                        <input type="hidden" name="provider" value="<?= e($provider->id()) ?>">
                        <h3 class="h6 mb-3">Defaults</h3>
                        <div class="mb-3">
                            <label for="<?= e($folderId) ?>" class="form-label">Default folder</label>
                            <input class="form-control mono" type="text" name="cloud_folder" id="<?= e($folderId) ?>"
                                   value="<?= e($prefs['folder']) ?>" placeholder="(root)">
                            <div class="form-text">Relative to your <?= e($label) ?> root. Leave blank for root.</div>
                        </div>
                        <div class="mb-3">
                            <label for="<?= e($conflictId) ?>" class="form-label">Default conflict policy</label>
                            <select class="form-select" name="cloud_conflict" id="<?= e($conflictId) ?>">
                                <option value="rename" <?= $prefs['conflict'] === 'rename' ? 'selected' : '' ?>>Rename (add number)</option>
                                <option value="overwrite" <?= $prefs['conflict'] === 'overwrite' ? 'selected' : '' ?>>Overwrite</option>
                                <option value="skip" <?= $prefs['conflict'] === 'skip' ? 'selected' : '' ?>>Skip</option>
                            </select>
                        </div>
                        <button class="btn btn-primary" type="submit">Save defaults</button>
                    </form>

                    <div class="d-flex flex-wrap gap-2">
                        <a class="btn btn-outline-primary" href="<?= e($provider->connectUrl(true)) ?>">Reconnect <?= e($label) ?></a>
                        <form method="post" class="d-inline">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="disconnect_cloud">
                            <input type="hidden" name="provider" value="<?= e($provider->id()) ?>">
                            <button class="btn btn-outline-danger" type="submit">Disconnect <?= e($label) ?></button>
                        </form>
                    </div>
                    <p class="form-text mt-2 mb-0">Use Reconnect after the app’s <?= e($label) ?> permissions change, or to refresh the linked account.</p>
                <?php else: ?>
                    <p class="text-secondary mb-3">Connect your own <?= e($label) ?> to use “Save To Cloud”.</p>
                    <a class="btn btn-primary" href="<?= e($provider->connectUrl()) ?>">Connect <?= e($label) ?></a>
                <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php
View::render('Account', ob_get_clean(), $user);
