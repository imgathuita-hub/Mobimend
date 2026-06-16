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
$role = is_array($user) ? (string) ($user['role'] ?? '') : '';
$canSeePayments = in_array($role, ['admin', 'super_admin', 'finance'], true);

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
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

function order_track_url(array $order): string
{
    $query = ['ref' => (string) ($order['order_number'] ?? '')];
    if (!empty($order['customer_phone'])) {
        $query['phone'] = (string) $order['customer_phone'];
    }

    return 'track.php?' . http_build_query($query);
}

function status_class(mixed $status): string
{
    $value = strtolower((string) $status);
    if (in_array($value, ['paid', 'completed', 'ready', 'shipped', 'confirmed'], true)) {
        return 'good';
    }
    if (in_array($value, ['failed', 'cancelled', 'refunded'], true)) {
        return 'bad';
    }
    if (in_array($value, ['pending', 'processing', 'partially_paid', 'requires_review', 'unpaid'], true)) {
        return 'warn';
    }

    return 'neutral';
}

if (!$user && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $stmt = $pdo->prepare('SELECT id, name, email, password_hash, role FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);
    $foundUser = $stmt->fetch();

    if (
        !$foundUser
        || !password_verify($password, (string) $foundUser['password_hash'])
        || !in_array((string) $foundUser['role'], $adminRoles, true)
    ) {
        $message = 'Invalid credentials.';
        $tone = 'error';
    } else {
        $_SESSION['admin_user'] = [
            'id' => (int) $foundUser['id'],
            'name' => (string) $foundUser['name'],
            'email' => (string) $foundUser['email'],
            'role' => (string) $foundUser['role'],
        ];
        header('Location: admin_dashboard.php');
        exit;
    }
}

if ($user && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_order') {
    $orderId = (int) ($_POST['order_id'] ?? 0);
    $status = (string) ($_POST['status'] ?? 'pending');
    $paymentStatus = (string) ($_POST['payment_status'] ?? 'unpaid');
    $paymentRecordStatus = (string) ($_POST['payment_record_status'] ?? 'pending');
    $validStatuses = ['pending', 'confirmed', 'processing', 'ready', 'shipped', 'completed', 'cancelled', 'refunded'];
    $validPaymentStatuses = ['unpaid', 'partially_paid', 'paid', 'failed', 'refunded'];
    $validPaymentRecordStatuses = ['pending', 'processing', 'paid', 'failed', 'cancelled', 'refunded', 'requires_review'];

    if ($orderId > 0 && in_array($status, $validStatuses, true) && in_array($paymentStatus, $validPaymentStatuses, true)) {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('UPDATE orders SET status = :status, payment_status = :payment_status, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            'status' => $status,
            'payment_status' => $paymentStatus,
            'updated_at' => now(),
            'id' => $orderId,
        ]);
        $stmt = $pdo->prepare(
            'INSERT INTO order_status_updates (order_id, changed_by_user_id, status, note, customer_visible, created_at)
             VALUES (:order_id, :changed_by_user_id, :status, :note, 1, :created_at)'
        );
        $stmt->execute([
            'order_id' => $orderId,
            'changed_by_user_id' => (int) $user['id'],
            'status' => $status,
            'note' => 'Admin updated order and payment status.',
            'created_at' => now(),
        ]);

        if (in_array($paymentRecordStatus, $validPaymentRecordStatuses, true)) {
            $stmt = $pdo->prepare('UPDATE payments SET status = :status, verified_by_user_id = :verified_by, verified_at = :verified_at, updated_at = :updated_at WHERE order_id = :order_id');
            $stmt->execute([
                'status' => $paymentRecordStatus,
                'verified_by' => $paymentRecordStatus === 'paid' ? (int) $user['id'] : null,
                'verified_at' => $paymentRecordStatus === 'paid' ? now() : null,
                'updated_at' => now(),
                'order_id' => $orderId,
            ]);
        }

        $pdo->commit();
        redirect_with_message('admin_orders.php', 'Order updated.');
    }
}

$adminCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$orders = [];
$stats = ['orders' => 0, 'pending' => 0, 'paid' => 0, 'revenue' => 0.0, 'profit' => 0.0];

if ($user) {
    $orders = $pdo->query(
        'SELECT o.*, p.payment_method, p.status AS payment_record_status, p.mpesa_receipt_number
         FROM orders o
         LEFT JOIN payments p ON p.order_id = o.id
         ORDER BY o.created_at DESC
         LIMIT 80'
    )->fetchAll();
    foreach ($orders as $order) {
        $stats['orders']++;
        if ((string) $order['payment_status'] !== 'paid') {
            $stats['pending']++;
        }
        if ((string) $order['payment_status'] === 'paid') {
            $stats['paid']++;
            $stats['revenue'] += (float) $order['grand_total'];
        }
    }
    $stats['profit'] = (float) $pdo->query('SELECT COALESCE(SUM(profit), 0) FROM inventory_transactions')->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Orders Command Center | Mobimend</title>
  <link rel="stylesheet" href="style.css">
  <link rel="stylesheet" href="admin_ops.css">
  <style>
    body { margin: 0; background: #f6f8fb; color: #111827; font-family: Inter, Arial, sans-serif; }
    .admin-hero { background: #111827; color: #fff; padding: 28px 24px; }
    .admin-hero a { color: #dbeafe; }
    .shell { max-width: 1240px; margin: 0 auto; padding: 24px; }
    .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 18px; box-shadow: 0 16px 34px rgba(15, 23, 42, 0.06); }
    .stats-row { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 12px; margin-bottom: 18px; }
    .stat { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; }
    .stat span { color: #64748b; display: block; font-size: 12px; font-weight: 800; text-transform: uppercase; }
    .stat strong { display: block; margin-top: 6px; font-size: 1.45rem; }
    .banner { margin-bottom: 14px; border-radius: 8px; padding: 12px 14px; font-weight: 700; }
    .banner.success { background: #ecfdf5; color: #166534; border: 1px solid #bbf7d0; }
    .banner.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
    .banner.info { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
    input, select, button { border-radius: 8px; border: 1px solid #d1d5db; padding: 10px; font: inherit; }
    button { background: #1766c5; color: #fff; border: 0; font-weight: 800; cursor: pointer; }
    .muted { color: #64748b; }
    .orders-head { display: flex; justify-content: space-between; gap: 14px; align-items: flex-start; margin-bottom: 16px; }
    .orders-head h2 { margin: 0; }
    .orders-head p { margin: 5px 0 0; color: #64748b; max-width: 720px; }
    .orders-grid { display: grid; gap: 12px; }
    .order-card { display: grid; grid-template-columns: minmax(220px, 1.2fr) minmax(180px, .9fr) minmax(190px, .9fr) minmax(310px, 1.35fr); gap: 16px; align-items: stretch; padding: 16px; border: 1px solid #e2e8f0; border-radius: 8px; background: #fff; }
    .order-card:hover { border-color: #cbd5e1; box-shadow: 0 12px 28px rgba(15, 23, 42, 0.06); }
    .order-main { display: grid; gap: 10px; align-content: start; }
    .order-number { display: flex; align-items: center; gap: 9px; flex-wrap: wrap; }
    .order-number strong { font-size: 17px; }
    .order-meta { display: flex; flex-wrap: wrap; gap: 8px; color: #64748b; font-size: 12px; }
    .type-chip, .status-pill { display: inline-flex; align-items: center; min-height: 24px; border-radius: 999px; padding: 3px 8px; font-size: 12px; font-weight: 900; }
    .type-chip { background: #eef2f7; color: #334155; }
    .status-pill.good { background: #dcfce7; color: #166534; }
    .status-pill.warn { background: #fef3c7; color: #92400e; }
    .status-pill.bad { background: #fee2e2; color: #991b1b; }
    .status-pill.neutral { background: #e0f2fe; color: #075985; }
    .customer-block, .money-block, .update-panel { display: grid; gap: 8px; align-content: start; }
    .label { color: #64748b; font-size: 11px; font-weight: 900; letter-spacing: .04em; text-transform: uppercase; }
    .money { font-size: 18px; font-weight: 900; color: #111827; }
    .payment-line { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
    .inline-form { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)) auto; gap: 8px; align-items: end; }
    .inline-form label { display: grid; gap: 5px; color: #64748b; font-size: 11px; font-weight: 900; text-transform: uppercase; }
    .inline-form select { width: 100%; min-width: 0; }
    .order-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 2px; }
    .button-link { display: inline-flex; align-items: center; justify-content: center; min-height: 36px; border-radius: 8px; padding: 8px 11px; border: 1px solid #d8dee8; background: #f8fafc; color: #334155; text-decoration: none; font-size: 12px; font-weight: 900; }
    .button-link.primary { border-color: #1766c5; background: #1766c5; color: #fff; }
    .empty-state { padding: 28px; border: 1px dashed #cbd5e1; border-radius: 8px; color: #64748b; background: #f8fafc; }
    @media (max-width: 1100px) { .order-card { grid-template-columns: 1fr 1fr; } .inline-form { grid-template-columns: 1fr 1fr; } }
    @media (max-width: 760px) { .stats-row, .order-card, .inline-form { grid-template-columns: 1fr; } .shell { padding: 14px 12px 24px; } .orders-head { flex-direction: column; } }
  </style>
</head>
<body class="admin-ops">
  <header class="admin-hero">
    <div class="ops-header-inner">
      <div class="ops-brand">
        <h1>Orders Command Center</h1>
        <p>Reconcile retail and wholesale orders, payment state, fulfillment progress, revenue, and profit movement.</p>
      </div>
      <nav class="ops-nav" aria-label="Admin navigation">
        <a href="admin_dashboard.php">Operations</a>
        <?php if ($canSeePayments): ?><a href="admin_payments.php">Payments</a><?php endif; ?>
        <a href="admin_inventory.php">Inventory</a>
        <a class="active" href="admin_orders.php">Orders</a>
        <a href="admin_repairs.php">Repairs</a>
        <a href="admin_products.php">Products</a>
        <a href="logout.php">Logout</a>
      </nav>
    </div>
  </header>

  <main class="shell">
    <?php if ($message !== ''): ?>
      <div class="banner <?= h($tone) ?>"><?= h($message) ?></div>
    <?php endif; ?>

    <?php if (!$user): ?>
      <section class="card" style="max-width: 460px; margin: 40px auto;">
        <h2>Admin Login</h2>
        <?php if ($adminCount === 0): ?>
          <div class="banner info">No admin exists yet. Start with <a href="setup_admin.php">setup_admin.php</a>.</div>
        <?php endif; ?>
        <form method="post" style="display: grid; gap: 12px;">
          <input type="hidden" name="action" value="login">
          <input type="email" name="email" placeholder="Email" required>
          <input type="password" name="password" placeholder="Password" required>
          <button type="submit">Login</button>
        </form>
      </section>
    <?php else: ?>
      <section class="stats-row">
        <div class="stat"><span>Recent orders</span><strong><?= number_format((int) $stats['orders']) ?></strong></div>
        <div class="stat"><span>Pending payment</span><strong><?= number_format((int) $stats['pending']) ?></strong></div>
        <div class="stat"><span>Paid orders</span><strong><?= number_format((int) $stats['paid']) ?></strong></div>
        <div class="stat"><span>Paid revenue</span><strong>KES <?= number_format((float) $stats['revenue'], 0) ?></strong></div>
        <div class="stat"><span>Recorded profit</span><strong>KES <?= number_format((float) $stats['profit'], 0) ?></strong></div>
      </section>

      <section class="card">
        <div class="orders-head">
          <div>
            <h2>Orders</h2>
            <p>Scan fulfillment state, payment confidence, customer details, and the customer-facing tracking page from one workspace.</p>
          </div>
          <a class="button-link primary" href="track.php">Track customer order</a>
        </div>

        <div class="orders-grid">
          <?php if ($orders === []): ?>
            <div class="empty-state">No orders yet. Retail and wholesale checkouts will appear here.</div>
          <?php endif; ?>

          <?php foreach ($orders as $order): ?>
            <?php
              $paymentRecordStatus = (string) ($order['payment_record_status'] ?? $order['payment_status'] ?? 'pending');
              $paymentMethod = (string) ($order['payment_method'] ?? 'pending');
            ?>
            <article class="order-card">
              <div class="order-main">
                <div class="order-number">
                  <strong><?= h($order['order_number']) ?></strong>
                  <span class="type-chip"><?= h(pretty_status($order['order_type'])) ?></span>
                </div>
                <div class="order-meta">
                  <span><?= h(pretty_date($order['created_at'])) ?></span>
                  <?php if (!empty($order['mpesa_receipt_number'])): ?><span>Receipt <?= h($order['mpesa_receipt_number']) ?></span><?php endif; ?>
                </div>
                <div class="payment-line">
                  <span class="status-pill <?= h(status_class($order['status'])) ?>"><?= h(pretty_status($order['status'])) ?></span>
                  <span class="status-pill <?= h(status_class($order['payment_status'])) ?>"><?= h(pretty_status($order['payment_status'])) ?></span>
                </div>
              </div>

              <div class="customer-block">
                <span class="label">Customer</span>
                <strong><?= h($order['customer_name'] ?: 'Unknown customer') ?></strong>
                <span class="muted"><?= h($order['customer_phone'] ?: 'No phone on order') ?></span>
                <?php if (!empty($order['customer_email'])): ?><span class="muted"><?= h($order['customer_email']) ?></span><?php endif; ?>
              </div>

              <div class="money-block">
                <span class="label">Commercials</span>
                <span class="money">KES <?= number_format((float) $order['grand_total'], 2) ?></span>
                <div class="payment-line">
                  <span class="type-chip"><?= h(pretty_status($paymentMethod)) ?></span>
                  <span class="status-pill <?= h(status_class($paymentRecordStatus)) ?>"><?= h(pretty_status($paymentRecordStatus)) ?></span>
                </div>
              </div>

              <div class="update-panel">
                <form method="post" class="inline-form">
                  <input type="hidden" name="action" value="update_order">
                  <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                  <label>Fulfillment
                    <select name="status">
                      <?php foreach (['pending', 'confirmed', 'processing', 'ready', 'shipped', 'completed', 'cancelled', 'refunded'] as $status): ?>
                        <option value="<?= h($status) ?>" <?= $order['status'] === $status ? 'selected' : '' ?>><?= h(pretty_status($status)) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <label>Order pay
                    <select name="payment_status">
                      <?php foreach (['unpaid', 'partially_paid', 'paid', 'failed', 'refunded'] as $paymentStatus): ?>
                        <option value="<?= h($paymentStatus) ?>" <?= $order['payment_status'] === $paymentStatus ? 'selected' : '' ?>><?= h(pretty_status($paymentStatus)) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <label>Payment record
                    <select name="payment_record_status">
                      <?php foreach (['pending', 'processing', 'paid', 'failed', 'cancelled', 'refunded', 'requires_review'] as $recordStatus): ?>
                        <option value="<?= h($recordStatus) ?>" <?= ($order['payment_record_status'] ?? '') === $recordStatus ? 'selected' : '' ?>><?= h(pretty_status($recordStatus)) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <button type="submit">Save</button>
                </form>
                <div class="order-actions">
                  <a class="button-link" href="<?= h(order_track_url($order)) ?>">Open tracking</a>
                  <?php if ($canSeePayments): ?><a class="button-link" href="admin_payments.php?q=<?= h(urlencode((string) $order['order_number'])) ?>">Payments</a><?php endif; ?>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>
  </main>
</body>
</html>
