<?php

declare(strict_types=1);

namespace App;

final class DownloadService
{
    private const ALLOWED_HEIGHTS = ['4320', '2160', '1440', '1080', '720', '480', '360', '240'];

    /**
     * @return array{ok:bool, stored_name?:string, original_name?:string, size?:int, error?:string}
     */
    public function downloadUrl(string $url, ?string $filename = null): array
    {
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
            return ['ok' => false, 'error' => 'Only http(s) URLs are allowed.'];
        }

        $original = $filename !== null && $filename !== '' ? basename($filename) : basename(parse_url($url, PHP_URL_PATH) ?: 'download.bin');
        $original = $this->sanitizeFilename($original);
        $stored = $this->uniqueStoredName($original);
        $dest = Config::tempDir() . '/' . $stored;

        if (!is_dir(Config::tempDir())) {
            mkdir(Config::tempDir(), 0775, true);
        }

        $fp = fopen($dest, 'wb');
        if ($fp === false) {
            return ['ok' => false, 'error' => 'Cannot write to temp storage.'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 600,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'tools.omid.dev/1.0',
        ]);
        $ok = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if ($ok === false || $code >= 400) {
            @unlink($dest);

            return ['ok' => false, 'error' => $err !== '' ? $err : ('HTTP ' . $code)];
        }

        return [
            'ok' => true,
            'stored_name' => $stored,
            'original_name' => $original,
            'size' => (int) filesize($dest),
        ];
    }

    /**
     * @return array{ok:bool, stored_name?:string, original_name?:string, size?:int, error?:string}
     */
    public function downloadYoutube(string $url, string $ytFormat = 'best'): array
    {
        $videoId = is_youtube($url);
        if ($videoId === false) {
            return ['ok' => false, 'error' => 'Not a valid YouTube URL.'];
        }

        if (!is_dir(Config::tempDir())) {
            mkdir(Config::tempDir(), 0775, true);
        }

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
        // Normalize to a safe unique name if needed
        $safe = $this->sanitizeFilename($stored);
        if ($safe !== $stored) {
            $newPath = $base . DIRECTORY_SEPARATOR . $this->uniqueStoredName($safe);
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

    /** @return list<string> */
    private function formatArgs(string $ytFormat): array
    {
        if ($ytFormat === 'best' || !in_array($ytFormat, self::ALLOWED_HEIGHTS, true)) {
            return ['-f', 'bestvideo*+bestaudio/best'];
        }

        return ['-f', 'bestvideo*[height<=' . $ytFormat . ']+bestaudio/best'];
    }

    private function sanitizeFilename(string $name): string
    {
        $name = basename($name);
        $name = preg_replace('/[^\w.\- ()\[\]]+/u', '_', $name) ?? 'file.bin';
        $name = trim($name, '. ');
        if ($name === '') {
            $name = 'file.bin';
        }

        return $name;
    }

    private function uniqueStoredName(string $original): string
    {
        $dir = Config::tempDir();
        $candidate = $original;
        $i = 1;
        while (file_exists($dir . '/' . $candidate)) {
            $pi = pathinfo($original);
            $base = $pi['filename'] ?? 'file';
            $ext = isset($pi['extension']) ? '.' . $pi['extension'] : '';
            $candidate = $base . '_' . $i . $ext;
            $i++;
        }

        return $candidate;
    }
}
