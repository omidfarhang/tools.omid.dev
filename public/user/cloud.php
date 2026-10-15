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
        redirect('/user/cloud.php');
    }
    if ($action === 'save_dropbox_prefs' || $action === 'save_cloud_prefs') {
        $users->updateCloudDestinationPrefs(
            (int) $user['id'],
            (string) ($_POST['cloud_folder'] ?? $_POST['dropbox_folder'] ?? ''),
            (string) ($_POST['cloud_conflict'] ?? $_POST['dropbox_conflict'] ?? 'rename')
        );
        flash('success', 'Cloud defaults saved.');
        redirect('/user/cloud.php');
    }
    redirect('/user/cloud.php');
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
$providers = $registry->all();

ob_start();
$active = 'cloud';
include dirname(__DIR__, 2) . '/templates/partials/account_nav.php';
?>
<p class="text-secondary mb-3">Connect your own cloud accounts for “Save To Cloud”.</p>

<div class="row row-cards">
    <?php foreach ($providers as $provider): ?>
        <?php
        $configured = $provider->isConfigured();
        $connected = $provider->isConnected($user);
        $details = $provider->connectionDetails($user);
        $label = $provider->label();
        $folderId = 'cloud_folder_' . $provider->id();
        $conflictId = 'cloud_conflict_' . $provider->id();
        ?>
        <div class="col-lg-6">
            <div class="card">
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
        </div>
    <?php endforeach; ?>
</div>
<?php
View::render('Cloud', ob_get_clean(), $user, [
    ['label' => 'Account', 'href' => '/user/'],
    ['label' => 'Cloud'],
]);
