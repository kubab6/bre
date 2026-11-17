<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/customers.php';
require_once __DIR__ . '/../includes/pdf.php';

requireManager();

// id=all: alle Aufträge des Monats über alle Kunden, immer als interne Variante.
$all = ($_GET['id'] ?? '') === 'all';
$customer = null;
if (!$all) {
    $customer = customerById((int)($_GET['id'] ?? 0));
    if (!$customer) {
        http_response_code(404);
        exit('Kunde nicht gefunden');
    }
}
[$month, $from, $to] = monthRange($_GET['month'] ?? null);
// Variante: "customer" für den Kunden (nur Aufträge), "internal" zusätzlich mit Material und Einkaufspreisen.
$internal = $all || ($_GET['variant'] ?? '') === 'internal';

$sql = 'SELECT e.*, COALESCE(NULLIF(u.name, \'\'), u.username) AS user_display,
               c.name AS customer_name, c.address AS customer_address
        FROM job_entries e JOIN users u ON u.id = e.user_id LEFT JOIN customers c ON c.id = e.customer_id
        WHERE e.start_time >= ? AND e.start_time <= ? AND e.end_time IS NOT NULL';
$params = [$from . ' 00:00:00', $to . ' 23:59:59'];
if (!$all) {
    $sql .= ' AND e.customer_id = ?';
    $params[] = (int)$customer['id'];
}
$jobs = db()->prepare($sql . ' ORDER BY e.start_time');
$jobs->execute($params);
$jobs = $jobs->fetchAll();

$materials = [];
if ($internal) {
    $sql = 'SELECT m.*, c.name AS customer_name FROM materials m LEFT JOIN customers c ON c.id = m.customer_id
            WHERE m.used_on >= ? AND m.used_on <= ?';
    $params = [$from, $to];
    if (!$all) {
        $sql .= ' AND m.customer_id = ?';
        $params[] = (int)$customer['id'];
    }
    $materials = db()->prepare($sql . ' ORDER BY m.used_on, m.id');
    $materials->execute($params);
    $materials = $materials->fetchAll();
}

$r = new PdfReport();
$pdf = $r->pdf;
$left = $r->left;
$right = $r->right;

// Kopf
$pdf->setFont('B', 16);
$pdf->text($left, $r->y, $all ? 'Auftragsübersicht (intern)' : ($internal ? 'Leistungsnachweis (intern)' : 'Leistungsnachweis'));
$pdf->setFont('R', 10);
$pdf->text($right, $r->y, monthLabel($month), 'R');
$r->y += 12;

// Kundenblock bzw. Übersicht
$infoY = $r->y;
$pdf->setFont('B', 11);
if ($all) {
    $pdf->text($left, $r->y, 'Alle Kunden');
    $r->y += 5;
    $pdf->setFont('R', 9.5);
    $nCustomers = count(array_filter(array_unique(array_map(fn($j) => (int)$j['customer_id'], $jobs))));
    $pdf->text($left, $r->y, count($jobs) . (count($jobs) === 1 ? ' Auftrag' : ' Aufträge') . ' bei ' . $nCustomers . ($nCustomers === 1 ? ' Kunde' : ' Kunden'));
    $r->y += 4.5;
} else {
    $pdf->text($left, $r->y, $customer['name']);
    $r->y += 5;
    $pdf->setFont('R', 9.5);
    foreach (preg_split('/\R/', $customer['address']) as $line) {
        if (trim($line) === '') continue;
        $pdf->text($left, $r->y, trim($line));
        $r->y += 4.5;
    }
    if ($customer['contact'] !== '') {
        foreach ($pdf->wrap($customer['contact'], 110) as $line) {
            $pdf->text($left, $r->y, $line);
            $r->y += 4.5;
        }
    }
}
$meta = [
    'Zeitraum' => date('d.m.Y', strtotime($from)) . ' – ' . date('d.m.Y', strtotime($to)),
    'Erstellt am' => date('d.m.Y'),
];
if (!$all && $customer['customer_no'] !== '') $meta = ['Kundennr.' => $customer['customer_no']] + $meta;
if ($internal) $meta['Variante'] = 'INTERN – nicht für den Kunden';
$r->y = max($r->y, $r->meta($meta, $infoY)) + 8;

// Aufträge
if ($all) {
    $jobCols = [
        ['Datum', $left, 18, 'L'],
        ['Kunde', $left + 18, 30, 'L'],
        ['Mitarbeiter', $left + 48, 22, 'L'],
        ['Art', $left + 70, 21, 'L'],
        ['Auftrag / Notiz', $left + 91, 55, 'L'],
        ['Zeit', $left + 146, 20, 'L'],
        ['Dauer', $left + 166, 14, 'R'],
    ];
} else {
    $jobCols = [
        ['Datum', $left, 18, 'L'],
        ['Mitarbeiter', $left + 18, 24, 'L'],
        ['Art', $left + 42, 21, 'L'],
        ['Auftrag / Notiz', $left + 63, 79, 'L'],
        ['Zeit', $left + 142, 22, 'L'],
        ['Dauer', $left + 164, 16, 'R'],
    ];
}
$r->section('Aufträge');
$total = 0;
$byType = [];
$byCustomer = []; // Name => [Sekunden, EK]
if (!$jobs) {
    $r->note('Keine Aufträge in diesem Zeitraum.');
} else {
    $r->head($jobCols);
    foreach ($jobs as $j) {
        $secs = diffSeconds($j['start_time'], $j['end_time']);
        $total += $secs;
        $type = jobTypeLabel($j['job_type']) ?: 'Ohne Art';
        $byType[$type] = ($byType[$type] ?? 0) + $secs;
        $cname = $j['customer_name'] ?? 'Ohne Kunde';
        $byCustomer[$cname][0] = ($byCustomer[$cname][0] ?? 0) + $secs;
        // Anfahrt bei Vor-Ort; ältere Einträge ohne Angabe: Adresse des Kunden.
        $travel = $j['job_type'] === 'onsite' ? ($j['travel'] !== '' ? $j['travel'] : addressLine((string)($j['customer_address'] ?? ''))) : '';
        $desc = $j['job_name'] . ($j['note'] !== '' ? "\n" . $j['note'] : '')
            . ($travel !== '' ? "\nAnfahrt: " . $travel : '');
        $cells = [date('d.m.Y', strtotime($j['start_time']))];
        if ($all) $cells[] = $j['customer_name'] ?? '–';
        array_push($cells,
            $j['user_display'],
            jobTypeLabel($j['job_type']),
            $desc,
            substr($j['start_time'], 11, 5) . '–' . substr($j['end_time'], 11, 5),
            fmtHours($secs)
        );
        $r->row($jobCols, $cells);
    }
    // Summen
    $sumX = $all ? $left + 146 : $left + 142;
    $r->ensure(8 + count($byType) * 5);
    $r->y += 1;
    $pdf->setFont('R', 9);
    if (count($byType) > 1) {
        foreach ($byType as $type => $secs) {
            $pdf->text($sumX, $r->y, $type);
            $pdf->text($right, $r->y, fmtHours($secs), 'R');
            $r->y += 4.8;
        }
    }
    $pdf->setFont('B', 9.5);
    $pdf->text($sumX, $r->y, 'Gesamt');
    $pdf->text($right, $r->y, fmtHours($total), 'R');
    $r->y += 10;
}

// Material mit Einkaufspreisen (nur im internen PDF)
$ekTotal = 0.0;
if ($internal) {
    if ($all) {
        $matCols = [
            ['Datum', $left, 18, 'L'],
            ['Kunde', $left + 18, 30, 'L'],
            ['Material', $left + 48, 54, 'L'],
            ['Menge', $left + 102, 14, 'R'],
            ['Einheit', $left + 118, 14, 'L'],
            ['EK/Einheit', $left + 132, 22, 'R'],
            ['EK gesamt', $left + 156, 24, 'R'],
        ];
    } else {
        $matCols = [
            ['Datum', $left, 18, 'L'],
            ['Material', $left + 18, 82, 'L'],
            ['Menge', $left + 100, 16, 'R'],
            ['Einheit', $left + 118, 16, 'L'],
            ['EK/Einheit', $left + 134, 22, 'R'],
            ['EK gesamt', $left + 158, 22, 'R'],
        ];
    }
    $r->section('Material');
    if (!$materials) {
        $r->note('Kein Material in diesem Zeitraum.');
    } else {
        $r->head($matCols);
        $ekMissing = false;
        foreach ($materials as $m) {
            $cells = [date('d.m.Y', strtotime($m['used_on']))];
            if ($all) $cells[] = $m['customer_name'] ?? '–';
            array_push($cells,
                $m['description'] . ($m['note'] !== '' ? "\n" . $m['note'] : ''),
                fmtQty((float)$m['quantity']),
                $m['unit']
            );
            if ($m['purchase_price'] === null) {
                $ekMissing = true;
                array_push($cells, '–', '–');
            } else {
                $line = (float)$m['purchase_price'] * (float)$m['quantity'];
                $ekTotal += $line;
                $cname = $m['customer_name'] ?? 'Ohne Kunde';
                $byCustomer[$cname][1] = ($byCustomer[$cname][1] ?? 0) + $line;
                array_push($cells, fmtMoney((float)$m['purchase_price']), fmtMoney($line));
            }
            $r->row($matCols, $cells);
        }
        $r->ensure(10);
        $r->y += 1;
        $pdf->setFont('B', 9.5);
        $pdf->text($left + 118, $r->y, 'EK gesamt');
        $pdf->text($right, $r->y, fmtMoney($ekTotal), 'R');
        if ($ekMissing) {
            $r->y += 5;
            $pdf->setFont('R', 8);
            $pdf->text($right, $r->y, 'ohne Posten ohne EK-Angabe', 'R');
        }
        $r->y += 8;
    }
}

// Übersicht je Kunde (nur bei "Alle")
if ($all && $byCustomer) {
    ksort($byCustomer, SORT_NATURAL | SORT_FLAG_CASE);
    $sumCols = [
        ['Kunde', $left, 110, 'L'],
        ['Zeit', $left + 110, 30, 'R'],
        ['EK Material', $left + 140, 40, 'R'],
    ];
    $r->section('Übersicht je Kunde');
    $r->head($sumCols);
    foreach ($byCustomer as $name => $v) {
        $r->row($sumCols, [$name, isset($v[0]) ? fmtHours($v[0]) : '–', isset($v[1]) ? fmtMoney($v[1]) : '–']);
    }
    $r->row($sumCols, ['Gesamt', fmtHours($total), fmtMoney($ekTotal)], true, true);
}

$name = $all ? 'Alle Kunden' : $customer['name'];
$filename = ($all ? 'Auftragsuebersicht_Alle' : 'Leistungsnachweis_' . fileSlug($customer['name'], 'Kunde'))
    . '_' . $month . ($internal ? '_intern' : '') . '.pdf';
$r->send(
    $name . ' · ' . monthLabel($month) . ($internal ? ' · INTERN' : ''),
    ($all ? 'Auftragsübersicht ' : 'Leistungsnachweis ') . $name . ' ' . monthLabel($month),
    $filename
);
