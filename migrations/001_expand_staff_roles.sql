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

ALTER TABLE users
  MODIFY COLUMN role ENUM(
    'admin',
    'manager',
    'receptionist',
    'housekeeper',
    'chef',
    'restaurant_cashier',
    'concierge',
    'accountant',
    'maintenance_officer',
    'security_guard'
  ) NOT NULL;

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

ALTER TABLE rooms
  MODIFY COLUMN status ENUM('available', 'cleaning', 'cleaned', 'maintenance')
  NOT NULL DEFAULT 'available';

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
