<?php

declare(strict_types=1);

require __DIR__ . '/session_bootstrap.php';
session_start();

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/commerce_helpers.php';

use Mobimend\Config\Database;

$pdo = Database::connection();
$id = (int) ($_GET['id'] ?? 0);
$slug = trim((string) ($_GET['slug'] ?? ''));

$stmt = $pdo->prepare(
    'SELECT p.*, pc.name AS category_name
     FROM products p
     LEFT JOIN product_categories pc ON pc.id = p.category_id
     WHERE (:id > 0 AND p.id = :id) OR (:slug <> "" AND p.slug = :slug)
     LIMIT 1'
);
$stmt->execute(['id' => $id, 'slug' => $slug]);
$product = $stmt->fetch();

if (!$product) {
    http_response_code(404);
}

$variants = [];
$related = [];
if ($product) {
    $stmt = $pdo->prepare('SELECT * FROM product_variants WHERE product_id = :id AND is_active = 1 ORDER BY retail_price ASC, variant_name ASC');
    $stmt->execute(['id' => (int) $product['id']]);
    $variants = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        'SELECT p.id, p.name, p.media_url, p.compatible_brand, p.compatible_model, MIN(pv.retail_price) AS price, SUM(pv.stock_quantity) AS stock
         FROM products p
         LEFT JOIN product_variants pv ON pv.product_id = p.id AND pv.is_active = 1
         WHERE p.id <> :id
           AND p.status IN ("active", "out_of_stock")
           AND (p.category_id <=> :category_id OR p.compatible_brand = :compatible_brand OR p.brand = :brand)
         GROUP BY p.id
         ORDER BY stock DESC, p.name ASC
         LIMIT 4'
    );
    $stmt->execute([
        'id' => (int) $product['id'],
        'category_id' => $product['category_id'],
        'compatible_brand' => (string) $product['compatible_brand'],
        'brand' => (string) $product['brand'],
    ]);
    $related = $stmt->fetchAll();
}

$shopCart = is_array($_SESSION['shop_cart'] ?? null) ? $_SESSION['shop_cart'] : [];
$wholesaleCart = is_array($_SESSION['wholesale_cart'] ?? null) ? $_SESSION['wholesale_cart'] : [];
$cartQuantity = array_sum(array_map('intval', $shopCart)) + array_sum(array_map('intval', $wholesaleCart));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $product ? htmlspecialchars((string) $product['name']) : 'Product not found' ?> | Mobimend Spares</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&family=Montserrat:wght@700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <nav class="site-nav">
    <a class="nav-left" href="index.php">
      <img src="assets/LOGO FINAL MOBIMEND WH BG.png" alt="Mobimend Spares" class="logo">
      <div class="brand"><h1>MOBIMEND</h1><p class="tagline">Product detail</p></div>
    </a>
    <button class="menu-toggle" id="menu-toggle" aria-expanded="false" aria-label="Toggle navigation"><i class="fa-solid fa-bars"></i></button>
    <ul class="nav-links" id="nav-links">
      <li><a href="index.php">Home</a></li>
      <li><a href="accessories.php">Shop</a></li>
      <li><a href="wholesale.php">Wholesale</a></li>
      <li><?= commerce_nav_cart((int) $cartQuantity) ?></li>
    </ul>
  </nav>

  <main class="section alt">
    <div class="section-inner">
      <?php if (!$product): ?>
        <div class="php-banner error">Product not found.</div>
      <?php else: ?>
        <?php
          $stock = array_reduce($variants, static fn (int $sum, array $variant): int => $sum + (int) $variant['stock_quantity'], 0);
          $primaryVariant = $variants[0] ?? null;
          $compatibility = trim((string) $product['compatible_brand'] . ' ' . (string) $product['compatible_model']);
        ?>
        <section class="pdp-layout">
          <div class="pdp-gallery">
            <div class="pdp-main-image">
              <img src="<?= htmlspecialchars(commerce_product_image_url($product['media_url'] ?? null)) ?>" alt="<?= htmlspecialchars((string) $product['name']) ?>" onerror="this.src='<?= htmlspecialchars(commerce_product_image_url(null)) ?>'">
            </div>
            <div class="pdp-thumbs">
              <button type="button"><img src="<?= htmlspecialchars(commerce_product_image_url($product['media_url'] ?? null)) ?>" alt=""></button>
              <?php foreach (array_slice($variants, 0, 3) as $variant): ?>
                <button type="button"><span><?= htmlspecialchars((string) $variant['variant_name']) ?></span></button>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="pdp-info">
            <p class="section-kicker"><i class="fa-solid fa-shield-halved"></i> <?= htmlspecialchars((string) ($product['category_name'] ?? 'Mobimend catalog')) ?></p>
            <h1><?= htmlspecialchars((string) $product['name']) ?></h1>
            <p><?= htmlspecialchars((string) ($product['description'] ?: 'Quality checked part from Mobimend Spares.')) ?></p>
            <div class="pdp-price-row">
              <strong>KES <?= number_format((float) ($primaryVariant['retail_price'] ?? $product['retail_price']), 2) ?></strong>
              <span class="status-pill"><?= htmlspecialchars(commerce_stock_label($stock)) ?> - <?= number_format($stock) ?> units</span>
            </div>
            <div class="compatibility-badge pdp-fit"><i class="fa-solid fa-mobile-screen"></i> <?= htmlspecialchars($compatibility !== '' ? $compatibility : 'Universal compatibility') ?></div>

            <?php if ($primaryVariant): ?>
              <form method="post" action="accessories.php#cart" class="pdp-buy-box add-cart-form" data-cart-form>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_cart">
                <input type="hidden" name="variant_id" value="<?= (int) $primaryVariant['id'] ?>">
                <label>Quantity <input type="number" min="1" max="<?= (int) $primaryVariant['stock_quantity'] ?>" name="quantity" value="1"></label>
                <button class="btn-primary" type="submit" <?= (int) $primaryVariant['stock_quantity'] <= 0 ? 'disabled' : '' ?>><i class="fa-solid fa-cart-plus"></i> Add to cart</button>
              </form>
            <?php endif; ?>
          </div>
        </section>

        <section class="pdp-detail-grid">
          <div class="payment-card">
            <h3>Compatibility chart</h3>
            <div class="compat-table">
              <div><span>Brand</span><strong><?= htmlspecialchars((string) ($product['compatible_brand'] ?: $product['brand'] ?: 'Most brands')) ?></strong></div>
              <div><span>Model</span><strong><?= htmlspecialchars((string) ($product['compatible_model'] ?: 'Universal / confirm before checkout')) ?></strong></div>
              <div><span>Channel</span><strong><?= htmlspecialchars((string) $product['catalog_channel']) ?></strong></div>
              <div><span>SKU</span><strong><?= htmlspecialchars((string) $product['sku']) ?></strong></div>
            </div>
          </div>
          <div class="payment-card">
            <h3>Available variants</h3>
            <div class="variant-list">
              <?php foreach ($variants as $variant): ?>
                <div>
                  <strong><?= htmlspecialchars((string) $variant['variant_name']) ?></strong>
                  <span><?= htmlspecialchars((string) $variant['sku']) ?> - <?= number_format((int) $variant['stock_quantity']) ?> units - KES <?= number_format((float) $variant['retail_price'], 2) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </section>

        <section class="pdp-related">
          <div class="shop-grid-head">
            <div><h2>Related parts</h2><p>Useful matches for the same brand, category, or device family.</p></div>
          </div>
          <div class="product-grid related-grid">
            <?php foreach ($related as $item): ?>
              <article class="product-card">
                <a class="product-art product-photo" href="product.php?id=<?= (int) $item['id'] ?>">
                  <img src="<?= htmlspecialchars(commerce_product_image_url($item['media_url'] ?? null)) ?>" alt="<?= htmlspecialchars((string) $item['name']) ?>">
                </a>
                <div class="product-body">
                  <h3><a href="product.php?id=<?= (int) $item['id'] ?>"><?= htmlspecialchars((string) $item['name']) ?></a></h3>
                  <p><?= htmlspecialchars(trim((string) $item['compatible_brand'] . ' ' . (string) $item['compatible_model'])) ?></p>
                  <span class="price">KES <?= number_format((float) $item['price'], 2) ?></span>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endif; ?>
    </div>
  </main>

  <script src="chatbot.js"></script>
  <script src="commerce.js"></script>
  <script src="site.js"></script>
</body>
</html>
