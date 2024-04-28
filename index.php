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
</head>

<body>
    <div class="col-lg-8 mx-auto p-4 py-md-5">
        <?php include_once 'includes/header.php' ?>
        <main>
            <h1>Download to Host</h1>
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
                    echo $sentToDb;
                }
            }
            ?>
            <form action="" method="post">
                <div class="mb-3">
                    <label for="DLURL" class="form-label">URL:</label>
                    <input name="DLURL" type="url" class="form-control" id="DLURL" onchange="getFileNameFromUrl(this.value)" placeholder="https://......">
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
    </script>
    <script src="assets/js/bootstrap.bundle.min.js" integrity="sha384-u1OknCvxWvY5kfmNBILK2hRnQC3Pr17a+RTT6rIHI7NnikvbZlHgTPOOmMi466C8" crossorigin="anonymous"></script>
</body>

</html>