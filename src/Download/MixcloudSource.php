<?php

declare(strict_types=1);

namespace App\Download;

final class MixcloudSource implements DownloadSource
{
    public function id(): string
    {
        return 'mixcloud';
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
            return ['ok' => false, 'error' => 'Not a valid Mixcloud URL.'];
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

        if (!preg_match(
            '#^(?:https?://)?(?:(?:www|m)\.)?mixcloud\.com/([^/\s?\#]+)/([^/\s?\#]+)/?#i',
            $url,
            $matches
        )) {
            return null;
        }

        $user = strtolower($matches[1]);
        $reserved = [
            'discover', 'upload', 'competitions', 'about', 'jobs', 'developers',
            'login', 'signup', 'settings', 'messages', 'notifications', 'search',
        ];
        if (in_array($user, $reserved, true)) {
            return null;
        }

        return 'https://www.mixcloud.com/' . $matches[1] . '/' . $matches[2] . '/';
    }
}
