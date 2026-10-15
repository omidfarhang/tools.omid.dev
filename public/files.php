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

$result = (new FileRepository())->forUserPaginated(
    (int) $user['id'],
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
        Tip: <a href="/user/">Connect a cloud account</a> to send files to your cloud.
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
