<?php

declare(strict_types=1);

namespace App\Download;

use App\Config;
use App\ManagedSettings;
use App\YtDlpCookieStore;

final class YtDlpRunner
{
    /**
     * @param list<string> $formatArgs e.g. ['-f', 'bestvideo*+bestaudio/best']
     * @param list<string> $extraArgs optional extra yt-dlp flags (not including URL)
     * @return array{ok:bool, stored_name?:string, original_name?:string, size?:int, error?:string}
     */
    public static function download(string $url, array $formatArgs = [], array $extraArgs = []): array
    {
        if (!is_dir(Config::tempDir())) {
            mkdir(Config::tempDir(), 0775, true);
        }

        $template = Config::tempDir() . '/%(title).60B [%(id)s].%(ext)s';
        $cookiePath = (new YtDlpCookieStore())->cookieFilePath();
        try {
            $siteExtra = ManagedSettings::parseExtraArgs((string) Config::get('ytdlp_extra_args', ''));
        } catch (\InvalidArgumentException) {
            $siteExtra = [];
        }

        $cmd = ['yt-dlp', ...$formatArgs, ...$siteExtra, ...$extraArgs, '-o', $template, '--restrict-filenames', '--no-playlist', '-q', '--print', 'after_move:filepath'];
        if ($cookiePath !== null && is_file($cookiePath)) {
            $cmd[] = '--cookies';
            $cmd[] = $cookiePath;
        }
        $cmd[] = $url;

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

    /** @var list<string> */
    public const AUDIO_FORMATS = ['best', 'm4a', 'mp3', 'opus', 'flac'];

    /**
     * Build -f args from a quality token (best or a pixel height).
     * When $audioOnly, prefer bestaudio and extract to a common container.
     *
     * @param list<string> $allowedHeights
     * @return list<string>
     */
    public static function formatArgs(string $quality, array $allowedHeights, bool $audioOnly = false): array
    {
        if ($audioOnly) {
            return self::audioFormatArgs('m4a');
        }

        if ($quality === 'best' || !in_array($quality, $allowedHeights, true)) {
            return ['-f', 'bestvideo*+bestaudio/best'];
        }

        return ['-f', 'bestvideo*[height<=' . $quality . ']+bestaudio/best'];
    }

    /**
     * Format args for audio-primary sites (SoundCloud, Mixcloud, …).
     * "best" keeps the downloaded audio stream; other values re-encode via -x.
     *
     * @return list<string>
     */
    public static function audioFormatArgs(string $format): array
    {
        $format = in_array($format, self::AUDIO_FORMATS, true) ? $format : 'best';
        if ($format === 'best') {
            return ['-f', 'ba/bestaudio/best'];
        }

        return ['-f', 'ba/bestaudio/best', '-x', '--audio-format', $format];
    }

    /**
     * Subtitle flags so embeds land in the media file when the container supports it.
     *
     * @return list<string>
     */
    public static function subtitleArgs(?string $subs): array
    {
        $subs = $subs !== null && $subs !== '' ? $subs : 'none';
        if ($subs === 'none') {
            return [];
        }

        if ($subs === 'en') {
            return ['--write-subs', '--embed-subs', '--sub-langs', 'en'];
        }

        if ($subs === 'all') {
            return ['--write-subs', '--embed-subs', '--sub-langs', 'all'];
        }

        return [];
    }
}
