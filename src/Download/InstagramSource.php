<?php

declare(strict_types=1);

namespace App\Download;

final class InstagramSource implements DownloadSource
{
    private const ALLOWED_HEIGHTS = ['720'];

    public function id(): string
    {
        return 'instagram';
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
        return 'video';
    }

    public function download(string $url, array $options = []): array
    {
        $normalized = $this->normalizeUrl($url);
        if ($normalized === null) {
            return ['ok' => false, 'error' => 'Not a valid Instagram URL.'];
        }

        $ytFormat = (string) ($options['yt_format'] ?? 'best');
        $audioOnly = !empty($options['yt_audio_only']);
        $ytSubs = isset($options['yt_subs']) ? (string) $options['yt_subs'] : null;

        return YtDlpRunner::download(
            $normalized,
            YtDlpRunner::formatArgs($ytFormat, self::ALLOWED_HEIGHTS, $audioOnly),
            YtDlpRunner::subtitleArgs($ytSubs)
        );
    }

    public function normalizeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if (!preg_match(
            '#^(?:https?://)?(?:www\.)?(?:instagram\.com|instagr\.am)/(p|reel|reels|tv)/([A-Za-z0-9_-]+)/?#i',
            $url,
            $matches
        )) {
            return null;
        }

        $kind = strtolower($matches[1]);
        if ($kind === 'reels') {
            $kind = 'reel';
        }

        return 'https://www.instagram.com/' . $kind . '/' . $matches[2] . '/';
    }
}
