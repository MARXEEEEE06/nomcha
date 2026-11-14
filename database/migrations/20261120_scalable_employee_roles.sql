-- Allow controlled employee roles to grow beyond the initial admin/staff pair.
ALTER TABLE user_credentials
  MODIFY role VARCHAR(32) NOT NULL DEFAULT 'customer';
