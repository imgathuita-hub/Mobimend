<?php

declare(strict_types=1);

namespace Mobimend\Services;

use PDO;
use Throwable;

final class PaymentCallbackQueue
{
    /** @param array<string,mixed>|null $payload */
    public static function enqueue(
        PDO $pdo,
        string $rawPayload,
        ?array $payload,
        string $reason,
        ?string $lastError = null,
        int $attempts = 0
    ): void {
        $checkoutRequestId = self::checkoutRequestId($payload);
        $availableAt = date('Y-m-d H:i:s', time() + self::backoffSeconds($attempts));

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO payment_callback_queue
                 (checkout_request_id, raw_payload, payload, status, attempts, available_at, last_error, created_at, updated_at)
                 VALUES
                 (:checkout_request_id, :raw_payload, :payload, "pending", :attempts, :available_at, :last_error, :created_at, :updated_at)'
            );
            $stmt->execute([
                'checkout_request_id' => $checkoutRequestId,
                'raw_payload' => $rawPayload,
                'payload' => $payload !== null ? json_encode($payload, JSON_THROW_ON_ERROR) : null,
                'attempts' => $attempts,
                'available_at' => $availableAt,
                'last_error' => substr($reason . ($lastError ? ': ' . $lastError : ''), 0, 1000),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            return;
        } catch (Throwable $exception) {
            error_log('Payment callback queue DB write failed: ' . $exception->getMessage());
        }

        self::writeFileFallback($rawPayload, $payload, $reason, $lastError);
    }

    /** @param array<string,mixed>|null $payload */
    public static function enqueueWithoutDatabase(string $rawPayload, ?array $payload, string $reason, ?string $lastError = null): void
    {
        self::writeFileFallback($rawPayload, $payload, $reason, $lastError);
    }

    /** @param array<string,mixed>|null $payload */
    private static function checkoutRequestId(?array $payload): ?string
    {
        $value = $payload['Body']['stkCallback']['CheckoutRequestID'] ?? null;
        return is_scalar($value) ? (string) $value : null;
    }

    private static function backoffSeconds(int $attempts): int
    {
        return min(900, 30 * (2 ** max(0, min($attempts, 5))));
    }

    /** @param array<string,mixed>|null $payload */
    private static function writeFileFallback(string $rawPayload, ?array $payload, string $reason, ?string $lastError): void
    {
        $dir = dirname(__DIR__, 2) . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $entry = [
            'created_at' => date(DATE_ATOM),
            'reason' => $reason,
            'last_error' => $lastError,
            'checkout_request_id' => self::checkoutRequestId($payload),
            'raw_payload' => $rawPayload,
        ];

        @file_put_contents($dir . '/payment_callback_queue.jsonl', json_encode($entry) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
