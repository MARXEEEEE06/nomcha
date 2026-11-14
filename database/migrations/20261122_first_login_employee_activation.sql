-- New employee accounts stay inactive until their first successful password or OTP login.
ALTER TABLE user_credentials
  ADD COLUMN first_login_activation TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active;
