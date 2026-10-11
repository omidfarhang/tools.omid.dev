<?php

declare(strict_types=1);

namespace App\Download;

use App\Config;

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

        if (!is_dir(Config::tempDir())) {
            mkdir(Config::tempDir(), 0775, true);
        }

        $ytFormat = (string) ($options['yt_format'] ?? 'best');
        $template = Config::tempDir() . '/%(title).200B [%(id)s].%(ext)s';
        $formatArgs = $this->formatArgs($ytFormat);
        $cookiePath = (string) Config::get('ytdlp_cookies_path');

        $cmd = ['yt-dlp', ...$formatArgs, '-o', $template, '--no-playlist', '-q', '--print', 'after_move:filepath'];
        if (is_file($cookiePath)) {
            $cmd[] = '--cookies';
            $cmd[] = $cookiePath;
        }
        $cmd[] = 'https://www.youtube.com/watch?v=' . $videoId;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes, Config::tempDir());
        if (!is_resource($proc)) {
            return ['ok' => false, 'error' => 'Failed to start yt-dlp.'];
        }
        fclose($pipes[0]);
        $stdout = trim(stream_get_contents($pipes[1]) ?: '');
        $stderr = trim(stream_get_contents($pipes[2]) ?: '');
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        if ($code !== 0 || $stdout === '') {
            return ['ok' => false, 'error' => $stderr !== '' ? $stderr : 'yt-dlp failed.'];
        }

        $path = $stdout;
        $real = realpath($path);
        $base = realpath(Config::tempDir());
        if ($real === false || $base === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
            return ['ok' => false, 'error' => 'yt-dlp wrote outside temp directory.'];
        }

        $stored = basename($real);
        $safe = FilenameHelper::sanitize($stored);
        if ($safe !== $stored) {
            $newPath = $base . DIRECTORY_SEPARATOR . FilenameHelper::uniqueStoredName($safe);
            rename($real, $newPath);
            $real = $newPath;
            $stored = basename($real);
        }

        return [
            'ok' => true,
            'stored_name' => $stored,
            'original_name' => $stored,
            'size' => (int) filesize($real),
        ];
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

    /** @return list<string> */
    private function formatArgs(string $ytFormat): array
    {
        if ($ytFormat === 'best' || !in_array($ytFormat, self::ALLOWED_HEIGHTS, true)) {
            return ['-f', 'bestvideo*+bestaudio/best'];
        }

        return ['-f', 'bestvideo*[height<=' . $ytFormat . ']+bestaudio/best'];
    }
}
