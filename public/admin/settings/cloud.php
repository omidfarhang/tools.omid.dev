<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/src/bootstrap.php';

use App\Auth;
use App\Config;
use App\Csrf;
use App\DropboxOAuth;
use App\GoogleDriveOAuth;
use App\ManagedSettings;
use App\SettingsRepository;
use App\View;

$admin = Auth::requireAdmin();
$settings = new SettingsRepository();
$dropboxOauth = new DropboxOAuth();
$googleOauth = new GoogleDriveOAuth();

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
        $settings->set(ManagedSettings::DROPBOX_APP_KEY, '');
        $settings->set(ManagedSettings::DROPBOX_APP_SECRET, '');
        Config::setOverride(ManagedSettings::DROPBOX_APP_KEY, '');
        Config::setOverride(ManagedSettings::DROPBOX_APP_SECRET, '');
        flash('success', 'Dropbox app credentials cleared.');
    } elseif ($action === 'save_google_drive') {
        $clientId = trim((string) ($_POST['google_drive_client_id'] ?? ''));
        $secret = (string) ($_POST['google_drive_client_secret'] ?? '');
        $secretSubmitted = trim($secret) !== '';

        if ($clientId === '') {
            flash('error', 'Google Drive client ID is required.');
            redirect('/admin/settings/cloud.php');
        }

        $settings->set(ManagedSettings::GOOGLE_DRIVE_CLIENT_ID, $clientId);
        Config::setOverride(ManagedSettings::GOOGLE_DRIVE_CLIENT_ID, $clientId);

        if ($secretSubmitted) {
            $settings->set(ManagedSettings::GOOGLE_DRIVE_CLIENT_SECRET, $secret);
            Config::setOverride(ManagedSettings::GOOGLE_DRIVE_CLIENT_SECRET, $secret);
        } elseif ((string) Config::get(ManagedSettings::GOOGLE_DRIVE_CLIENT_SECRET, '') === '') {
            flash('error', 'Google Drive client secret is required the first time.');
            redirect('/admin/settings/cloud.php');
        }

        flash('success', 'Google Drive app credentials saved.');
    } elseif ($action === 'clear_google_drive') {
        $settings->set(ManagedSettings::GOOGLE_DRIVE_CLIENT_ID, '');
        $settings->set(ManagedSettings::GOOGLE_DRIVE_CLIENT_SECRET, '');
        Config::setOverride(ManagedSettings::GOOGLE_DRIVE_CLIENT_ID, '');
        Config::setOverride(ManagedSettings::GOOGLE_DRIVE_CLIENT_SECRET, '');
        flash('success', 'Google Drive app credentials cleared.');
    }

    redirect('/admin/settings/cloud.php');
}

$dropboxKey = (string) Config::get(ManagedSettings::DROPBOX_APP_KEY, '');
$dropboxSecret = (string) Config::get(ManagedSettings::DROPBOX_APP_SECRET, '');
$dropboxSecretStored = $dropboxSecret !== '';
$dropboxConfigured = $dropboxOauth->isConfigured();
$dropboxRedirectUri = $dropboxOauth->redirectUri();

$googleClientId = (string) Config::get(ManagedSettings::GOOGLE_DRIVE_CLIENT_ID, '');
$googleSecret = (string) Config::get(ManagedSettings::GOOGLE_DRIVE_CLIENT_SECRET, '');
$googleSecretStored = $googleSecret !== '';
$googleConfigured = $googleOauth->isConfigured();
$googleRedirectUri = $googleOauth->redirectUri();

ob_start();
?>
<p class="text-secondary mb-3">Cloud provider app credentials. Users still connect their own accounts via OAuth.</p>

<div class="card mb-3">
    <div class="card-body">
    <h2 class="card-title">Dropbox</h2>
    <?php if ($dropboxConfigured): ?>
        <p class="mb-3">App credentials are configured. Users can connect Dropbox from Account.</p>
    <?php else: ?>
        <p class="text-muted mb-3">Not configured. Create an app in the Dropbox App Console, then paste the key and secret here.</p>
    <?php endif; ?>

    <p class="form-text mb-3">
        Redirect URI to register in Dropbox:
        <code class="user-select-all"><?= e($dropboxRedirectUri) ?></code>
        · <a href="https://www.dropbox.com/developers/apps" target="_blank" rel="noopener">Dropbox App Console</a>
    </p>

    <form method="post" class="vstack gap-3 mb-3" autocomplete="off">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="save_dropbox">
        <div>
            <label class="form-label" for="dropbox_app_key">App key</label>
            <input class="form-control font-monospace" type="text" name="dropbox_app_key" id="dropbox_app_key"
                   value="<?= e($dropboxKey) ?>" required autocomplete="off" spellcheck="false">
        </div>
        <div>
            <label class="form-label" for="dropbox_app_secret">App secret</label>
            <input class="form-control font-monospace" type="password" name="dropbox_app_secret" id="dropbox_app_secret"
                   value="" autocomplete="new-password" spellcheck="false"
                   placeholder="<?= $dropboxSecretStored ? '•••• leave blank to keep current' : 'Required' ?>">
            <?php if ($dropboxSecretStored && $dropboxSecret !== ''): ?>
                <div class="form-text">Stored secret: <span class="font-monospace"><?= e(ManagedSettings::maskSecret($dropboxSecret)) ?></span></div>
            <?php endif; ?>
        </div>
        <div>
            <button type="submit" class="btn btn-primary">Save Dropbox credentials</button>
        </div>
    </form>

    <?php if ($dropboxConfigured): ?>
        <form method="post" onsubmit="return confirm('Clear Dropbox app credentials?');">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="clear_dropbox">
            <button type="submit" class="btn btn-outline-danger">Clear credentials</button>
        </form>
    <?php endif; ?>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
    <h2 class="card-title">Google Drive</h2>
    <?php if ($googleConfigured): ?>
        <p class="mb-3">App credentials are configured. Users can connect Google Drive from Account.</p>
    <?php else: ?>
        <p class="text-muted mb-3">Not configured. Create an OAuth client in Google Cloud Console, enable the Drive API, then paste the client ID and secret here.</p>
    <?php endif; ?>

    <p class="form-text mb-3">
        Redirect URI to register in Google Cloud:
        <code class="user-select-all"><?= e($googleRedirectUri) ?></code>
        · <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">Google Cloud Credentials</a>
    </p>
    <p class="form-text mb-3">Operator setup notes: <span class="font-monospace">docs/google-drive.md</span></p>

    <form method="post" class="vstack gap-3 mb-3" autocomplete="off">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="save_google_drive">
        <div>
            <label class="form-label" for="google_drive_client_id">Client ID</label>
            <input class="form-control font-monospace" type="text" name="google_drive_client_id" id="google_drive_client_id"
                   value="<?= e($googleClientId) ?>" required autocomplete="off" spellcheck="false">
        </div>
        <div>
            <label class="form-label" for="google_drive_client_secret">Client secret</label>
            <input class="form-control font-monospace" type="password" name="google_drive_client_secret" id="google_drive_client_secret"
                   value="" autocomplete="new-password" spellcheck="false"
                   placeholder="<?= $googleSecretStored ? '•••• leave blank to keep current' : 'Required' ?>">
            <?php if ($googleSecretStored && $googleSecret !== ''): ?>
                <div class="form-text">Stored secret: <span class="font-monospace"><?= e(ManagedSettings::maskSecret($googleSecret)) ?></span></div>
            <?php endif; ?>
        </div>
        <div>
            <button type="submit" class="btn btn-primary">Save Google Drive credentials</button>
        </div>
    </form>

    <?php if ($googleConfigured): ?>
        <form method="post" onsubmit="return confirm('Clear Google Drive app credentials?');">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="clear_google_drive">
            <button type="submit" class="btn btn-outline-danger">Clear credentials</button>
        </form>
    <?php endif; ?>
    </div>
</div>
<?php
View::render('Cloud', ob_get_clean(), $admin, [
    ['label' => 'Admin', 'href' => '/admin/'],
    ['label' => 'Settings', 'href' => '/admin/settings/'],
    ['label' => 'Cloud'],
]);
