<?php

declare(strict_types=1);

namespace App;

final class ClientIp
{
    /**
     * Client address for rate limiting / auth logs.
     * Prefers leftmost X-Forwarded-For hop when present (trusted reverse proxy).
     */
    public static function resolve(): string
    {
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if (is_string($forwarded) && $forwarded !== '') {
            $parts = explode(',', $forwarded);
            $first = trim($parts[0]);
            if ($first !== '' && filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }

        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        if (is_string($remote) && $remote !== '' && filter_var($remote, FILTER_VALIDATE_IP)) {
            return $remote;
        }

        return '0.0.0.0';
    }
}
