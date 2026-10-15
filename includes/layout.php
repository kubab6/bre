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
        <?php if ($u && $u['is_admin']): ?>
            <a href="/admin.php" class="<?= $active === 'admin' ? 'active' : '' ?>">Admin</a>
        <?php endif; ?>
    </nav>
    <div class="user">
        <button type="button" id="theme-toggle" class="theme-toggle" title="Theme wechseln">Theme</button>
        <span><?= h($u['username'] ?? '') ?></span>
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
