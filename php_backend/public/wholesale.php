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
    } else {
        try {
            $pdo->beginTransaction();
            $updatedCount = 0;
            $totalRevenue = 0.0;

            foreach ($selectedItems as $itemId => $quantity) {
                $stmt = $pdo->prepare('SELECT * FROM inventory_items WHERE id = :id FOR UPDATE');
                $stmt->execute(['id' => $itemId]);
                $item = $stmt->fetch();

                if (!$item) {
                    throw new RuntimeException('One of the selected items no longer exists.');
                }

                $available = (int) $item['quantity'];
                if ($available < $quantity) {
                    throw new RuntimeException('Requested quantity for ' . $item['model'] . ' ' . $item['part_type'] . ' exceeds available stock.');
                }

                $nextQuantity = $available - $quantity;
                $update = $pdo->prepare('UPDATE inventory_items SET quantity = :quantity, status = :status, updated_at = :updated_at WHERE id = :id');
                $update->execute([
                    'id' => $itemId,
                    'quantity' => $nextQuantity,
                    'status' => $nextQuantity > 0 ? 'in_stock' : 'sold_out',
                    'updated_at' => now(),
                ]);

                $unitBuyPrice = (float) $item['buy_price'];
                $unitSellPrice = (float) $item['sell_price'];
                $lineRevenue = $unitSellPrice * $quantity;

                $insert = $pdo->prepare(
                    'INSERT INTO inventory_transactions
                     (inventory_item_id, brand, model, part_type, quantity, unit_buy_price, unit_sell_price,
                      total_cost, total_revenue, profit, source, created_at)
                     VALUES
                     (:inventory_item_id, :brand, :model, :part_type, :quantity, :unit_buy_price, :unit_sell_price,
                      :total_cost, :total_revenue, :profit, :source, :created_at)'
                );
                $insert->execute([
                    'inventory_item_id' => $itemId,
                    'brand' => $item['brand'],
                    'model' => $item['model'],
                    'part_type' => $item['part_type'],
                    'quantity' => $quantity,
                    'unit_buy_price' => $unitBuyPrice,
                    'unit_sell_price' => $unitSellPrice,
                    'total_cost' => $unitBuyPrice * $quantity,
                    'total_revenue' => $lineRevenue,
                    'profit' => ($unitSellPrice - $unitBuyPrice) * $quantity,
                    'source' => 'website_checkout',
                    'created_at' => now(),
                ]);

                $updatedCount += $quantity;
                $totalRevenue += $lineRevenue;
            }

            $pdo->commit();
            $message = 'Checkout completed for ' . $updatedCount . ' units. Total value: KES ' . number_format($totalRevenue, 2) . '.';
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

$sql = 'SELECT * FROM inventory_items WHERE quantity > 0';
$params = [];
if ($selectedBrand !== '') {
    $sql .= ' AND brand = :brand';
    $params['brand'] = $selectedBrand;
}
$sql .= ' ORDER BY brand ASC, model ASC, part_type ASC';
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
      <img src="../../public/assets/LOGO FINAL MOBIMEND WH BG.png" alt="Mobimend Spares" class="logo">
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
                    <td>KES <?= number_format((float) $item['sell_price'], 2) ?></td>
                    <td><span class="status-pill"><?= (int) $item['quantity'] >= 5 ? '5+' : 'Low stock' ?></span></td>
                    <td><input type="number" min="0" max="<?= (int) $item['quantity'] ?>" name="quantities[<?= (int) $item['id'] ?>]" placeholder="0"></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>

            <div class="checkout-flow">
              <div class="payment-card">
                <h3>Wholesale checkout notes</h3>
                <p>Orders deduct inventory immediately and insert inventory transaction records. Payment verification can be connected next.</p>
              </div>
              <div class="payment-card">
                <h3>Payment readiness</h3>
                <p>M-Pesa STK, card authorization, and admin reconciliation states are ready in the frontend pattern.</p>
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
