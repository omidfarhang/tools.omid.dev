<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Auth;
use App\Csrf;
use App\TotpService;
use App\TrustedDeviceRepository;
use App\UserRepository;
use App\View;

$user = Auth::requireLogin();
$users = new UserRepository();
$user = $users->findById((int) $user['id']) ?? $user;
$totp = new TotpService();
$trustedDevices = new TrustedDeviceRepository();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $action = (string) ($_POST['action'] ?? '');
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
        redirect('/user/security.php');
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
        redirect('/user/security.php');
    }
    if ($action === 'totp_cancel_setup') {
        Auth::clearTotpSetupSecret();
        flash('success', 'Two-factor setup cancelled.');
        redirect('/user/security.php');
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
        redirect('/user/security.php');
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
        redirect('/user/security.php');
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
        redirect('/user/security.php');
    }
    if ($action === 'totp_dismiss_recovery') {
        unset($_SESSION['totp_recovery_codes_once']);
        redirect('/user/security.php');
    }
    if ($action === 'revoke_trusted_device') {
        $deviceId = (int) ($_POST['device_id'] ?? 0);
        if ($trustedDevices->revoke((int) $user['id'], $deviceId)) {
            flash('success', 'Trusted device removed.');
        } else {
            flash('error', 'Device not found.');
        }
        redirect('/user/security.php');
    }
    if ($action === 'revoke_all_trusted_devices') {
        $n = $trustedDevices->revokeAll((int) $user['id']);
        flash('success', $n > 0
            ? "Removed {$n} trusted device(s)."
            : 'No trusted devices to remove.');
        redirect('/user/security.php');
    }
    redirect('/user/security.php');
}

$user = $users->findById((int) $user['id']) ?? $user;
$totpEnabled = UserRepository::totpEnabled($user);
$totpSetupSecret = Auth::totpSetupSecret();
$recoveryCodesOnce = $_SESSION['totp_recovery_codes_once'] ?? null;
if (!is_array($recoveryCodesOnce)) {
    $recoveryCodesOnce = null;
}
$deviceList = $totpEnabled ? $trustedDevices->listForUser((int) $user['id']) : [];

ob_start();
?>
<p class="text-secondary mb-3">Password and two-factor authentication for this account.</p>

<div class="row row-cards">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-body">
                <h2 class="card-title">Change password</h2>
                <form method="post" autocomplete="off">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="change_password">
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
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card">
            <div class="card-body">
                <h2 class="card-title">Two-factor authentication</h2>
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

                    <div class="mb-4">
                        <h3 class="h6">Trusted devices</h3>
                        <p class="form-text mb-2">
                            Devices that can skip the authenticator code for
                            <?= (int) TrustedDeviceRepository::TTL_DAYS ?> days after password login.
                        </p>
                        <?php if ($deviceList === []): ?>
                            <p class="text-secondary small mb-0">No remembered devices.</p>
                        <?php else: ?>
                            <ul class="list-group list-group-flush mb-2">
                                <?php foreach ($deviceList as $device): ?>
                                    <li class="list-group-item px-0 d-flex justify-content-between align-items-start gap-2">
                                        <div class="min-w-0">
                                            <div class="small text-truncate" title="<?= e((string) $device['label']) ?>">
                                                <?= e((string) $device['label']) ?>
                                            </div>
                                            <div class="text-secondary small">
                                                Added <?= e((string) $device['created_at']) ?> UTC
                                                · expires <?= e((string) $device['expires_at']) ?> UTC
                                            </div>
                                        </div>
                                        <form method="post" class="flex-shrink-0">
                                            <?= Csrf::field() ?>
                                            <input type="hidden" name="action" value="revoke_trusted_device">
                                            <input type="hidden" name="device_id" value="<?= (int) $device['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger" type="submit">Revoke</button>
                                        </form>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <form method="post">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="action" value="revoke_all_trusted_devices">
                                <button class="btn btn-sm btn-link text-danger px-0" type="submit">Revoke all devices</button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <form method="post" class="mb-4" autocomplete="off">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="totp_regenerate_recovery">
                        <h3 class="h6">Regenerate recovery codes</h3>
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
                        <h3 class="h6">Disable two-factor</h3>
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
        </div>
    </div>
</div>
<?php
View::render('Security', ob_get_clean(), $user, [
    ['label' => 'Account', 'href' => '/user/'],
    ['label' => 'Security'],
]);
