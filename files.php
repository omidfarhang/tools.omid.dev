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
    <h1>Available Files</h1>
    <table class="table">
    <thead>
    <tr>
                <th scope="col">#</th>
                <td scope="col">File</td>
                <td scope="col">Download</td>
                <td scope="col">Size</td>
                <td scope="col">Date</td></tr>
</thead>
<tbody>
    <?php
        $files = glob('temp/*');
        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        $index = 1;
    foreach($files as $file){
        printf('<tr>
                <th scope="row">'.$index.'</th>
                <td>%2$s</td>
                <td><a href="%1$s" target="_blank">Download</a></td>
                <td><a onClick=\"javascript: return confirm(\'Please confirm deletion\');\" href=\'delete.php?id="%1$s"\'>x</a></td>
                <td>%3$s</td> 
                <td>%4$s</td></tr>', 
                $file,
                basename($file),
                human_filesize(filesize($file)),
                date('F d Y, H:i:s', filemtime($file)));
                $index++;
    }
    ?>
    </tbody>
    </table>
    </main>
    <?php include_once 'includes/footer.php' ?>
</div>
    <script src="assets/js/jquery-3.4.1.min.js"></script>
    <script src="assets/js/bootstrap.bundle.min.js" integrity="sha384-u1OknCvxWvY5kfmNBILK2hRnQC3Pr17a+RTT6rIHI7NnikvbZlHgTPOOmMi466C8" crossorigin="anonymous"></script>
</body>

</html>
