USE hotel_management;

CREATE TABLE paypal_settings (
  id TINYINT UNSIGNED NOT NULL,
  client_id VARCHAR(255) NOT NULL,
  encrypted_secret TEXT NOT NULL,
  receiver_email VARCHAR(190) NOT NULL,
  currency CHAR(3) NOT NULL,
  rwf_per_unit DECIMAL(14,4) NOT NULL,
  mode ENUM('sandbox', 'live') NOT NULL DEFAULT 'sandbox',
  updated_by INT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT chk_paypal_settings_singleton CHECK (id = 1),
  CONSTRAINT fk_paypal_settings_updater FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;
