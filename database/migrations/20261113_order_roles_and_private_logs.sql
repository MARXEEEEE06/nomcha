-- Apply after the existing OTP, role, and order migrations.
-- Keep authentication logs linked to user IDs without retaining email addresses.
ALTER TABLE auth_security_logs
  ADD COLUMN user_id INT(11) DEFAULT NULL AFTER log_id;

UPDATE auth_security_logs l
JOIN user_credentials c ON LOWER(c.email_addr) = LOWER(l.email_addr)
SET l.user_id = c.user_id
WHERE l.email_addr IS NOT NULL;

ALTER TABLE auth_security_logs
  DROP INDEX auth_security_logs_email,
  DROP COLUMN email_addr,
  ADD KEY auth_security_logs_user_created (user_id, created_at);

CREATE TABLE staff_order_actions (
  action_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id INT(11) NOT NULL,
  user_id INT(11) NOT NULL,
  event_type ENUM('status_changed','cancelled') NOT NULL,
  from_status VARCHAR(32) NOT NULL,
  to_status VARCHAR(32) NOT NULL,
  cancellation_reason ENUM('customer_request','item_unavailable','payment_issue','duplicate_order','delivery_issue','other') DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (action_id),
  KEY staff_order_actions_order (order_id, created_at),
  KEY staff_order_actions_user (user_id, created_at),
  CONSTRAINT staff_order_actions_order_fk FOREIGN KEY (order_id) REFERENCES customer_orders (order_id),
  CONSTRAINT staff_order_actions_user_fk FOREIGN KEY (user_id) REFERENCES user_info (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
