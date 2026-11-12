USE hotel_management;

ALTER TABLE online_payment_attempts
  ADD COLUMN gateway_order_id VARCHAR(40) NULL AFTER tx_ref,
  ADD COLUMN expected_amount_rwf DECIMAL(12,2) NULL AFTER expected_amount,
  ADD COLUMN currency CHAR(3) NULL AFTER expected_amount_rwf,
  ADD UNIQUE KEY uq_online_payment_gateway_order (gateway_order_id);

ALTER TABLE payments
  MODIFY COLUMN payment_method ENUM('cash', 'mobile_money', 'card', 'bank', 'flutterwave', 'paypal') NOT NULL,
  ADD COLUMN gateway_amount DECIMAL(12,2) NULL AFTER gateway_reference,
  ADD COLUMN gateway_currency CHAR(3) NULL AFTER gateway_amount;
