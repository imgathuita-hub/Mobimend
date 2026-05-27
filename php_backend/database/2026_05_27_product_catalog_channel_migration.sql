-- Adds explicit catalog placement for retail accessories and wholesale spare parts.

ALTER TABLE products
  ADD COLUMN IF NOT EXISTS catalog_channel ENUM('shop', 'wholesale', 'both') NOT NULL DEFAULT 'shop'
  AFTER minimum_wholesale_quantity;

UPDATE products
SET catalog_channel = 'shop'
WHERE catalog_channel IS NULL OR catalog_channel = '';
