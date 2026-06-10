<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Mobimend\Config\Database;

header('Content-Type: application/json; charset=utf-8');

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Invalid JSON']);
    exit;
}

$callback = $payload['Body']['stkCallback'] ?? [];
if (!is_array($callback)) {
    http_response_code(400);
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Missing STK callback']);
    exit;
}

$checkoutRequestId = (string) ($callback['CheckoutRequestID'] ?? '');
$resultCode = (int) ($callback['ResultCode'] ?? -1);
$receipt = '';

$metadata = $callback['CallbackMetadata']['Item'] ?? [];
if (is_array($metadata)) {
    foreach ($metadata as $item) {
        if (is_array($item) && ($item['Name'] ?? '') === 'MpesaReceiptNumber') {
            $receipt = (string) ($item['Value'] ?? '');
            break;
        }
    }
}

if ($checkoutRequestId !== '') {
    $pdo = Database::connection();
    $stmt = $pdo->prepare(
        'UPDATE payments
         SET status = :status,
             mpesa_receipt_number = COALESCE(NULLIF(:receipt, ""), mpesa_receipt_number),
             raw_response = :raw_response,
             updated_at = :updated_at
         WHERE checkout_request_id = :checkout_request_id'
    );
    $stmt->execute([
        'status' => $resultCode === 0 ? 'paid' : 'failed',
        'receipt' => $receipt,
        'raw_response' => json_encode($payload, JSON_THROW_ON_ERROR),
        'updated_at' => now(),
        'checkout_request_id' => $checkoutRequestId,
    ]);

    if ($resultCode === 0) {
        $stmt = $pdo->prepare(
            'UPDATE orders o
             INNER JOIN payments p ON p.order_id = o.id
             SET o.payment_status = "paid", o.updated_at = :updated_at
             WHERE p.checkout_request_id = :checkout_request_id'
        );
        $stmt->execute([
            'updated_at' => now(),
            'checkout_request_id' => $checkoutRequestId,
        ]);
    }
}

echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
