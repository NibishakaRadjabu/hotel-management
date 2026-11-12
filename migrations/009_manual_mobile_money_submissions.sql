USE hotel_management;

CREATE TABLE manual_payment_submissions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id INT UNSIGNED NOT NULL,
  payment_method ENUM('mobile_money') NOT NULL,
  transaction_reference VARCHAR(100) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  status ENUM('pending', 'verified', 'rejected', 'expired') NOT NULL DEFAULT 'pending',
  reviewed_by INT UNSIGNED NULL,
  submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_manual_payment_booking (booking_id),
  UNIQUE KEY uq_manual_payment_reference (transaction_reference),
  KEY idx_manual_payment_status_submitted (status, submitted_at),
  CONSTRAINT fk_manual_payment_booking FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE,
  CONSTRAINT fk_manual_payment_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_manual_payment_amount CHECK (amount > 0)
) ENGINE=InnoDB;
