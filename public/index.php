<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$page = $_GET['page'] ?? null;
if ($page === null) {
    $pathPage = trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/');
    if (in_array($pathPage, ['dashboard', 'analytics', 'entries', 'gallery', 'table', 'week_editor', 'profile', 'settings', 'team', 'team_settings', 'admin', 'metric', 'penalties', 'comparison_detail', 'strikes_detail', 'notifications', 'challenges', 'login', 'login_background'], true)) {
        $page = $pathPage;
    }
}
$currentUser = current_user($pdo);
set_current_locale(resolve_locale($config, $currentUser));

if ($page === null || $page === '') {
    $page = $currentUser !== null ? 'dashboard' : 'login';
}

if ($page === 'users') {
    $page = 'admin';
}

if ($currentUser !== null && !in_array($page, ['app_icon', 'login_background', 'media', 'media_thumb', 'api_meal_calendar', 'api_gallery_recent'], true)) {
    run_system_backup_scheduler($pdo, $config, (int) ($currentUser['id'] ?? 0));
    notion_run_scheduler($pdo, $config, (int) ($currentUser['id'] ?? 0));
    telegram_run_scheduler($pdo, $config);
}

function send_private_cached_file_response(string $filePath, string $mime, int $maxAge = 604800, bool $immutable = false): void
{
    $mtime = @filemtime($filePath) ?: time();
    $filesize = filesize($filePath);
    $lastModified = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';
    $etag = '"' . sha1(str_replace('\\', '/', $filePath) . '|' . (string) $mtime . '|' . (string) ($filesize === false ? '' : $filesize)) . '"';
    $cacheControl = 'private, max-age=' . max(0, $maxAge);
    if ($immutable) {
        $cacheControl .= ', immutable';
    }

    $ifNoneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
    $notModified = false;
    if ($ifNoneMatch !== '') {
        $clientEtags = array_map('trim', explode(',', $ifNoneMatch));
        $notModified = $ifNoneMatch === '*' || in_array($etag, $clientEtags, true);
    } else {
        $ifModifiedSince = trim((string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));
        $clientMtime = $ifModifiedSince !== '' ? strtotime($ifModifiedSince) : false;
        $notModified = $clientMtime !== false && $clientMtime >= $mtime;
    }

    header('Cache-Control: ' . $cacheControl);
    header('ETag: ' . $etag);
    header('Last-Modified: ' . $lastModified);
    header('X-Content-Type-Options: nosniff');

    if ($notModified) {
        http_response_code(304);
        exit;
    }

    header('Content-Type: ' . $mime);
    if ($filesize !== false) {
        header('Content-Length: ' . (string) $filesize);
    }
    readfile($filePath);
    exit;
}

if ($page === 'set_locale') {
    if (!is_post()) {
        redirect('/');
    }

    if (!csrf_verify()) {
        flash_set('error', t('flash.csrf'));
        redirect(safe_redirect_target($_POST['redirect_to'] ?? '/'));
    }

    $locale = persist_session_locale((string) ($_POST['locale'] ?? ''));
    set_current_locale($locale);

    if ($currentUser !== null) {
        $beforeLocale = (string) ($currentUser['locale'] ?? 'en');
        db_execute(
            $pdo,
            'UPDATE users SET locale = :locale, updated_at = :updated_at WHERE id = :id',
            [
                ':locale' => $locale,
                ':updated_at' => now_iso(),
                ':id' => (int) $currentUser['id'],
            ]
        );
        if ($beforeLocale !== $locale) {
            audit_log(
                $pdo,
                (int) $currentUser['id'],
                'locale_changed',
                'user',
                (string) $currentUser['id'],
                'Language changed.',
                ['locale' => $beforeLocale],
                ['locale' => $locale]
            );
        }
    }

    if (!empty($_POST['async']) || strpos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false) {
        json_response(['ok' => true, 'locale' => $locale]);
    }

    redirect(safe_redirect_target($_POST['redirect_to'] ?? '/'));
}

if ($page === 'set_theme') {
    if (!is_post()) {
        redirect('/');
    }

    if ($currentUser === null) {
        json_response(['ok' => false], 401);
    }

    if (!csrf_verify()) {
        json_response(['ok' => false, 'error' => 'csrf'], 403);
    }

    $theme = (string) ($_POST['theme_mode'] ?? '');
    if (!in_array($theme, ['light', 'dark'], true)) {
        json_response(['ok' => false, 'error' => 'invalid'], 422);
    }

    db_execute(
        $pdo,
        'UPDATE users SET theme_mode = :theme_mode, updated_at = :updated_at WHERE id = :id',
        [
            ':theme_mode' => $theme,
            ':updated_at' => now_iso(),
            ':id' => (int) $currentUser['id'],
        ]
    );

    json_response(['ok' => true, 'theme_mode' => $theme]);
}

if ($page === 'notion_oauth_callback') {
    if ($currentUser === null || !is_admin($currentUser)) {
        flash_set('error', t('flash.no_permission'));
        redirect('/?page=login');
    }
    $oauthError = trim((string) ($_GET['error'] ?? ''));
    $oauthCode = trim((string) ($_GET['code'] ?? ''));
    $oauthState = (string) ($_GET['state'] ?? '');
    $expectedState = (string) ($_SESSION['notion_oauth_state'] ?? '');
    unset($_SESSION['notion_oauth_state']);

    if ($oauthError !== '') {
        flash_set('error', trim(t('flash.notion_oauth_failed') . ' ' . $oauthError));
        redirect('/?page=admin&section=app');
    }
    if ($oauthCode === '' || $expectedState === '' || !hash_equals($expectedState, $oauthState)) {
        flash_set('error', t('flash.notion_oauth_state'));
        redirect('/?page=admin&section=app');
    }

    $notionSettings = notion_settings($pdo);
    $exchange = notion_oauth_exchange_code($notionSettings, $oauthCode, notion_oauth_redirect_uri($notionSettings));
    if ($exchange['ok']) {
        set_app_setting($pdo, 'notion_token', $exchange['access_token'], (int) $currentUser['id']);
        set_app_setting($pdo, 'notion_workspace_name', $exchange['workspace_name'], (int) $currentUser['id']);
        set_app_setting($pdo, 'notion_enabled', '1', (int) $currentUser['id']);
        flash_set('success', t('flash.notion_oauth_connected', ['workspace' => $exchange['workspace_name']]));
    } else {
        flash_set('error', trim(t('flash.notion_oauth_failed') . ' ' . (string) $exchange['error']));
    }
    redirect('/?page=admin&section=app');
}

if ($page === 'logout') {
    $logoutMessage = t('flash.logout');
    set_remember_me_cookie($config, false);
    logout_user();
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    flash_set('success', $logoutMessage);
    redirect('/?page=login');
}

if ($page === 'app_icon') {
    $appIconPath = trim((string) (app_setting($pdo, 'app_icon_path', '') ?? ''));
    $resolvedPath = resolve_media_storage_path($config, $appIconPath);
    if ($resolvedPath === null || !is_file($resolvedPath)) {
        http_response_code(404);
        echo e(t('flash.not_found'));
        exit;
    }

    $mime = detect_media_mime_type($resolvedPath);

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($resolvedPath));
    header('Cache-Control: public, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    readfile($resolvedPath);
    exit;
}

if ($page === 'login_background') {
    $backgroundPath = trim((string) (app_setting($pdo, 'login_background_path', '') ?? ''));
    if ($backgroundPath === '' || !is_valid_login_background_path($config, $backgroundPath)) {
        http_response_code(404);
        echo e(t('flash.not_found'));
        exit;
    }
    $resolvedPath = resolve_media_storage_path($config, $backgroundPath);
    if ($resolvedPath === null || !is_file($resolvedPath)) {
        http_response_code(404);
        echo e(t('flash.not_found'));
        exit;
    }

    $mime = detect_media_mime_type($resolvedPath);
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($resolvedPath));
    header('Cache-Control: public, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    readfile($resolvedPath);
    exit;
}

if ($page === 'api_save_row') {
    $currentUser = require_login($pdo);

    if (!is_post()) {
        json_response(['ok' => false, 'message' => t('flash.method_not_allowed')], 405);
    }

    $raw = file_get_contents('php://input');
    if ($raw === false) {
        json_response(['ok' => false, 'message' => t('flash.invalid_body')], 400);
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        json_response(['ok' => false, 'message' => t('flash.invalid_json')], 400);
    }

    if (!isset($json['csrf_token']) || !is_string($json['csrf_token']) || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $json['csrf_token'])) {
        json_response(['ok' => false, 'message' => t('flash.csrf')], 419);
    }

    $userId = (int) ($json['user_id'] ?? 0);
    if (!is_admin($currentUser) && $userId !== (int) $currentUser['id']) {
        json_response(['ok' => false, 'message' => t('flash.no_permission')], 403);
    }
    if ($userId <= 0) {
        json_response(['ok' => false, 'message' => t('flash.invalid_user')], 422);
    }

    $habitPayload = is_array($json['habits'] ?? null) ? (array) $json['habits'] : [];
    $hasWorkoutsPayload = is_array($json['workouts'] ?? null);
    $workoutsPayload = [];
    if ($hasWorkoutsPayload) {
        foreach (array_values((array) ($json['workouts'] ?? [])) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $workoutsPayload[] = [
                'workout_type_id' => $row['workout_type_id'] ?? null,
                'workout_type' => trim((string) ($row['workout_type'] ?? '')),
            ];
        }
    }
    $derivedExtraWorkout = 0;
    $derivedWorkoutDone = (int) ($json['workout_done'] ?? 0) === 1 ? 1 : 0;
    if ($hasWorkoutsPayload) {
        $derivedWorkoutDone = ($derivedWorkoutDone === 1 || count($workoutsPayload) > 0) ? 1 : 0;
        $derivedExtraWorkout = ((int) ($json['extra_workout'] ?? 0) === 1 || count($workoutsPayload) > 1) ? 1 : 0;
    }

    $payload = [
        'user_id' => $userId,
        'log_date' => to_date((string) ($json['log_date'] ?? null)),
        'log_time' => normalize_log_time($json['log_time'] ?? '', (new DateTimeImmutable('now'))->format('H:i')),
        'steps' => max(0, (int) ($json['steps'] ?? 0)),
        'workout_done' => $hasWorkoutsPayload ? $derivedWorkoutDone : ((int) ($json['workout_done'] ?? 0) === 1 ? 1 : 0),
        'workout_type_id' => !empty($json['workout_type_id']) ? (int) $json['workout_type_id'] : null,
        'workout_type' => trim((string) ($json['workout_type'] ?? '')),
        'junk_food' => (int) ($json['junk_food'] ?? 0) === 1 ? 1 : 0,
        'extra_workout' => $hasWorkoutsPayload ? $derivedExtraWorkout : ((int) ($json['extra_workout'] ?? 0) === 1 ? 1 : 0),
        'distance_km' => ($json['distance_km'] ?? '') !== '' ? (float) $json['distance_km'] : null,
        'training_calories_burned' => ($json['training_calories_burned'] ?? '') !== '' ? (float) $json['training_calories_burned'] : null,
        'weight' => ($json['weight'] ?? '') !== '' ? (float) $json['weight'] : null,
        'notes' => trim((string) ($json['notes'] ?? '')),
        'step_exception_reason' => trim((string) ($json['step_exception_reason'] ?? '')),
        'distance_exception_reason' => trim((string) ($json['distance_exception_reason'] ?? '')),
        'workout_exception_reason' => trim((string) ($json['workout_exception_reason'] ?? '')),
        'resend_requests' => (int) ($json['resend_requests'] ?? 0) === 1 ? 1 : 0,
        'morning_walk' => !empty($habitPayload['morning_walk']) || (int) ($json['morning_walk'] ?? 0) === 1 ? 1 : 0,
        'journaling' => !empty($habitPayload['journaling']) || (int) ($json['journaling'] ?? 0) === 1 ? 1 : 0,
        'evening_chores' => !empty($habitPayload['evening_chores']) || (int) ($json['evening_chores'] ?? 0) === 1 ? 1 : 0,
        'reading' => !empty($habitPayload['reading']) || (int) ($json['reading'] ?? 0) === 1 ? 1 : 0,
        'habits' => $habitPayload,
    ];
    if ($hasWorkoutsPayload) {
        $payload['workouts'] = $workoutsPayload;
    }

    try {
        $before = fetch_log($pdo, $userId, (string) $payload['log_date']);
        upsert_daily_log_and_sync_approvals($pdo, $payload, (int) $currentUser['id']);
        $after = fetch_log($pdo, $userId, (string) $payload['log_date']);
        audit_log(
            $pdo,
            (int) $currentUser['id'],
            'daily_log_saved',
            'daily_log',
            $userId . ':' . (string) $payload['log_date'],
            'Daily log saved from week editor.',
            audit_snapshot($before),
            audit_snapshot($after)
        );
        $settings = challenge_settings($pdo, $config);
        auto_complete_user_goals(
            $pdo,
            $userId,
            (string) $settings['challenge_start'],
            (string) $settings['challenge_end'],
            (int) $currentUser['id']
        );
        auto_complete_team_goals_for_user(
            $pdo,
            $userId,
            (string) $settings['challenge_start'],
            (string) $settings['challenge_end'],
            (int) $currentUser['id']
        );
    } catch (Throwable $e) {
        json_response(['ok' => false, 'message' => t('flash.save_failed')], 500);
    }

    json_response(['ok' => true]);
}

if ($page === 'api_create_habit') {
    $currentUser = require_login($pdo);

    if (!is_post()) {
        json_response(['ok' => false, 'message' => t('flash.method_not_allowed')], 405);
    }

    $raw = file_get_contents('php://input');
    if ($raw === false) {
        json_response(['ok' => false, 'message' => t('flash.invalid_body')], 400);
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        json_response(['ok' => false, 'message' => t('flash.invalid_json')], 400);
    }

    if (!isset($json['csrf_token']) || !is_string($json['csrf_token']) || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $json['csrf_token'])) {
        json_response(['ok' => false, 'message' => t('flash.csrf')], 419);
    }

    $label = trim((string) ($json['label'] ?? ''));
    if ($label === '') {
        json_response(['ok' => false, 'message' => t('table.custom_habit_required')], 422);
    }

    try {
        $habit = create_custom_habit_from_label($pdo, $label, (int) $currentUser['id']);
        if ($habit === null) {
            json_response(['ok' => false, 'message' => t('table.custom_habit_error')], 422);
        }

        json_response([
            'ok' => true,
            'habit' => [
                'id' => (int) ($habit['id'] ?? 0),
                'code' => (string) ($habit['code'] ?? ''),
                'label' => (string) ($habit['label'] ?? ''),
            ],
        ]);
    } catch (Throwable) {
        json_response(['ok' => false, 'message' => t('table.custom_habit_error')], 500);
    }
}

if ($page === 'login') {
    if ($currentUser !== null) {
        redirect('/?page=dashboard');
    }

    if (is_post()) {
        if (!csrf_verify()) {
            flash_set('error', t('flash.csrf'));
            redirect('/?page=login');
        }

        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $ipAddress = request_ip_address();

        if (login_attempt_is_blocked($pdo, $username, $ipAddress, 5, 15)) {
            flash_set('error', t('flash.login_blocked'));
            redirect('/?page=login');
        }

        if (login_user($pdo, $username, $password)) {
            clear_login_attempts($pdo, $username, $ipAddress);
            $rememberMe = bool_from_form('remember_me') === 1;
            set_remember_me_cookie($config, $rememberMe);
            sync_session_cookie_lifetime($config, $rememberMe);
            $currentUser = current_user($pdo);
            set_current_locale(resolve_locale($config, $currentUser));
            flash_set('success', t('flash.welcome'));
            redirect('/?page=dashboard');
        }

        register_failed_login_attempt($pdo, $username, $ipAddress);
        flash_set('error', t('flash.bad_credentials'));
        redirect('/?page=login');
    }

    $appIconSetting = db_fetch_one(
        $pdo,
        'SELECT setting_value, updated_at FROM app_settings WHERE setting_key = :key',
        [':key' => 'app_icon_path']
    );
    $appIconPath = $appIconSetting !== null ? trim((string) ($appIconSetting['setting_value'] ?? '')) : '';
    $appIconVersion = null;
    if ($appIconSetting !== null && !empty($appIconSetting['updated_at'])) {
        $timestamp = strtotime((string) $appIconSetting['updated_at']);
        if ($timestamp !== false) {
            $appIconVersion = (string) $timestamp;
        }
    }
    $loginAppIconUrl = '';
    if ($appIconPath !== '' && resolve_media_storage_path($config, $appIconPath) !== null) {
        $loginAppIconUrl = with_cache_buster('/?page=app_icon', $appIconVersion);
    }

    $backgroundSetting = db_fetch_one(
        $pdo,
        'SELECT setting_value, updated_at FROM app_settings WHERE setting_key = :key',
        [':key' => 'login_background_path']
    );
    $loginBackgroundPath = $backgroundSetting !== null ? trim((string) ($backgroundSetting['setting_value'] ?? '')) : '';
    $loginBackgroundVersion = null;
    if ($backgroundSetting !== null && !empty($backgroundSetting['updated_at'])) {
        $timestamp = strtotime((string) $backgroundSetting['updated_at']);
        if ($timestamp !== false) {
            $loginBackgroundVersion = (string) $timestamp;
        }
    }
    $loginBackgroundUrl = '';
    if ($loginBackgroundPath !== '' && is_valid_login_background_path($config, $loginBackgroundPath)) {
        $loginBackgroundUrl = with_cache_buster('/?page=login_background', $loginBackgroundVersion);
    }
    $loginRememberDefault = remember_me_cookie_is_enabled($config);

    render_view('login', [
        'title' => t('login.submit'),
        'currentPage' => 'login',
        'currentUser' => null,
        'loginAppIconUrl' => $loginAppIconUrl,
        'loginBackgroundUrl' => $loginBackgroundUrl,
        'loginRememberDefault' => $loginRememberDefault,
        'config' => $config,
    ]);
}

if ($page === 'media') {
    $mediaPath = trim((string) ($_GET['path'] ?? ''));
    $mediaUser = current_user($pdo);
    if ($mediaUser === null) {
        media_debug_log('media_route', [
            'stored_value' => $mediaPath,
            'helper_input' => $mediaPath,
            'normalized_value' => (string) (normalize_media_reference($mediaPath)['normalized'] ?? ''),
            'final_url' => '',
            'result' => 'no_auth',
            'request_uri' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
        ]);
        flash_set('error', t('auth.login_required'));
        redirect('/?page=login');
    }

    $normalizedMedia = normalize_media_reference($mediaPath);
    $normalizedMediaKind = (string) ($normalizedMedia['kind'] ?? '');
    if ($normalizedMediaKind !== 'media') {
        media_debug_log('media_route', [
            'stored_value' => $mediaPath,
            'helper_input' => $mediaPath,
            'normalized_value' => (string) ($normalizedMedia['normalized'] ?? ''),
            'normalized_kind' => $normalizedMediaKind,
            'final_url' => '',
            'result' => 'path_invalid',
            'request_uri' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
            'user_id' => (int) ($mediaUser['id'] ?? 0),
        ]);
        http_response_code(404);
        echo e(t('flash.not_found'));
        exit;
    }

    $resolvedPath = resolve_media_storage_path($config, $mediaPath);
    if ($resolvedPath === null || !is_file($resolvedPath)) {
        media_debug_log('media_route', [
            'stored_value' => $mediaPath,
            'helper_input' => $mediaPath,
            'normalized_value' => (string) ($normalizedMedia['normalized'] ?? ''),
            'normalized_kind' => $normalizedMediaKind,
            'resolved_path' => (string) $resolvedPath,
            'final_url' => '',
            'result' => 'file_not_found',
            'request_uri' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
            'user_id' => (int) ($mediaUser['id'] ?? 0),
        ]);
        http_response_code(404);
        echo e(t('flash.not_found'));
        exit;
    }

    $mime = detect_media_mime_type($resolvedPath);
    $filesize = filesize($resolvedPath);
    media_debug_log('media_route', [
        'stored_value' => $mediaPath,
        'helper_input' => $mediaPath,
        'normalized_value' => (string) ($normalizedMedia['normalized'] ?? ''),
        'normalized_kind' => $normalizedMediaKind,
        'resolved_path' => $resolvedPath,
        'final_url' => '/?page=media&path=' . rawurlencode((string) ($normalizedMedia['normalized'] ?? '')),
        'result' => 'served_ok',
        'mime' => $mime,
        'bytes' => $filesize === false ? null : (int) $filesize,
        'request_uri' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
        'user_id' => (int) ($mediaUser['id'] ?? 0),
    ]);

    header('Content-Type: ' . $mime);
    if ($filesize !== false) {
        header('Content-Length: ' . (string) $filesize);
    }
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    readfile($resolvedPath);
    exit;
}

if ($page === 'media_thumb') {
    $mediaPath = trim((string) ($_GET['path'] ?? ''));
    $mediaUser = current_user($pdo);
    if ($mediaUser === null) {
        flash_set('error', t('auth.login_required'));
        redirect('/?page=login');
    }

    $normalizedMedia = normalize_media_reference($mediaPath);
    if (($normalizedMedia['kind'] ?? '') !== 'media') {
        http_response_code(404);
        echo e(t('flash.not_found'));
        exit;
    }

    $width = max(80, min(1200, (int) ($_GET['w'] ?? 360)));
    $thumb = null;
    try {
        $thumb = generate_media_thumbnail($config, (string) ($normalizedMedia['normalized'] ?? ''), $width);
    } catch (Throwable) {
        $thumb = null;
    }

    if (!is_array($thumb) || !is_file((string) ($thumb['path'] ?? ''))) {
        $fallbackPath = resolve_media_storage_path($config, (string) ($normalizedMedia['normalized'] ?? ''));
        if ($fallbackPath === null || !is_file($fallbackPath)) {
            http_response_code(404);
            echo e(t('flash.not_found'));
            exit;
        }

        $fallbackMime = detect_media_mime_type($fallbackPath);
        send_private_cached_file_response($fallbackPath, $fallbackMime, 604800, trim((string) ($_GET['v'] ?? '')) !== '');
    }

    $thumbPath = (string) $thumb['path'];
    $mime = (string) ($thumb['mime'] ?? 'image/jpeg');
    send_private_cached_file_response($thumbPath, $mime, 604800, trim((string) ($_GET['v'] ?? '')) !== '');
}

$currentUser = require_login($pdo);

if ($page === 'api_gallery_recent') {
    $selectedUserId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : (int) $currentUser['id'];
    if (is_admin($currentUser) && $selectedUserId < 0) {
        $selectedUserId = 0;
    }
    if (!is_admin($currentUser) && $selectedUserId !== (int) $currentUser['id']) {
        $selectedUserId = (int) $currentUser['id'];
    }
    if (!is_admin($currentUser) && $selectedUserId <= 0) {
        $selectedUserId = (int) $currentUser['id'];
    }
    if ($selectedUserId > 0) {
        $targetUser = db_fetch_one($pdo, 'SELECT id FROM users WHERE id = :id AND active = 1', [':id' => $selectedUserId]);
        if ($targetUser === null) {
            json_response(['ok' => false, 'message' => t('flash.invalid_user')], 404);
        }
    }
    $galleryUserFilter = $selectedUserId > 0 ? $selectedUserId : null;
    $galleryPage = max(1, (int) ($_GET['gallery_page'] ?? 1));
    $galleryPerPage = max(24, min(240, (int) ($_GET['gallery_per_page'] ?? 96)));
    $galleryOffset = ($galleryPage - 1) * $galleryPerPage;

    $rows = fetch_gallery_photos($pdo, $galleryPerPage + 1, $galleryOffset, $galleryUserFilter);
    $hasMore = count($rows) > $galleryPerPage;
    if ($hasMore) {
        array_pop($rows);
    }

    $previousMonth = '';
    if ($galleryOffset > 0) {
        $previousRows = fetch_gallery_photos($pdo, 1, $galleryOffset - 1, $galleryUserFilter);
        if ($previousRows !== []) {
            $previousMonth = substr((string) ($previousRows[0]['log_date'] ?? ''), 0, 7);
        }
    }

    $items = [];
    foreach ($rows as $photo) {
        $photoId = (int) ($photo['id'] ?? 0);
        $photoPath = (string) ($photo['file_path'] ?? '');
        $date = (string) ($photo['log_date'] ?? '');
        $monthKey = substr($date, 0, 7);
        $isMonthStart = $monthKey !== '' && $monthKey !== $previousMonth;
        if ($monthKey !== '') {
            $previousMonth = $monthKey;
        }

        $items[] = [
            'id' => $photoId,
            'href' => '/?page=photo&photo_id=' . $photoId,
            'date_label' => format_date_eu($date),
            'month_label' => localized_month_label($date),
            'month_start' => $isMonthStart,
            'thumb_url' => media_thumbnail_url($photoPath, 400),
            'thumb_srcset' => media_thumbnail_srcset($photoPath, [200, 400, 800]),
            'thumb_sizes' => '(max-width: 700px) 33vw, (max-width: 1100px) 20vw, 170px',
        ];
    }

    json_response([
        'ok' => true,
        'page' => $galleryPage,
        'per_page' => $galleryPerPage,
        'has_more' => $hasMore,
        'next_page' => $hasMore ? $galleryPage + 1 : null,
        'user_id' => $selectedUserId,
        'items' => $items,
        'labels' => [
            'no_photo' => t('entries.no_photo'),
            'photo' => t('common.photo'),
        ],
    ]);
}

if ($page === 'api_meal_calendar') {
    $calendarView = (string) ($_GET['calendar_view'] ?? 'month');
    if (!in_array($calendarView, ['month', 'week', 'day'], true)) {
        $calendarView = 'month';
    }
    $includePhotos = (string) ($_GET['include_photos'] ?? '1') !== '0';
    $selectedDate = calendar_date_from_request($_GET, $calendarView);

    $selectedUserId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : (int) $currentUser['id'];
    if (is_admin($currentUser) && $selectedUserId < 0) {
        $selectedUserId = 0;
    }
    if (!is_admin($currentUser) && $selectedUserId !== (int) $currentUser['id']) {
        $selectedUserId = (int) $currentUser['id'];
    }
    if (!is_admin($currentUser) && $selectedUserId <= 0) {
        $selectedUserId = (int) $currentUser['id'];
    }

    if ($selectedUserId > 0) {
        $targetUser = db_fetch_one($pdo, 'SELECT id FROM users WHERE id = :id AND active = 1', [':id' => $selectedUserId]);
        if ($targetUser === null) {
            json_response(['ok' => false, 'message' => t('flash.invalid_user')], 404);
        }
    }
    $calendarUserFilter = $selectedUserId > 0 ? $selectedUserId : null;

    $categoryLabels = [
        'breakfast' => t('entries.breakfast'),
        'lunch' => t('entries.lunch'),
        'dinner' => t('entries.dinner'),
        'other' => t('common.other'),
        'meal' => t('entries.lunch'),
        'workout' => t('entries.workout'),
    ];
    $nutritionSummary = static function (array $photo): string {
        $parts = [];
        $calories = $photo['calories'] ?? null;
        if ($calories !== null && $calories !== '') {
            $parts[] = rtrim(rtrim(number_format((float) $calories, 1, '.', ''), '0'), '.') . ' kcal';
        }
        $protein = $photo['protein_g'] ?? null;
        if ($protein !== null && $protein !== '') {
            $parts[] = 'P ' . rtrim(rtrim(number_format((float) $protein, 1, '.', ''), '0'), '.') . 'g';
        }
        $carbs = $photo['carbs_g'] ?? null;
        if ($carbs !== null && $carbs !== '') {
            $parts[] = 'C ' . rtrim(rtrim(number_format((float) $carbs, 1, '.', ''), '0'), '.') . 'g';
        }
        $fat = $photo['fat_g'] ?? null;
        if ($fat !== null && $fat !== '') {
            $parts[] = 'F ' . rtrim(rtrim(number_format((float) $fat, 1, '.', ''), '0'), '.') . 'g';
        }

        return implode(' | ', $parts);
    };

    $mealCalendar = fetch_meal_calendar($pdo, $selectedDate, $calendarUserFilter, $calendarView);
    $photoPreviewPayload = static function (array $photo) use ($selectedDate): array {
        $photoId = (int) ($photo['id'] ?? 0);

        return [
            'id' => $photoId,
            'date' => (string) ($photo['log_date'] ?? $selectedDate),
            'date_label' => format_date_eu((string) ($photo['log_date'] ?? $selectedDate)),
            'photo_url' => media_url((string) ($photo['file_path'] ?? '')),
            'thumb_url' => media_thumbnail_url((string) ($photo['file_path'] ?? ''), 360),
            'thumb_srcset' => media_thumbnail_srcset((string) ($photo['file_path'] ?? ''), [200, 400, 800]),
            'thumb_sizes' => '(max-width: 600px) 24vw, 140px',
            'photo_href' => '/?page=photo&photo_id=' . $photoId,
        ];
    };
    $days = [];
    foreach ($mealCalendar as $dateKey => $day) {
        $photoCount = (int) ($day['count'] ?? 0);
        $preview = is_array($day['preview'] ?? null) ? (array) $day['preview'] : null;
        $previewPhotoId = $preview !== null ? (int) ($preview['id'] ?? 0) : 0;
        $previewPhotos = [];
        foreach (array_slice(array_values((array) ($day['photos'] ?? [])), 0, 3) as $previewPhoto) {
            if (is_array($previewPhoto)) {
                $previewPayload = $photoPreviewPayload($previewPhoto);
                if ((string) ($previewPayload['thumb_url'] ?? '') !== '' || (string) ($previewPayload['photo_url'] ?? '') !== '') {
                    $previewPhotos[] = $previewPayload;
                }
            }
        }
        $days[] = [
            'date' => (string) $dateKey,
            'date_label' => format_date_eu((string) $dateKey),
            'day_number' => (new DateTimeImmutable((string) $dateKey))->format('j'),
            'date_short' => (new DateTimeImmutable((string) $dateKey))->format('d/m'),
            'has_log' => $photoCount > 0,
            'count' => $photoCount,
            'count_label' => $photoCount . ' ' . ($photoCount === 1 ? t('entries.photo_singular') : t('entries.photo_plural')),
            'href' => $previewPhotoId > 0
                ? '/?page=photo&photo_id=' . $previewPhotoId
                : ($selectedUserId === (int) $currentUser['id']
                    ? '/?page=entries&mode=meal&date=' . rawurlencode((string) $dateKey)
                    : '/?' . http_build_query([
                        'page' => 'entries',
                        'mode' => 'calendar',
                        'user_id' => $selectedUserId,
                        'calendar_view' => $calendarView,
                        'date' => (string) $dateKey,
                    ])),
            'preview_url' => $preview !== null ? media_url((string) ($preview['file_path'] ?? '')) : '',
            'thumb_url' => $preview !== null ? media_thumbnail_url((string) ($preview['file_path'] ?? ''), 360) : '',
            'thumb_srcset' => $preview !== null ? media_thumbnail_srcset((string) ($preview['file_path'] ?? ''), [200, 400, 800]) : '',
            'thumb_sizes' => '(max-width: 600px) 24vw, 140px',
            'preview_photos' => $previewPhotos,
        ];
    }

    $selectedDayData = is_array($mealCalendar[$selectedDate] ?? null) ? (array) $mealCalendar[$selectedDate] : [];
    $selectedPhotos = [];
    $periodRows = [];
    if ($includePhotos) {
        foreach ($mealCalendar as $day) {
            foreach (array_values((array) ($day['photos'] ?? [])) as $photo) {
                if (is_array($photo)) {
                    $periodRows[] = $photo;
                }
            }
        }
    }
    usort(
        $periodRows,
        static function (array $left, array $right): int {
            $dateCompare = strcmp((string) ($right['log_date'] ?? ''), (string) ($left['log_date'] ?? ''));
            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            return strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? ''));
        }
    );
    $periodPhotos = [];
    $photoPayload = static function (array $photo) use ($selectedDate, $categoryLabels, $nutritionSummary): array {
        $photoId = (int) ($photo['id'] ?? 0);
        $category = (string) ($photo['category'] ?? 'other');

        return [
            'id' => $photoId,
            'display_name' => (string) ($photo['display_name'] ?? ''),
            'date' => (string) ($photo['log_date'] ?? $selectedDate),
            'date_label' => format_date_eu((string) ($photo['log_date'] ?? $selectedDate)),
            'category_label' => (string) ($categoryLabels[$category] ?? $category),
            'caption' => (string) ($photo['caption'] ?? ''),
            'nutrition' => $nutritionSummary($photo),
            'photo_url' => media_url((string) ($photo['file_path'] ?? '')),
            'thumb_url' => media_thumbnail_url((string) ($photo['file_path'] ?? ''), 360),
            'thumb_srcset' => media_thumbnail_srcset((string) ($photo['file_path'] ?? ''), [200, 400, 800]),
            'thumb_sizes' => '(max-width: 600px) 33vw, 180px',
            'photo_href' => '/?page=photo&photo_id=' . $photoId,
        ];
    };
    if ($includePhotos) {
        foreach ($periodRows as $photo) {
            $periodPhotos[] = $photoPayload($photo);
        }
        foreach (array_values((array) ($selectedDayData['photos'] ?? [])) as $photo) {
            if (!is_array($photo)) {
                continue;
            }
            $selectedPhotos[] = $photoPayload($photo);
        }
    }

    json_response([
        'ok' => true,
        'date' => $selectedDate,
        'calendar_month' => substr($selectedDate, 0, 7),
        'calendar_week' => date_to_iso_week($selectedDate),
        'calendar_view' => $calendarView,
        'period_label' => $calendarView === 'month'
            ? localized_month_label($selectedDate)
            : ($calendarView === 'week' ? date_to_iso_week($selectedDate) : format_date_eu($selectedDate)),
        'user_id' => $selectedUserId,
        'days' => $days,
        'selected_photos' => $selectedPhotos,
        'period_photos' => $periodPhotos,
        'labels' => [
            'no_photo' => t('entries.no_photo'),
            'no_photos' => t('entries.no_photos'),
            'photo' => t('common.photo'),
            'photo_singular' => t('entries.photo_singular'),
            'photo_plural' => t('entries.photo_plural'),
            'recent_photos' => t('entries.recent_photos'),
            'date' => t('common.date'),
            'empty_period_title' => t('gallery.empty_period_title'),
            'empty_period_body' => t('gallery.empty_period_body'),
            'view_latest' => t('gallery.view_latest'),
        ],
    ]);
}

if ($page === 'entries') {
    $entryMode = (string) ($_GET['mode'] ?? 'data');
    if (!in_array($entryMode, ['data', 'meal', 'calendar'], true)) {
        $entryMode = 'data';
    }

    if (is_post()) {
        if (!csrf_verify()) {
            flash_set('error', t('flash.csrf'));
            redirect('/?page=entries&mode=' . rawurlencode($entryMode));
        }

        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'save_log') {
            $userId = (int) $currentUser['id'];

            $date = to_date($_POST['log_date'] ?? null);

            try {
                $existingLogForHabits = fetch_log($pdo, $userId, $date);
                $habitValues = [];
                foreach (list_habit_definitions($pdo, false) as $habit) {
                    $code = (string) $habit['code'];
                    if ($code === 'morning_walk' && !isset($_POST['habit'][$code])) {
                        $habitValues[$code] = !empty($existingLogForHabits['habits'][$code]) && (int) ($existingLogForHabits['habits'][$code]['value'] ?? 0) === 1 ? 1 : 0;
                        continue;
                    }
                    $habitValues[$code] = isset($_POST['habit'][$code]) && $_POST['habit'][$code] === '1' ? 1 : 0;
                }

                $rawWorkouts = [];
                $hasWorkoutPayload = isset($_POST['workouts']) || isset($_POST['workout_type_id']) || isset($_POST['workout_type']);
                $isNewWorkoutForm = (string) ($_POST['workout_form_mode'] ?? '') === '1';
                if (bool_from_form('workout_enabled') === 1 || (!$isNewWorkoutForm && $hasWorkoutPayload)) {
                    if (isset($_POST['workouts']) && is_array($_POST['workouts'])) {
                        foreach (array_values((array) $_POST['workouts']) as $workoutRow) {
                            if (!is_array($workoutRow)) {
                                continue;
                            }
                            $rawWorkouts[] = [
                                'workout_type_id' => $workoutRow['workout_type_id'] ?? null,
                                'workout_type' => $workoutRow['workout_type'] ?? '',
                                'fields' => is_array($workoutRow['fields'] ?? null) ? (array) $workoutRow['fields'] : [],
                            ];
                        }
                    } else {
                        $workoutTypeIds = is_array($_POST['workout_type_id'] ?? null) ? array_values((array) $_POST['workout_type_id']) : [];
                        $workoutTypes = is_array($_POST['workout_type'] ?? null) ? array_values((array) $_POST['workout_type']) : [];
                        if ($workoutTypeIds === [] && isset($_POST['workout_type_id']) && !is_array($_POST['workout_type_id'])) {
                            $workoutTypeIds[] = (string) $_POST['workout_type_id'];
                        }
                        if ($workoutTypes === [] && isset($_POST['workout_type']) && !is_array($_POST['workout_type'])) {
                            $workoutTypes[] = (string) $_POST['workout_type'];
                        }
                        $workoutRowCount = max(count($workoutTypeIds), count($workoutTypes));
                        for ($i = 0; $i < $workoutRowCount; $i++) {
                            $rawWorkouts[] = [
                                'workout_type_id' => $workoutTypeIds[$i] ?? null,
                                'workout_type' => $workoutTypes[$i] ?? '',
                            ];
                        }
                    }
                }

                $payload = [
                    'user_id' => $userId,
                    'log_date' => $date,
                    'log_time' => normalize_log_time($_POST['log_time'] ?? '', (new DateTimeImmutable('now'))->format('H:i')),
                    'steps' => max(0, (int) ($_POST['steps'] ?? 0)),
                    'workout_done' => 0,
                    'workout_type_id' => null,
                    'workout_type' => '',
                    'workouts' => $rawWorkouts,
                    'junk_food' => bool_from_form('junk_food'),
                    'extra_workout' => 0,
                    'base_steps' => max(0, (int) ($_POST['steps'] ?? 0)),
                    'base_distance_km' => ($_POST['distance_km'] ?? '') !== '' ? (float) $_POST['distance_km'] : null,
                    'base_training_calories_burned' => ($_POST['training_calories_burned'] ?? '') !== '' ? (float) $_POST['training_calories_burned'] : null,
                    'distance_km' => ($_POST['distance_km'] ?? '') !== '' ? (float) $_POST['distance_km'] : null,
                    'training_calories_burned' => ($_POST['training_calories_burned'] ?? '') !== '' ? (float) $_POST['training_calories_burned'] : null,
                    'weight' => ($_POST['weight'] ?? '') !== '' ? (float) $_POST['weight'] : null,
                    'notes' => trim((string) ($_POST['notes'] ?? '')),
                    'step_exception_reason' => '',
                    'distance_exception_reason' => '',
                    'workout_exception_reason' => '',
                    'resend_requests' => 0,
                    'morning_walk' => (int) ($habitValues['morning_walk'] ?? 0) === 1 ? 1 : 0,
                    'journaling' => (int) ($habitValues['journaling'] ?? 0) === 1 ? 1 : 0,
                    'evening_chores' => (int) ($habitValues['evening_chores'] ?? 0) === 1 ? 1 : 0,
                    'reading' => (int) ($habitValues['reading'] ?? 0) === 1 ? 1 : 0,
                    'habits' => $habitValues,
                ];
                $payload = normalize_log_workouts_payload($pdo, $payload, (int) $currentUser['id']);
                $goalFailures = evaluate_primary_goal_failures($currentUser, $payload);
                $missingReason = trim((string) ($_POST['missing_reason'] ?? ''));
                if ($missingReason !== '') {
                    if (!empty($goalFailures['steps'])) {
                        $payload['step_exception_reason'] = $missingReason;
                    }
                    if (!empty($goalFailures['missing_km'])) {
                        $payload['distance_exception_reason'] = $missingReason;
                    }
                    if (!empty($goalFailures['workout'])) {
                        $payload['workout_exception_reason'] = $missingReason;
                    }
                }
                $before = fetch_log($pdo, $userId, $date);
                upsert_daily_log_and_sync_approvals($pdo, $payload, (int) $currentUser['id']);
                $after = fetch_log($pdo, $userId, $date);
                audit_log(
                    $pdo,
                    (int) $currentUser['id'],
                    'daily_log_saved',
                    'daily_log',
                    $userId . ':' . $date,
                    'Daily log saved.',
                    audit_snapshot($before),
                    audit_snapshot($after)
                );
                $settings = challenge_settings($pdo, $config);
                auto_complete_user_goals(
                    $pdo,
                    $userId,
                    (string) $settings['challenge_start'],
                    (string) $settings['challenge_end'],
                    (int) $currentUser['id']
                );
                auto_complete_team_goals_for_user(
                    $pdo,
                    $userId,
                    (string) $settings['challenge_start'],
                    (string) $settings['challenge_end'],
                    (int) $currentUser['id']
                );
                flash_set('success', t('flash.log_saved'));
            } catch (Throwable $e) {
                flash_set('error', t('flash.save_failed'));
            }

            redirect('/?page=entries&mode=' . rawurlencode($entryMode) . '&date=' . $date);
        }

        if ($action === 'upload_photo') {
            $userId = (int) $currentUser['id'];

            $date = to_date($_POST['log_date'] ?? null);
            $category = (string) ($_POST['category'] ?? 'other');
            $caption = trim((string) ($_POST['caption'] ?? ''));
            $nutrition = [
                'calories' => $_POST['photo_calories'] ?? null,
                'protein_g' => $_POST['photo_protein_g'] ?? null,
                'carbs_g' => $_POST['photo_carbs_g'] ?? null,
                'fat_g' => $_POST['photo_fat_g'] ?? null,
                'fiber_g' => $_POST['photo_fiber_g'] ?? null,
                'sugar_g' => $_POST['photo_sugar_g'] ?? null,
                'sodium_mg' => $_POST['photo_sodium_mg'] ?? null,
            ];

            $createdPhotoId = 0;
            try {
                $createdPhoto = save_photo_entry($pdo, $config, $userId, $date, $category, $caption, $_FILES['photo'] ?? [], $nutrition);
                $createdPhotoId = (int) ($createdPhoto['id'] ?? 0);
                audit_log(
                    $pdo,
                    (int) $currentUser['id'],
                    'photo_uploaded',
                    'photo_entry',
                    $createdPhotoId > 0 ? (string) $createdPhotoId : ($userId . ':' . $date),
                    'Proof photo uploaded.',
                    null,
                    [
                        'photo_id' => $createdPhotoId,
                        'user_id' => $userId,
                        'log_date' => $date,
                        'category' => $category,
                        'caption' => $caption,
                        'nutrition' => $nutrition,
                    ]
                );
                flash_set('success', t('flash.photo_uploaded'));
            } catch (Throwable $e) {
                flash_set('error', $e->getMessage());
            }

            if ($createdPhotoId > 0) {
                redirect('/?page=photo&photo_id=' . $createdPhotoId);
            }
            redirect('/?page=entries&mode=' . rawurlencode($entryMode) . '&date=' . $date);
        }

        if ($action === 'delete_photo') {
            $photoId = (int) ($_POST['photo_id'] ?? 0);
            $redirectMode = (string) ($_POST['redirect_mode'] ?? $entryMode);
            if (!in_array($redirectMode, ['meal', 'calendar'], true)) {
                $redirectMode = 'meal';
            }
            $redirectDate = to_date((string) ($_POST['redirect_date'] ?? null));
            $redirectCalendarView = (string) ($_POST['redirect_calendar_view'] ?? 'month');
            if (!in_array($redirectCalendarView, ['month', 'week', 'day'], true)) {
                $redirectCalendarView = 'month';
            }

            try {
                if ($photoId <= 0) {
                    throw new RuntimeException(t('flash.not_found'));
                }

                $photo = db_fetch_one($pdo, 'SELECT * FROM photo_entries WHERE id = :id', [':id' => $photoId]);
                if ($photo === null) {
                    throw new RuntimeException(t('flash.not_found'));
                }

                if (!is_admin($currentUser) && (int) ($photo['user_id'] ?? 0) !== (int) $currentUser['id']) {
                    throw new RuntimeException(t('flash.no_permission'));
                }

                $deletedPhoto = delete_photo_entry($pdo, $config, $photoId);
                if ($deletedPhoto === null) {
                    throw new RuntimeException(t('flash.not_found'));
                }

                audit_log(
                    $pdo,
                    (int) $currentUser['id'],
                    'photo_deleted',
                    'photo_entry',
                    (string) $photoId,
                    'Proof photo deleted.',
                    audit_snapshot($photo),
                    null
                );
                flash_set('success', t('flash.photo_deleted'));
            } catch (Throwable $e) {
                flash_set('error', t('flash.photo_delete_failed', ['error' => $e->getMessage()]));
            }

            $redirectUrl = '/?page=entries&mode=' . rawurlencode($redirectMode) . '&date=' . rawurlencode($redirectDate);
            if ($redirectMode === 'calendar') {
                $redirectUrl .= '&calendar_view=' . rawurlencode($redirectCalendarView);
            }
            redirect($redirectUrl);
        }
    }

    $users = $entryMode === 'calendar' && is_admin($currentUser) ? list_active_users($pdo) : [$currentUser];
    $selectedUserId = (int) $currentUser['id'];
    if ($entryMode === 'calendar' && is_admin($currentUser) && isset($_GET['user_id'])) {
        $selectedUserId = (int) $_GET['user_id'];
    }
    if ($selectedUserId <= 0) {
        $selectedUserId = (int) $currentUser['id'];
    }
    $selectedUser = find_user_by_id($users, $selectedUserId);
    if ($selectedUser === null) {
        $selectedUser = $currentUser;
        $selectedUserId = (int) $currentUser['id'];
    }

    $calendarView = (string) ($_GET['calendar_view'] ?? 'month');
    if (!in_array($calendarView, ['month', 'week', 'day'], true)) {
        $calendarView = 'month';
    }
    $hasExplicitCalendarDate = trim((string) ($_GET['date'] ?? '')) !== ''
        || trim((string) ($_GET['calendar_month'] ?? '')) !== ''
        || trim((string) ($_GET['calendar_week'] ?? '')) !== '';
    $calendarDateFallback = null;
    if ($entryMode === 'calendar' && !$hasExplicitCalendarDate) {
        $latestMealPhoto = fetch_latest_meal_photo($pdo, $selectedUserId);
        $calendarDateFallback = is_array($latestMealPhoto ?? null) && !empty($latestMealPhoto['log_date'])
            ? (string) $latestMealPhoto['log_date']
            : null;
    }
    $selectedDate = calendar_date_from_request($_GET, $calendarView, $calendarDateFallback);
    $currentLog = fetch_log($pdo, $selectedUserId, $selectedDate);
    $recentPhotos = fetch_recent_photos($pdo, 20, $selectedUserId);
    $workoutTypes = list_workout_types($pdo, true);
    $mealCalendar = [];
    if ($entryMode === 'calendar') {
        $mealCalendar = fetch_meal_calendar($pdo, $selectedDate, $selectedUserId, $calendarView);
    }

    render_view('entries', [
        'title' => t('entries.title'),
        'currentPage' => 'entries',
        'currentUser' => $currentUser,
        'entryMode' => $entryMode,
        'users' => $users,
        'selectedUserId' => $selectedUserId,
        'selectedUser' => $selectedUser,
        'selectedDate' => $selectedDate,
        'currentLog' => $currentLog,
        'recentPhotos' => $recentPhotos,
        'mealCalendar' => $mealCalendar,
        'calendarView' => $calendarView,
        'workoutTypes' => $workoutTypes,
        'workoutTypeFields' => list_workout_type_fields_grouped($pdo, true),
        'habits' => list_habit_definitions($pdo, true),
        'entryPrimaryGoals' => user_primary_goals($currentUser),
        'config' => $config,
    ]);
}

if ($page === 'photo') {
    $photoId = isset($_GET['photo_id']) ? (int) $_GET['photo_id'] : (int) ($_POST['photo_id'] ?? 0);
    $photo = fetch_photo_by_id($pdo, $photoId);
    if ($photo === null) {
        flash_set('error', t('flash.not_found'));
        redirect('/?page=entries&mode=meal&date=' . rawurlencode(to_date(null)));
    }

    $photoOwnerId = (int) ($photo['user_id'] ?? 0);
    $canDeletePhoto = is_admin($currentUser) || $photoOwnerId === (int) $currentUser['id'];
    $canEditPhoto = is_admin($currentUser) || $photoOwnerId === (int) $currentUser['id'];

    if (is_post()) {
        if (!csrf_verify()) {
            flash_set('error', t('flash.csrf'));
            redirect('/?page=photo&photo_id=' . (int) $photoId);
        }

        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'update_photo') {
            try {
                if (!$canEditPhoto) {
                    throw new RuntimeException(t('flash.no_permission'));
                }

                $date = to_date((string) ($_POST['log_date'] ?? ($photo['log_date'] ?? null)));
                $category = (string) ($_POST['category'] ?? ($photo['category'] ?? 'other'));
                $caption = trim((string) ($_POST['caption'] ?? ''));
                $nutrition = [
                    'calories' => $_POST['photo_calories'] ?? null,
                    'protein_g' => $_POST['photo_protein_g'] ?? null,
                    'carbs_g' => $_POST['photo_carbs_g'] ?? null,
                    'fat_g' => $_POST['photo_fat_g'] ?? null,
                    'fiber_g' => $_POST['photo_fiber_g'] ?? null,
                    'sugar_g' => $_POST['photo_sugar_g'] ?? null,
                    'sodium_mg' => $_POST['photo_sodium_mg'] ?? null,
                ];

                $beforePhoto = db_fetch_one($pdo, 'SELECT * FROM photo_entries WHERE id = :id', [':id' => $photoId]);
                $updatedPhoto = update_photo_entry(
                    $pdo,
                    $config,
                    $photoId,
                    $date,
                    $category,
                    $caption,
                    $nutrition,
                    is_array($_FILES['photo'] ?? null) ? (array) $_FILES['photo'] : null
                );
                if ($updatedPhoto === null) {
                    throw new RuntimeException(t('flash.not_found'));
                }
                $afterPhoto = db_fetch_one($pdo, 'SELECT * FROM photo_entries WHERE id = :id', [':id' => $photoId]);
                audit_log(
                    $pdo,
                    (int) $currentUser['id'],
                    'photo_updated',
                    'photo_entry',
                    (string) $photoId,
                    'Photo post updated.',
                    audit_snapshot($beforePhoto),
                    audit_snapshot($afterPhoto)
                );
                flash_set('success', t('photo.updated'));
            } catch (Throwable $e) {
                flash_set('error', $e->getMessage() !== '' ? $e->getMessage() : t('flash.save_failed'));
            }

            redirect('/?page=photo&photo_id=' . (int) $photoId);
        }

        if ($action === 'add_photo_comment') {
            $commentBody = (string) ($_POST['comment'] ?? '');
            try {
                $createdComment = create_photo_comment($pdo, $photoId, (int) $currentUser['id'], $commentBody);
                audit_log(
                    $pdo,
                    (int) $currentUser['id'],
                    'photo_comment_created',
                    'photo_comment',
                    (string) ($createdComment['id'] ?? ''),
                    'Photo comment created.',
                    null,
                    audit_snapshot($createdComment)
                );
                flash_set('success', t('photo.comment_added'));
            } catch (Throwable $e) {
                flash_set('error', $e->getMessage() !== '' ? $e->getMessage() : t('flash.save_failed'));
            }

            redirect('/?page=photo&photo_id=' . (int) $photoId);
        }

        if ($action === 'delete_photo_comment') {
            $commentId = (int) ($_POST['comment_id'] ?? 0);
            try {
                if ($commentId <= 0) {
                    throw new RuntimeException(t('flash.not_found'));
                }
                $comment = db_fetch_one(
                    $pdo,
                    'SELECT * FROM photo_comments WHERE id = :id AND photo_id = :photo_id',
                    [':id' => $commentId, ':photo_id' => $photoId]
                );
                if ($comment === null) {
                    throw new RuntimeException(t('flash.not_found'));
                }
                $canDeleteComment = is_admin($currentUser)
                    || (int) ($comment['user_id'] ?? 0) === (int) $currentUser['id']
                    || $photoOwnerId === (int) $currentUser['id'];
                if (!$canDeleteComment) {
                    throw new RuntimeException(t('flash.no_permission'));
                }

                $deletedComment = delete_photo_comment($pdo, $commentId);
                if ($deletedComment === null) {
                    throw new RuntimeException(t('flash.not_found'));
                }
                audit_log(
                    $pdo,
                    (int) $currentUser['id'],
                    'photo_comment_deleted',
                    'photo_comment',
                    (string) $commentId,
                    'Photo comment deleted.',
                    audit_snapshot($comment),
                    null
                );
                flash_set('success', t('photo.comment_deleted'));
            } catch (Throwable $e) {
                flash_set('error', $e->getMessage() !== '' ? $e->getMessage() : t('flash.save_failed'));
            }

            redirect('/?page=photo&photo_id=' . (int) $photoId);
        }

        if ($action === 'delete_photo') {
            try {
                if (!$canDeletePhoto) {
                    throw new RuntimeException(t('flash.no_permission'));
                }
                $deletedPhoto = delete_photo_entry($pdo, $config, $photoId);
                if ($deletedPhoto === null) {
                    throw new RuntimeException(t('flash.not_found'));
                }
                audit_log(
                    $pdo,
                    (int) $currentUser['id'],
                    'photo_deleted',
                    'photo_entry',
                    (string) $photoId,
                    'Proof photo deleted from photo detail.',
                    audit_snapshot($photo),
                    null
                );
                flash_set('success', t('flash.photo_deleted'));
            } catch (Throwable $e) {
                flash_set('error', t('flash.photo_delete_failed', ['error' => $e->getMessage()]));
                redirect('/?page=photo&photo_id=' . (int) $photoId);
            }

            redirect('/?page=entries&mode=meal&date=' . rawurlencode((string) ($photo['log_date'] ?? to_date(null))));
        }
    }

    $photo = fetch_photo_by_id($pdo, $photoId);
    if ($photo === null) {
        flash_set('error', t('flash.not_found'));
        redirect('/?page=entries&mode=meal&date=' . rawurlencode(to_date(null)));
    }

    render_view('photo', [
        'title' => t('photo.title'),
        'currentPage' => 'photo',
        'currentUser' => $currentUser,
        'photo' => $photo,
        'comments' => fetch_photo_comments($pdo, $photoId, 250),
        'canDeletePhoto' => $canDeletePhoto,
        'canEditPhoto' => $canEditPhoto,
        'config' => $config,
    ]);
}

if ($page === 'gallery') {
    $galleryView = (string) ($_GET['gallery_view'] ?? '');
    if (!in_array($galleryView, ['recent', 'calendar'], true)) {
        $redirectParams = $_GET;
        $redirectParams['page'] = 'gallery';
        $redirectParams['gallery_view'] = 'recent';
        redirect('/?' . http_build_query($redirectParams));
    }
    $calendarView = (string) ($_GET['calendar_view'] ?? 'month');
    if (!in_array($calendarView, ['month', 'week', 'day'], true)) {
        $calendarView = 'month';
    }
    $users = is_admin($currentUser) ? list_active_users($pdo) : [$currentUser];
    $selectedUserId = isset($_GET['user_id'])
        ? (int) $_GET['user_id']
        : (is_admin($currentUser) ? 0 : (int) $currentUser['id']);
    if (!is_admin($currentUser)) {
        $selectedUserId = (int) $currentUser['id'];
    } elseif ($selectedUserId < 0) {
        $selectedUserId = 0;
    }

    $selectedUser = $selectedUserId > 0 ? find_user_by_id($users, $selectedUserId) : null;
    if ($selectedUserId > 0 && $selectedUser === null) {
        $selectedUser = $currentUser;
        $selectedUserId = (int) $currentUser['id'];
    }
    $galleryUserFilter = $selectedUserId > 0 ? $selectedUserId : null;

    $hasExplicitCalendarDate = trim((string) ($_GET['date'] ?? '')) !== ''
        || trim((string) ($_GET['calendar_month'] ?? '')) !== ''
        || trim((string) ($_GET['calendar_week'] ?? '')) !== '';
    $calendarDateFallback = null;
    if (!$hasExplicitCalendarDate) {
        $latestMealPhoto = fetch_latest_meal_photo($pdo, $galleryUserFilter);
        $calendarDateFallback = is_array($latestMealPhoto ?? null) && !empty($latestMealPhoto['log_date'])
            ? (string) $latestMealPhoto['log_date']
            : null;
    }
    $selectedDate = calendar_date_from_request($_GET, $calendarView, $calendarDateFallback);

    $galleryPage = max(1, (int) ($_GET['gallery_page'] ?? 1));
    $galleryPerPage = max(24, min(240, (int) ($_GET['gallery_per_page'] ?? 96)));
    $galleryOffset = ($galleryPage - 1) * $galleryPerPage;
    $galleryHasMore = false;
    $galleryNextPage = null;
    $galleryMonthSeed = '';
    $galleryPhotos = [];
    if ($galleryView === 'recent') {
        $galleryRows = fetch_gallery_photos($pdo, $galleryPerPage + 1, $galleryOffset, $galleryUserFilter);
        $galleryHasMore = count($galleryRows) > $galleryPerPage;
        if ($galleryHasMore) {
            array_pop($galleryRows);
        }
        $galleryPhotos = $galleryRows;
        $galleryNextPage = $galleryHasMore ? $galleryPage + 1 : null;
        if ($galleryOffset > 0) {
            $gallerySeedRows = fetch_gallery_photos($pdo, 1, $galleryOffset - 1, $galleryUserFilter);
            if ($gallerySeedRows !== []) {
                $galleryMonthSeed = substr((string) ($gallerySeedRows[0]['log_date'] ?? ''), 0, 7);
            }
        }
    }
    $mealCalendar = $galleryView === 'calendar'
        ? fetch_meal_calendar($pdo, $selectedDate, $galleryUserFilter, $calendarView)
        : [];

    render_view('gallery', [
        'title' => t('gallery.title'),
        'currentPage' => 'gallery',
        'currentUser' => $currentUser,
        'users' => $users,
        'selectedUser' => $selectedUser,
        'galleryPhotos' => $galleryPhotos,
        'galleryView' => $galleryView,
        'galleryPage' => $galleryPage,
        'galleryPerPage' => $galleryPerPage,
        'galleryHasMore' => $galleryHasMore,
        'galleryNextPage' => $galleryNextPage,
        'galleryMonthSeed' => $galleryMonthSeed,
        'galleryApiUrl' => '/?page=api_gallery_recent',
        'calendarView' => $calendarView,
        'selectedDate' => $selectedDate,
        'mealCalendar' => $mealCalendar,
        'config' => $config,
    ]);
}

if ($page === 'table' || $page === 'week_editor') {
    $users = list_active_users($pdo);

    $selectedUserId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : (int) $currentUser['id'];
    if (!is_admin($currentUser) && $selectedUserId !== (int) $currentUser['id']) {
        $selectedUserId = (int) $currentUser['id'];
    }

    $selectedUser = find_user_by_id($users, $selectedUserId);
    if ($selectedUser === null) {
        $selectedUser = $currentUser;
        $selectedUserId = (int) $selectedUser['id'];
    }

    $settings = challenge_settings($pdo, $config);
    if (!challenge_is_active($settings)) {
        flash_set('error', t('flash.challenge_inactive'));
        redirect('/?page=admin');
    }

    $challengeStart = to_date((string) ($settings['challenge_start'] ?? null));
    $challengeEnd = to_date((string) ($settings['challenge_end'] ?? null), $challengeStart);
    try {
        if ((new DateTimeImmutable($challengeEnd)) < (new DateTimeImmutable($challengeStart))) {
            $challengeEnd = $challengeStart;
        }
    } catch (Throwable) {
        $challengeEnd = $challengeStart;
    }

    $defaultMonday = (new DateTimeImmutable('monday this week'))->format('Y-m-d');
    $hasExplicitWeek = (isset($_GET['week']) && trim((string) $_GET['week']) !== '')
        || (isset($_GET['week_start']) && trim((string) $_GET['week_start']) !== '');
    $requestedTrainingScope = strtolower(trim((string) ($_GET['range'] ?? $_GET['scope'] ?? '')));
    $trainingTableScope = ($requestedTrainingScope === 'week' || $hasExplicitWeek) ? 'week' : 'all';
    if ($requestedTrainingScope === 'all') {
        $trainingTableScope = 'all';
    }

    $weekInput = isset($_GET['week']) && $_GET['week'] !== ''
        ? week_to_monday((string) $_GET['week'], $defaultMonday)
        : to_date($_GET['week_start'] ?? null, $defaultMonday);
    $weekStartObj = week_start_for(new DateTimeImmutable($weekInput));
    $weekStart = $weekStartObj->format('Y-m-d');
    $weekEnd = $weekStartObj->modify('+6 days')->format('Y-m-d');

    if ($trainingTableScope === 'all') {
        $rangeStartObj = new DateTimeImmutable($challengeStart);
        $rangeEndObj = new DateTimeImmutable($challengeEnd);
    } else {
        $rangeStartObj = $weekStartObj;
        $rangeEndObj = $weekStartObj->modify('+6 days');
    }

    $weekDates = array_map(
        static fn(DateTimeImmutable $d): string => $d->format('Y-m-d'),
        day_sequence($rangeStartObj, $rangeEndObj)
    );
    $trainingRangeStart = $rangeStartObj->format('Y-m-d');
    $trainingRangeEnd = $rangeEndObj->format('Y-m-d');

    $logs = fetch_logs_for_user_between($pdo, $selectedUserId, $trainingRangeStart, $trainingRangeEnd);
    $logsByDate = [];
    foreach ($logs as $log) {
        $logsByDate[$log['log_date']] = $log;
    }
    $approvalRequestsByDate = fetch_approval_requests_by_user_between($pdo, $selectedUserId, $trainingRangeStart, $trainingRangeEnd);

    $metrics = compute_challenge_metrics($pdo, [$selectedUser], (string) $settings['challenge_start'], (string) $settings['challenge_end']);
    $metrics = apply_strike_review_overrides_to_metrics($pdo, $metrics);
    $viewName = $page === 'week_editor' ? 'week_editor' : 'table';

    render_view($viewName, [
        'title' => $page === 'week_editor' ? t('table.editor_title') : t('table.title'),
        'currentPage' => 'table',
        'currentUser' => $currentUser,
        'users' => $users,
        'selectedUser' => $selectedUser,
        'trainingTableScope' => $trainingTableScope,
        'trainingRangeStart' => $trainingRangeStart,
        'trainingRangeEnd' => $trainingRangeEnd,
        'challengeStart' => $challengeStart,
        'challengeEnd' => $challengeEnd,
        'weekStart' => $weekStart,
        'weekEnd' => $weekEnd,
        'weekDates' => $weekDates,
        'logsByDate' => $logsByDate,
        'approvalRequestsByDate' => $approvalRequestsByDate,
        'selectedMetric' => array_values($metrics)[0] ?? null,
        'workoutTypes' => list_workout_types($pdo, true),
        'habits' => list_habit_definitions($pdo, true),
        'config' => $config,
    ]);
}

if ($page === 'notifications') {
    $openNotificationId = isset($_GET['open_notification_id']) ? (int) $_GET['open_notification_id'] : 0;
    if ($openNotificationId > 0) {
        $destination = open_user_notification($pdo, $openNotificationId, (int) $currentUser['id']);
        redirect($destination);
    }

    if (is_post()) {
        if (!csrf_verify()) {
            flash_set('error', t('flash.csrf'));
            redirect('/?page=notifications');
        }

        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'mark_notification_read') {
            $notificationId = (int) ($_POST['notification_id'] ?? 0);
            mark_user_notification_read($pdo, $notificationId, (int) $currentUser['id']);
            redirect('/?page=notifications');
        }
        if ($action === 'mark_all_notifications_read') {
            mark_all_user_notifications_read($pdo, (int) $currentUser['id']);
            redirect('/?page=notifications');
        }
        if ($action === 'delete_notification') {
            $notificationId = (int) ($_POST['notification_id'] ?? 0);
            delete_user_notification($pdo, $notificationId, (int) $currentUser['id']);
            redirect('/?page=notifications');
        }
        if ($action === 'delete_read_notifications') {
            delete_user_read_notifications($pdo, (int) $currentUser['id']);
            redirect('/?page=notifications');
        }
        if ($action === 'delete_all_notifications') {
            delete_all_user_notifications($pdo, (int) $currentUser['id']);
            redirect('/?page=notifications');
        }
    }

    $notifications = user_notifications($pdo, (int) $currentUser['id'], 200, true);

    render_view('notifications', [
        'title' => t('notifications.title'),
        'currentPage' => 'notifications',
        'currentUser' => $currentUser,
        'notifications' => $notifications,
        'config' => $config,
    ]);
}

if ($page === 'challenges') {
    $archives = list_challenge_archives($pdo);
    render_view('challenges', [
        'title' => t('challenges.title'),
        'currentPage' => 'team',
        'currentUser' => $currentUser,
        'archives' => $archives,
        'config' => $config,
    ]);
}

if ($page === 'settings') {
    $settingsView = (string) ($_GET['view'] ?? '');
    if (!in_array($settingsView, ['avatar'], true)) {
        $settingsView = '';
    }
    $settingsRedirect = static function (?string $view = null): string {
        return $view === 'avatar' ? '/?page=settings&view=avatar#avatar' : '/?page=settings';
    };

    if (is_post()) {
        if (!csrf_verify()) {
            flash_set('error', t('flash.csrf'));
            redirect($settingsRedirect($settingsView));
        }

        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'change_password') {
            $currentPassword = (string) ($_POST['current_password'] ?? '');
            $newPassword = (string) ($_POST['new_password'] ?? '');
            $newPasswordConfirm = (string) ($_POST['new_password_confirm'] ?? '');
            if ($newPassword !== $newPasswordConfirm) {
                flash_set('error', t('flash.password_mismatch'));
                redirect('/?page=settings');
            }
            if (strlen($newPassword) < 8) {
                flash_set('error', t('flash.password_short'));
                redirect('/?page=settings');
            }
            if (!change_password($pdo, (int) $currentUser['id'], $currentPassword, $newPassword)) {
                flash_set('error', t('flash.current_password_wrong'));
                redirect('/?page=settings');
            }
            audit_log($pdo, (int) $currentUser['id'], 'password_changed', 'user', (string) $currentUser['id'], 'Password changed.', null, ['password_changed' => true]);
            flash_set('success', t('flash.password_updated'));
            redirect('/?page=settings');
        }

        if ($action === 'update_preferences') {
            $before = db_fetch_one($pdo, 'SELECT * FROM users WHERE id = :id', [':id' => (int) $currentUser['id']]);
            $primaryType = in_array(($_POST['primary_goal_type'] ?? 'steps'), ['steps', 'km'], true) ? (string) $_POST['primary_goal_type'] : 'steps';
            $themeMode = in_array(($_POST['theme_mode'] ?? 'auto'), ['auto', 'light', 'dark'], true) ? (string) $_POST['theme_mode'] : 'auto';
            $layoutJson = (string) ($before['dashboard_layout_json'] ?? '[]');
            $hasWidgetPayload = array_key_exists('dashboard_widgets', $_POST) || array_key_exists('dashboard_order', $_POST);
            if ($hasWidgetPayload) {
                $allowedWidgets = ['kpis', 'distance_walked', 'approvals', 'steps', 'steps_cumulative', 'distance_cumulative', 'weight', 'comparison', 'ranking', 'meals', 'weekly', 'calories', 'achievements'];
                $selectedWidgets = array_values(array_intersect(array_map('strval', (array) ($_POST['dashboard_widgets'] ?? [])), $allowedWidgets));
                $selectedWidgets = array_values(array_unique(array_map(
                    static fn(string $widget): string => $widget === 'money' ? 'distance_walked' : $widget,
                    $selectedWidgets
                )));
                $widgetOrder = (array) ($_POST['dashboard_order'] ?? []);
                usort($selectedWidgets, static function (string $left, string $right) use ($widgetOrder, $allowedWidgets): int {
                    $leftOrder = isset($widgetOrder[$left]) ? (int) $widgetOrder[$left] : (int) array_search($left, $allowedWidgets, true);
                    $rightOrder = isset($widgetOrder[$right]) ? (int) $widgetOrder[$right] : (int) array_search($right, $allowedWidgets, true);
                    return $leftOrder <=> $rightOrder;
                });
                $layoutJson = json_encode($selectedWidgets, JSON_UNESCAPED_SLASHES) ?: '[]';
            }
            db_execute(
                $pdo,
                'UPDATE users
                 SET primary_goal_type = :primary_goal_type,
                     primary_goal_value = :primary_goal_value,
                     calorie_burn_goal = :calorie_burn_goal,
                     calorie_consumed_max = :calorie_consumed_max,
                     theme_mode = :theme_mode,
                     dashboard_view = :dashboard_view,
                     dashboard_layout_json = :dashboard_layout_json,
                     updated_at = :updated_at
                 WHERE id = :id',
                [
                    ':primary_goal_type' => $primaryType,
                    ':primary_goal_value' => ($_POST['primary_goal_value'] ?? '') !== '' ? (float) $_POST['primary_goal_value'] : null,
                    ':calorie_burn_goal' => ($_POST['calorie_burn_goal'] ?? '') !== '' ? max(0.0, (float) $_POST['calorie_burn_goal']) : null,
                    ':calorie_consumed_max' => ($_POST['calorie_consumed_max'] ?? '') !== '' ? max(0.0, (float) $_POST['calorie_consumed_max']) : null,
                    ':theme_mode' => $themeMode,
                    ':dashboard_view' => (string) ($_POST['dashboard_view'] ?? 'current_week'),
                    ':dashboard_layout_json' => $layoutJson,
                    ':updated_at' => now_iso(),
                    ':id' => (int) $currentUser['id'],
                ]
            );
            $after = db_fetch_one($pdo, 'SELECT * FROM users WHERE id = :id', [':id' => (int) $currentUser['id']]);
            audit_log($pdo, (int) $currentUser['id'], 'user_preferences_updated', 'user', (string) $currentUser['id'], 'User preferences updated.', audit_snapshot($before, ['password_hash']), audit_snapshot($after, ['password_hash']));
            flash_set('success', t('flash.preferences_updated'));
            redirect('/?page=settings');
        }

        if ($action === 'telegram_generate_link') {
            telegram_generate_link_code($pdo, (int) $currentUser['id']);
            flash_set('success', t('flash.telegram_link_ready'));
            redirect('/?page=settings#telegram');
        }

        if ($action === 'telegram_update_prefs') {
            telegram_update_user_prefs($pdo, (int) $currentUser['id'], $_POST);
            flash_set('success', t('flash.telegram_prefs_updated'));
            redirect('/?page=settings#telegram');
        }

        if ($action === 'telegram_unlink') {
            telegram_unlink_user($pdo, (int) $currentUser['id']);
            flash_set('success', t('flash.telegram_unlinked'));
            redirect('/?page=settings#telegram');
        }

        if ($action === 'telegram_test') {
            $telegramSettings = telegram_settings($pdo);
            $telegramChatId = trim((string) ($currentUser['telegram_chat_id'] ?? ''));
            if ($telegramChatId === '' || !telegram_is_enabled($telegramSettings)) {
                flash_set('error', trim(t('flash.telegram_test_failed') . ' ' . t('settings.telegram_unavailable')));
            } else {
                $telegramTest = telegram_send_test($telegramSettings, $telegramChatId, t('telegram.msg_test'));
                if ($telegramTest['ok']) {
                    flash_set('success', t('flash.telegram_test_sent'));
                } else {
                    flash_set('error', trim(t('flash.telegram_test_failed') . ' ' . (string) $telegramTest['error']));
                }
            }
            redirect('/?page=settings#telegram');
        }

        if ($action === 'upload_avatar') {
            $storedPath = null;
            $persisted = false;
            try {
                $cropped = trim((string) ($_POST['avatar_cropped'] ?? ''));
                if ($cropped !== '') {
                    $storedPath = save_uploaded_image_from_data_url($config, $cropped, 'avatars', 'user_' . (string) $currentUser['id']);
                } else {
                    $storedPath = save_uploaded_image($config, $_FILES['avatar'] ?? [], 'avatars', 'user_' . (string) $currentUser['id']);
                }

                $resolvedStoredPath = resolve_media_storage_path($config, (string) $storedPath);
                if ($resolvedStoredPath === null || !is_file($resolvedStoredPath)) {
                    throw new RuntimeException(t('upload.move_failed'));
                }

                $updatedAt = now_iso();
                $pdo->beginTransaction();
                db_execute(
                    $pdo,
                    'UPDATE users SET avatar_path = :avatar_path, updated_at = :updated_at WHERE id = :id',
                    [
                        ':avatar_path' => $storedPath,
                        ':updated_at' => $updatedAt,
                        ':id' => (int) $currentUser['id'],
                    ]
                );
                $updatedUser = db_fetch_one(
                    $pdo,
                    'SELECT id, avatar_path, updated_at FROM users WHERE id = :id',
                    [':id' => (int) $currentUser['id']]
                );
                if ($updatedUser === null || trim((string) ($updatedUser['avatar_path'] ?? '')) !== (string) $storedPath) {
                    throw new RuntimeException(t('upload.persist_failed'));
                }
                $pdo->commit();
                $persisted = true;

                try {
                    audit_log(
                        $pdo,
                        (int) $currentUser['id'],
                        'avatar_updated',
                        'user',
                        (string) $currentUser['id'],
                        'Avatar updated.',
                        null,
                        ['avatar_path' => $storedPath]
                    );
                } catch (Throwable) {
                    // Audit issues should not block a successful avatar update.
                }
                flash_set('success', t('flash.avatar_updated'));
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if (!$persisted && is_string($storedPath) && trim($storedPath) !== '') {
                    $failedFile = resolve_media_storage_path($config, $storedPath);
                    if ($failedFile !== null && is_file($failedFile)) {
                        @unlink($failedFile);
                    }
                }
                flash_set('error', $e->getMessage());
            }
            $avatarRedirectView = (string) ($_POST['settings_view'] ?? '') === 'avatar' ? 'avatar' : null;
            redirect($settingsRedirect($avatarRedirectView));
        }
    }

    $currentUser = current_user($pdo) ?? $currentUser;
    $settingsGoalRows = list_goals($pdo, 'user', (int) $currentUser['id']);
    $settingsHabitDefinitions = list_habit_definitions($pdo, true);
    $settingsGoalMetric = [];
    try {
        $settingsChallenge = challenge_settings($pdo, $config);
        $settingsMetrics = compute_challenge_metrics(
            $pdo,
            [$currentUser],
            (string) $settingsChallenge['challenge_start'],
            (string) $settingsChallenge['challenge_end']
        );
        $settingsMetrics = apply_strike_review_overrides_to_metrics($pdo, $settingsMetrics);
        $settingsGoalMetric = $settingsMetrics[(int) $currentUser['id']] ?? array_values($settingsMetrics)[0] ?? [];
    } catch (Throwable) {
        $settingsGoalMetric = [];
    }

    render_view('settings', [
        'title' => t('settings.title'),
        'currentPage' => 'settings',
        'currentUser' => $currentUser,
        'settingsView' => $settingsView,
        'settingsGoalCards' => build_user_goal_view_models($settingsGoalRows, is_array($settingsGoalMetric) ? $settingsGoalMetric : [], $settingsHabitDefinitions),
        'telegramSettings' => telegram_settings($pdo),
        'config' => $config,
    ]);
}

if ($page === 'profile') {
    $requestedProfileUserId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : (int) $currentUser['id'];
    if ($requestedProfileUserId <= 0) {
        $requestedProfileUserId = (int) $currentUser['id'];
    }
    $profileUser = db_fetch_one(
        $pdo,
        'SELECT * FROM users WHERE id = :id AND active = 1',
        [':id' => $requestedProfileUserId]
    );
    if ($profileUser === null) {
        flash_set('error', t('flash.no_permission'));
        $profileUser = $currentUser;
    }

    $isOwnProfile = (int) $profileUser['id'] === (int) $currentUser['id'];
    $sharesTeamWithTarget = false;
    if (!$isOwnProfile && !is_admin($currentUser)) {
        $sharedTeam = db_fetch_one(
            $pdo,
            'SELECT tm1.team_id
             FROM team_memberships tm1
             JOIN team_memberships tm2 ON tm2.team_id = tm1.team_id
             WHERE tm1.user_id = :viewer_id AND tm1.active = 1
               AND tm2.user_id = :target_id AND tm2.active = 1
             LIMIT 1',
            [
                ':viewer_id' => (int) $currentUser['id'],
                ':target_id' => (int) $profileUser['id'],
            ]
        );
        $sharesTeamWithTarget = $sharedTeam !== null;
    }
    if (!$isOwnProfile && !is_admin($currentUser) && !$sharesTeamWithTarget) {
        flash_set('error', t('flash.no_permission'));
        redirect('/?page=profile');
    }

    $canEditProfile = $isOwnProfile || is_admin($currentUser);
    $canDeleteProfileAchievements = is_admin($currentUser) || $isOwnProfile;
    $profileBaseQuery = ['page' => 'profile'];
    if (!$isOwnProfile) {
        $profileBaseQuery['user_id'] = (int) $profileUser['id'];
    }
    $requestedProfileChallengeKey = trim((string) ($_GET['challenge'] ?? 'current'));
    if ($requestedProfileChallengeKey !== '' && $requestedProfileChallengeKey !== 'current') {
        $profileBaseQuery['challenge'] = $requestedProfileChallengeKey;
    }
    $profileUrl = static function (?string $section = null, array $extra = []) use (&$profileBaseQuery): string {
        $query = array_merge($profileBaseQuery, $extra);
        if ($section !== null && $section !== '') {
            $query['section'] = $section;
        }
        return '/?' . http_build_query($query);
    };

    if (is_post()) {
        if (!csrf_verify()) {
            flash_set('error', t('flash.csrf'));
            redirect($profileUrl());
        }

        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'change_password') {
            if (!$canEditProfile || !$isOwnProfile) {
                flash_set('error', t('flash.no_permission'));
                redirect($profileUrl());
            }
            $currentPassword = (string) ($_POST['current_password'] ?? '');
            $newPassword = (string) ($_POST['new_password'] ?? '');
            $newPasswordConfirm = (string) ($_POST['new_password_confirm'] ?? '');

            if ($newPassword !== $newPasswordConfirm) {
                flash_set('error', t('flash.password_mismatch'));
                redirect($profileUrl());
            }

            if (strlen($newPassword) < 8) {
                flash_set('error', t('flash.password_short'));
                redirect($profileUrl());
            }

            if (!change_password($pdo, (int) $profileUser['id'], $currentPassword, $newPassword)) {
                flash_set('error', t('flash.current_password_wrong'));
                redirect($profileUrl());
            }

            audit_log(
                $pdo,
                (int) $currentUser['id'],
                'password_changed',
                'user',
                (string) $profileUser['id'],
                'Password changed.',
                null,
                ['password_changed' => true]
            );
            flash_set('success', t('flash.password_updated'));
            redirect($profileUrl());
        }

        if ($action === 'update_profile_tagline') {
            if (!$canEditProfile || !$isOwnProfile) {
                flash_set('error', t('flash.no_permission'));
                redirect($profileUrl());
            }
            $before = db_fetch_one($pdo, 'SELECT * FROM users WHERE id = :id', [':id' => (int) $profileUser['id']]);
            $tagline = trim((string) ($_POST['profile_tagline'] ?? ''));
            if (function_exists('mb_substr')) {
                $tagline = mb_substr($tagline, 0, 160);
            } else {
                $tagline = substr($tagline, 0, 160);
            }
            db_execute(
                $pdo,
                'UPDATE users SET profile_tagline = :profile_tagline, updated_at = :updated_at WHERE id = :id',
                [
                    ':profile_tagline' => $tagline !== '' ? $tagline : null,
                    ':updated_at' => now_iso(),
                    ':id' => (int) $profileUser['id'],
                ]
            );
            $after = db_fetch_one($pdo, 'SELECT * FROM users WHERE id = :id', [':id' => (int) $profileUser['id']]);
            audit_log(
                $pdo,
                (int) $currentUser['id'],
                'profile_tagline_updated',
                'user',
                (string) $profileUser['id'],
                'Profile tagline updated.',
                audit_snapshot($before, ['password_hash']),
                audit_snapshot($after, ['password_hash'])
            );
            flash_set('success', t('flash.preferences_updated'));
            redirect($profileUrl());
        }

        if ($action === 'create_goal') {
            if (!$canEditProfile) {
                flash_set('error', t('flash.no_permission'));
                redirect($profileUrl('goals'));
            }
            $title = trim((string) ($_POST['title'] ?? ''));
            if ($title !== '') {
                create_goal($pdo, [
                    'scope' => 'user',
                    'team_id' => null,
                    'user_id' => (int) $profileUser['id'],
                    'title' => $title,
                    'target_type' => normalize_goal_target_type((string) ($_POST['target_type'] ?? 'custom')),
                    'target_value' => ($_POST['target_value'] ?? '') !== '' ? (float) $_POST['target_value'] : null,
                    'current_value' => 0,
                    'due_date' => ($_POST['due_date'] ?? '') !== '' ? to_date((string) $_POST['due_date']) : null,
                ], (int) $currentUser['id']);
                flash_set('success', t('flash.goal_created'));
            }
            redirect($profileUrl('goals'));
        }

        if ($action === 'goal_status') {
            if (!$canEditProfile) {
                flash_set('error', t('flash.no_permission'));
                redirect($profileUrl('goals'));
            }
            $goalId = (int) ($_POST['goal_id'] ?? 0);
            $goal = db_fetch_one($pdo, 'SELECT * FROM goals WHERE id = :id', [':id' => $goalId]);
            if ($goal === null || (string) ($goal['scope'] ?? '') !== 'user' || (int) ($goal['user_id'] ?? 0) !== (int) $profileUser['id']) {
                flash_set('error', t('flash.no_permission'));
                redirect($profileUrl('goals'));
            }
            update_goal_status($pdo, (int) ($_POST['goal_id'] ?? 0), (string) ($_POST['status'] ?? 'active'), (int) $currentUser['id']);
            flash_set('success', t('flash.goal_updated'));
            redirect($profileUrl('goals'));
        }

        if ($action === 'update_goal') {
            if (!$canEditProfile) {
                flash_set('error', t('flash.no_permission'));
                redirect($profileUrl('goals'));
            }
            $goalId = (int) ($_POST['goal_id'] ?? 0);
            $goal = db_fetch_one($pdo, 'SELECT * FROM goals WHERE id = :id', [':id' => $goalId]);
            if ($goal === null || (string) ($goal['scope'] ?? '') !== 'user' || (int) ($goal['user_id'] ?? 0) !== (int) $profileUser['id']) {
                flash_set('error', t('flash.no_permission'));
                redirect($profileUrl('goals'));
            }
            update_goal($pdo, $goalId, [
                'title' => trim((string) ($_POST['title'] ?? '')),
                'target_type' => normalize_goal_target_type((string) ($_POST['target_type'] ?? 'custom')),
                'target_value' => ($_POST['target_value'] ?? '') !== '' ? (float) $_POST['target_value'] : null,
                'due_date' => ($_POST['due_date'] ?? '') !== '' ? to_date((string) $_POST['due_date']) : null,
            ], (int) $currentUser['id']);
            flash_set('success', t('flash.goal_updated'));
            redirect($profileUrl('goals'));
        }

        if ($action === 'delete_goal') {
            if (!$canEditProfile) {
                flash_set('error', t('flash.no_permission'));
                redirect($profileUrl('goals'));
            }
            $goalId = (int) ($_POST['goal_id'] ?? 0);
            $goal = db_fetch_one($pdo, 'SELECT * FROM goals WHERE id = :id', [':id' => $goalId]);
            if ($goal !== null && (string) $goal['scope'] === 'user' && (int) ($goal['user_id'] ?? 0) === (int) $profileUser['id']) {
                delete_goal($pdo, $goalId, (int) $currentUser['id']);
                flash_set('success', t('flash.goal_deleted'));
            } else {
                flash_set('error', t('flash.no_permission'));
            }
            redirect($profileUrl('goals'));
        }

        if ($action === 'update_profile_config') {
            if (!$canEditProfile) {
                flash_set('error', t('flash.no_permission'));
                redirect($profileUrl('config'));
            }
            $before = db_fetch_one($pdo, 'SELECT * FROM users WHERE id = :id', [':id' => (int) $profileUser['id']]);
            $primaryGoalType = in_array((string) ($_POST['primary_goal_type'] ?? 'steps'), ['steps', 'km', 'workouts'], true)
                ? (string) $_POST['primary_goal_type']
                : 'steps';
            $rawPrimaryGoalsSpec = trim((string) ($_POST['primary_goals_spec'] ?? ''));
            try {
                $normalizedPrimaryGoalsSpec = $rawPrimaryGoalsSpec !== '' ? normalize_primary_goals_spec($rawPrimaryGoalsSpec) : null;
            } catch (InvalidArgumentException $exception) {
                flash_set('error', $exception->getMessage());
                redirect($profileUrl('config', ['edit' => 1]));
            }
            db_execute(
                $pdo,
                'UPDATE users
                 SET primary_goal_type = :primary_goal_type,
                     primary_goal_value = :primary_goal_value,
                     primary_goals_spec = :primary_goals_spec,
                     workout_target = :workout_target,
                     maintenance_calories = :maintenance_calories,
                     calorie_burn_goal = :calorie_burn_goal,
                     calorie_consumed_max = :calorie_consumed_max,
                     ideal_weight = :ideal_weight,
                     updated_at = :updated_at
                 WHERE id = :id',
                [
                    ':primary_goal_type' => $primaryGoalType,
                    ':primary_goal_value' => ($_POST['primary_goal_value'] ?? '') !== '' ? (float) $_POST['primary_goal_value'] : null,
                    ':primary_goals_spec' => $normalizedPrimaryGoalsSpec,
                    ':workout_target' => max(0, (int) ($_POST['workout_target'] ?? 0)),
                    ':maintenance_calories' => ($_POST['maintenance_calories'] ?? '') !== '' ? max(0.0, (float) $_POST['maintenance_calories']) : null,
                    ':calorie_burn_goal' => ($_POST['calorie_burn_goal'] ?? '') !== '' ? max(0.0, (float) $_POST['calorie_burn_goal']) : null,
                    ':calorie_consumed_max' => ($_POST['calorie_consumed_max'] ?? '') !== '' ? max(0.0, (float) $_POST['calorie_consumed_max']) : null,
                    ':ideal_weight' => ($_POST['ideal_weight'] ?? '') !== '' ? (float) $_POST['ideal_weight'] : null,
                    ':updated_at' => now_iso(),
                    ':id' => (int) $profileUser['id'],
                ]
            );
            $after = db_fetch_one($pdo, 'SELECT * FROM users WHERE id = :id', [':id' => (int) $profileUser['id']]);
            audit_log(
                $pdo,
                (int) $currentUser['id'],
                'profile_config_updated',
                'user',
                (string) $profileUser['id'],
                'Profile configuration updated.',
                audit_snapshot($before, ['password_hash']),
                audit_snapshot($after, ['password_hash'])
            );
            flash_set('success', t('flash.preferences_updated'));
            redirect($profileUrl('config'));
        }

        if ($action === 'delete_achievement_award') {
            if (!$canDeleteProfileAchievements) {
                flash_set('error', t('flash.no_permission'));
                redirect($profileUrl('achievements'));
            }
            $awardId = (int) ($_POST['award_id'] ?? 0);
            if ($awardId <= 0) {
                flash_set('error', 'Invalid achievement id.');
                redirect($profileUrl('achievements'));
            }
            $award = db_fetch_one($pdo, 'SELECT * FROM achievement_awards WHERE id = :id', [':id' => $awardId]);
            if ($award !== null && (int) ($award['user_id'] ?? 0) === (int) $profileUser['id']) {
                delete_achievement_award($pdo, $awardId, (int) $currentUser['id']);
                flash_set('success', t('flash.achievement_deleted'));
            } else {
                flash_set('error', t('flash.no_permission'));
            }
            redirect($profileUrl('achievements'));
        }
    }

    $settings = challenge_settings($pdo, $config);
    $profileChallengeArchives = list_challenge_archives($pdo);
    $profileChallengeOptions = [[
        'key' => 'current',
        'id' => null,
        'name' => (string) ($settings['challenge_name'] ?? 'Fitness Challenge'),
        'start' => (string) ($settings['challenge_start'] ?? to_date(null)),
        'end' => (string) ($settings['challenge_end'] ?? to_date(null)),
        'is_archive' => false,
        'archived_at' => '',
    ]];
    foreach ($profileChallengeArchives as $archive) {
        $archiveId = (int) ($archive['id'] ?? 0);
        $archiveStart = trim((string) ($archive['challenge_start'] ?? ''));
        $archiveEnd = trim((string) ($archive['challenge_end'] ?? ''));
        if ($archiveId <= 0 || $archiveStart === '' || $archiveEnd === '') {
            continue;
        }
        $profileChallengeOptions[] = [
            'key' => 'archive:' . $archiveId,
            'id' => $archiveId,
            'name' => (string) ($archive['challenge_name'] ?? t('challenges.unnamed')),
            'start' => $archiveStart,
            'end' => $archiveEnd,
            'is_archive' => true,
            'archived_at' => (string) ($archive['archived_at'] ?? ''),
        ];
    }
    $profileSelectedChallenge = $profileChallengeOptions[0];
    foreach ($profileChallengeOptions as $challengeOption) {
        if ((string) ($challengeOption['key'] ?? '') === $requestedProfileChallengeKey) {
            $profileSelectedChallenge = $challengeOption;
            break;
        }
    }
    $profileSelectedChallengeKey = (string) ($profileSelectedChallenge['key'] ?? 'current');
    if ($profileSelectedChallengeKey !== 'current') {
        $profileBaseQuery['challenge'] = $profileSelectedChallengeKey;
    } else {
        unset($profileBaseQuery['challenge']);
    }
    $profileChallengeStart = (string) ($profileSelectedChallenge['start'] ?? (string) $settings['challenge_start']);
    $profileChallengeEnd = (string) ($profileSelectedChallenge['end'] ?? (string) $settings['challenge_end']);
    $profileSelectedChallengeIsArchive = !empty($profileSelectedChallenge['is_archive']);
    if (!$profileSelectedChallengeIsArchive && challenge_is_active($settings)) {
        auto_complete_user_goals(
            $pdo,
            (int) $profileUser['id'],
            $profileChallengeStart,
            $profileChallengeEnd,
            null
        );
    }
    $metrics = compute_challenge_metrics(
        $pdo,
        [$profileUser],
        $profileChallengeStart,
        $profileChallengeEnd
    );
    $metrics = apply_strike_review_overrides_to_metrics($pdo, $metrics);
    $profileMetric = array_values($metrics)[0] ?? null;
    $profileChallengeSummaryFromMetric = static function (?array $metric): array {
        if (!is_array($metric)) {
            $metric = [];
        }

        return [
            'steps' => (int) ($metric['total_steps'] ?? 0),
            'distance_km' => round((float) ($metric['total_km'] ?? 0), 2),
            'workouts' => (int) max((int) ($metric['workout_count'] ?? 0), (int) ($metric['workout_success'] ?? 0)),
            'workout_target' => (int) ($metric['workout_target'] ?? 0),
            'score' => round((float) ($metric['score'] ?? 0), 1),
            'step_completion_pct' => round((float) ($metric['step_completion_pct'] ?? 0), 1),
            'workout_completion_pct' => round((float) ($metric['workout_completion_pct'] ?? 0), 1),
        ];
    };
    foreach ($profileChallengeOptions as $challengeOptionIndex => $challengeOption) {
        $challengeOptionKey = (string) ($challengeOption['key'] ?? '');
        if ($challengeOptionKey === $profileSelectedChallengeKey) {
            $profileChallengeOptions[$challengeOptionIndex]['summary'] = $profileChallengeSummaryFromMetric(
                is_array($profileMetric) ? $profileMetric : null
            );
            continue;
        }

        $challengeOptionStart = trim((string) ($challengeOption['start'] ?? ''));
        $challengeOptionEnd = trim((string) ($challengeOption['end'] ?? ''));
        if ($challengeOptionStart === '' || $challengeOptionEnd === '') {
            $profileChallengeOptions[$challengeOptionIndex]['summary'] = $profileChallengeSummaryFromMetric(null);
            continue;
        }

        $challengeOptionMetrics = compute_challenge_metrics($pdo, [$profileUser], $challengeOptionStart, $challengeOptionEnd);
        $challengeOptionMetrics = apply_strike_review_overrides_to_metrics($pdo, $challengeOptionMetrics);
        $profileChallengeOptions[$challengeOptionIndex]['summary'] = $profileChallengeSummaryFromMetric(
            array_values($challengeOptionMetrics)[0] ?? null
        );
    }
    foreach ($profileChallengeOptions as $challengeOption) {
        if ((string) ($challengeOption['key'] ?? '') === $profileSelectedChallengeKey) {
            $profileSelectedChallenge = $challengeOption;
            break;
        }
    }
    $profileDistanceWeekly = [];
    $profileWorkoutWeekly = [];
    $profileScoreWeekly = [];
    $profileLogs = fetch_logs_for_user_between(
        $pdo,
        (int) ($profileUser['id'] ?? 0),
        $profileChallengeStart,
        $profileChallengeEnd
    );
    $habitDefinitions = list_habit_definitions($pdo, true);
    if (is_array($profileMetric)) {
        $distanceByWeek = [];
        foreach ($profileLogs as $profileLog) {
            $logDate = (string) ($profileLog['log_date'] ?? '');
            if ($logDate === '') {
                continue;
            }
            $weekKey = week_start_for(new DateTimeImmutable($logDate))->format('Y-m-d');
            if (!isset($distanceByWeek[$weekKey])) {
                $distanceByWeek[$weekKey] = 0.0;
            }
            $distanceByWeek[$weekKey] += (float) ($profileLog['distance_km'] ?? 0);
        }
        ksort($distanceByWeek);
        foreach ($distanceByWeek as $weekStart => $distanceValue) {
            $profileDistanceWeekly[] = [
                'label' => format_date_eu((string) $weekStart),
                'value' => round((float) $distanceValue, 2),
            ];
        }

        foreach ((array) ($profileMetric['weekly'] ?? []) as $weekRow) {
            $workoutValue = max(
                max(0, (int) ($weekRow['workouts'] ?? 0)),
                max(0, (int) ($weekRow['workout_success_week'] ?? 0)),
                max(0, (int) ($weekRow['workout_target_week'] ?? 0) - (int) ($weekRow['workout_failures'] ?? 0))
            );
            $scoreValue = round(max(
                0.0,
                100 - (
                    ((int) ($weekRow['step_failures'] ?? 0) * 6) +
                    ((int) ($weekRow['workout_failures'] ?? 0) * 8) +
                    ((int) ($weekRow['skip_warnings'] ?? 0) * 3) +
                    ((int) ($weekRow['strikes_after_week'] ?? 0) * 4)
                )
            ), 1);
            $weekLabel = format_date_eu((string) ($weekRow['week_start'] ?? ''));
            $profileWorkoutWeekly[] = ['label' => $weekLabel, 'value' => $workoutValue];
            $profileScoreWeekly[] = ['label' => $weekLabel, 'value' => $scoreValue];
        }
    }

    $logsByDate = [];
    foreach ($profileLogs as $profileLog) {
        $logDate = (string) ($profileLog['log_date'] ?? '');
        if ($logDate === '') {
            continue;
        }
        $logsByDate[$logDate] = $profileLog;
    }

    $photoRows = db_fetch_all(
        $pdo,
        'SELECT *
         FROM photo_entries
         WHERE user_id = :user_id
           AND log_date BETWEEN :start AND :end
         ORDER BY log_date ASC, created_at ASC',
        [
            ':user_id' => (int) ($profileUser['id'] ?? 0),
            ':start' => $profileChallengeStart,
            ':end' => $profileChallengeEnd,
        ]
    );
    $photosByDate = [];
    foreach ($photoRows as $photoRow) {
        $logDate = (string) ($photoRow['log_date'] ?? '');
        if ($logDate === '') {
            continue;
        }
        if (!isset($photosByDate[$logDate])) {
            $photosByDate[$logDate] = [];
        }
        $photosByDate[$logDate][] = $photoRow;
    }

    $approvalRows = db_fetch_all(
        $pdo,
        'SELECT ar.approval_type, ar.status, ar.detail, dl.log_date
         FROM approval_requests ar
         JOIN daily_logs dl ON dl.id = ar.log_id
         WHERE ar.user_id = :user_id
           AND dl.log_date BETWEEN :start AND :end
         ORDER BY dl.log_date ASC, ar.approval_type ASC',
        [
            ':user_id' => (int) ($profileUser['id'] ?? 0),
            ':start' => $profileChallengeStart,
            ':end' => $profileChallengeEnd,
        ]
    );
    $approvalsByDate = [];
    foreach ($approvalRows as $approvalRow) {
        $logDate = (string) ($approvalRow['log_date'] ?? '');
        if ($logDate === '') {
            continue;
        }
        $approvalType = (string) ($approvalRow['approval_type'] ?? '');
        if ($approvalType === '') {
            continue;
        }
        if (!isset($approvalsByDate[$logDate])) {
            $approvalsByDate[$logDate] = [];
        }
        $approvalsByDate[$logDate][$approvalType] = [
            'status' => (string) ($approvalRow['status'] ?? ''),
            'detail' => trim((string) ($approvalRow['detail'] ?? '')),
        ];
    }

    $habitLabelsByCode = [];
    foreach ($habitDefinitions as $habitDefinition) {
        $habitLabelsByCode[(string) $habitDefinition['code']] = (string) $habitDefinition['label'];
    }
    $personalGoals = list_goals($pdo, 'user', (int) $profileUser['id']);
    $habitGoalCodes = [];
    foreach ($personalGoals as $goal) {
        $goalType = normalize_goal_target_type((string) ($goal['target_type'] ?? ''));
        if ((string) ($goal['status'] ?? 'active') !== 'active' || !str_starts_with($goalType, 'habit:')) {
            continue;
        }
        $habitCode = substr($goalType, 6);
        if ($habitCode !== '') {
            $habitGoalCodes[$habitCode] = true;
        }
    }
    $habitGoalCodesList = array_values(array_keys($habitGoalCodes));

    $rangeStart = new DateTimeImmutable($profileChallengeStart);
    $rangeEnd = new DateTimeImmutable($profileChallengeEnd);
    if ($rangeEnd < $rangeStart) {
        $rangeEnd = $rangeStart;
    }

    $profileDailyDetails = [];
    $profileDailyPhotoNutrition = [];
    $dailyHasInput = static function (?array $log, array $workouts, array $habitsForPdf, string $stepReason, string $workoutReason, array $approvalsForDate): bool {
        if ($log === null) {
            return false;
        }
        $hasApproval = false;
        foreach ($approvalsForDate as $approval) {
            if (!is_array($approval)) {
                continue;
            }
            if (trim((string) ($approval['status'] ?? '')) !== '' || trim((string) ($approval['detail'] ?? '')) !== '') {
                $hasApproval = true;
                break;
            }
        }

        return (int) ($log['steps'] ?? 0) > 0
            || (float) ($log['distance_km'] ?? 0) > 0
            || $workouts !== []
            || (($log['training_calories_burned'] ?? null) !== null && (float) $log['training_calories_burned'] > 0)
            || ($log['weight'] ?? null) !== null
            || (int) ($log['junk_food'] ?? 0) === 1
            || (int) ($log['extra_workout'] ?? 0) === 1
            || trim((string) ($log['notes'] ?? '')) !== ''
            || $stepReason !== ''
            || $workoutReason !== ''
            || $hasApproval
            || $habitsForPdf !== [];
    };
    $nutritionHasInput = static function (array $photos, array $nutritionTotals, array $photoItems): bool {
        if ($photos !== [] || $photoItems !== []) {
            return true;
        }
        foreach ($nutritionTotals as $value) {
            if ((float) $value > 0) {
                return true;
            }
        }

        return false;
    };
    foreach (day_sequence($rangeStart, $rangeEnd) as $day) {
        $date = $day->format('Y-m-d');
        $log = $logsByDate[$date] ?? null;
        $workouts = is_array($log['workouts'] ?? null) ? array_values((array) $log['workouts']) : [];
        $workoutTypes = [];
        foreach ($workouts as $workout) {
            if (!is_array($workout)) {
                continue;
            }
            $workoutType = trim((string) ($workout['workout_type'] ?? ''));
            if ($workoutType === '') {
                continue;
            }
            $workoutTypes[] = $workoutType;
        }

        $habitValues = [];
        foreach ($habitDefinitions as $habitDefinition) {
            $code = (string) ($habitDefinition['code'] ?? '');
            if ($code === '') {
                continue;
            }
            $habitValue = !empty($log['habits'][$code]) && (int) ($log['habits'][$code]['value'] ?? 0) === 1 ? 1 : 0;
            $habitValues[] = [
                'code' => $code,
                'label' => (string) ($habitLabelsByCode[$code] ?? $code),
                'value' => $habitValue,
            ];
        }
        $habitValuesForPdf = array_values(array_filter(
            $habitValues,
            static fn(array $habit): bool => (int) ($habit['value'] ?? 0) === 1 || isset($habitGoalCodes[(string) ($habit['code'] ?? '')])
        ));

        $stepReason = trim((string) ($log['step_exception_reason'] ?? ''));
        $workoutReason = trim((string) ($log['workout_exception_reason'] ?? ''));
        $combinedReason = $stepReason !== '' ? $stepReason : $workoutReason;
        $approvalsForDate = is_array($approvalsByDate[$date] ?? null) ? (array) $approvalsByDate[$date] : [];

        if ($dailyHasInput($log, $workouts, $habitValuesForPdf, $stepReason, $workoutReason, $approvalsForDate)) {
            $profileDailyDetails[] = [
                'date' => $date,
                'steps' => (int) ($log['steps'] ?? 0),
                'distance_km' => round((float) ($log['distance_km'] ?? 0), 2),
                'workout_count' => count($workouts),
                'workout_counted' => count($workouts) > 0 ? 1 : 0,
                'workout_types' => $workoutTypes,
                'training_calories_burned' => ($log['training_calories_burned'] ?? null) !== null ? round((float) $log['training_calories_burned'], 2) : null,
                'weight' => ($log['weight'] ?? null) !== null ? round((float) $log['weight'], 2) : null,
                'junk_food' => (int) ($log['junk_food'] ?? 0) === 1 ? 1 : 0,
                'extra_workout' => (int) ($log['extra_workout'] ?? 0) === 1 ? 1 : 0,
                'notes' => trim((string) ($log['notes'] ?? '')),
                'missing_reason' => $combinedReason,
                'step_exception_reason' => $stepReason,
                'workout_exception_reason' => $workoutReason,
                'approval_step_status' => (string) ($approvalsForDate[APPROVAL_TYPE_STEP_EXCEPTION]['status'] ?? ''),
                'approval_step_detail' => (string) ($approvalsForDate[APPROVAL_TYPE_STEP_EXCEPTION]['detail'] ?? ''),
                'approval_workout_status' => (string) ($approvalsForDate[APPROVAL_TYPE_WORKOUT_EXCEPTION]['status'] ?? ''),
                'approval_workout_detail' => (string) ($approvalsForDate[APPROVAL_TYPE_WORKOUT_EXCEPTION]['detail'] ?? ''),
                'approval_extra_status' => (string) ($approvalsForDate[APPROVAL_TYPE_EXTRA_WORKOUT_OVERRIDE]['status'] ?? ''),
                'approval_extra_detail' => (string) ($approvalsForDate[APPROVAL_TYPE_EXTRA_WORKOUT_OVERRIDE]['detail'] ?? ''),
                'habits' => $habitValuesForPdf,
            ];
        }

        $photos = is_array($photosByDate[$date] ?? null) ? (array) $photosByDate[$date] : [];
        $nutritionTotals = [
            'calories' => 0.0,
            'protein_g' => 0.0,
            'carbs_g' => 0.0,
            'fat_g' => 0.0,
            'fiber_g' => 0.0,
            'sugar_g' => 0.0,
            'sodium_mg' => 0.0,
        ];
        $photoItems = [];
        foreach ($photos as $photo) {
            $nutritionTotals['calories'] += (float) ($photo['calories'] ?? 0);
            $nutritionTotals['protein_g'] += (float) ($photo['protein_g'] ?? 0);
            $nutritionTotals['carbs_g'] += (float) ($photo['carbs_g'] ?? 0);
            $nutritionTotals['fat_g'] += (float) ($photo['fat_g'] ?? 0);
            $nutritionTotals['fiber_g'] += (float) ($photo['fiber_g'] ?? 0);
            $nutritionTotals['sugar_g'] += (float) ($photo['sugar_g'] ?? 0);
            $nutritionTotals['sodium_mg'] += (float) ($photo['sodium_mg'] ?? 0);
            $photoItems[] = [
                'category' => (string) ($photo['category'] ?? ''),
                'caption' => trim((string) ($photo['caption'] ?? '')),
                'calories' => ($photo['calories'] ?? null) !== null ? round((float) $photo['calories'], 2) : null,
                'protein_g' => ($photo['protein_g'] ?? null) !== null ? round((float) $photo['protein_g'], 2) : null,
                'carbs_g' => ($photo['carbs_g'] ?? null) !== null ? round((float) $photo['carbs_g'], 2) : null,
                'fat_g' => ($photo['fat_g'] ?? null) !== null ? round((float) $photo['fat_g'], 2) : null,
                'fiber_g' => ($photo['fiber_g'] ?? null) !== null ? round((float) $photo['fiber_g'], 2) : null,
                'sugar_g' => ($photo['sugar_g'] ?? null) !== null ? round((float) $photo['sugar_g'], 2) : null,
                'sodium_mg' => ($photo['sodium_mg'] ?? null) !== null ? round((float) $photo['sodium_mg'], 2) : null,
            ];
        }
        if ($nutritionHasInput($photos, $nutritionTotals, $photoItems)) {
            $profileDailyPhotoNutrition[] = [
                'date' => $date,
                'photo_count' => count($photos),
                'totals' => [
                    'calories' => round($nutritionTotals['calories'], 2),
                    'protein_g' => round($nutritionTotals['protein_g'], 2),
                    'carbs_g' => round($nutritionTotals['carbs_g'], 2),
                    'fat_g' => round($nutritionTotals['fat_g'], 2),
                    'fiber_g' => round($nutritionTotals['fiber_g'], 2),
                    'sugar_g' => round($nutritionTotals['sugar_g'], 2),
                    'sodium_mg' => round($nutritionTotals['sodium_mg'], 2),
                ],
                'items' => $photoItems,
            ];
        }
    }

    $profileWeeklySummary = [];
    foreach ((array) ($profileMetric['weekly'] ?? []) as $weekRow) {
        $stepRequired = max(0, (int) ($weekRow['step_days_required_week'] ?? 0));
        $stepSuccess = max(0, (int) ($weekRow['step_days_success_week'] ?? 0));
        $workoutTarget = max(0, (int) ($weekRow['workout_target_week'] ?? 0));
        $workoutSuccess = max(0, (int) ($weekRow['workout_success_week'] ?? 0));
        $progressParts = [];
        if ($stepRequired > 0) {
            $progressParts[] = min(100.0, ($stepSuccess / $stepRequired) * 100);
        }
        if ($workoutTarget > 0) {
            $progressParts[] = min(100.0, ($workoutSuccess / $workoutTarget) * 100);
        }
        $progressPct = $progressParts !== [] ? round(array_sum($progressParts) / count($progressParts), 1) : 0.0;
        $profileWeeklySummary[] = [
            'week_start' => (string) ($weekRow['week_start'] ?? ''),
            'week_end' => (string) ($weekRow['week_end'] ?? ''),
            'status' => (string) ($weekRow['status'] ?? ''),
            'steps' => (int) ($weekRow['steps'] ?? 0),
            'distance_km' => round((float) ($weekRow['km'] ?? 0), 2),
            'workouts' => (int) ($weekRow['workouts'] ?? 0),
            'step_success' => $stepSuccess,
            'step_required' => $stepRequired,
            'workout_success' => $workoutSuccess,
            'workout_target' => $workoutTarget,
            'progress_pct' => $progressPct,
            'failures' => (int) ($weekRow['total_failures'] ?? 0),
            'strikes_after_week' => (int) ($weekRow['strikes_after_week'] ?? 0),
            'penalty' => (float) ($weekRow['penalty'] ?? 0),
        ];
    }

    $profileMonthlySummaryByKey = [];
    $ensureMonthSummary = static function (array &$rows, string $date): array {
        $key = substr($date, 0, 7);
        if (!isset($rows[$key])) {
            $rows[$key] = [
                'month' => $key,
                'label' => localized_month_label($date),
                'input_days' => 0,
                'photo_days' => 0,
                'photo_count' => 0,
                'steps' => 0,
                'distance_km' => 0.0,
                'workouts' => 0,
                'training_calories_burned' => 0.0,
                'calories' => 0.0,
                'protein_g' => 0.0,
                'carbs_g' => 0.0,
                'fat_g' => 0.0,
                'weights' => [],
                'progress_values' => [],
            ];
        }

        return $rows[$key];
    };
    foreach ($profileDailyDetails as $day) {
        $date = (string) ($day['date'] ?? '');
        if ($date === '') {
            continue;
        }
        $month = $ensureMonthSummary($profileMonthlySummaryByKey, $date);
        $month['input_days']++;
        $month['steps'] += (int) ($day['steps'] ?? 0);
        $month['distance_km'] += (float) ($day['distance_km'] ?? 0);
        $month['workouts'] += (int) ($day['workout_count'] ?? 0);
        $month['training_calories_burned'] += (float) ($day['training_calories_burned'] ?? 0);
        if (($day['weight'] ?? null) !== null) {
            $month['weights'][] = (float) $day['weight'];
        }
        $profileMonthlySummaryByKey[substr($date, 0, 7)] = $month;
    }
    foreach ($profileDailyPhotoNutrition as $day) {
        $date = (string) ($day['date'] ?? '');
        if ($date === '') {
            continue;
        }
        $month = $ensureMonthSummary($profileMonthlySummaryByKey, $date);
        $totals = is_array($day['totals'] ?? null) ? (array) $day['totals'] : [];
        $month['photo_days']++;
        $month['photo_count'] += (int) ($day['photo_count'] ?? 0);
        $month['calories'] += (float) ($totals['calories'] ?? 0);
        $month['protein_g'] += (float) ($totals['protein_g'] ?? 0);
        $month['carbs_g'] += (float) ($totals['carbs_g'] ?? 0);
        $month['fat_g'] += (float) ($totals['fat_g'] ?? 0);
        $profileMonthlySummaryByKey[substr($date, 0, 7)] = $month;
    }
    foreach ($profileWeeklySummary as $week) {
        $date = (string) ($week['week_start'] ?? '');
        if ($date === '') {
            continue;
        }
        $month = $ensureMonthSummary($profileMonthlySummaryByKey, $date);
        $month['progress_values'][] = (float) ($week['progress_pct'] ?? 0);
        $profileMonthlySummaryByKey[substr($date, 0, 7)] = $month;
    }
    ksort($profileMonthlySummaryByKey);
    $profileMonthlySummary = [];
    foreach ($profileMonthlySummaryByKey as $month) {
        $weights = (array) ($month['weights'] ?? []);
        $progressValues = (array) ($month['progress_values'] ?? []);
        $profileMonthlySummary[] = [
            'month' => (string) ($month['month'] ?? ''),
            'label' => (string) ($month['label'] ?? ''),
            'input_days' => (int) ($month['input_days'] ?? 0),
            'photo_days' => (int) ($month['photo_days'] ?? 0),
            'photo_count' => (int) ($month['photo_count'] ?? 0),
            'steps' => (int) ($month['steps'] ?? 0),
            'distance_km' => round((float) ($month['distance_km'] ?? 0), 2),
            'workouts' => (int) ($month['workouts'] ?? 0),
            'training_calories_burned' => round((float) ($month['training_calories_burned'] ?? 0), 2),
            'calories' => round((float) ($month['calories'] ?? 0), 2),
            'protein_g' => round((float) ($month['protein_g'] ?? 0), 2),
            'carbs_g' => round((float) ($month['carbs_g'] ?? 0), 2),
            'fat_g' => round((float) ($month['fat_g'] ?? 0), 2),
            'avg_weight' => $weights !== [] ? round(array_sum($weights) / count($weights), 2) : null,
            'weight_change' => count($weights) > 1 ? round($weights[count($weights) - 1] - $weights[0], 2) : null,
            'progress_pct' => $progressValues !== [] ? round(array_sum($progressValues) / count($progressValues), 1) : 0.0,
        ];
    }
    $nutritionTotalsForPdf = [
        'calories' => 0.0,
        'protein_g' => 0.0,
        'carbs_g' => 0.0,
        'fat_g' => 0.0,
        'fiber_g' => 0.0,
        'sugar_g' => 0.0,
        'sodium_mg' => 0.0,
    ];
    $photoCountForPdf = 0;
    foreach ($profileDailyPhotoNutrition as $day) {
        $photoCountForPdf += (int) ($day['photo_count'] ?? 0);
        $totals = is_array($day['totals'] ?? null) ? (array) $day['totals'] : [];
        foreach ($nutritionTotalsForPdf as $key => $value) {
            $nutritionTotalsForPdf[$key] = $value + (float) ($totals[$key] ?? 0);
        }
    }
    $progressValuesForPdf = array_map(static fn(array $week): float => (float) ($week['progress_pct'] ?? 0), $profileWeeklySummary);
    $weightValuesForPdf = array_values(array_filter(array_map(
        static fn(array $day): ?float => ($day['weight'] ?? null) !== null ? (float) $day['weight'] : null,
        $profileDailyDetails
    ), static fn(?float $value): bool => $value !== null));
    $profileTotalSummary = [
        'input_days' => count($profileDailyDetails),
        'photo_days' => count($profileDailyPhotoNutrition),
        'photo_count' => $photoCountForPdf,
        'steps' => (int) ($profileMetric['total_steps'] ?? 0),
        'distance_km' => round((float) ($profileMetric['total_km'] ?? 0), 2),
        'workouts' => (int) max((int) ($profileMetric['workout_count'] ?? 0), (int) ($profileMetric['workout_success'] ?? 0)),
        'training_calories_burned' => round(array_sum(array_map(static fn(array $day): float => (float) ($day['training_calories_burned'] ?? 0), $profileDailyDetails)), 2),
        'nutrition' => array_map(static fn(float $value): float => round($value, 2), $nutritionTotalsForPdf),
        'avg_progress_pct' => $progressValuesForPdf !== [] ? round(array_sum($progressValuesForPdf) / count($progressValuesForPdf), 1) : 0.0,
        'failures' => array_sum(array_map(static fn(array $week): int => (int) ($week['failures'] ?? 0), $profileWeeklySummary)),
        'strikes' => (int) ($profileMetric['current_strikes'] ?? 0),
        'penalty' => (float) ($profileMetric['total_penalty'] ?? 0),
        'first_weight' => $weightValuesForPdf !== [] ? $weightValuesForPdf[0] : null,
        'avg_weight' => $weightValuesForPdf !== [] ? round(array_sum($weightValuesForPdf) / count($weightValuesForPdf), 2) : null,
        'latest_weight' => $weightValuesForPdf !== [] ? $weightValuesForPdf[count($weightValuesForPdf) - 1] : null,
        'weight_change' => count($weightValuesForPdf) > 1 ? round($weightValuesForPdf[count($weightValuesForPdf) - 1] - $weightValuesForPdf[0], 2) : null,
    ];

    if (!$profileSelectedChallengeIsArchive && challenge_is_active($settings)) {
        evaluate_automatic_achievements($pdo, $metrics);
    }
    $profileUser = db_fetch_one($pdo, 'SELECT * FROM users WHERE id = :id', [':id' => (int) $profileUser['id']]) ?? $profileUser;

    $profileGoalCards = build_user_goal_view_models($personalGoals, is_array($profileMetric) ? $profileMetric : [], $habitDefinitions);

    render_view('profile', [
        'title' => t('profile.title'),
        'currentPage' => 'profile',
        'currentUser' => $currentUser,
        'profileUser' => $profileUser,
        'profileMetric' => $profileMetric,
        'isOwnProfile' => $isOwnProfile,
        'canEditProfile' => $canEditProfile,
        'canExportProfilePdf' => $isOwnProfile || is_admin($currentUser),
        'profileDistanceWeekly' => $profileDistanceWeekly,
        'profileWorkoutWeekly' => $profileWorkoutWeekly,
        'profileScoreWeekly' => $profileScoreWeekly,
        'profileChallengeRange' => [
            'start' => $profileChallengeStart,
            'end' => $profileChallengeEnd,
            'name' => (string) ($profileSelectedChallenge['name'] ?? ''),
            'is_archive' => $profileSelectedChallengeIsArchive,
        ],
        'profileChallengeOptions' => $profileChallengeOptions,
        'profileSelectedChallengeKey' => $profileSelectedChallengeKey,
        'profileSelectedChallenge' => $profileSelectedChallenge,
        'profileDailyDetails' => $profileDailyDetails,
        'profileDailyPhotoNutrition' => $profileDailyPhotoNutrition,
        'profileWeeklySummary' => $profileWeeklySummary,
        'profileMonthlySummary' => $profileMonthlySummary,
        'profileTotalSummary' => $profileTotalSummary,
        'habitGoalCodes' => $habitGoalCodesList,
        'profileBaseUrl' => $profileUrl(),
        'personalGoals' => $personalGoals,
        'profileGoalCards' => $profileGoalCards,
        'userAchievements' => list_awarded_achievements($pdo, (int) $profileUser['id'], null),
        'canDeleteAchievements' => $canDeleteProfileAchievements,
        'recentActivity' => fetch_audit_logs($pdo, ['actor_user_id' => (int) $profileUser['id']], 30),
        'habits' => $habitDefinitions,
        'config' => $config,
    ]);
}

if ($page === 'achievements') {
    $scope = (string) ($_GET['scope'] ?? 'user');
    $scope = $scope === 'team' ? 'team' : 'user';
    $settings = challenge_settings($pdo, $config);
    $achievementOwner = null;
    $achievementsMetrics = [];
    $achievementUserId = null;
    $achievementTeamId = null;
    $canDeleteAchievements = false;
    $backHref = '/?page=profile';

    if ($scope === 'team') {
        $achievementTeamId = isset($_GET['team_id']) ? (int) $_GET['team_id'] : 0;
        if ($achievementTeamId <= 0) {
            $userTeams = list_user_teams($pdo, (int) $currentUser['id']);
            $achievementTeamId = (int) ($userTeams[0]['id'] ?? 0);
        }
        $team = $achievementTeamId > 0
            ? db_fetch_one($pdo, 'SELECT * FROM teams WHERE id = :id', [':id' => $achievementTeamId])
            : null;
        if ($team === null) {
            flash_set('error', t('flash.not_found'));
            redirect('/?page=team');
        }

        $isMember = db_fetch_one(
            $pdo,
            'SELECT id FROM team_memberships WHERE team_id = :team_id AND user_id = :user_id AND active = 1 LIMIT 1',
            [':team_id' => (int) $team['id'], ':user_id' => (int) $currentUser['id']]
        ) !== null;
        if (!$isMember && !is_admin($currentUser)) {
            flash_set('error', t('flash.no_permission'));
            redirect('/?page=team');
        }

        $teamUsers = list_active_team_users($pdo, (int) $team['id']);
        $achievementsMetrics = compute_challenge_metrics(
            $pdo,
            $teamUsers,
            (string) $settings['challenge_start'],
            (string) $settings['challenge_end']
        );
        $achievementsMetrics = apply_strike_review_overrides_to_metrics($pdo, $achievementsMetrics);
        evaluate_automatic_achievements($pdo, $achievementsMetrics, (int) $team['id']);
        $achievementOwner = $team;
        $achievementTeamId = (int) $team['id'];
        $canDeleteAchievements = can_manage_team($pdo, $currentUser, $achievementTeamId);
        $backHref = '/?' . http_build_query(['page' => 'team', 'team_id' => $achievementTeamId]);
    } else {
        $achievementUserId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : (int) $currentUser['id'];
        if ($achievementUserId <= 0) {
            $achievementUserId = (int) $currentUser['id'];
        }
        $profileUser = db_fetch_one($pdo, 'SELECT * FROM users WHERE id = :id AND active = 1', [':id' => $achievementUserId]);
        if ($profileUser === null) {
            flash_set('error', t('flash.no_permission'));
            redirect('/?page=profile');
        }

        $isOwnProfile = (int) $profileUser['id'] === (int) $currentUser['id'];
        $sharesTeamWithTarget = false;
        if (!$isOwnProfile && !is_admin($currentUser)) {
            $sharesTeamWithTarget = db_fetch_one(
                $pdo,
                'SELECT tm1.team_id
                 FROM team_memberships tm1
                 JOIN team_memberships tm2 ON tm2.team_id = tm1.team_id
                 WHERE tm1.user_id = :viewer_id AND tm1.active = 1
                   AND tm2.user_id = :target_id AND tm2.active = 1
                 LIMIT 1',
                [
                    ':viewer_id' => (int) $currentUser['id'],
                    ':target_id' => (int) $profileUser['id'],
                ]
            ) !== null;
        }
        if (!$isOwnProfile && !is_admin($currentUser) && !$sharesTeamWithTarget) {
            flash_set('error', t('flash.no_permission'));
            redirect('/?page=profile');
        }

        $achievementsMetrics = compute_challenge_metrics(
            $pdo,
            [$profileUser],
            (string) $settings['challenge_start'],
            (string) $settings['challenge_end']
        );
        $achievementsMetrics = apply_strike_review_overrides_to_metrics($pdo, $achievementsMetrics);
        evaluate_automatic_achievements($pdo, $achievementsMetrics);
        $achievementOwner = $profileUser;
        $achievementUserId = (int) $profileUser['id'];
        $canDeleteAchievements = is_admin($currentUser) || $isOwnProfile;
        if ((string) ($_GET['back'] ?? '') === 'dashboard') {
            $backParams = ['page' => 'dashboard', 'user_id' => $achievementUserId];
            $backView = trim((string) ($_GET['view'] ?? ''));
            if ($backView !== '') {
                $backParams['view'] = $backView;
            }
        } else {
            $backParams = ['page' => 'profile'];
            if (!$isOwnProfile) {
                $backParams['user_id'] = $achievementUserId;
            }
        }
        $backHref = '/?' . http_build_query($backParams);
    }

    $pageParams = ['page' => 'achievements', 'scope' => $scope];
    if ($scope === 'team') {
        $pageParams['team_id'] = $achievementTeamId;
    } else {
        $pageParams['user_id'] = $achievementUserId;
        if ((string) ($_GET['back'] ?? '') === 'dashboard') {
            $pageParams['back'] = 'dashboard';
            $backView = trim((string) ($_GET['view'] ?? ''));
            if ($backView !== '') {
                $pageParams['view'] = $backView;
            }
        }
    }
    $achievementsUrl = '/?' . http_build_query($pageParams);

    if (is_post()) {
        if (!csrf_verify()) {
            flash_set('error', t('flash.csrf'));
            redirect($achievementsUrl);
        }
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'delete_achievement_award') {
            if (!$canDeleteAchievements) {
                flash_set('error', t('flash.no_permission'));
                redirect($achievementsUrl);
            }
            $awardId = (int) ($_POST['award_id'] ?? 0);
            $award = $awardId > 0
                ? db_fetch_one($pdo, 'SELECT * FROM achievement_awards WHERE id = :id', [':id' => $awardId])
                : null;
            $allowedAward = false;
            if ($award !== null && $scope === 'team') {
                $allowedAward = (int) ($award['team_id'] ?? 0) === $achievementTeamId;
            } elseif ($award !== null) {
                $allowedAward = (int) ($award['user_id'] ?? 0) === $achievementUserId;
            }
            if ($allowedAward) {
                delete_achievement_award($pdo, $awardId, (int) $currentUser['id']);
                flash_set('success', t('flash.achievement_deleted'));
            } else {
                flash_set('error', t('flash.no_permission'));
            }
            redirect($achievementsUrl);
        }
    }

    $achievementsAll = list_achievement_collection($pdo, $scope, $achievementUserId, $achievementTeamId, $achievementsMetrics);

    render_view('achievements', [
        'title' => t('achievements.title'),
        'currentPage' => 'achievements',
        'currentUser' => $currentUser,
        'achievementScope' => $scope,
        'achievementOwner' => $achievementOwner,
        'achievementsAll' => $achievementsAll,
        'canDeleteAchievements' => $canDeleteAchievements,
        'achievementsUrl' => $achievementsUrl,
        'backHref' => $backHref,
        'config' => $config,
    ]);
}

if ($page === 'admin') {
    require_admin($currentUser);

    if (is_post()) {
        if (!csrf_verify()) {
            flash_set('error', t('flash.csrf'));
            redirect('/?page=admin');
        }

        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'update_app_name') {
            set_app_setting($pdo, 'app_name', trim((string) ($_POST['app_name'] ?? '')) ?: (string) ($config['app_name'] ?? 'Fitness Challenge Tracker'), (int) $currentUser['id']);
            flash_set('success', t('flash.app_name_updated'));
            redirect('/?page=admin');
        }

        if ($action === 'update_penalties_feature') {
            set_app_setting($pdo, 'penalties_enabled', bool_from_form('penalties_enabled') === 1 ? '1' : '0', (int) $currentUser['id']);
            flash_set('success', t('flash.penalties_feature_updated'));
            redirect('/?page=admin&section=app');
        }

        if ($action === 'update_notion_settings') {
            notion_update_settings($pdo, $_POST, (int) $currentUser['id']);
            flash_set('success', t('flash.notion_settings_updated'));
            redirect('/?page=admin&section=notion');
        }

        if ($action === 'notion_oauth_start') {
            $notionSettings = notion_settings($pdo);
            if (!notion_oauth_configured($notionSettings) || notion_oauth_redirect_uri($notionSettings) === '') {
                flash_set('error', t('flash.notion_oauth_not_configured'));
                redirect('/?page=admin&section=notion');
            }
            $notionOauthState = bin2hex(random_bytes(16));
            $_SESSION['notion_oauth_state'] = $notionOauthState;
            redirect(notion_oauth_authorize_url($notionSettings, $notionOauthState));
        }

        if ($action === 'notion_oauth_disconnect') {
            notion_oauth_disconnect($pdo, (int) $currentUser['id']);
            flash_set('success', t('flash.notion_oauth_disconnected'));
            redirect('/?page=admin&section=notion');
        }

        if ($action === 'notion_create_database') {
            $notionParentPage = trim((string) ($_POST['notion_parent_page_id'] ?? ''));
            set_app_setting($pdo, 'notion_parent_page_id', $notionParentPage, (int) $currentUser['id']);
            $notionCreate = notion_create_database(notion_settings($pdo), $notionParentPage);
            if ($notionCreate['ok']) {
                set_app_setting($pdo, 'notion_database_id', $notionCreate['database_id'], (int) $currentUser['id']);
                notion_refresh_schema_cache($pdo, (int) $currentUser['id']);
                flash_set('success', t('flash.notion_db_created'));
            } else {
                flash_set('error', trim(t('flash.notion_db_create_failed') . ' ' . (string) $notionCreate['error']));
            }
            redirect('/?page=admin&section=notion');
        }

        if ($action === 'notion_load_schema') {
            $schemaResult = notion_refresh_schema_cache($pdo, (int) $currentUser['id']);
            if ($schemaResult['ok']) {
                flash_set('success', t('flash.notion_schema_loaded', ['count' => (int) $schemaResult['count']]));
            } else {
                flash_set('error', trim(t('flash.notion_schema_failed') . ' ' . (string) $schemaResult['error']));
            }
            redirect('/?page=admin&section=notion');
        }

        if ($action === 'update_notion_field_map') {
            notion_save_field_map($pdo, $_POST, (int) $currentUser['id']);
            flash_set('success', t('flash.notion_mapping_updated'));
            redirect('/?page=admin&section=notion');
        }

        if ($action === 'update_telegram_settings') {
            telegram_update_settings($pdo, $_POST, (int) $currentUser['id']);
            flash_set('success', t('flash.telegram_settings_updated'));
            redirect('/?page=admin&section=telegram');
        }

        if ($action === 'telegram_verify_bot') {
            $telegramVerify = telegram_verify_bot($pdo, (int) $currentUser['id']);
            if ($telegramVerify['ok']) {
                flash_set('success', t('flash.telegram_verified', ['username' => (string) $telegramVerify['username']]));
            } else {
                flash_set('error', trim(t('flash.telegram_verify_failed') . ' ' . (string) $telegramVerify['error']));
            }
            redirect('/?page=admin&section=telegram');
        }

        if ($action === 'telegram_admin_unlink') {
            $telegramUnlinkUserId = (int) ($_POST['user_id'] ?? 0);
            if ($telegramUnlinkUserId > 0) {
                telegram_unlink_user($pdo, $telegramUnlinkUserId);
                flash_set('success', t('flash.telegram_admin_unlinked'));
            }
            redirect('/?page=admin&section=telegram');
        }

        if ($action === 'notion_sync_now') {
            $notionResult = notion_sync_run($pdo, $config, (int) $currentUser['id']);
            flash_set($notionResult['ok'] ? 'success' : 'error', trim(t('flash.notion_sync_done') . ' ' . (string) ($notionResult['message'] ?? '')));
            redirect('/?page=admin&section=notion');
        }

        if ($action === 'update_challenge_settings') {
            update_challenge_settings(
                $pdo,
                (string) ($_POST['challenge_name'] ?? ''),
                (string) ($_POST['challenge_start'] ?? ''),
                (string) ($_POST['challenge_end'] ?? ''),
                (int) $currentUser['id']
            );
            flash_set('success', t('flash.challenge_updated'));
            redirect('/?page=admin&section=challenge');
        }

        if ($action === 'start_new_challenge') {
            start_new_challenge(
                $pdo,
                (string) ($_POST['new_challenge_name'] ?? ''),
                (string) ($_POST['new_challenge_start'] ?? ''),
                (string) ($_POST['new_challenge_end'] ?? ''),
                (int) $currentUser['id']
            );
            flash_set('success', t('flash.challenge_started'));
            redirect('/?page=admin&section=challenge');
        }

        if ($action === 'archive_challenge') {
            if ((string) ($_POST['confirm_archive'] ?? '') === 'ARCHIVE') {
                archive_challenge($pdo, (int) $currentUser['id']);
                flash_set('success', t('flash.challenge_archived'));
            }
            redirect('/?page=admin&section=challenge');
        }

        if ($action === 'reactivate_challenge') {
            $archiveId = (int) ($_POST['archive_id'] ?? 0);
            if ($archiveId > 0 && reactivate_challenge($pdo, $archiveId, (int) $currentUser['id'])) {
                flash_set('success', t('flash.challenge_reactivated'));
            } else {
                flash_set('error', t('flash.challenge_reactivate_failed'));
            }
            redirect('/?page=admin&section=challenge');
        }

        if ($action === 'create_user') {
            $payload = [
                'username' => trim((string) ($_POST['username'] ?? '')),
                'display_name' => trim((string) ($_POST['display_name'] ?? '')),
                'password' => (string) ($_POST['password'] ?? ''),
                'role' => (string) ($_POST['role'] ?? 'user'),
                'step_goal' => max(0, (int) ($_POST['step_goal'] ?? 0)),
                'step_days_mask' => normalize_mask($_POST['step_days'] ?? []),
                'workout_target' => max(0, (int) ($_POST['workout_target'] ?? 0)),
                'workout_days_mask' => normalize_mask($_POST['workout_days'] ?? []),
                'workout_strict' => (int) ($_POST['workout_strict'] ?? 0) === 1 ? 1 : 0,
                'ideal_weight' => ($_POST['ideal_weight'] ?? '') !== '' ? (float) $_POST['ideal_weight'] : null,
                'primary_goal_type' => in_array(($_POST['primary_goal_type'] ?? 'steps'), ['steps', 'km'], true) ? (string) $_POST['primary_goal_type'] : 'steps',
                'primary_goal_value' => ($_POST['primary_goal_value'] ?? '') !== '' ? (float) $_POST['primary_goal_value'] : null,
                'active' => bool_from_form('active'),
            ];

            if ($payload['username'] === '' || $payload['display_name'] === '' || strlen($payload['password']) < 8) {
                flash_set('error', t('flash.user_required'));
                redirect('/?page=admin');
            }

            try {
                $beforeUsers = db_fetch_all($pdo, 'SELECT id FROM users ORDER BY id');
                create_user($pdo, $payload);
                $created = db_fetch_one($pdo, 'SELECT * FROM users WHERE username = :username', [':username' => $payload['username']]);
                if ($created !== null) {
                    $team = default_team($pdo);
                    set_team_membership($pdo, (int) $team['id'], (int) $created['id'], true, (int) $currentUser['id']);
                    audit_log($pdo, (int) $currentUser['id'], 'user_created', 'user', (string) $created['id'], 'User created.', ['users_before' => count($beforeUsers)], audit_snapshot($created, ['password_hash']));
                }
                flash_set('success', t('flash.user_created'));
            } catch (Throwable $e) {
                flash_set('error', t('flash.user_create_failed', ['error' => $e->getMessage()]));
            }

            redirect('/?page=admin');
        }

        if ($action === 'update_user') {
            $userId = (int) ($_POST['user_id'] ?? 0);
            $payload = [
                'display_name' => trim((string) ($_POST['display_name'] ?? '')),
                'password' => (string) ($_POST['password'] ?? ''),
                'role' => (string) ($_POST['role'] ?? 'user'),
                'step_goal' => max(0, (int) ($_POST['step_goal'] ?? 0)),
                'step_days_mask' => normalize_mask($_POST['step_days'] ?? []),
                'workout_target' => max(0, (int) ($_POST['workout_target'] ?? 0)),
                'workout_days_mask' => normalize_mask($_POST['workout_days'] ?? []),
                'workout_strict' => (int) ($_POST['workout_strict'] ?? 0) === 1 ? 1 : 0,
                'ideal_weight' => ($_POST['ideal_weight'] ?? '') !== '' ? (float) $_POST['ideal_weight'] : null,
                'primary_goal_type' => in_array(($_POST['primary_goal_type'] ?? 'steps'), ['steps', 'km'], true) ? (string) $_POST['primary_goal_type'] : 'steps',
                'primary_goal_value' => ($_POST['primary_goal_value'] ?? '') !== '' ? (float) $_POST['primary_goal_value'] : null,
                'active' => bool_from_form('active'),
            ];

            if ($payload['display_name'] === '') {
                flash_set('error', t('flash.display_name_required'));
                redirect('/?page=admin');
            }

            try {
                $before = db_fetch_one($pdo, 'SELECT * FROM users WHERE id = :id', [':id' => $userId]);
                update_user($pdo, $userId, $payload);
                $after = db_fetch_one($pdo, 'SELECT * FROM users WHERE id = :id', [':id' => $userId]);
                audit_log($pdo, (int) $currentUser['id'], 'user_updated', 'user', (string) $userId, 'User settings updated.', audit_snapshot($before, ['password_hash']), audit_snapshot($after, ['password_hash']));
                flash_set('success', t('flash.user_updated'));
            } catch (Throwable $e) {
                flash_set('error', t('flash.user_update_failed', ['error' => $e->getMessage()]));
            }

            redirect('/?page=admin');
        }

        if ($action === 'deactivate_workout_type') {
            deactivate_workout_type($pdo, (int) ($_POST['type_id'] ?? 0), (int) $currentUser['id']);
            flash_set('success', t('flash.workout_type_removed'));
            redirect('/?page=admin');
        }

        if ($action === 'update_workout_type') {
            rename_workout_type($pdo, (int) ($_POST['type_id'] ?? 0), (string) ($_POST['name'] ?? ''), bool_from_form('active') === 1, (int) $currentUser['id']);
            flash_set('success', t('flash.workout_type_updated'));
            redirect('/?page=admin&section=workout_types&type_id=' . (int) ($_POST['type_id'] ?? 0));
        }

        if ($action === 'create_workout_type') {
            $createdTypeId = save_workout_type_if_needed($pdo, (string) ($_POST['name'] ?? ''), (int) $currentUser['id']);
            flash_set('success', t('flash.workout_type_updated'));
            redirect('/?page=admin&section=workout_types' . ($createdTypeId !== null ? '&type_id=' . (int) $createdTypeId : ''));
        }

        if ($action === 'delete_workout_type') {
            delete_workout_type($pdo, (int) ($_POST['type_id'] ?? 0), (int) $currentUser['id']);
            flash_set('success', t('flash.workout_type_removed'));
            redirect('/?page=admin&section=workout_types');
        }

        if ($action === 'save_workout_type_field') {
            $typeId = (int) ($_POST['type_id'] ?? 0);
            try {
                save_workout_type_field(
                    $pdo,
                    $typeId,
                    !empty($_POST['field_id']) ? (int) $_POST['field_id'] : null,
                    (string) ($_POST['label'] ?? ''),
                    (string) ($_POST['input_kind'] ?? 'number'),
                    (string) ($_POST['data_key'] ?? ''),
                    bool_from_form('required') === 1,
                    bool_from_form('active') === 1,
                    (int) ($_POST['sort_order'] ?? 0),
                    (int) $currentUser['id']
                );
                flash_set('success', t('flash.workout_type_updated'));
            } catch (Throwable $e) {
                flash_set('error', $e->getMessage() !== '' ? $e->getMessage() : t('flash.save_failed'));
            }
            redirect('/?page=admin&section=workout_types&type_id=' . $typeId);
        }

        if ($action === 'delete_workout_type_field') {
            $typeId = (int) ($_POST['type_id'] ?? 0);
            delete_workout_type_field($pdo, (int) ($_POST['field_id'] ?? 0), (int) $currentUser['id']);
            flash_set('success', t('flash.workout_type_updated'));
            redirect('/?page=admin&section=workout_types&type_id=' . $typeId);
        }

        if ($action === 'save_habit') {
            save_habit_definition(
                $pdo,
                !empty($_POST['habit_id']) ? (int) $_POST['habit_id'] : null,
                (string) ($_POST['code'] ?? ''),
                (string) ($_POST['label'] ?? ''),
                bool_from_form('active') === 1,
                (int) ($_POST['sort_order'] ?? 0),
                (int) $currentUser['id']
            );
            flash_set('success', t('flash.habit_saved'));
            redirect('/?page=admin');
        }

        if ($action === 'delete_habit') {
            deactivate_habit_definition($pdo, (int) ($_POST['habit_id'] ?? 0), (int) $currentUser['id']);
            flash_set('success', t('flash.habit_saved'));
            redirect('/?page=admin&section=habits');
        }

        if ($action === 'create_achievement') {
            try {
                $translations = normalize_achievement_translations_input(
                    $_POST['translations'] ?? [],
                    (string) ($_POST['name'] ?? ''),
                    (string) ($_POST['description'] ?? ''),
                    (string) ($_POST['reward_text'] ?? '')
                );
                $englishTranslation = $translations['en'] ?? [];
                $name = trim((string) ($englishTranslation['name'] ?? ''));
                $description = trim((string) ($englishTranslation['description'] ?? ''));
                $rewardText = trim((string) ($englishTranslation['reward_text'] ?? ''));
                $code = trim((string) ($_POST['code'] ?? ''));
                $scope = (string) ($_POST['scope'] ?? 'user');
                $active = bool_from_form('active') === 1;
                $iconKey = normalize_achievement_icon_key((string) ($_POST['icon_key'] ?? 'trophy'));
                if ($name === '') {
                    throw new RuntimeException('Achievement name is required.');
                }

                $imagePath = null;
                if (!empty($_FILES['image']['name'])) {
                    $imagePath = save_uploaded_image($config, $_FILES['image'], 'achievements', 'achievement');
                }

                $conditionalEnabled = !empty($_POST['conditional']) || !empty($_POST['conditional_enabled']);
                if ($conditionalEnabled) {
                    create_conditional_achievement($pdo, [
                        'code' => $code,
                        'name' => $name,
                        'description' => $description,
                        'scope' => $scope,
                        'active' => $active ? 1 : 0,
                        'image_path' => $imagePath,
                        'icon_key' => $iconKey,
                        'reward_text' => $rewardText,
                        'translations' => $translations,
                        'metric_key' => (string) ($_POST['metric'] ?? ($_POST['metric_key'] ?? 'steps')),
                        'habit_code' => (string) ($_POST['habit_code'] ?? ''),
                        'operator' => (string) ($_POST['operator'] ?? '>='),
                        'target_value' => (float) ($_POST['target_amount'] ?? ($_POST['target_value'] ?? 1)),
                        'window' => (string) ($_POST['window'] ?? 'total'),
                    ], (int) $currentUser['id']);
                } else {
                    create_manual_achievement(
                        $pdo,
                        $name,
                        $description,
                        $scope,
                        (int) $currentUser['id'],
                        $imagePath,
                        $rewardText,
                        $code,
                        $active,
                        null,
                        $iconKey,
                        $translations
                    );
                }
                flash_set('success', t('flash.achievement_created'));
            } catch (Throwable $e) {
                flash_set('error', $e->getMessage() !== '' ? $e->getMessage() : 'Achievement could not be created.');
            }
            redirect('/?page=admin&section=achievements');
        }

        if ($action === 'update_achievement') {
            try {
                $achievementId = (int) ($_POST['achievement_id'] ?? 0);
                if ($achievementId <= 0) {
                    throw new RuntimeException('Achievement not found.');
                }

                $existing = db_fetch_one($pdo, 'SELECT * FROM achievements WHERE id = :id', [':id' => $achievementId]);
                if ($existing === null) {
                    throw new RuntimeException('Achievement not found.');
                }

                $imagePath = bool_from_form('remove_image') === 1 ? '' : (string) ($existing['image_path'] ?? '');
                if (!empty($_FILES['image']['name'])) {
                    $imagePath = save_uploaded_image($config, $_FILES['image'], 'achievements', 'achievement');
                }
                $translations = normalize_achievement_translations_input(
                    $_POST['translations'] ?? [],
                    (string) ($_POST['name'] ?? ($existing['name'] ?? '')),
                    (string) ($_POST['description'] ?? ($existing['description'] ?? '')),
                    (string) ($_POST['reward_text'] ?? ($existing['reward_text'] ?? ''))
                );
                $englishTranslation = $translations['en'] ?? [];

                update_achievement($pdo, $achievementId, [
                    'code' => trim((string) ($_POST['code'] ?? '')),
                    'name' => trim((string) ($englishTranslation['name'] ?? '')),
                    'scope' => (string) ($_POST['scope'] ?? 'user'),
                    'description' => trim((string) ($englishTranslation['description'] ?? '')),
                    'reward_text' => trim((string) ($englishTranslation['reward_text'] ?? '')),
                    'translations' => $translations,
                    'image_path' => $imagePath !== '' ? $imagePath : null,
                    'icon_key' => normalize_achievement_icon_key((string) ($_POST['icon_key'] ?? ($existing['icon_key'] ?? 'trophy'))),
                    'active' => bool_from_form('active') === 1,
                    'conditional_enabled' => bool_from_form('conditional_enabled') === 1,
                    'metric_key' => (string) ($_POST['metric'] ?? ($_POST['metric_key'] ?? 'steps')),
                    'habit_code' => (string) ($_POST['habit_code'] ?? ''),
                    'operator' => (string) ($_POST['operator'] ?? '>='),
                    'target_value' => (float) ($_POST['target_amount'] ?? ($_POST['target_value'] ?? 1)),
                    'window' => (string) ($_POST['window'] ?? 'total'),
                ], (int) $currentUser['id']);
                flash_set('success', t('flash.achievement_created'));
            } catch (Throwable $e) {
                flash_set('error', $e->getMessage() !== '' ? $e->getMessage() : 'Achievement could not be updated.');
            }
            redirect('/?page=admin&section=achievements');
        }

        if ($action === 'deactivate_achievement') {
            try {
                $achievementId = (int) ($_POST['achievement_id'] ?? 0);
                if ($achievementId <= 0) {
                    throw new RuntimeException('Achievement not found.');
                }
                deactivate_achievement($pdo, $achievementId, (int) $currentUser['id']);
                flash_set('success', t('flash.achievement_deleted'));
            } catch (Throwable $e) {
                flash_set('error', $e->getMessage() !== '' ? $e->getMessage() : 'Achievement could not be deactivated.');
            }
            redirect('/?page=admin&section=achievements');
        }

        if ($action === 'grant_achievement') {
            $scope = (string) ($_POST['scope'] ?? 'user');
            award_achievement(
                $pdo,
                (int) ($_POST['achievement_id'] ?? 0),
                $scope === 'user' ? (int) ($_POST['user_id'] ?? 0) : null,
                $scope === 'team' ? (int) ($_POST['team_id'] ?? 0) : null,
                (int) $currentUser['id'],
                trim((string) ($_POST['note'] ?? '')),
                true
            );
            flash_set('success', t('flash.achievement_granted'));
            redirect('/?page=admin&section=achievements');
        }

        if ($action === 'team_membership') {
            set_team_membership(
                $pdo,
                (int) ($_POST['team_id'] ?? 0),
                (int) ($_POST['user_id'] ?? 0),
                (string) ($_POST['member_action'] ?? 'add') === 'add',
                (int) $currentUser['id']
            );
            flash_set('success', t('flash.team_updated'));
            redirect('/?page=admin');
        }

        if ($action === 'team_settings') {
            update_team_settings(
                $pdo,
                (int) ($_POST['team_id'] ?? 0),
                (string) ($_POST['name'] ?? ''),
                (string) ($_POST['description'] ?? ''),
                (string) ($_POST['join_mode'] ?? 'closed'),
                (string) ($_POST['visibility'] ?? 'visible'),
                (int) $currentUser['id']
            );
            flash_set('success', t('flash.team_updated'));
            redirect('/?page=admin');
        }

        if ($action === 'delete_achievement_award') {
            $awardId = (int) ($_POST['award_id'] ?? 0);
            if ($awardId <= 0) {
                flash_set('error', 'Invalid achievement id.');
                redirect('/?page=admin&section=achievements');
            }
            delete_achievement_award($pdo, $awardId, (int) $currentUser['id']);
            flash_set('success', t('flash.achievement_deleted'));
            redirect('/?page=admin&section=achievements');
        }

        if ($action === 'create_motivational_quote') {
            try {
                create_motivational_quote($pdo, (string) ($_POST['quote_text'] ?? ''), (int) $currentUser['id']);
                flash_set('success', t('flash.motivational_quote_created'));
            } catch (Throwable $e) {
                flash_set('error', $e->getMessage() !== '' ? $e->getMessage() : 'Motivational quote could not be created.');
            }
            redirect('/?page=admin&section=motivational_quotes');
        }

        if ($action === 'resolve_join_request') {
            resolve_team_join_request($pdo, (int) ($_POST['request_id'] ?? 0), (string) ($_POST['decision'] ?? '') === 'approve', (int) $currentUser['id']);
            flash_set('success', t('flash.team_updated'));
            redirect('/?page=admin');
        }

        if ($action === 'update_backup_settings') {
            $enabled = bool_from_form('backup_auto_enabled') === 1;
            $frequency = normalize_backup_frequency((string) ($_POST['backup_frequency'] ?? 'daily'));
            $runTime = normalize_backup_run_time((string) ($_POST['backup_run_time'] ?? '00:00'));
            $retention = max(1, min(200, (int) ($_POST['backup_retention_count'] ?? 20)));
            set_app_setting($pdo, 'backup_auto_enabled', $enabled ? '1' : '0', (int) $currentUser['id']);
            set_app_setting($pdo, 'backup_frequency', $frequency, (int) $currentUser['id']);
            set_app_setting($pdo, 'backup_run_time', $runTime, (int) $currentUser['id']);
            set_app_setting($pdo, 'backup_retention_count', (string) $retention, (int) $currentUser['id']);
            flash_set('success', t('flash.backup_settings_saved'));
            redirect('/?page=admin&section=backups');
        }

        if ($action === 'create_backup_now') {
            try {
                $backup = create_system_backup($pdo, $config, 'manual', (int) $currentUser['id']);
                $settings = system_backup_settings($pdo);
                $retention = max(1, (int) ($settings['retention_count'] ?? 20));
                prune_system_backups($pdo, $config, $retention);
                audit_log(
                    $pdo,
                    (int) $currentUser['id'],
                    'backup_created',
                    'system_backup',
                    (string) ($backup['id'] ?? ''),
                    'Manual backup created.',
                    null,
                    [
                        'trigger' => 'manual',
                        'file_path' => (string) ($backup['file_path'] ?? ''),
                    ]
                );
                flash_set('success', t('flash.backup_created'));
            } catch (Throwable $e) {
                audit_log(
                    $pdo,
                    (int) $currentUser['id'],
                    'backup_error',
                    'system_backup',
                    'manual_backup_error',
                    'Manual backup failed.',
                    null,
                    ['error' => $e->getMessage()]
                );
                flash_set('error', t('flash.backup_failed', ['error' => $e->getMessage()]));
            }
            redirect('/?page=admin&section=backups');
        }

        if ($action === 'regenerate_photo_thumbnails') {
            try {
                $result = regenerate_photo_thumbnails($pdo, $config);
                audit_log(
                    $pdo,
                    (int) $currentUser['id'],
                    'photo_thumbnails_regenerated',
                    'photo_entry',
                    'all',
                    'Photo thumbnails regenerated.',
                    null,
                    $result
                );
                flash_set('success', t('flash.photo_thumbnails_regenerated', [
                    'photos' => (string) ($result['photos'] ?? 0),
                    'generated' => (string) ($result['generated'] ?? 0),
                    'failed' => (string) ($result['failed'] ?? 0),
                ]));
            } catch (Throwable $e) {
                flash_set('error', t('flash.photo_thumbnails_failed', ['error' => $e->getMessage()]));
            }
            redirect('/?page=admin&section=backups');
        }

        if ($action === 'delete_backup') {
            $backupId = (int) ($_POST['backup_id'] ?? 0);
            $backup = fetch_system_backup($pdo, $backupId);
            delete_system_backup($pdo, $config, $backupId);
            audit_log(
                $pdo,
                (int) $currentUser['id'],
                'backup_deleted',
                'system_backup',
                (string) $backupId,
                'Backup deleted.',
                audit_snapshot($backup),
                null
            );
            flash_set('success', t('flash.backup_deleted'));
            redirect('/?page=admin&section=backups');
        }

        if ($action === 'download_backup') {
            $backupId = (int) ($_POST['backup_id'] ?? 0);
            $backup = fetch_system_backup($pdo, $backupId);
            if ($backup === null) {
                flash_set('error', t('flash.not_found'));
                redirect('/?page=admin&section=backups');
            }
            $absolutePath = system_backup_absolute_path($config, (string) ($backup['file_path'] ?? ''));
            if ($absolutePath === null || !is_file($absolutePath)) {
                flash_set('error', t('flash.not_found'));
                redirect('/?page=admin&section=backups');
            }

            audit_log(
                $pdo,
                (int) $currentUser['id'],
                'backup_downloaded',
                'system_backup',
                (string) $backupId,
                'Backup downloaded.',
                null,
                [
                    'file_path' => (string) ($backup['file_path'] ?? ''),
                ]
            );

            $contentType = system_backup_is_zip($absolutePath) ? 'application/zip' : 'application/gzip';
            header('Content-Type: ' . $contentType);
            header('Content-Length: ' . (string) filesize($absolutePath));
            header('Content-Disposition: attachment; filename="' . basename($absolutePath) . '"');
            header('Cache-Control: no-store');
            readfile($absolutePath);
            exit;
        }

        if ($action === 'restore_backup') {
            $backupId = (int) ($_POST['backup_id'] ?? 0);
            $confirm = strtoupper(trim((string) ($_POST['confirm_restore'] ?? '')));
            if ($confirm !== 'RESTORE') {
                flash_set('error', t('flash.restore_confirm_required'));
                redirect('/?page=admin&section=backups');
            }

            $backup = fetch_system_backup($pdo, $backupId);
            if ($backup === null) {
                flash_set('error', t('flash.not_found'));
                redirect('/?page=admin&section=backups');
            }
            $absolutePath = system_backup_absolute_path($config, (string) ($backup['file_path'] ?? ''));
            if ($absolutePath === null || !is_file($absolutePath)) {
                flash_set('error', t('flash.not_found'));
                redirect('/?page=admin&section=backups');
            }

            try {
                $pdo = null;
                $GLOBALS['pdo'] = null;
                restore_system_backup_archive($config, $absolutePath);
                $pdo = db_connect($config);
                $GLOBALS['pdo'] = $pdo;
                reconcile_system_backups($pdo, $config);
                $restoredBackupMeta = db_fetch_one($pdo, 'SELECT id FROM system_backups WHERE file_path = :file_path', [':file_path' => (string) ($backup['file_path'] ?? '')]);
                $restoredBackupId = (int) ($restoredBackupMeta['id'] ?? $backupId);
                mark_system_backup_restore_result($pdo, $restoredBackupId, 'restored', (int) $currentUser['id']);
                audit_log(
                    $pdo,
                    (int) $currentUser['id'],
                    'backup_restored',
                    'system_backup',
                    (string) $restoredBackupId,
                    'Backup restored.',
                    null,
                    [
                        'file_path' => (string) ($backup['file_path'] ?? ''),
                    ]
                );
                flash_set('success', t('flash.backup_restored'));
            } catch (Throwable $e) {
                if (!($pdo instanceof PDO)) {
                    $pdo = db_connect($config);
                    $GLOBALS['pdo'] = $pdo;
                }
                mark_system_backup_restore_result($pdo, $backupId, 'error', (int) $currentUser['id'], $e->getMessage());
                audit_log(
                    $pdo,
                    (int) $currentUser['id'],
                    'backup_error',
                    'system_backup',
                    (string) $backupId,
                    'Backup restore failed.',
                    null,
                    ['error' => $e->getMessage()]
                );
                flash_set('error', t('flash.backup_restore_failed', ['error' => $e->getMessage()]));
            }
            redirect('/?page=admin&section=backups');
        }

        if ($action === 'upload_login_background') {
            try {
                $path = save_uploaded_image($config, $_FILES['login_background'] ?? [], 'app/login_backgrounds', 'login_bg');
                if (!is_valid_login_background_path($config, $path)) {
                    throw new RuntimeException(t('upload.invalid_image'));
                }
                set_app_setting($pdo, 'login_background_path', $path, (int) $currentUser['id']);
                flash_set('success', t('flash.login_background_updated'));
            } catch (Throwable $e) {
                flash_set('error', $e->getMessage());
            }
            redirect('/?page=admin&section=app');
        }

        if ($action === 'set_login_background') {
            $selectedPath = trim((string) ($_POST['login_background_path'] ?? ''));
            if ($selectedPath !== '' && !is_valid_login_background_path($config, $selectedPath)) {
                flash_set('error', t('flash.not_found'));
                redirect('/?page=admin&section=app');
            }
            set_app_setting($pdo, 'login_background_path', $selectedPath !== '' ? $selectedPath : null, (int) $currentUser['id']);
            flash_set('success', t('flash.login_background_updated'));
            redirect('/?page=admin&section=app');
        }

        if ($action === 'clear_login_background') {
            set_app_setting($pdo, 'login_background_path', null, (int) $currentUser['id']);
            flash_set('success', t('flash.login_background_cleared'));
            redirect('/?page=admin&section=app');
        }

        if ($action === 'upload_app_icon') {
            try {
                $cropped = trim((string) ($_POST['app_icon_cropped'] ?? ''));
                if ($cropped !== '') {
                    $path = save_uploaded_image_from_data_url($config, $cropped, 'app', 'app_icon');
                } else {
                    $path = save_uploaded_image($config, $_FILES['app_icon'] ?? [], 'app', 'app_icon');
                }
                set_app_setting($pdo, 'app_icon_path', $path, (int) $currentUser['id']);
                flash_set('success', t('flash.app_icon_updated'));
            } catch (Throwable $e) {
                flash_set('error', $e->getMessage());
            }
            redirect('/?page=admin');
        }
    }

    $team = default_team($pdo);
    $users = db_fetch_all($pdo, 'SELECT * FROM users ORDER BY created_at ASC');
    $challengeSettings = challenge_settings($pdo, $config);
    $appIconSetting = db_fetch_one(
        $pdo,
        'SELECT setting_value, updated_at FROM app_settings WHERE setting_key = :key',
        [':key' => 'app_icon_path']
    );
    $appIconPath = $appIconSetting !== null ? (string) ($appIconSetting['setting_value'] ?? '') : '';
    $appIconVersion = null;
    if ($appIconSetting !== null && !empty($appIconSetting['updated_at'])) {
        $timestamp = strtotime((string) $appIconSetting['updated_at']);
        if ($timestamp !== false) {
            $appIconVersion = (string) $timestamp;
        }
    }
    $loginBackgroundPath = trim((string) (app_setting($pdo, 'login_background_path', '') ?? ''));
    $backupSettings = system_backup_settings($pdo);
    reconcile_system_backups($pdo, $config);
    $systemBackups = list_system_backups($pdo, $config, 200);
    $workoutTypeFields = list_workout_type_fields_grouped($pdo, false);
    $loginBackgroundLibrary = list_login_background_library($config);
    $adminAchievements = list_achievements_for_admin($pdo);
    $selectedAdminAchievementId = 0;
    $selectedAdminAchievementParam = trim((string) ($_GET['achievement_id'] ?? ''));
    if ($selectedAdminAchievementParam !== '' && ctype_digit($selectedAdminAchievementParam)) {
        $selectedAdminAchievementId = (int) $selectedAdminAchievementParam;
    }
    if ($selectedAdminAchievementId <= 0 && $adminAchievements !== []) {
        $selectedAdminAchievementId = (int) ($adminAchievements[0]['id'] ?? 0);
    }
    $selectedAdminAchievement = null;
    foreach ($adminAchievements as $adminAchievement) {
        if ((int) ($adminAchievement['id'] ?? 0) === $selectedAdminAchievementId) {
            $selectedAdminAchievement = $adminAchievement;
            break;
        }
    }
    $adminAchievementStats = [
        'unlocked' => 0,
        'in_progress' => 0,
        'locked' => 0,
        'total' => 0,
        'avg_progress' => 0.0,
        'recent_unlocks' => [],
        'rows' => [],
    ];
    if (is_array($selectedAdminAchievement)) {
        try {
            $activeAdminUsers = array_values(array_filter(
                $users,
                static fn(array $user): bool => (int) ($user['active'] ?? 1) === 1
            ));
            if ($activeAdminUsers === []) {
                $activeAdminUsers = list_active_users($pdo);
            }
            $adminAchievementMetrics = compute_challenge_metrics(
                $pdo,
                $activeAdminUsers,
                (string) $challengeSettings['challenge_start'],
                (string) $challengeSettings['challenge_end']
            );
            $adminAchievementMetrics = apply_strike_review_overrides_to_metrics($pdo, $adminAchievementMetrics);
            $adminAchievementStats = build_admin_achievement_stats($pdo, $selectedAdminAchievement, $activeAdminUsers, $team, $adminAchievementMetrics);
        } catch (Throwable) {
            $adminAchievementStats = [
                'unlocked' => 0,
                'in_progress' => 0,
                'locked' => 0,
                'total' => 0,
                'avg_progress' => 0.0,
                'recent_unlocks' => [],
                'rows' => [],
            ];
        }
    }
    $auditFilters = [
        'actor_user_id' => isset($_GET['actor_user_id']) && $_GET['actor_user_id'] !== '' ? (int) $_GET['actor_user_id'] : null,
        'entity_type' => trim((string) ($_GET['entity_type'] ?? '')),
        'date_from' => ($_GET['date_from'] ?? '') !== '' ? to_date((string) $_GET['date_from']) : null,
        'date_to' => ($_GET['date_to'] ?? '') !== '' ? to_date((string) $_GET['date_to']) : null,
    ];

    render_view('admin', [
        'title' => t('admin.title'),
        'currentPage' => 'admin',
        'currentUser' => $currentUser,
        'users' => $users,
        'team' => $team,
        'teamMembers' => list_team_members($pdo, (int) $team['id'], false),
        'joinRequests' => pending_team_join_requests($pdo, (int) $team['id']),
        'availableUsers' => list_users_not_in_active_team($pdo, (int) $team['id']),
        'workoutTypes' => list_workout_types($pdo, false),
        'workoutTypeFields' => $workoutTypeFields,
        'habits' => list_habit_definitions($pdo, false),
        'achievements' => list_achievements($pdo, true),
        'adminAchievements' => $adminAchievements,
        'selectedAdminAchievementId' => $selectedAdminAchievementId,
        'adminAchievementStats' => $adminAchievementStats,
        'achievementAwards' => list_recent_achievement_awards($pdo, 300),
        'motivationalQuotes' => list_motivational_quotes($pdo),
        'appIconPath' => $appIconPath,
        'appIconVersion' => $appIconVersion,
        'appNameSetting' => app_setting($pdo, 'app_name', (string) ($config['app_name'] ?? 'Fitness Challenge Tracker')),
        'penaltiesEnabled' => penalties_enabled($pdo),
        'notionSettings' => notion_settings($pdo),
        'notionFieldLabels' => notion_field_labels(),
        'notionFieldMap' => notion_field_map($pdo),
        'notionSchemaCache' => notion_schema_cache($pdo),
        'telegramSettings' => telegram_settings($pdo),
        'telegramLinkedUsers' => telegram_linked_users($pdo),
        'loginBackgroundPath' => $loginBackgroundPath,
        'loginBackgroundLibrary' => $loginBackgroundLibrary,
        'backupSettings' => $backupSettings,
        'systemBackups' => $systemBackups,
        'challengeSettings' => $challengeSettings,
        'challengeArchives' => list_challenge_archives($pdo),
        'auditLogs' => fetch_audit_logs($pdo, $auditFilters, 100),
        'auditFilters' => $auditFilters,
        'config' => $config,
    ]);
}

if ($page === 'team_settings') {
    $userTeams = list_user_teams($pdo, (int) $currentUser['id']);
    $teamId = isset($_GET['team_id']) ? (int) $_GET['team_id'] : ($userTeams !== [] ? (int) $userTeams[0]['id'] : (int) default_team($pdo)['id']);
    if (is_admin($currentUser)) {
        $team = db_fetch_one($pdo, 'SELECT * FROM teams WHERE id = :id', [':id' => $teamId]) ?? default_team($pdo);
    } else {
        $team = null;
        foreach ($userTeams as $candidate) {
            if ((int) $candidate['id'] === $teamId) {
                $team = $candidate;
                break;
            }
        }
        if ($team === null && $userTeams !== []) {
            $team = $userTeams[0];
        }
    }

    if ($team === null) {
        flash_set('error', t('flash.no_permission'));
        redirect('/?page=team');
    }

    require_team_manager($pdo, $currentUser, (int) $team['id']);

    if (is_post()) {
        if (!csrf_verify()) {
            flash_set('error', t('flash.csrf'));
            redirect('/?page=team_settings&team_id=' . (int) $team['id']);
        }

        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'team_settings') {
            update_team_settings(
                $pdo,
                (int) $team['id'],
                (string) ($_POST['name'] ?? ''),
                (string) ($_POST['description'] ?? ''),
                (string) ($_POST['join_mode'] ?? 'closed'),
                (string) ($_POST['visibility'] ?? 'visible'),
                (int) $currentUser['id']
            );
            flash_set('success', t('flash.team_updated'));
            redirect('/?page=team_settings&team_id=' . (int) $team['id']);
        }

        if ($action === 'team_membership') {
            set_team_membership(
                $pdo,
                (int) $team['id'],
                (int) ($_POST['user_id'] ?? 0),
                (string) ($_POST['member_action'] ?? 'add') === 'add',
                (int) $currentUser['id']
            );
            flash_set('success', t('flash.team_updated'));
            redirect('/?page=team_settings&team_id=' . (int) $team['id']);
        }

        if ($action === 'team_role') {
            update_team_member_role($pdo, (int) $team['id'], (int) ($_POST['user_id'] ?? 0), (string) ($_POST['role'] ?? 'member'), (int) $currentUser['id']);
            flash_set('success', t('flash.team_updated'));
            redirect('/?page=team_settings&team_id=' . (int) $team['id']);
        }

        if ($action === 'resolve_join_request') {
            resolve_team_join_request($pdo, (int) ($_POST['request_id'] ?? 0), (string) ($_POST['decision'] ?? '') === 'approve', (int) $currentUser['id']);
            flash_set('success', t('flash.team_updated'));
            redirect('/?page=team_settings&team_id=' . (int) $team['id']);
        }
    }

    render_view('team_settings', [
        'title' => t('team.settings'),
        'currentPage' => 'team',
        'currentUser' => $currentUser,
        'team' => $team,
        'teamMembers' => list_team_members($pdo, (int) $team['id'], false),
        'availableUsers' => list_users_not_in_active_team($pdo, (int) $team['id']),
        'joinRequests' => pending_team_join_requests($pdo, (int) $team['id']),
        'config' => $config,
    ]);
}

if ($page === 'team') {
    $penaltiesEnabled = penalties_enabled($pdo);
    if (is_post()) {
        if (!csrf_verify()) {
            flash_set('error', t('flash.csrf'));
            redirect('/?page=team');
        }

        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'join_team') {
            $result = request_or_join_team($pdo, (int) ($_POST['team_id'] ?? 0), (int) $currentUser['id']);
            flash_set('success', t('flash.team_' . $result));
            redirect('/?page=team');
        }

        $userTeamsForPost = list_user_teams($pdo, (int) $currentUser['id']);
        $team = $userTeamsForPost !== [] ? $userTeamsForPost[0] : default_team($pdo);
        $requestedTeamId = (int) ($_POST['team_id'] ?? ($_GET['team_id'] ?? 0));
        if ($requestedTeamId > 0) {
            foreach ($userTeamsForPost as $candidateTeam) {
                if ((int) ($candidateTeam['id'] ?? 0) === $requestedTeamId) {
                    $team = $candidateTeam;
                    break;
                }
            }
        }
        $canManageTeamForPost = can_manage_team($pdo, $currentUser, (int) $team['id']);
        $redirectTeamParams = [
            'page' => 'team',
            'team_id' => (int) $team['id'],
        ];
        $redirectTeamView = trim((string) ($_POST['redirect_view'] ?? ($_GET['view'] ?? '')));
        if ($redirectTeamView !== '') {
            $redirectTeamParams['view'] = $redirectTeamView;
        }
        $teamRedirectUrl = '/?' . http_build_query($redirectTeamParams);

        if ($action === 'save_team_layout') {
            $postedView = trim((string) ($_POST['team_view'] ?? ''));
            if ($postedView !== '') {
                $redirectTeamParams['view'] = $postedView;
                $teamRedirectUrl = '/?' . http_build_query($redirectTeamParams);
            }

            $layoutJson = null;
            if (empty($_POST['reset_team_layout'])) {
                $layout = normalize_team_layout_widgets((array) ($_POST['team_widgets'] ?? []));
                $layoutJson = json_encode($layout, JSON_UNESCAPED_SLASHES);
            }

            db_execute(
                $pdo,
                'UPDATE users SET team_layout_json = :team_layout_json, updated_at = :updated_at WHERE id = :id',
                [
                    ':team_layout_json' => $layoutJson,
                    ':updated_at' => now_iso(),
                    ':id' => (int) $currentUser['id'],
                ]
            );
            flash_set('success', t('team.layout_saved'));
            redirect($teamRedirectUrl);
        }

        if ($action === 'team_membership') {
            require_admin($currentUser);
            set_team_membership(
                $pdo,
                (int) $team['id'],
                (int) ($_POST['user_id'] ?? 0),
                (string) ($_POST['member_action'] ?? 'add') === 'add',
                (int) $currentUser['id']
            );
            flash_set('success', t('flash.team_updated'));
            redirect('/?page=team');
        }

        $buildTeamSummaryForGoal = static function () use ($pdo, $config, $team): array {
            $settingsForGoal = challenge_settings($pdo, $config);
            $teamUsersForGoal = list_active_team_users($pdo, (int) $team['id']);
            $metricsForGoal = compute_challenge_metrics(
                $pdo,
                $teamUsersForGoal,
                (string) $settingsForGoal['challenge_start'],
                (string) $settingsForGoal['challenge_end']
            );
            $metricsForGoal = apply_strike_review_overrides_to_metrics($pdo, $metricsForGoal);
            $teamRowsForGoal = team_rows_for_view(array_values($metricsForGoal), 'total');
            $teamSummaryForGoal = team_summary_from_rows($teamRowsForGoal);
            $teamCaloriesForGoal = resolve_team_calories_summary(
                $pdo,
                (int) $team['id'],
                (string) $settingsForGoal['challenge_start'],
                (string) $settingsForGoal['challenge_end']
            );
            $teamSummaryForGoal['calories_burned'] = (float) ($teamCaloriesForGoal['burned'] ?? 0);
            $teamSummaryForGoal['calories_consumed'] = (float) ($teamCaloriesForGoal['consumed'] ?? 0);

            return [
                'summary' => $teamSummaryForGoal,
                'users' => $teamUsersForGoal,
            ];
        };

        if ($action === 'create_goal') {
            if (!$canManageTeamForPost) {
                flash_set('error', t('flash.no_permission'));
                redirect($teamRedirectUrl);
            }
            $title = trim((string) ($_POST['title'] ?? ''));
            if ($title !== '') {
                $goalType = normalize_goal_target_type((string) ($_POST['target_type'] ?? 'custom'));
                if (!$penaltiesEnabled && $goalType === 'penalties') {
                    flash_set('error', t('metric.invalid'));
                    redirect($teamRedirectUrl);
                }
                $targetValue = ($_POST['target_value'] ?? '') !== '' ? (float) $_POST['target_value'] : null;
                $rewardEnabled = bool_from_form('reward_enabled');
                $rewardTextRaw = trim((string) ($_POST['reward_text'] ?? ''));
                $rewardText = $rewardEnabled && $rewardTextRaw !== '' ? substr($rewardTextRaw, 0, 120) : null;
                $customUnit = trim((string) ($_POST['custom_unit'] ?? ''));
                $unitLabel = $goalType === 'custom'
                    ? ($customUnit !== '' ? substr($customUnit, 0, 24) : null)
                    : goal_target_default_unit($goalType);
                $secondaryEnabled = bool_from_form('secondary_enabled') === 1;
                $secondaryType = normalize_goal_target_type((string) ($_POST['secondary_target_type'] ?? 'custom'));
                $secondaryTargetValueRaw = ($_POST['secondary_target_value'] ?? '') !== '' ? (float) $_POST['secondary_target_value'] : null;
                if (!$secondaryEnabled || $secondaryTargetValueRaw === null || $secondaryTargetValueRaw <= 0) {
                    $secondaryEnabled = false;
                    $secondaryType = 'custom';
                    $secondaryTargetValueRaw = null;
                }
                if (!$penaltiesEnabled && $secondaryEnabled && $secondaryType === 'penalties') {
                    flash_set('error', t('metric.invalid'));
                    redirect($teamRedirectUrl);
                }
                $secondaryCustomUnit = trim((string) ($_POST['secondary_custom_unit'] ?? ''));
                $secondaryUnitLabel = $secondaryEnabled
                    ? (
                        $secondaryType === 'custom'
                            ? ($secondaryCustomUnit !== '' ? substr($secondaryCustomUnit, 0, 24) : null)
                            : goal_target_default_unit($secondaryType)
                    )
                    : null;
                $startSchedule = resolve_goal_start_datetime(
                    (string) ($_POST['start_date'] ?? ''),
                    (string) ($_POST['start_time'] ?? '')
                );
                $startDate = (string) ($startSchedule['start_date'] ?? '');
                $startTime = (string) ($startSchedule['start_time'] ?? '');
                $startAt = $startSchedule['start_at'] instanceof DateTimeImmutable ? $startSchedule['start_at'] : null;
                $dueDate = ($_POST['due_date'] ?? '') !== '' ? to_date((string) $_POST['due_date']) : null;
                $dueTime = normalize_goal_due_time($dueDate, (string) ($_POST['due_time'] ?? ''));
                $dueAt = $dueDate !== null ? log_datetime_from_values($dueDate, (string) ($dueTime ?? '23:59'), '23:59') : null;
                if ($startAt instanceof DateTimeImmutable && $dueAt instanceof DateTimeImmutable && $startAt > $dueAt) {
                    flash_set('error', t('goals.start_after_due'));
                    redirect($teamRedirectUrl);
                }

                $nowDateTime = new DateTimeImmutable('now');
                $startsInFuture = $startAt instanceof DateTimeImmutable && $startAt > $nowDateTime;
                $baselineValue = null;
                $secondaryBaselineValue = null;
                if (!$startsInFuture) {
                    $teamMetricsContext = $buildTeamSummaryForGoal();
                    $teamSummaryForGoal = is_array($teamMetricsContext['summary'] ?? null) ? $teamMetricsContext['summary'] : [];
                    $teamUsersForGoal = is_array($teamMetricsContext['users'] ?? null) ? $teamMetricsContext['users'] : [];
                    $currentMetricValue = goal_team_metric_value_for_type($goalType, $teamSummaryForGoal, 0);
                    $baselineValue = goal_team_baseline_from_start(
                        $pdo,
                        [
                            'target_type' => $goalType,
                            'start_date' => $startDate,
                            'start_time' => $startTime,
                        ],
                        $teamUsersForGoal,
                        $currentMetricValue,
                        $nowDateTime
                    );
                    if ($secondaryEnabled && $secondaryTargetValueRaw !== null) {
                        $secondaryCurrentMetricValue = goal_team_metric_value_for_type($secondaryType, $teamSummaryForGoal, 0);
                        $secondaryBaselineValue = goal_team_baseline_from_start(
                            $pdo,
                            [
                                'target_type' => $secondaryType,
                                'start_date' => $startDate,
                                'start_time' => $startTime,
                            ],
                            $teamUsersForGoal,
                            $secondaryCurrentMetricValue,
                            $nowDateTime
                        );
                    }
                }

                create_goal($pdo, [
                    'scope' => 'team',
                    'team_id' => (int) $team['id'],
                    'user_id' => null,
                    'title' => $title,
                    'target_type' => $goalType,
                    'target_value' => $targetValue,
                    'baseline_value' => $baselineValue,
                    'current_value' => 0,
                    'secondary_enabled' => $secondaryEnabled ? 1 : 0,
                    'secondary_target_type' => $secondaryEnabled ? $secondaryType : null,
                    'secondary_target_value' => $secondaryEnabled ? $secondaryTargetValueRaw : null,
                    'secondary_baseline_value' => $secondaryEnabled ? $secondaryBaselineValue : null,
                    'secondary_current_value' => 0,
                    'secondary_unit_label' => $secondaryUnitLabel,
                    'unit_label' => $unitLabel,
                    'reward_text' => $rewardText,
                    'start_date' => $startDate !== '' ? $startDate : null,
                    'start_time' => $startTime !== '' ? $startTime : null,
                    'due_date' => $dueDate,
                    'due_time' => $dueTime,
                ], (int) $currentUser['id']);

                $settingsForGoal = challenge_settings($pdo, $config);
                auto_complete_team_goals_for_team(
                    $pdo,
                    (int) $team['id'],
                    (string) $settingsForGoal['challenge_start'],
                    (string) $settingsForGoal['challenge_end'],
                    (int) $currentUser['id']
                );
                flash_set('success', t('flash.goal_created'));
            }
            redirect($teamRedirectUrl);
        }

        if ($action === 'update_goal') {
            if (!$canManageTeamForPost) {
                flash_set('error', t('flash.no_permission'));
                redirect($teamRedirectUrl);
            }
            $goalId = (int) ($_POST['goal_id'] ?? 0);
            $goal = db_fetch_one($pdo, 'SELECT * FROM goals WHERE id = :id', [':id' => $goalId]);
            if ($goal === null || (string) ($goal['scope'] ?? '') !== 'team' || (int) ($goal['team_id'] ?? 0) !== (int) $team['id']) {
                flash_set('error', t('flash.no_permission'));
                redirect($teamRedirectUrl);
            }
            $goalType = normalize_goal_target_type((string) ($_POST['target_type'] ?? 'custom'));
            $goalTypeBefore = normalize_goal_target_type((string) ($goal['target_type'] ?? 'custom'));
            if (!$penaltiesEnabled && $goalType === 'penalties' && $goalTypeBefore !== 'penalties') {
                flash_set('error', t('metric.invalid'));
                redirect($teamRedirectUrl);
            }
            $secondaryWasEnabledBefore = goal_has_secondary_target($goal);
            $secondaryTypeBefore = goal_secondary_target_type($goal);
            $rewardEnabled = bool_from_form('reward_enabled');
            $rewardTextRaw = trim((string) ($_POST['reward_text'] ?? ''));
            $rewardText = $rewardEnabled && $rewardTextRaw !== '' ? substr($rewardTextRaw, 0, 120) : null;
            $customUnit = trim((string) ($_POST['custom_unit'] ?? ''));
            $unitLabel = $goalType === 'custom'
                ? ($customUnit !== '' ? substr($customUnit, 0, 24) : null)
                : goal_target_default_unit($goalType);
            $secondaryEnabled = bool_from_form('secondary_enabled') === 1;
            $secondaryType = normalize_goal_target_type((string) ($_POST['secondary_target_type'] ?? 'custom'));
            $secondaryTargetValueRaw = ($_POST['secondary_target_value'] ?? '') !== '' ? (float) $_POST['secondary_target_value'] : null;
            if (!$secondaryEnabled || $secondaryTargetValueRaw === null || $secondaryTargetValueRaw <= 0) {
                $secondaryEnabled = false;
                $secondaryType = 'custom';
                $secondaryTargetValueRaw = null;
            }
            if (!$penaltiesEnabled && $secondaryEnabled && $secondaryType === 'penalties' && $secondaryTypeBefore !== 'penalties') {
                flash_set('error', t('metric.invalid'));
                redirect($teamRedirectUrl);
            }
            $secondaryCustomUnit = trim((string) ($_POST['secondary_custom_unit'] ?? ''));
            $secondaryUnitLabel = $secondaryEnabled
                ? (
                    $secondaryType === 'custom'
                        ? ($secondaryCustomUnit !== '' ? substr($secondaryCustomUnit, 0, 24) : null)
                        : goal_target_default_unit($secondaryType)
                )
                : null;
            $startSchedule = resolve_goal_start_datetime(
                (string) ($_POST['start_date'] ?? ''),
                (string) ($_POST['start_time'] ?? '')
            );
            $startDate = (string) ($startSchedule['start_date'] ?? '');
            $startTime = (string) ($startSchedule['start_time'] ?? '');
            $startAt = $startSchedule['start_at'] instanceof DateTimeImmutable ? $startSchedule['start_at'] : null;
            $dueDate = ($_POST['due_date'] ?? '') !== '' ? to_date((string) $_POST['due_date']) : null;
            $dueTime = normalize_goal_due_time($dueDate, (string) ($_POST['due_time'] ?? ''));
            $dueAt = $dueDate !== null ? log_datetime_from_values($dueDate, (string) ($dueTime ?? '23:59'), '23:59') : null;
            if ($startAt instanceof DateTimeImmutable && $dueAt instanceof DateTimeImmutable && $startAt > $dueAt) {
                flash_set('error', t('goals.start_after_due'));
                redirect($teamRedirectUrl);
            }
            $updatePayload = [
                'title' => trim((string) ($_POST['title'] ?? '')),
                'target_type' => $goalType,
                'target_value' => ($_POST['target_value'] ?? '') !== '' ? (float) $_POST['target_value'] : null,
                'secondary_enabled' => $secondaryEnabled ? 1 : 0,
                'secondary_target_type' => $secondaryEnabled ? $secondaryType : null,
                'secondary_target_value' => $secondaryEnabled ? $secondaryTargetValueRaw : null,
                'secondary_unit_label' => $secondaryUnitLabel,
                'unit_label' => $unitLabel,
                'reward_text' => $rewardText,
                'start_date' => $startDate !== '' ? $startDate : null,
                'start_time' => $startTime !== '' ? $startTime : null,
                'due_date' => $dueDate,
                'due_time' => $dueTime,
            ];

            $nowDateTime = new DateTimeImmutable('now');
            $startsInFuture = $startAt instanceof DateTimeImmutable && $startAt > $nowDateTime;
            $teamMetricsContext = null;
            $resolveTeamMetricsContext = static function () use (&$teamMetricsContext, $buildTeamSummaryForGoal): array {
                if (!is_array($teamMetricsContext)) {
                    $teamMetricsContext = $buildTeamSummaryForGoal();
                }
                return $teamMetricsContext;
            };
            if ($startsInFuture) {
                $updatePayload['baseline_value'] = null;
                $updatePayload['current_value'] = 0;
                $updatePayload['secondary_baseline_value'] = null;
                $updatePayload['secondary_current_value'] = 0;
            } else {
                $shouldResetToNow = $goalType !== $goalTypeBefore;
                $shouldBackfillFromStart = !is_numeric($goal['baseline_value'] ?? null);
                if ($shouldResetToNow || $shouldBackfillFromStart) {
                    $teamMetricsContext = $resolveTeamMetricsContext();
                    $teamSummaryForGoal = is_array($teamMetricsContext['summary'] ?? null) ? $teamMetricsContext['summary'] : [];
                    $teamUsersForGoal = is_array($teamMetricsContext['users'] ?? null) ? $teamMetricsContext['users'] : [];
                    $currentMetricValue = goal_team_metric_value_for_type($goalType, $teamSummaryForGoal, 0);
                    $updatePayload['baseline_value'] = $shouldResetToNow
                        ? round($currentMetricValue, 2)
                        : goal_team_baseline_from_start(
                            $pdo,
                            [
                                'target_type' => $goalType,
                                'start_date' => $startDate,
                                'start_time' => $startTime,
                            ],
                            $teamUsersForGoal,
                            $currentMetricValue,
                            $nowDateTime
                        );
                    $updatePayload['current_value'] = 0;
                }

                if (!$secondaryEnabled) {
                    $updatePayload['secondary_baseline_value'] = null;
                    $updatePayload['secondary_current_value'] = 0;
                } else {
                    $shouldResetSecondaryToNow = !$secondaryWasEnabledBefore || $secondaryType !== $secondaryTypeBefore;
                    $shouldBackfillSecondaryFromStart = !is_numeric($goal['secondary_baseline_value'] ?? null);
                    if ($shouldResetSecondaryToNow || $shouldBackfillSecondaryFromStart) {
                        $teamMetricsContext = $resolveTeamMetricsContext();
                        $teamSummaryForGoal = is_array($teamMetricsContext['summary'] ?? null) ? $teamMetricsContext['summary'] : [];
                        $teamUsersForGoal = is_array($teamMetricsContext['users'] ?? null) ? $teamMetricsContext['users'] : [];
                        $secondaryCurrentMetricValue = goal_team_metric_value_for_type($secondaryType, $teamSummaryForGoal, 0);
                        $updatePayload['secondary_baseline_value'] = $shouldResetSecondaryToNow
                            ? round($secondaryCurrentMetricValue, 2)
                            : goal_team_baseline_from_start(
                                $pdo,
                                [
                                    'target_type' => $secondaryType,
                                    'start_date' => $startDate,
                                    'start_time' => $startTime,
                                ],
                                $teamUsersForGoal,
                                $secondaryCurrentMetricValue,
                                $nowDateTime
                            );
                        $updatePayload['secondary_current_value'] = 0;
                    }
                }
            }

            update_goal($pdo, $goalId, $updatePayload, (int) $currentUser['id']);
            flash_set('success', t('flash.goal_updated'));
            redirect($teamRedirectUrl);
        }

        if ($action === 'delete_goal') {
            if (!$canManageTeamForPost) {
                flash_set('error', t('flash.no_permission'));
                redirect($teamRedirectUrl);
            }
            $goalId = (int) ($_POST['goal_id'] ?? 0);
            $goal = db_fetch_one($pdo, 'SELECT * FROM goals WHERE id = :id', [':id' => $goalId]);
            if ($goal !== null && (string) $goal['scope'] === 'team' && (int) ($goal['team_id'] ?? 0) === (int) $team['id']) {
                delete_goal($pdo, $goalId, (int) $currentUser['id']);
                flash_set('success', t('flash.goal_deleted'));
            } else {
                flash_set('error', t('flash.no_permission'));
            }
            redirect($teamRedirectUrl);
        }

        if ($action === 'goal_status') {
            if (!$canManageTeamForPost) {
                flash_set('error', t('flash.no_permission'));
                redirect($teamRedirectUrl);
            }
            $goalId = (int) ($_POST['goal_id'] ?? 0);
            $goal = db_fetch_one($pdo, 'SELECT * FROM goals WHERE id = :id', [':id' => $goalId]);
            if ($goal !== null && (string) ($goal['scope'] ?? '') === 'team' && (int) ($goal['team_id'] ?? 0) === (int) $team['id']) {
                update_goal_status($pdo, $goalId, (string) ($_POST['status'] ?? 'active'), (int) $currentUser['id']);
                flash_set('success', t('flash.goal_updated'));
            } else {
                flash_set('error', t('flash.no_permission'));
            }
            redirect($teamRedirectUrl);
        }

        if ($action === 'create_team_achievement') {
            flash_set('error', t('flash.no_permission'));
            redirect('/?page=team');
        }

        if ($action === 'delete_achievement_award') {
            if (!can_manage_team($pdo, $currentUser, (int) $team['id'])) {
                flash_set('error', t('flash.no_permission'));
                redirect('/?page=team');
            }
            $awardId = (int) ($_POST['award_id'] ?? 0);
            if ($awardId <= 0) {
                flash_set('error', 'Invalid achievement id.');
                redirect('/?page=team');
            }
            $award = db_fetch_one($pdo, 'SELECT * FROM achievement_awards WHERE id = :id', [':id' => $awardId]);
            if ($award !== null && (int) ($award['team_id'] ?? 0) === (int) $team['id']) {
                delete_achievement_award($pdo, $awardId, (int) $currentUser['id']);
                flash_set('success', t('flash.achievement_deleted'));
            } else {
                flash_set('error', t('flash.no_permission'));
            }
            redirect('/?page=team');
        }

    }

    $userTeams = list_user_teams($pdo, (int) $currentUser['id']);
    if ($userTeams === []) {
        render_view('team_splash', [
            'title' => t('team.join_team'),
            'currentPage' => 'team',
            'currentUser' => $currentUser,
            'teams' => list_joinable_teams($pdo, (int) $currentUser['id']),
            'config' => $config,
        ]);
    }

    $teamId = isset($_GET['team_id']) ? (int) $_GET['team_id'] : (int) $userTeams[0]['id'];
    $team = null;
    foreach ($userTeams as $candidate) {
        if ((int) $candidate['id'] === $teamId) {
            $team = $candidate;
            break;
        }
    }
    if ($team === null) {
        $team = $userTeams[0];
    }

    $settings = challenge_settings($pdo, $config);
    if (!challenge_is_active($settings)) {
        render_view('team_inactive', [
            'title' => t('team.no_active_challenge_title'),
            'currentPage' => 'team',
            'currentUser' => $currentUser,
            'team' => $team,
            'challengeSettings' => $settings,
            'hasArchives' => list_challenge_archives($pdo) !== [],
            'config' => $config,
        ]);
    }
    $teamUsers = list_active_team_users($pdo, (int) $team['id']);
    $metricsByUser = compute_challenge_metrics(
        $pdo,
        $teamUsers,
        (string) $settings['challenge_start'],
        (string) $settings['challenge_end']
    );
    $metricsByUser = apply_strike_review_overrides_to_metrics($pdo, $metricsByUser);
    evaluate_automatic_achievements($pdo, $metricsByUser, (int) $team['id']);
    $metricsOrdered = array_values($metricsByUser);
    $teamSummaryTotalRows = team_rows_for_view($metricsOrdered, 'total');
    $teamSummaryTotal = team_summary_from_rows($teamSummaryTotalRows);
    $teamCaloriesTotal = resolve_team_calories_summary(
        $pdo,
        (int) $team['id'],
        (string) $settings['challenge_start'],
        (string) $settings['challenge_end']
    );
    $teamSummaryTotal['calories_burned'] = (float) ($teamCaloriesTotal['burned'] ?? 0);
    $teamSummaryTotal['calories_consumed'] = (float) ($teamCaloriesTotal['consumed'] ?? 0);
    auto_complete_team_goals($pdo, (int) $team['id'], $teamSummaryTotal, (int) $currentUser['id']);
    $teamSummary = $teamSummaryTotal;

    $weekOptionsMap = [];
    foreach ($metricsOrdered as $metric) {
        foreach (($metric['weekly'] ?? []) as $weekRow) {
            $weekStart = (string) ($weekRow['week_start'] ?? '');
            if ($weekStart !== '') {
                $weekOptionsMap[$weekStart] = true;
            }
        }
    }
    $weekOptions = array_keys($weekOptionsMap);
    sort($weekOptions);
    $defaultWeekStart = $weekOptions !== [] ? $weekOptions[count($weekOptions) - 1] : to_date(null);
    $normalizeTeamWeekView = static function (string $rawView, string $fallback): string {
        $normalizedDate = to_date($rawView, $fallback);
        try {
            return week_start_for(new DateTimeImmutable($normalizedDate))->format('Y-m-d');
        } catch (Throwable) {
            return $fallback;
        }
    };
    $teamView = (string) ($_GET['view'] ?? 'current_week');
    if (!in_array($teamView, ['current_week', 'total'], true)) {
        $teamView = $normalizeTeamWeekView($teamView, $defaultWeekStart);
    }
    $selectedWeekStart = $teamView === 'current_week'
        ? $defaultWeekStart
        : ($teamView === 'total' ? $defaultWeekStart : $normalizeTeamWeekView($teamView, $defaultWeekStart));
    if (!in_array($selectedWeekStart, $weekOptions, true) && $weekOptions !== []) {
        $selectedWeekStart = $defaultWeekStart;
    }
    $effectiveTeamView = $teamView === 'total' ? 'total' : $selectedWeekStart;
    $teamComparisonRows = team_rows_for_view($metricsOrdered, $effectiveTeamView);
    $teamSummary = team_summary_from_rows($teamComparisonRows);

    $challengeStart = new DateTimeImmutable((string) $settings['challenge_start']);
    $challengeConfiguredEnd = new DateTimeImmutable((string) $settings['challenge_end']);
    $challengeToday = new DateTimeImmutable('today');
    $challengeEnd = $challengeConfiguredEnd > $challengeToday ? $challengeToday : $challengeConfiguredEnd;
    if ($challengeEnd < $challengeStart) {
        $challengeEnd = $challengeStart;
    }

    $dailyTotals = [];
    foreach (day_sequence($challengeStart, $challengeEnd) as $day) {
        $dailyTotals[$day->format('Y-m-d')] = ['steps' => 0, 'distance' => 0.0, 'workouts' => 0];
    }

    $dailyByUser = [];
    foreach ($metricsOrdered as $metric) {
        $userId = (int) ($metric['user']['id'] ?? 0);
        if ($userId > 0) {
            $dailyByUser[$userId] = [
                'user_id' => $userId,
                'display_name' => (string) ($metric['user']['display_name'] ?? ''),
                'daily' => [],
            ];
            foreach ($dailyTotals as $date => $_) {
                $dailyByUser[$userId]['daily'][$date] = ['steps' => 0, 'distance' => 0.0];
            }
        }

        $workoutsByDate = [];
        foreach ((array) ($metric['workout_series'] ?? []) as $workoutPoint) {
            $date = (string) ($workoutPoint['date'] ?? '');
            if ($date === '') {
                continue;
            }
            $workoutsByDate[$date] = max(0, (int) ($workoutPoint['workouts'] ?? 0));
        }

        foreach ((array) ($metric['steps_series'] ?? []) as $seriesPoint) {
            $date = (string) ($seriesPoint['date'] ?? '');
            if ($date === '' || !isset($dailyTotals[$date])) {
                continue;
            }
            $steps = max(0, (int) ($seriesPoint['steps'] ?? 0));
            $distance = max(0.0, (float) ($seriesPoint['km'] ?? 0));
            $dailyTotals[$date]['steps'] += $steps;
            $dailyTotals[$date]['distance'] += $distance;
            $dailyTotals[$date]['workouts'] += $workoutsByDate[$date] ?? 0;
            if ($userId > 0 && isset($dailyByUser[$userId]['daily'][$date])) {
                $dailyByUser[$userId]['daily'][$date]['steps'] += $steps;
                $dailyByUser[$userId]['daily'][$date]['distance'] += $distance;
            }
        }
    }

    $rangeStart = $selectedWeekStart;
    $rangeEnd = (new DateTimeImmutable($selectedWeekStart))->modify('+6 days')->format('Y-m-d');
    $filteredDaily = [];
    foreach ($dailyTotals as $date => $row) {
        if ($teamView === 'total' || ($date >= $rangeStart && $date <= $rangeEnd)) {
            $filteredDaily[$date] = $row;
        }
    }
    if ($filteredDaily === []) {
        $filteredDaily = $dailyTotals;
    }

    $teamDailyLabels = [];
    $teamDailySteps = [];
    $teamDailyDistance = [];
    $teamDailyWorkouts = [];
    foreach ($filteredDaily as $date => $row) {
        $teamDailyLabels[] = format_date_eu($date);
        $teamDailySteps[] = (int) $row['steps'];
        $teamDailyDistance[] = round((float) $row['distance'], 2);
        $teamDailyWorkouts[] = (int) $row['workouts'];
    }

    $teamCumulativeLabels = $teamDailyLabels;
    $teamCumulativeSteps = [];
    $teamCumulativeDistance = [];
    $runningSteps = 0;
    $runningDistance = 0.0;
    foreach ($filteredDaily as $row) {
        $runningSteps += max(0, (int) ($row['steps'] ?? 0));
        $runningDistance += max(0.0, (float) ($row['distance'] ?? 0));
        $teamCumulativeSteps[] = $runningSteps;
        $teamCumulativeDistance[] = round($runningDistance, 2);
    }

    $teamCumulativeByUser = [];
    if (count($dailyByUser) > 1) {
        foreach ($dailyByUser as $userId => $userDaily) {
            $userRunningSteps = 0;
            $userRunningDistance = 0.0;
            $userStepSeries = [];
            $userDistanceSeries = [];
            foreach (array_keys($filteredDaily) as $date) {
                $point = (array) (($userDaily['daily'][$date] ?? ['steps' => 0, 'distance' => 0.0]));
                $userRunningSteps += max(0, (int) ($point['steps'] ?? 0));
                $userRunningDistance += max(0.0, (float) ($point['distance'] ?? 0));
                $userStepSeries[] = $userRunningSteps;
                $userDistanceSeries[] = round($userRunningDistance, 2);
            }
            $teamCumulativeByUser[] = [
                'user_id' => (int) $userId,
                'display_name' => (string) ($userDaily['display_name'] ?? ''),
                'steps' => $userStepSeries,
                'distance' => $userDistanceSeries,
            ];
        }
    }

    $weeklyAgg = [];
    foreach ($metricsOrdered as $metric) {
        foreach (($metric['weekly'] ?? []) as $weekRow) {
            $weekStart = (string) ($weekRow['week_start'] ?? '');
            if ($weekStart === '') {
                continue;
            }
            if (!isset($weeklyAgg[$weekStart])) {
                $weeklyAgg[$weekStart] = [
                    'members' => 0,
                    'score_sum' => 0.0,
                    'strikes' => 0,
                    'penalties' => 0,
                ];
            }
            $scoreForWeek = max(
                0.0,
                100 - (
                    ((int) ($weekRow['step_failures'] ?? 0) * 6) +
                    ((int) ($weekRow['workout_failures'] ?? 0) * 8) +
                    ((int) ($weekRow['skip_warnings'] ?? 0) * 3) +
                    ((int) ($weekRow['strikes_after_week'] ?? 0) * 4)
                )
            );
            $weeklyAgg[$weekStart]['members']++;
            $weeklyAgg[$weekStart]['score_sum'] += $scoreForWeek;
            $weeklyAgg[$weekStart]['strikes'] += (int) ($weekRow['strikes_after_week'] ?? 0);
            $weeklyAgg[$weekStart]['penalties'] += (int) ($weekRow['penalty'] ?? 0);
        }
    }
    ksort($weeklyAgg);

    $filteredWeekly = [];
    foreach ($weeklyAgg as $weekStart => $row) {
        if ($teamView === 'total' || $teamView === 'current_week' || $weekStart === $selectedWeekStart) {
            $filteredWeekly[$weekStart] = $row;
        }
    }
    if ($teamView === 'current_week' && $filteredWeekly !== []) {
        $filteredWeekly = array_slice($filteredWeekly, -8, null, true);
    }
    if ($filteredWeekly === []) {
        $filteredWeekly = $weeklyAgg;
    }

    $teamWeeklyLabels = [];
    $teamWeeklyScore = [];
    $teamWeeklyStrikes = [];
    $teamWeeklyPenalties = [];
    foreach ($filteredWeekly as $weekStart => $row) {
        $teamWeeklyLabels[] = format_date_eu($weekStart);
        $members = max(1, (int) ($row['members'] ?? 0));
        $teamWeeklyScore[] = round(((float) ($row['score_sum'] ?? 0.0)) / $members, 1);
        $teamWeeklyStrikes[] = (int) ($row['strikes'] ?? 0);
        $teamWeeklyPenalties[] = (int) ($row['penalties'] ?? 0);
    }

    $formatTeamMetricValue = static function (string $metricKey, float|int $value): string {
        return match ($metricKey) {
            'distance' => number_format((float) $value, 2) . ' km',
            'penalty' => '€' . number_format((float) $value, 2),
            'score' => number_format((float) $value, 1),
            default => (string) ((int) round((float) $value)),
        };
    };

    $teamMetricConfigs = [
        'steps' => [
            'title' => t('metric.total_steps'),
            'summary_value' => (float) ($teamSummary['total_steps'] ?? 0),
            'chart_type' => 'line',
            'chart_labels' => $teamDailyLabels,
            'chart_values' => $teamDailySteps,
            'chart_color' => '#14a38b',
            'chart_fill' => 'rgba(20, 163, 139, 0.16)',
        ],
        'distance' => [
            'title' => t('metric.total_km'),
            'summary_value' => (float) ($teamSummary['total_km'] ?? 0),
            'chart_type' => 'line',
            'chart_labels' => $teamDailyLabels,
            'chart_values' => $teamDailyDistance,
            'chart_color' => '#3b82f6',
            'chart_fill' => 'rgba(59, 130, 246, 0.14)',
        ],
        'workouts' => [
            'title' => t('metric.workouts'),
            'summary_value' => max((float) ($teamSummary['workout_count'] ?? 0), (float) ($teamSummary['workout_success'] ?? 0)),
            'chart_type' => 'bar',
            'chart_labels' => $teamDailyLabels,
            'chart_values' => $teamDailyWorkouts,
            'chart_color' => '#ec4899',
            'chart_fill' => 'rgba(244, 114, 182, 0.35)',
        ],
        'score' => [
            'title' => t('metric.score'),
            'summary_value' => (float) ($teamSummary['score_avg'] ?? 0),
            'chart_type' => 'line',
            'chart_labels' => $teamWeeklyLabels,
            'chart_values' => $teamWeeklyScore,
            'chart_color' => '#14a38b',
            'chart_fill' => 'rgba(20, 163, 139, 0.14)',
        ],
        'strikes' => [
            'title' => t('metric.strikes'),
            'summary_value' => (float) ($teamSummary['strikes'] ?? 0),
            'chart_type' => 'line',
            'chart_labels' => $teamWeeklyLabels,
            'chart_values' => $teamWeeklyStrikes,
            'chart_color' => '#f97316',
            'chart_fill' => 'rgba(249, 115, 22, 0.12)',
        ],
        'penalty' => [
            'title' => t('metric.penalty'),
            'summary_value' => (float) ($teamSummary['penalty'] ?? 0),
            'chart_type' => 'line',
            'chart_labels' => $teamWeeklyLabels,
            'chart_values' => $teamWeeklyPenalties,
            'chart_color' => '#ef4444',
            'chart_fill' => 'rgba(239, 68, 68, 0.12)',
        ],
    ];
    if (!$penaltiesEnabled) {
        unset($teamMetricConfigs['strikes'], $teamMetricConfigs['penalty']);
    }

    $teamMetricKey = trim((string) ($_GET['metric'] ?? ''));
    $teamMetricDetail = null;
    if ($teamMetricKey !== '') {
        if (!isset($teamMetricConfigs[$teamMetricKey])) {
            flash_set('error', t('metric.invalid'));
            $teamRedirectParams = ['page' => 'team', 'team_id' => (int) $team['id']];
            if ($teamView !== '') {
                $teamRedirectParams['view'] = $teamView;
            }
            redirect('/?' . http_build_query($teamRedirectParams));
        }

        $comparisonRows = [];
        foreach ($teamComparisonRows as $row) {
            $value = match ($teamMetricKey) {
                'distance' => (float) ($row['distance'] ?? 0),
                'workouts' => (float) ($row['workouts'] ?? 0),
                'score' => (float) ($row['score'] ?? 0),
                'strikes' => (float) ($row['strikes'] ?? 0),
                'penalty' => (float) ($row['penalties'] ?? 0),
                default => (float) ($row['steps'] ?? 0),
            };
            $comparisonRows[] = [
                'user_id' => (int) ($row['user_id'] ?? 0),
                'display_name' => (string) ($row['display_name'] ?? ''),
                'value' => $value,
                'value_display' => $formatTeamMetricValue($teamMetricKey, $value),
            ];
        }

        usort(
            $comparisonRows,
            static function (array $left, array $right) use ($teamMetricKey): int {
                if ($teamMetricKey === 'penalty') {
                    return $left['value'] <=> $right['value'];
                }

                return $right['value'] <=> $left['value'];
            }
        );

        $metricConfig = $teamMetricConfigs[$teamMetricKey];
        $totalValue = (float) ($metricConfig['summary_value'] ?? 0);
        $teamMetricDetail = [
            'key' => $teamMetricKey,
            'title' => (string) $metricConfig['title'],
            'total_value' => $totalValue,
            'total_display' => $formatTeamMetricValue($teamMetricKey, $totalValue),
            'chart_type' => (string) $metricConfig['chart_type'],
            'chart_labels' => array_values((array) ($metricConfig['chart_labels'] ?? [])),
            'chart_values' => array_values((array) ($metricConfig['chart_values'] ?? [])),
            'chart_color' => (string) $metricConfig['chart_color'],
            'chart_fill' => (string) $metricConfig['chart_fill'],
            'comparison_rows' => $comparisonRows,
        ];
    }

    $teamSection = (string) ($_GET['section'] ?? '');
    if (!in_array($teamSection, ['', 'member'], true)) {
        $teamSection = '';
    }

    $teamMemberDetail = null;
    if ($teamSection === 'member') {
        $teamMemberId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
        $memberIsInTeam = false;
        foreach ($teamUsers as $teamUser) {
            if ((int) ($teamUser['id'] ?? 0) === $teamMemberId) {
                $memberIsInTeam = true;
                break;
            }
        }

        if (!$memberIsInTeam) {
            flash_set('error', t('flash.no_permission'));
            redirect('/?' . http_build_query([
                'page' => 'team',
                'team_id' => (int) $team['id'],
                'view' => $teamView,
            ]));
        }

        $memberMetric = $metricsByUser[$teamMemberId] ?? null;
        if (!is_array($memberMetric)) {
            flash_set('error', t('flash.not_found'));
            redirect('/?' . http_build_query([
                'page' => 'team',
                'team_id' => (int) $team['id'],
                'view' => $teamView,
            ]));
        }

        $memberStepsSeries = (array) ($memberMetric['steps_series'] ?? []);
        $memberStepsLabels = [];
        $memberStepsValues = [];
        $memberDistanceValues = [];
        foreach ($memberStepsSeries as $seriesRow) {
            $memberStepsLabels[] = format_date_eu((string) ($seriesRow['date'] ?? ''));
            $memberStepsValues[] = (int) ($seriesRow['steps'] ?? 0);
            $memberDistanceValues[] = round((float) ($seriesRow['km'] ?? 0), 2);
        }

        $memberWeeklyRows = (array) ($memberMetric['weekly'] ?? []);
        $memberWeeklyLabels = [];
        $memberWorkoutWeekly = [];
        $memberScoreWeekly = [];
        $memberPenaltyWeekly = [];
        foreach ($memberWeeklyRows as $weeklyRow) {
            $memberWeeklyLabels[] = format_date_eu((string) ($weeklyRow['week_start'] ?? ''));
            $memberWorkoutWeekly[] = max(0, (int) ($weeklyRow['workouts'] ?? 0));
            $memberScoreWeekly[] = round(max(
                0.0,
                100 - (
                    ((int) ($weeklyRow['step_failures'] ?? 0) * 6) +
                    ((int) ($weeklyRow['workout_failures'] ?? 0) * 8) +
                    ((int) ($weeklyRow['skip_warnings'] ?? 0) * 3) +
                    ((int) ($weeklyRow['strikes_after_week'] ?? 0) * 4)
                )
            ), 1);
            $memberPenaltyWeekly[] = (int) ($weeklyRow['penalty'] ?? 0);
        }

        $memberRecentActivity = fetch_audit_logs(
            $pdo,
            ['actor_user_id' => $teamMemberId],
            20
        );
        $memberAchievements = list_awarded_achievements($pdo, $teamMemberId, null);

        $teamMemberDetail = [
            'user' => (array) ($memberMetric['user'] ?? []),
            'metric' => $memberMetric,
            'steps_labels' => $memberStepsLabels,
            'steps_values' => $memberStepsValues,
            'distance_values' => $memberDistanceValues,
            'weekly_labels' => $memberWeeklyLabels,
            'workout_weekly' => $memberWorkoutWeekly,
            'score_weekly' => $memberScoreWeekly,
            'penalty_weekly' => $memberPenaltyWeekly,
            'recent_activity' => $memberRecentActivity,
            'achievements' => $memberAchievements,
        ];
    }

    $goalTypeLabel = static function (string $targetType): string {
        return match (normalize_goal_target_type($targetType)) {
            'steps' => t('metric.steps'),
            'km' => t('metric.distance_km'),
            'workouts' => t('metric.workouts'),
            'score' => t('metric.score'),
            'calories_burned' => t('dashboard.calories_burned'),
            'calories_consumed' => t('dashboard.calories_consumed'),
            'penalties' => t('metric.penalty'),
            'strikes' => t('metric.strikes'),
            'weight' => t('metric.weight'),
            default => t('common.other'),
        };
    };
    $formatGoalValue = static function (float $value, string $targetType, ?string $unitLabel = null): string {
        $normalizedType = normalize_goal_target_type($targetType);
        $unit = trim((string) $unitLabel);
        if ($unit === '') {
            $unit = goal_target_default_unit($normalizedType);
        }
        $rounded = match ($normalizedType) {
            'steps', 'workouts', 'strikes', 'calories_burned', 'calories_consumed' => (string) ((int) round($value)),
            'score', 'weight' => number_format($value, 1, '.', ''),
            'km' => number_format($value, 2, '.', ''),
            'penalties' => number_format($value, 2, '.', ''),
            default => fmod($value, 1.0) === 0.0 ? (string) ((int) $value) : number_format($value, 2, '.', ''),
        };
        if ($normalizedType === 'penalties') {
            return '€' . $rounded;
        }

        return $unit !== '' ? $rounded . ' ' . $unit : $rounded;
    };
    $teamGoalsRaw = list_goals($pdo, 'team', null, (int) $team['id']);
    $teamGoals = [];
    $nowDateTime = new DateTimeImmutable('now');
    $formatDebugNumber = static function (float $value): string {
        $normalized = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
        return $normalized !== '' ? $normalized : '0';
    };
    $challengeEndDeadline = null;
    $challengeEndDate = to_date((string) ($settings['challenge_end'] ?? ''), '');
    if ($challengeEndDate !== '') {
        try {
            $challengeEndDeadline = new DateTimeImmutable($challengeEndDate . ' 23:59:59');
        } catch (Throwable) {
            $challengeEndDeadline = null;
        }
    }
    foreach ($teamGoalsRaw as $goal) {
        $type = normalize_goal_target_type((string) ($goal['target_type'] ?? 'custom'));
        $unitLabel = trim((string) ($goal['unit_label'] ?? ''));
        $goalStartDate = trim((string) ($goal['start_date'] ?? ''));
        $goalStartTime = $goalStartDate !== '' ? normalize_goal_start_time((string) ($goal['start_time'] ?? ''), '00:00') : '';
        $goalStartAt = $goalStartDate !== '' ? log_datetime_from_values($goalStartDate, $goalStartTime, '00:00') : null;
        $goalDueDate = trim((string) ($goal['due_date'] ?? ''));
        $goalDueTime = normalize_goal_due_time($goalDueDate !== '' ? $goalDueDate : null, (string) ($goal['due_time'] ?? ''));
        $goalDueAt = $goalDueDate !== '' && $goalDueTime !== null ? log_datetime_from_values($goalDueDate, $goalDueTime, '23:59') : null;
        if ($unitLabel === '') {
            $unitLabel = goal_target_default_unit($type);
        }
        $goalProgressState = goal_team_progress_state($pdo, $goal, $teamSummaryTotal, $nowDateTime);
        $goalForProgress = is_array($goalProgressState['goal'] ?? null) ? (array) $goalProgressState['goal'] : $goal;
        $primaryState = is_array($goalProgressState['primary'] ?? null) ? (array) $goalProgressState['primary'] : [];
        $secondaryState = is_array($goalProgressState['secondary'] ?? null) ? (array) $goalProgressState['secondary'] : [];
        $hasStarted = !empty($goalProgressState['has_started']);
        $currentMetricValue = (float) ($primaryState['current_metric_value'] ?? $goalProgressState['current_metric_value'] ?? goal_team_metric_value($goalForProgress, $teamSummaryTotal));
        $progressValue = $hasStarted ? (float) ($primaryState['progress_value'] ?? $goalProgressState['progress_value'] ?? 0.0) : 0.0;
        $targetValue = max(0.0, (float) ($goal['target_value'] ?? 0));
        if ((string) ($goal['status'] ?? '') === 'complete' && $targetValue > 0 && $progressValue < $targetValue) {
            $progressValue = $targetValue;
        }
        $primaryProgressPctRaw = $targetValue > 0 ? round(($progressValue / $targetValue) * 100, 1) : 0.0;
        $progressPctRaw = (float) ($goalProgressState['progress_pct_raw'] ?? $primaryProgressPctRaw);
        $secondaryEnabled = $secondaryState !== [];
        $secondaryType = normalize_goal_target_type((string) ($secondaryState['target_type'] ?? $goalForProgress['secondary_target_type'] ?? 'custom'));
        $secondaryUnitLabel = trim((string) ($goalForProgress['secondary_unit_label'] ?? ''));
        if ($secondaryUnitLabel === '') {
            $secondaryUnitLabel = goal_target_default_unit($secondaryType);
        }
        $secondaryTargetValue = $secondaryEnabled ? max(0.0, (float) ($secondaryState['target_value'] ?? $goalForProgress['secondary_target_value'] ?? 0)) : 0.0;
        $secondaryProgressValue = $secondaryEnabled && $hasStarted
            ? (float) ($secondaryState['progress_value'] ?? 0.0)
            : 0.0;
        if ($secondaryEnabled && (string) ($goal['status'] ?? '') === 'complete' && $secondaryTargetValue > 0 && $secondaryProgressValue < $secondaryTargetValue) {
            $secondaryProgressValue = $secondaryTargetValue;
        }
        $secondaryProgressPctRaw = $secondaryEnabled && $secondaryTargetValue > 0
            ? round(($secondaryProgressValue / $secondaryTargetValue) * 100, 1)
            : 0.0;
        if ($secondaryEnabled) {
            $progressPctRaw = round(($primaryProgressPctRaw + $secondaryProgressPctRaw) / 2, 1);
        }
        if ((string) ($goal['status'] ?? '') === 'complete') {
            $progressPctRaw = max(100.0, $progressPctRaw);
        }
        $progressPctVisual = max(0.0, min(100.0, $progressPctRaw));
        $baselineValue = is_numeric($goalForProgress['baseline_value'] ?? null) ? (float) $goalForProgress['baseline_value'] : $currentMetricValue;
        $baselineDisplay = is_numeric($goalForProgress['baseline_value'] ?? null)
            ? $formatGoalValue($baselineValue, $type, $unitLabel)
            : '-';
        $secondaryBaselineValue = is_numeric($goalForProgress['secondary_baseline_value'] ?? null)
            ? (float) $goalForProgress['secondary_baseline_value']
            : null;
        $secondaryBaselineDisplay = $secondaryEnabled && $secondaryBaselineValue !== null
            ? $formatGoalValue($secondaryBaselineValue, $secondaryType, $secondaryUnitLabel)
            : '-';

        $countdownMode = 'end';
        $countdownDeadline = null;
        $countdownNextDeadline = null;
        if (!$hasStarted && $goalStartAt instanceof DateTimeImmutable) {
            $countdownMode = 'start';
            $countdownDeadline = $goalStartAt;
            $countdownNextDeadline = $goalDueAt instanceof DateTimeImmutable ? $goalDueAt : $challengeEndDeadline;
        } else {
            $countdownDeadline = $goalDueAt instanceof DateTimeImmutable ? $goalDueAt : $challengeEndDeadline;
        }
        $isExpired = $hasStarted && $countdownMode === 'end' && $countdownDeadline instanceof DateTimeImmutable && $nowDateTime >= $countdownDeadline;

        $teamGoals[] = array_merge($goalForProgress, [
            'target_type_normalized' => $type,
            'target_type_label' => $goalTypeLabel($type),
            'unit_label_resolved' => $unitLabel,
            'is_lower_better' => goal_target_type_is_lower_better($type),
            'direction_label' => goal_target_type_is_lower_better($type) ? t('goals.lower_better') : t('goals.higher_better'),
            'has_started' => $hasStarted,
            'progress_value' => $progressValue,
            'progress_pct' => $progressPctRaw,
            'progress_pct_raw' => $progressPctRaw,
            'progress_pct_visual' => $progressPctVisual,
            'progress_display' => $formatGoalValue($progressValue, $type, $unitLabel),
            'target_display' => $formatGoalValue($targetValue, $type, $unitLabel),
            'baseline_display' => $baselineDisplay,
            'primary_progress_display' => $formatGoalValue($progressValue, $type, $unitLabel),
            'primary_target_display' => $formatGoalValue($targetValue, $type, $unitLabel),
            'primary_progress_pct_raw' => $primaryProgressPctRaw,
            'primary_progress_pct_visual' => max(0.0, min(100.0, $primaryProgressPctRaw)),
            'secondary_enabled' => $secondaryEnabled,
            'secondary_target_value' => $secondaryTargetValue,
            'secondary_target_type_normalized' => $secondaryType,
            'secondary_target_type_label' => $secondaryEnabled ? $goalTypeLabel($secondaryType) : null,
            'secondary_unit_label_resolved' => $secondaryUnitLabel,
            'secondary_progress_value' => $secondaryProgressValue,
            'secondary_progress_display' => $secondaryEnabled ? $formatGoalValue($secondaryProgressValue, $secondaryType, $secondaryUnitLabel) : null,
            'secondary_target_display' => $secondaryEnabled ? $formatGoalValue($secondaryTargetValue, $secondaryType, $secondaryUnitLabel) : null,
            'secondary_baseline_display' => $secondaryBaselineDisplay,
            'secondary_progress_pct_raw' => $secondaryProgressPctRaw,
            'secondary_progress_pct_visual' => max(0.0, min(100.0, $secondaryProgressPctRaw)),
            'current_metric_value' => $currentMetricValue,
            'baseline_value_numeric' => is_numeric($goalForProgress['baseline_value'] ?? null) ? (float) $goalForProgress['baseline_value'] : null,
            'progress_debug' => [
                'current_metric' => $formatDebugNumber($currentMetricValue),
                'baseline' => is_numeric($goalForProgress['baseline_value'] ?? null) ? $formatDebugNumber((float) $goalForProgress['baseline_value']) : 'null',
                'progress' => $formatDebugNumber($progressValue),
                'target' => $formatDebugNumber($targetValue),
                'secondary_progress' => $secondaryEnabled ? $formatDebugNumber($secondaryProgressValue) : 'n/a',
                'secondary_target' => $secondaryEnabled ? $formatDebugNumber($secondaryTargetValue) : 'n/a',
            ],
            'start_date_resolved' => $goalStartDate !== '' ? $goalStartDate : null,
            'start_time_resolved' => $goalStartDate !== '' ? $goalStartTime : null,
            'start_at' => $goalStartAt instanceof DateTimeImmutable ? $goalStartAt->format('Y-m-d H:i') : null,
            'due_time_resolved' => $goalDueTime,
            'due_at' => $goalDueDate !== '' && $goalDueTime !== null ? ($goalDueDate . ' ' . $goalDueTime) : null,
            'countdown_mode' => $countdownMode,
            'countdown_deadline_iso' => $countdownDeadline instanceof DateTimeImmutable ? $countdownDeadline->format(DateTimeInterface::ATOM) : null,
            'countdown_next_deadline_iso' => $countdownNextDeadline instanceof DateTimeImmutable ? $countdownNextDeadline->format(DateTimeInterface::ATOM) : null,
            'is_expired' => $isExpired,
        ]);
    }

    $teamGoalDebugEnabled = isset($_GET['debug_goal']) && (string) $_GET['debug_goal'] === '1';

    $teamActiveChallenge = null;
    $activeGoals = array_values(array_filter(
        $teamGoals,
        static fn(array $goal): bool => (string) ($goal['status'] ?? '') === 'active'
    ));
    if ($activeGoals !== []) {
        usort(
            $activeGoals,
            static function (array $left, array $right): int {
                $leftDueRaw = trim((string) ($left['due_at'] ?? ''));
                $rightDueRaw = trim((string) ($right['due_at'] ?? ''));
                $leftHasDue = $leftDueRaw !== '';
                $rightHasDue = $rightDueRaw !== '';

                if ($leftHasDue && !$rightHasDue) {
                    return -1;
                }
                if (!$leftHasDue && $rightHasDue) {
                    return 1;
                }
                if ($leftHasDue && $rightHasDue) {
                    if ($leftDueRaw !== $rightDueRaw) {
                        return strcmp($leftDueRaw, $rightDueRaw);
                    }
                }

                return strcmp((string) ($left['created_at'] ?? ''), (string) ($right['created_at'] ?? ''));
            }
        );

        $teamActiveChallenge = $activeGoals[0];
        $isPreStart = empty($teamActiveChallenge['has_started']);
        $teamActiveChallenge['is_pre_start'] = $isPreStart;
        $teamActiveChallenge['countdown_mode'] = $isPreStart ? 'start' : 'end';
        $teamActiveChallenge['countdown_label'] = $isPreStart
            ? t('team.active_challenge_starts_in')
            : t('team.active_challenge_time_left');
        if ($isPreStart) {
            $teamActiveChallenge['is_expired'] = false;
        } elseif (!empty($teamActiveChallenge['countdown_deadline_iso'])) {
            try {
                $teamActiveChallenge['is_expired'] = $nowDateTime >= new DateTimeImmutable((string) $teamActiveChallenge['countdown_deadline_iso']);
            } catch (Throwable) {
                $teamActiveChallenge['is_expired'] = false;
            }
        } else {
            $teamActiveChallenge['is_expired'] = false;
        }
    }

    $teamLayoutWidgets = normalize_team_layout_widgets((string) ($currentUser['team_layout_json'] ?? ''));
    $teamLayoutLabels = [
        'metrics' => t('team.widget_metrics'),
        'active_challenge' => t('team.widget_active_challenge'),
        'leaderboard' => t('team.widget_leaderboard'),
        'challenges' => t('team.widget_challenges'),
        'members' => t('team.widget_members'),
        'daily_charts' => t('team.widget_daily_charts'),
        'cumulative_steps' => t('team.widget_cumulative_steps'),
        'cumulative_distance' => t('team.widget_cumulative_distance'),
        'weekly_charts' => t('team.widget_weekly_charts'),
        'achievements' => t('team.widget_achievements'),
    ];
    $teamLayoutEditMode = (string) ($_GET['layout_edit'] ?? '') === '1' && $teamSection === '' && $teamMetricDetail === null;
    $teamTopbarQuery = [
        'page' => 'team',
        'team_id' => (int) ($team['id'] ?? 0),
        'view' => $teamView,
    ];
    if ($teamGoalDebugEnabled) {
        $teamTopbarQuery['debug_goal'] = '1';
    }
    $teamEditLayoutUrl = '/?' . http_build_query($teamTopbarQuery + ['layout_edit' => '1']);

    ob_start();
    ?>
    <?php if ($teamLayoutEditMode): ?>
    <button class="btn btn-primary btn-topbar" type="submit" form="team-layout-edit-form"><?= e(t('common.save')) ?></button>
    <?php else: ?>
    <details class="topbar-context">
        <summary class="btn btn-ghost btn-topbar"><?= e(t('dashboard.view_mode')) ?></summary>
        <div class="topbar-context-panel">
            <form method="get" class="stack">
                <input type="hidden" name="page" value="team">
                <input type="hidden" name="team_id" value="<?= (int) ($team['id'] ?? 0) ?>">
                <?php if ($teamGoalDebugEnabled): ?>
                    <input type="hidden" name="debug_goal" value="1">
                <?php endif; ?>
                <?php if ($teamSection === 'member' && $teamMemberDetail !== null): ?>
                    <input type="hidden" name="section" value="member">
                    <input type="hidden" name="user_id" value="<?= (int) ($teamMemberDetail['user']['id'] ?? 0) ?>">
                <?php endif; ?>
                <?php if ($teamMetricDetail !== null): ?>
                    <input type="hidden" name="metric" value="<?= e((string) ($teamMetricDetail['key'] ?? '')) ?>">
                <?php endif; ?>
                <label>
                    <?= e(t('dashboard.view_mode')) ?>
                    <select name="view" onchange="this.form.submit()">
                        <option value="current_week" <?= $teamView === 'current_week' ? 'selected' : '' ?>><?= e(t('dashboard.current_week')) ?></option>
                        <option value="total" <?= $teamView === 'total' ? 'selected' : '' ?>><?= e(t('metric.total')) ?></option>
                        <?php foreach ($weekOptions as $weekStart): ?>
                            <option value="<?= e((string) $weekStart) ?>" <?= $teamView === (string) $weekStart ? 'selected' : '' ?>><?= e(format_date_eu((string) $weekStart)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </form>
            <?php if ($teamSection === '' && $teamMetricDetail === null): ?>
                <a class="btn btn-primary btn-block" href="<?= e($teamEditLayoutUrl) ?>"><?= e(t('team.edit_layout')) ?></a>
            <?php endif; ?>
        </div>
    </details>
    <?php endif; ?>
    <?php
    $teamTopbarControls = ob_get_clean();

    render_view('team', [
        'title' => t('team.title'),
        'currentPage' => 'team',
        'currentUser' => $currentUser,
        'team' => $team,
        'members' => list_team_members($pdo, (int) $team['id'], true),
        'availableUsers' => list_users_not_in_active_team($pdo, (int) $team['id']),
        'metricsOrdered' => $metricsOrdered,
        'teamSummary' => $teamSummary,
        'teamView' => $teamView,
        'teamLayoutEditMode' => $teamLayoutEditMode,
        'teamLayoutLabels' => $teamLayoutLabels,
        'teamWeekOptions' => $weekOptions,
        'teamSelectedWeekStart' => $selectedWeekStart,
        'teamDailyLabels' => $teamDailyLabels,
        'teamDailySteps' => $teamDailySteps,
        'teamDailyDistance' => $teamDailyDistance,
        'teamDailyWorkouts' => $teamDailyWorkouts,
        'teamCumulativeLabels' => $teamCumulativeLabels,
        'teamCumulativeSteps' => $teamCumulativeSteps,
        'teamCumulativeDistance' => $teamCumulativeDistance,
        'teamCumulativeByUser' => $teamCumulativeByUser,
        'teamWeeklyLabels' => $teamWeeklyLabels,
        'teamWeeklyScore' => $teamWeeklyScore,
        'teamWeeklyStrikes' => $teamWeeklyStrikes,
        'teamWeeklyPenalties' => $teamWeeklyPenalties,
        'teamComparisonRows' => $teamComparisonRows,
        'teamMetricDetail' => $teamMetricDetail,
        'teamSection' => $teamSection,
        'teamMemberDetail' => $teamMemberDetail,
        'teamGoals' => $teamGoals,
        'teamActiveChallenge' => $teamActiveChallenge,
        'teamGoalDebugEnabled' => $teamGoalDebugEnabled,
        'challengeSettings' => $settings,
        'teamAchievements' => list_awarded_achievements($pdo, null, (int) $team['id']),
        'canDeleteAchievements' => can_manage_team($pdo, $currentUser, (int) $team['id']),
        'canManageTeam' => can_manage_team($pdo, $currentUser, (int) $team['id']),
        'topbarControls' => $teamTopbarControls,
        'config' => $config,
    ]);
}

if ($page === 'metric') {
    $settings = challenge_settings($pdo, $config);
    if (!challenge_is_active($settings)) {
        flash_set('error', t('flash.challenge_inactive'));
        redirect('/?page=admin');
    }

    if (is_post()) {
        if (!csrf_verify()) {
            flash_set('error', t('flash.csrf'));
            redirect('/?page=analytics');
        }

        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save_analytics_layout') {
            $allowedSections = analytics_layout_sections_default();
            $resetLayout = !empty($_POST['reset_analytics_layout']);
            $sections = [];
            if (!$resetLayout) {
                $sections = array_values(array_intersect(array_map('strval', (array) ($_POST['analytics_sections'] ?? [])), $allowedSections));
                $sections = array_values(array_unique($sections));
                $sectionOrder = (array) ($_POST['analytics_order'] ?? []);
                usort($sections, static function (string $left, string $right) use ($sectionOrder, $allowedSections): int {
                    $leftOrder = isset($sectionOrder[$left]) ? (int) $sectionOrder[$left] : (int) array_search($left, $allowedSections, true);
                    $rightOrder = isset($sectionOrder[$right]) ? (int) $sectionOrder[$right] : (int) array_search($right, $allowedSections, true);

                    return $leftOrder <=> $rightOrder;
                });
            }

            db_execute(
                $pdo,
                'UPDATE users SET analytics_layout_json = :analytics_layout_json, updated_at = :updated_at WHERE id = :id',
                [
                    ':analytics_layout_json' => $resetLayout ? null : json_encode($sections, JSON_UNESCAPED_SLASHES),
                    ':updated_at' => now_iso(),
                    ':id' => (int) $currentUser['id'],
                ]
            );

            $redirectParams = ['page' => 'analytics'];
            if (!empty($_POST['redirect_user_id'])) {
                $redirectParams['user_id'] = (int) $_POST['redirect_user_id'];
            }
            $redirectPeriod = (string) ($_POST['analytics_period'] ?? 'current_week');
            if (in_array($redirectPeriod, ['current_week', 'week', 'month', 'total'], true)) {
                $redirectParams['analytics_period'] = $redirectPeriod;
            }
            $redirectWeek = trim((string) ($_POST['analytics_week'] ?? ''));
            if ($redirectWeek !== '') {
                $redirectParams['analytics_week'] = to_date($redirectWeek);
            }
            $redirectMonth = trim((string) ($_POST['analytics_month'] ?? ''));
            if (preg_match('/^\d{4}-\d{2}$/', $redirectMonth)) {
                $redirectParams['analytics_month'] = $redirectMonth;
            }

            flash_set('success', t('analytics.layout_saved'));
            redirect('/?' . http_build_query($redirectParams));
        }
    }

    $team = default_team($pdo);
    $users = list_active_team_users($pdo, (int) $team['id']);
    if ($users === []) {
        $users = list_active_users($pdo);
    }

    $metricsByUser = compute_challenge_metrics(
        $pdo,
        $users,
        (string) $settings['challenge_start'],
        (string) $settings['challenge_end']
    );
    $metricsByUser = apply_strike_review_overrides_to_metrics($pdo, $metricsByUser);
    evaluate_automatic_achievements($pdo, $metricsByUser, (int) $team['id']);

    $metricsById = [];
    foreach ($metricsByUser as $userId => $metric) {
        $metricsById[(int) $userId] = $metric;
    }

    $selectedUserId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : (int) $currentUser['id'];
    if (!is_admin($currentUser) && $selectedUserId !== (int) $currentUser['id']) {
        $selectedUserId = (int) $currentUser['id'];
    }

    $selectedMetric = $metricsById[$selectedUserId] ?? null;
    if ($selectedMetric === null) {
        $selectedMetric = count($metricsByUser) > 0 ? array_values($metricsByUser)[0] : null;
    }
    if ($selectedMetric === null) {
        flash_set('error', t('flash.no_active_users'));
        redirect('/?page=dashboard');
    }

    $penaltiesEnabled = penalties_enabled($pdo);
    $allowedMetrics = [
        'steps' => t('metric.steps'),
        'distance' => t('metric.distance_km'),
        'workouts' => t('metric.workouts'),
        'score' => t('metric.score'),
    ];
    if ($penaltiesEnabled) {
        $allowedMetrics['strikes'] = t('metric.strikes');
        $allowedMetrics['money'] = t('metric.penalty');
    }
    $metricKey = (string) ($_GET['metric'] ?? 'steps');
    if (!isset($allowedMetrics[$metricKey])) {
        flash_set('error', t('metric.invalid'));
        redirect('/?page=dashboard');
    }

    $weeklyRows = array_values((array) ($selectedMetric['weekly'] ?? []));
    usort(
        $weeklyRows,
        static fn(array $left, array $right): int => strcmp((string) ($left['week_start'] ?? ''), (string) ($right['week_start'] ?? ''))
    );
    $weekOptionsMap = [];
    foreach ($weeklyRows as $weekRow) {
        $weekStart = (string) ($weekRow['week_start'] ?? '');
        if ($weekStart !== '') {
            $weekOptionsMap[$weekStart] = true;
        }
    }
    $weekOptions = array_keys($weekOptionsMap);
    sort($weekOptions);
    $defaultWeekStart = $weekOptions !== [] ? $weekOptions[count($weekOptions) - 1] : to_date(null);
    $normalizeDashboardWeekView = static function (string $rawView, string $fallback): string {
        $normalizedDate = to_date($rawView, $fallback);
        try {
            return week_start_for(new DateTimeImmutable($normalizedDate))->format('Y-m-d');
        } catch (Throwable) {
            return $fallback;
        }
    };
    $dashboardView = (string) ($_GET['view'] ?? ($currentUser['dashboard_view'] ?? 'current_week'));
    if (!in_array($dashboardView, ['current_week', 'total'], true)) {
        $dashboardView = $normalizeDashboardWeekView($dashboardView, $defaultWeekStart);
    }
    $selectedWeekStart = $defaultWeekStart;
    if ($dashboardView !== 'total') {
        $selectedWeekStart = $dashboardView === 'current_week'
            ? $defaultWeekStart
            : $normalizeDashboardWeekView($dashboardView, $defaultWeekStart);
        if (!in_array($selectedWeekStart, $weekOptions, true) && $weekOptions !== []) {
            $selectedWeekStart = $defaultWeekStart;
        }
    }

    $selectedWeeklyRows = [];
    if ($dashboardView === 'total') {
        $selectedWeeklyRows = $weeklyRows;
    } else {
        foreach ($weeklyRows as $weekRow) {
            if ((string) ($weekRow['week_start'] ?? '') === $selectedWeekStart) {
                $selectedWeeklyRows[] = $weekRow;
            }
        }
    }
    if ($selectedWeeklyRows === [] && $weeklyRows !== []) {
        $selectedWeeklyRows = [$weeklyRows[count($weeklyRows) - 1]];
    }
    $seriesLabels = [];
    $seriesValues = [];
    $currentValue = 0;
    $currentValueSuffix = '';
    $chartLabel = $allowedMetrics[$metricKey];
    $score_for_week = static function (array $row) use ($selectedMetric): float {
        $stepRequired = max(
            0,
            (int) ($row['step_days_required_week'] ?? ((int) ($row['step_days_success_week'] ?? 0) + (int) ($row['step_failures'] ?? 0)))
        );
        $stepSuccess = max(
            0,
            min($stepRequired, (int) ($row['step_days_success_week'] ?? ($stepRequired - (int) ($row['step_failures'] ?? 0))))
        );
        $stepPct = $stepRequired > 0 ? round(($stepSuccess / $stepRequired) * 100, 1) : 0.0;
        $workoutTarget = max(0, (int) ($row['workout_target_week'] ?? 0));
        $workoutSuccess = max(
            max(0, (int) ($row['workouts'] ?? 0)),
            isset($row['workout_success_week'])
                ? max(0, (int) ($row['workout_success_week'] ?? 0))
                : max(0, $workoutTarget - (int) ($row['workout_failures'] ?? 0))
        );
        $workoutPct = $workoutTarget > 0 ? round(($workoutSuccess / $workoutTarget) * 100, 1) : 0.0;
        $strikesNet = max(
            0,
            (int) ($row['total_failures'] ?? ((int) ($row['step_failures'] ?? 0) + (int) ($row['workout_failures'] ?? 0)))
            - (int) ($row['strike_reduction'] ?? 0)
        );
        $warnings = max(0, (int) ($row['skip_warnings'] ?? 0));
        $disciplineScore = max(0.0, 100.0 - min(100.0, ($strikesNet * 10) + ($warnings * 3)));
        $weightProgress = null;
        if (array_key_exists('weight_progress_pct', $selectedMetric) && $selectedMetric['weight_progress_pct'] !== null && is_numeric($selectedMetric['weight_progress_pct'])) {
            $weightProgress = (float) $selectedMetric['weight_progress_pct'];
        }
        $components = score_components_from_progress($stepPct, $workoutPct, $disciplineScore, $weightProgress);

        return score_value_from_components($components);
    };
    $workout_success_for_week = static function (array $row): int {
        $workouts = max(0, (int) ($row['workouts'] ?? 0));
        if (isset($row['workout_success_week'])) {
            return max($workouts, (int) ($row['workout_success_week'] ?? 0));
        }
        if (isset($row['workout_target_week'])) {
            return max($workouts, max(0, (int) ($row['workout_target_week'] ?? 0) - (int) ($row['workout_failures'] ?? 0)));
        }

        return $workouts;
    };
    $strikes_net_for_week = static function (array $row): int {
        $totalFailures = (int) ($row['total_failures'] ?? ((int) ($row['step_failures'] ?? 0) + (int) ($row['workout_failures'] ?? 0)));
        $strikeReduction = (int) ($row['strike_reduction'] ?? 0);

        return $totalFailures - $strikeReduction;
    };

    if ($metricKey === 'steps') {
        $seriesLabels = array_map(static fn(array $row): string => format_date_eu((string) ($row['week_start'] ?? '')), $selectedWeeklyRows);
        $seriesValues = array_map(static fn(array $row): int => (int) ($row['steps'] ?? 0), $selectedWeeklyRows);
        $currentValue = array_sum($seriesValues);
    }

    if ($metricKey === 'distance') {
        $seriesLabels = array_map(static fn(array $row): string => format_date_eu((string) ($row['week_start'] ?? '')), $selectedWeeklyRows);
        $seriesValues = array_map(static fn(array $row): float => round((float) ($row['km'] ?? 0), 2), $selectedWeeklyRows);
        $currentValue = array_sum($seriesValues);
        $currentValueSuffix = ' km';
    }

    if ($metricKey === 'workouts') {
        $seriesLabels = array_map(static fn(array $row): string => format_date_eu((string) ($row['week_start'] ?? '')), $selectedWeeklyRows);
        $seriesValues = array_map($workout_success_for_week, $selectedWeeklyRows);
        $currentValue = array_sum($seriesValues);
    }

    if ($metricKey === 'money') {
        $seriesLabels = array_map(static fn(array $row): string => format_date_eu((string) ($row['week_start'] ?? '')), $selectedWeeklyRows);
        $seriesValues = array_map(static fn(array $row): int => (int) ($row['penalty'] ?? 0), $selectedWeeklyRows);
        $currentValue = (float) array_sum($seriesValues);
        $currentValueSuffix = ' €';
    }

    if ($metricKey === 'strikes') {
        $seriesLabels = array_map(static fn(array $row): string => format_date_eu((string) ($row['week_start'] ?? '')), $selectedWeeklyRows);
        $seriesValues = array_map($strikes_net_for_week, $selectedWeeklyRows);
        $currentValue = array_sum($seriesValues);
    }

    if ($metricKey === 'score') {
        $seriesLabels = array_map(static fn(array $row): string => format_date_eu((string) ($row['week_start'] ?? '')), $selectedWeeklyRows);
        $seriesValues = array_map($score_for_week, $selectedWeeklyRows);
        $seriesCount = count($seriesValues);
        $currentValue = $seriesCount > 0 ? round(array_sum($seriesValues) / $seriesCount, 1) : 0;
    }

    $backUrl = '/?' . http_build_query([
        'page' => 'dashboard',
        'user_id' => (int) ($selectedMetric['user']['id'] ?? 0),
        'view' => $dashboardView,
    ]);

    render_view('metric', [
        'title' => t('metric.detail_title'),
        'currentPage' => 'dashboard',
        'currentUser' => $currentUser,
        'users' => $users,
        'selectedMetric' => $selectedMetric,
        'metricKey' => $metricKey,
        'metricLabel' => $allowedMetrics[$metricKey],
        'seriesLabels' => $seriesLabels,
        'seriesValues' => $seriesValues,
        'currentValue' => $currentValue,
        'currentValueSuffix' => $currentValueSuffix,
        'chartLabel' => $chartLabel,
        'dashboardView' => $dashboardView,
        'weekOptions' => $weekOptions,
        'selectedWeekStart' => $selectedWeekStart,
        'backUrl' => $backUrl,
        'config' => $config,
    ]);
}

if ($page === 'comparison_detail') {
    $settings = challenge_settings($pdo, $config);
    if (!challenge_is_active($settings)) {
        flash_set('error', t('flash.challenge_inactive'));
        redirect('/?page=admin');
    }

    $team = default_team($pdo);
    $users = list_active_team_users($pdo, (int) $team['id']);
    if ($users === []) {
        $users = list_active_users($pdo);
    }
    $metricsByUser = compute_challenge_metrics(
        $pdo,
        $users,
        (string) $settings['challenge_start'],
        (string) $settings['challenge_end']
    );
    $metricsByUser = apply_strike_review_overrides_to_metrics($pdo, $metricsByUser);

    $metricsById = [];
    foreach ($metricsByUser as $userId => $metric) {
        $metricsById[(int) $userId] = $metric;
    }

    $selectedUserId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : (int) $currentUser['id'];
    if (!is_admin($currentUser) && $selectedUserId !== (int) $currentUser['id']) {
        $selectedUserId = (int) $currentUser['id'];
    }
    $selectedMetric = $metricsById[$selectedUserId] ?? null;
    if ($selectedMetric === null) {
        $selectedMetric = count($metricsByUser) > 0 ? array_values($metricsByUser)[0] : null;
    }
    if ($selectedMetric === null) {
        flash_set('error', t('flash.no_active_users'));
        redirect('/?page=dashboard');
    }

    $weekOptions = week_starts_from_metrics($selectedMetric);
    $defaultWeekStart = $weekOptions !== [] ? $weekOptions[count($weekOptions) - 1] : to_date(null);
    $normalizeView = static function (string $rawView, string $fallback): string {
        $normalizedDate = to_date($rawView, $fallback);
        try {
            return week_start_for(new DateTimeImmutable($normalizedDate))->format('Y-m-d');
        } catch (Throwable) {
            return $fallback;
        }
    };
    $dashboardView = (string) ($_GET['view'] ?? ($currentUser['dashboard_view'] ?? 'current_week'));
    if (!in_array($dashboardView, ['current_week', 'total'], true)) {
        $dashboardView = $normalizeView($dashboardView, $defaultWeekStart);
    }
    $selectedWeekStart = $dashboardView === 'current_week'
        ? $defaultWeekStart
        : ($dashboardView === 'total' ? $defaultWeekStart : $normalizeView($dashboardView, $defaultWeekStart));
    if (!in_array($selectedWeekStart, $weekOptions, true) && $weekOptions !== []) {
        $selectedWeekStart = $defaultWeekStart;
    }
    $effectiveView = $dashboardView === 'total' ? 'total' : $selectedWeekStart;
    $selectedSnapshot = metric_snapshot_for_view($selectedMetric, $effectiveView);
    $selectedBreakdown = score_breakdown_from_snapshot($selectedMetric, $selectedSnapshot);

    $compareMetric = null;
    foreach ($metricsByUser as $metric) {
        if ((int) ($metric['user']['id'] ?? 0) !== (int) ($selectedMetric['user']['id'] ?? 0)) {
            $compareMetric = $metric;
            break;
        }
    }
    $compareSnapshot = $compareMetric !== null ? metric_snapshot_for_view($compareMetric, $effectiveView) : null;
    $compareBreakdown = $compareMetric !== null && is_array($compareSnapshot)
        ? score_breakdown_from_snapshot($compareMetric, $compareSnapshot)
        : null;

    render_view('comparison_detail', [
        'title' => t('dashboard.comparison_detail_title'),
        'currentPage' => 'dashboard',
        'currentUser' => $currentUser,
        'users' => $users,
        'selectedMetric' => $selectedMetric,
        'selectedSnapshot' => $selectedSnapshot,
        'selectedBreakdown' => $selectedBreakdown,
        'compareMetric' => $compareMetric,
        'compareSnapshot' => $compareSnapshot,
        'compareBreakdown' => $compareBreakdown,
        'dashboardView' => $dashboardView,
        'weekOptions' => $weekOptions,
        'selectedWeekStart' => $selectedWeekStart,
        'backUrl' => '/?' . http_build_query([
            'page' => 'dashboard',
            'user_id' => (int) ($selectedMetric['user']['id'] ?? 0),
            'view' => $dashboardView,
        ]),
        'config' => $config,
    ]);
}

if ($page === 'strikes_detail') {
    if (!penalties_enabled($pdo)) {
        flash_set('error', t('metric.invalid'));
        redirect('/?page=dashboard');
    }

    $settings = challenge_settings($pdo, $config);
    if (!challenge_is_active($settings)) {
        flash_set('error', t('flash.challenge_inactive'));
        redirect('/?page=admin');
    }

    $team = default_team($pdo);
    $users = list_active_team_users($pdo, (int) $team['id']);
    if ($users === []) {
        $users = list_active_users($pdo);
    }
    $rawMetricsByUser = compute_challenge_metrics(
        $pdo,
        $users,
        (string) $settings['challenge_start'],
        (string) $settings['challenge_end']
    );
    $metricsByUser = apply_strike_review_overrides_to_metrics($pdo, $rawMetricsByUser);

    $metricsRawById = [];
    $metricsById = [];
    foreach ($rawMetricsByUser as $userId => $metric) {
        $metricsRawById[(int) $userId] = $metric;
    }
    foreach ($metricsByUser as $userId => $metric) {
        $metricsById[(int) $userId] = $metric;
    }

    $selectedUserId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : (int) $currentUser['id'];
    if (!is_admin($currentUser) && $selectedUserId !== (int) $currentUser['id']) {
        $selectedUserId = (int) $currentUser['id'];
    }

    $selectedRawMetric = $metricsRawById[$selectedUserId] ?? null;
    $selectedMetric = $metricsById[$selectedUserId] ?? null;
    if ($selectedRawMetric === null || $selectedMetric === null) {
        $fallbackRaw = count($rawMetricsByUser) > 0 ? array_values($rawMetricsByUser)[0] : null;
        $fallbackAdjusted = count($metricsByUser) > 0 ? array_values($metricsByUser)[0] : null;
        $selectedRawMetric = is_array($fallbackRaw) ? $fallbackRaw : null;
        $selectedMetric = is_array($fallbackAdjusted) ? $fallbackAdjusted : null;
    }
    if ($selectedRawMetric === null || $selectedMetric === null) {
        flash_set('error', t('flash.no_active_users'));
        redirect('/?page=dashboard');
    }

    $weekOptions = week_starts_from_metrics($selectedMetric);
    $defaultWeekStart = $weekOptions !== [] ? $weekOptions[count($weekOptions) - 1] : to_date(null);
    $normalizeView = static function (string $rawView, string $fallback): string {
        $normalizedDate = to_date($rawView, $fallback);
        try {
            return week_start_for(new DateTimeImmutable($normalizedDate))->format('Y-m-d');
        } catch (Throwable) {
            return $fallback;
        }
    };
    $dashboardView = (string) ($_GET['view'] ?? ($currentUser['dashboard_view'] ?? 'current_week'));
    if (!in_array($dashboardView, ['current_week', 'total'], true)) {
        $dashboardView = $normalizeView($dashboardView, $defaultWeekStart);
    }
    $selectedWeekStart = $dashboardView === 'current_week'
        ? $defaultWeekStart
        : ($dashboardView === 'total' ? $defaultWeekStart : $normalizeView($dashboardView, $defaultWeekStart));
    if (!in_array($selectedWeekStart, $weekOptions, true) && $weekOptions !== []) {
        $selectedWeekStart = $defaultWeekStart;
    }
    $effectiveView = $dashboardView === 'total' ? 'total' : $selectedWeekStart;

    if (is_post()) {
        if (!csrf_verify()) {
            flash_set('error', t('flash.csrf'));
            redirect('/?' . http_build_query([
                'page' => 'strikes_detail',
                'user_id' => (int) ($selectedMetric['user']['id'] ?? (int) $currentUser['id']),
                'view' => $dashboardView,
            ]));
        }

        $action = (string) ($_POST['action'] ?? '');
        $redirectUserId = (int) ($_POST['redirect_user_id'] ?? (int) ($selectedMetric['user']['id'] ?? (int) $currentUser['id']));
        $redirectView = (string) ($_POST['redirect_view'] ?? $dashboardView);
        $redirectQuery = [
            'page' => 'strikes_detail',
            'user_id' => $redirectUserId,
            'view' => $redirectView,
        ];

        if ($action === 'create_strike_review_request') {
            $targetUserId = (int) ($_POST['target_user_id'] ?? 0);
            if ($targetUserId !== (int) $currentUser['id']) {
                flash_set('error', t('flash.no_permission'));
                redirect('/?' . http_build_query($redirectQuery));
            }
            $result = create_strike_review_request(
                $pdo,
                $targetUserId,
                (string) ($_POST['week_start'] ?? ''),
                (string) ($_POST['event_date'] ?? ''),
                (string) ($_POST['reason'] ?? 'step_miss'),
                (string) ($_POST['request_comment'] ?? ''),
                (int) $currentUser['id']
            );
            flash_set(!empty($result['ok']) ? 'success' : 'error', (string) ($result['message'] ?? t('flash.save_failed')));
            redirect('/?' . http_build_query($redirectQuery));
        }

        if ($action === 'vote_strike_review_request') {
            $requestId = (int) ($_POST['request_id'] ?? 0);
            $decision = (string) ($_POST['decision'] ?? '');
            $result = vote_strike_review_request($pdo, $requestId, (int) $currentUser['id'], $decision);
            flash_set(!empty($result['ok']) ? 'success' : 'error', (string) ($result['message'] ?? t('flash.save_failed')));
            redirect('/?' . http_build_query($redirectQuery));
        }
    }

    // Refresh metrics after potential POST actions.
    $rawMetricsByUser = compute_challenge_metrics(
        $pdo,
        $users,
        (string) $settings['challenge_start'],
        (string) $settings['challenge_end']
    );
    $metricsByUser = apply_strike_review_overrides_to_metrics($pdo, $rawMetricsByUser);
    $metricsRawById = [];
    $metricsById = [];
    foreach ($rawMetricsByUser as $userId => $metric) {
        $metricsRawById[(int) $userId] = $metric;
    }
    foreach ($metricsByUser as $userId => $metric) {
        $metricsById[(int) $userId] = $metric;
    }
    $selectedRawMetric = $metricsRawById[$selectedUserId] ?? (count($rawMetricsByUser) > 0 ? array_values($rawMetricsByUser)[0] : null);
    $selectedMetric = $metricsById[$selectedUserId] ?? (count($metricsByUser) > 0 ? array_values($metricsByUser)[0] : null);
    if (!is_array($selectedRawMetric) || !is_array($selectedMetric)) {
        flash_set('error', t('flash.no_active_users'));
        redirect('/?page=dashboard');
    }

    $snapshot = metric_snapshot_for_view($selectedMetric, $effectiveView);
    $rows = build_strike_detail_rows_for_view($pdo, $selectedRawMetric, $selectedMetric, $effectiveView);
    $pendingRows = db_fetch_all(
        $pdo,
        'SELECT r.*, requester.display_name AS requested_by_name, target.display_name AS target_name
         FROM strike_review_requests r
         LEFT JOIN users requester ON requester.id = r.requested_by
         LEFT JOIN users target ON target.id = r.target_user_id
         WHERE r.status = "pending"
         ORDER BY r.updated_at DESC
         LIMIT 200'
    );
    $pendingVotes = [];
    foreach ($pendingRows as $pendingRow) {
        $eligible = decode_int_list_json((string) ($pendingRow['eligible_voters_json'] ?? '[]'));
        if (in_array((int) $currentUser['id'], $eligible, true)) {
            $pendingVotes[] = $pendingRow;
        }
    }

    render_view('strikes_detail', [
        'title' => t('strikes.detail_title'),
        'currentPage' => 'dashboard',
        'currentUser' => $currentUser,
        'users' => $users,
        'selectedMetric' => $selectedMetric,
        'selectedSnapshot' => $snapshot,
        'dashboardView' => $dashboardView,
        'weekOptions' => $weekOptions,
        'selectedWeekStart' => $selectedWeekStart,
        'strikeRows' => $rows,
        'pendingStrikeVotes' => $pendingVotes,
        'backUrl' => '/?' . http_build_query([
            'page' => 'dashboard',
            'user_id' => (int) ($selectedMetric['user']['id'] ?? 0),
            'view' => $dashboardView,
        ]),
        'config' => $config,
    ]);
}

if ($page === 'penalties') {
    if (!penalties_enabled($pdo)) {
        flash_set('error', t('metric.invalid'));
        redirect('/?page=dashboard');
    }

    $settings = challenge_settings($pdo, $config);
    if (!challenge_is_active($settings)) {
        flash_set('error', t('flash.challenge_inactive'));
        redirect('/?page=admin');
    }

    $team = default_team($pdo);
    $users = list_active_team_users($pdo, (int) $team['id']);
    if ($users === []) {
        $users = list_active_users($pdo);
    }

    $metricsByUser = compute_challenge_metrics(
        $pdo,
        $users,
        (string) $settings['challenge_start'],
        (string) $settings['challenge_end']
    );
    $metricsByUser = apply_strike_review_overrides_to_metrics($pdo, $metricsByUser);

    $metricsById = [];
    foreach ($metricsByUser as $userId => $metric) {
        $metricsById[(int) $userId] = $metric;
    }

    $selectedUserId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : (int) $currentUser['id'];
    if (!is_admin($currentUser) && $selectedUserId !== (int) $currentUser['id']) {
        $selectedUserId = (int) $currentUser['id'];
    }

    $selectedMetric = $metricsById[$selectedUserId] ?? null;
    if ($selectedMetric === null) {
        $selectedMetric = count($metricsByUser) > 0 ? array_values($metricsByUser)[0] : null;
    }
    if ($selectedMetric === null) {
        flash_set('error', t('flash.no_active_users'));
        redirect('/?page=dashboard');
    }

    $weeklyRows = array_values((array) ($selectedMetric['weekly'] ?? []));
    usort(
        $weeklyRows,
        static fn(array $left, array $right): int => strcmp((string) ($left['week_start'] ?? ''), (string) ($right['week_start'] ?? ''))
    );
    $weekOptionsMap = [];
    foreach ($weeklyRows as $weekRow) {
        $weekStart = (string) ($weekRow['week_start'] ?? '');
        if ($weekStart !== '') {
            $weekOptionsMap[$weekStart] = true;
        }
    }
    $weekOptions = array_keys($weekOptionsMap);
    sort($weekOptions);
    $defaultWeekStart = $weekOptions !== [] ? $weekOptions[count($weekOptions) - 1] : to_date(null);
    $normalizeDashboardWeekView = static function (string $rawView, string $fallback): string {
        $normalizedDate = to_date($rawView, $fallback);
        try {
            return week_start_for(new DateTimeImmutable($normalizedDate))->format('Y-m-d');
        } catch (Throwable) {
            return $fallback;
        }
    };

    $dashboardView = (string) ($_GET['view'] ?? ($currentUser['dashboard_view'] ?? 'current_week'));
    if (!in_array($dashboardView, ['current_week', 'total'], true)) {
        $dashboardView = $normalizeDashboardWeekView($dashboardView, $defaultWeekStart);
    }

    $selectedWeekStart = $defaultWeekStart;
    if ($dashboardView !== 'total') {
        $selectedWeekStart = $dashboardView === 'current_week'
            ? $defaultWeekStart
            : $normalizeDashboardWeekView($dashboardView, $defaultWeekStart);
        if (!in_array($selectedWeekStart, $weekOptions, true) && $weekOptions !== []) {
            $selectedWeekStart = $defaultWeekStart;
        }
    }

    $selectedWeeklyRows = [];
    if ($dashboardView === 'total') {
        $selectedWeeklyRows = $weeklyRows;
    } else {
        foreach ($weeklyRows as $weekRow) {
            if ((string) ($weekRow['week_start'] ?? '') === $selectedWeekStart) {
                $selectedWeeklyRows[] = $weekRow;
            }
        }
    }
    if ($selectedWeeklyRows === [] && $weeklyRows !== []) {
        $selectedWeeklyRows = [$weeklyRows[count($weeklyRows) - 1]];
    }

    $penaltyRows = [];
    $rangeSummary = [
        'penalty_total' => 0,
        'step_failures' => 0,
        'workout_failures' => 0,
        'warnings' => 0,
        'strike_reduction' => 0,
        'total_failures' => 0,
        'net_strikes' => 0,
    ];
    foreach ($selectedWeeklyRows as $weekRow) {
        $stepFailures = (int) ($weekRow['step_failures'] ?? 0);
        $workoutFailures = (int) ($weekRow['workout_failures'] ?? 0);
        $totalFailures = (int) ($weekRow['total_failures'] ?? ($stepFailures + $workoutFailures));
        $strikeReduction = (int) ($weekRow['strike_reduction'] ?? 0);
        $warnings = (int) ($weekRow['skip_warnings'] ?? 0);
        $penalty = (int) ($weekRow['penalty'] ?? 0);
        $netStrikes = $totalFailures - $strikeReduction;
        $rangeSummary['penalty_total'] += $penalty;
        $rangeSummary['step_failures'] += $stepFailures;
        $rangeSummary['workout_failures'] += $workoutFailures;
        $rangeSummary['warnings'] += $warnings;
        $rangeSummary['strike_reduction'] += $strikeReduction;
        $rangeSummary['total_failures'] += $totalFailures;
        $rangeSummary['net_strikes'] += $netStrikes;
        $penaltyRows[] = [
            'user_id' => (int) ($selectedMetric['user']['id'] ?? 0),
            'week_start' => (string) ($weekRow['week_start'] ?? ''),
            'week_end' => (string) ($weekRow['week_end'] ?? ''),
            'status' => (string) ($weekRow['status'] ?? ''),
            'penalty' => $penalty,
            'step_failures' => $stepFailures,
            'workout_failures' => $workoutFailures,
            'warnings' => $warnings,
            'strike_reduction' => $strikeReduction,
            'total_failures' => $totalFailures,
            'net_strikes' => $netStrikes,
            'strikes_after_week' => (int) ($weekRow['strikes_after_week'] ?? 0),
        ];
    }

    $backUrl = '/?' . http_build_query([
        'page' => 'dashboard',
        'user_id' => (int) ($selectedMetric['user']['id'] ?? 0),
        'view' => $dashboardView,
    ]);

    render_view('penalties', [
        'title' => t('penalties.title'),
        'currentPage' => 'dashboard',
        'currentUser' => $currentUser,
        'users' => $users,
        'selectedMetric' => $selectedMetric,
        'dashboardView' => $dashboardView,
        'weekOptions' => $weekOptions,
        'selectedWeekStart' => $selectedWeekStart,
        'penaltyRows' => $penaltyRows,
        'penaltiesSummary' => $rangeSummary,
        'backUrl' => $backUrl,
        'config' => $config,
    ]);
}


if ($page === 'analytics') {
    $settings = challenge_settings($pdo, $config);
    if (!challenge_is_active($settings)) {
        flash_set('error', t('flash.challenge_inactive'));
        redirect('/?page=admin');
    }

    $team = default_team($pdo);
    $users = list_active_team_users($pdo, (int) $team['id']);
    if ($users === []) {
        $users = list_active_users($pdo);
    }

    $metricsByUser = compute_challenge_metrics(
        $pdo,
        $users,
        (string) $settings['challenge_start'],
        (string) $settings['challenge_end']
    );
    $metricsByUser = apply_strike_review_overrides_to_metrics($pdo, $metricsByUser);

    $metricsById = [];
    foreach ($metricsByUser as $userId => $metric) {
        $metricsById[(int) $userId] = $metric;
    }

    $selectedUserId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : (int) $currentUser['id'];
    if (!is_admin($currentUser) && $selectedUserId !== (int) $currentUser['id']) {
        $selectedUserId = (int) $currentUser['id'];
    }

    $selectedMetric = $metricsById[$selectedUserId] ?? null;
    if ($selectedMetric === null) {
        $selectedMetric = count($metricsByUser) > 0 ? array_values($metricsByUser)[0] : null;
    }

    if ($selectedMetric === null) {
        flash_set('error', t('flash.no_active_users'));
        redirect('/?page=admin');
    }

    $weekOptions = week_starts_from_metrics($selectedMetric);
    $defaultWeekStart = $weekOptions !== [] ? $weekOptions[count($weekOptions) - 1] : to_date(null);
    $normalizeAnalyticsWeek = static function (string $rawView, string $fallback): string {
        $normalizedDate = to_date($rawView, $fallback);
        try {
            return week_start_for(new DateTimeImmutable($normalizedDate))->format('Y-m-d');
        } catch (Throwable) {
            return $fallback;
        }
    };

    $selectedWeekStart = $normalizeAnalyticsWeek((string) ($_GET['analytics_week'] ?? $defaultWeekStart), $defaultWeekStart);
    if (!in_array($selectedWeekStart, $weekOptions, true) && $weekOptions !== []) {
        $selectedWeekStart = $defaultWeekStart;
    }

    $challengeStartObj = new DateTimeImmutable((string) $settings['challenge_start']);
    $challengeConfiguredEnd = new DateTimeImmutable((string) $settings['challenge_end']);
    $todayObj = new DateTimeImmutable('today');
    $challengeEndObj = $challengeConfiguredEnd > $todayObj ? $todayObj : $challengeConfiguredEnd;
    if ($challengeEndObj < $challengeStartObj) {
        $challengeEndObj = $challengeStartObj;
    }

    $analyticsPeriod = (string) ($_GET['analytics_period'] ?? 'current_week');
    if (!in_array($analyticsPeriod, ['current_week', 'week', 'month', 'total'], true)) {
        $analyticsPeriod = 'current_week';
    }
    $analyticsWeek = $normalizeAnalyticsWeek((string) ($_GET['analytics_week'] ?? $selectedWeekStart), $selectedWeekStart);
    if (!in_array($analyticsWeek, $weekOptions, true) && $weekOptions !== []) {
        $analyticsWeek = $selectedWeekStart;
    }
    $analyticsMonth = (string) ($_GET['analytics_month'] ?? substr($selectedWeekStart, 0, 7));
    if (!preg_match('/^\d{4}-\d{2}$/', $analyticsMonth)) {
        $analyticsMonth = substr($selectedWeekStart, 0, 7);
    }

    try {
        if ($analyticsPeriod === 'total') {
            $analyticsStartObj = $challengeStartObj;
            $analyticsEndObj = $challengeEndObj;
        } elseif ($analyticsPeriod === 'month') {
            $analyticsStartObj = new DateTimeImmutable($analyticsMonth . '-01');
            $analyticsEndObj = $analyticsStartObj->modify('last day of this month');
        } else {
            $analyticsBaseWeek = $analyticsPeriod === 'week' ? $analyticsWeek : $defaultWeekStart;
            $analyticsStartObj = new DateTimeImmutable($analyticsBaseWeek);
            $analyticsEndObj = $analyticsStartObj->modify('+6 days');
        }
    } catch (Throwable) {
        $analyticsStartObj = new DateTimeImmutable($defaultWeekStart);
        $analyticsEndObj = $analyticsStartObj->modify('+6 days');
        $analyticsPeriod = 'current_week';
    }
    if ($analyticsStartObj < $challengeStartObj) {
        $analyticsStartObj = $challengeStartObj;
    }
    if ($analyticsEndObj > $challengeEndObj) {
        $analyticsEndObj = $challengeEndObj;
    }
    if ($analyticsEndObj < $analyticsStartObj) {
        $analyticsEndObj = $analyticsStartObj;
    }
    $analyticsStartDate = $analyticsStartObj->format('Y-m-d');
    $analyticsEndDate = $analyticsEndObj->format('Y-m-d');

    $analyticsSnapshotForRange = static function (array $metric, string $startDate, string $endDate): array {
        $weeklyRows = array_values((array) ($metric['weekly'] ?? []));
        $weightProgress = null;
        if (array_key_exists('weight_progress_pct', $metric) && $metric['weight_progress_pct'] !== null && is_numeric($metric['weight_progress_pct'])) {
            $weightProgress = (float) $metric['weight_progress_pct'];
        }
        $rangeRows = array_values(array_filter(
            $weeklyRows,
            static function (array $row) use ($startDate, $endDate): bool {
                $weekStart = (string) ($row['week_start'] ?? '');
                $weekEnd = (string) ($row['week_end'] ?? $weekStart);
                return $weekStart !== '' && $weekStart <= $endDate && $weekEnd >= $startDate;
            }
        ));
        if ($rangeRows === []) {
            return [
                'steps' => 0,
                'distance_km' => 0.0,
                'workouts' => 0,
                'workout_target' => 0,
                'score' => 0.0,
                'strikes' => 0,
                'penalty' => 0.0,
                'weight_progress' => $weightProgress,
                'step_completion_pct' => 0.0,
                'workout_completion_pct' => 0.0,
                'discipline_score' => 100.0,
                'score_components' => score_components_from_progress(0.0, 0.0, 100.0, $weightProgress),
            ];
        }

        $steps = 0;
        $distance = 0.0;
        $workouts = 0;
        $workoutTarget = 0;
        $stepRequired = 0;
        $stepSuccess = 0;
        $strikes = 0;
        $warnings = 0;
        $penalty = 0.0;
        foreach ($rangeRows as $row) {
            $steps += (int) ($row['steps'] ?? 0);
            $distance += (float) ($row['km'] ?? 0);
            $workoutTarget += max(0, (int) ($row['workout_target_week'] ?? 0));
            $workouts += max(
                max(0, (int) ($row['workouts'] ?? 0)),
                array_key_exists('workout_success_week', $row)
                    ? max(0, (int) ($row['workout_success_week'] ?? 0))
                    : max(0, (int) ($row['workout_target_week'] ?? 0) - (int) ($row['workout_failures'] ?? 0))
            );
            $weekStepRequired = max(
                0,
                (int) ($row['step_days_required_week'] ?? ((int) ($row['step_days_success_week'] ?? 0) + (int) ($row['step_failures'] ?? 0)))
            );
            $stepRequired += $weekStepRequired;
            $stepSuccess += max(
                0,
                min($weekStepRequired, (int) ($row['step_days_success_week'] ?? ($weekStepRequired - (int) ($row['step_failures'] ?? 0))))
            );
            $strikes += max(
                0,
                (int) ($row['total_failures'] ?? ((int) ($row['step_failures'] ?? 0) + (int) ($row['workout_failures'] ?? 0)))
                - (int) ($row['strike_reduction'] ?? 0)
            );
            $warnings += max(0, (int) ($row['skip_warnings'] ?? 0));
            $penalty += max(0.0, (float) ($row['penalty'] ?? 0));
        }

        $stepCompletionPct = $stepRequired > 0 ? round(($stepSuccess / $stepRequired) * 100, 1) : 0.0;
        $workoutCompletionPct = $workoutTarget > 0 ? round(($workouts / $workoutTarget) * 100, 1) : 0.0;
        $disciplineScore = max(0.0, 100.0 - min(100.0, ($strikes * 10) + ($warnings * 3)));
        $scoreComponents = score_components_from_progress($stepCompletionPct, $workoutCompletionPct, $disciplineScore, $weightProgress);

        return [
            'steps' => $steps,
            'distance_km' => round($distance, 2),
            'workouts' => $workouts,
            'workout_target' => $workoutTarget,
            'score' => score_value_from_components($scoreComponents),
            'strikes' => $strikes,
            'penalty' => round($penalty, 2),
            'weight_progress' => $weightProgress,
            'step_completion_pct' => $stepCompletionPct,
            'workout_completion_pct' => $workoutCompletionPct,
            'discipline_score' => round($disciplineScore, 1),
            'score_components' => $scoreComponents,
        ];
    };

    $selectedMetricSnapshot = $analyticsSnapshotForRange($selectedMetric, $analyticsStartDate, $analyticsEndDate);
    $compareMetric = null;
    foreach ($metricsByUser as $metric) {
        if ((int) $metric['user']['id'] !== (int) $selectedMetric['user']['id']) {
            $compareMetric = $metric;
            break;
        }
    }
    $compareMetricSnapshot = $compareMetric !== null ? $analyticsSnapshotForRange($compareMetric, $analyticsStartDate, $analyticsEndDate) : null;

    $distanceByDate = fetch_distance_totals_by_date_for_user_between(
        $pdo,
        (int) ($selectedMetric['user']['id'] ?? 0),
        (string) $settings['challenge_start'],
        (string) $settings['challenge_end']
    );
    $maintenanceCalories = ($selectedMetric['user']['maintenance_calories'] ?? null) !== null
        ? (float) $selectedMetric['user']['maintenance_calories']
        : null;
    $dashboardCalorieStats = fetch_user_calorie_stats(
        $pdo,
        (int) ($selectedMetric['user']['id'] ?? 0),
        $analyticsStartDate,
        $analyticsEndDate,
        $maintenanceCalories
    );
    $analyticsFoodStats = fetch_user_food_stats(
        $pdo,
        (int) ($selectedMetric['user']['id'] ?? 0),
        $analyticsStartDate,
        $analyticsEndDate
    );

    render_view('analytics', [
        'title' => t('nav.analytics'),
        'currentPage' => 'analytics',
        'currentUser' => $currentUser,
        'settings' => $settings,
        'users' => $users,
        'selectedMetric' => $selectedMetric,
        'selectedMetricSnapshot' => $selectedMetricSnapshot,
        'compareMetric' => $compareMetric,
        'compareMetricSnapshot' => $compareMetricSnapshot,
        'metricsOrdered' => array_values($metricsByUser),
        'selectedWeekStart' => $selectedWeekStart,
        'dashboardView' => $analyticsPeriod === 'total' ? 'total' : $selectedWeekStart,
        'weekOptions' => $weekOptions,
        'dashboardAnalyticsPeriod' => $analyticsPeriod,
        'dashboardAnalyticsWeek' => $analyticsWeek,
        'dashboardAnalyticsMonth' => $analyticsMonth,
        'dashboardAnalyticsRangeStart' => $analyticsStartDate,
        'dashboardAnalyticsRangeEnd' => $analyticsEndDate,
        'dashboardDistanceByDate' => $distanceByDate,
        'dashboardCalorieStats' => $dashboardCalorieStats,
        'dashboardCalorieRangeStart' => $analyticsStartDate,
        'dashboardCalorieRangeEnd' => $analyticsEndDate,
        'analyticsFoodStats' => $analyticsFoodStats,
        'config' => $config,
    ]);
}

if ($page === 'dashboard') {
    if (is_post()) {
        if (!csrf_verify()) {
            flash_set('error', t('flash.csrf'));
            redirect('/?page=dashboard');
        }

        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'resolve_approval') {
            $approvalId = (int) ($_POST['approval_id'] ?? 0);
            $decision = (string) ($_POST['decision'] ?? '');
            $note = trim((string) ($_POST['decision_note'] ?? ''));

            $before = db_fetch_one($pdo, 'SELECT * FROM approval_requests WHERE id = :id', [':id' => $approvalId]);
            $result = resolve_approval_request($pdo, $currentUser, $approvalId, $decision, $note);
            $after = db_fetch_one($pdo, 'SELECT * FROM approval_requests WHERE id = :id', [':id' => $approvalId]);
            if ($result['ok']) {
                audit_log(
                    $pdo,
                    (int) $currentUser['id'],
                    'approval_' . (string) ($after['status'] ?? 'updated'),
                    'approval_request',
                    (string) $approvalId,
                    'Approval request resolved.',
                    audit_snapshot($before),
                    audit_snapshot($after)
                );
            }
            flash_set($result['ok'] ? 'success' : 'error', $result['message']);

            $query = [
                'page' => 'dashboard',
            ];
            if (!empty($_POST['redirect_user_id'])) {
                $query['user_id'] = (int) $_POST['redirect_user_id'];
            }
            if (!empty($_POST['redirect_week_start'])) {
                $query['week_start'] = (string) $_POST['redirect_week_start'];
            }

            redirect('/?' . http_build_query($query));
        }

        if ($action === 'save_dashboard_layout' || $action === 'save_dashboard_prefs') {
            $allowedWidgets = ['kpis', 'approvals', 'ranking', 'weekly', 'achievements'];
            $resetLayout = bool_from_form('reset_dashboard_layout') === 1;
            $widgets = [];
            if (!$resetLayout) {
                $widgets = array_values(array_intersect(array_map('strval', (array) ($_POST['dashboard_widgets'] ?? [])), $allowedWidgets));
                $widgets = array_values(array_unique(array_map(
                    static fn(string $widget): string => $widget === 'money' ? 'distance_walked' : $widget,
                    $widgets
                )));
                $widgetOrder = (array) ($_POST['dashboard_order'] ?? []);
                usort($widgets, static function (string $left, string $right) use ($widgetOrder, $allowedWidgets): int {
                    $leftOrder = isset($widgetOrder[$left]) ? (int) $widgetOrder[$left] : (int) array_search($left, $allowedWidgets, true);
                    $rightOrder = isset($widgetOrder[$right]) ? (int) $widgetOrder[$right] : (int) array_search($right, $allowedWidgets, true);
                    return $leftOrder <=> $rightOrder;
                });
            }
            db_execute(
                $pdo,
                'UPDATE users SET dashboard_view = :dashboard_view, dashboard_layout_json = :layout, updated_at = :updated_at WHERE id = :id',
                [
                    ':dashboard_view' => (string) ($_POST['dashboard_view'] ?? 'current_week'),
                    ':layout' => $resetLayout ? '[]' : json_encode($widgets, JSON_UNESCAPED_SLASHES),
                    ':updated_at' => now_iso(),
                    ':id' => (int) $currentUser['id'],
                ]
            );
            audit_log($pdo, (int) $currentUser['id'], 'dashboard_preferences_updated', 'user', (string) $currentUser['id'], 'Dashboard preferences updated.', null, ['dashboard_view' => $_POST['dashboard_view'] ?? 'current_week', 'widgets' => $widgets, 'reset' => $resetLayout]);
            flash_set('success', t('flash.preferences_updated'));
            $dashboardRedirectParams = [
                'page' => 'dashboard',
                'view' => (string) ($_POST['dashboard_view'] ?? 'current_week'),
            ];
            if (!empty($_POST['redirect_user_id'])) {
                $dashboardRedirectParams['user_id'] = (int) $_POST['redirect_user_id'];
            }
            redirect('/?' . http_build_query($dashboardRedirectParams));
        }
    }

    $dashboardRequestStartedAt = microtime(true);
    $dashboardTimings = [];
    $captureDashboardTiming = static function (string $name, float $startedAt) use (&$dashboardTimings): void {
        $dashboardTimings[$name] = max(0.0, (microtime(true) - $startedAt) * 1000);
    };

    $settings = challenge_settings($pdo, $config);
    if (!challenge_is_active($settings)) {
        flash_set('error', t('flash.challenge_inactive'));
        redirect('/?page=admin');
    }
    $team = default_team($pdo);
    $users = list_active_team_users($pdo, (int) $team['id']);
    if ($users === []) {
        $users = list_active_users($pdo);
    }
    $metricsStartedAt = microtime(true);
    $dashboardMetricCacheKey = 'dashboard_metrics:' . hash('sha256', json_encode([
        'users' => array_map(static fn(array $user): int => (int) ($user['id'] ?? 0), $users),
        'start' => (string) $settings['challenge_start'],
        'end' => (string) $settings['challenge_end'],
    ], JSON_UNESCAPED_SLASHES) ?: '');
    $cachedMetrics = app_cache_get($dashboardMetricCacheKey, 300);
    if (is_array($cachedMetrics)) {
        $metricsByUser = $cachedMetrics;
    } else {
        $metricsByUser = compute_challenge_metrics(
            $pdo,
            $users,
            (string) $settings['challenge_start'],
            (string) $settings['challenge_end']
        );
        $metricsByUser = apply_strike_review_overrides_to_metrics($pdo, $metricsByUser);
        app_cache_set($dashboardMetricCacheKey, $metricsByUser);
    }
    $captureDashboardTiming('metrics', $metricsStartedAt);

    $metricsById = [];
    foreach ($metricsByUser as $userId => $metric) {
        $metricsById[(int) $userId] = $metric;
    }

    $selectedUserId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : (int) $currentUser['id'];
    if (!is_admin($currentUser) && $selectedUserId !== (int) $currentUser['id']) {
        $selectedUserId = (int) $currentUser['id'];
    }

    $selectedMetric = $metricsById[$selectedUserId] ?? null;
    if ($selectedMetric === null) {
        $selectedMetric = count($metricsByUser) > 0 ? array_values($metricsByUser)[0] : null;
    }

    if ($selectedMetric === null) {
        flash_set('error', t('flash.no_active_users'));
        redirect('/?page=admin');
    }

    $weekOptions = week_starts_from_metrics($selectedMetric);
    $defaultWeekStart = $weekOptions !== [] ? $weekOptions[count($weekOptions) - 1] : to_date(null);
    $normalizeDashboardWeekView = static function (string $rawView, string $fallback): string {
        $normalizedDate = to_date($rawView, $fallback);
        try {
            return week_start_for(new DateTimeImmutable($normalizedDate))->format('Y-m-d');
        } catch (Throwable) {
            return $fallback;
        }
    };
    $dashboardView = (string) ($_GET['view'] ?? ($currentUser['dashboard_view'] ?? 'current_week'));
    if (!in_array($dashboardView, ['current_week', 'total'], true)) {
        $dashboardView = $normalizeDashboardWeekView($dashboardView, $defaultWeekStart);
    }
    $selectedWeekStart = $dashboardView === 'current_week'
        ? $defaultWeekStart
        : ($dashboardView === 'total' ? $defaultWeekStart : $normalizeDashboardWeekView($dashboardView, $defaultWeekStart));

    if (!in_array($selectedWeekStart, $weekOptions, true) && $weekOptions !== []) {
        $selectedWeekStart = $defaultWeekStart;
    }
    $dashboardMetricView = $dashboardView === 'total' ? 'total' : $selectedWeekStart;
    $challengeStartObj = new DateTimeImmutable((string) $settings['challenge_start']);
    $challengeConfiguredEnd = new DateTimeImmutable((string) $settings['challenge_end']);
    $todayObj = new DateTimeImmutable('today');
    $challengeEndObj = $challengeConfiguredEnd > $todayObj ? $todayObj : $challengeConfiguredEnd;
    if ($challengeEndObj < $challengeStartObj) {
        $challengeEndObj = $challengeStartObj;
    }

    $selectedMetricSnapshot = metric_snapshot_for_view($selectedMetric, $dashboardMetricView);
    $snapshotWorkoutTarget = max(0, (int) ($selectedMetricSnapshot['workout_target'] ?? 0));
    $snapshotWorkoutSuccess = max(0, (int) ($selectedMetricSnapshot['workouts'] ?? 0));
    $selectedMetricSnapshot['workout_completion_pct'] = $snapshotWorkoutTarget > 0
        ? round(($snapshotWorkoutSuccess / $snapshotWorkoutTarget) * 100, 1)
        : 0.0;

    $compareMetric = null;
    foreach ($metricsByUser as $metric) {
        if ((int) $metric['user']['id'] !== (int) $selectedMetric['user']['id']) {
            $compareMetric = $metric;
            break;
        }
    }
    $compareMetricSnapshot = $compareMetric !== null ? metric_snapshot_for_view($compareMetric, $dashboardMetricView) : null;

    $dashboardOverviewStartedAt = microtime(true);
    $settlementSummary = weekly_settlement_summary(array_values($metricsByUser), $selectedWeekStart);
    $pendingApprovals = fetch_pending_approvals($pdo, $currentUser, null, 80);
    $captureDashboardTiming('overview', $dashboardOverviewStartedAt);

    if ($dashboardView === 'total') {
        $calorieStartDate = $challengeStartObj->format('Y-m-d');
        $calorieEndDate = $challengeEndObj->format('Y-m-d');
    } else {
        $calorieStartObj = new DateTimeImmutable($selectedWeekStart);
        $calorieEndObj = $calorieStartObj->modify('+6 days');
        if ($calorieStartObj < $challengeStartObj) {
            $calorieStartObj = $challengeStartObj;
        }
        if ($calorieEndObj > $challengeEndObj) {
            $calorieEndObj = $challengeEndObj;
        }
        if ($calorieEndObj < $calorieStartObj) {
            $calorieEndObj = $calorieStartObj;
        }
        $calorieStartDate = $calorieStartObj->format('Y-m-d');
        $calorieEndDate = $calorieEndObj->format('Y-m-d');
    }
    $dashboardDetailsStartedAt = microtime(true);
    $maintenanceCalories = ($selectedMetric['user']['maintenance_calories'] ?? null) !== null
        ? (float) $selectedMetric['user']['maintenance_calories']
        : null;
    $dashboardCalorieStats = fetch_user_calorie_stats(
        $pdo,
        (int) ($selectedMetric['user']['id'] ?? 0),
        $calorieStartDate,
        $calorieEndDate,
        $maintenanceCalories
    );
    $dashboardAchievementUserId = (int) ($selectedMetric['user']['id'] ?? $selectedUserId);
    $dashboardAchievements = list_achievement_collection(
        $pdo,
        'user',
        $dashboardAchievementUserId,
        null,
        [$dashboardAchievementUserId => $selectedMetric]
    );
    $captureDashboardTiming('detail_widgets', $dashboardDetailsStartedAt);
    if ($dashboardView !== (string) ($currentUser['dashboard_view'] ?? 'current_week')) {
        db_execute($pdo, 'UPDATE users SET dashboard_view = :view, updated_at = :updated_at WHERE id = :id', [':view' => $dashboardView, ':updated_at' => now_iso(), ':id' => (int) $currentUser['id']]);
        $currentUser['dashboard_view'] = $dashboardView;
    }
    $dashboardServerTimingParts = [];
    foreach ($dashboardTimings as $metricName => $durationMs) {
        $safeName = strtolower((string) preg_replace('/[^a-z0-9_]+/', '_', (string) $metricName));
        if ($safeName === '') {
            continue;
        }
        $dashboardServerTimingParts[] = $safeName . ';dur=' . number_format($durationMs, 2, '.', '');
    }
    $dashboardTotalMs = max(0.0, (microtime(true) - $dashboardRequestStartedAt) * 1000);
    $dbProfile = function_exists('db_profile_snapshot') ? db_profile_snapshot() : [];
    if ($dbProfile !== []) {
        $dashboardServerTimingParts[] = 'db;dur=' . number_format((float) ($dbProfile['query_time_ms'] ?? 0), 2, '.', '');
        $dashboardServerTimingParts[] = 'db_queries;desc="' . max(0, (int) ($dbProfile['query_count'] ?? 0)) . '"';
    }
    $dashboardServerTimingParts[] = 'total;dur=' . number_format($dashboardTotalMs, 2, '.', '');
    header('Server-Timing: ' . implode(', ', $dashboardServerTimingParts));
    if (function_exists('db_profile_enabled') && db_profile_enabled()) {
        error_log('[dashboard-profile] ' . json_encode([
            'total_ms' => round($dashboardTotalMs, 2),
            'timings_ms' => $dashboardTimings,
            'db' => $dbProfile,
        ], JSON_UNESCAPED_SLASHES));
    }

    render_view('dashboard', [
        'title' => t('nav.dashboard'),
        'currentPage' => 'dashboard',
        'currentUser' => $currentUser,
        'settings' => $settings,
        'users' => $users,
        'selectedMetric' => $selectedMetric,
        'selectedMetricSnapshot' => $selectedMetricSnapshot,
        'compareMetric' => $compareMetric,
        'compareMetricSnapshot' => $compareMetricSnapshot,
        'metricsOrdered' => array_values($metricsByUser),
        'selectedWeekStart' => $selectedWeekStart,
        'dashboardView' => $dashboardView,
        'weekOptions' => $weekOptions,
        'settlementSummary' => $settlementSummary,
        'pendingApprovals' => $pendingApprovals,
        'dashboardCalorieStats' => $dashboardCalorieStats,
        'dashboardCalorieRangeStart' => $calorieStartDate,
        'dashboardCalorieRangeEnd' => $calorieEndDate,
        'dashboardAchievements' => $dashboardAchievements,
        'motivationQuote' => random_motivation_quote_from_db($pdo),
        'config' => $config,
    ]);
}

http_response_code(404);
echo e(t('flash.not_found'));
