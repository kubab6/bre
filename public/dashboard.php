<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';

$me = requireLogin();
$user = viewedUser($me);
$own = (int)$user['id'] === (int)$me['id'];

$active = db()->prepare('SELECT id, start_time, work_type FROM work_sessions WHERE user_id = ? AND end_time IS NULL ORDER BY start_time DESC LIMIT 1');
$active->execute([$user['id']]);
$activeSession = $active->fetch();

// Pause: letzter Abschnitt heute wurde mit "Pause" beendet und noch nicht fortgesetzt.
$pause = null;
if (!$activeSession) {
    $p = db()->prepare('SELECT end_time, work_type FROM work_sessions WHERE user_id = ? AND pause_after = 1 AND end_time >= ? ORDER BY end_time DESC LIMIT 1');
    $p->execute([$user['id'], date('Y-m-d 00:00:00')]);
    $pause = $p->fetch() ?: null;
}

$since = (new DateTime('-6 days'))->setTime(0, 0)->format('Y-m-d H:i:s');
$rowsStmt = db()->prepare(
    'SELECT id, start_time, end_time, work_type FROM work_sessions
     WHERE user_id = ? AND start_time >= ?
     ORDER BY start_time DESC'
);
$rowsStmt->execute([$user['id'], $since]);
$rows = $rowsStmt->fetchAll();

$byDay = [];
$weekTotal = 0;
foreach ($rows as $r) {
    $day = substr($r['start_time'], 0, 10);
    $secs = $r['end_time'] ? diffSeconds($r['start_time'], $r['end_time']) : 0;
    $byDay[$day]['rows'][] = $r;
    $byDay[$day]['total'] = ($byDay[$day]['total'] ?? 0) + $secs;
    $weekTotal += $secs;
}
// Pausen = Lücken zwischen den Abschnitten eines Tages.
foreach ($byDay as $day => $info) {
    $sorted = array_reverse($info['rows']);
    $gap = 0;
    for ($i = 1; $i < count($sorted); $i++) {
        $prevEnd = $sorted[$i - 1]['end_time'];
        if ($prevEnd) $gap += max(0, strtotime($sorted[$i]['start_time']) - strtotime($prevEnd));
    }
    $byDay[$day]['pause'] = $gap;
}

$days = [];
for ($i = 0; $i < 7; $i++) {
    $d = (new DateTime("-$i days"))->format('Y-m-d');
    $days[] = $d;
}

renderHeader('Arbeitszeit', 'dashboard');
?>
<?= userSwitcher($me, $user, '/dashboard.php') ?>
<section class="card stopwatch">
    <?php if ($own): ?>
    <h2>Stoppuhr</h2>
    <div class="clock<?= $pause ? ' is-paused' : '' ?>" id="clock"
         data-active="<?= ($activeSession || $pause) ? '1' : '0' ?>"
         data-start="<?= $activeSession ? h($activeSession['start_time']) : ($pause ? h($pause['end_time']) : '') ?>">
        <span class="time" id="clock-time"><?= ($activeSession || $pause) ? '00:00:00' : '–' ?></span>
        <span class="status" id="clock-status">
            <?php if ($activeSession): ?>
                Eingestochen seit <?= h(substr($activeSession['start_time'], 11, 5)) ?><?= $activeSession['work_type'] !== '' ? ' · ' . h(workTypeLabel($activeSession['work_type'])) : '' ?>
            <?php elseif ($pause): ?>
                Pause seit <?= h(substr($pause['end_time'], 11, 5)) ?>
            <?php else: ?>
                Ausgestochen
            <?php endif; ?>
        </span>
    </div>
    <div class="actions">
        <?php if ($activeSession): ?>
            <button id="btn-clock-pause" type="button">Pause</button>
            <button id="btn-clock-out" type="button" class="danger">Ausstechen</button>
        <?php elseif ($pause): ?>
            <button id="btn-clock-in" type="button" class="primary">Pause beenden</button>
            <button id="btn-clock-out" type="button" class="danger">Ausstechen</button>
        <?php else: ?>
            <select id="clock-work-type" aria-label="Art"><?= workTypeOptions(null) ?></select>
            <button id="btn-clock-in" type="button" class="primary">Einstechen</button>
        <?php endif; ?>
    </div>
    <p class="hint" id="clock-error" hidden></p>
    <?php else: ?>
    <h2>Arbeitszeit von <?= h(displayName($user)) ?></h2>
    <p class="muted"><?= $activeSession ? 'Eingestochen seit ' . h(substr($activeSession['start_time'], 11, 5)) : ($pause ? 'Pause seit ' . h(substr($pause['end_time'], 11, 5)) : 'Ausgestochen') ?></p>
    <?php endif; ?>

    <details class="manual-add"<?= $own ? '' : ' open' ?>>
        <summary>Arbeitszeit nachtragen (ohne Stoppuhr)</summary>
        <form id="work-create-form" class="inline-form">
            <?= csrfField() ?>
            <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
            <label>Art<select name="work_type"><?= workTypeOptions(null) ?></select></label>
            <label>Start<input type="datetime-local" name="start_time" required data-today></label>
            <label>Ende<input type="datetime-local" name="end_time" required data-today></label>
            <button type="submit" class="primary">Speichern</button>
        </form>
        <p class="hint" id="work-create-error" hidden></p>
    </details>
</section>

<section class="card">
    <div class="card-head">
        <h2>Letzte 7 Tage</h2>
        <span class="muted">Gesamt: <strong><?= h(fmtDurationShort($weekTotal)) ?></strong></span>
    </div>

    <div class="day-list" data-csrf="<?= h(csrfToken()) ?>">
        <?php foreach ($days as $d):
            $dayRows = $byDay[$d]['rows'] ?? [];
            $dayTotal = $byDay[$d]['total'] ?? 0;
            $dt = DateTime::createFromFormat('Y-m-d', $d);
            $label = $dt ? strftime_de($dt) : $d;
        ?>
            <div class="day-group">
                <div class="day-header">
                    <strong><?= h($label) ?></strong>
                    <span class="muted"><?php if (!empty($byDay[$d]['pause'])): ?>Pause <?= h(fmtDurationShort($byDay[$d]['pause'])) ?> · <?php endif; ?><?= h(fmtDurationShort($dayTotal)) ?></span>
                </div>
                <?php if (!$dayRows): ?>
                    <p class="empty muted">Keine Einträge</p>
                <?php else: foreach ($dayRows as $r): ?>
                    <div class="session-row" data-id="<?= (int)$r['id'] ?>">
                        <label>Art<select class="edit-type"><?= workTypeOptions($r['work_type']) ?></select></label>
                        <label>Start<input type="datetime-local" class="edit-start" value="<?= h(toLocalInputValue($r['start_time'])) ?>"></label>
                        <label>Ende<input type="datetime-local" class="edit-end" value="<?= h(toLocalInputValue($r['end_time'])) ?>" <?= $r['end_time'] === null ? 'placeholder="läuft…"' : '' ?>></label>
                        <span class="dur" title="Dauer">
                            <?= $r['end_time'] ? h(fmtDurationShort(diffSeconds($r['start_time'], $r['end_time']))) : '<em>läuft…</em>' ?>
                        </span>
                        <div class="row-actions">
                            <button type="button" class="btn-save">Speichern</button>
                            <button type="button" class="btn-delete">Löschen</button>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php
renderFooter('/assets/dashboard.js');

function strftime_de(DateTime $dt): string {
    static $days = ['Sonntag','Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag'];
    return $days[(int)$dt->format('w')] . ', ' . $dt->format('d.m.Y');
}
