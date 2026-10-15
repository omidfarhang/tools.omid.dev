<?php

declare(strict_types=1);

namespace App\Download;

final class DownloadRegistry
{
    private YtDlpSource $ytdlp;

    /** @param list<DownloadSource> $sources */
    public function __construct(private array $sources, ?YtDlpSource $ytdlp = null)
    {
        $this->ytdlp = $ytdlp ?? new YtDlpSource();
    }

    public static function default(): self
    {
        return new self([
            new YoutubeSource(),
            new TwitterSource(),
            new InstagramSource(),
            new TikTokSource(),
            new SoundCloudSource(),
            new MixcloudSource(),
            new BandcampSource(),
            new VimeoSource(),
            new RedditSource(),
            new HttpSource(),
        ]);
    }

    /** Whether this source is a curated yt-dlp site (not plain HTTP / force-yt-dlp). */
    public static function isCurated(?DownloadSource $source): bool
    {
        if ($source === null) {
            return false;
        }
        $id = $source->id();

        return $id !== 'http' && $id !== 'ytdlp';
    }

    /**
     * Curated source id → media kind for the download form.
     *
     * @return array<string, 'video'|'audio'>
     */
    public function curatedKinds(): array
    {
        $kinds = [];
        foreach ($this->sources as $source) {
            if (!self::isCurated($source)) {
                continue;
            }
            $kind = $source->mediaKind();
            if ($kind === 'video' || $kind === 'audio') {
                $kinds[$source->id()] = $kind;
            }
        }

        return $kinds;
    }

    /**
     * @param array{force_ytdlp?:bool|int|string} $options
     */
    public function resolve(string $url, array $options = []): ?DownloadSource
    {
        if (!empty($options['force_ytdlp'])) {
            return $this->ytdlp;
        }

        foreach ($this->sources as $source) {
            if ($source->id() === 'ytdlp') {
                continue;
            }
            if ($source->supports($url)) {
                return $source;
            }
        }

        return null;
    }

    /**
     * @param array{filename?:?string, yt_format?:string, yt_audio_only?:bool|int|string, yt_subs?:?string, force_ytdlp?:bool|int|string} $options
     * @return array{ok:bool, stored_name?:string, original_name?:string, size?:int, error?:string, source?:string}
     */
    public function download(string $url, array $options = []): array
    {
        $source = $this->resolve($url, $options);
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
