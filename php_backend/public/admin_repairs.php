<?php

declare(strict_types=1);

require __DIR__ . '/session_bootstrap.php';
session_start();

require dirname(__DIR__) . '/src/bootstrap.php';

use Mobimend\Config\Database;

$pdo = Database::connection();
$adminRoles = ['admin', 'super_admin', 'technician'];
$message = '';
$tone = 'info';
$user = $_SESSION['admin_user'] ?? null;
if (is_array($user) && !in_array((string) ($user['role'] ?? ''), $adminRoles, true)) {
    unset($_SESSION['admin_user']);
    $user = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'login') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');

        if ($email === '' || $password === '') {
            $message = 'Email and password are required.';
            $tone = 'error';
        } else {
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
    } elseif ($user) {
        if ($action === 'update_status') {
            $bookingId = (int) ($_POST['booking_id'] ?? 0);
            $status = trim((string) ($_POST['status'] ?? 'Pending'));
            $allowed = ['Pending', 'In Progress', 'Completed', 'Cancelled'];

            if ($bookingId > 0 && in_array($status, $allowed, true)) {
                $stmt = $pdo->prepare('UPDATE repair_bookings SET status = :status, updated_at = :updated_at WHERE id = :id');
                $stmt->execute([
                    'id' => $bookingId,
                    'status' => $status,
                    'updated_at' => now(),
                ]);
                header('Location: admin_repairs.php?message=' . urlencode('Booking status updated.') . '&tone=' . urlencode('success'));
                exit;
            }

            $message = 'Please choose a valid booking status.';
            $tone = 'error';
        }

        if ($action === 'delete_booking') {
            $bookingId = (int) ($_POST['booking_id'] ?? 0);
            if ($bookingId > 0) {
                $stmt = $pdo->prepare('DELETE FROM repair_bookings WHERE id = :id');
                $stmt->execute(['id' => $bookingId]);
                header('Location: admin_repairs.php?message=' . urlencode('Booking deleted.') . '&tone=' . urlencode('success'));
                exit;
            }
        }
    }
}

if (isset($_GET['message'])) {
    $message = (string) $_GET['message'];
    $tone = (string) ($_GET['tone'] ?? 'info');
}

$adminCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$bookings = [];
$totalBookings = 0;
$pendingBookings = 0;
$completedBookings = 0;

if ($user) {
    $bookings = $pdo->query('SELECT * FROM repair_bookings ORDER BY booking_date DESC, created_at DESC')->fetchAll();
    $totalBookings = count($bookings);
    foreach ($bookings as $booking) {
        $status = (string) $booking['status'];
        if ($status === 'Pending' || $status === 'In Progress') {
            $pendingBookings++;
        }
        if ($status === 'Completed') {
            $completedBookings++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Mobimend PHP Repair Admin</title>
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@600;700&family=Roboto:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="admin_ops.css">
  <style>
    body {
      margin: 0;
      font-family: 'Roboto', sans-serif;
      background: linear-gradient(180deg, #eef4ff 0%, #f7f9fc 48%, #ffffff 100%);
      color: #1f2933;
    }
    header {
      background: linear-gradient(135deg, #0f4aa1, #1766c5 55%, #3b82f6);
      color: #fff;
      padding: 24px 20px;
      box-shadow: 0 14px 34px rgba(23, 102, 197, 0.2);
    }
    header h1 { margin: 0; font-family: 'Montserrat', sans-serif; }
    .hero-copy {
      margin-top: 8px;
      max-width: 720px;
      color: rgba(255, 255, 255, 0.88);
    }
    .container {
      max-width: 1120px;
      margin: 28px auto;
      padding: 0 16px;
    }
    .card {
      background: #fff;
      border-radius: 18px;
      padding: 20px;
      margin-bottom: 18px;
      box-shadow: 0 18px 48px rgba(15, 23, 42, 0.08);
      border: 1px solid rgba(148, 163, 184, 0.18);
    }
    .login-shell {
      min-height: calc(100vh - 120px);
      display: grid;
      place-items: center;
    }
    .login-card {
      width: min(460px, 100%);
      padding: 28px;
    }
    .login-stack {
      display: grid;
      gap: 12px;
    }
    input, select, textarea {
      width: 100%;
      box-sizing: border-box;
      padding: 12px 13px;
      border-radius: 12px;
      border: 1px solid #d7dbe3;
      background: #fbfdff;
      font: inherit;
    }
    button, .button-link {
      display: inline-block;
      background: #1766c5;
      color: #fff;
      border: none;
      padding: 11px 16px;
      border-radius: 12px;
      cursor: pointer;
      font-weight: 600;
      text-decoration: none;
      font: inherit;
    }
    .secondary { background: #6b7280; }
    .ghost { background: #e8f0fe; color: #0f4aa1; }
    .danger { background: #b91c1c; }
    .topbar, .dashboard-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 18px;
    }
    .dashboard-title h2, .card h3 {
      margin: 0;
      font-family: 'Montserrat', sans-serif;
      color: #0f172a;
    }
    .dashboard-title p, .muted {
      margin: 6px 0 0;
      color: #556070;
    }
    .banner {
      margin-bottom: 14px;
      padding: 12px 14px;
      border-radius: 12px;
      font-size: 0.95rem;
      font-weight: 500;
    }
    .banner.info { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
    .banner.success { background: #ecfdf5; color: #15803d; border: 1px solid #bbf7d0; }
    .banner.error { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
    .stats {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 12px;
      margin-bottom: 18px;
    }
    .stat {
      border: 1px solid #dbeafe;
      border-radius: 16px;
      padding: 16px;
      background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
    }
    .stat span {
      display: block;
      margin-bottom: 8px;
      color: #556070;
      font-size: 0.9rem;
    }
    .stat strong {
      font-family: 'Montserrat', sans-serif;
      font-size: 1.5rem;
      color: #0f172a;
    }
    .table-wrap { overflow-x: auto; }
    table {
      width: 100%;
      border-collapse: collapse;
      background: #fff;
    }
    th, td {
      padding: 12px;
      border-bottom: 1px solid #eef0f4;
      text-align: left;
      vertical-align: top;
    }
    th {
      background: #f1f5f9;
      font-weight: 700;
    }
    .actions {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      align-items: center;
    }
    .status-pill {
      display: inline-block;
      padding: 4px 8px;
      border-radius: 999px;
      font-size: 0.85rem;
      background: #e5efff;
      color: #1d4ed8;
      font-weight: 600;
    }
    .small { font-size: 0.9rem; color: #556070; }
    @media (max-width: 900px) {
      .stats { grid-template-columns: 1fr; }
      .topbar, .dashboard-header { flex-direction: column; align-items: flex-start; }
    }
  </style>
</head>
<body class="admin-ops">
  <header>
    <div class="ops-header-inner">
      <div class="ops-brand">
        <h1>Repair Bookings</h1>
        <p>Confirm bookings, move repair work through service stages, and keep customer-visible status aligned with operations.</p>
      </div>
      <nav class="ops-nav" aria-label="Admin navigation">
        <a href="admin_dashboard.php">Operations</a>
        <a href="admin_inventory.php">Inventory</a>
        <a href="admin_orders.php">Orders</a>
        <a class="active" href="admin_repairs.php">Repairs</a>
        <a href="admin_products.php">Products</a>
        <a href="logout.php">Logout</a>
      </nav>
    </div>
  </header>

  <?php if (!$user): ?>
    <div class="container">
      <div class="login-shell">
        <div class="card login-card">
          <h3>Admin Login</h3>
          <p class="muted">Sign in with your Mobimend admin account.</p>

          <?php if ($message !== ''): ?>
            <div class="banner <?= htmlspecialchars($tone) ?>"><?= htmlspecialchars($message) ?></div>
          <?php endif; ?>

          <?php if ($adminCount === 0): ?>
            <div class="banner info">No admin exists yet. Start with <a href="setup_admin.php">setup_admin.php</a>.</div>
          <?php endif; ?>

          <form method="post" class="login-stack">
            <input type="hidden" name="action" value="login">
            <input type="email" name="email" placeholder="Email" required>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit">Login</button>
          </form>
        </div>
      </div>
    </div>
  <?php else: ?>
    <div class="container">
      <div class="topbar">
        <div class="dashboard-title">
          <h2>Repair Dashboard</h2>
          <p>Signed in as <?= htmlspecialchars((string) $user['name']) ?> (<?= htmlspecialchars((string) $user['email']) ?>)</p>
        </div>
        <div class="actions">
          <a href="admin_dashboard.php" class="button-link ghost">Operations</a>
          <a href="admin_inventory.php" class="button-link ghost">Inventory</a>
          <a href="admin_orders.php" class="button-link ghost">Orders</a>
        </div>
      </div>

      <?php if ($message !== ''): ?>
        <div class="banner <?= htmlspecialchars($tone) ?>"><?= htmlspecialchars($message) ?></div>
      <?php endif; ?>

      <div class="stats">
        <div class="stat">
          <span>Total Bookings</span>
          <strong><?= number_format($totalBookings) ?></strong>
        </div>
        <div class="stat">
          <span>Open Bookings</span>
          <strong><?= number_format($pendingBookings) ?></strong>
        </div>
        <div class="stat">
          <span>Completed</span>
          <strong><?= number_format($completedBookings) ?></strong>
        </div>
      </div>

      <div class="card">
        <div class="dashboard-header">
          <div>
            <h3>Repair Bookings</h3>
            <div class="small">Use status updates to keep repair queue, customer tracking, and parts planning in sync.</div>
          </div>
        </div>

        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Booked</th>
                <th>Customer</th>
                <th>Device</th>
                <th>Repair</th>
                <th>Notes</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($bookings === []): ?>
                <tr>
                  <td colspan="7" class="small">No repair bookings yet. Public bookings inserted into `repair_bookings` will appear here.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($bookings as $booking): ?>
                  <tr>
                    <td class="small"><?= htmlspecialchars((string) $booking['booking_date']) ?></td>
                    <td>
                      <div><strong><?= htmlspecialchars((string) $booking['customer_name']) ?></strong></div>
                      <div class="small"><?= htmlspecialchars((string) $booking['phone_number']) ?></div>
                      <div class="small"><?= htmlspecialchars((string) $booking['email']) ?></div>
                    </td>
                    <td><?= htmlspecialchars((string) $booking['device_model']) ?></td>
                    <td><?= htmlspecialchars((string) $booking['repair_type']) ?></td>
                    <td class="small"><?= nl2br(htmlspecialchars((string) $booking['issue_description'])) ?></td>
                    <td><span class="status-pill"><?= htmlspecialchars((string) $booking['status']) ?></span></td>
                    <td>
                      <div class="actions">
                        <form method="post">
                          <input type="hidden" name="action" value="update_status">
                          <input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>">
                          <select name="status">
                            <?php foreach (['Pending', 'In Progress', 'Completed', 'Cancelled'] as $status): ?>
                              <option value="<?= htmlspecialchars($status) ?>" <?= (string) $booking['status'] === $status ? 'selected' : '' ?>><?= htmlspecialchars($status) ?></option>
                            <?php endforeach; ?>
                          </select>
                          <button type="submit" class="secondary">Save</button>
                        </form>
                        <form method="post" onsubmit="return confirm('Delete this booking?');">
                          <input type="hidden" name="action" value="delete_booking">
                          <input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>">
                          <button type="submit" class="danger">Delete</button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  <?php endif; ?>
</body>
</html>
