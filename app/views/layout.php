<?php

declare(strict_types=1);

$flash = flash_get();
$loggedIn = isset($currentUser) && $currentUser !== null;
$appName = (string) (app_setting($GLOBALS['pdo'], 'app_name', (string) ($config['app_name'] ?? 'Fitness Challenge Tracker')) ?? 'Fitness Challenge Tracker');
$pageTitle = isset($title) ? $title . ' · ' . $appName : $appName;
$currentPage = $currentPage ?? '';
$activeLocale = current_locale();
$redirectTo = safe_redirect_target($_SERVER['REQUEST_URI'] ?? '/');
$appIconPath = $loggedIn ? app_setting($GLOBALS['pdo'], 'app_icon_path') : null;
$desktopNavItems = [
    'dashboard' => ['label' => t('nav.dashboard'), 'href' => '/?page=dashboard', 'icon' => 'D'],
    'entries' => ['label' => t('nav.entries'), 'href' => '/?page=entries', 'icon' => 'L'],
    'team' => ['label' => t('nav.team'), 'href' => '/?page=team', 'icon' => 'T'],
    'profile' => ['label' => t('nav.profile'), 'href' => '/?page=profile', 'icon' => 'P'],
    'settings' => ['label' => t('nav.settings'), 'href' => '/?page=settings', 'icon' => 'S'],
];
if ($loggedIn && is_admin($currentUser)) {
    $desktopNavItems['admin'] = ['label' => t('nav.admin'), 'href' => '/?page=admin', 'icon' => 'A'];
}
$mobileNavItems = array_intersect_key($desktopNavItems, array_flip(['dashboard', 'entries', 'team', 'profile']));
$topbarControls = $topbarControls ?? '';
?>
<!doctype html>
<html lang="<?= e($activeLocale) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/styles.css?v=6">
</head>
<body>
<?php if ($loggedIn): ?>
    <header class="topbar">
        <a class="brand" href="/?page=dashboard">
            <?php if (!empty($currentUser['avatar_path'])): ?>
                <img class="brand-avatar" src="<?= e((string) $currentUser['avatar_path']) ?>" alt="<?= e((string) $currentUser['display_name']) ?>">
            <?php elseif ($appIconPath !== null && $appIconPath !== ''): ?>
                <img class="brand-avatar" src="<?= e($appIconPath) ?>" alt="<?= e($appName) ?>">
            <?php else: ?>
                <span class="brand-mark"><?= e(initials_for((string) $currentUser['display_name'])) ?></span>
            <?php endif; ?>
            <span><?= e($appName) ?></span>
        </a>

        <nav class="nav-links nav-desktop" aria-label="Primary">
            <?php foreach ($desktopNavItems as $pageKey => $item): ?>
                <a class="<?= $currentPage === $pageKey ? 'active' : '' ?>" href="<?= e($item['href']) ?>">
                    <span><?= e($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="topbar-actions">
            <?= $topbarControls ?>
            <a class="logout-link" href="/?page=logout"><?= e(t('nav.logout')) ?></a>
        </div>
    </header>
<?php endif; ?>

<main class="container <?= $loggedIn ? 'container-with-nav' : '' ?>">
    <?php if (!$loggedIn): ?>
        <form method="post" action="/?page=set_locale" class="locale-form auth-locale">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="redirect_to" value="<?= e($redirectTo) ?>">
            <label class="sr-only" for="locale-select-login"><?= e(t('common.language')) ?></label>
            <select id="locale-select-login" name="locale" onchange="this.form.submit()">
                <?php foreach (locale_options() as $locale => $label): ?>
                    <option value="<?= e($locale) ?>" <?= $locale === $activeLocale ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    <?php endif; ?>

    <?php if ($flash !== null): ?>
        <div class="flash flash-<?= e((string) ($flash['type'] ?? 'info')) ?>"><?= e((string) ($flash['message'] ?? '')) ?></div>
    <?php endif; ?>

    <?= $content ?>
</main>

<?php if ($loggedIn): ?>
    <a class="floating-log" href="/?page=entries" aria-label="<?= e(t('nav.entries')) ?>">+</a>
    <nav class="bottom-nav" aria-label="Primary mobile">
        <?php foreach ($mobileNavItems as $pageKey => $item): ?>
            <a class="<?= $currentPage === $pageKey ? 'active' : '' ?>" href="<?= e($item['href']) ?>">
                <span class="nav-icon"><?= e($item['icon']) ?></span>
                <span><?= e($item['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>

<script src="/assets/main.js?v=6"></script>
</body>
</html>
