<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/src/bootstrap.php';

use App\Auth;
use App\Config;
use App\Csrf;
use App\LoginRateLimiter;
use App\ManagedSettings;
use App\SettingsRepository;
use App\View;

$admin = Auth::requireAdmin();
$settings = new SettingsRepository();
$limiter = new LoginRateLimiter();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_limits') {
        $max = (int) ($_POST['login_max_attempts'] ?? 0);
        $window = (int) ($_POST['login_window_minutes'] ?? 0);
        if ($max < 1 || $max > 1000) {
            flash('error', 'Max attempts must be between 1 and 1000.');
            redirect('/admin/settings/security.php');
        }
        if ($window < 1 || $window > 1440) {
            flash('error', 'Window must be between 1 and 1440 minutes.');
            redirect('/admin/settings/security.php');
        }
        $settings->set(ManagedSettings::LOGIN_MAX_ATTEMPTS, (string) $max);
        $settings->set(ManagedSettings::LOGIN_WINDOW_MINUTES, (string) $window);
        Config::setOverride(ManagedSettings::LOGIN_MAX_ATTEMPTS, (string) $max);
        Config::setOverride(ManagedSettings::LOGIN_WINDOW_MINUTES, (string) $window);
        flash('success', 'Login rate limits saved.');
    }

    if ($action === 'unblock_ip') {
        $ip = trim((string) ($_POST['ip'] ?? ''));
        $n = $limiter->clearByIp($ip);
        flash($n > 0 ? 'success' : 'error', $n > 0
            ? "Cleared {$n} attempt(s) for IP {$ip}."
            : 'No attempts found for that IP.');
    }

    if ($action === 'unblock_username') {
        $username = trim((string) ($_POST['username'] ?? ''));
        if ($username === '(register)') {
            $username = LoginRateLimiter::REGISTER_KEY;
        }
        $n = $limiter->clearByUsername($username);
        $label = LoginRateLimiter::formatBlockKey('username', $username);
        flash($n > 0 ? 'success' : 'error', $n > 0
            ? "Cleared {$n} attempt(s) for username {$label}."
            : 'No attempts found for that username.');
    }

    redirect('/admin/settings/security.php');
}

$maxAttempts = $limiter->maxAttempts();
$windowMinutes = $limiter->windowMinutes();
$blocks = $limiter->listActiveBlocks();

ob_start();
?>
<p class="text-secondary mb-3">
    In-app login/register throttling. Edge limits (nginx) and fail2ban are configured on the host —
    see <span class="font-monospace">docs/fail2ban.md</span>.
</p>

<div class="card mb-3">
    <div class="card-body">
        <h2 class="card-title">Rate limits</h2>
        <p class="text-secondary mb-3">
            After this many failed attempts within the window (per IP or per username), further tries
            are blocked until the window slides or you unblock below.
        </p>
        <form method="post" class="row g-3 align-items-end" autocomplete="off">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="save_limits">
            <div class="col-md-3">
                <label class="form-label" for="login_max_attempts">Max attempts</label>
                <input class="form-control" type="number" min="1" max="1000" required
                       name="login_max_attempts" id="login_max_attempts"
                       value="<?= e((string) $maxAttempts) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="login_window_minutes">Window (minutes)</label>
                <input class="form-control" type="number" min="1" max="1440" required
                       name="login_window_minutes" id="login_window_minutes"
                       value="<?= e((string) $windowMinutes) ?>">
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary">Save limits</button>
            </div>
        </form>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <h2 class="card-title">Active blocks</h2>
        <p class="text-secondary mb-3">
            Keys at or over <?= (int) $maxAttempts ?> failures in the last <?= (int) $windowMinutes ?> minutes.
        </p>
        <?php if ($blocks === []): ?>
            <div class="empty">
                <p class="empty-title">No active blocks</p>
                <p class="empty-subtitle text-secondary">Nothing is currently rate-limited.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-vcenter">
                    <thead>
                    <tr>
                        <th>Type</th>
                        <th>Key</th>
                        <th>Failures</th>
                        <th>Latest (UTC)</th>
                        <th class="w-1"></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($blocks as $block): ?>
                        <?php
                        $type = $block['type'];
                        $key = $block['key'];
                        $label = LoginRateLimiter::formatBlockKey($type, $key);
                        ?>
                        <tr>
                            <td><?= e($type) ?></td>
                            <td class="font-monospace"><?= e($label) ?></td>
                            <td><?= (int) $block['count'] ?></td>
                            <td class="text-secondary"><?= e($block['latest']) ?></td>
                            <td>
                                <form method="post" class="d-inline">
                                    <?= Csrf::field() ?>
                                    <?php if ($type === 'ip'): ?>
                                        <input type="hidden" name="action" value="unblock_ip">
                                        <input type="hidden" name="ip" value="<?= e($key) ?>">
                                    <?php else: ?>
                                        <input type="hidden" name="action" value="unblock_username">
                                        <input type="hidden" name="username" value="<?= e($key) ?>">
                                    <?php endif; ?>
                                    <button type="submit" class="btn btn-sm">Unblock</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <h2 class="card-title">Manual unblock</h2>
        <p class="text-secondary mb-3">Clear attempts for an IP or username even if it is not listed above.</p>
        <div class="row g-3">
            <div class="col-md-6">
                <form method="post" class="row g-2 align-items-end" autocomplete="off">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="unblock_ip">
                    <div class="col">
                        <label class="form-label" for="manual_ip">IP address</label>
                        <input class="form-control font-monospace" name="ip" id="manual_ip"
                               placeholder="203.0.113.10" required>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn">Unblock IP</button>
                    </div>
                </form>
            </div>
            <div class="col-md-6">
                <form method="post" class="row g-2 align-items-end" autocomplete="off">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="unblock_username">
                    <div class="col">
                        <label class="form-label" for="manual_username">Username</label>
                        <input class="form-control font-monospace" name="username" id="manual_username"
                               placeholder="alice" pattern="[a-zA-Z0-9._-]{1,32}" required>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn">Unblock user</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php
View::render('Security', ob_get_clean(), $admin, [
    ['label' => 'Admin', 'href' => '/admin/'],
    ['label' => 'Settings', 'href' => '/admin/settings/'],
    ['label' => 'Security'],
]);
