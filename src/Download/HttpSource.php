<?php

declare(strict_types=1);

namespace App\Download;

use App\Config;

final class HttpSource implements DownloadSource
{
    public function id(): string
    {
        return 'http';
    }

    public function supports(string $url): bool
    {
        return (bool) filter_var($url, FILTER_VALIDATE_URL) && (bool) preg_match('#^https?://#i', $url);
    }

    public function requiresLocalFile(): bool
    {
        return false;
    }

    public function download(string $url, array $options = []): array
    {
        if (!$this->supports($url)) {
            return ['ok' => false, 'error' => 'Only http(s) URLs are allowed.'];
        }

        $filename = $options['filename'] ?? null;
        $original = $filename !== null && $filename !== ''
            ? basename((string) $filename)
            : basename(parse_url($url, PHP_URL_PATH) ?: 'download.bin');
        $original = FilenameHelper::sanitize($original);
        $stored = FilenameHelper::uniqueStoredName($original);
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
}
