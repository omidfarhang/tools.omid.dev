<?php
/** @var array{asyncJobId?:?string,filename?:string,jobId?:?int,provider?:string}|null $cloudJob */
$cloudJob = $cloudJob ?? null;
?>
<script>
window.cloudJobData = <?= json_encode($cloudJob, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

function cloudStatusBadgeClass(status) {
    if (status === 'complete') return 'badge text-bg-success job-status-badge';
    if (status === 'failed') return 'badge text-bg-danger job-status-badge';
    if (status === 'skipped') return 'badge text-bg-warning job-status-badge';
    if (status === 'in_progress') return 'badge text-bg-info job-status-badge';
    return 'badge text-bg-secondary job-status-badge';
}

function updateCloudJobRow(response) {
    if (!response.job || !response.job.id) return;
    const row = document.querySelector('tr[data-job-id="' + response.job.id + '"]');
    if (!row) return;
    const status = response.job.status || response.status || '';
    row.setAttribute('data-status', status);
    const badge = row.querySelector('.job-status-badge');
    if (badge) {
        badge.className = cloudStatusBadgeClass(status);
        badge.textContent = status;
    }
    const pathCell = row.children[2];
    if (pathCell && (response.job.result_path || response.job.remote_path || response.job.dropbox_path)) {
        pathCell.textContent = response.job.result_path || response.job.remote_path || response.job.dropbox_path;
    }
}

function startCloudMonitoring(asyncJobId, filename, provider) {
    const statusDiv = document.getElementById('cloud-status');
    const messageDiv = document.getElementById('cloud-message');
    const progressDiv = document.getElementById('cloud-progress');
    if (!statusDiv || !messageDiv || !progressDiv || !asyncJobId) return;
    provider = provider || 'dropbox';
    statusDiv.className = 'alert alert-info';
    statusDiv.classList.remove('d-none');
    messageDiv.textContent = 'Uploading "' + filename + '" to cloud (' + provider + ')...';
    progressDiv.classList.remove('d-none');
    checkCloudStatus(asyncJobId, filename, provider);
}

function checkCloudStatus(asyncJobId, filename, provider) {
    provider = provider || 'dropbox';
    const body = new URLSearchParams({
        async_job_id: asyncJobId,
        provider: provider,
        _csrf: <?= json_encode(\App\Csrf::token()) ?>,
    });

    fetch('/api/check_cloud_status.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(),
        credentials: 'same-origin',
    })
        .then(function (res) { return res.json(); })
        .then(function (response) {
            updateCloudJobRow(response);
            const statusDiv = document.getElementById('cloud-status');
            const messageDiv = document.getElementById('cloud-message');
            const progressDiv = document.getElementById('cloud-progress');
            if (!statusDiv || !messageDiv || !progressDiv) return;

            if (response.success) {
                if (response.status === 'in_progress') {
                    messageDiv.textContent = 'Upload in progress for "' + filename + '"...';
                    setTimeout(function () { checkCloudStatus(asyncJobId, filename, provider); }, 2000);
                } else if (response.status === 'complete') {
                    statusDiv.className = 'alert alert-success';
                    progressDiv.classList.add('d-none');
                    const path = (response.data && (response.data.path_display || response.data.path_lower))
                        || (response.job && (response.job.result_path || response.job.remote_path))
                        || 'N/A';
                    let msg = 'Uploaded "' + filename + '" to cloud. Path: ' + path;
                    if (response.deleted_local) {
                        msg += ' Host copy deleted.';
                    }
                    messageDiv.textContent = msg;
                } else if (response.status === 'failed') {
                    statusDiv.className = 'alert alert-danger';
                    progressDiv.classList.add('d-none');
                    messageDiv.textContent = 'Failed to upload "' + filename + '".';
                } else {
                    setTimeout(function () { checkCloudStatus(asyncJobId, filename, provider); }, 2000);
                }
            } else {
                statusDiv.className = 'alert alert-danger';
                progressDiv.classList.add('d-none');
                messageDiv.textContent = 'Error: ' + (response.error || 'Unknown error');
            }
        })
        .catch(function () {
            const statusDiv = document.getElementById('cloud-status');
            if (!statusDiv) return;
            statusDiv.className = 'alert alert-danger';
            statusDiv.classList.remove('d-none');
            const progress = document.getElementById('cloud-progress');
            if (progress) progress.classList.add('d-none');
            const messageDiv = document.getElementById('cloud-message');
            if (messageDiv) messageDiv.textContent = 'Error checking upload status.';
        });
}

function pollInProgressCloudJobs() {
    document.querySelectorAll('#cloud-jobs-panel tr[data-status="in_progress"]').forEach(function (row) {
        const asyncId = row.getAttribute('data-async-job-id');
        const provider = row.getAttribute('data-provider') || 'dropbox';
        const nameCell = row.children[0];
        const filename = nameCell ? nameCell.textContent : 'file';
        if (asyncId) {
            checkCloudStatus(asyncId, filename, provider);
        }
    });
}

document.addEventListener('DOMContentLoaded', function () {
    if (window.cloudJobData && window.cloudJobData.asyncJobId) {
        startCloudMonitoring(
            window.cloudJobData.asyncJobId,
            window.cloudJobData.filename || 'file',
            window.cloudJobData.provider || 'dropbox'
        );
    }
    pollInProgressCloudJobs();
});
</script>
