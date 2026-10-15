<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

if (currentUser()) {
    header('Location: /dashboard.php');
    exit;
}

if (userCount() === 0) {
    header('Location: /setup.php');
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $u = postStr('username');
    $p = (string)postParam('password', '');
    if ($u === '' || $p === '') {
        $error = 'Bitte Benutzername und Passwort eingeben.';
    } elseif (login($u, $p)) {
        header('Location: /dashboard.php');
        exit;
    } else {
        $error = 'Login fehlgeschlagen.';
    }
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login – <?= h(APP_NAME) ?></title>
    <link rel="icon" href="/favicon/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="/favicon/apple-touch-icon.png">
    <script>(function(){try{var t=localStorage.getItem('theme');if(t==='dark'||t==='light')document.documentElement.dataset.theme=t;}catch(e){}})();</script>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body class="auth-body">
    <main class="auth-card">
        <h1><?= h(APP_NAME) ?></h1>
        <?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
        <form method="post" autocomplete="on">
            <?= csrfField() ?>
            <label>Benutzername<input type="text" name="username" required autofocus></label>
            <label>Passwort<input type="password" name="password" required></label>
            <button type="submit">Anmelden</button>
        </form>
    </main>
</body>
</html>
