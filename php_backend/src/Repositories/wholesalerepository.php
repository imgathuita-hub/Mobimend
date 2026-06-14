<?php

declare(strict_types=1);

namespace Mobimend\Repositories;

use PDO;

/**
 * All database queries for the wholesale catalog and cart checkout.
 * Keeps wholesale.php as a thin view layer — no raw SQL in templates.
 */
final class WholesaleRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // ── Schema detection (cached per request) ────────────────────────────────

    public function hasCatalogChannel(): bool
    {
        static $result = null;
        if ($result !== null) {
            return $result;
        }

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = "products"
               AND COLUMN_NAME  = "catalog_channel"'
        );
        $stmt->execute();
        $result = (int) $stmt->fetchColumn() > 0;
        return $result;
    }

    // ── Catalog ──────────────────────────────────────────────────────────────

    /** @return list<string> */
    public function activeBrands(): array
    {
        $channelWhere = $this->hasCatalogChannel()
            ? ' WHERE p.catalog_channel IN ("wholesale", "both") OR p.id IS NULL'
            : '';

        $stmt = $this->pdo->query(
            'SELECT DISTINCT ii.brand
             FROM inventory_items ii
             LEFT JOIN product_variants pv ON pv.id = ii.product_variant_id
             LEFT JOIN products p ON p.id = pv.product_id'
            . $channelWhere
            . ' ORDER BY ii.brand ASC'
        );

        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'brand');
    }

    /** @return list<array<string,mixed>> */
    public function catalogItems(string $brand = ''): array
    {
        $channelSelect = $this->hasCatalogChannel()
            ? 'p.catalog_channel'
            : '"wholesale" AS catalog_channel';

        $channelFilter = $this->hasCatalogChannel()
            ? ' AND (p.catalog_channel IN ("wholesale", "both") OR p.id IS NULL)'
            : '';

        $sql = 'SELECT ii.*, p.minimum_wholesale_quantity, p.media_url, p.name AS product_name,
                       ' . $channelSelect . ', pv.sku
                FROM inventory_items ii
                LEFT JOIN product_variants pv ON pv.id = ii.product_variant_id
                LEFT JOIN products p ON p.id = pv.product_id
                WHERE ii.quantity > 0' . $channelFilter;

        $params = [];
        if ($brand !== '') {
            $sql .= ' AND ii.brand = :brand';
            $params['brand'] = $brand;
        }

        $sql .= ' ORDER BY ii.brand ASC, ii.model ASC, ii.part_type ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Cart hydration ───────────────────────────────────────────────────────

    /**
     * Given a session cart (itemId => qty), return enriched rows with
     * cart_quantity, cart_unit_price, cart_line_total already computed.
     *
     * @param  array<int,int>               $sessionCart
     * @return array{items: list<array<string,mixed>>, total: float}
     */
    public function hydrateCart(array $sessionCart): array
    {
        if ($sessionCart === []) {
            return ['items' => [], 'total' => 0.0];
        }

        $ids          = array_values(array_filter(array_map('intval', array_keys($sessionCart))));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $channelFilter = $this->hasCatalogChannel()
            ? ' AND (p.catalog_channel IN ("wholesale", "both") OR p.id IS NULL)'
            : '';

        $stmt = $this->pdo->prepare(
            'SELECT ii.*, p.minimum_wholesale_quantity, p.media_url, p.name AS product_name, pv.sku
             FROM inventory_items ii
             LEFT JOIN product_variants pv ON pv.id = ii.product_variant_id
             LEFT JOIN products p ON p.id = pv.product_id
             WHERE ii.id IN (' . $placeholders . ') AND ii.quantity > 0' . $channelFilter
        );
        $stmt->execute($ids);

        $items = [];
        $total = 0.0;

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $moq      = max(1, (int) ($row['minimum_wholesale_quantity'] ?? 5));
            $quantity = min(
                max($moq, (int) ($sessionCart[(int) $row['id']] ?? $moq)),
                max(0, (int) $row['quantity'])
            );

            if ($quantity < $moq) {
                continue;
            }

            $unitPrice = (float) $row['wholesale_price'] > 0
                ? (float) $row['wholesale_price']
                : (float) $row['sell_price'];

            $row['cart_quantity']  = $quantity;
            $row['cart_unit_price']  = $unitPrice;
            $row['cart_line_total']  = $unitPrice * $quantity;
            $total += (float) $row['cart_line_total'];
            $items[] = $row;
        }

        return ['items' => $items, 'total' => $total];
    }

    // ── Single-item fetch for add-to-cart ────────────────────────────────────

    /** @return array<string,mixed>|false */
    public function findCartableItem(int $itemId): array|false
    {
        $channelFilter = $this->hasCatalogChannel()
            ? ' AND (p.catalog_channel IN ("wholesale", "both") OR p.id IS NULL)'
            : '';

        $stmt = $this->pdo->prepare(
            'SELECT ii.*, p.minimum_wholesale_quantity
             FROM inventory_items ii
             LEFT JOIN product_variants pv ON pv.id = ii.product_variant_id
             LEFT JOIN products p ON p.id = pv.product_id
             WHERE ii.id = :id AND ii.quantity > 0' . $channelFilter . '
             LIMIT 1'
        );
        $stmt->execute(['id' => $itemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : false;
    }
}
