<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Auth;
use App\Cloud\CloudSendService;
use App\Csrf;
use App\Download\DownloadRegistry;
use App\DownloadHistoryRepository;
use App\FileRepository;
use App\ShareService;
use App\UserRepository;
use App\View;

$user = Auth::requireLogin();
$users = new UserRepository();
$user = $users->findById((int) $user['id']) ?? $user;
$cloud = CloudSendService::create();
$cloudConnected = $cloud->registry()->anyConnected($user);
$prefs = UserRepository::cloudPrefs($user);
$preferred = $prefs['behavior'];
if (!$cloudConnected && in_array($preferred, ['save_to_cloud', 'save_to_both'], true)) {
    $preferred = 'save_to_disk';
}

$cloudJob = null;
$resultHtml = '';
$downloads = DownloadRegistry::default();
$history = new DownloadHistoryRepository();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $rawBehavior = (string) ($_POST['behavior'] ?? '');
    if (!in_array($rawBehavior, ['save_to_disk', 'save_to_cloud', 'save_to_dropbox', 'save_to_both'], true)) {
        flash('error', 'Invalid action.');
        redirect('/');
    }
    $behavior = UserRepository::normalizeBehavior($rawBehavior);

    $url = trim((string) ($_POST['DLURL'] ?? ''));
    $filename = trim((string) ($_POST['DLFILENAME'] ?? ''));
    $ytFormat = (string) ($_POST['yt_format'] ?? 'best');
    $folder = UserRepository::normalizeFolder((string) ($_POST['cloud_folder'] ?? $_POST['dropbox_folder'] ?? $prefs['folder']));
    $conflict = UserRepository::normalizeConflict((string) ($_POST['cloud_conflict'] ?? $_POST['dropbox_conflict'] ?? $prefs['conflict']));
    $deleteAfter = isset($_POST['delete_after']) && (string) $_POST['delete_after'] === '1';

    $files = new FileRepository();
    $shares = new ShareService();

    if ($url === '') {
        flash('error', 'URL is required.');
        redirect('/');
    }

    $source = $downloads->resolve($url);
    if ($source === null) {
        flash('error', 'No download source supports this URL.');
        redirect('/');
    }

    $needsCloud = $behavior === 'save_to_cloud' || $behavior === 'save_to_both';
    if ($needsCloud && !$cloudConnected) {
        flash('error', 'Connect a cloud account first (Account page).');
        redirect('/user/');
    }

    $downloadResult = null;
    $fileId = null;
    $cloudJobId = null;
    $historyStatus = 'success';
    $historyError = null;
    $resultFilename = $filename !== '' ? $filename : null;

    $recordHistory = static function () use (
        $history,
        $user,
        $url,
        $filename,
        &$resultFilename,
        $behavior,
        &$historyStatus,
        &$historyError,
        &$fileId,
        &$cloudJobId
    ): void {
        $history->record([
            'user_id' => (int) $user['id'],
            'source_url' => $url,
            'requested_filename' => $filename !== '' ? $filename : null,
            'result_filename' => $resultFilename,
            'behavior' => $behavior,
            'status' => $historyStatus,
            'error' => $historyError,
            'file_id' => $fileId,
            'cloud_job_id' => $cloudJobId,
        ]);
    };

    $needsDisk = $behavior === 'save_to_disk'
        || $behavior === 'save_to_both'
        || ($behavior === 'save_to_cloud' && $source->requiresLocalFile());

    if ($needsDisk) {
        $downloadResult = $downloads->download($url, [
            'filename' => $filename !== '' ? $filename : null,
            'yt_format' => $ytFormat,
        ]);

        if (!$downloadResult['ok']) {
            $historyStatus = 'failed';
            $historyError = $downloadResult['error'] ?? 'Download failed.';
            $recordHistory();
            flash('error', $historyError);
            redirect('/');
        }

        $resultFilename = $downloadResult['original_name'] ?? $resultFilename;
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
                . '<a href="/files.php">My Files</a> · '
                . '<a href="/history.php">History</a>'
                . '</div>';
        }
    }

    if ($needsCloud) {
        if ($source->requiresLocalFile() || $behavior === 'save_to_both') {
            $token = $shares->enable((int) $fileId, (int) $user['id']);
            $fetchUrl = ShareService::publicUrl($token);
            $cloudFilename = $downloadResult['original_name'] ?? 'video.mp4';
            $localFileId = (int) $fileId;
        } else {
            $fetchUrl = $url;
            $cloudFilename = $filename !== '' ? $filename : null;
            $localFileId = null;
            $deleteAfter = false;
        }

        $sent = $cloud->startForUser(
            $user,
            $fetchUrl,
            $cloudFilename,
            $folder,
            $conflict,
            $deleteAfter,
            $localFileId,
            'dropbox'
        );

        $cloudJobId = isset($sent['job_id']) ? (int) $sent['job_id'] : null;
        if ($resultFilename === null && isset($sent['filename'])) {
            $resultFilename = (string) $sent['filename'];
        } elseif ($resultFilename === null && $cloudFilename !== null) {
            $resultFilename = $cloudFilename;
        }

        if ($sent['success']) {
            if (($sent['status'] ?? '') === 'skipped') {
                $resultHtml .= '<div class="alert alert-warning">'
                    . e($sent['message'] ?? 'Skipped — file already exists in cloud.')
                    . ' <a href="/history.php">View history</a>'
                    . '</div>';
            } else {
                $cloudJob = [
                    'asyncJobId' => $sent['async_job_id'] ?? null,
                    'filename' => $sent['filename'] ?? ($cloudFilename ?? 'file'),
                    'jobId' => $sent['job_id'] ?? null,
                    'provider' => 'dropbox',
                ];
            }
        } else {
            $historyStatus = 'failed';
            $historyError = ($sent['message'] ?? 'Cloud upload failed')
                . (isset($sent['error']) ? ' — ' . (string) $sent['error'] : '');
            $resultHtml .= '<div class="alert alert-danger">' . e($historyError)
                . ' <a href="/history.php">View history</a>'
                . '</div>';
        }
    }

    $recordHistory();
}

ob_start();
?>
<div class="page-header">
    <p class="page-lede">Paste a URL to pull onto this host, or send it to your connected cloud.</p>
</div>

<div id="cloud-status" class="alert d-none" role="alert">
    <div id="cloud-message"></div>
    <div id="cloud-progress" class="progress mt-2 d-none">
        <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width:100%"></div>
    </div>
</div>

<?= $resultHtml ?>

<?php if (!$cloudConnected): ?>
    <div class="status-callout">
        No cloud account connected. You can still save to disk.
        <a href="/user/">Connect Dropbox</a>
    </div>
<?php endif; ?>

<div class="panel">
    <h2 class="panel-title">New download</h2>
    <form action="" method="post" id="download-form">
        <?= Csrf::field() ?>
        <div class="mb-3">
            <label for="DLURL" class="form-label">URL</label>
            <input name="DLURL" type="url" class="form-control" id="DLURL" placeholder="https://..." required>
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

        <fieldset class="mb-3">
            <legend class="form-label mb-2">Save to</legend>
            <div class="d-flex flex-column gap-2">
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="behavior" id="behavior_disk"
                           value="save_to_disk" <?= $preferred === 'save_to_disk' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="behavior_disk">Disk</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="behavior" id="behavior_cloud"
                           value="save_to_cloud"
                           <?= $preferred === 'save_to_cloud' ? 'checked' : '' ?>
                           <?= $cloudConnected ? '' : 'disabled' ?>>
                    <label class="form-check-label" for="behavior_cloud">Cloud</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="behavior" id="behavior_both"
                           value="save_to_both"
                           <?= $preferred === 'save_to_both' ? 'checked' : '' ?>
                           <?= $cloudConnected ? '' : 'disabled' ?>>
                    <label class="form-check-label" for="behavior_both">Disk + Cloud</label>
                </div>
            </div>
        </fieldset>

        <div class="mb-3">
            <label for="DLFILENAME" class="form-label">Filename</label>
            <input name="DLFILENAME" type="text" class="form-control mono" id="DLFILENAME" placeholder="Auto from URL, can be changed">
            <div class="form-text d-none" id="filename-youtube-hint">YouTube sets the name from the video title.</div>
        </div>

        <?php if ($cloudConnected): ?>
            <div class="border rounded p-3 mb-3 bg-white d-none" id="cloud-options">
                <div class="mb-3">
                    <label for="cloud_folder" class="form-label">Cloud folder</label>
                    <input name="cloud_folder" type="text" class="form-control mono" id="cloud_folder"
                           value="<?= e($prefs['folder']) ?>" placeholder="Tools Center">
                    <div class="form-text">Relative to your cloud root. Leave blank for root.</div>
                </div>
                <div class="mb-3">
                    <label for="cloud_conflict" class="form-label">If file already exists</label>
                    <select class="form-select" name="cloud_conflict" id="cloud_conflict">
                        <option value="rename" <?= $prefs['conflict'] === 'rename' ? 'selected' : '' ?>>Rename (add number)</option>
                        <option value="overwrite" <?= $prefs['conflict'] === 'overwrite' ? 'selected' : '' ?>>Overwrite</option>
                        <option value="skip" <?= $prefs['conflict'] === 'skip' ? 'selected' : '' ?>>Skip</option>
                    </select>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" value="1" name="delete_after" id="delete_after">
                    <label class="form-check-label" for="delete_after">
                        Delete from host after cloud succeeds
                    </label>
                    <div class="form-text">Only when this download also keeps a host copy (YouTube → cloud, or Disk + Cloud).</div>
                </div>
            </div>
        <?php endif; ?>

        <button class="btn btn-primary" type="submit">Start download</button>
    </form>
</div>

<?php include dirname(__DIR__) . '/templates/partials/cloud_monitor_js.php'; ?>
<script>
(function () {
    const urlInput = document.getElementById('DLURL');
    const filenameInput = document.getElementById('DLFILENAME');
    const youtubeOptions = document.getElementById('youtube-options');
    const youtubeHint = document.getElementById('filename-youtube-hint');
    const cloudOptions = document.getElementById('cloud-options');
    let filenameDirty = false;

    const youtubeRe = /^(?:http(?:s)?:\/\/)?(?:www\.)?(?:m\.)?(?:youtu\.be\/|youtube\.com\/(?:(?:watch)?\?(?:.*&)?v(?:i)?=|(?:embed|v|vi|user|shorts)\/))([^\?&\"'>]+)/;

    function isYoutubeUrl(url) {
        const match = url.match(youtubeRe);
        return !!(match && match[1]);
    }

    function filenameFromUrl(url) {
        const hashIndex = url.indexOf('#');
        if (hashIndex !== -1) {
            url = url.substring(0, hashIndex);
        }
        return (url.split('/').pop() || '').replace(/[\?].*$/g, '');
    }

    function syncFilenameForUrl(url) {
        const yt = isYoutubeUrl(url);
        youtubeOptions.classList.toggle('d-none', !yt);
        youtubeHint.classList.toggle('d-none', !yt);
        filenameInput.disabled = yt;
        if (yt) {
            filenameInput.value = '';
            filenameDirty = false;
            return;
        }
        if (!filenameDirty) {
            filenameInput.value = filenameFromUrl(url);
        }
    }

    function syncCloudOptions() {
        if (!cloudOptions) return;
        const selected = document.querySelector('input[name="behavior"]:checked');
        const value = selected ? selected.value : 'save_to_disk';
        const show = value === 'save_to_cloud' || value === 'save_to_both';
        cloudOptions.classList.toggle('d-none', !show);
    }

    filenameInput.addEventListener('input', function () {
        if (!filenameInput.disabled) {
            filenameDirty = true;
        }
    });

    urlInput.addEventListener('input', function () {
        syncFilenameForUrl(urlInput.value);
    });

    document.querySelectorAll('input[name="behavior"]').forEach(function (el) {
        el.addEventListener('change', syncCloudOptions);
    });

    syncFilenameForUrl(urlInput.value);
    syncCloudOptions();
})();
</script>
<?php
View::render('Download', ob_get_clean(), $user);
