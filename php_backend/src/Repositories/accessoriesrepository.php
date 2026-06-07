<?php

declare(strict_types=1);

namespace Mobimend\Repositories;

use PDO;

/**
 * All database queries for the retail accessories shop.
 */
final class AccessoriesRepository
{
    public function __construct(private readonly PDO $pdo) {}

    // ── Categories ───────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    public function activeCategories(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, name, slug
             FROM product_categories
             WHERE is_active = 1
             ORDER BY name ASC'
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Products ─────────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    public function shopProducts(int $categoryId = 0, string $search = ''): array
    {
        $sql = 'SELECT p.id, p.name, p.brand, p.compatible_brand, p.compatible_model,
                       p.description, p.retail_price, p.media_url, p.status,
                       p.catalog_channel,
                       MIN(pv.retail_price) AS variant_min_price,
                       SUM(pv.stock_quantity) AS total_stock,
                       MIN(pv.id) AS default_variant_id
                FROM products p
                LEFT JOIN product_variants pv ON pv.product_id = p.id AND pv.is_active = 1
                WHERE p.status IN ("active", "out_of_stock")
                  AND p.catalog_channel IN ("shop", "both")';

        $params = [];

        if ($categoryId > 0) {
            $sql .= ' AND p.category_id = :cat';
            $params['cat'] = $categoryId;
        }

        if ($search !== '') {
            $sql .= ' AND MATCH(p.name, p.brand, p.compatible_brand, p.compatible_model, p.description) AGAINST (:q IN BOOLEAN MODE)';
            $params['q'] = $search . '*';
        }

        $sql .= ' GROUP BY p.id ORDER BY p.name ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|false */
    public function variantForCart(int $variantId): array|false
    {
        $stmt = $this->pdo->prepare(
            'SELECT pv.id, pv.stock_quantity, p.name, p.catalog_channel
             FROM product_variants pv
             INNER JOIN products p ON p.id = pv.product_id
             WHERE pv.id = :id
               AND pv.is_active = 1
               AND p.status IN ("active", "out_of_stock")
               AND p.catalog_channel IN ("shop", "both")
             LIMIT 1'
        );
        $stmt->execute(['id' => $variantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : false;
    }

    // ── Cart hydration ───────────────────────────────────────────────────────

    /**
     * @param  array<int,int>               $sessionCart
     * @return array{items: list<array<string,mixed>>, subtotal: float, delivery_fee: float, grand_total: float}
     */
    public function hydrateCart(array $sessionCart, float $deliveryFee = 0.0): array
    {
        if ($sessionCart === []) {
            return ['items' => [], 'subtotal' => 0.0, 'delivery_fee' => $deliveryFee, 'grand_total' => $deliveryFee];
        }

        $ids          = array_values(array_filter(array_map('intval', array_keys($sessionCart))));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $this->pdo->prepare(
            'SELECT pv.id AS variant_id, pv.retail_price, pv.stock_quantity,
                    p.id AS product_id, p.name, p.brand, p.media_url,
                    p.compatible_brand, p.compatible_model
             FROM product_variants pv
             INNER JOIN products p ON p.id = pv.product_id
             WHERE pv.id IN (' . $placeholders . ')
               AND pv.is_active = 1
               AND p.status IN ("active", "out_of_stock")
               AND p.catalog_channel IN ("shop", "both")'
        );
        $stmt->execute($ids);

        $items    = [];
        $subtotal = 0.0;

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $qty = min(
                max(1, (int) ($sessionCart[(int) $row['variant_id']] ?? 1)),
                max(0, (int) $row['stock_quantity'])
            );

            $row['cart_qty']        = $qty;
            $row['cart_unit_price'] = (float) $row['retail_price'];
            $row['cart_line_total'] = (float) $row['retail_price'] * $qty;
            $subtotal               += $row['cart_line_total'];
            $items[]                = $row;
        }

        return [
            'items'       => $items,
            'subtotal'    => $subtotal,
            'delivery_fee'=> $deliveryFee,
            'grand_total' => $subtotal + $deliveryFee,
        ];
    }
}
