<?php

declare(strict_types=1);

header('Content-Type: application/json');

require __DIR__ . '/src/bootstrap.php';

use Mobimend\Config\Database;

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);

file_put_contents(dirname(__DIR__) . '/storage/daraja_log.txt', date('c') . "\n" . $raw . "\n\n", FILE_APPEND);

$result = is_array($data) ? ($data['Body']['stkCallback'] ?? null) : null;
if (!is_array($result)) {
    http_response_code(200);
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Ignored']);
    exit;
}

$checkoutId = (string) ($result['CheckoutRequestID'] ?? '');
$merchantRequestId = (string) ($result['MerchantRequestID'] ?? '');
$resultCode = (int) ($result['ResultCode'] ?? -1);
$resultDesc = (string) ($result['ResultDesc'] ?? '');
$metadataItems = $result['CallbackMetadata']['Item'] ?? [];
$metadata = [];

if (is_array($metadataItems)) {
    foreach ($metadataItems as $item) {
        if (isset($item['Name'])) {
            $metadata[(string) $item['Name']] = $item['Value'] ?? null;
        }
    }
}

$pdo = Database::connection();
$pdo->beginTransaction();

try {
    $stmt = $pdo->prepare(
        'SELECT * FROM payments
         WHERE checkout_request_id = :checkout_request_id
            OR (merchant_request_id IS NOT NULL AND merchant_request_id = :merchant_request_id)
         ORDER BY id DESC
         LIMIT 1
         FOR UPDATE'
    );
    $stmt->execute([
        'checkout_request_id' => $checkoutId,
        'merchant_request_id' => $merchantRequestId,
    ]);
    $payment = $stmt->fetch();

    if ($payment) {
        $status = $resultCode === 0 ? 'paid' : 'failed';
        $stmt = $pdo->prepare(
            'UPDATE payments
             SET status = :status,
                 mpesa_receipt_number = :mpesa_receipt_number,
                 phone_number = COALESCE(NULLIF(:phone_number, ""), phone_number),
                 raw_response = :raw_response,
                 verified_at = :verified_at,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'status' => $status,
            'mpesa_receipt_number' => $metadata['MpesaReceiptNumber'] ?? null,
            'phone_number' => isset($metadata['PhoneNumber']) ? (string) $metadata['PhoneNumber'] : '',
            'raw_response' => json_encode($data),
            'verified_at' => $resultCode === 0 ? now() : null,
            'updated_at' => now(),
            'id' => (int) $payment['id'],
        ]);

        if ($resultCode === 0 && !empty($payment['order_id'])) {
            $stmt = $pdo->prepare('UPDATE orders SET payment_status = "paid", updated_at = :updated_at WHERE id = :id');
            $stmt->execute([
                'updated_at' => now(),
                'id' => (int) $payment['order_id'],
            ]);
        } elseif ($resultCode !== 0 && !empty($payment['order_id'])) {
            $stmt = $pdo->prepare('UPDATE orders SET payment_status = "failed", updated_at = :updated_at WHERE id = :id AND payment_status = "unpaid"');
            $stmt->execute([
                'updated_at' => now(),
                'id' => (int) $payment['order_id'],
            ]);
        }
    }

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    file_put_contents(dirname(__DIR__) . '/storage/daraja_log.txt', date('c') . "\nCallback DB error: " . $exception->getMessage() . "\n\n", FILE_APPEND);
}

http_response_code(200);
echo json_encode(['ResultCode' => 0, 'ResultDesc' => $resultDesc ?: 'Accepted']);
