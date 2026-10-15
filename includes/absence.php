<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

const ABSENCE_TYPES = [
    'vacation' => 'Urlaub',
    'sick' => 'Krank',
];

function absenceLabel(string $type): string {
    return ABSENCE_TYPES[$type] ?? $type;
}

/**
 * Abwesenheits-Werktage (Mo–Fr) eines Benutzers im Zeitraum, als [Y-m-d => type].
 */
function absenceWeekdays(int $userId, string $from, string $to): array {
    $stmt = db()->prepare(
        'SELECT type, start_date, end_date FROM absences
         WHERE user_id = ? AND end_date >= ? AND start_date <= ?'
    );
    $stmt->execute([$userId, $from, $to]);
    $days = [];
    while ($r = $stmt->fetch()) {
        $s = max($r['start_date'], $from);
        $e = min($r['end_date'], $to);
        for ($cur = new DateTimeImmutable($s); $cur->format('Y-m-d') <= $e; $cur = $cur->modify('+1 day')) {
            $d = $cur->format('Y-m-d');
            if (isWeekday($d)) $days[$d] = $r['type'];
        }
    }
    return $days;
}
