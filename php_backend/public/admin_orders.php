<?php

declare(strict_types=1);

require __DIR__ . '/session_bootstrap.php';
session_start();

require dirname(__DIR__) . '/src/bootstrap.php';

use Mobimend\Config\Database;

$pdo = Database::connection();
$adminRoles = ['admin', 'super_admin', 'technician'];
$user = $_SESSION['admin_user'] ?? null;
if (is_array($user) && !in_array((string) ($user['role'] ?? ''), $adminRoles, true)) {
    unset($_SESSION['admin_user']);
    $user = null;
}
$message = (string) ($_GET['message'] ?? '');
$tone = (string) ($_GET['tone'] ?? 'info');

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
    .stat span { color: #64748b; display: block; }
    .stat strong { display: block; margin-top: 6px; font-size: 1.45rem; }
    .banner { margin-bottom: 14px; border-radius: 8px; padding: 12px 14px; font-weight: 700; }
    .banner.success { background: #ecfdf5; color: #166534; border: 1px solid #bbf7d0; }
    .banner.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
    .banner.info { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
    input, select, button { border-radius: 8px; border: 1px solid #d1d5db; padding: 10px; font: inherit; }
    button { background: #1766c5; color: #fff; border: 0; font-weight: 800; cursor: pointer; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 11px; border-bottom: 1px solid #eef2f7; text-align: left; vertical-align: top; }
    th { background: #f8fafc; }
    .table-wrap { overflow-x: auto; }
    .muted { color: #64748b; }
    .inline-form { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
    @media (max-width: 980px) { .stats-row { grid-template-columns: 1fr; } }
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
        <a href="admin_inventory.php">Inventory</a>
        <a class="active" href="admin_orders.php">Orders</a>
        <a href="admin_repairs.php">Repairs</a>
        <a href="admin_products.php">Products</a>
        <a href="logout.php">Logout</a>
      </nav>
    </div>
    <h1>Mobimend Orders Command Center</h1>
    <p>Retail and wholesale order reconciliation, payment status, revenue, and fulfillment state.</p>
    <p><a href="admin_products.php">Products</a> · <a href="admin_inventory.php">Inventory</a> · <a href="accessories.php">Shop</a> · <a href="wholesale.php">Wholesale</a></p>
  </header>

  <main class="shell">
    <?php if ($message !== ''): ?>
      <div class="banner <?= htmlspecialchars($tone) ?>"><?= htmlspecialchars($message) ?></div>
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
        <h2>Orders</h2>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Order</th>
                <th>Customer</th>
                <th>Total</th>
                <th>Payment</th>
                <th>Status</th>
                <th>Update</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($orders === []): ?>
                <tr><td colspan="6" class="muted">No orders yet. Retail and wholesale checkouts will appear here.</td></tr>
              <?php endif; ?>
              <?php foreach ($orders as $order): ?>
                <tr>
                  <td>
                    <strong><?= htmlspecialchars((string) $order['order_number']) ?></strong><br>
                    <span class="muted"><?= htmlspecialchars((string) $order['order_type']) ?> · <?= htmlspecialchars((string) $order['created_at']) ?></span>
                  </td>
                  <td>
                    <?= htmlspecialchars((string) $order['customer_name']) ?><br>
                    <span class="muted"><?= htmlspecialchars((string) $order['customer_phone']) ?></span>
                  </td>
                  <td>KES <?= number_format((float) $order['grand_total'], 2) ?></td>
                  <td>
                    <?= htmlspecialchars((string) ($order['payment_method'] ?? 'pending')) ?><br>
                    <span class="muted"><?= htmlspecialchars((string) ($order['payment_record_status'] ?? $order['payment_status'])) ?></span>
                  </td>
                  <td><?= htmlspecialchars((string) $order['status']) ?><br><span class="muted"><?= htmlspecialchars((string) $order['payment_status']) ?></span></td>
                  <td>
                    <form method="post" class="inline-form">
                      <input type="hidden" name="action" value="update_order">
                      <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                      <select name="status">
                        <?php foreach (['pending', 'confirmed', 'processing', 'ready', 'shipped', 'completed', 'cancelled', 'refunded'] as $status): ?>
                          <option value="<?= $status ?>" <?= $order['status'] === $status ? 'selected' : '' ?>><?= $status ?></option>
                        <?php endforeach; ?>
                      </select>
                      <select name="payment_status">
                        <?php foreach (['unpaid', 'partially_paid', 'paid', 'failed', 'refunded'] as $paymentStatus): ?>
                          <option value="<?= $paymentStatus ?>" <?= $order['payment_status'] === $paymentStatus ? 'selected' : '' ?>><?= $paymentStatus ?></option>
                        <?php endforeach; ?>
                      </select>
                      <select name="payment_record_status">
                        <?php foreach (['pending', 'processing', 'paid', 'failed', 'cancelled', 'refunded', 'requires_review'] as $paymentRecordStatus): ?>
                          <option value="<?= $paymentRecordStatus ?>" <?= ($order['payment_record_status'] ?? '') === $paymentRecordStatus ? 'selected' : '' ?>><?= $paymentRecordStatus ?></option>
                        <?php endforeach; ?>
                      </select>
                      <button type="submit">Save</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    <?php endif; ?>
  </main>
</body>
</html>
