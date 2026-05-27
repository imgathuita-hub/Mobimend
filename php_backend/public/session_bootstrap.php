<?php

declare(strict_types=1);

$sessionPath = dirname(__DIR__, 2) . '/storage/php-sessions';
if (!is_dir($sessionPath)) {
    mkdir($sessionPath, 0775, true);
}

if (is_dir($sessionPath) && is_writable($sessionPath)) {
    session_save_path($sessionPath);
}

