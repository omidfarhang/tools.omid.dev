<?php

declare(strict_types=1);

namespace App\Download;

/**
 * Host suffixes for non-curated sites that ship a dedicated yt-dlp extractor.
 * Used to default-check "Use yt-dlp" on the download form (user may uncheck).
 *
 * Regenerated via scripts/generate-ytdlp-known-hosts.py
 */
final class YtDlpKnownHosts
{
    /** @var list<string>|null */
    private static ?array $hosts = null;

    /** @var array<string, true>|null */
    private static ?array $lookup = null;

    /**
     * @return list<string>
     */
    public static function hosts(): array
    {
        self::load();

        return self::$hosts ?? [];
    }

    public static function matches(string $url): bool
    {
        $host = self::hostFromUrl($url);
        if ($host === null) {
            return false;
        }

        self::load();
        if (self::$lookup === null) {
            return false;
        }

        $candidate = $host;
        while ($candidate !== '') {
            if (isset(self::$lookup[$candidate])) {
                return true;
            }
            $dot = strpos($candidate, '.');
            if ($dot === false) {
                return false;
            }
            $candidate = substr($candidate, $dot + 1);
        }

        return false;
    }

    public static function hostFromUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }
        $host = strtolower($host);
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return $host !== '' ? $host : null;
    }

    private static function load(): void
    {
        if (self::$hosts !== null) {
            return;
        }

        $path = __DIR__ . '/data/ytdlp-known-hosts.json';
        if (!is_readable($path)) {
            self::$hosts = [];
            self::$lookup = [];

            return;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            self::$hosts = [];
            self::$lookup = [];

            return;
        }

        $data = json_decode($raw, true);
        $list = is_array($data) && isset($data['hosts']) && is_array($data['hosts'])
            ? $data['hosts']
            : [];

        $hosts = [];
        $lookup = [];
        foreach ($list as $host) {
            if (!is_string($host)) {
                continue;
            }
            $host = strtolower(trim($host));
            if ($host === '' || str_contains($host, '/')) {
                continue;
            }
            $hosts[] = $host;
            $lookup[$host] = true;
        }

        self::$hosts = $hosts;
        self::$lookup = $lookup;
    }
}
