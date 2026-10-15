<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';

$user = requireLogin();

$msg = null;
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $current = (string)postParam('current_password', '');
    $new = (string)postParam('new_password', '');
    $new2 = (string)postParam('new_password2', '');

    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$user['id']]);
    $hash = (string)$stmt->fetchColumn();

    if (!password_verify($current, $hash)) {
        $err = 'Aktuelles Passwort ist falsch.';
    } elseif (strlen($new) < 8) {
        $err = 'Neues Passwort muss mindestens 8 Zeichen haben.';
    } elseif ($new !== $new2) {
        $err = 'Neue Passwörter stimmen nicht überein.';
    } else {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
        session_regenerate_id(true);
        $msg = 'Passwort geändert.';
    }
}

renderHeader('Mein Konto', 'account');
?>
<?php if ($msg): ?><p class="flash success"><?= h($msg) ?></p><?php endif; ?>
<?php if ($err): ?><p class="flash error"><?= h($err) ?></p><?php endif; ?>

<section class="card">
    <h2>Mein Konto</h2>
    <p class="muted">Angemeldet als <strong><?= h($user['username']) ?></strong> · Rolle: <?= h(roleLabel($user['role'])) ?></p>
</section>

<section class="card">
    <h2>Passwort ändern</h2>
    <form method="post" class="footer-form">
        <?= csrfField() ?>
        <label>Aktuelles Passwort<input type="password" name="current_password" required autocomplete="current-password"></label>
        <label>Neues Passwort<input type="password" name="new_password" required minlength="8" autocomplete="new-password"></label>
        <label>Neues Passwort wiederholen<input type="password" name="new_password2" required minlength="8" autocomplete="new-password"></label>
        <button type="submit" class="primary">Passwort ändern</button>
    </form>
</section>
<?php
renderFooter();
