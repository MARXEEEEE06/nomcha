-- Apply after the role and OTP migrations, once.
ALTER TABLE auth_otp_challenges
  MODIFY purpose ENUM('registration','password_reset','account_deletion','email_change') NOT NULL;

ALTER TABLE user_info
  ADD COLUMN photo_path VARCHAR(255) DEFAULT NULL AFTER mobileno;

ALTER TABLE menu_items
  ADD COLUMN item_kind ENUM('item','option') NOT NULL DEFAULT 'item' AFTER category_id,
  ADD COLUMN availability_scope ENUM('item_only','option_only','item_and_option') NOT NULL DEFAULT 'item_only' AFTER item_kind,
  ADD COLUMN image_data MEDIUMBLOB DEFAULT NULL AFTER image_url,
  ADD COLUMN image_mime VARCHAR(40) DEFAULT NULL AFTER image_data;

-- The flavor rows in this category are customization choices rather than sellable menu entries.
UPDATE menu_items SET item_kind = 'option', availability_scope = 'option_only'
 WHERE category_id = 1 AND name NOT LIKE '%pc Chikin Rack Fanverse Meal';
