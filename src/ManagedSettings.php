<?php

declare(strict_types=1);

namespace App;

/**
 * Site settings managed in Admin (SQLite), with env as bootstrap / legacy fallback.
 */
final class ManagedSettings
{
    public const DROPBOX_APP_KEY = 'dropbox_app_key';
    public const DROPBOX_APP_SECRET = 'dropbox_app_secret';
    public const YTDLP_EXTRA_ARGS = 'ytdlp_extra_args';

    /** @return list<string> */
    public static function configKeys(): array
    {
        return [
            self::DROPBOX_APP_KEY,
            self::DROPBOX_APP_SECRET,
            self::YTDLP_EXTRA_ARGS,
        ];
    }

    /**
     * Copy Dropbox credentials from env into SQLite once if not already stored.
     */
    public static function importFromEnvIfMissing(SettingsRepository $repo): void
    {
        foreach ([self::DROPBOX_APP_KEY, self::DROPBOX_APP_SECRET] as $key) {
            if ($repo->exists($key)) {
                continue;
            }
            $value = (string) Config::raw($key, '');
            if ($value !== '') {
                $repo->set($key, $value);
            }
        }
    }

    public static function maskSecret(string $value): string
    {
        $len = strlen($value);
        if ($len === 0) {
            return '';
        }
        if ($len <= 4) {
            return str_repeat('•', $len);
        }

        return str_repeat('•', $len - 4) . substr($value, -4);
    }

    /**
     * Split admin-supplied yt-dlp extra args into argv tokens (no shell).
     *
     * @return list<string>
     * @throws \InvalidArgumentException
     */
    public static function parseExtraArgs(string $raw): array
    {
        $raw = trim(str_replace("\r\n", "\n", $raw));
        if ($raw === '') {
            return [];
        }
        if (preg_match('/[;&|`$<>\\\\]/', $raw) === 1) {
            throw new \InvalidArgumentException(
                'Extra args cannot contain shell metacharacters (; & | ` $ < > \\).'
            );
        }

        $parts = preg_split('/\s+/', $raw);
        if ($parts === false) {
            return [];
        }

        $args = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $args[] = $part;
        }

        return $args;
    }
}
