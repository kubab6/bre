<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Berlin');

const APP_NAME = 'Zeiterfassung';
const DB_PATH = __DIR__ . '/../data/app.sqlite';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
