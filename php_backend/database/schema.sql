-- Mobimend MySQL schema
-- Supports phone repair bookings, accessories sales, wholesale pricing, content,
-- payments, inventory movement, and customer/admin workflows.

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(160) NOT NULL UNIQUE,
  phone VARCHAR(40) NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('customer', 'wholesale_customer', 'technician', 'admin', 'super_admin') NOT NULL DEFAULT 'customer',
  account_status ENUM('pending', 'active', 'suspended') NOT NULL DEFAULT 'active',
  email_verified_at TIMESTAMP NULL,
  last_login_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_users_role (role),
  INDEX idx_users_status (account_status)
);

CREATE TABLE IF NOT EXISTS user_addresses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  label VARCHAR(80) NOT NULL DEFAULT 'Default',
  recipient_name VARCHAR(120) NOT NULL,
  phone VARCHAR(40) NOT NULL,
  county VARCHAR(120) NOT NULL DEFAULT '',
  town VARCHAR(120) NOT NULL DEFAULT '',
  street_address VARCHAR(255) NOT NULL DEFAULT '',
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_user_addresses_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_user_addresses_user (user_id)
);

CREATE TABLE IF NOT EXISTS product_categories (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  parent_id BIGINT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  slug VARCHAR(140) NOT NULL UNIQUE,
  description TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_product_categories_parent FOREIGN KEY (parent_id) REFERENCES product_categories(id) ON DELETE SET NULL,
  INDEX idx_product_categories_parent (parent_id)
);

CREATE TABLE IF NOT EXISTS products (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  category_id BIGINT UNSIGNED NULL,
  name VARCHAR(180) NOT NULL,
  slug VARCHAR(200) NOT NULL UNIQUE,
  sku VARCHAR(80) NOT NULL UNIQUE,
  brand VARCHAR(120) NOT NULL DEFAULT '',
  compatible_brand VARCHAR(120) NOT NULL DEFAULT '',
  compatible_model VARCHAR(120) NOT NULL DEFAULT '',
  description TEXT NULL,
  retail_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  wholesale_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  minimum_wholesale_quantity INT UNSIGNED NOT NULL DEFAULT 1,
  status ENUM('draft', 'active', 'out_of_stock', 'archived') NOT NULL DEFAULT 'active',
  media_url VARCHAR(512) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES product_categories(id) ON DELETE SET NULL,
  INDEX idx_products_category (category_id),
  INDEX idx_products_status (status),
  FULLTEXT INDEX ft_products_search (name, brand, compatible_brand, compatible_model, description)
);

CREATE TABLE IF NOT EXISTS product_variants (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  product_id BIGINT UNSIGNED NOT NULL,
  sku VARCHAR(80) NOT NULL UNIQUE,
  variant_name VARCHAR(140) NOT NULL,
  color VARCHAR(80) NOT NULL DEFAULT '',
  quality_grade VARCHAR(80) NOT NULL DEFAULT '',
  retail_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  wholesale_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  stock_quantity INT NOT NULL DEFAULT 0,
  low_stock_threshold INT NOT NULL DEFAULT 5,
  reorder_point INT NOT NULL DEFAULT 5,
  low_stock TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_product_variants_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  INDEX idx_product_variants_product (product_id),
  INDEX idx_product_variants_stock (stock_quantity)
);

-- Existing PHP admin/wholesale pages use this table directly.
CREATE TABLE IF NOT EXISTS inventory_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  product_variant_id BIGINT UNSIGNED NULL,
  brand VARCHAR(120) NOT NULL,
  model VARCHAR(120) NOT NULL,
  part_type VARCHAR(120) NOT NULL,
  quantity INT NOT NULL DEFAULT 0,
  low_stock_threshold INT NOT NULL DEFAULT 5,
  reorder_point INT NOT NULL DEFAULT 5,
  low_stock TINYINT(1) NOT NULL DEFAULT 0,
  buy_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  sell_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  wholesale_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  status VARCHAR(40) NOT NULL DEFAULT 'in_stock',
  notes TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_inventory_items_variant FOREIGN KEY (product_variant_id) REFERENCES product_variants(id) ON DELETE SET NULL,
  INDEX idx_inventory_items_lookup (brand, model, part_type),
  INDEX idx_inventory_items_status (status),
  INDEX idx_inventory_items_stock (quantity)
);

CREATE TABLE IF NOT EXISTS inventory_transactions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  inventory_item_id BIGINT UNSIGNED NOT NULL,
  order_item_id BIGINT UNSIGNED NULL,
  brand VARCHAR(120) NOT NULL,
  model VARCHAR(120) NOT NULL,
  part_type VARCHAR(120) NOT NULL,
  quantity INT NOT NULL,
  unit_buy_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  unit_sell_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_revenue DECIMAL(12,2) NOT NULL DEFAULT 0,
  profit DECIMAL(12,2) NOT NULL DEFAULT 0,
  source VARCHAR(80) NOT NULL DEFAULT 'website_checkout',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_inventory_transactions_item FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id),
  INDEX idx_inventory_transactions_item (inventory_item_id),
  INDEX idx_inventory_transactions_source (source)
);

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

CREATE TABLE IF NOT EXISTS repair_services (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(140) NOT NULL,
  slug VARCHAR(160) NOT NULL UNIQUE,
  description TEXT NULL,
  device_brand VARCHAR(120) NOT NULL DEFAULT '',
  device_model VARCHAR(120) NOT NULL DEFAULT '',
  base_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  estimated_duration_minutes INT UNSIGNED NOT NULL DEFAULT 60,
  warranty_days INT UNSIGNED NOT NULL DEFAULT 30,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_repair_services_device (device_brand, device_model),
  FULLTEXT INDEX ft_repair_services_search (name, description, device_brand, device_model)
);

CREATE TABLE IF NOT EXISTS technicians (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  display_name VARCHAR(120) NOT NULL,
  phone VARCHAR(40) NOT NULL DEFAULT '',
  specialties TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_technicians_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Existing public repair/admin pages use this table directly.
CREATE TABLE IF NOT EXISTS repair_bookings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  repair_service_id BIGINT UNSIGNED NULL,
  technician_id BIGINT UNSIGNED NULL,
  customer_name VARCHAR(120) NOT NULL,
  phone_number VARCHAR(40) NOT NULL,
  email VARCHAR(160) NOT NULL DEFAULT '',
  device_model VARCHAR(120) NOT NULL,
  repair_type VARCHAR(120) NOT NULL,
  issue_description TEXT NOT NULL,
  diagnosis_notes TEXT NULL,
  estimated_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  final_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  status VARCHAR(40) NOT NULL DEFAULT 'Pending',
  booking_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  preferred_time_slot VARCHAR(80) NOT NULL DEFAULT '',
  completed_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_repair_bookings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_repair_bookings_service FOREIGN KEY (repair_service_id) REFERENCES repair_services(id) ON DELETE SET NULL,
  CONSTRAINT fk_repair_bookings_technician FOREIGN KEY (technician_id) REFERENCES technicians(id) ON DELETE SET NULL,
  INDEX idx_repair_bookings_status (status),
  INDEX idx_repair_bookings_date (booking_date),
  INDEX idx_repair_bookings_user (user_id)
);

CREATE TABLE IF NOT EXISTS repair_status_updates (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  repair_booking_id BIGINT UNSIGNED NOT NULL,
  changed_by_user_id BIGINT UNSIGNED NULL,
  status VARCHAR(40) NOT NULL,
  note TEXT NULL,
  customer_visible TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_repair_status_updates_booking FOREIGN KEY (repair_booking_id) REFERENCES repair_bookings(id) ON DELETE CASCADE,
  CONSTRAINT fk_repair_status_updates_user FOREIGN KEY (changed_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_repair_status_updates_booking (repair_booking_id)
);

CREATE TABLE IF NOT EXISTS orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  repair_booking_id BIGINT UNSIGNED NULL,
  order_number VARCHAR(40) NOT NULL UNIQUE,
  order_type ENUM('product', 'repair', 'mixed', 'wholesale') NOT NULL DEFAULT 'product',
  status ENUM('pending', 'confirmed', 'processing', 'ready', 'shipped', 'completed', 'cancelled', 'refunded') NOT NULL DEFAULT 'pending',
  payment_status ENUM('unpaid', 'partially_paid', 'paid', 'failed', 'refunded') NOT NULL DEFAULT 'unpaid',
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
  discount_total DECIMAL(12,2) NOT NULL DEFAULT 0,
  delivery_fee DECIMAL(12,2) NOT NULL DEFAULT 0,
  tax_total DECIMAL(12,2) NOT NULL DEFAULT 0,
  grand_total DECIMAL(12,2) NOT NULL DEFAULT 0,
  customer_name VARCHAR(120) NOT NULL DEFAULT '',
  customer_email VARCHAR(160) NOT NULL DEFAULT '',
  customer_phone VARCHAR(40) NOT NULL DEFAULT '',
  delivery_address TEXT NULL,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_orders_repair_booking FOREIGN KEY (repair_booking_id) REFERENCES repair_bookings(id) ON DELETE SET NULL,
  INDEX idx_orders_user (user_id),
  INDEX idx_orders_status (status),
  INDEX idx_orders_payment_status (payment_status)
);

CREATE TABLE IF NOT EXISTS order_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NULL,
  product_variant_id BIGINT UNSIGNED NULL,
  repair_service_id BIGINT UNSIGNED NULL,
  item_name VARCHAR(180) NOT NULL,
  sku VARCHAR(80) NOT NULL DEFAULT '',
  quantity INT UNSIGNED NOT NULL DEFAULT 1,
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
  CONSTRAINT fk_order_items_variant FOREIGN KEY (product_variant_id) REFERENCES product_variants(id) ON DELETE SET NULL,
  CONSTRAINT fk_order_items_repair_service FOREIGN KEY (repair_service_id) REFERENCES repair_services(id) ON DELETE SET NULL,
  INDEX idx_order_items_order (order_id)
);

CREATE TABLE IF NOT EXISTS order_status_updates (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  changed_by_user_id BIGINT UNSIGNED NULL,
  status VARCHAR(40) NOT NULL,
  note TEXT NULL,
  customer_visible TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_order_status_updates_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_order_status_updates_user FOREIGN KEY (changed_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_order_status_updates_order (order_id)
);

CREATE TABLE IF NOT EXISTS payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NULL,
  repair_booking_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NULL,
  payment_method ENUM('mpesa_stk', 'bank_transfer', 'cash', 'card') NOT NULL DEFAULT 'mpesa_stk',
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  currency CHAR(3) NOT NULL DEFAULT 'KES',
  status ENUM('pending', 'processing', 'paid', 'failed', 'cancelled', 'refunded', 'requires_review') NOT NULL DEFAULT 'pending',
  merchant_request_id VARCHAR(120) NULL,
  checkout_request_id VARCHAR(120) NULL,
  mpesa_receipt_number VARCHAR(120) NULL,
  phone_number VARCHAR(40) NOT NULL DEFAULT '',
  bank_reference VARCHAR(120) NULL,
  statement_upload_path VARCHAR(255) NULL,
  raw_response JSON NULL,
  verified_by_user_id BIGINT UNSIGNED NULL,
  verified_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
  CONSTRAINT fk_payments_repair_booking FOREIGN KEY (repair_booking_id) REFERENCES repair_bookings(id) ON DELETE SET NULL,
  CONSTRAINT fk_payments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_payments_verified_by FOREIGN KEY (verified_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_payments_status (status),
  INDEX idx_payments_checkout_request (checkout_request_id),
  INDEX idx_payments_receipt (mpesa_receipt_number)
);

CREATE TABLE IF NOT EXISTS wholesale_applications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  business_name VARCHAR(160) NOT NULL,
  contact_name VARCHAR(120) NOT NULL,
  phone VARCHAR(40) NOT NULL,
  email VARCHAR(160) NOT NULL,
  business_location VARCHAR(180) NOT NULL DEFAULT '',
  kra_pin VARCHAR(80) NOT NULL DEFAULT '',
  status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  reviewed_by_user_id BIGINT UNSIGNED NULL,
  reviewed_at TIMESTAMP NULL,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_wholesale_applications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_wholesale_applications_reviewer FOREIGN KEY (reviewed_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_wholesale_applications_status (status)
);

CREATE TABLE IF NOT EXISTS wholesale_price_rules (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  product_id BIGINT UNSIGNED NULL,
  product_variant_id BIGINT UNSIGNED NULL,
  minimum_quantity INT UNSIGNED NOT NULL DEFAULT 1,
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  starts_at TIMESTAMP NULL,
  ends_at TIMESTAMP NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_wholesale_price_rules_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_wholesale_price_rules_variant FOREIGN KEY (product_variant_id) REFERENCES product_variants(id) ON DELETE CASCADE,
  INDEX idx_wholesale_price_rules_product (product_id),
  INDEX idx_wholesale_price_rules_variant (product_variant_id)
);

CREATE TABLE IF NOT EXISTS blog_categories (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  slug VARCHAR(140) NOT NULL UNIQUE,
  description TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS blog_posts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  author_id BIGINT UNSIGNED NULL,
  category_id BIGINT UNSIGNED NULL,
  title VARCHAR(180) NOT NULL,
  slug VARCHAR(200) NOT NULL UNIQUE,
  excerpt TEXT NULL,
  body LONGTEXT NOT NULL,
  featured_image_path VARCHAR(255) NULL,
  status ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'draft',
  published_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_blog_posts_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_blog_posts_category FOREIGN KEY (category_id) REFERENCES blog_categories(id) ON DELETE SET NULL,
  INDEX idx_blog_posts_status (status),
  FULLTEXT INDEX ft_blog_posts_search (title, excerpt, body)
);

CREATE TABLE IF NOT EXISTS device_knowledge_base (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  brand VARCHAR(120) NOT NULL,
  model VARCHAR(120) NOT NULL,
  common_issue VARCHAR(180) NOT NULL,
  symptoms TEXT NULL,
  recommended_service_id BIGINT UNSIGNED NULL,
  likely_parts TEXT NULL,
  customer_tip TEXT NULL,
  confidence_score DECIMAL(5,2) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_device_knowledge_service FOREIGN KEY (recommended_service_id) REFERENCES repair_services(id) ON DELETE SET NULL,
  INDEX idx_device_knowledge_device (brand, model),
  FULLTEXT INDEX ft_device_knowledge_search (common_issue, symptoms, likely_parts, customer_tip)
);
