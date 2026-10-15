<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/absence.php';

$user = requireLogin();
$isAdmin = !empty($user['is_admin']);

$month = $_GET['m'] ?? date('Y-m');
if (!is_string($month) || !preg_match('/^\d{4}-\d{2}$/', $month) || !DateTimeImmutable::createFromFormat('!Y-m', $month)) {
    $month = date('Y-m');
}
$first = DateTimeImmutable::createFromFormat('!Y-m', $month);
$last = $first->modify('last day of this month');

$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $action = postStr('action');

    if ($action === 'create') {
        $type = postStr('type');
        $from = postStr('start_date');
        $to = postStr('end_date');
        $note = postStr('note');
        $targetId = $isAdmin && postInt('user_id') > 0 ? postInt('user_id') : (int)$user['id'];
        $fromDt = DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        $toDt = DateTimeImmutable::createFromFormat('!Y-m-d', $to);

        $exists = db()->prepare('SELECT 1 FROM users WHERE id = ?');
        $exists->execute([$targetId]);

        if (!isset(ABSENCE_TYPES[$type])) {
            $err = 'Ungültige Art.';
        } elseif (!$fromDt || !$toDt || $fromDt->format('Y-m-d') !== $from || $toDt->format('Y-m-d') !== $to) {
            $err = 'Ungültiges Datum.';
        } elseif ($to < $from) {
            $err = 'Bis-Datum liegt vor dem Von-Datum.';
        } elseif (mb_strlen($note) > 500) {
            $err = 'Notiz zu lang.';
        } elseif (!$exists->fetch()) {
            $err = 'Benutzer nicht gefunden.';
        } else {
            $overlap = db()->prepare('SELECT 1 FROM absences WHERE user_id = ? AND end_date >= ? AND start_date <= ?');
            $overlap->execute([$targetId, $from, $to]);
            if ($overlap->fetch()) {
                $err = 'In diesem Zeitraum ist bereits eine Abwesenheit eingetragen.';
            } else {
                db()->prepare('INSERT INTO absences (user_id, type, start_date, end_date, note) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$targetId, $type, $from, $to, $note]);
                $id = (int)db()->lastInsertId();
                auditLog((int)$user['id'], 'absence', $id, 'create', null, fetchEntity('absence', $id));
                header('Location: /calendar.php?m=' . substr($from, 0, 7));
                exit;
            }
        }
    } elseif ($action === 'delete') {
        $id = postInt('id');
        $before = fetchEntity('absence', $id);
        if (!$before || (!$isAdmin && (int)$before['user_id'] !== (int)$user['id'])) {
            $err = 'Eintrag nicht gefunden.';
        } else {
            db()->prepare('DELETE FROM absences WHERE id = ?')->execute([$id]);
            auditLog((int)$user['id'], 'absence', $id, 'delete', $before, null);
            header('Location: /calendar.php?m=' . $month);
            exit;
        }
    }
}

$stmt = db()->prepare(
    'SELECT a.id, a.user_id, a.type, a.start_date, a.end_date, a.note, u.username
     FROM absences a JOIN users u ON u.id = a.user_id
     WHERE a.end_date >= ? AND a.start_date <= ?
     ORDER BY a.start_date, u.username'
);
$stmt->execute([$first->format('Y-m-d'), $last->format('Y-m-d')]);
$absences = $stmt->fetchAll();

// Abwesenheiten pro Tag des Monats
$byDay = [];
foreach ($absences as $a) {
    $s = max($a['start_date'], $first->format('Y-m-d'));
    $e = min($a['end_date'], $last->format('Y-m-d'));
    for ($cur = new DateTimeImmutable($s); $cur->format('Y-m-d') <= $e; $cur = $cur->modify('+1 day')) {
        $byDay[$cur->format('Y-m-d')][] = $a;
    }
}

$users = $isAdmin ? db()->query('SELECT id, username FROM users ORDER BY username')->fetchAll() : [];

$monthNames = ['Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];
$prev = $first->modify('-1 month')->format('Y-m');
$next = $first->modify('+1 month')->format('Y-m');
$today = date('Y-m-d');
$leading = (int)$first->format('N') - 1; // Mo = 0

renderHeader('Kalender', 'calendar');
?>
<?php if ($err): ?><p class="flash error"><?= h($err) ?></p><?php endif; ?>

<section class="card">
    <div class="card-head">
        <h2><?= h($monthNames[(int)$first->format('n') - 1] . ' ' . $first->format('Y')) ?></h2>
        <span class="cal-nav">
            <a href="/calendar.php?m=<?= h($prev) ?>">‹ Zurück</a>
            <a href="/calendar.php">Heute</a>
            <a href="/calendar.php?m=<?= h($next) ?>">Weiter ›</a>
        </span>
    </div>

    <div class="calendar">
        <?php foreach (['Mo','Di','Mi','Do','Fr','Sa','So'] as $wd): ?>
            <div class="cal-head"><?= $wd ?></div>
        <?php endforeach; ?>
        <?php for ($i = 0; $i < $leading; $i++): ?>
            <div class="cal-day cal-empty"></div>
        <?php endfor; ?>
        <?php for ($cur = $first; $cur <= $last; $cur = $cur->modify('+1 day')):
            $d = $cur->format('Y-m-d');
            $classes = ['cal-day'];
            if (!isWeekday($d)) $classes[] = 'weekend';
            if ($d === $today) $classes[] = 'today';
        ?>
            <div class="<?= implode(' ', $classes) ?>">
                <span class="cal-num"><?= (int)$cur->format('j') ?></span>
                <?php foreach ($byDay[$d] ?? [] as $a): ?>
                    <span class="cal-chip <?= h($a['type']) ?>" title="<?= h($a['username'] . ': ' . absenceLabel($a['type'])) ?>"><?= h($a['username']) ?></span>
                <?php endforeach; ?>
            </div>
        <?php endfor; ?>
    </div>
    <p class="hint">
        <span class="cal-chip vacation">Urlaub</span>
        <span class="cal-chip sick">Krank</span>
        Abwesenheiten aller Benutzer. Werktage mit Abwesenheit zählen im Zeitkonto nicht als Soll.
    </p>
</section>

<section class="card">
    <h2>Abwesenheit eintragen</h2>
    <form method="post" class="inline-form">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create">
        <?php if ($isAdmin): ?>
            <label>Benutzer
                <select name="user_id">
                    <?php foreach ($users as $u): ?>
                        <option value="<?= (int)$u['id'] ?>" <?= (int)$u['id'] === (int)$user['id'] ? 'selected' : '' ?>><?= h($u['username']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>
        <label>Art
            <select name="type">
                <?php foreach (ABSENCE_TYPES as $k => $label): ?>
                    <option value="<?= h($k) ?>"><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Von<input type="date" name="start_date" required></label>
        <label>Bis<input type="date" name="end_date" required></label>
        <label>Notiz<input type="text" name="note" maxlength="500" placeholder="optional"></label>
        <button type="submit" class="primary">Eintragen</button>
    </form>
</section>

<section class="card">
    <h2>Einträge in diesem Monat</h2>
    <?php if (!$absences): ?>
        <p class="muted">Keine Abwesenheiten.</p>
    <?php else: ?>
    <div class="absence-wrap">
    <table class="absence-table">
        <thead><tr><th>Benutzer</th><th>Art</th><th>Von</th><th>Bis</th><th>Notiz</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($absences as $a):
            $mine = (int)$a['user_id'] === (int)$user['id'];
        ?>
            <tr>
                <td data-label="Benutzer"><?= h($a['username']) ?></td>
                <td data-label="Art"><span class="cal-chip <?= h($a['type']) ?>"><?= h(absenceLabel($a['type'])) ?></span></td>
                <td data-label="Von"><?= h((new DateTimeImmutable($a['start_date']))->format('d.m.Y')) ?></td>
                <td data-label="Bis"><?= h((new DateTimeImmutable($a['end_date']))->format('d.m.Y')) ?></td>
                <td data-label="Notiz"><?= ($mine || $isAdmin) ? h($a['note']) : '' ?></td>
                <td class="row-actions">
                    <?php if ($mine || $isAdmin): ?>
                        <form method="post" class="inline" onsubmit="return confirm('Abwesenheit löschen?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                            <button type="submit" class="btn-delete">Löschen</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p class="hint">Notizen sehen nur die betroffene Person und Admins.</p>
    <?php endif; ?>
</section>
<?php
renderFooter();
