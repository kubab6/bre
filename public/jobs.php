<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';

$me = requireLogin();
$user = viewedUser($me);
$own = (int)$user['id'] === (int)$me['id'];

$active = db()->prepare('SELECT id, job_name, note, start_time FROM job_entries WHERE user_id = ? AND end_time IS NULL ORDER BY start_time DESC LIMIT 1');
$active->execute([$user['id']]);
$activeJob = $active->fetch();

$since = (new DateTime('-29 days'))->setTime(0, 0)->format('Y-m-d H:i:s');
$rowsStmt = db()->prepare(
    'SELECT id, job_name, start_time, end_time, note, is_recorded FROM job_entries
     WHERE user_id = ? AND start_time >= ?
     ORDER BY start_time DESC'
);
$rowsStmt->execute([$user['id'], $since]);
$rows = $rowsStmt->fetchAll();

$totalsByJob = [];
$grandTotal = 0;
foreach ($rows as $r) {
    if (!$r['end_time']) continue;
    $secs = diffSeconds($r['start_time'], $r['end_time']);
    $totalsByJob[$r['job_name']] = ($totalsByJob[$r['job_name']] ?? 0) + $secs;
    $grandTotal += $secs;
}
arsort($totalsByJob);

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
                <strong><?= h($activeJob['job_name']) ?></strong>
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
            <label>Auftrag<input type="text" name="job_name" required maxlength="200" placeholder="z.B. Müller GmbH"></label>
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
        <h2>Letzte 30 Tage</h2>
        <span class="muted">Gesamt: <strong><?= h(fmtDurationShort($grandTotal)) ?></strong></span>
    </div>

    <?php if ($totalsByJob): ?>
        <details class="job-totals">
            <summary>Summen pro Auftrag</summary>
            <ul>
                <?php foreach ($totalsByJob as $name => $secs): ?>
                    <li><?= h($name) ?> <span class="muted"><?= h(fmtDurationShort($secs)) ?></span></li>
                <?php endforeach; ?>
            </ul>
        </details>
    <?php endif; ?>

    <div class="job-list" data-csrf="<?= h(csrfToken()) ?>">
        <?php if (!$rows): ?>
            <p class="muted">Keine Einträge</p>
        <?php else: foreach ($rows as $r): ?>
            <div class="job-row <?= $r['is_recorded'] ? 'is-recorded' : '' ?>" data-id="<?= (int)$r['id'] ?>">
                <div class="job-row-main">
                    <label>Auftrag<input type="text" class="edit-job" value="<?= h($r['job_name']) ?>" maxlength="200"></label>
                    <label>Notiz<input type="text" class="edit-note" value="<?= h($r['note']) ?>" maxlength="500"></label>
                </div>
                <div class="job-row-meta">
                    <label>Start<input type="datetime-local" class="edit-start" value="<?= h(toLocalInputValue($r['start_time'])) ?>"></label>
                    <label>Ende<input type="datetime-local" class="edit-end" value="<?= h(toLocalInputValue($r['end_time'])) ?>" <?= $r['end_time'] === null ? 'placeholder="läuft…"' : '' ?>></label>
                    <span class="dur" title="Dauer">
                        <?= $r['end_time'] ? h(fmtDurationShort(diffSeconds($r['start_time'], $r['end_time']))) : '<em>läuft…</em>' ?>
                    </span>
                    <label class="checkbox-inline" title="Als eingetragen markieren">
                        <input type="checkbox" class="edit-recorded" <?= $r['is_recorded'] ? 'checked' : '' ?>>
                        Eingetragen
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
