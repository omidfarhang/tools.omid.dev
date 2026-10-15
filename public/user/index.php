<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Auth;
use App\Cloud\CloudRegistry;
use App\Csrf;
use App\TotpService;
use App\UserRepository;
use App\View;

$user = Auth::requireLogin();
$users = new UserRepository();
$user = $users->findById((int) $user['id']) ?? $user;
$registry = CloudRegistry::default();
$totp = new TotpService();

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
        $allowed = ['disk'];
        foreach ($registry->all() as $provider) {
            if ($provider->isConfigured()) {
                $allowed[] = $provider->id();
            }
        }
        $rawDest = $_POST['dest'] ?? [];
        if (!is_array($rawDest)) {
            $rawDest = [];
        }
        $destinations = UserRepository::normalizeDestinations($rawDest, $allowed);
        if ($destinations === []) {
            flash('error', 'Choose at least one preferred destination.');
            redirect('/user/');
        }
        $users->updateDefaultDestinations((int) $user['id'], $destinations);
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
    if ($action === 'totp_start') {
        $password = (string) ($_POST['password'] ?? '');
        $hash = (string) ($user['password_hash'] ?? '');
        if (UserRepository::totpEnabled($user)) {
            flash('error', 'Two-factor authentication is already enabled.');
        } elseif ($hash === '' || !password_verify($password, $hash)) {
            flash('error', 'Current password is incorrect.');
        } else {
            Auth::setTotpSetupSecret($totp->createSecret());
            flash('success', 'Scan the QR code with your authenticator app, then enter a code to confirm.');
        }
        redirect('/user/');
    }
    if ($action === 'totp_cancel_setup') {
        Auth::clearTotpSetupSecret();
        flash('success', 'Two-factor setup cancelled.');
        redirect('/user/');
    }
    if ($action === 'totp_confirm') {
        $secret = Auth::totpSetupSecret();
        $code = trim((string) ($_POST['code'] ?? ''));
        if ($secret === null) {
            flash('error', 'No two-factor setup in progress. Start again.');
        } elseif (!$totp->verify($secret, $code)) {
            flash('error', 'Invalid authentication code. Try again.');
        } else {
            $plainCodes = $totp->generateRecoveryCodes();
            $users->enableTotp((int) $user['id'], $secret, $totp->hashRecoveryCodes($plainCodes));
            Auth::clearTotpSetupSecret();
            $_SESSION['totp_recovery_codes_once'] = $plainCodes;
            flash('success', 'Two-factor authentication enabled. Save your recovery codes now — they will not be shown again.');
        }
        redirect('/user/');
    }
    if ($action === 'totp_disable') {
        $password = (string) ($_POST['password'] ?? '');
        $code = trim((string) ($_POST['code'] ?? ''));
        $hash = (string) ($user['password_hash'] ?? '');
        $secret = (string) ($user['totp_secret'] ?? '');
        if (!UserRepository::totpEnabled($user)) {
            flash('error', 'Two-factor authentication is not enabled.');
        } elseif ($hash === '' || !password_verify($password, $hash)) {
            flash('error', 'Current password is incorrect.');
        } else {
            $ok = $totp->verify($secret, $code);
            if (!$ok) {
                $hashes = TotpService::decodeRecoveryHashes(
                    isset($user['totp_recovery_hashes']) ? (string) $user['totp_recovery_hashes'] : null
                );
                $consumed = $totp->consumeRecoveryCode($hashes, $code);
                $ok = $consumed['ok'];
            }
            if (!$ok) {
                flash('error', 'Invalid authentication or recovery code.');
            } else {
                $users->disableTotp((int) $user['id']);
                Auth::clearTotpSetupSecret();
                unset($_SESSION['totp_recovery_codes_once']);
                flash('success', 'Two-factor authentication disabled.');
            }
        }
        redirect('/user/');
    }
    if ($action === 'totp_regenerate_recovery') {
        $password = (string) ($_POST['password'] ?? '');
        $code = trim((string) ($_POST['code'] ?? ''));
        $hash = (string) ($user['password_hash'] ?? '');
        $secret = (string) ($user['totp_secret'] ?? '');
        if (!UserRepository::totpEnabled($user)) {
            flash('error', 'Two-factor authentication is not enabled.');
        } elseif ($hash === '' || !password_verify($password, $hash)) {
            flash('error', 'Current password is incorrect.');
        } elseif (!$totp->verify($secret, $code)) {
            flash('error', 'Invalid authentication code.');
        } else {
            $plainCodes = $totp->generateRecoveryCodes();
            $users->setTotpRecoveryHashes((int) $user['id'], $totp->hashRecoveryCodes($plainCodes));
            $_SESSION['totp_recovery_codes_once'] = $plainCodes;
            flash('success', 'New recovery codes generated. Save them now — they will not be shown again.');
        }
        redirect('/user/');
    }
    if ($action === 'totp_dismiss_recovery') {
        unset($_SESSION['totp_recovery_codes_once']);
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
$preferredDestinations = $prefs['destinations'];
$usedBytes = $users->usedBytes((int) $user['id']);
$quotaBytes = UserRepository::effectiveQuotaBytes($user);
$providers = $registry->all();
$configuredProviders = [];
foreach ($providers as $provider) {
    if ($provider->isConfigured()) {
        $configuredProviders[] = $provider;
    }
}
$totpEnabled = UserRepository::totpEnabled($user);
$totpSetupSecret = Auth::totpSetupSecret();
$recoveryCodesOnce = $_SESSION['totp_recovery_codes_once'] ?? null;
if (!is_array($recoveryCodesOnce)) {
    $recoveryCodesOnce = null;
}

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

            <div class="mt-4 pt-4 border-top">
                <h3 class="h6 mb-3">Two-factor authentication</h3>
                <?php if ($recoveryCodesOnce !== null): ?>
                    <div class="alert alert-warning">
                        <p class="mb-2 fw-semibold">Save these recovery codes now</p>
                        <p class="small text-secondary mb-2">Each code works once. Store them offline — they will not be shown again.</p>
                        <ul class="font-monospace small mb-3">
                            <?php foreach ($recoveryCodesOnce as $rc): ?>
                                <li><?= e((string) $rc) ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <form method="post">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="totp_dismiss_recovery">
                            <button class="btn btn-sm btn-primary" type="submit">I have saved these codes</button>
                        </form>
                    </div>
                <?php elseif ($totpSetupSecret !== null): ?>
                    <p class="text-secondary mb-3">Scan this QR code with your authenticator app, then enter a 6-digit code to confirm.</p>
                    <?php
                    $qrUri = $totp->qrDataUri((string) $user['username'], $totpSetupSecret, 200);
                    ?>
                    <?php if ($qrUri !== ''): ?>
                        <div class="text-center mb-3">
                            <img src="<?= e($qrUri) ?>"
                                 alt="TOTP QR code" width="200" height="200" class="border rounded bg-white p-2">
                        </div>
                    <?php endif; ?>
                    <p class="small text-secondary mb-1">Or enter this key manually:</p>
                    <p class="font-monospace mb-3"><?= e($totp->manualKey($totpSetupSecret)) ?></p>
                    <p class="small text-secondary mb-1">Provisioning URI:</p>
                    <p class="font-monospace small text-break mb-3"><?= e($totp->otpauthUri((string) $user['username'], $totpSetupSecret)) ?></p>
                    <form method="post" class="mb-2" autocomplete="off">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="totp_confirm">
                        <div class="mb-3">
                            <label class="form-label" for="totp_confirm_code">Authentication code</label>
                            <input class="form-control font-monospace" type="text" name="code" id="totp_confirm_code"
                                   inputmode="numeric" autocomplete="one-time-code" required pattern="[0-9]{6}">
                        </div>
                        <button class="btn btn-primary" type="submit">Confirm and enable</button>
                    </form>
                    <form method="post">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="totp_cancel_setup">
                        <button class="btn btn-link px-0" type="submit">Cancel setup</button>
                    </form>
                <?php elseif ($totpEnabled): ?>
                    <div class="alert alert-success mb-3">Two-factor authentication is enabled.</div>
                    <form method="post" class="mb-4" autocomplete="off">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="totp_regenerate_recovery">
                        <h4 class="h6">Regenerate recovery codes</h4>
                        <p class="form-text mb-2">Invalidates previous recovery codes.</p>
                        <div class="mb-2">
                            <label class="form-label" for="totp_regen_password">Current password</label>
                            <input class="form-control" type="password" name="password" id="totp_regen_password"
                                   required autocomplete="current-password">
                        </div>
                        <div class="mb-2">
                            <label class="form-label" for="totp_regen_code">Authenticator code</label>
                            <input class="form-control font-monospace" type="text" name="code" id="totp_regen_code"
                                   inputmode="numeric" autocomplete="one-time-code" required>
                        </div>
                        <button class="btn btn-outline-primary btn-sm" type="submit">Generate new codes</button>
                    </form>
                    <form method="post" autocomplete="off">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="totp_disable">
                        <h4 class="h6">Disable two-factor</h4>
                        <div class="mb-2">
                            <label class="form-label" for="totp_disable_password">Current password</label>
                            <input class="form-control" type="password" name="password" id="totp_disable_password"
                                   required autocomplete="current-password">
                        </div>
                        <div class="mb-2">
                            <label class="form-label" for="totp_disable_code">Authenticator or recovery code</label>
                            <input class="form-control font-monospace" type="text" name="code" id="totp_disable_code"
                                   required autocomplete="one-time-code">
                        </div>
                        <button class="btn btn-outline-danger btn-sm" type="submit">Disable 2FA</button>
                    </form>
                <?php else: ?>
                    <p class="text-secondary mb-3">Add an authenticator app code at login for extra protection.</p>
                    <form method="post" autocomplete="off">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="totp_start">
                        <div class="mb-3">
                            <label class="form-label" for="totp_start_password">Confirm password</label>
                            <input class="form-control" type="password" name="password" id="totp_start_password"
                                   required autocomplete="current-password">
                        </div>
                        <button class="btn btn-outline-primary" type="submit">Set up two-factor</button>
                    </form>
                <?php endif; ?>
            </div>

            <form method="post" class="mt-4">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="save_download_prefs">
                <fieldset class="choice-fieldset mb-3">
                    <legend class="form-label">Preferred destinations</legend>
                    <div class="choice-grid">
                        <label class="choice-tile" for="pref_dest_disk">
                            <input class="form-check-input" type="checkbox" name="dest[]" id="pref_dest_disk"
                                   value="disk" <?= in_array('disk', $preferredDestinations, true) ? 'checked' : '' ?>>
                            <span class="choice-tile-body">
                                <span class="choice-tile-title">Local</span>
                                <span class="choice-tile-desc">Keep on this host</span>
                            </span>
                        </label>
                        <?php foreach ($configuredProviders as $provider): ?>
                            <?php
                            $pid = $provider->id();
                            $pConnected = $provider->isConnected($user);
                            $pChecked = $pConnected && in_array($pid, $preferredDestinations, true);
                            ?>
                            <label class="choice-tile<?= $pConnected ? '' : ' is-disabled' ?>" for="pref_dest_<?= e($pid) ?>">
                                <input class="form-check-input" type="checkbox" name="dest[]"
                                       id="pref_dest_<?= e($pid) ?>"
                                       value="<?= e($pid) ?>"
                                       <?= $pChecked ? 'checked' : '' ?>
                                       <?= $pConnected ? '' : 'disabled' ?>>
                                <span class="choice-tile-body">
                                    <span class="choice-tile-title"><?= e($provider->label()) ?></span>
                                    <span class="choice-tile-desc"><?= $pConnected ? 'Pre-select on home' : 'Connect first' ?></span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="form-text mt-2">Pre-selects destinations on the home download form. Auto-delete is chosen per job.</div>
                </fieldset>
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
