<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Auth;
use App\Csrf;
use App\TotpService;
use App\TrustedDeviceRepository;
use App\UserRepository;
use App\View;
use App\WebAuthnCredentialRepository;

$user = Auth::requireLogin();
$users = new UserRepository();
$user = $users->findById((int) $user['id']) ?? $user;
$totp = new TotpService();
$trustedDevices = new TrustedDeviceRepository();
$passkeys = new WebAuthnCredentialRepository();

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
                $fresh = $users->findById((int) $user['id']) ?? $user;
                if (!Auth::mfaRequired($fresh)) {
                    $trustedDevices->revokeAll((int) $user['id']);
                    flash('success', 'Authenticator app disabled.');
                } else {
                    flash('success', 'Authenticator app disabled. Passkeys still protect this account.');
                }
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
    if ($action === 'webauthn_delete') {
        $password = (string) ($_POST['password'] ?? '');
        $credentialPk = (int) ($_POST['credential_id'] ?? 0);
        $hash = (string) ($user['password_hash'] ?? '');
        if ($password === '' || $hash === '' || !password_verify($password, $hash)) {
            flash('error', 'Current password is incorrect.');
        } elseif ($credentialPk <= 0 || !$passkeys->deleteForUser((int) $user['id'], $credentialPk)) {
            flash('error', 'Passkey not found.');
        } else {
            $fresh = $users->findById((int) $user['id']) ?? $user;
            if (!Auth::mfaRequired($fresh)) {
                $trustedDevices->revokeAll((int) $user['id']);
            }
            flash('success', 'Passkey removed.');
        }
        redirect('/user/security.php');
    }
    redirect('/user/security.php');
}

$user = $users->findById((int) $user['id']) ?? $user;
$totpEnabled = UserRepository::totpEnabled($user);
$mfaOn = Auth::mfaRequired($user);
$totpSetupSecret = Auth::totpSetupSecret();
$recoveryCodesOnce = $_SESSION['totp_recovery_codes_once'] ?? null;
if (!is_array($recoveryCodesOnce)) {
    $recoveryCodesOnce = null;
}
$passkeyList = $passkeys->listForUser((int) $user['id']);
$deviceList = $mfaOn ? $trustedDevices->listForUser((int) $user['id']) : [];

ob_start();
$active = 'security';
include dirname(__DIR__, 2) . '/templates/partials/account_nav.php';
?>
<p class="text-secondary mb-3">Password, authenticator app, and passkeys for this account.</p>

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
                    <?php
                    $recoveryCodesText = implode("\n", array_map(static fn ($c): string => (string) $c, $recoveryCodesOnce)) . "\n";
                    $recoveryCodesDownloadHref = 'data:text/plain;charset=utf-8,' . rawurlencode($recoveryCodesText);
                    ?>
                    <div class="alert alert-warning">
                        <p class="mb-2 fw-semibold">Save these recovery codes now</p>
                        <p class="small text-secondary mb-2">Each code works once. Store them offline — they will not be shown again.</p>
                        <ul class="font-monospace small mb-3">
                            <?php foreach ($recoveryCodesOnce as $rc): ?>
                                <li><?= e((string) $rc) ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <textarea id="recovery-codes-text" class="visually-hidden" readonly tabindex="-1" aria-hidden="true"><?= e($recoveryCodesText) ?></textarea>
                        <div class="d-flex flex-wrap gap-2 mb-3">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="recovery-codes-copy">Copy</button>
                            <a class="btn btn-sm btn-outline-secondary"
                               href="<?= e($recoveryCodesDownloadHref) ?>"
                               download="recovery-codes.txt">Download as .txt</a>
                        </div>
                        <form method="post">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="totp_dismiss_recovery">
                            <button class="btn btn-sm btn-primary" type="submit">I have saved these codes</button>
                        </form>
                    </div>
                    <script>
                    (function () {
                        var textEl = document.getElementById('recovery-codes-text');
                        var copyBtn = document.getElementById('recovery-codes-copy');
                        if (!textEl || !copyBtn) return;
                        var text = textEl.value.replace(/\n$/, '');
                        copyBtn.addEventListener('click', function () {
                            var prev = copyBtn.textContent;
                            var done = function () {
                                copyBtn.textContent = 'Copied';
                                setTimeout(function () { copyBtn.textContent = prev; }, 1200);
                            };
                            var fallback = function () {
                                textEl.classList.remove('visually-hidden');
                                textEl.focus();
                                textEl.select();
                                try { document.execCommand('copy'); } catch (e) {}
                                textEl.classList.add('visually-hidden');
                                done();
                            };
                            if (navigator.clipboard && navigator.clipboard.writeText) {
                                navigator.clipboard.writeText(text).then(done).catch(fallback);
                            } else {
                                fallback();
                            }
                        });
                    })();
                    </script>
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
                    <div class="alert alert-success mb-3">Authenticator app is enabled.</div>

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
                        <h3 class="h6">Disable authenticator app</h3>
                        <p class="form-text mb-2">
                            Passkeys (if any) stay registered and still count as two-factor.
                        </p>
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
                        <button class="btn btn-outline-danger btn-sm" type="submit">Disable authenticator</button>
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
                        <button class="btn btn-outline-primary" type="submit">Set up authenticator</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row row-cards mt-3">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-body">
                <h2 class="card-title">Passkeys</h2>
                <p class="text-secondary mb-3">
                    Use Face ID, Touch ID, Windows Hello, or a security key as a second factor after your password.
                    Works alongside or instead of an authenticator app.
                </p>
                <div id="passkey-error" class="alert alert-danger d-none" role="alert"></div>
                <?php if ($passkeyList === []): ?>
                    <p class="text-secondary small mb-3">No passkeys registered yet.</p>
                <?php else: ?>
                    <ul class="list-group list-group-flush mb-3">
                        <?php foreach ($passkeyList as $pk): ?>
                            <li class="list-group-item px-0">
                                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                    <div class="min-w-0">
                                        <div class="small text-truncate" title="<?= e((string) $pk['label']) ?>">
                                            <?= e((string) $pk['label']) ?>
                                        </div>
                                        <div class="text-secondary small">
                                            Added <?= e((string) $pk['created_at']) ?> UTC
                                            <?php if (!empty($pk['last_used_at'])): ?>
                                                · last used <?= e((string) $pk['last_used_at']) ?> UTC
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <form method="post" class="row g-2 align-items-end" autocomplete="off"
                                      onsubmit="return confirm('Remove this passkey?');">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="action" value="webauthn_delete">
                                    <input type="hidden" name="credential_id" value="<?= (int) $pk['id'] ?>">
                                    <div class="col">
                                        <label class="form-label visually-hidden" for="pk_del_pw_<?= (int) $pk['id'] ?>">Password</label>
                                        <input class="form-control form-control-sm" type="password" name="password"
                                               id="pk_del_pw_<?= (int) $pk['id'] ?>"
                                               placeholder="Current password" required autocomplete="current-password">
                                    </div>
                                    <div class="col-auto">
                                        <button class="btn btn-sm btn-outline-danger" type="submit">Remove</button>
                                    </div>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <div class="mb-2">
                    <label class="form-label" for="passkey_label">Label (optional)</label>
                    <input class="form-control" type="text" id="passkey_label" maxlength="80"
                           placeholder="e.g. MacBook Touch ID" autocomplete="off">
                </div>
                <button type="button" class="btn btn-outline-primary" id="passkey-register-btn">
                    Add a passkey
                </button>
            </div>
        </div>
    </div>
    <?php if ($mfaOn): ?>
    <div class="col-lg-6">
        <div class="card">
            <div class="card-body">
                <h2 class="card-title">Trusted devices</h2>
                <p class="form-text mb-2">
                    Devices that can skip the second factor for
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
        </div>
    </div>
    <?php endif; ?>
</div>
<script src="/assets/js/webauthn.js"></script>
<script>
(function () {
    var btn = document.getElementById('passkey-register-btn');
    var err = document.getElementById('passkey-error');
    var labelEl = document.getElementById('passkey_label');
    if (!btn || !window.ToolsWebAuthn) return;
    var csrf = <?= json_encode(Csrf::token(), JSON_THROW_ON_ERROR) ?>;
    btn.addEventListener('click', function () {
        err.classList.add('d-none');
        err.textContent = '';
        btn.disabled = true;
        window.ToolsWebAuthn.registerPasskey(csrf, labelEl ? labelEl.value : '')
            .then(function () { window.location.reload(); })
            .catch(function (e) {
                err.textContent = e && e.message ? e.message : 'Could not register passkey.';
                err.classList.remove('d-none');
                btn.disabled = false;
            });
    });
})();
</script>
<?php
View::render('Security', ob_get_clean(), $user, [
    ['label' => 'Account', 'href' => '/user/'],
    ['label' => 'Security'],
]);
