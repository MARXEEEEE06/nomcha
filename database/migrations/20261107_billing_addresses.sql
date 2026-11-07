-- Apply once to nomcha_db. Existing home_addr values remain available as legacy data.
CREATE TABLE IF NOT EXISTS user_addresses (
  address_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT(11) NOT NULL,
  recipient_name VARCHAR(120) NOT NULL,
  province VARCHAR(120) NOT NULL,
  municipality VARCHAR(120) NOT NULL,
  barangay VARCHAR(120) NOT NULL,
  latitude DECIMAL(10,7) DEFAULT NULL,
  longitude DECIMAL(10,7) DEFAULT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (address_id),
  KEY user_addresses_user_default (user_id, is_default),
  CONSTRAINT user_addresses_user_fk FOREIGN KEY (user_id) REFERENCES user_info (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
