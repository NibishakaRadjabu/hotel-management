CREATE DATABASE IF NOT EXISTS hotel_management
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE hotel_management;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  full_name VARCHAR(160) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin', 'manager', 'receptionist', 'housekeeper', 'chef', 'restaurant_cashier', 'concierge', 'accountant', 'maintenance_officer', 'security_guard') NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  must_change_password TINYINT(1) NOT NULL DEFAULT 1,
  session_version INT UNSIGNED NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role_active (role, is_active),
  CONSTRAINT fk_users_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS login_attempts (
  attempt_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  email VARCHAR(190) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  window_started_at DATETIME NOT NULL,
  attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (attempt_key),
  KEY idx_login_attempts_window (window_started_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_user_id INT UNSIGNED NULL,
  target_user_id INT UNSIGNED NULL,
  event_type VARCHAR(60) NOT NULL,
  ip_address VARCHAR(45) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_logs_actor_time (actor_user_id, created_at),
  KEY idx_audit_logs_target_time (target_user_id, created_at),
  CONSTRAINT fk_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_audit_target FOREIGN KEY (target_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

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

CREATE TABLE IF NOT EXISTS rooms (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  room_number VARCHAR(20) NOT NULL,
  room_type VARCHAR(80) NOT NULL,
  floor SMALLINT NOT NULL DEFAULT 1,
  price DECIMAL(12,2) NOT NULL,
  status ENUM('available', 'cleaning', 'cleaned', 'maintenance') NOT NULL DEFAULT 'available',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rooms_room_number (room_number),
  CONSTRAINT chk_rooms_price CHECK (price >= 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS room_assignments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  room_id INT UNSIGNED NOT NULL,
  assigned_by INT UNSIGNED NOT NULL,
  assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_room_assignment (user_id, room_id),
  KEY idx_room_assignments_room (room_id),
  CONSTRAINT fk_room_assignment_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_room_assignment_room FOREIGN KEY (room_id) REFERENCES rooms (id) ON DELETE CASCADE,
  CONSTRAINT fk_room_assignment_assigner FOREIGN KEY (assigned_by) REFERENCES users (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS guests (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  full_name VARCHAR(160) NOT NULL,
  phone VARCHAR(40) NOT NULL,
  id_passport VARCHAR(80) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_guests_phone (phone),
  KEY idx_guests_id_passport (id_passport)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS bookings (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  guest_id INT UNSIGNED NOT NULL,
  room_id INT UNSIGNED NOT NULL,
  checkin_date DATE NOT NULL,
  checkout_date DATE NOT NULL,
  nightly_rate DECIMAL(12,2) NOT NULL,
  total_amount DECIMAL(12,2) NOT NULL,
  status ENUM('pending', 'confirmed', 'checked_in', 'checked_out', 'cancelled') NOT NULL DEFAULT 'confirmed',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_bookings_room_dates (room_id, checkin_date, checkout_date, status),
  KEY idx_bookings_guest (guest_id),
  KEY idx_bookings_status_dates (status, checkin_date),
  CONSTRAINT fk_bookings_guest FOREIGN KEY (guest_id) REFERENCES guests (id),
  CONSTRAINT fk_bookings_room FOREIGN KEY (room_id) REFERENCES rooms (id),
  CONSTRAINT chk_bookings_dates CHECK (checkout_date > checkin_date),
  CONSTRAINT chk_bookings_amount CHECK (nightly_rate >= 0 AND total_amount >= 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id INT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  payment_method ENUM('cash', 'mobile_money', 'card', 'bank') NOT NULL,
  payment_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_payments_date (payment_date),
  KEY idx_payments_booking (booking_id),
  CONSTRAINT fk_payments_booking FOREIGN KEY (booking_id) REFERENCES bookings (id),
  CONSTRAINT chk_payments_amount CHECK (amount >= 0)
) ENGINE=InnoDB;
