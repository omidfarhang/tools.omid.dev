<?php

declare(strict_types=1);

use App\Config;
use App\Database;

$root = dirname(__DIR__);

require_once $root . '/vendor/autoload.php';

Config::load($root);

if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

$temp = Config::tempDir();
if (!is_dir($temp)) {
    mkdir($temp, 0775, true);
}

Database::pdo();
Config::hydrateFromDatabase();
