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
                $url = $_POST['DLURL'];
                $filename = $_POST['DLFILENAME'];
                $status = null;
                if (is_youtube($url)) {
                    $status = youtube_download($url);
                } else {
                    $status = common_download($url, $filename);
                }

                if (!$status) {
                    echo "failed to copy $url...\n";
                } else {
                    $file = 'temp/'.basename($status);
                    echo 'Successfully downloaded!';
                    echo '<br><br>';
                    echo 'Source: ' . $url;
                    echo '<br>';
                    echo 'Name: ' . basename($status);
                    echo '<br>';
                    echo 'Size: ' . human_filesize(filesize(glob($file)));
                    echo '<br><br>';
                    echo 'Your file is ready for download from here:';
                    echo '<br>';
                    echo 'CloudFlare: <a href="' . $file . '" target="_blank">Download</a>';
                    echo '<br><br>';
                }
            }
            ?>
            <form action="" method="post">
                <div class="mb-3">
                    <label for="DLURL" class="form-label">URL:</label>
                    <input name="DLURL" type="url" class="form-control" id="DLURL" onchange="getFileNameFromUrl(this.value)" placeholder="https://......">
                </div>
                <div class="mb-3">
                    <label for="DLFILENAME" class="form-label">Filename:</label>
                    <input name="DLFILENAME" type="text" class="form-control" id="DLFILENAME" placeholder="Auto generated, can be changed">
                </div>
                <h5 id="status"></h5>
                <input class="btn btn-primary" name="Submit1" type="submit" value="Submit" />
                <input class="btn btn-primary" name="Submit2" id="dropbox" type="button" value="Save To Dropbox" onclick="saveToDropbox()" />
            </form>
        </main>
        <?php include_once 'includes/footer.php' ?>
    </div>
    <script type="text/javascript" src="https://www.dropbox.com/static/api/2/dropins.js" id="dropboxjs" data-app-key="gnls7njehojr4u4"></script>
    <script src="assets/js/jquery-3.4.1.min.js"></script>
    <script>
        function getFileNameFromUrl(url) {
            if (!url) {
                return
            };
            const hashIndex = url.indexOf('#')
            url = hashIndex !== -1 ? url.substring(0, hashIndex) : url
            document.getElementById("DLFILENAME").value = (url.split('/').pop() || '').replace(/[\?].*$/g, '');
            return (url.split('/').pop() || '').replace(/[\?].*$/g, '')
        }

        function saveToDropbox() {
            document.getElementById("dropbox").disabled = true;
            if (!document.getElementById("DLURL").value) {
                document.getElementById("status").innerHTML = "No URL!";
                document.getElementById("dropbox").disabled = false;
                return;
            }
            if (!document.getElementById("DLFILENAME").value) {
                document.getElementById("status").innerHTML = "No Filename!";
                document.getElementById("dropbox").disabled = false;
                return;
            }

            $.ajax({
                url: "dropbox.php", // The other php filename here
                type: "POST",
                data: {
                    "url": document.getElementById("DLURL").value,
                    "filename": document.getElementById("DLFILENAME").value
                },
                success: function(html) {
                    document.getElementById("status").innerHTML = html;
                    document.getElementById("dropbox").disabled = false;
                }
            });
        }
    </script>
    <script src="assets/js/bootstrap.bundle.min.js" integrity="sha384-u1OknCvxWvY5kfmNBILK2hRnQC3Pr17a+RTT6rIHI7NnikvbZlHgTPOOmMi466C8" crossorigin="anonymous"></script>
</body>

</html>