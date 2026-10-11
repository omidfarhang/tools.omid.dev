<?php
/** @var string $title */
/** @var string $content */
/** @var array|null $user */
/** @var string $appName */
/** @var list<array{type:string,message:string}> $flashes */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$isActive = static function (string ...$prefixes) use ($path): bool {
    foreach ($prefixes as $prefix) {
        if ($prefix === '/') {
            if ($path === '/' || $path === '/index.php') {
                return true;
            }
            continue;
        }
        if (str_starts_with($path, $prefix)) {
            return true;
        }
    }
    return false;
};

$navIcon = static function (string $name): string {
    $icons = [
        'home' => '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1h-5v-6H10v6H5a1 1 0 0 1-1-1v-9.5Z"/></svg>',
        'files' => '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M8 4h6l4 4v12a1 1 0 0 1-1 1H8a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1Z"/><path d="M14 4v4h4"/></svg>',
        'user' => '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="8" r="3.5"/><path d="M5 19.5c1.5-3 4-4.5 7-4.5s5.5 1.5 7 4.5"/></svg>',
        'admin' => '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 3 4.5 6.5v5c0 4.5 3.2 7.8 7.5 9 4.3-1.2 7.5-4.5 7.5-9v-5L12 3Z"/></svg>',
        'users' => '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M3.5 19c1.2-2.8 3.3-4.2 5.5-4.2s4.3 1.4 5.5 4.2"/><path d="M14 19c.7-1.8 2-2.8 3.5-2.8 1.2 0 2.2.6 3 1.8"/></svg>',
    ];
    return $icons[$name] ?? '';
};

$brandInitial = strtoupper(substr($appName, 0, 1));

$renderNav = static function () use ($user, $isActive, $navIcon): void {
    ?>
    <nav class="sidebar-nav">
        <a href="/" class="<?= $isActive('/', '/index.php') ? 'active' : '' ?>"><?= $navIcon('home') ?> Home</a>
        <a href="/files.php" class="<?= $isActive('/files.php') ? 'active' : '' ?>"><?= $navIcon('files') ?> My Files</a>
        <a href="/user/" class="<?= $isActive('/user') ? 'active' : '' ?>"><?= $navIcon('user') ?> Account</a>
        <?php if (($user['role'] ?? '') === 'admin'): ?>
            <div class="nav-section">Admin</div>
            <a href="/admin/" class="<?= ($isActive('/admin') && !$isActive('/admin/users.php', '/admin/files.php')) ? 'active' : '' ?>"><?= $navIcon('admin') ?> Overview</a>
            <a href="/admin/users.php" class="<?= $isActive('/admin/users.php') ? 'active' : '' ?>"><?= $navIcon('users') ?> Users</a>
            <a href="/admin/files.php" class="<?= $isActive('/admin/files.php') ? 'active' : '' ?>"><?= $navIcon('files') ?> All files</a>
        <?php endif; ?>
    </nav>
    <?php
};
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" sizes="32x32" href="https://omid.dev/logo/favicon-32x32.png">
    <title><?= e($title) ?> · <?= e($appName) ?></title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=ibm-plex-mono:400,500|outfit:400,500,600,700" rel="stylesheet">
    <link href="/assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="/assets/css/app.css" rel="stylesheet">
</head>
<body class="<?= $user ? 'layout-app' : 'layout-auth' ?>">
<?php if ($user): ?>
    <aside class="sidebar">
        <a class="sidebar-brand" href="/">
            <span class="brand-mark"><?= e($brandInitial) ?></span>
            <span class="brand-text"><?= e($appName) ?></span>
        </a>
        <?php $renderNav(); ?>
    </aside>

    <div class="offcanvas offcanvas-start sidebar-offcanvas" tabindex="-1" id="sidebarOffcanvas" aria-labelledby="sidebarOffcanvasLabel">
        <div class="offcanvas-header">
            <h2 class="offcanvas-title h5 mb-0" id="sidebarOffcanvasLabel"><?= e($appName) ?></h2>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body pt-0">
            <?php $renderNav(); ?>
        </div>
    </div>

    <div class="app-main">
        <header class="topbar">
            <div class="d-flex align-items-center gap-2">
                <button class="sidebar-toggle" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarOffcanvas" aria-controls="sidebarOffcanvas" aria-label="Open menu">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                </button>
                <h1 class="topbar-title"><?= e($title) ?></h1>
            </div>
            <div class="topbar-actions">
                <span class="user-chip"><span class="user-chip-dot" aria-hidden="true"></span><?= e($user['username']) ?></span>
                <a class="btn btn-sm btn-outline-secondary" href="/logout.php">Logout</a>
            </div>
        </header>

        <main class="content">
            <?php foreach ($flashes as $flash): ?>
                <div class="alert alert-<?= e($flash['type'] === 'error' ? 'danger' : $flash['type']) ?>">
                    <?= e($flash['message']) ?>
                </div>
            <?php endforeach; ?>
            <?= $content ?>
        </main>

        <footer class="app-footer">
            All Rights Reserved <?= date('Y') ?> | Omid Farhang
        </footer>
    </div>
<?php else: ?>
    <div class="auth-wrap">
        <div class="auth-panel">
            <a class="auth-brand" href="/login.php">
                <span class="brand-mark"><?= e($brandInitial) ?></span>
                <span class="brand-text"><?= e($appName) ?></span>
            </a>
            <?php foreach ($flashes as $flash): ?>
                <div class="alert alert-<?= e($flash['type'] === 'error' ? 'danger' : $flash['type']) ?>">
                    <?= e($flash['message']) ?>
                </div>
            <?php endforeach; ?>
            <?= $content ?>
        </div>
    </div>
    <footer class="auth-footer">
        All Rights Reserved <?= date('Y') ?> | Omid Farhang
    </footer>
<?php endif; ?>
<script src="/assets/js/jquery.min.js"></script>
<script src="/assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
