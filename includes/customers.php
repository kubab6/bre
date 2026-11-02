<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

const JOB_TYPES = [
    'remote' => 'Fernwartung',
    'onsite' => 'Vor-Ort',
    'workshop' => 'Werkstatt',
];

function jobTypeLabel(?string $type): string {
    return JOB_TYPES[$type ?? ''] ?? '';
}

/** Gültige Auftragsart aus POST oder '' (keine Angabe). */
function postJobType(): string {
    $t = postStr('job_type');
    return isset(JOB_TYPES[$t]) ? $t : '';
}

/**
 * Kunden-ID aus POST: null = kein Kunde. Liefert false, wenn die ID
 * nicht existiert.
 * @return int|null|false
 */
function postCustomerId(string $key = 'customer_id') {
    $id = postInt($key);
    if ($id <= 0) return null;
    return customerById($id) ? $id : false;
}

function customerById(int $id): ?array {
    $stmt = db()->prepare('SELECT * FROM customers WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Alle Kunden alphabetisch; inaktive nur, wenn $withInactive. */
function customerList(bool $withInactive = false): array {
    $sql = 'SELECT id, name, customer_no, is_active FROM customers'
        . ($withInactive ? '' : ' WHERE is_active = 1')
        . ' ORDER BY name COLLATE NOCASE';
    return db()->query($sql)->fetchAll();
}

/** <option>-Liste für eine Kunden-Auswahl. Inaktive Kunden erscheinen nur, wenn ausgewählt. */
function customerOptions(array $customers, ?int $selected, string $emptyLabel = '– kein Kunde –'): string {
    $html = '<option value="">' . h($emptyLabel) . '</option>';
    foreach ($customers as $c) {
        $isSel = $selected !== null && (int)$c['id'] === $selected;
        if (!(int)$c['is_active'] && !$isSel) continue;
        $label = $c['name'] . ($c['customer_no'] !== '' ? ' (' . $c['customer_no'] . ')' : '');
        $html .= '<option value="' . (int)$c['id'] . '"' . ($isSel ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    return $html;
}

function jobTypeOptions(?string $selected): string {
    $html = '<option value="">– Art –</option>';
    foreach (JOB_TYPES as $k => $label) {
        $html .= '<option value="' . h($k) . '"' . ($selected === $k ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    return $html;
}

/** Monat aus "YYYY-MM" (Fallback: aktueller Monat) als [Monat, erster Tag, letzter Tag]. */
function monthRange(?string $month): array {
    if (!is_string($month) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
        $month = date('Y-m');
    }
    $first = new DateTimeImmutable($month . '-01');
    return [$month, $first->format('Y-m-d'), $first->modify('last day of this month')->format('Y-m-d')];
}

function monthLabel(string $month): string {
    $names = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
    [$y, $m] = explode('-', $month);
    return $names[(int)$m - 1] . ' ' . $y;
}

function fmtQty(float $q): string {
    return rtrim(rtrim(number_format($q, 2, ',', '.'), '0'), ',');
}

/** Menge im deutschen oder englischen Format ("1,5" / "1.5"). */
function parseQty(string $s): ?float {
    $s = str_replace(' ', '', trim($s));
    if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $s)) $s = str_replace('.', '', $s);
    $s = str_replace(',', '.', $s);
    if (!is_numeric($s)) return null;
    $q = (float)$s;
    return $q > 0 && $q < 1e9 ? $q : null;
}
