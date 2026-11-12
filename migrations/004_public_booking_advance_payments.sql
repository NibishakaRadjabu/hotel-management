USE hotel_management;

ALTER TABLE guests
  ADD COLUMN email VARCHAR(190) NULL AFTER phone;

ALTER TABLE bookings
  ADD COLUMN booking_source ENUM('staff', 'online') NOT NULL DEFAULT 'staff' AFTER status,
  ADD COLUMN payment_deadline_at DATETIME NULL AFTER booking_source;

ALTER TABLE payments
  MODIFY COLUMN payment_method ENUM('cash', 'mobile_money', 'card', 'bank', 'flutterwave') NOT NULL,
  ADD COLUMN gateway_reference VARCHAR(100) NULL AFTER payment_method,
  ADD UNIQUE KEY uq_payments_gateway_reference (gateway_reference);

CREATE TABLE online_payment_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id INT UNSIGNED NOT NULL,
  tx_ref VARCHAR(50) NOT NULL,
  expected_amount DECIMAL(12,2) NOT NULL,
  status ENUM('initiated', 'successful', 'failed', 'needs_review') NOT NULL DEFAULT 'initiated',
  gateway_transaction_id VARCHAR(40) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_online_payment_tx_ref (tx_ref),
  UNIQUE KEY uq_online_payment_gateway_transaction (gateway_transaction_id),
  KEY idx_online_payment_booking_status (booking_id, status),
  CONSTRAINT fk_online_payment_booking FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE,
  CONSTRAINT chk_online_payment_amount CHECK (expected_amount > 0)
) ENGINE=InnoDB;
