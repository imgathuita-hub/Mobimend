<?php

declare(strict_types=1);

require __DIR__ . '/session_bootstrap.php';
session_start();

require dirname(__DIR__) . '/src/bootstrap.php';

use Mobimend\Config\Database;
use Mobimend\Services\InventoryLedger;

$pdo = Database::connection();
$adminRoles = ['admin', 'super_admin', 'technician'];
$user = $_SESSION['admin_user'] ?? null;
if (is_array($user) && !in_array((string) ($user['role'] ?? ''), $adminRoles, true)) {
    unset($_SESSION['admin_user']);
    $user = null;
}
$message = (string) ($_GET['message'] ?? '');
$tone = (string) ($_GET['tone'] ?? 'info');

function product_column_exists(PDO $pdo, string $column): bool
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

function upload_product_image(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Product image upload failed.');
    }
    if ((int) ($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('Product image must be 5MB or smaller.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmpName) ?: '';
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Upload a JPG, PNG, WebP, or GIF product image.');
    }

    $uploadDir = __DIR__ . '/uploads/products';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }
    if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
        throw new RuntimeException('Product upload directory is not writable.');
    }

    $filename = date('YmdHis') . '-' . bin2hex(random_bytes(6)) . '.' . $extensions[$mime];
    $destination = $uploadDir . '/' . $filename;
    if (!move_uploaded_file($tmpName, $destination)) {
        throw new RuntimeException('Could not save the uploaded product image.');
    }

    return 'uploads/products/' . $filename;
}

function product_image_url(?string $mediaUrl): string
{
    $mediaUrl = trim((string) $mediaUrl);
    return $mediaUrl !== '' ? $mediaUrl : 'assets/LOGO FINAL MOBIMEND WH BG.png';
}

$hasCatalogChannel = product_column_exists($pdo, 'catalog_channel');

if (!$user && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');

    $stmt = $pdo->prepare('SELECT id, name, email, password_hash, role FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);
    $foundUser = $stmt->fetch();

    if (
        !$foundUser
        || !password_verify($password, (string) $foundUser['password_hash'])
        || !in_array((string) $foundUser['role'], $adminRoles, true)
    ) {
        $message = 'Invalid credentials.';
        $tone = 'error';
    } else {
        $_SESSION['admin_user'] = [
            'id' => (int) $foundUser['id'],
            'name' => (string) $foundUser['name'],
            'email' => (string) $foundUser['email'],
            'role' => (string) $foundUser['role'],
        ];
        header('Location: admin_dashboard.php');
        exit;
    }
}

if ($user && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'save_category') {
            $name = trim((string) ($_POST['category_name'] ?? ''));
            if ($name === '') {
                throw new RuntimeException('Category name is required.');
            }

            $slug = slugify($name);
            $stmt = $pdo->prepare(
                'INSERT INTO product_categories (name, slug, description, is_active)
                 VALUES (:name, :slug, :description, 1)
                 ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), is_active = 1'
            );
            $stmt->execute([
                'name' => $name,
                'slug' => $slug,
                'description' => trim((string) ($_POST['category_description'] ?? '')),
            ]);

            redirect_with_message('admin_products.php', 'Category saved.');
        }

        if ($action === 'save_product') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $sku = strtoupper(trim((string) ($_POST['sku'] ?? '')));
            $variantSku = strtoupper(trim((string) ($_POST['variant_sku'] ?? '')));
            $categoryId = (int) ($_POST['category_id'] ?? 0);

            if ($name === '' || $sku === '' || $variantSku === '') {
                throw new RuntimeException('Product name, SKU, and variant SKU are required.');
            }

            $pdo->beginTransaction();

            $retailPrice = max(0, (float) ($_POST['retail_price'] ?? 0));
            $wholesalePrice = max(0, (float) ($_POST['wholesale_price'] ?? 0));
            $stockQuantity = max(0, (int) ($_POST['stock_quantity'] ?? 0));
            $lowStockThreshold = max(1, (int) ($_POST['low_stock_threshold'] ?? 5));
            $brand = trim((string) ($_POST['brand'] ?? ''));
            $compatibleBrand = trim((string) ($_POST['compatible_brand'] ?? ''));
            $compatibleModel = trim((string) ($_POST['compatible_model'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $minimumWholesaleQuantity = max(1, (int) ($_POST['minimum_wholesale_quantity'] ?? 5));
            $variantName = trim((string) ($_POST['variant_name'] ?? 'Default'));
            $qualityGrade = trim((string) ($_POST['quality_grade'] ?? 'Standard'));
            $catalogChannel = (string) ($_POST['catalog_channel'] ?? 'shop');
            if (!in_array($catalogChannel, ['shop', 'wholesale', 'both'], true)) {
                $catalogChannel = 'shop';
            }
            $uploadedMediaUrl = upload_product_image($_FILES['product_image'] ?? []);
            $mediaUrl = $uploadedMediaUrl !== '' ? $uploadedMediaUrl : trim((string) ($_POST['media_url'] ?? ''));

            $productColumns = [
                'category_id', 'name', 'slug', 'sku', 'brand', 'compatible_brand', 'compatible_model', 'description',
                'retail_price', 'wholesale_price', 'minimum_wholesale_quantity',
            ];
            $productValues = [
                ':category_id', ':name', ':slug', ':sku', ':brand', ':compatible_brand', ':compatible_model', ':description',
                ':retail_price', ':wholesale_price', ':minimum_wholesale_quantity',
            ];
            $productParams = [
                'category_id' => $categoryId > 0 ? $categoryId : null,
                'name' => $name,
                'slug' => slugify($name . '-' . $sku),
                'sku' => $sku,
                'brand' => $brand,
                'compatible_brand' => $compatibleBrand,
                'compatible_model' => $compatibleModel,
                'description' => $description,
                'retail_price' => $retailPrice,
                'wholesale_price' => $wholesalePrice,
                'minimum_wholesale_quantity' => $minimumWholesaleQuantity,
            ];
            if ($hasCatalogChannel) {
                $productColumns[] = 'catalog_channel';
                $productValues[] = ':catalog_channel';
                $productParams['catalog_channel'] = $catalogChannel;
            }
            $productColumns[] = 'status';
            $productColumns[] = 'media_url';
            $productValues[] = ':status';
            $productValues[] = ':media_url';
            $productParams += [
                'status' => $stockQuantity > 0 ? 'active' : 'out_of_stock',
                'media_url' => $mediaUrl,
            ];

            $stmt = $pdo->prepare(
                'INSERT INTO products (' . implode(', ', $productColumns) . ')
                 VALUES (' . implode(', ', $productValues) . ')'
            );
            $stmt->execute($productParams);
            $productId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare(
                'INSERT INTO product_variants
                 (product_id, sku, variant_name, color, quality_grade, retail_price, wholesale_price, stock_quantity, low_stock_threshold, reorder_point, low_stock, is_active)
                 VALUES
                 (:product_id, :sku, :variant_name, :color, :quality_grade, :retail_price, :wholesale_price, :stock_quantity, :low_stock_threshold, :reorder_point, :low_stock, 1)'
            );
            $stmt->execute([
                'product_id' => $productId,
                'sku' => $variantSku,
                'variant_name' => $variantName !== '' ? $variantName : 'Default',
                'color' => trim((string) ($_POST['color'] ?? '')),
                'quality_grade' => $qualityGrade,
                'retail_price' => $retailPrice,
                'wholesale_price' => $wholesalePrice,
                'stock_quantity' => $stockQuantity,
                'low_stock_threshold' => $lowStockThreshold,
                'reorder_point' => $lowStockThreshold,
                'low_stock' => $stockQuantity <= $lowStockThreshold ? 1 : 0,
            ]);
            $variantId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare(
                'INSERT INTO inventory_items
                 (product_variant_id, brand, model, part_type, quantity, low_stock_threshold, reorder_point, low_stock, buy_price, sell_price, wholesale_price, status, notes)
                 VALUES
                 (:product_variant_id, :brand, :model, :part_type, :quantity, :low_stock_threshold, :reorder_point, :low_stock, :buy_price, :sell_price, :wholesale_price, :status, :notes)'
            );
            $stmt->execute([
                'product_variant_id' => $variantId,
                'brand' => $brand !== '' ? $brand : $compatibleBrand,
                'model' => $compatibleModel !== '' ? $compatibleModel : $name,
                'part_type' => $name,
                'quantity' => $stockQuantity,
                'low_stock_threshold' => $lowStockThreshold,
                'reorder_point' => $lowStockThreshold,
                'low_stock' => $stockQuantity <= $lowStockThreshold ? 1 : 0,
                'buy_price' => max(0, (float) ($_POST['buy_price'] ?? 0)),
                'sell_price' => $retailPrice,
                'wholesale_price' => $wholesalePrice,
                'status' => $stockQuantity > 0 ? 'in_stock' : 'sold_out',
                'notes' => $description,
            ]);
            $inventoryItemId = (int) $pdo->lastInsertId();

            if ($stockQuantity > 0) {
                InventoryLedger::recordMovement($pdo, [
                    'inventory_item_id' => $inventoryItemId,
                    'product_variant_id' => $variantId,
                    'movement_type' => 'receive',
                    'source' => 'product_opening_stock',
                    'quantity_delta' => $stockQuantity,
                    'previous_quantity' => 0,
                    'new_quantity' => $stockQuantity,
                    'unit_buy_price' => max(0, (float) ($_POST['buy_price'] ?? 0)),
                    'unit_sell_price' => $retailPrice,
                    'brand' => $brand !== '' ? $brand : $compatibleBrand,
                    'model' => $compatibleModel !== '' ? $compatibleModel : $name,
                    'part_type' => $name,
                    'reason' => 'Opening stock',
                    'created_by_user_id' => (int) $user['id'],
                ]);
            }

            $pdo->commit();
            redirect_with_message('admin_products.php', 'Product, variant, and inventory line created.');
        }

        if ($action === 'adjust_stock') {
            $variantId = (int) ($_POST['variant_id'] ?? 0);
            $delta = (int) ($_POST['delta'] ?? 0);
            $reason = trim((string) ($_POST['reason'] ?? 'manual_adjustment'));

            if ($variantId <= 0 || $delta === 0) {
                throw new RuntimeException('Choose a variant and enter a non-zero stock adjustment.');
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'SELECT pv.*, p.name, p.brand, p.compatible_model, ii.id AS inventory_item_id, ii.buy_price, ii.sell_price,
                        ii.quantity AS inventory_quantity, ii.reorder_point, ii.low_stock_threshold
                 FROM product_variants pv
                 INNER JOIN products p ON p.id = pv.product_id
                 LEFT JOIN inventory_items ii ON ii.product_variant_id = pv.id
                 WHERE pv.id = :id
                 FOR UPDATE'
            );
            $stmt->execute(['id' => $variantId]);
            $variant = $stmt->fetch();
            if (!$variant) {
                throw new RuntimeException('Variant not found.');
            }

            $previousStock = (int) $variant['stock_quantity'];
            $nextStock = max(0, $previousStock + $delta);
            $actualDelta = $nextStock - $previousStock;
            if ($actualDelta === 0) {
                throw new RuntimeException('Adjustment would not change stock.');
            }
            $reorderPoint = (int) ($variant['reorder_point'] ?? $variant['low_stock_threshold'] ?? 0);
            $update = $pdo->prepare('UPDATE product_variants SET stock_quantity = :stock, low_stock = :low_stock, updated_at = :updated_at WHERE id = :id');
            $update->execute(['stock' => $nextStock, 'low_stock' => $reorderPoint > 0 && $nextStock <= $reorderPoint ? 1 : 0, 'updated_at' => now(), 'id' => $variantId]);

            if (!empty($variant['inventory_item_id'])) {
                $update = $pdo->prepare('UPDATE inventory_items SET quantity = :stock, low_stock = :low_stock, status = :status, updated_at = :updated_at WHERE id = :id');
                $update->execute([
                    'stock' => $nextStock,
                    'low_stock' => $reorderPoint > 0 && $nextStock <= $reorderPoint ? 1 : 0,
                    'status' => $nextStock > 0 ? 'in_stock' : 'sold_out',
                    'updated_at' => now(),
                    'id' => (int) $variant['inventory_item_id'],
                ]);

                $movement = [
                    'inventory_item_id' => (int) $variant['inventory_item_id'],
                    'product_variant_id' => $variantId,
                    'brand' => (string) $variant['brand'],
                    'model' => (string) $variant['compatible_model'],
                    'part_type' => (string) $variant['name'],
                    'quantity_delta' => $actualDelta,
                    'previous_quantity' => (int) $variant['inventory_quantity'],
                    'new_quantity' => $nextStock,
                    'movement_type' => $actualDelta > 0 ? 'receive' : 'adjustment',
                    'unit_buy_price' => (float) ($variant['buy_price'] ?? 0),
                    'unit_sell_price' => (float) ($variant['sell_price'] ?? 0),
                    'source' => 'stock_' . slugify($reason),
                    'reason' => $reason,
                    'created_by_user_id' => (int) $user['id'],
                ];
                InventoryLedger::recordMovement($pdo, $movement);
                InventoryLedger::mirrorTransaction($pdo, $movement);

                if ($actualDelta < 0) {
                    InventoryLedger::enqueueReorderAlert($pdo, [
                        'inventory_item_id' => (int) $variant['inventory_item_id'],
                        'product_variant_id' => $variantId,
                        'brand' => (string) $variant['brand'],
                        'model' => (string) $variant['compatible_model'],
                        'part_type' => (string) $variant['name'],
                        'quantity' => $nextStock,
                        'reorder_point' => (int) ($variant['reorder_point'] ?? $variant['low_stock_threshold'] ?? 0),
                    ]);
                }
            }

            $pdo->commit();
            redirect_with_message('admin_products.php', 'Stock adjusted.');
        }

        if ($action === 'update_product') {
            $productId = (int) ($_POST['product_id'] ?? 0);
            if ($productId <= 0) {
                throw new RuntimeException('Choose a product to update.');
            }

            $categoryId = (int) ($_POST['category_id'] ?? 0);
            $status = (string) ($_POST['status'] ?? 'active');
            if (!in_array($status, ['draft', 'active', 'out_of_stock', 'archived'], true)) {
                $status = 'active';
            }
            $catalogChannel = (string) ($_POST['catalog_channel'] ?? 'shop');
            if (!in_array($catalogChannel, ['shop', 'wholesale', 'both'], true)) {
                $catalogChannel = 'shop';
            }

            $uploadedMediaUrl = upload_product_image($_FILES['product_image'] ?? []);
            $manualMediaUrl = trim((string) ($_POST['media_url'] ?? ''));
            $mediaUrl = $uploadedMediaUrl !== '' ? $uploadedMediaUrl : $manualMediaUrl;

            $columns = [
                'category_id = :category_id',
                'status = :status',
                'updated_at = :updated_at',
            ];
            $params = [
                'category_id' => $categoryId > 0 ? $categoryId : null,
                'status' => $status,
                'updated_at' => now(),
                'id' => $productId,
            ];
            if ($hasCatalogChannel) {
                $columns[] = 'catalog_channel = :catalog_channel';
                $params['catalog_channel'] = $catalogChannel;
            }
            if ($mediaUrl !== '') {
                $columns[] = 'media_url = :media_url';
                $params['media_url'] = $mediaUrl;
            }

            $stmt = $pdo->prepare('UPDATE products SET ' . implode(', ', $columns) . ' WHERE id = :id');
            $stmt->execute($params);
            redirect_with_message('admin_products.php', 'Product catalog details updated.');
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = $exception->getMessage();
        $tone = 'error';
    }
}

$adminCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$categories = $pdo->query('SELECT * FROM product_categories ORDER BY name ASC')->fetchAll();
$variants = [];
$stats = ['products' => 0, 'units' => 0, 'low' => 0, 'value' => 0.0];
$recentTransactions = [];

if ($user) {
    $catalogChannelSelect = $hasCatalogChannel ? 'p.catalog_channel' : '"shop" AS catalog_channel';
    $variants = $pdo->query(
        'SELECT pv.*, p.id AS product_id, p.name, p.sku AS product_sku, p.brand, p.compatible_model, p.minimum_wholesale_quantity, p.media_url, p.category_id, p.status, ' . $catalogChannelSelect . ', pc.name AS category_name
         FROM product_variants pv
         INNER JOIN products p ON p.id = pv.product_id
         LEFT JOIN product_categories pc ON pc.id = p.category_id
         ORDER BY p.created_at DESC, pv.created_at DESC'
    )->fetchAll();
    foreach ($variants as $variant) {
        $stats['products']++;
        $stats['units'] += (int) $variant['stock_quantity'];
        $stats['value'] += (float) $variant['retail_price'] * (int) $variant['stock_quantity'];
        if ((int) ($variant['low_stock'] ?? 0) === 1 || (int) $variant['stock_quantity'] <= (int) ($variant['reorder_point'] ?? $variant['low_stock_threshold'])) {
            $stats['low']++;
        }
    }
    $recentTransactions = $pdo->query(
        'SELECT sm.*, ii.brand, ii.model, ii.part_type
         FROM stock_movements sm
         INNER JOIN inventory_items ii ON ii.id = sm.inventory_item_id
         ORDER BY sm.created_at DESC
         LIMIT 8'
    )->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Product Command Center | Mobimend</title>
  <link rel="stylesheet" href="style.css">
  <link rel="stylesheet" href="admin_ops.css">
  <style>
    body { background: #f6f8fb; color: #111827; font-family: Inter, Arial, sans-serif; margin: 0; }
    .admin-shell { max-width: 1240px; margin: 0 auto; padding: 24px; }
    .admin-hero { background: #111827; color: #fff; padding: 28px 24px; }
    .admin-hero a { color: #dbeafe; }
    .admin-grid { display: grid; grid-template-columns: 0.9fr 1.4fr; gap: 18px; align-items: start; }
    .admin-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 18px; box-shadow: 0 16px 34px rgba(15, 23, 42, 0.06); }
    .admin-card h2, .admin-card h3 { margin-top: 0; }
    .form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
    .form-grid .full { grid-column: 1 / -1; }
    input, select, textarea { width: 100%; box-sizing: border-box; border: 1px solid #d1d5db; border-radius: 8px; padding: 11px 12px; font: inherit; background: #fff; }
    button, .admin-btn { border: 0; border-radius: 8px; padding: 11px 14px; background: #1766c5; color: #fff; font-weight: 800; cursor: pointer; text-decoration: none; display: inline-block; }
    .admin-btn.secondary { background: #eef2ff; color: #1e3a8a; }
    .admin-btn.danger { background: #b91c1c; }
    .stats-row { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 18px; }
    .stat-box { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; }
    .stat-box span { display: block; color: #64748b; font-size: 0.9rem; }
    .stat-box strong { display: block; margin-top: 6px; font-size: 1.5rem; }
    .banner { margin-bottom: 14px; border-radius: 8px; padding: 12px 14px; font-weight: 700; }
    .banner.success { background: #ecfdf5; color: #166534; border: 1px solid #bbf7d0; }
    .banner.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
    .banner.info { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
    .table-wrap { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; }
    th, td { text-align: left; padding: 11px; border-bottom: 1px solid #eef2f7; vertical-align: top; }
    th { background: #f8fafc; }
    .muted { color: #64748b; }
    .stack { display: grid; gap: 12px; }
    .inline-form { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
    .inline-form input { width: 110px; }
    .catalog-product-cell { display: grid; grid-template-columns: 58px minmax(0, 1fr); gap: 10px; align-items: start; }
    .catalog-product-cell img { width: 58px; height: 58px; object-fit: cover; border-radius: 8px; background: #eef2f7; }
    .catalog-thumb { width: 58px; height: 58px; object-fit: cover; border-radius: 8px; background: #eef2f7; margin-bottom: 8px; }
    .catalog-edit-form { display: grid; gap: 8px; min-width: 230px; }
    .catalog-edit-row { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
    .catalog-edit-form input[type="file"], .catalog-edit-form input[name="media_url"] { width: 100%; }
    @media (max-width: 900px) { .admin-grid, .stats-row, .form-grid { grid-template-columns: 1fr; } .form-grid .full { grid-column: auto; } }
  </style>
</head>
<body class="admin-ops">
  <header class="admin-hero">
    <div class="ops-header-inner">
      <div class="ops-brand">
        <h1>Product Command Center</h1>
        <p>Manage catalog variants, retail visibility, wholesale rules, and inventory movement from one admin workspace.</p>
      </div>
      <nav class="ops-nav" aria-label="Admin navigation">
        <a href="admin_dashboard.php">Operations</a>
        <a href="admin_inventory.php">Inventory</a>
        <a href="admin_orders.php">Orders</a>
        <a href="admin_repairs.php">Repairs</a>
        <a class="active" href="admin_products.php">Products</a>
        <a href="logout.php">Logout</a>
      </nav>
    </div>
    <h1>Mobimend Product Command Center</h1>
    <p>Catalog, variants, stock levels, wholesale rules, and inventory movement in one practical admin view.</p>
    <p><a href="admin_orders.php">Orders</a> · <a href="admin_inventory.php">Legacy inventory</a> · <a href="accessories.php">Retail shop</a> · <a href="wholesale.php">Wholesale desk</a> · <a href="logout.php">Logout</a></p>
  </header>

  <main class="admin-shell">
    <?php if ($message !== ''): ?>
      <div class="banner <?= htmlspecialchars($tone) ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if (!$user): ?>
      <section class="admin-card" style="max-width: 460px; margin: 40px auto;">
        <h2>Admin Login</h2>
        <?php if ($adminCount === 0): ?>
          <div class="banner info">No admin exists yet. Start with <a href="setup_admin.php">setup_admin.php</a>.</div>
        <?php endif; ?>
        <form method="post" class="stack">
          <input type="hidden" name="action" value="login">
          <input type="email" name="email" placeholder="Email" required>
          <input type="password" name="password" placeholder="Password" required>
          <button type="submit">Login</button>
        </form>
      </section>
    <?php else: ?>
      <section class="stats-row">
        <div class="stat-box"><span>Active sellable variants</span><strong><?= number_format($stats['products']) ?></strong></div>
        <div class="stat-box"><span>Total stock units</span><strong><?= number_format((int) $stats['units']) ?></strong></div>
        <div class="stat-box"><span>Low stock lines</span><strong><?= number_format((int) $stats['low']) ?></strong></div>
        <div class="stat-box"><span>Retail stock value</span><strong>KES <?= number_format((float) $stats['value'], 0) ?></strong></div>
      </section>

      <section class="admin-grid">
        <div class="stack">
          <article class="admin-card">
            <h2>Add Category</h2>
            <form method="post" class="stack">
              <input type="hidden" name="action" value="save_category">
              <input name="category_name" placeholder="Chargers, Screens, Batteries..." required>
              <textarea name="category_description" rows="3" placeholder="Short internal description"></textarea>
              <button type="submit">Save Category</button>
            </form>
          </article>

          <article class="admin-card">
            <h2>Add Product + First Variant</h2>
            <p class="muted">Use Shop for accessories like chargers, cables, protectors, smart watches, and earpods. Use Wholesale for spare parts like LCDs, batteries, switches, cameras, and speakers.</p>
            <form method="post" class="form-grid" enctype="multipart/form-data">
              <input type="hidden" name="action" value="save_product">
              <select name="catalog_channel">
                <option value="shop">Shop accessories</option>
                <option value="wholesale">Wholesale spare parts</option>
                <option value="both">Both shop and wholesale</option>
              </select>
              <select name="category_id">
                <option value="0">No category</option>
                <?php foreach ($categories as $category): ?>
                  <option value="<?= (int) $category['id'] ?>"><?= htmlspecialchars((string) $category['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <input name="name" placeholder="Product name" required>
              <input name="sku" placeholder="Product SKU" required>
              <input name="variant_sku" placeholder="Variant SKU" required>
              <input name="brand" placeholder="Brand / supplier">
              <input name="compatible_brand" placeholder="Compatible brand">
              <input name="compatible_model" placeholder="Compatible model">
              <input name="variant_name" placeholder="Variant name">
              <input name="color" placeholder="Color">
              <input name="quality_grade" placeholder="Quality grade">
              <input type="number" min="0" step="0.01" name="buy_price" placeholder="Buy price">
              <input type="number" min="0" step="0.01" name="retail_price" placeholder="Retail price" required>
              <input type="number" min="0" step="0.01" name="wholesale_price" placeholder="Wholesale price" required>
              <input type="number" min="1" name="minimum_wholesale_quantity" value="5" placeholder="MOQ">
              <input type="number" min="0" name="stock_quantity" placeholder="Opening stock" required>
              <input type="number" min="1" name="low_stock_threshold" value="5" placeholder="Low stock threshold">
              <input class="full" type="file" name="product_image" accept="image/jpeg,image/png,image/webp,image/gif">
              <input class="full" name="media_url" placeholder="Optional image URL if you do not upload a file">
              <textarea class="full" name="description" rows="4" placeholder="Customer-facing description and compatibility notes"></textarea>
              <button class="full" type="submit">Create Product</button>
            </form>
          </article>
        </div>

        <div class="stack">
          <article class="admin-card">
            <h2>Catalog and Stock</h2>
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Product</th>
                    <th>Variant</th>
                    <th>Stock</th>
                    <th>Pricing</th>
                    <th>Catalog</th>
                    <th>Adjust</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($variants === []): ?>
                    <tr><td colspan="6" class="muted">No products yet. Create a category and first product to activate the shop.</td></tr>
                  <?php endif; ?>
                  <?php foreach ($variants as $variant): ?>
                    <tr>
                      <td>
                        <img class="catalog-thumb" src="<?= htmlspecialchars(product_image_url($variant['media_url'] ?? null)) ?>" alt="<?= htmlspecialchars((string) $variant['name']) ?>">
                        <strong><?= htmlspecialchars((string) $variant['name']) ?></strong><br>
                        <span class="muted"><?= htmlspecialchars(ucfirst((string) $variant['catalog_channel'])) ?> catalog</span><br>
                        <span class="muted"><?= htmlspecialchars((string) ($variant['category_name'] ?? 'Uncategorized')) ?> · <?= htmlspecialchars((string) $variant['brand']) ?></span>
                      </td>
                      <td><?= htmlspecialchars((string) $variant['variant_name']) ?><br><span class="muted"><?= htmlspecialchars((string) $variant['sku']) ?></span></td>
                      <td>
                        <?= (int) $variant['stock_quantity'] ?> units<br>
                        <span class="muted">Low at <?= (int) $variant['low_stock_threshold'] ?></span>
                      </td>
                      <td>
                        Retail KES <?= number_format((float) $variant['retail_price'], 2) ?><br>
                        <span class="muted">Wholesale KES <?= number_format((float) $variant['wholesale_price'], 2) ?> · MOQ <?= (int) $variant['minimum_wholesale_quantity'] ?></span>
                      </td>
                      <td>
                        <form method="post" class="catalog-edit-form" enctype="multipart/form-data">
                          <input type="hidden" name="action" value="update_product">
                          <input type="hidden" name="product_id" value="<?= (int) $variant['product_id'] ?>">
                          <div class="catalog-edit-row">
                            <select name="catalog_channel">
                              <?php foreach (['shop' => 'Shop', 'wholesale' => 'Wholesale', 'both' => 'Both'] as $value => $label): ?>
                                <option value="<?= $value ?>" <?= $variant['catalog_channel'] === $value ? 'selected' : '' ?>><?= $label ?></option>
                              <?php endforeach; ?>
                            </select>
                            <select name="status">
                              <?php foreach (['draft', 'active', 'out_of_stock', 'archived'] as $status): ?>
                                <option value="<?= $status ?>" <?= $variant['status'] === $status ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $status)) ?></option>
                              <?php endforeach; ?>
                            </select>
                          </div>
                          <select name="category_id">
                            <option value="0">No category</option>
                            <?php foreach ($categories as $category): ?>
                              <option value="<?= (int) $category['id'] ?>" <?= (int) $variant['category_id'] === (int) $category['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $category['name']) ?></option>
                            <?php endforeach; ?>
                          </select>
                          <input type="file" name="product_image" accept="image/jpeg,image/png,image/webp,image/gif">
                          <input name="media_url" placeholder="New image URL">
                          <button type="submit">Save catalog</button>
                        </form>
                      </td>
                      <td>
                        <form method="post" class="inline-form">
                          <input type="hidden" name="action" value="adjust_stock">
                          <input type="hidden" name="variant_id" value="<?= (int) $variant['id'] ?>">
                          <input type="number" name="delta" placeholder="+/- qty" required>
                          <input name="reason" placeholder="reason">
                          <button type="submit">Apply</button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </article>

          <article class="admin-card">
            <h2>Recent Inventory Movement</h2>
            <div class="table-wrap">
              <table>
                <thead><tr><th>Item</th><th>Qty</th><th>Source</th><th>When</th></tr></thead>
                <tbody>
                  <?php if ($recentTransactions === []): ?>
                    <tr><td colspan="4" class="muted">No stock movement recorded yet.</td></tr>
                  <?php endif; ?>
                  <?php foreach ($recentTransactions as $transaction): ?>
                    <tr>
                      <td><?= htmlspecialchars((string) $transaction['brand']) ?> <?= htmlspecialchars((string) $transaction['model']) ?><br><span class="muted"><?= htmlspecialchars((string) $transaction['part_type']) ?></span></td>
                      <td><?= (int) $transaction['quantity_delta'] ?></td>
                      <td><?= htmlspecialchars((string) $transaction['source']) ?></td>
                      <td><?= htmlspecialchars((string) $transaction['created_at']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </article>
        </div>
      </section>
    <?php endif; ?>
  </main>
</body>
</html>
