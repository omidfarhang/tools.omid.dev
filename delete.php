<?php

    if(isset($_GET['file']))
      {
        $filename='temp/'.$_GET['id'];
        if($unlink($filename))
          {
          header('location:files.php');
          } else {
            echo 'Something went wrong, maybe file not found or lack of permission.';
          }
      }
?>