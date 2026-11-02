<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/audit.php';

$user = requireLogin();
csrfCheck();

$action = postStr('action');
$now = date('Y-m-d H:i:s');

/** Laufende Pause des Benutzers (letzter Abschnitt mit "Pause danach") oder null. */
function pausedSession(int $userId): ?array {
    $stmt = db()->prepare('SELECT id, work_type FROM work_sessions WHERE user_id = ? AND pause_after = 1 ORDER BY end_time DESC LIMIT 1');
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

if ($action === 'in') {
    $stmt = db()->prepare('SELECT id FROM work_sessions WHERE user_id = ? AND end_time IS NULL');
    $stmt->execute([$user['id']]);
    if ($stmt->fetch()) {
        jsonError('Bereits eingestochen.', 409);
    }
    // Nach einer Pause geht es mit der gleichen Art weiter, sofern keine gewählt wurde.
    $paused = pausedSession((int)$user['id']);
    $type = postStr('work_type');
    if (!isset(WORK_TYPES[$type])) $type = $paused['work_type'] ?? '';
    db()->prepare('UPDATE work_sessions SET pause_after = 0 WHERE user_id = ? AND pause_after = 1')->execute([$user['id']]);
    $stmt = db()->prepare('INSERT INTO work_sessions (user_id, start_time, work_type) VALUES (?, ?, ?)');
    $stmt->execute([$user['id'], $now, $type]);
    $id = (int)db()->lastInsertId();
    auditLog((int)$user['id'], 'work_session', $id, 'create', null, fetchEntity('work_session', $id));
    jsonResponse(['ok' => true, 'id' => $id, 'start' => $now]);
}

if ($action === 'out' || $action === 'pause') {
    // Ausstechen während einer Pause beendet nur die Pause.
    if ($action === 'out' && ($paused = pausedSession((int)$user['id']))) {
        $stmt = db()->prepare('SELECT 1 FROM work_sessions WHERE user_id = ? AND end_time IS NULL');
        $stmt->execute([$user['id']]);
        if (!$stmt->fetch()) {
            db()->prepare('UPDATE work_sessions SET pause_after = 0 WHERE user_id = ?')->execute([$user['id']]);
            jsonResponse(['ok' => true, 'duration' => 0]);
        }
    }
    $stmt = db()->prepare('SELECT id, start_time FROM work_sessions WHERE user_id = ? AND end_time IS NULL ORDER BY start_time DESC LIMIT 1');
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch();
    if (!$row) {
        jsonError('Nicht eingestochen.', 409);
    }
    $before = fetchEntity('work_session', (int)$row['id']);
    db()->prepare('UPDATE work_sessions SET end_time = ?, pause_after = ? WHERE id = ?')
        ->execute([$now, $action === 'pause' ? 1 : 0, $row['id']]);
    $after = fetchEntity('work_session', (int)$row['id']);
    auditLog((int)$user['id'], 'work_session', (int)$row['id'], 'update', $before, $after);
    jsonResponse(['ok' => true, 'duration' => diffSeconds($row['start_time'], $now)]);
}

jsonError('Unbekannte Aktion.');
