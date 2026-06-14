<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use Mobimend\Config\Database;
use Mobimend\Services\DarajaStkPush;
use Mobimend\Services\PaymentAuditLogger;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['errorMessage' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$phone = trim((string) ($payload['phone'] ?? ''));
$amount = (float) ($payload['amount'] ?? 0);
$reference = trim((string) ($payload['reference'] ?? 'Mobimend'));
$paymentId = (int) ($payload['payment_id'] ?? 0);

if ($phone === '' || $amount < 1) {
    http_response_code(422);
    echo json_encode(['errorMessage' => 'Valid phone and amount are required.']);
    exit;
}

$pdo = Database::connection();

try {
    if ($paymentId > 0) {
        $stmt = $pdo->prepare('SELECT p.*, o.order_number FROM payments p LEFT JOIN orders o ON o.id = p.order_id WHERE p.id = :id');
        $stmt->execute(['id' => $paymentId]);
        $payment = $stmt->fetch();

        if (!$payment) {
            throw new RuntimeException('Payment record was not found.');
        }

        $amount = (float) $payment['amount'];
        $phone = (string) $payment['phone_number'];
        $reference = (string) ($payment['order_number'] ?: $reference);
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO payments
             (payment_method, amount, currency, status, phone_number, created_at, updated_at)
             VALUES
             ("mpesa_stk", :amount, "KES", "pending", :phone_number, :created_at, :updated_at)'
        );
        $stmt->execute([
            'amount' => $amount,
            'phone_number' => $phone,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $paymentId = (int) $pdo->lastInsertId();
    }

    PaymentAuditLogger::record($pdo, $paymentId, 'mpesa.stk.requested', 'pending', [
        'phone' => $phone,
        'amount' => $amount,
        'reference' => $reference,
    ]);

    $response = DarajaStkPush::initiate($amount, $phone, $reference, 'Mobimend payment');
    $checkoutRequestId = isset($response['CheckoutRequestID']) ? (string) $response['CheckoutRequestID'] : null;
    $merchantRequestId = isset($response['MerchantRequestID']) ? (string) $response['MerchantRequestID'] : null;
    $responseCode = (string) ($response['ResponseCode'] ?? '');
    $status = $responseCode === '0' ? 'processing' : 'failed';

    $update = $pdo->prepare(
        'UPDATE payments
         SET status = :status,
             merchant_request_id = :merchant_request_id,
             checkout_request_id = :checkout_request_id,
             raw_response = :raw_response,
             updated_at = :updated_at
         WHERE id = :id'
    );
    $update->execute([
        'status' => $status,
        'merchant_request_id' => $merchantRequestId,
        'checkout_request_id' => $checkoutRequestId,
        'raw_response' => json_encode($response, JSON_THROW_ON_ERROR),
        'updated_at' => now(),
        'id' => $paymentId,
    ]);

    PaymentAuditLogger::record($pdo, $paymentId, 'mpesa.stk.response', $status, $response, null, $checkoutRequestId);

    echo json_encode($response + [
        'status' => $status,
        'payment_id' => $paymentId,
    ]);
} catch (Throwable $exception) {
    if ($paymentId > 0) {
        PaymentAuditLogger::record($pdo, $paymentId, 'mpesa.stk.failed', 'failed', null, [
            'error' => $exception->getMessage(),
        ]);

        $stmt = $pdo->prepare('UPDATE payments SET status = "failed", updated_at = :updated_at WHERE id = :id');
        $stmt->execute(['updated_at' => now(), 'id' => $paymentId]);
    }

    error_log('STK Push Error: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['errorMessage' => 'Payment initiation failed. Please retry.']);
}
