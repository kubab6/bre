<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

requireAdmin();

$type = $_GET['type'] ?? 'work';
$userId = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? (int)$_GET['user_id'] : null;
$from = isset($_GET['from']) && $_GET['from'] !== '' ? $_GET['from'] . ' 00:00:00' : null;
$to = isset($_GET['to']) && $_GET['to'] !== '' ? $_GET['to'] . ' 23:59:59' : null;
$recorded = $_GET['recorded'] ?? 'all';

$where = [];
$params = [];
if ($userId !== null) { $where[] = 'e.user_id = ?'; $params[] = $userId; }
if ($from !== null) { $where[] = 'e.start_time >= ?'; $params[] = $from; }
if ($to !== null) { $where[] = 'e.start_time <= ?'; $params[] = $to; }

if ($type === 'jobs') {
    if ($recorded === 'open') $where[] = 'e.is_recorded = 0';
    elseif ($recorded === 'done') $where[] = 'e.is_recorded = 1';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

if ($type === 'jobs') {
    $sql = "SELECT u.username, e.job_name, e.start_time, e.end_time, e.note, e.is_recorded
            FROM job_entries e JOIN users u ON u.id = e.user_id
            $whereSql ORDER BY e.start_time";
    $headers = ['Benutzer','Auftrag','Start','Ende','Dauer (Min)','Notiz','Eingetragen'];
    $filename = 'auftragszeiten_' . date('Y-m-d') . '.csv';
} else {
    $sql = "SELECT u.username, e.start_time, e.end_time
            FROM work_sessions e JOIN users u ON u.id = e.user_id
            $whereSql ORDER BY e.start_time";
    $headers = ['Benutzer','Start','Ende','Dauer (Min)'];
    $filename = 'arbeitszeiten_' . date('Y-m-d') . '.csv';
}

$stmt = db()->prepare($sql);
$stmt->execute($params);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, $headers, ';');

while ($r = $stmt->fetch()) {
    $minutes = $r['end_time'] ? (int)round(diffSeconds($r['start_time'], $r['end_time']) / 60) : '';
    if ($type === 'jobs') {
        fputcsv($out, [
            $r['username'], $r['job_name'], $r['start_time'], $r['end_time'] ?? '',
            $minutes, $r['note'], ((int)$r['is_recorded'] === 1 ? 'Ja' : 'Nein'),
        ], ';');
    } else {
        fputcsv($out, [$r['username'], $r['start_time'], $r['end_time'] ?? '', $minutes], ';');
    }
}
fclose($out);
