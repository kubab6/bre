<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/customers.php';
require_once __DIR__ . '/../includes/absence.php';
require_once __DIR__ . '/../includes/pdf.php';

// Monatlicher Arbeitszeitnachweis: Benutzer sehen nur sich selbst, Manager/Admins per ?user=ID jeden.
$me = requireLogin();
$user = viewedUser($me);
$uid = (int)$user['id'];
[$month, $from, $to] = monthRange($_GET['month'] ?? null);

$cfg = db()->prepare('SELECT daily_target_minutes, balance_start_date FROM users WHERE id = ?');
$cfg->execute([$uid]);
$cfg = $cfg->fetch();
$targetSecs = (int)$cfg['daily_target_minutes'] * 60;
$startDate = (string)$cfg['balance_start_date'];
$today = date('Y-m-d');

$stmt = db()->prepare(
    'SELECT start_time, end_time, work_type FROM work_sessions
     WHERE user_id = ? AND start_time >= ? AND start_time <= ?
     ORDER BY start_time'
);
$stmt->execute([$uid, $from . ' 00:00:00', $to . ' 23:59:59']);
$byDay = [];
foreach ($stmt->fetchAll() as $s) {
    $byDay[substr($s['start_time'], 0, 10)][] = $s;
}
$absent = absenceWeekdays($uid, $from, $to);

// Tage aufbereiten
$days = [];
$sum = ['ist' => 0, 'soll' => 0, 'pause' => 0, 'workdays' => 0, 'running' => false];
$byType = [];
$absCount = [];
for ($d = new DateTimeImmutable($from); $d->format('Y-m-d') <= $to; $d = $d->modify('+1 day')) {
    $date = $d->format('Y-m-d');
    $sessions = $byDay[$date] ?? [];
    $ist = 0;
    $pause = 0;
    $lines = [];
    $prevEnd = null;
    foreach ($sessions as $s) {
        if ($prevEnd !== null) $pause += max(0, strtotime($s['start_time']) - strtotime($prevEnd));
        $label = workTypeLabel($s['work_type']);
        if ($s['end_time'] === null) {
            $sum['running'] = true;
            $lines[] = substr($s['start_time'], 11, 5) . '–läuft' . ($label !== '' ? ' · ' . $label : '');
            $prevEnd = null;
            continue;
        }
        $secs = diffSeconds($s['start_time'], $s['end_time']);
        $ist += $secs;
        $byType[$label ?: 'Ohne Art'] = ($byType[$label ?: 'Ohne Art'] ?? 0) + $secs;
        $lines[] = substr($s['start_time'], 11, 5) . '–' . substr($s['end_time'], 11, 5) . ($label !== '' ? ' · ' . $label : '');
        $prevEnd = $s['end_time'];
    }
    // Soll: Werktage ohne Urlaub/Krank, ab Beginn des Zeitkontos und nur bis heute.
    $abs = $absent[$date] ?? null;
    $soll = isWeekday($date) && !$abs && $date >= $startDate && $date <= $today ? $targetSecs : 0;
    if ($abs) {
        array_unshift($lines, absenceLabel($abs));
        $absCount[$abs] = ($absCount[$abs] ?? 0) + 1;
    }
    $sum['ist'] += $ist;
    $sum['soll'] += $soll;
    $sum['pause'] += $pause;
    if ($ist > 0) $sum['workdays']++;
    $days[] = compact('date', 'lines', 'ist', 'soll', 'pause') + ['weekend' => !isWeekday($date)];
}

function fmtDiff(int $secs): string {
    return ($secs >= 0 ? '+' : '') . fmtHours($secs);
}

$r = new PdfReport();
$pdf = $r->pdf;
$left = $r->left;
$right = $r->right;

$pdf->setFont('B', 16);
$pdf->text($left, $r->y, 'Arbeitszeitnachweis');
$pdf->setFont('R', 10);
$pdf->text($right, $r->y, monthLabel($month), 'R');
$r->y += 12;

$infoY = $r->y;
$pdf->setFont('B', 11);
$pdf->text($left, $r->y, displayName($user));
$r->y += 5;
if (displayName($user) !== $user['username']) {
    $pdf->setFont('R', 9.5);
    $pdf->text($left, $r->y, 'Benutzer: ' . $user['username']);
    $r->y += 4.5;
}
$r->y = max($r->y, $r->meta([
    'Zeitraum' => date('d.m.Y', strtotime($from)) . ' – ' . date('d.m.Y', strtotime($to)),
    'Soll pro Werktag' => fmtHours($targetSecs),
    'Erstellt am' => date('d.m.Y'),
], $infoY)) + 8;

$cols = [
    ['Datum', $left, 26, 'L'],
    ['Arbeitszeiten', $left + 26, 70, 'L'],
    ['Pause', $left + 96, 20, 'R'],
    ['Ist', $left + 116, 20, 'R'],
    ['Soll', $left + 136, 20, 'R'],
    ['Differenz', $left + 156, 24, 'R'],
];
$wd = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
$r->section('Tage');
$r->head($cols);
foreach ($days as $day) {
    $has = $day['lines'] || $day['soll'];
    $r->row($cols, [
        $wd[(int)date('w', strtotime($day['date']))] . ' ' . date('d.m.Y', strtotime($day['date'])),
        $day['lines'] ? implode("\n", $day['lines']) : '',
        $day['pause'] ? fmtHours($day['pause']) : '',
        $day['ist'] || $day['lines'] ? fmtHours($day['ist']) : '',
        $day['soll'] ? fmtHours($day['soll']) : '',
        $has ? fmtDiff($day['ist'] - $day['soll']) : '',
    ], false, $day['weekend']);
}
$r->row($cols, ['Summe', '', fmtHours($sum['pause']), fmtHours($sum['ist']), fmtHours($sum['soll']), fmtDiff($sum['ist'] - $sum['soll'])], true, true);
$r->y += 4;

// Zusammenfassung
$sumCols = [['', $left, 110, 'L'], ['', $left + 110, 70, 'R']];
$r->section('Zusammenfassung');
$rows = [
    ['Ist-Arbeitszeit', fmtHours($sum['ist'])],
    ['Soll-Arbeitszeit', fmtHours($sum['soll'])],
    ['Differenz', fmtDiff($sum['ist'] - $sum['soll'])],
    ['Pausen gesamt', fmtHours($sum['pause'])],
    ['Tage mit Arbeitszeit', (string)$sum['workdays']],
];
foreach (ABSENCE_TYPES as $k => $label) {
    if (!empty($absCount[$k])) $rows[] = [$label . ' (Werktage)', (string)$absCount[$k]];
}
if (count($byType) > 1 || (count($byType) === 1 && !isset($byType['Ohne Art']))) {
    ksort($byType);
    foreach ($byType as $t => $secs) $rows[] = ['davon ' . $t, fmtHours($secs)];
}
foreach ($rows as $i => $row) {
    $r->row($sumCols, $row, $i === 2);
}
$r->ensure(14);
$pdf->setFont('R', 7.5);
$hint = 'Soll: Werktage Mo–Fr ohne Urlaub/Krank' . ($startDate > $from ? ', ab ' . date('d.m.Y', strtotime($startDate)) : '')
    . ($to > $today ? ', nur bis heute' : '') . '. Pause = Lücken zwischen den Arbeitsabschnitten eines Tages.'
    . ($sum['running'] ? ' Laufende Abschnitte sind nicht mitgezählt.' : '');
foreach ($pdf->wrap($hint, $right - $left) as $line) {
    $pdf->text($left, $r->y, $line);
    $r->y += 3.8;
}

$r->send(
    displayName($user) . ' · ' . monthLabel($month) . ' · Arbeitszeitnachweis',
    'Arbeitszeitnachweis ' . displayName($user) . ' ' . monthLabel($month),
    'Arbeitszeit_' . fileSlug(displayName($user), 'Benutzer') . '_' . $month . '.pdf'
);
