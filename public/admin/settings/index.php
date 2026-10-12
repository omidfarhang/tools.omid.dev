<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/src/bootstrap.php';

use App\Auth;
use App\Config;
use App\DropboxOAuth;
use App\View;
use App\YtDlpCookieStore;

$admin = Auth::requireAdmin();
$cookies = new YtDlpCookieStore();
$dropboxConfigured = (new DropboxOAuth())->isConfigured();
$extraArgs = trim((string) Config::get('ytdlp_extra_args', ''));

ob_start();
?>
<a class="breadcrumb-dash" href="/admin/">&larr; Admin</a>
<div class="page-header">
    <p class="page-lede">Site-wide configuration previously kept in <span class="font-monospace">.env</span> — edit by section.</p>
</div>

<div class="row g-3">
    <div class="col-md-6">
        <a class="action-tile" href="/admin/settings/ytdlp.php">
            <div class="action-tile-title">yt-dlp</div>
            <p class="action-tile-desc mb-2">Cookies and extra extractor flags for YouTube and other sites.</p>
            <p class="mb-0 small text-muted">
                Cookies: <?= $cookies->has() ? 'configured' : 'not set' ?>
                · Extra args: <?= $extraArgs !== '' ? 'set' : 'none' ?>
            </p>
        </a>
    </div>
    <div class="col-md-6">
        <a class="action-tile" href="/admin/settings/cloud.php">
            <div class="action-tile-title">Cloud</div>
            <p class="action-tile-desc mb-2">Dropbox app key and secret for per-user OAuth.</p>
            <p class="mb-0 small text-muted">
                Dropbox app: <?= $dropboxConfigured ? 'configured' : 'not set' ?>
            </p>
        </a>
    </div>
</div>
<?php
View::render('Admin · Settings', ob_get_clean(), $admin);
