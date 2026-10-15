<?php
declare(strict_types=1);

function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function fmtDuration(int $seconds): string {
    if ($seconds < 0) $seconds = 0;
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    return sprintf('%02d:%02d:%02d', $h, $m, $s);
}

function fmtDurationShort(int $seconds): string {
    if ($seconds < 0) $seconds = 0;
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    return sprintf('%dh %02dm', $h, $m);
}

function parseDateTimeLocal(string $value): ?string {
    $value = trim($value);
    if ($value === '') return null;
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $value);
    if (!$dt) $dt = DateTime::createFromFormat('Y-m-d\TH:i:s', $value);
    if (!$dt) $dt = DateTime::createFromFormat('Y-m-d H:i:s', $value);
    if (!$dt) $dt = DateTime::createFromFormat('Y-m-d H:i', $value);
    if (!$dt) return null;
    return $dt->format('Y-m-d H:i:s');
}

function toLocalInputValue(?string $datetime): string {
    if (!$datetime) return '';
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $datetime);
    if (!$dt) return '';
    return $dt->format('Y-m-d\TH:i');
}

function diffSeconds(string $start, ?string $end): int {
    $s = strtotime($start);
    $e = $end ? strtotime($end) : time();
    if ($s === false || $e === false) return 0;
    return max(0, $e - $s);
}

function jsonResponse($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function jsonError(string $msg, int $status = 400): void {
    jsonResponse(['ok' => false, 'error' => $msg], $status);
}

function postParam(string $key, $default = null) {
    return $_POST[$key] ?? $default;
}

function postInt(string $key, int $default = 0): int {
    return isset($_POST[$key]) ? (int)$_POST[$key] : $default;
}

function postStr(string $key, string $default = ''): string {
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function fmtSignedHM(int $minutes): string {
    $sign = $minutes < 0 ? '-' : '+';
    $abs = abs($minutes);
    return sprintf('%s%dh %02dm', $sign, intdiv($abs, 60), $abs % 60);
}

function workdaysBetween(string $startDate, string $endDate): int {
    try {
        $s = new DateTimeImmutable($startDate);
        $e = new DateTimeImmutable($endDate);
    } catch (Exception $ex) {
        return 0;
    }
    if ($s > $e) return 0;
    $count = 0;
    for ($cur = $s; $cur <= $e; $cur = $cur->modify('+1 day')) {
        $w = (int)$cur->format('w');
        if ($w >= 1 && $w <= 5) $count++;
    }
    return $count;
}

function isWeekday(string $date): bool {
    $w = (int)(new DateTimeImmutable($date))->format('w');
    return $w >= 1 && $w <= 5;
}
