-- Apply once to nomcha_db. Existing accounts remain customers by default.
ALTER TABLE user_credentials
  ADD COLUMN role ENUM('customer','staff','admin') NOT NULL DEFAULT 'customer' AFTER password;

-- The existing protected bootstrap account is the initial administrator.
UPDATE user_credentials SET role = 'admin' WHERE email_addr = 'admin@admin';

-- Provision trusted staff/admin accounts by changing role through a controlled admin process.
-- Never accept role from public registration or login form input.
