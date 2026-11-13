-- Preserve existing account ages as unknown; newly registered accounts get a timestamp.
ALTER TABLE user_credentials ADD COLUMN created_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE user_credentials ALTER COLUMN created_at SET DEFAULT CURRENT_TIMESTAMP;

ALTER TABLE promotions
  ADD COLUMN promo_image VARCHAR(500) DEFAULT NULL,
  ADD COLUMN faq_json JSON DEFAULT NULL;

-- Distinguish an item's first launch from a later return to the menu.
ALTER TABLE menu_items ADD COLUMN has_been_available TINYINT(1) NOT NULL DEFAULT 0;
UPDATE menu_items SET has_been_available = 1 WHERE is_available = 1;
