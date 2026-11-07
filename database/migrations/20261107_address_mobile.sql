-- Apply once to nomcha_db after 20261107_billing_addresses.sql.
ALTER TABLE user_addresses ADD COLUMN mobile_no VARCHAR(13) DEFAULT NULL AFTER recipient_name;
UPDATE user_addresses a
JOIN user_info u ON u.user_id = a.user_id
SET a.mobile_no = u.mobileno
WHERE a.mobile_no IS NULL;
