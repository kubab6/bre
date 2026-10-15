<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function currentUser(): ?array {
    if (empty($_SESSION['uid'])) return null;
    static $user = null;
    if ($user === null || $user['id'] !== $_SESSION['uid']) {
        $stmt = db()->prepare('SELECT id, username, is_admin FROM users WHERE id = ?');
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

function requireAdmin(): array {
    $u = requireLogin();
    if (empty($u['is_admin'])) {
        http_response_code(403);
        exit('Zugriff verweigert');
    }
    return $u;
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
