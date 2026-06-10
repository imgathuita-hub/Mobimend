<?php
require_once 'daraja_config.php';

function getDarajaToken() {
    $credentials = base64_encode(CONSUMER_KEY . ':' . CONSUMER_SECRET);
    $url = BASE_URL . '/oauth/v1/generate?grant_type=client_credentials';

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Basic ' . $credentials]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $result = curl_exec($ch);
    curl_close($ch);

    $json = json_decode($result, true);
    return $json['access_token'] ?? null;
}