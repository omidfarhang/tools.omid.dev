<?php

declare(strict_types=1);

namespace App\Download;

final class TwitterSource implements DownloadSource
{
    private const ALLOWED_HEIGHTS = ['720', '480'];

    public function id(): string
    {
        return 'twitter';
    }

    public function supports(string $url): bool
    {
        return $this->normalizeUrl($url) !== null;
    }

    public function requiresLocalFile(): bool
    {
        return true;
    }

    public function download(string $url, array $options = []): array
    {
        $normalized = $this->normalizeUrl($url);
        if ($normalized === null) {
            return ['ok' => false, 'error' => 'Not a valid X/Twitter URL.'];
        }

        $ytFormat = (string) ($options['yt_format'] ?? 'best');

        return YtDlpRunner::download(
            $normalized,
            YtDlpRunner::formatArgs($ytFormat, self::ALLOWED_HEIGHTS)
        );
    }

    public function normalizeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if (!preg_match(
            '#^(?:https?://)?(?:www\.|mobile\.)?(?:twitter\.com|x\.com)/([^/\s?\#]+)/status/(\d+)#i',
            $url,
            $matches
        )) {
            return null;
        }

        return 'https://x.com/' . $matches[1] . '/status/' . $matches[2];
    }
}
