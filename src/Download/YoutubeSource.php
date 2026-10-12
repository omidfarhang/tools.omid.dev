<?php

declare(strict_types=1);

namespace App\Download;

final class YoutubeSource implements DownloadSource
{
    private const ALLOWED_HEIGHTS = ['4320', '2160', '1440', '1080', '720', '480', '360', '240'];

    public function id(): string
    {
        return 'youtube';
    }

    public function supports(string $url): bool
    {
        return $this->extractVideoId($url) !== false;
    }

    public function requiresLocalFile(): bool
    {
        return true;
    }

    public function download(string $url, array $options = []): array
    {
        $videoId = $this->extractVideoId($url);
        if ($videoId === false) {
            return ['ok' => false, 'error' => 'Not a valid YouTube URL.'];
        }

        $ytFormat = (string) ($options['yt_format'] ?? 'best');

        return YtDlpRunner::download(
            'https://www.youtube.com/watch?v=' . $videoId,
            YtDlpRunner::formatArgs($ytFormat, self::ALLOWED_HEIGHTS)
        );
    }

    public function extractVideoId(string $url): string|false
    {
        if (!preg_match(
            '#^(?:https?://)?(?:www\.)?(?:m\.)?(?:youtu\.be/|youtube\.com/(?:(?:watch)?\?(?:.*&)?v(?:i)?=|(?:embed|v|vi|user|shorts)/))([^?&"\'>]+)#i',
            $url,
            $matches
        )) {
            return false;
        }

        return $matches[1] !== '' ? $matches[1] : false;
    }
}
