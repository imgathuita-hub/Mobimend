<?php

declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

define('DARAJA_ENV', strtolower((string) env('MPESA_ENVIRONMENT', 'sandbox')));
define('CONSUMER_KEY', trim((string) env('MPESA_CONSUMER_KEY', '')));
define('CONSUMER_SECRET', trim((string) env('MPESA_CONSUMER_SECRET', '')));
define('SHORTCODE', trim((string) env('MPESA_SHORTCODE', '')));
define('PASSKEY', trim((string) env('MPESA_PASSKEY', '')));
define('CALLBACK_URL', trim((string) env('MPESA_CALLBACK_URL', '')));
define('BASE_URL', DARAJA_ENV === 'production'
    ? 'https://api.safaricom.co.ke'
    : 'https://sandbox.safaricom.co.ke');
