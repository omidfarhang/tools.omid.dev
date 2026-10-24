<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Auth;
use App\Cloud\CloudSendService;
use App\FileRepository;
use App\Pager;
use App\UserRepository;
use App\View;

$user = Auth::requireLogin();
$users = new UserRepository();
$user = $users->findById((int) $user['id']) ?? $user;

$req = Pager::requestParams();
$sortInfo = FileRepository::normalizeSort($req['sort'], $req['dir']);
$sort = $sortInfo['sort'];
$dir = $sortInfo['dir'];
$q = $req['q'];
$userId = (int) $user['id'];
$fileRepo = new FileRepository();

$highlightFileId = isset($_GET['file']) ? (int) $_GET['file'] : 0;
if ($highlightFileId > 0) {
    $targetPage = $fileRepo->pageForFile($highlightFileId, $userId, $sort, $dir, $q, Pager::DEFAULT_PER_PAGE);
    if ($targetPage === null) {
        flash('error', 'File not found.');
        redirect(Pager::url('/files.php', [
            'sort' => $sort,
            'dir' => strtolower($dir),
            'q' => $q !== '' ? $q : null,
            'page' => $req['page'] > 1 ? $req['page'] : null,
        ]));
    }
    if ($targetPage !== $req['page']) {
        redirect(Pager::url('/files.php', [
            'sort' => $sort,
            'dir' => strtolower($dir),
            'q' => $q !== '' ? $q : null,
            'page' => $targetPage > 1 ? $targetPage : null,
            'file' => $highlightFileId,
        ]));
    }
} else {
    $highlightFileId = 0;
}

$result = $fileRepo->forUserPaginated(
    $userId,
    $sort,
    $dir,
    $q,
    $req['page']
);
$files = $result['items'];

$pagerPath = '/files.php';
$pagerQuery = [
    'sort' => $sort,
    'dir' => strtolower($dir),
    'q' => $q !== '' ? $q : null,
    'file' => $highlightFileId > 0 ? $highlightFileId : null,
];
$redirect = Pager::url($pagerPath, $pagerQuery, ['page' => $result['page'] > 1 ? $result['page'] : null]);

$cloud = CloudSendService::create();
$cloudConnected = $cloud->registry()->anyConnected($user);
$connectedCloudProviders = [];
foreach ($cloud->registry()->all() as $provider) {
    if ($provider->isConfigured() && $provider->isConnected($user)) {
        $connectedCloudProviders[] = $provider;
    }
}
$prefs = UserRepository::cloudPrefs($user);
$usedBytes = $users->usedBytes((int) $user['id']);
$quotaBytes = UserRepository::effectiveQuotaBytes($user);
$storageLabel = UserRepository::formatUsageLabel($usedBytes, $quotaBytes);

$cloudJob = null;
if (!empty($_SESSION['_cloud_monitor'])) {
    $cloudJob = $_SESSION['_cloud_monitor'];
    unset($_SESSION['_cloud_monitor']);
} elseif (!empty($_SESSION['_dropbox_monitor'])) {
    // Legacy session key from older send flow.
    $cloudJob = $_SESSION['_dropbox_monitor'];
    $cloudJob['provider'] = $cloudJob['provider'] ?? 'dropbox';
    unset($_SESSION['_dropbox_monitor']);
}

$mode = 'user';
$pagerTotal = $result['total'];
$pagerPage = $result['page'];
$pagerTotalPages = $result['total_pages'];
$pagerPerPage = $result['per_page'];

ob_start();
?>
<p class="text-secondary mb-3">Download files from the host or enable a public link (optional expiry and download limit) to share without login.</p>

<div id="cloud-status" class="alert d-none" role="alert">
    <div id="cloud-message"></div>
    <div id="cloud-progress" class="progress mt-2 d-none">
        <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width:100%"></div>
    </div>
</div>

<?php if (!$cloudConnected): ?>
    <div class="alert alert-warning">
        Tip: <a href="/user/cloud.php">Connect a cloud account</a> to send files to your cloud.
    </div>
<?php endif; ?>

<div class="card">
    <?php if ($pagerTotal === 0 && $q === ''): ?>
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h2 class="card-title mb-0">Your files</h2>
            <span class="text-secondary small mono"><?= e($storageLabel) ?></span>
        </div>
        <div class="empty">
            <p class="empty-title">No files yet</p>
            <p class="empty-subtitle text-secondary">Pull a URL from the <a href="/">Download</a> page to get started.</p>
        </div>
    <?php else: ?>
        <?php
        $panelTitle = 'Your files';
        $panelMeta = $storageLabel;
        include dirname(__DIR__) . '/templates/partials/files_table.php';
        ?>
    <?php endif; ?>
</div>

<?php
if ($files) {
    include dirname(__DIR__) . '/templates/partials/files_modals.php';
    include dirname(__DIR__) . '/templates/partials/files_js.php';
}
include dirname(__DIR__) . '/templates/partials/cloud_monitor_js.php';
View::render('My Files', ob_get_clean(), $user);
