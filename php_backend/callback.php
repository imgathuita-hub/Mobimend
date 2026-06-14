<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use Mobimend\Config\Database;
use Mobimend\Services\MpesaCallbackProcessor;
use Mobimend\Services\PaymentAuditLogger;
use Mobimend\Services\PaymentCallbackQueue;

header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input') ?: '';
$pdo = null;
$decoded = null;

try {
    $pdo = Database::connection();
    verify_callback_signature($raw);

    $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new InvalidArgumentException('Callback payload must be a JSON object.');
    }

    $callback = MpesaCallbackProcessor::validatePayload($decoded);
    $checkoutRequestId = (string) $callback['CheckoutRequestID'];

    PaymentAuditLogger::record($pdo, null, 'mpesa.callback.received', 'received', $decoded, null, $checkoutRequestId);

    retry_operation(static function () use ($pdo, $decoded): void {
        MpesaCallbackProcessor::process($pdo, $decoded);
    }, max(1, (int) env('MPESA_CALLBACK_RETRY_ATTEMPTS', '3')));

    http_response_code(200);
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Callback processed successfully']);
} catch (JsonException|InvalidArgumentException $exception) {
    if ($pdo instanceof \PDO) {
        PaymentCallbackQueue::enqueue($pdo, $raw, is_array($decoded) ? $decoded : null, 'invalid_callback', $exception->getMessage());
        PaymentAuditLogger::record($pdo, null, 'mpesa.callback.rejected', 'invalid', is_array($decoded) ? $decoded : null, [
            'error' => $exception->getMessage(),
        ]);
    } else {
        PaymentCallbackQueue::enqueueWithoutDatabase($raw, is_array($decoded) ? $decoded : null, 'invalid_callback', $exception->getMessage());
    }

    http_response_code(400);
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Invalid callback payload']);
} catch (Throwable $exception) {
    if ($pdo instanceof \PDO) {
        PaymentCallbackQueue::enqueue($pdo, $raw, is_array($decoded) ? $decoded : null, 'processing_failed', $exception->getMessage());
        PaymentAuditLogger::record($pdo, null, 'mpesa.callback.queued', 'queued', is_array($decoded) ? $decoded : null, [
            'error' => $exception->getMessage(),
        ]);
    } else {
        PaymentCallbackQueue::enqueueWithoutDatabase($raw, is_array($decoded) ? $decoded : null, 'processing_failed', $exception->getMessage());
    }

    error_log('Callback processing queued: ' . $exception->getMessage());
    http_response_code(200);
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Callback queued for retry']);
}

function verify_callback_signature(string $rawPayload): void
{
    $secret = trim((string) env('MPESA_CALLBACK_SIGNATURE_SECRET', ''));
    if ($secret === '') {
        return;
    }

    $provided = callback_header('X-Mobimend-Signature')
        ?: callback_header('X-Hub-Signature-256')
        ?: callback_header('X-Mpesa-Signature');

    if ($provided === '') {
        throw new InvalidArgumentException('Missing callback signature.');
    }

    $provided = str_starts_with($provided, 'sha256=') ? substr($provided, 7) : $provided;
    $expected = hash_hmac('sha256', $rawPayload, $secret);

    if (!hash_equals($expected, $provided)) {
        throw new InvalidArgumentException('Invalid callback signature.');
    }
}

function callback_header(string $name): string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return trim((string) ($_SERVER[$serverKey] ?? ''));
}

function retry_operation(callable $operation, int $maxAttempts): void
{
    $last = null;

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        try {
            $operation();
            return;
        } catch (Throwable $exception) {
            $last = $exception;
            if ($attempt < $maxAttempts) {
                usleep(min(250000 * $attempt, 1000000));
            }
        }
    }

    throw $last ?? new RuntimeException('Retry operation failed.');
}
