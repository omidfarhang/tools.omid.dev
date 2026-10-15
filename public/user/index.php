<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Auth;
use App\Cloud\CloudRegistry;
use App\TrustedDeviceRepository;
use App\UserRepository;
use App\View;
use App\WebAuthnCredentialRepository;

$user = Auth::requireLogin();
$users = new UserRepository();
$user = $users->findById((int) $user['id']) ?? $user;
$registry = CloudRegistry::default();
$trustedDevices = new TrustedDeviceRepository();

$usedBytes = $users->usedBytes((int) $user['id']);
$quotaBytes = UserRepository::effectiveQuotaBytes($user);
$totpEnabled = UserRepository::totpEnabled($user);
$passkeyCount = (new WebAuthnCredentialRepository())->countForUser((int) $user['id']);
$mfaOn = Auth::mfaRequired($user);
$deviceCount = $mfaOn ? count($trustedDevices->listForUser((int) $user['id'])) : 0;

$connectedLabels = [];
foreach ($registry->all() as $provider) {
    if ($provider->isConfigured() && $provider->isConnected($user)) {
        $connectedLabels[] = $provider->label();
    }
}

ob_start();
$active = 'overview';
include dirname(__DIR__, 2) . '/templates/partials/account_nav.php';
?>
<p class="text-secondary mb-3">Profile, security, and cloud connections for this account.</p>

<div class="row row-cards">
    <div class="col-md-4">
        <a class="card card-link" href="/user/profile.php">
            <div class="card-body">
                <h3 class="card-title">Profile</h3>
                <div class="text-secondary mb-2">Username, role, storage, and download destination defaults.</div>
                <div class="text-secondary small">
                    <?= e($user['username']) ?>
                    · <?= e(UserRepository::formatUsageLabel($usedBytes, $quotaBytes)) ?>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-4">
        <a class="card card-link" href="/user/security.php">
            <div class="card-body">
                <h3 class="card-title">Security</h3>
                <div class="text-secondary mb-2">Password, authenticator, passkeys, and trusted devices.</div>
                <div class="text-secondary small">
                    2FA: <?= $mfaOn ? 'enabled' : 'off' ?>
                    <?php if ($totpEnabled): ?> · TOTP<?php endif; ?>
                    <?php if ($passkeyCount > 0): ?>
                        · <?= (int) $passkeyCount ?> passkey<?= $passkeyCount === 1 ? '' : 's' ?>
                    <?php endif; ?>
                    <?php if ($mfaOn): ?>
                        · Trusted devices: <?= (int) $deviceCount ?>
                    <?php endif; ?>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-4">
        <a class="card card-link" href="/user/cloud.php">
            <div class="card-body">
                <h3 class="card-title">Cloud</h3>
                <div class="text-secondary mb-2">Connect Dropbox or Google Drive and set upload defaults.</div>
                <div class="text-secondary small">
                    <?php if ($connectedLabels === []): ?>
                        No cloud connected
                    <?php else: ?>
                        Connected: <?= e(implode(', ', $connectedLabels)) ?>
                    <?php endif; ?>
                </div>
            </div>
        </a>
    </div>
</div>
<?php
View::render('Account', ob_get_clean(), $user);
