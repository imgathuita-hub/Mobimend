<?php
// daraja_config.php — NEVER commit this with real keys; use environment variables

define('DARAJA_ENV', 'sandbox'); // or 'production'
define('CONSUMER_KEY', getenv('DARAJA_CONSUMER_KEY') ?: 'RvUcgubNNYFtY5spszNNWAQLXv0GKDbeHyJK3wqc1cGiGbf3');
define('CONSUMER_SECRET', getenv('DARAJA_CONSUMER_SECRET') ?: 'VlWyiowGpFGWh6t6yMW12CZ1AKHS8POgNVEgz0tmAzW7PIStybtXrA62hVeDYIg9');
define('SHORTCODE', '174379'); // sandbox shortcode
define('PASSKEY', 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919'); // sandbox passkey
define('CALLBACK_URL', 'https://yourdomain.com/php_backend/callback.php');
define('BASE_URL', DARAJA_ENV === 'sandbox'
    ? 'https://sandbox.safaricom.co.ke'
    : 'https://api.safaricom.co.ke');