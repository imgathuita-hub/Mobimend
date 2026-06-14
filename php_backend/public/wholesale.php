<?php

declare(strict_types=1);

require __DIR__ . '/session_bootstrap.php';
session_start();

require dirname(__DIR__) . '/src/bootstrap.php';

use Mobimend\Config\Database;
use Mobimend\Services\InventoryLedger;

$pdo = Database::connection();
$message = (string) ($_GET['message'] ?? '');
$tone = (string) ($_GET['tone'] ?? 'info');
$selectedBrand = trim((string) ($_GET['brand'] ?? ''));
$createdOrder = null;
$_SESSION['wholesale_cart'] ??= [];

function wholesale_product_column_exists(PDO $pdo, string $column): bool
{
    static $cache = [];
    if (array_key_exists($column, $cache)) {
        return $cache[$column];
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = "products"
           AND COLUMN_NAME = :column'
    );
    $stmt->execute(['column' => $column]);
    $cache[$column] = (int) $stmt->fetchColumn() > 0;
    return $cache[$column];
}

function wholesale_redirect(string $message, string $tone = 'success'): never
{
    header('Location: wholesale.php?message=' . urlencode($message) . '&tone=' . urlencode($tone) . '#cart');
    exit;
}

function product_image_url(?string $mediaUrl): string
{
    $mediaUrl = trim((string) $mediaUrl);
    if ($mediaUrl === '') {
        $mediaUrl = 'assets/LOGO FINAL MOBIMEND WH BG.png';
    }
    if (preg_match('/^https?:\/\//i', $mediaUrl) === 1 || str_starts_with($mediaUrl, '/')) {
        return $mediaUrl;
    }

    $version = '';
    $localPath = __DIR__ . '/' . ltrim($mediaUrl, '/');
    if (is_file($localPath)) {
        $version = '?v=' . filemtime($localPath);
    }

    return rtrim((string) env('APP_URL', ''), '/') . '/' . ltrim($mediaUrl, '/') . $version;
}

$hasCatalogChannel = wholesale_product_column_exists($pdo, 'catalog_channel');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'checkout');

    if ($action === 'add_cart') {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $quantity = max(1, (int) ($_POST['quantity'] ?? 1));
        $channelFilter = $hasCatalogChannel ? ' AND p.catalog_channel IN ("wholesale", "both")' : '';
        $stmt = $pdo->prepare(
            'SELECT ii.*, p.minimum_wholesale_quantity
             FROM inventory_items ii
             INNER JOIN product_variants pv ON pv.id = ii.product_variant_id
             INNER JOIN products p ON p.id = pv.product_id
             WHERE ii.id = :id AND ii.quantity > 0 AND pv.is_active = 1 AND p.status IN ("active", "out_of_stock")' . $channelFilter . '
             LIMIT 1'
        );
        $stmt->execute(['id' => $itemId]);
        $item = $stmt->fetch();
        if (!$item) {
            wholesale_redirect('That part is not available for wholesale.', 'error');
        }
        $moq = max(1, (int) ($item['minimum_wholesale_quantity'] ?? 5));
        $quantity = max($moq, $quantity);
        $_SESSION['wholesale_cart'][$itemId] = min((int) $item['quantity'], (int) ($_SESSION['wholesale_cart'][$itemId] ?? 0) + $quantity);
        wholesale_redirect((string) $item['part_type'] . ' added to wholesale cart.');
    }

    if ($action === 'update_cart') {
        $quantities = $_POST['cart_quantities'] ?? [];
        $nextCart = [];
        if (is_array($quantities)) {
            foreach ($quantities as $itemId => $quantity) {
                $itemId = (int) $itemId;
                $quantity = max(0, (int) $quantity);
                if ($itemId > 0 && $quantity > 0) {
                    $nextCart[$itemId] = $quantity;
                }
            }
        }
        $_SESSION['wholesale_cart'] = $nextCart;
        wholesale_redirect('Wholesale cart updated.');
    }

    if ($action === 'remove_cart') {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        unset($_SESSION['wholesale_cart'][$itemId]);
        wholesale_redirect('Item removed from wholesale cart.');
    }

    $quantities = $_SESSION['wholesale_cart'] ?? [];
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
                $channelFilter = $hasCatalogChannel ? ' AND p.catalog_channel IN ("wholesale", "both")' : '';
                $stmt = $pdo->prepare(
                    'SELECT ii.*, pv.product_id, pv.sku, pv.stock_quantity, p.name AS product_name, p.minimum_wholesale_quantity
                     FROM inventory_items ii
                     INNER JOIN product_variants pv ON pv.id = ii.product_variant_id
                     INNER JOIN products p ON p.id = pv.product_id
                     WHERE ii.id = :id AND pv.is_active = 1 AND p.status IN ("active", "out_of_stock")' . $channelFilter . '
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
                $reorderPoint = (int) ($item['reorder_point'] ?? $item['low_stock_threshold'] ?? 0);
                $update = $pdo->prepare('UPDATE inventory_items SET quantity = :quantity, low_stock = :low_stock, status = :status, updated_at = :updated_at WHERE id = :id');
                $update->execute([
                    'id' => (int) $item['id'],
                    'quantity' => $nextQuantity,
                    'low_stock' => $reorderPoint > 0 && $nextQuantity <= $reorderPoint ? 1 : 0,
                    'status' => $nextQuantity > 0 ? 'in_stock' : 'sold_out',
                    'updated_at' => now(),
                ]);

                if (!empty($item['product_variant_id'])) {
                    $variantStock = max(0, (int) ($item['stock_quantity'] ?? $item['quantity']) - $quantity);
                    $variantUpdate = $pdo->prepare('UPDATE product_variants SET stock_quantity = :stock, low_stock = :low_stock, updated_at = :updated_at WHERE id = :id');
                    $variantUpdate->execute([
                        'stock' => $variantStock,
                        'low_stock' => $reorderPoint > 0 && $variantStock <= $reorderPoint ? 1 : 0,
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

                $movement = [
                    'inventory_item_id' => (int) $item['id'],
                    'product_variant_id' => !empty($item['product_variant_id']) ? (int) $item['product_variant_id'] : null,
                    'order_item_id' => $orderItemId,
                    'brand' => $item['brand'],
                    'model' => $item['model'],
                    'part_type' => $item['part_type'],
                    'movement_type' => 'fulfill',
                    'source' => 'wholesale_checkout',
                    'quantity_delta' => -$quantity,
                    'previous_quantity' => (int) $item['quantity'],
                    'new_quantity' => $nextQuantity,
                    'unit_buy_price' => (float) $line['unit_buy_price'],
                    'unit_sell_price' => (float) $line['unit_sell_price'],
                    'total_cost' => (float) $line['unit_buy_price'] * $quantity,
                    'total_revenue' => (float) $line['line_revenue'],
                    'profit' => ((float) $line['unit_sell_price'] - (float) $line['unit_buy_price']) * $quantity,
                    'reason' => 'Wholesale checkout ' . $orderNumber,
                ];
                InventoryLedger::recordMovement($pdo, $movement);
                InventoryLedger::mirrorTransaction($pdo, $movement);
                InventoryLedger::enqueueReorderAlert($pdo, [
                    'inventory_item_id' => (int) $item['id'],
                    'product_variant_id' => !empty($item['product_variant_id']) ? (int) $item['product_variant_id'] : null,
                    'brand' => (string) $item['brand'],
                    'model' => (string) $item['model'],
                    'part_type' => (string) $item['part_type'],
                    'quantity' => $nextQuantity,
                    'reorder_point' => (int) ($item['reorder_point'] ?? $item['low_stock_threshold'] ?? 0),
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
            $paymentId = (int) $pdo->lastInsertId();

            $pdo->commit();
            $_SESSION['wholesale_cart'] = [];
            $createdOrder = [
                'number' => $orderNumber,
                'total' => $totalRevenue,
                'payment_id' => $paymentId,
                'payment_method' => $paymentMethod,
                'phone' => $buyerPhone,
                'units' => $updatedCount,
            ];
            $message = $paymentMethod === 'mpesa_stk'
                ? 'Wholesale order ' . $orderNumber . ' created. Sending M-Pesa prompt now.'
                : 'Wholesale order ' . $orderNumber . ' created for ' . $updatedCount . ' units. Total value: KES ' . number_format($totalRevenue, 2) . '.';
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

$brandChannelJoin = 'INNER JOIN product_variants pv ON pv.id = ii.product_variant_id INNER JOIN products p ON p.id = pv.product_id';
$brandChannelWhere = $hasCatalogChannel ? ' WHERE pv.is_active = 1 AND p.status IN ("active", "out_of_stock") AND p.catalog_channel IN ("wholesale", "both")' : ' WHERE pv.is_active = 1 AND p.status IN ("active", "out_of_stock")';
$brandsStmt = $pdo->query('SELECT DISTINCT ii.brand FROM inventory_items ii ' . $brandChannelJoin . $brandChannelWhere . ' ORDER BY ii.brand ASC');
$brands = array_map(static fn (array $row): string => (string) $row['brand'], $brandsStmt->fetchAll());

$catalogChannelSelect = $hasCatalogChannel ? 'p.catalog_channel' : '"wholesale" AS catalog_channel';
$sql = 'SELECT ii.*, p.minimum_wholesale_quantity, p.media_url, p.name AS product_name, ' . $catalogChannelSelect . ', pv.sku
        FROM inventory_items ii
        INNER JOIN product_variants pv ON pv.id = ii.product_variant_id
        INNER JOIN products p ON p.id = pv.product_id
        WHERE ii.quantity > 0 AND pv.is_active = 1 AND p.status IN ("active", "out_of_stock")';
$params = [];
if ($hasCatalogChannel) {
    $sql .= ' AND p.catalog_channel IN ("wholesale", "both")';
}
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
$wholesaleCart = is_array($_SESSION['wholesale_cart'] ?? null) ? $_SESSION['wholesale_cart'] : [];
$cartItems = [];
$cartQuantity = 0;
$cartTotal = 0.0;
if ($wholesaleCart !== []) {
    $ids = array_values(array_filter(array_map('intval', array_keys($wholesaleCart)), static fn (int $id): bool => $id > 0));
    if ($ids !== []) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $channelFilter = $hasCatalogChannel ? ' AND p.catalog_channel IN ("wholesale", "both")' : '';
        $stmt = $pdo->prepare(
            'SELECT ii.*, p.minimum_wholesale_quantity, p.media_url, p.name AS product_name, pv.sku
             FROM inventory_items ii
             INNER JOIN product_variants pv ON pv.id = ii.product_variant_id
             INNER JOIN products p ON p.id = pv.product_id
             WHERE ii.id IN (' . $placeholders . ') AND ii.quantity > 0 AND pv.is_active = 1 AND p.status IN ("active", "out_of_stock")' . $channelFilter
        );
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $row) {
            $moq = max(1, (int) ($row['minimum_wholesale_quantity'] ?? 5));
            $quantity = min(max($moq, (int) ($wholesaleCart[(int) $row['id']] ?? $moq)), max(0, (int) $row['quantity']));
            if ($quantity < $moq) {
                continue;
            }
            $unitPrice = (float) $row['wholesale_price'] > 0 ? (float) $row['wholesale_price'] : (float) $row['sell_price'];
            $row['cart_quantity'] = $quantity;
            $row['cart_unit_price'] = $unitPrice;
            $row['cart_line_total'] = $unitPrice * $quantity;
            $cartQuantity += $quantity;
            $cartTotal += (float) $row['cart_line_total'];
            $cartItems[] = $row;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Wholesale Parts | Mobimend Spares</title>
  <meta name="description" content="Wholesale phone spare parts — screens, batteries, charging ports and more. MOQ-aware ordering with live inventory for repair shops and resellers.">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&family=Montserrat:wght@700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="style.css">
  <link rel="stylesheet" href="shop_overhaul.css">
</head>
<body>

  <!-- ── Nav ──────────────────────────────────────────────────── -->
  <nav class="site-nav">
    <a class="nav-left" href="index.php">
      <img src="assets/LOGO FINAL MOBIMEND WH BG.png" alt="Mobimend Spares" class="logo">
      <div class="brand"><h1>MOBIMEND</h1><p class="tagline">Wholesale desk</p></div>
    </a>
    <button class="menu-toggle" id="menu-toggle" aria-expanded="false" aria-label="Toggle navigation">
      <i class="fa-solid fa-bars"></i>
    </button>
    <ul class="nav-links" id="nav-links">
      <li><a href="index.php">Home</a></li>
      <li><a href="repair.php">Repair</a></li>
      <li><a href="accessories.php">Shop</a></li>
      <li><a class="active" href="wholesale.php">Wholesale</a></li>
      <li><a href="blog.php">Blog</a></li>
      <li><a href="track.php">Track</a></li>
      <li><a href="account.php">Account</a></li>
      <li>
        <button class="nav-cart-btn" id="cartDrawerToggle" type="button" aria-label="View bulk cart">
          <i class="fa-solid fa-cart-flatbed"></i>
          Bulk cart
          <span class="nav-cart-badge" id="navCartBadge"
                data-count="<?= $cartQuantity ?>"><?= $cartQuantity > 0 ? $cartQuantity : '' ?></span>
        </button>
      </li>
    </ul>
  </nav>

  <!-- ── Hero ──────────────────────────────────────────────────── -->
  <header class="wholesale-hero-v2">
    <div class="wholesale-hero-inner">
      <p class="section-kicker" style="color:rgba(255,255,255,.9)">
        <i class="fa-solid fa-network-wired"></i> Product-linked wholesale desk
      </p>
      <h1 class="section-title" style="color:#fff">
        Bulk parts supply with a builder's heartbeat.
      </h1>
      <p class="section-copy">
        Source admin-approved parts with live stock, MOQ-aware quantities, and checkout that records inventory movement the moment an order is placed.
      </p>
      <div class="wholesale-hero-actions">
        <a class="wholesale-hero-primary" href="#live-catalog">
          <i class="fa-solid fa-boxes-stacked"></i> Browse wholesale stock
        </a>
        <a class="wholesale-hero-secondary" href="accessories.php">
          <i class="fa-solid fa-store"></i> Retail shop
        </a>
      </div>
      <div class="wholesale-stats">
        <div class="wholesale-stat">
          <strong><?= number_format($totalUnits) ?></strong>
          <span>units in stock</span>
        </div>
        <div class="wholesale-stat">
          <strong><?= number_format($activeBrands) ?></strong>
          <span>active brands</span>
        </div>
        <div class="wholesale-stat">
          <strong>MOQ-led</strong>
          <span>bulk tier</span>
        </div>
        <div class="wholesale-stat">
          <strong>Live</strong>
          <span>real-time stock</span>
        </div>
      </div>
    </div>
  </header>

  <!-- ── Alert ─────────────────────────────────────────────────── -->
  <?php if ($message !== ''): ?>
    <div class="alert-banner <?= htmlspecialchars($tone) ?>" id="live-catalog">
      <i class="fa-solid <?= $tone === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check' ?>"></i>
      <?= htmlspecialchars($message) ?>
      <?php if ($createdOrder && ($createdOrder['payment_method'] ?? '') === 'mpesa_stk'): ?>
        <span data-mpesa-status style="margin-left:10px">Preparing M-Pesa prompt...</span>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($createdOrder && ($createdOrder['payment_method'] ?? '') === 'mpesa_stk'): ?>
    <div
      data-mpesa-checkout
      data-auto-start="1"
      data-phone="<?= htmlspecialchars((string) $createdOrder['phone'], ENT_QUOTES, 'UTF-8') ?>"
      data-amount="<?= htmlspecialchars((string) $createdOrder['total'], ENT_QUOTES, 'UTF-8') ?>"
      data-reference="<?= htmlspecialchars((string) $createdOrder['number'], ENT_QUOTES, 'UTF-8') ?>"
      data-payment-id="<?= (int) $createdOrder['payment_id'] ?>"
      data-success-url="track.php"
      hidden></div>
  <?php endif; ?>

  <!-- ── Main layout ───────────────────────────────────────────── -->
  <main id="live-catalog">
    <div class="cart-drawer-overlay" id="cartDrawerOverlay"></div>

    <div class="wholesale-layout-v2">

      <!-- ── Sidebar ─────────────────────────────────────────────── -->
      <aside class="wholesale-sidebar">

        <!-- Brand filter -->
        <div class="sidebar-section">
          <h4>Filter by brand</h4>
          <div class="brand-pill-list">
            <a class="brand-pill <?= $selectedBrand === '' ? 'active' : '' ?>"
               href="wholesale.php#live-catalog">
              All brands
            </a>
            <?php foreach ($brands as $brand): ?>
              <a class="brand-pill <?= $selectedBrand === $brand ? 'active' : '' ?>"
                 href="wholesale.php?brand=<?= urlencode($brand) ?>#live-catalog">
                <?= htmlspecialchars($brand) ?>
                <span class="brand-count">
                  <?= count(array_filter($items, fn($i) => $i['brand'] === $brand)) ?>
                </span>
              </a>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Pricing tiers -->
        <div class="sidebar-section">
          <h4>Pricing tiers</h4>
          <div class="tier-grid">
            <div class="tier-row">
              <span class="tier-label">1 – 4 units</span>
              <span class="tier-badge retail">Retail</span>
            </div>
            <div class="tier-row">
              <span class="tier-label">5 – 19 units</span>
              <span class="tier-badge reseller">Reseller</span>
            </div>
            <div class="tier-row">
              <span class="tier-label">20+ units</span>
              <span class="tier-badge distrib">Distributor</span>
            </div>
          </div>
          <a class="btn-ghost" href="contact.php"
             style="margin-top:14px;display:inline-flex;align-items:center;gap:7px;font-size:.84rem">
            <i class="fa-solid fa-envelope"></i> Request special pricing
          </a>
        </div>

        <!-- Bulk cart -->
      </aside>

      <!-- ── Catalog ─────────────────────────────────────────────── -->
      <section aria-label="Wholesale catalog">
        <div class="w-catalog-head">
          <h2>
            <?= $selectedBrand !== '' ? htmlspecialchars($selectedBrand) . ' parts' : 'All spare parts' ?>
            <span style="font-size:.85rem;font-weight:400;color:var(--shop-muted);margin-left:8px">
              <?= count($items) ?> available
            </span>
          </h2>
        </div>

        <div class="w-catalog-grid">
          <?php if ($items === []): ?>
            <div class="w-empty">
              <i class="fa-solid fa-box-open" style="font-size:2rem;color:var(--shop-line);display:block;margin-bottom:12px"></i>
              <p>No stock for this filter yet. <a href="wholesale.php">Clear filter</a></p>
            </div>
          <?php endif; ?>

          <?php foreach ($items as $item):
            $unitPrice = (float)$item['wholesale_price'] > 0
              ? (float)$item['wholesale_price']
              : (float)$item['sell_price'];
            $moq = max(1, (int)($item['minimum_wholesale_quantity'] ?? 5));
            $imgUrl = $item['media_url'] ?? null;
            $partIcons = [
              'LCD'        => 'fa-display',
              'Screen'     => 'fa-display',
              'Battery'    => 'fa-battery-three-quarters',
              'Charging'   => 'fa-plug',
              'Speaker'    => 'fa-volume-high',
              'Earpiece'   => 'fa-headphones',
              'Camera'     => 'fa-camera',
              'Back Cover' => 'fa-mobile-screen',
              'Chassis'    => 'fa-mobile-screen',
            ];
            $iconKey  = 'fa-microchip';
            foreach ($partIcons as $k => $v) {
              if (stripos((string)$item['part_type'], $k) !== false) { $iconKey = $v; break; }
            }
          ?>
            <article class="w-part-card">
              <div class="w-part-img">
                <?php if ($imgUrl): ?>
                  <img src="<?= htmlspecialchars(product_image_url($imgUrl)) ?>"
                       alt="<?= htmlspecialchars((string)$item['part_type']) ?>"
                       loading="lazy">
                <?php else: ?>
                  <i class="fa-solid <?= $iconKey ?> part-icon"></i>
                <?php endif; ?>
                <?php if ((int)$item['quantity'] <= 10): ?>
                  <span class="stock-badge low-stock" style="position:absolute;top:8px;left:8px">
                    Low stock
                  </span>
                <?php endif; ?>
              </div>
              <div class="w-part-body">
                <div class="w-part-brand"><?= htmlspecialchars((string)$item['brand']) ?></div>
                <div class="w-part-name"><?= htmlspecialchars((string)($item['product_name'] ?: $item['part_type'])) ?></div>
                <div class="w-part-model"><?= htmlspecialchars((string)$item['model']) ?></div>
                <?php if (trim((string)($item['sku'] ?? '')) !== ''): ?>
                  <div class="w-part-sku">SKU <?= htmlspecialchars((string)$item['sku']) ?></div>
                <?php endif; ?>
                <div class="w-part-meta">
                  <span class="w-part-price">KES <?= number_format($unitPrice, 2) ?></span>
                  <span class="w-part-stock"><?= number_format((int)$item['quantity']) ?> units</span>
                </div>
                <div class="moq-tag">
                  <i class="fa-solid fa-layer-group" style="font-size:.7rem"></i>
                  Min. order: <?= $moq ?> units
                </div>
                <form method="post" action="wholesale.php#cart" class="w-add-form">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action"  value="add_cart">
                  <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
                  <input class="w-qty-input"
                         type="number"
                         min="<?= $moq ?>"
                         max="<?= (int)$item['quantity'] ?>"
                         name="quantity"
                         value="<?= $moq ?>">
                  <button class="w-add-btn" type="submit">
                    <i class="fa-solid fa-plus"></i> Add
                  </button>
                </form>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      </section>

    </div><!-- end wholesale-layout-v2 -->

    <!-- ── Sticky bulk cart bar ─────────────────────────────────── -->
    <aside class="cart-v2" id="cartDrawer" aria-label="Wholesale cart" aria-hidden="true">
      <div class="cart-v2-head">
        <h2 class="cart-v2-title">
          <i class="fa-solid fa-cart-flatbed"></i>
          Bulk cart
        </h2>
        <div class="cart-v2-head-right">
          <span class="cart-v2-count" id="cartCountBadge"><?= $cartQuantity ?></span>
          <button class="cart-close-btn" id="cartDrawerClose" aria-label="Close cart">
            <i class="fa-solid fa-xmark"></i>
          </button>
        </div>
      </div>

      <div class="cart-v2-body">
        <?php if ($cartItems === []): ?>
          <div class="cart-v2-empty">
            <i class="fa-solid fa-box-open"></i>
            <p>Your bulk cart is empty.<br>Add MOQ quantities from the catalog.</p>
          </div>
        <?php else: ?>
          <form method="post" action="wholesale.php" id="updateWholesaleCartForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_cart">
            <?php foreach ($cartItems as $item): ?>
              <?php $itemName = trim((string)($item['product_name'] ?: $item['brand'] . ' ' . $item['model'] . ' ' . $item['part_type'])); ?>
              <div class="cart-line-v2 wholesale-cart-line">
                <img src="<?= htmlspecialchars(product_image_url($item['media_url'] ?? null)) ?>"
                     alt="<?= htmlspecialchars($itemName) ?>"
                     onerror="this.style.background='#eaf7fb'">
                <div>
                  <div class="line-name"><?= htmlspecialchars($itemName) ?></div>
                  <div class="line-price">
                    KES <?= number_format((float)$item['cart_unit_price'], 2) ?> each
                    <span class="line-moq">MOQ <?= max(1, (int)($item['minimum_wholesale_quantity'] ?? 5)) ?></span>
                  </div>
                </div>
                <input class="cart-qty-input"
                       type="number"
                       min="0"
                       max="<?= (int)$item['quantity'] ?>"
                       name="cart_quantities[<?= (int)$item['id'] ?>]"
                       value="<?= (int)$item['cart_quantity'] ?>">
                <button class="cart-remove"
                        type="submit"
                        form="remove-wholesale-<?= (int)$item['id'] ?>"
                        aria-label="Remove <?= htmlspecialchars($itemName) ?>">
                  <i class="fa-solid fa-trash-can"></i>
                </button>
              </div>
            <?php endforeach; ?>
          </form>

          <?php foreach ($cartItems as $item): ?>
            <form id="remove-wholesale-<?= (int)$item['id'] ?>" method="post" action="wholesale.php" hidden>
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="remove_cart">
              <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
            </form>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <?php if ($cartItems !== []): ?>
        <div class="cart-v2-totals">
          <div class="cart-total-row"><span>Lines</span><span><?= count($cartItems) ?></span></div>
          <div class="cart-total-row"><span>Units</span><span><?= number_format($cartQuantity) ?></span></div>
          <div class="cart-total-row grand"><span>Total</span><span>KES <?= number_format($cartTotal, 2) ?></span></div>
        </div>
        <div class="cart-v2-actions">
          <a class="cart-checkout-btn" href="#wholesale-checkout" id="cartGoCheckout">
            <i class="fa-solid fa-lock"></i> Checkout - KES <?= number_format($cartTotal, 2) ?>
          </a>
          <button class="cart-update-btn" type="submit" form="updateWholesaleCartForm">
            <i class="fa-solid fa-rotate"></i> Update cart
          </button>
        </div>
      <?php endif; ?>
    </aside>

    <!-- ── Wholesale checkout ──────────────────────────────────── -->
    <section class="w-checkout-section" id="wholesale-checkout">
      <div class="w-checkout-inner">
        <p class="section-kicker"><i class="fa-solid fa-file-invoice"></i> Bulk checkout</p>
        <h2 class="section-title">Submit your wholesale order.</h2>

        <form method="post" action="wholesale.php#wholesale-checkout" class="w-checkout-grid">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="checkout">

          <div style="display:grid;gap:20px">

            <!-- Buyer details -->
            <div class="checkout-card">
              <h3><i class="fa-solid fa-building"></i> Buyer details</h3>
              <div class="form-row-v2">
                <div class="form-field-v2">
                  <label>Contact name *</label>
                  <input class="form-input-v2" name="buyer_name" placeholder="Jane Buyer" required>
                </div>
                <div class="form-field-v2">
                  <label>Phone number *</label>
                  <input class="form-input-v2" name="buyer_phone" placeholder="07XX XXX XXX" required>
                </div>
              </div>
              <div class="form-row-v2">
                <div class="form-field-v2">
                  <label>Email address</label>
                  <input class="form-input-v2" type="email" name="buyer_email" placeholder="buyer@business.com">
                </div>
                <div class="form-field-v2">
                  <label>Business name</label>
                  <input class="form-input-v2" name="business_name" placeholder="Repair shop or reseller name">
                </div>
              </div>
              <div class="form-row-v2 full">
                <div class="form-field-v2">
                  <label>Delivery / pickup notes</label>
                  <input class="form-input-v2" name="delivery_address"
                         placeholder="Pickup at Juja, rider dispatch address, or courier instructions">
                </div>
              </div>
            </div>

            <!-- Payment -->
            <div class="checkout-card">
              <h3><i class="fa-solid fa-wallet"></i> Payment method</h3>
              <div class="payment-options-v2">
                <label class="pay-option">
                  <input type="radio" name="payment_method" value="mpesa_stk" checked>
                  <div class="pay-icon"><i class="fa-solid fa-mobile-screen-button"></i></div>
                  <div>
                    <div class="pay-label">M-Pesa STK Push</div>
                    <div class="pay-desc">Get a prompt on your phone to enter PIN</div>
                  </div>
                  <div class="pay-check"></div>
                </label>
                <label class="pay-option">
                  <input type="radio" name="payment_method" value="bank_transfer">
                  <div class="pay-icon"><i class="fa-solid fa-building-columns"></i></div>
                  <div>
                    <div class="pay-label">Bank transfer</div>
                    <div class="pay-desc">EFT or RTGS — details shared after order</div>
                  </div>
                  <div class="pay-check"></div>
                </label>
                <label class="pay-option">
                  <input type="radio" name="payment_method" value="cash">
                  <div class="pay-icon"><i class="fa-solid fa-money-bill-wave"></i></div>
                  <div>
                    <div class="pay-label">Cash on pickup</div>
                    <div class="pay-desc">Pay at Juja shop on collection</div>
                  </div>
                  <div class="pay-check"></div>
                </label>
              </div>
            </div>
          </div>

          <!-- Order summary -->
          <div class="summary-card">
            <h3>Bulk order summary</h3>
            <?php if ($cartItems === []): ?>
              <p style="color:var(--shop-muted);font-size:.88rem">Add parts from the catalog to continue.</p>
            <?php else: ?>
              <div class="summary-lines">
                <?php foreach ($cartItems as $ci): ?>
                  <div class="summary-line">
                    <span class="line-label">
                      <?= htmlspecialchars((string)$ci['brand']) ?>
                      <?= htmlspecialchars((string)$ci['part_type']) ?>
                      × <?= (int)$ci['cart_quantity'] ?>
                    </span>
                    <span class="line-val">KES <?= number_format((float)$ci['cart_line_total'], 2) ?></span>
                  </div>
                <?php endforeach; ?>
              </div>
              <div class="summary-divider"></div>
              <div class="summary-grand">
                <span>Total</span>
                <span>KES <?= number_format($cartTotal, 2) ?></span>
              </div>
            <?php endif; ?>
            <p style="font-size:.78rem;color:var(--shop-muted);margin:0 0 16px">
              Stock is deducted immediately on order. Payments are saved as pending for admin reconciliation.
            </p>
            <button class="submit-order-btn" type="submit" id="pay-btn" data-mpesa-trigger <?= $cartItems === [] ? 'disabled' : '' ?>>
              <i class="fa-solid fa-lock"></i>
              <?= $cartItems === [] ? 'Build your cart first' : 'Submit wholesale order' ?>
            </button>
          </div>
        </form>
      </div>
    </section>
  </main>

  <!-- ── Footer ──────────────────────────────────────────────────── -->
  <footer class="footer">
    <div class="container footer-grid">
      <div>
        <h3>Mobimend Wholesale</h3>
        <p>MOQ-aware spare parts supply for repair shops and resellers across Kenya.</p>
      </div>
      <div><h3>Catalog</h3><ul><li><a href="wholesale.php">All parts</a></li><li><a href="accessories.php">Retail shop</a></li><li><a href="repair.php">Book repair</a></li></ul></div>
      <div><h3>Support</h3><ul><li><a href="track.php">Track order</a></li><li><a href="contact.php">Contact team</a></li><li><a href="blog.php">Repair guides</a></li></ul></div>
      <div><h3>Juja</h3><ul><li>Mum &amp; Dad Business Centre, Stall 9E</li><li>0799 183 907</li><li>mobimendspares@gmail.com</li></ul></div>
    </div>
    <div class="container footer-bottom">&copy; 2026 Mobimend Spares. All rights reserved.</div>
  </footer>

  <script src="chatbot.js"></script>
  <script src="site.js"></script>
  <script src="mpesa_checkout.js"></script>
</body>
</html>
