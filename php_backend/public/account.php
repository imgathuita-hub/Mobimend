<?php

declare(strict_types=1);

session_start();

require dirname(__DIR__) . '/src/bootstrap.php';

use Mobimend\Config\Database;

$pdo = Database::connection();
$adminRoles = ['admin', 'super_admin', 'technician'];
$mode = (string) ($_GET['mode'] ?? 'login');
$resetToken = (string) ($_GET['reset'] ?? '');
$fieldErrors = [];
$notice = '';
$noticeTone = 'info';
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
$rememberCookie = 'mobimend_remember';

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
}

function session_payload(array $user): array
{
    return [
        'id' => (int) $user['id'],
        'name' => (string) $user['name'],
        'email' => (string) $user['email'],
        'role' => (string) $user['role'],
    ];
}

function rotate_session_id_safely(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    if (@session_regenerate_id(true)) {
        return;
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_regenerate_id(false);
    }
}

function open_session_for(array $user, array $adminRoles): void
{
    rotate_session_id_safely();
    $payload = session_payload($user);

    if (in_array($payload['role'], $adminRoles, true)) {
        $_SESSION['admin_user'] = $payload;
        unset($_SESSION['account_user']);
        return;
    }

    $_SESSION['account_user'] = $payload;
    unset($_SESSION['admin_user']);
}

function password_score(string $password): int
{
    $score = 0;
    if (strlen($password) >= 8) {
        $score++;
    }
    if (preg_match('/[A-Z]/', $password)) {
        $score++;
    }
    if (preg_match('/[0-9]/', $password)) {
        $score++;
    }
    if (preg_match('/[^A-Za-z0-9]/', $password)) {
        $score++;
    }
    return $score;
}

function smtp_read($socket): string
{
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }
    return $response;
}

function smtp_command($socket, string $command, array $expectedCodes): string
{
    fwrite($socket, $command . "\r\n");
    $response = smtp_read($socket);
    $code = (int) substr($response, 0, 3);
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('SMTP command failed: ' . trim($response));
    }
    return $response;
}

function smtp_send_mail(string $to, string $subject, string $body): bool
{
    $host = (string) env('SMTP_HOST', '');
    $port = (int) env('SMTP_PORT', '587');
    $username = (string) env('SMTP_USERNAME', '');
    $password = (string) env('SMTP_PASSWORD', '');
    $fromEmail = (string) env('SMTP_FROM_EMAIL', $username);
    $fromName = trim((string) env('SMTP_FROM_NAME', 'Mobimend Spares'), '"\'');
    $encryption = strtolower((string) env('SMTP_ENCRYPTION', 'tls'));

    if ($host === '' || $username === '' || $password === '' || $fromEmail === '') {
        return false;
    }

    $remote = $encryption === 'ssl' ? 'ssl://' . $host . ':' . $port : 'tcp://' . $host . ':' . $port;
    $socket = stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
    if (!$socket) {
        throw new RuntimeException('SMTP connection failed: ' . $errstr);
    }

    smtp_read($socket);
    smtp_command($socket, 'EHLO localhost', [250]);

    if ($encryption === 'tls') {
        smtp_command($socket, 'STARTTLS', [220]);
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('Unable to enable SMTP TLS.');
        }
        smtp_command($socket, 'EHLO localhost', [250]);
    }

    smtp_command($socket, 'AUTH LOGIN', [334]);
    smtp_command($socket, base64_encode($username), [334]);
    smtp_command($socket, base64_encode($password), [235]);
    smtp_command($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
    smtp_command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
    smtp_command($socket, 'DATA', [354]);

    $headers = [
        'From: ' . $fromName . ' <' . $fromEmail . '>',
        'To: ' . $to,
        'Subject: ' . $subject,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
    ];
    $message = implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n.", "\n..", $body);
    smtp_command($socket, $message . "\r\n.", [250]);
    smtp_command($socket, 'QUIT', [221]);
    fclose($socket);

    return true;
}

function app_url(string $path): string
{
    $base = rtrim((string) env('APP_URL', ''), '/');
    if ($base !== '') {
        return $base . '/' . ltrim($path, '/');
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $dir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')));
    return $scheme . '://' . $host . rtrim($dir, '/') . '/' . ltrim($path, '/');
}

function remember_user(PDO $pdo, int $userId, string $cookieName, bool $secure): void
{
    $selector = bin2hex(random_bytes(9));
    $validator = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + (86400 * 30));

    $stmt = $pdo->prepare(
        'INSERT INTO remember_tokens (user_id, selector, validator_hash, expires_at, created_at)
         VALUES (:user_id, :selector, :validator_hash, :expires_at, :created_at)'
    );
    $stmt->execute([
        'user_id' => $userId,
        'selector' => $selector,
        'validator_hash' => hash('sha256', $validator),
        'expires_at' => $expires,
        'created_at' => now(),
    ]);

    setcookie($cookieName, $selector . ':' . $validator, [
        'expires' => time() + (86400 * 30),
        'path' => '/',
        'httponly' => true,
        'secure' => $secure,
        'samesite' => 'Lax',
    ]);
}

function clear_remember_cookie(PDO $pdo, string $cookieName, bool $secure): void
{
    $cookie = (string) ($_COOKIE[$cookieName] ?? '');
    [$selector] = array_pad(explode(':', $cookie, 2), 2, '');
    if ($selector !== '') {
        $stmt = $pdo->prepare('DELETE FROM remember_tokens WHERE selector = :selector');
        $stmt->execute(['selector' => $selector]);
    }

    setcookie($cookieName, '', [
        'expires' => time() - 3600,
        'path' => '/',
        'httponly' => true,
        'secure' => $secure,
        'samesite' => 'Lax',
    ]);
}

if (isset($_GET['logout'])) {
    clear_remember_cookie($pdo, $rememberCookie, $isHttps);
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
    session_write_close();
    header('Location: account.php?mode=login');
    exit;
}

function table_column_exists(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table
           AND COLUMN_NAME = :column'
    );
    $stmt->execute([
        'table' => $table,
        'column' => $column,
    ]);

    $cache[$key] = (int) $stmt->fetchColumn() > 0;
    return $cache[$key];
}

if (!isset($_SESSION['account_user'], $_SESSION['admin_user']) && isset($_COOKIE[$rememberCookie])) {
    [$selector, $validator] = array_pad(explode(':', (string) $_COOKIE[$rememberCookie], 2), 2, '');
    if ($selector !== '' && $validator !== '') {
        $stmt = $pdo->prepare(
            'SELECT users.id, users.name, users.email, users.role, remember_tokens.validator_hash
             FROM remember_tokens
             INNER JOIN users ON users.id = remember_tokens.user_id
             WHERE remember_tokens.selector = :selector AND remember_tokens.expires_at > :now
             LIMIT 1'
        );
        $stmt->execute(['selector' => $selector, 'now' => now()]);
        $remembered = $stmt->fetch();

        if ($remembered && hash_equals((string) $remembered['validator_hash'], hash('sha256', $validator))) {
            open_session_for($remembered, $adminRoles);
        }
    }
}

$accountUser = $_SESSION['account_user'] ?? null;
$adminUser = $_SESSION['admin_user'] ?? null;

$sessionUser = $adminUser ?: $accountUser;
if (is_array($sessionUser) && isset($sessionUser['id'])) {
    $sessionStmt = $pdo->prepare('SELECT id, name, email, role FROM users WHERE id = :id LIMIT 1');
    $sessionStmt->execute(['id' => (int) $sessionUser['id']]);
    $freshUser = $sessionStmt->fetch();

    if ($freshUser) {
        open_session_for($freshUser, $adminRoles);
        $accountUser = $_SESSION['account_user'] ?? null;
        $adminUser = $_SESSION['admin_user'] ?? null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'login');
    $mode = $action;

    if ($action === 'signup') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        if ($name === '') {
            $fieldErrors['signup_name'] = 'Full name is required.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $fieldErrors['signup_email'] = 'Enter a valid email address.';
        }
        if ($phone === '') {
            $fieldErrors['signup_phone'] = 'Phone number is required.';
        }
        if (password_score($password) < 2) {
            $fieldErrors['signup_password'] = 'Use at least 8 characters with a number or uppercase letter.';
        }
        if ($password !== $confirm) {
            $fieldErrors['signup_confirm'] = 'Passwords do not match.';
        }

        if ($fieldErrors === []) {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $stmt->execute(['email' => $email]);
            if ($stmt->fetch()) {
                $fieldErrors['signup_email'] = 'This email is already registered. Login instead.';
            }
        }

        if ($fieldErrors === []) {
            $stmt = $pdo->prepare(
                'INSERT INTO users (name, email, phone, password_hash, role, account_status, created_at, updated_at)
                 VALUES (:name, :email, :phone, :password_hash, :role, :account_status, :created_at, :updated_at)'
            );
            $stmt->execute([
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => 'customer',
                'account_status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            open_session_for([
                'id' => (int) $pdo->lastInsertId(),
                'name' => $name,
                'email' => $email,
                'role' => 'customer',
            ], $adminRoles);
            $accountUser = $_SESSION['account_user'];
            $notice = 'Welcome to Mobimend, ' . $name . '.';
            $noticeTone = 'success';
        }
    }

    if ($action === 'login') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $remember = isset($_POST['remember']);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $fieldErrors['login_email'] = 'Enter a valid email address.';
        }
        if ($password === '') {
            $fieldErrors['login_password'] = 'Password is required.';
        }

        $attemptStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE ip_address = :ip_address AND email = :email AND attempted_at >= :window_start'
        );
        $attemptStmt->execute([
            'ip_address' => client_ip(),
            'email' => $email,
            'window_start' => date('Y-m-d H:i:s', time() - 600),
        ]);

        if ((int) $attemptStmt->fetchColumn() >= 5) {
            $fieldErrors['login_password'] = 'Too many attempts. Try again in 10 minutes.';
        }

        if ($fieldErrors === []) {
            $stmt = $pdo->prepare('SELECT id, name, email, password_hash, role FROM users WHERE email = :email LIMIT 1');
            $stmt->execute(['email' => $email]);
            $foundUser = $stmt->fetch();

            if (!$foundUser || !password_verify($password, (string) $foundUser['password_hash'])) {
                $insertAttempt = $pdo->prepare(
                    'INSERT INTO login_attempts (email, ip_address, attempted_at)
                     VALUES (:email, :ip_address, :attempted_at)'
                );
                $insertAttempt->execute([
                    'email' => $email,
                    'ip_address' => client_ip(),
                    'attempted_at' => now(),
                ]);
                $fieldErrors['login_password'] = 'Invalid email or password.';
            } else {
                $pdo->prepare('DELETE FROM login_attempts WHERE email = :email AND ip_address = :ip_address')
                    ->execute(['email' => $email, 'ip_address' => client_ip()]);
                open_session_for($foundUser, $adminRoles);
                if ($remember) {
                    remember_user($pdo, (int) $foundUser['id'], $rememberCookie, $isHttps);
                }
                $accountUser = $_SESSION['account_user'] ?? null;
                $adminUser = $_SESSION['admin_user'] ?? null;
                $notice = in_array((string) $foundUser['role'], $adminRoles, true)
                    ? 'Admin workspace opened for ' . $foundUser['name'] . '.'
                    : 'Welcome back, ' . $foundUser['name'] . '.';
                $noticeTone = 'success';
            }
        }
    }

    if ($action === 'forgot') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $fieldErrors['forgot_email'] = 'Enter a valid email address.';
        }

        if ($fieldErrors === []) {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $stmt->execute(['email' => $email]);
            $foundUser = $stmt->fetch();
            $notice = 'If that email exists, a reset link has been sent.';
            $noticeTone = 'success';

            if ($foundUser) {
                $token = bin2hex(random_bytes(32));
                $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = :user_id')->execute(['user_id' => (int) $foundUser['id']]);
                $insert = $pdo->prepare(
                    'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, created_at)
                     VALUES (:user_id, :token_hash, :expires_at, :created_at)'
                );
                $insert->execute([
                    'user_id' => (int) $foundUser['id'],
                    'token_hash' => hash('sha256', $token),
                    'expires_at' => date('Y-m-d H:i:s', time() + 3600),
                    'created_at' => now(),
                ]);
                $resetLink = app_url('account.php?reset=' . urlencode($token));
                $emailBody = "Hello,\n\nWe received a request to reset your Mobimend Spares password.\n\nUse this link within one hour:\n" . $resetLink . "\n\nIf you did not request this, you can ignore this email.\n\nMobimend Spares";

                try {
                    if (!smtp_send_mail($email, 'Reset your Mobimend password', $emailBody)) {
                        $notice = 'SMTP is not configured yet. Add SMTP settings in php_backend/.env to email reset links.';
                        $noticeTone = 'error';
                    }
                } catch (Throwable $exception) {
                    $notice = 'Reset email could not be sent. Check SMTP settings.';
                    $noticeTone = 'error';
                }
            }
        }
    }

    if ($action === 'reset') {
        $token = (string) ($_POST['token'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        if (password_score($password) < 2) {
            $fieldErrors['reset_password'] = 'Use at least 8 characters with a number or uppercase letter.';
        }
        if ($password !== $confirm) {
            $fieldErrors['reset_confirm'] = 'Passwords do not match.';
        }

        if ($fieldErrors === []) {
            $stmt = $pdo->prepare(
                'SELECT user_id FROM password_reset_tokens
                 WHERE token_hash = :token_hash AND expires_at > :now
                 LIMIT 1'
            );
            $stmt->execute(['token_hash' => hash('sha256', $token), 'now' => now()]);
            $resetRow = $stmt->fetch();

            if (!$resetRow) {
                $fieldErrors['reset_password'] = 'Reset link is invalid or expired.';
            } else {
                $pdo->prepare('UPDATE users SET password_hash = :password_hash, updated_at = :updated_at WHERE id = :id')
                    ->execute([
                        'id' => (int) $resetRow['user_id'],
                        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                        'updated_at' => now(),
                    ]);
                $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = :user_id')
                    ->execute(['user_id' => (int) $resetRow['user_id']]);
                $mode = 'login';
                $notice = 'Password updated. You can login now.';
                $noticeTone = 'success';
            }
        }
    }
}

$customerRepairs = [];
$customerOrders = [];
$customerPayments = [];
$adminStats = [
    'inventory_items' => 0,
    'low_stock' => 0,
    'pending_repairs' => 0,
    'orders_today' => 0,
    'pending_payments' => 0,
    'customers' => 0,
];

if ($accountUser) {
    $repairsStmt = $pdo->prepare(
        'SELECT id, device_model, repair_type, status, booking_date
         FROM repair_bookings
         WHERE user_id = :user_id OR email = :email
         ORDER BY created_at DESC
         LIMIT 5'
    );
    $repairsStmt->execute(['user_id' => (int) $accountUser['id'], 'email' => (string) $accountUser['email']]);
    $customerRepairs = $repairsStmt->fetchAll();

    $ordersStmt = $pdo->prepare(
        'SELECT id, order_number, order_type, status, payment_status, grand_total, created_at
         FROM orders
         WHERE user_id = :user_id OR customer_email = :email
         ORDER BY created_at DESC
         LIMIT 5'
    );
    $ordersStmt->execute(['user_id' => (int) $accountUser['id'], 'email' => (string) $accountUser['email']]);
    $customerOrders = $ordersStmt->fetchAll();

    $paymentsStmt = $pdo->prepare(
        'SELECT id, payment_method, amount, currency, status, mpesa_receipt_number, created_at
         FROM payments
         WHERE user_id = :user_id
         ORDER BY created_at DESC
         LIMIT 5'
    );
    $paymentsStmt->execute(['user_id' => (int) $accountUser['id']]);
    $customerPayments = $paymentsStmt->fetchAll();
}

if ($adminUser) {
    $adminStats['inventory_items'] = (int) $pdo->query('SELECT COUNT(*) FROM inventory_items')->fetchColumn();
    if (table_column_exists($pdo, 'inventory_items', 'low_stock')) {
        $lowStockSql = 'SELECT COUNT(*) FROM inventory_items WHERE low_stock = 1';
    } elseif (table_column_exists($pdo, 'inventory_items', 'reorder_point')) {
        $lowStockSql = 'SELECT COUNT(*) FROM inventory_items WHERE quantity <= reorder_point';
    } else {
        $lowStockSql = 'SELECT COUNT(*) FROM inventory_items WHERE quantity <= low_stock_threshold';
    }
    $adminStats['low_stock'] = (int) $pdo->query($lowStockSql)->fetchColumn();
    $adminStats['pending_repairs'] = (int) $pdo->query("SELECT COUNT(*) FROM repair_bookings WHERE status IN ('Pending', 'In Progress')")->fetchColumn();
    $adminStats['orders_today'] = (int) $pdo->query('SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURRENT_DATE')->fetchColumn();
    $adminStats['pending_payments'] = (int) $pdo->query("SELECT COUNT(*) FROM payments WHERE status IN ('pending', 'processing', 'requires_review')")->fetchColumn();
    $adminStats['customers'] = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('customer', 'wholesale_customer')")->fetchColumn();
}

$showAuth = !$accountUser && !$adminUser;
$resetToken = $resetToken !== '' ? $resetToken : (string) ($_POST['token'] ?? '');
$mode = $resetToken !== '' && $showAuth ? 'reset' : $mode;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Account | Mobimend Spares</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&family=Montserrat:wght@700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="style.css">
</head>
<body class="account-body <?= $showAuth ? 'auth-mode' : 'dashboard-mode' ?>">
  <?php if ($showAuth): ?>
    <nav class="site-nav account-auth-nav">
      <a class="nav-left" href="index.php">
        <img src="assets/LOGO FINAL MOBIMEND WH BG.png" alt="Mobimend Spares" class="logo">
        <div class="brand"><h1>MOBIMEND</h1><p class="tagline">Secure portal</p></div>
      </a>
      <button class="menu-toggle" id="menu-toggle" aria-expanded="false" aria-label="Toggle navigation"><i class="fa-solid fa-bars"></i></button>
      <ul class="nav-links" id="nav-links">
        <li><a href="index.php">Home</a></li>
        <li><a href="repair.php">Repair</a></li>
        <li><a href="accessories.php">Shop</a></li>
        <li><a href="wholesale.php">Wholesale</a></li>
        <li><a href="blog.php">Blog</a></li>
        <li><a href="track.php">Track</a></li>
        <li><a class="active" href="account.php">Account</a></li>
      </ul>
    </nav>

    <main class="account-login-shell portal-login-shell">
      <section class="portal-auth-stage">
        <div class="portal-stage-copy">
          <p class="section-kicker"><i class="fa-solid fa-shield-halved"></i> One secure Mobimend ID</p>
          <h1>Repair tracking, shopping, wholesale, and operations in one account.</h1>
          <p>Customers get a clear service hub. Admins and technicians get a guarded route into the command workspace after their role is verified.</p>
        </div>

        <div class="portal-device-card" aria-label="Mobimend account workspace preview">
          <div class="portal-device-top">
            <span>Mobimend OS</span>
            <strong data-portal-mode-label><?= $mode === 'signup' ? 'Customer onboarding' : 'Secure sign in' ?></strong>
          </div>
          <div class="portal-orbit">
            <span><i class="fa-solid fa-screwdriver-wrench"></i></span>
            <span><i class="fa-solid fa-bag-shopping"></i></span>
            <span><i class="fa-solid fa-boxes-stacked"></i></span>
          </div>
          <div class="portal-flow-card active" data-flow-card="customer">
            <small>Customer journey</small>
            <strong>Book, pay, track, and reorder</strong>
            <div class="portal-progress"><span style="width: 76%"></span></div>
          </div>
          <div class="portal-flow-card" data-flow-card="admin">
            <small>Admin operations</small>
            <strong>Repairs, inventory, orders, payments</strong>
            <div class="portal-progress"><span style="width: 88%"></span></div>
          </div>
          <div class="portal-mini-grid">
            <div><strong>90 days</strong><span>Repair warranty</span></div>
            <div><strong>M-Pesa</strong><span>Receipt trail</span></div>
            <div><strong>Live</strong><span>Status updates</span></div>
          </div>
        </div>

        <div class="portal-business-lanes" aria-label="Mobimend business model">
          <article>
            <i class="fa-solid fa-mobile-screen-button"></i>
            <span>Repair CRM</span>
            <strong>Diagnostics, bookings, repair status, warranty history.</strong>
          </article>
          <article>
            <i class="fa-solid fa-cart-shopping"></i>
            <span>Commerce</span>
            <strong>Accessories, repeat purchases, and saved customer records.</strong>
          </article>
          <article>
            <i class="fa-solid fa-warehouse"></i>
            <span>Wholesale</span>
            <strong>Parts availability, reseller accounts, and inventory velocity.</strong>
          </article>
          <article>
            <i class="fa-solid fa-user-gear"></i>
            <span>Admin OS</span>
            <strong>Technician queues, stock alerts, payments, and customer care.</strong>
          </article>
        </div>
      </section>

      <section class="login-panel portal-login-panel">
        <div class="login-card portal-login-card">
          <div class="portal-card-header">
            <span><i class="fa-solid fa-lock"></i></span>
            <div>
              <strong>Account portal</strong>
              <p>Customer and admin access</p>
            </div>
          </div>

          <div class="portal-intent-tabs" role="tablist" aria-label="Choose account type">
            <button class="active" type="button" data-login-intent="customer"><i class="fa-solid fa-user"></i> Customer</button>
            <button type="button" data-login-intent="admin"><i class="fa-solid fa-user-shield"></i> Admin</button>
          </div>

          <?php if ($notice !== ''): ?>
            <div class="auth-notice <?= htmlspecialchars($noticeTone) ?>"><?= htmlspecialchars($notice) ?></div>
          <?php endif; ?>

          <form method="post" class="auth-panel <?= $mode === 'login' ? 'active' : '' ?>" data-panel="login">
            <input type="hidden" name="action" value="login">
            <h1 data-login-title>Welcome back</h1>
            <p data-login-copy>Sign in to track repairs, orders, payments, warranties, and saved service history.</p>

            <label>Email</label>
            <input name="email" type="email" placeholder="you@example.com" value="<?= htmlspecialchars((string) ($_POST['email'] ?? '')) ?>" data-login-email required>
            <div class="field-error"><?= htmlspecialchars($fieldErrors['login_email'] ?? '') ?></div>

            <label>Password</label>
            <div class="password-field">
              <input name="password" type="password" placeholder="********" required>
              <button type="button" data-toggle-password>Show</button>
            </div>
            <div class="field-error"><?= htmlspecialchars($fieldErrors['login_password'] ?? '') ?></div>

            <div class="login-options-row">
              <label class="remember-row"><input name="remember" type="checkbox"> Remember me</label>
              <a href="#" data-auth-panel="forgot">Forgot password</a>
            </div>

            <button class="btn-primary login-submit" type="submit"><i class="fa-solid fa-arrow-right-to-bracket"></i> Continue securely</button>
            <div class="portal-login-note" data-login-note><i class="fa-solid fa-circle-info"></i> Admin accounts are routed automatically by role after login.</div>
            <p class="login-signup-copy" data-customer-signup-copy>New customer? <a href="#" data-auth-panel="signup">Create your Mobimend ID</a></p>
          </form>

          <form method="post" class="auth-panel <?= $mode === 'signup' ? 'active' : '' ?>" data-panel="signup">
            <input type="hidden" name="action" value="signup">
            <h1>Create customer ID</h1>
            <p>Save repair history, receipts, warranty records, and faster checkout details.</p>
            <label>Full name</label>
            <input name="name" placeholder="Jane Customer" value="<?= htmlspecialchars((string) ($_POST['name'] ?? '')) ?>" required>
            <div class="field-error"><?= htmlspecialchars($fieldErrors['signup_name'] ?? '') ?></div>

            <label>Email</label>
            <input name="email" type="email" placeholder="you@example.com" value="<?= htmlspecialchars((string) ($_POST['email'] ?? '')) ?>" required>
            <div class="field-error"><?= htmlspecialchars($fieldErrors['signup_email'] ?? '') ?></div>

            <label>Phone number</label>
            <input name="phone" placeholder="07XX XXX XXX" value="<?= htmlspecialchars((string) ($_POST['phone'] ?? '')) ?>" required>
            <div class="field-error"><?= htmlspecialchars($fieldErrors['signup_phone'] ?? '') ?></div>

            <label>Password</label>
            <div class="password-field">
              <input name="password" type="password" data-strength-source required>
              <button type="button" data-toggle-password>Show</button>
            </div>
            <div class="password-strength" data-strength-meter><span></span></div>
            <div class="field-error"><?= htmlspecialchars($fieldErrors['signup_password'] ?? '') ?></div>

            <label>Confirm password</label>
            <div class="password-field">
              <input name="confirm_password" type="password" required>
              <button type="button" data-toggle-password>Show</button>
            </div>
            <div class="field-error"><?= htmlspecialchars($fieldErrors['signup_confirm'] ?? '') ?></div>

            <button class="btn-primary login-submit" type="submit"><i class="fa-solid fa-user-plus"></i> Create account</button>
            <p class="login-signup-copy"><a href="#" data-auth-panel="login">Already have an account? Login</a></p>
          </form>

          <form method="post" class="auth-panel <?= $mode === 'forgot' ? 'active' : '' ?>" data-panel="forgot">
            <input type="hidden" name="action" value="forgot">
            <h1>Reset password</h1>
            <p>Enter your email and we will prepare a one-hour reset link.</p>
            <label>Email</label>
            <input name="email" type="email" placeholder="you@example.com" required>
            <div class="field-error"><?= htmlspecialchars($fieldErrors['forgot_email'] ?? '') ?></div>
            <button class="btn-primary login-submit" type="submit"><i class="fa-solid fa-paper-plane"></i> Send reset link</button>
            <p class="login-signup-copy"><a href="#" data-auth-panel="login">Back to login</a></p>
          </form>

          <form method="post" class="auth-panel <?= $mode === 'reset' ? 'active' : '' ?>" data-panel="reset">
            <input type="hidden" name="action" value="reset">
            <input type="hidden" name="token" value="<?= htmlspecialchars($resetToken) ?>">
            <h1>Choose new password</h1>
            <label>New password</label>
            <div class="password-field">
              <input name="password" type="password" data-strength-source required>
              <button type="button" data-toggle-password>Show</button>
            </div>
            <div class="password-strength" data-strength-meter><span></span></div>
            <div class="field-error"><?= htmlspecialchars($fieldErrors['reset_password'] ?? '') ?></div>

            <label>Confirm new password</label>
            <div class="password-field">
              <input name="confirm_password" type="password" required>
              <button type="button" data-toggle-password>Show</button>
            </div>
            <div class="field-error"><?= htmlspecialchars($fieldErrors['reset_confirm'] ?? '') ?></div>
            <button class="btn-primary login-submit" type="submit"><i class="fa-solid fa-key"></i> Update password</button>
          </form>

          <div class="portal-security-strip">
            <span><i class="fa-solid fa-shield"></i> Rate limited</span>
            <span><i class="fa-solid fa-cookie-bite"></i> Remember tokens</span>
            <span><i class="fa-solid fa-user-lock"></i> Role routed</span>
          </div>
        </div>
      </section>
    </main>
  <?php else: ?>
    <nav class="site-nav">
      <a class="nav-left" href="index.php">
        <img src="assets/LOGO FINAL MOBIMEND WH BG.png" alt="Mobimend" class="logo">
        <div class="brand"><h1>MOBIMEND</h1><p class="tagline">Secure portal</p></div>
      </a>
      <button class="menu-toggle" id="menu-toggle" aria-expanded="false" aria-label="Toggle navigation"><i class="fa-solid fa-bars"></i></button>
      <ul class="nav-links" id="nav-links">
        <li><a href="index.php">Home</a></li>
        <li><a href="repair.php">Repair</a></li>
        <li><a href="accessories.php">Shop</a></li>
        <li><a href="wholesale.php">Wholesale</a></li>
        <li><a href="blog.php">Blog</a></li>
        <li><a href="track.php">Track</a></li>
        <li><a class="active" href="account.php">Account</a></li>
        <li><a class="nav-btn logout-nav-btn" href="account.php?logout=1"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a></li>
      </ul>
    </nav>

    <main class="account-dashboard section alt">
      <div class="section-inner">
        <?php if ($notice !== ''): ?>
          <div class="php-banner <?= htmlspecialchars($noticeTone) ?>"><?= htmlspecialchars($notice) ?></div>
        <?php endif; ?>

        <?php if ($accountUser): ?>
          <section class="account-hero">
            <div>
              <p class="section-kicker"><i class="fa-solid fa-user"></i> Customer dashboard</p>
              <h1 class="section-title">Welcome, <?= htmlspecialchars((string) $accountUser['name']) ?>.</h1>
              <p class="section-copy">Track repair orders, confirm payments, browse products, and review order history.</p>
              <div class="account-hero-metrics" aria-label="Account summary">
                <div><strong><?= count($customerRepairs) ?></strong><span>Repairs</span></div>
                <div><strong><?= count($customerOrders) ?></strong><span>Orders</span></div>
                <div><strong><?= count($customerPayments) ?></strong><span>Payments</span></div>
              </div>
              <div class="account-actions">
                <a class="btn-primary" href="repair.php"><i class="fa-solid fa-screwdriver-wrench"></i> Book repair</a>
                <a class="btn-light" href="accessories.php"><i class="fa-solid fa-bag-shopping"></i> Shop accessories</a>
                <a class="btn-light" href="track.php"><i class="fa-solid fa-location-dot"></i> Track status</a>
              </div>
            </div>
            <div class="account-identity-card">
              <span class="account-avatar"><?= htmlspecialchars(strtoupper(substr((string) $accountUser['name'], 0, 1))) ?></span>
              <div>
                <strong><?= htmlspecialchars((string) $accountUser['email']) ?></strong>
                <p>Customer portal</p>
              </div>
            </div>
          </section>

          <div class="account-grid">
            <article class="dashboard-card wide quick-actions-card">
              <div class="account-action-grid">
                <a href="track.php"><i class="fa-solid fa-location-dot"></i><span>Track repairs</span></a>
                <a href="accessories.php"><i class="fa-solid fa-bag-shopping"></i><span>Browse products</span></a>
                <a href="#payments"><i class="fa-solid fa-credit-card"></i><span>View payments</span></a>
                <a href="#settings"><i class="fa-solid fa-user-gear"></i><span>Account settings</span></a>
              </div>
            </article>

            <article class="dashboard-card">
              <div class="dashboard-card-heading"><span><i class="fa-solid fa-screwdriver-wrench"></i></span><h3>Active repairs</h3></div>
              <?php if ($customerRepairs === []): ?>
                <p>No repair bookings linked to this account yet.</p>
                <a class="btn-ghost" href="repair.php">Book a repair</a>
              <?php else: ?>
                <div class="account-list">
                <?php foreach ($customerRepairs as $repair): ?>
                  <p><strong>#<?= (int) $repair['id'] ?> <?= htmlspecialchars((string) $repair['device_model']) ?></strong><br><?= htmlspecialchars((string) $repair['repair_type']) ?> - <?= htmlspecialchars((string) $repair['status']) ?></p>
                <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </article>

            <article class="dashboard-card">
              <div class="dashboard-card-heading"><span><i class="fa-solid fa-bag-shopping"></i></span><h3>Recent purchases</h3></div>
              <?php if ($customerOrders === []): ?>
                <p>No purchases yet.</p>
                <a class="btn-ghost" href="accessories.php">Start shopping</a>
              <?php else: ?>
                <div class="account-list">
                <?php foreach ($customerOrders as $order): ?>
                  <p><strong><?= htmlspecialchars((string) $order['order_number']) ?></strong><br><?= htmlspecialchars((string) $order['status']) ?> - KES <?= number_format((float) $order['grand_total'], 2) ?></p>
                <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </article>

            <article class="dashboard-card" id="payments">
              <div class="dashboard-card-heading"><span><i class="fa-solid fa-credit-card"></i></span><h3>Payments</h3></div>
              <?php if ($customerPayments === []): ?>
                <p>No pending payments. M-Pesa and card receipts will appear here.</p>
              <?php else: ?>
                <div class="account-list">
                <?php foreach ($customerPayments as $payment): ?>
                  <p><strong><?= htmlspecialchars((string) $payment['payment_method']) ?></strong><br><?= htmlspecialchars((string) $payment['status']) ?> - <?= htmlspecialchars((string) $payment['currency']) ?> <?= number_format((float) $payment['amount'], 2) ?></p>
                <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </article>

            <article class="dashboard-card" id="settings">
              <div class="dashboard-card-heading"><span><i class="fa-solid fa-user-gear"></i></span><h3>Account settings</h3></div>
              <p>Name, phone number, saved addresses, and password changes will live here next.</p>
            </article>
          </div>
        <?php endif; ?>

        <?php if ($adminUser): ?>
          <section class="account-hero admin-hero">
            <div>
              <p class="section-kicker"><i class="fa-solid fa-user-gear"></i> Admin dashboard</p>
              <h1 class="section-title">Welcome, <?= htmlspecialchars((string) $adminUser['name']) ?>.</h1>
              <p class="section-copy">Manage repairs, inventory, orders, payments, customers, and role permissions.</p>
              <div class="account-hero-metrics" aria-label="Admin summary">
                <div><strong><?= number_format($adminStats['pending_repairs']) ?></strong><span>Repairs</span></div>
                <div><strong><?= number_format($adminStats['orders_today']) ?></strong><span>Orders today</span></div>
                <div><strong><?= number_format($adminStats['low_stock']) ?></strong><span>Low stock</span></div>
              </div>
              <div class="account-actions">
                <a class="btn-primary" href="admin_repairs.php"><i class="fa-solid fa-screwdriver-wrench"></i> Repairs</a>
                <a class="btn-light" href="admin_inventory.php"><i class="fa-solid fa-boxes-stacked"></i> Inventory</a>
              </div>
            </div>
            <div class="account-identity-card">
              <span class="account-avatar"><?= htmlspecialchars(strtoupper(substr((string) $adminUser['name'], 0, 1))) ?></span>
              <div>
                <strong><?= htmlspecialchars((string) $adminUser['email']) ?></strong>
                <p><?= htmlspecialchars((string) $adminUser['role']) ?></p>
              </div>
            </div>
          </section>

          <div class="account-grid">
            <article class="dashboard-card stat-card"><span><i class="fa-solid fa-screwdriver-wrench"></i></span><h3>Repairs in progress</h3><p><strong><?= number_format($adminStats['pending_repairs']) ?></strong> active repairs</p><a class="btn-dark" href="admin_repairs.php">Manage repairs</a></article>
            <article class="dashboard-card stat-card"><span><i class="fa-solid fa-receipt"></i></span><h3>Orders today</h3><p><strong><?= number_format($adminStats['orders_today']) ?></strong> orders recorded today</p></article>
            <article class="dashboard-card stat-card"><span><i class="fa-solid fa-credit-card"></i></span><h3>Payments pending</h3><p><strong><?= number_format($adminStats['pending_payments']) ?></strong> payments need confirmation</p></article>
            <article class="dashboard-card stat-card"><span><i class="fa-solid fa-triangle-exclamation"></i></span><h3>Low stock alerts</h3><p><strong><?= number_format($adminStats['low_stock']) ?></strong> inventory items are low</p><a class="btn-dark" href="admin_inventory.php">Manage inventory</a></article>
            <article class="dashboard-card wide">
              <h3>Admin navigation</h3>
              <div class="category-pills">
                <a class="pill active" href="admin_repairs.php">Repairs</a>
                <a class="pill" href="admin_inventory.php">Inventory</a>
                <span class="pill">Payments</span>
                <span class="pill">Orders</span>
                <?php if ((string) $adminUser['role'] === 'super_admin'): ?>
                  <span class="pill">User management</span>
                <?php endif; ?>
              </div>
            </article>
          </div>
        <?php endif; ?>
      </div>
    </main>
  <?php endif; ?>

  <script src="chatbot.js"></script>
  <script src="site.js"></script>
  <script>
    document.querySelectorAll('[data-auth-panel]').forEach((trigger) => {
      trigger.addEventListener('click', (event) => {
        event.preventDefault();
        const target = trigger.dataset.authPanel;
        document.querySelectorAll('[data-panel]').forEach((panel) => {
          panel.classList.toggle('active', panel.dataset.panel === target);
        });
        document.querySelectorAll('.auth-tabs [data-auth-panel]').forEach((tab) => {
          tab.classList.toggle('active', tab.dataset.authPanel === target);
        });
      });
    });

    const intentContent = {
      customer: {
        title: 'Welcome back',
        copy: 'Sign in to track repairs, orders, payments, warranties, and saved service history.',
        note: 'Admin accounts are routed automatically by role after login.',
        email: 'you@example.com',
        mode: 'Customer service hub',
      },
      admin: {
        title: 'Open admin workspace',
        copy: 'Use your staff email to review repair queues, inventory alerts, orders, and payments.',
        note: 'Only staff roles can enter admin dashboards. Customer accounts remain in the customer portal.',
        email: 'admin@mobimend.co.ke',
        mode: 'Operations command',
      },
    };

    function setLoginIntent(intent) {
      const content = intentContent[intent] || intentContent.customer;
      document.querySelectorAll('[data-login-intent]').forEach((button) => {
        button.classList.toggle('active', button.dataset.loginIntent === intent);
      });
      document.querySelectorAll('[data-flow-card]').forEach((card) => {
        card.classList.toggle('active', card.dataset.flowCard === intent);
      });
      const title = document.querySelector('[data-login-title]');
      const copy = document.querySelector('[data-login-copy]');
      const note = document.querySelector('[data-login-note]');
      const email = document.querySelector('[data-login-email]');
      const modeLabel = document.querySelector('[data-portal-mode-label]');
      const signup = document.querySelector('[data-customer-signup-copy]');
      if (title) title.textContent = content.title;
      if (copy) copy.textContent = content.copy;
      if (note) note.innerHTML = `<i class="fa-solid fa-circle-info"></i> ${content.note}`;
      if (email) email.placeholder = content.email;
      if (modeLabel) modeLabel.textContent = content.mode;
      if (signup) signup.hidden = intent === 'admin';
    }

    document.querySelectorAll('[data-login-intent]').forEach((button) => {
      button.addEventListener('click', () => {
        setLoginIntent(button.dataset.loginIntent || 'customer');
        document.querySelectorAll('[data-panel]').forEach((panel) => {
          panel.classList.toggle('active', panel.dataset.panel === 'login');
        });
      });
    });

    document.querySelectorAll('[data-toggle-password]').forEach((button) => {
      button.addEventListener('click', () => {
        const input = button.previousElementSibling;
        const showing = input.type === 'text';
        input.type = showing ? 'password' : 'text';
        button.textContent = showing ? 'Show' : 'Hide';
      });
    });

    document.querySelectorAll('[data-strength-source]').forEach((input) => {
      input.addEventListener('input', () => {
        const meter = input.closest('form').querySelector('[data-strength-meter] span');
        if (!meter) return;
        let score = 0;
        if (input.value.length >= 8) score += 1;
        if (/[A-Z]/.test(input.value)) score += 1;
        if (/[0-9]/.test(input.value)) score += 1;
        if (/[^A-Za-z0-9]/.test(input.value)) score += 1;
        meter.style.width = `${Math.max(score, 1) * 25}%`;
        meter.dataset.score = String(score);
      });
    });
  </script>
</body>
</html>
