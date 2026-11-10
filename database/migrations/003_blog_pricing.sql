CREATE TABLE settings (
  k VARCHAR(60) PRIMARY KEY,
  v VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

INSERT INTO settings (k, v) VALUES ('platform_commission', '10');

CREATE TABLE posts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vendor_id INT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  body MEDIUMTEXT,
  cover VARCHAR(255) NULL,
  status ENUM('draft','published') NOT NULL DEFAULT 'published',
  views INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  KEY posts_vendor (vendor_id),
  FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE post_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  post_id INT UNSIGNED NOT NULL,
  kind ENUM('product','set','group') NOT NULL,
  ref INT UNSIGNED NULL,
  label VARCHAR(80) NULL,
  pos INT NOT NULL DEFAULT 0,
  KEY pi_post (post_id),
  FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

UPDATE products SET promo_price = ROUND(price * 0.75, 2)
