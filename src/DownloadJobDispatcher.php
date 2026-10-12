<?php

declare(strict_types=1);

namespace App;

final class DownloadJobDispatcher
{
    public static function dispatch(int $jobId): void
    {
        $script = dirname(__DIR__) . '/scripts/process_download_job.php';
        $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';

        if (is_file($script) && function_exists('exec')) {
            $cmd = sprintf(
                '%s %s %d > /dev/null 2>&1 &',
                escapeshellarg($php),
                escapeshellarg($script),
                $jobId
            );
            @exec($cmd);

            return;
        }

        // Fallback when background exec is unavailable: finish the HTTP response, then process.
        register_shutdown_function(static function () use ($jobId): void {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            try {
                (new DownloadJobProcessor())->process($jobId);
            } catch (\Throwable) {
                // Status remains pending/in_progress for a later retry via status poll.
            }
        });
    }

    public static function dispatchPending(int $limit = 5): void
    {
        $ids = (new DownloadJobRepository())->pendingIds($limit);
        foreach ($ids as $id) {
            self::dispatch($id);
        }
    }
}
