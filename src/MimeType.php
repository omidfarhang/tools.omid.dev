<?php

declare(strict_types=1);

namespace App;

/**
 * MIME detection and preview allowlist for in-browser file preview.
 */
final class MimeType
{
    public const TEXT_PREVIEW_MAX_BYTES = 2_097_152; // 2 MiB

    /** @var array<string, string> */
    private const EXTENSION_MAP = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'pdf' => 'application/pdf',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'ogv' => 'video/ogg',
        'mp3' => 'audio/mpeg',
        'm4a' => 'audio/mp4',
        'ogg' => 'audio/ogg',
        'oga' => 'audio/ogg',
        'wav' => 'audio/wav',
        'aac' => 'audio/aac',
        'txt' => 'text/plain',
        'log' => 'text/plain',
        'csv' => 'text/csv',
        'json' => 'application/json',
        'xml' => 'application/xml',
        'md' => 'text/plain',
        'nfo' => 'text/plain',
    ];

    /** @var array<string, 'image'|'pdf'|'video'|'audio'|'text'> */
    private const PREVIEW_KINDS = [
        'image/jpeg' => 'image',
        'image/png' => 'image',
        'image/gif' => 'image',
        'image/webp' => 'image',
        'application/pdf' => 'pdf',
        'video/mp4' => 'video',
        'video/webm' => 'video',
        'video/ogg' => 'video',
        'audio/mpeg' => 'audio',
        'audio/mp4' => 'audio',
        'audio/ogg' => 'audio',
        'audio/wav' => 'audio',
        'audio/x-wav' => 'audio',
        'audio/webm' => 'audio',
        'audio/aac' => 'audio',
        'text/plain' => 'text',
        'text/csv' => 'text',
        'application/json' => 'text',
        'application/xml' => 'text',
        'text/xml' => 'text',
    ];

    public static function detect(string $path, string $originalName): string
    {
        $mime = '';
        if (function_exists('mime_content_type') && is_file($path)) {
            $detected = @mime_content_type($path);
            if (is_string($detected) && $detected !== '') {
                $mime = strtolower(trim(explode(';', $detected, 2)[0]));
            }
        }

        if ($mime === '' || $mime === 'application/octet-stream' || $mime === 'inode/x-empty') {
            $fromName = self::fromExtension($originalName);
            if ($fromName !== null) {
                return $fromName;
            }
        }

        return $mime !== '' ? $mime : 'application/octet-stream';
    }

    public static function fromExtension(string $filename): ?string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext === '' || !isset(self::EXTENSION_MAP[$ext])) {
            return null;
        }

        return self::EXTENSION_MAP[$ext];
    }

    public static function isPreviewable(string $mime): bool
    {
        return self::previewKind($mime) !== null;
    }

    /**
     * Guess preview kind from filename alone (for list UI without opening the file).
     *
     * @return 'image'|'pdf'|'video'|'audio'|'text'|null
     */
    public static function previewKindFromName(string $filename): ?string
    {
        $mime = self::fromExtension($filename);
        if ($mime === null) {
            return null;
        }

        return self::previewKind($mime);
    }

    /**
     * @return 'image'|'pdf'|'video'|'audio'|'text'|null
     */
    public static function previewKind(string $mime): ?string
    {
        $mime = strtolower(trim(explode(';', $mime, 2)[0]));

        return self::PREVIEW_KINDS[$mime] ?? null;
    }

    public static function isTextPreviewAllowed(string $mime, int $sizeBytes): bool
    {
        return self::previewKind($mime) === 'text' && $sizeBytes <= self::TEXT_PREVIEW_MAX_BYTES;
    }
}
