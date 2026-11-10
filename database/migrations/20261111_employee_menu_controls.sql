-- Apply after 20261110_role_based_access.sql and 20261107_auth_otp.sql.
ALTER TABLE user_credentials
  ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER role;

ALTER TABLE auth_otp_challenges
  MODIFY purpose ENUM('registration','password_reset','account_deletion') NOT NULL;

ALTER TABLE menu_items
  ADD COLUMN publish_at DATETIME DEFAULT NULL AFTER is_available,
  ADD COLUMN customizations_json JSON DEFAULT NULL AFTER publish_at;

-- Existing menu items remain immediately visible. Future additions can be scheduled with publish_at.
