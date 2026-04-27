<?php

declare(strict_types=1);

$flash = flash_get();
$loggedIn = isset($currentUser) && $currentUser !== null;
$appName = (string) (app_setting($GLOBALS['pdo'], 'app_name', (string) ($config['app_name'] ?? 'Fitness Challenge Tracker')) ?? 'Fitness Challenge Tracker');
$pageTitle = isset($title) ? $title . ' · ' . $appName : $appName;
$currentPage = $currentPage ?? '';
$activeLocale = current_locale();
$redirectTo = safe_redirect_target($_SERVER['REQUEST_URI'] ?? '/');
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
    'team' => ['label' => t('nav.team'), 'href' => '/?page=team', 'icon' => 'users'],
    'profile' => ['label' => t('nav.profile'), 'href' => '/?page=profile', 'icon' => 'user'],
];
$mobileNavItems = array_intersect_key($desktopNavItems, array_flip(['dashboard', 'team', 'profile']));
$topbarControls = $topbarControls ?? '';

$renderMobileIcon = static function (string $icon): string {
    return match ($icon) {
        'home' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 10.5 12 3l9 7.5v10.5a1 1 0 0 1-1 1h-5.5v-6.5h-5V22H4a1 1 0 0 1-1-1z"/></svg>',
        'users' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 11a4 4 0 1 0-3.999-4A4 4 0 0 0 16 11Zm-8 0a3 3 0 1 0-2.999-3A3 3 0 0 0 8 11Zm0 2c-2.67 0-8 1.34-8 4v1h10v-1c0-1.16.62-2.16 1.67-2.94A11.2 11.2 0 0 0 8 13Zm8 0c-2.67 0-8 1.34-8 4v1h16v-1c0-2.66-5.33-4-8-4Z"/></svg>',
        default => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a6 6 0 1 0 0 12 6 6 0 0 0 0-12Zm0 14c-4.42 0-8 2.01-8 4.5V22h16v-1.5c0-2.49-3.58-4.5-8-4.5Z"/></svg>',
    };
};
?>
<!doctype html>
<html lang="<?= e($activeLocale) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
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
    <link rel="stylesheet" href="/assets/styles.css?v=17">
</head>
<body data-page="<?= e((string) $currentPage) ?>">
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
                <a class="<?= $currentPage === $pageKey ? 'active' : '' ?>" href="<?= e($item['href']) ?>">
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
                </summary>
                <div class="user-menu-panel">
                    <a href="/?page=profile"><?= e(t('nav.profile')) ?></a>
                    <a href="/?page=settings"><?= e(t('nav.settings')) ?></a>
                    <?php if (is_admin($currentUser)): ?>
                        <a href="/?page=admin"><?= e(t('nav.admin')) ?></a>
                    <?php endif; ?>
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
            <a class="<?= $currentPage === $pageKey ? 'active' : '' ?>" href="<?= e($item['href']) ?>" <?= $currentPage === $pageKey ? 'aria-current="page"' : '' ?>>
                <span class="nav-icon"><?= $renderMobileIcon((string) $item['icon']) ?></span>
                <span><?= e($item['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>

<script src="/assets/main.js?v=16"></script>
</body>
</html>
