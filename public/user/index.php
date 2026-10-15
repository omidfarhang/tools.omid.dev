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
    redirect('/user/');
}

$user = $users->findById((int) $user['id']) ?? $user;
$prefs = UserRepository::cloudPrefs($user);
$preferredDestinations = $prefs['destinations'];
$usedBytes = $users->usedBytes((int) $user['id']);
$quotaBytes = UserRepository::effectiveQuotaBytes($user);
$configuredProviders = [];
foreach ($registry->all() as $provider) {
    if ($provider->isConfigured()) {
        $configuredProviders[] = $provider;
    }
}

ob_start();
$active = 'overview';
include dirname(__DIR__, 2) . '/templates/partials/account_nav.php';
?>
<p class="text-secondary mb-3">Account details and preferred download destinations.</p>

<div class="row row-cards">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-body">
                <h2 class="card-title">Details</h2>
                <dl class="mb-0">
                    <dt class="text-secondary">Username</dt>
                    <dd class="mb-3"><?= e($user['username']) ?></dd>
                    <dt class="text-secondary">Role</dt>
                    <dd class="mb-3"><span class="badge bg-azure-lt text-azure"><?= e($user['role']) ?></span></dd>
                    <dt class="text-secondary">Storage</dt>
                    <dd class="mb-0"><span class="mono"><?= e(UserRepository::formatUsageLabel($usedBytes, $quotaBytes)) ?></span></dd>
                </dl>
                <p class="mt-4 mb-0"><a href="/files.php">Manage my files &amp; public links</a></p>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card">
            <div class="card-body">
                <h2 class="card-title">Download preferences</h2>
                <form method="post">
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
                <?php if ($configuredProviders !== []): ?>
                    <p class="form-text mt-3 mb-0">
                        Connect cloud accounts under <a href="/user/cloud.php">Cloud</a>.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php
View::render('Account', ob_get_clean(), $user);
