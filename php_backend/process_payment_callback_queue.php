<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use Mobimend\Config\Database;
use Mobimend\Services\MpesaCallbackProcessor;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$limit = max(1, (int) ($argv[1] ?? 25));
$pdo = Database::connection();

$jobs = $pdo->prepare(
    'SELECT *
     FROM payment_callback_queue
     WHERE status IN ("pending", "failed") AND available_at <= NOW()
     ORDER BY available_at ASC, id ASC
     LIMIT :limit'
);
$jobs->bindValue('limit', $limit, PDO::PARAM_INT);
$jobs->execute();

foreach ($jobs->fetchAll() as $job) {
    $jobId = (int) $job['id'];
    $attempts = (int) $job['attempts'] + 1;

    $pdo->prepare('UPDATE payment_callback_queue SET status = "processing", attempts = :attempts, updated_at = :updated_at WHERE id = :id')
        ->execute(['attempts' => $attempts, 'updated_at' => now(), 'id' => $jobId]);

    try {
        $payload = json_decode((string) ($job['payload'] ?: $job['raw_payload']), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new RuntimeException('Queued payload is not a JSON object.');
        }

        MpesaCallbackProcessor::process($pdo, $payload);

        $pdo->prepare(
            'UPDATE payment_callback_queue
             SET status = "completed", processed_at = :processed_at, updated_at = :updated_at, last_error = NULL
             WHERE id = :id'
        )->execute(['processed_at' => now(), 'updated_at' => now(), 'id' => $jobId]);

        echo 'Processed callback queue job #' . $jobId . PHP_EOL;
    } catch (Throwable $exception) {
        $nextDelay = min(900, 30 * (2 ** min($attempts, 5)));
        $availableAt = date('Y-m-d H:i:s', time() + $nextDelay);
        $status = $attempts >= 10 ? 'failed' : 'pending';

        $pdo->prepare(
            'UPDATE payment_callback_queue
             SET status = :status,
                 available_at = :available_at,
                 last_error = :last_error,
                 updated_at = :updated_at
             WHERE id = :id'
        )->execute([
            'status' => $status,
            'available_at' => $availableAt,
            'last_error' => substr($exception->getMessage(), 0, 1000),
            'updated_at' => now(),
            'id' => $jobId,
        ]);

        echo 'Queued callback job #' . $jobId . ' failed: ' . $exception->getMessage() . PHP_EOL;
    }
}
