<?php

declare(strict_types=1);

header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');

require __DIR__ . '/src/bootstrap.php';

use Mobimend\Config\Database;

function payment_status_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

$checkoutRequestId = trim((string) ($_GET['id'] ?? ''));

if ($checkoutRequestId === '' || strlen($checkoutRequestId) > 140 || !preg_match('/^[A-Za-z0-9_-]+$/', $checkoutRequestId)) {
    payment_status_response([
        'paid' => false,
        'status' => 'invalid',
        'message' => 'Valid CheckoutRequestID is required.',
    ], 400);
}

try {
    $pdo = Database::connection();
    $stmt = $pdo->prepare(
        'SELECT p.id, p.status, p.amount, p.currency, p.mpesa_receipt_number, p.updated_at,
                o.order_number, o.payment_status AS order_payment_status
         FROM payments p
         LEFT JOIN orders o ON o.id = p.order_id
         WHERE p.checkout_request_id = :checkout_request_id
         ORDER BY p.id DESC
         LIMIT 1'
    );
    $stmt->execute(['checkout_request_id' => $checkoutRequestId]);
    $payment = $stmt->fetch();

    if (!$payment) {
        payment_status_response([
            'paid' => false,
            'status' => 'pending',
            'message' => 'Payment confirmation is still pending.',
        ]);
    }

    $status = (string) $payment['status'];
    payment_status_response([
        'paid' => $status === 'paid',
        'status' => $status,
        'receipt' => $payment['mpesa_receipt_number'],
        'order_number' => $payment['order_number'],
        'amount' => (float) $payment['amount'],
        'currency' => $payment['currency'],
        'updated_at' => $payment['updated_at'],
    ]);
} catch (Throwable $exception) {
    payment_status_response([
        'paid' => false,
        'status' => 'error',
        'message' => $exception->getMessage(),
    ], 500);
}
