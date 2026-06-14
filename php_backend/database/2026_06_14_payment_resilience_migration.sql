-- Payment resilience, callback retry queue, and audit trail.

CREATE TABLE IF NOT EXISTS payment_audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  payment_id BIGINT UNSIGNED NULL,
  checkout_request_id VARCHAR(120) NULL,
  event_type VARCHAR(80) NOT NULL,
  status VARCHAR(40) NOT NULL,
  payload JSON NULL,
  context JSON NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_payment_audit_logs_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL,
  INDEX idx_payment_audit_logs_payment (payment_id),
  INDEX idx_payment_audit_logs_checkout (checkout_request_id),
  INDEX idx_payment_audit_logs_event_time (event_type, created_at)
);

CREATE TABLE IF NOT EXISTS payment_callback_queue (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  checkout_request_id VARCHAR(120) NULL,
  raw_payload LONGTEXT NOT NULL,
  payload JSON NULL,
  status ENUM('pending', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'pending',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  available_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at TIMESTAMP NULL,
  last_error TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_payment_callback_queue_status_available (status, available_at),
  INDEX idx_payment_callback_queue_checkout (checkout_request_id)
);
