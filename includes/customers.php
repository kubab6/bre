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

/**
 * Kunde zu einem eingetippten Namen: vorhandener Kunde (Groß-/Kleinschreibung
 * egal) oder neu angelegt. Leerer Name = kein Kunde. False bei zu langem Namen.
 * @return int|null|false
 */
function resolveCustomerName(string $name) {
    $name = trim(preg_replace('/\s+/u', ' ', $name));
    if ($name === '') return null;
    if (mb_strlen($name) > 200) return false;
    // Auswahl aus der Vorschlagsliste kann die Kundennummer enthalten: "Name (K-1)".
    foreach (customerList(true) as $c) {
        $label = $c['name'] . ($c['customer_no'] !== '' ? ' (' . $c['customer_no'] . ')' : '');
        if (mb_strtolower($c['name']) === mb_strtolower($name) || mb_strtolower($label) === mb_strtolower($name)) {
            return (int)$c['id'];
        }
    }
    db()->prepare('INSERT INTO customers (name) VALUES (?)')->execute([$name]);
    return (int)db()->lastInsertId();
}

function customerNameById(?int $id): string {
    if ($id === null) return '';
    $c = customerById($id);
    return $c ? $c['name'] : '';
}

/** <datalist> mit allen aktiven Kunden für Eingabefelder mit Vorschlägen. */
function customerDatalist(string $id = 'customer-list'): string {
    $html = '<datalist id="' . h($id) . '">';
    foreach (customerList() as $c) {
        $html .= '<option value="' . h($c['name']) . '">' . ($c['customer_no'] !== '' ? h($c['customer_no']) : '') . '</option>';
    }
    return $html . '</datalist>';
}

/**
 * Monate für eine Auswahlliste, neuester zuerst: vom ältesten Eintrag
 * (max. 5 Jahre zurück) bis zum aktuellen Monat.
 */
function monthChoices(): array {
    $oldest = db()->query(
        "SELECT MIN(m) FROM (
            SELECT MIN(substr(start_time, 1, 7)) AS m FROM job_entries
            UNION ALL SELECT MIN(substr(used_on, 1, 7)) FROM materials
            UNION ALL SELECT MIN(substr(start_time, 1, 7)) FROM work_sessions
        )"
    )->fetchColumn();
    $current = new DateTimeImmutable(date('Y-m-01'));
    $limit = $current->modify('-60 months');
    $start = $oldest ? new DateTimeImmutable($oldest . '-01') : $current;
    if ($start < $limit) $start = $limit;
    $months = [];
    for ($m = $current; $m >= $start; $m = $m->modify('-1 month')) {
        $months[] = $m->format('Y-m');
    }
    return $months;
}

function monthOptions(string $selected, array $extra = []): string {
    $html = '';
    foreach ($extra as $value => $label) {
        $html .= '<option value="' . h((string)$value) . '"' . ((string)$value === $selected ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    $months = monthChoices();
    if ($selected !== '' && !isset($extra[$selected]) && !in_array($selected, $months, true)) $months[] = $selected;
    foreach ($months as $m) {
        $html .= '<option value="' . h($m) . '"' . ($m === $selected ? ' selected' : '') . '>' . h(monthLabel($m)) . '</option>';
    }
    return $html;
}

function fmtMoney(?float $v): string {
    return $v === null ? '' : number_format($v, 2, ',', '.') . ' €';
}

/** Preis "12,50" / "12.5" / "" → float oder null (leer). False wenn ungültig. */
function parsePrice(string $s) {
    $s = trim(str_replace(['€', ' '], '', $s));
    if ($s === '') return null;
    if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $s)) $s = str_replace('.', '', $s);
    $s = str_replace(',', '.', $s);
    if (!is_numeric($s) || (float)$s < 0 || (float)$s >= 1e9) return false;
    return round((float)$s, 4);
}
