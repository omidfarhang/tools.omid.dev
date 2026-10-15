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
        'home' => '<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l-2 0l9 -9l9 9l-2 0"/><path d="M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-7"/><path d="M9 21v-6a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2v6"/></svg>',
        'files' => '<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z"/></svg>',
        'history' => '<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8l0 4l2 2"/><path d="M3.05 11a9 9 0 1 1 .5 4m-.5 5v-5h5"/></svg>',
        'user' => '<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 7a4 4 0 1 0 8 0a4 4 0 0 0 -8 0"/><path d="M6 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2"/></svg>',
        'admin' => '<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3"/></svg>',
        'users' => '<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 7m-4 0a4 4 0 1 0 8 0a4 4 0 1 0 -8 0"/><path d="M3 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/><path d="M21 21v-2a4 4 0 0 0 -3 -3.85"/></svg>',
        'ytdlp' => '<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2"/><path d="M7 11l5 5l5 -5"/><path d="M12 4l0 12"/></svg>',
        'cloud' => '<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6.657 18c-2.572 0 -4.657 -2.007 -4.657 -4.483c0 -2.475 2.085 -4.482 4.657 -4.482c.393 -1.762 1.794 -3.2 3.675 -3.773c1.88 -.572 3.956 -.193 5.444 1c1.488 1.19 2.162 3.007 1.77 4.769h.99c1.913 0 3.464 1.56 3.464 3.486c0 1.927 -1.551 3.487 -3.465 3.487h-12.878"/></svg>',
        'storage' => '<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 4h12a2 2 0 0 1 2 2v2a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-2a2 2 0 0 1 2 -2z"/><path d="M6 12h12a2 2 0 0 1 2 2v2a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-2a2 2 0 0 1 2 -2z"/><path d="M6 14h.01"/><path d="M6 6h.01"/></svg>',
    ];
    return $icons[$name] ?? '';
};

$brandInitial = strtoupper(substr($appName, 0, 1));

$navLink = static function (string $href, string $label, string $icon, bool $active) use ($navIcon): string {
    $cls = $active ? 'nav-link active' : 'nav-link';
    return '<li class="nav-item">'
        . '<a class="' . $cls . '" href="' . e($href) . '">'
        . '<span class="nav-link-icon d-md-none d-lg-inline-block">' . $navIcon($icon) . '</span>'
        . '<span class="nav-link-title">' . e($label) . '</span>'
        . '</a></li>';
};

$renderNav = static function () use ($user, $isActive, $navLink): void {
    echo '<ul class="navbar-nav">';
    echo $navLink('/', 'Home', 'home', $isActive('/', '/index.php'));
    echo $navLink('/files.php', 'My Files', 'files', $isActive('/files.php'));
    echo $navLink('/history.php', 'History', 'history', $isActive('/history.php'));
    echo $navLink('/user/', 'Account', 'user', $isActive('/user'));
    if (($user['role'] ?? '') === 'admin') {
        echo '<li class="nav-section-title">Admin</li>';
        echo $navLink(
            '/admin/',
            'Overview',
            'admin',
            $isActive('/admin') && !$isActive('/admin/users.php', '/admin/files.php', '/admin/history.php', '/admin/settings', '/admin/cookies.php')
        );
        echo $navLink('/admin/users.php', 'Users', 'users', $isActive('/admin/users.php'));
        echo $navLink('/admin/files.php', 'All files', 'files', $isActive('/admin/files.php'));
        echo $navLink('/admin/history.php', 'All history', 'history', $isActive('/admin/history.php'));
        echo '<li class="nav-section-title">Settings</li>';
        echo $navLink('/admin/settings/ytdlp.php', 'yt-dlp', 'ytdlp', $isActive('/admin/settings/ytdlp.php'));
        echo $navLink('/admin/settings/cloud.php', 'Cloud', 'cloud', $isActive('/admin/settings/cloud.php'));
        echo $navLink('/admin/settings/storage.php', 'Storage', 'storage', $isActive('/admin/settings/storage.php'));
    }
    echo '</ul>';
};

$renderThemeSwitch = static function (string $extraClass = ''): void {
    $class = trim('theme-switch ' . $extraClass);
    ?>
    <div class="<?= e($class) ?>" data-theme-switch role="group" aria-label="Color theme">
        <button type="button" class="theme-switch-btn" data-theme-value="light" aria-pressed="false" title="Light" aria-label="Light theme">
            <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 12m-4 0a4 4 0 1 0 8 0a4 4 0 1 0 -8 0"/><path d="M3 12h1m8 -9v1m8 8h1m-9 8v1m-6.4 -15.4l.7 .7m12.1 -.7l-.7 .7m0 11.4l.7 .7m-12.1 -.7l-.7 .7"/></svg>
        </button>
        <button type="button" class="theme-switch-btn" data-theme-value="auto" aria-pressed="false" title="System" aria-label="System theme">
            <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 5a1 1 0 0 1 1 -1h16a1 1 0 0 1 1 1v10a1 1 0 0 1 -1 1h-16a1 1 0 0 1 -1 -1v-10z"/><path d="M7 20h10"/><path d="M9 16v4"/><path d="M15 16v4"/></svg>
        </button>
        <button type="button" class="theme-switch-btn" data-theme-value="dark" aria-pressed="false" title="Dark" aria-label="Dark theme">
            <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3c.132 0 .263 0 .393 0a7.5 7.5 0 0 0 7.92 12.446a9 9 0 1 1 -8.313 -12.454z"/></svg>
        </button>
    </div>
    <?php
};
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" sizes="32x32" href="https://omid.dev/logo/favicon-32x32.png">
    <title><?= e($title) ?> · <?= e($appName) ?></title>
    <script src="/assets/js/tabler-theme.min.js"></script>
    <link href="/assets/css/tabler.min.css" rel="stylesheet">
    <link href="/assets/css/app.css" rel="stylesheet">
</head>
<body>
<?php if ($user): ?>
    <div class="page">
        <aside class="navbar navbar-vertical navbar-expand-lg" data-bs-theme="dark">
            <div class="container-fluid">
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#sidebar-menu" aria-controls="sidebar-menu" aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <a class="navbar-brand" href="/">
                    <span class="avatar avatar-sm bg-teal-lt text-teal me-2"><?= e($brandInitial) ?></span>
                    <?= e($appName) ?>
                </a>
                <div class="navbar-nav flex-row d-lg-none">
                    <?php $renderThemeSwitch(); ?>
                </div>
                <div class="collapse navbar-collapse" id="sidebar-menu">
                    <?php $renderNav(); ?>
                </div>
            </div>
        </aside>

        <div class="page-wrapper">
            <div class="page-header d-print-none">
                <div class="container-xl">
                    <div class="row g-2 align-items-center">
                        <div class="col">
                            <h2 class="page-title"><?= e($title) ?></h2>
                        </div>
                        <div class="col-auto ms-auto d-print-none">
                            <div class="d-flex align-items-center gap-2">
                                <div class="d-none d-lg-block">
                                    <?php $renderThemeSwitch(); ?>
                                </div>
                                <span class="badge bg-azure-lt text-azure"><?= e($user['username']) ?></span>
                                <a class="btn btn-outline-secondary btn-sm" href="/logout.php">Logout</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="page-body">
                <div class="container-xl">
                    <?php foreach ($flashes as $flash): ?>
                        <div class="alert alert-<?= e($flash['type'] === 'error' ? 'danger' : $flash['type']) ?>" role="alert">
                            <?= e($flash['message']) ?>
                        </div>
                    <?php endforeach; ?>
                    <?= $content ?>
                </div>
            </div>

            <footer class="footer footer-transparent d-print-none">
                <div class="container-xl">
                    <div class="text-secondary">All Rights Reserved <?= date('Y') ?> | Omid Farhang</div>
                </div>
            </footer>
        </div>
    </div>
<?php else: ?>
    <div class="page page-center">
        <div class="container container-tight py-4">
            <div class="text-center mb-4">
                <div class="mb-3">
                    <?php $renderThemeSwitch('d-inline-flex'); ?>
                </div>
                <a href="/login.php" class="navbar-brand navbar-brand-autodark">
                    <span class="avatar bg-teal-lt text-teal me-2"><?= e($brandInitial) ?></span>
                    <?= e($appName) ?>
                </a>
            </div>
            <?php foreach ($flashes as $flash): ?>
                <div class="alert alert-<?= e($flash['type'] === 'error' ? 'danger' : $flash['type']) ?>" role="alert">
                    <?= e($flash['message']) ?>
                </div>
            <?php endforeach; ?>
            <?= $content ?>
            <div class="text-center text-secondary mt-4">
                All Rights Reserved <?= date('Y') ?> | Omid Farhang
            </div>
        </div>
    </div>
<?php endif; ?>
<script src="/assets/js/tabler.min.js"></script>
<script src="/assets/js/theme-switch.js"></script>
</body>
</html>
