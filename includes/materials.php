<?php
declare(strict_types=1);

require_once __DIR__ . '/customers.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/csrf.php';

/** Nach einer Aktion mit Meldung zurück auf die Seite (Post/Redirect/Get). */
function backTo(string $url, ?string $msg, ?string $err = null): void {
    $_SESSION['flash'] = ['msg' => $msg, 'err' => $err];
    header('Location: ' . $url);
    exit;
}

/** Rücksprung-URL aus dem Formular, nur auf erlaubte Seiten. */
function backUrl(string $default): string {
    $back = (string)($_POST['back'] ?? $default);
    return preg_match('#^/(customers|jobs)\.php(\?[\w=&%.-]*)?$#', $back) ? $back : $default;
}

function renderFlash(): string {
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    $html = '';
    if (!empty($flash['msg'])) $html .= '<p class="flash success">' . h($flash['msg']) . '</p>';
    if (!empty($flash['err'])) $html .= '<p class="flash error">' . h($flash['err']) . '</p>';
    return $html;
}

/** Material des Formulars prüfen; null + Fehlertext bei ungültiger Eingabe. */
function materialFromPost(?string &$err): ?array {
    $date = postStr('used_on');
    $qty = parseQty(postStr('quantity', '1'));
    $price = parsePrice(postStr('purchase_price'));
    $data = [
        'used_on' => $date,
        'description' => postStr('description'),
        'quantity' => $qty,
        'unit' => postStr('unit', 'Stk') ?: 'Stk',
        'purchase_price' => $price,
        'note' => postStr('note'),
    ];
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) { $err = 'Ungültiges Datum.'; return null; }
    if ($data['description'] === '' || mb_strlen($data['description']) > 300) { $err = 'Materialbezeichnung erforderlich (max. 300 Zeichen).'; return null; }
    if ($qty === null) { $err = 'Ungültige Menge.'; return null; }
    if ($price === false) { $err = 'Ungültiger Einkaufspreis.'; return null; }
    if (mb_strlen($data['unit']) > 20 || mb_strlen($data['note']) > 500) { $err = 'Einheit oder Notiz zu lang.'; return null; }
    return $data;
}

/** Material laden, wenn $me es bearbeiten darf (eigenes oder Manager). */
function editableMaterial(array $me, int $id): ?array {
    $stmt = db()->prepare('SELECT * FROM materials WHERE id = ?');
    $stmt->execute([$id]);
    $m = $stmt->fetch();
    if (!$m) return null;
    return (isManager($me) || (int)$m['user_id'] === (int)$me['id']) ? $m : null;
}

/**
 * Material-Aktionen aus einem POST ausführen (leitet immer weiter).
 * Gibt false zurück, wenn die Aktion keine Material-Aktion ist.
 */
function handleMaterialPost(array $me, string $action, string $back): bool {
    if ($action === 'create_material') {
        // Kunde entweder per ID (Kundenseite) oder als eingetippter Name (Aufträge).
        if (isset($_POST['customer_name'])) {
            $cid = resolveCustomerName(postStr('customer_name'));
            if ($cid === null) backTo($back, null, 'Bitte einen Kunden angeben.');
            if ($cid === false) backTo($back, null, 'Kundenname zu lang (max. 200 Zeichen).');
        } else {
            $c = customerById(postInt('customer_id'));
            if (!$c) backTo($back, null, 'Kunde nicht gefunden.');
            $cid = (int)$c['id'];
        }
        $err = null;
        $data = materialFromPost($err);
        if (!$data) backTo($back, null, $err);
        db()->prepare('INSERT INTO materials (customer_id, user_id, used_on, description, quantity, unit, purchase_price, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$cid, (int)$me['id'], ...array_values($data)]);
        $id = (int)db()->lastInsertId();
        auditLog((int)$me['id'], 'material', $id, 'create', null, fetchEntity('material', $id));
        backTo($back, 'Material eingetragen.');
    }

    if (!in_array($action, ['update_material', 'set_material_recorded', 'delete_material'], true)) return false;

    $m = editableMaterial($me, postInt('material_id'));
    if (!$m) backTo($back, null, 'Material nicht gefunden.');
    $mid = (int)$m['id'];
    $before = fetchEntity('material', $mid);
    if ($action === 'update_material') {
        $err = null;
        $data = materialFromPost($err);
        if (!$data) backTo($back, null, $err);
        db()->prepare('UPDATE materials SET used_on = ?, description = ?, quantity = ?, unit = ?, purchase_price = ?, note = ? WHERE id = ?')
            ->execute([...array_values($data), $mid]);
        auditLog((int)$me['id'], 'material', $mid, 'update', $before, fetchEntity('material', $mid));
        backTo($back, 'Material gespeichert.');
    }
    if ($action === 'set_material_recorded') {
        db()->prepare('UPDATE materials SET is_recorded = ? WHERE id = ?')->execute([postInt('value') === 1 ? 1 : 0, $mid]);
        auditLog((int)$me['id'], 'material', $mid, 'update', $before, fetchEntity('material', $mid));
        backTo($back, null);
    }
    db()->prepare('DELETE FROM materials WHERE id = ?')->execute([$mid]);
    auditLog((int)$me['id'], 'material', $mid, 'delete', $before, null);
    backTo($back, 'Material gelöscht.');
    return true;
}

/** Eingabefelder für Material (Neu und Bearbeiten). */
function materialFields(?array $m, string $defaultDate): string {
    $price = $m && $m['purchase_price'] !== null ? number_format((float)$m['purchase_price'], 2, ',', '') : '';
    return '<label>Datum<input type="date" name="used_on" required value="' . h($m['used_on'] ?? $defaultDate) . '" data-today></label>'
        . '<label class="grow">Material<input type="text" name="description" required maxlength="300" value="' . h($m['description'] ?? '') . '"' . ($m ? '' : ' placeholder="z.B. Netzwerkkabel Cat6 5 m"') . '></label>'
        . '<label>Menge<input type="text" name="quantity" required value="' . h($m ? fmtQty((float)$m['quantity']) : '1') . '" inputmode="decimal" class="qty"></label>'
        . '<label>Einheit<input type="text" name="unit" maxlength="20" value="' . h($m['unit'] ?? 'Stk') . '" class="unit" list="units"></label>'
        . '<label title="Einkaufspreis je Einheit, nur intern sichtbar">EK je Einheit (€)<input type="text" name="purchase_price" value="' . h($price) . '" inputmode="decimal" class="qty" placeholder="intern"></label>'
        . '<label class="grow">Notiz<input type="text" name="note" maxlength="500" value="' . h($m['note'] ?? '') . '"' . ($m ? '' : ' placeholder="optional"') . '></label>';
}

function unitDatalist(): string {
    return '<datalist id="units"><option value="Stk"><option value="m"><option value="Std"><option value="Pkg"><option value="Set"><option value="l"></datalist>';
}

/**
 * Materialtabelle mit Abgerechnet-Häkchen, Bearbeiten und Löschen.
 * Formulare gehen an $action (Seite) und springen nach $back zurück.
 */
function renderMaterialTable(array $materials, string $action, string $back, bool $showUser, bool $showCustomer): string {
    ob_start(); ?>
    <div class="table-wrap">
    <table class="materials">
        <thead><tr>
            <th>Datum</th><?php if ($showCustomer): ?><th>Kunde</th><?php endif; ?><th>Material</th><th class="num">Menge</th><th class="num">EK</th>
            <?php if ($showUser): ?><th>Von</th><?php endif; ?><th>Abgerechnet</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($materials as $m): ?>
            <tr class="<?= $m['is_recorded'] ? 'is-recorded' : '' ?>">
                <td data-label="Datum"><?= h(date('d.m.Y', strtotime($m['used_on']))) ?></td>
                <?php if ($showCustomer): ?><td data-label="Kunde"><a href="/customers.php?id=<?= (int)$m['customer_id'] ?>&month=<?= h(substr($m['used_on'], 0, 7)) ?>"><?= h($m['customer_name'] ?? '') ?></a></td><?php endif; ?>
                <td data-label="Material"><?= h($m['description']) ?><?php if ($m['note'] !== ''): ?><br><span class="muted"><?= h($m['note']) ?></span><?php endif; ?></td>
                <td data-label="Menge" class="num"><?= h(fmtQty((float)$m['quantity'])) ?> <?= h($m['unit']) ?></td>
                <td data-label="EK" class="num" title="Einkaufspreis je Einheit (intern)"><?= $m['purchase_price'] !== null ? h(fmtMoney((float)$m['purchase_price'])) : '–' ?></td>
                <?php if ($showUser): ?><td data-label="Von"><?= h($m['user_display'] ?? '–') ?></td><?php endif; ?>
                <td data-label="Abgerechnet">
                    <form method="post" action="<?= h($action) ?>" class="inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="set_material_recorded">
                        <input type="hidden" name="material_id" value="<?= (int)$m['id'] ?>">
                        <input type="hidden" name="back" value="<?= h($back) ?>">
                        <input type="hidden" name="value" value="0">
                        <label class="checkbox"><input type="checkbox" name="value" value="1" <?= $m['is_recorded'] ? 'checked' : '' ?> onchange="this.form.submit()"> Abgerechnet</label>
                        <noscript><button type="submit">Setzen</button></noscript>
                    </form>
                </td>
                <td class="row-actions">
                    <details class="material-edit">
                        <summary>Bearbeiten</summary>
                        <form method="post" action="<?= h($action) ?>" class="inline-form">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="update_material">
                            <input type="hidden" name="material_id" value="<?= (int)$m['id'] ?>">
                            <input type="hidden" name="back" value="<?= h($back) ?>">
                            <?= materialFields($m, $m['used_on']) ?>
                            <button type="submit" class="primary">Speichern</button>
                        </form>
                        <form method="post" action="<?= h($action) ?>" class="inline" onsubmit="return confirm('Material löschen?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_material">
                            <input type="hidden" name="material_id" value="<?= (int)$m['id'] ?>">
                            <input type="hidden" name="back" value="<?= h($back) ?>">
                            <button type="submit" class="btn-delete">Löschen</button>
                        </form>
                    </details>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php
    return (string)ob_get_clean();
}
