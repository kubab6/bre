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

if ($action === 'start') {
    $name = postStr('job_name');
    $note = postStr('note');
    if ($name === '' || mb_strlen($name) > 200) jsonError('Auftragsname erforderlich.');
    if (mb_strlen($note) > 500) jsonError('Notiz zu lang.');

    $stmt = db()->prepare('SELECT id FROM job_entries WHERE user_id = ? AND end_time IS NULL');
    $stmt->execute([$user['id']]);
    if ($stmt->fetch()) {
        jsonError('Es läuft bereits ein Auftrag.', 409);
    }
    $stmt = db()->prepare('INSERT INTO job_entries (user_id, job_name, start_time, note) VALUES (?, ?, ?, ?)');
    $stmt->execute([$user['id'], $name, $now, $note]);
    $id = (int)db()->lastInsertId();
    auditLog((int)$user['id'], 'job_entry', $id, 'create', null, fetchEntity('job_entry', $id));
    jsonResponse(['ok' => true, 'id' => $id, 'start' => $now]);
}

if ($action === 'create') {
    $name = postStr('job_name');
    $note = postStr('note');
    $start = parseDateTimeLocal((string)postParam('start_time', ''));
    $endRaw = (string)postParam('end_time', '');
    $end = $endRaw === '' ? null : parseDateTimeLocal($endRaw);
    if ($name === '' || mb_strlen($name) > 200) jsonError('Auftragsname erforderlich.');
    if (mb_strlen($note) > 500) jsonError('Notiz zu lang.');
    if (!$start) jsonError('Ungültige Startzeit.');
    if ($endRaw !== '' && !$end) jsonError('Ungültige Endzeit.');
    if ($end !== null && strtotime($end) < strtotime($start)) jsonError('Ende vor Start.');
    $targetId = targetUserId($user);
    if ($targetId === null) jsonError('Benutzer nicht gefunden.', 404);
    $stmt = db()->prepare('INSERT INTO job_entries (user_id, job_name, start_time, end_time, note) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$targetId, $name, $start, $end, $note]);
    $id = (int)db()->lastInsertId();
    auditLog((int)$user['id'], 'job_entry', $id, 'create', null, fetchEntity('job_entry', $id));
    jsonResponse(['ok' => true, 'id' => $id]);
}

if ($action === 'stop') {
    $stmt = db()->prepare('SELECT id, start_time FROM job_entries WHERE user_id = ? AND end_time IS NULL ORDER BY start_time DESC LIMIT 1');
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Kein laufender Auftrag.', 409);
    $before = fetchEntity('job_entry', (int)$row['id']);
    db()->prepare('UPDATE job_entries SET end_time = ? WHERE id = ?')->execute([$now, $row['id']]);
    $after = fetchEntity('job_entry', (int)$row['id']);
    auditLog((int)$user['id'], 'job_entry', (int)$row['id'], 'update', $before, $after);
    jsonResponse(['ok' => true, 'duration' => diffSeconds($row['start_time'], $now)]);
}

$id = postInt('id');
// Manager dürfen die Einträge aller Benutzer bearbeiten.
if (isManager($user)) {
    $stmt = db()->prepare('SELECT id FROM job_entries WHERE id = ?');
    $stmt->execute([$id]);
} else {
    $stmt = db()->prepare('SELECT id FROM job_entries WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $user['id']]);
}
if (!$stmt->fetch()) jsonError('Eintrag nicht gefunden.', 404);

if ($action === 'update') {
    $name = postStr('job_name');
    $note = postStr('note');
    $start = parseDateTimeLocal((string)postParam('start_time', ''));
    $endRaw = (string)postParam('end_time', '');
    $end = $endRaw === '' ? null : parseDateTimeLocal($endRaw);
    if ($name === '' || mb_strlen($name) > 200) jsonError('Auftragsname erforderlich.');
    if (mb_strlen($note) > 500) jsonError('Notiz zu lang.');
    if (!$start) jsonError('Ungültige Startzeit.');
    if ($endRaw !== '' && !$end) jsonError('Ungültige Endzeit.');
    if ($end !== null && strtotime($end) < strtotime($start)) jsonError('Ende vor Start.');

    $before = fetchEntity('job_entry', $id);
    db()->prepare('UPDATE job_entries SET job_name = ?, note = ?, start_time = ?, end_time = ? WHERE id = ?')
        ->execute([$name, $note, $start, $end, $id]);
    $after = fetchEntity('job_entry', $id);
    auditLog((int)$user['id'], 'job_entry', $id, 'update', $before, $after);

    $duration = $end ? diffSeconds($start, $end) : null;
    jsonResponse(['ok' => true, 'duration' => $duration]);
}

if ($action === 'set_recorded') {
    $val = postInt('value') === 1 ? 1 : 0;
    $before = fetchEntity('job_entry', $id);
    db()->prepare('UPDATE job_entries SET is_recorded = ? WHERE id = ?')->execute([$val, $id]);
    $after = fetchEntity('job_entry', $id);
    auditLog((int)$user['id'], 'job_entry', $id, 'update', $before, $after);
    jsonResponse(['ok' => true, 'is_recorded' => $val]);
}

if ($action === 'delete') {
    $before = fetchEntity('job_entry', $id);
    db()->prepare('DELETE FROM job_entries WHERE id = ?')->execute([$id]);
    auditLog((int)$user['id'], 'job_entry', $id, 'delete', $before, null);
    jsonResponse(['ok' => true]);
}

jsonError('Unbekannte Aktion.');
