<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/src/bootstrap.php';

use App\Auth;
use App\Config;
use App\DropboxOAuth;
use App\GoogleDriveOAuth;
use App\LoginRateLimiter;
use App\UserRepository;
use App\View;
use App\YtDlpCookieStore;

$admin = Auth::requireAdmin();
$cookies = new YtDlpCookieStore();
$dropboxConfigured = (new DropboxOAuth())->isConfigured();
$googleDriveConfigured = (new GoogleDriveOAuth())->isConfigured();
$extraArgs = trim((string) Config::get('ytdlp_extra_args', ''));
$defaultQuota = (new UserRepository())->defaultQuotaBytes();
$limiter = new LoginRateLimiter();
$maxAttempts = $limiter->maxAttempts();
$windowMinutes = $limiter->windowMinutes();
$activeBlocks = count($limiter->listActiveBlocks());

ob_start();
?>
<p class="text-secondary mb-3">Site-wide configuration — edit by section.</p>

<div class="row row-cards">
    <div class="col-md-6">
        <a class="card card-link" href="/admin/settings/ytdlp.php">
            <div class="card-body">
                <h3 class="card-title">yt-dlp</h3>
                <div class="text-secondary mb-2">Cookies and extra extractor flags for YouTube and other sites.</div>
                <div class="text-secondary small">
                    Cookies: <?= $cookies->has() ? 'configured' : 'not set' ?>
                    · Extra args: <?= $extraArgs !== '' ? 'set' : 'none' ?>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-6">
        <a class="card card-link" href="/admin/settings/cloud.php">
            <div class="card-body">
                <h3 class="card-title">Cloud</h3>
                <div class="text-secondary mb-2">Cloud app credentials for per-user OAuth.</div>
                <div class="text-secondary small">
                    Dropbox: <?= $dropboxConfigured ? 'configured' : 'not set' ?>
                    · Google Drive: <?= $googleDriveConfigured ? 'configured' : 'not set' ?>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-6">
        <a class="card card-link" href="/admin/settings/storage.php">
            <div class="card-body">
                <h3 class="card-title">Storage</h3>
                <div class="text-secondary mb-2">Default disk quota for new user accounts.</div>
                <div class="text-secondary small">
                    Default quota: <?= e(UserRepository::formatQuotaLabel($defaultQuota)) ?>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-6">
        <a class="card card-link" href="/admin/settings/security.php">
            <div class="card-body">
                <h3 class="card-title">Security</h3>
                <div class="text-secondary mb-2">Login rate limits and unblock locked IPs or usernames.</div>
                <div class="text-secondary small">
                    <?= (int) $maxAttempts ?> / <?= (int) $windowMinutes ?> min
                    · Active blocks: <?= (int) $activeBlocks ?>
                </div>
            </div>
        </a>
    </div>
</div>
<?php
View::render('Settings', ob_get_clean(), $admin, [
    ['label' => 'Admin', 'href' => '/admin/'],
    ['label' => 'Settings'],
]);
