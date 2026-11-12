ALTER TABLE agents ADD COLUMN api_secret VARCHAR(64) NULL;

ALTER TABLE users ADD COLUMN ext_id VARCHAR(80) NULL, ADD COLUMN is_managed TINYINT(1) NOT NULL DEFAULT 0, ADD UNIQUE KEY uq_users_agent_ext (agent_id, ext_id);

ALTER TABLE products ADD COLUMN external_id VARCHAR(80) NULL, ADD COLUMN ext_img_hash CHAR(32) NULL, ADD UNIQUE KEY uq_products_ext (vendor_id, external_id);

CREATE TABLE IF NOT EXISTS webhook_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  agent_id INT UNSIGNED NOT NULL,
  event VARCHAR(40) NOT NULL,
  event_uid CHAR(24) NOT NULL,
  payload MEDIUMTEXT NOT NULL,
  status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  next_try INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_due (status, next_try),
  KEY idx_agent (agent_id),
  FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
) ENGINE=InnoDB
