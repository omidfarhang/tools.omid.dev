<?php

declare(strict_types=1);

namespace App\Download;

final class VimeoSource implements DownloadSource
{
    private const ALLOWED_HEIGHTS = ['2160', '1080', '720', '360'];

    public function id(): string
    {
        return 'vimeo';
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
            return ['ok' => false, 'error' => 'Not a valid Vimeo URL.'];
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

        if (preg_match('#^(?:https?://)?(?:www\.)?vimeo\.com/(?:channels/[^/\s?]+|groups/[^/\s?]+/videos)/(\d+)#i', $url, $matches)) {
            return 'https://vimeo.com/' . $matches[1];
        }

        if (preg_match('#^(?:https?://)?(?:(?:www|player)\.)?vimeo\.com/(?:video/)?(\d+)#i', $url, $matches)) {
            return 'https://vimeo.com/' . $matches[1];
        }

        return null;
    }
}
