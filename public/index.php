<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Auth;
use App\Cloud\CloudJobRepository;
use App\Cloud\CloudSendService;
use App\Csrf;
use App\Download\DownloadRegistry;
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

$cloudJob = null;
$resultHtml = '';
$downloads = DownloadRegistry::default();

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

    $needsDisk = $behavior === 'save_to_disk'
        || $behavior === 'save_to_both'
        || ($behavior === 'save_to_cloud' && $source->requiresLocalFile());

    if ($needsDisk) {
        $downloadResult = $downloads->download($url, [
            'filename' => $filename !== '' ? $filename : null,
            'yt_format' => $ytFormat,
        ]);

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

        if ($sent['success']) {
            if (($sent['status'] ?? '') === 'skipped') {
                $resultHtml .= '<div class="alert alert-warning">'
                    . e($sent['message'] ?? 'Skipped — file already exists in cloud.')
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
            $resultHtml .= '<div class="alert alert-danger">' . e($sent['message'] ?? 'Cloud upload failed')
                . (isset($sent['error']) ? ' — ' . e((string) $sent['error']) : '')
                . '</div>';
        }
    }
}

$cloudJobs = (new CloudJobRepository())->recentForUser((int) $user['id']);

$btnClass = static function (string $action) use ($preferred): string {
    return $action === $preferred ? 'btn btn-primary' : 'btn btn-outline-primary';
};

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

        <?php if ($cloudConnected): ?>
            <div class="border rounded p-3 mb-3 bg-white">
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
                    <div class="form-text">Applies when a host copy exists (YouTube, Disk + Cloud). Ignored for direct URL → cloud.</div>
                </div>
            </div>
        <?php endif; ?>

        <div class="d-flex flex-wrap gap-2">
            <button class="<?= e($btnClass('save_to_disk')) ?>" name="behavior" type="submit" value="save_to_disk">Save To Disk</button>
            <button class="<?= e($btnClass('save_to_cloud')) ?>" name="behavior" type="submit" value="save_to_cloud" <?= $cloudConnected ? '' : 'disabled' ?>>Save To Cloud</button>
            <button class="<?= e($btnClass('save_to_both')) ?>" name="behavior" type="submit" value="save_to_both" <?= $cloudConnected ? '' : 'disabled' ?>>Save To Disk + Cloud</button>
        </div>
    </form>
</div>

<?php
include dirname(__DIR__) . '/templates/partials/cloud_jobs.php';
include dirname(__DIR__) . '/templates/partials/cloud_monitor_js.php';
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
