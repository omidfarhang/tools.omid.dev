<?php

declare(strict_types=1);

namespace App;

use App\Cloud\CloudJobRepository;
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
        if (in_array($status, ['complete', 'failed', 'cancelled'], true)) {
            return;
        }

        if ($status === 'pending' && !$this->jobs->claim($jobId)) {
            return;
        }

        $job = $this->jobs->findById($jobId);
        if ($job === null || $this->jobs->isCancelled($jobId)) {
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
        $ytAudioOnly = (int) ($job['yt_audio_only'] ?? 0) === 1;
        $ytSubsRaw = $job['yt_subs'] ?? null;
        $ytSubs = is_string($ytSubsRaw) && in_array($ytSubsRaw, ['en', 'all'], true) ? $ytSubsRaw : null;
        $forceYtdlp = (int) ($job['force_ytdlp'] ?? 0) === 1;
        $folder = UserRepository::normalizeFolder((string) ($job['cloud_folder'] ?? ''));
        $conflict = UserRepository::normalizeConflict((string) ($job['cloud_conflict'] ?? 'rename'));
        $deleteAfter = (int) ($job['delete_after'] ?? 0) === 1;

        $resolveOpts = ['force_ytdlp' => $forceYtdlp];
        $source = $this->downloads->resolve($url, $resolveOpts);
        if ($source === null) {
            $this->jobs->markFailed($jobId, 'No download source supports this URL.');
            return;
        }

        $needsCloud = $behavior === 'save_to_cloud' || $behavior === 'save_to_both';
        $requestedProviderId = trim((string) ($job['cloud_provider'] ?? ''));
        $cloudProvider = null;
        if ($needsCloud) {
            $registry = $this->cloud->registry();
            if ($requestedProviderId !== '') {
                $cloudProvider = $registry->get($requestedProviderId);
                if ($cloudProvider === null || !$cloudProvider->isConfigured()) {
                    $this->jobs->markFailed($jobId, 'Unknown or unconfigured cloud provider.');
                    return;
                }
                if (!$cloudProvider->isConnected($user)) {
                    $this->jobs->markFailed(
                        $jobId,
                        'Connect ' . $cloudProvider->label() . ' first (Account → Cloud).'
                    );
                    return;
                }
            } else {
                $cloudProvider = $registry->defaultConnected($user);
                if ($cloudProvider === null) {
                    $this->jobs->markFailed($jobId, 'Connect a cloud account first (Account → Cloud).');
                    return;
                }
            }
        }

        $downloadResult = null;
        $fileId = null;
        $cloudJobId = null;
        $resultFilename = $filename !== '' ? $filename : null;

        $needsDisk = $behavior === 'save_to_disk'
            || $behavior === 'save_to_both'
            || ($behavior === 'save_to_cloud' && $source->requiresLocalFile());

        try {
            if ($this->jobs->isCancelled($jobId)) {
                return;
            }

            if ($needsDisk) {
                $downloadResult = $this->downloads->download($url, [
                    'filename' => $filename !== '' ? $filename : null,
                    'yt_format' => $ytFormat,
                    'yt_audio_only' => $ytAudioOnly,
                    'yt_subs' => $ytSubs,
                    'force_ytdlp' => $forceYtdlp,
                ]);

                if ($this->jobs->isCancelled($jobId)) {
                    $this->discardTempDownload($downloadResult);
                    return;
                }

                if (!$downloadResult['ok']) {
                    $this->jobs->markFailed($jobId, $downloadResult['error'] ?? 'Download failed.');
                    return;
                }

                $resultFilename = $downloadResult['original_name'] ?? $resultFilename;
                $sizeBytes = (int) ($downloadResult['size'] ?? 0);
                if ($this->users->wouldExceedQuota($user, $sizeBytes)) {
                    $this->discardTempDownload($downloadResult);
                    $used = $this->users->usedBytes((int) $user['id']);
                    $quota = UserRepository::effectiveQuotaBytes($user);
                    $this->jobs->markFailed(
                        $jobId,
                        sprintf(
                            'Storage quota exceeded (%s). Delete files or ask an admin to raise your limit.',
                            UserRepository::formatUsageLabel($used, $quota)
                        )
                    );
                    return;
                }
                $fileId = $this->files->create(
                    (int) $user['id'],
                    $downloadResult['stored_name'],
                    $downloadResult['original_name'],
                    $sizeBytes,
                    $url
                );
            }

            if ($this->jobs->isCancelled($jobId)) {
                return;
            }

            if ($needsCloud) {
                if ($source->requiresLocalFile() || $behavior === 'save_to_both') {
                    $token = $this->shares->enable((int) $fileId, (int) $user['id'], purpose: 'cloud_fetch');
                    $fetchUrl = ShareService::publicUrl($token);
                    $cloudFilename = $downloadResult['original_name'] ?? 'video.mp4';
                    $localFileId = (int) $fileId;
                } else {
                    $fetchUrl = $url;
                    $cloudFilename = $filename !== '' ? $filename : null;
                    $localFileId = null;
                    $deleteAfter = false;
                }

                if ($cloudProvider === null) {
                    $this->jobs->markFailed($jobId, 'Connect a cloud account first (Account → Cloud).', $resultFilename, $fileId);
                    return;
                }

                if ($this->jobs->isCancelled($jobId)) {
                    return;
                }

                $sent = $this->cloud->startForUser(
                    $user,
                    $fetchUrl,
                    $cloudFilename,
                    $folder,
                    $conflict,
                    $deleteAfter,
                    $localFileId,
                    $cloudProvider->id()
                );

                $cloudJobId = isset($sent['job_id']) ? (int) $sent['job_id'] : null;
                if ($resultFilename === null && isset($sent['filename'])) {
                    $resultFilename = (string) $sent['filename'];
                } elseif ($resultFilename === null && $cloudFilename !== null) {
                    $resultFilename = $cloudFilename;
                }

                if ($this->jobs->isCancelled($jobId)) {
                    if ($cloudJobId !== null) {
                        (new CloudJobRepository())->cancel($cloudJobId, (int) $user['id']);
                    }
                    return;
                }

                if (!$sent['success']) {
                    $error = ($sent['message'] ?? 'Cloud upload failed')
                        . (isset($sent['error']) ? ' — ' . (string) $sent['error'] : '');
                    $this->jobs->markFailed($jobId, $error, $resultFilename, $fileId, $cloudJobId);
                    return;
                }
            }

            if ($this->jobs->isCancelled($jobId)) {
                return;
            }

            $this->jobs->markComplete($jobId, $resultFilename, $fileId, $cloudJobId);
        } catch (\Throwable $e) {
            if ($this->jobs->isCancelled($jobId)) {
                return;
            }
            $this->jobs->markFailed(
                $jobId,
                $e->getMessage() !== '' ? $e->getMessage() : 'Download job failed.',
                $resultFilename,
                $fileId,
                $cloudJobId
            );
        }
    }

    /**
     * @param array<string, mixed>|null $downloadResult
     */
    private function discardTempDownload(?array $downloadResult): void
    {
        if ($downloadResult === null) {
            return;
        }
        $stored = (string) ($downloadResult['stored_name'] ?? '');
        if ($stored === '') {
            return;
        }
        $path = FileRepository::absolutePath($stored);
        if ($path !== null && is_file($path)) {
            unlink($path);
        }
    }
}
