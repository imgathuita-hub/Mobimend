<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once 'daraja_config.php';
require_once 'get_token.php';

$input = json_decode(file_get_contents('php://input'), true);
$phone  = preg_replace('/^0/', '254', $input['phone']); // convert 07xx → 2547xx
$amount = intval($input['amount']);
$ref    = $input['reference'] ?? 'MobimendOrder';

$token     = getDarajaToken();
$timestamp = date('YmdHis');
$password  = base64_encode(SHORTCODE . PASSKEY . $timestamp);

$payload = [
    'BusinessShortCode' => SHORTCODE,
    'Password'          => $password,
    'Timestamp'         => $timestamp,
    'TransactionType'   => 'CustomerPayBillOnline',
    'Amount'            => $amount,
    'PartyA'            => $phone,
    'PartyB'            => SHORTCODE,
    'PhoneNumber'       => $phone,
    'CallBackURL'       => CALLBACK_URL,
    'AccountReference'  => $ref,
    'TransactionDesc'   => 'Payment for ' . $ref,
];

$ch = curl_init(BASE_URL . '/mpesa/stkpush/v1/processrequest');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token,
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);

echo $response; // return raw Daraja response to frontend