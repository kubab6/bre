<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

if (userCount() > 0) {
    header('Location: /index.php');
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $u = postStr('username');
    $p = (string)postParam('password', '');
    $p2 = (string)postParam('password2', '');
    if ($u === '' || $p === '') {
        $error = 'Benutzername und Passwort erforderlich.';
    } elseif ($p !== $p2) {
        $error = 'Passwörter stimmen nicht überein.';
    } elseif (strlen($p) < 8) {
        $error = 'Passwort muss mindestens 8 Zeichen haben.';
    } else {
        $stmt = db()->prepare('INSERT INTO users (username, password_hash, is_admin) VALUES (?, ?, 1)');
        $stmt->execute([$u, password_hash($p, PASSWORD_DEFAULT)]);
        login($u, $p);
        header('Location: /dashboard.php');
        exit;
    }
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Einrichtung – <?= h(APP_NAME) ?></title>
    <link rel="icon" href="/favicon/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="/favicon/apple-touch-icon.png">
    <script>(function(){try{var t=localStorage.getItem('theme');if(t==='dark'||t==='light')document.documentElement.dataset.theme=t;}catch(e){}})();</script>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body class="auth-body">
    <main class="auth-card">
        <h1>Erst-Einrichtung</h1>
        <p class="hint">Lege den ersten Admin-Account an.</p>
        <?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
        <form method="post">
            <?= csrfField() ?>
            <label>Benutzername<input type="text" name="username" required autofocus></label>
            <label>Passwort<input type="password" name="password" required minlength="8"></label>
            <label>Passwort wiederholen<input type="password" name="password2" required minlength="8"></label>
            <button type="submit">Admin anlegen</button>
        </form>
    </main>
</body>
</html>
