-- Preserve the offer name and discount terms used by each new order/redemption.
ALTER TABLE customer_orders
  ADD COLUMN promo_name_snapshot VARCHAR(180) DEFAULT NULL AFTER promo_id;

ALTER TABLE promotion_redemptions
  ADD COLUMN promo_name_snapshot VARCHAR(180) DEFAULT NULL AFTER code_used,
  ADD COLUMN promo_terms_snapshot TEXT DEFAULT NULL AFTER promo_name_snapshot;
