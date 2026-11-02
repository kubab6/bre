<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/customers.php';
require_once __DIR__ . '/../includes/materials.php';

$me = requireLogin();
$isMgr = isManager($me);

/** Kundendaten aus dem Formular; null + Fehlertext bei ungültiger Eingabe. */
function customerFromPost(?string &$err): ?array {
    $data = [
        'name' => postStr('name'),
        'customer_no' => postStr('customer_no'),
        'address' => str_replace("\r\n", "\n", postStr('address')),
        'contact' => postStr('contact'),
        'note' => str_replace("\r\n", "\n", postStr('note')),
    ];
    if ($data['name'] === '' || mb_strlen($data['name']) > 200) { $err = 'Kundenname erforderlich (max. 200 Zeichen).'; return null; }
    if (mb_strlen($data['customer_no']) > 50 || mb_strlen($data['contact']) > 300
        || mb_strlen($data['address']) > 500 || mb_strlen($data['note']) > 1000) {
        $err = 'Eine Angabe ist zu lang.';
        return null;
    }
    return $data;
}

// ---------------------------------------------------------------- Aktionen
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $action = postStr('action');
    $back = backUrl('/customers.php');
    $err = null;

    $managerOnly = ['create_customer', 'update_customer', 'set_customer_active', 'delete_customer', 'merge_customer', 'assign_jobs'];
    if (in_array($action, $managerOnly, true) && !$isMgr) {
        backTo($back, null, 'Nur Manager und Admins dürfen Kunden verwalten.');
    }

    if ($action === 'create_customer') {
        $data = customerFromPost($err);
        if (!$data) backTo($back, null, $err);
        db()->prepare('INSERT INTO customers (name, customer_no, address, contact, note) VALUES (?, ?, ?, ?, ?)')
            ->execute(array_values($data));
        $id = (int)db()->lastInsertId();
        backTo('/customers.php?id=' . $id, 'Kunde „' . $data['name'] . '“ angelegt.');
    }

    if ($action === 'assign_jobs') {
        // Bisherige Aufträge ohne Kunde anhand des Auftragsnamens zuordnen.
        $jobName = postStr('job_name');
        $target = postStr('target');
        if ($jobName === '') backTo($back, null, 'Auftragsname fehlt.');
        if ($target === 'new') {
            db()->prepare('INSERT INTO customers (name) VALUES (?)')->execute([mb_substr($jobName, 0, 200)]);
            $customerId = (int)db()->lastInsertId();
        } else {
            $customerId = (int)$target;
            if (!customerById($customerId)) backTo($back, null, 'Bitte einen Kunden wählen.');
        }
        $ids = db()->prepare('SELECT id FROM job_entries WHERE customer_id IS NULL AND job_name = ?');
        $ids->execute([$jobName]);
        $count = 0;
        foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $jobId) {
            $before = fetchEntity('job_entry', (int)$jobId);
            db()->prepare('UPDATE job_entries SET customer_id = ? WHERE id = ?')->execute([$customerId, $jobId]);
            auditLog((int)$me['id'], 'job_entry', (int)$jobId, 'update', $before, fetchEntity('job_entry', (int)$jobId));
            $count++;
        }
        backTo($back, $count . ' Aufträge „' . $jobName . '“ zugeordnet.');
    }

    if (in_array($action, ['update_customer', 'set_customer_active', 'delete_customer', 'merge_customer'], true)) {
        $customer = customerById(postInt('customer_id'));
        if (!$customer) backTo($back, null, 'Kunde nicht gefunden.');
        $cid = (int)$customer['id'];

        if ($action === 'update_customer') {
            $data = customerFromPost($err);
            if (!$data) backTo($back, null, $err);
            db()->prepare('UPDATE customers SET name = ?, customer_no = ?, address = ?, contact = ?, note = ? WHERE id = ?')
                ->execute([...array_values($data), $cid]);
            backTo($back, 'Kundendaten gespeichert.');
        }
        if ($action === 'set_customer_active') {
            $active = postInt('value') === 1 ? 1 : 0;
            db()->prepare('UPDATE customers SET is_active = ? WHERE id = ?')->execute([$active, $cid]);
            backTo($back, $active ? 'Kunde ist wieder aktiv.' : 'Kunde deaktiviert. Er erscheint nicht mehr in der Auswahl bei neuen Aufträgen.');
        }
        if ($action === 'delete_customer') {
            $used = db()->prepare('SELECT (SELECT COUNT(*) FROM job_entries WHERE customer_id = ?) + (SELECT COUNT(*) FROM materials WHERE customer_id = ?)');
            $used->execute([$cid, $cid]);
            if ((int)$used->fetchColumn() > 0) {
                backTo($back, null, 'Der Kunde hat Aufträge oder Material und kann nicht gelöscht werden. Du kannst ihn stattdessen deaktivieren.');
            }
            db()->prepare('DELETE FROM customers WHERE id = ?')->execute([$cid]);
            backTo('/customers.php', 'Kunde gelöscht.');
        }
        if ($action === 'merge_customer') {
            // Doppelt angelegten Kunden in einen anderen überführen.
            $target = customerById(postInt('target_id'));
            if (!$target || (int)$target['id'] === $cid) backTo($back, null, 'Bitte einen anderen Kunden als Ziel wählen.');
            $tid = (int)$target['id'];
            foreach (['job_entry' => 'job_entries', 'material' => 'materials'] as $type => $table) {
                $ids = db()->prepare("SELECT id FROM $table WHERE customer_id = ?");
                $ids->execute([$cid]);
                foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $eid) {
                    $before = fetchEntity($type, (int)$eid);
                    db()->prepare("UPDATE $table SET customer_id = ? WHERE id = ?")->execute([$tid, $eid]);
                    auditLog((int)$me['id'], $type, (int)$eid, 'update', $before, fetchEntity($type, (int)$eid));
                }
            }
            db()->prepare('DELETE FROM customers WHERE id = ?')->execute([$cid]);
            backTo('/customers.php?id=' . $tid, '„' . $customer['name'] . '“ wurde mit „' . $target['name'] . '“ zusammengeführt.');
        }
    }

    handleMaterialPost($me, $action, $back);
    backTo($back, null, 'Unbekannte Aktion.');
}

$customer = isset($_GET['id']) ? customerById((int)$_GET['id']) : null;
[$month, $monthFrom, $monthTo] = monthRange($_GET['month'] ?? null);
$monthStart = $monthFrom . ' 00:00:00';
$monthEnd = $monthTo . ' 23:59:59';

renderHeader($customer ? $customer['name'] : 'Kunden', 'customers');
echo renderFlash();

if ($customer):
    // ------------------------------------------------------------ Detailseite
    $cid = (int)$customer['id'];
    $self = '/customers.php?id=' . $cid . '&month=' . $month;
    $prev = (new DateTimeImmutable($monthFrom))->modify('-1 month')->format('Y-m');
    $next = (new DateTimeImmutable($monthFrom))->modify('+1 month')->format('Y-m');

    // Manager sehen alle Aufträge und alles Material des Kunden, Benutzer nur ihre eigenen.
    $ownOnly = $isMgr ? '' : ' AND e.user_id = ' . (int)$me['id'];
    $jobs = db()->prepare(
        "SELECT e.*, COALESCE(NULLIF(u.name, ''), u.username) AS user_display FROM job_entries e JOIN users u ON u.id = e.user_id
         WHERE e.customer_id = ? AND e.start_time >= ? AND e.start_time <= ? $ownOnly
         ORDER BY e.start_time"
    );
    $jobs->execute([$cid, $monthStart, $monthEnd]);
    $jobs = $jobs->fetchAll();

    $ownOnlyM = $isMgr ? '' : ' AND m.user_id = ' . (int)$me['id'];
    $materials = db()->prepare(
        "SELECT m.*, COALESCE(NULLIF(u.name, ''), u.username) AS user_display FROM materials m LEFT JOIN users u ON u.id = m.user_id
         WHERE m.customer_id = ? AND m.used_on >= ? AND m.used_on <= ? $ownOnlyM
         ORDER BY m.used_on, m.id"
    );
    $materials->execute([$cid, $monthFrom, $monthTo]);
    $materials = $materials->fetchAll();

    $total = 0;
    $byType = [];
    foreach ($jobs as $j) {
        if (!$j['end_time']) continue;
        $secs = diffSeconds($j['start_time'], $j['end_time']);
        $total += $secs;
        $label = jobTypeLabel($j['job_type']) ?: 'Ohne Art';
        $byType[$label] = ($byType[$label] ?? 0) + $secs;
    }
    $today = date('Y-m-d');
    $defaultDate = ($today >= $monthFrom && $today <= $monthTo) ? $today : $monthFrom;
    ?>
<p class="breadcrumb"><a href="/customers.php">← Alle Kunden</a></p>

<section class="card">
    <div class="card-head">
        <h2><?= h($customer['name']) ?><?= $customer['customer_no'] !== '' ? ' <span class="muted">Nr. ' . h($customer['customer_no']) . '</span>' : '' ?></h2>
        <?php if (!(int)$customer['is_active']): ?><span class="badge">inaktiv</span><?php endif; ?>
    </div>
    <div class="customer-info">
        <?php if ($customer['address'] !== ''): ?><div><span class="muted">Adresse</span><br><?= nl2br(h($customer['address'])) ?></div><?php endif; ?>
        <?php if ($customer['contact'] !== ''): ?><div><span class="muted">Kontakt</span><br><?= h($customer['contact']) ?></div><?php endif; ?>
        <?php if ($customer['note'] !== ''): ?><div><span class="muted">Notiz</span><br><?= nl2br(h($customer['note'])) ?></div><?php endif; ?>
    </div>
    <?php if ($isMgr): ?>
    <details class="manual-add">
        <summary>Kundendaten bearbeiten</summary>
        <form method="post" class="customer-form">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_customer">
            <input type="hidden" name="customer_id" value="<?= $cid ?>">
            <input type="hidden" name="back" value="<?= h($self) ?>">
            <label>Name<input type="text" name="name" required maxlength="200" value="<?= h($customer['name']) ?>"></label>
            <label>Kundennummer<input type="text" name="customer_no" maxlength="50" value="<?= h($customer['customer_no']) ?>"></label>
            <label>Adresse<textarea name="address" rows="3" maxlength="500"><?= h($customer['address']) ?></textarea></label>
            <label>Kontakt (Ansprechpartner, Telefon, E-Mail)<input type="text" name="contact" maxlength="300" value="<?= h($customer['contact']) ?>"></label>
            <label>Notiz<textarea name="note" rows="2" maxlength="1000"><?= h($customer['note']) ?></textarea></label>
            <div class="form-actions">
                <button type="submit" class="primary">Speichern</button>
            </div>
        </form>
        <div class="form-actions">
            <form method="post" class="inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="set_customer_active">
                <input type="hidden" name="customer_id" value="<?= $cid ?>">
                <input type="hidden" name="back" value="<?= h($self) ?>">
                <input type="hidden" name="value" value="<?= (int)$customer['is_active'] ? 0 : 1 ?>">
                <button type="submit"><?= (int)$customer['is_active'] ? 'Deaktivieren' : 'Wieder aktivieren' ?></button>
            </form>
            <form method="post" class="inline" onsubmit="return confirm('Kunden löschen?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete_customer">
                <input type="hidden" name="customer_id" value="<?= $cid ?>">
                <input type="hidden" name="back" value="<?= h($self) ?>">
                <button type="submit" class="danger">Löschen</button>
            </form>
        </div>
        <p class="hint">Löschen geht nur, solange der Kunde keine Aufträge und kein Material hat.</p>
        <form method="post" class="inline-form merge-form" onsubmit="return confirm('Alle Aufträge und das Material in den gewählten Kunden verschieben und diesen Kunden löschen?')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="merge_customer">
            <input type="hidden" name="customer_id" value="<?= $cid ?>">
            <input type="hidden" name="back" value="<?= h($self) ?>">
            <label>Doppelt angelegt? Zusammenführen mit
                <select name="target_id" required>
                    <option value="">– Kunde wählen –</option>
                    <?php foreach (customerList(true) as $c): if ((int)$c['id'] === $cid) continue; ?>
                        <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit">Zusammenführen</button>
        </form>
    </details>
    <?php endif; ?>
</section>

<section class="card">
    <div class="card-head month-head">
        <a href="/customers.php?id=<?= $cid ?>&month=<?= h($prev) ?>">‹ <?= h(monthLabel($prev)) ?></a>
        <h2><?= h(monthLabel($month)) ?></h2>
        <a href="/customers.php?id=<?= $cid ?>&month=<?= h($next) ?>"><?= h(monthLabel($next)) ?> ›</a>
    </div>
    <form method="get" action="/customers.php" class="inline-form month-pick">
        <input type="hidden" name="id" value="<?= $cid ?>">
        <label>Monat<select name="month" onchange="this.form.submit()"><?= monthOptions($month) ?></select></label>
        <noscript><button type="submit">Anzeigen</button></noscript>
    </form>
    <?php if ($isMgr): ?>
    <div class="pdf-buttons">
        <a class="btn primary" href="/customer_pdf.php?id=<?= $cid ?>&month=<?= h($month) ?>&variant=customer" target="_blank">PDF für Kunden</a>
        <a class="btn" href="/customer_pdf.php?id=<?= $cid ?>&month=<?= h($month) ?>&variant=internal" target="_blank">PDF intern (mit EK)</a>
    </div>
    <?php endif; ?>
</section>

<section class="card">
    <div class="card-head">
        <h2>Aufträge</h2>
        <span class="muted">Gesamt: <strong><?= h(fmtDurationShort($total)) ?></strong></span>
    </div>
    <?php if ($byType): ?>
        <p class="muted type-sums"><?php foreach ($byType as $label => $secs): ?><span><?= h($label) ?>: <?= h(fmtDurationShort($secs)) ?></span><?php endforeach; ?></p>
    <?php endif; ?>
    <?php if (!$jobs): ?>
        <p class="muted">Keine Aufträge in diesem Monat.</p>
    <?php else: ?>
    <div class="table-wrap">
    <table class="customer-jobs">
        <thead><tr><th>Datum</th><?php if ($isMgr): ?><th>Mitarbeiter</th><?php endif; ?><th>Art</th><th>Auftrag</th><th>Zeit</th><th class="num">Dauer</th><th>Abgerechnet</th></tr></thead>
        <tbody>
        <?php foreach ($jobs as $j): ?>
            <tr class="<?= $j['is_recorded'] ? 'is-recorded' : '' ?>">
                <td data-label="Datum"><?= h(date('d.m.Y', strtotime($j['start_time']))) ?></td>
                <?php if ($isMgr): ?><td data-label="Mitarbeiter"><?= h($j['user_display']) ?></td><?php endif; ?>
                <td data-label="Art"><?= h(jobTypeLabel($j['job_type'])) ?></td>
                <td data-label="Auftrag"><?= h($j['job_name']) ?><?php if ($j['note'] !== ''): ?><br><span class="muted"><?= h($j['note']) ?></span><?php endif; ?></td>
                <td data-label="Zeit"><?= h(substr($j['start_time'], 11, 5)) ?>–<?= $j['end_time'] ? h(substr($j['end_time'], 11, 5)) : '…' ?></td>
                <td data-label="Dauer" class="num"><?= $j['end_time'] ? h(fmtDurationShort(diffSeconds($j['start_time'], $j['end_time']))) : '<em>läuft…</em>' ?></td>
                <td data-label="Abgerechnet"><?= $j['is_recorded'] ? 'Ja' : 'Nein' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
    <p class="hint">Bearbeiten und als abgerechnet markieren unter <a href="/jobs.php?customer=<?= $cid ?>&month=<?= h($month) ?>">Aufträge</a><?= $isMgr ? ' (je Mitarbeiter)' : '' ?>.</p>
</section>

<section class="card">
    <h2>Material</h2>
    <form method="post" class="inline-form material-form">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create_material">
        <input type="hidden" name="customer_id" value="<?= $cid ?>">
        <input type="hidden" name="back" value="<?= h($self) ?>">
        <?= materialFields(null, $defaultDate) ?>
        <button type="submit" class="primary">Eintragen</button>
    </form>
    <?= unitDatalist() ?>

    <?php if (!$materials): ?>
        <p class="muted">Kein Material in diesem Monat.</p>
    <?php else: ?>
        <?= renderMaterialTable($materials, '/customers.php', $self, $isMgr, false) ?>
    <?php endif; ?>
</section>
<?php
else:
    // ------------------------------------------------------------ Kundenliste
    $showInactive = !empty($_GET['inactive']);
    $customers = customerList($showInactive);
    $stats = db()->prepare(
        'SELECT customer_id, start_time, end_time FROM job_entries
         WHERE customer_id IS NOT NULL AND end_time IS NOT NULL AND start_time >= ? AND start_time <= ?'
         . ($isMgr ? '' : ' AND user_id = ' . (int)$me['id'])
    );
    $stats->execute([$monthStart, $monthEnd]);
    $hours = [];
    foreach ($stats->fetchAll() as $r) {
        $hours[$r['customer_id']] = ($hours[$r['customer_id']] ?? 0) + diffSeconds($r['start_time'], $r['end_time']);
    }
    $matStmt = db()->prepare(
        'SELECT customer_id, COUNT(*) FROM materials WHERE used_on >= ? AND used_on <= ?'
        . ($isMgr ? '' : ' AND user_id = ' . (int)$me['id']) . ' GROUP BY customer_id'
    );
    $matStmt->execute([$monthFrom, $monthTo]);
    $matCount = $matStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $unassigned = $isMgr ? db()->query(
        'SELECT job_name, COUNT(*) AS n, MAX(start_time) AS last FROM job_entries
         WHERE customer_id IS NULL GROUP BY job_name ORDER BY n DESC, job_name LIMIT 100'
    )->fetchAll() : [];
    ?>
<?php if ($isMgr): ?>
<section class="card">
    <h2>Neuer Kunde</h2>
    <form method="post" class="customer-form">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create_customer">
        <label>Name<input type="text" name="name" required maxlength="200" placeholder="z.B. Müller GmbH"></label>
        <label>Kundennummer<input type="text" name="customer_no" maxlength="50" placeholder="optional"></label>
        <label>Adresse<textarea name="address" rows="3" maxlength="500" placeholder="optional"></textarea></label>
        <label>Kontakt (Ansprechpartner, Telefon, E-Mail)<input type="text" name="contact" maxlength="300" placeholder="optional"></label>
        <label>Notiz<textarea name="note" rows="2" maxlength="1000" placeholder="optional"></textarea></label>
        <div class="form-actions"><button type="submit" class="primary">Anlegen</button></div>
    </form>
</section>
<?php endif; ?>

<section class="card">
    <div class="card-head">
        <h2>Kunden</h2>
        <a href="/customers.php<?= $showInactive ? '' : '?inactive=1' ?>" class="muted"><?= $showInactive ? 'Inaktive ausblenden' : 'Inaktive anzeigen' ?></a>
    </div>
    <?php if (!$customers): ?>
        <p class="muted">Noch keine Kunden angelegt.<?= $isMgr ? '' : ' Kunden legen Manager oder Admins an.' ?></p>
    <?php else: ?>
    <div class="table-wrap">
    <table class="customers">
        <thead><tr><th>Kunde</th><th>Nr.</th><th class="num">Zeit <?= h(monthLabel($month)) ?></th><th class="num">Material</th></tr></thead>
        <tbody>
        <?php foreach ($customers as $c): ?>
            <tr class="<?= (int)$c['is_active'] ? '' : 'is-recorded' ?>">
                <td data-label="Kunde"><a href="/customers.php?id=<?= (int)$c['id'] ?>"><?= h($c['name']) ?></a><?= (int)$c['is_active'] ? '' : ' <span class="badge">inaktiv</span>' ?></td>
                <td data-label="Nr."><?= h($c['customer_no']) ?></td>
                <td data-label="Zeit" class="num"><?= isset($hours[$c['id']]) ? h(fmtDurationShort($hours[$c['id']])) : '–' ?></td>
                <td data-label="Material" class="num"><?= (int)($matCount[$c['id']] ?? 0) ?: '–' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</section>

<?php if ($isMgr && $customers): ?>
<section class="card">
    <h2>PDF-Export je Kunde</h2>
    <form method="get" action="/customer_pdf.php" class="inline-form" target="_blank">
        <label>Kunde<select name="id" required><?= customerOptions(customerList(true), null, '– Kunde wählen –') ?></select></label>
        <label>Monat<select name="month" required><?= monthOptions(date('Y-m')) ?></select></label>
        <label>Variante
            <select name="variant">
                <option value="customer">Für Kunden</option>
                <option value="internal">Intern (mit EK-Preisen)</option>
            </select>
        </label>
        <button type="submit" class="primary">PDF erstellen</button>
    </form>
    <p class="hint">Enthält alle Aufträge (aller Mitarbeiter) und das Material des Kunden im gewählten Monat. Die interne Variante zeigt zusätzlich die Einkaufspreise.</p>
</section>
<?php endif; ?>

<?php if ($unassigned): ?>
<section class="card">
    <h2>Aufträge ohne Kunde zuordnen</h2>
    <p class="hint">Bisherige Aufträge haben noch keinen Kunden. Hier kannst du alle Aufträge mit gleichem Namen auf einmal einem Kunden zuordnen oder aus dem Namen einen neuen Kunden anlegen.</p>
    <div class="table-wrap">
    <table class="assign">
        <thead><tr><th>Auftragsname</th><th class="num">Einträge</th><th>Zuordnen zu</th></tr></thead>
        <tbody>
        <?php $allCustomers = customerList(); foreach ($unassigned as $u): ?>
            <tr>
                <td data-label="Auftrag"><?= h($u['job_name']) ?> <span class="muted">zuletzt <?= h(date('d.m.Y', strtotime($u['last']))) ?></span></td>
                <td data-label="Einträge" class="num"><?= (int)$u['n'] ?></td>
                <td>
                    <form method="post" class="inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="assign_jobs">
                        <input type="hidden" name="job_name" value="<?= h($u['job_name']) ?>">
                        <select name="target" required>
                            <option value="">– wählen –</option>
                            <option value="new">Neuer Kunde „<?= h(mb_strimwidth($u['job_name'], 0, 40, '…')) ?>“</option>
                            <?php foreach ($allCustomers as $c): ?>
                                <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit">Zuordnen</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
<?php endif; ?>
<?php
endif;
renderFooter();
