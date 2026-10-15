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

if ($action === 'in') {
    $stmt = db()->prepare('SELECT id FROM work_sessions WHERE user_id = ? AND end_time IS NULL');
    $stmt->execute([$user['id']]);
    if ($stmt->fetch()) {
        jsonError('Bereits eingestochen.', 409);
    }
    $stmt = db()->prepare('INSERT INTO work_sessions (user_id, start_time) VALUES (?, ?)');
    $stmt->execute([$user['id'], $now]);
    $id = (int)db()->lastInsertId();
    auditLog((int)$user['id'], 'work_session', $id, 'create', null, [
        'user_id' => $user['id'], 'start_time' => $now, 'end_time' => null,
    ]);
    jsonResponse(['ok' => true, 'id' => $id, 'start' => $now]);
}

if ($action === 'out') {
    $stmt = db()->prepare('SELECT id, start_time FROM work_sessions WHERE user_id = ? AND end_time IS NULL ORDER BY start_time DESC LIMIT 1');
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch();
    if (!$row) {
        jsonError('Nicht eingestochen.', 409);
    }
    $before = fetchEntity('work_session', (int)$row['id']);
    db()->prepare('UPDATE work_sessions SET end_time = ? WHERE id = ?')->execute([$now, $row['id']]);
    $after = fetchEntity('work_session', (int)$row['id']);
    auditLog((int)$user['id'], 'work_session', (int)$row['id'], 'update', $before, $after);
    jsonResponse(['ok' => true, 'duration' => diffSeconds($row['start_time'], $now)]);
}

jsonError('Unbekannte Aktion.');
