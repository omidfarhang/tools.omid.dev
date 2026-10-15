<?php

declare(strict_types=1);

namespace App;

final class AuthLog
{
    /** Rotate when the active log reaches this size (bytes). */
    private const MAX_BYTES = 5_242_880; // 5 MiB

    /**
     * Append a fail2ban-parseable auth line.
     * Format (stable): `{iso8601} tools-auth {FAILURE|LOCKOUT} ip={ip} user={user} action={login|register}`
     */
    public static function write(string $event, string $ip, string $username, string $action): void
    {
        if ($event !== 'FAILURE' && $event !== 'LOCKOUT') {
            return;
        }
        if ($action !== 'login' && $action !== 'register') {
            return;
        }

        $path = self::path();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        self::rotateIfNeeded($path);

        $user = self::sanitizeUser($username);
        $ipSafe = preg_replace('/[^0-9a-fA-F:.]/', '', $ip) ?: '0.0.0.0';
        $line = sprintf(
            "%s tools-auth %s ip=%s user=%s action=%s\n",
            gmdate('c'),
            $event,
            $ipSafe,
            $user,
            $action
        );

        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }

    public static function path(): string
    {
        return rtrim((string) Config::get('root_dir'), '/') . '/storage/logs/auth.log';
    }

    /**
     * Keep at most ~10 MiB: active auth.log + one rotated auth.log.1.
     * fail2ban should watch auth.log (new lines after rotate continue there).
     */
    private static function rotateIfNeeded(string $path): void
    {
        if (!is_file($path)) {
            return;
        }
        $size = @filesize($path);
        if ($size === false || $size < self::MAX_BYTES) {
            return;
        }

        $rotated = $path . '.1';
        @unlink($rotated);
        @rename($path, $rotated);
    }

    private static function sanitizeUser(string $username): string
    {
        $username = trim($username);
        if ($username === '' || !preg_match('/^[a-zA-Z0-9._-]{1,32}$/', $username)) {
            return '-';
        }

        return $username;
    }
}
