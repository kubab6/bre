<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/helpers.php';

function renderHeader(string $title, string $active = ''): void {
    $u = currentUser();
    ?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?> – <?= h(APP_NAME) ?></title>
    <link rel="icon" href="/favicon/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="/favicon/apple-touch-icon.png">
    <script>(function(){try{var t=localStorage.getItem('theme');if(t==='dark'||t==='light')document.documentElement.dataset.theme=t;}catch(e){}})();</script>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<header class="topbar">
    <div class="brand"><?= h(APP_NAME) ?></div>
    <nav>
        <a href="/dashboard.php" class="<?= $active === 'dashboard' ? 'active' : '' ?>">Arbeitszeit</a>
        <a href="/jobs.php" class="<?= $active === 'jobs' ? 'active' : '' ?>">Aufträge</a>
        <a href="/balance.php" class="<?= $active === 'balance' ? 'active' : '' ?>">Zeitkonto</a>
        <a href="/calendar.php" class="<?= $active === 'calendar' ? 'active' : '' ?>">Kalender</a>
        <a href="/log.php" class="<?= $active === 'log' ? 'active' : '' ?>">Protokoll</a>
        <?php if (isManager($u)): ?>
            <a href="/admin.php" class="<?= $active === 'admin' ? 'active' : '' ?>">Verwaltung</a>
        <?php endif; ?>
    </nav>
    <div class="user">
        <button type="button" id="theme-toggle" class="theme-toggle" title="Theme wechseln">Theme</button>
        <a href="/account.php" class="account<?= $active === 'account' ? ' active' : '' ?>" title="Mein Konto"><?= h($u['username'] ?? '') ?></a>
        <a href="/logout.php" class="logout">Abmelden</a>
    </div>
</header>
<main class="container">
    <?php
}

/**
 * Footer-Links aus den Einstellungen: eine Zeile pro Link, Format "Name | URL".
 */
function footerLinks(): array {
    $links = [];
    foreach (preg_split('/\R/', setting('footer_links')) as $line) {
        $parts = array_map('trim', explode('|', $line, 2));
        if (count($parts) === 2 && $parts[0] !== '' && isSafeUrl($parts[1])) {
            $links[] = ['label' => $parts[0], 'url' => $parts[1]];
        }
    }
    return $links;
}

function isSafeUrl(string $url): bool {
    return (bool)preg_match('#^(https?://|/(?!/))#i', $url);
}

function siteFooter(): string {
    $text = setting('footer_text');
    $links = footerLinks();
    if ($text === '' && !$links) return '';
    $html = '<footer class="site-footer">';
    if ($text !== '') $html .= '<span>' . h($text) . '</span>';
    foreach ($links as $l) {
        $html .= '<a href="' . h($l['url']) . '">' . h($l['label']) . '</a>';
    }
    return $html . '</footer>';
}

/**
 * Auswahl des angezeigten Benutzers für Manager (leer für alle anderen).
 */
function userSwitcher(array $me, array $viewed, string $page): string {
    if (!isManager($me)) return '';
    $users = db()->query('SELECT id, username FROM users ORDER BY username')->fetchAll();
    $html = '<form method="get" action="' . h($page) . '" class="user-switch"><label>Benutzer anzeigen<select name="user" onchange="this.form.submit()">';
    foreach ($users as $u) {
        $sel = (int)$u['id'] === (int)$viewed['id'] ? ' selected' : '';
        $html .= '<option value="' . (int)$u['id'] . '"' . $sel . '>' . h($u['username']) . '</option>';
    }
    $html .= '</select></label><noscript><button type="submit">Anzeigen</button></noscript></form>';
    if ((int)$viewed['id'] !== (int)$me['id']) {
        $html .= '<p class="flash viewing">Du siehst die Daten von <strong>' . h($viewed['username']) . '</strong>. <a href="' . h($page) . '">Zurück zu meinen Daten</a></p>';
    }
    return $html;
}

function renderFooter(string $jsFile = ''): void {
    ?>
</main>
<?= siteFooter() ?>
<script src="/assets/theme.js"></script>
<?php if ($jsFile): ?>
<script src="/assets/common.js"></script>
<script src="<?= h($jsFile) ?>"></script>
<?php endif; ?>
</body>
</html>
    <?php
}
