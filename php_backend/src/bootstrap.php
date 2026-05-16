<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Mobimend\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

require __DIR__ . '/Support/helpers.php';

\Mobimend\Config\Env::load(dirname(__DIR__) . '/.env');

set_exception_handler(static function (Throwable $exception): void {
    http_response_code(500);

    $debug = env('APP_DEBUG', 'false') === 'true';
    $message = $exception->getMessage() ?: 'Server error';

    if (PHP_SAPI !== 'cli') {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Mobimend Error</title>';
        echo '<style>body{font-family:Arial,sans-serif;background:#f8fafc;color:#111827;padding:32px}';
        echo '.card{max-width:900px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:24px;';
        echo 'box-shadow:0 10px 30px rgba(0,0,0,.06)}pre{white-space:pre-wrap;background:#f3f4f6;padding:16px;border-radius:12px;overflow:auto}</style>';
        echo '</head><body><div class="card"><h1>Mobimend PHP Error</h1>';
        echo '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';

        if ($debug) {
            echo '<pre>' . htmlspecialchars($exception->getTraceAsString(), ENT_QUOTES, 'UTF-8') . '</pre>';
        } else {
            echo '<p>Turn on APP_DEBUG in php_backend/.env to see more detail.</p>';
        }

        echo '</div></body></html>';
        exit;
    }

    fwrite(STDERR, $message . PHP_EOL);
    if ($debug) {
        fwrite(STDERR, $exception->getTraceAsString() . PHP_EOL);
    }
    exit(1);
});
