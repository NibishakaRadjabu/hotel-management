USE hotel_management;

CREATE TABLE website_media (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  media_type ENUM('hero_image', 'room_image', 'hotel_video') NOT NULL,
  room_id INT UNSIGNED NULL,
  file_path VARCHAR(255) NOT NULL,
  caption VARCHAR(160) NOT NULL DEFAULT '',
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_website_media_type_active_order (media_type, is_active, sort_order, id),
  KEY idx_website_media_room (room_id),
  CONSTRAINT fk_website_media_room FOREIGN KEY (room_id) REFERENCES rooms (id) ON DELETE CASCADE,
  CONSTRAINT fk_website_media_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;
