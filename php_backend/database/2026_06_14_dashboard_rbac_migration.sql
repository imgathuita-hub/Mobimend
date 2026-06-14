-- Dashboard role-based access support.

ALTER TABLE users
  MODIFY role ENUM('customer', 'wholesale_customer', 'technician', 'finance', 'admin', 'super_admin') NOT NULL DEFAULT 'customer';
