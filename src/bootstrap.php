<?php

declare(strict_types=1);

use App\Config;
use App\Database;

$root = dirname(__DIR__);

require_once $root . '/vendor/autoload.php';

Config::load($root);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$temp = Config::tempDir();
if (!is_dir($temp)) {
    mkdir($temp, 0775, true);
}

Database::pdo();
Config::hydrateFromDatabase();
