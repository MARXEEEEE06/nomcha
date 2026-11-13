-- Apply after 20261112_profile_employee_menu_media.sql to enable OTP login.
ALTER TABLE auth_otp_challenges
  MODIFY purpose ENUM('registration','password_reset','account_deletion','email_change','login') NOT NULL;
