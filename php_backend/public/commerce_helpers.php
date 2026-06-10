<?php

declare(strict_types=1);

function commerce_product_image_url(?string $mediaUrl): string
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

function commerce_stock_label(int $stock): string
{
    if ($stock <= 0) {
        return 'Sold out';
    }

    if ($stock <= 5) {
        return 'Low stock';
    }

    return 'In stock';
}

function commerce_nav_cart(int $count, string $label = 'Cart'): string
{
    return '<a href="#cart" data-open-cart aria-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '">'
        . '<i class="fa-solid fa-cart-shopping"></i> ' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
        . ' <span class="nav-cart-count" data-cart-count>' . number_format($count) . '</span></a>';
}
