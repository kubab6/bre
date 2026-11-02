<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/materials.php';

$me = requireLogin();

// Material erfassen/bearbeiten (Formulare auf dieser Seite).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $back = backUrl('/jobs.php');
    handleMaterialPost($me, postStr('action'), $back);
    backTo($back, null, 'Unbekannte Aktion.');
}

$user = viewedUser($me);
$own = (int)$user['id'] === (int)$me['id'];

// Es können mehrere Aufträge gleichzeitig laufen.
$active = db()->prepare('SELECT id, job_name, note, start_time, customer_id, job_type FROM job_entries WHERE user_id = ? AND end_time IS NULL ORDER BY start_time');
$active->execute([$user['id']]);
$activeJobs = $active->fetchAll();

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
    $rangeTitle = monthLabel($filterMonth);
} else {
    $filterMonth = '';
    $from = (new DateTime('-29 days'))->format('Y-m-d');
    $to = date('Y-m-d');
    $rangeTitle = 'Letzte 30 Tage';
}
$where[] = 'e.start_time >= ? AND e.start_time <= ?';
array_push($params, $from . ' 00:00:00', $to . ' 23:59:59');
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

// Material des angezeigten Benutzers im gleichen Zeitraum und für den gewählten Kunden.
$mWhere = ['m.user_id = ?', 'm.used_on >= ?', 'm.used_on <= ?'];
$mParams = [$user['id'], $from, $to];
if ($filterCustomer === 'none') {
    $mWhere[] = '0';
} elseif ($filterCustomer !== '') {
    $mWhere[] = 'm.customer_id = ?';
    $mParams[] = (int)$filterCustomer;
}
$mStmt = db()->prepare(
    'SELECT m.*, c.name AS customer_name FROM materials m JOIN customers c ON c.id = m.customer_id
     WHERE ' . implode(' AND ', $mWhere) . ' ORDER BY ' . ($sort === 'customer' ? 'c.name COLLATE NOCASE, ' : '') . 'm.used_on DESC, m.id DESC'
);
$mStmt->execute($mParams);
$materials = $mStmt->fetchAll();

$query = array_filter(['user' => $own ? null : (int)$user['id'], 'customer' => $filterCustomer, 'month' => $filterMonth, 'sort' => $sort === 'date' ? null : $sort]);
$self = '/jobs.php' . ($query ? '?' . http_build_query($query) : '');

renderHeader('Aufträge', 'jobs');
?>
<?= renderFlash() ?>
<?= userSwitcher($me, $user, '/jobs.php') ?>
<?= customerDatalist() ?>
<?= unitDatalist() ?>
<section class="card stopwatch">
    <h2><?= $own ? 'Auftragszeit erfassen' : 'Aufträge von ' . h(displayName($user)) ?></h2>
    <?php if (!$activeJobs && !$own): ?><p class="muted">Kein laufender Auftrag</p><?php endif; ?>
    <?php if ($activeJobs): ?>
    <div class="running-jobs">
        <?php foreach ($activeJobs as $aj): ?>
        <div class="running-job" data-id="<?= (int)$aj['id'] ?>" data-start="<?= h($aj['start_time']) ?>">
            <span class="time job-time">00:00:00</span>
            <span class="status">
                <?php if ($aj['customer_id'] !== null): ?><?= h($customerNames[$aj['customer_id']] ?? '') ?>: <?php endif; ?>
                <strong><?= h($aj['job_name']) ?></strong>
                <?php if ($aj['job_type'] !== ''): ?> <span class="badge"><?= h(jobTypeLabel($aj['job_type'])) ?></span><?php endif; ?>
                <?php if ($aj['note']): ?> – <em><?= h($aj['note']) ?></em><?php endif; ?>
                <br>seit <?= h(date('d.m. H:i', strtotime($aj['start_time']))) ?>
            </span>
            <?php if ($own): ?><button type="button" class="danger btn-job-stop" data-id="<?= (int)$aj['id'] ?>">Stoppen</button><?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if ($own): ?>
        <?php if ($activeJobs): ?><p class="hint">Du kannst zusätzlich einen weiteren Auftrag starten:</p><?php endif; ?>
        <form id="job-start-form" class="inline-form">
            <?= csrfField() ?>
            <label>Kunde<input type="text" name="customer_name" list="customer-list" maxlength="200" placeholder="Name eintippen" autocomplete="off"></label>
            <label>Art<select name="job_type"><?= jobTypeOptions(null) ?></select></label>
            <label>Auftrag<input type="text" name="job_name" required maxlength="200" placeholder="z.B. Drucker einrichten"></label>
            <label>Notiz<input type="text" name="note" maxlength="500" placeholder="optional"></label>
            <button type="submit" class="primary"><?= $activeJobs ? 'Weiteren starten' : 'Starten' ?></button>
        </form>
        <p class="hint" id="job-error" hidden></p>
    <?php endif; ?>

    <details class="manual-add"<?= $own ? '' : ' open' ?>>
        <summary>Manuellen Eintrag anlegen (ohne Stoppuhr)</summary>
        <form id="job-create-form" class="inline-form">
            <?= csrfField() ?>
            <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
            <label>Kunde<input type="text" name="customer_name" list="customer-list" maxlength="200" placeholder="Name eintippen" autocomplete="off"></label>
            <label>Art<select name="job_type"><?= jobTypeOptions(null) ?></select></label>
            <label>Auftrag<input type="text" name="job_name" required maxlength="200"></label>
            <label>Start<input type="datetime-local" name="start_time" required data-today></label>
            <label>Ende<input type="datetime-local" name="end_time" required data-today></label>
            <label>Notiz<input type="text" name="note" maxlength="500" placeholder="optional"></label>
            <button type="submit" class="primary">Speichern</button>
        </form>
        <p class="hint" id="job-create-error" hidden></p>
    </details>
    <p class="hint">Unbekannte Kundennamen werden automatisch als neuer Kunde angelegt.</p>
</section>

<section class="card">
    <h2>Material erfassen</h2>
    <form method="post" action="/jobs.php" class="inline-form material-form">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create_material">
        <input type="hidden" name="back" value="<?= h($self) ?>">
        <label>Kunde<input type="text" name="customer_name" list="customer-list" required maxlength="200" placeholder="Name eintippen" autocomplete="off"
            value="<?= h($filterCustomer !== '' && $filterCustomer !== 'none' ? ($customerNames[(int)$filterCustomer] ?? '') : '') ?>"></label>
        <?= materialFields(null, date('Y-m-d')) ?>
        <button type="submit" class="primary">Eintragen</button>
    </form>
    <p class="hint">Der Einkaufspreis (EK) ist nur intern sichtbar und erscheint nur im internen PDF.</p>
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
        <label>Zeitraum<select name="month" onchange="this.form.submit()"><?= monthOptions($filterMonth, ['' => 'Letzte 30 Tage']) ?></select></label>
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
                    <label>Kunde<input type="text" class="edit-customer" list="customer-list" maxlength="200" autocomplete="off" value="<?= h($r['customer_id'] !== null ? ($customerNames[$r['customer_id']] ?? '') : '') ?>"></label>
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

<section class="card">
    <div class="card-head">
        <h2>Material · <?= h($rangeTitle) ?></h2>
        <?php if ($filterCustomer !== '' && $filterCustomer !== 'none'): ?><a href="/customers.php?id=<?= (int)$filterCustomer ?><?= $filterMonth ? '&month=' . h($filterMonth) : '' ?>" class="muted">Zur Kundenseite</a><?php endif; ?>
    </div>
    <?php if (!$materials): ?>
        <p class="muted">Kein Material in diesem Zeitraum.</p>
    <?php else: ?>
        <?= renderMaterialTable($materials, '/jobs.php', $self, false, true) ?>
    <?php endif; ?>
</section>
<?php
renderFooter('/assets/jobs.js');
