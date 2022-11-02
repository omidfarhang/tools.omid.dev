<?php
include_once 'functions.php';
function common_download($url, $filename = null)
{
    if (!copy($url, $filename)) {
        return false;
    } else {
        return true;
    }
}
function youtube_download($url)
{
    $youtube_id = is_youtube($url);
    $output = shell_exec("yt-dlp $youtube_id -q --exec echo");
    return $output;
}
function dropbox_saveurl($url, $filename = null)
{
    $cheaders = array('Authorization: Bearer 0T-Nu7hCogsAAAAAAADkmuOiW4WmlBV_woQWqSE4cm1IwVjBHzAxQKXjqWrK9u9P', 'Content-Type: application/json');
    $params = array(
        'path' => $filename,
        'url' => $url
    );
    $payload = json_encode($params);
    $ch = curl_init('https://api.dropboxapi.com/2/files/save_url');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $cheaders);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}
