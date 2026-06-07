-- Inventory safety migration for existing Mobimend MySQL databases.


ALTER TABLE products
  ADD COLUMN IF NOT EXISTS media_url VARCHAR(512) NULL AFTER status;

UPDATE products
SET media_url = image_path
WHERE media_url IS NULL
  AND image_path IS NOT NULL;

ALTER TABLE product_variants
  ADD COLUMN IF NOT EXISTS reorder_point INT NOT NULL DEFAULT 5 AFTER low_stock_threshold;

ALTER TABLE product_variants
  ADD COLUMN IF NOT EXISTS low_stock TINYINT(1) NOT NULL DEFAULT 0 AFTER reorder_point;

UPDATE product_variants
SET reorder_point = low_stock_threshold
WHERE reorder_point = 5
  AND low_stock_threshold <> 5;

ALTER TABLE inventory_items
  ADD COLUMN IF NOT EXISTS reorder_point INT NOT NULL DEFAULT 5 AFTER low_stock_threshold;

ALTER TABLE inventory_items
  ADD COLUMN IF NOT EXISTS low_stock TINYINT(1) NOT NULL DEFAULT 0 AFTER reorder_point;

UPDATE inventory_items
SET reorder_point = low_stock_threshold
WHERE reorder_point = 5
  AND low_stock_threshold <> 5;

UPDATE product_variants
SET low_stock = stock_quantity <= reorder_point;

UPDATE inventory_items
SET low_stock = quantity <= reorder_point;

CREATE TABLE IF NOT EXISTS stock_movements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  inventory_item_id BIGINT UNSIGNED NOT NULL,
  product_variant_id BIGINT UNSIGNED NULL,
  order_item_id BIGINT UNSIGNED NULL,
  movement_type ENUM('receive', 'fulfill', 'return', 'adjustment', 'correction') NOT NULL DEFAULT 'adjustment',
  source VARCHAR(80) NOT NULL DEFAULT 'inventory',
  quantity_delta INT NOT NULL,
  previous_quantity INT NOT NULL DEFAULT 0,
  new_quantity INT NOT NULL DEFAULT 0,
  unit_buy_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  unit_sell_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_revenue DECIMAL(12,2) NOT NULL DEFAULT 0,
  profit DECIMAL(12,2) NOT NULL DEFAULT 0,
  reason VARCHAR(255) NOT NULL DEFAULT '',
  created_by_user_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_stock_movements_item FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id),
  CONSTRAINT fk_stock_movements_variant FOREIGN KEY (product_variant_id) REFERENCES product_variants(id) ON DELETE SET NULL,
  CONSTRAINT fk_stock_movements_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_stock_movements_item_time (inventory_item_id, created_at),
  INDEX idx_stock_movements_order_item (order_item_id),
  INDEX idx_stock_movements_source (source),
  INDEX idx_stock_movements_type (movement_type)
);

CREATE TABLE IF NOT EXISTS inventory_alert_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  inventory_item_id BIGINT UNSIGNED NOT NULL,
  job_type VARCHAR(80) NOT NULL DEFAULT 'low_stock',
  payload JSON NOT NULL,
  status ENUM('pending', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'pending',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  available_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_inventory_alert_jobs_item FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON DELETE CASCADE,
  INDEX idx_inventory_alert_jobs_status_available (status, available_at),
  INDEX idx_inventory_alert_jobs_item (inventory_item_id)
);

INSERT INTO stock_movements
  (inventory_item_id, product_variant_id, movement_type, source, quantity_delta, previous_quantity, new_quantity,
   unit_buy_price, unit_sell_price, total_cost, total_revenue, profit, reason, created_at)
SELECT
  ii.id,
  ii.product_variant_id,
  'receive',
  'migration_baseline',
  ii.quantity,
  0,
  ii.quantity,
  ii.buy_price,
  ii.sell_price,
  ii.buy_price * ii.quantity,
  0,
  0,
  'Baseline stock captured during inventory safety migration',
  NOW()
FROM inventory_items ii
LEFT JOIN stock_movements sm ON sm.inventory_item_id = ii.id
WHERE ii.quantity > 0
  AND sm.id IS NULL;

INSERT INTO inventory_alert_jobs
  (inventory_item_id, job_type, payload, status, available_at, created_at)
SELECT
  ii.id,
  'low_stock',
  JSON_OBJECT(
    'inventory_item_id', ii.id,
    'product_variant_id', ii.product_variant_id,
    'brand', ii.brand,
    'model', ii.model,
    'part_type', ii.part_type,
    'quantity', ii.quantity,
    'reorder_point', ii.reorder_point
  ),
  'pending',
  NOW(),
  NOW()
FROM inventory_items ii
LEFT JOIN inventory_alert_jobs jobs
  ON jobs.inventory_item_id = ii.id
 AND jobs.job_type = 'low_stock'
 AND jobs.status IN ('pending', 'processing')
WHERE ii.low_stock = 1
  AND jobs.id IS NULL;
