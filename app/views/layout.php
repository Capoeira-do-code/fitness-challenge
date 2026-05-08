<?php

declare(strict_types=1);

$flash = flash_get();
$loggedIn = isset($currentUser) && $currentUser !== null;
$appName = (string) (app_setting($GLOBALS['pdo'], 'app_name', (string) ($config['app_name'] ?? 'Fitness Challenge Tracker')) ?? 'Fitness Challenge Tracker');
$pageTitle = isset($title) ? $title . ' - ' . $appName : $appName;
$currentPage = $currentPage ?? '';
$activeLocale = current_locale();
$redirectTo = safe_redirect_target($_SERVER['REQUEST_URI'] ?? '/');
$loginBackgroundUrl = (string) ($loginBackgroundUrl ?? '');
$appIconSetting = db_fetch_one($GLOBALS['pdo'], 'SELECT setting_value, updated_at FROM app_settings WHERE setting_key = :key', [':key' => 'app_icon_path']);
$appIconPath = $appIconSetting !== null ? trim((string) ($appIconSetting['setting_value'] ?? '')) : '';
$appIconVersion = null;
if ($appIconSetting !== null && !empty($appIconSetting['updated_at'])) {
    $appIconTimestamp = strtotime((string) $appIconSetting['updated_at']);
    if ($appIconTimestamp !== false) {
        $appIconVersion = (string) $appIconTimestamp;
    }
}
$appIconWebUrl = '';
if ($appIconPath !== '' && resolve_media_storage_path($config, $appIconPath) !== null) {
    $appIconWebUrl = with_cache_buster('/?page=app_icon', $appIconVersion);
}
$desktopNavItems = [
    'dashboard' => ['label' => t('nav.dashboard'), 'href' => '/?page=dashboard', 'icon' => 'home'],
    'calendar' => ['label' => t('nav.calendar'), 'href' => '/?page=entries&mode=calendar&calendar_view=month', 'icon' => 'calendar'],
    'analytics' => ['label' => t('nav.analytics'), 'href' => '/?page=analytics', 'icon' => 'analytics'],
    'team' => ['label' => t('nav.team'), 'href' => '/?page=team', 'icon' => 'users'],
    'profile' => ['label' => t('nav.profile'), 'href' => '/?page=profile', 'icon' => 'user'],
];
$mobileNavItems = array_intersect_key($desktopNavItems, array_flip(['dashboard', 'calendar', 'analytics', 'team']));
$topbarControls = $topbarControls ?? '';
$unreadNotificationsCount = $loggedIn ? user_unread_notifications_count($GLOBALS['pdo'], (int) ($currentUser['id'] ?? 0)) : 0;
$themeMode = $loggedIn ? (string) ($currentUser['theme_mode'] ?? 'auto') : 'auto';
if (!in_array($themeMode, ['auto', 'light', 'dark'], true)) {
    $themeMode = 'auto';
}
$isNavActive = static function (string $pageKey) use ($currentPage): bool {
    if ($pageKey === 'calendar') {
        return $currentPage === 'entries' && (string) ($_GET['mode'] ?? '') === 'calendar';
    }

    return $currentPage === $pageKey;
};

$renderMobileIcon = static function (string $icon): string {
    return match ($icon) {
        'home' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11.5 12 4l9 7.5"/><path d="M5 10.5V20h5v-5h4v5h5v-9.5"/></svg>',
        'calendar' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M8 2v4M16 2v4M3 10h18"/><path d="M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01"/></svg>',
        'analytics' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19V5"/><path d="M4 19h17"/><rect x="7" y="11" width="3" height="5" rx="1"/><rect x="12" y="7" width="3" height="9" rx="1"/><rect x="17" y="9" width="3" height="7" rx="1"/></svg>',
        'users' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        default => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 0 0-16 0"/></svg>',
    };
};
?>
<!doctype html>
<html lang="<?= e($activeLocale) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <title><?= e($pageTitle) ?></title>
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="<?= e($appName) ?>">
    <?php if ($appIconWebUrl !== ''): ?>
        <link rel="icon" href="<?= e($appIconWebUrl) ?>">
        <link rel="apple-touch-icon" sizes="180x180" href="<?= e($appIconWebUrl) ?>">
    <?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/styles.css?v=38">
</head>
<?php
$bodyClasses = [];
if (!$loggedIn && $currentPage === 'login') {
    $bodyClasses[] = 'login-body';
}
if (!$loggedIn && $currentPage === 'login' && $loginBackgroundUrl !== '') {
    $bodyClasses[] = 'login-body-has-bg';
}
if ($loggedIn && (string) ($_GET['layout_edit'] ?? '') === '1') {
    $bodyClasses[] = 'layout-edit-active';
}
$bodyStyle = '';
if (!$loggedIn && $currentPage === 'login' && $loginBackgroundUrl !== '') {
    $bodyStyle = "--login-bg-image:url('" . e($loginBackgroundUrl) . "');";
}
?>
<body data-page="<?= e((string) $currentPage) ?>" data-theme="<?= e($themeMode) ?>"<?= $bodyClasses !== [] ? ' class="' . e(implode(' ', $bodyClasses)) . '"' : '' ?><?= $bodyStyle !== '' ? ' style="' . $bodyStyle . '"' : '' ?>>
<?php if ($loggedIn): ?>
    <header class="topbar">
        <a class="brand" href="/?page=dashboard">
            <?php if ($appIconWebUrl !== ''): ?>
                <img class="brand-avatar" src="<?= e($appIconWebUrl) ?>" alt="<?= e($appName) ?>">
            <?php else: ?>
                <span class="brand-mark"><?= e(initials_for($appName)) ?></span>
            <?php endif; ?>
            <span><?= e($appName) ?></span>
        </a>

        <nav class="nav-links nav-desktop" aria-label="Primary">
            <?php foreach ($desktopNavItems as $pageKey => $item): ?>
                <?php $navActive = $isNavActive((string) $pageKey); ?>
                <a class="<?= $navActive ? 'active' : '' ?>" href="<?= e($item['href']) ?>" <?= $navActive ? 'aria-current="page"' : '' ?>>
                    <span><?= e($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="topbar-actions">
            <?= $topbarControls ?>
            <details class="add-menu topbar-add-menu">
                <summary class="btn btn-primary add-menu-trigger btn-add" data-add-button aria-label="<?= e(t('entries.title')) ?>">
                    <span aria-hidden="true">+</span>
                    <span class="sr-only"><?= e(t('entries.title')) ?></span>
                </summary>
                <div class="add-menu-panel">
                    <a class="btn btn-ghost" href="/?page=entries&mode=data"><?= e(t('entries.quick_data')) ?></a>
                    <a class="btn btn-ghost" href="/?page=entries&mode=meal"><?= e(t('entries.quick_meal')) ?></a>
                </div>
            </details>
            <details class="user-menu">
                <summary class="user-menu-trigger">
                    <?php $currentUserAvatarUrl = avatar_url($currentUser); ?>
                    <?php if ($currentUserAvatarUrl !== ''): ?>
                        <img src="<?= e($currentUserAvatarUrl) ?>" alt="<?= e((string) $currentUser['display_name']) ?>">
                    <?php else: ?>
                        <span><?= e(initials_for((string) $currentUser['display_name'])) ?></span>
                    <?php endif; ?>
                    <?php if ($unreadNotificationsCount > 0): ?>
                        <span class="user-menu-unread-badge" data-notification-badge aria-label="<?= e(t('nav.notifications')) ?>: <?= (int) $unreadNotificationsCount ?>"><?= (int) min(99, $unreadNotificationsCount) ?></span>
                    <?php endif; ?>
                </summary>
                <div class="user-menu-panel">
                    <a href="/?page=profile"><?= e(t('nav.profile')) ?></a>
                    <a href="/?page=notifications"><?= e(t('nav.notifications')) ?><?php if ($unreadNotificationsCount > 0): ?> (<?= (int) $unreadNotificationsCount ?>)<?php endif; ?></a>
                    <a href="/?page=settings"><?= e(t('nav.settings')) ?></a>
                    <?php if (is_admin($currentUser)): ?>
                        <a href="/?page=admin"><?= e(t('nav.admin')) ?></a>
                    <?php endif; ?>
                    <a href="/?page=settings&view=avatar#avatar"><?= e(t('settings.change_avatar')) ?></a>
                    <a href="/?page=logout"><?= e(t('nav.logout')) ?></a>
                </div>
            </details>
        </div>
    </header>
<?php endif; ?>

<main class="container <?= $loggedIn ? 'container-with-nav' : '' ?>">
    <?php if (!$loggedIn && $currentPage !== 'login'): ?>
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
    <nav class="bottom-nav mobile-liquid-nav" aria-label="Primary mobile">
        <div class="liquid-nav-pill">
            <?php foreach ($mobileNavItems as $pageKey => $item): ?>
                <?php $navActive = $isNavActive((string) $pageKey); ?>
                <a class="liquid-nav-item<?= $navActive ? ' active' : '' ?>" href="<?= e($item['href']) ?>" <?= $navActive ? 'aria-current="page"' : '' ?>>
                    <span class="nav-icon"><?= $renderMobileIcon((string) $item['icon']) ?></span>
                    <span class="nav-label"><?= e($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <details class="bottom-nav-plus liquid-nav-plus add-menu">
            <summary aria-label="<?= e(t('entries.title')) ?>">
                <span class="nav-icon bottom-nav-plus-icon" aria-hidden="true">+</span>
                <span><?= e(t('common.create')) ?></span>
            </summary>
            <div class="add-menu-panel bottom-nav-plus-menu">
                <a class="btn btn-ghost" href="/?page=entries&mode=data"><?= e(t('entries.quick_data')) ?></a>
                <a class="btn btn-ghost" href="/?page=entries&mode=meal"><?= e(t('entries.quick_meal')) ?></a>
            </div>
        </details>
    </nav>
<?php endif; ?>

<script src="/assets/main.js?v=30"></script>
</body>
</html>
