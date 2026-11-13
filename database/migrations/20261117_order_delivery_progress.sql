-- Store the calculated route distance for delivery ETA simulations in order history.
ALTER TABLE customer_orders
  ADD COLUMN delivery_distance_km DECIMAL(8,2) DEFAULT NULL AFTER delivery_fee;
