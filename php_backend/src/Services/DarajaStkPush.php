<?php

declare(strict_types=1);

namespace Mobimend\Services;

use RuntimeException;

final class DarajaStkPush
{
    /** @return array<string,mixed> */
    public static function initiate(float $amount, string $phoneNumber, string $accountReference, string $description): array
    {
        $consumerKey = trim((string) env('MPESA_CONSUMER_KEY', ''));
        $consumerSecret = trim((string) env('MPESA_CONSUMER_SECRET', ''));
        $shortCode = trim((string) env('MPESA_SHORTCODE', ''));
        $passkey = trim((string) env('MPESA_PASSKEY', ''));
        $callbackUrl = trim((string) env('MPESA_CALLBACK_URL', ''));
        $environment = strtolower(trim((string) env('MPESA_ENVIRONMENT', 'sandbox')));

        if ($consumerKey === '' || $consumerSecret === '' || $shortCode === '' || $passkey === '') {
            throw new RuntimeException('M-Pesa STK is not configured. Add Daraja credentials to php_backend/.env.');
        }

        if ($callbackUrl === '') {
            $appUrl = rtrim((string) env('APP_URL', ''), '/');
            $callbackUrl = $appUrl !== '' ? $appUrl . '/callback.php' : '';
        }

        if ($callbackUrl === '') {
            throw new RuntimeException('M-Pesa callback URL is missing.');
        }

        $baseUrl = $environment === 'production'
            ? 'https://api.safaricom.co.ke'
            : 'https://sandbox.safaricom.co.ke';

        $accessToken = self::accessToken($baseUrl, $consumerKey, $consumerSecret);
        $timestamp = date('YmdHis');
        $password = base64_encode($shortCode . $passkey . $timestamp);
        $phone = self::normalizePhone($phoneNumber);

        $payload = [
            'BusinessShortCode' => $shortCode,
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => (string) env('MPESA_TRANSACTION_TYPE', 'CustomerPayBillOnline'),
            'Amount' => max(1, (int) round($amount)),
            'PartyA' => $phone,
            'PartyB' => $shortCode,
            'PhoneNumber' => $phone,
            'CallBackURL' => $callbackUrl,
            'AccountReference' => substr($accountReference, 0, 12),
            'TransactionDesc' => substr($description, 0, 64),
        ];

        return self::jsonPost($baseUrl . '/mpesa/stkpush/v1/processrequest', $payload, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ]);
    }

    private static function accessToken(string $baseUrl, string $consumerKey, string $consumerSecret): string
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL extension is required for Daraja STK Push.');
        }

        $curl = curl_init($baseUrl . '/oauth/v1/generate?grant_type=client_credentials');
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $consumerKey . ':' . $consumerSecret,
            CURLOPT_TIMEOUT => 30,
        ]);

        [$body, $error, $status] = self::curlWithRetry($curl);

        if ($body === false || $error !== '') {
            throw new RuntimeException('Could not connect to Daraja OAuth: ' . $error);
        }

        $decoded = json_decode((string) $body, true);
        if ($status >= 400 || !is_array($decoded) || empty($decoded['access_token'])) {
            throw new RuntimeException('Daraja OAuth rejected the request.');
        }

        return (string) $decoded['access_token'];
    }

    /** @param array<string,mixed> $payload @param list<string> $headers @return array<string,mixed> */
    private static function jsonPost(string $url, array $payload, array $headers): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL extension is required for Daraja STK Push.');
        }

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
        ]);

        [$body, $error, $status] = self::curlWithRetry($curl);

        if ($body === false || $error !== '') {
            throw new RuntimeException('Could not connect to Daraja STK endpoint: ' . $error);
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Daraja returned an unreadable response.');
        }

        $decoded['_http_status'] = $status;
        if ($status >= 400) {
            throw new RuntimeException((string) ($decoded['errorMessage'] ?? $decoded['ResponseDescription'] ?? 'Daraja STK request failed.'));
        }

        return $decoded;
    }

    private static function normalizePhone(string $phoneNumber): string
    {
        $digits = preg_replace('/\D+/', '', $phoneNumber) ?? '';

        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return '254' . substr($digits, 1);
        }

        if (str_starts_with($digits, '7') && strlen($digits) === 9) {
            return '254' . $digits;
        }

        if (str_starts_with($digits, '254') && strlen($digits) === 12) {
            return $digits;
        }

        throw new RuntimeException('Enter a valid Kenyan M-Pesa phone number.');
    }

    /** @return array{0:string|false,1:string,2:int} */
    private static function curlWithRetry(\CurlHandle $curl): array
    {
        $maxAttempts = max(1, (int) env('MPESA_RETRY_ATTEMPTS', '3'));
        $lastBody = false;
        $lastError = '';
        $lastStatus = 0;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $body = curl_exec($curl);
            $error = curl_error($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

            $lastBody = $body;
            $lastError = $error;
            $lastStatus = $status;

            if ($body !== false && $error === '' && ($status < 500 || $attempt === $maxAttempts)) {
                curl_close($curl);
                return [$body, $error, $status];
            }

            if ($attempt < $maxAttempts) {
                usleep(min(500000 * $attempt, 1500000));
            }
        }

        curl_close($curl);
        return [$lastBody, $lastError, $lastStatus];
    }
}
