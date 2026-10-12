<?php

declare(strict_types=1);

namespace App;

final class Config
{
    private static bool $loaded = false;

    /** @var array<string, mixed> */
    private static array $values = [];

    /** DB-backed overrides for ManagedSettings keys (take precedence over env). */
    /** @var array<string, string> */
    private static array $dbOverrides = [];

    public static function load(string $rootDir): void
    {
        if (self::$loaded) {
            return;
        }

        self::loadEnvFile($rootDir . '/.env');

        $defaults = [
            'app_base_url' => getenv('APP_BASE_URL') ?: 'http://localhost:8080',
            'app_name' => getenv('APP_NAME') ?: 'Tools Center',
            'db_path' => getenv('DB_PATH') ?: ($rootDir . '/storage/app.db'),
            'temp_dir' => getenv('TEMP_DIR') ?: ($rootDir . '/storage/temp'),
            'ytdlp_cookies_path' => getenv('YTDLP_COOKIES_PATH') ?: ($rootDir . '/storage/ytdlp-cookies.txt'),
            'dropbox_app_key' => getenv('DROPBOX_APP_KEY') ?: '',
            'dropbox_app_secret' => getenv('DROPBOX_APP_SECRET') ?: '',
            'ytdlp_extra_args' => '',
            'admin_username' => getenv('ADMIN_USERNAME') ?: 'admin',
            'admin_password' => getenv('ADMIN_PASSWORD') ?: '',
            'app_cli_token' => getenv('APP_CLI_TOKEN') ?: '',
            'app_cli_username' => getenv('APP_CLI_USERNAME') ?: 'admin',
            'root_dir' => $rootDir,
        ];

        self::$values = $defaults;
        self::$loaded = true;
    }

    /**
     * Load ManagedSettings rows from SQLite so Config::get prefers admin values.
     * Call after Database::pdo().
     */
    public static function hydrateFromDatabase(): void
    {
        $repo = new SettingsRepository();
        self::$dbOverrides = [];
        foreach (ManagedSettings::configKeys() as $key) {
            if ($repo->exists($key)) {
                self::$dbOverrides[$key] = (string) ($repo->get($key) ?? '');
            }
        }
    }

    /** Env/default value only (ignores admin DB overrides). */
    public static function raw(string $key, mixed $default = null): mixed
    {
        return self::$values[$key] ?? $default;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$dbOverrides)) {
            return self::$dbOverrides[$key];
        }

        return self::$values[$key] ?? $default;
    }

    public static function setOverride(string $key, ?string $value): void
    {
        if ($value === null) {
            unset(self::$dbOverrides[$key]);

            return;
        }
        self::$dbOverrides[$key] = $value;
    }

    public static function baseUrl(): string
    {
        return rtrim((string) self::get('app_base_url'), '/');
    }

    public static function tempDir(): string
    {
        return rtrim((string) self::get('temp_dir'), '/');
    }

    private static function loadEnvFile(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"'))
                || (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }
            if ($name !== '' && getenv($name) === false) {
                putenv($name . '=' . $value);
                $_ENV[$name] = $value;
            }
        }
    }
}
