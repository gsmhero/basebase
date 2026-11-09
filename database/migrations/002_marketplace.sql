ALTER TABLE users
  ADD COLUMN name VARCHAR(120) NOT NULL DEFAULT '' AFTER email,
  ADD COLUMN phone VARCHAR(40) NULL,
  ADD COLUMN last_seen DATETIME NULL;

ALTER TABLE vendors
  ADD COLUMN slug VARCHAR(120) NULL,
  ADD COLUMN bio TEXT NULL,
  ADD COLUMN blogging_type VARCHAR(190) NULL,
  ADD COLUMN avatar VARCHAR(255) NULL,
  ADD COLUMN socials JSON NULL,
  ADD COLUMN payout_type ENUM('selfemployed','ip','company') NOT NULL DEFAULT 'selfemployed',
  ADD COLUMN personal JSON NULL,
  ADD UNIQUE KEY vendors_slug (slug);

CREATE TABLE categories (
  id INT UNSIGNED PRIMARY KEY,
  parent_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  slug VARCHAR(120) NOT NULL UNIQUE,
  FOREIGN KEY (parent_id) REFERENCES categories(id)
) ENGINE=InnoDB;

INSERT INTO categories (id, parent_id, name, slug) VALUES
 (1,NULL,'Cosmetics','cosmetics'),(2,NULL,'Electronics','electronics'),(3,NULL,'Home & Living','home'),
 (4,NULL,'Fashion','fashion'),(5,NULL,'Kids','kids'),(6,NULL,'Sport & Outdoor','sport'),
 (11,1,'Skin care','skin-care'),(12,1,'Makeup','makeup'),(13,1,'Hair care','hair-care'),
 (21,2,'Audio','audio'),(22,2,'Smart gadgets','gadgets'),(23,2,'Accessories','e-accessories'),
 (31,3,'Kitchen','kitchen'),(32,3,'Decor','decor'),(33,3,'Textile','textile'),
 (41,4,'Women','women'),(42,4,'Men','men'),(43,4,'Bags','bags'),
 (51,5,'Toys','toys'),(52,5,'Baby care','baby'),
 (61,6,'Fitness','fitness'),(62,6,'Travel','travel');

ALTER TABLE products
  ADD COLUMN category_id INT UNSIGNED NULL,
  ADD COLUMN sku VARCHAR(80) NULL,
  ADD COLUMN short_desc VARCHAR(500) NULL,
  ADD COLUMN promo_price DECIMAL(12,2) NULL,
  ADD COLUMN reseller_pct DECIMAL(5,2) NOT NULL DEFAULT 15.00,
  ADD COLUMN image VARCHAR(255) NULL,
  ADD COLUMN gallery JSON NULL,
  ADD COLUMN attrs JSON NULL,
  ADD COLUMN variants JSON NULL,
  ADD COLUMN views INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN status ENUM('active','draft','archived') NOT NULL DEFAULT 'active',
  ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  ADD KEY products_cat (category_id),
  ADD KEY products_status (status);

ALTER TABLE orders
  ADD COLUMN name VARCHAR(120) NOT NULL DEFAULT '',
  ADD COLUMN phone VARCHAR(40) NOT NULL DEFAULT '',
  ADD COLUMN address VARCHAR(500) NOT NULL DEFAULT '',
  ADD COLUMN delivery_method VARCHAR(60) NOT NULL DEFAULT '',
  ADD COLUMN payment_method VARCHAR(40) NOT NULL DEFAULT 'cod',
  ADD COLUMN payment_status ENUM('unpaid','paid','refunded') NOT NULL DEFAULT 'unpaid',
  ADD COLUMN notes VARCHAR(500) NOT NULL DEFAULT '';

ALTER TABLE order_items
  MODIFY item_status ENUM('pending','prepare','shipped','on_delivery','delivered','archived','returned') NOT NULL DEFAULT 'pending',
  ADD COLUMN reseller_id INT UNSIGNED NULL,
  ADD COLUMN reseller_fee DECIMAL(12,2) NOT NULL DEFAULT 0,
  ADD COLUMN title VARCHAR(255) NOT NULL DEFAULT '',
  ADD COLUMN variant VARCHAR(190) NOT NULL DEFAULT '',
  ADD KEY oi_vendor (vendor_id),
  ADD KEY oi_reseller (reseller_id);

CREATE TABLE collection_items (
  vendor_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  grp VARCHAR(80) NOT NULL DEFAULT '',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (vendor_id, product_id),
  FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE product_sets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vendor_id INT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL,
  price DECIMAL(12,2) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE set_items (
  set_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  qty INT NOT NULL DEFAULT 1,
  PRIMARY KEY (set_id, product_id),
  FOREIGN KEY (set_id) REFERENCES product_sets(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE cart_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  shop_id INT UNSIGNED NOT NULL DEFAULT 0,
  variant VARCHAR(190) NOT NULL DEFAULT '',
  qty INT NOT NULL DEFAULT 1,
  price_override DECIMAL(12,2) NULL,
  UNIQUE KEY cart_uq (user_id, product_id, variant, shop_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE wishlist (
  user_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, product_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE reviews (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  rating TINYINT NOT NULL,
  body TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY review_uq (product_id, user_id),
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  from_id INT UNSIGNED NOT NULL,
  to_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NULL,
  body TEXT NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY msg_to (to_id, is_read),
  FOREIGN KEY (from_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (to_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE notifications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  body VARCHAR(500) NOT NULL,
  url VARCHAR(255) NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY n_user (user_id, is_read),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE wallet_tx (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  type ENUM('sale','commission','withdraw','adjust') NOT NULL,
  status ENUM('pending','done','rejected') NOT NULL DEFAULT 'done',
  ref VARCHAR(60) NULL,
  note VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY wallet_ref (ref),
  KEY w_user (user_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
