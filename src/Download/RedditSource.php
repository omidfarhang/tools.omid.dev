<?php

declare(strict_types=1);

namespace App\Download;

final class RedditSource implements DownloadSource
{
    private const ALLOWED_HEIGHTS = ['1080', '720', '480'];

    public function id(): string
    {
        return 'reddit';
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
            return ['ok' => false, 'error' => 'Not a valid Reddit URL.'];
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

        if (preg_match('#^(?:https?://)?v\.redd\.it/([A-Za-z0-9]+)/?#i', $url, $matches)) {
            return 'https://v.redd.it/' . $matches[1];
        }

        if (preg_match('#^(?:https?://)?(?:www\.)?redd\.it/([A-Za-z0-9]+)/?#i', $url, $matches)) {
            return 'https://redd.it/' . $matches[1];
        }

        if (preg_match(
            '#^(?:https?://)?(?:(?:www|old|new|np|m)\.)?reddit\.com/(r/[^/\s?]+/comments/[A-Za-z0-9]+(?:/[^/\s?]*)?)#i',
            $url,
            $matches
        )) {
            return 'https://www.reddit.com/' . rtrim($matches[1], '/') . '/';
        }

        return null;
    }
}
