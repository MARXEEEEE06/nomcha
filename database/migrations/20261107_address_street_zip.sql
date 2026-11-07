-- Apply once to nomcha_db after the billing address and address mobile migrations.
ALTER TABLE user_addresses
  ADD COLUMN street_address VARCHAR(255) NOT NULL DEFAULT '' AFTER mobile_no,
  ADD COLUMN zip_code VARCHAR(10) NOT NULL DEFAULT '' AFTER barangay;
