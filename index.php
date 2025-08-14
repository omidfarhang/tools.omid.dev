<?php
include_once 'classes/functions.php';
include_once 'classes/downloads.php';
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" dir="ltr">

<head>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta content="text/html; charset=utf-8" http-equiv="Content-Type" />
    <meta content="en-us" http-equiv="Content-Language" />
    <?php include_once 'includes/favicon.php' ?>
    <title>Download to Host</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-iYQeCzEYFbKjA/T2uDLTpkwGzCiq6soy8tYaI1GyVh/UjpbCx/TYkiZhlZB6+fzT" crossorigin="anonymous">
    <style>
        #dropbox-status {
            margin-top: 20px;
        }
        .progress {
            height: 20px;
        }
        .progress-bar {
            background: linear-gradient(45deg, #007bff, #0056b3);
        }
    </style>
</head>

<body>
    <div class="col-lg-8 mx-auto p-4 py-md-5">
        <?php include_once 'includes/header.php' ?>
        <main>
            <h1>Download to Host</h1>
            <div id="dropbox-status" class="alert d-none" role="alert">
                <div id="dropbox-message"></div>
                <div id="dropbox-progress" class="progress mt-2 d-none">
                    <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 100%"></div>
                </div>
            </div>
            
            <?php
            if ($_POST) {
                $bahavior = $_POST['behavior'];
                $url = $_POST['DLURL'];
                $filename = $_POST['DLFILENAME'];
                $yt_format = $_POST['yt_format'];
                $status = null;
                $sentToDb = null;
                if (is_youtube($url)) {
                    $status = youtube_download($url, $yt_format);
                    if ($bahavior === 'save_to_dropbox') {
                        $db_filename = basename($status);
                        $db_url = 'https://tools.omid.dev/temp/'.$db_filename;
                        $sentToDb = dropbox_saveurl($db_url, $db_filename);
                    }
                } else {
                    if ($bahavior === 'save_to_disk') {
                        $status = common_download($url, $filename);
                    }
                    if ($bahavior === 'save_to_dropbox') {
                        $sentToDb = dropbox_saveurl($url, $filename);
                    }
                }

                if (!$status && ($bahavior === 'save_to_disk')) {
                    echo "failed to copy $url...\n";
                } 
                
                if ($status) {
                    $file = 'temp/'.basename($status);
                    echo 'Successfully downloaded!';
                    echo '<br><br>';
                    echo 'Source: ' . $url;
                    echo '<br>';
                    echo 'Name: ' . basename($status);
                    echo '<br>';
                    echo 'Size: ' . human_filesize(filesize($file));
                    echo '<br><br>';
                    echo 'Your file is ready for download from here:';
                    echo '<br>';
                    echo 'CloudFlare: <a href="' . $file . '" target="_blank">Download</a>';
                    echo '<br><br>';
                }
                if ($sentToDb) {
                    if ($sentToDb['success']) {
                        echo '<script>
                        window.dropboxJobData = {
                            asyncJobId: "' . $sentToDb['async_job_id'] . '",
                            filename: "' . htmlspecialchars($sentToDb['filename']) . '"
                        };
                        </script>';
                    } else {
                        echo '<div class="alert alert-danger">' . htmlspecialchars($sentToDb['message']) . '</div>';
                        if (isset($sentToDb['error'])) {
                            echo '<div class="alert alert-warning">Error: ' . htmlspecialchars($sentToDb['error']) . '</div>';
                        }
                    }
                }
            }
            ?>
            <form action="" method="post">
                <div class="mb-3">
                    <label for="DLURL" class="form-label">URL:</label>
                    <input name="DLURL" type="url" class="form-control" id="DLURL" oninput="getFileNameFromUrl(this.value)" placeholder="https://......">
                </div>
                <div class="mb-3 d-none" id="youtube-options">
                    <label for="yt_format" class="form-label">YouTube Format:</label>
                    <select class="form-control" name="yt_format" id="yt_format">
                        <option value="best" selected>Best</option>
                        <option value="4320">4320p (8k)</option>
                        <option value="2160">2160p (4K)</option>
                        <option value="1440">1440p (2k)</option>
                        <option value="1080">1080p (HD)</option>
                        <option value="720">720p (HD)</option>
                        <option value="480">480p (SD)</option>
                        <option value="360">360p (SD)</option>
                        <option value="240">240p (SD)</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label for="DLFILENAME" class="form-label">Filename:</label>
                    <input name="DLFILENAME" type="text" class="form-control" id="DLFILENAME" placeholder="Auto generated, can be changed">
                </div>
                <h5 id="status"></h5>
                <button class="btn btn-primary" name="behavior" type="submit" value="save_to_disk">Save To Disk</button>
                <button class="btn btn-primary" name="behavior" type="submit" value="save_to_dropbox">Save To Dropbox</button>
            </form>
        </main>
        <?php include_once 'includes/footer.php' ?>
    </div>
    <script src="assets/js/jquery-3.4.1.min.js"></script>
    <script>
        $(document).ready(function() {
            // Check if there's pending Dropbox job data to monitor
            if (window.dropboxJobData) {
                startDropboxMonitoring(window.dropboxJobData.asyncJobId, window.dropboxJobData.filename);
            }
        });

        function getFileNameFromUrl(url) {
            if (!url) {
                return
            };
            const isYoutube = url.match(/^(?:http(?:s)?:\/\/)?(?:www\.)?(?:m\.)?(?:youtu\.be\/|youtube\.com\/(?:(?:watch)?\?(?:.*&)?v(?:i)?=|(?:embed|v|vi|user|shorts)\/))([^\?&\"'>]+)/);
            if(isYoutube && isYoutube[1]) {
                document.getElementById("youtube-options").classList.remove('d-none');
            } else {
                document.getElementById("youtube-options").classList.add('d-none');
            }
            const hashIndex = url.indexOf('#')
            url = hashIndex !== -1 ? url.substring(0, hashIndex) : url
            document.getElementById("DLFILENAME").value = (url.split('/').pop() || '').replace(/[\?].*$/g, '');
            return (url.split('/').pop() || '').replace(/[\?].*$/g, '')
        }

        function startDropboxMonitoring(asyncJobId, filename) {
            const statusDiv = document.getElementById('dropbox-status');
            const messageDiv = document.getElementById('dropbox-message');
            const progressDiv = document.getElementById('dropbox-progress');
            
            statusDiv.className = 'alert alert-info';
            statusDiv.classList.remove('d-none');
            messageDiv.innerHTML = 'Uploading "' + filename + '" to Dropbox...';
            progressDiv.classList.remove('d-none');
            
            checkDropboxStatus(asyncJobId, filename);
        }

        function checkDropboxStatus(asyncJobId, filename) {
            $.ajax({
                url: 'check_dropbox_status.php',
                method: 'POST',
                data: {
                    async_job_id: asyncJobId
                },
                dataType: 'json',
                success: function(response) {
                    const statusDiv = document.getElementById('dropbox-status');
                    const messageDiv = document.getElementById('dropbox-message');
                    const progressDiv = document.getElementById('dropbox-progress');
                    
                    if (response.success) {
                        if (response.status === 'in_progress') {
                            messageDiv.innerHTML = 'Upload in progress for "' + filename + '"...';
                            // Check again after 2 seconds
                            setTimeout(function() {
                                checkDropboxStatus(asyncJobId, filename);
                            }, 2000);
                        } else if (response.status === 'complete') {
                            statusDiv.className = 'alert alert-success';
                            progressDiv.classList.add('d-none');
                            messageDiv.innerHTML = '✅ Successfully uploaded "' + filename + '" to Dropbox!<br><strong>File ID:</strong> ' + (response.data.id || 'N/A') + '<br><strong>Path:</strong> ' + (response.data.path_display || response.data.path_lower || 'N/A');
                        } else if (response.status === 'failed') {
                            statusDiv.className = 'alert alert-danger';
                            progressDiv.classList.add('d-none');
                            messageDiv.innerHTML = '❌ Failed to upload "' + filename + '" to Dropbox.<br><strong>Error:</strong> ' + (response.data.error_summary || 'Unknown error');
                        } else {
                            // Handle other statuses
                            messageDiv.innerHTML = 'Status: ' + response.status + ' for "' + filename + '"';
                            setTimeout(function() {
                                checkDropboxStatus(asyncJobId, filename);
                            }, 2000);
                        }
                    } else {
                        statusDiv.className = 'alert alert-danger';
                        progressDiv.classList.add('d-none');
                        messageDiv.innerHTML = '❌ Error checking upload status: ' + (response.error || 'Unknown error');
                    }
                },
                error: function() {
                    const statusDiv = document.getElementById('dropbox-status');
                    const messageDiv = document.getElementById('dropbox-message');
                    const progressDiv = document.getElementById('dropbox-progress');
                    
                    statusDiv.className = 'alert alert-danger';
                    progressDiv.classList.add('d-none');
                    messageDiv.innerHTML = '❌ Error checking upload status. Please try again.';
                }
            });
        }
    </script>
    <script src="assets/js/bootstrap.bundle.min.js" integrity="sha384-u1OknCvxWvY5kfmNBILK2hRnQC3Pr17a+RTT6rIHI7NnikvbZlHgTPOOmMi466C8" crossorigin="anonymous"></script>
</body>

</html>