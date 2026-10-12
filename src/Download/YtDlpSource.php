<?php

declare(strict_types=1);

namespace App\Download;

final class YtDlpSource implements DownloadSource
{
    private const ALLOWED_HEIGHTS = ['4320', '2160', '1440', '1080', '720', '480', '360', '240'];

    public function id(): string
    {
        return 'ytdlp';
    }

    public function supports(string $url): bool
    {
        // Never auto-matched; selected only via force_ytdlp.
        return false;
    }

    public function requiresLocalFile(): bool
    {
        return true;
    }

    public function download(string $url, array $options = []): array
    {
        $url = trim($url);
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
            return ['ok' => false, 'error' => 'Not a valid URL for yt-dlp.'];
        }

        $ytFormat = (string) ($options['yt_format'] ?? 'best');

        return YtDlpRunner::download(
            $url,
            YtDlpRunner::formatArgs($ytFormat, self::ALLOWED_HEIGHTS)
        );
    }
}
