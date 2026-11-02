<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/customers.php';

$me = requireLogin();
$user = viewedUser($me);
$own = (int)$user['id'] === (int)$me['id'];

$active = db()->prepare('SELECT id, job_name, note, start_time, customer_id, job_type FROM job_entries WHERE user_id = ? AND end_time IS NULL ORDER BY start_time DESC LIMIT 1');
$active->execute([$user['id']]);
$activeJob = $active->fetch();

$customers = customerList(true);
$customerNames = array_column($customers, 'name', 'id');

// Filter: Kunde (leer = alle, "none" = ohne Kunde), Monat (leer = letzte 30 Tage), Sortierung.
$filterCustomer = (string)($_GET['customer'] ?? '');
if ($filterCustomer !== 'none' && !isset($customerNames[(int)$filterCustomer])) $filterCustomer = '';
$filterMonth = (string)($_GET['month'] ?? '');
$sort = ($_GET['sort'] ?? '') === 'customer' ? 'customer' : 'date';

$where = ['e.user_id = ?'];
$params = [$user['id']];
if ($filterMonth !== '' && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $filterMonth)) {
    [, $from, $to] = monthRange($filterMonth);
    $where[] = 'e.start_time >= ? AND e.start_time <= ?';
    array_push($params, $from . ' 00:00:00', $to . ' 23:59:59');
    $rangeTitle = monthLabel($filterMonth);
} else {
    $filterMonth = '';
    $where[] = 'e.start_time >= ?';
    $params[] = (new DateTime('-29 days'))->setTime(0, 0)->format('Y-m-d H:i:s');
    $rangeTitle = 'Letzte 30 Tage';
}
if ($filterCustomer === 'none') {
    $where[] = 'e.customer_id IS NULL';
} elseif ($filterCustomer !== '') {
    $where[] = 'e.customer_id = ?';
    $params[] = (int)$filterCustomer;
}
$orderSql = $sort === 'customer'
    ? "c.name IS NULL, c.name COLLATE NOCASE, e.start_time DESC"
    : 'e.start_time DESC';
$rowsStmt = db()->prepare(
    'SELECT e.id, e.job_name, e.start_time, e.end_time, e.note, e.is_recorded, e.customer_id, e.job_type
     FROM job_entries e LEFT JOIN customers c ON c.id = e.customer_id
     WHERE ' . implode(' AND ', $where) . "
     ORDER BY $orderSql"
);
$rowsStmt->execute($params);
$rows = $rowsStmt->fetchAll();

$totalsByCustomer = [];
$grandTotal = 0;
foreach ($rows as $r) {
    if (!$r['end_time']) continue;
    $secs = diffSeconds($r['start_time'], $r['end_time']);
    $key = $r['customer_id'] !== null ? ($customerNames[$r['customer_id']] ?? '?') : 'Ohne Kunde';
    $totalsByCustomer[$key] = ($totalsByCustomer[$key] ?? 0) + $secs;
    $grandTotal += $secs;
}
arsort($totalsByCustomer);

renderHeader('Aufträge', 'jobs');
?>
<?= userSwitcher($me, $user, '/jobs.php') ?>
<section class="card stopwatch">
    <?php if (!$own): ?>
    <h2>Aufträge von <?= h($user['username']) ?></h2>
    <p class="muted"><?= $activeJob ? 'Läuft gerade: ' . h($activeJob['job_name']) . ' seit ' . h(substr($activeJob['start_time'], 11, 5)) : 'Kein laufender Auftrag' ?></p>
    <?php else: ?>
    <h2>Auftragszeit erfassen</h2>
    <?php if ($activeJob): ?>
        <div class="clock" id="job-clock" data-active="1" data-start="<?= h($activeJob['start_time']) ?>">
            <span class="time" id="job-time">00:00:00</span>
            <span class="status">
                <?php if ($activeJob['customer_id'] !== null): ?><?= h($customerNames[$activeJob['customer_id']] ?? '') ?>: <?php endif; ?>
                <strong><?= h($activeJob['job_name']) ?></strong>
                <?php if ($activeJob['job_type'] !== ''): ?> <span class="badge"><?= h(jobTypeLabel($activeJob['job_type'])) ?></span><?php endif; ?>
                <?php if ($activeJob['note']): ?> – <em><?= h($activeJob['note']) ?></em><?php endif; ?>
                <br>seit <?= h(substr($activeJob['start_time'], 11, 5)) ?>
            </span>
        </div>
        <div class="actions">
            <button id="btn-job-stop" type="button" class="danger" data-id="<?= (int)$activeJob['id'] ?>">Stoppen</button>
        </div>
    <?php else: ?>
        <form id="job-start-form" class="inline-form">
            <?= csrfField() ?>
            <label>Kunde<select name="customer_id"><?= customerOptions($customers, null) ?></select></label>
            <label>Art<select name="job_type"><?= jobTypeOptions(null) ?></select></label>
            <label>Auftrag<input type="text" name="job_name" required maxlength="200" placeholder="z.B. Drucker einrichten"></label>
            <label>Notiz<input type="text" name="note" maxlength="500" placeholder="optional"></label>
            <button type="submit" class="primary">Starten</button>
        </form>
    <?php endif; ?>
    <p class="hint" id="job-error" hidden></p>
    <?php endif; ?>

    <details class="manual-add"<?= $own ? '' : ' open' ?>>
        <summary>Manuellen Eintrag anlegen (ohne Stoppuhr)</summary>
        <form id="job-create-form" class="inline-form">
            <?= csrfField() ?>
            <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
            <label>Kunde<select name="customer_id"><?= customerOptions($customers, null) ?></select></label>
            <label>Art<select name="job_type"><?= jobTypeOptions(null) ?></select></label>
            <label>Auftrag<input type="text" name="job_name" required maxlength="200"></label>
            <label>Start<input type="datetime-local" name="start_time" required></label>
            <label>Ende<input type="datetime-local" name="end_time" required></label>
            <label>Notiz<input type="text" name="note" maxlength="500" placeholder="optional"></label>
            <button type="submit" class="primary">Speichern</button>
        </form>
        <p class="hint" id="job-create-error" hidden></p>
    </details>
</section>

<section class="card">
    <div class="card-head">
        <h2><?= h($rangeTitle) ?></h2>
        <span class="muted">Gesamt: <strong><?= h(fmtDurationShort($grandTotal)) ?></strong></span>
    </div>

    <form method="get" action="/jobs.php" class="inline-form job-filter">
        <?php if (!$own): ?><input type="hidden" name="user" value="<?= (int)$user['id'] ?>"><?php endif; ?>
        <label>Kunde
            <select name="customer" onchange="this.form.submit()">
                <option value="">Alle Kunden</option>
                <option value="none"<?= $filterCustomer === 'none' ? ' selected' : '' ?>>Ohne Kunde</option>
                <?php foreach ($customers as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"<?= $filterCustomer === (string)$c['id'] ? ' selected' : '' ?>><?= h($c['name']) ?><?= (int)$c['is_active'] ? '' : ' (inaktiv)' ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Monat<input type="month" name="month" value="<?= h($filterMonth) ?>" onchange="this.form.submit()"></label>
        <label>Sortierung
            <select name="sort" onchange="this.form.submit()">
                <option value="date">Nach Datum</option>
                <option value="customer"<?= $sort === 'customer' ? ' selected' : '' ?>>Nach Kunde</option>
            </select>
        </label>
        <noscript><button type="submit">Filtern</button></noscript>
        <?php if ($filterCustomer !== '' || $filterMonth !== '' || $sort !== 'date'): ?>
            <a href="/jobs.php<?= $own ? '' : '?user=' . (int)$user['id'] ?>" class="reset-filter">Filter zurücksetzen</a>
        <?php endif; ?>
    </form>

    <?php if ($totalsByCustomer): ?>
        <details class="job-totals">
            <summary>Summen pro Kunde</summary>
            <ul>
                <?php foreach ($totalsByCustomer as $name => $secs): ?>
                    <li><?= h($name) ?> <span class="muted"><?= h(fmtDurationShort($secs)) ?></span></li>
                <?php endforeach; ?>
            </ul>
        </details>
    <?php endif; ?>

    <div class="job-list" data-csrf="<?= h(csrfToken()) ?>">
        <?php if (!$rows): ?>
            <p class="muted">Keine Einträge</p>
        <?php else: $lastGroup = null; foreach ($rows as $r): ?>
            <?php if ($sort === 'customer'):
                $group = $r['customer_id'] !== null ? ($customerNames[$r['customer_id']] ?? '?') : 'Ohne Kunde';
                if ($group !== $lastGroup): $lastGroup = $group; ?>
                <h3 class="job-group"><?= h($group) ?></h3>
            <?php endif; endif; ?>
            <div class="job-row <?= $r['is_recorded'] ? 'is-recorded' : '' ?>" data-id="<?= (int)$r['id'] ?>">
                <div class="job-row-main">
                    <label>Kunde<select class="edit-customer"><?= customerOptions($customers, $r['customer_id'] !== null ? (int)$r['customer_id'] : null) ?></select></label>
                    <label>Art<select class="edit-type"><?= jobTypeOptions($r['job_type']) ?></select></label>
                    <label>Auftrag<input type="text" class="edit-job" value="<?= h($r['job_name']) ?>" maxlength="200"></label>
                    <label>Notiz<input type="text" class="edit-note" value="<?= h($r['note']) ?>" maxlength="500"></label>
                </div>
                <div class="job-row-meta">
                    <label>Start<input type="datetime-local" class="edit-start" value="<?= h(toLocalInputValue($r['start_time'])) ?>"></label>
                    <label>Ende<input type="datetime-local" class="edit-end" value="<?= h(toLocalInputValue($r['end_time'])) ?>" <?= $r['end_time'] === null ? 'placeholder="läuft…"' : '' ?>></label>
                    <span class="dur" title="Dauer">
                        <?= $r['end_time'] ? h(fmtDurationShort(diffSeconds($r['start_time'], $r['end_time']))) : '<em>läuft…</em>' ?>
                    </span>
                    <label class="checkbox-inline" title="Als abgerechnet markieren">
                        <input type="checkbox" class="edit-recorded" <?= $r['is_recorded'] ? 'checked' : '' ?>>
                        Abgerechnet
                    </label>
                    <div class="row-actions">
                        <button type="button" class="btn-save">Speichern</button>
                        <button type="button" class="btn-delete">Löschen</button>
                    </div>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>
</section>
<?php
renderFooter('/assets/jobs.js');
