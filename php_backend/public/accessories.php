<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Mobimend\Config\Database;

$pdo = Database::connection();
$message = (string) ($_GET['message'] ?? '');
$tone = (string) ($_GET['tone'] ?? 'info');
$selectedCategory = (int) ($_GET['category'] ?? 0);
$search = trim((string) ($_GET['q'] ?? ''));
$createdOrder = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $quantities = $_POST['quantities'] ?? [];
    $selectedItems = [];

    if (is_array($quantities)) {
        foreach ($quantities as $variantId => $quantity) {
            $variantId = (int) $variantId;
            $quantity = max(0, (int) $quantity);
            if ($variantId > 0 && $quantity > 0) {
                $selectedItems[$variantId] = $quantity;
            }
        }
    }

    $customerName = trim((string) ($_POST['customer_name'] ?? ''));
    $customerPhone = trim((string) ($_POST['customer_phone'] ?? ''));
    $customerEmail = trim((string) ($_POST['customer_email'] ?? ''));
    $deliveryAddress = trim((string) ($_POST['delivery_address'] ?? ''));
    $paymentMethod = (string) ($_POST['payment_method'] ?? 'mpesa_stk');

    try {
        if ($selectedItems === []) {
            throw new RuntimeException('Choose at least one product quantity before checkout.');
        }
        if ($customerName === '' || $customerPhone === '') {
            throw new RuntimeException('Customer name and phone number are required.');
        }
        if (!in_array($paymentMethod, ['mpesa_stk', 'cash', 'card'], true)) {
            $paymentMethod = 'mpesa_stk';
        }

        $pdo->beginTransaction();
        $lines = [];
        $subtotal = 0.0;

        foreach ($selectedItems as $variantId => $quantity) {
            $stmt = $pdo->prepare(
                'SELECT pv.*, p.id AS product_id, p.name, p.brand, p.compatible_brand, p.compatible_model,
                        p.minimum_wholesale_quantity, ii.id AS inventory_item_id, ii.buy_price
                 FROM product_variants pv
                 INNER JOIN products p ON p.id = pv.product_id
                 LEFT JOIN inventory_items ii ON ii.product_variant_id = pv.id
                 WHERE pv.id = :id AND pv.is_active = 1 AND p.status IN ("active", "out_of_stock")
                 FOR UPDATE'
            );
            $stmt->execute(['id' => $variantId]);
            $variant = $stmt->fetch();

            if (!$variant) {
                throw new RuntimeException('One selected product is no longer available.');
            }
            if ((int) $variant['stock_quantity'] < $quantity) {
                throw new RuntimeException((string) $variant['name'] . ' has only ' . (int) $variant['stock_quantity'] . ' units available.');
            }

            $unitPrice = (float) $variant['retail_price'];
            $lineTotal = $unitPrice * $quantity;
            $subtotal += $lineTotal;
            $lines[] = [
                'variant' => $variant,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
            ];
        }

        $orderNumber = order_number('SHOP');
        $stmt = $pdo->prepare(
            'INSERT INTO orders
             (order_number, order_type, status, payment_status, subtotal, grand_total,
              customer_name, customer_email, customer_phone, delivery_address, notes, created_at, updated_at)
             VALUES
             (:order_number, "product", "confirmed", "unpaid", :subtotal, :grand_total,
              :customer_name, :customer_email, :customer_phone, :delivery_address, :notes, :created_at, :updated_at)'
        );
        $stmt->execute([
            'order_number' => $orderNumber,
            'subtotal' => $subtotal,
            'grand_total' => $subtotal,
            'customer_name' => $customerName,
            'customer_email' => $customerEmail,
            'customer_phone' => $customerPhone,
            'delivery_address' => $deliveryAddress,
            'notes' => trim((string) ($_POST['notes'] ?? '')),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $orderId = (int) $pdo->lastInsertId();

        foreach ($lines as $line) {
            $variant = $line['variant'];
            $quantity = (int) $line['quantity'];
            $nextStock = (int) $variant['stock_quantity'] - $quantity;

            $stmt = $pdo->prepare(
                'INSERT INTO order_items
                 (order_id, product_id, product_variant_id, item_name, sku, quantity, unit_price, line_total, created_at)
                 VALUES
                 (:order_id, :product_id, :product_variant_id, :item_name, :sku, :quantity, :unit_price, :line_total, :created_at)'
            );
            $stmt->execute([
                'order_id' => $orderId,
                'product_id' => (int) $variant['product_id'],
                'product_variant_id' => (int) $variant['id'],
                'item_name' => (string) $variant['name'] . ' - ' . (string) $variant['variant_name'],
                'sku' => (string) $variant['sku'],
                'quantity' => $quantity,
                'unit_price' => (float) $line['unit_price'],
                'line_total' => (float) $line['line_total'],
                'created_at' => now(),
            ]);
            $orderItemId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare('UPDATE product_variants SET stock_quantity = :stock, updated_at = :updated_at WHERE id = :id');
            $stmt->execute(['stock' => $nextStock, 'updated_at' => now(), 'id' => (int) $variant['id']]);

            $inventoryItemId = (int) ($variant['inventory_item_id'] ?? 0);
            if ($inventoryItemId <= 0) {
                $stmt = $pdo->prepare(
                    'INSERT INTO inventory_items
                     (product_variant_id, brand, model, part_type, quantity, low_stock_threshold, buy_price, sell_price, wholesale_price, status, notes)
                     VALUES
                     (:product_variant_id, :brand, :model, :part_type, :quantity, :low_stock_threshold, 0, :sell_price, :wholesale_price, :status, :notes)'
                );
                $stmt->execute([
                    'product_variant_id' => (int) $variant['id'],
                    'brand' => (string) ($variant['brand'] ?: $variant['compatible_brand']),
                    'model' => (string) $variant['compatible_model'],
                    'part_type' => (string) $variant['name'],
                    'quantity' => $nextStock,
                    'low_stock_threshold' => (int) $variant['low_stock_threshold'],
                    'sell_price' => (float) $variant['retail_price'],
                    'wholesale_price' => (float) $variant['wholesale_price'],
                    'status' => $nextStock > 0 ? 'in_stock' : 'sold_out',
                    'notes' => 'Auto-created from retail checkout.',
                ]);
                $inventoryItemId = (int) $pdo->lastInsertId();
            } else {
                $stmt = $pdo->prepare('UPDATE inventory_items SET quantity = :stock, status = :status, updated_at = :updated_at WHERE id = :id');
                $stmt->execute([
                    'stock' => $nextStock,
                    'status' => $nextStock > 0 ? 'in_stock' : 'sold_out',
                    'updated_at' => now(),
                    'id' => $inventoryItemId,
                ]);
            }

            $buyPrice = (float) ($variant['buy_price'] ?? 0);
            $stmt = $pdo->prepare(
                'INSERT INTO inventory_transactions
                 (inventory_item_id, order_item_id, brand, model, part_type, quantity, unit_buy_price, unit_sell_price,
                  total_cost, total_revenue, profit, source, created_at)
                 VALUES
                 (:inventory_item_id, :order_item_id, :brand, :model, :part_type, :quantity, :unit_buy_price, :unit_sell_price,
                  :total_cost, :total_revenue, :profit, "retail_checkout", :created_at)'
            );
            $stmt->execute([
                'inventory_item_id' => $inventoryItemId,
                'order_item_id' => $orderItemId,
                'brand' => (string) ($variant['brand'] ?: $variant['compatible_brand']),
                'model' => (string) $variant['compatible_model'],
                'part_type' => (string) $variant['name'],
                'quantity' => $quantity,
                'unit_buy_price' => $buyPrice,
                'unit_sell_price' => (float) $line['unit_price'],
                'total_cost' => $buyPrice * $quantity,
                'total_revenue' => (float) $line['line_total'],
                'profit' => ((float) $line['unit_price'] - $buyPrice) * $quantity,
                'created_at' => now(),
            ]);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO payments
             (order_id, payment_method, amount, currency, status, phone_number, created_at, updated_at)
             VALUES
             (:order_id, :payment_method, :amount, "KES", "pending", :phone_number, :created_at, :updated_at)'
        );
        $stmt->execute([
            'order_id' => $orderId,
            'payment_method' => $paymentMethod,
            'amount' => $subtotal,
            'phone_number' => $customerPhone,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pdo->commit();
        $createdOrder = ['number' => $orderNumber, 'total' => $subtotal];
        $message = 'Order ' . $orderNumber . ' created. Payment is pending verification.';
        $tone = 'success';
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = $exception->getMessage();
        $tone = 'error';
    }
}

$categories = $pdo->query('SELECT * FROM product_categories WHERE is_active = 1 ORDER BY name ASC')->fetchAll();
$params = [];
$sql = 'SELECT pv.*, p.name, p.description, p.brand, p.compatible_brand, p.compatible_model, p.minimum_wholesale_quantity,
               pc.id AS category_id, pc.name AS category_name
        FROM product_variants pv
        INNER JOIN products p ON p.id = pv.product_id
        LEFT JOIN product_categories pc ON pc.id = p.category_id
        WHERE pv.is_active = 1 AND p.status IN ("active", "out_of_stock")';
if ($selectedCategory > 0) {
    $sql .= ' AND pc.id = :category_id';
    $params['category_id'] = $selectedCategory;
}
if ($search !== '') {
    $sql .= ' AND (p.name LIKE :search OR p.brand LIKE :search OR p.compatible_brand LIKE :search OR p.compatible_model LIKE :search OR pv.sku LIKE :search)';
    $params['search'] = '%' . $search . '%';
}
$sql .= ' ORDER BY pc.name ASC, p.name ASC, pv.variant_name ASC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$cartCount = array_reduce($products, static fn (int $sum, array $product): int => $sum + max(0, (int) $product['stock_quantity']), 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Accessories Shop | Mobimend Spares</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&family=Montserrat:wght@700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <nav class="site-nav">
    <a class="nav-left" href="index.php">
      <img src="assets/LOGO FINAL MOBIMEND WH BG.png" alt="Mobimend Spares" class="logo">
      <div class="brand"><h1>MOBIMEND</h1><p class="tagline">Accessories shop</p></div>
    </a>
    <button class="menu-toggle" id="menu-toggle" aria-expanded="false" aria-label="Toggle navigation"><i class="fa-solid fa-bars"></i></button>
    <ul class="nav-links" id="nav-links">
      <li><a href="index.php">Home</a></li>
      <li><a href="repair.php">Repair</a></li>
      <li><a class="active" href="accessories.php">Shop</a></li>
      <li><a href="wholesale.php">Wholesale</a></li>
      <li><a href="blog.php">Blog</a></li>
      <li><a href="track.php">Track</a></li>
      <li><a href="account.php">Account</a></li>
    </ul>
  </nav>

  <main>
    <section class="section alt">
      <div class="section-inner">
        <p class="section-kicker"><i class="fa-solid fa-bag-shopping"></i> Live retail catalog</p>
        <h1 class="section-title">Accessories now sell from real stock.</h1>
        <p class="section-copy">Search, choose quantities, and create an order that reserves inventory and opens a payment record for verification.</p>

        <?php if ($message !== ''): ?>
          <div class="php-banner <?= htmlspecialchars($tone) ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($createdOrder): ?>
          <div class="trust-strip">
            <div class="trust-item"><strong><?= htmlspecialchars($createdOrder['number']) ?></strong><span>order number</span></div>
            <div class="trust-item"><strong>KES <?= number_format((float) $createdOrder['total'], 2) ?></strong><span>pending payment</span></div>
            <div class="trust-item"><strong>Confirmed</strong><span>stock reserved</span></div>
          </div>
        <?php endif; ?>

        <form method="get" class="shop-toolbar">
          <input id="productSearch" name="q" type="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search cases, chargers, protectors, screens...">
          <select name="category">
            <option value="0">All categories</option>
            <?php foreach ($categories as $category): ?>
              <option value="<?= (int) $category['id'] ?>" <?= $selectedCategory === (int) $category['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $category['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn-dark" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
          <a class="btn-ghost" href="accessories.php">Reset</a>
        </form>

        <form method="post" id="retailCheckout">
          <div id="productGrid" class="product-grid" data-server-products aria-live="polite">
            <?php if ($products === []): ?>
              <article class="product-card">
                <div class="product-body">
                  <h3>No sellable accessories yet</h3>
                  <p>Add products from <a href="admin_products.php">admin_products.php</a> to activate the shop catalog.</p>
                </div>
              </article>
            <?php endif; ?>
            <?php foreach ($products as $product): ?>
              <?php $stock = (int) $product['stock_quantity']; ?>
              <article class="product-card" data-product-card>
                <div class="product-art">
                  <div class="css-accessory charger" aria-hidden="true"></div>
                </div>
                <div class="product-body">
                  <div class="blog-meta">
                    <span class="status-pill"><?= htmlspecialchars((string) ($product['category_name'] ?? 'Catalog')) ?></span>
                    <span class="status-pill"><?= $stock > 0 ? number_format($stock) . ' in stock' : 'Sold out' ?></span>
                  </div>
                  <h3><?= htmlspecialchars((string) $product['name']) ?></h3>
                  <p><?= htmlspecialchars(trim((string) $product['compatible_brand'] . ' ' . (string) $product['compatible_model'])) ?></p>
                  <p><?= htmlspecialchars((string) $product['variant_name']) ?> · <?= htmlspecialchars((string) $product['sku']) ?></p>
                  <div class="price-row">
                    <span class="price">KES <?= number_format((float) $product['retail_price'], 2) ?></span>
                    <input type="number" min="0" max="<?= $stock ?>" name="quantities[<?= (int) $product['id'] ?>]" placeholder="Qty" <?= $stock <= 0 ? 'disabled' : '' ?> style="max-width: 92px;">
                  </div>
                </div>
              </article>
            <?php endforeach; ?>
          </div>

          <section class="section" id="checkout">
            <div class="section-inner" style="padding-left: 0; padding-right: 0;">
              <p class="section-kicker"><i class="fa-solid fa-credit-card"></i> Checkout and payment</p>
              <h2 class="section-title">Create an order and reserve stock immediately.</h2>
              <div class="checkout-flow">
                <div class="payment-card">
                  <h3>Customer and delivery</h3>
                  <div class="form-grid" style="margin-top: 16px;">
                    <div><label>Name</label><input name="customer_name" placeholder="Jane Customer" required></div>
                    <div><label>Phone</label><input name="customer_phone" placeholder="07XX XXX XXX" required></div>
                    <div><label>Email</label><input type="email" name="customer_email" placeholder="jane@example.com"></div>
                    <div><label>Delivery option</label><select name="delivery_option"><option>Pickup at Juja shop</option><option>Local delivery</option><option>Courier delivery</option></select></div>
                    <div class="full"><label>Delivery address</label><input name="delivery_address" placeholder="Town, building, rider notes"></div>
                    <div class="full"><label>Order notes</label><input name="notes" placeholder="Color preference, exact model, or delivery note"></div>
                  </div>
                </div>

                <div class="payment-card">
                  <h3>Payment method</h3>
                  <label class="payment-method"><input type="radio" name="payment_method" value="mpesa_stk" data-payment-method checked> M-Pesa STK Push</label>
                  <label class="payment-method"><input type="radio" name="payment_method" value="cash" data-payment-method> Pay on pickup</label>
                  <label class="payment-method"><input type="radio" name="payment_method" value="card" data-payment-method> Card payment</label>
                  <p><strong>Current flow:</strong> payment record is created as pending so admin can verify M-Pesa, cash, or card confirmation.</p>
                  <button class="btn-primary" type="submit" style="width: 100%; margin-top: 12px;"><i class="fa-solid fa-lock"></i> Create order</button>
                </div>
              </div>
            </div>
          </section>
        </form>
      </div>
    </section>
  </main>

  <footer class="footer">
    <div class="container footer-grid">
      <div><h3>Mobimend Shop</h3><p>Accessories backed by live products, order records, and stock movement.</p></div>
      <div><h3>Stock</h3><ul><li><?= number_format($cartCount) ?> visible units</li><li>Real checkout</li><li>Payment pending state</li></ul></div>
      <div><h3>Support</h3><ul><li><a href="track.php">Track order</a></li><li><a href="contact.php">Contact team</a></li></ul></div>
      <div><h3>Admin</h3><ul><li><a href="admin_products.php">Product center</a></li><li><a href="admin_inventory.php">Inventory admin</a></li></ul></div>
    </div>
    <div class="container footer-bottom">&copy; 2026 Mobimend Spares.</div>
  </footer>

  <script src="chatbot.js"></script>
  <script src="site.js"></script>
</body>
</html>
