<?php

declare(strict_types=1);

namespace Mobimend\Services;

use PDO;

final class InventoryLedger
{
    public static function recordMovement(PDO $pdo, array $movement): void
    {
        $quantityDelta = (int) ($movement['quantity_delta'] ?? 0);
        if ($quantityDelta === 0) {
            return;
        }

        $previousQuantity = (int) ($movement['previous_quantity'] ?? 0);
        $newQuantity = (int) ($movement['new_quantity'] ?? ($previousQuantity + $quantityDelta));
        $unitBuyPrice = (float) ($movement['unit_buy_price'] ?? 0);
        $unitSellPrice = (float) ($movement['unit_sell_price'] ?? 0);
        $absoluteQuantity = abs($quantityDelta);

        $stmt = $pdo->prepare(
            'INSERT INTO stock_movements
             (inventory_item_id, product_variant_id, order_item_id, movement_type, source, quantity_delta,
              previous_quantity, new_quantity, unit_buy_price, unit_sell_price, total_cost, total_revenue,
              profit, reason, created_by_user_id, created_at)
             VALUES
             (:inventory_item_id, :product_variant_id, :order_item_id, :movement_type, :source, :quantity_delta,
              :previous_quantity, :new_quantity, :unit_buy_price, :unit_sell_price, :total_cost, :total_revenue,
              :profit, :reason, :created_by_user_id, :created_at)'
        );
        $stmt->execute([
            'inventory_item_id' => (int) $movement['inventory_item_id'],
            'product_variant_id' => !empty($movement['product_variant_id']) ? (int) $movement['product_variant_id'] : null,
            'order_item_id' => !empty($movement['order_item_id']) ? (int) $movement['order_item_id'] : null,
            'movement_type' => (string) ($movement['movement_type'] ?? ($quantityDelta > 0 ? 'receive' : 'fulfill')),
            'source' => (string) ($movement['source'] ?? 'inventory'),
            'quantity_delta' => $quantityDelta,
            'previous_quantity' => $previousQuantity,
            'new_quantity' => $newQuantity,
            'unit_buy_price' => $unitBuyPrice,
            'unit_sell_price' => $unitSellPrice,
            'total_cost' => (float) ($movement['total_cost'] ?? ($unitBuyPrice * $absoluteQuantity)),
            'total_revenue' => (float) ($movement['total_revenue'] ?? ($quantityDelta < 0 ? $unitSellPrice * $absoluteQuantity : 0)),
            'profit' => (float) ($movement['profit'] ?? ($quantityDelta < 0 ? ($unitSellPrice - $unitBuyPrice) * $absoluteQuantity : 0)),
            'reason' => (string) ($movement['reason'] ?? ''),
            'created_by_user_id' => !empty($movement['created_by_user_id']) ? (int) $movement['created_by_user_id'] : null,
            'created_at' => (string) ($movement['created_at'] ?? now()),
        ]);
    }

    public static function mirrorTransaction(PDO $pdo, array $movement): void
    {
        $quantityDelta = (int) ($movement['quantity_delta'] ?? 0);
        if ($quantityDelta === 0) {
            return;
        }

        $quantity = abs($quantityDelta);
        $unitBuyPrice = (float) ($movement['unit_buy_price'] ?? 0);
        $unitSellPrice = (float) ($movement['unit_sell_price'] ?? 0);

        $stmt = $pdo->prepare(
            'INSERT INTO inventory_transactions
             (inventory_item_id, order_item_id, brand, model, part_type, quantity, unit_buy_price, unit_sell_price,
              total_cost, total_revenue, profit, source, created_at)
             VALUES
             (:inventory_item_id, :order_item_id, :brand, :model, :part_type, :quantity, :unit_buy_price, :unit_sell_price,
              :total_cost, :total_revenue, :profit, :source, :created_at)'
        );
        $stmt->execute([
            'inventory_item_id' => (int) $movement['inventory_item_id'],
            'order_item_id' => !empty($movement['order_item_id']) ? (int) $movement['order_item_id'] : null,
            'brand' => (string) ($movement['brand'] ?? ''),
            'model' => (string) ($movement['model'] ?? ''),
            'part_type' => (string) ($movement['part_type'] ?? ''),
            'quantity' => $quantityDelta,
            'unit_buy_price' => $unitBuyPrice,
            'unit_sell_price' => $unitSellPrice,
            'total_cost' => (float) ($movement['total_cost'] ?? ($unitBuyPrice * $quantity)),
            'total_revenue' => (float) ($movement['total_revenue'] ?? ($quantityDelta < 0 ? $unitSellPrice * $quantity : 0)),
            'profit' => (float) ($movement['profit'] ?? ($quantityDelta < 0 ? ($unitSellPrice - $unitBuyPrice) * $quantity : 0)),
            'source' => (string) ($movement['source'] ?? 'inventory'),
            'created_at' => (string) ($movement['created_at'] ?? now()),
        ]);
    }

    public static function enqueueReorderAlert(PDO $pdo, array $item): void
    {
        $quantity = (int) ($item['quantity'] ?? 0);
        $reorderPoint = (int) ($item['reorder_point'] ?? $item['low_stock_threshold'] ?? 0);

        if ($reorderPoint <= 0 || $quantity > $reorderPoint) {
            return;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO inventory_alert_jobs
             (inventory_item_id, job_type, payload, status, available_at, created_at)
             VALUES
             (:inventory_item_id, "low_stock", :payload, "pending", :available_at, :created_at)'
        );
        $stmt->execute([
            'inventory_item_id' => (int) $item['inventory_item_id'],
            'payload' => json_encode([
                'inventory_item_id' => (int) $item['inventory_item_id'],
                'product_variant_id' => !empty($item['product_variant_id']) ? (int) $item['product_variant_id'] : null,
                'brand' => (string) ($item['brand'] ?? ''),
                'model' => (string) ($item['model'] ?? ''),
                'part_type' => (string) ($item['part_type'] ?? ''),
                'quantity' => $quantity,
                'reorder_point' => $reorderPoint,
            ], JSON_THROW_ON_ERROR),
            'available_at' => now(),
            'created_at' => now(),
        ]);
    }
}
