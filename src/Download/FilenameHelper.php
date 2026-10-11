<?php

declare(strict_types=1);

namespace App\Download;

use App\Config;

final class FilenameHelper
{
    public static function sanitize(string $name): string
    {
        $name = basename($name);
        $name = preg_replace('/[^\w.\- ()\[\]]+/u', '_', $name) ?? 'file.bin';
        $name = trim($name, '. ');
        if ($name === '') {
            $name = 'file.bin';
        }

        return $name;
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
