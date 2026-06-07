<?php

declare(strict_types=1);

$sessionPath = dirname(__DIR__, 2) . '/storage/php-sessions';
if (!is_dir($sessionPath)) {
    mkdir($sessionPath, 0775, true);
}

if (is_dir($sessionPath) && is_writable($sessionPath)) {
    session_save_path($sessionPath);
}

// ── CSRF helpers ──────────────────────────────────────────────────────────────
// Call csrf_token() to get (or lazily create) the token for this session.
// Call csrf_field() to echo the hidden input inside any HTML form.
// Call csrf_verify() at the top of any POST handler — it throws on failure.

function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        // session_start() must be called before this, but guard anyway.
        throw new RuntimeException('Session must be started before calling csrf_token().');
    }

    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['_csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function csrf_verify(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $submitted = (string) ($_POST['_csrf_token'] ?? '');
    $expected  = csrf_token();

    // hash_equals prevents timing attacks.
    if ($submitted === '' || !hash_equals($expected, $submitted)) {
        http_response_code(419);
        // Rotate the token so the old one can't be retried.
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        exit('Request expired. Please go back and try again.');
    }
}
