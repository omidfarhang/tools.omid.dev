<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Auth;
use App\AuthLog;
use App\ClientIp;
use App\Csrf;
use App\LoginRateLimiter;
use App\TotpService;
use App\TrustedDeviceRepository;
use App\UserRepository;
use App\View;
use App\WebAuthnCredentialRepository;

if (Auth::check()) {
    redirect('/');
}

if (isset($_GET['cancel'])) {
    Auth::clearPendingTotp();
    redirect('/login.php');
}

$user = Auth::pendingTotpUser();
if ($user === null) {
    redirect('/login.php');
}

$username = (string) $user['username'];
$error = null;
$totpEnabled = UserRepository::totpEnabled($user);
$hasPasskeys = (new WebAuthnCredentialRepository())->hasForUser((int) $user['id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    if (!$totpEnabled) {
        $error = 'Use a passkey to continue, or go back and sign in again.';
    } else {
        $code = trim((string) ($_POST['code'] ?? ''));
        $ip = ClientIp::resolve();
        $limiter = new LoginRateLimiter();

        if ($limiter->isLimited($ip, $username)) {
            AuthLog::write('LOCKOUT', $ip, $username, 'login');
            $error = 'Too many attempts. Try again in a few minutes.';
        } else {
            $totp = new TotpService();
            $secret = (string) ($user['totp_secret'] ?? '');
            $ok = $secret !== '' && $totp->verify($secret, $code);

            if (!$ok) {
                $hashes = TotpService::decodeRecoveryHashes(
                    isset($user['totp_recovery_hashes']) ? (string) $user['totp_recovery_hashes'] : null
                );
                $consumed = $totp->consumeRecoveryCode($hashes, $code);
                if ($consumed['ok']) {
                    (new UserRepository())->setTotpRecoveryHashes((int) $user['id'], $consumed['hashes']);
                    $ok = true;
                }
            }

            if ($ok) {
                Auth::completeTotpLogin((int) $user['id']);
                $limiter->clear($ip, $username);
                if (!empty($_POST['remember_device'])) {
                    (new TrustedDeviceRepository())->remember(
                        (int) $user['id'],
                        (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
                    );
                }
                redirect('/');
            }

            $limiter->recordFailure($ip, $username);
            AuthLog::write('FAILURE', $ip, $username, 'login');
            $error = 'Invalid authentication code.';
        }
    }
}

ob_start();
?>
<div class="card card-md">
    <div class="card-body">
        <h2 class="h2 text-center mb-4">Two-factor authentication</h2>
        <?php if ($hasPasskeys): ?>
            <p class="text-secondary text-center mb-3">
                Confirm with a passkey<?= $totpEnabled ? ', or enter an authenticator code' : '' ?>.
            </p>
            <div id="passkey-mfa-error" class="alert alert-danger d-none" role="alert"></div>
            <div class="mb-3">
                <label class="form-check">
                    <input class="form-check-input" type="checkbox" id="remember_device_passkey" value="1">
                    <span class="form-check-label">
                        Remember this device for <?= (int) TrustedDeviceRepository::TTL_DAYS ?> days
                    </span>
                </label>
            </div>
            <button type="button" class="btn btn-primary w-100 mb-3" id="passkey-assert-btn">
                Use a passkey
            </button>
            <?php if ($totpEnabled): ?>
                <div class="hr-text mb-3">or</div>
            <?php endif; ?>
        <?php elseif ($totpEnabled): ?>
            <p class="text-secondary text-center mb-4">
                Enter the 6-digit code from your authenticator app, or a recovery code.
            </p>
        <?php else: ?>
            <p class="text-secondary text-center mb-4">
                No second factor is available. Go back and sign in again, or contact an admin.
            </p>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($totpEnabled): ?>
            <form method="post" autocomplete="off">
                <?= Csrf::field() ?>
                <div class="mb-3">
                    <label class="form-label" for="code">Authentication code</label>
                    <input class="form-control form-control-lg text-center font-monospace" id="code" name="code"
                           inputmode="text" autocomplete="one-time-code" required
                           <?= $hasPasskeys ? '' : 'autofocus' ?>
                           placeholder="000000">
                </div>
                <div class="mb-3">
                    <label class="form-check">
                        <input class="form-check-input" type="checkbox" name="remember_device" value="1" id="remember_device">
                        <span class="form-check-label">
                            Remember this device for <?= (int) TrustedDeviceRepository::TTL_DAYS ?> days
                        </span>
                    </label>
                </div>
                <div class="form-footer">
                    <button class="btn <?= $hasPasskeys ? 'btn-outline-primary' : 'btn-primary' ?> w-100" type="submit">Verify</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>
<div class="text-center text-secondary mt-3">
    <a href="/totp.php?cancel=1">Back to login</a>
</div>
<?php if ($hasPasskeys): ?>
<script src="/assets/js/webauthn.js"></script>
<script>
(function () {
    var btn = document.getElementById('passkey-assert-btn');
    var err = document.getElementById('passkey-mfa-error');
    var remember = document.getElementById('remember_device_passkey');
    if (!btn || !window.ToolsWebAuthn) return;
    var csrf = <?= json_encode(Csrf::token(), JSON_THROW_ON_ERROR) ?>;
    btn.addEventListener('click', function () {
        err.classList.add('d-none');
        err.textContent = '';
        btn.disabled = true;
        window.ToolsWebAuthn.assertPasskey(csrf, remember && remember.checked)
            .then(function (res) {
                window.location.href = (res && res.redirect) ? res.redirect : '/';
            })
            .catch(function (e) {
                err.textContent = e && e.message ? e.message : 'Passkey verification failed.';
                err.classList.remove('d-none');
                btn.disabled = false;
            });
    });
})();
</script>
<?php endif; ?>
<?php
View::render('Two-factor', ob_get_clean(), null);
