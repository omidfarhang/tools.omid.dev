<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Auth;
use App\Csrf;
use App\DownloadService;
use App\DropboxClient;
use App\DropboxJobRepository;
use App\FileRepository;
use App\ShareService;
use App\UserRepository;
use App\View;

$user = Auth::requireLogin();
$users = new UserRepository();
$user = $users->findById((int) $user['id']) ?? $user;
$dropbox = new DropboxClient($user);
$dropboxConnected = $dropbox->isConnected();
$prefs = UserRepository::dropboxPrefs($user);
$preferred = $prefs['behavior'];

$dropboxJob = null;
$resultHtml = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $behavior = (string) ($_POST['behavior'] ?? '');
    $url = trim((string) ($_POST['DLURL'] ?? ''));
    $filename = trim((string) ($_POST['DLFILENAME'] ?? ''));
    $ytFormat = (string) ($_POST['yt_format'] ?? 'best');
    $folder = UserRepository::normalizeFolder((string) ($_POST['dropbox_folder'] ?? $prefs['folder']));
    $conflict = UserRepository::normalizeConflict((string) ($_POST['dropbox_conflict'] ?? $prefs['conflict']));
    $deleteAfter = isset($_POST['delete_after']) && (string) $_POST['delete_after'] === '1';

    $downloader = new DownloadService();
    $files = new FileRepository();
    $shares = new ShareService();

    if ($url === '') {
        flash('error', 'URL is required.');
        redirect('/');
    }

    if (!in_array($behavior, ['save_to_disk', 'save_to_dropbox', 'save_to_both'], true)) {
        flash('error', 'Invalid action.');
        redirect('/');
    }

    $needsDropbox = $behavior === 'save_to_dropbox' || $behavior === 'save_to_both';
    if ($needsDropbox && !$dropboxConnected) {
        flash('error', 'Connect your Dropbox account first (Account page).');
        redirect('/user/');
    }

    $ytId = is_youtube($url);
    $downloadResult = null;
    $fileId = null;

    $needsDisk = $behavior === 'save_to_disk'
        || $behavior === 'save_to_both'
        || ($behavior === 'save_to_dropbox' && $ytId !== false);

    if ($needsDisk) {
        $downloadResult = $ytId !== false
            ? $downloader->downloadYoutube($url, $ytFormat)
            : $downloader->downloadUrl($url, $filename !== '' ? $filename : null);

        if (!$downloadResult['ok']) {
            flash('error', $downloadResult['error'] ?? 'Download failed.');
            redirect('/');
        }

        $fileId = $files->create(
            (int) $user['id'],
            $downloadResult['stored_name'],
            $downloadResult['original_name'],
            (int) $downloadResult['size'],
            $url
        );

        if ($behavior === 'save_to_disk' || $behavior === 'save_to_both') {
            $resultHtml = '<div class="alert alert-success">'
                . 'Successfully downloaded <strong>' . e($downloadResult['original_name']) . '</strong> '
                . '(' . e(human_filesize((int) $downloadResult['size'])) . '). '
                . '<a href="/download.php?id=' . (int) $fileId . '">Download</a> · '
                . '<a href="/files.php">My Files</a>'
                . '</div>';
        }
    }

    if ($needsDropbox) {
        if ($ytId !== false || $behavior === 'save_to_both') {
            $token = $shares->enable((int) $fileId, (int) $user['id']);
            $fetchUrl = ShareService::publicUrl($token);
            $dbFilename = $downloadResult['original_name'] ?? 'video.mp4';
            $localFileId = (int) $fileId;
        } else {
            $fetchUrl = $url;
            $dbFilename = $filename !== '' ? $filename : null;
            $localFileId = null;
            $deleteAfter = false;
        }

        $sent = $dropbox->startJob(
            $fetchUrl,
            $dbFilename,
            $folder,
            $conflict,
            $deleteAfter,
            $localFileId
        );

        if ($sent['success']) {
            if (($sent['status'] ?? '') === 'skipped') {
                $resultHtml .= '<div class="alert alert-warning">'
                    . e($sent['message'] ?? 'Skipped — file already exists in Dropbox.')
                    . '</div>';
            } else {
                $dropboxJob = [
                    'asyncJobId' => $sent['async_job_id'] ?? null,
                    'filename' => $sent['filename'] ?? ($dbFilename ?? 'file'),
                    'jobId' => $sent['job_id'] ?? null,
                ];
            }
        } else {
            $resultHtml .= '<div class="alert alert-danger">' . e($sent['message'] ?? 'Dropbox failed')
                . (isset($sent['error']) ? ' — ' . e((string) $sent['error']) : '')
                . '</div>';
        }
    }
}

$dropboxJobs = (new DropboxJobRepository())->recentForUser((int) $user['id']);

$btnClass = static function (string $action) use ($preferred): string {
    return $action === $preferred ? 'btn btn-primary' : 'btn btn-outline-primary';
};

ob_start();
?>
<div class="page-header">
    <p class="page-lede">Pull an HTTP(S) or YouTube URL to this host, or send it straight to your Dropbox.</p>
</div>

<div id="dropbox-status" class="alert d-none" role="alert">
    <div id="dropbox-message"></div>
    <div id="dropbox-progress" class="progress mt-2 d-none">
        <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width:100%"></div>
    </div>
</div>

<?= $resultHtml ?>

<?php if (!$dropboxConnected): ?>
    <div class="status-callout">
        Dropbox is not connected. You can still save to disk.
        <a href="/user/">Connect Dropbox</a>
    </div>
<?php endif; ?>

<div class="panel">
    <h2 class="panel-title">New download</h2>
    <form action="" method="post" id="download-form">
        <?= Csrf::field() ?>
        <div class="mb-3">
            <label for="DLURL" class="form-label">URL</label>
            <input name="DLURL" type="url" class="form-control" id="DLURL" oninput="getFileNameFromUrl(this.value)" placeholder="https://..." required>
        </div>
        <div class="mb-3 d-none" id="youtube-options">
            <label for="yt_format" class="form-label">YouTube Format</label>
            <select class="form-select" name="yt_format" id="yt_format">
                <option value="best" selected>Best</option>
                <option value="4320">4320p (8k)</option>
                <option value="2160">2160p (4K)</option>
                <option value="1440">1440p (2k)</option>
                <option value="1080">1080p (HD)</option>
                <option value="720">720p (HD)</option>
                <option value="480">480p (SD)</option>
                <option value="360">360p (SD)</option>
                <option value="240">240p (SD)</option>
            </select>
        </div>
        <div class="mb-3">
            <label for="DLFILENAME" class="form-label">Filename</label>
            <input name="DLFILENAME" type="text" class="form-control mono" id="DLFILENAME" placeholder="Auto generated, can be changed">
        </div>

        <?php if ($dropboxConnected): ?>
            <div class="border rounded p-3 mb-3 bg-white">
                <div class="mb-3">
                    <label for="dropbox_folder" class="form-label">Dropbox folder</label>
                    <input name="dropbox_folder" type="text" class="form-control mono" id="dropbox_folder"
                           value="<?= e($prefs['folder']) ?>" placeholder="Tools Center">
                    <div class="form-text">Relative to your Dropbox root. Leave blank for root.</div>
                </div>
                <div class="mb-3">
                    <label for="dropbox_conflict" class="form-label">If file already exists</label>
                    <select class="form-select" name="dropbox_conflict" id="dropbox_conflict">
                        <option value="rename" <?= $prefs['conflict'] === 'rename' ? 'selected' : '' ?>>Rename (add number)</option>
                        <option value="overwrite" <?= $prefs['conflict'] === 'overwrite' ? 'selected' : '' ?>>Overwrite</option>
                        <option value="skip" <?= $prefs['conflict'] === 'skip' ? 'selected' : '' ?>>Skip</option>
                    </select>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" value="1" name="delete_after" id="delete_after">
                    <label class="form-check-label" for="delete_after">
                        Delete from host after Dropbox succeeds
                    </label>
                    <div class="form-text">Applies when a host copy exists (YouTube, Disk + Dropbox). Ignored for direct URL → Dropbox.</div>
                </div>
            </div>
        <?php endif; ?>

        <div class="d-flex flex-wrap gap-2">
            <button class="<?= e($btnClass('save_to_disk')) ?>" name="behavior" type="submit" value="save_to_disk">Save To Disk</button>
            <button class="<?= e($btnClass('save_to_dropbox')) ?>" name="behavior" type="submit" value="save_to_dropbox" <?= $dropboxConnected ? '' : 'disabled' ?>>Save To Dropbox</button>
            <button class="<?= e($btnClass('save_to_both')) ?>" name="behavior" type="submit" value="save_to_both" <?= $dropboxConnected ? '' : 'disabled' ?>>Save To Disk + Dropbox</button>
        </div>
    </form>
</div>

<?php
include dirname(__DIR__) . '/templates/partials/dropbox_jobs.php';
include dirname(__DIR__) . '/templates/partials/dropbox_monitor_js.php';
?>
<script>
function getFileNameFromUrl(url) {
    if (!url) return;
    const isYoutube = url.match(/^(?:http(?:s)?:\/\/)?(?:www\.)?(?:m\.)?(?:youtu\.be\/|youtube\.com\/(?:(?:watch)?\?(?:.*&)?v(?:i)?=|(?:embed|v|vi|user|shorts)\/))([^\?&\"'>]+)/);
    document.getElementById('youtube-options').classList.toggle('d-none', !(isYoutube && isYoutube[1]));
    const hashIndex = url.indexOf('#');
    url = hashIndex !== -1 ? url.substring(0, hashIndex) : url;
    document.getElementById('DLFILENAME').value = (url.split('/').pop() || '').replace(/[\?].*$/g, '');
}
</script>
<?php
View::render('Download', ob_get_clean(), $user);
