<?php
/** Append an audit record. Call inside the same transaction as the change. */
function write_audit_log(PDO $pdo, string $action, string $entityType, ?int $entityId, ?array $before, ?array $after): void {
    $stmt = $pdo->prepare('INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, before_data, after_data) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
        $action,
        $entityType,
        $entityId,
        $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    ]);
}
