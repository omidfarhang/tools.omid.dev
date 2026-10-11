<?php
/** @var string $title */
/** @var string $content */
/** @var array|null $user */
/** @var string $appName */
/** @var list<array{type:string,message:string}> $flashes */
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" sizes="32x32" href="https://omid.dev/logo/favicon-32x32.png">
    <title><?= e($title) ?> · <?= e($appName) ?></title>
    <link href="/assets/css/bootstrap.min.css" rel="stylesheet">
    <style>
        #dropbox-status { margin-top: 1rem; }
        .progress { height: 1.25rem; }
    </style>
</head>
<body>
<div class="col-lg-8 mx-auto p-4 py-md-5">
    <nav class="navbar navbar-expand-lg navbar-light pb-3 mb-4 border-bottom">
        <div class="container-fluid px-0">
            <a class="navbar-brand" href="/"><?= e($appName) ?></a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navMain">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <?php if ($user): ?>
                        <li class="nav-item"><a class="nav-link" href="/">Home</a></li>
                        <li class="nav-item"><a class="nav-link" href="/files.php">My Files</a></li>
                        <li class="nav-item"><a class="nav-link" href="/user/">Account</a></li>
                        <?php if (($user['role'] ?? '') === 'admin'): ?>
                            <li class="nav-item"><a class="nav-link" href="/admin/">Admin</a></li>
                        <?php endif; ?>
                    <?php endif; ?>
                </ul>
                <ul class="navbar-nav ms-auto">
                    <?php if ($user): ?>
                        <li class="nav-item"><span class="navbar-text me-3"><?= e($user['username']) ?></span></li>
                        <li class="nav-item"><a class="nav-link" href="/logout.php">Logout</a></li>
                    <?php else: ?>
                        <li class="nav-item"><a class="nav-link" href="/login.php">Login</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <?php foreach ($flashes as $flash): ?>
        <div class="alert alert-<?= e($flash['type'] === 'error' ? 'danger' : $flash['type']) ?>">
            <?= e($flash['message']) ?>
        </div>
    <?php endforeach; ?>

    <main>
        <?= $content ?>
    </main>

    <footer class="pt-5 my-5 text-muted border-top">
        All Rights Reserved <?= date('Y') ?> | Omid Farhang
    </footer>
</div>
<script src="/assets/js/jquery.min.js"></script>
<script src="/assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
