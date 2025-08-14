<?php
include_once 'classes/downloads.php';

header('Content-Type: application/json');

if ($_POST && isset($_POST['async_job_id'])) {
    $async_job_id = $_POST['async_job_id'];
    $status = dropbox_check_job_status($async_job_id);
    echo json_encode($status);
} else {
    echo json_encode(array(
        'success' => false,
        'error' => 'No async_job_id provided'
    ));
}
?>
