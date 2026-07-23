<?php

declare(strict_types=1);

$flash = flash_get();
$loggedIn = isset($currentUser) && $currentUser !== null;
$appName = (string) (app_setting($GLOBALS['pdo'], 'app_name', (string) ($config['app_name'] ?? 'Fitness Challenge Tracker')) ?? 'Fitness Challenge Tracker');
$pageTitle = isset($title) ? $title . ' - ' . $appName : $appName;
$currentPage = $currentPage ?? '';
$contextualBackFallbacks = [
    'analytics' => '/?page=dashboard',
    'duels' => '/?page=social',
    'competitions' => '/?page=social',
    'gallery' => '/?page=social',
    'notifications' => '/?page=dashboard&section=alerts',
    'quests' => '/?page=dashboard&section=rewards',
    'season' => '/?page=dashboard&section=rewards',
    'settings' => '/?page=profile',
    'week_editor' => '/?page=dashboard&section=history',
    'table' => '/?page=dashboard&section=history',
];
$contextualBackDestinationKeys = [
    'analytics' => 'nav.dashboard',
    'duels' => 'nav.social',
    'competitions' => 'nav.social',
    'gallery' => 'nav.social',
    'notifications' => 'dashboard.mobile_alerts',
    'quests' => 'dashboard.mobile_rewards',
    'season' => 'dashboard.mobile_rewards',
    'settings' => 'nav.profile',
    'week_editor' => 'dashboard.mobile_history',
    'table' => 'dashboard.mobile_history',
];
$contextualRoutePage = trim((string) ($_GET['page'] ?? $currentPage));
$contextualBackFallback = $contextualBackFallbacks[$contextualRoutePage] ?? '';
$contextualBackDestination = isset($contextualBackDestinationKeys[$contextualRoutePage])
    ? t($contextualBackDestinationKeys[$contextualRoutePage])
    : '';
if ($contextualRoutePage === 'analytics' && trim((string) ($_GET['section'] ?? '')) !== '') {
    $contextualBackFallback = '';
    $contextualBackDestination = '';
}
if ($contextualRoutePage === 'settings' && trim((string) ($_GET['view'] ?? '')) !== '') {
    $contextualBackFallback = '';
    $contextualBackDestination = '';
}
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
$pageStylesAssetFile = match ($currentPage) {
    'workouts' => 'workouts.css',
    'competitions' => 'competitions.css',
    'profile' => 'profile.css',
    'team' => 'team.css',
    'admin' => 'admin.css',
    'dashboard', 'quests', 'season' => 'dashboard.css',
    'metric' => 'metric.css',
    'achievements' => 'achievements.css',
    'entries' => 'entries.css',
    'settings' => 'settings.css',
    'register', 'onboarding' => 'onboarding.css',
    default => '',
};
$pageStylesAssetPath = $pageStylesAssetFile !== '' ? $projectRoot . '/public/assets/' . $pageStylesAssetFile : '';
$detailStylesAssetFile = $currentPage === 'workouts' && (string) ($_GET['view'] ?? '') === 'library'
    ? 'workouts-library.css'
    : '';
$detailStylesAssetPath = $detailStylesAssetFile !== '' ? $projectRoot . '/public/assets/' . $detailStylesAssetFile : '';
$themeStylesAssetFile = 'theme-dark.css';
$themeStylesAssetPath = $projectRoot . '/public/assets/' . $themeStylesAssetFile;
$mainJsAssetPath = $projectRoot . '/public/assets/main.js';
$stylesAssetVersion = is_file($stylesAssetPath) ? (string) (@filemtime($stylesAssetPath) ?: '') : null;
$pageStylesAssetVersion = $pageStylesAssetPath !== '' && is_file($pageStylesAssetPath) ? (string) (@filemtime($pageStylesAssetPath) ?: '') : null;
$detailStylesAssetVersion = $detailStylesAssetPath !== '' && is_file($detailStylesAssetPath) ? (string) (@filemtime($detailStylesAssetPath) ?: '') : null;
$themeStylesAssetVersion = is_file($themeStylesAssetPath) ? (string) (@filemtime($themeStylesAssetPath) ?: '') : null;
$mainJsAssetVersion = is_file($mainJsAssetPath) ? (string) (@filemtime($mainJsAssetPath) ?: '') : null;
$compressedAssetUrl = static function (string $file, ?string $version = null): string {
    $query = ['file' => ltrim($file, '/')];
    if ($version !== null && $version !== '') {
        $query['v'] = $version;
    }
    return '/asset.php?' . http_build_query($query);
};
$stylesAssetUrl = $compressedAssetUrl('styles.css', $stylesAssetVersion);
$pageStylesAssetUrl = $pageStylesAssetPath !== '' ? $compressedAssetUrl($pageStylesAssetFile, $pageStylesAssetVersion) : '';
$detailStylesAssetUrl = $detailStylesAssetPath !== '' ? $compressedAssetUrl($detailStylesAssetFile, $detailStylesAssetVersion) : '';
$themeStylesAssetUrl = is_file($themeStylesAssetPath) ? $compressedAssetUrl($themeStylesAssetFile, $themeStylesAssetVersion) : '';
$mainJsAssetUrl = $compressedAssetUrl('main.js', $mainJsAssetVersion);
$desktopNavItems = [
    'dashboard' => ['label' => t('nav.dashboard'), 'href' => '/?page=dashboard', 'icon' => 'home'],
    'table' => ['label' => t('nav.table'), 'href' => '/?page=workouts', 'icon' => 'dumbbell'],
    'gallery' => ['label' => t('gallery.title'), 'href' => '/?page=gallery&gallery_view=recent', 'icon' => 'gallery'],
    'analytics' => ['label' => t('nav.analytics'), 'href' => '/?page=analytics', 'icon' => 'analytics'],
    'team' => ['label' => t('nav.team'), 'href' => '/?page=team', 'icon' => 'users'],
    'profile' => ['label' => t('nav.profile'), 'href' => '/?page=profile', 'icon' => 'user'],
];
$mobileNavItems = [
    'dashboard' => array_replace($desktopNavItems['dashboard'], ['label' => t('nav.home')]),
    'table' => array_replace($desktopNavItems['table'], ['label' => t('nav.training_short')]),
    'social' => ['label' => t('nav.social'), 'href' => '/?page=social', 'icon' => 'social'],
    'profile' => $desktopNavItems['profile'],
];
$topbarControls = $topbarControls ?? '';
$unreadNotificationsCount = $loggedIn ? user_unread_notifications_count($GLOBALS['pdo'], (int) ($currentUser['id'] ?? 0)) : 0;
$mobileChallengeTeamId = 0;
$mobileCanCreateCompetition = false;
if ($loggedIn) {
    try {
        foreach (list_user_teams($GLOBALS['pdo'], (int) ($currentUser['id'] ?? 0)) as $quickTeam) {
            if (can_manage_team($GLOBALS['pdo'], $currentUser, (int) ($quickTeam['id'] ?? 0))) {
                $mobileChallengeTeamId = (int) ($quickTeam['id'] ?? 0);
                break;
            }
        }
        squads_ensure_schema($GLOBALS['pdo']);
        $mobileCanCreateCompetition = squads_owned($GLOBALS['pdo'], (int) ($currentUser['id'] ?? 0)) !== [];
    } catch (Throwable) {
        // The quick sheet is navigation only. A missing optional community table
        // must never prevent the rest of the application shell from rendering.
    }
}
// Light is the default look: "auto" handed people a dark app because their OS said so,
// which is not what this product is.
$themeMode = $loggedIn ? (string) ($currentUser['theme_mode'] ?? 'light') : 'light';
if (!in_array($themeMode, ['auto', 'light', 'dark'], true)) {
    $themeMode = 'light';
}
$penaltiesEnabledForLayout = $loggedIn ? penalties_enabled($GLOBALS['pdo']) : false;
$isNavActive = static function (string $pageKey) use ($currentPage): bool {
    if ($pageKey === 'dashboard' && in_array($currentPage, ['metric', 'season'], true)) {
        return true;
    }
    if ($pageKey === 'calendar') {
        return $currentPage === 'entries' && (string) ($_GET['mode'] ?? '') === 'calendar';
    }
    if ($pageKey === 'gallery') {
        return in_array($currentPage, ['gallery', 'photo'], true);
    }
    if ($pageKey === 'table') {
        return in_array($currentPage, ['table', 'week_editor', 'workouts'], true);
    }
    return $currentPage === $pageKey;
};
$isMobileNavActive = static function (string $pageKey) use ($currentPage): bool {
    return match ($pageKey) {
        'dashboard' => in_array($currentPage, ['dashboard', 'analytics', 'metric', 'season', 'comparison_detail', 'strikes_detail', 'penalties', 'notifications'], true),
        'table' => in_array($currentPage, ['table', 'week_editor', 'workouts'], true),
        'social' => in_array($currentPage, ['social', 'gallery', 'photo', 'team', 'team_settings', 'friends', 'challenges', 'duels', 'competitions'], true),
        'profile' => in_array($currentPage, ['profile', 'settings', 'achievements'], true),
        default => $currentPage === $pageKey,
    };
};

$renderMobileIcon = static function (string $icon): string {
    return match ($icon) {
        'home' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11.5 12 4l9 7.5"/><path d="M5 10.5V20h5v-5h4v5h5v-9.5"/></svg>',
        'calendar' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M8 2v4M16 2v4M3 10h18"/><path d="M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01"/></svg>',
        'dumbbell' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 8v8M3 10v4M18 8v8M21 10v4M6 12h12"/></svg>',
        'gallery' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><circle cx="8.5" cy="10.5" r="1.5"/><path d="m21 16-4.5-4.5L9 19"/></svg>',
        'analytics' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19V5"/><path d="M4 19h17"/><rect x="7" y="11" width="3" height="5" rx="1"/><rect x="12" y="7" width="3" height="9" rx="1"/><rect x="17" y="9" width="3" height="7" rx="1"/></svg>',
        'social' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="8" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M2.5 20a5.5 5.5 0 0 1 11 0M13.5 19a4.5 4.5 0 0 1 8 0"/></svg>',
        'users' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        default => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 0 0-16 0"/></svg>',
    };
};
$renderQuickActionIcon = static function (string $mode): string {
    return match ($mode) {
        'data' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="5" width="16" height="16" rx="3"/><path d="M8 3v4M16 3v4M4 10h16M8 15l2 2 5-5"/></svg>',
        'meal' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3v7M3.5 3v5a2.5 2.5 0 0 0 5 0V3M6 10v11M15 3v18M15 3c3 1.5 4 4.5 4 8h-4"/></svg>',
        'goal' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="3"/><path d="m14 10 6-6M16 4h4v4"/></svg>',
        'challenge' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 21V4M5 5h11l-2 4 2 4H5"/></svg>',
        'competition' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 4h8v4a4 4 0 0 1-8 0V4ZM8 6H4v2a4 4 0 0 0 4 4M16 6h4v2a4 4 0 0 1-4 4M12 12v5M8 21h8M9 17h6"/></svg>',
        'register' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 5h14M5 12h9M5 19h6"/><path d="m16 17 2 2 4-5"/></svg>',
        'create' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/><path d="M18 3v3M16.5 4.5h3M5 17v2M4 18h2"/></svg>',
        default => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 8v8M3 10v4M18 8v8M21 10v4M6 12h12"/></svg>',
    };
};
?>
<!doctype html>
<html lang="<?= e($activeLocale) ?>">
<head>
    <meta charset="utf-8">
    <?php // Pinch-zoom stays available: blocking it fails WCAG 1.4.4 and makes the dense
          // training table unusable for anyone who needs to zoom. The reason people disable
          // it - iOS auto-zooming on focus - is fixed properly instead, by giving touch
          // devices 16px fields. ?>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= e($pageTitle) ?></title>
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="<?= e($appName) ?>">
    <meta name="theme-color" content="#18a999">
    <link rel="manifest" href="/?page=manifest">
    <?php if ($appIconWebUrl !== ''): ?>
        <link rel="icon" href="<?= e($appIconWebUrl) ?>">
        <link rel="apple-touch-icon" sizes="180x180" href="<?= e($appIconWebUrl) ?>">
    <?php else: ?>
        <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='16' fill='%2318a999'/%3E%3Cpath d='M14 25v14m-7-9v4m43-9v14m7-9v4M14 32h36' fill='none' stroke='white' stroke-width='6' stroke-linecap='round'/%3E%3C/svg%3E">
        <link rel="apple-touch-icon" sizes="192x192" href="/?page=app_icon_default&amp;size=192">
    <?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e($stylesAssetUrl) ?>">
    <?php if ($pageStylesAssetUrl !== ''): ?>
        <link rel="stylesheet" href="<?= e($pageStylesAssetUrl) ?>" data-pjax-page-style="page">
    <?php endif; ?>
    <?php if ($themeStylesAssetUrl !== ''): ?>
        <link rel="stylesheet" href="<?= e($themeStylesAssetUrl) ?>" data-theme-stylesheet>
    <?php endif; ?>
    <?php if ($detailStylesAssetUrl !== ''): ?>
        <link rel="stylesheet" href="<?= e($detailStylesAssetUrl) ?>" data-pjax-page-style="detail">
    <?php endif; ?>
</head>
<?php
$bodyClasses = [];
$minimalAppShell = $loggedIn && $currentPage === 'onboarding';
if (!empty($immersiveMobile)) {
    $bodyClasses[] = 'mobile-immersive-mode';
}
if (!$loggedIn && in_array($currentPage, ['login', 'register'], true)) {
    $bodyClasses[] = 'login-body';
}
if (!$loggedIn && $currentPage === 'login' && $loginBackgroundUrl !== '') {
    $bodyClasses[] = 'login-body-has-bg';
}
if (!$loggedIn && $currentPage === 'login' && (string) ($loginStyle ?? '') === 'spotlight') {
    $bodyClasses[] = 'login-variant-spotlight-body';
}
$layoutEditRequested = $loggedIn
    && (string) ($_GET['layout_edit'] ?? '') === '1'
    && in_array((string) $currentPage, ['dashboard', 'analytics', 'team', 'profile'], true);
if ($layoutEditRequested && $currentPage === 'profile') {
    $profileSectionForLayout = trim((string) ($_GET['section'] ?? ''));
    $profileUserIdForLayout = isset($_GET['user_id']) ? (int) $_GET['user_id'] : (int) ($currentUser['id'] ?? 0);
    $layoutEditRequested = $profileSectionForLayout === '' && $profileUserIdForLayout === (int) ($currentUser['id'] ?? 0);
}
if ($layoutEditRequested && $currentPage === 'team') {
    $layoutEditRequested = trim((string) ($_GET['section'] ?? '')) === ''
        && trim((string) ($_GET['metric'] ?? '')) === '';
}
if ($layoutEditRequested) {
    $bodyClasses[] = 'layout-edit-active';
}
$bodyStyle = '';
if (!$loggedIn && $currentPage === 'login' && $loginBackgroundUrl !== '') {
    $bodyStyle = "--login-bg-image:url('" . e($loginBackgroundUrl) . "');";
}
?>
<body data-page="<?= e((string) $currentPage) ?>" data-theme="<?= e($themeMode) ?>" data-ui-user="<?= $loggedIn ? (int) ($currentUser['id'] ?? 0) : 0 ?>" data-penalties-enabled="<?= $penaltiesEnabledForLayout ? '1' : '0' ?>" data-layout-drag-label="<?= e(t('layout.drag_widget')) ?>" data-layout-remove-label="<?= e(t('layout.remove_widget')) ?>" data-layout-add-label="<?= e(t('layout.add_widget')) ?>" data-layout-visible-label="<?= e(t('layout.visible_widget')) ?>"<?= $bodyClasses !== [] ? ' class="' . e(implode(' ', $bodyClasses)) . '"' : '' ?><?= $bodyStyle !== '' ? ' style="' . $bodyStyle . '"' : '' ?>>
<?php if ($loggedIn && !$minimalAppShell): ?>
    <header class="topbar">
        <a class="brand" href="/?page=dashboard">
            <?php if ($appIconWebUrl !== ''): ?>
                <img class="brand-avatar" src="<?= e($appIconWebUrl) ?>" alt="<?= e($appName) ?>">
            <?php else: ?>
                <span class="brand-mark"><?= e(initials_for($appName)) ?></span>
            <?php endif; ?>
            <span class="brand-name"><?= e($appName) ?></span>
        </a>

        <?php // On a phone the page name lives here instead of in a hero panel that ate a
              // quarter of the screen. The app name is already implied by the icon next to it.
              // It is a real <h1>: the hero heading it replaces is display:none on mobile, so
              // without this the page would have no heading at all for a screen reader. ?>
        <h1 class="topbar-page-title"><?= e((string) ($title ?? '')) ?></h1>

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
            <?php // Desktop: a preview dropdown, so a notification can be read (and dismissed)
                  // without leaving the page. Mobile keeps the plain link - a dropdown that
                  // tall on a phone is just a worse version of the page it links to. ?>
            <a class="topbar-notif-btn topbar-notif-link" href="/?page=notifications" aria-label="<?= e(t('nav.notifications')) ?><?= $unreadNotificationsCount > 0 ? ' (' . (int) $unreadNotificationsCount . ')' : '' ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                <?php if ($unreadNotificationsCount > 0): ?>
                    <span class="topbar-notif-badge" data-notification-badge><?= (int) min(99, $unreadNotificationsCount) ?></span>
                <?php endif; ?>
            </a>
            <?php $notifPreview = $loggedIn ? user_notifications($GLOBALS['pdo'], (int) ($currentUser['id'] ?? 0), 5, true) : []; ?>
            <details class="notif-menu">
                <summary class="topbar-notif-btn" aria-label="<?= e(t('nav.notifications')) ?><?= $unreadNotificationsCount > 0 ? ' (' . (int) $unreadNotificationsCount . ')' : '' ?>" aria-expanded="false">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                    <?php if ($unreadNotificationsCount > 0): ?>
                        <span class="topbar-notif-badge" data-notification-badge><?= (int) min(99, $unreadNotificationsCount) ?></span>
                    <?php endif; ?>
                </summary>
                <div class="notif-menu-panel" aria-labelledby="notif-preview-title">
                    <div class="notif-menu-head">
                        <span class="notif-menu-title"><strong id="notif-preview-title"><?= e(t('nav.notifications')) ?></strong><small><?= e(t('notifications.unread_count', ['count' => $unreadNotificationsCount])) ?></small></span>
                        <?php if ($unreadNotificationsCount > 0): ?>
                            <form method="post" action="/?page=notifications" class="inline-form">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="mark_all_notifications_read">
                                <button class="btn btn-ghost small" type="submit"><?= e(t('notifications.mark_all_read')) ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <?php if ($notifPreview === []): ?>
                        <p class="muted small notif-menu-empty"><?= e(t('notifications.empty')) ?></p>
                    <?php else: ?>
                        <ul class="notif-menu-list">
                            <?php foreach ($notifPreview as $notifItem): ?>
                                <li class="notif-menu-item<?= empty($notifItem['read_at']) ? ' is-unread' : '' ?>">
                                    <a href="/?page=notifications&open_notification_id=<?= (int) $notifItem['id'] ?>">
                                        <strong><?= e((string) ($notifItem['title'] ?? '')) ?></strong>
                                        <span><?= e((string) ($notifItem['message'] ?? '')) ?></span>
                                        <small class="muted"><?= e(human_time_ago((string) ($notifItem['created_at'] ?? ''))) ?></small>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <a class="btn btn-ghost small btn-block notif-menu-all" href="/?page=notifications"><?= e(t('common.view_all')) ?></a>
                </div>
            </details>
            <details class="add-menu topbar-add-menu">
                <summary class="btn btn-primary add-menu-trigger btn-add" data-add-button aria-label="<?= e(t('entries.title')) ?>">
                    <span aria-hidden="true">+</span>
                    <span class="sr-only"><?= e(t('entries.title')) ?></span>
                </summary>
                <div class="add-menu-panel">
                    <a class="btn btn-ghost quick-entry-action" href="/?page=entries&mode=data"><span class="quick-entry-icon"><?= $renderQuickActionIcon('data') ?></span><span><?= e(t('entries.quick_data')) ?></span></a>
                    <a class="btn btn-ghost quick-entry-action" href="/?page=entries&mode=meal"><span class="quick-entry-icon"><?= $renderQuickActionIcon('meal') ?></span><span><?= e(t('entries.quick_meal')) ?></span></a>
                </div>
            </details>
            <details class="user-menu">
                <summary class="user-menu-trigger" aria-label="<?= e(t('nav.user_menu', ['name' => (string) $currentUser['display_name']])) ?>" aria-expanded="false">
                    <?php $currentUserAvatarUrl = avatar_url($currentUser); ?>
                    <?php $currentUserFrameClass = cosmetic_frame_class($currentUser); ?>
                    <?php if ($currentUserAvatarUrl !== ''): ?>
                        <img class="<?= e(trim($currentUserFrameClass)) ?>" src="<?= e($currentUserAvatarUrl) ?>" alt="<?= e((string) $currentUser['display_name']) ?>">
                    <?php else: ?>
                        <span class="<?= e(trim($currentUserFrameClass)) ?>"><?= e(initials_for((string) $currentUser['display_name'])) ?></span>
                    <?php endif; ?>
                </summary>
                <nav class="user-menu-panel" aria-label="<?= e(t('nav.user_menu', ['name' => (string) $currentUser['display_name']])) ?>" data-menu-stack>
                    <div class="user-menu-view<?= is_admin($currentUser) ? ' has-admin-item' : '' ?>" data-menu-view="main">
                    <?php if (function_exists('xp_user_level_info')): $menuXp = xp_user_level_info($GLOBALS['pdo'], (int) $currentUser['id']); ?>
                        <a class="user-menu-level" href="/?page=profile" title="<?= e(t('xp.level') . ' ' . (int) $menuXp['level']) ?>">
                            <span class="profile-level-badge"><?= e(t('xp.level_short')) ?> <?= (int) $menuXp['level'] ?></span>
                            <span class="user-menu-xp">
                                <span class="user-menu-xp-bar"><span style="width: <?= max(0, min(100, (int) $menuXp['progress_pct'])) ?>%"></span></span>
                                <span class="user-menu-xp-text"><?= e(number_format((int) $menuXp['total_xp'])) ?> <?= e(t('xp.points')) ?> &middot; <?= e(t('xp.to_next', ['xp' => number_format((int) $menuXp['xp_to_next'])])) ?></span>
                            </span>
                        </a>
                    <?php endif; ?>
                    <a class="user-menu-link" href="/?page=profile"<?= $currentPage === 'profile' ? ' aria-current="page"' : '' ?>><span class="user-menu-item-icon" aria-hidden="true"><?= activity_icon_svg('user') ?></span><span><?= e(t('nav.profile')) ?></span></a>
                    <a class="user-menu-link" href="/?page=workouts"<?= $currentPage === 'workouts' ? ' aria-current="page"' : '' ?>><span class="user-menu-item-icon" aria-hidden="true"><?= activity_icon_svg('dumbbell') ?></span><span><?= e(t('nav.workouts')) ?></span></a>
                    <a class="user-menu-link" href="/?page=friends"<?= $currentPage === 'friends' ? ' aria-current="page"' : '' ?>><span class="user-menu-item-icon" aria-hidden="true"><?= activity_icon_svg('users') ?></span><span><?= e(t('nav.friends')) ?></span></a>
                    <a class="user-menu-link" href="/?page=settings"<?= $currentPage === 'settings' ? ' aria-current="page"' : '' ?>><span class="user-menu-item-icon" aria-hidden="true"><?= activity_icon_svg('sliders') ?></span><span><?= e(t('nav.settings')) ?></span></a>
                    <button type="button" class="user-menu-theme-toggle" data-theme-toggle data-csrf="<?= e(csrf_token()) ?>" data-label-dark="<?= e(t('nav.theme_toggle_dark')) ?>" data-label-light="<?= e(t('nav.theme_toggle_light')) ?>" aria-pressed="<?= $themeMode === 'dark' ? 'true' : 'false' ?>">
                        <span class="theme-toggle-icon theme-toggle-icon-sun" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4 12H2M22 12h-2M5 5l1.5 1.5M17.5 17.5 19 19M5 19l1.5-1.5M17.5 6.5 19 5"/></svg></span>
                        <span class="theme-toggle-icon theme-toggle-icon-moon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M20 14.5A8 8 0 1 1 9.5 4a6.5 6.5 0 0 0 10.5 10.5z"/></svg></span>
                        <span class="theme-toggle-label" data-theme-toggle-label><?= e($themeMode === 'dark' ? t('nav.theme_toggle_light') : t('nav.theme_toggle_dark')) ?></span>
                    </button>
                    <?php if (is_admin($currentUser)): ?>
                        <a class="user-menu-link" href="/?page=admin"<?= $currentPage === 'admin' ? ' aria-current="page"' : '' ?>><span class="user-menu-item-icon" aria-hidden="true"><?= activity_icon_svg('shield') ?></span><span><?= e(t('nav.admin')) ?></span></a>
                    <?php endif; ?>
                    <a class="user-menu-link user-menu-logout" href="/?page=logout"><span class="user-menu-item-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 17l5-5-5-5M15 12H3"/><path d="M14 4h5a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-5"/></svg></span><span><?= e(t('nav.logout')) ?></span></a>
                    </div>
                </nav>
            </details>
        </div>
    </header>
<?php endif; ?>

<main class="container <?= $loggedIn && !$minimalAppShell ? 'container-with-nav' : '' ?>">
    <?php if (!$loggedIn && !in_array($currentPage, ['login', 'register'], true)): ?>
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

    <?php if ($loggedIn && !$minimalAppShell && $contextualBackFallback !== ''): ?>
        <nav class="contextual-route-back" data-contextual-back-container aria-label="<?= e(t('common.back')) ?>">
            <button class="hierarchy-back destination-back" type="button" data-hierarchy-back data-fallback="<?= e($contextualBackFallback) ?>" aria-label="<?= e(t('common.back')) ?>: <?= e($contextualBackDestination) ?>"><span aria-hidden="true">&larr;</span><strong><?= e($contextualBackDestination) ?></strong></button>
        </nav>
    <?php endif; ?>

    <?= $content ?>
</main>

<?php if ($loggedIn && !$minimalAppShell): ?>
    <details class="floating-log add-menu">
        <summary class="add-menu-trigger" aria-label="<?= e(t('entries.title')) ?>">+</summary>
        <div class="add-menu-panel floating-add-panel">
            <a class="btn btn-ghost quick-entry-action" href="/?page=entries&mode=data"><span class="quick-entry-icon"><?= $renderQuickActionIcon('data') ?></span><span><?= e(t('entries.quick_data')) ?></span></a>
            <a class="btn btn-ghost quick-entry-action" href="/?page=entries&mode=meal"><span class="quick-entry-icon"><?= $renderQuickActionIcon('meal') ?></span><span><?= e(t('entries.quick_meal')) ?></span></a>
        </div>
    </details>
    <nav class="bottom-nav mobile-liquid-nav" aria-label="<?= e(t('nav.mobile_primary')) ?>">
        <div class="liquid-nav-pill">
            <?php $mobileNavPosition = 0; ?>
            <?php foreach ($mobileNavItems as $pageKey => $item): ?>
                <?php if ($mobileNavPosition === 2): ?>
                    <details class="bottom-nav-plus liquid-nav-plus add-menu" data-nav-action="create">
                        <summary aria-label="<?= e(t('quick_actions.title')) ?>" aria-haspopup="menu">
                            <span class="nav-icon bottom-nav-plus-icon" aria-hidden="true">+</span>
                            <span class="nav-label"><?= e(t('common.create')) ?></span>
                        </summary>
                        <div class="add-menu-panel bottom-nav-plus-menu mobile-quick-sheet" data-menu-stack>
                            <div class="mobile-quick-view" data-menu-view="main">
                                <div class="mobile-quick-head">
                                    <div><strong><?= e(t('quick_actions.title')) ?></strong><small><?= e(t('quick_actions.subtitle')) ?></small></div>
                                    <button type="button" data-menu-close aria-label="<?= e(t('menu.close')) ?>">&times;</button>
                                </div>
                                <button type="button" class="mobile-quick-nav" data-tone="blue" data-menu-open="quick-register">
                                    <span class="mobile-quick-nav-icon" aria-hidden="true"><?= $renderQuickActionIcon('register') ?></span><span class="mobile-quick-nav-copy"><strong><?= e(t('quick_actions.register')) ?></strong><small><?= e(t('quick_actions.register_hint')) ?></small></span><span class="mobile-quick-chevron" aria-hidden="true">&rsaquo;</span>
                                </button>
                                <button type="button" class="mobile-quick-nav" data-tone="violet" data-menu-open="quick-create">
                                    <span class="mobile-quick-nav-icon" aria-hidden="true"><?= $renderQuickActionIcon('create') ?></span><span class="mobile-quick-nav-copy"><strong><?= e(t('quick_actions.create')) ?></strong><small><?= e(t('quick_actions.create_hint')) ?></small></span><span class="mobile-quick-chevron" aria-hidden="true">&rsaquo;</span>
                                </button>
                            </div>
                            <div class="mobile-quick-view" data-menu-view="quick-register" hidden>
                                <div class="mobile-quick-head"><button class="menu-destination-back" type="button" data-menu-back aria-label="<?= e(t('common.back')) ?>: <?= e(t('quick_actions.title')) ?>"><span aria-hidden="true">&larr;</span><strong><?= e(t('quick_actions.title')) ?></strong></button><strong><?= e(t('quick_actions.register')) ?></strong><button type="button" data-menu-close aria-label="<?= e(t('menu.close')) ?>">&times;</button></div>
                                <a class="mobile-quick-action" data-tone="blue" href="/?page=entries&mode=data"><span class="quick-entry-icon"><?= $renderQuickActionIcon('data') ?></span><span><strong><?= e(t('entries.quick_data')) ?></strong><small><?= e(t('quick_actions.daily_hint')) ?></small></span></a>
                                <a class="mobile-quick-action" data-tone="orange" href="/?page=entries&mode=meal"><span class="quick-entry-icon"><?= $renderQuickActionIcon('meal') ?></span><span><strong><?= e(t('entries.quick_meal')) ?></strong><small><?= e(t('quick_actions.meal_hint')) ?></small></span></a>
                                <a class="mobile-quick-action" data-tone="green" href="/?page=workouts"><span class="quick-entry-icon"><?= $renderQuickActionIcon('workout') ?></span><span><strong><?= e(t('quick_actions.workout')) ?></strong><small><?= e(t('quick_actions.workout_hint')) ?></small></span></a>
                            </div>
                            <div class="mobile-quick-view" data-menu-view="quick-create" hidden>
                                <div class="mobile-quick-head"><button class="menu-destination-back" type="button" data-menu-back aria-label="<?= e(t('common.back')) ?>: <?= e(t('quick_actions.title')) ?>"><span aria-hidden="true">&larr;</span><strong><?= e(t('quick_actions.title')) ?></strong></button><strong><?= e(t('quick_actions.create')) ?></strong><button type="button" data-menu-close aria-label="<?= e(t('menu.close')) ?>">&times;</button></div>
                                <a class="mobile-quick-action" data-tone="violet" href="/?page=profile&section=goals&goal_new=1"><span class="quick-entry-icon"><?= $renderQuickActionIcon('goal') ?></span><span><strong><?= e(t('quick_actions.goal')) ?></strong><small><?= e(t('quick_actions.goal_hint')) ?></small></span></a>
                                <?php if ($mobileChallengeTeamId > 0): ?>
                                    <a class="mobile-quick-action" data-tone="orange" href="/?page=team&team_id=<?= $mobileChallengeTeamId ?>&section=challenge&create=1"><span class="quick-entry-icon"><?= $renderQuickActionIcon('challenge') ?></span><span><strong><?= e(t('quick_actions.challenge')) ?></strong><small><?= e(t('quick_actions.challenge_hint')) ?></small></span></a>
                                <?php endif; ?>
                                <?php if ($mobileCanCreateCompetition): ?>
                                    <a class="mobile-quick-action" data-tone="amber" href="/?page=competitions#competition-teams"><span class="quick-entry-icon"><?= $renderQuickActionIcon('competition') ?></span><span><strong><?= e(t('quick_actions.competition')) ?></strong><small><?= e(t('quick_actions.competition_hint')) ?></small></span></a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </details>
                <?php endif; ?>
                <?php $navActive = $isMobileNavActive((string) $pageKey); ?>
                <a class="liquid-nav-item<?= $navActive ? ' active' : '' ?>" data-nav-destination="<?= e((string) $pageKey) ?>" href="<?= e($item['href']) ?>" <?= $navActive ? 'aria-current="page"' : '' ?>>
                    <span class="nav-icon"><?= $renderMobileIcon((string) $item['icon']) ?></span>
                    <span class="nav-label"><?= e($item['label']) ?></span>
                </a>
                <?php $mobileNavPosition++; ?>
            <?php endforeach; ?>
        </div>
    </nav>
<?php endif; ?>

<?php if ($loggedIn && !$minimalAppShell): ?>
    <aside class="pwa-install-nudge" data-pwa-install-nudge data-ios-hint="<?= e(t('pwa.nudge_ios')) ?>" data-android-hint="<?= e(t('pwa.nudge_android')) ?>" hidden role="region" aria-labelledby="pwa-install-nudge-title">
        <span class="pwa-install-nudge-icon" aria-hidden="true"><img src="<?= e($appIconWebUrl !== '' ? $appIconWebUrl : '/?page=app_icon_default&size=192') ?>" alt=""></span>
        <span class="pwa-install-nudge-copy"><strong id="pwa-install-nudge-title"><?= e(t('pwa.nudge_title')) ?></strong><small data-pwa-nudge-hint><?= e(t('pwa.nudge_hint')) ?></small></span>
        <button class="pwa-install-nudge-action" type="button" data-pwa-nudge-install><?= e(t('pwa.nudge_action')) ?></button>
        <button class="pwa-install-nudge-close" type="button" data-pwa-nudge-close aria-label="<?= e(t('pwa.nudge_close')) ?>">&times;</button>
    </aside>
    <button type="button" class="to-top-btn" data-to-top hidden aria-label="<?= e(t('common.back_to_top')) ?>">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V5"/><path d="m5 12 7-7 7 7"/></svg>
    </button>
<?php endif; ?>

<?php
// Unlock celebrations. Drained here (not in a page view) so a quest completed on
// any page is celebrated on the very next render, exactly once.
$celebrations = [];
$celebrationPdo = db_current();
if ($loggedIn && $celebrationPdo instanceof PDO && isset($currentUser['id'])) {
    $celebrations = celebrations_drain($celebrationPdo, (int) $currentUser['id']);
}
?>
<?php if ($celebrations !== []): ?>
    <div class="celebration-stack" data-celebrations aria-live="polite">
        <?php foreach ($celebrations as $celebration): ?>
            <div class="celebration-toast celebration-<?= e((string) $celebration['kind']) ?>" role="status">
                <span class="celebration-spark" aria-hidden="true">&#127881;</span>
                <span class="celebration-body">
                    <strong><?= e(t('celebration.' . $celebration['kind'])) ?></strong>
                    <span><?= e((string) $celebration['label']) ?></span>
                </span>
                <?php if ((int) $celebration['xp'] > 0): ?>
                    <span class="celebration-xp">+<?= (int) $celebration['xp'] ?> XP</span>
                <?php endif; ?>
                <button type="button" class="celebration-close" data-celebration-close aria-label="<?= e(t('celebration.dismiss')) ?>">&times;</button>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script src="<?= e($mainJsAssetUrl) ?>"></script>
</body>
</html>
