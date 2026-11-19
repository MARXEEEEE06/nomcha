CREATE TABLE IF NOT EXISTS audit_logs (
  audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_user_id INT DEFAULT NULL,
  action VARCHAR(80) NOT NULL,
  entity_type VARCHAR(60) NOT NULL,
  entity_id BIGINT UNSIGNED DEFAULT NULL,
  before_data JSON DEFAULT NULL,
  after_data JSON DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (audit_id),
  KEY audit_entity (entity_type, entity_id, created_at),
  KEY audit_actor (actor_user_id, created_at),
  KEY audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
