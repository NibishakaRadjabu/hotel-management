USE hotel_management;

ALTER TABLE payments
  MODIFY COLUMN payment_method ENUM('cash', 'mobile_money', 'card', 'bank', 'flutterwave', 'paypal', 'test') NOT NULL;
