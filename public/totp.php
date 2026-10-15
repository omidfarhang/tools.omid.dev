<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Auth;
use App\AuthLog;
use App\ClientIp;
use App\Csrf;
use App\LoginRateLimiter;
use App\TotpService;
use App\UserRepository;
use App\View;

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
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
            redirect('/');
        }

        $limiter->recordFailure($ip, $username);
        AuthLog::write('FAILURE', $ip, $username, 'login');
        $error = 'Invalid authentication code.';
    }
}

ob_start();
?>
<div class="card card-md">
    <div class="card-body">
        <h2 class="h2 text-center mb-4">Two-factor authentication</h2>
        <p class="text-secondary text-center mb-4">
            Enter the 6-digit code from your authenticator app, or a recovery code.
        </p>
        <?php if ($error): ?>
            <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" autocomplete="off">
            <?= Csrf::field() ?>
            <div class="mb-2">
                <label class="form-label" for="code">Authentication code</label>
                <input class="form-control form-control-lg text-center font-monospace" id="code" name="code"
                       inputmode="text" autocomplete="one-time-code" required autofocus
                       placeholder="000000">
            </div>
            <div class="form-footer">
                <button class="btn btn-primary w-100" type="submit">Verify</button>
            </div>
        </form>
    </div>
</div>
<div class="text-center text-secondary mt-3">
    <a href="/totp.php?cancel=1">Back to login</a>
</div>
<?php
View::render('Two-factor', ob_get_clean(), null);
