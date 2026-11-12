-- Careers, customer feedback, team activity inbox, and promotions.
CREATE TABLE IF NOT EXISTS team_inbox_events (
  event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_type VARCHAR(60) NOT NULL,
  title VARCHAR(180) NOT NULL,
  message TEXT NOT NULL,
  audience ENUM('all','staff','admin') NOT NULL DEFAULT 'all',
  entity_type VARCHAR(40) DEFAULT NULL,
  entity_id BIGINT UNSIGNED DEFAULT NULL,
  created_by INT DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (event_id), KEY team_events_created (created_at), KEY team_events_audience (audience)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS team_inbox_reads (
  event_id BIGINT UNSIGNED NOT NULL,
  user_id INT NOT NULL,
  read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (event_id,user_id), KEY inbox_reads_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS careers_applications (
  application_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  purpose VARCHAR(80) NOT NULL,
  first_name VARCHAR(100) NOT NULL,
  middle_name VARCHAR(100) DEFAULT NULL,
  last_name VARCHAR(100) NOT NULL,
  position_applied VARCHAR(160) NOT NULL,
  mobile_number VARCHAR(20) NOT NULL,
  address VARCHAR(500) NOT NULL,
  email VARCHAR(255) NOT NULL,
  resume_path VARCHAR(255) DEFAULT NULL,
  status ENUM('new','reviewing','interview','offered','hired','rejected','withdrawn') NOT NULL DEFAULT 'new',
  assigned_to INT DEFAULT NULL,
  staff_notes TEXT DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(application_id), KEY career_status_created(status,created_at), KEY career_assignee(assigned_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS contact_submissions (
  contact_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  full_name VARCHAR(160) NOT NULL,
  email VARCHAR(255) NOT NULL,
  mobile_number VARCHAR(20) NOT NULL,
  subject VARCHAR(80) NOT NULL,
  message TEXT NOT NULL,
  status ENUM('new','open','waiting','resolved','closed') NOT NULL DEFAULT 'new',
  priority ENUM('normal','high') NOT NULL DEFAULT 'normal',
  assigned_to INT DEFAULT NULL,
  staff_notes TEXT DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(contact_id), KEY contact_status_created(status,created_at), KEY contact_assignee(assigned_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS promotions (
  promo_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(180) NOT NULL,
  description TEXT NOT NULL,
  mechanics TEXT DEFAULT NULL,
  promo_code VARCHAR(60) DEFAULT NULL,
  discount_type ENUM('percent','fixed','free_item') NOT NULL DEFAULT 'percent',
  discount_value DECIMAL(10,2) NOT NULL DEFAULT 0,
  max_discount DECIMAL(10,2) DEFAULT NULL,
  minimum_spend DECIMAL(10,2) NOT NULL DEFAULT 0,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  channel VARCHAR(100) DEFAULT NULL,
  usage_limit INT UNSIGNED DEFAULT NULL,
  usage_count INT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('draft','scheduled','active','paused','ended') NOT NULL DEFAULT 'draft',
  created_by INT DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(promo_id), UNIQUE KEY promo_code_unique(promo_code), KEY promo_schedule(status,starts_at,ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS promotion_redemptions (
  redemption_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  promo_id BIGINT UNSIGNED NOT NULL,
  order_id INT DEFAULT NULL,
  user_id INT DEFAULT NULL,
  code_used VARCHAR(60) DEFAULT NULL,
  order_subtotal DECIMAL(10,2) NOT NULL,
  discount_amount DECIMAL(10,2) NOT NULL,
  redeemed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(redemption_id), KEY redemption_promo_date(promo_id,redeemed_at), KEY redemption_order(order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Record menu changes for the shared team activity inbox.
DELIMITER //
CREATE TRIGGER menu_items_inbox_insert AFTER INSERT ON menu_items FOR EACH ROW
BEGIN
  INSERT INTO team_inbox_events(event_type,title,message,audience,entity_type,entity_id)
  VALUES('product_created', CONCAT('New menu item: ', NEW.name), CONCAT(NEW.name, ' was added to the menu.'), 'all', 'menu_item', NEW.item_id);
END//
CREATE TRIGGER menu_items_inbox_update AFTER UPDATE ON menu_items FOR EACH ROW
BEGIN
  IF NOT (OLD.name <=> NEW.name) OR NOT (OLD.description <=> NEW.description) OR NOT (OLD.price <=> NEW.price) OR NOT (OLD.is_available <=> NEW.is_available) OR NOT (OLD.publish_at <=> NEW.publish_at) THEN
    INSERT INTO team_inbox_events(event_type,title,message,audience,entity_type,entity_id)
    VALUES('product_updated', CONCAT('Menu updated: ', NEW.name), CONCAT(NEW.name, ' details or availability were updated.'), 'all', 'menu_item', NEW.item_id);
  END IF;
END//
DELIMITER ;
