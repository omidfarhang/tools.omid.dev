<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/src/bootstrap.php';

use App\Auth;
use App\Config;
use App\Csrf;
use App\DropboxOAuth;
use App\ManagedSettings;
use App\SettingsRepository;
use App\View;

$admin = Auth::requireAdmin();
$settings = new SettingsRepository();
$oauth = new DropboxOAuth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_dropbox') {
        $key = trim((string) ($_POST['dropbox_app_key'] ?? ''));
        $secret = (string) ($_POST['dropbox_app_secret'] ?? '');
        $secretSubmitted = trim($secret) !== '';

        if ($key === '') {
            flash('error', 'Dropbox app key is required.');
            redirect('/admin/settings/cloud.php');
        }

        $settings->set(ManagedSettings::DROPBOX_APP_KEY, $key);
        Config::setOverride(ManagedSettings::DROPBOX_APP_KEY, $key);

        if ($secretSubmitted) {
            $settings->set(ManagedSettings::DROPBOX_APP_SECRET, $secret);
            Config::setOverride(ManagedSettings::DROPBOX_APP_SECRET, $secret);
        } elseif ((string) Config::get(ManagedSettings::DROPBOX_APP_SECRET, '') === '') {
            flash('error', 'Dropbox app secret is required the first time.');
            redirect('/admin/settings/cloud.php');
        }

        flash('success', 'Dropbox app credentials saved.');
    } elseif ($action === 'clear_dropbox') {
        // Store empty strings so admin clear wins over legacy env fallbacks.
        $settings->set(ManagedSettings::DROPBOX_APP_KEY, '');
        $settings->set(ManagedSettings::DROPBOX_APP_SECRET, '');
        Config::setOverride(ManagedSettings::DROPBOX_APP_KEY, '');
        Config::setOverride(ManagedSettings::DROPBOX_APP_SECRET, '');
        flash('success', 'Dropbox app credentials cleared.');
    }

    redirect('/admin/settings/cloud.php');
}

$appKey = (string) Config::get(ManagedSettings::DROPBOX_APP_KEY, '');
$appSecret = (string) Config::get(ManagedSettings::DROPBOX_APP_SECRET, '');
$secretStored = $appSecret !== '';
$configured = $oauth->isConfigured();
$redirectUri = $oauth->redirectUri();

ob_start();
?>
<a class="d-inline-block mb-3" href="/admin/settings/">&larr; Settings</a>
<div class="page-header">
    <p class="text-secondary">Cloud provider app credentials. Users still connect their own accounts via OAuth.</p>
</div>

<div class="card mb-3">
    <div class="card-body">
    <h2 class="card-title">Dropbox</h2>
    <?php if ($configured): ?>
        <p class="mb-3">App credentials are configured. Users can connect Dropbox from Account.</p>
    <?php else: ?>
        <p class="text-muted mb-3">Not configured. Create an app in the Dropbox App Console, then paste the key and secret here.</p>
    <?php endif; ?>

    <p class="form-text mb-3">
        Redirect URI to register in Dropbox:
        <code class="user-select-all"><?= e($redirectUri) ?></code>
        · <a href="https://www.dropbox.com/developers/apps" target="_blank" rel="noopener">Dropbox App Console</a>
    </p>

    <form method="post" class="vstack gap-3 mb-3" autocomplete="off">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="save_dropbox">
        <div>
            <label class="form-label" for="dropbox_app_key">App key</label>
            <input class="form-control font-monospace" type="text" name="dropbox_app_key" id="dropbox_app_key"
                   value="<?= e($appKey) ?>" required autocomplete="off" spellcheck="false">
        </div>
        <div>
            <label class="form-label" for="dropbox_app_secret">App secret</label>
            <input class="form-control font-monospace" type="password" name="dropbox_app_secret" id="dropbox_app_secret"
                   value="" autocomplete="new-password" spellcheck="false"
                   placeholder="<?= $secretStored ? '•••• leave blank to keep current' : 'Required' ?>">
            <?php if ($secretStored && $appSecret !== ''): ?>
                <div class="form-text">Stored secret: <span class="font-monospace"><?= e(ManagedSettings::maskSecret($appSecret)) ?></span></div>
            <?php endif; ?>
        </div>
        <div>
            <button type="submit" class="btn btn-primary">Save Dropbox credentials</button>
        </div>
    </form>

    <?php if ($configured): ?>
        <form method="post" onsubmit="return confirm('Clear Dropbox app credentials?');">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="clear_dropbox">
            <button type="submit" class="btn btn-outline-danger">Clear credentials</button>
        </form>
    <?php endif; ?>
    </div>
</div>
<?php
View::render('Admin · Cloud', ob_get_clean(), $admin);
