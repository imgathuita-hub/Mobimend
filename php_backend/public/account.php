<?php

declare(strict_types=1);

session_start();

require dirname(__DIR__) . '/src/bootstrap.php';

use Mobimend\Config\Database;

$pdo = Database::connection();
$message = '';
$tone = 'info';
$accountUser = $_SESSION['account_user'] ?? null;
$adminUser = $_SESSION['admin_user'] ?? null;
$adminRoles = ['admin', 'super_admin', 'technician'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $destination = (string) ($_POST['destination'] ?? 'customer');

    if ($email === '' || $password === '') {
        $message = 'Email and password are required.';
        $tone = 'error';
    } else {
        $stmt = $pdo->prepare('SELECT id, name, email, password_hash, role FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $foundUser = $stmt->fetch();

        if (!$foundUser || !password_verify($password, (string) $foundUser['password_hash'])) {
            $message = 'Invalid login credentials.';
            $tone = 'error';
        } else {
            $sessionUser = [
                'id' => (int) $foundUser['id'],
                'name' => (string) $foundUser['name'],
                'email' => (string) $foundUser['email'],
                'role' => (string) $foundUser['role'],
            ];

            if (in_array($sessionUser['role'], $adminRoles, true) || $destination === 'admin') {
                if (!in_array($sessionUser['role'], $adminRoles, true)) {
                    $message = 'This account does not have admin permissions.';
                    $tone = 'error';
                } else {
                    $_SESSION['admin_user'] = $sessionUser;
                    header('Location: admin_inventory.php');
                    exit;
                }
            } else {
                $_SESSION['account_user'] = $sessionUser;
                $accountUser = $sessionUser;
                $message = 'Welcome back, ' . $sessionUser['name'] . '.';
                $tone = 'success';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login | Mobimend Spares</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&family=Montserrat:wght@700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <nav class="site-nav">
    <a class="nav-left" href="index.php">
      <img src="../../public/assets/LOGO FINAL MOBIMEND WH BG.png" alt="Mobimend" class="logo">
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

  <main class="section alt">
    <div class="section-inner">
      <p class="section-kicker"><i class="fa-solid fa-right-to-bracket"></i> Login gateway</p>
      <h1 class="section-title">Customers track orders. Admins manage inventory.</h1>
      <p class="section-copy">One account page now routes customers to tracking and admins to inventory/repair management using the `users` table roles.</p>

      <?php if ($message !== ''): ?>
        <div class="php-banner <?= htmlspecialchars($tone) ?>"><?= htmlspecialchars($message) ?></div>
      <?php endif; ?>

      <?php if ($adminUser): ?>
        <div class="account-grid">
          <article class="dashboard-card wide">
            <h3>Admin signed in</h3>
            <p><?= htmlspecialchars((string) $adminUser['name']) ?> can manage inventories and repair bookings.</p>
            <div class="category-pills">
              <a class="pill active" href="admin_inventory.php">Inventory admin</a>
              <a class="pill" href="admin_repairs.php">Repair bookings</a>
              <a class="pill" href="setup_admin.php">Admin setup</a>
              <a class="pill" href="logout.php">Logout</a>
            </div>
          </article>
        </div>
      <?php elseif ($accountUser): ?>
        <div class="account-grid">
          <article class="dashboard-card wide">
            <h3>Customer dashboard</h3>
            <p>Signed in as <?= htmlspecialchars((string) $accountUser['name']) ?>. Track orders, saved addresses, repair progress, payment status, and warranty history here.</p>
            <a class="btn-primary" href="track.php">Track latest order</a>
          </article>
          <article class="dashboard-card"><h3>Order history</h3><p>Accessories and repair orders will appear here after checkout is connected.</p></article>
          <article class="dashboard-card"><h3>Saved addresses</h3><p>Default pickup and delivery addresses for faster checkout.</p></article>
          <article class="dashboard-card"><h3>Repair tracking</h3><p>Booked, diagnosing, awaiting parts, in repair, ready, completed.</p></article>
          <article class="dashboard-card"><h3>Warranty history</h3><p>Repair warranty records and proof of payment.</p></article>
        </div>
      <?php else: ?>
        <div class="account-grid">
          <article class="dashboard-card">
            <div class="path-icon"><i class="fa-solid fa-user"></i></div>
            <h3>Customer login</h3>
            <p>Track orders, saved addresses, repair progress, payment status, and warranty history.</p>
            <form method="post" class="form-grid" style="margin-top: 16px;">
              <input type="hidden" name="destination" value="customer">
              <div class="full"><label>Email</label><input name="email" type="email" placeholder="customer@example.com" required></div>
              <div class="full"><label>Password</label><input name="password" type="password" placeholder="Enter password" required></div>
              <div class="full"><button class="btn-primary" type="submit" style="width: 100%;">Login to customer dashboard</button></div>
            </form>
          </article>

          <article class="dashboard-card">
            <div class="path-icon"><i class="fa-solid fa-user-gear"></i></div>
            <h3>Admin login</h3>
            <p>Manage inventory, repair bookings, wholesale orders, payments, users, blog posts, and reports.</p>
            <form method="post" class="form-grid" style="margin-top: 16px;">
              <input type="hidden" name="destination" value="admin">
              <div class="full"><label>Admin email</label><input name="email" type="email" placeholder="admin@mobimend.local" required></div>
              <div class="full"><label>Password</label><input name="password" type="password" placeholder="Enter password" required></div>
              <div class="full"><button class="btn-dark" type="submit" style="width: 100%;">Login to admin dashboard</button></div>
            </form>
            <div class="category-pills" style="margin-top: 16px;">
              <span class="pill">Inventory</span>
              <span class="pill">Repairs</span>
              <span class="pill">Payments</span>
              <span class="pill">Reports</span>
            </div>
          </article>
        </div>
      <?php endif; ?>
    </div>
  </main>

  <script src="chatbot.js"></script>
  <script src="site.js"></script>
</body>
</html>
