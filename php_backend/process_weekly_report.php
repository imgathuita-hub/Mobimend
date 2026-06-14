<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use Mobimend\Config\Database;

$pdo = Database::connection();

function report_scalar(PDO $pdo, string $sql): mixed
{
    return $pdo->query($sql)->fetchColumn();
}

function report_rows(PDO $pdo, string $sql): array
{
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function report_money(mixed $value): string
{
    return 'KES ' . number_format((float) $value, 2);
}

$periodStart = date('Y-m-d', strtotime('monday last week'));
$periodEnd = date('Y-m-d', strtotime('sunday last week'));
$generatedAt = date('Y-m-d H:i:s');

$summary = [
    'revenue' => (float) report_scalar(
        $pdo,
        'SELECT COALESCE(SUM(amount), 0)
         FROM payments
         WHERE status = "paid"
           AND DATE(COALESCE(verified_at, updated_at, created_at)) BETWEEN DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) + 7 DAY)
           AND DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) + 1 DAY)'
    ),
    'orders' => (int) report_scalar(
        $pdo,
        'SELECT COUNT(*)
         FROM orders
         WHERE DATE(created_at) BETWEEN DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) + 7 DAY)
           AND DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) + 1 DAY)'
    ),
    'completed_repairs' => (int) report_scalar(
        $pdo,
        'SELECT COUNT(*)
         FROM repair_bookings
         WHERE status IN ("Completed", "completed")
           AND DATE(updated_at) BETWEEN DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) + 7 DAY)
           AND DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) + 1 DAY)'
    ),
    'failed_payments' => (int) report_scalar(
        $pdo,
        'SELECT COUNT(*)
         FROM payments
         WHERE status IN ("failed", "cancelled", "requires_review")
           AND DATE(created_at) BETWEEN DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) + 7 DAY)
           AND DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) + 1 DAY)'
    ),
    'low_stock' => (int) report_scalar($pdo, 'SELECT COUNT(*) FROM inventory_items WHERE quantity <= reorder_point OR low_stock = 1'),
    'gross_profit' => (float) report_scalar(
        $pdo,
        'SELECT COALESCE(SUM(profit), 0)
         FROM inventory_transactions
         WHERE DATE(created_at) BETWEEN DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) + 7 DAY)
           AND DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) + 1 DAY)'
    ),
];

$topMovers = report_rows(
    $pdo,
    'SELECT brand, model, part_type, COALESCE(SUM(quantity), 0) AS units, COALESCE(SUM(total_revenue), 0) AS revenue
     FROM inventory_transactions
     WHERE DATE(created_at) BETWEEN DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) + 7 DAY)
       AND DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) + 1 DAY)
     GROUP BY brand, model, part_type
     ORDER BY units DESC, revenue DESC
     LIMIT 8'
);

$stockRisks = report_rows(
    $pdo,
    'SELECT brand, model, part_type, quantity, reorder_point
     FROM inventory_items
     WHERE quantity <= reorder_point OR low_stock = 1
     ORDER BY quantity ASC, updated_at ASC
     LIMIT 8'
);

$lines = [
    'Mobimend Weekly Performance Report',
    'Period: ' . $periodStart . ' to ' . $periodEnd,
    'Generated: ' . $generatedAt,
    '',
    'Revenue: ' . report_money($summary['revenue']),
    'Gross profit: ' . report_money($summary['gross_profit']),
    'Orders created: ' . number_format($summary['orders']),
    'Completed repairs: ' . number_format($summary['completed_repairs']),
    'Failed/review payments: ' . number_format($summary['failed_payments']),
    'Low-stock lines: ' . number_format($summary['low_stock']),
    '',
    'Top movers:',
];

if ($topMovers === []) {
    $lines[] = '- No stock movement recorded last week.';
}

foreach ($topMovers as $item) {
    $lines[] = sprintf(
        '- %s %s %s: %s units, %s revenue',
        (string) $item['brand'],
        (string) $item['model'],
        (string) $item['part_type'],
        number_format((int) $item['units']),
        report_money($item['revenue'])
    );
}

$lines[] = '';
$lines[] = 'Stock risks:';

if ($stockRisks === []) {
    $lines[] = '- No low-stock lines.';
}

foreach ($stockRisks as $item) {
    $lines[] = sprintf(
        '- %s %s %s: %s in stock, reorder at %s',
        (string) $item['brand'],
        (string) $item['model'],
        (string) $item['part_type'],
        number_format((int) $item['quantity']),
        number_format((int) $item['reorder_point'])
    );
}

$report = implode(PHP_EOL, $lines) . PHP_EOL;
$recipients = array_filter(array_map('trim', explode(',', (string) env('WEEKLY_REPORT_RECIPIENTS', ''))));

if ($recipients !== []) {
    $subject = 'Mobimend weekly performance report - ' . $periodStart . ' to ' . $periodEnd;
    $headers = 'From: ' . (string) env('SMTP_FROM_EMAIL', 'reports@mobimend.local');
    foreach ($recipients as $recipient) {
        mail($recipient, $subject, $report, $headers);
    }
}

echo $report;
