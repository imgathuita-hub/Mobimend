<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Mobimend\Config\Database;

$pdo = Database::connection();
$message = '';
$tone = 'info';
$selectedBrand = trim((string) ($_GET['brand'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $quantities = $_POST['quantities'] ?? [];
    $selectedItems = [];
    $buyerName = trim((string) ($_POST['buyer_name'] ?? ''));
    $buyerPhone = trim((string) ($_POST['buyer_phone'] ?? ''));
    $buyerEmail = trim((string) ($_POST['buyer_email'] ?? ''));
    $businessName = trim((string) ($_POST['business_name'] ?? ''));
    $deliveryAddress = trim((string) ($_POST['delivery_address'] ?? ''));
    $paymentMethod = (string) ($_POST['payment_method'] ?? 'mpesa_stk');

    if (is_array($quantities)) {
        foreach ($quantities as $itemId => $quantity) {
            $itemId = (int) $itemId;
            $quantity = max(0, (int) $quantity);
            if ($itemId > 0 && $quantity > 0) {
                $selectedItems[$itemId] = $quantity;
            }
        }
    }

    if ($selectedItems === []) {
        $message = 'Choose at least one item quantity before checkout.';
        $tone = 'error';
    } elseif ($buyerName === '' || $buyerPhone === '') {
        $message = 'Buyer name and phone number are required for wholesale checkout.';
        $tone = 'error';
    } else {
        try {
            if (!in_array($paymentMethod, ['mpesa_stk', 'cash', 'bank_transfer', 'card'], true)) {
                $paymentMethod = 'mpesa_stk';
            }

            $pdo->beginTransaction();
            $updatedCount = 0;
            $totalRevenue = 0.0;
            $lines = [];

            foreach ($selectedItems as $itemId => $quantity) {
                $stmt = $pdo->prepare(
                    'SELECT ii.*, pv.product_id, pv.sku, pv.stock_quantity, p.name AS product_name, p.minimum_wholesale_quantity
                     FROM inventory_items ii
                     LEFT JOIN product_variants pv ON pv.id = ii.product_variant_id
                     LEFT JOIN products p ON p.id = pv.product_id
                     WHERE ii.id = :id
                     FOR UPDATE'
                );
                $stmt->execute(['id' => $itemId]);
                $item = $stmt->fetch();

                if (!$item) {
                    throw new RuntimeException('One of the selected items no longer exists.');
                }

                $moq = max(1, (int) ($item['minimum_wholesale_quantity'] ?? 5));
                if ($quantity < $moq) {
                    throw new RuntimeException('MOQ for ' . $item['model'] . ' ' . $item['part_type'] . ' is ' . $moq . ' units.');
                }

                $available = (int) $item['quantity'];
                if ($available < $quantity) {
                    throw new RuntimeException('Requested quantity for ' . $item['model'] . ' ' . $item['part_type'] . ' exceeds available stock.');
                }

                $unitBuyPrice = (float) $item['buy_price'];
                $unitSellPrice = (float) $item['wholesale_price'] > 0 ? (float) $item['wholesale_price'] : (float) $item['sell_price'];
                $lineRevenue = $unitSellPrice * $quantity;
                $totalRevenue += $lineRevenue;
                $updatedCount += $quantity;
                $lines[] = [
                    'item' => $item,
                    'quantity' => $quantity,
                    'unit_buy_price' => $unitBuyPrice,
                    'unit_sell_price' => $unitSellPrice,
                    'line_revenue' => $lineRevenue,
                ];
            }

            $orderNumber = order_number('WHOLE');
            $order = $pdo->prepare(
                'INSERT INTO orders
                 (order_number, order_type, status, payment_status, subtotal, grand_total,
                  customer_name, customer_email, customer_phone, delivery_address, notes, created_at, updated_at)
                 VALUES
                 (:order_number, "wholesale", "confirmed", "unpaid", :subtotal, :grand_total,
                  :customer_name, :customer_email, :customer_phone, :delivery_address, :notes, :created_at, :updated_at)'
            );
            $order->execute([
                'order_number' => $orderNumber,
                'subtotal' => $totalRevenue,
                'grand_total' => $totalRevenue,
                'customer_name' => $buyerName,
                'customer_email' => $buyerEmail,
                'customer_phone' => $buyerPhone,
                'delivery_address' => $deliveryAddress,
                'notes' => $businessName !== '' ? 'Business: ' . $businessName : '',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $orderId = (int) $pdo->lastInsertId();

            foreach ($lines as $line) {
                $item = $line['item'];
                $quantity = (int) $line['quantity'];

                $nextQuantity = (int) $item['quantity'] - $quantity;
                $update = $pdo->prepare('UPDATE inventory_items SET quantity = :quantity, status = :status, updated_at = :updated_at WHERE id = :id');
                $update->execute([
                    'id' => (int) $item['id'],
                    'quantity' => $nextQuantity,
                    'status' => $nextQuantity > 0 ? 'in_stock' : 'sold_out',
                    'updated_at' => now(),
                ]);

                if (!empty($item['product_variant_id'])) {
                    $variantStock = max(0, (int) ($item['stock_quantity'] ?? $item['quantity']) - $quantity);
                    $variantUpdate = $pdo->prepare('UPDATE product_variants SET stock_quantity = :stock, updated_at = :updated_at WHERE id = :id');
                    $variantUpdate->execute([
                        'stock' => $variantStock,
                        'updated_at' => now(),
                        'id' => (int) $item['product_variant_id'],
                    ]);
                }

                $orderItem = $pdo->prepare(
                    'INSERT INTO order_items
                     (order_id, product_id, product_variant_id, item_name, sku, quantity, unit_price, line_total, created_at)
                     VALUES
                     (:order_id, :product_id, :product_variant_id, :item_name, :sku, :quantity, :unit_price, :line_total, :created_at)'
                );
                $orderItem->execute([
                    'order_id' => $orderId,
                    'product_id' => !empty($item['product_id']) ? (int) $item['product_id'] : null,
                    'product_variant_id' => !empty($item['product_variant_id']) ? (int) $item['product_variant_id'] : null,
                    'item_name' => (string) $item['brand'] . ' ' . (string) $item['model'] . ' ' . (string) $item['part_type'],
                    'sku' => (string) ($item['sku'] ?? ''),
                    'quantity' => $quantity,
                    'unit_price' => (float) $line['unit_sell_price'],
                    'line_total' => (float) $line['line_revenue'],
                    'created_at' => now(),
                ]);
                $orderItemId = (int) $pdo->lastInsertId();

                $insert = $pdo->prepare(
                    'INSERT INTO inventory_transactions
                     (inventory_item_id, order_item_id, brand, model, part_type, quantity, unit_buy_price, unit_sell_price,
                      total_cost, total_revenue, profit, source, created_at)
                     VALUES
                     (:inventory_item_id, :order_item_id, :brand, :model, :part_type, :quantity, :unit_buy_price, :unit_sell_price,
                      :total_cost, :total_revenue, :profit, :source, :created_at)'
                );
                $insert->execute([
                    'inventory_item_id' => (int) $item['id'],
                    'order_item_id' => $orderItemId,
                    'brand' => $item['brand'],
                    'model' => $item['model'],
                    'part_type' => $item['part_type'],
                    'quantity' => $quantity,
                    'unit_buy_price' => (float) $line['unit_buy_price'],
                    'unit_sell_price' => (float) $line['unit_sell_price'],
                    'total_cost' => (float) $line['unit_buy_price'] * $quantity,
                    'total_revenue' => (float) $line['line_revenue'],
                    'profit' => ((float) $line['unit_sell_price'] - (float) $line['unit_buy_price']) * $quantity,
                    'source' => 'wholesale_checkout',
                    'created_at' => now(),
                ]);
            }

            $payment = $pdo->prepare(
                'INSERT INTO payments
                 (order_id, payment_method, amount, currency, status, phone_number, created_at, updated_at)
                 VALUES
                 (:order_id, :payment_method, :amount, "KES", "pending", :phone_number, :created_at, :updated_at)'
            );
            $payment->execute([
                'order_id' => $orderId,
                'payment_method' => $paymentMethod,
                'amount' => $totalRevenue,
                'phone_number' => $buyerPhone,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $pdo->commit();
            $message = 'Wholesale order ' . $orderNumber . ' created for ' . $updatedCount . ' units. Total value: KES ' . number_format($totalRevenue, 2) . '.';
            $tone = 'success';
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = $exception->getMessage();
            $tone = 'error';
        }
    }
}

$brandsStmt = $pdo->query('SELECT DISTINCT brand FROM inventory_items ORDER BY brand ASC');
$brands = array_map(static fn (array $row): string => (string) $row['brand'], $brandsStmt->fetchAll());

$sql = 'SELECT ii.*, p.minimum_wholesale_quantity, pv.sku
        FROM inventory_items ii
        LEFT JOIN product_variants pv ON pv.id = ii.product_variant_id
        LEFT JOIN products p ON p.id = pv.product_id
        WHERE ii.quantity > 0';
$params = [];
if ($selectedBrand !== '') {
    $sql .= ' AND ii.brand = :brand';
    $params['brand'] = $selectedBrand;
}
$sql .= ' ORDER BY ii.brand ASC, ii.model ASC, ii.part_type ASC';
$itemsStmt = $pdo->prepare($sql);
$itemsStmt->execute($params);
$items = $itemsStmt->fetchAll();

$totalUnits = array_reduce($items, static fn (int $sum, array $item): int => $sum + (int) $item['quantity'], 0);
$activeBrands = count(array_unique(array_map(static fn (array $item): string => (string) $item['brand'], $items)));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Wholesale Parts | Mobimend Spares</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&family=Montserrat:wght@700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <nav class="site-nav">
    <a class="nav-left" href="index.php">
      <img src="assets/LOGO FINAL MOBIMEND WH BG.png" alt="Mobimend Spares" class="logo">
      <div class="brand"><h1>MOBIMEND</h1><p class="tagline">Wholesale desk</p></div>
    </a>
    <button class="menu-toggle" id="menu-toggle" aria-expanded="false" aria-label="Toggle navigation"><i class="fa-solid fa-bars"></i></button>
    <ul class="nav-links" id="nav-links">
      <li><a href="index.php">Home</a></li>
      <li><a href="repair.php">Repair</a></li>
      <li><a href="accessories.php">Shop</a></li>
      <li><a class="active" href="wholesale.php">Wholesale</a></li>
      <li><a href="blog.php">Blog</a></li>
      <li><a href="track.php">Track</a></li>
      <li><a href="account.php">Account</a></li>
    </ul>
  </nav>

  <header class="wholesale-hero">
    <div class="section-inner">
      <p class="section-kicker"><i class="fa-solid fa-boxes-stacked"></i> Live wholesale inventory</p>
      <h1 class="section-title">MOQ-aware ordering for repair shops and resellers.</h1>
      <p class="section-copy">Filter by brand, select quantities, and submit a stock-backed checkout that deducts inventory and records transactions.</p>
      <div class="trust-strip">
        <div class="trust-item"><strong><?= number_format($totalUnits) ?></strong><span>visible units</span></div>
        <div class="trust-item"><strong><?= number_format($activeBrands) ?></strong><span>active brands</span></div>
        <div class="trust-item"><strong>MOQ 5+</strong><span>recommended bulk tier</span></div>
        <div class="trust-item"><strong>Live</strong><span>MySQL inventory</span></div>
      </div>
    </div>
  </header>

  <main class="section alt" id="live-catalog">
    <div class="section-inner">
      <?php if ($message !== ''): ?>
        <div class="php-banner <?= htmlspecialchars($tone) ?>"><?= htmlspecialchars($message) ?></div>
      <?php endif; ?>

      <div class="wholesale-layout">
        <aside class="wholesale-card wholesale-filter">
          <h3>Buyer controls</h3>
          <p>Separate pricing tiers and quantity guidance for business customers.</p>
          <div class="category-pills">
            <a class="pill <?= $selectedBrand === '' ? 'active' : '' ?>" href="wholesale.php#live-catalog">All brands</a>
            <?php foreach ($brands as $brand): ?>
              <a class="pill <?= $selectedBrand === $brand ? 'active' : '' ?>" href="wholesale.php?brand=<?= urlencode($brand) ?>#live-catalog"><?= htmlspecialchars($brand) ?></a>
            <?php endforeach; ?>
          </div>
          <div class="payment-method" style="margin-top: 18px;">
            <strong>Pricing tiers</strong>
            <span>1-4 units: retail</span>
            <span>5-19 units: reseller</span>
            <span>20+ units: distributor</span>
          </div>
          <a class="btn-ghost" href="contact.php" style="margin-top: 14px;">Request special pricing</a>
        </aside>

        <section class="wholesale-card">
          <h2>Wholesale catalog</h2>
          <p>Select quantities. MOQ warnings can later become enforced pricing logic.</p>
          <form method="post" action="#live-catalog">
            <table class="wholesale-table">
              <thead>
                <tr>
                  <th>Part</th>
                  <th>Brand / model</th>
                  <th>Available</th>
                  <th>Unit price</th>
                  <th>MOQ</th>
                  <th>Qty</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($items === []): ?>
                  <tr><td colspan="6">No stock available for this filter yet. Add inventory from admin.</td></tr>
                <?php endif; ?>
                <?php foreach ($items as $item): ?>
                  <tr>
                    <td><strong><?= htmlspecialchars((string) $item['part_type']) ?></strong><br><span class="status-pill">Quality checked</span></td>
                    <td><?= htmlspecialchars((string) $item['brand']) ?><br><small><?= htmlspecialchars((string) $item['model']) ?></small></td>
                    <td><?= (int) $item['quantity'] ?></td>
                    <td>KES <?= number_format((float) ($item['wholesale_price'] > 0 ? $item['wholesale_price'] : $item['sell_price']), 2) ?></td>
                    <td><span class="status-pill"><?= max(1, (int) ($item['minimum_wholesale_quantity'] ?? 5)) ?>+</span></td>
                    <td><input type="number" min="0" max="<?= (int) $item['quantity'] ?>" name="quantities[<?= (int) $item['id'] ?>]" placeholder="0"></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>

            <div class="checkout-flow">
              <div class="payment-card">
                <h3>Buyer details</h3>
                <div class="form-grid" style="margin-top: 16px;">
                  <div><label>Contact name</label><input name="buyer_name" placeholder="Jane Buyer" required></div>
                  <div><label>Phone</label><input name="buyer_phone" placeholder="07XX XXX XXX" required></div>
                  <div><label>Email</label><input type="email" name="buyer_email" placeholder="buyer@example.com"></div>
                  <div><label>Business</label><input name="business_name" placeholder="Repair shop or reseller"></div>
                  <div class="full"><label>Delivery / pickup notes</label><input name="delivery_address" placeholder="Pickup, rider dispatch, or courier address"></div>
                </div>
              </div>
              <div class="payment-card">
                <h3>Wholesale checkout notes</h3>
                <p>Orders deduct inventory immediately, create a wholesale order, and insert inventory transaction records for profit tracking.</p>
              </div>
              <div class="payment-card">
                <h3>Payment readiness</h3>
                <label class="payment-method"><input type="radio" name="payment_method" value="mpesa_stk" checked> M-Pesa STK</label>
                <label class="payment-method"><input type="radio" name="payment_method" value="bank_transfer"> Bank transfer</label>
                <label class="payment-method"><input type="radio" name="payment_method" value="cash"> Cash on pickup</label>
                <p>M-Pesa, bank transfer, and cash payments are saved as pending records for admin reconciliation.</p>
                <button class="btn-primary" type="submit" style="width: 100%; margin-top: 12px;">Submit wholesale checkout</button>
              </div>
            </div>
          </form>
        </section>
      </div>
    </div>
  </main>

  <script src="chatbot.js"></script>
  <script src="site.js"></script>
</body>
</html>
