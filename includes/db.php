<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

const SCHEMA_VERSION = 3;

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $isNew = !file_exists(DB_PATH);
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        if ($isNew) {
            initSchema($pdo);
        }
        migrate($pdo);
    }
    return $pdo;
}

function initSchema(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            is_admin INTEGER NOT NULL DEFAULT 0,
            daily_target_minutes INTEGER NOT NULL DEFAULT 480,
            balance_start_date TEXT NOT NULL DEFAULT (DATE('now')),
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE work_sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            start_time TEXT NOT NULL,
            end_time TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE INDEX idx_work_user_start ON work_sessions(user_id, start_time);
        CREATE TABLE job_entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            job_name TEXT NOT NULL,
            start_time TEXT NOT NULL,
            end_time TEXT,
            note TEXT NOT NULL DEFAULT '',
            is_recorded INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE INDEX idx_job_user_start ON job_entries(user_id, start_time);
        CREATE TABLE audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            actor_id INTEGER NOT NULL,
            target_user_id INTEGER NOT NULL,
            entity_type TEXT NOT NULL,
            entity_id INTEGER NOT NULL,
            action TEXT NOT NULL,
            before_json TEXT,
            after_json TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE INDEX idx_audit_target ON audit_log(target_user_id, created_at);
        CREATE INDEX idx_audit_entity ON audit_log(entity_type, entity_id);
    ");
    createV3Tables($pdo);
    $pdo->exec('PRAGMA user_version = ' . SCHEMA_VERSION);
}

function migrate(PDO $pdo): void {
    $version = (int)$pdo->query('PRAGMA user_version')->fetchColumn();

    if ($version < 2) {
        $cols = $pdo->query("PRAGMA table_info(users)")->fetchAll();
        $userCols = array_column($cols, 'name');
        if (!in_array('daily_target_minutes', $userCols, true)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN daily_target_minutes INTEGER NOT NULL DEFAULT 480');
        }
        if (!in_array('balance_start_date', $userCols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN balance_start_date TEXT NOT NULL DEFAULT '2025-01-01'");
        }
        $jobCols = array_column($pdo->query("PRAGMA table_info(job_entries)")->fetchAll(), 'name');
        if (!in_array('is_recorded', $jobCols, true)) {
            $pdo->exec('ALTER TABLE job_entries ADD COLUMN is_recorded INTEGER NOT NULL DEFAULT 0');
        }
        $hasAudit = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='audit_log'")->fetch();
        if (!$hasAudit) {
            $pdo->exec("
                CREATE TABLE audit_log (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    actor_id INTEGER NOT NULL,
                    target_user_id INTEGER NOT NULL,
                    entity_type TEXT NOT NULL,
                    entity_id INTEGER NOT NULL,
                    action TEXT NOT NULL,
                    before_json TEXT,
                    after_json TEXT,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                );
                CREATE INDEX idx_audit_target ON audit_log(target_user_id, created_at);
                CREATE INDEX idx_audit_entity ON audit_log(entity_type, entity_id);
            ");
        }
        $pdo->exec('PRAGMA user_version = 2');
    }

    if ($version < 3) {
        createV3Tables($pdo);
        $pdo->exec('PRAGMA user_version = 3');
    }
}

function createV3Tables(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS absences (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            type TEXT NOT NULL,
            start_date TEXT NOT NULL,
            end_date TEXT NOT NULL,
            note TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE INDEX IF NOT EXISTS idx_absence_dates ON absences(start_date, end_date);
        CREATE INDEX IF NOT EXISTS idx_absence_user ON absences(user_id, start_date);
        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );
    ");
}

function setting(string $key, string $default = ''): string {
    $stmt = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $stmt->execute([$key]);
    $v = $stmt->fetchColumn();
    return $v === false ? $default : (string)$v;
}

function setSetting(string $key, string $value): void {
    db()->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
        ->execute([$key, $value]);
}
