<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use Mobimend\Config\Database;
use Mobimend\Services\AnalyticsClient;

header('Content-Type: application/json; charset=utf-8');

$status = [
    'status' => 'ok',
    'service' => 'mobimend-php-api',
    'time' => date(DATE_ATOM),
    'checks' => [
        'php' => [
            'status' => 'ok',
            'version' => PHP_VERSION,
        ],
        'database' => [
            'status' => 'unknown',
        ],
        'analytics' => [
            'status' => 'unknown',
            'base_url' => (string) env('ANALYTICS_API_BASE', 'http://localhost:8001'),
        ],
    ],
];

try {
    Database::connection()->query('SELECT 1');
    $status['checks']['database']['status'] = 'ok';
} catch (Throwable $exception) {
    $status['status'] = 'degraded';
    $status['checks']['database'] = [
        'status' => 'error',
        'message' => $exception->getMessage(),
    ];
}

try {
    $analytics = (new AnalyticsClient())->get('/health');
    $status['checks']['analytics']['status'] = (bool) ($analytics['_available'] ?? false) ? 'ok' : 'offline';
    if (!empty($analytics['_cached'])) {
        $status['checks']['analytics']['cached'] = true;
    }
} catch (Throwable $exception) {
    $status['checks']['analytics'] += [
        'status' => 'offline',
        'message' => $exception->getMessage(),
    ];
}

http_response_code($status['status'] === 'ok' ? 200 : 503);
echo json_encode($status, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
