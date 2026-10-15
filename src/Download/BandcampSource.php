<?php

declare(strict_types=1);

namespace App\Download;

final class BandcampSource implements DownloadSource
{
    public function id(): string
    {
        return 'bandcamp';
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
            return ['ok' => false, 'error' => 'Not a valid Bandcamp URL.'];
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
            '#^(?:https?://)?([a-z0-9-]+)\.bandcamp\.com/(track|album)/([A-Za-z0-9_-]+)/?#i',
            $url,
            $matches
        )) {
            return null;
        }

        $sub = strtolower($matches[1]);
        if (in_array($sub, ['www', 'daily', 'fandom'], true)) {
            return null;
        }

        $kind = strtolower($matches[2]);

        return 'https://' . $sub . '.bandcamp.com/' . $kind . '/' . $matches[3];
    }
}
