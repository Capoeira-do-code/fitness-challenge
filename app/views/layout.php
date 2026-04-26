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
    'team' => ['label' => t('nav.team'), 'href' => '/?page=team', 'icon' => 'T'],
    'profile' => ['label' => t('nav.profile'), 'href' => '/?page=profile', 'icon' => 'P'],
    'settings' => ['label' => t('nav.settings'), 'href' => '/?page=settings', 'icon' => 'S'],
];
if ($loggedIn && is_admin($currentUser)) {
    $desktopNavItems['admin'] = ['label' => t('nav.admin'), 'href' => '/?page=admin', 'icon' => 'A'];
}
$mobileNavItems = array_intersect_key($desktopNavItems, array_flip(['dashboard', 'team', 'profile']));
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
    <link rel="stylesheet" href="/assets/styles.css?v=8">
</head>
<body data-page="<?= e((string) $currentPage) ?>">
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
            <details class="add-menu topbar-add-menu">
                <summary class="btn btn-primary add-menu-trigger" aria-label="<?= e(t('entries.title')) ?>">+</summary>
                <div class="add-menu-panel">
                    <a class="btn btn-ghost" href="/?page=entries&mode=data"><?= e(t('entries.quick_data')) ?></a>
                    <a class="btn btn-ghost" href="/?page=entries&mode=meal"><?= e(t('entries.quick_meal')) ?></a>
                </div>
            </details>
            <details class="user-menu">
                <summary class="user-menu-trigger">
                    <?php if (!empty($currentUser['avatar_path'])): ?>
                        <img src="<?= e((string) $currentUser['avatar_path']) ?>" alt="<?= e((string) $currentUser['display_name']) ?>">
                    <?php else: ?>
                        <span><?= e(initials_for((string) $currentUser['display_name'])) ?></span>
                    <?php endif; ?>
                </summary>
                <div class="user-menu-panel">
                    <a href="/?page=profile"><?= e(t('nav.profile')) ?></a>
                    <a href="/?page=settings"><?= e(t('nav.settings')) ?></a>
                    <a href="/?page=settings#avatar"><?= e(t('settings.change_avatar')) ?></a>
                    <a href="/?page=logout"><?= e(t('nav.logout')) ?></a>
                </div>
            </details>
        </div>
    </header>
<?php endif; ?>

<main class="container <?= $loggedIn ? 'container-with-nav' : '' ?>">
    <?php if (!$loggedIn): ?>
        <?php
        $localeScope = 'login';
        $localeFormClass = 'locale-form auth-locale';
        $localeSelectId = 'locale-select-login';
        $localeRedirectTo = $redirectTo;
        $localeShowSaveButton = false;
        $localeAsync = $currentPage === 'login';
        require __DIR__ . '/components/locale_selector.php';
        ?>
    <?php endif; ?>

    <?php if ($flash !== null): ?>
        <div class="flash flash-<?= e((string) ($flash['type'] ?? 'info')) ?>"><?= e((string) ($flash['message'] ?? '')) ?></div>
    <?php endif; ?>

    <?= $content ?>
</main>

<?php if ($loggedIn): ?>
    <details class="floating-log add-menu">
        <summary class="add-menu-trigger" aria-label="<?= e(t('entries.title')) ?>">+</summary>
        <div class="add-menu-panel floating-add-panel">
            <a class="btn btn-ghost" href="/?page=entries&mode=data"><?= e(t('entries.quick_data')) ?></a>
            <a class="btn btn-ghost" href="/?page=entries&mode=meal"><?= e(t('entries.quick_meal')) ?></a>
        </div>
    </details>
    <nav class="bottom-nav" aria-label="Primary mobile">
        <?php foreach ($mobileNavItems as $pageKey => $item): ?>
            <a class="<?= $currentPage === $pageKey ? 'active' : '' ?>" href="<?= e($item['href']) ?>">
                <span class="nav-icon"><?= e($item['icon']) ?></span>
                <span><?= e($item['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>

<script src="/assets/main.js?v=8"></script>
</body>
</html>
