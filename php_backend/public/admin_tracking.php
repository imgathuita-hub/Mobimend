<?php

declare(strict_types=1);

require __DIR__ . '/session_bootstrap.php';
session_start();

require dirname(__DIR__) . '/src/bootstrap.php';

use Mobimend\Config\Database;

$pdo = Database::connection();
$adminRoles = ['admin', 'super_admin', 'technician', 'finance'];
$user = $_SESSION['admin_user'] ?? null;
if (is_array($user) && !in_array((string) ($user['role'] ?? ''), $adminRoles, true)) {
    unset($_SESSION['admin_user']);
    $user = null;
}

$message = (string) ($_GET['message'] ?? '');
$tone = (string) ($_GET['tone'] ?? 'info');
$ref = trim((string) ($_GET['ref'] ?? $_GET['order'] ?? ''));
$phone = trim((string) ($_GET['phone'] ?? ''));
$record = null;
$recordType = '';
$historyRows = [];

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function money(mixed $value): string
{
    return 'KES ' . number_format((float) $value, 2);
}

function pretty_status(mixed $status): string
{
    return ucwords(str_replace(['_', '-'], ' ', (string) $status));
}

function pretty_date(mixed $value): string
{
    $timestamp = strtotime((string) $value);

    return $timestamp ? date('M j, Y g:i A', $timestamp) : '';
}

function status_tone(mixed $status): string
{
    $value = strtolower((string) $status);
    if (in_array($value, ['paid', 'completed', 'ready', 'shipped', 'confirmed'], true)) {
        return 'good';
    }
    if (in_array($value, ['failed', 'cancelled', 'refunded'], true)) {
        return 'bad';
    }
    if (in_array($value, ['pending', 'processing', 'partially_paid', 'requires_review', 'unpaid', 'in progress'], true)) {
        return 'warn';
    }

    return 'neutral';
}

function find_tracking_record(PDO $pdo, string $ref, string $phone = ''): ?array
{
    if ($ref === '') {
        return null;
    }

    $order = $pdo->prepare(
        'SELECT o.*, p.status AS payment_record_status, p.payment_method, p.mpesa_receipt_number,
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
    if ($record) {
        return $record;
    }

    $repair = $pdo->prepare(
        'SELECT rb.*, p.status AS payment_record_status, p.payment_method, p.mpesa_receipt_number,
                p.checkout_request_id, p.updated_at AS payment_updated,
                "repair" AS record_type
         FROM repair_bookings rb
         LEFT JOIN payments p ON p.repair_booking_id = rb.id
         WHERE (rb.id = :booking_id
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
        'ref_receipt' => $ref,
        'ref_checkout' => $ref,
        'phone_filter' => $phone,
        'phone_repair' => $phone,
        'phone_payment' => $phone,
    ]);
    $record = $repair->fetch();

    return $record ?: null;
}

function tracking_history(PDO $pdo, string $type, int $id): array
{
    if ($type === 'repair') {
        $stmt = $pdo->prepare(
            'SELECT status, note, created_at
             FROM repair_status_updates
             WHERE repair_booking_id = :id AND customer_visible = 1
             ORDER BY created_at ASC'
        );
    } else {
        $stmt = $pdo->prepare(
            'SELECT status, note, created_at
             FROM order_status_updates
             WHERE order_id = :id AND customer_visible = 1
             ORDER BY created_at ASC'
        );
    }
    $stmt->execute(['id' => $id]);

    return $stmt->fetchAll();
}

function tracking_reference(array $record, string $type): string
{
    return $type === 'repair' ? (string) $record['id'] : (string) ($record['order_number'] ?? '');
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
            'title' => 'No tracking record selected.',
            'body' => 'Enter a customer reference to generate the tracking response.',
            'next' => 'Use an order number, repair booking ID, M-Pesa receipt, or checkout request ID.',
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
                'next' => 'If you already paid, track again using your M-Pesa receipt number.',
            ];
        }
        if (in_array($status, ['pending', 'confirmed', 'processing'], true)) {
            return [
                'title' => 'Your order is being prepared.',
                'body' => $latestNote !== '' ? $latestNote : 'Our team is preparing your order and will update the tracking page when it is ready or dispatched.',
                'next' => 'Please check the tracking page again for the next fulfillment update.',
            ];
        }
        if (in_array($status, ['ready', 'shipped'], true)) {
            return [
                'title' => $status === 'ready' ? 'Your order is ready.' : 'Your order is on the way.',
                'body' => $latestNote !== '' ? $latestNote : 'Your order has reached the next fulfillment stage.',
                'next' => $status === 'ready' ? 'Coordinate pickup or delivery with Mobimend.' : 'Watch for delivery communication from our team.',
            ];
        }
        if ($status === 'completed') {
            return [
                'title' => 'Your order is complete.',
                'body' => $latestNote !== '' ? $latestNote : 'This order has been completed. Thank you for shopping with Mobimend.',
                'next' => 'Contact support if you need after-delivery help.',
            ];
        }
    }

    if ($recordType === 'repair') {
        if (in_array($status, ['pending', 'confirmed'], true)) {
            return [
                'title' => 'Your repair booking is in the queue.',
                'body' => $latestNote !== '' ? $latestNote : 'We have received your repair booking and will update the tracking page when diagnosis starts.',
                'next' => 'Keep your booking details ready for the next update.',
            ];
        }
        if ($status === 'in progress') {
            return [
                'title' => 'Your repair is in progress.',
                'body' => $latestNote !== '' ? $latestNote : 'A technician is working on your device and will share the next repair checkpoint.',
                'next' => 'Check back for diagnosis, parts, or completion updates.',
            ];
        }
        if ($status === 'ready') {
            return [
                'title' => 'Your device is ready.',
                'body' => $latestNote !== '' ? $latestNote : 'Your repair is ready for collection or delivery coordination.',
                'next' => 'Contact Mobimend to arrange pickup or delivery.',
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

$adminCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

if ($user && $ref !== '') {
    $record = find_tracking_record($pdo, $ref, $phone);
    if ($record) {
        $recordType = (string) $record['record_type'];
        $historyRows = tracking_history($pdo, $recordType, (int) $record['id']);
        if ($historyRows === []) {
            $historyRows[] = [
                'status' => (string) $record['status'],
                'note' => $recordType === 'repair' ? 'Repair booking received by Mobimend.' : 'Order received by Mobimend.',
                'created_at' => (string) ($record['created_at'] ?? $record['booking_date'] ?? ''),
            ];
        }
    }
}

$autoResponse = automated_tracking_response($record, $recordType, $historyRows);
$publicTrackingUrl = '';
$recordTitle = '';
$customerName = '';
$customerPhone = '';
$paymentStatus = '';
if ($record) {
    $recordTitle = $recordType === 'repair'
        ? 'Repair booking #' . (int) $record['id']
        : 'Order ' . (string) ($record['order_number'] ?? '');
    $customerName = (string) ($record['customer_name'] ?? '');
    $customerPhone = $recordType === 'repair'
        ? (string) ($record['phone_number'] ?? '')
        : (string) ($record['customer_phone'] ?? '');
    $paymentStatus = (string) ($record['payment_record_status'] ?? $record['payment_status'] ?? '');
    $publicTrackingUrl = 'track.php?' . http_build_query([
        'ref' => tracking_reference($record, $recordType),
        'phone' => $customerPhone,
    ]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Tracking Queries | Mobimend</title>
  <link rel="stylesheet" href="style.css">
  <link rel="stylesheet" href="admin_ops.css">
  <style>
    body { margin: 0; background: #f6f8fb; color: #111827; font-family: Inter, Arial, sans-serif; }
    .admin-hero { background: #111827; color: #fff; padding: 18px 24px; }
    .shell { max-width: 980px; margin: 0 auto; padding: 22px 16px 36px; }
    .panel { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 18px; box-shadow: 0 12px 28px rgba(15, 23, 42, 0.05); }
    .panel + .panel { margin-top: 14px; }
    .panel h2, .panel h3 { margin: 0; }
    .copy { color: #64748b; margin: 6px 0 0; }
    .banner { margin-bottom: 14px; border-radius: 8px; padding: 12px 14px; font-weight: 800; }
    .banner.success { background: #ecfdf5; color: #166534; border: 1px solid #bbf7d0; }
    .banner.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
    .banner.info { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
    .search-form { display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(180px, .8fr) auto; gap: 10px; align-items: end; margin-top: 16px; }
    label { display: grid; gap: 6px; color: #475569; font-size: 11px; font-weight: 900; text-transform: uppercase; }
    input, button { border-radius: 8px; border: 1px solid #d1d5db; padding: 10px; font: inherit; }
    button, .button-link { display: inline-flex; align-items: center; justify-content: center; min-height: 40px; border: 0; border-radius: 8px; background: #1766c5; color: #fff; font-weight: 900; text-decoration: none; cursor: pointer; }
    .button-link.secondary { background: #eef2f7; color: #334155; border: 1px solid #d8dee8; }
    .result-head { display: flex; gap: 10px; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; }
    .result-actions { display: flex; gap: 8px; flex-wrap: wrap; }
    .pill { display: inline-flex; align-items: center; min-height: 24px; padding: 3px 8px; border-radius: 999px; background: #eef2f7; color: #334155; font-size: 12px; font-weight: 900; }
    .pill.good { background: #dcfce7; color: #166534; }
    .pill.warn { background: #fef3c7; color: #92400e; }
    .pill.bad { background: #fee2e2; color: #991b1b; }
    .pill.neutral { background: #e0f2fe; color: #075985; }
    .facts { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin-top: 14px; }
    .fact { border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px; background: #f8fafc; min-width: 0; }
    .fact span { display: block; color: #64748b; font-size: 11px; font-weight: 900; text-transform: uppercase; }
    .fact strong { display: block; margin-top: 5px; overflow-wrap: anywhere; }
    .auto-response { margin-top: 14px; border: 1px solid #bfdbfe; border-radius: 8px; padding: 16px; background: #eff6ff; }
    .auto-response h3 { color: #1d4ed8; }
    .auto-response p { margin: 9px 0 0; color: #334155; line-height: 1.55; }
    .timeline { display: grid; gap: 10px; margin-top: 14px; }
    .timeline-row { display: grid; grid-template-columns: 34px minmax(0, 1fr); gap: 10px; align-items: start; }
    .timeline-dot { width: 34px; height: 34px; border-radius: 50%; display: grid; place-items: center; background: #dbeafe; color: #1d4ed8; font-weight: 900; }
    .timeline-body { border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; background: #fff; }
    .timeline-body p { margin: 6px 0 0; color: #475569; white-space: pre-wrap; }
    .empty { border: 1px dashed #cbd5e1; border-radius: 8px; background: #f8fafc; color: #64748b; padding: 18px; }
    .muted { color: #64748b; }
    @media (max-width: 760px) { .search-form, .facts { grid-template-columns: 1fr; } .shell { padding: 14px 12px 28px; } }
  </style>
</head>
<body class="admin-ops">
  <header class="admin-hero">
    <div class="ops-header-inner">
      <div class="ops-brand">
        <h1>Tracking Queries</h1>
        <p>Enter a customer reference and return the same simple tracking answer customers see on the public tracker.</p>
      </div>
      <nav class="ops-nav" aria-label="Admin navigation">
        <a href="admin_dashboard.php">Operations</a>
        <a href="admin_orders.php">Orders</a>
        <a href="admin_repairs.php">Repairs</a>
        <a class="active" href="admin_tracking.php">Tracking</a>
        <a href="logout.php">Logout</a>
      </nav>
    </div>
  </header>

  <main class="shell">
    <?php if ($message !== ''): ?><div class="banner <?= h($tone) ?>"><?= h($message) ?></div><?php endif; ?>

    <?php if (!$user): ?>
      <section class="panel" style="max-width: 460px; margin: 40px auto;">
        <h2>Admin Login</h2>
        <?php if ($adminCount === 0): ?><div class="banner info">No admin exists yet. Start with <a href="setup_admin.php">setup_admin.php</a>.</div><?php endif; ?>
        <p class="copy">Sign in through the admin dashboard to use tracking queries.</p>
        <a class="button-link" style="margin-top: 14px;" href="admin_dashboard.php">Go to admin login</a>
      </section>
    <?php else: ?>
      <section class="panel">
        <h2>Customer tracking query</h2>
        <p class="copy">Search by order number, repair booking ID, M-Pesa receipt, or checkout request ID.</p>
        <form class="search-form" method="get" action="admin_tracking.php">
          <label>Reference
            <input name="ref" value="<?= h($ref) ?>" placeholder="Order number, booking ID, receipt, checkout ID" required>
          </label>
          <label>Phone
            <input name="phone" value="<?= h($phone) ?>" placeholder="Optional">
          </label>
          <button type="submit">Return response</button>
        </form>
      </section>

      <section class="panel">
        <?php if ($ref === ''): ?>
          <div class="empty">Enter a customer reference above to automatically return the tracking response.</div>
        <?php elseif (!$record): ?>
          <div class="empty">No tracking record matched <?= h($ref) ?><?= $phone !== '' ? ' with that phone number' : '' ?>.</div>
        <?php else: ?>
          <div class="result-head">
            <div>
              <h2><?= h($recordTitle) ?></h2>
              <p class="copy"><?= h($customerName ?: 'Unknown customer') ?><?= $customerPhone !== '' ? ' - ' . h($customerPhone) : '' ?></p>
            </div>
            <div class="result-actions">
              <span class="pill <?= h(status_tone($record['status'] ?? '')) ?>"><?= h(pretty_status($record['status'] ?? '')) ?></span>
              <a class="button-link secondary" href="<?= h($publicTrackingUrl) ?>" target="_blank" rel="noopener">Open public view</a>
            </div>
          </div>

          <div class="facts">
            <div class="fact"><span>Type</span><strong><?= h(pretty_status($recordType)) ?></strong></div>
            <div class="fact"><span>Reference</span><strong><?= h(tracking_reference($record, $recordType)) ?></strong></div>
            <div class="fact"><span>Payment</span><strong><?= h($paymentStatus !== '' ? pretty_status($paymentStatus) : 'Not recorded') ?></strong></div>
            <div class="fact">
              <span><?= $recordType === 'repair' ? 'Device' : 'Total' ?></span>
              <strong><?= $recordType === 'repair' ? h($record['device_model'] ?? '-') : h(money($record['grand_total'] ?? 0)) ?></strong>
            </div>
          </div>

          <div class="auto-response">
            <h3><?= h($autoResponse['title']) ?></h3>
            <p><?= h($autoResponse['body']) ?></p>
            <p><strong>Next:</strong> <?= h($autoResponse['next']) ?></p>
          </div>
        <?php endif; ?>
      </section>

      <?php if ($record): ?>
        <section class="panel">
          <h3>Customer-visible timeline</h3>
          <div class="timeline">
            <?php foreach ($historyRows as $index => $row): ?>
              <div class="timeline-row">
                <div class="timeline-dot"><?= $index + 1 ?></div>
                <div class="timeline-body">
                  <strong><?= h(pretty_status($row['status'])) ?></strong>
                  <p><?= h((string) ($row['note'] ?: 'Status updated.')) ?></p>
                  <?php if (!empty($row['created_at'])): ?><p class="muted"><?= h(pretty_date($row['created_at'])) ?></p><?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endif; ?>
    <?php endif; ?>
  </main>
</body>
</html>
