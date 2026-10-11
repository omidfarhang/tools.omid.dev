<?php

declare(strict_types=1);

namespace App\Download;

final class DownloadRegistry
{
    /** @param list<DownloadSource> $sources */
    public function __construct(private array $sources)
    {
    }

    public static function default(): self
    {
        return new self([
            new YoutubeSource(),
            new HttpSource(),
        ]);
    }

    public function resolve(string $url): ?DownloadSource
    {
        foreach ($this->sources as $source) {
            if ($source->supports($url)) {
                return $source;
            }
        }

        return null;
    }

    /**
     * @param array{filename?:?string, yt_format?:string} $options
     * @return array{ok:bool, stored_name?:string, original_name?:string, size?:int, error?:string, source?:string}
     */
    public function download(string $url, array $options = []): array
    {
        $source = $this->resolve($url);
        if ($source === null) {
            return ['ok' => false, 'error' => 'No download source supports this URL.'];
        }

        $result = $source->download($url, $options);
        if ($result['ok']) {
            $result['source'] = $source->id();
        }

        return $result;
    }
}
