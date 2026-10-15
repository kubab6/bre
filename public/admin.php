<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';

$me = requireAdmin();

$msg = null;
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $action = postStr('action');

    if ($action === 'create_user') {
        $u = postStr('username');
        $p = (string)postParam('password', '');
        $isAdmin = postInt('is_admin') === 1 ? 1 : 0;
        $targetMin = max(0, postInt('daily_target_minutes', 480));
        $startDate = postStr('balance_start_date');
        if ($startDate === '' || !DateTime::createFromFormat('Y-m-d', $startDate)) {
            $startDate = date('Y-m-d');
        }
        if ($u === '' || strlen($p) < 8) {
            $err = 'Benutzername erforderlich; Passwort min. 8 Zeichen.';
        } else {
            try {
                $stmt = db()->prepare('INSERT INTO users (username, password_hash, is_admin, daily_target_minutes, balance_start_date) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$u, password_hash($p, PASSWORD_DEFAULT), $isAdmin, $targetMin, $startDate]);
                $msg = 'Benutzer „' . $u . '" angelegt.';
            } catch (PDOException $e) {
                $err = 'Benutzername bereits vergeben.';
            }
        }
    } elseif ($action === 'update_balance') {
        $id = postInt('user_id');
        $targetMin = max(0, postInt('daily_target_minutes'));
        $startDate = postStr('balance_start_date');
        if ($startDate === '' || !DateTime::createFromFormat('Y-m-d', $startDate)) {
            $err = 'Ungültiges Startdatum.';
        } else {
            db()->prepare('UPDATE users SET daily_target_minutes = ?, balance_start_date = ? WHERE id = ?')
                ->execute([$targetMin, $startDate, $id]);
            $msg = 'Zeitkonto-Konfiguration gespeichert.';
        }
    } elseif ($action === 'reset_password') {
        $id = postInt('user_id');
        $p = (string)postParam('password', '');
        if ($id <= 0 || strlen($p) < 8) {
            $err = 'Passwort min. 8 Zeichen.';
        } else {
            $stmt = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $stmt->execute([password_hash($p, PASSWORD_DEFAULT), $id]);
            $msg = 'Passwort aktualisiert.';
        }
    } elseif ($action === 'toggle_admin') {
        $id = postInt('user_id');
        if ($id === (int)$me['id']) {
            $err = 'Eigene Admin-Rechte können nicht entfernt werden.';
        } else {
            db()->prepare('UPDATE users SET is_admin = 1 - is_admin WHERE id = ?')->execute([$id]);
            $msg = 'Admin-Rechte geändert.';
        }
    } elseif ($action === 'update_footer') {
        $text = postStr('footer_text');
        $linksRaw = str_replace("\r\n", "\n", postStr('footer_links'));
        $bad = [];
        foreach (explode("\n", $linksRaw) as $line) {
            if (trim($line) === '') continue;
            $parts = array_map('trim', explode('|', $line, 2));
            if (count($parts) !== 2 || $parts[0] === '' || !isSafeUrl($parts[1])) $bad[] = trim($line);
        }
        if (mb_strlen($text) > 300 || mb_strlen($linksRaw) > 2000) {
            $err = 'Footer-Text oder Links zu lang.';
        } elseif ($bad) {
            $err = 'Ungültige Link-Zeile: „' . $bad[0] . '“. Format: Name | https://… oder Name | /pfad';
        } else {
            setSetting('footer_text', $text);
            setSetting('footer_links', $linksRaw);
            $msg = 'Footer gespeichert.';
        }
    } elseif ($action === 'delete_user') {
        $id = postInt('user_id');
        if ($id === (int)$me['id']) {
            $err = 'Eigener Account kann nicht gelöscht werden.';
        } else {
            db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            $msg = 'Benutzer gelöscht.';
        }
    }
}

$users = db()->query('SELECT id, username, is_admin, created_at, daily_target_minutes, balance_start_date FROM users ORDER BY username')->fetchAll();

renderHeader('Admin', 'admin');
?>
<?php if ($msg): ?><p class="flash success"><?= h($msg) ?></p><?php endif; ?>
<?php if ($err): ?><p class="flash error"><?= h($err) ?></p><?php endif; ?>

<section class="card">
    <h2>Neuen Benutzer anlegen</h2>
    <form method="post" class="inline-form">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create_user">
        <label>Benutzername<input type="text" name="username" required maxlength="100"></label>
        <label>Passwort<input type="password" name="password" required minlength="8"></label>
        <label>Soll/Tag (Min)<input type="number" name="daily_target_minutes" value="480" min="0" max="1440" style="width:90px"></label>
        <label>Konto-Start<input type="date" name="balance_start_date" value="<?= h(date('Y-m-d')) ?>"></label>
        <label class="checkbox"><input type="checkbox" name="is_admin" value="1"> Admin</label>
        <button type="submit" class="primary">Anlegen</button>
    </form>
</section>

<section class="card">
    <h2>Benutzer</h2>
    <div class="table-wrap">
    <table class="users">
        <thead><tr><th>Benutzer</th><th>Admin</th><th>Zeitkonto</th><th>Erstellt</th><th>Aktionen</th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <td data-label="Benutzer"><?= h($u['username']) ?><?= $u['id'] == $me['id'] ? ' <span class="muted">(du)</span>' : '' ?></td>
                <td data-label="Admin"><?= $u['is_admin'] ? 'Ja' : 'Nein' ?></td>
                <td data-label="Zeitkonto">
                    <form method="post" class="inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="update_balance">
                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                        <input type="number" name="daily_target_minutes" value="<?= (int)$u['daily_target_minutes'] ?>" min="0" max="1440" style="width:70px" title="Soll/Tag (Min)">
                        <input type="date" name="balance_start_date" value="<?= h($u['balance_start_date']) ?>" title="Startdatum">
                        <button type="submit">Speichern</button>
                    </form>
                </td>
                <td data-label="Erstellt"><?= h($u['created_at']) ?></td>
                <td data-label="Aktionen" class="row-actions">
                    <form method="post" class="inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="reset_password">
                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                        <input type="password" name="password" placeholder="Neues Passwort" minlength="8" required>
                        <button type="submit">PW setzen</button>
                    </form>
                    <?php if ($u['id'] != $me['id']): ?>
                        <form method="post" class="inline" onsubmit="return confirm('Admin-Rechte ändern?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="toggle_admin">
                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                            <button type="submit"><?= $u['is_admin'] ? 'Admin entfernen' : 'Zum Admin' ?></button>
                        </form>
                        <form method="post" class="inline" onsubmit="return confirm('Benutzer und alle Zeiten löschen?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_user">
                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                            <button type="submit" class="danger">Löschen</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>

<section class="card">
    <h2>Footer</h2>
    <form method="post" class="footer-form">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="update_footer">
        <label>Text (optional)<input type="text" name="footer_text" maxlength="300" value="<?= h(setting('footer_text')) ?>" placeholder="z.B. © Firma GmbH"></label>
        <label>Links, einer pro Zeile im Format „Name | URL“
            <textarea name="footer_links" rows="4" placeholder="Datenschutz | https://example.de/datenschutz&#10;Impressum | https://example.de/impressum"><?= h(setting('footer_links')) ?></textarea>
        </label>
        <p class="hint">Der Footer erscheint auf allen Seiten, auch auf der Login-Seite.</p>
        <button type="submit" class="primary">Speichern</button>
    </form>
</section>

<section class="card">
    <h2>CSV-Export</h2>
    <form method="get" action="/admin_export.php" class="inline-form">
        <label>Typ
            <select name="type">
                <option value="work">Arbeitszeit</option>
                <option value="jobs">Aufträge</option>
            </select>
        </label>
        <label>Benutzer
            <select name="user_id">
                <option value="">Alle</option>
                <?php foreach ($users as $u): ?>
                    <option value="<?= (int)$u['id'] ?>"><?= h($u['username']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Von<input type="date" name="from"></label>
        <label>Bis<input type="date" name="to"></label>
        <label>Eingetragen
            <select name="recorded">
                <option value="all">Alle (nur Aufträge)</option>
                <option value="open">Nur offen</option>
                <option value="done">Nur eingetragen</option>
            </select>
        </label>
        <button type="submit" class="primary">Exportieren</button>
    </form>
</section>
<?php
renderFooter();
