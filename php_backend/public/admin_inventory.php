<?php

declare(strict_types=1);

session_start();

require dirname(__DIR__) . '/src/bootstrap.php';

use Mobimend\Config\Database;

$pdo = Database::connection();
$message = '';
$tone = 'info';
$editingId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$user = $_SESSION['admin_user'] ?? null;

$form = [
    'brand' => '',
    'model' => '',
    'part_type' => '',
    'quantity' => '0',
    'buy_price' => '0',
    'sell_price' => '0',
    'status' => 'in_stock',
    'notes' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'login') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');

        if ($email === '' || $password === '') {
            $message = 'Email and password are required.';
            $tone = 'error';
        } else {
            $stmt = $pdo->prepare('SELECT id, name, email, password_hash, role FROM users WHERE email = :email LIMIT 1');
            $stmt->execute(['email' => $email]);
            $foundUser = $stmt->fetch();

            if (!$foundUser || !password_verify($password, (string) $foundUser['password_hash'])) {
                $message = 'Invalid credentials.';
                $tone = 'error';
            } else {
                $_SESSION['admin_user'] = [
                    'id' => (int) $foundUser['id'],
                    'name' => (string) $foundUser['name'],
                    'email' => (string) $foundUser['email'],
                    'role' => (string) $foundUser['role'],
                ];
                header('Location: admin_inventory.php');
                exit;
            }
        }
    } elseif ($user) {
        if ($action === 'save_item') {
            $itemId = (int) ($_POST['item_id'] ?? 0);
            $form = [
                'brand' => trim((string) ($_POST['brand'] ?? '')),
                'model' => trim((string) ($_POST['model'] ?? '')),
                'part_type' => trim((string) ($_POST['part_type'] ?? '')),
                'quantity' => (string) max(0, (int) ($_POST['quantity'] ?? 0)),
                'buy_price' => (string) max(0, (float) ($_POST['buy_price'] ?? 0)),
                'sell_price' => (string) max(0, (float) ($_POST['sell_price'] ?? 0)),
                'status' => trim((string) ($_POST['status'] ?? 'in_stock')),
                'notes' => trim((string) ($_POST['notes'] ?? '')),
            ];

            if ($form['brand'] === '' || $form['model'] === '' || $form['part_type'] === '') {
                $message = 'Brand, model, and part type are required.';
                $tone = 'error';
                $editingId = $itemId;
            } else {
                $params = [
                    'brand' => $form['brand'],
                    'model' => $form['model'],
                    'part_type' => $form['part_type'],
                    'quantity' => (int) $form['quantity'],
                    'buy_price' => (float) $form['buy_price'],
                    'sell_price' => (float) $form['sell_price'],
                    'status' => in_array($form['status'], ['in_stock', 'sold_out'], true) ? $form['status'] : 'in_stock',
                    'notes' => $form['notes'],
                    'updated_at' => now(),
                ];

                if ($itemId > 0) {
                    $stmt = $pdo->prepare(
                        'UPDATE inventory_items
                         SET brand = :brand, model = :model, part_type = :part_type, quantity = :quantity,
                             buy_price = :buy_price, sell_price = :sell_price, status = :status, notes = :notes,
                             updated_at = :updated_at
                         WHERE id = :id'
                    );
                    $params['id'] = $itemId;
                    $stmt->execute($params);
                    $message = 'Inventory item updated.';
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO inventory_items
                         (brand, model, part_type, quantity, buy_price, sell_price, status, notes, created_at, updated_at)
                         VALUES
                         (:brand, :model, :part_type, :quantity, :buy_price, :sell_price, :status, :notes, :created_at, :updated_at)'
                    );
                    $params['created_at'] = now();
                    $stmt->execute($params);
                    $message = 'Inventory item created.';
                }

                $tone = 'success';
                header('Location: admin_inventory.php?message=' . urlencode($message) . '&tone=' . urlencode($tone));
                exit;
            }
        }

        if ($action === 'delete_item') {
            $itemId = (int) ($_POST['item_id'] ?? 0);
            if ($itemId > 0) {
                $stmt = $pdo->prepare('DELETE FROM inventory_items WHERE id = :id');
                $stmt->execute(['id' => $itemId]);
                header('Location: admin_inventory.php?message=' . urlencode('Inventory item deleted.') . '&tone=' . urlencode('success'));
                exit;
            }
        }
    }
}

if (isset($_GET['message'])) {
    $message = (string) $_GET['message'];
    $tone = (string) ($_GET['tone'] ?? 'info');
}

$adminCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

if ($user && $editingId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM inventory_items WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $editingId]);
    $editItem = $stmt->fetch();
    if ($editItem) {
        $form = [
            'brand' => (string) $editItem['brand'],
            'model' => (string) $editItem['model'],
            'part_type' => (string) $editItem['part_type'],
            'quantity' => (string) $editItem['quantity'],
            'buy_price' => (string) $editItem['buy_price'],
            'sell_price' => (string) $editItem['sell_price'],
            'status' => (string) $editItem['status'],
            'notes' => (string) $editItem['notes'],
        ];
    } else {
        $editingId = 0;
    }
}

$items = [];
$availableUnits = 0;
$lowStockLines = 0;
$outOfStockLines = 0;

if ($user) {
    $items = $pdo->query('SELECT * FROM inventory_items ORDER BY created_at DESC')->fetchAll();
    foreach ($items as $item) {
        $quantity = (int) $item['quantity'];
        $availableUnits += $quantity;
        if ($quantity > 0 && $quantity <= 5) {
            $lowStockLines++;
        }
        if ($quantity <= 0 || (string) $item['status'] !== 'in_stock') {
            $outOfStockLines++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Mobimend PHP Inventory Admin</title>
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@600;700&family=Roboto:wght@400;500&display=swap" rel="stylesheet">
  <style>
    body {
      margin: 0;
      font-family: 'Roboto', sans-serif;
      background: linear-gradient(180deg, #eef4ff 0%, #f7f9fc 48%, #ffffff 100%);
      color: #1f2933;
    }
    header {
      background: linear-gradient(135deg, #0f4aa1, #1766c5 55%, #3b82f6);
      color: #fff;
      padding: 24px 20px;
      box-shadow: 0 14px 34px rgba(23, 102, 197, 0.2);
    }
    header h1 {
      margin: 0;
      font-family: 'Montserrat', sans-serif;
    }
    .hero-copy {
      margin-top: 8px;
      max-width: 720px;
      color: rgba(255, 255, 255, 0.88);
    }
    .container {
      max-width: 1120px;
      margin: 28px auto;
      padding: 0 16px;
    }
    .card {
      background: #fff;
      border-radius: 18px;
      padding: 20px;
      margin-bottom: 18px;
      box-shadow: 0 18px 48px rgba(15, 23, 42, 0.08);
      border: 1px solid rgba(148, 163, 184, 0.18);
    }
    .login-shell {
      min-height: calc(100vh - 120px);
      display: grid;
      place-items: center;
    }
    .login-card {
      width: min(460px, 100%);
      padding: 28px;
    }
    .login-stack,
    .grid-form {
      display: grid;
      gap: 12px;
    }
    .grid-form {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .grid-form .full {
      grid-column: 1 / -1;
    }
    input,
    select,
    textarea {
      width: 100%;
      box-sizing: border-box;
      padding: 12px 13px;
      border-radius: 12px;
      border: 1px solid #d7dbe3;
      background: #fbfdff;
      font: inherit;
    }
    button,
    .button-link {
      display: inline-block;
      background: #1766c5;
      color: #fff;
      border: none;
      padding: 11px 16px;
      border-radius: 12px;
      cursor: pointer;
      font-weight: 600;
      text-decoration: none;
      font: inherit;
    }
    .secondary {
      background: #6b7280;
    }
    .ghost {
      background: #e8f0fe;
      color: #0f4aa1;
    }
    .danger {
      background: #b91c1c;
    }
    .dashboard-header,
    .topbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 18px;
    }
    .dashboard-title h2,
    .card h3 {
      margin: 0;
      font-family: 'Montserrat', sans-serif;
      color: #0f172a;
    }
    .dashboard-title p,
    .muted {
      margin: 6px 0 0;
      color: #556070;
    }
    .banner {
      margin-bottom: 14px;
      padding: 12px 14px;
      border-radius: 12px;
      font-size: 0.95rem;
      font-weight: 500;
    }
    .banner.info {
      background: #eff6ff;
      color: #1d4ed8;
      border: 1px solid #bfdbfe;
    }
    .banner.success {
      background: #ecfdf5;
      color: #15803d;
      border: 1px solid #bbf7d0;
    }
    .banner.error {
      background: #fef2f2;
      color: #b91c1c;
      border: 1px solid #fecaca;
    }
    .split-grid {
      display: grid;
      grid-template-columns: 1fr 1.3fr;
      gap: 18px;
      align-items: start;
    }
    .stats {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 12px;
      margin-bottom: 18px;
    }
    .stat {
      border: 1px solid #dbeafe;
      border-radius: 16px;
      padding: 16px;
      background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
    }
    .stat span {
      display: block;
      margin-bottom: 8px;
      color: #556070;
      font-size: 0.9rem;
    }
    .stat strong {
      font-family: 'Montserrat', sans-serif;
      font-size: 1.5rem;
      color: #0f172a;
    }
    .table-wrap {
      overflow-x: auto;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      background: #fff;
    }
    th, td {
      padding: 12px;
      border-bottom: 1px solid #eef0f4;
      text-align: left;
      vertical-align: top;
    }
    th {
      background: #f1f5f9;
      font-weight: 700;
    }
    .actions {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
    }
    .small {
      font-size: 0.9rem;
      color: #556070;
    }
    @media (max-width: 900px) {
      .split-grid,
      .stats,
      .grid-form {
        grid-template-columns: 1fr;
      }
      .topbar,
      .dashboard-header {
        flex-direction: column;
        align-items: flex-start;
      }
    }
  </style>
</head>
<body>
  <header>
    <h1>Mobimend Inventory Admin</h1>
    <div class="hero-copy">Direct PHP workspace for inventory login, stock creation, updates, and deletion without using the JavaScript API flow.</div>
  </header>

  <?php if (!$user): ?>
    <div class="container">
      <div class="login-shell">
        <div class="card login-card">
          <h3>Admin Login</h3>
          <p class="muted">Sign in with the admin user stored in the `users` table.</p>

          <?php if ($message !== ''): ?>
            <div class="banner <?= htmlspecialchars($tone) ?>"><?= htmlspecialchars($message) ?></div>
          <?php endif; ?>

          <?php if ($adminCount === 0): ?>
            <div class="banner info">No admin exists yet. Start with <a href="setup_admin.php">setup_admin.php</a>.</div>
          <?php endif; ?>

          <form method="post" class="login-stack">
            <input type="hidden" name="action" value="login">
            <input type="email" name="email" placeholder="Email" required>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit">Login</button>
          </form>
        </div>
      </div>
    </div>
  <?php else: ?>
    <div class="container">
      <div class="topbar">
        <div class="dashboard-title">
          <h2>Inventory Dashboard</h2>
          <p>Signed in as <?= htmlspecialchars((string) $user['name']) ?> (<?= htmlspecialchars((string) $user['email']) ?>)</p>
        </div>
        <a href="logout.php" class="button-link ghost">Logout</a>
      </div>

      <?php if ($message !== ''): ?>
        <div class="banner <?= htmlspecialchars($tone) ?>"><?= htmlspecialchars($message) ?></div>
      <?php endif; ?>

      <div class="stats">
        <div class="stat">
          <span>Available Stock Units</span>
          <strong><?= number_format($availableUnits) ?></strong>
        </div>
        <div class="stat">
          <span>Low Stock Lines</span>
          <strong><?= number_format($lowStockLines) ?></strong>
        </div>
        <div class="stat">
          <span>Out of Stock Lines</span>
          <strong><?= number_format($outOfStockLines) ?></strong>
        </div>
      </div>

      <div class="split-grid">
        <div class="card">
          <div class="dashboard-header">
            <div>
              <h3><?= $editingId > 0 ? 'Edit Inventory Item' : 'Add Inventory Item' ?></h3>
              <div class="small"><?= $editingId > 0 ? 'Update the selected stock record.' : 'Create a new stock record directly in MySQL.' ?></div>
            </div>
            <?php if ($editingId > 0): ?>
              <a href="admin_inventory.php" class="button-link secondary">Clear</a>
            <?php endif; ?>
          </div>

          <form method="post" class="grid-form">
            <input type="hidden" name="action" value="save_item">
            <input type="hidden" name="item_id" value="<?= $editingId ?>">

            <input type="text" name="brand" placeholder="Brand" value="<?= htmlspecialchars($form['brand']) ?>" required>
            <input type="text" name="model" placeholder="Model" value="<?= htmlspecialchars($form['model']) ?>" required>
            <input type="text" name="part_type" placeholder="Part Type" value="<?= htmlspecialchars($form['part_type']) ?>" required>
            <input type="number" min="0" name="quantity" placeholder="Quantity" value="<?= htmlspecialchars($form['quantity']) ?>" required>
            <input type="number" min="0" step="0.01" name="buy_price" placeholder="Buy Price" value="<?= htmlspecialchars($form['buy_price']) ?>" required>
            <input type="number" min="0" step="0.01" name="sell_price" placeholder="Sell Price" value="<?= htmlspecialchars($form['sell_price']) ?>" required>
            <select name="status">
              <option value="in_stock" <?= $form['status'] === 'in_stock' ? 'selected' : '' ?>>In Stock</option>
              <option value="sold_out" <?= $form['status'] === 'sold_out' ? 'selected' : '' ?>>Sold Out</option>
            </select>
            <div></div>
            <textarea class="full" name="notes" rows="4" placeholder="Notes"><?= htmlspecialchars($form['notes']) ?></textarea>
            <div class="full">
              <button type="submit"><?= $editingId > 0 ? 'Update Item' : 'Create Item' ?></button>
            </div>
          </form>
        </div>

        <div class="card">
          <div class="dashboard-header">
            <div>
              <h3>Inventory Items</h3>
              <div class="small">Direct PHP view of the `inventory_items` table.</div>
            </div>
          </div>

          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Brand</th>
                  <th>Model</th>
                  <th>Part</th>
                  <th>Qty</th>
                  <th>Buy</th>
                  <th>Sell</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($items === []): ?>
                  <tr>
                    <td colspan="8" class="small">No inventory items yet. Add your first stock line from the form.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($items as $item): ?>
                    <tr>
                      <td><?= htmlspecialchars((string) $item['brand']) ?></td>
                      <td><?= htmlspecialchars((string) $item['model']) ?></td>
                      <td><?= htmlspecialchars((string) $item['part_type']) ?></td>
                      <td><?= (int) $item['quantity'] ?></td>
                      <td><?= number_format((float) $item['buy_price'], 2) ?></td>
                      <td><?= number_format((float) $item['sell_price'], 2) ?></td>
                      <td><?= htmlspecialchars((string) $item['status']) ?></td>
                      <td>
                        <div class="actions">
                          <a href="admin_inventory.php?edit=<?= (int) $item['id'] ?>" class="button-link">Edit</a>
                          <form method="post" onsubmit="return confirm('Delete this item?');">
                            <input type="hidden" name="action" value="delete_item">
                            <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                            <button type="submit" class="danger">Delete</button>
                          </form>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>
</body>
</html>
