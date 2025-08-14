<?php
include_once 'functions.php';
function common_download($url, $filename = null)
{
    if(!isset($filename) || ($filename === '')){
        $filename = basename($url);
    }
    $save_location = 'temp/'.$filename;
    if (!copy($url, $save_location)) {
        return false;
    } else {
        return $filename;
    }
}
function youtube_download($url, $yt_format)
{
    $format = "-f bestvideo*+bestaudio/best";
    $cookie = "--cookies .secure/cookies.txt";
    $youtube_id = is_youtube($url);
    if ($yt_format == 'best') {
        $format = "-f bestvideo*+bestaudio/best";
    } else {
        $format = '-f "bestvideo*[height<='.$yt_format.']+bestaudio/best"';
    }
    $output = exec("yt-dlp $format $cookie $youtube_id -q --exec echo");
    return $output;
}
function dropbox_saveurl($url, $filename = null)
{
    if(!isset($filename) || ($filename === '')){
        $filename = basename($url);
    }
    $token = trim(file_get_contents(__DIR__ . '/../.secure/dropbox_token.txt'));
    $cheaders = array('Authorization: Bearer ' . $token, 'Content-Type: application/json');
    $params = array(
        'path' => '/'.$filename,
        'url' => $url
    );
    $payload = json_encode($params);
    $ch = curl_init('https://api.dropboxapi.com/2/files/save_url');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $cheaders);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $decoded_response = json_decode($response, true);
    
    if ($http_code == 200 && isset($decoded_response['async_job_id'])) {
        return array(
            'success' => true,
            'async_job_id' => $decoded_response['async_job_id'],
            'filename' => $filename,
            'message' => 'File upload to Dropbox started successfully!'
        );
    } else {
        return array(
            'success' => false,
            'error' => $decoded_response['error_summary'] ?? 'Unknown error occurred',
            'message' => 'Failed to start Dropbox upload'
        );
    }
}

function dropbox_check_job_status($async_job_id)
{
    $token = trim(file_get_contents(__DIR__ . '/../.secure/dropbox_token.txt'));
    $cheaders = array('Authorization: Bearer ' . $token, 'Content-Type: application/json');
    $params = array(
        'async_job_id' => $async_job_id
    );
    $payload = json_encode($params);
    $ch = curl_init('https://api.dropboxapi.com/2/files/save_url/check_job_status');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $cheaders);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $decoded_response = json_decode($response, true);
    
    if ($http_code == 200) {
        return array(
            'success' => true,
            'status' => $decoded_response['.tag'],
            'data' => $decoded_response
        );
    } else {
        return array(
            'success' => false,
            'error' => $decoded_response['error_summary'] ?? 'Unknown error occurred'
        );
    }
}
