<?php

declare(strict_types=1);

namespace App\Download;

use App\Config;

final class FilenameHelper
{
    private const MAX_STEM_LEN = 100;

    public static function sanitize(string $name): string
    {
        $name = basename($name);

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
            if ($converted !== false) {
                $name = $converted;
            }
        }

        $name = preg_replace('/[^A-Za-z0-9.\- _()\[\]]+/', '_', $name) ?? 'file.bin';
        $name = preg_replace('/[_\s]{2,}/', '_', $name) ?? $name;
        $name = trim($name, '. _');
        if ($name === '') {
            $name = 'file.bin';
        }

        $pi = pathinfo($name);
        $stem = $pi['filename'] ?? $name;
        $ext = isset($pi['extension']) && $pi['extension'] !== '' ? '.' . $pi['extension'] : '';
        if (strlen($stem) > self::MAX_STEM_LEN) {
            $stem = rtrim(substr($stem, 0, self::MAX_STEM_LEN), '. _');
            if ($stem === '') {
                $stem = 'file';
            }
        }

        return $stem . $ext;
    }

    public static function uniqueStoredName(string $original): string
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
