<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$jobId = isset($argv[1]) ? (int) $argv[1] : 0;
if ($jobId < 1) {
    fwrite(STDERR, "Usage: php scripts/process_download_job.php <job_id>\n");
    exit(1);
}

require dirname(__DIR__) . '/src/bootstrap.php';

use App\DownloadJobProcessor;

(new DownloadJobProcessor())->process($jobId);
