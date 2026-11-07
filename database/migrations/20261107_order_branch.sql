-- Apply once to nomcha_db after customer_orders has been created.
ALTER TABLE customer_orders ADD COLUMN branch_id VARCHAR(32) DEFAULT NULL AFTER fulfillment;
