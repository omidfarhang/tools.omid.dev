<?php

declare(strict_types=1);

namespace App\Download;

final class TikTokSource implements DownloadSource
{
    private const ALLOWED_HEIGHTS = ['720'];

    public function id(): string
    {
        return 'tiktok';
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
            return ['ok' => false, 'error' => 'Not a valid TikTok URL.'];
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

        // Short links — pass through with https; yt-dlp resolves them.
        if (preg_match('#^(?:https?://)?(?:vm|vt)\.tiktok\.com/([A-Za-z0-9_-]+)/?#i', $url, $matches)) {
            $host = stripos($url, 'vt.tiktok.com') !== false ? 'vt.tiktok.com' : 'vm.tiktok.com';

            return 'https://' . $host . '/' . $matches[1] . '/';
        }

        if (preg_match(
            '#^(?:https?://)?(?:www\.|m\.)?tiktok\.com/@([^/\s?\#]+)/video/(\d+)#i',
            $url,
            $matches
        )) {
            return 'https://www.tiktok.com/@' . $matches[1] . '/video/' . $matches[2];
        }

        return null;
    }
}
