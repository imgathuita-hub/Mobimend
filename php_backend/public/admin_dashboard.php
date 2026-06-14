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

function user_role(?array $user): string
{
    return is_array($user) ? (string) ($user['role'] ?? '') : '';
}

function can_view_finance(?array $user): bool
{
    return in_array(user_role($user), ['admin', 'super_admin', 'finance'], true);
}

function can_view_operations(?array $user): bool
{
    return in_array(user_role($user), ['admin', 'super_admin', 'technician'], true);
}

function can_view_inventory(?array $user): bool
{
    return in_array(user_role($user), ['admin', 'super_admin', 'technician'], true);
}

function can_manage_business(?array $user): bool
{
    return in_array(user_role($user), ['admin', 'super_admin'], true);
}

function scalar_value(PDO $pdo, string $sql, array $params = []): mixed
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function rows(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function percent_value(int|float $part, int|float $whole): float
{
    if ((float) $whole <= 0.0) {
        return 0.0;
    }

    return round(((float) $part / (float) $whole) * 100, 1);
}

function age_seconds(?string $datetime): int
{
    $timestamp = strtotime((string) $datetime);
    if (!$timestamp) {
        return 0;
    }

    return max(0, time() - $timestamp);
}

function priority_from_score(int $score): string
{
    if ($score >= 85) {
        return 'critical';
    }
    if ($score >= 60) {
        return 'high';
    }

    return 'normal';
}

function sla_label(int $ageSeconds, int $warningSeconds, int $breachSeconds): array
{
    if ($ageSeconds >= $breachSeconds) {
        return ['label' => 'SLA breached', 'class' => 'sla-breached'];
    }
    if ($ageSeconds >= $warningSeconds) {
        return ['label' => 'Due soon', 'class' => 'sla-warning'];
    }

    return ['label' => 'On track', 'class' => 'sla-ok'];
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
            if (!can_view_operations($user)) {
                throw new RuntimeException('You do not have permission to manage repairs.');
            }

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
            if (!can_view_finance($user)) {
                throw new RuntimeException('You do not have permission to verify payments.');
            }

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
            if (!can_view_finance($user)) {
                throw new RuntimeException('You do not have permission to review payments.');
            }

            $paymentId = (int) ($_POST['payment_id'] ?? 0);
            if ($paymentId <= 0) {
                throw new RuntimeException('Payment is required.');
            }

            $stmt = $pdo->prepare('UPDATE payments SET status = "requires_review", updated_at = :updated_at WHERE id = :id');
            $stmt->execute(['id' => $paymentId, 'updated_at' => now()]);
            admin_reply($action === 'query_bank' ? 'Bank transfer moved to query.' : 'Payment flagged for manual review.', 'info');
        }

        if ($action === 'approve_wholesale' || $action === 'defer_wholesale') {
            if (!can_manage_business($user)) {
                throw new RuntimeException('You do not have permission to manage wholesale applications.');
            }

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
            if (!can_view_inventory($user)) {
                throw new RuntimeException('You do not have permission to manage inventory alerts.');
            }

            $jobId = (int) ($_POST['job_id'] ?? 0);
            if ($jobId <= 0) {
                throw new RuntimeException('Alert job is required.');
            }

            $stmt = $pdo->prepare('UPDATE inventory_alert_jobs SET status = "completed", processed_at = :processed_at, updated_at = :updated_at WHERE id = :id');
            $stmt->execute(['id' => $jobId, 'processed_at' => now(), 'updated_at' => now()]);
            admin_reply('Restock action recorded.');
        }

        if ($action === 'urgent_restock') {
            if (!can_view_inventory($user)) {
                throw new RuntimeException('You do not have permission to request restock.');
            }

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

$role = user_role($user);
$canSeeFinance = can_view_finance($user);
$canSeeOps = can_view_operations($user);
$canSeeInventory = can_view_inventory($user);
$canManageBusiness = can_manage_business($user);

$kpis = [
    'today_revenue' => 0.0,
    'month_revenue' => 0.0,
    'today_orders' => 0,
    'open_orders' => 0,
    'today_repairs' => 0,
    'open_repairs' => 0,
    'completed_repairs_today' => 0,
    'payment_success_rate' => 0.0,
    'payment_review' => 0,
    'low_stock' => $metrics['stock'],
    'out_of_stock' => 0,
    'inventory_value' => 0.0,
    'gross_profit_30' => 0.0,
    'wholesale_pending' => $metrics['wholesale'],
];

$chartData = [
    'labels' => [],
    'revenue' => [],
    'repairsBooked' => [],
    'repairsCompleted' => [],
    'paymentLabels' => [],
    'paymentValues' => [],
    'orderLabels' => [],
    'orderValues' => [],
    'inventoryLabels' => [],
    'inventoryValues' => [],
];

$topMovers = [];
$recentSignals = [];

if ($user) {
    $kpis['today_revenue'] = (float) scalar_value(
        $pdo,
        'SELECT COALESCE(SUM(amount), 0)
         FROM payments
         WHERE status = "paid"
           AND DATE(COALESCE(verified_at, updated_at, created_at)) = CURDATE()'
    );
    $kpis['month_revenue'] = (float) scalar_value(
        $pdo,
        'SELECT COALESCE(SUM(amount), 0)
         FROM payments
         WHERE status = "paid"
           AND COALESCE(verified_at, updated_at, created_at) >= DATE_FORMAT(CURDATE(), "%Y-%m-01")'
    );
    $kpis['today_orders'] = (int) scalar_value($pdo, 'SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()');
    $kpis['open_orders'] = (int) scalar_value($pdo, 'SELECT COUNT(*) FROM orders WHERE status IN ("pending", "confirmed", "processing", "ready")');
    $kpis['today_repairs'] = (int) scalar_value($pdo, 'SELECT COUNT(*) FROM repair_bookings WHERE DATE(created_at) = CURDATE()');
    $kpis['open_repairs'] = (int) scalar_value($pdo, 'SELECT COUNT(*) FROM repair_bookings WHERE status IN ("Pending", "pending", "In Progress", "in progress", "Unconfirmed", "unconfirmed")');
    $kpis['completed_repairs_today'] = (int) scalar_value($pdo, 'SELECT COUNT(*) FROM repair_bookings WHERE status IN ("Completed", "completed") AND DATE(updated_at) = CURDATE()');
    $paymentAttempts = (int) scalar_value($pdo, 'SELECT COUNT(*) FROM payments WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)');
    $paidAttempts = (int) scalar_value($pdo, 'SELECT COUNT(*) FROM payments WHERE status = "paid" AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)');
    $kpis['payment_success_rate'] = percent_value($paidAttempts, $paymentAttempts);
    $kpis['payment_review'] = (int) scalar_value($pdo, 'SELECT COUNT(*) FROM payments WHERE status IN ("pending", "processing", "requires_review")');
    $kpis['out_of_stock'] = (int) scalar_value($pdo, 'SELECT COUNT(*) FROM inventory_items WHERE quantity <= 0 OR status = "sold_out"');
    $kpis['inventory_value'] = (float) scalar_value($pdo, 'SELECT COALESCE(SUM(quantity * buy_price), 0) FROM inventory_items WHERE quantity > 0');
    $kpis['gross_profit_30'] = (float) scalar_value($pdo, 'SELECT COALESCE(SUM(profit), 0) FROM inventory_transactions WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)');

    $dateLabels = [];
    for ($offset = 13; $offset >= 0; $offset--) {
        $date = date('Y-m-d', strtotime('-' . $offset . ' days'));
        $dateLabels[$date] = [
            'label' => date('M j', strtotime($date)),
            'revenue' => 0.0,
            'booked' => 0,
            'completed' => 0,
        ];
    }

    foreach (rows(
        $pdo,
        'SELECT DATE(COALESCE(verified_at, updated_at, created_at)) AS metric_day, COALESCE(SUM(amount), 0) AS total
         FROM payments
         WHERE status = "paid"
           AND COALESCE(verified_at, updated_at, created_at) >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
         GROUP BY DATE(COALESCE(verified_at, updated_at, created_at))'
    ) as $row) {
        $dayKey = (string) $row['metric_day'];
        if (isset($dateLabels[$dayKey])) {
            $dateLabels[$dayKey]['revenue'] = (float) $row['total'];
        }
    }

    foreach (rows(
        $pdo,
        'SELECT DATE(created_at) AS metric_day, COUNT(*) AS total
         FROM repair_bookings
         WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
         GROUP BY DATE(created_at)'
    ) as $row) {
        $dayKey = (string) $row['metric_day'];
        if (isset($dateLabels[$dayKey])) {
            $dateLabels[$dayKey]['booked'] = (int) $row['total'];
        }
    }

    foreach (rows(
        $pdo,
        'SELECT DATE(updated_at) AS metric_day, COUNT(*) AS total
         FROM repair_bookings
         WHERE status IN ("Completed", "completed")
           AND updated_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
         GROUP BY DATE(updated_at)'
    ) as $row) {
        $dayKey = (string) $row['metric_day'];
        if (isset($dateLabels[$dayKey])) {
            $dateLabels[$dayKey]['completed'] = (int) $row['total'];
        }
    }

    foreach ($dateLabels as $bucket) {
        $chartData['labels'][] = $bucket['label'];
        $chartData['revenue'][] = $bucket['revenue'];
        $chartData['repairsBooked'][] = $bucket['booked'];
        $chartData['repairsCompleted'][] = $bucket['completed'];
    }

    foreach (rows($pdo, 'SELECT status, COUNT(*) AS total FROM payments WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY status ORDER BY total DESC') as $row) {
        $chartData['paymentLabels'][] = ucwords(str_replace('_', ' ', (string) $row['status']));
        $chartData['paymentValues'][] = (int) $row['total'];
    }

    foreach (rows($pdo, 'SELECT status, COUNT(*) AS total FROM orders WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY status ORDER BY total DESC') as $row) {
        $chartData['orderLabels'][] = ucwords((string) $row['status']);
        $chartData['orderValues'][] = (int) $row['total'];
    }

    foreach (rows($pdo, 'SELECT part_type, SUM(quantity) AS total FROM inventory_items GROUP BY part_type ORDER BY total ASC LIMIT 8') as $row) {
        $chartData['inventoryLabels'][] = (string) $row['part_type'];
        $chartData['inventoryValues'][] = (int) $row['total'];
    }

    $topMovers = rows(
        $pdo,
        'SELECT brand, model, part_type, COALESCE(SUM(quantity), 0) AS units, COALESCE(SUM(total_revenue), 0) AS revenue, COALESCE(SUM(profit), 0) AS profit
         FROM inventory_transactions
         WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
         GROUP BY brand, model, part_type
         ORDER BY units DESC, revenue DESC
         LIMIT 6'
    );

    $recentSignals = rows(
        $pdo,
        '(SELECT "payment" AS signal_type, CONCAT(payment_method, " ", status) AS title, amount AS value, created_at
          FROM payments
          ORDER BY created_at DESC
          LIMIT 4)
         UNION ALL
         (SELECT "repair" AS signal_type, CONCAT(device_model, " ", repair_type) AS title, estimated_price AS value, created_at
          FROM repair_bookings
          ORDER BY created_at DESC
          LIMIT 4)
         ORDER BY created_at DESC
         LIMIT 6'
    );
}

$workstreamItems = [];

if ($canSeeFinance) {
    foreach ($mpesaPayments as $payment) {
        $age = age_seconds((string) $payment['created_at']);
        $score = $age > 86400 ? 96 : ($age > 3600 ? 86 : 64);
        $sla = sla_label($age, 900, 3600);
        $workstreamItems[] = [
            'type' => 'payment',
            'lane' => 'Revenue Blocked',
            'icon' => 'fa-credit-card',
            'score' => $score,
            'priority' => priority_from_score($score),
            'sla' => $sla,
            'title' => $age > 3600 ? 'M-Pesa payment timed out' : 'M-Pesa awaiting callback',
            'subject' => (string) ($payment['order_number'] ?: ('Payment #' . $payment['id'])),
            'customer' => (string) ($payment['phone_number'] ?: 'Customer phone missing'),
            'value' => money($payment['amount']),
            'age' => time_ago((string) $payment['created_at']),
            'impact' => $age > 3600 ? 'Revenue and fulfillment are blocked until finance resolves this payment.' : 'Payment prompt is still processing; monitor before fulfillment.',
            'actions' => [
                ['kind' => 'form', 'label' => 'Mark paid', 'class' => 'btn-confirm', 'fields' => ['action' => 'mark_payment_paid', 'payment_id' => (int) $payment['id']]],
                ['kind' => 'form', 'label' => 'Flag', 'class' => 'btn-danger', 'fields' => ['action' => 'flag_payment', 'payment_id' => (int) $payment['id']]],
                ['kind' => 'link', 'label' => 'Orders', 'class' => 'btn-view', 'href' => 'admin_orders.php'],
            ],
        ];
    }

    foreach ($bankPayments as $payment) {
        $age = age_seconds((string) $payment['created_at']);
        $score = $age > 86400 ? 88 : 68;
        $sla = sla_label($age, 14400, 86400);
        $workstreamItems[] = [
            'type' => 'payment',
            'lane' => 'Revenue Blocked',
            'icon' => 'fa-building-columns',
            'score' => $score,
            'priority' => priority_from_score($score),
            'sla' => $sla,
            'title' => 'Bank transfer needs review',
            'subject' => (string) ($payment['order_number'] ?: ('Payment #' . $payment['id'])),
            'customer' => (string) ($payment['order_customer'] ?: $payment['repair_customer'] ?: $payment['phone_number']),
            'value' => money($payment['amount']),
            'age' => time_ago((string) $payment['created_at']),
            'impact' => 'Manual verification is needed before revenue is recognized or fulfillment proceeds.',
            'actions' => [
                ['kind' => 'form', 'label' => 'Approve', 'class' => 'btn-confirm', 'fields' => ['action' => 'approve_bank', 'payment_id' => (int) $payment['id']]],
                ['kind' => 'form', 'label' => 'Query', 'class' => 'btn-reject', 'fields' => ['action' => 'query_bank', 'payment_id' => (int) $payment['id']]],
            ],
        ];
    }
}

if ($canSeeOps) {
    foreach ($bookings as $booking) {
        $age = age_seconds((string) $booking['created_at']);
        $score = $age > 86400 ? 92 : ($age > 14400 ? 75 : 58);
        $sla = sla_label($age, 1800, 14400);
        $workstreamItems[] = [
            'type' => 'repair',
            'lane' => 'Customer Waiting',
            'icon' => 'fa-mobile-screen',
            'score' => $score,
            'priority' => priority_from_score($score),
            'sla' => $sla,
            'title' => 'Repair booking needs confirmation',
            'subject' => (string) $booking['device_model'] . ' - ' . (string) $booking['repair_type'],
            'customer' => (string) $booking['customer_name'],
            'value' => money($booking['estimated_price'] ?? 0),
            'age' => time_ago((string) $booking['created_at']),
            'impact' => 'Customer is waiting for confirmation before the repair pipeline can start.',
            'actions' => [
                ['kind' => 'form', 'label' => 'Confirm', 'class' => 'btn-confirm', 'fields' => ['action' => 'confirm_booking', 'booking_id' => (int) $booking['id']]],
                ['kind' => 'link', 'label' => 'View', 'class' => 'btn-view', 'href' => 'admin_repairs.php'],
            ],
        ];
    }

    foreach ($partsNeeded as $part) {
        $item = $part['item'];
        $booking = $part['booking'];
        $qty = (int) $item['quantity'];
        $score = $qty <= 0 ? 94 : 72;
        $workstreamItems[] = [
            'type' => 'inventory',
            'lane' => 'Stock Risk',
            'icon' => 'fa-microchip',
            'score' => $score,
            'priority' => priority_from_score($score),
            'sla' => ['label' => $qty <= 0 ? 'Repair blocked' : 'Low buffer', 'class' => $qty <= 0 ? 'sla-breached' : 'sla-warning'],
            'title' => $qty <= 0 ? 'Part unavailable for repair' : 'Part stock may block repair',
            'subject' => (string) $item['brand'] . ' ' . (string) $item['model'] . ' ' . (string) $item['part_type'],
            'customer' => 'For ' . (string) $booking['customer_name'],
            'value' => number_format($qty) . ' units',
            'age' => 'Reorder at ' . number_format((int) $item['reorder_point']),
            'impact' => $qty <= 0 ? 'Repair cannot be completed until this part is sourced.' : 'Repair demand is close to exhausting available stock.',
            'actions' => [
                ['kind' => 'form', 'label' => $qty <= 0 ? 'Order' : 'Restock', 'class' => $qty <= 0 ? 'btn-danger' : 'btn-warn', 'fields' => ['action' => 'urgent_restock', 'inventory_item_id' => (int) $item['id']]],
                ['kind' => 'link', 'label' => 'Inventory', 'class' => 'btn-view', 'href' => 'admin_inventory.php'],
            ],
        ];
    }
}

if ($canSeeInventory) {
    foreach ($lowStock as $item) {
        $qty = (int) $item['quantity'];
        $score = $qty <= 0 ? 90 : 66;
        $workstreamItems[] = [
            'type' => 'inventory',
            'lane' => 'Stock Risk',
            'icon' => 'fa-cubes-stacked',
            'score' => $score,
            'priority' => priority_from_score($score),
            'sla' => ['label' => $qty <= 0 ? 'Stockout' : 'Reorder now', 'class' => $qty <= 0 ? 'sla-breached' : 'sla-warning'],
            'title' => $qty <= 0 ? 'Inventory line is out of stock' : 'Inventory line is below reorder point',
            'subject' => (string) $item['brand'] . ' ' . (string) $item['model'] . ' ' . (string) $item['part_type'],
            'customer' => 'Stock control',
            'value' => number_format($qty) . ' units',
            'age' => 'Reorder at ' . number_format((int) $item['reorder_point']),
            'impact' => 'Protect repair completion, shop sales, and wholesale fulfillment from stockouts.',
            'actions' => [
                ['kind' => 'form', 'label' => $qty <= 0 ? 'Urgent order' : 'Reorder', 'class' => $qty <= 0 ? 'btn-danger' : 'btn-warn', 'fields' => ['action' => 'urgent_restock', 'inventory_item_id' => (int) $item['id']]],
                ['kind' => 'link', 'label' => 'Manage', 'class' => 'btn-view', 'href' => 'admin_inventory.php'],
            ],
        ];
    }
}

if ($canManageBusiness) {
    foreach ($wholesaleApps as $application) {
        $age = age_seconds((string) $application['created_at']);
        $score = $age > 172800 ? 70 : 48;
        $sla = sla_label($age, 86400, 172800);
        $workstreamItems[] = [
            'type' => 'wholesale',
            'lane' => 'Growth Opportunity',
            'icon' => 'fa-store',
            'score' => $score,
            'priority' => priority_from_score($score),
            'sla' => $sla,
            'title' => 'Wholesale application awaiting decision',
            'subject' => (string) $application['business_name'],
            'customer' => (string) $application['contact_name'] . ' - ' . (string) $application['business_location'],
            'value' => 'New account',
            'age' => time_ago((string) $application['created_at']),
            'impact' => 'A qualified reseller may unlock repeat wholesale revenue.',
            'actions' => [
                ['kind' => 'form', 'label' => 'Approve', 'class' => 'btn-confirm', 'fields' => ['action' => 'approve_wholesale', 'application_id' => (int) $application['id']]],
                ['kind' => 'form', 'label' => 'Defer', 'class' => 'btn-reject', 'fields' => ['action' => 'defer_wholesale', 'application_id' => (int) $application['id']]],
            ],
        ];
    }
}

usort($workstreamItems, static fn (array $a, array $b): int => ($b['score'] <=> $a['score']));

$workstreamStats = [
    'all' => count($workstreamItems),
    'critical' => count(array_filter($workstreamItems, static fn (array $item): bool => $item['priority'] === 'critical')),
    'payment' => count(array_filter($workstreamItems, static fn (array $item): bool => $item['type'] === 'payment')),
    'repair' => count(array_filter($workstreamItems, static fn (array $item): bool => $item['type'] === 'repair')),
    'inventory' => count(array_filter($workstreamItems, static fn (array $item): bool => $item['type'] === 'inventory')),
    'wholesale' => count(array_filter($workstreamItems, static fn (array $item): bool => $item['type'] === 'wholesale')),
];

$urgentActions = count($workstreamItems);
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
      --bg: #eef2f6;
      --surface: #fff;
      --surface-2: #f3f4f6;
      --line: rgba(31, 30, 29, 0.15);
      --line-strong: rgba(31, 30, 29, 0.3);
      --text: #141413;
      --muted: #5f5e5a;
      --soft: #77766f;
      --nav: #101820;
      --nav-soft: rgba(255, 255, 255, 0.72);
    }
    * { box-sizing: border-box; }
    body { margin: 0; background: var(--bg); color: var(--text); font-family: Inter, Arial, sans-serif; letter-spacing: 0; }
    a { color: inherit; }
    .sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0, 0, 0, 0); }
    .wrap { min-height: 100vh; background: var(--bg); display: grid; grid-template-columns: 260px minmax(0, 1fr); }
    .ops-rail { position: sticky; top: 0; height: 100vh; background: var(--nav); color: #fff; padding: 18px 14px; display: flex; flex-direction: column; gap: 18px; }
    .rail-brand { display: flex; align-items: center; gap: 10px; padding: 4px 6px 14px; border-bottom: 1px solid rgba(255,255,255,.1); }
    .rail-mark { width: 36px; height: 36px; border-radius: 8px; display: grid; place-items: center; background: #1d9e75; color: #fff; font-weight: 900; }
    .rail-brand strong { display: block; font-size: 15px; line-height: 1; }
    .rail-brand span { display: block; margin-top: 4px; font-size: 11px; color: var(--nav-soft); }
    .rail-section-label { color: rgba(255,255,255,.44); font-size: 10px; font-weight: 900; text-transform: uppercase; padding: 0 8px; margin-bottom: 7px; }
    .rail-nav { display: grid; gap: 5px; }
    .rail-link { display: flex; align-items: center; gap: 10px; min-height: 38px; padding: 9px 10px; border-radius: 8px; color: var(--nav-soft); text-decoration: none; font-size: 12px; font-weight: 800; }
    .rail-link:hover, .rail-link.active { color: #fff; background: rgba(255,255,255,.1); }
    .rail-link i { width: 16px; text-align: center; color: #5dcaa5; }
    .rail-profile { margin-top: auto; padding: 12px; border: 1px solid rgba(255,255,255,.12); border-radius: 8px; background: rgba(255,255,255,.06); }
    .rail-profile strong { display: block; font-size: 12px; }
    .rail-profile span { display: block; margin-top: 4px; color: var(--nav-soft); font-size: 11px; }
    .dashboard-stage { min-width: 0; }
    .top-bar { position: sticky; top: 0; z-index: 20; display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 20px; border-bottom: 1px solid var(--line); background: rgba(255, 255, 255, 0.94); backdrop-filter: blur(12px); }
    .top-bar-left, .top-bar-right { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .logo { font-size: 17px; font-weight: 800; }
    .logo span { color: #1d9e75; }
    .badge-urgent { background: #faece7; color: #993c1d; font-size: 11px; padding: 3px 9px; border-radius: 20px; font-weight: 800; }
    .admin-pill, .date-chip { font-size: 12px; color: var(--muted); background: var(--surface-2); border: 1px solid var(--line); padding: 5px 10px; border-radius: 20px; }
    .action-btn, .btn-view, .btn-reject { font-size: 11px; color: var(--muted); border: 1px solid var(--line); background: var(--surface); padding: 5px 10px; border-radius: 6px; cursor: pointer; text-decoration: none; white-space: nowrap; }
    .action-btn:hover, .btn-view:hover, .btn-reject:hover { background: var(--surface-2); }
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
    .cockpit { padding: 18px 20px 4px; background: #f6f8fb; border-bottom: 1px solid var(--line); }
    .cockpit-hero { display: flex; align-items: flex-start; justify-content: space-between; gap: 18px; margin-bottom: 14px; }
    .cockpit-title { margin: 0; font-size: 24px; line-height: 1.12; letter-spacing: 0; }
    .cockpit-copy { margin: 6px 0 0; color: var(--muted); font-size: 13px; max-width: 760px; }
    .role-chip { display: inline-flex; align-items: center; gap: 7px; min-height: 32px; padding: 7px 11px; border: 1px solid var(--line); border-radius: 999px; background: #fff; color: #334155; font-size: 12px; font-weight: 800; white-space: nowrap; }
    .kpi-grid { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 10px; }
    .kpi-card { background: #fff; border: 1px solid var(--line); border-radius: 8px; padding: 13px 14px; min-height: 112px; display: flex; flex-direction: column; justify-content: space-between; }
    .kpi-top { display: flex; align-items: center; justify-content: space-between; gap: 10px; color: var(--muted); font-size: 11px; font-weight: 800; text-transform: uppercase; }
    .kpi-icon { width: 30px; height: 30px; border-radius: 8px; display: grid; place-items: center; background: #e8f5ef; color: #0f6e56; flex: 0 0 auto; }
    .kpi-value { margin-top: 10px; font-size: 24px; font-weight: 900; line-height: 1; }
    .kpi-note { margin-top: 6px; color: var(--soft); font-size: 11px; }
    .chart-grid { display: grid; grid-template-columns: minmax(0, 1.45fr) minmax(320px, .85fr); gap: 12px; margin-top: 12px; }
    .chart-panel { background: #fff; border: 1px solid var(--line); border-radius: 8px; padding: 14px; min-width: 0; }
    .chart-panel-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 10px; }
    .chart-panel h2 { margin: 0; font-size: 14px; line-height: 1.2; }
    .chart-panel p { margin: 3px 0 0; color: var(--muted); font-size: 11px; }
    .chart-box { position: relative; height: 272px; width: 100%; }
    .chart-box.small { height: 224px; }
    .insight-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-top: 12px; }
    .insight-card { background: #fff; border: 1px solid var(--line); border-radius: 8px; padding: 14px; min-width: 0; }
    .insight-title { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 10px; font-size: 13px; font-weight: 900; }
    .mini-list { display: grid; gap: 9px; }
    .mini-item { display: flex; align-items: center; justify-content: space-between; gap: 10px; border-top: 1px solid var(--line); padding-top: 9px; font-size: 12px; }
    .mini-item:first-child { border-top: none; padding-top: 0; }
    .mini-main { min-width: 0; }
    .mini-main strong { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .mini-main span { display: block; color: var(--muted); font-size: 11px; margin-top: 2px; }
    .mini-value { font-weight: 900; white-space: nowrap; }
    .automation-strip { display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: center; gap: 12px; margin-top: 12px; padding: 13px 14px; background: #111827; color: #fff; border-radius: 8px; }
    .automation-strip strong { display: block; font-size: 13px; }
    .automation-strip span { display: block; color: #cbd5e1; font-size: 12px; margin-top: 3px; }
    .workstream-intro { display: flex; align-items: flex-end; justify-content: space-between; gap: 14px; padding: 18px 20px 0; background: var(--surface); }
    .workstream-intro h2 { margin: 0; font-size: 18px; line-height: 1.2; }
    .workstream-intro p { margin: 5px 0 0; color: var(--muted); font-size: 12px; max-width: 760px; }
    .workstream-meta { display: inline-flex; align-items: center; gap: 7px; padding: 7px 10px; border: 1px solid var(--line); border-radius: 999px; color: var(--muted); background: var(--surface-2); font-size: 11px; font-weight: 800; white-space: nowrap; }
    .command-board { padding: 16px 20px 28px; display: grid; gap: 12px; }
    .workstream-filters { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .filter-chip { min-height: 34px; border: 1px solid var(--line); border-radius: 999px; background: #fff; color: var(--muted); padding: 7px 11px; font-size: 12px; font-weight: 900; cursor: pointer; }
    .filter-chip.active { border-color: #1d9e75; color: #0f6e56; background: #e8f5ef; }
    .queue-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 10px; }
    .queue-card { display: grid; grid-template-columns: auto minmax(0, 1fr) auto; gap: 12px; align-items: flex-start; padding: 14px; border: 1px solid var(--line); border-radius: 8px; background: #fff; min-width: 0; }
    .queue-card[data-priority="critical"] { border-left: 4px solid #e24b4a; }
    .queue-card[data-priority="high"] { border-left: 4px solid #ef9f27; }
    .queue-card[data-priority="normal"] { border-left: 4px solid #1d9e75; }
    .queue-icon { width: 38px; height: 38px; border-radius: 8px; display: grid; place-items: center; background: #f3f4f6; color: #185fa5; }
    .queue-main { min-width: 0; }
    .queue-kicker { display: flex; align-items: center; gap: 7px; flex-wrap: wrap; margin-bottom: 6px; }
    .priority-pill, .sla-pill, .lane-pill { display: inline-flex; align-items: center; min-height: 22px; border-radius: 999px; padding: 3px 8px; font-size: 10px; font-weight: 900; text-transform: uppercase; }
    .priority-critical { background: #fcebeb; color: #a32d2d; }
    .priority-high { background: #faeeda; color: #854f0b; }
    .priority-normal { background: #e1f5ee; color: #0f6e56; }
    .sla-breached { background: #fcebeb; color: #a32d2d; }
    .sla-warning { background: #fff7df; color: #854f0b; }
    .sla-ok { background: #e1f5ee; color: #0f6e56; }
    .lane-pill { background: #eef2f7; color: #334155; text-transform: none; }
    .queue-title { margin: 0; font-size: 14px; line-height: 1.25; font-weight: 900; }
    .queue-subject { margin-top: 3px; color: #334155; font-size: 13px; font-weight: 800; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .queue-impact { margin-top: 7px; color: var(--muted); font-size: 12px; line-height: 1.4; max-width: 760px; }
    .queue-facts { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 8px; color: var(--soft); font-size: 11px; }
    .queue-facts span { display: inline-flex; align-items: center; gap: 5px; }
    .queue-actions { display: flex; gap: 6px; align-items: center; justify-content: flex-end; flex-wrap: wrap; min-width: 180px; }
    .queue-empty { padding: 28px; border: 1px dashed var(--line-strong); border-radius: 8px; background: #fff; text-align: center; color: var(--muted); }
    @media (max-width: 1080px) { .wrap { grid-template-columns: 1fr; } .ops-rail { position: static; height: auto; flex-direction: row; align-items: center; overflow-x: auto; } .rail-brand, .rail-profile { flex: 0 0 auto; border-bottom: none; } .rail-section { flex: 1 0 auto; } .rail-nav { display: flex; } .kpi-grid { grid-template-columns: repeat(3, 1fr); } .main, .chart-grid, .insight-grid { grid-template-columns: 1fr; } }
    @media (max-width: 720px) { .ops-rail { align-items: flex-start; } .rail-profile { display: none; } .top-bar, .cockpit-hero, .automation-strip, .workstream-intro { align-items: flex-start; } .cockpit, .command-board { padding: 12px; } .kpi-grid { grid-template-columns: repeat(2, 1fr); } .main { padding: 12px; } .row, .queue-card { grid-template-columns: 1fr; flex-wrap: wrap; } .queue-actions { justify-content: flex-start; min-width: 0; } .row-actions { width: 100%; justify-content: flex-start; padding-left: 42px; } .blog-grid { grid-template-columns: 1fr; } .blog-card:nth-child(odd) { border-right: none; } .cockpit-title { font-size: 20px; } .chart-box { height: 230px; } }
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
    <aside class="ops-rail" aria-label="Admin navigation">
      <div class="rail-brand">
        <div class="rail-mark">M</div>
        <div>
          <strong>Mobimend</strong>
          <span>Operations OS</span>
        </div>
      </div>

      <div class="rail-section">
        <div class="rail-section-label">Workspace</div>
        <nav class="rail-nav">
          <a class="rail-link active" href="admin_dashboard.php"><i class="fa-solid fa-chart-simple"></i>Dashboard</a>
          <?php if ($canSeeOps): ?><a class="rail-link" href="admin_repairs.php"><i class="fa-solid fa-screwdriver-wrench"></i>Repairs</a><?php endif; ?>
          <?php if ($canSeeInventory): ?><a class="rail-link" href="admin_inventory.php"><i class="fa-solid fa-boxes-stacked"></i>Inventory</a><?php endif; ?>
          <?php if ($canSeeFinance || $canManageBusiness): ?><a class="rail-link" href="admin_orders.php"><i class="fa-solid fa-receipt"></i>Orders</a><?php endif; ?>
          <?php if ($canManageBusiness): ?><a class="rail-link" href="admin_products.php"><i class="fa-solid fa-tags"></i>Products</a><?php endif; ?>
        </nav>
      </div>

      <div class="rail-section">
        <div class="rail-section-label">Decision Layer</div>
        <nav class="rail-nav">
          <?php if ($canSeeFinance): ?><a class="rail-link" href="#healthChart"><i class="fa-solid fa-credit-card"></i>Payments</a><?php endif; ?>
          <?php if ($canSeeOps): ?><a class="rail-link" href="#bookings"><i class="fa-solid fa-list-check"></i>Workstreams</a><?php endif; ?>
          <?php if ($canManageBusiness): ?><a class="rail-link" href="#blog"><i class="fa-solid fa-lightbulb"></i>Demand Signals</a><?php endif; ?>
        </nav>
      </div>

      <div class="rail-profile">
        <strong><?= h($user['name'] ?? 'Admin') ?></strong>
        <span><?= h(ucwords(str_replace('_', ' ', $role))) ?> access</span>
      </div>
    </aside>

    <div class="dashboard-stage">
    <div id="toast" class="toast"></div>
    <header class="top-bar">
      <div class="top-bar-left">
        <div class="logo">Mobi<span>mend</span> <span style="font-size:11px;font-weight:500;color:var(--soft)">Admin</span></div>
        <div class="badge-urgent">Live operations</div>
      </div>
      <div class="top-bar-right">
        <div class="date-chip"><?= h(date('D, j M Y')) ?></div>
        <div class="admin-pill"><i class="fa-solid fa-user"></i> <?= h($user['name'] ?? 'Admin') ?></div>
        <a class="action-btn" href="logout.php">Logout</a>
      </div>
    </header>

    <section class="cockpit" aria-label="Business cockpit">
      <div class="cockpit-hero">
        <div>
          <h2 class="cockpit-title">
            <?php if ($role === 'technician'): ?>
              Technician repair cockpit
            <?php elseif ($role === 'finance'): ?>
              Finance and payment cockpit
            <?php else: ?>
              Mobimend business cockpit
            <?php endif; ?>
          </h2>
          <p class="cockpit-copy">
            <?php if ($role === 'technician'): ?>
              Prioritize open repairs, parts blockers, and stock risks without finance noise.
            <?php elseif ($role === 'finance'): ?>
              Monitor collections, reconciliation risk, and payment conversion without operational clutter.
            <?php else: ?>
              Track revenue, repairs, payments, stock risk, and customer demand from one operating view.
            <?php endif; ?>
          </p>
        </div>
        <div class="role-chip"><i class="fa-solid fa-shield-halved"></i><?= h(ucwords(str_replace('_', ' ', $role))) ?></div>
      </div>

      <div class="kpi-grid">
        <?php if ($canSeeFinance): ?>
          <article class="kpi-card">
            <div class="kpi-top"><span>Revenue today</span><span class="kpi-icon"><i class="fa-solid fa-sack-dollar"></i></span></div>
            <div><div class="kpi-value"><?= h(money($kpis['today_revenue'])) ?></div><div class="kpi-note"><?= h(money($kpis['month_revenue'])) ?> month-to-date</div></div>
          </article>
          <article class="kpi-card">
            <div class="kpi-top"><span>Payment success</span><span class="kpi-icon"><i class="fa-solid fa-circle-check"></i></span></div>
            <div><div class="kpi-value"><?= number_format((float) $kpis['payment_success_rate'], 1) ?>%</div><div class="kpi-note"><?= number_format((int) $kpis['payment_review']) ?> payments need review</div></div>
          </article>
        <?php endif; ?>

        <?php if ($canSeeOps): ?>
          <article class="kpi-card">
            <div class="kpi-top"><span>Open repairs</span><span class="kpi-icon"><i class="fa-solid fa-screwdriver-wrench"></i></span></div>
            <div><div class="kpi-value"><?= number_format((int) $kpis['open_repairs']) ?></div><div class="kpi-note"><?= number_format((int) $kpis['completed_repairs_today']) ?> completed today</div></div>
          </article>
        <?php endif; ?>

        <?php if ($canSeeInventory): ?>
          <article class="kpi-card">
            <div class="kpi-top"><span>Inventory risk</span><span class="kpi-icon"><i class="fa-solid fa-boxes-stacked"></i></span></div>
            <div><div class="kpi-value"><?= number_format((int) $kpis['low_stock']) ?></div><div class="kpi-note"><?= number_format((int) $kpis['out_of_stock']) ?> out of stock</div></div>
          </article>
        <?php endif; ?>

        <?php if ($canSeeFinance || $canManageBusiness): ?>
          <article class="kpi-card">
            <div class="kpi-top"><span>Order pipeline</span><span class="kpi-icon"><i class="fa-solid fa-truck-fast"></i></span></div>
            <div><div class="kpi-value"><?= number_format((int) $kpis['open_orders']) ?></div><div class="kpi-note"><?= number_format((int) $kpis['today_orders']) ?> new today</div></div>
          </article>
        <?php endif; ?>

        <?php if ($canManageBusiness): ?>
          <article class="kpi-card">
            <div class="kpi-top"><span>Gross profit</span><span class="kpi-icon"><i class="fa-solid fa-chart-line"></i></span></div>
            <div><div class="kpi-value"><?= h(money($kpis['gross_profit_30'])) ?></div><div class="kpi-note">Last 30 days from stock ledger</div></div>
          </article>
        <?php endif; ?>
      </div>

      <div class="chart-grid">
        <section class="chart-panel">
          <div class="chart-panel-head">
            <div>
              <h2><?= $canSeeFinance ? 'Revenue and repair trend' : 'Repair throughput trend' ?></h2>
              <p>Last 14 days, grouped by day.</p>
            </div>
            <span class="count-badge cb-teal">Live SQL</span>
          </div>
          <div class="chart-box"><canvas id="trendChart"></canvas></div>
        </section>

        <section class="chart-panel">
          <div class="chart-panel-head">
            <div>
              <h2><?= $canSeeFinance ? 'Payment health' : 'Inventory levels' ?></h2>
              <p><?= $canSeeFinance ? 'Last 30 days by status.' : 'Lowest stock categories.' ?></p>
            </div>
            <span class="count-badge cb-blue">KPI</span>
          </div>
          <div class="chart-box small"><canvas id="healthChart"></canvas></div>
        </section>
      </div>

      <div class="insight-grid">
        <?php if ($canManageBusiness || $canSeeFinance): ?>
          <section class="insight-card">
            <div class="insight-title"><span><i class="fa-solid fa-bolt"></i> Fast movers</span><a class="action-btn" href="admin_inventory.php">Stock</a></div>
            <div class="mini-list">
              <?php if ($topMovers === []): ?><div class="empty">No movement data yet.</div><?php endif; ?>
              <?php foreach ($topMovers as $mover): ?>
                <div class="mini-item">
                  <div class="mini-main"><strong><?= h($mover['brand'] . ' ' . $mover['model']) ?></strong><span><?= h($mover['part_type']) ?> - <?= number_format((int) $mover['units']) ?> units</span></div>
                  <div class="mini-value"><?= h(money($mover['revenue'])) ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endif; ?>

        <?php if ($canSeeOps): ?>
          <section class="insight-card">
            <div class="insight-title"><span><i class="fa-solid fa-stopwatch"></i> Repair focus</span><a class="action-btn" href="admin_repairs.php">Queue</a></div>
            <div class="mini-list">
              <div class="mini-item"><div class="mini-main"><strong>New repair bookings</strong><span>Needs confirmation and diagnosis</span></div><div class="mini-value"><?= number_format((int) $metrics['bookings']) ?></div></div>
              <div class="mini-item"><div class="mini-main"><strong>Parts blockers</strong><span>Matched against low stock</span></div><div class="mini-value"><?= number_format((int) $metrics['parts']) ?></div></div>
              <div class="mini-item"><div class="mini-main"><strong>Completed today</strong><span>Customer-visible output</span></div><div class="mini-value"><?= number_format((int) $kpis['completed_repairs_today']) ?></div></div>
            </div>
          </section>
        <?php endif; ?>

        <section class="insight-card">
          <div class="insight-title"><span><i class="fa-solid fa-signal"></i> Recent signals</span><span class="count-badge cb-gray"><?= count($recentSignals) ?></span></div>
          <div class="mini-list">
            <?php if ($recentSignals === []): ?><div class="empty">No recent activity.</div><?php endif; ?>
            <?php foreach ($recentSignals as $signal): ?>
              <div class="mini-item">
                <div class="mini-main"><strong><?= h($signal['title']) ?></strong><span><?= h(ucfirst((string) $signal['signal_type'])) ?> - <?= h(time_ago((string) $signal['created_at'])) ?></span></div>
                <div class="mini-value"><?= h(money($signal['value'])) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        </section>
      </div>

      <?php if ($canManageBusiness || $canSeeFinance): ?>
        <div class="automation-strip">
          <div>
            <strong><i class="fa-solid fa-calendar-check"></i> Weekly management report ready for cron automation</strong>
            <span>Next step: schedule a PHP worker to email revenue, repairs, payment health, stock risk, and top movers every Monday.</span>
          </div>
          <button class="action-btn" type="button" data-toast="Recommended cron: run php php_backend/process_weekly_report.php every Monday at 08:00.">Cron plan</button>
        </div>
      <?php endif; ?>
    </section>

    <section class="workstream-intro" aria-label="Action workstreams">
      <div>
        <h2>Action workstreams</h2>
        <p>
          <?php if ($role === 'technician'): ?>
            Work from the highest-friction repair and stock blockers first. Every row carries the customer, device, part, quantity, age, and next action needed.
          <?php elseif ($role === 'finance'): ?>
            Reconcile money by exception: timed-out M-Pesa prompts, manual bank transfers, and payments that need a human decision are surfaced here.
          <?php else: ?>
            Move from signal to action: confirm bookings, unblock parts, recover payments, protect stock, and approve business growth without hunting across pages.
          <?php endif; ?>
        </p>
      </div>
      <div class="workstream-meta" id="urgent-count"><i class="fa-solid fa-list-check"></i><?= number_format($urgentActions) ?> open actions</div>
    </section>

    <section class="command-board" aria-label="Prioritized action queue">
      <div class="workstream-filters">
        <button class="filter-chip active" type="button" data-filter="all">All <?= number_format($workstreamStats['all']) ?></button>
        <button class="filter-chip" type="button" data-filter="critical">Critical <?= number_format($workstreamStats['critical']) ?></button>
        <?php if ($canSeeFinance): ?><button class="filter-chip" type="button" data-filter="payment">Payments <?= number_format($workstreamStats['payment']) ?></button><?php endif; ?>
        <?php if ($canSeeOps): ?><button class="filter-chip" type="button" data-filter="repair">Repairs <?= number_format($workstreamStats['repair']) ?></button><?php endif; ?>
        <?php if ($canSeeInventory): ?><button class="filter-chip" type="button" data-filter="inventory">Inventory <?= number_format($workstreamStats['inventory']) ?></button><?php endif; ?>
        <?php if ($canManageBusiness): ?><button class="filter-chip" type="button" data-filter="wholesale">Wholesale <?= number_format($workstreamStats['wholesale']) ?></button><?php endif; ?>
      </div>

      <div class="queue-grid" id="workstreamQueue">
        <?php if ($workstreamItems === []): ?>
          <div class="queue-empty">No open actions. The operation is clear for this role.</div>
        <?php endif; ?>

        <?php foreach ($workstreamItems as $item): ?>
          <article class="queue-card" data-row data-type="<?= h($item['type']) ?>" data-priority="<?= h($item['priority']) ?>">
            <div class="queue-icon"><i class="fa-solid <?= h($item['icon']) ?>"></i></div>
            <div class="queue-main">
              <div class="queue-kicker">
                <span class="priority-pill priority-<?= h($item['priority']) ?>"><?= h($item['priority']) ?></span>
                <span class="sla-pill <?= h($item['sla']['class']) ?>"><?= h($item['sla']['label']) ?></span>
                <span class="lane-pill"><?= h($item['lane']) ?></span>
              </div>
              <h3 class="queue-title"><?= h($item['title']) ?></h3>
              <div class="queue-subject"><?= h($item['subject']) ?></div>
              <div class="queue-impact"><?= h($item['impact']) ?></div>
              <div class="queue-facts">
                <span><i class="fa-solid fa-user"></i><?= h($item['customer']) ?></span>
                <span><i class="fa-solid fa-coins"></i><?= h($item['value']) ?></span>
                <span><i class="fa-regular fa-clock"></i><?= h($item['age']) ?></span>
              </div>
            </div>
            <div class="queue-actions">
              <?php foreach ($item['actions'] as $action): ?>
                <?php if ($action['kind'] === 'form'): ?>
                  <form method="post" data-ajax>
                    <?php foreach ($action['fields'] as $field => $value): ?>
                      <input type="hidden" name="<?= h($field) ?>" value="<?= h($value) ?>">
                    <?php endforeach; ?>
                    <button class="<?= h($action['class']) ?>" type="submit"><?= h($action['label']) ?></button>
                  </form>
                <?php else: ?>
                  <a class="<?= h($action['class']) ?>" href="<?= h($action['href']) ?>"><?= h($action['label']) ?></a>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script>
    const dashboardData = <?= json_encode($chartData, JSON_THROW_ON_ERROR) ?>;
    const canSeeFinance = <?= $canSeeFinance ? 'true' : 'false' ?>;

    function renderCharts() {
      if (!window.Chart) {
        document.querySelectorAll('.chart-box').forEach((box) => {
          box.innerHTML = '<div class="empty">Charts could not load. KPI numbers are still available.</div>';
        });
        return;
      }

      Chart.defaults.font.family = 'Inter, Arial, sans-serif';
      Chart.defaults.color = '#5f5e5a';

      const trendCanvas = document.getElementById('trendChart');
      if (trendCanvas) {
        const datasets = [];
        if (canSeeFinance) {
          datasets.push({
            type: 'line',
            label: 'Revenue',
            data: dashboardData.revenue,
            borderColor: '#0f6e56',
            backgroundColor: 'rgba(15, 110, 86, 0.12)',
            tension: 0.32,
            fill: true,
            yAxisID: 'money'
          });
        }
        datasets.push({
          type: 'bar',
          label: 'Repairs booked',
          data: dashboardData.repairsBooked,
          backgroundColor: '#185fa5',
          borderRadius: 4,
          yAxisID: 'count'
        });
        datasets.push({
          type: 'bar',
          label: 'Repairs completed',
          data: dashboardData.repairsCompleted,
          backgroundColor: '#ef9f27',
          borderRadius: 4,
          yAxisID: 'count'
        });

        new Chart(trendCanvas, {
          data: { labels: dashboardData.labels, datasets },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, usePointStyle: true } } },
            scales: {
              money: { type: 'linear', display: canSeeFinance, position: 'left', grid: { color: 'rgba(31,30,29,.08)' }, ticks: { callback: (value) => 'KES ' + Number(value).toLocaleString() } },
              count: { type: 'linear', display: true, position: canSeeFinance ? 'right' : 'left', grid: { drawOnChartArea: !canSeeFinance, color: 'rgba(31,30,29,.08)' }, beginAtZero: true, ticks: { precision: 0 } },
              x: { grid: { display: false } }
            }
          }
        });
      }

      const healthCanvas = document.getElementById('healthChart');
      if (healthCanvas) {
        if (canSeeFinance) {
          new Chart(healthCanvas, {
            type: 'doughnut',
            data: {
              labels: dashboardData.paymentLabels.length ? dashboardData.paymentLabels : ['No payments'],
              datasets: [{ data: dashboardData.paymentValues.length ? dashboardData.paymentValues : [1], backgroundColor: ['#0f6e56', '#185fa5', '#ef9f27', '#a32d2d', '#534ab7', '#64748b'], borderWidth: 0 }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, usePointStyle: true } } }, cutout: '62%' }
          });
        } else {
          new Chart(healthCanvas, {
            type: 'bar',
            data: {
              labels: dashboardData.inventoryLabels.length ? dashboardData.inventoryLabels : ['No stock'],
              datasets: [{ label: 'Units', data: dashboardData.inventoryValues.length ? dashboardData.inventoryValues : [0], backgroundColor: '#0f6e56', borderRadius: 4 }]
            },
            options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 } }, y: { grid: { display: false } } } }
          });
        }
      }
    }

    renderCharts();

    const toastEl = document.getElementById('toast');
    const urgentEl = document.getElementById('urgent-count');

    function toast(message) {
      toastEl.textContent = message;
      toastEl.classList.add('show');
      window.setTimeout(() => toastEl.classList.remove('show'), 3000);
    }

    function updateUrgent() {
      const pending = document.querySelectorAll('[data-row]:not(.done)').length;
      urgentEl.innerHTML = '<i class="fa-solid fa-list-check"></i>' + pending + ' open actions';
    }

    function applyWorkstreamFilter(filter) {
      document.querySelectorAll('[data-row]').forEach((row) => {
        const visible = filter === 'all'
          || (filter === 'critical' && row.dataset.priority === 'critical')
          || row.dataset.type === filter;
        row.hidden = !visible;
      });
    }

    document.querySelectorAll('[data-toast]').forEach((button) => {
      button.addEventListener('click', () => toast(button.dataset.toast));
    });

    document.querySelectorAll('[data-prompt]').forEach((button) => {
      button.addEventListener('click', () => toast(button.dataset.prompt));
    });

    document.querySelectorAll('[data-filter]').forEach((button) => {
      button.addEventListener('click', () => {
        document.querySelectorAll('[data-filter]').forEach((item) => item.classList.remove('active'));
        button.classList.add('active');
        applyWorkstreamFilter(button.dataset.filter);
      });
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
    updateUrgent();
  </script>
<?php endif; ?>
</body>
</html>
