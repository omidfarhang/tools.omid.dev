<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/src/bootstrap.php';

use App\Auth;
use App\Config;
use App\Csrf;
use App\ManagedSettings;
use App\SettingsRepository;
use App\View;
use App\YtDlpCookieStore;

$admin = Auth::requireAdmin();
$store = new YtDlpCookieStore();
$store->importLegacyFileIfNeeded();
$settings = new SettingsRepository();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_cookies') {
        $content = (string) ($_POST['cookies'] ?? '');
        if (isset($_FILES['cookies_file']) && is_array($_FILES['cookies_file'])) {
            $upload = $_FILES['cookies_file'];
            $err = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($err === UPLOAD_ERR_OK) {
                $tmp = (string) ($upload['tmp_name'] ?? '');
                if ($tmp !== '' && is_uploaded_file($tmp)) {
                    $uploaded = file_get_contents($tmp);
                    if ($uploaded !== false && trim($uploaded) !== '') {
                        $content = $uploaded;
                    }
                }
            } elseif ($err !== UPLOAD_ERR_NO_FILE) {
                flash('error', 'Cookie file upload failed.');
                redirect('/admin/settings/ytdlp.php');
            }
        }

        try {
            $store->save($content);
            flash('success', 'yt-dlp cookies saved.');
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        } catch (\RuntimeException $e) {
            flash('error', $e->getMessage());
        }
    } elseif ($action === 'clear_cookies') {
        $store->clear();
        flash('success', 'yt-dlp cookies cleared.');
    } elseif ($action === 'save_options') {
        $raw = trim((string) ($_POST['ytdlp_extra_args'] ?? ''));
        try {
            ManagedSettings::parseExtraArgs($raw);
            if ($raw === '') {
                $settings->delete(ManagedSettings::YTDLP_EXTRA_ARGS);
                Config::setOverride(ManagedSettings::YTDLP_EXTRA_ARGS, null);
            } else {
                $settings->set(ManagedSettings::YTDLP_EXTRA_ARGS, $raw);
                Config::setOverride(ManagedSettings::YTDLP_EXTRA_ARGS, $raw);
            }
            flash('success', 'yt-dlp options saved.');
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        }
    }

    redirect('/admin/settings/ytdlp.php');
}

$configured = $store->has();
$updatedAt = $store->updatedAt();
$size = $store->sizeBytes();
$extraArgs = (string) Config::get(ManagedSettings::YTDLP_EXTRA_ARGS, '');

ob_start();
?>
<a class="d-inline-block mb-3" href="/admin/settings/">&larr; Settings</a>
<p class="text-secondary mb-3">Site-wide yt-dlp options for YouTube and other extractors.</p>

<div class="card mb-3">
    <div class="card-body">
    <h2 class="card-title">Cookies</h2>
    <?php if ($configured): ?>
        <p class="mb-1">Configured<?= $updatedAt !== null ? ' · updated ' . e($updatedAt) . ' UTC' : '' ?>.</p>
        <p class="text-muted mb-3"><span class="font-monospace"><?= (int) $size ?></span> bytes stored. Contents are not shown after save.</p>
    <?php else: ?>
        <p class="text-muted mb-3">No cookies configured. YouTube downloads from datacenter IPs often fail without them.</p>
    <?php endif; ?>

    <p class="form-text mb-3">
        Export from a logged-in browser (prefer a private window →
        <code>youtube.com/robots.txt</code>), then paste or upload the Netscape file.
        See
        <a href="https://github.com/yt-dlp/yt-dlp/wiki/Extractors#exporting-youtube-cookies" target="_blank" rel="noopener">yt-dlp cookie tips</a>.
        Prefer a throwaway account.
    </p>
    <form method="post" enctype="multipart/form-data" class="vstack gap-3 mb-3">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="save_cookies">
        <div>
            <label class="form-label" for="cookies">Paste Netscape cookies</label>
            <textarea class="form-control font-monospace" name="cookies" id="cookies" rows="8" spellcheck="false" placeholder="# Netscape HTTP Cookie File&#10;…"></textarea>
        </div>
        <div>
            <label class="form-label" for="cookies_file">Or upload cookies.txt</label>
            <input class="form-control" type="file" name="cookies_file" id="cookies_file" accept=".txt,text/plain">
        </div>
        <div>
            <button type="submit" class="btn btn-primary">Save cookies</button>
        </div>
    </form>

    <?php if ($configured): ?>
        <form method="post" onsubmit="return confirm('Clear yt-dlp cookies?');">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="clear_cookies">
            <button type="submit" class="btn btn-outline-danger">Clear cookies</button>
        </form>
    <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <h2 class="card-title">Extra arguments</h2>
        <p class="form-text mb-3">
            Optional space-separated yt-dlp flags applied to every download (for example
            <code>--retries 3</code> or <code>--extractor-args youtube:player_client=android</code>).
            No shell metacharacters. Leave blank for defaults.
        </p>
        <form method="post" class="vstack gap-3">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="save_options">
            <div>
                <label class="form-label" for="ytdlp_extra_args">Extra args</label>
                <input class="form-control font-monospace" type="text" name="ytdlp_extra_args" id="ytdlp_extra_args"
                       value="<?= e($extraArgs) ?>"
                       placeholder="--geo-bypass --retries 3"
                       autocomplete="off" spellcheck="false">
            </div>
            <div>
                <button type="submit" class="btn btn-primary">Save options</button>
            </div>
        </form>
    </div>
</div>
<?php
View::render('Admin · yt-dlp', ob_get_clean(), $admin);
