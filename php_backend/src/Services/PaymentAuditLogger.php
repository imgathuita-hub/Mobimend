<?php

declare(strict_types=1);

namespace Mobimend\Services;

use PDO;
use Throwable;

final class PaymentAuditLogger
{
    /** @param array<string,mixed>|null $payload @param array<string,mixed>|null $context */
    public static function record(
        PDO $pdo,
        ?int $paymentId,
        string $eventType,
        string $status,
        ?array $payload = null,
        ?array $context = null,
        ?string $checkoutRequestId = null
    ): void {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO payment_audit_logs
                 (payment_id, checkout_request_id, event_type, status, payload, context, ip_address, user_agent, created_at)
                 VALUES
                 (:payment_id, :checkout_request_id, :event_type, :status, :payload, :context, :ip_address, :user_agent, :created_at)'
            );
            $stmt->execute([
                'payment_id' => $paymentId,
                'checkout_request_id' => $checkoutRequestId,
                'event_type' => substr($eventType, 0, 80),
                'status' => substr($status, 0, 40),
                'payload' => $payload !== null ? json_encode($payload, JSON_THROW_ON_ERROR) : null,
                'context' => $context !== null ? json_encode($context, JSON_THROW_ON_ERROR) : null,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : null,
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            error_log('Payment audit write failed: ' . $exception->getMessage());
        }
    }
}
