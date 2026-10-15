<?php

declare(strict_types=1);

namespace App;

/**
 * Site settings managed in Admin (SQLite). Cloud credentials are DB-only.
 */
final class ManagedSettings
{
    public const DROPBOX_APP_KEY = 'dropbox_app_key';
    public const DROPBOX_APP_SECRET = 'dropbox_app_secret';
    public const GOOGLE_DRIVE_CLIENT_ID = 'google_drive_client_id';
    public const GOOGLE_DRIVE_CLIENT_SECRET = 'google_drive_client_secret';
    public const YTDLP_EXTRA_ARGS = 'ytdlp_extra_args';
    /** NULL / missing = unlimited for new users without a per-user override. Stored as decimal byte string. */
    public const DEFAULT_USER_QUOTA_BYTES = 'default_user_quota_bytes';
    /** Failed login/register attempts before lock (per IP or username). */
    public const LOGIN_MAX_ATTEMPTS = 'login_max_attempts';
    /** Sliding window in minutes for counting failures. */
    public const LOGIN_WINDOW_MINUTES = 'login_window_minutes';

    /** @return list<string> */
    public static function configKeys(): array
    {
        return [
            self::DROPBOX_APP_KEY,
            self::DROPBOX_APP_SECRET,
            self::GOOGLE_DRIVE_CLIENT_ID,
            self::GOOGLE_DRIVE_CLIENT_SECRET,
            self::YTDLP_EXTRA_ARGS,
            self::DEFAULT_USER_QUOTA_BYTES,
            self::LOGIN_MAX_ATTEMPTS,
            self::LOGIN_WINDOW_MINUTES,
        ];
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
