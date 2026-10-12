<?php

declare(strict_types=1);

namespace App;

/**
 * Site-wide yt-dlp cookies (Netscape format), managed by admins.
 * Content lives in SQLite; a materialized file is kept for yt-dlp / CLI.
 */
final class YtDlpCookieStore
{
    public const SETTING_KEY = 'ytdlp_cookies';

    private const MAX_BYTES = 2_000_000;

    private SettingsRepository $settings;

    public function __construct(?SettingsRepository $settings = null)
    {
        $this->settings = $settings ?? new SettingsRepository();
    }

    public function has(): bool
    {
        $value = $this->settings->get(self::SETTING_KEY);

        return $value !== null && trim($value) !== '';
    }

    public function updatedAt(): ?string
    {
        if (!$this->has()) {
            return null;
        }

        return $this->settings->getUpdatedAt(self::SETTING_KEY);
    }

    public function sizeBytes(): int
    {
        $value = $this->settings->get(self::SETTING_KEY);

        return $value === null ? 0 : strlen($value);
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function save(string $content): void
    {
        $content = str_replace("\r\n", "\n", $content);
        $content = trim($content);
        if ($content === '') {
            throw new \InvalidArgumentException('Cookie file is empty.');
        }
        if (strlen($content) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Cookie file is too large (max 2 MB).');
        }
        if (!$this->looksLikeNetscapeCookies($content)) {
            throw new \InvalidArgumentException(
                'Expected Netscape cookie format (tab-separated lines, often starting with # Netscape).'
            );
        }

        $this->settings->set(self::SETTING_KEY, $content . "\n");
        $this->materialize();
    }

    public function clear(): void
    {
        $this->settings->delete(self::SETTING_KEY);
        $path = $this->materializedPath();
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Ensure the on-disk cookie file matches the DB and return its path, or null if unset.
     */
    public function cookieFilePath(): ?string
    {
        if (!$this->has()) {
            return null;
        }

        return $this->materialize();
    }

    public function materialize(): ?string
    {
        $content = $this->settings->get(self::SETTING_KEY);
        if ($content === null || trim($content) === '') {
            return null;
        }

        $path = $this->materializedPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $content) === false) {
            throw new \RuntimeException('Failed to write cookie file.');
        }
        @chmod($tmp, 0640);
        rename($tmp, $path);

        return $path;
    }

    /**
     * One-time import from the legacy env/file path into the database.
     */
    public function importLegacyFileIfNeeded(): void
    {
        if ($this->has()) {
            return;
        }

        $candidates = array_unique(array_filter([
            (string) Config::get('ytdlp_cookies_path'),
            rtrim((string) Config::get('root_dir'), '/') . '/.secure/cookies.txt',
        ]));

        foreach ($candidates as $legacy) {
            if ($legacy === '' || !is_file($legacy) || !is_readable($legacy)) {
                continue;
            }
            $content = file_get_contents($legacy);
            if ($content === false || trim($content) === '') {
                continue;
            }
            try {
                $this->save($content);

                return;
            } catch (\InvalidArgumentException) {
                // Try the next candidate.
            }
        }
    }

    public function materializedPath(): string
    {
        $configured = (string) Config::get('ytdlp_cookies_path');
        if ($configured !== '') {
            return $configured;
        }

        return Config::tempDir() . '/../ytdlp-cookies.txt';
    }

    private function looksLikeNetscapeCookies(string $content): bool
    {
        if (str_contains($content, '# Netscape') || str_contains($content, '# HTTP Cookie File')) {
            return true;
        }

        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            // domain \t flag \t path \t secure \t expiry \t name \t value
            if (substr_count($line, "\t") >= 6) {
                return true;
            }
        }

        return false;
    }
}
