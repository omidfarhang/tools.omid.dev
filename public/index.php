<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Auth;
use App\Cloud\CloudSendService;
use App\Csrf;
use App\Download\DownloadRegistry;
use App\DownloadJobDispatcher;
use App\DownloadJobRepository;
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

$downloads = DownloadRegistry::default();
$jobRepo = new DownloadJobRepository();

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

    $jobId = $jobRepo->create([
        'user_id' => (int) $user['id'],
        'source_url' => $url,
        'requested_filename' => $filename !== '' ? $filename : null,
        'behavior' => $behavior,
        'yt_format' => $ytFormat,
        'cloud_folder' => $needsCloud ? $folder : null,
        'cloud_conflict' => $needsCloud ? $conflict : null,
        'delete_after' => $needsCloud && $deleteAfter,
    ]);

    flash('success', 'Download queued.');
    session_write_close();
    DownloadJobDispatcher::dispatch($jobId);
    redirect('/');
}

DownloadJobDispatcher::dispatchPending(5);
$homeJobs = $jobRepo->homeForUser((int) $user['id'], 8);
$activeJobCount = count($jobRepo->activeForUser((int) $user['id']));

$destinationLabel = static function (string $behavior): string {
    return match ($behavior) {
        'save_to_cloud', 'save_to_dropbox' => 'Cloud',
        'save_to_both' => 'Disk + Cloud',
        default => 'Disk',
    };
};

$statusBadge = static function (string $status): string {
    return match ($status) {
        'complete' => 'success',
        'failed' => 'danger',
        'in_progress' => 'info',
        'pending' => 'secondary',
        default => 'secondary',
    };
};

$truncateUrl = static function (string $url, int $max = 56): string {
    if (strlen($url) <= $max) {
        return $url;
    }

    return substr($url, 0, $max - 1) . '…';
};

ob_start();
?>
<div class="page-header">
    <p class="page-lede">Paste a URL to pull onto this host, or send it to your connected cloud.</p>
</div>

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

<div class="panel panel-flush mt-3 <?= $homeJobs ? '' : 'd-none' ?>" id="active-jobs-panel">
    <div class="panel-head d-flex justify-content-between align-items-center">
        <h2 class="panel-title mb-0">Jobs</h2>
        <span class="text-muted small" id="active-jobs-count">
            <?php if ($activeJobCount > 0): ?>
                <?= $activeJobCount ?> active
            <?php else: ?>
                <?= count($homeJobs) ?> recent
            <?php endif; ?>
            · <a href="/history.php">History</a>
        </span>
    </div>
    <div class="table-responsive">
        <table class="table table-striped align-middle mb-0">
            <thead>
            <tr>
                <th>Source</th>
                <th>Name</th>
                <th>Destination</th>
                <th>Status</th>
            </tr>
            </thead>
            <tbody id="active-jobs-body">
            <?php foreach ($homeJobs as $job): ?>
                <?php
                $name = (string) ($job['result_filename'] ?? $job['requested_filename'] ?? '');
                $status = (string) ($job['status'] ?? 'pending');
                $cloudStatus = (string) ($job['cloud_status'] ?? '');
                $isActive = in_array($status, ['pending', 'in_progress'], true)
                    || ($status === 'complete' && in_array($cloudStatus, ['pending', 'in_progress'], true));
                $displayStatus = $status;
                if ($status === 'complete' && in_array($cloudStatus, ['pending', 'in_progress'], true)) {
                    $displayStatus = 'cloud upload';
                }
                $badgeStatus = $displayStatus === 'cloud upload' ? 'in_progress' : $status;
                $fileId = isset($job['file_id']) && $job['file_id'] !== null ? (int) $job['file_id'] : null;
                ?>
                <tr data-job-id="<?= (int) $job['id'] ?>" class="<?= $isActive ? '' : 'text-muted' ?>">
                    <td class="mono small" title="<?= e((string) $job['source_url']) ?>"><?= e($truncateUrl((string) $job['source_url'])) ?></td>
                    <td class="mono">
                        <?php if ($fileId !== null && $status === 'complete'): ?>
                            <a class="filename" href="/download.php?id=<?= $fileId ?>"><?= e($name !== '' ? $name : 'file') ?></a>
                        <?php else: ?>
                            <?= e($name !== '' ? $name : '—') ?>
                        <?php endif; ?>
                    </td>
                    <td><?= e($destinationLabel((string) $job['behavior'])) ?></td>
                    <td>
                        <span class="badge text-bg-<?= e($statusBadge($badgeStatus)) ?> job-status-badge">
                            <?= e($displayStatus) ?>
                        </span>
                        <?php if (!empty($job['error'])): ?>
                            <div class="text-muted small mt-1 job-error"><?= e((string) $job['error']) ?></div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

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

    const panel = document.getElementById('active-jobs-panel');
    const body = document.getElementById('active-jobs-body');
    const countEl = document.getElementById('active-jobs-count');
    const csrf = <?= json_encode(Csrf::token()) ?>;

    function destinationLabel(behavior) {
        if (behavior === 'save_to_cloud' || behavior === 'save_to_dropbox') return 'Cloud';
        if (behavior === 'save_to_both') return 'Disk + Cloud';
        return 'Disk';
    }

    function statusBadgeClass(status) {
        if (status === 'complete') return 'badge text-bg-success job-status-badge';
        if (status === 'failed') return 'badge text-bg-danger job-status-badge';
        if (status === 'in_progress' || status === 'cloud_upload') return 'badge text-bg-info job-status-badge';
        return 'badge text-bg-secondary job-status-badge';
    }

    function truncateUrl(url, max) {
        max = max || 56;
        if (!url || url.length <= max) return url || '';
        return url.substring(0, max - 1) + '…';
    }

    function displayStatus(job) {
        if (job.status === 'complete' && (job.cloud_status === 'pending' || job.cloud_status === 'in_progress')) {
            return 'cloud upload';
        }
        return job.status || 'pending';
    }

    function renderJobs(jobs, activeCount) {
        if (!panel || !body || !countEl) return;
        if (!jobs.length) {
            panel.classList.add('d-none');
            body.innerHTML = '';
            countEl.innerHTML = '0 recent · <a href="/history.php">History</a>';
            return;
        }
        panel.classList.remove('d-none');
        const countLabel = activeCount > 0 ? (activeCount + ' active') : (jobs.length + ' recent');
        countEl.innerHTML = countLabel + ' · <a href="/history.php">History</a>';
        body.innerHTML = jobs.map(function (job) {
            const name = job.result_filename || job.requested_filename || '—';
            const label = displayStatus(job);
            const badgeStatus = label === 'cloud upload' ? 'cloud_upload' : job.status;
            const rowClass = job.active ? '' : ' class="text-muted"';
            let nameHtml = escapeHtml(name);
            if (job.file_id && job.status === 'complete') {
                nameHtml = '<a class="filename" href="/download.php?id=' + job.file_id + '">' + escapeHtml(name === '—' ? 'file' : name) + '</a>';
            }
            return '<tr data-job-id="' + job.id + '"' + rowClass + '>'
                + '<td class="mono small" title="' + escapeAttr(job.source_url) + '">' + escapeHtml(truncateUrl(job.source_url)) + '</td>'
                + '<td class="mono">' + nameHtml + '</td>'
                + '<td>' + escapeHtml(destinationLabel(job.behavior)) + '</td>'
                + '<td><span class="' + statusBadgeClass(badgeStatus) + '">' + escapeHtml(label) + '</span>'
                + (job.error ? '<div class="text-muted small mt-1">' + escapeHtml(job.error) + '</div>' : '')
                + '</td></tr>';
        }).join('');
    }

    function escapeHtml(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function escapeAttr(str) {
        return escapeHtml(str).replace(/'/g, '&#39;');
    }

    function pollActiveJobs() {
        const bodyParams = new URLSearchParams({ _csrf: csrf });
        fetch('/api/download_jobs_status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: bodyParams.toString(),
            credentials: 'same-origin',
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data && data.success && Array.isArray(data.jobs)) {
                    const activeCount = typeof data.active_count === 'number' ? data.active_count : 0;
                    renderJobs(data.jobs, activeCount);
                    if (activeCount > 0) {
                        setTimeout(pollActiveJobs, 2000);
                    }
                }
            })
            .catch(function () {
                setTimeout(pollActiveJobs, 4000);
            });
    }

    if (<?= $activeJobCount > 0 ? 'true' : 'false' ?>) {
        setTimeout(pollActiveJobs, 2000);
    } else {
        // Still poke once in case a job was queued just before navigation.
        setTimeout(pollActiveJobs, 1500);
    }
})();
</script>
<?php
View::render('Download', ob_get_clean(), $user);
