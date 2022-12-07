<?php

    if(isset($_GET['file']))
      {
        $filename='temp/'.$_GET['file'];
        $del = $unlink($filename);
        if($del)
          {
          header('location:files.php');
          } else {
            echo 'Something went wrong, maybe file not found or lack of permission.';
            echo $del;
          }
      } else {
        echo 'Where is the file?';
      }
?>