<?php

declare(strict_types=1);

require __DIR__ . '/session_bootstrap.php';
session_start();

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/commerce_helpers.php';

use Mobimend\Config\Database;

header('Content-Type: application/json; charset=utf-8');

$pdo = Database::connection();
$channel = (string) ($_GET['channel'] ?? 'shop');
$query = trim((string) ($_GET['q'] ?? ''));
$brand = trim((string) ($_GET['brand'] ?? ''));
$category = (int) ($_GET['category'] ?? 0);

function search_product_column_exists(PDO $pdo, string $column): bool
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

function render_shop_card(array $product): string
{
    $stock = (int) $product['stock_quantity'];
    $compatibility = trim((string) $product['compatible_brand'] . ' ' . (string) $product['compatible_model']);
    if ($compatibility === '') {
        $compatibility = 'Universal fit';
    }

    ob_start();
    ?>
    <article class="product-card ecommerce-card" data-product-card>
      <a class="product-art product-photo" href="product.php?id=<?= (int) $product['product_id'] ?>">
        <img src="<?= htmlspecialchars(commerce_product_image_url($product['media_url'] ?? null)) ?>" alt="<?= htmlspecialchars((string) $product['name']) ?>" onerror="this.src='<?= htmlspecialchars(commerce_product_image_url(null)) ?>'">
      </a>
      <div class="product-body">
        <div class="blog-meta">
          <span class="status-pill"><?= htmlspecialchars((string) ($product['category_name'] ?? 'Shop')) ?></span>
          <span class="status-pill stock-<?= $stock > 0 ? 'ready' : 'out' ?>"><?= htmlspecialchars(commerce_stock_label($stock)) ?></span>
        </div>
        <h3><a href="product.php?id=<?= (int) $product['product_id'] ?>"><?= htmlspecialchars((string) $product['name']) ?></a></h3>
        <p class="compatibility-badge"><i class="fa-solid fa-mobile-screen"></i> <?= htmlspecialchars($compatibility) ?></p>
        <p><?= htmlspecialchars((string) $product['variant_name']) ?> - <?= htmlspecialchars((string) $product['sku']) ?></p>
        <div class="price-row">
          <span class="price">KES <?= number_format((float) $product['retail_price'], 2) ?></span>
          <form method="post" action="accessories.php#cart" class="add-cart-form" data-cart-form>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_cart">
            <input type="hidden" name="variant_id" value="<?= (int) $product['id'] ?>">
            <input type="number" min="1" max="<?= $stock ?>" name="quantity" value="1" aria-label="Quantity" <?= $stock <= 0 ? 'disabled' : '' ?>>
            <button class="btn-dark" type="submit" <?= $stock <= 0 ? 'disabled' : '' ?>><i class="fa-solid fa-cart-plus"></i> Add</button>
          </form>
        </div>
      </div>
    </article>
    <?php
    return (string) ob_get_clean();
}

function render_wholesale_card(array $item): string
{
    $moq = max(1, (int) ($item['minimum_wholesale_quantity'] ?? 5));
    $unitPrice = (float) $item['wholesale_price'] > 0 ? (float) $item['wholesale_price'] : (float) $item['sell_price'];
    $stock = (int) $item['quantity'];
    $productId = (int) ($item['product_id'] ?? 0);

    ob_start();
    ?>
    <article class="wholesale-product-card"
      data-wholesale-card
      data-brand="<?= htmlspecialchars(strtolower((string) $item['brand'])) ?>"
      data-part-type="<?= htmlspecialchars(strtolower((string) $item['part_type'])) ?>"
      data-price="<?= $unitPrice ?>"
      data-stock="<?= $stock ?>">
      <a class="wholesale-card-image" href="<?= $productId > 0 ? 'product.php?id=' . $productId : '#' ?>" aria-label="<?= htmlspecialchars((string) $item['part_type']) ?>">
        <img src="<?= htmlspecialchars(commerce_product_image_url($item['media_url'] ?? null)) ?>" alt="<?= htmlspecialchars((string) $item['part_type']) ?>" onerror="this.src='<?= htmlspecialchars(commerce_product_image_url(null)) ?>'">
      </a>
      <div class="wholesale-product-body">
        <div class="blog-meta">
          <span class="status-pill"><?= htmlspecialchars((string) $item['brand']) ?></span>
          <span class="status-pill"><?= htmlspecialchars(commerce_stock_label($stock)) ?></span>
        </div>
        <h3><?= htmlspecialchars((string) $item['part_type']) ?></h3>
        <p><?= htmlspecialchars((string) $item['model']) ?></p>
        <div class="wholesale-card-metrics">
          <span><strong><?= number_format($stock) ?></strong> units</span>
          <span><strong><?= $moq ?>+</strong> MOQ</span>
          <span><strong>KES <?= number_format($unitPrice, 0) ?></strong> each</span>
        </div>
        <form method="post" action="wholesale.php#cart" class="wholesale-add-form" data-cart-form>
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="add_cart">
          <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
          <input type="number" min="<?= $moq ?>" max="<?= $stock ?>" name="quantity" value="<?= $moq ?>" aria-label="Quantity">
          <button class="btn-dark" type="submit"><i class="fa-solid fa-cart-plus"></i> Add</button>
        </form>
      </div>
    </article>
    <?php
    return (string) ob_get_clean();
}

try {
    $hasCatalogChannel = search_product_column_exists($pdo, 'catalog_channel');

    if ($channel === 'wholesale') {
        $sql = 'SELECT ii.*, p.id AS product_id, p.minimum_wholesale_quantity, p.media_url, p.name AS product_name, pv.sku
                FROM inventory_items ii
                INNER JOIN product_variants pv ON pv.id = ii.product_variant_id
                INNER JOIN products p ON p.id = pv.product_id
                WHERE ii.quantity > 0 AND pv.is_active = 1 AND p.status IN ("active", "out_of_stock")';
        $params = [];

        if ($hasCatalogChannel) {
            $sql .= ' AND p.catalog_channel IN ("wholesale", "both")';
        }
        if ($brand !== '') {
            $sql .= ' AND ii.brand = :brand';
            $params['brand'] = $brand;
        }
        if ($query !== '') {
            $sql .= ' AND (ii.brand LIKE :q OR ii.model LIKE :q OR ii.part_type LIKE :q OR p.name LIKE :q OR pv.sku LIKE :q)';
            $params['q'] = '%' . $query . '%';
        }

        $sql .= ' ORDER BY ii.brand ASC, ii.model ASC, ii.part_type ASC LIMIT 80';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll();
        echo json_encode([
            'count' => count($items),
            'html' => $items === []
                ? '<article class="wholesale-product-card empty-state"><h3>No matching wholesale parts</h3><p>Try another brand, model, or part type.</p></article>'
                : implode('', array_map('render_wholesale_card', $items)),
        ]);
        exit;
    }

    $catalogChannelSelect = $hasCatalogChannel ? 'p.catalog_channel' : '"shop" AS catalog_channel';
    $sql = 'SELECT pv.*, p.id AS product_id, p.name, p.description, p.brand, p.compatible_brand, p.compatible_model, p.minimum_wholesale_quantity, p.media_url, ' . $catalogChannelSelect . ',
                   pc.id AS category_id, pc.name AS category_name
            FROM product_variants pv
            INNER JOIN products p ON p.id = pv.product_id
            LEFT JOIN product_categories pc ON pc.id = p.category_id
            WHERE pv.is_active = 1 AND p.status IN ("active", "out_of_stock")';
    $params = [];

    if ($hasCatalogChannel) {
        $sql .= ' AND p.catalog_channel IN ("shop", "both")';
    }
    if ($category > 0) {
        $sql .= ' AND pc.id = :category_id';
        $params['category_id'] = $category;
    }
    if ($query !== '') {
        $sql .= ' AND (MATCH(p.name, p.brand, p.compatible_brand, p.compatible_model, p.description) AGAINST (:fulltext IN BOOLEAN MODE)
                    OR p.name LIKE :q OR p.brand LIKE :q OR p.compatible_brand LIKE :q OR p.compatible_model LIKE :q OR pv.sku LIKE :q)';
        $params['fulltext'] = $query . '*';
        $params['q'] = '%' . $query . '%';
    }

    $sql .= ' ORDER BY pc.name ASC, p.name ASC, pv.variant_name ASC LIMIT 80';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll();

    echo json_encode([
        'count' => count($products),
        'html' => $products === []
            ? '<article class="product-card empty-state"><div class="product-body"><h3>No matching accessories</h3><p>Try another brand, model, or part type.</p></div></article>'
            : implode('', array_map('render_shop_card', $products)),
    ]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['error' => $exception->getMessage()]);
}
