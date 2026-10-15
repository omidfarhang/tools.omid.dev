<?php

declare(strict_types=1);

namespace App\Download;

final class SoundCloudSource implements DownloadSource
{
    public function id(): string
    {
        return 'soundcloud';
    }

    public function supports(string $url): bool
    {
        return $this->normalizeUrl($url) !== null;
    }

    public function requiresLocalFile(): bool
    {
        return true;
    }

    public function mediaKind(): string
    {
        return 'audio';
    }

    public function download(string $url, array $options = []): array
    {
        $normalized = $this->normalizeUrl($url);
        if ($normalized === null) {
            return ['ok' => false, 'error' => 'Not a valid SoundCloud URL.'];
        }

        $format = (string) ($options['yt_format'] ?? 'best');

        return YtDlpRunner::download($normalized, YtDlpRunner::audioFormatArgs($format));
    }

    public function normalizeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if (preg_match('#^(?:https?://)?on\.soundcloud\.com/([A-Za-z0-9_-]+)/?#i', $url, $matches)) {
            return 'https://on.soundcloud.com/' . $matches[1];
        }

        if (!preg_match('#^(?:https?://)?(?:(?:www|m)\.)?soundcloud\.com/(.+)$#i', $url, $matches)) {
            return null;
        }

        $path = strtok($matches[1], '?#');
        if ($path === false) {
            return null;
        }
        $path = trim($path, '/');
        if ($path === '' || !str_contains($path, '/')) {
            return null;
        }

        $first = strtolower(explode('/', $path, 2)[0]);
        $reserved = [
            'discover', 'stream', 'you', 'search', 'charts', 'pages', 'settings',
            'messages', 'notifications', 'upload', 'signin', 'login', 'about',
            'jobs', 'developers', 'legal', 'cookies', 'privacy', 'terms',
        ];
        if (in_array($first, $reserved, true)) {
            return null;
        }

        return 'https://soundcloud.com/' . $path;
    }
}
