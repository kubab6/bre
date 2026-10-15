<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/absence.php';

$user = requireLogin();

$showAll = !empty($_GET['all']) && isManager($user);
$entityFilter = $_GET['entity'] ?? '';

$where = [];
$params = [];
if (!$showAll) {
    $where[] = 'l.target_user_id = ?';
    $params[] = $user['id'];
}
if (isset(AUDIT_FIELDS[$entityFilter])) {
    $where[] = 'l.entity_type = ?';
    $params[] = $entityFilter;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$limit = 200;
$sql = "SELECT l.*,
               ua.username AS actor_name,
               ut.username AS target_name
        FROM audit_log l
        LEFT JOIN users ua ON ua.id = l.actor_id
        LEFT JOIN users ut ON ut.id = l.target_user_id
        $whereSql
        ORDER BY l.id DESC
        LIMIT $limit";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$entries = $stmt->fetchAll();

function entityLabel(string $type): string {
    return ['work_session' => 'Arbeitszeit', 'job_entry' => 'Auftrag', 'absence' => 'Abwesenheit'][$type] ?? $type;
}

function actionLabel(string $a): string {
    return ['create' => 'Erstellt', 'update' => 'Geändert', 'delete' => 'Gelöscht'][$a] ?? $a;
}

function fieldLabel(string $f): string {
    return [
        'start_time' => 'Start',
        'end_time' => 'Ende',
        'job_name' => 'Auftrag',
        'note' => 'Notiz',
        'is_recorded' => 'Eingetragen',
        'type' => 'Art',
        'start_date' => 'Von',
        'end_date' => 'Bis',
    ][$f] ?? $f;
}

function fmtVal(string $field, $val): string {
    if ($val === null || $val === '') return '∅';
    if ($field === 'is_recorded') return ((int)$val === 1) ? 'Ja' : 'Nein';
    if ($field === 'type') return absenceLabel((string)$val);
    return (string)$val;
}

function diffRows(?array $before, ?array $after): array {
    $rows = [];
    $keys = array_unique(array_merge(array_keys($before ?? []), array_keys($after ?? [])));
    foreach ($keys as $k) {
        $b = $before[$k] ?? null;
        $a = $after[$k] ?? null;
        if ($b === $a) continue;
        $rows[] = [$k, $b, $a];
    }
    return $rows;
}

renderHeader('Protokoll', 'log');
?>
<section class="card">
    <h2>Änderungs-Protokoll</h2>
    <form method="get" class="inline-form">
        <label>Typ
            <select name="entity">
                <option value="">Alle</option>
                <option value="work_session" <?= $entityFilter === 'work_session' ? 'selected' : '' ?>>Arbeitszeit</option>
                <option value="job_entry" <?= $entityFilter === 'job_entry' ? 'selected' : '' ?>>Aufträge</option>
                <option value="absence" <?= $entityFilter === 'absence' ? 'selected' : '' ?>>Abwesenheiten</option>
            </select>
        </label>
        <?php if (isManager($user)): ?>
            <label class="checkbox">
                <input type="checkbox" name="all" value="1" <?= $showAll ? 'checked' : '' ?>>
                Alle Benutzer
            </label>
        <?php endif; ?>
        <button type="submit">Filtern</button>
    </form>
    <p class="hint">Letzte <?= count($entries) ?> Einträge<?= count($entries) >= $limit ? ' (begrenzt auf '.$limit.')' : '' ?>.</p>

    <?php if (!$entries): ?>
        <p class="muted">Noch keine Änderungen protokolliert.</p>
    <?php else: ?>
    <div class="table-wrap">
    <table class="audit">
        <thead>
            <tr><th>Wann</th><?php if ($showAll): ?><th>Benutzer</th><?php endif; ?><th>Typ</th><th>ID</th><th>Aktion</th><th>Änderung</th></tr>
        </thead>
        <tbody>
        <?php foreach ($entries as $e):
            $before = $e['before_json'] ? json_decode($e['before_json'], true) : null;
            $after  = $e['after_json']  ? json_decode($e['after_json'],  true) : null;
            $diff = diffRows($before, $after);
        ?>
            <tr>
                <td class="nowrap"><?= h($e['created_at']) ?></td>
                <?php if ($showAll): ?>
                    <td><?= h($e['target_name'] ?? '#'.$e['target_user_id']) ?><?php if ($e['actor_id'] !== $e['target_user_id']): ?>
                        <span class="muted"><br>von <?= h($e['actor_name'] ?? '#'.$e['actor_id']) ?></span>
                    <?php endif; ?></td>
                <?php endif; ?>
                <td><?= h(entityLabel($e['entity_type'])) ?></td>
                <td class="muted">#<?= (int)$e['entity_id'] ?></td>
                <td><?= h(actionLabel($e['action'])) ?></td>
                <td>
                    <?php if ($e['action'] === 'create' && $after): ?>
                        <ul class="diff">
                            <?php foreach ($after as $f => $v): if ($v === null || $v === '' || $v === 0) continue; ?>
                                <li><span class="field"><?= h(fieldLabel($f)) ?>:</span> <?= h(fmtVal($f, $v)) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php elseif ($e['action'] === 'delete' && $before): ?>
                        <ul class="diff">
                            <?php foreach ($before as $f => $v): if ($v === null || $v === '') continue; ?>
                                <li><span class="field"><?= h(fieldLabel($f)) ?>:</span> <?= h(fmtVal($f, $v)) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <ul class="diff">
                            <?php foreach ($diff as [$f, $b, $a]): ?>
                                <li>
                                    <span class="field"><?= h(fieldLabel($f)) ?>:</span>
                                    <span class="old"><?= h(fmtVal($f, $b)) ?></span>
                                    →
                                    <span class="new"><?= h(fmtVal($f, $a)) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</section>
<?php
renderFooter();
