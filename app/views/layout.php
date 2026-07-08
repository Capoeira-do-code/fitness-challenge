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
$projectRoot = dirname(__DIR__, 2);
$stylesAssetPath = $projectRoot . '/public/assets/styles.css';
$mainJsAssetPath = $projectRoot . '/public/assets/main.js';
$stylesAssetVersion = is_file($stylesAssetPath) ? (string) (@filemtime($stylesAssetPath) ?: '') : null;
$mainJsAssetVersion = is_file($mainJsAssetPath) ? (string) (@filemtime($mainJsAssetPath) ?: '') : null;
$stylesAssetUrl = with_cache_buster('/assets/styles.css', $stylesAssetVersion);
$mainJsAssetUrl = with_cache_buster('/assets/main.js', $mainJsAssetVersion);
$desktopNavItems = [
    'dashboard' => ['label' => t('nav.dashboard'), 'href' => '/?page=dashboard', 'icon' => 'home'],
    'table' => ['label' => t('nav.table'), 'href' => '/?page=week_editor&range=all', 'icon' => 'calendar'],
    'gallery' => ['label' => t('gallery.title'), 'href' => '/?page=gallery&gallery_view=recent', 'icon' => 'gallery'],
    'analytics' => ['label' => t('nav.analytics'), 'href' => '/?page=analytics', 'icon' => 'analytics'],
    'team' => ['label' => t('nav.team'), 'href' => '/?page=team', 'icon' => 'users'],
    'profile' => ['label' => t('nav.profile'), 'href' => '/?page=profile', 'icon' => 'user'],
];
$mobileNavItems = [
    'dashboard' => $desktopNavItems['dashboard'],
    'table' => $desktopNavItems['table'],
    'gallery' => ['label' => t('gallery.title'), 'href' => '/?page=gallery&gallery_view=recent', 'icon' => 'gallery'],
    'analytics' => $desktopNavItems['analytics'],
    'team' => $desktopNavItems['team'],
];
$topbarControls = $topbarControls ?? '';
$unreadNotificationsCount = $loggedIn ? user_unread_notifications_count($GLOBALS['pdo'], (int) ($currentUser['id'] ?? 0)) : 0;
$themeMode = $loggedIn ? (string) ($currentUser['theme_mode'] ?? 'auto') : 'auto';
if (!in_array($themeMode, ['auto', 'light', 'dark'], true)) {
    $themeMode = 'auto';
}
$penaltiesEnabledForLayout = $loggedIn ? penalties_enabled($GLOBALS['pdo']) : false;
$isNavActive = static function (string $pageKey) use ($currentPage): bool {
    if ($pageKey === 'calendar') {
        return $currentPage === 'entries' && (string) ($_GET['mode'] ?? '') === 'calendar';
    }
    if ($pageKey === 'gallery') {
        return in_array($currentPage, ['gallery', 'photo'], true);
    }

    return $currentPage === $pageKey;
};

$renderMobileIcon = static function (string $icon): string {
    return match ($icon) {
        'home' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11.5 12 4l9 7.5"/><path d="M5 10.5V20h5v-5h4v5h5v-9.5"/></svg>',
        'calendar' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M8 2v4M16 2v4M3 10h18"/><path d="M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01"/></svg>',
        'gallery' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><circle cx="8.5" cy="10.5" r="1.5"/><path d="m21 16-4.5-4.5L9 19"/></svg>',
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
    <link rel="stylesheet" href="<?= e($stylesAssetUrl) ?>">
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
<body data-page="<?= e((string) $currentPage) ?>" data-theme="<?= e($themeMode) ?>" data-penalties-enabled="<?= $penaltiesEnabledForLayout ? '1' : '0' ?>"<?= $bodyClasses !== [] ? ' class="' . e(implode(' ', $bodyClasses)) . '"' : '' ?><?= $bodyStyle !== '' ? ' style="' . $bodyStyle . '"' : '' ?>>
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
                    <a href="/?page=friends"><?= e(t('nav.friends')) ?></a>
                    <a href="/?page=duels"><?= e(t('nav.duels')) ?></a>
                    <a href="/?page=notifications"><?= e(t('nav.notifications')) ?><?php if ($unreadNotificationsCount > 0): ?> (<?= (int) $unreadNotificationsCount ?>)<?php endif; ?></a>
                    <a href="/?page=settings"><?= e(t('nav.settings')) ?></a>
                    <button type="button" class="user-menu-theme-toggle" data-theme-toggle data-csrf="<?= e(csrf_token()) ?>" data-label-dark="<?= e(t('nav.theme_toggle_dark')) ?>" data-label-light="<?= e(t('nav.theme_toggle_light')) ?>" aria-pressed="<?= $themeMode === 'dark' ? 'true' : 'false' ?>">
                        <span class="theme-toggle-icon theme-toggle-icon-sun" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4 12H2M22 12h-2M5 5l1.5 1.5M17.5 17.5 19 19M5 19l1.5-1.5M17.5 6.5 19 5"/></svg></span>
                        <span class="theme-toggle-icon theme-toggle-icon-moon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M20 14.5A8 8 0 1 1 9.5 4a6.5 6.5 0 0 0 10.5 10.5z"/></svg></span>
                        <span class="theme-toggle-label" data-theme-toggle-label><?= e($themeMode === 'dark' ? t('nav.theme_toggle_light') : t('nav.theme_toggle_dark')) ?></span>
                    </button>
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

<script src="<?= e($mainJsAssetUrl) ?>"></script>
</body>
</html>
