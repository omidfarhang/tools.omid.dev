<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/src/bootstrap.php';

use App\Auth;
use App\Csrf;
use App\ManagedSettings;
use App\SettingsRepository;
use App\UserRepository;
use App\View;

$admin = Auth::requireAdmin();
$settings = new SettingsRepository();
$users = new UserRepository();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_default_quota') {
        $parsed = UserRepository::parseQuotaMibInput($_POST['default_quota_mib'] ?? null);
        if ($parsed === false) {
            flash('error', 'Default quota must be a whole number of MiB, or blank for unlimited.');
            redirect('/admin/settings/storage.php');
        }
        if ($parsed === null) {
            $settings->delete(ManagedSettings::DEFAULT_USER_QUOTA_BYTES);
            flash('success', 'Default user quota cleared (unlimited for new users).');
        } else {
            $settings->set(ManagedSettings::DEFAULT_USER_QUOTA_BYTES, (string) $parsed);
            flash('success', 'Default user quota saved.');
        }
    }

    redirect('/admin/settings/storage.php');
}

$defaultQuota = $users->defaultQuotaBytes();

ob_start();
?>
<p class="text-secondary mb-3">Site-wide storage defaults. Per-user limits are edited under Users.</p>

<div class="card">
    <div class="card-body">
        <h2 class="card-title">Default user quota</h2>
        <p class="text-secondary mb-3">
            Applied when a user self-registers or when an admin creates a user without a quota override.
            Existing accounts are not changed.
        </p>
        <form method="post" class="row g-3 align-items-end" autocomplete="off">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="save_default_quota">
            <div class="col-md-4">
                <label class="form-label" for="default_quota_mib">Quota (MiB)</label>
                <input class="form-control mono" type="text" inputmode="numeric" pattern="[0-9]*"
                       name="default_quota_mib" id="default_quota_mib"
                       value="<?= e(UserRepository::quotaBytesToMib($defaultQuota)) ?>"
                       placeholder="Unlimited" autocomplete="off">
                <div class="form-text">Leave blank for unlimited. Example: 1024 = 1 GiB.</div>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary">Save default</button>
            </div>
        </form>
        <p class="form-text mt-3 mb-0">
            Current default:
            <span class="fw-semibold"><?= e(UserRepository::formatQuotaLabel($defaultQuota)) ?></span>
        </p>
    </div>
</div>
<?php
View::render('Admin · Storage', ob_get_clean(), $admin, [
    ['label' => 'Admin', 'href' => '/admin/'],
    ['label' => 'Settings', 'href' => '/admin/settings/'],
    ['label' => 'Storage'],
]);
