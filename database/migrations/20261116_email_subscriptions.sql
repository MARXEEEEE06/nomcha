-- Explicit marketing consent is tracked separately from customer accounts.
CREATE TABLE IF NOT EXISTS email_subscriptions (
  subscription_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email_addr VARCHAR(255) NOT NULL,
  consented_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  unsubscribed_at TIMESTAMP NULL DEFAULT NULL,
  unsubscribe_token_hash CHAR(64) NOT NULL,
  PRIMARY KEY (subscription_id),
  UNIQUE KEY email_subscriptions_email_unique (email_addr),
  UNIQUE KEY email_subscriptions_token_unique (unsubscribe_token_hash),
  KEY email_subscriptions_active (unsubscribed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
