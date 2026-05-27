<?php

declare(strict_types=1);

require __DIR__ . '/session_bootstrap.php';
session_start();

require dirname(__DIR__) . '/src/bootstrap.php';

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
$rememberCookie = 'mobimend_remember';
$cookie = (string) ($_COOKIE[$rememberCookie] ?? '');
[$selector] = array_pad(explode(':', $cookie, 2), 2, '');

if ($selector !== '') {
    $pdo = \Mobimend\Config\Database::connection();
    $stmt = $pdo->prepare('DELETE FROM remember_tokens WHERE selector = :selector');
    $stmt->execute(['selector' => $selector]);
}

setcookie($rememberCookie, '', [
    'expires' => time() - 3600,
    'path' => '/',
    'httponly' => true,
    'secure' => $isHttps,
    'samesite' => 'Lax',
]);

$_SESSION = [];
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

setcookie(session_name(), '', [
    'expires' => time() - 3600,
    'path' => '/',
    'httponly' => true,
    'secure' => $isHttps,
    'samesite' => 'Lax',
]);

header('Location: account.php?mode=login');
exit;
