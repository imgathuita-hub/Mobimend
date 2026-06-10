<?php
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

// Log everything first for debugging
file_put_contents(__DIR__ . '/../storage/daraja_log.txt', date('c') . "\n" . $raw . "\n\n", FILE_APPEND);

$result = $data['Body']['stkCallback'] ?? null;
if (!$result) exit;

$resultCode = $result['ResultCode'];   // 0 = success
$checkoutID = $result['CheckoutRequestID'];

if ($resultCode === 0) {
    $items = $result['CallbackMetadata']['Item'];
    $meta  = array_column($items, 'Value', 'Name');
    $mpesaRef  = $meta['MpesaReceiptNumber'];
    $amount    = $meta['Amount'];
    $phone     = $meta['PhoneNumber'];

    // TODO: update your database — mark order as paid
    // e.g., updateOrderStatus($checkoutID, 'paid', $mpesaRef);
}

http_response_code(200);
echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);