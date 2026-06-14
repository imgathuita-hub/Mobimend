<?php

declare(strict_types=1);

namespace Mobimend\Services;

use InvalidArgumentException;
use PDO;
use RuntimeException;

final class MpesaCallbackProcessor
{
    /** @param array<string,mixed> $payload */
    public static function process(PDO $pdo, array $payload): void
    {
        $callback = self::validatePayload($payload);
        $checkoutRequestId = (string) $callback['CheckoutRequestID'];
        $merchantRequestId = (string) $callback['MerchantRequestID'];
        $resultCode = (int) $callback['ResultCode'];
        $resultDesc = (string) $callback['ResultDesc'];
        $metadata = self::metadata($callback);

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM payments WHERE checkout_request_id = :checkout_request_id FOR UPDATE');
            $stmt->execute(['checkout_request_id' => $checkoutRequestId]);
            $payment = $stmt->fetch();

            if (!$payment) {
                throw new RuntimeException('No payment found for checkout request ' . $checkoutRequestId);
            }

            $paymentId = (int) $payment['id'];
            $status = $resultCode === 0 ? 'paid' : 'failed';
            $orderPaymentStatus = $resultCode === 0 ? 'paid' : 'failed';
            $receipt = $metadata['MpesaReceiptNumber'] ?? null;
            $amount = isset($metadata['Amount']) ? (float) $metadata['Amount'] : (float) $payment['amount'];
            $phone = isset($metadata['PhoneNumber']) ? (string) $metadata['PhoneNumber'] : (string) $payment['phone_number'];

            $update = $pdo->prepare(
                'UPDATE payments
                 SET status = :status,
                     merchant_request_id = COALESCE(merchant_request_id, :merchant_request_id),
                     mpesa_receipt_number = COALESCE(:receipt, mpesa_receipt_number),
                     phone_number = :phone_number,
                     amount = :amount,
                     raw_response = :raw_response,
                     verified_at = CASE WHEN :paid = 1 THEN COALESCE(verified_at, :verified_at) ELSE verified_at END,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $update->execute([
                'status' => $status,
                'merchant_request_id' => $merchantRequestId,
                'receipt' => $receipt,
                'phone_number' => $phone,
                'amount' => $amount,
                'raw_response' => json_encode($payload, JSON_THROW_ON_ERROR),
                'paid' => $resultCode === 0 ? 1 : 0,
                'verified_at' => now(),
                'updated_at' => now(),
                'id' => $paymentId,
            ]);

            if (!empty($payment['order_id'])) {
                $order = $pdo->prepare('UPDATE orders SET payment_status = :payment_status, updated_at = :updated_at WHERE id = :id');
                $order->execute([
                    'payment_status' => $orderPaymentStatus,
                    'updated_at' => now(),
                    'id' => (int) $payment['order_id'],
                ]);
            }

            PaymentAuditLogger::record($pdo, $paymentId, 'mpesa.callback.processed', $status, $payload, [
                'result_code' => $resultCode,
                'result_desc' => $resultDesc,
                'metadata' => $metadata,
            ], $checkoutRequestId);

            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public static function validatePayload(array $payload): array
    {
        $callback = $payload['Body']['stkCallback'] ?? null;
        if (!is_array($callback)) {
            throw new InvalidArgumentException('Missing Body.stkCallback object.');
        }

        foreach (['MerchantRequestID', 'CheckoutRequestID', 'ResultCode', 'ResultDesc'] as $field) {
            if (!array_key_exists($field, $callback) || $callback[$field] === '') {
                throw new InvalidArgumentException('Missing callback field: ' . $field);
            }
        }

        if (!is_numeric($callback['ResultCode'])) {
            throw new InvalidArgumentException('ResultCode must be numeric.');
        }

        return $callback;
    }

    /** @param array<string,mixed> $callback @return array<string,mixed> */
    private static function metadata(array $callback): array
    {
        $items = $callback['CallbackMetadata']['Item'] ?? [];
        if (!is_array($items)) {
            return [];
        }

        $metadata = [];
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['Name'])) {
                continue;
            }
            $metadata[(string) $item['Name']] = $item['Value'] ?? null;
        }

        return $metadata;
    }
}
