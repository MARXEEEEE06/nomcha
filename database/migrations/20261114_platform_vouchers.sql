-- Platform vouchers and checkout promotion eligibility.
ALTER TABLE promotions
  ADD COLUMN is_platform_voucher TINYINT(1) NOT NULL DEFAULT 0 AFTER promo_code,
  ADD COLUMN eligibility_scope ENUM('all','exclusive') NOT NULL DEFAULT 'all' AFTER is_platform_voucher,
  ADD COLUMN faq_question VARCHAR(500) DEFAULT NULL AFTER mechanics,
  ADD COLUMN faq_answer TEXT DEFAULT NULL AFTER faq_question;

CREATE TABLE IF NOT EXISTS promotion_eligible_items (
  promo_id BIGINT UNSIGNED NOT NULL,
  item_id INT NOT NULL,
  PRIMARY KEY (promo_id,item_id),
  CONSTRAINT promotion_eligible_items_promo_fk FOREIGN KEY (promo_id) REFERENCES promotions(promo_id) ON DELETE CASCADE,
  CONSTRAINT promotion_eligible_items_item_fk FOREIGN KEY (item_id) REFERENCES menu_items(item_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS promotion_claims (
  claim_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  promo_id BIGINT UNSIGNED NOT NULL,
  user_id INT NOT NULL,
  claimed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  redeemed_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (claim_id),
  UNIQUE KEY promotion_claim_user_unique (promo_id,user_id),
  KEY promotion_claim_user (user_id,redeemed_at),
  CONSTRAINT promotion_claim_promo_fk FOREIGN KEY (promo_id) REFERENCES promotions(promo_id) ON DELETE CASCADE,
  CONSTRAINT promotion_claim_user_fk FOREIGN KEY (user_id) REFERENCES user_info(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE customer_orders
  ADD COLUMN promo_id BIGINT UNSIGNED DEFAULT NULL AFTER subtotal,
  ADD COLUMN discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER promo_id;

ALTER TABLE promotion_redemptions
  ADD COLUMN claim_id BIGINT UNSIGNED DEFAULT NULL AFTER user_id,
  ADD UNIQUE KEY promotion_redemption_order_unique (order_id);
