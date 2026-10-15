<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/absence.php';

$user = requireLogin();

$stmt = db()->prepare('SELECT daily_target_minutes, balance_start_date FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$cfg = $stmt->fetch();

$target = (int)$cfg['daily_target_minutes'];
$startDate = $cfg['balance_start_date'];
$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$yesterday = (new DateTimeImmutable('yesterday'))->format('Y-m-d');

$workdaysClosed = workdaysBetween($startDate, $yesterday);
$absentClosed = $startDate <= $yesterday ? count(absenceWeekdays((int)$user['id'], $startDate, $yesterday)) : 0;
$soll = ($workdaysClosed - $absentClosed) * $target;

$stmt = db()->prepare(
    "SELECT start_time, end_time FROM work_sessions
     WHERE user_id = ? AND end_time IS NOT NULL
       AND DATE(start_time) >= ? AND DATE(start_time) <= ?"
);
$stmt->execute([$user['id'], $startDate, $yesterday]);
$ist = 0;
while ($r = $stmt->fetch()) {
    $ist += (int)round(diffSeconds($r['start_time'], $r['end_time']) / 60);
}
$saldo = $ist - $soll;

$todayStmt = db()->prepare(
    "SELECT start_time, end_time FROM work_sessions
     WHERE user_id = ? AND DATE(start_time) = ?"
);
$todayStmt->execute([$user['id'], $today]);
$todayIst = 0;
$todayRunning = false;
while ($r = $todayStmt->fetch()) {
    $end = $r['end_time'];
    if (!$end) { $end = date('Y-m-d H:i:s'); $todayRunning = true; }
    $todayIst += (int)round(diffSeconds($r['start_time'], $end) / 60);
}
$daysBack = 14;
$windowStart = (new DateTimeImmutable("-".($daysBack-1)." days"))->format('Y-m-d');
$absent = absenceWeekdays((int)$user['id'], $windowStart, $today);
$todayTarget = isWeekday($today) && !isset($absent[$today]) ? $target : 0;

$daily = [];
for ($i = $daysBack - 1; $i >= 0; $i--) {
    $d = (new DateTimeImmutable("-$i days"))->format('Y-m-d');
    $daily[$d] = [
        'ist' => 0,
        'soll' => isWeekday($d) && !isset($absent[$d]) ? $target : 0,
        'absence' => $absent[$d] ?? null,
    ];
}
$dailyStmt = db()->prepare(
    "SELECT DATE(start_time) AS d, start_time, end_time FROM work_sessions
     WHERE user_id = ? AND DATE(start_time) >= ?"
);
$dailyStmt->execute([$user['id'], $windowStart]);
while ($r = $dailyStmt->fetch()) {
    if (!isset($daily[$r['d']])) continue;
    $end = $r['end_time'] ?: date('Y-m-d H:i:s');
    $daily[$r['d']]['ist'] += (int)round(diffSeconds($r['start_time'], $end) / 60);
}

renderHeader('Zeitkonto', 'balance');
?>
<section class="card">
    <h2>Zeitkonto</h2>
    <p class="muted">
        Berechnung ab <strong><?= h($startDate) ?></strong> bis einschließlich gestern.
        Soll = <?= sprintf('%dh %02dm', intdiv($target, 60), $target % 60) ?> pro Werktag (Mo–Fr),
        ohne Urlaubs- und Krankheitstage<?= $absentClosed ? ' (' . $absentClosed . ($absentClosed === 1 ? ' Tag)' : ' Tage)') : '' ?>.
    </p>
    <div class="balance-grid">
        <div class="balance-tile">
            <span class="muted">Soll</span>
            <strong><?= h(sprintf('%dh %02dm', intdiv($soll, 60), $soll % 60)) ?></strong>
        </div>
        <div class="balance-tile">
            <span class="muted">Ist</span>
            <strong><?= h(sprintf('%dh %02dm', intdiv($ist, 60), $ist % 60)) ?></strong>
        </div>
        <div class="balance-tile <?= $saldo >= 0 ? 'pos' : 'neg' ?>">
            <span class="muted">Saldo</span>
            <strong><?= h(fmtSignedHM($saldo)) ?></strong>
        </div>
    </div>
</section>

<section class="card">
    <h2>Heute</h2>
    <div class="balance-grid">
        <div class="balance-tile">
            <span class="muted">Soll heute</span>
            <strong><?= h(sprintf('%dh %02dm', intdiv($todayTarget, 60), $todayTarget % 60)) ?></strong>
        </div>
        <div class="balance-tile">
            <span class="muted">Ist heute<?= $todayRunning ? ' (läuft)' : '' ?></span>
            <strong><?= h(sprintf('%dh %02dm', intdiv($todayIst, 60), $todayIst % 60)) ?></strong>
        </div>
        <?php $diffToday = $todayIst - $todayTarget; ?>
        <div class="balance-tile <?= $diffToday >= 0 ? 'pos' : 'neg' ?>">
            <span class="muted">Differenz</span>
            <strong><?= h(fmtSignedHM($diffToday)) ?></strong>
        </div>
    </div>
</section>

<section class="card">
    <h2>Letzte 14 Tage</h2>
    <div class="table-wrap">
    <table class="balance-table">
        <thead>
            <tr><th>Datum</th><th>Wochentag</th><th class="num">Soll</th><th class="num">Ist</th><th class="num">Differenz</th></tr>
        </thead>
        <tbody>
        <?php
        $weekdayNames = ['Sonntag','Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag'];
        foreach ($daily as $d => $v):
            $diff = $v['ist'] - $v['soll'];
            $dt = new DateTimeImmutable($d);
            $isWeekend = !isWeekday($d);
        ?>
            <tr class="<?= $isWeekend ? 'weekend' : '' ?>">
                <td><?= h($dt->format('d.m.Y')) ?></td>
                <td><?= h($weekdayNames[(int)$dt->format('w')]) ?><?php if ($v['absence']): ?> <span class="cal-chip <?= h($v['absence']) ?>"><?= h(absenceLabel($v['absence'])) ?></span><?php endif; ?></td>
                <td class="num"><?= h(sprintf('%dh %02dm', intdiv($v['soll'], 60), $v['soll'] % 60)) ?></td>
                <td class="num"><?= h(sprintf('%dh %02dm', intdiv($v['ist'], 60), $v['ist'] % 60)) ?></td>
                <td class="num <?= $diff >= 0 ? 'pos' : 'neg' ?>"><?= h(fmtSignedHM($diff)) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p class="hint">Heute zählt mit der bisher erfassten Zeit (auch eine laufende Sitzung).</p>
</section>
<?php
renderFooter();
