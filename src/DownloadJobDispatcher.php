<?php

declare(strict_types=1);

namespace App;

final class DownloadJobDispatcher
{
    /** @var array<int, true> */
    private static array $queued = [];

    public static function dispatch(int $jobId): void
    {
        if ($jobId < 1 || isset(self::$queued[$jobId])) {
            return;
        }
        self::$queued[$jobId] = true;

        // Process after the HTTP response is sent. Spawning a background CLI via exec()
        // is unreliable under Apache (child often dies with the request).
        register_shutdown_function(static function () use ($jobId): void {
            ignore_user_abort(true);
            @set_time_limit(0);

            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            } else {
                while (ob_get_level() > 0) {
                    @ob_end_flush();
                }
                @flush();
            }

            try {
                (new DownloadJobProcessor())->process($jobId);
            } catch (\Throwable) {
                // Leave status for a later page/API retry.
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
