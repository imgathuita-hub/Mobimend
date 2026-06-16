<?php

declare(strict_types=1);

require __DIR__ . '/session_bootstrap.php';
session_start();

require dirname(__DIR__) . '/src/bootstrap.php';

use Mobimend\Config\Database;

$pdo = Database::connection();
$adminRoles = ['admin', 'super_admin', 'finance'];
$user = $_SESSION['admin_user'] ?? null;
if (is_array($user) && !in_array((string) ($user['role'] ?? ''), $adminRoles, true)) {
    unset($_SESSION['admin_user']);
    $user = null;
}

$message = (string) ($_GET['message'] ?? '');
$tone = (string) ($_GET['tone'] ?? 'info');
$role = is_array($user) ? (string) ($user['role'] ?? '') : '';
$canManageOps = in_array($role, ['admin', 'super_admin'], true);

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function money(mixed $value): string
{
    return 'KES ' . number_format((float) $value, 2);
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

$adminCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

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
        header('Location: admin_payments.php');
        exit;
    }
}

$status = (string) ($_GET['status'] ?? '');
$method = (string) ($_GET['method'] ?? '');
$search = trim((string) ($_GET['q'] ?? ''));
$validStatuses = ['', 'pending', 'processing', 'paid', 'failed', 'cancelled', 'refunded', 'requires_review'];
$validMethods = ['', 'mpesa_stk', 'bank_transfer', 'cash', 'card'];
if (!in_array($status, $validStatuses, true)) {
    $status = '';
}
if (!in_array($method, $validMethods, true)) {
    $method = '';
}

$payments = [];
$stats = [
    'total' => 0,
    'paid' => 0,
    'review' => 0,
    'failed' => 0,
    'revenue_30' => 0.0,
];

if ($user) {
    $stats = [
        'total' => (int) $pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn(),
        'paid' => (int) $pdo->query('SELECT COUNT(*) FROM payments WHERE status = "paid"')->fetchColumn(),
        'review' => (int) $pdo->query('SELECT COUNT(*) FROM payments WHERE status IN ("pending", "processing", "requires_review")')->fetchColumn(),
        'failed' => (int) $pdo->query('SELECT COUNT(*) FROM payments WHERE status IN ("failed", "cancelled")')->fetchColumn(),
        'revenue_30' => (float) $pdo->query(
            'SELECT COALESCE(SUM(amount), 0)
             FROM payments
             WHERE status = "paid"
               AND COALESCE(verified_at, updated_at, created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)'
        )->fetchColumn(),
    ];

    $where = [];
    $params = [];
    if ($status !== '') {
        $where[] = 'p.status = :status';
        $params['status'] = $status;
    }
    if ($method !== '') {
        $where[] = 'p.payment_method = :method';
        $params['method'] = $method;
    }
    if ($search !== '') {
        $where[] = '(p.checkout_request_id LIKE :q
            OR p.mpesa_receipt_number LIKE :q
            OR p.phone_number LIKE :q
            OR p.bank_reference LIKE :q
            OR o.order_number LIKE :q
            OR o.customer_name LIKE :q
            OR rb.customer_name LIKE :q)';
        $params['q'] = '%' . $search . '%';
    }

    $sql = 'SELECT p.*, o.order_number, o.order_type, o.customer_name AS order_customer,
                   rb.id AS repair_id, rb.customer_name AS repair_customer,
                   u.name AS account_name, verifier.name AS verified_by_name
            FROM payments p
            LEFT JOIN orders o ON o.id = p.order_id
            LEFT JOIN repair_bookings rb ON rb.id = p.repair_booking_id
            LEFT JOIN users u ON u.id = p.user_id
            LEFT JOIN users verifier ON verifier.id = p.verified_by_user_id';
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY p.created_at DESC LIMIT 150';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $payments = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Payments Ledger | Mobimend</title>
  <link rel="stylesheet" href="admin_ops.css">
  <style>
    body { margin: 0; background: #f6f8fb; color: #111827; font-family: Inter, Arial, sans-serif; }
    .shell { max-width: 1240px; margin: 0 auto; padding: 24px; }
    .stats-row { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
    .stat { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; }
    .stat span, .muted { color: #64748b; }
    .stat strong { display: block; margin-top: 6px; font-size: 1.35rem; }
    .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 18px; }
    .banner { margin-bottom: 14px; border-radius: 8px; padding: 12px 14px; font-weight: 700; }
    .banner.success { background: #ecfdf5; color: #166534; border: 1px solid #bbf7d0; }
    .banner.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
    .filters { display: grid; grid-template-columns: 1fr 180px 180px auto; gap: 10px; align-items: end; margin-bottom: 14px; }
    label { display: grid; gap: 5px; font-size: 12px; color: #475569; font-weight: 800; }
    input, select, button { border-radius: 8px; border: 1px solid #d1d5db; padding: 10px; font: inherit; }
    button { background: #1766c5; color: #fff; border: 0; font-weight: 800; cursor: pointer; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 11px; border-bottom: 1px solid #eef2f7; text-align: left; vertical-align: top; }
    th { background: #f8fafc; color: #475569; font-size: 12px; text-transform: uppercase; }
    .table-wrap { overflow-x: auto; }
    .pill { display: inline-flex; align-items: center; min-height: 24px; padding: 3px 8px; border-radius: 999px; background: #eef2f7; color: #334155; font-size: 12px; font-weight: 800; }
    .pill.paid { background: #dcfce7; color: #166534; }
    .pill.requires_review, .pill.processing, .pill.pending { background: #fef3c7; color: #92400e; }
    .pill.failed, .pill.cancelled { background: #fee2e2; color: #991b1b; }
    .row-actions { display: flex; gap: 8px; flex-wrap: wrap; }
    @media (max-width: 920px) { .stats-row, .filters { grid-template-columns: 1fr; } }
  </style>
</head>
<body class="admin-ops">
  <header class="admin-hero">
    <div class="ops-header-inner">
      <div class="ops-brand"><h1>Payments Ledger</h1><p>Payment records only: M-Pesa, bank transfer, cash, card, receipts, references, and verification state.</p></div>
      <nav class="ops-nav" aria-label="Admin navigation">
        <a href="admin_dashboard.php">Operations</a>
        <a href="admin_payments.php" class="active">Payments</a>
        <a href="admin_orders.php">Orders</a>
        <?php if ($canManageOps): ?><a href="admin_repairs.php">Repairs</a><?php endif; ?>
        <?php if ($canManageOps): ?><a href="admin_inventory.php">Inventory</a><?php endif; ?>
        <?php if ($canManageOps): ?><a href="admin_blog.php">Blog</a><?php endif; ?>
        <a href="logout.php">Logout</a>
      </nav>
    </div>
  </header>

  <main class="shell">
    <?php if ($message !== ''): ?><div class="banner <?= h($tone) ?>"><?= h($message) ?></div><?php endif; ?>

    <?php if (!$user): ?>
      <section class="card" style="max-width: 460px; margin: 40px auto;">
        <h2>Finance Login</h2>
        <?php if ($adminCount === 0): ?><div class="banner info">No admin exists yet. Start with <a href="setup_admin.php">setup_admin.php</a>.</div><?php endif; ?>
        <form method="post" style="display: grid; gap: 12px;">
          <input type="hidden" name="action" value="login">
          <input type="email" name="email" placeholder="Email" required>
          <input type="password" name="password" placeholder="Password" required>
          <button type="submit">Login</button>
        </form>
      </section>
    <?php else: ?>
      <section class="stats-row" aria-label="Payment summary">
        <div class="stat"><span>Total payments</span><strong><?= number_format($stats['total']) ?></strong></div>
        <div class="stat"><span>Paid</span><strong><?= number_format($stats['paid']) ?></strong></div>
        <div class="stat"><span>Needs review</span><strong><?= number_format($stats['review']) ?></strong></div>
        <div class="stat"><span>Failed / cancelled</span><strong><?= number_format($stats['failed']) ?></strong></div>
        <div class="stat"><span>Paid last 30 days</span><strong><?= h(money($stats['revenue_30'])) ?></strong></div>
      </section>

      <section class="card">
        <form class="filters" method="get" action="admin_payments.php">
          <label>Search
            <input name="q" value="<?= h($search) ?>" placeholder="Receipt, checkout ID, phone, order, customer">
          </label>
          <label>Status
            <select name="status">
              <?php foreach ($validStatuses as $option): ?>
                <option value="<?= h($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= h($option === '' ? 'All statuses' : pretty_status($option)) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Method
            <select name="method">
              <?php foreach ($validMethods as $option): ?>
                <option value="<?= h($option) ?>" <?= $method === $option ? 'selected' : '' ?>><?= h($option === '' ? 'All methods' : pretty_status($option)) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <button type="submit">Apply</button>
        </form>

        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Payment</th>
                <th>Customer / Source</th>
                <th>Amount</th>
                <th>Status</th>
                <th>References</th>
                <th>Dates</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($payments === []): ?><tr><td colspan="6" class="muted">No payments match this view.</td></tr><?php endif; ?>
              <?php foreach ($payments as $payment): ?>
                <?php
                  $customer = (string) ($payment['order_customer'] ?: $payment['repair_customer'] ?: $payment['account_name'] ?: $payment['phone_number'] ?: 'Unknown customer');
                  $source = !empty($payment['order_number'])
                      ? 'Order ' . (string) $payment['order_number']
                      : (!empty($payment['repair_id']) ? 'Repair #' . (int) $payment['repair_id'] : 'Standalone payment');
                ?>
                <tr>
                  <td><strong>#<?= (int) $payment['id'] ?></strong><br><span class="muted"><?= h(pretty_status((string) $payment['payment_method'])) ?></span></td>
                  <td><strong><?= h($customer) ?></strong><br><span class="muted"><?= h($source) ?></span></td>
                  <td><strong><?= h(money($payment['amount'])) ?></strong><br><span class="muted"><?= h($payment['currency']) ?></span></td>
                  <td><span class="pill <?= h($payment['status']) ?>"><?= h(pretty_status((string) $payment['status'])) ?></span></td>
                  <td>
                    <span class="muted">Receipt:</span> <?= h($payment['mpesa_receipt_number'] ?: '-') ?><br>
                    <span class="muted">Checkout:</span> <?= h($payment['checkout_request_id'] ?: '-') ?><br>
                    <span class="muted">Bank:</span> <?= h($payment['bank_reference'] ?: '-') ?>
                  </td>
                  <td>
                    <span class="muted">Created:</span> <?= h(pretty_date($payment['created_at'])) ?><br>
                    <span class="muted">Verified:</span> <?= h(pretty_date($payment['verified_at'] ?? '')) ?: '-' ?><br>
                    <span class="muted">By:</span> <?= h($payment['verified_by_name'] ?: '-') ?>
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
