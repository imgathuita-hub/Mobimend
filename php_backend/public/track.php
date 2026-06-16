<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Mobimend\Config\Database;

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function pretty_status(string $status): string
{
    return ucwords(str_replace(['_', '-'], ' ', $status));
}

function pretty_date(mixed $value): string
{
    $timestamp = strtotime((string) $value);

    return $timestamp ? date('M j, Y g:i A', $timestamp) : '';
}

function latest_tracking_step(array $historyRows): ?array
{
    if ($historyRows === []) {
        return null;
    }

    return $historyRows[array_key_last($historyRows)];
}

function automated_tracking_response(?array $record, string $recordType, array $historyRows): array
{
    if (!$record) {
        return [
            'title' => 'We are ready to check your status.',
            'body' => 'Enter your repair booking ID, order number, M-Pesa receipt, or checkout request ID and we will show the latest update available.',
            'next' => 'Use the phone number from your booking or order if you want a more exact match.',
        ];
    }

    $status = strtolower((string) ($record['status'] ?? ''));
    $paymentStatus = strtolower((string) ($record['payment_record_status'] ?? $record['payment_status'] ?? ''));
    $latest = latest_tracking_step($historyRows);
    $latestNote = trim((string) ($latest['note'] ?? ''));

    if ($recordType === 'order') {
        if (in_array($paymentStatus, ['unpaid', 'pending', 'processing', 'requires_review'], true)) {
            return [
                'title' => 'Your order is waiting for payment confirmation.',
                'body' => $latestNote !== '' ? $latestNote : 'We have your order, and fulfillment will continue once payment is confirmed.',
                'next' => 'Keep your M-Pesa confirmation nearby. If you already paid, use the receipt number to track again.',
            ];
        }
        if (in_array($status, ['confirmed', 'processing'], true)) {
            return [
                'title' => 'Your order is being prepared.',
                'body' => $latestNote !== '' ? $latestNote : 'Our team is preparing your items and will update this page when the order is ready or dispatched.',
                'next' => 'Check this page again later for the next fulfillment update.',
            ];
        }
        if (in_array($status, ['ready', 'shipped'], true)) {
            return [
                'title' => $status === 'ready' ? 'Your order is ready.' : 'Your order is on the way.',
                'body' => $latestNote !== '' ? $latestNote : 'The order has reached the next fulfillment stage.',
                'next' => $status === 'ready' ? 'Please coordinate pickup or delivery with Mobimend.' : 'Watch for delivery communication from our team.',
            ];
        }
        if ($status === 'completed') {
            return [
                'title' => 'Your order is complete.',
                'body' => $latestNote !== '' ? $latestNote : 'This order has been completed. Thank you for shopping with Mobimend.',
                'next' => 'Contact support if you need help after delivery.',
            ];
        }
    }

    if ($recordType === 'repair') {
        if (in_array($status, ['pending', 'confirmed'], true)) {
            return [
                'title' => 'Your repair booking is in the queue.',
                'body' => $latestNote !== '' ? $latestNote : 'We have received your repair request and will update this page when diagnosis starts.',
                'next' => 'Keep your device and booking details ready for the next update.',
            ];
        }
        if ($status === 'in progress') {
            return [
                'title' => 'Your repair is in progress.',
                'body' => $latestNote !== '' ? $latestNote : 'A technician is working on the device and will update the tracking timeline with the next checkpoint.',
                'next' => 'Check back for parts, diagnosis, or completion updates.',
            ];
        }
        if ($status === 'ready') {
            return [
                'title' => 'Your device is ready.',
                'body' => $latestNote !== '' ? $latestNote : 'Your repair is ready for collection or delivery coordination.',
                'next' => 'Please contact Mobimend to arrange pickup or delivery.',
            ];
        }
        if ($status === 'completed') {
            return [
                'title' => 'Your repair is complete.',
                'body' => $latestNote !== '' ? $latestNote : 'This repair has been completed. Thank you for trusting Mobimend.',
                'next' => 'Reach out if you need after-service support.',
            ];
        }
    }

    return [
        'title' => 'We found your tracking record.',
        'body' => $latestNote !== '' ? $latestNote : 'The latest status is shown below.',
        'next' => 'Follow the timeline for the most recent customer-visible update.',
    ];
}

$pdo = Database::connection();
$ref = trim((string) ($_GET['ref'] ?? $_GET['order'] ?? ''));
$phone = trim((string) ($_GET['phone'] ?? ''));
$record = null;
$recordType = '';
$historyRows = [];
$autoResponse = automated_tracking_response(null, '', []);

if ($ref !== '') {
    $order = $pdo->prepare(
        'SELECT o.*, p.status AS payment_record_status, p.mpesa_receipt_number,
                p.checkout_request_id, p.updated_at AS payment_updated,
                "order" AS record_type
         FROM orders o
         LEFT JOIN payments p ON p.order_id = o.id
         WHERE (o.order_number = :ref_order
                OR p.mpesa_receipt_number = :ref_receipt
                OR p.checkout_request_id = :ref_checkout)
           AND (:phone_filter = ""
                OR o.customer_phone = :phone_order
                OR p.phone_number = :phone_payment)
         ORDER BY p.updated_at DESC
         LIMIT 1'
    );
    $order->execute([
        'ref_order' => $ref,
        'ref_receipt' => $ref,
        'ref_checkout' => $ref,
        'phone_filter' => $phone,
        'phone_order' => $phone,
        'phone_payment' => $phone,
    ]);
    $record = $order->fetch();

    if (!$record) {
        $repair = $pdo->prepare(
            'SELECT rb.*, p.status AS payment_record_status, p.mpesa_receipt_number,
                    p.checkout_request_id, p.updated_at AS payment_updated,
                    "repair" AS record_type
             FROM repair_bookings rb
             LEFT JOIN payments p ON p.repair_booking_id = rb.id
             WHERE (rb.id = :booking_id
                    OR rb.customer_name LIKE :customer_name
                    OR p.mpesa_receipt_number = :ref_receipt
                    OR p.checkout_request_id = :ref_checkout)
               AND (:phone_filter = ""
                    OR rb.phone_number = :phone_repair
                    OR p.phone_number = :phone_payment)
             ORDER BY p.updated_at DESC
             LIMIT 1'
        );
        $repair->execute([
            'booking_id' => ctype_digit($ref) ? (int) $ref : 0,
            'customer_name' => '%' . $ref . '%',
            'ref_receipt' => $ref,
            'ref_checkout' => $ref,
            'phone_filter' => $phone,
            'phone_repair' => $phone,
            'phone_payment' => $phone,
        ]);
        $record = $repair->fetch();
    }

    if ($record) {
        $recordType = (string) $record['record_type'];
        if ($recordType === 'repair') {
            $history = $pdo->prepare(
                'SELECT status, note, created_at
                 FROM repair_status_updates
                 WHERE repair_booking_id = :id AND customer_visible = 1
                 ORDER BY created_at ASC'
            );
        } else {
            $history = $pdo->prepare(
                'SELECT status, note, created_at
                 FROM order_status_updates
                 WHERE order_id = :id AND customer_visible = 1
                 ORDER BY created_at ASC'
            );
        }
        $history->execute(['id' => (int) $record['id']]);
        $historyRows = $history->fetchAll();

        if ($historyRows === []) {
            $historyRows[] = [
                'status' => (string) $record['status'],
                'note' => $recordType === 'repair'
                    ? 'Repair booking received by Mobimend.'
                    : 'Order received by Mobimend.',
                'created_at' => (string) ($record['created_at'] ?? $record['booking_date'] ?? ''),
            ];
        }
    }
}

$autoResponse = automated_tracking_response($record, $recordType, $historyRows);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Track Order | Mobimend Spares</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&family=Montserrat:wght@700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <nav class="site-nav">
    <a class="nav-left" href="index.php"><img src="assets/LOGO FINAL MOBIMEND WH BG.png" alt="Mobimend" class="logo"><div class="brand"><h1>MOBIMEND</h1><p class="tagline">Tracking</p></div></a>
    <button class="menu-toggle" id="menu-toggle" aria-expanded="false" aria-label="Toggle navigation"><i class="fa-solid fa-bars"></i></button>
    <ul class="nav-links" id="nav-links">
      <li><a href="index.php">Home</a></li><li><a href="repair.php">Repair</a></li><li><a href="accessories.php">Shop</a></li><li><a href="wholesale.php">Wholesale</a></li><li><a href="blog.php">Blog</a></li><li><a class="active" href="track.php">Track</a></li><li><a href="account.php">Account</a></li>
    </ul>
  </nav>

  <main class="section alt">
    <div class="section-inner tracking-grid">
      <div class="track-card">
        <p class="section-kicker"><i class="fa-solid fa-satellite-dish"></i> Real-time style status</p>
        <h1 class="section-title">Track a repair, order, or payment.</h1>
        <p class="section-copy">Enter an order number, repair booking ID, M-Pesa receipt, or checkout request ID.</p>
        <form id="trackForm" class="form-grid" style="margin-top: 20px;" method="get" action="track.php">
          <div class="full">
            <label for="ref">Reference number</label>
            <input id="ref" name="ref" placeholder="MM-260616-ABC123, booking ID, or receipt" value="<?= h($ref) ?>" required>
          </div>
          <div>
            <label for="phone">Phone number</label>
            <input id="phone" name="phone" placeholder="07XX XXX XXX" value="<?= h($phone) ?>">
          </div>
          <div>
            <label>Type</label>
            <select disabled><option>Auto-detect</option></select>
          </div>
          <div class="full"><button class="btn-primary" type="submit">Show status</button></div>
        </form>
      </div>

      <div class="track-card" id="trackingResult">
        <?php if ($ref === ''): ?>
          <h3>Ready when you are</h3>
          <p>Submit a reference number to see the latest customer-visible tracking history.</p>
        <?php elseif (!$record): ?>
          <h3>No record found</h3>
          <p>We could not match <?= h($ref) ?><?= $phone !== '' ? ' with that phone number' : '' ?>. Check the reference and try again.</p>
        <?php else: ?>
          <?php
            $title = $recordType === 'repair'
                ? 'Repair booking #' . (int) $record['id']
                : 'Order ' . (string) $record['order_number'];
            $lastUpdated = (string) ($record['payment_updated'] ?? $record['updated_at'] ?? $record['created_at'] ?? '');
            $paymentStatus = (string) ($record['payment_record_status'] ?? $record['payment_status'] ?? '');
          ?>
          <h3><?= h($title) ?></h3>
          <p>
            <?= h(pretty_status((string) $record['status'])) ?>
            <?php if ($lastUpdated !== ''): ?> - last updated <?= h(pretty_date($lastUpdated)) ?><?php endif; ?>
          </p>
          <div style="margin: 16px 0; padding: 14px; border: 1px solid #bfdbfe; border-radius: 8px; background: #eff6ff;">
            <strong><?= h($autoResponse['title']) ?></strong>
            <p style="margin: 8px 0 0;"><?= h($autoResponse['body']) ?></p>
            <p style="margin: 8px 0 0;"><strong>Next:</strong> <?= h($autoResponse['next']) ?></p>
          </div>
          <div style="display: grid; gap: 8px; margin: 16px 0;">
            <p><strong>Customer:</strong> <?= h($record['customer_name'] ?? '') ?></p>
            <?php if ($recordType === 'repair'): ?>
              <p><strong>Device:</strong> <?= h($record['device_model'] ?? '') ?> - <?= h($record['repair_type'] ?? '') ?></p>
            <?php else: ?>
              <p><strong>Total:</strong> KES <?= number_format((float) ($record['grand_total'] ?? 0), 2) ?></p>
            <?php endif; ?>
            <?php if ($paymentStatus !== ''): ?>
              <p><strong>Payment:</strong> <?= h(pretty_status($paymentStatus)) ?><?= !empty($record['mpesa_receipt_number']) ? ' - Receipt ' . h($record['mpesa_receipt_number']) : '' ?></p>
            <?php endif; ?>
          </div>
          <div class="timeline">
            <?php foreach ($historyRows as $index => $step): ?>
              <?php $isLast = $index === array_key_last($historyRows); ?>
              <div class="timeline-step <?= $isLast ? 'live' : 'done' ?>">
                <div class="timeline-dot"><i class="fa-solid <?= $isLast ? 'fa-location-crosshairs' : 'fa-check' ?>"></i></div>
                <div>
                  <strong><?= h(pretty_status((string) $step['status'])) ?></strong>
                  <p><?= h((string) ($step['note'] ?: 'Status updated.')) ?></p>
                  <?php if (!empty($step['created_at'])): ?><p><?= h(pretty_date($step['created_at'])) ?></p><?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </main>

  <script src="chatbot.js"></script>
  <script src="site.js"></script>
</body>
</html>
