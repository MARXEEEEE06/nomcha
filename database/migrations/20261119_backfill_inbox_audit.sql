-- Backfill existing team activity into the unified audit log.
-- audit_logs needs a source_event_id so this migration can be rerun safely.
ALTER TABLE audit_logs
  ADD COLUMN source_event_id BIGINT UNSIGNED DEFAULT NULL AFTER audit_id,
  ADD UNIQUE KEY audit_source_event (source_event_id);

INSERT INTO audit_logs (
  source_event_id, actor_user_id, action, entity_type, entity_id,
  before_data, after_data, created_at
)
SELECT
  e.event_id,
  e.created_by,
  CONCAT('inbox_', e.event_type),
  COALESCE(e.entity_type, 'team_inbox_event'),
  COALESCE(e.entity_id, e.event_id),
  NULL,
  JSON_OBJECT('title', e.title, 'message', e.message, 'audience', e.audience, 'event_type', e.event_type),
  e.created_at
FROM team_inbox_events e
ON DUPLICATE KEY UPDATE source_event_id = VALUES(source_event_id);
