<?php

declare(strict_types=1);

/**
 * Example config values. Runtime config is loaded from environment / .env
 * via App\Config. Copy .env.example to .env for local/docker deploys.
 */
return [
    'app_base_url' => 'https://tools.omid.dev',
    'app_name' => 'Tools Center',
    'db_path' => __DIR__ . '/../storage/app.db',
    'temp_dir' => __DIR__ . '/../storage/temp',
    'ytdlp_cookies_path' => __DIR__ . '/../storage/ytdlp-cookies.txt',
    'dropbox_app_key' => '',
    'dropbox_app_secret' => '',
    'admin_username' => 'admin',
    'admin_password' => 'change-me',
    'app_cli_token' => '',
    'app_cli_username' => 'admin',
];
