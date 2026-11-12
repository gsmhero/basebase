ALTER TABLE users MODIFY role ENUM('customer','vendor','supplier','admin','agent') NOT NULL DEFAULT 'customer';

CREATE TABLE IF NOT EXISTS agents (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL UNIQUE,
  agent_key VARCHAR(80) NULL UNIQUE,
  domain VARCHAR(190) NOT NULL DEFAULT '',
  status ENUM('new','pending','active','suspended') NOT NULL DEFAULT 'new',
  agency_fee DECIMAL(5,2) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE users ADD COLUMN agent_id INT UNSIGNED NULL, ADD KEY idx_users_agent (agent_id);

ALTER TABLE order_items ADD COLUMN agent_id INT UNSIGNED NULL, ADD COLUMN agent_fee DECIMAL(10,2) NOT NULL DEFAULT 0
