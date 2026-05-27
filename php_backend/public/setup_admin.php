<?php

declare(strict_types=1);

require __DIR__ . '/session_bootstrap.php';
session_start();

require dirname(__DIR__) . '/src/bootstrap.php';

use Mobimend\Config\Database;

$pdo = Database::connection();
$message = '';
$tone = 'info';

$count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $count === 0) {
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
    $setupToken = trim((string) ($_POST['setup_token'] ?? ''));

    if ($setupToken !== (string) env('ADMIN_SETUP_TOKEN', '')) {
        $message = 'Setup token is invalid.';
        $tone = 'error';
    } elseif ($name === '' || $email === '' || $password === '') {
        $message = 'Name, email, and password are required.';
        $tone = 'error';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Email format is invalid.';
        $tone = 'error';
    } elseif ($password !== $confirmPassword) {
        $message = 'Passwords do not match.';
        $tone = 'error';
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO users (name, email, password_hash, role, created_at, updated_at)
             VALUES (:name, :email, :password_hash, :role, :created_at, :updated_at)'
        );
        $timestamp = now();
        $stmt->execute([
            'name' => $name,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => 'admin',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $_SESSION['admin_user'] = [
            'id' => (int) $pdo->lastInsertId(),
            'name' => $name,
            'email' => $email,
            'role' => 'admin',
        ];

        header('Location: admin_dashboard.php');
        exit;
    }
}

$count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Mobimend Setup Admin</title>
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@600;700&family=Roboto:wght@400;500&display=swap" rel="stylesheet">
  <style>
    body {
      margin: 0;
      min-height: 100vh;
      display: grid;
      place-items: center;
      background: linear-gradient(180deg, #eef4ff 0%, #f7f9fc 48%, #ffffff 100%);
      font-family: 'Roboto', sans-serif;
      color: #1f2933;
      padding: 20px;
    }
    .card {
      width: min(520px, 100%);
      background: #fff;
      border-radius: 18px;
      padding: 28px;
      box-shadow: 0 18px 48px rgba(15, 23, 42, 0.08);
      border: 1px solid rgba(148, 163, 184, 0.18);
    }
    h1 {
      margin: 0 0 10px;
      font-family: 'Montserrat', sans-serif;
      font-size: 1.7rem;
      color: #0f172a;
    }
    p {
      margin: 0 0 18px;
      color: #556070;
      line-height: 1.5;
    }
    .stack {
      display: grid;
      gap: 12px;
    }
    input {
      width: 100%;
      box-sizing: border-box;
      padding: 12px 13px;
      border-radius: 12px;
      border: 1px solid #d7dbe3;
      background: #fbfdff;
      font: inherit;
    }
    button {
      background: #1766c5;
      color: #fff;
      border: none;
      padding: 12px 16px;
      border-radius: 12px;
      cursor: pointer;
      font-weight: 600;
      font: inherit;
    }
    .banner {
      margin-bottom: 14px;
      padding: 12px 14px;
      border-radius: 12px;
      font-size: 0.95rem;
    }
    .banner.info {
      background: #eff6ff;
      color: #1d4ed8;
      border: 1px solid #bfdbfe;
    }
    .banner.error {
      background: #fef2f2;
      color: #b91c1c;
      border: 1px solid #fecaca;
    }
    .banner.success {
      background: #ecfdf5;
      color: #15803d;
      border: 1px solid #bbf7d0;
    }
    .links {
      margin-top: 16px;
      font-size: 0.95rem;
    }
    .links a {
      color: #1766c5;
      text-decoration: none;
    }
  </style>
</head>
<body>
  <div class="card">
    <h1>Admin Setup</h1>
    <p>Create the first admin account for the Mobimend operations dashboard.</p>

    <?php if ($count > 0): ?>
      <div class="banner info">An admin account already exists. Use <a href="admin_dashboard.php">admin_dashboard.php</a> to sign in.</div>
    <?php else: ?>
      <?php if ($message !== ''): ?>
        <div class="banner <?= htmlspecialchars($tone) ?>"><?= htmlspecialchars($message) ?></div>
      <?php endif; ?>

      <form method="post" class="stack">
        <input type="text" name="name" placeholder="Admin name" required>
        <input type="email" name="email" placeholder="Admin email" required>
        <input type="password" name="password" placeholder="Password" required>
        <input type="password" name="confirm_password" placeholder="Confirm password" required>
        <input type="text" name="setup_token" placeholder="Setup token from .env" required>
        <button type="submit">Create Admin Account</button>
      </form>
    <?php endif; ?>

    <div class="links">
      <a href="admin_dashboard.php">Go to Admin Dashboard</a>
    </div>
  </div>
</body>
</html>
