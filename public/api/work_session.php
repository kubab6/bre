<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/audit.php';

$user = requireLogin();
csrfCheck();

$action = postStr('action');

if ($action === 'create') {
    $start = parseDateTimeLocal((string)postParam('start_time', ''));
    $end = parseDateTimeLocal((string)postParam('end_time', ''));
    if (!$start) jsonError('Ungültige Startzeit.');
    if (!$end) jsonError('Ungültige Endzeit.');
    if (strtotime($end) < strtotime($start)) jsonError('Ende vor Start.');
    $targetId = targetUserId($user);
    if ($targetId === null) jsonError('Benutzer nicht gefunden.', 404);
    $stmt = db()->prepare('INSERT INTO work_sessions (user_id, start_time, end_time) VALUES (?, ?, ?)');
    $stmt->execute([$targetId, $start, $end]);
    $id = (int)db()->lastInsertId();
    auditLog((int)$user['id'], 'work_session', $id, 'create', null, fetchEntity('work_session', $id));
    jsonResponse(['ok' => true, 'id' => $id]);
}

$id = postInt('id');

// Manager dürfen die Einträge aller Benutzer bearbeiten.
if (isManager($user)) {
    $stmt = db()->prepare('SELECT id FROM work_sessions WHERE id = ?');
    $stmt->execute([$id]);
} else {
    $stmt = db()->prepare('SELECT id FROM work_sessions WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $user['id']]);
}
if (!$stmt->fetch()) {
    jsonError('Eintrag nicht gefunden.', 404);
}

if ($action === 'update') {
    $start = parseDateTimeLocal((string)postParam('start_time', ''));
    $endRaw = (string)postParam('end_time', '');
    $end = $endRaw === '' ? null : parseDateTimeLocal($endRaw);
    if (!$start) jsonError('Ungültige Startzeit.');
    if ($endRaw !== '' && !$end) jsonError('Ungültige Endzeit.');
    if ($end !== null && strtotime($end) < strtotime($start)) jsonError('Ende vor Start.');

    $before = fetchEntity('work_session', $id);
    db()->prepare('UPDATE work_sessions SET start_time = ?, end_time = ? WHERE id = ?')
        ->execute([$start, $end, $id]);
    $after = fetchEntity('work_session', $id);
    auditLog((int)$user['id'], 'work_session', $id, 'update', $before, $after);

    $duration = $end ? diffSeconds($start, $end) : null;
    jsonResponse(['ok' => true, 'duration' => $duration]);
}

if ($action === 'delete') {
    $before = fetchEntity('work_session', $id);
    db()->prepare('DELETE FROM work_sessions WHERE id = ?')->execute([$id]);
    auditLog((int)$user['id'], 'work_session', $id, 'delete', $before, null);
    jsonResponse(['ok' => true]);
}

jsonError('Unbekannte Aktion.');
