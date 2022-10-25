<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" dir="ltr">

<head>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta content="text/html; charset=utf-8" http-equiv="Content-Type" />
    <meta content="en-us" http-equiv="Content-Language" />
    <link rel="shortcut icon" href="https://omidfarhang.com/favicon.ico" />
    <title>Download to Host</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-iYQeCzEYFbKjA/T2uDLTpkwGzCiq6soy8tYaI1GyVh/UjpbCx/TYkiZhlZB6+fzT" crossorigin="anonymous">
</head>

<body>
<div class="col-lg-8 mx-auto p-4 py-md-5">
  <header class="d-flex align-items-center pb-3 mb-5 border-bottom">
    <a href="/" class="d-flex align-items-center text-dark text-decoration-none">
      <span class="fs-4">Tools Center</span>
    </a>
  </header>

  <main>
    <h1>Available Files</h1>
    <table class="table">
    <thead>
    <tr>
                <th scope="col">#</th>
                <td scope="col">File</td>
                <td scope="col">CloudFlare</td>
                <td scope="col">Iran</td>
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
                <th scope="row">$index</th>
                <td>%1$s</td>
                <td><a href="https://tools.omidfarhang.com/%1$s" target="_blank">%1$s</a></td>
                <td><a href="https://tools.omidfarhang.ir/%1$s" target="_blank">%1$s</a></td>
                <td>%2$s</td></tr>', 
                $file, // or basename($file) for just the filename w\out path
                date('F d Y, H:i:s', filemtime($file)));
                $index++;
    }
    ?>
    </tbody>
    </table>
    </main>
  <footer class="pt-5 my-5 text-muted border-top">
    All Rights Reserved 2022 | Omid Farhang
  </footer>
</div>
    <script src="assets/js/jquery-3.4.1.min.js"></script>
    <script src="assets/js/bootstrap.bundle.min.js" integrity="sha384-u1OknCvxWvY5kfmNBILK2hRnQC3Pr17a+RTT6rIHI7NnikvbZlHgTPOOmMi466C8" crossorigin="anonymous"></script>
</body>

</html>
