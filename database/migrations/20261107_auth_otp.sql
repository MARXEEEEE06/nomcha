-- Apply once to nomcha_db before using email OTP registration or recovery.
CREATE TABLE IF NOT EXISTS auth_otp_challenges (
  challenge_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email_addr VARCHAR(255) NOT NULL,
  purpose ENUM('registration', 'password_reset') NOT NULL,
  user_id INT(11) DEFAULT NULL,
  otp_hash CHAR(64) NOT NULL,
  registration_data JSON DEFAULT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  consumed_at DATETIME DEFAULT NULL,
  PRIMARY KEY (challenge_id),
  KEY auth_otp_email_purpose_created (email_addr, purpose, created_at),
  KEY auth_otp_expiry (expires_at),
  KEY auth_otp_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS auth_security_logs (
  log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_name VARCHAR(50) NOT NULL,
  email_addr VARCHAR(255) DEFAULT NULL,
  purpose VARCHAR(32) DEFAULT NULL,
  outcome VARCHAR(32) NOT NULL,
  details JSON DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (log_id),
  KEY auth_security_logs_created (created_at),
  KEY auth_security_logs_email (email_addr, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
