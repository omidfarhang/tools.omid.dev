<?php
/** @var array{asyncJobId?:?string,filename?:string,jobId?:?int}|null $dropboxJob */
?>
<script>
window.dropboxJobData = <?= json_encode($dropboxJob, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

function dropboxStatusBadgeClass(status) {
    if (status === 'complete') return 'badge text-bg-success job-status-badge';
    if (status === 'failed') return 'badge text-bg-danger job-status-badge';
    if (status === 'skipped') return 'badge text-bg-warning job-status-badge';
    if (status === 'in_progress') return 'badge text-bg-info job-status-badge';
    return 'badge text-bg-secondary job-status-badge';
}

function updateDropboxJobRow(response) {
    if (!response.job || !response.job.id) return;
    const row = document.querySelector('tr[data-job-id="' + response.job.id + '"]');
    if (!row) return;
    const status = response.job.status || response.status || '';
    row.setAttribute('data-status', status);
    const badge = row.querySelector('.job-status-badge');
    if (badge) {
        badge.className = dropboxStatusBadgeClass(status);
        badge.textContent = status;
    }
    const pathCell = row.children[1];
    if (pathCell && (response.job.result_path || response.job.dropbox_path)) {
        pathCell.textContent = response.job.result_path || response.job.dropbox_path;
    }
}

function startDropboxMonitoring(asyncJobId, filename) {
    const statusDiv = document.getElementById('dropbox-status');
    const messageDiv = document.getElementById('dropbox-message');
    const progressDiv = document.getElementById('dropbox-progress');
    if (!statusDiv || !messageDiv || !progressDiv || !asyncJobId) return;
    statusDiv.className = 'alert alert-info';
    statusDiv.classList.remove('d-none');
    messageDiv.textContent = 'Uploading "' + filename + '" to Dropbox...';
    progressDiv.classList.remove('d-none');
    checkDropboxStatus(asyncJobId, filename);
}

function checkDropboxStatus(asyncJobId, filename) {
    const body = new URLSearchParams({
        async_job_id: asyncJobId,
        _csrf: <?= json_encode(\App\Csrf::token()) ?>,
    });

    fetch('/api/check_dropbox_status.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(),
        credentials: 'same-origin',
    })
        .then(function (res) { return res.json(); })
        .then(function (response) {
            updateDropboxJobRow(response);
            const statusDiv = document.getElementById('dropbox-status');
            const messageDiv = document.getElementById('dropbox-message');
            const progressDiv = document.getElementById('dropbox-progress');
            if (!statusDiv || !messageDiv || !progressDiv) return;

            if (response.success) {
                if (response.status === 'in_progress') {
                    messageDiv.textContent = 'Upload in progress for "' + filename + '"...';
                    setTimeout(function () { checkDropboxStatus(asyncJobId, filename); }, 2000);
                } else if (response.status === 'complete') {
                    statusDiv.className = 'alert alert-success';
                    progressDiv.classList.add('d-none');
                    const path = (response.data && (response.data.path_display || response.data.path_lower))
                        || (response.job && response.job.result_path)
                        || 'N/A';
                    let msg = 'Uploaded "' + filename + '" to Dropbox. Path: ' + path;
                    if (response.deleted_local) {
                        msg += ' Host copy deleted.';
                    }
                    messageDiv.textContent = msg;
                } else if (response.status === 'failed') {
                    statusDiv.className = 'alert alert-danger';
                    progressDiv.classList.add('d-none');
                    messageDiv.textContent = 'Failed to upload "' + filename + '".';
                } else {
                    setTimeout(function () { checkDropboxStatus(asyncJobId, filename); }, 2000);
                }
            } else {
                statusDiv.className = 'alert alert-danger';
                progressDiv.classList.add('d-none');
                messageDiv.textContent = 'Error: ' + (response.error || 'Unknown error');
            }
        })
        .catch(function () {
            const statusDiv = document.getElementById('dropbox-status');
            if (!statusDiv) return;
            statusDiv.className = 'alert alert-danger';
            statusDiv.classList.remove('d-none');
            const progress = document.getElementById('dropbox-progress');
            if (progress) progress.classList.add('d-none');
            const messageDiv = document.getElementById('dropbox-message');
            if (messageDiv) messageDiv.textContent = 'Error checking upload status.';
        });
}

function pollInProgressDropboxJobs() {
    document.querySelectorAll('#dropbox-jobs-panel tr[data-status="in_progress"]').forEach(function (row) {
        const asyncId = row.getAttribute('data-async-job-id');
        const nameCell = row.children[0];
        const filename = nameCell ? nameCell.textContent : 'file';
        if (asyncId) {
            checkDropboxStatus(asyncId, filename);
        }
    });
}

document.addEventListener('DOMContentLoaded', function () {
    if (window.dropboxJobData && window.dropboxJobData.asyncJobId) {
        startDropboxMonitoring(window.dropboxJobData.asyncJobId, window.dropboxJobData.filename || 'file');
    }
    pollInProgressDropboxJobs();
});
</script>
