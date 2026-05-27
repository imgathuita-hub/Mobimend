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

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function money(mixed $value): string
{
    return 'KES ' . number_format((float) $value, 2);
}

function wants_json(): bool
{
    return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
        || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'fetch';
}

function admin_reply(string $message, string $tone = 'success'): never
{
    if (wants_json()) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => $tone !== 'error', 'message' => $message, 'tone' => $tone], JSON_THROW_ON_ERROR);
        exit;
    }

    redirect_with_message('admin_dashboard.php', $message, $tone);
}

function time_ago(?string $datetime): string
{
    if (!$datetime) {
        return 'just now';
    }

    $timestamp = strtotime($datetime);
    if (!$timestamp) {
        return (string) $datetime;
    }

    $seconds = max(0, time() - $timestamp);
    if ($seconds < 60) {
        return 'just now';
    }
    if ($seconds < 3600) {
        return floor($seconds / 60) . ' min ago';
    }
    if ($seconds < 86400) {
        return floor($seconds / 3600) . ' hrs ago';
    }

    return floor($seconds / 86400) . ' days ago';
}

function urgency_class(?string $datetime): string
{
    $timestamp = strtotime((string) $datetime);
    if (!$timestamp) {
        return 'ri-teal';
    }

    $hours = ($timestamp - time()) / 3600;
    if ($hours <= 4) {
        return 'ri-red';
    }
    if ($hours <= 24) {
        return 'ri-amber';
    }

    return 'ri-teal';
}

function stock_width(int $quantity, int $reorderPoint): int
{
    if ($reorderPoint <= 0) {
        return $quantity > 0 ? 100 : 0;
    }

    return max(0, min(100, (int) round(($quantity / max(1, $reorderPoint * 2)) * 100)));
}

function stock_color(int $quantity, int $reorderPoint): string
{
    if ($quantity <= 0) {
        return '#E24B4A';
    }
    if ($quantity <= $reorderPoint) {
        return '#EF9F27';
    }

    return '#1D9E75';
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
        redirect_with_message('admin_dashboard.php', 'Welcome back.');
    }
}

if ($user && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'confirm_booking') {
            $bookingId = (int) ($_POST['booking_id'] ?? 0);
            if ($bookingId <= 0) {
                throw new RuntimeException('Booking is required.');
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare('UPDATE repair_bookings SET status = "In Progress", updated_at = :updated_at WHERE id = :id');
            $stmt->execute(['id' => $bookingId, 'updated_at' => now()]);
            $stmt = $pdo->prepare(
                'INSERT INTO repair_status_updates
                 (repair_booking_id, changed_by_user_id, status, note, customer_visible, created_at)
                 VALUES (:booking_id, :user_id, "In Progress", "Booking confirmed by admin.", 1, :created_at)'
            );
            $stmt->execute(['booking_id' => $bookingId, 'user_id' => (int) $user['id'], 'created_at' => now()]);
            $pdo->commit();
            admin_reply('Booking confirmed.');
        }

        if ($action === 'mark_payment_paid' || $action === 'approve_bank') {
            $paymentId = (int) ($_POST['payment_id'] ?? 0);
            if ($paymentId <= 0) {
                throw new RuntimeException('Payment is required.');
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT * FROM payments WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $paymentId]);
            $payment = $stmt->fetch();
            if (!$payment) {
                throw new RuntimeException('Payment not found.');
            }

            $stmt = $pdo->prepare(
                'UPDATE payments
                 SET status = "paid", verified_by_user_id = :verified_by, verified_at = :verified_at, updated_at = :updated_at
                 WHERE id = :id'
            );
            $stmt->execute([
                'id' => $paymentId,
                'verified_by' => (int) $user['id'],
                'verified_at' => now(),
                'updated_at' => now(),
            ]);

            if (!empty($payment['order_id'])) {
                $stmt = $pdo->prepare('UPDATE orders SET payment_status = "paid", updated_at = :updated_at WHERE id = :id');
                $stmt->execute(['id' => (int) $payment['order_id'], 'updated_at' => now()]);
            }

            $pdo->commit();
            admin_reply($action === 'approve_bank' ? 'Bank transfer approved.' : 'Payment marked paid.');
        }

        if ($action === 'flag_payment' || $action === 'query_bank') {
            $paymentId = (int) ($_POST['payment_id'] ?? 0);
            if ($paymentId <= 0) {
                throw new RuntimeException('Payment is required.');
            }

            $stmt = $pdo->prepare('UPDATE payments SET status = "requires_review", updated_at = :updated_at WHERE id = :id');
            $stmt->execute(['id' => $paymentId, 'updated_at' => now()]);
            admin_reply($action === 'query_bank' ? 'Bank transfer moved to query.' : 'Payment flagged for manual review.', 'info');
        }

        if ($action === 'approve_wholesale' || $action === 'defer_wholesale') {
            $applicationId = (int) ($_POST['application_id'] ?? 0);
            if ($applicationId <= 0) {
                throw new RuntimeException('Application is required.');
            }

            if ($action === 'approve_wholesale') {
                $stmt = $pdo->prepare(
                    'UPDATE wholesale_applications
                     SET status = "approved", reviewed_by_user_id = :reviewed_by, reviewed_at = :reviewed_at, updated_at = :updated_at
                     WHERE id = :id'
                );
                $stmt->execute([
                    'id' => $applicationId,
                    'reviewed_by' => (int) $user['id'],
                    'reviewed_at' => now(),
                    'updated_at' => now(),
                ]);
                admin_reply('Wholesale application approved.');
            }

            $stmt = $pdo->prepare(
                'UPDATE wholesale_applications
                 SET notes = CONCAT(COALESCE(notes, ""), :note), updated_at = :updated_at
                 WHERE id = :id'
            );
            $stmt->execute([
                'id' => $applicationId,
                'note' => "\nDeferred from admin dashboard on " . now(),
                'updated_at' => now(),
            ]);
            admin_reply('Wholesale application deferred.', 'info');
        }

        if ($action === 'complete_alert_job') {
            $jobId = (int) ($_POST['job_id'] ?? 0);
            if ($jobId <= 0) {
                throw new RuntimeException('Alert job is required.');
            }

            $stmt = $pdo->prepare('UPDATE inventory_alert_jobs SET status = "completed", processed_at = :processed_at, updated_at = :updated_at WHERE id = :id');
            $stmt->execute(['id' => $jobId, 'processed_at' => now(), 'updated_at' => now()]);
            admin_reply('Restock action recorded.');
        }

        if ($action === 'urgent_restock') {
            $itemId = (int) ($_POST['inventory_item_id'] ?? 0);
            if ($itemId <= 0) {
                throw new RuntimeException('Inventory item is required.');
            }

            $stmt = $pdo->prepare('SELECT * FROM inventory_items WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $itemId]);
            $item = $stmt->fetch();
            if (!$item) {
                throw new RuntimeException('Inventory item not found.');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO inventory_alert_jobs
                 (inventory_item_id, job_type, payload, status, available_at, created_at)
                 VALUES (:inventory_item_id, "urgent_restock", :payload, "pending", :available_at, :created_at)'
            );
            $stmt->execute([
                'inventory_item_id' => $itemId,
                'payload' => json_encode([
                    'inventory_item_id' => $itemId,
                    'brand' => (string) $item['brand'],
                    'model' => (string) $item['model'],
                    'part_type' => (string) $item['part_type'],
                    'quantity' => (int) $item['quantity'],
                    'reorder_point' => (int) $item['reorder_point'],
                    'requested_by_user_id' => (int) $user['id'],
                ], JSON_THROW_ON_ERROR),
                'available_at' => now(),
                'created_at' => now(),
            ]);
            admin_reply('Urgent restock job queued.');
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        admin_reply($exception->getMessage(), 'error');
    }
}

$bookings = [];
$lowStock = [];
$partsNeeded = [];
$mpesaPayments = [];
$bankPayments = [];
$wholesaleApps = [];
$timelineDays = [];
$blogSuggestions = [
    ['queries' => 47, 'topic' => 'iPhone screen repair cost guide - Kenya 2026', 'meta' => 'Most asked pricing question'],
    ['queries' => 34, 'topic' => 'How long does a phone battery replacement take?', 'meta' => 'Common pre-booking question'],
    ['queries' => 28, 'topic' => 'Phone water damage: what to do in the first 30 minutes', 'meta' => 'Urgent support searches'],
    ['queries' => 19, 'topic' => 'OEM vs aftermarket screens: which should you choose?', 'meta' => 'Purchase decision support'],
    ['queries' => 15, 'topic' => 'M-Pesa payment not confirming? What to check', 'meta' => 'Payment support deflection'],
];

$metrics = [
    'bookings' => 0,
    'parts' => 0,
    'stock' => 0,
    'mpesa' => 0,
    'bank' => 0,
    'wholesale' => 0,
];

if ($user) {
    $bookings = $pdo->query(
        'SELECT *
         FROM repair_bookings
         WHERE status IN ("Pending", "pending", "Unconfirmed", "unconfirmed")
         ORDER BY booking_date ASC, created_at ASC
         LIMIT 8'
    )->fetchAll();

    $lowStock = $pdo->query(
        'SELECT *
         FROM inventory_items
         WHERE quantity <= reorder_point OR low_stock = 1
         ORDER BY quantity ASC, updated_at ASC
         LIMIT 8'
    )->fetchAll();

    foreach ($bookings as $booking) {
        foreach ($lowStock as $item) {
            $haystack = strtolower((string) $booking['device_model'] . ' ' . (string) $booking['repair_type'] . ' ' . (string) $booking['issue_description']);
            $needle = strtolower((string) $item['brand'] . ' ' . (string) $item['model'] . ' ' . (string) $item['part_type']);
            $matchesRepair = str_contains($haystack, strtolower((string) $item['brand']))
                || str_contains($haystack, strtolower((string) $item['model']))
                || str_contains($haystack, strtolower((string) $item['part_type']));

            if ($matchesRepair || count($partsNeeded) < 3) {
                $partsNeeded[] = ['booking' => $booking, 'item' => $item];
                break;
            }
        }
    }

    $mpesaPayments = $pdo->query(
        'SELECT p.*, o.order_number, o.order_type, rb.id AS repair_id, rb.customer_name AS repair_customer
         FROM payments p
         LEFT JOIN orders o ON o.id = p.order_id
         LEFT JOIN repair_bookings rb ON rb.id = p.repair_booking_id
         WHERE p.payment_method = "mpesa_stk" AND p.status IN ("pending", "processing", "requires_review")
         ORDER BY p.created_at DESC
         LIMIT 8'
    )->fetchAll();

    $bankPayments = $pdo->query(
        'SELECT p.*, o.order_number, o.order_type, o.customer_name AS order_customer, rb.customer_name AS repair_customer
         FROM payments p
         LEFT JOIN orders o ON o.id = p.order_id
         LEFT JOIN repair_bookings rb ON rb.id = p.repair_booking_id
         WHERE p.payment_method = "bank_transfer" AND p.status IN ("pending", "processing", "requires_review")
         ORDER BY p.created_at ASC
         LIMIT 8'
    )->fetchAll();

    $wholesaleApps = $pdo->query(
        'SELECT *
         FROM wholesale_applications
         WHERE status = "pending"
         ORDER BY created_at ASC
         LIMIT 8'
    )->fetchAll();

    for ($day = 0; $day < 5; $day++) {
        $label = $day === 0 ? 'Today' : date('D', strtotime('+' . $day . ' days'));
        $timelineDays[] = ['label' => $label, 'items' => []];
    }

    foreach ($partsNeeded as $index => $part) {
        $dayIndex = min(4, $index);
        $timelineDays[$dayIndex]['items'][] = [
            'label' => (string) $part['item']['part_type'],
            'need' => (int) $part['item']['quantity'] <= 0,
        ];
    }

    $metrics = [
        'bookings' => count($bookings),
        'parts' => count($partsNeeded),
        'stock' => count($lowStock),
        'mpesa' => count($mpesaPayments),
        'bank' => count($bankPayments),
        'wholesale' => count($wholesaleApps),
    ];
}

$urgentActions = array_sum($metrics);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Mobimend Admin Operations</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link rel="stylesheet" href="admin_ops.css">
  <style>
    :root {
      --bg: #f5f6f8;
      --surface: #fff;
      --surface-2: #f3f4f6;
      --line: rgba(31, 30, 29, 0.15);
      --line-strong: rgba(31, 30, 29, 0.3);
      --text: #141413;
      --muted: #5f5e5a;
      --soft: #77766f;
    }
    * { box-sizing: border-box; }
    body { margin: 0; background: var(--bg); color: var(--text); font-family: Inter, Arial, sans-serif; letter-spacing: 0; }
    a { color: inherit; }
    .sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0, 0, 0, 0); }
    .wrap { min-height: 100vh; background: var(--surface); }
    .top-bar { position: sticky; top: 0; z-index: 20; display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 20px; border-bottom: 1px solid var(--line); background: rgba(255, 255, 255, 0.94); backdrop-filter: blur(12px); }
    .top-bar-left, .top-bar-right { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .logo { font-size: 17px; font-weight: 800; }
    .logo span { color: #1d9e75; }
    .badge-urgent { background: #faece7; color: #993c1d; font-size: 11px; padding: 3px 9px; border-radius: 20px; font-weight: 800; }
    .admin-pill, .date-chip { font-size: 12px; color: var(--muted); background: var(--surface-2); border: 1px solid var(--line); padding: 5px 10px; border-radius: 20px; }
    .action-btn, .btn-view, .btn-reject { font-size: 11px; color: var(--muted); border: 1px solid var(--line); background: var(--surface); padding: 5px 10px; border-radius: 6px; cursor: pointer; text-decoration: none; white-space: nowrap; }
    .action-btn:hover, .btn-view:hover, .btn-reject:hover { background: var(--surface-2); }
    .metrics { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 10px; padding: 16px 20px; background: var(--surface-2); }
    .metric { background: var(--surface); border-radius: 8px; padding: 11px 12px; cursor: pointer; border: 1px solid var(--line); transition: border-color 0.15s, transform 0.15s; text-decoration: none; }
    .metric:hover { border-color: var(--line-strong); transform: translateY(-1px); }
    .metric.active { border-color: #1d9e75; border-width: 2px; }
    .metric-label { font-size: 11px; color: var(--muted); margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .metric-val { font-size: 22px; font-weight: 800; color: var(--text); }
    .metric-sub { font-size: 10px; color: var(--soft); margin-top: 2px; }
    .dot-red, .dot-amber, .dot-green { display: inline-block; width: 6px; height: 6px; border-radius: 50%; margin-right: 4px; vertical-align: middle; }
    .dot-red { background: #e24b4a; } .dot-amber { background: #ef9f27; } .dot-green { background: #1d9e75; }
    .main { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 16px; padding: 16px 20px 28px; }
    .panel { background: var(--surface); border: 1px solid var(--line); border-radius: 8px; overflow: hidden; min-width: 0; }
    .panel.full { grid-column: 1 / -1; }
    .panel-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 12px 14px; border-bottom: 1px solid var(--line); }
    .panel-title { font-size: 13px; font-weight: 800; display: flex; align-items: center; gap: 7px; min-width: 0; }
    .count-badge { font-size: 11px; padding: 2px 7px; border-radius: 10px; font-weight: 800; white-space: nowrap; }
    .cb-red { background: #fcebeb; color: #a32d2d; } .cb-amber { background: #faeeda; color: #854f0b; } .cb-teal { background: #e1f5ee; color: #0f6e56; } .cb-blue { background: #e6f1fb; color: #185fa5; } .cb-purple { background: #eeedfe; color: #3c3489; } .cb-gray { background: var(--surface-2); color: var(--muted); }
    .row { display: flex; align-items: flex-start; gap: 10px; padding: 11px 14px; border-bottom: 1px solid var(--line); }
    .row:last-child { border-bottom: none; }
    .row.done { opacity: 0.42; }
    .row-icon { width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; flex: 0 0 auto; font-size: 15px; }
    .ri-red { background: #fcebeb; color: #a32d2d; } .ri-amber { background: #faeeda; color: #854f0b; } .ri-teal { background: #e1f5ee; color: #0f6e56; } .ri-blue { background: #e6f1fb; color: #185fa5; } .ri-purple { background: #eeedfe; color: #534ab7; }
    .row-body { flex: 1; min-width: 0; }
    .row-title { font-size: 13px; font-weight: 800; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .row-meta { font-size: 11px; color: var(--muted); margin-top: 3px; display: flex; gap: 10px; flex-wrap: wrap; }
    .row-actions { display: flex; gap: 6px; align-items: center; flex: 0 0 auto; flex-wrap: wrap; justify-content: flex-end; }
    .btn-confirm, .btn-warn, .btn-danger { font-size: 11px; border: 1px solid transparent; padding: 5px 10px; border-radius: 6px; cursor: pointer; font-weight: 800; white-space: nowrap; }
    .btn-confirm { background: #e1f5ee; color: #0f6e56; border-color: #5dcaa5; }
    .btn-warn { background: #faeeda; color: #854f0b; border-color: #fac775; }
    .btn-danger { background: #fcebeb; color: #a32d2d; border-color: #f7c1c1; }
    .status-pill { font-size: 10px; padding: 2px 7px; border-radius: 10px; white-space: nowrap; }
    .sp-pending { background: #faeeda; color: #854f0b; } .sp-low { background: #fcebeb; color: #a32d2d; } .sp-waiting { background: #e6f1fb; color: #185fa5; } .sp-manual { background: #eeedfe; color: #3c3489; } .sp-new { background: #e1f5ee; color: #0f6e56; }
    .stock-bar-wrap { margin-top: 6px; background: var(--surface-2); border-radius: 4px; height: 5px; width: 100%; max-width: 130px; overflow: hidden; }
    .stock-bar { height: 5px; border-radius: 4px; }
    .timeline-shell { padding: 9px 14px; border-top: 1px solid var(--line); }
    .timeline-strip { display: flex; gap: 0; overflow-x: auto; scrollbar-width: none; }
    .tday { flex: 0 0 76px; border-right: 1px solid var(--line); padding-right: 8px; margin-right: 8px; }
    .tday:last-child { border-right: none; }
    .tday-label { font-size: 10px; color: var(--soft); margin-bottom: 6px; font-weight: 800; }
    .tday-item { font-size: 10px; color: var(--muted); background: var(--surface-2); border-radius: 4px; padding: 4px 5px; margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 68px; }
    .tday-item.need { background: #fcebeb; color: #a32d2d; } .tday-item.ok { background: #e1f5ee; color: #0f6e56; }
    .blog-grid { display: grid; grid-template-columns: 1fr 1fr; }
    .blog-card { padding: 11px 14px; border-bottom: 1px solid var(--line); display: flex; align-items: center; gap: 10px; min-width: 0; }
    .blog-card:nth-child(odd) { border-right: 1px solid var(--line); }
    .blog-card:last-child { grid-column: 1 / -1; border-right: none; border-bottom: none; }
    .freq-badge { font-size: 10px; font-weight: 800; padding: 2px 6px; border-radius: 6px; background: #e1f5ee; color: #0f6e56; white-space: nowrap; }
    .blog-topic { font-size: 13px; font-weight: 800; margin-top: 4px; }
    .blog-meta { font-size: 11px; color: var(--muted); margin-top: 2px; }
    .empty { font-size: 12px; color: var(--soft); padding: 16px; text-align: center; }
    .toast { display: none; position: fixed; top: 58px; right: 16px; background: var(--surface); border: 1px solid var(--line-strong); border-radius: 8px; padding: 11px 14px; font-size: 12px; z-index: 50; min-width: 220px; max-width: 320px; }
    .toast.show { display: block; }
    .login-shell { min-height: 100vh; display: grid; place-items: center; padding: 24px; }
    .login-card { width: min(460px, 100%); background: #fff; border: 1px solid var(--line); border-radius: 8px; padding: 22px; }
    .login-card h1 { margin: 0 0 8px; font-size: 22px; }
    .login-card p { color: var(--muted); margin: 0 0 16px; }
    .login-card form { display: grid; gap: 10px; }
    input { width: 100%; border: 1px solid var(--line); border-radius: 8px; padding: 11px 12px; font: inherit; }
    .banner { margin: 12px 0; border-radius: 8px; padding: 11px 12px; font-size: 13px; font-weight: 800; }
    .banner.success { background: #ecfdf5; color: #166534; border: 1px solid #bbf7d0; } .banner.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; } .banner.info { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
    @media (max-width: 1080px) { .metrics { grid-template-columns: repeat(3, 1fr); } .main { grid-template-columns: 1fr; } }
    @media (max-width: 720px) { .top-bar { align-items: flex-start; } .metrics { grid-template-columns: repeat(2, 1fr); padding: 12px; } .main { padding: 12px; } .row { flex-wrap: wrap; } .row-actions { width: 100%; justify-content: flex-start; padding-left: 42px; } .blog-grid { grid-template-columns: 1fr; } .blog-card:nth-child(odd) { border-right: none; } }
  </style>
</head>
<body class="admin-ops">
<?php if (!$user): ?>
  <main class="login-shell">
    <section class="login-card">
      <h1>Mobimend Admin</h1>
      <p>Sign in to open the operations dashboard.</p>
      <?php if ($message !== ''): ?><div class="banner <?= h($tone) ?>"><?= h($message) ?></div><?php endif; ?>
      <?php if ($adminCount === 0): ?><div class="banner info">No admin exists yet. Start with <a href="setup_admin.php">setup_admin.php</a>.</div><?php endif; ?>
      <form method="post">
        <input type="hidden" name="action" value="login">
        <input type="email" name="email" placeholder="Email" required>
        <input type="password" name="password" placeholder="Password" required>
        <button class="btn-confirm" type="submit">Login</button>
      </form>
    </section>
  </main>
<?php else: ?>
  <h1 class="sr-only">Mobimend admin dashboard</h1>
  <div class="wrap">
    <div id="toast" class="toast"></div>
    <header class="top-bar">
      <div class="top-bar-left">
        <div class="logo">Mobi<span>mend</span> <span style="font-size:11px;font-weight:500;color:var(--soft)">Admin</span></div>
        <div class="badge-urgent" id="urgent-count"><?= number_format($urgentActions) ?> urgent actions</div>
      </div>
      <div class="top-bar-right">
        <div class="date-chip"><?= h(date('D, j M Y')) ?></div>
        <div class="admin-pill"><i class="fa-solid fa-user"></i> <?= h($user['name'] ?? 'Admin') ?></div>
        <a class="action-btn" href="admin_repairs.php">Repairs</a>
        <a class="action-btn" href="admin_orders.php">Orders</a>
        <a class="action-btn" href="admin_products.php">Products</a>
        <a class="action-btn" href="logout.php">Logout</a>
      </div>
    </header>

    <nav class="metrics" aria-label="Admin queues">
      <a class="metric active" href="#bookings"><div class="metric-label"><span class="dot-amber"></span>New bookings</div><div class="metric-val"><?= number_format($metrics['bookings']) ?></div><div class="metric-sub">Awaiting confirmation</div></a>
      <a class="metric" href="#parts"><div class="metric-label"><span class="dot-amber"></span>Parts needed</div><div class="metric-val"><?= number_format($metrics['parts']) ?></div><div class="metric-sub">Across repair queue</div></a>
      <a class="metric" href="#stock"><div class="metric-label"><span class="dot-red"></span>Low stock</div><div class="metric-val"><?= number_format($metrics['stock']) ?></div><div class="metric-sub">At or below reorder point</div></a>
      <a class="metric" href="#mpesa"><div class="metric-label"><span class="dot-amber"></span>M-Pesa pending</div><div class="metric-val"><?= number_format($metrics['mpesa']) ?></div><div class="metric-sub">Awaiting callback</div></a>
      <a class="metric" href="#bank"><div class="metric-label"><span class="dot-amber"></span>Bank transfers</div><div class="metric-val"><?= number_format($metrics['bank']) ?></div><div class="metric-sub">Need manual check</div></a>
      <a class="metric" href="#wholesale"><div class="metric-label"><span class="dot-green"></span>Wholesale apps</div><div class="metric-val"><?= number_format($metrics['wholesale']) ?></div><div class="metric-sub">Pending approval</div></a>
    </nav>

    <main class="main">
      <section class="panel" id="bookings">
        <div class="panel-head">
          <div class="panel-title"><i class="fa-solid fa-screwdriver-wrench"></i> Repair bookings <span class="count-badge cb-amber"><?= number_format($metrics['bookings']) ?> pending</span></div>
          <a class="action-btn" href="admin_repairs.php">View all</a>
        </div>
        <?php if ($bookings === []): ?>
          <div class="empty">No unconfirmed repair bookings.</div>
        <?php endif; ?>
        <?php foreach ($bookings as $booking): ?>
          <div class="row" data-row>
            <div class="row-icon <?= h(urgency_class((string) $booking['booking_date'])) ?>"><i class="fa-solid fa-mobile-screen"></i></div>
            <div class="row-body">
              <div class="row-title"><?= h($booking['device_model']) ?> - <?= h($booking['repair_type']) ?></div>
              <div class="row-meta"><span><?= h($booking['customer_name']) ?></span><span>Booked <?= h(time_ago((string) $booking['created_at'])) ?></span><span class="status-pill sp-pending"><?= h($booking['status']) ?></span></div>
            </div>
            <div class="row-actions">
              <form method="post" data-ajax>
                <input type="hidden" name="action" value="confirm_booking">
                <input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>">
                <button class="btn-confirm" type="submit">Confirm</button>
              </form>
              <a class="btn-view" href="admin_repairs.php">View</a>
            </div>
          </div>
        <?php endforeach; ?>
      </section>

      <section class="panel" id="parts">
        <div class="panel-head">
          <div class="panel-title"><i class="fa-solid fa-box"></i> Parts needed <span class="count-badge cb-amber"><?= number_format($metrics['parts']) ?> items</span></div>
          <a class="action-btn" href="admin_inventory.php">Inventory</a>
        </div>
        <?php if ($partsNeeded === []): ?>
          <div class="empty">No obvious part blockers found for pending bookings.</div>
        <?php endif; ?>
        <?php foreach ($partsNeeded as $part): ?>
          <?php $item = $part['item']; $booking = $part['booking']; $qty = (int) $item['quantity']; $rp = (int) $item['reorder_point']; ?>
          <div class="row" data-row>
            <div class="row-icon <?= $qty <= 0 ? 'ri-red' : 'ri-amber' ?>"><i class="fa-solid fa-microchip"></i></div>
            <div class="row-body">
              <div class="row-title"><?= h($item['brand']) ?> <?= h($item['model']) ?> <?= h($item['part_type']) ?></div>
              <div class="row-meta"><span>For: <?= h($booking['customer_name']) ?></span><span>Stock: <?= $qty ?> units</span></div>
              <div class="stock-bar-wrap"><div class="stock-bar" style="width:<?= stock_width($qty, $rp) ?>%;background:<?= stock_color($qty, $rp) ?>"></div></div>
            </div>
            <div class="row-actions">
              <form method="post" data-ajax>
                <input type="hidden" name="action" value="urgent_restock">
                <input type="hidden" name="inventory_item_id" value="<?= (int) $item['id'] ?>">
                <button class="<?= $qty <= 0 ? 'btn-danger' : 'btn-warn' ?>" type="submit"><?= $qty <= 0 ? 'Order' : 'Restock' ?></button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
        <div class="timeline-shell">
          <div class="tday-label">Parts timeline - next 5 days</div>
          <div class="timeline-strip">
            <?php foreach ($timelineDays as $day): ?>
              <div class="tday">
                <div class="tday-label"><?= h($day['label']) ?></div>
                <?php if ($day['items'] === []): ?><div class="tday-item">clear</div><?php endif; ?>
                <?php foreach ($day['items'] as $timelineItem): ?><div class="tday-item <?= $timelineItem['need'] ? 'need' : 'ok' ?>"><?= h($timelineItem['label']) ?></div><?php endforeach; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </section>

      <section class="panel" id="stock">
        <div class="panel-head">
          <div class="panel-title"><i class="fa-solid fa-triangle-exclamation" style="color:#e24b4a"></i> Low stock <span class="count-badge cb-red"><?= number_format($metrics['stock']) ?> critical</span></div>
          <a class="action-btn" href="admin_inventory.php">Manage</a>
        </div>
        <?php if ($lowStock === []): ?><div class="empty">No low-stock blockers.</div><?php endif; ?>
        <?php foreach ($lowStock as $item): ?>
          <?php $qty = (int) $item['quantity']; $rp = (int) $item['reorder_point']; ?>
          <div class="row" data-row>
            <div class="row-icon <?= $qty <= 0 ? 'ri-red' : 'ri-amber' ?>"><i class="fa-solid fa-cubes-stacked"></i></div>
            <div class="row-body">
              <div class="row-title"><?= h($item['brand']) ?> <?= h($item['model']) ?> <?= h($item['part_type']) ?></div>
              <div class="row-meta"><span><b style="color:<?= $qty <= 0 ? '#e24b4a' : '#ef9f27' ?>"><?= $qty ?> in stock</b></span><span>Reorder at <?= $rp ?></span></div>
              <div class="stock-bar-wrap"><div class="stock-bar" style="width:<?= stock_width($qty, $rp) ?>%;background:<?= stock_color($qty, $rp) ?>"></div></div>
            </div>
            <div class="row-actions">
              <form method="post" data-ajax>
                <input type="hidden" name="action" value="urgent_restock">
                <input type="hidden" name="inventory_item_id" value="<?= (int) $item['id'] ?>">
                <button class="<?= $qty <= 0 ? 'btn-danger' : 'btn-warn' ?>" type="submit"><?= $qty <= 0 ? 'Urgent order' : 'Reorder' ?></button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </section>

      <section class="panel" id="mpesa">
        <div class="panel-head">
          <div class="panel-title"><i class="fa-solid fa-arrows-rotate" style="color:#185fa5"></i> M-Pesa payments <span class="count-badge cb-blue"><?= number_format($metrics['mpesa']) ?> waiting</span></div>
          <a class="action-btn" href="admin_orders.php">Orders</a>
        </div>
        <?php if ($mpesaPayments === []): ?><div class="empty">No pending M-Pesa payments.</div><?php endif; ?>
        <?php foreach ($mpesaPayments as $payment): ?>
          <?php $ageSeconds = max(0, time() - (strtotime((string) $payment['created_at']) ?: time())); ?>
          <div class="row" data-row>
            <div class="row-icon ri-blue"><i class="fa-solid fa-credit-card"></i></div>
            <div class="row-body">
              <div class="row-title"><?= h(money($payment['amount'])) ?> - <?= h($payment['order_number'] ?: ('Payment #' . $payment['id'])) ?></div>
              <div class="row-meta"><span><?= h($payment['phone_number']) ?></span><span>Initiated <?= h(time_ago((string) $payment['created_at'])) ?></span><span class="status-pill <?= $ageSeconds > 3600 ? 'sp-low' : 'sp-waiting' ?>"><?= $ageSeconds > 3600 ? 'Likely timed out' : 'Awaiting callback' ?></span></div>
            </div>
            <div class="row-actions">
              <?php if ($ageSeconds <= 3600): ?><button class="btn-view" type="button" data-toast="Retry request queued for <?= h($payment['order_number'] ?: ('payment #' . $payment['id'])) ?>">Retry STK</button><?php endif; ?>
              <form method="post" data-ajax>
                <input type="hidden" name="action" value="mark_payment_paid">
                <input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>">
                <button class="btn-confirm" type="submit">Mark paid</button>
              </form>
              <?php if ($ageSeconds > 3600): ?>
                <form method="post" data-ajax>
                  <input type="hidden" name="action" value="flag_payment">
                  <input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>">
                  <button class="btn-danger" type="submit">Flag</button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </section>

      <section class="panel" id="bank">
        <div class="panel-head">
          <div class="panel-title"><i class="fa-solid fa-file-invoice" style="color:#534ab7"></i> Bank transfers <span class="count-badge cb-purple"><?= number_format($metrics['bank']) ?> unverified</span></div>
          <a class="action-btn" href="admin_orders.php">Review</a>
        </div>
        <?php if ($bankPayments === []): ?><div class="empty">No bank transfers need review.</div><?php endif; ?>
        <?php foreach ($bankPayments as $payment): ?>
          <div class="row" data-row>
            <div class="row-icon ri-purple"><i class="fa-solid fa-upload"></i></div>
            <div class="row-body">
              <div class="row-title"><?= h(money($payment['amount'])) ?> - <?= h($payment['order_number'] ?: ('Payment #' . $payment['id'])) ?></div>
              <div class="row-meta"><span><?= h($payment['order_customer'] ?: $payment['repair_customer'] ?: $payment['phone_number']) ?></span><span>Uploaded <?= h(time_ago((string) $payment['created_at'])) ?></span><span class="status-pill sp-manual">Manual review</span></div>
            </div>
            <div class="row-actions">
              <form method="post" data-ajax><input type="hidden" name="action" value="approve_bank"><input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>"><button class="btn-confirm" type="submit">Approve</button></form>
              <form method="post" data-ajax><input type="hidden" name="action" value="query_bank"><input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>"><button class="btn-reject" type="submit">Query</button></form>
            </div>
          </div>
        <?php endforeach; ?>
      </section>

      <section class="panel" id="wholesale">
        <div class="panel-head">
          <div class="panel-title"><i class="fa-solid fa-store"></i> Wholesale applications <span class="count-badge cb-teal"><?= number_format($metrics['wholesale']) ?> pending</span></div>
          <button class="action-btn" type="button" data-toast="Approval policy: verify business identity, repair-shop/reseller fit, payment reliability, and delivery location.">Review criteria</button>
        </div>
        <?php if ($wholesaleApps === []): ?><div class="empty">No pending wholesale applications.</div><?php endif; ?>
        <?php foreach ($wholesaleApps as $application): ?>
          <div class="row" data-row>
            <div class="row-icon ri-teal"><i class="fa-solid fa-building"></i></div>
            <div class="row-body">
              <div class="row-title"><?= h($application['business_name']) ?> - <?= h($application['contact_name']) ?></div>
              <div class="row-meta"><span>Applied <?= h(time_ago((string) $application['created_at'])) ?></span><span><?= h($application['business_location']) ?></span><span class="status-pill sp-new">New applicant</span></div>
            </div>
            <div class="row-actions">
              <form method="post" data-ajax><input type="hidden" name="action" value="approve_wholesale"><input type="hidden" name="application_id" value="<?= (int) $application['id'] ?>"><button class="btn-confirm" type="submit">Approve</button></form>
              <form method="post" data-ajax><input type="hidden" name="action" value="defer_wholesale"><input type="hidden" name="application_id" value="<?= (int) $application['id'] ?>"><button class="btn-reject" type="submit">Defer</button></form>
            </div>
          </div>
        <?php endforeach; ?>
      </section>

      <section class="panel full" id="blog">
        <div class="panel-head">
          <div class="panel-title"><i class="fa-solid fa-lightbulb" style="color:#ba7517"></i> Blog topics from customer issues <span class="count-badge cb-amber"><?= count($blogSuggestions) ?> suggestions</span></div>
          <a class="action-btn" href="blog.php">Public blog</a>
        </div>
        <div class="blog-grid">
          <?php foreach ($blogSuggestions as $suggestion): ?>
            <article class="blog-card">
              <div style="flex:1;min-width:0">
                <span class="freq-badge"><?= (int) $suggestion['queries'] ?> queries</span>
                <div class="blog-topic"><?= h($suggestion['topic']) ?></div>
                <div class="blog-meta"><?= h($suggestion['meta']) ?></div>
              </div>
              <button class="btn-confirm" type="button" data-prompt="<?= h('Draft: ' . $suggestion['topic']) ?>">Write</button>
            </article>
          <?php endforeach; ?>
        </div>
      </section>
    </main>
  </div>

  <script>
    const toastEl = document.getElementById('toast');
    const urgentEl = document.getElementById('urgent-count');

    function toast(message) {
      toastEl.textContent = message;
      toastEl.classList.add('show');
      window.setTimeout(() => toastEl.classList.remove('show'), 3000);
    }

    function updateUrgent() {
      const pending = document.querySelectorAll('[data-ajax] button:not([disabled])').length;
      urgentEl.textContent = pending + ' urgent actions';
    }

    document.querySelectorAll('[data-toast]').forEach((button) => {
      button.addEventListener('click', () => toast(button.dataset.toast));
    });

    document.querySelectorAll('[data-prompt]').forEach((button) => {
      button.addEventListener('click', () => toast(button.dataset.prompt));
    });

    document.querySelectorAll('[data-ajax]').forEach((form) => {
      form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const button = form.querySelector('button');
        button.disabled = true;
        const original = button.textContent;
        button.textContent = 'Working';

        try {
          const response = await fetch('admin_dashboard.php', {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' },
            body: new FormData(form)
          });
          const payload = await response.json();
          if (!payload.ok) {
            throw new Error(payload.message || 'Action failed');
          }
          const row = form.closest('[data-row]');
          if (row) row.classList.add('done');
          button.textContent = 'Done';
          toast(payload.message || 'Done');
          updateUrgent();
        } catch (error) {
          button.disabled = false;
          button.textContent = original;
          toast(error.message || 'Action failed');
        }
      });
    });

    document.querySelectorAll('.metric').forEach((metric) => {
      metric.addEventListener('click', () => {
        document.querySelectorAll('.metric').forEach((item) => item.classList.remove('active'));
        metric.classList.add('active');
      });
    });
  </script>
<?php endif; ?>
</body>
</html>
