<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Berlin');

const APP_NAME = 'Zeiterfassung';
const DB_PATH = __DIR__ . '/../data/app.sqlite';
const SESSION_PATH = __DIR__ . '/../data/sessions';
// Wie lange man ohne Aktivität angemeldet bleibt (Sekunden). 30 Tage.
const SESSION_LIFETIME = 30 * 24 * 3600;

if (session_status() === PHP_SESSION_NONE) {
    // Eigener Session-Ordner: Der System-Ordner wird oft per Cron nach der
    // php.ini-Laufzeit (Standard 24 Min) aufgeräumt, unabhängig von dieser App.
    if (!is_dir(SESSION_PATH)) {
        @mkdir(SESSION_PATH, 0700, true);
    }
    if (is_dir(SESSION_PATH) && is_writable(SESSION_PATH)) {
        session_save_path(SESSION_PATH);
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
    }
    ini_set('session.gc_maxlifetime', (string)SESSION_LIFETIME);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    // Ablauf bei jeder Anfrage nach hinten schieben (gleitendes Timeout).
    if (!empty($_SESSION['uid'])) {
        $p = session_get_cookie_params();
        setcookie(session_name(), session_id(), [
            'expires' => time() + SESSION_LIFETIME,
            'path' => $p['path'],
            'domain' => $p['domain'],
            'secure' => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'],
        ]);
    }
}
