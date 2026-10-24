<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Auth;
use App\Cloud\CloudSendService;
use App\Config;
use App\Csrf;
use App\Download\DownloadRegistry;
use App\Download\YtDlpKnownHosts;
use App\Download\YtDlpRunner;
use App\DownloadJobDispatcher;
use App\DownloadJobProcessor;
use App\DownloadJobRepository;
use App\MimeType;
use App\UserRepository;
use App\View;

$user = Auth::requireLogin();
$users = new UserRepository();
$user = $users->findById((int) $user['id']) ?? $user;
$cloud = CloudSendService::create();
$cloudRegistry = $cloud->registry();
$cloudConnected = $cloudRegistry->anyConnected($user);
$configuredProviders = [];
foreach ($cloudRegistry->all() as $provider) {
    if ($provider->isConfigured()) {
        $configuredProviders[] = $provider;
    }
}
$prefs = UserRepository::cloudPrefs($user);
$preferredDestinations = $prefs['destinations'];
$connectedProviderIds = [];
foreach ($configuredProviders as $provider) {
    if ($provider->isConnected($user)) {
        $connectedProviderIds[] = $provider->id();
    }
}
$preferredDestinations = array_values(array_filter(
    $preferredDestinations,
    static function (string $id) use ($connectedProviderIds): bool {
        return $id === 'disk' || in_array($id, $connectedProviderIds, true);
    }
));
if ($preferredDestinations === []) {
    $preferredDestinations = ['disk'];
}

$downloads = DownloadRegistry::default();
$jobRepo = new DownloadJobRepository();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();

    $allowedProviderIds = [];
    foreach ($configuredProviders as $provider) {
        $allowedProviderIds[] = $provider->id();
    }
    $rawDest = $_POST['dest'] ?? [];
    if (!is_array($rawDest)) {
        $rawDest = [];
    }
    $destinations = UserRepository::normalizeDestinations($rawDest, array_merge(['disk'], $allowedProviderIds));
    if ($destinations === []) {
        flash('error', 'Choose at least one destination.');
        redirect('/');
    }

    $url = trim((string) ($_POST['DLURL'] ?? ''));
    $filename = trim((string) ($_POST['DLFILENAME'] ?? ''));
    $forceRequested = isset($_POST['force_ytdlp']) && (string) $_POST['force_ytdlp'] === '1';
    $rawFolders = $_POST['cloud_folder'] ?? $_POST['dropbox_folder'] ?? [];
    if (!is_array($rawFolders)) {
        // Legacy flat field from older clients.
        $rawFolders = ['dropbox' => (string) $rawFolders];
    }
    $rawConflicts = $_POST['cloud_conflict'] ?? $_POST['dropbox_conflict'] ?? [];
    if (!is_array($rawConflicts)) {
        $rawConflicts = ['dropbox' => (string) $rawConflicts];
    }
    $deleteAfter = isset($_POST['delete_after']) && (string) $_POST['delete_after'] === '1';

    if ($url === '') {
        flash('error', 'URL is required.');
        redirect('/');
    }

    $autoSource = $downloads->resolve($url);
    $isCurated = DownloadRegistry::isCurated($autoSource);
    $forceYtdlp = $forceRequested && !$isCurated;
    $source = $downloads->resolve($url, ['force_ytdlp' => $forceYtdlp]);
    if ($source === null) {
        flash('error', 'No download source supports this URL.');
        redirect('/');
    }

    $ytFormat = 'best';
    $ytAudioOnly = false;
    $ytSubs = null;
    if ($isCurated && $autoSource !== null) {
        if ($autoSource->mediaKind() === 'audio') {
            $rawAudio = (string) ($_POST['audio_format'] ?? 'best');
            $ytFormat = in_array($rawAudio, YtDlpRunner::AUDIO_FORMATS, true) ? $rawAudio : 'best';
            $ytAudioOnly = true;
        } else {
            $ytFormat = match ($autoSource->id()) {
                'youtube' => (string) ($_POST['yt_format'] ?? 'best'),
                'twitter' => (string) ($_POST['twitter_format'] ?? 'best'),
                'instagram' => (string) ($_POST['instagram_format'] ?? 'best'),
                'tiktok' => (string) ($_POST['tiktok_format'] ?? 'best'),
                'vimeo' => (string) ($_POST['vimeo_format'] ?? 'best'),
                'reddit' => (string) ($_POST['reddit_format'] ?? 'best'),
                default => 'best',
            };
            $ytAudioOnly = isset($_POST['yt_audio_only']) && (string) $_POST['yt_audio_only'] === '1';
            $rawSubs = (string) ($_POST['yt_subs'] ?? 'none');
            if (in_array($rawSubs, ['en', 'all'], true)) {
                $ytSubs = $rawSubs;
            }
        }
    } elseif ($forceYtdlp) {
        $ytFormat = (string) ($_POST['ytdlp_format'] ?? 'best');
    }

    foreach ($destinations as $destId) {
        if ($destId === 'disk') {
            continue;
        }
        $provider = $cloudRegistry->get($destId);
        if ($provider === null || !$provider->isConfigured() || !$provider->isConnected($user)) {
            flash('error', 'Connect ' . ($provider?->label() ?? $destId) . ' first (Account → Cloud).');
            redirect('/user/cloud.php');
        }
    }

    $jobIds = [];
    foreach ($destinations as $destId) {
        if ($destId === 'disk') {
            $jobIds[] = $jobRepo->create([
                'user_id' => (int) $user['id'],
                'source_url' => $url,
                'requested_filename' => $filename !== '' ? $filename : null,
                'behavior' => 'save_to_disk',
                'yt_format' => $ytFormat,
                'yt_audio_only' => $ytAudioOnly,
                'yt_subs' => $ytSubs,
                'force_ytdlp' => $forceYtdlp,
            ]);
            continue;
        }

        $provider = $cloudRegistry->get($destId);
        if ($provider === null) {
            continue;
        }

        $providerPrefs = UserRepository::providerPrefs($user, $provider->id());
        $folder = UserRepository::normalizeFolder(
            (string) ($rawFolders[$provider->id()] ?? $providerPrefs['folder'])
        );
        $conflict = UserRepository::normalizeConflict(
            (string) ($rawConflicts[$provider->id()] ?? $providerPrefs['conflict'])
        );

        $jobIds[] = $jobRepo->create([
            'user_id' => (int) $user['id'],
            'source_url' => $url,
            'requested_filename' => $filename !== '' ? $filename : null,
            'behavior' => 'save_to_cloud',
            'yt_format' => $ytFormat,
            'yt_audio_only' => $ytAudioOnly,
            'yt_subs' => $ytSubs,
            'force_ytdlp' => $forceYtdlp,
            'cloud_folder' => $folder,
            'cloud_conflict' => $conflict,
            'cloud_provider' => $provider->id(),
            'delete_after' => $deleteAfter,
        ]);
    }

    if ($jobIds === []) {
        flash('error', 'Choose at least one destination.');
        redirect('/');
    }

    $count = count($jobIds);
    flash('success', $count === 1 ? 'Download queued.' : $count . ' downloads queued.');
    session_write_close();

    // Send the redirect first, then process in this request so the web server
    // does not kill a background CLI child before the job runs.
    $dest = rtrim((string) Config::get('app_base_url'), '/') . '/';
    header('Location: ' . $dest);
    header('Connection: close');
    header('Content-Length: 0');
    http_response_code(302);
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    @flush();
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
    }

    ignore_user_abort(true);
    @set_time_limit(0);
    $processor = new DownloadJobProcessor();
    foreach ($jobIds as $jobId) {
        try {
            $processor->process($jobId);
        } catch (\Throwable) {
            // Job stays pending/in_progress for Home/API retry.
        }
    }
    exit;
}

// Retry any leftover pending jobs after this page response.
DownloadJobDispatcher::dispatchPending(3);
$homeJobs = $jobRepo->homeForUser((int) $user['id'], 10);
$activeJobCount = 0;
foreach ($homeJobs as $job) {
    if (DownloadJobRepository::isActiveRow($job)) {
        $activeJobCount++;
    }
}

$providerLabel = static function (string $provider): string {
    return match ($provider) {
        'dropbox' => 'Dropbox',
        'google_drive' => 'Google Drive',
        default => $provider !== '' ? ucfirst(str_replace('_', ' ', $provider)) : 'Cloud',
    };
};

$destinationLabel = static function (string $behavior, ?string $provider = null) use ($providerLabel): string {
    $cloud = $provider !== null && $provider !== ''
        ? $providerLabel($provider)
        : 'Cloud';

    return match ($behavior) {
        'save_to_cloud', 'save_to_dropbox' => $cloud,
        'save_to_both' => 'Local + ' . $cloud,
        default => 'Local',
    };
};

$statusBadge = static function (string $status): string {
    return match ($status) {
        'complete', 'success' => 'success',
        'failed' => 'danger',
        'cancelled' => 'secondary',
        'skipped' => 'warning',
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

$failReasonButton = static function (string $error, string $status): string {
    if ($error === '' || !in_array($status, ['failed', 'skipped'], true)) {
        return '';
    }

    return '<button type="button" class="btn btn-link btn-sm text-danger p-0 align-baseline ms-1"'
        . ' data-bs-toggle="modal" data-bs-target="#jobs-fail-modal"'
        . ' data-fail-reason="' . e($error) . '">'
        . 'Reason</button>';
};

ob_start();
?>
<p class="text-secondary mb-3">Paste a URL to pull onto this host, or send it to your connected cloud.</p>

<?php if (!$cloudConnected): ?>
    <div class="alert alert-warning">
        No cloud account connected. You can still save to disk.
        <a href="/user/cloud.php">Connect a cloud account</a>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
    <form action="" method="post" id="download-form">
        <?= Csrf::field() ?>

        <section class="mb-4">
            <h2 class="subheader">Source</h2>
            <div class="mb-0">
                <label for="DLURL" class="form-label">URL</label>
                <input name="DLURL" type="url" class="form-control" id="DLURL" placeholder="https://..." required autocomplete="off" spellcheck="false">
            </div>

            <div class="source-options mt-3 d-none" id="youtube-options">
                <label for="yt_format" class="form-label">YouTube quality</label>
                <select class="form-select form-control-narrow" name="yt_format" id="yt_format">
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
            <div class="source-options mt-3 d-none" id="twitter-options">
                <label for="twitter_format" class="form-label">X / Twitter quality</label>
                <select class="form-select form-control-narrow" name="twitter_format" id="twitter_format">
                    <option value="best" selected>Best</option>
                    <option value="720">720p</option>
                    <option value="480">480p</option>
                </select>
            </div>
            <div class="source-options mt-3 d-none" id="instagram-options">
                <label for="instagram_format" class="form-label">Instagram quality</label>
                <select class="form-select form-control-narrow" name="instagram_format" id="instagram_format">
                    <option value="best" selected>Best</option>
                    <option value="720">720p</option>
                </select>
            </div>
            <div class="source-options mt-3 d-none" id="tiktok-options">
                <label for="tiktok_format" class="form-label">TikTok quality</label>
                <select class="form-select form-control-narrow" name="tiktok_format" id="tiktok_format">
                    <option value="best" selected>Best</option>
                    <option value="720">720p</option>
                </select>
            </div>
            <div class="source-options mt-3 d-none" id="vimeo-options">
                <label for="vimeo_format" class="form-label">Vimeo quality</label>
                <select class="form-select form-control-narrow" name="vimeo_format" id="vimeo_format">
                    <option value="best" selected>Best</option>
                    <option value="2160">2160p (4K)</option>
                    <option value="1080">1080p (HD)</option>
                    <option value="720">720p (HD)</option>
                    <option value="360">360p (SD)</option>
                </select>
            </div>
            <div class="source-options mt-3 d-none" id="reddit-options">
                <label for="reddit_format" class="form-label">Reddit quality</label>
                <select class="form-select form-control-narrow" name="reddit_format" id="reddit_format">
                    <option value="best" selected>Best</option>
                    <option value="1080">1080p</option>
                    <option value="720">720p</option>
                    <option value="480">480p</option>
                </select>
            </div>
            <div class="source-options mt-3 d-none" id="audio-options">
                <label for="audio_format" class="form-label">Audio format</label>
                <select class="form-select form-control-narrow" name="audio_format" id="audio_format">
                    <option value="best" selected>Original / best</option>
                    <option value="m4a">M4A</option>
                    <option value="mp3">MP3</option>
                    <option value="opus">Opus</option>
                    <option value="flac">FLAC</option>
                </select>
                <div class="form-text">Keep the original stream, or re-encode to a container.</div>
            </div>
            <div class="source-options mt-3 d-none" id="curated-extra-options">
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" value="1" name="yt_audio_only" id="yt_audio_only">
                    <label class="form-check-label" for="yt_audio_only">Audio only</label>
                </div>
                <div class="mb-0">
                    <label for="yt_subs" class="form-label">Subtitles</label>
                    <select class="form-select form-control-narrow" name="yt_subs" id="yt_subs">
                        <option value="none" selected>None</option>
                        <option value="en">English</option>
                        <option value="all">All available</option>
                    </select>
                </div>
            </div>
            <div class="source-options source-options-block mt-3 d-none" id="ytdlp-options">
                <div class="form-check mb-0">
                    <input type="hidden" name="force_ytdlp" value="0">
                    <input class="form-check-input" type="checkbox" value="1" name="force_ytdlp" id="force_ytdlp">
                    <label class="form-check-label" for="force_ytdlp">Use yt-dlp</label>
                    <div class="form-text">On by default for sites yt-dlp knows. Uncheck for a direct file download.</div>
                </div>
                <div class="source-options-nested d-none" id="ytdlp-format-wrap">
                    <label for="ytdlp_format" class="form-label">Quality</label>
                    <select class="form-select form-control-narrow" name="ytdlp_format" id="ytdlp_format">
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
            </div>
        </section>

        <section class="mb-4">
            <h2 class="subheader">Save to</h2>
            <fieldset class="choice-fieldset">
                <legend class="visually-hidden">Save destinations</legend>
                <div class="choice-grid">
                    <label class="choice-tile" for="dest_disk">
                        <input class="form-check-input" type="checkbox" name="dest[]" id="dest_disk"
                               value="disk" <?= in_array('disk', $preferredDestinations, true) ? 'checked' : '' ?>>
                        <span class="choice-tile-body">
                            <span class="choice-tile-title">Local</span>
                            <span class="choice-tile-desc">Keep on this host</span>
                        </span>
                    </label>
                    <?php foreach ($configuredProviders as $provider): ?>
                        <?php
                        $pid = $provider->id();
                        $pConnected = $provider->isConnected($user);
                        $pChecked = $pConnected && in_array($pid, $preferredDestinations, true);
                        ?>
                        <label class="choice-tile<?= $pConnected ? '' : ' is-disabled' ?>" for="dest_<?= e($pid) ?>">
                            <input class="form-check-input dest-cloud" type="checkbox" name="dest[]"
                                   id="dest_<?= e($pid) ?>"
                                   value="<?= e($pid) ?>"
                                   <?= $pChecked ? 'checked' : '' ?>
                                   <?= $pConnected ? '' : 'disabled' ?>>
                            <span class="choice-tile-body">
                                <span class="choice-tile-title"><?= e($provider->label()) ?></span>
                                <span class="choice-tile-desc"><?= $pConnected ? 'Upload to ' . e($provider->label()) : 'Connect first' ?></span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="form-text mt-2">Each checked destination starts its own job.</div>
            </fieldset>

            <?php if ($configuredProviders !== []): ?>
                <?php foreach ($configuredProviders as $provider): ?>
                    <?php
                    $pid = $provider->id();
                    $pConnected = $provider->isConnected($user);
                    if (!$pConnected) {
                        continue;
                    }
                    $providerPrefs = UserRepository::providerPrefs($user, $pid);
                    $folderFieldId = 'cloud_folder_' . $pid;
                    $conflictFieldId = 'cloud_conflict_' . $pid;
                    ?>
                    <div class="cloud-options d-none mt-3" id="cloud-options-<?= e($pid) ?>" data-cloud-provider="<?= e($pid) ?>">
                        <h3 class="h6 mb-2"><?= e($provider->label()) ?> options</h3>
                        <div class="row g-3">
                            <div class="col-md-7">
                                <label for="<?= e($folderFieldId) ?>" class="form-label">Folder</label>
                                <input name="cloud_folder[<?= e($pid) ?>]" type="text" class="form-control mono"
                                       id="<?= e($folderFieldId) ?>"
                                       value="<?= e($providerPrefs['folder']) ?>" placeholder="(root)">
                                <div class="form-text">Relative to your <?= e($provider->label()) ?> root. Leave blank for root.</div>
                            </div>
                            <div class="col-md-5">
                                <label for="<?= e($conflictFieldId) ?>" class="form-label">If file already exists</label>
                                <select class="form-select" name="cloud_conflict[<?= e($pid) ?>]" id="<?= e($conflictFieldId) ?>">
                                    <option value="rename" <?= $providerPrefs['conflict'] === 'rename' ? 'selected' : '' ?>>Rename (add number)</option>
                                    <option value="overwrite" <?= $providerPrefs['conflict'] === 'overwrite' ? 'selected' : '' ?>>Overwrite</option>
                                    <option value="skip" <?= $providerPrefs['conflict'] === 'skip' ? 'selected' : '' ?>>Skip</option>
                                </select>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <div class="cloud-options-shared d-none mt-3" id="cloud-options-shared">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" value="1" name="delete_after" id="delete_after">
                        <label class="form-check-label" for="delete_after">
                            Delete host staging file after cloud succeeds
                        </label>
                        <div class="form-text">Applies to cloud jobs that stage on the host first (e.g. yt-dlp). Does not affect a separate Local job.</div>
                    </div>
                </div>
            <?php endif; ?>
        </section>

        <section class="mb-4">
            <h2 class="subheader">Filename</h2>
            <label for="DLFILENAME" class="form-label visually-hidden">Filename</label>
            <input name="DLFILENAME" type="text" class="form-control mono" id="DLFILENAME" placeholder="Auto from URL, can be changed" autocomplete="off" spellcheck="false">
            <div class="form-text d-none" id="filename-ytdlp-hint">yt-dlp sets the name from the media title.</div>
        </section>

        <div class="mt-4 pt-3 border-top">
            <button class="btn btn-primary" type="submit">Start download</button>
        </div>
    </form>
    </div>
</div>

<div class="card mt-3" id="active-jobs-panel">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h2 class="card-title mb-0">Jobs</h2>
        <span class="text-muted small" id="active-jobs-count">
            <?php if ($activeJobCount > 0): ?>
                <?= $activeJobCount ?> active
            <?php elseif ($homeJobs): ?>
                <?= count($homeJobs) ?> recent
            <?php else: ?>
                none yet
            <?php endif; ?>
            · <a href="/history.php">History</a>
        </span>
    </div>
    <?php if (!$homeJobs): ?>
        <div class="empty" id="active-jobs-empty">
            <p class="empty-title">No jobs yet</p>
            <p class="empty-subtitle text-secondary">Queue a URL above. Recent and in-progress jobs show up here.</p>
        </div>
        <div class="table-responsive d-none" id="active-jobs-table-wrap">
            <table class="table card-table table-striped align-middle mb-0" id="active-jobs-table">
                <thead>
                <tr>
                    <th>Source</th>
                    <th>Name</th>
                    <th>Destination</th>
                    <th>Status</th>
                    <th class="w-1"></th>
                </tr>
                </thead>
                <tbody id="active-jobs-body"></tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="empty d-none" id="active-jobs-empty">
            <p class="empty-title">No jobs yet</p>
            <p class="empty-subtitle text-secondary">Queue a URL above. Recent and in-progress jobs show up here.</p>
        </div>
        <div class="table-responsive" id="active-jobs-table-wrap">
            <table class="table card-table table-striped align-middle mb-0" id="active-jobs-table">
                <thead>
                <tr>
                    <th>Source</th>
                    <th>Name</th>
                    <th>Destination</th>
                    <th>Status</th>
                    <th class="w-1"></th>
                </tr>
                </thead>
                <tbody id="active-jobs-body">
                <?php foreach ($homeJobs as $job): ?>
                    <?php
                    $name = (string) ($job['result_filename'] ?? $job['requested_filename'] ?? '');
                    $status = (string) ($job['status'] ?? 'pending');
                    $hasCloud = !empty($job['cloud_job_id']);
                    $cloudPath = (string) ($job['cloud_result_path'] ?? $job['cloud_remote_path'] ?? '');
                    $cloudStatus = (string) ($job['cloud_status'] ?? '');
                    $cloudProvider = (string) ($job['cloud_provider'] ?? 'dropbox');
                    $isActive = DownloadJobRepository::isActiveRow($job);
                    $fileId = isset($job['file_id']) && $job['file_id'] !== null ? (int) $job['file_id'] : null;
                    $canCancel = DownloadJobRepository::canCancel($job);
                    $canRetry = DownloadJobRepository::canRetry($job);
                    $canHide = DownloadJobRepository::canHide($job);
                    $error = (string) ($job['error'] ?? '');
                    $rowId = 'job-cloud-' . (int) $job['id'];
                    $rowClass = $isActive ? '' : 'text-muted';
                    ?>
                    <tr data-job-id="<?= (int) $job['id'] ?>"<?= $rowClass !== '' ? ' class="' . e($rowClass) . '"' : '' ?>>
                        <td class="mono small" title="<?= e((string) $job['source_url']) ?>"><?= e($truncateUrl((string) $job['source_url'])) ?></td>
                        <td class="mono">
                            <?php if ($fileId !== null && $status === 'complete'): ?>
                                <?php
                                $displayName = $name !== '' ? $name : 'file';
                                $previewKind = MimeType::previewKindFromName($displayName);
                                $canPreview = $previewKind !== null;
                                ?>
                                <?php if ($canPreview): ?>
                                    <button type="button" class="filename btn btn-link p-0 align-baseline"
                                            data-action-preview
                                            data-file-id="<?= $fileId ?>"
                                            data-file-name="<?= e($displayName) ?>"
                                            data-preview-kind="<?= e($previewKind) ?>"><?= e($displayName) ?></button>
                                <?php else: ?>
                                    <a class="filename" href="/download.php?id=<?= $fileId ?>"><?= e($displayName) ?></a>
                                <?php endif; ?>
                            <?php else: ?>
                                <?= e($name !== '' ? $name : '—') ?>
                            <?php endif; ?>
                        </td>
                        <td<?= $hasCloud
                            ? ' class="history-row-toggle" data-bs-toggle="collapse" data-bs-target="#' . e($rowId) . '" role="button" aria-expanded="false" style="cursor:pointer"'
                            : '' ?>>
                            <?= e($destinationLabel((string) $job['behavior'], $hasCloud ? $cloudProvider : null)) ?>
                            <?php if ($hasCloud): ?>
                                <div class="text-muted small">Cloud details ↓</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge text-bg-<?= e($statusBadge($status)) ?> job-status-badge">
                                <?= e($status) ?>
                            </span>
                            <?= $failReasonButton($error, $status) ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <div class="d-inline-flex align-items-center gap-2">
                                <?php if ($canCancel): ?>
                                    <form method="post" action="/job_action.php" class="d-inline">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="cancel">
                                        <input type="hidden" name="id" value="<?= (int) $job['id'] ?>">
                                        <input type="hidden" name="return" value="/">
                                        <button type="submit" class="btn btn-link btn-sm text-muted p-0">Cancel</button>
                                    </form>
                                <?php elseif ($canRetry): ?>
                                    <form method="post" action="/job_action.php" class="d-inline">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="retry">
                                        <input type="hidden" name="id" value="<?= (int) $job['id'] ?>">
                                        <input type="hidden" name="return" value="/">
                                        <button type="submit" class="btn btn-link btn-sm p-0">Retry</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($canHide): ?>
                                    <form method="post" action="/history_hide.php" class="d-inline">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="kind" value="download">
                                        <input type="hidden" name="id" value="<?= (int) $job['id'] ?>">
                                        <input type="hidden" name="return" value="/">
                                        <button type="submit" class="btn btn-link btn-sm text-muted p-0">Remove</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php if ($hasCloud): ?>
                        <tr class="collapse" id="<?= e($rowId) ?>">
                            <td colspan="5" class="bg-white">
                                <div class="p-3 small">
                                    <div class="fw-semibold mb-2"><?= e($providerLabel($cloudProvider)) ?> job</div>
                                    <dl class="row mb-0">
                                        <dt class="col-sm-2 text-muted">Status</dt>
                                        <dd class="col-sm-10">
                                            <span class="badge text-bg-<?= e($statusBadge($cloudStatus !== '' ? $cloudStatus : 'pending')) ?>">
                                                <?= e($cloudStatus !== '' ? $cloudStatus : '—') ?>
                                            </span>
                                            <?= $failReasonButton((string) ($job['cloud_error'] ?? ''), $cloudStatus !== '' ? $cloudStatus : 'pending') ?>
                                        </dd>
                                        <dt class="col-sm-2 text-muted">Path</dt>
                                        <dd class="col-sm-10 mono"><?= e($cloudPath !== '' ? $cloudPath : '—') ?></dd>
                                        <?php if (!empty($job['cloud_updated_at'])): ?>
                                            <dt class="col-sm-2 text-muted">Updated</dt>
                                            <dd class="col-sm-10"><?= e((string) $job['cloud_updated_at']) ?></dd>
                                        <?php endif; ?>
                                    </dl>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="jobs-fail-modal" tabindex="-1" aria-labelledby="jobs-fail-modal-label" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="jobs-fail-modal-label">Fail reason</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <pre class="mb-0 small text-wrap" id="jobs-fail-modal-body" style="white-space: pre-wrap; word-break: break-word;"></pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const urlInput = document.getElementById('DLURL');
    const filenameInput = document.getElementById('DLFILENAME');
    const videoOptionEls = {
        youtube: document.getElementById('youtube-options'),
        twitter: document.getElementById('twitter-options'),
        instagram: document.getElementById('instagram-options'),
        tiktok: document.getElementById('tiktok-options'),
        vimeo: document.getElementById('vimeo-options'),
        reddit: document.getElementById('reddit-options'),
    };
    const audioOptions = document.getElementById('audio-options');
    const curatedExtraOptions = document.getElementById('curated-extra-options');
    const ytAudioOnly = document.getElementById('yt_audio_only');
    const curatedQualitySelects = [
        document.getElementById('yt_format'),
        document.getElementById('twitter_format'),
        document.getElementById('instagram_format'),
        document.getElementById('tiktok_format'),
        document.getElementById('vimeo_format'),
        document.getElementById('reddit_format'),
    ].filter(Boolean);
    const ytdlpOptions = document.getElementById('ytdlp-options');
    const ytdlpFormatWrap = document.getElementById('ytdlp-format-wrap');
    const forceYtdlp = document.getElementById('force_ytdlp');
    const filenameHint = document.getElementById('filename-ytdlp-hint');
    const cloudOptionPanels = document.querySelectorAll('.cloud-options[data-cloud-provider]');
    const cloudOptionsShared = document.getElementById('cloud-options-shared');
    let filenameDirty = false;
    let lastYtdlpHost = null;
    let ytdlpTouched = false;

    const knownYtdlpHosts = new Set(<?= json_encode(YtDlpKnownHosts::hosts(), JSON_UNESCAPED_SLASHES) ?>);
    const curatedKinds = <?= json_encode($downloads->curatedKinds(), JSON_UNESCAPED_SLASHES) ?>;

    const youtubeRe = /^(?:http(?:s)?:\/\/)?(?:www\.)?(?:m\.)?(?:youtu\.be\/|youtube\.com\/(?:(?:watch)?\?(?:.*&)?v(?:i)?=|(?:embed|v|vi|user|shorts)\/))([^\?&\"'>]+)/;
    const twitterRe = /^(?:https?:\/\/)?(?:www\.|mobile\.)?(?:twitter\.com|x\.com)\/[^/\s?#]+\/status\/\d+/i;
    const instagramRe = /^(?:https?:\/\/)?(?:www\.)?(?:instagram\.com|instagr\.am)\/(?:p|reel|reels|tv)\/[A-Za-z0-9_-]+/i;
    const tiktokRe = /^(?:https?:\/\/)?(?:(?:vm|vt)\.tiktok\.com\/[A-Za-z0-9_-]+|(?:www\.|m\.)?tiktok\.com\/@[^/\s?#]+\/video\/\d+)/i;
    const soundcloudRe = /^(?:https?:\/\/)?(?:on\.soundcloud\.com\/[A-Za-z0-9_-]+|(?:(?:www|m)\.)?soundcloud\.com\/[^/\s?#]+\/[^/\s?#]+)/i;
    const mixcloudRe = /^(?:https?:\/\/)?(?:(?:www|m)\.)?mixcloud\.com\/[^/\s?#]+\/[^/\s?#]+/i;
    const bandcampRe = /^(?:https?:\/\/)?[a-z0-9-]+\.bandcamp\.com\/(?:track|album)\/[A-Za-z0-9_-]+/i;
    const vimeoRe = /^(?:https?:\/\/)?(?:(?:www|player)\.)?vimeo\.com\/(?:channels\/[^/\s?#]+\/|groups\/[^/\s?#]+\/videos\/|video\/)?\d+/i;
    const redditRe = /^(?:https?:\/\/)?(?:v\.redd\.it\/[A-Za-z0-9]+|(?:www\.)?redd\.it\/[A-Za-z0-9]+|(?:(?:www|old|new|np|m)\.)?reddit\.com\/r\/[^/\s?#]+\/comments\/[A-Za-z0-9]+)/i;

    function detectSource(url) {
        if (youtubeRe.test(url)) return 'youtube';
        if (twitterRe.test(url)) return 'twitter';
        if (instagramRe.test(url)) return 'instagram';
        if (tiktokRe.test(url)) return 'tiktok';
        if (soundcloudRe.test(url)) return 'soundcloud';
        if (mixcloudRe.test(url)) return 'mixcloud';
        if (bandcampRe.test(url)) return 'bandcamp';
        if (vimeoRe.test(url)) return 'vimeo';
        if (redditRe.test(url)) return 'reddit';
        return url.trim() !== '' ? 'other' : null;
    }

    function hostFromUrl(url) {
        url = (url || '').trim();
        if (!url) return null;
        try {
            if (!/^https?:\/\//i.test(url)) {
                url = 'https://' + url;
            }
            let host = new URL(url).hostname.toLowerCase();
            if (host.startsWith('www.')) {
                host = host.slice(4);
            }
            return host || null;
        } catch (e) {
            return null;
        }
    }

    function isKnownYtdlpHost(host) {
        if (!host) return false;
        let candidate = host;
        while (candidate) {
            if (knownYtdlpHosts.has(candidate)) return true;
            const dot = candidate.indexOf('.');
            if (dot === -1) return false;
            candidate = candidate.slice(dot + 1);
        }
        return false;
    }

    function filenameFromUrl(url) {
        const hashIndex = url.indexOf('#');
        if (hashIndex !== -1) {
            url = url.substring(0, hashIndex);
        }
        return (url.split('/').pop() || '').replace(/[\?].*$/g, '');
    }

    function syncSourceOptions() {
        const url = urlInput.value;
        const source = detectSource(url);
        const kind = source && curatedKinds[source] ? curatedKinds[source] : null;
        const curated = kind === 'video' || kind === 'audio';
        const isAudio = kind === 'audio';
        const isVideo = kind === 'video';
        const host = hostFromUrl(url);

        if (forceYtdlp && source === 'other') {
            if (host !== lastYtdlpHost) {
                lastYtdlpHost = host;
                ytdlpTouched = false;
            }
            if (!ytdlpTouched) {
                forceYtdlp.checked = isKnownYtdlpHost(host);
            }
        } else if (host !== lastYtdlpHost) {
            lastYtdlpHost = host;
            ytdlpTouched = false;
        }

        const useYtdlp = !curated && forceYtdlp && forceYtdlp.checked;
        const ytdlpActive = curated || useYtdlp;

        Object.keys(videoOptionEls).forEach(function (id) {
            const el = videoOptionEls[id];
            if (el) {
                el.classList.toggle('d-none', source !== id);
            }
        });
        if (audioOptions) {
            audioOptions.classList.toggle('d-none', !isAudio);
        }
        if (curatedExtraOptions) {
            curatedExtraOptions.classList.toggle('d-none', !isVideo);
        }
        const audioOnly = isVideo && ytAudioOnly && ytAudioOnly.checked;
        curatedQualitySelects.forEach(function (sel) {
            sel.disabled = audioOnly;
            const wrap = sel.closest('.source-options');
            if (wrap && wrap !== curatedExtraOptions && wrap !== audioOptions) {
                wrap.classList.toggle('opacity-50', audioOnly && !wrap.classList.contains('d-none'));
            }
        });
        ytdlpOptions.classList.toggle('d-none', source !== 'other');
        if (ytdlpFormatWrap) {
            ytdlpFormatWrap.classList.toggle('d-none', !useYtdlp);
        }
        if (filenameHint) {
            filenameHint.classList.toggle('d-none', !ytdlpActive);
        }
        filenameInput.disabled = ytdlpActive;
        if (ytdlpActive) {
            filenameInput.value = '';
            filenameDirty = false;
        } else if (!filenameDirty) {
            filenameInput.value = filenameFromUrl(url);
        }
    }

    function syncCloudOptions() {
        let anyCloud = false;
        cloudOptionPanels.forEach(function (panel) {
            const providerId = panel.getAttribute('data-cloud-provider');
            const checkbox = document.getElementById('dest_' + providerId);
            const show = !!(checkbox && checkbox.checked && !checkbox.disabled);
            panel.classList.toggle('d-none', !show);
            if (show) {
                anyCloud = true;
            }
        });
        if (cloudOptionsShared) {
            cloudOptionsShared.classList.toggle('d-none', !anyCloud);
        }
    }

    filenameInput.addEventListener('input', function () {
        if (!filenameInput.disabled) {
            filenameDirty = true;
        }
    });

    urlInput.addEventListener('input', syncSourceOptions);
    if (forceYtdlp) {
        forceYtdlp.addEventListener('change', function () {
            ytdlpTouched = true;
            syncSourceOptions();
        });
    }
    if (ytAudioOnly) {
        ytAudioOnly.addEventListener('change', syncSourceOptions);
    }

    document.querySelectorAll('input[name="dest[]"]').forEach(function (el) {
        el.addEventListener('change', syncCloudOptions);
    });

    syncSourceOptions();
    syncCloudOptions();

    const body = document.getElementById('active-jobs-body');
    const countEl = document.getElementById('active-jobs-count');
    const emptyEl = document.getElementById('active-jobs-empty');
    const tableWrap = document.getElementById('active-jobs-table-wrap');
    const csrf = <?= json_encode(Csrf::token()) ?>;

    function providerLabel(provider) {
        if (provider === 'dropbox') return 'Dropbox';
        if (provider === 'google_drive') return 'Google Drive';
        if (provider) return provider.replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
        return 'Cloud';
    }

    function destinationLabel(behavior, provider) {
        const cloud = provider ? providerLabel(provider) : 'Cloud';
        if (behavior === 'save_to_cloud' || behavior === 'save_to_dropbox') return cloud;
        if (behavior === 'save_to_both') return 'Local + ' + cloud;
        return 'Local';
    }

    function statusBadgeClass(status) {
        if (status === 'complete' || status === 'success') return 'badge text-bg-success job-status-badge';
        if (status === 'failed') return 'badge text-bg-danger job-status-badge';
        if (status === 'skipped') return 'badge text-bg-warning job-status-badge';
        if (status === 'in_progress') return 'badge text-bg-info job-status-badge';
        return 'badge text-bg-secondary job-status-badge';
    }

    function truncateUrl(url, max) {
        max = max || 56;
        if (!url || url.length <= max) return url || '';
        return url.substring(0, max - 1) + '…';
    }

    function failReasonButton(error, status) {
        if (!error || (status !== 'failed' && status !== 'skipped')) {
            return '';
        }
        return '<button type="button" class="btn btn-link btn-sm text-danger p-0 align-baseline ms-1"'
            + ' data-bs-toggle="modal" data-bs-target="#jobs-fail-modal"'
            + ' data-fail-reason="' + escapeAttr(error) + '">Reason</button>';
    }

    function jobActionHtml(job) {
        const parts = [];
        if (job.can_cancel) {
            parts.push('<form method="post" action="/job_action.php" class="d-inline">'
                + '<input type="hidden" name="_csrf" value="' + escapeAttr(csrf) + '">'
                + '<input type="hidden" name="action" value="cancel">'
                + '<input type="hidden" name="id" value="' + job.id + '">'
                + '<input type="hidden" name="return" value="/">'
                + '<button type="submit" class="btn btn-link btn-sm text-muted p-0">Cancel</button>'
                + '</form>');
        } else if (job.can_retry) {
            parts.push('<form method="post" action="/job_action.php" class="d-inline">'
                + '<input type="hidden" name="_csrf" value="' + escapeAttr(csrf) + '">'
                + '<input type="hidden" name="action" value="retry">'
                + '<input type="hidden" name="id" value="' + job.id + '">'
                + '<input type="hidden" name="return" value="/">'
                + '<button type="submit" class="btn btn-link btn-sm p-0">Retry</button>'
                + '</form>');
        }
        if (job.can_hide) {
            parts.push('<form method="post" action="/history_hide.php" class="d-inline">'
                + '<input type="hidden" name="_csrf" value="' + escapeAttr(csrf) + '">'
                + '<input type="hidden" name="kind" value="download">'
                + '<input type="hidden" name="id" value="' + job.id + '">'
                + '<input type="hidden" name="return" value="/">'
                + '<button type="submit" class="btn btn-link btn-sm text-muted p-0">Remove</button>'
                + '</form>');
        }
        if (!parts.length) {
            return '';
        }
        return '<div class="d-inline-flex align-items-center gap-2">' + parts.join('') + '</div>';
    }

    function cloudDetailsHtml(job) {
        const provider = job.cloud_provider || 'dropbox';
        const cloudStatus = job.cloud_status || '';
        const cloudPath = job.cloud_path || '';
        const badgeStatus = cloudStatus || 'pending';
        let html = '<tr class="collapse" id="job-cloud-' + job.id + '">'
            + '<td colspan="5" class="bg-white"><div class="p-3 small">'
            + '<div class="fw-semibold mb-2">' + escapeHtml(providerLabel(provider)) + ' job</div>'
            + '<dl class="row mb-0">'
            + '<dt class="col-sm-2 text-muted">Status</dt>'
            + '<dd class="col-sm-10"><span class="' + statusBadgeClass(badgeStatus) + '">'
            + escapeHtml(cloudStatus || '—') + '</span>'
            + failReasonButton(job.cloud_error, cloudStatus || 'pending')
            + '</dd>'
            + '<dt class="col-sm-2 text-muted">Path</dt>'
            + '<dd class="col-sm-10 mono">' + escapeHtml(cloudPath || '—') + '</dd>';
        if (job.cloud_updated_at) {
            html += '<dt class="col-sm-2 text-muted">Updated</dt>'
                + '<dd class="col-sm-10">' + escapeHtml(job.cloud_updated_at) + '</dd>';
        }
        html += '</dl></div></td></tr>';
        return html;
    }

    function renderJobs(jobs, activeCount) {
        if (!body || !countEl) return;
        const expanded = {};
        body.querySelectorAll('tr.collapse.show').forEach(function (row) {
            if (row.id) expanded[row.id] = true;
        });
        if (!jobs.length) {
            if (emptyEl) emptyEl.classList.remove('d-none');
            if (tableWrap) tableWrap.classList.add('d-none');
            body.innerHTML = '';
            countEl.innerHTML = 'none yet · <a href="/history.php">History</a>';
            return;
        }
        if (emptyEl) emptyEl.classList.add('d-none');
        if (tableWrap) tableWrap.classList.remove('d-none');
        const countLabel = activeCount > 0 ? (activeCount + ' active') : (jobs.length + ' recent');
        countEl.innerHTML = countLabel + ' · <a href="/history.php">History</a>';
        body.innerHTML = jobs.map(function (job) {
            const name = job.result_filename || job.requested_filename || '—';
            const status = job.status || 'pending';
            const hasCloud = !!job.cloud_job_id;
            const rowId = 'job-cloud-' + job.id;
            const rowClass = job.active ? '' : ' class="text-muted"';
            const destToggle = hasCloud
                ? ' class="history-row-toggle" data-bs-toggle="collapse" data-bs-target="#' + rowId
                    + '" role="button" aria-expanded="' + (expanded[rowId] ? 'true' : 'false')
                    + '" style="cursor:pointer"'
                : '';
            let nameHtml = escapeHtml(name);
            if (job.file_id && job.status === 'complete') {
                const label = name === '—' ? 'file' : name;
                nameHtml = typeof window.filePreviewLinkHtml === 'function'
                    ? window.filePreviewLinkHtml(job.file_id, label)
                    : ('<a class="filename" href="/download.php?id=' + job.file_id + '">'
                        + escapeHtml(label) + '</a>');
            }
            const destHtml = escapeHtml(destinationLabel(job.behavior, hasCloud ? (job.cloud_provider || 'dropbox') : null))
                + (hasCloud ? '<div class="text-muted small">Cloud details ↓</div>' : '');
            let html = '<tr data-job-id="' + job.id + '"' + rowClass + '>'
                + '<td class="mono small" title="' + escapeAttr(job.source_url) + '">' + escapeHtml(truncateUrl(job.source_url)) + '</td>'
                + '<td class="mono">' + nameHtml + '</td>'
                + '<td' + destToggle + '>' + destHtml + '</td>'
                + '<td><span class="' + statusBadgeClass(status) + '">' + escapeHtml(status) + '</span>'
                + failReasonButton(job.error, status)
                + '</td>'
                + '<td class="text-end text-nowrap">' + jobActionHtml(job) + '</td></tr>';
            if (hasCloud) {
                html += cloudDetailsHtml(job);
                if (expanded[rowId]) {
                    html = html.replace('class="collapse" id="' + rowId + '"', 'class="collapse show" id="' + rowId + '"');
                }
            }
            return html;
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
                if (<?= $activeJobCount > 0 ? 'true' : 'false' ?>) {
                    setTimeout(pollActiveJobs, 4000);
                }
            });
    }

    if (<?= $activeJobCount > 0 ? 'true' : 'false' ?>) {
        setTimeout(pollActiveJobs, 2000);
    } else {
        // Refresh once so a job that finished during redirect appears without reload.
        setTimeout(pollActiveJobs, 1200);
    }

    var failModal = document.getElementById('jobs-fail-modal');
    if (failModal) {
        failModal.addEventListener('show.bs.modal', function (event) {
            var btn = event.relatedTarget;
            var modalBody = document.getElementById('jobs-fail-modal-body');
            if (!modalBody) return;
            modalBody.textContent = btn && btn.getAttribute('data-fail-reason')
                ? btn.getAttribute('data-fail-reason')
                : '';
        });
    }
})();
</script>
<?php
include dirname(__DIR__) . '/templates/partials/preview_modal.php';
include dirname(__DIR__) . '/templates/partials/preview_js.php';
View::render('Download', ob_get_clean(), $user);
