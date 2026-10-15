<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

const ROLES = [
    'user' => 'Benutzer',
    'manager' => 'Manager',
    'admin' => 'Admin',
];

function roleLabel(string $role): string {
    return ROLES[$role] ?? $role;
}

function isAdmin(?array $u): bool {
    return ($u['role'] ?? '') === 'admin';
}

/** Manager und Admins: Zugriff auf die Zeiten aller Benutzer und die Benutzerverwaltung. */
function isManager(?array $u): bool {
    return in_array($u['role'] ?? '', ['manager', 'admin'], true);
}

/** Darf $me den Benutzer $target in der Verwaltung bearbeiten? Manager keine Admins. */
function canManageUser(array $me, array $target): bool {
    if (isAdmin($me)) return true;
    return isManager($me) && ($target['role'] ?? '') !== 'admin';
}

function currentUser(): ?array {
    if (empty($_SESSION['uid'])) return null;
    static $user = null;
    if ($user === null || $user['id'] !== $_SESSION['uid']) {
        $stmt = db()->prepare('SELECT id, username, role FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['uid']]);
        $user = $stmt->fetch() ?: null;
        if ($user === null) {
            session_destroy();
        }
    }
    return $user;
}

function requireLogin(): array {
    $u = currentUser();
    if (!$u) {
        header('Location: /index.php');
        exit;
    }
    return $u;
}

function requireManager(): array {
    $u = requireLogin();
    if (!isManager($u)) {
        http_response_code(403);
        exit('Zugriff verweigert');
    }
    return $u;
}

function requireAdmin(): array {
    $u = requireLogin();
    if (!isAdmin($u)) {
        http_response_code(403);
        exit('Zugriff verweigert');
    }
    return $u;
}

/**
 * Benutzer, dessen Daten eine Seite anzeigt: Manager können per ?user=ID
 * jeden Benutzer wählen, alle anderen sehen nur sich selbst.
 */
function viewedUser(array $me): array {
    $id = (int)($_GET['user'] ?? 0);
    if ($id > 0 && $id !== (int)$me['id'] && isManager($me)) {
        $stmt = db()->prepare('SELECT id, username, role FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $u = $stmt->fetch();
        if ($u) return $u;
    }
    return $me;
}

/**
 * Ziel-Benutzer für API-Aufrufe: Manager dürfen per user_id für andere
 * handeln, sonst immer der angemeldete Benutzer. Null, wenn unbekannt.
 */
function targetUserId(array $me): ?int {
    $id = (int)($_POST['user_id'] ?? 0);
    if ($id <= 0 || $id === (int)$me['id'] || !isManager($me)) return (int)$me['id'];
    $stmt = db()->prepare('SELECT id FROM users WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ? $id : null;
}

function login(string $username, string $password): bool {
    $stmt = db()->prepare('SELECT id, password_hash FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($password, $row['password_hash'])) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$row['id'];
    return true;
}

function logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function userCount(): int {
    return (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
}
