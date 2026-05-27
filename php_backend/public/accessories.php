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

        $pdo->commit();
        $_SESSION['shop_cart'] = [];
        $createdOrder = ['number' => $orderNumber, 'total' => $grandTotal];
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
$shopCountSql = 'SELECT COUNT(*)
        FROM product_variants pv
        INNER JOIN products p ON p.id = pv.product_id
        WHERE pv.is_active = 1 AND p.status IN ("active", "out_of_stock")';
if ($hasCatalogChannel) {
    $shopCountSql .= ' AND p.catalog_channel IN ("shop", "both")';
}
$totalShopProducts = (int) $pdo->query($shopCountSql)->fetchColumn();
$params = [];
$catalogChannelSelect = $hasCatalogChannel ? 'p.catalog_channel' : '"shop" AS catalog_channel';
$sql = 'SELECT pv.*, p.name, p.description, p.brand, p.compatible_brand, p.compatible_model, p.minimum_wholesale_quantity, p.media_url, ' . $catalogChannelSelect . ',
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

        <div id="retailCheckout">
          <div class="shop-commerce-layout">
          <div>
          <div class="shop-grid-head">
            <div>
              <h2>Products</h2>
              <p>Browse available accessories and add them to your cart.</p>
            </div>
          </div>
          <div id="productGrid" class="product-grid" data-server-products aria-live="polite">
            <?php if ($products === []): ?>
              <article class="product-card">
                <div class="product-body">
                  <h3><?= $filtersActive && $totalShopProducts > 0 ? 'No products match these filters' : 'No accessories available yet' ?></h3>
                  <p>
                    <?php if ($filtersActive && $totalShopProducts > 0): ?>
                      Clear the search/category filter to see all shop products.
                    <?php else: ?>
                      Please check back soon.
                    <?php endif; ?>
                  </p>
                  <?php if ($filtersActive): ?>
                    <p style="margin-top: 14px;"><a class="btn-dark" href="accessories.php">Show all products</a></p>
                  <?php endif; ?>
                </div>
              </article>
            <?php endif; ?>
            <?php foreach ($products as $product): ?>
              <?php $stock = (int) $product['stock_quantity']; ?>
              <article class="product-card" data-product-card>
                <div class="product-art product-photo">
                  <img src="<?= htmlspecialchars(product_image_url($product['media_url'] ?? null)) ?>" alt="<?= htmlspecialchars((string) $product['name']) ?>" onerror="this.src='<?= htmlspecialchars(product_image_url(null)) ?>'">
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
                    <form method="post" action="accessories.php#cart" class="add-cart-form">
                      <input type="hidden" name="action" value="add_cart">
                      <input type="hidden" name="variant_id" value="<?= (int) $product['id'] ?>">
                      <input type="number" min="1" max="<?= $stock ?>" name="quantity" value="1" <?= $stock <= 0 ? 'disabled' : '' ?>>
                      <button class="btn-dark" type="submit" data-add-confirm <?= $stock <= 0 ? 'disabled' : '' ?>><i class="fa-solid fa-cart-plus"></i> Add</button>
                      <div class="add-cart-confirm" hidden>
                        <span>Add this item to cart?</span>
                        <button class="btn-dark" type="button" data-confirm-add>Confirm</button>
                        <button class="btn-ghost" type="button" data-cancel-add>Cancel</button>
                      </div>
                    </form>
                  </div>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
          </div>

          <aside class="cart-panel sticky-cart-panel <?= $tone === 'success' && str_contains($message, 'added to cart') ? 'cart-panel-updated' : '' ?>" id="cart">
            <div class="cart-panel-head">
              <div>
                <p class="section-kicker"><i class="fa-solid fa-cart-shopping"></i> Cart</p>
                <h2>Your selected accessories</h2>
              </div>
              <strong><?= number_format($cartQuantity) ?> item<?= $cartQuantity === 1 ? '' : 's' ?></strong>
            </div>
            <?php if ($cartItems === []): ?>
              <p class="muted">Your cart is empty. Add accessories from the catalog.</p>
            <?php else: ?>
              <form method="post" action="#cart" class="cart-list">
                <input type="hidden" name="action" value="update_cart">
                <?php foreach ($cartItems as $item): ?>
                  <div class="cart-line compact">
                    <img src="<?= htmlspecialchars(product_image_url($item['media_url'] ?? null)) ?>" alt="<?= htmlspecialchars((string) $item['name']) ?>">
                    <div>
                      <strong><?= htmlspecialchars((string) $item['name']) ?></strong>
                      <span><?= htmlspecialchars((string) $item['variant_name']) ?> - KES <?= number_format((float) $item['retail_price'], 2) ?></span>
                    </div>
                    <input type="number" min="0" max="<?= (int) $item['stock_quantity'] ?>" name="cart_quantities[<?= (int) $item['id'] ?>]" value="<?= (int) $item['cart_quantity'] ?>">
                    <strong>KES <?= number_format((float) $item['cart_line_total'], 2) ?></strong>
                    <button class="cart-remove-btn" type="submit" form="remove-cart-<?= (int) $item['id'] ?>" aria-label="Remove <?= htmlspecialchars((string) $item['name']) ?>"><i class="fa-solid fa-trash-can"></i></button>
                  </div>
                <?php endforeach; ?>
                <div class="cart-totals">
                  <div><span>Subtotal</span><strong>KES <?= number_format($cartTotal, 2) ?></strong></div>
                  <div><span>Delivery</span><strong>Calculated at checkout</strong></div>
                </div>
                <div class="cart-actions">
                  <button class="btn-dark" type="submit"><i class="fa-solid fa-rotate"></i> Update cart</button>
                  <a class="btn-primary" href="#checkout"><i class="fa-solid fa-bag-shopping"></i> Checkout</a>
                </div>
              </form>
              <?php foreach ($cartItems as $item): ?>
                <form id="remove-cart-<?= (int) $item['id'] ?>" method="post" action="#cart" hidden>
                  <input type="hidden" name="action" value="remove_cart">
                  <input type="hidden" name="variant_id" value="<?= (int) $item['id'] ?>">
                </form>
              <?php endforeach; ?>
            <?php endif; ?>
          </aside>
          </div>

          <section class="section" id="checkout">
            <div class="section-inner" style="padding-left: 0; padding-right: 0;">
              <p class="section-kicker"><i class="fa-solid fa-credit-card"></i> Checkout and payment</p>
              <h2 class="section-title">Create an order and reserve stock immediately.</h2>
              <form method="post" class="checkout-flow">
                <input type="hidden" name="action" value="checkout">
                <div class="payment-card">
                  <h3>Customer and delivery</h3>
                  <div class="form-grid" style="margin-top: 16px;">
                    <div><label>Name</label><input name="customer_name" placeholder="Jane Customer" required></div>
                    <div><label>Phone</label><input name="customer_phone" placeholder="07XX XXX XXX" required></div>
                    <div><label>Email</label><input type="email" name="customer_email" placeholder="jane@example.com"></div>
                    <div><label>Delivery option</label><select name="delivery_option" data-delivery-option><option value="pickup" <?= $deliveryOption === 'pickup' ? 'selected' : '' ?>>Pickup at Juja shop - free</option><option value="local_delivery" <?= $deliveryOption === 'local_delivery' ? 'selected' : '' ?>>Local delivery - KES 250</option><option value="courier_delivery" <?= $deliveryOption === 'courier_delivery' ? 'selected' : '' ?>>Courier delivery - KES 450</option></select></div>
                    <div class="full"><label>Delivery address</label><input name="delivery_address" placeholder="Town, building, rider notes"></div>
                    <div class="full"><label>Order notes</label><input name="notes" placeholder="Color preference, exact model, or delivery note"></div>
                  </div>
                </div>

                <div class="payment-card checkout-summary-card">
                  <h3>Order summary</h3>
                  <?php if ($cartItems === []): ?>
                    <p class="muted">Add items to see your checkout summary.</p>
                  <?php else: ?>
                    <div class="checkout-lines">
                      <?php foreach ($cartItems as $item): ?>
                        <div>
                          <span><?= htmlspecialchars((string) $item['name']) ?> x <?= (int) $item['cart_quantity'] ?></span>
                          <strong>KES <?= number_format((float) $item['cart_line_total'], 2) ?></strong>
                        </div>
                      <?php endforeach; ?>
                    </div>
                    <div class="cart-totals order-totals">
                      <div><span>Subtotal</span><strong>KES <?= number_format($cartTotal, 2) ?></strong></div>
                      <div><span>Delivery</span><strong>KES <?= number_format($deliveryFee, 2) ?></strong></div>
                      <div class="grand-total"><span>Total</span><strong>KES <?= number_format($grandTotal, 2) ?></strong></div>
                    </div>
                  <?php endif; ?>
                </div>

                <div class="payment-card">
                  <h3>Payment method</h3>
                  <label class="payment-method"><input type="radio" name="payment_method" value="mpesa_stk" data-payment-method checked> M-Pesa STK Push</label>
                  <label class="payment-method"><input type="radio" name="payment_method" value="cash" data-payment-method> Pay on pickup</label>
                  <label class="payment-method"><input type="radio" name="payment_method" value="card" data-payment-method> Card payment</label>
                  <p><strong>Current flow:</strong> payment record is created as pending so admin can verify M-Pesa, cash, or card confirmation.</p>
                  <button class="btn-primary" type="submit" style="width: 100%; margin-top: 12px;" <?= $cartItems === [] ? 'disabled' : '' ?>><i class="fa-solid fa-lock"></i> Create order</button>
                </div>
              </form>
            </div>
          </section>
        </div>
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
  <script>
    document.addEventListener('click', (event) => {
      const confirmButton = event.target.closest('[data-confirm-add]');
      const cancelButton = event.target.closest('[data-cancel-add]');

      if (confirmButton) {
        const form = confirmButton.closest('.add-cart-form');
        if (form) {
          form.dataset.confirmed = 'true';
          form.requestSubmit();
        }
      }

      if (cancelButton) {
        const form = cancelButton.closest('.add-cart-form');
        const panel = form?.querySelector('.add-cart-confirm');
        if (form && panel) {
          delete form.dataset.confirmed;
          panel.hidden = true;
        }
      }
    });

    document.querySelectorAll('.add-cart-form').forEach((form) => {
      form.addEventListener('submit', async (event) => {
        if (form.dataset.confirmed !== 'true') {
          event.preventDefault();
          const panel = form.querySelector('.add-cart-confirm');
          if (panel) {
            panel.hidden = false;
          }
          return;
        }

        if (!window.fetch || !window.DOMParser) {
          return;
        }

        event.preventDefault();
        delete form.dataset.confirmed;
        const button = form.querySelector('button[type="submit"]');
        const originalText = button ? button.innerHTML : '';
        if (button) {
          button.disabled = true;
          button.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Adding';
        }

        try {
          const response = await fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
          });
          const html = await response.text();
          const nextDocument = new DOMParser().parseFromString(html, 'text/html');
          const nextCart = nextDocument.querySelector('#cart');
          const currentCart = document.querySelector('#cart');
          if (nextCart && currentCart) {
            currentCart.replaceWith(nextCart);
            document.querySelector('#cart')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
          } else {
            window.location.href = form.action;
          }
        } catch (error) {
          form.submit();
        } finally {
          if (button) {
            button.disabled = false;
            button.innerHTML = originalText;
          }
        }
      });
    });
  </script>
  <script src="site.js"></script>
</body>
</html>
