<?php

declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/daraja_config.php';
require_once __DIR__ . '/get_token.php';

use Mobimend\Config\Database;

function mpesa_json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function normalize_mpesa_phone(string $phone): string
{
    $phone = preg_replace('/\D+/', '', $phone) ?? '';
    if (str_starts_with($phone, '0')) {
        return '254' . substr($phone, 1);
    }
    if (str_starts_with($phone, '7') || str_starts_with($phone, '1')) {
        return '254' . $phone;
    }

    return $phone;
}

try {
    $input = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($input)) {
        mpesa_json_response(['errorMessage' => 'Invalid request body.'], 400);
    }

    $phone = normalize_mpesa_phone((string) ($input['phone'] ?? ''));
    $amount = (int) ceil((float) ($input['amount'] ?? 0));
    $ref = trim((string) ($input['reference'] ?? 'MobimendOrder'));
    $paymentId = (int) ($input['payment_id'] ?? 0);

    if (!preg_match('/^254(7|1)\d{8}$/', $phone)) {
        mpesa_json_response(['errorMessage' => 'Enter a valid Kenyan M-Pesa phone number.'], 422);
    }
    if ($amount <= 0) {
        mpesa_json_response(['errorMessage' => 'Payment amount must be greater than zero.'], 422);
    }

    $pdo = Database::connection();
    $payment = null;

    if ($paymentId > 0) {
        $stmt = $pdo->prepare('SELECT p.*, o.order_number FROM payments p LEFT JOIN orders o ON o.id = p.order_id WHERE p.id = :id LIMIT 1');
        $stmt->execute(['id' => $paymentId]);
        $payment = $stmt->fetch();
    }

    if (!$payment && $ref !== '') {
        $stmt = $pdo->prepare(
            'SELECT p.*, o.order_number
             FROM payments p
             INNER JOIN orders o ON o.id = p.order_id
             WHERE o.order_number = :reference
             ORDER BY p.id DESC
             LIMIT 1'
        );
        $stmt->execute(['reference' => $ref]);
        $payment = $stmt->fetch();
    }

    if ($payment) {
        $paymentId = (int) $payment['id'];
        $amount = (int) ceil((float) $payment['amount']);
        $phone = normalize_mpesa_phone((string) ($payment['phone_number'] ?: $phone));
        $ref = trim((string) ($payment['order_number'] ?: $ref));
    }

    $token = getDarajaToken();
    if (!$token) {
        mpesa_json_response(['errorMessage' => 'Unable to get Daraja access token.'], 502);
    }

    $timestamp = date('YmdHis');
    $password = base64_encode(SHORTCODE . PASSKEY . $timestamp);

    $payload = [
        'BusinessShortCode' => SHORTCODE,
        'Password' => $password,
        'Timestamp' => $timestamp,
        'TransactionType' => 'CustomerPayBillOnline',
        'Amount' => $amount,
        'PartyA' => $phone,
        'PartyB' => SHORTCODE,
        'PhoneNumber' => $phone,
        'CallBackURL' => CALLBACK_URL,
        'AccountReference' => $ref !== '' ? $ref : 'MobimendOrder',
        'TransactionDesc' => 'Payment for ' . ($ref !== '' ? $ref : 'MobimendOrder'),
    ];

    $ch = curl_init(BASE_URL . '/mpesa/stkpush/v1/processrequest');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $response === '') {
        mpesa_json_response(['errorMessage' => $curlError ?: 'Daraja request failed.'], 502);
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        mpesa_json_response(['errorMessage' => 'Daraja returned an invalid response.'], 502);
    }

    if ($paymentId > 0) {
        $status = (string) ($data['ResponseCode'] ?? '') === '0' ? 'processing' : 'failed';
        $stmt = $pdo->prepare(
            'UPDATE payments
             SET status = :status,
                 merchant_request_id = :merchant_request_id,
                 checkout_request_id = :checkout_request_id,
                 raw_response = :raw_response,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'status' => $status,
            'merchant_request_id' => $data['MerchantRequestID'] ?? null,
            'checkout_request_id' => $data['CheckoutRequestID'] ?? null,
            'raw_response' => json_encode($data),
            'updated_at' => now(),
            'id' => $paymentId,
        ]);
        $data['payment_id'] = $paymentId;
    }

    echo json_encode($data);
} catch (Throwable $exception) {
    mpesa_json_response(['errorMessage' => $exception->getMessage()], 500);
}
