<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

const AUDIT_FIELDS = [
    'work_session' => ['start_time', 'end_time'],
    'job_entry' => ['job_name', 'start_time', 'end_time', 'note', 'is_recorded'],
];

function fetchEntity(string $type, int $id): ?array {
    $table = $type === 'work_session' ? 'work_sessions' : 'job_entries';
    $cols = implode(',', AUDIT_FIELDS[$type]);
    $stmt = db()->prepare("SELECT id, user_id, $cols FROM $table WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function auditLog(int $actorId, string $entityType, int $entityId, string $action, ?array $before, ?array $after): void {
    if (!isset(AUDIT_FIELDS[$entityType])) return;
    $targetUserId = ($after['user_id'] ?? $before['user_id'] ?? $actorId);

    $beforeJson = $before ? json_encode(filterAuditFields($entityType, $before), JSON_UNESCAPED_UNICODE) : null;
    $afterJson  = $after  ? json_encode(filterAuditFields($entityType, $after),  JSON_UNESCAPED_UNICODE) : null;

    if ($action === 'update' && $beforeJson === $afterJson) return;

    $stmt = db()->prepare(
        'INSERT INTO audit_log (actor_id, target_user_id, entity_type, entity_id, action, before_json, after_json)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$actorId, (int)$targetUserId, $entityType, $entityId, $action, $beforeJson, $afterJson]);
}

function filterAuditFields(string $type, array $row): array {
    $out = [];
    foreach (AUDIT_FIELDS[$type] as $f) {
        if (array_key_exists($f, $row)) $out[$f] = $row[$f];
    }
    return $out;
}
