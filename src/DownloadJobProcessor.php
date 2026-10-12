<?php

declare(strict_types=1);

namespace App;

use App\Cloud\CloudSendService;
use App\Download\DownloadRegistry;

final class DownloadJobProcessor
{
    private DownloadJobRepository $jobs;
    private FileRepository $files;
    private DownloadRegistry $downloads;
    private CloudSendService $cloud;
    private ShareService $shares;
    private UserRepository $users;

    public function __construct(
        ?DownloadJobRepository $jobs = null,
        ?FileRepository $files = null,
        ?DownloadRegistry $downloads = null,
        ?CloudSendService $cloud = null,
        ?ShareService $shares = null,
        ?UserRepository $users = null,
    ) {
        $this->jobs = $jobs ?? new DownloadJobRepository();
        $this->files = $files ?? new FileRepository();
        $this->downloads = $downloads ?? DownloadRegistry::default();
        $this->cloud = $cloud ?? CloudSendService::create();
        $this->shares = $shares ?? new ShareService();
        $this->users = $users ?? new UserRepository();
    }

    public function process(int $jobId): void
    {
        $job = $this->jobs->findById($jobId);
        if ($job === null) {
            return;
        }

        $status = (string) ($job['status'] ?? '');
        if ($status === 'complete' || $status === 'failed') {
            return;
        }

        if ($status === 'pending' && !$this->jobs->claim($jobId)) {
            return;
        }

        $job = $this->jobs->findById($jobId);
        if ($job === null) {
            return;
        }

        $user = $this->users->findById((int) $job['user_id']);
        if ($user === null || (int) $user['is_active'] !== 1) {
            $this->jobs->markFailed($jobId, 'User not found or inactive.');
            return;
        }

        $url = (string) $job['source_url'];
        $filename = trim((string) ($job['requested_filename'] ?? ''));
        $behavior = UserRepository::normalizeBehavior((string) $job['behavior']);
        $ytFormat = (string) ($job['yt_format'] ?? 'best');
        $folder = UserRepository::normalizeFolder((string) ($job['cloud_folder'] ?? 'Tools Center'));
        $conflict = UserRepository::normalizeConflict((string) ($job['cloud_conflict'] ?? 'rename'));
        $deleteAfter = (int) ($job['delete_after'] ?? 0) === 1;

        $source = $this->downloads->resolve($url);
        if ($source === null) {
            $this->jobs->markFailed($jobId, 'No download source supports this URL.');
            return;
        }

        $needsCloud = $behavior === 'save_to_cloud' || $behavior === 'save_to_both';
        $cloudConnected = $this->cloud->registry()->anyConnected($user);
        if ($needsCloud && !$cloudConnected) {
            $this->jobs->markFailed($jobId, 'Connect a cloud account first (Account page).');
            return;
        }

        $downloadResult = null;
        $fileId = null;
        $cloudJobId = null;
        $resultFilename = $filename !== '' ? $filename : null;

        $needsDisk = $behavior === 'save_to_disk'
            || $behavior === 'save_to_both'
            || ($behavior === 'save_to_cloud' && $source->requiresLocalFile());

        try {
            if ($needsDisk) {
                $downloadResult = $this->downloads->download($url, [
                    'filename' => $filename !== '' ? $filename : null,
                    'yt_format' => $ytFormat,
                ]);

                if (!$downloadResult['ok']) {
                    $this->jobs->markFailed($jobId, $downloadResult['error'] ?? 'Download failed.');
                    return;
                }

                $resultFilename = $downloadResult['original_name'] ?? $resultFilename;
                $fileId = $this->files->create(
                    (int) $user['id'],
                    $downloadResult['stored_name'],
                    $downloadResult['original_name'],
                    (int) $downloadResult['size'],
                    $url
                );
            }

            if ($needsCloud) {
                if ($source->requiresLocalFile() || $behavior === 'save_to_both') {
                    $token = $this->shares->enable((int) $fileId, (int) $user['id']);
                    $fetchUrl = ShareService::publicUrl($token);
                    $cloudFilename = $downloadResult['original_name'] ?? 'video.mp4';
                    $localFileId = (int) $fileId;
                } else {
                    $fetchUrl = $url;
                    $cloudFilename = $filename !== '' ? $filename : null;
                    $localFileId = null;
                    $deleteAfter = false;
                }

                $sent = $this->cloud->startForUser(
                    $user,
                    $fetchUrl,
                    $cloudFilename,
                    $folder,
                    $conflict,
                    $deleteAfter,
                    $localFileId,
                    'dropbox'
                );

                $cloudJobId = isset($sent['job_id']) ? (int) $sent['job_id'] : null;
                if ($resultFilename === null && isset($sent['filename'])) {
                    $resultFilename = (string) $sent['filename'];
                } elseif ($resultFilename === null && $cloudFilename !== null) {
                    $resultFilename = $cloudFilename;
                }

                if (!$sent['success']) {
                    $error = ($sent['message'] ?? 'Cloud upload failed')
                        . (isset($sent['error']) ? ' — ' . (string) $sent['error'] : '');
                    $this->jobs->markFailed($jobId, $error, $resultFilename, $fileId, $cloudJobId);
                    return;
                }
            }

            $this->jobs->markComplete($jobId, $resultFilename, $fileId, $cloudJobId);
        } catch (\Throwable $e) {
            $this->jobs->markFailed(
                $jobId,
                $e->getMessage() !== '' ? $e->getMessage() : 'Download job failed.',
                $resultFilename,
                $fileId,
                $cloudJobId
            );
        }
    }
}
