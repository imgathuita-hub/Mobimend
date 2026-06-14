<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use Mobimend\Config\Database;

header('Content-Type: application/json; charset=utf-8');

$checkoutRequestId = trim((string) ($_GET['checkout_id'] ?? $_GET['id'] ?? ''));
$paymentId = (int) ($_GET['payment_id'] ?? 0);

if ($checkoutRequestId === '' && $paymentId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing payment identifier']);
    exit;
}

try {
    $pdo = Database::connection();

    if ($checkoutRequestId !== '') {
        $stmt = $pdo->prepare(
            'SELECT p.id, p.status, p.amount, p.currency, p.phone_number, p.checkout_request_id,
                    p.mpesa_receipt_number, p.created_at, p.updated_at, o.order_number
             FROM payments p
             LEFT JOIN orders o ON o.id = p.order_id
             WHERE p.checkout_request_id = :checkout_request_id
             LIMIT 1'
        );
        $stmt->execute(['checkout_request_id' => $checkoutRequestId]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT p.id, p.status, p.amount, p.currency, p.phone_number, p.checkout_request_id,
                    p.mpesa_receipt_number, p.created_at, p.updated_at, o.order_number
             FROM payments p
             LEFT JOIN orders o ON o.id = p.order_id
             WHERE p.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $paymentId]);
    }

    $payment = $stmt->fetch();
    if (!$payment) {
        echo json_encode([
            'paid' => false,
            'status' => 'pending',
            'message' => 'Payment is pending confirmation.',
        ]);
        exit;
    }

    $status = (string) $payment['status'];
    echo json_encode([
        'paid' => $status === 'paid',
        'status' => $status,
        'message' => payment_status_message($status),
        'payment_id' => (int) $payment['id'],
        'checkout_request_id' => $payment['checkout_request_id'],
        'receipt' => $payment['mpesa_receipt_number'],
        'order_number' => $payment['order_number'],
        'amount' => (float) $payment['amount'],
        'currency' => $payment['currency'],
        'phone' => $payment['phone_number'],
        'created_at' => $payment['created_at'],
        'updated_at' => $payment['updated_at'],
    ]);
} catch (Throwable $exception) {
    error_log('Check Payment Error: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Internal server error']);
}

function payment_status_message(string $status): string
{
    return match ($status) {
        'paid' => 'Payment confirmed.',
        'failed' => 'Payment failed or was cancelled.',
        'cancelled' => 'Payment was cancelled.',
        'processing' => 'Payment prompt sent. Waiting for confirmation.',
        default => 'Payment is pending confirmation.',
    };
}
