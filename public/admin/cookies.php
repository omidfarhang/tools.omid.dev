<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Auth;
use App\Csrf;
use App\View;
use App\YtDlpCookieStore;

$admin = Auth::requireAdmin();
$store = new YtDlpCookieStore();
$store->importLegacyFileIfNeeded();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save') {
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
                redirect('/admin/cookies.php');
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
    } elseif ($action === 'clear') {
        $store->clear();
        flash('success', 'yt-dlp cookies cleared.');
    }

    redirect('/admin/cookies.php');
}

$configured = $store->has();
$updatedAt = $store->updatedAt();
$size = $store->sizeBytes();

ob_start();
?>
<a class="breadcrumb-dash" href="/admin/">&larr; Admin</a>
<div class="page-header">
    <p class="page-lede">Site-wide Netscape cookies for yt-dlp (YouTube and other extractors that need auth).</p>
</div>

<div class="panel mb-3">
    <h2 class="panel-title">Status</h2>
    <?php if ($configured): ?>
        <p class="mb-1">Cookies are configured<?= $updatedAt !== null ? ' · updated ' . e($updatedAt) . ' UTC' : '' ?>.</p>
        <p class="text-muted mb-0"><span class="font-monospace"><?= (int) $size ?></span> bytes stored. Contents are not shown after save.</p>
    <?php else: ?>
        <p class="mb-0 text-muted">No cookies configured. YouTube downloads from datacenter IPs often fail without them.</p>
    <?php endif; ?>
</div>

<div class="panel mb-3">
    <h2 class="panel-title">Save cookies</h2>
    <p class="form-text mb-3">
        Export from a logged-in browser (prefer a private window →
        <code>youtube.com/robots.txt</code>), then paste or upload the Netscape file.
        See
        <a href="https://github.com/yt-dlp/yt-dlp/wiki/Extractors#exporting-youtube-cookies" target="_blank" rel="noopener">yt-dlp cookie tips</a>.
        Prefer a throwaway account.
    </p>
    <form method="post" enctype="multipart/form-data" class="vstack gap-3">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="save">
        <div>
            <label class="form-label" for="cookies">Paste Netscape cookies</label>
            <textarea class="form-control font-monospace" name="cookies" id="cookies" rows="10" spellcheck="false" placeholder="# Netscape HTTP Cookie File&#10;…"></textarea>
        </div>
        <div>
            <label class="form-label" for="cookies_file">Or upload cookies.txt</label>
            <input class="form-control" type="file" name="cookies_file" id="cookies_file" accept=".txt,text/plain">
        </div>
        <div>
            <button type="submit" class="btn btn-primary">Save cookies</button>
        </div>
    </form>
</div>

<?php if ($configured): ?>
<div class="panel">
    <h2 class="panel-title">Clear</h2>
    <p class="form-text mb-3">Removes stored cookies from the database and the on-disk file yt-dlp reads.</p>
    <form method="post" onsubmit="return confirm('Clear yt-dlp cookies?');">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="clear">
        <button type="submit" class="btn btn-outline-danger">Clear cookies</button>
    </form>
</div>
<?php endif; ?>
<?php
View::render('Admin · yt-dlp cookies', ob_get_clean(), $admin);
