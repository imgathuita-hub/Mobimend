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
$selectedCategory = (int) ($_GET['category'] ?? 0);
$search = trim((string) ($_GET['q'] ?? ''));
$deliveryOption = (string) ($_POST['delivery_option'] ?? 'pickup');
$createdOrder = null;
$_SESSION['shop_cart'] ??= [];

function shop_product_column_exists(PDO $pdo, string $column): bool
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

function shop_ensure_catalog_channel(PDO $pdo): void
{
    if (shop_product_column_exists($pdo, 'catalog_channel')) {
        return;
    }

    $pdo->exec(
        'ALTER TABLE products
         ADD COLUMN catalog_channel ENUM("shop", "wholesale", "both") NOT NULL DEFAULT "shop"
         AFTER minimum_wholesale_quantity'
    );
}

function shop_redirect(string $message, string $tone = 'success'): never
{
    header('Location: accessories.php?message=' . urlencode($message) . '&tone=' . urlencode($tone) . '#cart');
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

function shop_delivery_fee(string $deliveryOption): float
{
    return match ($deliveryOption) {
        'local_delivery' => 250.0,
        'courier_delivery' => 450.0,
        default => 0.0,
    };
}

function shop_delivery_label(string $deliveryOption): string
{
    return match ($deliveryOption) {
        'local_delivery' => 'Local delivery',
        'courier_delivery' => 'Courier delivery',
        default => 'Pickup at Juja shop',
    };
}

shop_ensure_catalog_channel($pdo);
$hasCatalogChannel = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'checkout');

    if ($action === 'add_cart') {
        $variantId = (int) ($_POST['variant_id'] ?? 0);
        $quantity = max(1, (int) ($_POST['quantity'] ?? 1));
        $channelFilter = $hasCatalogChannel ? ' AND p.catalog_channel IN ("shop", "both")' : '';
        $stmt = $pdo->prepare(
            'SELECT pv.stock_quantity, p.name
             FROM product_variants pv
             INNER JOIN products p ON p.id = pv.product_id
             WHERE pv.id = :id AND pv.is_active = 1 AND p.status IN ("active", "out_of_stock")' . $channelFilter . '
             LIMIT 1'
        );
        $stmt->execute(['id' => $variantId]);
        $variant = $stmt->fetch();
        if (!$variant || (int) $variant['stock_quantity'] <= 0) {
            shop_redirect('That product is not available for the shop cart.', 'error');
        }
        $_SESSION['shop_cart'][$variantId] = min((int) $variant['stock_quantity'], (int) ($_SESSION['shop_cart'][$variantId] ?? 0) + $quantity);
        shop_redirect((string) $variant['name'] . ' added to cart.');
    }

    if ($action === 'update_cart') {
        $quantities = $_POST['cart_quantities'] ?? [];
        $nextCart = [];
        if (is_array($quantities)) {
            foreach ($quantities as $variantId => $quantity) {
                $variantId = (int) $variantId;
                $quantity = max(0, (int) $quantity);
                if ($variantId > 0 && $quantity > 0) {
                    $nextCart[$variantId] = $quantity;
                }
            }
        }
        $_SESSION['shop_cart'] = $nextCart;
        shop_redirect('Cart updated.');
    }

    if ($action === 'remove_cart') {
        $variantId = (int) ($_POST['variant_id'] ?? 0);
        unset($_SESSION['shop_cart'][$variantId]);
        shop_redirect('Item removed from cart.');
    }

    $quantities = $_SESSION['shop_cart'] ?? [];
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
    $deliveryOption = (string) ($_POST['delivery_option'] ?? 'pickup');
    if (!in_array($deliveryOption, ['pickup', 'local_delivery', 'courier_delivery'], true)) {
        $deliveryOption = 'pickup';
    }
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
        $channelFilter = $hasCatalogChannel ? ' AND p.catalog_channel IN ("shop", "both")' : '';

        foreach ($selectedItems as $variantId => $quantity) {
            $stmt = $pdo->prepare(
                'SELECT pv.*, p.id AS product_id, p.name, p.brand, p.compatible_brand, p.compatible_model,
                        p.minimum_wholesale_quantity, ii.id AS inventory_item_id, ii.buy_price, ii.reorder_point, ii.low_stock_threshold
                 FROM product_variants pv
                 INNER JOIN products p ON p.id = pv.product_id
                 LEFT JOIN inventory_items ii ON ii.product_variant_id = pv.id
                 WHERE pv.id = :id AND pv.is_active = 1 AND p.status IN ("active", "out_of_stock")' . $channelFilter . '
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

        $deliveryFee = shop_delivery_fee($deliveryOption);
        $grandTotal = $subtotal + $deliveryFee;
        $orderNotes = trim((string) ($_POST['notes'] ?? ''));
        $orderNotes = trim('Delivery option: ' . shop_delivery_label($deliveryOption) . ($orderNotes !== '' ? "\n" . $orderNotes : ''));

        $orderNumber = order_number('SHOP');
        $stmt = $pdo->prepare(
            'INSERT INTO orders
             (order_number, order_type, status, payment_status, subtotal, grand_total,
              delivery_fee, customer_name, customer_email, customer_phone, delivery_address, notes, created_at, updated_at)
             VALUES
             (:order_number, "product", "confirmed", "unpaid", :subtotal, :grand_total,
              :delivery_fee, :customer_name, :customer_email, :customer_phone, :delivery_address, :notes, :created_at, :updated_at)'
        );
        $stmt->execute([
            'order_number' => $orderNumber,
            'subtotal' => $subtotal,
            'grand_total' => $grandTotal,
            'delivery_fee' => $deliveryFee,
            'customer_name' => $customerName,
            'customer_email' => $customerEmail,
            'customer_phone' => $customerPhone,
            'delivery_address' => $deliveryAddress,
            'notes' => $orderNotes,
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

            $reorderPoint = (int) ($variant['reorder_point'] ?? $variant['low_stock_threshold'] ?? 0);
            $stmt = $pdo->prepare('UPDATE product_variants SET stock_quantity = :stock, low_stock = :low_stock, updated_at = :updated_at WHERE id = :id');
            $stmt->execute([
                'stock' => $nextStock,
                'low_stock' => $reorderPoint > 0 && $nextStock <= $reorderPoint ? 1 : 0,
                'updated_at' => now(),
                'id' => (int) $variant['id'],
            ]);

            $inventoryItemId = (int) ($variant['inventory_item_id'] ?? 0);
            if ($inventoryItemId <= 0) {
                $stmt = $pdo->prepare(
                    'INSERT INTO inventory_items
                     (product_variant_id, brand, model, part_type, quantity, low_stock_threshold, reorder_point, low_stock, buy_price, sell_price, wholesale_price, status, notes)
                     VALUES
                     (:product_variant_id, :brand, :model, :part_type, :quantity, :low_stock_threshold, :reorder_point, :low_stock, 0, :sell_price, :wholesale_price, :status, :notes)'
                );
                $stmt->execute([
                    'product_variant_id' => (int) $variant['id'],
                    'brand' => (string) ($variant['brand'] ?: $variant['compatible_brand']),
                    'model' => (string) $variant['compatible_model'],
                    'part_type' => (string) $variant['name'],
                    'quantity' => $nextStock,
                    'low_stock_threshold' => (int) $variant['low_stock_threshold'],
                    'reorder_point' => (int) $variant['low_stock_threshold'],
                    'low_stock' => (int) $variant['low_stock_threshold'] > 0 && $nextStock <= (int) $variant['low_stock_threshold'] ? 1 : 0,
                    'sell_price' => (float) $variant['retail_price'],
                    'wholesale_price' => (float) $variant['wholesale_price'],
                    'status' => $nextStock > 0 ? 'in_stock' : 'sold_out',
                    'notes' => 'Auto-created from retail checkout.',
                ]);
                $inventoryItemId = (int) $pdo->lastInsertId();
            } else {
                $stmt = $pdo->prepare('UPDATE inventory_items SET quantity = :stock, low_stock = :low_stock, status = :status, updated_at = :updated_at WHERE id = :id');
                $stmt->execute([
                    'stock' => $nextStock,
                    'low_stock' => $reorderPoint > 0 && $nextStock <= $reorderPoint ? 1 : 0,
                    'status' => $nextStock > 0 ? 'in_stock' : 'sold_out',
                    'updated_at' => now(),
                    'id' => $inventoryItemId,
                ]);
            }

            $buyPrice = (float) ($variant['buy_price'] ?? 0);
            $movement = [
                'inventory_item_id' => $inventoryItemId,
                'product_variant_id' => (int) $variant['id'],
                'order_item_id' => $orderItemId,
                'brand' => (string) ($variant['brand'] ?: $variant['compatible_brand']),
                'model' => (string) $variant['compatible_model'],
                'part_type' => (string) $variant['name'],
                'movement_type' => 'fulfill',
                'source' => 'retail_checkout',
                'quantity_delta' => -$quantity,
                'previous_quantity' => (int) $variant['stock_quantity'],
                'new_quantity' => $nextStock,
                'unit_buy_price' => $buyPrice,
                'unit_sell_price' => (float) $line['unit_price'],
                'total_cost' => $buyPrice * $quantity,
                'total_revenue' => (float) $line['line_total'],
                'profit' => ((float) $line['unit_price'] - $buyPrice) * $quantity,
                'reason' => 'Retail checkout ' . $orderNumber,
            ];
            InventoryLedger::recordMovement($pdo, $movement);
            InventoryLedger::mirrorTransaction($pdo, $movement);
            InventoryLedger::enqueueReorderAlert($pdo, [
                'inventory_item_id' => $inventoryItemId,
                'product_variant_id' => (int) $variant['id'],
                'brand' => (string) ($variant['brand'] ?: $variant['compatible_brand']),
                'model' => (string) $variant['compatible_model'],
                'part_type' => (string) $variant['name'],
                'quantity' => $nextStock,
                'reorder_point' => (int) ($variant['reorder_point'] ?? $variant['low_stock_threshold'] ?? 0),
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
            'amount' => $grandTotal,
            'phone_number' => $customerPhone,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $paymentId = (int) $pdo->lastInsertId();

        $pdo->commit();
        $_SESSION['shop_cart'] = [];
        $createdOrder = [
            'number' => $orderNumber,
            'total' => $grandTotal,
            'payment_id' => $paymentId,
            'payment_method' => $paymentMethod,
            'phone' => $customerPhone,
        ];
        $message = $paymentMethod === 'mpesa_stk'
            ? 'Order ' . $orderNumber . ' created. Sending M-Pesa prompt now.'
            : 'Order ' . $orderNumber . ' created. Payment is pending verification.';
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
$shopCountSql = 'SELECT COUNT(*)
        FROM product_variants pv
        INNER JOIN products p ON p.id = pv.product_id
        WHERE pv.is_active = 1 AND pv.stock_quantity > 0 AND p.status IN ("active", "out_of_stock")';
if ($hasCatalogChannel) {
    $shopCountSql .= ' AND p.catalog_channel IN ("shop", "both")';
}
$totalShopProducts = (int) $pdo->query($shopCountSql)->fetchColumn();
$params = [];
$catalogChannelSelect = $hasCatalogChannel ? 'p.catalog_channel' : '"shop" AS catalog_channel';
$sql = 'SELECT pv.*, pv.stock_quantity AS total_stock, pv.id AS default_variant_id,
               p.name, p.description, p.brand, p.compatible_brand, p.compatible_model, p.minimum_wholesale_quantity, p.media_url, ' . $catalogChannelSelect . ',
               pc.id AS category_id, pc.name AS category_name
        FROM product_variants pv
        INNER JOIN products p ON p.id = pv.product_id
        LEFT JOIN product_categories pc ON pc.id = p.category_id
        WHERE pv.is_active = 1 AND p.status IN ("active", "out_of_stock")';
if ($hasCatalogChannel) {
    $sql .= ' AND p.catalog_channel IN ("shop", "both")';
}
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
$visibleProductCount = count($products);
$filtersActive = $selectedCategory > 0 || $search !== '';

$cartCount = array_reduce($products, static fn (int $sum, array $product): int => $sum + max(0, (int) $product['stock_quantity']), 0);
$shopCart = is_array($_SESSION['shop_cart'] ?? null) ? $_SESSION['shop_cart'] : [];
$cartItems = [];
$cartQuantity = 0;
$cartTotal = 0.0;
if ($shopCart !== []) {
    $ids = array_values(array_filter(array_map('intval', array_keys($shopCart)), static fn (int $id): bool => $id > 0));
    if ($ids !== []) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $channelFilter = $hasCatalogChannel ? ' AND p.catalog_channel IN ("shop", "both")' : '';
        $stmt = $pdo->prepare(
            'SELECT pv.*, p.name, p.media_url, p.brand, p.compatible_brand, p.compatible_model, pc.name AS category_name
             FROM product_variants pv
             INNER JOIN products p ON p.id = pv.product_id
             LEFT JOIN product_categories pc ON pc.id = p.category_id
             WHERE pv.id IN (' . $placeholders . ') AND pv.is_active = 1' . $channelFilter
        );
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $row) {
            $quantity = min(max(1, (int) ($shopCart[(int) $row['id']] ?? 1)), max(0, (int) $row['stock_quantity']));
            if ($quantity <= 0) {
                continue;
            }
            $row['cart_quantity'] = $quantity;
            $row['cart_line_total'] = $quantity * (float) $row['retail_price'];
            $cartQuantity += $quantity;
            $cartTotal += (float) $row['cart_line_total'];
            $cartItems[] = $row;
        }
    }
}
$deliveryFee = $cartItems === [] ? 0.0 : shop_delivery_fee($deliveryOption);
$grandTotal = $cartTotal + $deliveryFee;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Accessories Shop | Mobimend Spares</title>
  <meta name="description" content="Shop phone accessories — cases, chargers, screen protectors, earbuds and more. Fast delivery across Kenya.">
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
      <div class="brand"><h1>MOBIMEND</h1><p class="tagline">Accessories shop</p></div>
    </a>
    <button class="menu-toggle" id="menu-toggle" aria-expanded="false" aria-label="Toggle navigation">
      <i class="fa-solid fa-bars"></i>
    </button>
    <ul class="nav-links" id="nav-links">
      <li><a href="index.php">Home</a></li>
      <li><a href="repair.php">Repair</a></li>
      <li><a class="active" href="accessories.php">Shop</a></li>
      <li><a href="wholesale.php">Wholesale</a></li>
      <li><a href="blog.php">Blog</a></li>
      <li><a href="track.php">Track</a></li>
      <li><a href="account.php">Account</a></li>
      <li>
        <button class="nav-cart-btn" id="cartDrawerToggle" type="button" aria-label="View cart">
          <i class="fa-solid fa-cart-shopping"></i>
          Cart
          <span class="nav-cart-badge" id="navCartBadge"
                data-count="<?= $cartQuantity ?>"><?= $cartQuantity > 0 ? $cartQuantity : '' ?></span>
        </button>
      </li>
    </ul>
  </nav>

  <!-- ── Shop hero ─────────────────────────────────────────────── -->
  <section class="shop-hero">
    <div class="shop-hero-inner">
      <div>
        <p class="section-kicker"><i class="fa-solid fa-microchip"></i> Admin-curated live catalog</p>
        <h1 class="shop-hero-title">Tech essentials tuned for real repairs.</h1>
        <p class="shop-hero-sub shop-hero-live-copy">Admin-added accessories, parts, and device essentials from Mobimend's live inventory.</p>
        <div class="shop-hero-actions">
          <a class="shop-hero-primary" href="#productGrid"><i class="fa-solid fa-bolt"></i> Shop live stock</a>
          <a class="shop-hero-secondary" href="repair.php"><i class="fa-solid fa-screwdriver-wrench"></i> Book repair</a>
        </div>
        <p class="shop-hero-sub">Cases, chargers, protectors, audio — all backed by live stock. Order now, pickup in Juja or get it delivered.</p>
      </div>
      <div class="shop-hero-lab">
        <div class="lab-screen">
          <span class="lab-dot"></span>
          <strong><?= number_format($totalShopProducts) ?></strong>
          <small>sellable variants online</small>
        </div>
        <div class="shop-trust">
        <div class="shop-trust-item"><i class="fa-solid fa-shield-halved"></i> 90-day warranty</div>
        <div class="shop-trust-item"><i class="fa-solid fa-mobile-screen-button"></i> M-Pesa ready</div>
        <div class="shop-trust-item"><i class="fa-solid fa-truck-fast"></i> Same-day local delivery</div>
        <div class="shop-trust-item"><i class="fa-solid fa-circle-check"></i> Live stock only</div>
        </div>
      </div>
    </div>
  </section>

  <!-- ── Alert ─────────────────────────────────────────────────── -->
  <?php if ($message !== ''): ?>
    <div class="alert-banner <?= htmlspecialchars($tone) ?>">
      <i class="fa-solid <?= $tone === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check' ?>"></i>
      <?= htmlspecialchars($message) ?>
    </div>
  <?php endif; ?>

  <?php if ($createdOrder): ?>
    <div class="alert-banner success" style="margin-top:12px">
      <strong>Order <?= htmlspecialchars($createdOrder['number']) ?> confirmed!</strong>
      KES <?= number_format((float)$createdOrder['total'], 2) ?> — stock reserved.
      <?php if (($createdOrder['payment_method'] ?? '') === 'mpesa_stk'): ?>
        <span data-mpesa-status style="margin-left:10px">Preparing M-Pesa prompt...</span>
      <?php endif; ?>
      <a href="track.php" style="margin-left:10px;text-decoration:underline">Track it →</a>
    </div>
    <?php if (($createdOrder['payment_method'] ?? '') === 'mpesa_stk'): ?>
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
  <?php endif; ?>

  <!-- ── Search & filter toolbar ───────────────────────────────── -->
  <form method="get" action="accessories.php">
    <div class="shop-toolbar-v2">
      <div class="shop-search-wrap">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input class="shop-search-input" id="productSearch" name="q" type="search"
               value="<?= htmlspecialchars($search) ?>"
               placeholder="Search by name, brand, compatibility…"
               autocomplete="off">
      </div>
      <select class="shop-select" name="category">
        <option value="0">All categories</option>
        <?php foreach ($categories as $cat): ?>
          <option value="<?= (int)$cat['id'] ?>" <?= $selectedCategory === (int)$cat['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars((string)$cat['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <button class="shop-filter-btn" type="submit">
        <i class="fa-solid fa-sliders"></i> Filter
      </button>
      <?php if ($filtersActive): ?>
        <a class="shop-reset-btn" href="accessories.php">
          <i class="fa-solid fa-xmark"></i> Clear
        </a>
      <?php endif; ?>
    </div>

    <!-- Category pills -->
    <div class="shop-cat-pills">
      <a class="shop-cat-pill <?= $selectedCategory === 0 && $search === '' ? 'active' : '' ?>"
         href="accessories.php">All</a>
      <?php foreach ($categories as $cat): ?>
        <a class="shop-cat-pill <?= $selectedCategory === (int)$cat['id'] ? 'active' : '' ?>"
           href="accessories.php?category=<?= (int)$cat['id'] ?>">
          <?= htmlspecialchars((string)$cat['name']) ?>
        </a>
      <?php endforeach; ?>
    </div>
  </form>

  <!-- ── Main commerce layout ──────────────────────────────────── -->
  <main>
    <!-- Drawer overlay -->
    <div class="cart-drawer-overlay" id="cartDrawerOverlay"></div>

    <div class="shop-layout-v2" style="padding-bottom: 48px;">

      <!-- Product grid -->
      <section aria-label="Product catalog">
        <div class="product-count-bar">
          <p>
            <?php if ($filtersActive): ?>
              <?= number_format(count($products)) ?> result<?= count($products) !== 1 ? 's' : '' ?>
              <?= $search !== '' ? 'for "' . htmlspecialchars($search) . '"' : '' ?>
            <?php else: ?>
              <?= number_format(count($products)) ?> products
            <?php endif; ?>
          </p>
        </div>

        <div class="product-grid-v2" id="productGrid" data-server-products aria-live="polite">
          <?php if ($products === []): ?>
            <div class="shop-empty">
              <i class="fa-regular fa-face-sad-tear"></i>
              <h3><?= $filtersActive ? 'No products match these filters' : 'No accessories yet' ?></h3>
              <p><?= $filtersActive ? 'Try clearing your filters.' : 'Check back soon.' ?></p>
              <?php if ($filtersActive): ?>
                <a class="btn-dark" href="accessories.php" style="margin-top:14px;display:inline-flex;align-items:center;gap:7px">
                  <i class="fa-solid fa-rotate-left"></i> Show all
                </a>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <?php foreach ($products as $product): ?>
            <?php
              $stock    = (int)$product['total_stock'];
              $price    = (float)$product['retail_price'];
              $imgUrl   = product_image_url($product['media_url'] ?? null);
              $fallback = product_image_url(null);
              $varId    = (int)$product['default_variant_id'];

              if ($stock > 10)     { $stockLabel = 'In stock';   $stockClass = 'in-stock';  }
              elseif ($stock > 0)  { $stockLabel = 'Low stock';  $stockClass = 'low-stock'; }
              else                 { $stockLabel = 'Sold out';   $stockClass = 'sold-out';  }
            ?>
            <article class="product-card-v2">
              <div class="card-img">
                <?php if ($product['media_url'] && strpos((string)$product['media_url'], 'LOGO') === false): ?>
                  <img src="<?= htmlspecialchars($imgUrl) ?>"
                       alt="<?= htmlspecialchars((string)$product['name']) ?>"
                       loading="lazy"
                       onerror="this.parentElement.innerHTML='<div class=\"card-img-placeholder\"><i class=\"fa-solid fa-mobile-screen-button\"></i></div>'">
                <?php else: ?>
                  <div class="card-img-placeholder">
                    <i class="fa-solid fa-mobile-screen-button"></i>
                  </div>
                <?php endif; ?>
                <span class="stock-badge <?= $stockClass ?>"><?= $stockLabel ?></span>
              </div>
              <div class="card-body">
                <div class="card-category">
                  <?= htmlspecialchars((string)($product['category_name'] ?? 'Accessories')) ?>
                </div>
                <h3 class="card-name"><?= htmlspecialchars((string)$product['name']) ?></h3>
                <?php if (trim((string)$product['variant_name']) !== ''): ?>
                  <p class="card-variant">
                    <i class="fa-solid fa-code-branch"></i>
                    <?= htmlspecialchars((string)$product['variant_name']) ?>
                  </p>
                <?php endif; ?>
                <?php $compat = trim((string)$product['compatible_brand'] . ' ' . (string)$product['compatible_model']); ?>
                <?php if ($compat !== ''): ?>
                  <p class="card-compat">
                    <i class="fa-solid fa-circle-check" style="color:var(--shop-green);font-size:.8rem"></i>
                    <?= htmlspecialchars($compat) ?>
                  </p>
                <?php endif; ?>
                <?php if (trim((string)$product['sku']) !== ''): ?>
                  <p class="card-sku">SKU <?= htmlspecialchars((string)$product['sku']) ?></p>
                <?php endif; ?>
                <div class="card-footer">
                  <span class="card-price">KES <?= number_format($price, 2) ?></span>
                  <?php if ($stock > 0): ?>
                    <form method="post" action="accessories.php#cart" style="margin:0">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action"     value="add_cart">
                      <input type="hidden" name="variant_id" value="<?= $varId ?>">
                      <input type="hidden" name="quantity"   value="1">
                      <button class="card-add-btn" type="submit">
                        <i class="fa-solid fa-cart-plus"></i> Add
                      </button>
                    </form>
                  <?php else: ?>
                    <button class="card-add-btn" disabled>Sold out</button>
                  <?php endif; ?>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      </section>

      <!-- ── Cart panel ─────────────────────────────────────────── -->
    </div><!-- end shop-layout-v2 -->

    <!-- ── Cart drawer (outside grid — full-height slide-in) ───── -->
    <aside class="cart-v2" id="cartDrawer" aria-label="Shopping cart" aria-hidden="true">
      <div class="cart-v2-head">
        <h2 class="cart-v2-title">
          <i class="fa-solid fa-cart-shopping"></i>
          Your cart
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
            <i class="fa-regular fa-bag-shopping"></i>
            <p>Your cart is empty.<br>Add accessories to get started.</p>
          </div>
        <?php else: ?>
          <form method="post" action="accessories.php" id="updateCartForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_cart">
            <?php foreach ($cartItems as $item): ?>
              <div class="cart-line-v2">
                <img src="<?= htmlspecialchars(product_image_url($item['media_url'] ?? null)) ?>"
                     alt="<?= htmlspecialchars((string)$item['name']) ?>"
                     onerror="this.style.background='#eaf7fb'">
                <div>
                  <div class="line-name"><?= htmlspecialchars((string)$item['name']) ?></div>
                  <div class="line-price">KES <?= number_format((float)$item['retail_price'], 2) ?></div>
                </div>
                <input class="cart-qty-input"
                       type="number"
                       min="0"
                       max="<?= (int)$item['stock_quantity'] ?>"
                       name="cart_quantities[<?= (int)$item['id'] ?>]"
                       value="<?= (int)$item['cart_quantity'] ?>">
                <button class="cart-remove"
                        type="submit"
                        form="remove-<?= (int)$item['id'] ?>"
                        aria-label="Remove <?= htmlspecialchars((string)$item['name']) ?>">
                  <i class="fa-solid fa-trash-can"></i>
                </button>
              </div>
            <?php endforeach; ?>
          </form>

          <?php foreach ($cartItems as $item): ?>
            <form id="remove-<?= (int)$item['id'] ?>" method="post" action="accessories.php" hidden>
              <?= csrf_field() ?>
              <input type="hidden" name="action"     value="remove_cart">
              <input type="hidden" name="variant_id" value="<?= (int)$item['id'] ?>">
            </form>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <?php if ($cartItems !== []): ?>
        <div class="cart-v2-totals">
          <div class="cart-total-row"><span>Subtotal</span><span>KES <?= number_format($cartTotal, 2) ?></span></div>
          <div class="cart-total-row"><span>Delivery</span><span>At checkout</span></div>
          <div class="cart-total-row grand"><span>Est. total</span><span>KES <?= number_format($cartTotal, 2) ?></span></div>
        </div>
        <div class="cart-v2-actions">
          <a class="cart-checkout-btn" href="#checkout" id="cartGoCheckout">
            <i class="fa-solid fa-lock"></i> Checkout — KES <?= number_format($grandTotal, 2) ?>
          </a>
          <button class="cart-update-btn" type="submit" form="updateCartForm">
            <i class="fa-solid fa-rotate"></i> Update cart
          </button>
        </div>
      <?php endif; ?>
    </aside>

    <!-- ── Checkout ──────────────────────────────────────────────── -->
    <section class="checkout-section-v2" id="checkout">
      <div class="checkout-inner">
        <p class="section-kicker"><i class="fa-solid fa-credit-card"></i> Checkout</p>
        <h2 class="section-title">Complete your order.</h2>

        <form method="post" action="accessories.php#checkout" class="checkout-grid-v2">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="checkout">

          <div style="display:grid;gap:20px">

            <!-- Customer details -->
            <div class="checkout-card">
              <h3><i class="fa-solid fa-user"></i> Your details</h3>
              <div class="form-row-v2">
                <div class="form-field-v2">
                  <label>Full name *</label>
                  <input class="form-input-v2" name="customer_name" placeholder="Jane Customer" required>
                </div>
                <div class="form-field-v2">
                  <label>Phone number *</label>
                  <input class="form-input-v2" name="customer_phone" placeholder="07XX XXX XXX" required>
                </div>
              </div>
              <div class="form-row-v2">
                <div class="form-field-v2">
                  <label>Email address</label>
                  <input class="form-input-v2" type="email" name="customer_email" placeholder="jane@example.com">
                </div>
                <div class="form-field-v2">
                  <label>Delivery option</label>
                  <select class="form-select-v2" name="delivery_option" data-delivery-option>
                    <option value="pickup"          <?= $deliveryOption === 'pickup'          ? 'selected' : '' ?>>Pickup at Juja — Free</option>
                    <option value="local_delivery"  <?= $deliveryOption === 'local_delivery'  ? 'selected' : '' ?>>Local delivery — KES 250</option>
                    <option value="courier_delivery"<?= $deliveryOption === 'courier_delivery'? 'selected' : '' ?>>Courier delivery — KES 450</option>
                  </select>
                </div>
              </div>
              <div class="form-row-v2 full">
                <div class="form-field-v2">
                  <label>Delivery address</label>
                  <input class="form-input-v2" name="delivery_address" placeholder="Town, building, rider instructions">
                </div>
              </div>
              <div class="form-row-v2 full">
                <div class="form-field-v2">
                  <label>Order notes</label>
                  <input class="form-input-v2" name="notes" placeholder="Color, exact model variant, timing preference…">
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
                    <div class="pay-desc">Get a prompt on your phone to enter your PIN</div>
                  </div>
                  <div class="pay-check"></div>
                </label>
                <label class="pay-option">
                  <input type="radio" name="payment_method" value="cash">
                  <div class="pay-icon"><i class="fa-solid fa-money-bill-wave"></i></div>
                  <div>
                    <div class="pay-label">Pay on pickup</div>
                    <div class="pay-desc">Cash or M-Pesa when you collect at Juja</div>
                  </div>
                  <div class="pay-check"></div>
                </label>
                <label class="pay-option">
                  <input type="radio" name="payment_method" value="card">
                  <div class="pay-icon"><i class="fa-solid fa-credit-card"></i></div>
                  <div>
                    <div class="pay-label">Card payment</div>
                    <div class="pay-desc">Visa / Mastercard</div>
                  </div>
                  <div class="pay-check"></div>
                </label>
              </div>
            </div>
          </div>

          <!-- Order summary -->
          <div class="summary-card">
            <h3>Order summary</h3>
            <?php if ($cartItems === []): ?>
              <p style="color:var(--shop-muted);font-size:.9rem">Add items to your cart first.</p>
            <?php else: ?>
              <div class="summary-lines">
                <?php foreach ($cartItems as $item): ?>
                  <div class="summary-line">
                    <span class="line-label">
                      <?= htmlspecialchars((string)$item['name']) ?> × <?= (int)$item['cart_quantity'] ?>
                    </span>
                    <span class="line-val">KES <?= number_format((float)$item['cart_line_total'], 2) ?></span>
                  </div>
                <?php endforeach; ?>
              </div>
              <div class="summary-divider"></div>
              <div class="cart-total-row" style="font-size:.88rem">
                <span>Subtotal</span><span>KES <?= number_format($cartTotal, 2) ?></span>
              </div>
              <div class="cart-total-row" style="font-size:.88rem;margin-top:6px">
                <span>Delivery</span><span>KES <?= number_format($deliveryFee, 2) ?></span>
              </div>
              <div class="summary-divider"></div>
              <div class="summary-grand">
                <span>Total</span><span>KES <?= number_format($grandTotal, 2) ?></span>
              </div>
            <?php endif; ?>
            <button class="submit-order-btn" type="submit" id="pay-btn" data-mpesa-trigger <?= $cartItems === [] ? 'disabled' : '' ?>>
              <i class="fa-solid fa-lock"></i>
              <?= $cartItems === [] ? 'Add items to continue' : 'Place order — KES ' . number_format($grandTotal, 2) ?>
            </button>
            <p style="text-align:center;font-size:.76rem;color:var(--shop-muted);margin:10px 0 0">
              <i class="fa-solid fa-shield-halved"></i> Stock reserved immediately on order
            </p>
          </div>
        </form>
      </div>
    </section>
  </main>

  <!-- ── Footer ─────────────────────────────────────────────────── -->
  <footer class="footer">
    <div class="container footer-grid">
      <div>
        <h3>Mobimend Shop</h3>
        <p>Accessories backed by live stock, real orders, and fast delivery.</p>
      </div>
      <div><h3>Help</h3><ul><li><a href="track.php">Track order</a></li><li><a href="contact.php">Contact us</a></li><li><a href="repair.php">Book a repair</a></li></ul></div>
      <div><h3>More</h3><ul><li><a href="wholesale.php">Wholesale</a></li><li><a href="blog.php">Repair guides</a></li><li><a href="account.php">My account</a></li></ul></div>
      <div><h3>Juja shop</h3><ul><li>Mum &amp; Dad Business Centre, Stall 9E</li><li>0799 183 907</li><li>mobimendspares@gmail.com</li></ul></div>
    </div>
    <div class="container footer-bottom">&copy; 2026 Mobimend Spares. All rights reserved.</div>
  </footer>

  <script src="chatbot.js"></script>
  <script src="site.js"></script>
  <script src="mpesa_checkout.js"></script>
</body>
</html>
