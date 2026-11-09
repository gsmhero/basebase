-- Multivendor shop + dropshipping (MySQL 8 / MariaDB 10.4+)
CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('customer','vendor','supplier','admin') NOT NULL DEFAULT 'customer',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE vendors (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  shop_name VARCHAR(190) NOT NULL,
  commission_pct DECIMAL(5,2) NOT NULL DEFAULT 10.00,
  status ENUM('pending','active','suspended') DEFAULT 'pending',
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- Dropship suppliers: feed-based product import + order forwarding
CREATE TABLE suppliers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  feed_url VARCHAR(500),
  feed_format ENUM('yml','csv','json','api') DEFAULT 'yml',
  api_endpoint VARCHAR(500),
  markup_pct DECIMAL(5,2) DEFAULT 20.00,
  last_sync_at DATETIME NULL
) ENGINE=InnoDB;

CREATE TABLE products (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vendor_id INT UNSIGNED NOT NULL,
  supplier_id INT UNSIGNED NULL,
  supplier_sku VARCHAR(120) NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT,
  cost_price DECIMAL(12,2) NULL,
  price DECIMAL(12,2) NOT NULL,
  stock INT NOT NULL DEFAULT 0,
  is_dropship TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY supplier_item (supplier_id, supplier_sku),
  FOREIGN KEY (vendor_id) REFERENCES vendors(id),
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
) ENGINE=InnoDB;

CREATE TABLE orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  total DECIMAL(12,2) NOT NULL,
  status ENUM('new','paid','shipped','done','cancelled') DEFAULT 'new',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- Order splits per vendor; dropship items are forwarded to the supplier
CREATE TABLE order_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  vendor_id INT UNSIGNED NOT NULL,
  qty INT NOT NULL,
  price DECIMAL(12,2) NOT NULL,
  commission DECIMAL(12,2) NOT NULL DEFAULT 0,
  fulfillment ENUM('vendor','dropship') DEFAULT 'vendor',
  supplier_order_ref VARCHAR(120) NULL,
  tracking_no VARCHAR(120) NULL,
  item_status ENUM('new','forwarded','shipped','delivered') DEFAULT 'new',
  FOREIGN KEY (order_id) REFERENCES orders(id),
  FOREIGN KEY (product_id) REFERENCES products(id),
  FOREIGN KEY (vendor_id) REFERENCES vendors(id)
) ENGINE=InnoDB;
