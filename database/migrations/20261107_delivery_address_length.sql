-- Allow the complete address line, locality, and ZIP code in customer_orders.
ALTER TABLE customer_orders MODIFY delivery_address VARCHAR(750) DEFAULT NULL;
