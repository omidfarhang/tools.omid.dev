<?php
$url = $filename = "";
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (empty($_POST["url"])) {
        echo 'where is url?';
        die;
    } else {
        $url = $_POST["url"];
    }
    if (empty($_POST["filename"])) {
        echo 'where is filename?';
        die;
    } else {
        $milliseconds = round(microtime(true) * 1000);
        $filename = '/'.$milliseconds.'-'.$_POST["filename"];
    }
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
    echo $response;
    curl_close($ch);
}
