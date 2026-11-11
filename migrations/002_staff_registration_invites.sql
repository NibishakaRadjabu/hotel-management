USE hotel_management;

CREATE TABLE IF NOT EXISTS staff_invitations (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  email VARCHAR(190) NOT NULL,
  role ENUM('admin', 'manager', 'receptionist', 'housekeeper', 'chef', 'restaurant_cashier', 'concierge', 'accountant', 'maintenance_officer', 'security_guard') NOT NULL,
  created_by INT UNSIGNED NULL,
  expires_at DATETIME NOT NULL,
  consumed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_staff_invitations_token_hash (token_hash),
  KEY idx_staff_invitations_email_expiry (email, expires_at, consumed_at),
  KEY idx_staff_invitations_expiry (expires_at),
  CONSTRAINT fk_staff_invitation_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;
