<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/customers.php';
require_once __DIR__ . '/../includes/pdf.php';

requireManager();

$customer = customerById((int)($_GET['id'] ?? 0));
if (!$customer) {
    http_response_code(404);
    exit('Kunde nicht gefunden');
}
[$month, $from, $to] = monthRange($_GET['month'] ?? null);
// Variante: "customer" für den Kunden (nur Aufträge), "internal" zusätzlich mit Material und Einkaufspreisen.
$internal = ($_GET['variant'] ?? '') === 'internal';
$cid = (int)$customer['id'];

$jobs = db()->prepare(
    'SELECT e.*, COALESCE(NULLIF(u.name, \'\'), u.username) AS user_display FROM job_entries e JOIN users u ON u.id = e.user_id
     WHERE e.customer_id = ? AND e.start_time >= ? AND e.start_time <= ? AND e.end_time IS NOT NULL'
    . '
     ORDER BY e.start_time'
);
$jobs->execute([$cid, $from . ' 00:00:00', $to . ' 23:59:59']);
$jobs = $jobs->fetchAll();

$materials = [];
if ($internal) {
    $materials = db()->prepare(
        'SELECT * FROM materials WHERE customer_id = ? AND used_on >= ? AND used_on <= ?'
        . ' ORDER BY used_on, id'
    );
    $materials->execute([$cid, $from, $to]);
    $materials = $materials->fetchAll();
}

// ------------------------------------------------------------------ Layout
$pdf = new SimplePdf();
$left = 15.0;
$right = SimplePdf::W - 15.0;
$bottom = SimplePdf::H - 20.0;
$lh = 4.4; // Zeilenhöhe Tabelle
$y = 0.0;

$newPage = function () use ($pdf, &$y): void {
    $pdf->addPage();
    $y = 20.0;
};

/** Spaltenkopf zeichnen. $cols: [Titel, x, Breite, Ausrichtung] */
$drawHead = function (array $cols) use ($pdf, &$y, $left, $right): void {
    $pdf->setFillGray(0.92);
    $pdf->fillRect($left, $y - 4.2, $right - $left, 6.2);
    $pdf->setFillGray(0);
    $pdf->setFont('B', 8.5);
    foreach ($cols as [$title, $x, $w, $align]) {
        $pdf->text($align === 'R' ? $x + $w : $x, $y, $title, $align);
    }
    $y += 6.0;
};

/** Platz prüfen; bei Seitenumbruch Kopf wiederholen. */
$ensure = function (float $needed, ?array $cols = null) use (&$y, $bottom, $newPage, $drawHead): void {
    if ($y + $needed <= $bottom) return;
    $newPage();
    if ($cols) $drawHead($cols);
};

/** Tabellenzeile mit Umbruch je Zelle. */
$drawRow = function (array $cols, array $cells) use ($pdf, &$y, $lh, $left, $right, $ensure): void {
    $pdf->setFont('R', 8.5);
    $wrapped = [];
    $lines = 1;
    foreach ($cols as $i => [, , $w]) {
        $wrapped[$i] = $pdf->wrap((string)($cells[$i] ?? ''), $w - 1.5);
        $lines = max($lines, count($wrapped[$i]));
    }
    $ensure($lines * $lh + 1.5, $cols);
    $pdf->setFont('R', 8.5);
    foreach ($cols as $i => [, $x, $w, $align]) {
        foreach ($wrapped[$i] as $n => $line) {
            $pdf->text($align === 'R' ? $x + $w : $x, $y + $n * $lh, $line, $align);
        }
    }
    $y += ($lines - 1) * $lh + 2.2;
    $pdf->setStrokeGray(0.8);
    $pdf->line($left, $y, $right, $y, 0.15);
    $pdf->setStrokeGray(0);
    $y += $lh;
};

$newPage();

// Kopf
$pdf->setFont('B', 16);
$pdf->text($left, $y, $internal ? 'Leistungsnachweis (intern)' : 'Leistungsnachweis');
$pdf->setFont('R', 10);
$pdf->text($right, $y, monthLabel($month), 'R');
$y += 12;

// Kundenblock
$pdf->setFont('B', 11);
$pdf->text($left, $y, $customer['name']);
$infoY = $y;
$y += 5;
$pdf->setFont('R', 9.5);
foreach (preg_split('/\R/', $customer['address']) as $line) {
    if (trim($line) === '') continue;
    $pdf->text($left, $y, trim($line));
    $y += 4.5;
}
if ($customer['contact'] !== '') {
    foreach ($pdf->wrap($customer['contact'], 110) as $line) {
        $pdf->text($left, $y, $line);
        $y += 4.5;
    }
}
$pdf->setFont('R', 9);
$meta = [
    'Zeitraum' => date('d.m.Y', strtotime($from)) . ' – ' . date('d.m.Y', strtotime($to)),
    'Erstellt am' => date('d.m.Y'),
];
if ($customer['customer_no'] !== '') $meta = ['Kundennr.' => $customer['customer_no']] + $meta;
if ($internal) $meta['Variante'] = 'INTERN – nicht für den Kunden';
foreach ($meta as $k => $v) {
    $pdf->setFont('B', 9);
    $pdf->text(130, $infoY, $k);
    $pdf->setFont('R', 9);
    $pdf->text($right, $infoY, $v, 'R');
    $infoY += 4.8;
}
$y = max($y, $infoY) + 8;

// Aufträge
$jobCols = [
    ['Datum', $left, 18, 'L'],
    ['Mitarbeiter', $left + 18, 24, 'L'],
    ['Art', $left + 42, 21, 'L'],
    ['Auftrag / Notiz', $left + 63, 79, 'L'],
    ['Zeit', $left + 142, 22, 'L'],
    ['Dauer', $left + 164, 16, 'R'],
];
$pdf->setFont('B', 11);
$ensure(20);
$pdf->text($left, $y, 'Aufträge');
$y += 6;
$total = 0;
$byType = [];
if (!$jobs) {
    $pdf->setFont('R', 9);
    $pdf->text($left, $y, 'Keine Aufträge in diesem Zeitraum.');
    $y += 8;
} else {
    $drawHead($jobCols);
    foreach ($jobs as $j) {
        $secs = diffSeconds($j['start_time'], $j['end_time']);
        $total += $secs;
        $type = jobTypeLabel($j['job_type']) ?: 'Ohne Art';
        $byType[$type] = ($byType[$type] ?? 0) + $secs;
        // Anfahrt bei Vor-Ort; ältere Einträge ohne Angabe: Adresse des Kunden.
        $travel = $j['job_type'] === 'onsite' ? ($j['travel'] !== '' ? $j['travel'] : addressLine($customer['address'])) : '';
        $desc = $j['job_name'] . ($j['note'] !== '' ? "\n" . $j['note'] : '')
            . ($travel !== '' ? "\nAnfahrt: " . $travel : '');
        $drawRow($jobCols, [
            date('d.m.Y', strtotime($j['start_time'])),
            $j['user_display'],
            jobTypeLabel($j['job_type']),
            $desc,
            substr($j['start_time'], 11, 5) . '–' . substr($j['end_time'], 11, 5),
            fmtHours($secs),
        ]);
    }
    // Summen
    $ensure(8 + count($byType) * 5);
    $y += 1;
    $pdf->setFont('R', 9);
    if (count($byType) > 1) {
        foreach ($byType as $type => $secs) {
            $pdf->text($left + 142, $y, $type);
            $pdf->text($right, $y, fmtHours($secs), 'R');
            $y += 4.8;
        }
    }
    $pdf->setFont('B', 9.5);
    $pdf->text($left + 142, $y, 'Gesamt');
    $pdf->text($right, $y, fmtHours($total), 'R');
    $y += 10;
}

// Material mit Einkaufspreisen (nur im internen PDF)
if ($internal) {
    $matCols = [
        ['Datum', $left, 18, 'L'],
        ['Material', $left + 18, 82, 'L'],
        ['Menge', $left + 100, 16, 'R'],
        ['Einheit', $left + 118, 16, 'L'],
        ['EK/Einheit', $left + 134, 22, 'R'],
        ['EK gesamt', $left + 158, 22, 'R'],
    ];
    $ensure(20);
    $pdf->setFont('B', 11);
    $pdf->text($left, $y, 'Material');
    $y += 6;
    if (!$materials) {
        $pdf->setFont('R', 9);
        $pdf->text($left, $y, 'Kein Material in diesem Zeitraum.');
        $y += 8;
    } else {
        $drawHead($matCols);
        $ekTotal = 0.0;
        $ekMissing = false;
        foreach ($materials as $m) {
            $cells = [
                date('d.m.Y', strtotime($m['used_on'])),
                $m['description'] . ($m['note'] !== '' ? "\n" . $m['note'] : ''),
                fmtQty((float)$m['quantity']),
                $m['unit'],
            ];
            if ($m['purchase_price'] === null) {
                $ekMissing = true;
                array_push($cells, '–', '–');
            } else {
                $line = (float)$m['purchase_price'] * (float)$m['quantity'];
                $ekTotal += $line;
                array_push($cells, fmtMoney((float)$m['purchase_price']), fmtMoney($line));
            }
            $drawRow($matCols, $cells);
        }
        $ensure(10);
        $y += 1;
        $pdf->setFont('B', 9.5);
        $pdf->text($left + 118, $y, 'EK gesamt');
        $pdf->text($right, $y, fmtMoney($ekTotal), 'R');
        if ($ekMissing) {
            $y += 5;
            $pdf->setFont('R', 8);
            $pdf->text($right, $y, 'ohne Posten ohne EK-Angabe', 'R');
        }
        $y += 8;
    }
}

// Fußzeile mit Seitenzahlen
$pages = $pdf->pageCount();
for ($i = 0; $i < $pages; $i++) {
    $pdf->selectPage($i);
    $pdf->setStrokeGray(0.6);
    $pdf->line($left, SimplePdf::H - 13, $right, SimplePdf::H - 13, 0.2);
    $pdf->setFont('R', 7.5);
    $pdf->text($left, SimplePdf::H - 9, $customer['name'] . ' · ' . monthLabel($month) . ($internal ? ' · INTERN' : ''));
    $pdf->text($right, SimplePdf::H - 9, 'Seite ' . ($i + 1) . ' von ' . $pages, 'R');
}

/** Dauer als Stunden:Minuten, z.B. "1:30 h". */
function fmtHours(int $secs): string {
    $min = (int)round($secs / 60);
    return sprintf('%d:%02d h', intdiv($min, 60), $min % 60);
}

$slug = trim(preg_replace('/[^A-Za-z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT', strtr($customer['name'], ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss'])) ?: 'Kunde'), '_');
$filename = 'Leistungsnachweis_' . ($slug ?: 'Kunde') . '_' . $month . ($internal ? '_intern' : '') . '.pdf';
$data = $pdf->output('Leistungsnachweis ' . $customer['name'] . ' ' . monthLabel($month));
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Content-Length: ' . strlen($data));
echo $data;
