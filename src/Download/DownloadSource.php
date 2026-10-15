<?php

declare(strict_types=1);

namespace App\Download;

interface DownloadSource
{
    public function id(): string;

    public function supports(string $url): bool;

    /** Whether this source must land on host disk before a cloud-only send. */
    public function requiresLocalFile(): bool;

    /**
     * Primary media type for UI options.
     * - video: height quality + optional audio-only / subtitles
     * - audio: audio format only (podcasts, SoundCloud, …)
     * - file: direct HTTP(S) download
     *
     * @return 'video'|'audio'|'file'
     */
    public function mediaKind(): string;

    /**
     * @param array{filename?:?string, yt_format?:string, yt_audio_only?:bool|int|string, yt_subs?:?string, force_ytdlp?:bool|int|string} $options
     * @return array{ok:bool, stored_name?:string, original_name?:string, size?:int, error?:string}
     */
    public function download(string $url, array $options = []): array;
}
