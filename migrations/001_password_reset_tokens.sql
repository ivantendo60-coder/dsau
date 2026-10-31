CREATE TABLE IF NOT EXISTS password_reset_tokens (
  admin_id   INT UNSIGNED NOT NULL PRIMARY KEY,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_reset_expires (expires_at),
  CONSTRAINT fk_reset_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE
) ENGINE=InnoDB;
