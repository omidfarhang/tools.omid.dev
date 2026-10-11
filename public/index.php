<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Auth;
use App\Csrf;
use App\DownloadService;
use App\DropboxClient;
use App\FileRepository;
use App\ShareService;
use App\UserRepository;
use App\View;

$user = Auth::requireLogin();
$users = new UserRepository();
$user = $users->findById((int) $user['id']) ?? $user;
$dropbox = new DropboxClient($user);
$dropboxConnected = $dropbox->isConnected();

$dropboxJob = null;
$resultHtml = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $behavior = (string) ($_POST['behavior'] ?? '');
    $url = trim((string) ($_POST['DLURL'] ?? ''));
    $filename = trim((string) ($_POST['DLFILENAME'] ?? ''));
    $ytFormat = (string) ($_POST['yt_format'] ?? 'best');

    $downloader = new DownloadService();
    $files = new FileRepository();
    $shares = new ShareService();

    if ($url === '') {
        flash('error', 'URL is required.');
        redirect('/');
    }

    $ytId = is_youtube($url);
    $downloadResult = null;

    if ($behavior === 'save_to_disk' || ($behavior === 'save_to_dropbox' && $ytId !== false)) {
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

        if ($behavior === 'save_to_disk') {
            $resultHtml = '<div class="alert alert-success">'
                . 'Successfully downloaded <strong>' . e($downloadResult['original_name']) . '</strong> '
                . '(' . e(human_filesize((int) $downloadResult['size'])) . '). '
                . '<a href="/download.php?id=' . (int) $fileId . '">Download</a> · '
                . '<a href="/files.php">My Files</a>'
                . '</div>';
        }
    }

    if ($behavior === 'save_to_dropbox') {
        if (!$dropboxConnected) {
            flash('error', 'Connect your Dropbox account first (Account page).');
            redirect('/user/');
        }

        if ($ytId !== false) {
            // Dropbox must fetch a public URL — enable a share for the local file.
            $fileId = $fileId ?? 0;
            $token = $shares->enable($fileId, (int) $user['id']);
            $fetchUrl = ShareService::publicUrl($token);
            $dbFilename = $downloadResult['original_name'] ?? 'video.mp4';
        } else {
            $fetchUrl = $url;
            $dbFilename = $filename !== '' ? $filename : null;
        }

        $sent = $dropbox->saveUrl($fetchUrl, $dbFilename);
        if ($sent['success']) {
            $dropboxJob = [
                'asyncJobId' => $sent['async_job_id'],
                'filename' => $sent['filename'],
            ];
        } else {
            $resultHtml .= '<div class="alert alert-danger">' . e($sent['message'] ?? 'Dropbox failed')
                . (isset($sent['error']) ? ' — ' . e((string) $sent['error']) : '')
                . '</div>';
        }
    }
}

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
    <form action="" method="post">
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
        <div class="d-flex flex-wrap gap-2">
            <button class="btn btn-primary" name="behavior" type="submit" value="save_to_disk">Save To Disk</button>
            <button class="btn btn-outline-primary" name="behavior" type="submit" value="save_to_dropbox" <?= $dropboxConnected ? '' : 'disabled' ?>>Save To Dropbox</button>
        </div>
    </form>
</div>

<script>
window.dropboxJobData = <?= json_encode($dropboxJob, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

function getFileNameFromUrl(url) {
    if (!url) return;
    const isYoutube = url.match(/^(?:http(?:s)?:\/\/)?(?:www\.)?(?:m\.)?(?:youtu\.be\/|youtube\.com\/(?:(?:watch)?\?(?:.*&)?v(?:i)?=|(?:embed|v|vi|user|shorts)\/))([^\?&\"'>]+)/);
    document.getElementById('youtube-options').classList.toggle('d-none', !(isYoutube && isYoutube[1]));
    const hashIndex = url.indexOf('#');
    url = hashIndex !== -1 ? url.substring(0, hashIndex) : url;
    document.getElementById('DLFILENAME').value = (url.split('/').pop() || '').replace(/[\?].*$/g, '');
}

function startDropboxMonitoring(asyncJobId, filename) {
    const statusDiv = document.getElementById('dropbox-status');
    const messageDiv = document.getElementById('dropbox-message');
    const progressDiv = document.getElementById('dropbox-progress');
    statusDiv.className = 'alert alert-info';
    statusDiv.classList.remove('d-none');
    messageDiv.textContent = 'Uploading "' + filename + '" to Dropbox...';
    progressDiv.classList.remove('d-none');
    checkDropboxStatus(asyncJobId, filename);
}

function checkDropboxStatus(asyncJobId, filename) {
    $.ajax({
        url: '/api/check_dropbox_status.php',
        method: 'POST',
        data: { async_job_id: asyncJobId, _csrf: <?= json_encode(Csrf::token()) ?> },
        dataType: 'json',
        success: function (response) {
            const statusDiv = document.getElementById('dropbox-status');
            const messageDiv = document.getElementById('dropbox-message');
            const progressDiv = document.getElementById('dropbox-progress');
            if (response.success) {
                if (response.status === 'in_progress') {
                    messageDiv.textContent = 'Upload in progress for "' + filename + '"...';
                    setTimeout(function () { checkDropboxStatus(asyncJobId, filename); }, 2000);
                } else if (response.status === 'complete') {
                    statusDiv.className = 'alert alert-success';
                    progressDiv.classList.add('d-none');
                    const path = (response.data && (response.data.path_display || response.data.path_lower)) || 'N/A';
                    messageDiv.textContent = 'Uploaded "' + filename + '" to Dropbox. Path: ' + path;
                } else if (response.status === 'failed') {
                    statusDiv.className = 'alert alert-danger';
                    progressDiv.classList.add('d-none');
                    messageDiv.textContent = 'Failed to upload "' + filename + '".';
                } else {
                    setTimeout(function () { checkDropboxStatus(asyncJobId, filename); }, 2000);
                }
            } else {
                statusDiv.className = 'alert alert-danger';
                progressDiv.classList.add('d-none');
                messageDiv.textContent = 'Error: ' + (response.error || 'Unknown error');
            }
        },
        error: function () {
            const statusDiv = document.getElementById('dropbox-status');
            statusDiv.className = 'alert alert-danger';
            statusDiv.classList.remove('d-none');
            document.getElementById('dropbox-progress').classList.add('d-none');
            document.getElementById('dropbox-message').textContent = 'Error checking upload status.';
        }
    });
}

$(function () {
    if (window.dropboxJobData) {
        startDropboxMonitoring(window.dropboxJobData.asyncJobId, window.dropboxJobData.filename);
    }
});
</script>
<?php
View::render('Download', ob_get_clean(), $user);
