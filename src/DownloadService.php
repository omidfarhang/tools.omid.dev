<?php

declare(strict_types=1);

namespace App;

use App\Download\DownloadRegistry;

/**
 * Thin facade over DownloadRegistry for callers that do not need source details.
 */
final class DownloadService
{
    public function __construct(private ?DownloadRegistry $registry = null)
    {
        $this->registry = $registry ?? DownloadRegistry::default();
    }

    /**
     * @param array{filename?:?string, yt_format?:string} $options
     * @return array{ok:bool, stored_name?:string, original_name?:string, size?:int, error?:string, source?:string}
     */
    public function download(string $url, array $options = []): array
    {
        return $this->registry->download($url, $options);
    }

    public function registry(): DownloadRegistry
    {
        return $this->registry;
    }
}
