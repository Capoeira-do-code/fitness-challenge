<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

security_apply_response_headers($config);

$page = $_GET['page'] ?? null;
if ($page === null) {
    $pathPage = trim(security_request_path(), '/');
    if ($pathPage === 'index.php') {
        $pathPage = '';
    }
    if (in_array($pathPage, ['dashboard', 'overview', 'search', 'dashboard_panel_state', 'analytics', 'entries', 'gallery', 'table', 'week_editor', 'workouts', 'ranks', 'social', 'profile', 'settings', 'team', 'team_settings', 'admin', 'metric', 'quests', 'season', 'penalties', 'comparison_detail', 'strikes_detail', 'notifications', 'challenges', 'friends', 'duels', 'competitions', 'meal', 'login', 'register', 'onboarding', 'login_background'], true)) {
        $page = $pathPage;
    } elseif ($pathPage !== '') {
        security_mark_current_request($pdo, 'not_found', 10);
        security_reject_request(404, 'Not Found');
    }
}
$currentUser = current_user($pdo);
security_apply_response_headers($config, $currentUser !== null);
header('X-Fitness-User-Id: ' . (string) ((int) ($currentUser['id'] ?? 0)));
set_current_locale(resolve_locale($config, $currentUser));
$setupRequired = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0;
if ($setupRequired && !in_array($page, ['setup', 'manifest', 'app_icon_default'], true)) {
    $page = 'setup';
}
if (!$setupRequired && $page === 'setup') {
    redirect($currentUser !== null ? '/?page=dashboard' : '/?page=login');
}
if ($currentUser !== null && (string) ($currentUser['role'] ?? '') === 'admin') {
    remember_detected_app_base_url($pdo, (int) ($currentUser['id'] ?? 0));
}

if ($page === null || $page === '') {
    $page = $currentUser !== null ? 'dashboard' : 'login';
}

if ($currentUser !== null && is_post() && (string) ($_SERVER['HTTP_X_OFFLINE_REPLAY'] ?? '') === '1') {
    $offlineKey = trim((string) ($_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? ''));
    $offlineCsrf = (string) ($_POST['csrf_token'] ?? '');
    if (
        $offlineKey !== '' && strlen($offlineKey) <= 160 && $offlineCsrf !== ''
        && hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $offlineCsrf)
    ) {
        $inserted = db_execute($pdo, 'INSERT OR IGNORE INTO sync_mutations
            (user_id, idempotency_key, resource_type, resource_key, response_json, created_at)
            VALUES (:user,:key,"form_replay",:resource,:response,:now)', [
            ':user' => (int) $currentUser['id'],
            ':key' => $offlineKey,
            ':resource' => (string) $page,
            ':response' => '{"ok":true,"replayed":true}',
            ':now' => now_iso(),
        ]);
        if ($inserted === 0) {
            http_response_code(204);
            exit;
        }
    }
}

if ($page === 'users') {
    $page = 'admin';
}


if ($page === 'manifest') {
    $manifestAppName = trim((string) (app_setting($pdo, 'app_name', (string) ($config['app_name'] ?? 'Fitness Challenge')) ?? 'Fitness Challenge'));
    if ($manifestAppName === '') {
        $manifestAppName = 'Fitness Challenge';
    }
    $manifestIconPath = trim((string) (app_setting($pdo, 'app_icon_path', '') ?? ''));
    $manifestHasCustomIcon = $manifestIconPath !== '' && resolve_media_storage_path($config, $manifestIconPath) !== null;
    $manifestIcons = [];
    if ($manifestHasCustomIcon) {
        $manifestIcons[] = [
            'src' => '/?page=app_icon',
            'sizes' => 'any',
            'type' => detect_media_mime_type((string) resolve_media_storage_path($config, $manifestIconPath)),
            'purpose' => 'any',
        ];
    }
    $manifestIcons[] = ['src' => '/?page=app_icon_default&size=192', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'];
    $manifestIcons[] = ['src' => '/?page=app_icon_default&size=512', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'];
    $manifest = [
        'id' => '/',
        'name' => $manifestAppName,
        'short_name' => function_exists('mb_substr') ? mb_substr($manifestAppName, 0, 18) : substr($manifestAppName, 0, 18),
        'description' => 'Fitness, habits, goals and team challenges.',
        'start_url' => '/?page=dashboard&source=pwa',
        'scope' => '/',
        'display' => 'standalone',
        'display_override' => ['window-controls-overlay', 'standalone', 'minimal-ui'],
        'background_color' => '#080b0f',
        'theme_color' => '#18a999',
        'orientation' => 'any',
        'icons' => $manifestIcons,
        'shortcuts' => [
            ['name' => 'Daily log', 'short_name' => 'Log', 'url' => '/?page=entries&mode=data&source=shortcut', 'icons' => $manifestIcons],
            ['name' => 'Meal', 'short_name' => 'Meal', 'url' => '/?page=nutrition&source=shortcut', 'icons' => $manifestIcons],
            ['name' => 'Workout', 'short_name' => 'Workout', 'url' => '/?page=workouts&source=shortcut', 'icons' => $manifestIcons],
            ['name' => 'Metric', 'short_name' => 'Metric', 'url' => '/?page=entries&mode=data&metric_new=1&source=shortcut', 'icons' => $manifestIcons],
        ],
        'categories' => ['fitness', 'health', 'lifestyle'],
        'prefer_related_applications' => false,
    ];
    header('Content-Type: application/manifest+json; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    echo json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($page === 'app_icon_default') {
    $iconSize = (int) ($_GET['size'] ?? 192);
    $iconSize = $iconSize >= 512 ? 512 : 192;
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng')) {
        redirect('/assets/app-icon.svg');
    }
    $icon = imagecreatetruecolor($iconSize, $iconSize);
    $teal = imagecolorallocate($icon, 18, 161, 146);
    $white = imagecolorallocate($icon, 255, 255, 255);
    imagefill($icon, 0, 0, $teal);
    imagesetthickness($icon, max(14, (int) round($iconSize * 0.09)));
    $center = (int) round($iconSize / 2);
    $left = (int) round($iconSize * 0.27);
    $right = (int) round($iconSize * 0.73);
    $shortTop = (int) round($iconSize * 0.40);
    $shortBottom = (int) round($iconSize * 0.60);
    $longTop = (int) round($iconSize * 0.32);
    $longBottom = (int) round($iconSize * 0.68);
    imageline($icon, $left, $center, $right, $center, $white);
    imageline($icon, $left, $longTop, $left, $longBottom, $white);
    imageline($icon, $right, $longTop, $right, $longBottom, $white);
    imageline($icon, (int) round($iconSize * 0.16), $shortTop, (int) round($iconSize * 0.16), $shortBottom, $white);
    imageline($icon, (int) round($iconSize * 0.84), $shortTop, (int) round($iconSize * 0.84), $shortBottom, $white);
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=604800');
    imagepng($icon);
    if (PHP_VERSION_ID < 80500) {
        imagedestroy($icon);
    }
    exit;
}

if (
    $currentUser !== null
    && !empty($config['request_schedulers_enabled'])
    && !in_array($page, ['manifest', 'app_icon', 'app_icon_default', 'login_background', 'media', 'media_thumb', 'shared_workout_media', 'dashboard_panel_state', 'api_meal_calendar', 'api_gallery_recent', 'api_workout_media_search', 'api_workout_media_import'], true)
) {
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

if ($page === 'dashboard_panel_state') {
    if (!is_post()) {
        json_response(['ok' => false, 'error' => 'method_not_allowed'], 405);
    }
    if ($currentUser === null) {
        json_response(['ok' => false, 'error' => 'unauthorized'], 401);
    }
    if (!csrf_verify()) {
        json_response(['ok' => false, 'error' => 'csrf'], 419);
    }

    $panelKey = trim((string) ($_POST['panel_key'] ?? ''));
    $expandedValue = (string) ($_POST['expanded'] ?? '');
    if (!in_array($expandedValue, ['0', '1'], true)) {
        json_response(['ok' => false, 'error' => 'invalid_state'], 422);
    }

    try {
        $expanded = $expandedValue === '1';
        save_dashboard_panel_preference($pdo, (int) $currentUser['id'], $panelKey, $expanded);
    } catch (InvalidArgumentException) {
        json_response(['ok' => false, 'error' => 'invalid_panel'], 422);
    }

    json_response([
        'ok' => true,
        'panel_key' => $panelKey,
        'expanded' => $expanded,
    ]);
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

if ($page === 'setup') {
    $setupError = '';
    $setupLocale = normalize_locale((string) ($_POST['locale'] ?? config_default_locale($config)), config_default_locale($config));
    set_current_locale($setupLocale);
    $setupValues = [
        'app_name' => trim((string) ($_POST['app_name'] ?? ($config['app_name'] ?? 'Fitness Challenge'))),
        'challenge_name' => trim((string) ($_POST['challenge_name'] ?? t('setup.default_challenge_name'))),
        'team_name' => trim((string) ($_POST['team_name'] ?? t('setup.default_team_name'))),
        'display_name' => trim((string) ($_POST['display_name'] ?? '')),
        'username' => trim((string) ($_POST['username'] ?? 'admin')),
        'locale' => $setupLocale,
        'challenge_start' => trim((string) ($_POST['challenge_start'] ?? date('Y-m-d'))),
        'challenge_end' => trim((string) ($_POST['challenge_end'] ?? date('Y-m-d', strtotime('+90 days')))),
    ];

    if (is_post()) {
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
        $usernameLength = function_exists('mb_strlen') ? mb_strlen($setupValues['username']) : strlen($setupValues['username']);
        $displayNameLength = function_exists('mb_strlen') ? mb_strlen($setupValues['display_name']) : strlen($setupValues['display_name']);

        if (!csrf_verify()) {
            $setupError = t('flash.csrf');
        } elseif ($setupValues['app_name'] === '' || $setupValues['challenge_name'] === '' || $setupValues['team_name'] === '') {
            $setupError = t('setup.error_required_site_settings');
        } elseif ($setupValues['display_name'] === '' || $displayNameLength > 80) {
            $setupError = t('setup.error_display_name');
        } elseif ($usernameLength < 3 || $usernameLength > 40 || preg_match('/^[A-Za-z0-9._-]+$/', $setupValues['username']) !== 1) {
            $setupError = t('setup.error_username');
        } elseif (strlen($password) < 10) {
            $setupError = t('setup.error_password_length');
        } elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            $setupError = t('setup.error_password_complexity');
        } elseif ($password !== $passwordConfirm) {
            $setupError = t('flash.password_mismatch');
        } elseif (date_input_to_iso($setupValues['challenge_start']) === null || date_input_to_iso($setupValues['challenge_end']) === null) {
            $setupError = t('setup.error_dates_invalid');
        } elseif ($setupValues['challenge_end'] < $setupValues['challenge_start']) {
            $setupError = t('setup.error_date_order');
        } else {
            try {
                db_immediate_transaction($pdo, static function () use ($pdo, $setupValues, $password): void {
                    if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 0) {
                        throw new RuntimeException(t('setup.error_already_configured'));
                    }

                    $now = now_iso();
                    db_execute(
                        $pdo,
                        'INSERT INTO users (
                            username, password_hash, display_name, role, locale,
                            onboarding_status, onboarding_completed_at, active, created_at, updated_at
                         ) VALUES (
                            :username, :password_hash, :display_name, "admin", :locale,
                            "complete", :completed_at, 1, :created_at, :updated_at
                         )',
                        [
                            ':username' => $setupValues['username'],
                            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                            ':display_name' => $setupValues['display_name'],
                            ':locale' => $setupValues['locale'],
                            ':completed_at' => $now,
                            ':created_at' => $now,
                            ':updated_at' => $now,
                        ]
                    );
                    $adminId = (int) $pdo->lastInsertId();

                    db_execute(
                        $pdo,
                        'INSERT INTO app_settings (setting_key, setting_value, updated_by, updated_at)
                         VALUES ("app_name", :value, :updated_by, :updated_at)
                         ON CONFLICT(setting_key) DO UPDATE SET
                            setting_value = excluded.setting_value,
                            updated_by = excluded.updated_by,
                            updated_at = excluded.updated_at',
                        [
                            ':value' => $setupValues['app_name'],
                            ':updated_by' => $adminId,
                            ':updated_at' => $now,
                        ]
                    );
                    db_execute(
                        $pdo,
                        'UPDATE challenge_settings
                         SET challenge_name = :name, challenge_start = :start, challenge_end = :end, updated_at = :updated_at
                         WHERE id = 1',
                        [
                            ':name' => $setupValues['challenge_name'],
                            ':start' => $setupValues['challenge_start'],
                            ':end' => $setupValues['challenge_end'],
                            ':updated_at' => $now,
                        ]
                    );

                    $team = db_fetch_one($pdo, 'SELECT id FROM teams WHERE slug = "main"');
                    if ($team === null) {
                        db_execute(
                            $pdo,
                            'INSERT INTO teams (name, description, slug, active, created_at, updated_at)
                             VALUES (:name, "", "main", 1, :created_at, :updated_at)',
                            [':name' => $setupValues['team_name'], ':created_at' => $now, ':updated_at' => $now]
                        );
                        $teamId = (int) $pdo->lastInsertId();
                    } else {
                        $teamId = (int) $team['id'];
                        db_execute($pdo, 'UPDATE teams SET name = :name, updated_at = :updated_at WHERE id = :id', [
                            ':name' => $setupValues['team_name'],
                            ':updated_at' => $now,
                            ':id' => $teamId,
                        ]);
                    }
                    db_execute(
                        $pdo,
                        'INSERT INTO team_memberships (team_id, user_id, role, active, joined_at, created_at, updated_at)
                         VALUES (:team_id, :user_id, "owner", 1, :joined_at, :created_at, :updated_at)',
                        [
                            ':team_id' => $teamId,
                            ':user_id' => $adminId,
                            ':joined_at' => $now,
                            ':created_at' => $now,
                            ':updated_at' => $now,
                        ]
                    );
                    db_execute(
                        $pdo,
                        'INSERT INTO team_membership_periods (team_id, user_id, joined_at, created_at, updated_at)
                         VALUES (:team_id, :user_id, :joined_at, :created_at, :updated_at)',
                        [
                            ':team_id' => $teamId,
                            ':user_id' => $adminId,
                            ':joined_at' => $now,
                            ':created_at' => $now,
                            ':updated_at' => $now,
                        ]
                    );
                    db_execute($pdo, 'UPDATE users SET active_team_id = :team_id WHERE id = :id', [
                        ':team_id' => $teamId,
                        ':id' => $adminId,
                    ]);
                });

                persist_session_locale($setupValues['locale']);
                set_current_locale($setupValues['locale']);
                if (!login_user($pdo, $setupValues['username'], $password)) {
                    throw new RuntimeException(t('setup.error_autologin_failed'));
                }
                flash_set('success', t('setup.success'));
                redirect('/?page=dashboard');
            } catch (Throwable $e) {
                $setupError = $e->getMessage() !== '' ? $e->getMessage() : t('setup.error_generic');
            }
        }
    }

    render_view('setup', [
        'title' => t('setup.title'),
        'currentPage' => 'setup',
        'currentUser' => null,
        'setupError' => $setupError,
        'setupValues' => $setupValues,
        'config' => $config,
    ]);
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

if ($page === 'weekly_report') {
    $reportUser = require_login($pdo);
    $start = to_date((string) ($_GET['start'] ?? ''), '');
    $report = $start !== '' ? db_fetch_one($pdo, 'SELECT * FROM weekly_report_runs WHERE user_id = :user AND period_start = :start AND file_path IS NOT NULL', [
        ':user' => (int) $reportUser['id'],
        ':start' => $start,
    ]) : null;
    $path = $report !== null ? resolve_media_storage_path($config, (string) $report['file_path']) : null;
    if ($path === null || !is_file($path)) {
        http_response_code(404);
        echo e(t('flash.not_found'));
        exit;
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="weekly-report-' . rawurlencode($start) . '.pdf"');
    header('Cache-Control: private, no-store');
    readfile($path);
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

    $excusesAllowed = penalties_enabled($pdo);
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
        // Excuses only make sense while penalties are on. With the feature off the
        // client cannot show the fields, and the server refuses to store them, so a
        // crafted request cannot smuggle an excuse back in.
        'step_exception_reason' => $excusesAllowed ? trim((string) ($json['step_exception_reason'] ?? '')) : '',
        'distance_exception_reason' => $excusesAllowed ? trim((string) ($json['distance_exception_reason'] ?? '')) : '',
        'workout_exception_reason' => $excusesAllowed ? trim((string) ($json['workout_exception_reason'] ?? '')) : '',
        'resend_requests' => $excusesAllowed && (int) ($json['resend_requests'] ?? 0) === 1 ? 1 : 0,
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

if ($page === 'api_delete_habit') {
    $currentUser = require_login($pdo);

    if (!is_post()) {
        json_response(['ok' => false, 'message' => t('flash.method_not_allowed')], 405);
    }

    $json = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($json)) {
        json_response(['ok' => false, 'message' => t('flash.invalid_json')], 400);
    }
    if (!isset($json['csrf_token']) || !is_string($json['csrf_token']) || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $json['csrf_token'])) {
        json_response(['ok' => false, 'message' => t('flash.csrf')], 419);
    }

    $code = trim((string) ($json['code'] ?? ''));
    $habit = $code !== ''
        ? db_fetch_one($pdo, 'SELECT * FROM habit_definitions WHERE code = :c', [':c' => $code])
        : null;
    if ($habit === null) {
        json_response(['ok' => false, 'message' => t('table.custom_habit_error')], 404);
    }

    // Only custom habits can be removed, and only by the person who created them
    // (or an admin). The seeded habits belong to the challenge, not to a user.
    $createdBy = (int) ($habit['created_by'] ?? 0);
    if ($createdBy <= 0 || ($createdBy !== (int) $currentUser['id'] && !is_admin($currentUser))) {
        json_response(['ok' => false, 'message' => t('flash.no_permission')], 403);
    }

    // Deactivate rather than delete: the daily_log_habits rows already logged
    // against it stay intact, so past days keep their history.
    db_execute(
        $pdo,
        'UPDATE habit_definitions SET active = 0, updated_at = :now WHERE id = :id',
        [':now' => now_iso(), ':id' => (int) $habit['id']]
    );

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

if ($page === 'register') {
    if ($currentUser !== null) {
        redirect(onboarding_is_pending($currentUser) ? '/?page=onboarding' : '/?page=dashboard');
    }

    $registrationToken = trim((string) ($_POST['token'] ?? ($_GET['token'] ?? '')));
    $registrationInvite = registration_invite_from_token($pdo, $registrationToken);
    $publicRegistrationEnabled = public_registration_enabled($pdo);
    $registrationInviteStatus = $registrationInvite !== null ? registration_invite_status($registrationInvite) : 'invalid';
    $registrationMode = $registrationInviteStatus === 'active'
        ? 'invite'
        : ($registrationToken === '' && $publicRegistrationEnabled ? 'public' : ($registrationToken === '' ? 'closed' : 'invalid'));
    $registrationIsPublic = $registrationMode === 'public';
    $registrationAllowed = in_array($registrationMode, ['invite', 'public'], true);
    $registrationError = '';
    if (is_post()) {
        $registrationIpAddress = request_ip_address();
        $registrationRateKey = '__public_registration__';
        $requestedLocale = normalize_locale((string) ($_POST['locale'] ?? resolve_locale($config)), config_default_locale($config));
        persist_session_locale($requestedLocale);
        set_current_locale($requestedLocale);
        if (!csrf_verify()) {
            $registrationError = t('flash.csrf');
        } elseif (!$registrationAllowed) {
            $registrationError = t($registrationMode === 'closed' ? 'register.registration_closed' : 'register.invite_invalid');
        } elseif ($registrationIsPublic && login_attempt_is_blocked($pdo, $registrationRateKey, $registrationIpAddress, 5, 15)) {
            $registrationError = t('register.rate_limited');
        } elseif ((string) ($_POST['password'] ?? '') !== (string) ($_POST['password_confirm'] ?? '')) {
            $registrationError = t('flash.password_mismatch');
            if ($registrationIsPublic) {
                register_failed_login_attempt($pdo, $registrationRateKey, $registrationIpAddress);
            }
        } else {
            try {
                $registrationPayload = [
                    'username' => (string) ($_POST['username'] ?? ''),
                    'display_name' => (string) ($_POST['display_name'] ?? ''),
                    'password' => (string) ($_POST['password'] ?? ''),
                    'locale' => $requestedLocale,
                ];
                $registeredUser = $registrationIsPublic
                    ? register_user_public($pdo, $registrationPayload)
                    : register_user_with_invite($pdo, $registrationToken, $registrationPayload);
                if (!login_user($pdo, (string) $registeredUser['username'], (string) ($_POST['password'] ?? ''))) {
                    throw new RuntimeException(t('register.failed'));
                }
                if ($registrationIsPublic) {
                    clear_login_attempts($pdo, $registrationRateKey, $registrationIpAddress);
                }
                flash_set('success', t('register.success'));
                redirect('/?page=onboarding');
            } catch (Throwable $e) {
                if ($registrationIsPublic) {
                    register_failed_login_attempt($pdo, $registrationRateKey, $registrationIpAddress);
                }
                $registrationError = $e instanceof InvalidArgumentException && $e->getMessage() !== ''
                    ? $e->getMessage()
                    : t('register.failed');
                $registrationInvite = registration_invite_from_token($pdo, $registrationToken);
                $publicRegistrationEnabled = public_registration_enabled($pdo);
                $registrationInviteStatus = $registrationInvite !== null ? registration_invite_status($registrationInvite) : 'invalid';
                $registrationMode = $registrationInviteStatus === 'active'
                    ? 'invite'
                    : ($registrationToken === '' && $publicRegistrationEnabled ? 'public' : ($registrationToken === '' ? 'closed' : 'invalid'));
                $registrationIsPublic = $registrationMode === 'public';
                $registrationAllowed = in_array($registrationMode, ['invite', 'public'], true);
            }
        }
    }

    render_view('register', [
        'title' => t('register.title'),
        'currentPage' => 'register',
        'currentUser' => null,
        'registrationToken' => $registrationToken,
        'registrationInvite' => $registrationInvite,
        'registrationInviteStatus' => $registrationInviteStatus,
        'registrationMode' => $registrationMode,
        'registrationAllowed' => $registrationAllowed,
        'registrationIsPublic' => $registrationIsPublic,
        'publicRegistrationEnabled' => $publicRegistrationEnabled,
        'registrationError' => $registrationError,
        'config' => $config,
    ]);
}

if ($page === 'login') {
    if ($currentUser !== null) {
        redirect(onboarding_is_pending($currentUser) ? '/?page=onboarding' : '/?page=dashboard');
    }

    if (is_post()) {
        if (!csrf_verify()) {
            flash_set('error', t('flash.csrf'));
            redirect('/?page=login');
        }

        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $ipAddress = request_ip_address();

        $loginRetryQuery = $username !== '' ? '&username=' . urlencode($username) : '';

        if (login_attempt_is_blocked($pdo, $username, $ipAddress, 5, 15)) {
            flash_set('error', t('flash.login_blocked'));
            redirect('/?page=login' . $loginRetryQuery);
        }

        if (login_user($pdo, $username, $password)) {
            clear_login_attempts($pdo, $username, $ipAddress);
            $rememberMe = bool_from_form('remember_me') === 1;
            set_remember_me_cookie($config, $rememberMe);
            sync_session_cookie_lifetime($config, $rememberMe);
            $currentUser = current_user($pdo);
            set_current_locale(resolve_locale($config, $currentUser));
            $welcomeName = trim((string) ($currentUser['display_name'] ?? ''));
            if ($welcomeName === '') {
                $welcomeName = trim((string) ($currentUser['username'] ?? ''));
            }
            flash_set('success', $welcomeName !== ''
                ? t('flash.welcome_named', ['name' => $welcomeName])
                : t('flash.welcome'));
            redirect($currentUser !== null && onboarding_is_pending($currentUser) ? '/?page=onboarding' : '/?page=dashboard');
        }

        register_failed_login_attempt($pdo, $username, $ipAddress);
        flash_set('error', t('flash.bad_credentials'));
        redirect('/?page=login' . $loginRetryQuery);
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
    $loginStyle = login_style_normalize(app_setting($pdo, 'login_style', 'split'));
    $publicRegistrationEnabled = public_registration_enabled($pdo);

    render_view('login', [
        'title' => t('login.submit'),
        'currentPage' => 'login',
        'currentUser' => null,
        'loginAppIconUrl' => $loginAppIconUrl,
        'loginBackgroundUrl' => $loginBackgroundUrl,
        'loginRememberDefault' => $loginRememberDefault,
        'loginStyle' => $loginStyle,
        'publicRegistrationEnabled' => $publicRegistrationEnabled,
        'config' => $config,
    ]);
}

if ($page === 'shared_workout') {
    workouts_ensure_schema($pdo);
    $sharedToken = strtolower(trim((string) ($_GET['token'] ?? '')));
    $sharedSession = wk_public_session_get($pdo, $sharedToken);
    if ($sharedSession === null) {
        http_response_code(404);
    }
    $sharedOrigin = (string) ($_GET['origin'] ?? '') === 'home_feed' ? 'home_feed' : '';
    $sharedOriginReturn = $sharedOrigin !== '' ? trim((string) ($_GET['return_to'] ?? '')) : '';
    $sharedOriginReturn = $sharedOriginReturn !== '' ? safe_redirect_target($sharedOriginReturn) : '';
    $sharedReturnUrl = '/?page=shared_workout&token=' . rawurlencode($sharedToken);
    if ($sharedOriginReturn !== '') {
        $sharedReturnUrl .= '&origin=home_feed&return_to=' . rawurlencode($sharedOriginReturn);
    }
    if (is_post() && $sharedSession !== null) {
        if ($currentUser === null) {
            flash_set('error', t('auth.login_required'));
            redirect('/?page=login');
        }
        if (!csrf_verify()) {
            flash_set('error', t('flash.csrf'));
            redirect($sharedReturnUrl);
        }
        if ((string) ($_POST['action'] ?? '') === 'shared_workout_add_exercise') {
            $viewerId = (int) ($currentUser['id'] ?? 0);
            $routineId = max(0, (int) ($_POST['routine_id'] ?? 0));
            $exerciseId = max(0, (int) ($_POST['exercise_def_id'] ?? 0));
            $routine = wk_routine_get($pdo, $routineId, $viewerId);
            $sharedExercise = db_fetch_one(
                $pdo,
                'SELECT ed.*
                 FROM session_exercises se
                 JOIN exercise_definitions ed ON ed.id=se.exercise_def_id
                 WHERE se.session_id=:session AND se.exercise_def_id=:exercise
                   AND EXISTS (
                       SELECT 1 FROM workout_sets wset
                       WHERE wset.session_exercise_id=se.id AND wset.completed=1
                   )
                 LIMIT 1',
                [':session' => (int) $sharedSession['id'], ':exercise' => $exerciseId]
            );
            if ((int) ($sharedSession['user_id'] ?? 0) !== $viewerId
                && is_array($routine)
                && (int) ($routine['is_archived'] ?? 0) !== 1
                && is_array($sharedExercise)
                && (int) ($sharedExercise['active'] ?? 1) === 1) {
                $ownsTransaction = !$pdo->inTransaction();
                if ($ownsTransaction) $pdo->beginTransaction();
                try {
                    $targetExerciseId = ((int) ($sharedExercise['is_system'] ?? 0) === 1
                        || (int) ($sharedExercise['user_id'] ?? 0) === $viewerId)
                        ? $exerciseId
                        : wk_user_clone_exercise($pdo, $exerciseId, $viewerId, true, false);
                    $addedExerciseId = wk_routine_add_exercise($pdo, $routineId, $targetExerciseId);
                    if ($addedExerciseId <= 0) throw new RuntimeException(t('flash.save_failed'));
                    if ($ownsTransaction) $pdo->commit();
                    flash_set('success', t('workouts.exercise_added'));
                } catch (Throwable $e) {
                    if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
                    error_log('Shared workout exercise import failed: ' . $e->getMessage());
                    flash_set('error', $e instanceof InvalidArgumentException ? $e->getMessage() : t('flash.save_failed'));
                }
            } else {
                flash_set('error', t('flash.save_failed'));
            }
            redirect($sharedReturnUrl);
        }
        redirect($sharedReturnUrl);
    }
    $sharedExercises = $sharedSession !== null ? wk_session_exercises($pdo, (int) $sharedSession['id']) : [];
    $sharedExerciseMedia = $sharedExercises !== [] ? wk_exercise_media_map($pdo, array_map(
        static fn(array $exercise): array => array_merge($exercise, ['id' => (int) ($exercise['exercise_def_id'] ?? 0)]),
        $sharedExercises
    )) : [];
    render_view('shared_workout', [
        'title' => $sharedSession !== null ? (wk_session_display_title($sharedSession) ?: t('workouts.session')) : t('flash.not_found'),
        'currentPage' => 'shared_workout',
        'currentUser' => $currentUser,
        'sharedSession' => $sharedSession,
        'sharedExercises' => $sharedExercises,
        'sharedExerciseMedia' => $sharedExerciseMedia,
        'sharedReturnUrl' => $sharedReturnUrl,
        'sharedUserRoutines' => $currentUser !== null && (int) ($sharedSession['user_id'] ?? 0) !== (int) ($currentUser['id'] ?? 0)
            ? wk_routines_for_user($pdo, (int) $currentUser['id'], false)
            : [],
        'metaTitle' => $sharedSession !== null ? (wk_session_display_title($sharedSession) ?: t('workouts.session')) : null,
        'metaDescription' => $sharedSession !== null ? t('workouts.shared_meta_description') : null,
        'config' => $config,
    ]);
}

if ($page === 'shared_workout_media') {
    workouts_ensure_schema($pdo);
    $sharedMediaToken = strtolower(trim((string) ($_GET['token'] ?? '')));
    $sharedMediaPath = trim((string) ($_GET['path'] ?? ''));
    $sharedMediaSession = wk_public_session_get($pdo, $sharedMediaToken);
    $allowedMedia = $sharedMediaSession !== null ? db_fetch_one(
        $pdo,
        'SELECT 1
         FROM session_exercises se
         JOIN exercise_definitions ed ON ed.id=se.exercise_def_id
         LEFT JOIN exercise_media em ON em.exercise_def_id=ed.id
         WHERE se.session_id=:session
           AND (ed.image_path=:cover_path OR em.file_path=:gallery_path)
         LIMIT 1',
        [
            ':session' => (int) $sharedMediaSession['id'],
            ':cover_path' => $sharedMediaPath,
            ':gallery_path' => $sharedMediaPath,
        ]
    ) : null;
    $sharedThumb = null;
    if ($allowedMedia !== null) {
        try { $sharedThumb = generate_media_thumbnail($config, $sharedMediaPath, max(80, min(1200, (int) ($_GET['w'] ?? 360)))); } catch (Throwable) { $sharedThumb = null; }
    }
    if (!is_array($sharedThumb) || !is_file((string) ($sharedThumb['path'] ?? ''))) { http_response_code(404); exit; }
    header('Content-Type: ' . (string) ($sharedThumb['mime'] ?? 'image/webp'));
    header('Cache-Control: public, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    readfile((string) $sharedThumb['path']);
    exit;
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
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    if ($filesize !== false && str_starts_with($mime, 'video/')) {
        $fileSizeInt = (int) $filesize;
        header('Accept-Ranges: bytes');
        $range = trim((string) ($_SERVER['HTTP_RANGE'] ?? ''));
        if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $rangeMatch) === 1) {
            if ($rangeMatch[1] === '' && $rangeMatch[2] !== '') {
                $suffixLength = max(1, (int) $rangeMatch[2]);
                $start = max(0, $fileSizeInt - $suffixLength);
                $end = $fileSizeInt - 1;
            } else {
                $start = $rangeMatch[1] !== '' ? (int) $rangeMatch[1] : 0;
                $end = $rangeMatch[2] !== '' ? (int) $rangeMatch[2] : $fileSizeInt - 1;
            }
            if ($start < 0 || $end < $start || $start >= $fileSizeInt) {
                http_response_code(416);
                header('Content-Range: bytes */' . $fileSizeInt);
                exit;
            }
            $end = min($end, $fileSizeInt - 1);
            $length = $end - $start + 1;
            http_response_code(206);
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSizeInt);
            header('Content-Length: ' . $length);
            $handle = fopen($resolvedPath, 'rb');
            if ($handle === false) {
                http_response_code(500);
                exit;
            }
            fseek($handle, $start);
            $remaining = $length;
            while ($remaining > 0 && !feof($handle)) {
                $chunk = fread($handle, min(1024 * 1024, $remaining));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                echo $chunk;
                $remaining -= strlen($chunk);
            }
            fclose($handle);
            exit;
        }
    }
    if ($filesize !== false) {
        header('Content-Length: ' . (string) $filesize);
    }
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

if (onboarding_is_pending($currentUser) && $page !== 'onboarding') {
    redirect('/?page=onboarding');
}

if ($page === 'onboarding') {
    if (!onboarding_is_pending($currentUser)) {
        redirect('/?page=dashboard');
    }
    $onboardingSteps = ['profile', 'privacy', 'telegram', 'goals', 'teams'];
    $savedOnboardingStep = (string) ($currentUser['onboarding_step'] ?? 'profile');
    if (in_array($savedOnboardingStep, ['challenge', 'install'], true)) {
        $savedOnboardingStep = 'teams';
        set_user_onboarding_step($pdo, (int) $currentUser['id'], $savedOnboardingStep);
    }
    if (!in_array($savedOnboardingStep, $onboardingSteps, true)) {
        $savedOnboardingStep = 'profile';
    }
    $onboardingFurthestIndex = (int) array_search($savedOnboardingStep, $onboardingSteps, true);
    $onboardingStep = trim((string) ($_POST['step'] ?? ($_GET['step'] ?? $savedOnboardingStep)));
    if (!in_array($onboardingStep, $onboardingSteps, true)) {
        $onboardingStep = $savedOnboardingStep;
    }
    $onboardingStepIndex = (int) array_search($onboardingStep, $onboardingSteps, true);
    if ($onboardingStepIndex > $onboardingFurthestIndex) {
        redirect('/?page=onboarding&step=' . rawurlencode($savedOnboardingStep));
    }
    $onboardingNext = $onboardingSteps[$onboardingStepIndex + 1] ?? '';

    if (is_post()) {
        if (!csrf_verify()) {
            flash_set('error', t('flash.csrf'));
            redirect('/?page=onboarding&step=' . rawurlencode($onboardingStep));
        }
        $action = (string) ($_POST['action'] ?? 'save_onboarding_step');
        if ($action === 'skip_onboarding_step') {
            mark_user_onboarding_skipped($pdo, (int) $currentUser['id']);
            if ($onboardingNext === '') {
                complete_user_onboarding($pdo, (int) $currentUser['id']);
                flash_set('success', t('onboarding.completed'));
                redirect('/?page=dashboard');
            }
            if (($onboardingStepIndex + 1) > $onboardingFurthestIndex) {
                set_user_onboarding_step($pdo, (int) $currentUser['id'], $onboardingNext);
            }
            redirect('/?page=onboarding&step=' . rawurlencode($onboardingNext));
        }

        try {
            if ($onboardingStep === 'telegram' && (string) ($_POST['onboarding_telegram_action'] ?? '') === 'generate_link') {
                $telegramSettings = telegram_settings($pdo);
                if (!telegram_is_enabled($telegramSettings)) {
                    throw new RuntimeException(t('settings.telegram_unavailable'));
                }
                telegram_generate_link_code($pdo, (int) $currentUser['id']);
                flash_set('success', t('flash.telegram_link_ready'));
                redirect('/?page=onboarding&step=telegram');
            }
            if ($onboardingStep === 'goals') {
                $_SESSION['onboarding_goal_input'] = $_POST;
                $stepGoal = 0;
                if (bool_from_form('enable_step_goal') === 1) {
                    $parsedStepGoal = parse_localized_positive_integer($_POST['step_goal'] ?? '');
                    if ($parsedStepGoal === null) {
                        throw new InvalidArgumentException(t('onboarding.steps_invalid'));
                    }
                    $stepGoal = $parsedStepGoal;
                }
                $workoutTarget = bool_from_form('enable_workout_goal') === 1
                    ? max(0, min(14, (int) ($_POST['workout_target'] ?? 0)))
                    : 0;
                if (bool_from_form('enable_workout_goal') === 1 && $workoutTarget <= 0) {
                    throw new InvalidArgumentException(t('onboarding.workouts_invalid'));
                }
                $distanceGoal = 0.0;
                if (bool_from_form('enable_distance_goal') === 1) {
                    $distanceGoal = (float) ($_POST['distance_goal'] ?? 0);
                    if ($distanceGoal <= 0) {
                        throw new InvalidArgumentException(t('metric.invalid'));
                    }
                }
                $calorieBurnRaw = trim((string) ($_POST['calorie_burn_goal'] ?? ''));
                $calorieConsumedRaw = trim((string) ($_POST['calorie_consumed_max'] ?? ''));
                if (($calorieBurnRaw !== '' && (!is_numeric($calorieBurnRaw) || (float) $calorieBurnRaw <= 0))
                    || ($calorieConsumedRaw !== '' && (!is_numeric($calorieConsumedRaw) || (float) $calorieConsumedRaw <= 0))) {
                    throw new InvalidArgumentException(t('metric.invalid'));
                }
                $dailyGoals = [];
                if ($stepGoal > 0) {
                    $dailyGoals[] = ['type' => 'steps', 'value' => (float) $stepGoal];
                }
                if ($distanceGoal > 0) {
                    $dailyGoals[] = ['type' => 'km', 'value' => $distanceGoal];
                }
                $primaryGoalsSpec = format_primary_goals_spec($dailyGoals);
                $legacyPrimary = $dailyGoals[0] ?? null;
                $primaryGoalType = is_array($legacyPrimary) ? (string) ($legacyPrimary['type'] ?? 'none') : 'none';
                $primaryGoalValue = is_array($legacyPrimary) ? (float) ($legacyPrimary['value'] ?? 0) : 0.0;
                db_execute(
                    $pdo,
                    'UPDATE users SET step_goal = :step_goal, workout_target = :workout_target,
                        primary_goal_type = :primary_goal_type, primary_goal_value = :primary_goal_value,
                        primary_goals_spec = :primary_goals_spec, calorie_burn_goal = :calorie_burn_goal,
                        calorie_consumed_max = :calorie_consumed_max,
                        updated_at = :updated_at WHERE id = :id',
                    [
                        ':step_goal' => $stepGoal,
                        ':workout_target' => $workoutTarget,
                        ':primary_goal_type' => $primaryGoalType,
                        ':primary_goal_value' => $primaryGoalType !== 'none' && $primaryGoalValue > 0 ? $primaryGoalValue : null,
                        ':primary_goals_spec' => $primaryGoalsSpec !== '' ? $primaryGoalsSpec : null,
                        ':calorie_burn_goal' => $calorieBurnRaw !== '' ? (float) $calorieBurnRaw : null,
                        ':calorie_consumed_max' => $calorieConsumedRaw !== '' ? (float) $calorieConsumedRaw : null,
                        ':updated_at' => now_iso(),
                        ':id' => (int) $currentUser['id'],
                    ]
                );
                $updatedGoalUser = db_fetch_one($pdo, 'SELECT * FROM users WHERE id = :id', [':id' => (int) $currentUser['id']]) ?? $currentUser;
                $enabledMetrics = array_map('strval', (array) ($_POST['enabled_metrics'] ?? []));
                if ($stepGoal > 0) {
                    $enabledMetrics[] = 'steps';
                }
                if ($workoutTarget > 0) {
                    $enabledMetrics[] = 'workouts';
                }
                if ($calorieBurnRaw !== '') {
                    $enabledMetrics[] = 'calories_burned';
                }
                if ($calorieConsumedRaw !== '') {
                    $enabledMetrics[] = 'calories_consumed';
                }
                foreach ($dailyGoals as $dailyGoal) {
                    if ((string) ($dailyGoal['type'] ?? '') === 'km') {
                        $enabledMetrics[] = 'distance';
                    }
                }
                save_user_metric_preferences($pdo, $updatedGoalUser, $enabledMetrics);
                $customMetricNames = array_values((array) ($_POST['custom_metric_name'] ?? []));
                $customMetricUnits = array_values((array) ($_POST['custom_metric_unit'] ?? []));
                foreach (array_slice($customMetricNames, 0, 10) as $customIndex => $customMetricName) {
                    if (trim((string) $customMetricName) === '') {
                        continue;
                    }
                    $createdMetric = custom_metric_create($pdo, (int) $currentUser['id'], [
                        'name' => $customMetricName,
                        'unit' => $customMetricUnits[$customIndex] ?? '',
                        'frequency' => 'daily',
                        'direction' => 'increase',
                    ]);
                    $enabledMetrics[] = custom_metric_key((int) $createdMetric['id']);
                }
                if ($enabledMetrics !== metric_enabled_keys($pdo, $updatedGoalUser)) {
                    save_user_metric_preferences($pdo, $updatedGoalUser, $enabledMetrics);
                }
                unset($_SESSION['onboarding_goal_input']);
            } elseif ($onboardingStep === 'profile') {
                $newAvatarPath = '';
                $newCoverPath = '';
                $avatarCropped = trim((string) ($_POST['avatar_cropped'] ?? ''));
                $coverCropped = trim((string) ($_POST['cover_cropped'] ?? ''));
                $avatarUpload = is_array($_FILES['avatar'] ?? null) ? (array) $_FILES['avatar'] : [];
                $coverUpload = is_array($_FILES['cover'] ?? null) ? (array) $_FILES['cover'] : [];
                try {
                    if ($avatarCropped !== '') {
                        $newAvatarPath = save_uploaded_image_from_data_url($config, $avatarCropped, 'avatars', 'user_' . (int) $currentUser['id']);
                    } elseif ((int) ($avatarUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                        $newAvatarPath = save_uploaded_image($config, $avatarUpload, 'avatars', 'user_' . (int) $currentUser['id']);
                    }
                    if ($coverCropped !== '') {
                        $newCoverPath = save_uploaded_image_from_data_url($config, $coverCropped, 'profile_covers', 'user_' . (int) $currentUser['id']);
                    } elseif ((int) ($coverUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                        $newCoverPath = save_uploaded_image($config, $coverUpload, 'profile_covers', 'user_' . (int) $currentUser['id']);
                    }
                    $onboardingTheme = in_array(($_POST['theme_mode'] ?? 'light'), ['light', 'dark'], true) ? (string) $_POST['theme_mode'] : 'light';
                    $onboardingTagline = normalize_profile_tagline((string) ($_POST['profile_tagline'] ?? ''));
                    db_execute(
                        $pdo,
                        'UPDATE users SET avatar_path = :avatar_path, profile_cover_path = :profile_cover_path, profile_tagline = :profile_tagline, theme_mode = :theme_mode, updated_at = :updated_at WHERE id = :id',
                        [
                            ':avatar_path' => $newAvatarPath !== '' ? $newAvatarPath : ($currentUser['avatar_path'] ?? null),
                            ':profile_cover_path' => $newCoverPath !== '' ? $newCoverPath : ($currentUser['profile_cover_path'] ?? null),
                            ':profile_tagline' => $onboardingTagline !== '' ? $onboardingTagline : null,
                            ':theme_mode' => $onboardingTheme,
                            ':updated_at' => now_iso(),
                            ':id' => (int) $currentUser['id'],
                        ]
                    );
                    foreach ([
                        [$newAvatarPath, (string) ($currentUser['avatar_path'] ?? '')],
                        [$newCoverPath, (string) ($currentUser['profile_cover_path'] ?? '')],
                    ] as [$replacementPath, $previousPath]) {
                        if ($replacementPath === '' || $previousPath === '' || $replacementPath === $previousPath) {
                            continue;
                        }
                        $previousFile = resolve_media_storage_path($config, $previousPath);
                        if ($previousFile !== null && is_file($previousFile)) {
                            @unlink($previousFile);
                        }
                    }
                } catch (Throwable $uploadError) {
                    foreach ([$newAvatarPath, $newCoverPath] as $failedPath) {
                        $failedFile = $failedPath !== '' ? resolve_media_storage_path($config, $failedPath) : null;
                        if ($failedFile !== null && is_file($failedFile)) {
                            @unlink($failedFile);
                        }
                    }
                    throw $uploadError;
                }
            } elseif ($onboardingStep === 'privacy') {
                privacy_set_preferences(
                    $pdo,
                    (int) $currentUser['id'],
                    (string) ($_POST['profile_visibility'] ?? 'public'),
                    (array) ($_POST['data_visibility'] ?? [])
                );
            } elseif ($onboardingStep === 'telegram') {
                if (
                    telegram_is_enabled(telegram_settings($pdo))
                    && trim((string) ($currentUser['telegram_chat_id'] ?? '')) !== ''
                ) {
                    telegram_update_user_prefs($pdo, (int) $currentUser['id'], $_POST);
                }
            } elseif ($onboardingStep === 'teams') {
                $joinableTeams = list_joinable_teams($pdo, (int) $currentUser['id']);
                $allowedTeamIds = array_map(static fn(array $team): int => (int) ($team['id'] ?? 0), $joinableTeams);
                $selectedTeamIds = array_values(array_unique(array_map('intval', (array) ($_POST['team_ids'] ?? []))));
                foreach ($selectedTeamIds as $selectedTeamId) {
                    if (in_array($selectedTeamId, $allowedTeamIds, true)) {
                        request_or_join_team($pdo, $selectedTeamId, (int) $currentUser['id']);
                    }
                }
            }
        } catch (Throwable $e) {
            flash_set('error', $e->getMessage() !== '' ? $e->getMessage() : t('flash.save_failed'));
            redirect('/?page=onboarding&step=' . rawurlencode($onboardingStep));
        }

        if ($onboardingNext === '') {
            complete_user_onboarding($pdo, (int) $currentUser['id']);
            flash_set('success', t('onboarding.completed'));
            redirect('/?page=dashboard');
        }
        if (($onboardingStepIndex + 1) > $onboardingFurthestIndex) {
            set_user_onboarding_step($pdo, (int) $currentUser['id'], $onboardingNext);
        }
        redirect('/?page=onboarding&step=' . rawurlencode($onboardingNext));
    }

    $currentUser = current_user($pdo) ?? $currentUser;
    $onboardingTelegramSettings = $onboardingStep === 'telegram' ? telegram_settings($pdo) : [];
    render_view('onboarding', [
        'title' => t('onboarding.title'),
        'currentPage' => 'onboarding',
        'currentUser' => $currentUser,
        'onboardingSteps' => $onboardingSteps,
        'onboardingStep' => $onboardingStep,
        'onboardingStepIndex' => $onboardingStepIndex,
        'onboardingFurthestIndex' => $onboardingFurthestIndex,
        'onboardingNext' => $onboardingNext,
        'onboardingPrivacyVisibility' => user_visibility($currentUser),
        'onboardingDataVisibility' => privacy_data_preferences($currentUser),
        'onboardingTelegramSettings' => $onboardingTelegramSettings,
        'joinableTeams' => $onboardingStep === 'teams' ? list_joinable_teams($pdo, (int) $currentUser['id']) : [],
        'config' => $config,
    ]);
}

if ($page === 'api_friend_search') {
    friends_ensure_schema($pdo);
    $query = trim((string) ($_GET['q'] ?? ''));
    if ($query !== '' && (function_exists('mb_strlen') ? mb_strlen($query) : strlen($query)) < 2) {
        json_response(['ok' => true, 'users' => []]);
    }
    if ((function_exists('mb_strlen') ? mb_strlen($query) : strlen($query)) > 64) {
        json_response(['ok' => false, 'error' => 'query_too_long'], 422);
    }
    $now = microtime(true);
    $lastSearchAt = (float) ($_SESSION['friend_search_last_at'] ?? 0.0);
    if ($lastSearchAt > 0 && ($now - $lastSearchAt) < 0.15) {
        json_response(['ok' => false, 'error' => 'rate_limited'], 429);
    }
    $_SESSION['friend_search_last_at'] = $now;
    $users = friends_search_addable_users($pdo, (int) $currentUser['id'], $query, 10);
    json_response([
        'ok' => true,
        'users' => array_map(static fn(array $user): array => [
            'id' => (int) ($user['id'] ?? 0),
            'display_name' => (string) ($user['display_name'] ?? ''),
            'username' => (string) ($user['username'] ?? ''),
            'avatar_url' => avatar_url($user),
            'initials' => initials_for((string) ($user['display_name'] ?? $user['username'] ?? '?')),
        ], $users),
    ]);
}

if ($page === 'api_custom_metrics') {
    $currentUser = require_login($pdo);
    $userId = (int) $currentUser['id'];
    if (!is_post()) {
        json_response(['ok' => true, 'metrics' => custom_metrics_for_user($pdo, $userId)]);
    }
    $input = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }
    $csrf = (string) ($input['csrf_token'] ?? '');
    if ($csrf === '' || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $csrf)) {
        json_response(['ok' => false, 'message' => t('flash.csrf')], 419);
    }
    try {
        $action = (string) ($input['action'] ?? 'create');
        if ($action === 'create') {
            $metric = custom_metric_create($pdo, $userId, $input);
            $enabled = metric_enabled_keys($pdo, $currentUser);
            $enabled[] = custom_metric_key((int) $metric['id']);
            save_user_metric_preferences($pdo, $currentUser, $enabled);
            json_response(['ok' => true, 'metric' => $metric], 201);
        }
        if ($action === 'save_value') {
            $entry = custom_metric_save_value(
                $pdo,
                (int) ($input['metric_id'] ?? 0),
                $userId,
                (string) ($input['entry_date'] ?? null),
                $input['value'] ?? null,
                isset($input['version']) ? (int) $input['version'] : null
            );
            json_response(['ok' => true, 'entry' => $entry]);
        }
        if ($action === 'archive') {
            $metric = custom_metric_get($pdo, (int) ($input['metric_id'] ?? 0), $userId);
            if ($metric === null) {
                json_response(['ok' => false, 'message' => t('flash.not_found')], 404);
            }
            db_execute($pdo, 'UPDATE custom_metric_definitions SET active = 0, updated_at = :now WHERE id = :id AND owner_user_id = :user', [
                ':now' => now_iso(),
                ':id' => (int) $metric['id'],
                ':user' => $userId,
            ]);
            json_response(['ok' => true]);
        }
        json_response(['ok' => false, 'message' => t('flash.invalid_action')], 422);
    } catch (InvalidArgumentException $e) {
        json_response(['ok' => false, 'message' => $e->getMessage()], 422);
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'sync_conflict') {
            json_response(['ok' => false, 'conflict' => true, 'message' => 'This value changed on another device.'], 409);
        }
        error_log('Custom metric API error: ' . $e->getMessage());
        json_response(['ok' => false, 'message' => t('flash.save_failed')], 422);
    } catch (Throwable $e) {
        error_log('Custom metric API error: ' . $e->getMessage());
        json_response(['ok' => false, 'message' => t('flash.save_failed')], 500);
    }
}

if ($page === 'api_sync') {
    $currentUser = require_login($pdo);
    if (!is_post()) {
        json_response(['ok' => false, 'message' => t('flash.method_not_allowed')], 405);
    }
    $input = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($input) || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) ($input['csrf_token'] ?? ''))) {
        json_response(['ok' => false, 'message' => t('flash.csrf')], 419);
    }
    $results = [];
    foreach (array_slice((array) ($input['mutations'] ?? []), 0, 100) as $mutation) {
        if (!is_array($mutation)) {
            continue;
        }
        $key = trim((string) ($mutation['idempotency_key'] ?? ''));
        $existing = $key !== '' ? db_fetch_one($pdo, 'SELECT response_json FROM sync_mutations WHERE user_id = :user AND idempotency_key = :key', [
            ':user' => (int) $currentUser['id'],
            ':key' => $key,
        ]) : null;
        if ($existing !== null) {
            $results[] = json_decode((string) $existing['response_json'], true) ?: ['ok' => true, 'duplicate' => true];
            continue;
        }
        $result = ['ok' => false, 'idempotency_key' => $key];
        try {
            if (($mutation['resource_type'] ?? '') === 'custom_metric_entry') {
                $payload = (array) ($mutation['payload'] ?? []);
                $entry = custom_metric_save_value($pdo, (int) ($payload['metric_id'] ?? 0), (int) $currentUser['id'], (string) ($payload['entry_date'] ?? ''), $payload['value'] ?? null, isset($payload['version']) ? (int) $payload['version'] : null);
                $result = ['ok' => true, 'idempotency_key' => $key, 'entry' => $entry];
            } elseif (($mutation['resource_type'] ?? '') === 'nutrition_entry') {
                $payload = (array) ($mutation['payload'] ?? []);
                $entryId = (int) ($payload['id'] ?? 0);
                if ($entryId <= 0) {
                    $entry = nutrition_create_entry($pdo, (int) $currentUser['id'], $payload);
                } else {
                    $existingEntry = db_fetch_one($pdo, 'SELECT * FROM nutrition_entries WHERE id = :id AND user_id = :user', [
                        ':id' => $entryId,
                        ':user' => (int) $currentUser['id'],
                    ]);
                    if ($existingEntry === null) {
                        throw new InvalidArgumentException('Nutrition entry not found.');
                    }
                    if (isset($payload['version']) && (int) $payload['version'] !== (int) ($existingEntry['version'] ?? 1)) {
                        throw new RuntimeException('sync_conflict');
                    }
                    db_execute($pdo, 'UPDATE nutrition_entries SET entry_date=:date, entry_time=:time, meal_type=:type, notes=:notes,
                        calories=:calories, protein_g=:protein, carbs_g=:carbs, fat_g=:fat, fiber_g=:fiber, sugar_g=:sugar,
                        sodium_mg=:sodium, version=version+1, updated_at=:now WHERE id=:id AND user_id=:user', [
                        ':date' => to_date((string) ($payload['entry_date'] ?? $existingEntry['entry_date'])),
                        ':time' => normalize_log_time($payload['entry_time'] ?? $existingEntry['entry_time'] ?? '', '00:00'),
                        ':type' => in_array(($payload['meal_type'] ?? ''), ['breakfast', 'lunch', 'dinner', 'snack', 'other'], true) ? $payload['meal_type'] : $existingEntry['meal_type'],
                        ':notes' => trim((string) ($payload['notes'] ?? $existingEntry['notes'] ?? '')),
                        ':calories' => max(0, (float) ($payload['calories'] ?? $existingEntry['calories'] ?? 0)),
                        ':protein' => $payload['protein_g'] ?? $existingEntry['protein_g'],
                        ':carbs' => $payload['carbs_g'] ?? $existingEntry['carbs_g'],
                        ':fat' => $payload['fat_g'] ?? $existingEntry['fat_g'],
                        ':fiber' => $payload['fiber_g'] ?? $existingEntry['fiber_g'],
                        ':sugar' => $payload['sugar_g'] ?? $existingEntry['sugar_g'],
                        ':sodium' => $payload['sodium_mg'] ?? $existingEntry['sodium_mg'],
                        ':now' => now_iso(),
                        ':id' => $entryId,
                        ':user' => (int) $currentUser['id'],
                    ]);
                    $entry = db_fetch_one($pdo, 'SELECT * FROM nutrition_entries WHERE id=:id AND user_id=:user', [':id' => $entryId, ':user' => (int) $currentUser['id']]) ?? [];
                }
                $result = ['ok' => true, 'idempotency_key' => $key, 'entry' => $entry];
            } elseif (($mutation['resource_type'] ?? '') === 'daily_log') {
                $payload = (array) ($mutation['payload'] ?? []);
                $date = to_date((string) ($payload['log_date'] ?? ''));
                $existingLog = db_fetch_one($pdo, 'SELECT * FROM daily_logs WHERE user_id=:user AND log_date=:date', [
                    ':user' => (int) $currentUser['id'],
                    ':date' => $date,
                ]);
                if ($existingLog !== null && isset($payload['version']) && (int) $payload['version'] !== (int) ($existingLog['version'] ?? 1)) {
                    throw new RuntimeException('sync_conflict');
                }
                $logPayload = array_merge([
                    'user_id' => (int) $currentUser['id'],
                    'log_date' => $date,
                    'log_time' => '00:00',
                    'steps' => 0,
                    'workout_done' => 0,
                    'workout_type_id' => null,
                    'workout_type' => '',
                    'junk_food' => 0,
                    'extra_workout' => 0,
                    'distance_km' => null,
                    'training_calories_burned' => null,
                    'weight' => null,
                    'notes' => '',
                    'step_exception_reason' => '',
                    'distance_exception_reason' => '',
                    'workout_exception_reason' => '',
                    'morning_walk' => 0,
                    'journaling' => 0,
                    'evening_chores' => 0,
                    'reading' => 0,
                    'workouts' => [],
                    'habits' => [],
                ], $payload, ['user_id' => (int) $currentUser['id'], 'log_date' => $date]);
                unset($logPayload['version']);
                upsert_daily_log_and_sync_approvals($pdo, $logPayload, (int) $currentUser['id']);
                if ($existingLog !== null) {
                    db_execute($pdo, 'UPDATE daily_logs SET version=version+1 WHERE user_id=:user AND log_date=:date', [
                        ':user' => (int) $currentUser['id'],
                        ':date' => $date,
                    ]);
                }
                $entry = db_fetch_one($pdo, 'SELECT * FROM daily_logs WHERE user_id=:user AND log_date=:date', [
                    ':user' => (int) $currentUser['id'],
                    ':date' => $date,
                ]) ?? [];
                $result = ['ok' => true, 'idempotency_key' => $key, 'entry' => $entry];
            } elseif (($mutation['resource_type'] ?? '') === 'custom_habit_definition') {
                $payload = (array) ($mutation['payload'] ?? []);
                $habit = create_custom_habit_from_label($pdo, trim((string) ($payload['label'] ?? '')), (int) $currentUser['id']);
                if ($habit === null) {
                    throw new InvalidArgumentException('Habit name is required.');
                }
                $result = ['ok' => true, 'idempotency_key' => $key, 'habit' => $habit];
            } else {
                $result['message'] = 'Unsupported offline mutation.';
            }
        } catch (InvalidArgumentException $e) {
            $result['message'] = $e->getMessage();
        } catch (RuntimeException $e) {
            $result['conflict'] = $e->getMessage() === 'sync_conflict';
            $result['message'] = $result['conflict'] ? 'Conflict requires review.' : t('flash.save_failed');
            if (!$result['conflict']) {
                error_log('Offline sync runtime error: ' . $e->getMessage());
            }
            if ($result['conflict']) {
                $payload = (array) ($mutation['payload'] ?? []);
                if (($mutation['resource_type'] ?? '') === 'custom_metric_entry') {
                    $result['server'] = db_fetch_one($pdo, 'SELECT * FROM custom_metric_entries WHERE metric_id=:metric AND user_id=:user AND entry_date=:date', [
                        ':metric' => (int) ($payload['metric_id'] ?? 0),
                        ':user' => (int) $currentUser['id'],
                        ':date' => to_date((string) ($payload['entry_date'] ?? '')),
                    ]);
                } elseif (($mutation['resource_type'] ?? '') === 'nutrition_entry') {
                    $result['server'] = db_fetch_one($pdo, 'SELECT * FROM nutrition_entries WHERE id=:id AND user_id=:user', [
                        ':id' => (int) ($payload['id'] ?? 0),
                        ':user' => (int) $currentUser['id'],
                    ]);
                } elseif (($mutation['resource_type'] ?? '') === 'daily_log') {
                    $result['server'] = db_fetch_one($pdo, 'SELECT * FROM daily_logs WHERE user_id=:user AND log_date=:date', [
                        ':user' => (int) $currentUser['id'],
                        ':date' => to_date((string) ($payload['log_date'] ?? '')),
                    ]);
                }
            }
        } catch (Throwable $e) {
            error_log('Offline sync error: ' . $e->getMessage());
            $result['message'] = t('flash.save_failed');
        }
        if ($key !== '') {
            db_execute($pdo, 'INSERT OR IGNORE INTO sync_mutations (user_id, idempotency_key, resource_type, resource_key, response_json, created_at) VALUES (:user, :key, :type, :resource, :response, :now)', [
                ':user' => (int) $currentUser['id'],
                ':key' => $key,
                ':type' => (string) ($mutation['resource_type'] ?? ''),
                ':resource' => (string) ($mutation['resource_key'] ?? ''),
                ':response' => json_encode($result),
                ':now' => now_iso(),
            ]);
        }
        $results[] = $result;
    }
    json_response(['ok' => true, 'results' => $results]);
}

if ($page === 'api_workout_media_search') {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        json_response(['ok' => false, 'message' => t('flash.method_not_allowed')], 405);
    }
    if (!media_search_enabled($pdo)) {
        json_response(['ok' => false, 'message' => t('workouts.media_search_disabled')], 403);
    }
    $type = trim((string) ($_GET['type'] ?? ''));
    $query = trim((string) ($_GET['q'] ?? ''));
    if (!in_array($type, ['image', 'video'], true)) {
        json_response(['ok' => false, 'message' => t('workouts.media_search_query_invalid')], 422);
    }
    $now = microtime(true);
    $rateKey = 'workout_media_search_last_at_' . $type;
    $lastSearchAt = (float) ($_SESSION[$rateKey] ?? 0.0);
    if ($lastSearchAt > 0 && ($now - $lastSearchAt) < 0.35) {
        json_response(['ok' => false, 'message' => t('workouts.media_search_rate_limited')], 429);
    }
    $_SESSION[$rateKey] = $now;
    try {
        $mediaSearchConfig = media_search_effective_config($pdo, $config);
        $results = media_search_query($mediaSearchConfig, $type, $query, (int) $currentUser['id'], current_locale());
        json_response(['ok' => true, 'type' => $type, 'results' => $results]);
    } catch (InvalidArgumentException $e) {
        json_response(['ok' => false, 'message' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        $status = media_search_provider_available($mediaSearchConfig ?? media_search_effective_config($pdo, $config), $type) ? 502 : 503;
        json_response(['ok' => false, 'message' => $e->getMessage()], $status);
    }
}

if ($page === 'api_workout_media_import') {
    if (!is_post()) {
        json_response(['ok' => false, 'message' => t('flash.method_not_allowed')], 405);
    }
    if (!csrf_verify()) {
        json_response(['ok' => false, 'message' => t('flash.csrf')], 419);
    }
    if (!media_search_enabled($pdo)) {
        json_response(['ok' => false, 'message' => t('workouts.media_search_disabled')], 403);
    }
    $selection = trim((string) ($_POST['selection'] ?? ''));
    try {
        $image = media_search_download_selected_image($config, (int) $currentUser['id'], $selection);
        $safeBase = preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string) ($image['title'] ?? 'exercise')) ?: 'exercise';
        $safeBase = trim(substr($safeBase, 0, 70), '-_');
        if ($safeBase === '') {
            $safeBase = 'exercise';
        }
        $filename = $safeBase . '.' . (string) $image['extension'];
        header('Content-Type: ' . (string) $image['mime']);
        header('Content-Length: ' . strlen((string) $image['bytes']));
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Cache-Control: no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        echo (string) $image['bytes'];
        exit;
    } catch (InvalidArgumentException $e) {
        json_response(['ok' => false, 'message' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'message' => $e->getMessage()], 502);
    }
}

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

    $rows = fetch_gallery_photos($pdo, $galleryPerPage + 1, $galleryOffset, $galleryUserFilter, (int) $currentUser['id'], is_admin($currentUser));
    $hasMore = count($rows) > $galleryPerPage;
    if ($hasMore) {
        array_pop($rows);
    }

    $previousMonth = '';
    if ($galleryOffset > 0) {
        $previousRows = fetch_gallery_photos($pdo, 1, $galleryOffset - 1, $galleryUserFilter, (int) $currentUser['id'], is_admin($currentUser));
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
        $photoCount = (int) ($day['meal_count'] ?? $day['count'] ?? 0);
        $preview = is_array($day['preview'] ?? null) ? (array) $day['preview'] : null;
        $previewHasPhoto = $preview !== null
            && (int) ($preview['has_photo'] ?? 0) === 1
            && trim((string) ($preview['file_path'] ?? '')) !== '';
        $previewPhotoId = $previewHasPhoto ? (int) ($preview['id'] ?? 0) : 0;
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
            'count_label' => $photoCount . ' ' . ($photoCount === 1 ? t('entries.meal_singular') : t('entries.meal_plural')),
            'href' => $previewPhotoId > 0
                ? '/?page=photo&photo_id=' . $previewPhotoId
                : ($selectedUserId === (int) $currentUser['id']
                    ? '/?page=entries&mode=nutrition&date=' . rawurlencode((string) $dateKey)
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
            if ((int) ($photo['has_photo'] ?? 0) !== 1 || trim((string) ($photo['file_path'] ?? '')) === '') {
                continue;
            }
            $periodPhotos[] = $photoPayload($photo);
        }
        foreach (array_values((array) ($selectedDayData['photos'] ?? [])) as $photo) {
            if (
                !is_array($photo)
                || (int) ($photo['has_photo'] ?? 0) !== 1
                || trim((string) ($photo['file_path'] ?? '')) === ''
            ) {
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

if ($page === 'meal') {
    $mealEntryId = max(0, (int) ($_GET['meal_id'] ?? 0));
    $mealEntry = nutrition_public_entry_for_viewer($pdo, $mealEntryId, $currentUser);
    $mealOriginReturn = (string) ($_GET['origin'] ?? '') === 'home_feed'
        ? trim((string) ($_GET['return_to'] ?? ''))
        : '';
    $mealBackUrl = str_starts_with($mealOriginReturn, '/') && !str_starts_with($mealOriginReturn, '//')
        ? safe_redirect_target($mealOriginReturn)
        : '/?page=dashboard&home=feed&feed=friends#home-social-feed';
    if ($mealEntry === null) {
        http_response_code(404);
    }

    render_view('meal', [
        'title' => t('nutrition.meal_details'),
        'currentPage' => 'nutrition',
        'currentUser' => $currentUser,
        'mealEntry' => $mealEntry,
        'mealBackUrl' => $mealBackUrl,
        'config' => $config,
    ]);
}

if ($page === 'nutrition') {
    $currentUser = require_login($pdo);
    $nutritionReturnContext = trim((string) ($_POST['return_to'] ?? ($_GET['return_to'] ?? '')));
    $nutritionHasLocalReturn = str_starts_with($nutritionReturnContext, '/') && !str_starts_with($nutritionReturnContext, '//');
    $nutritionReturnUrl = $nutritionReturnContext === 'gallery'
        ? '/?page=gallery&gallery_view=recent'
        : ($nutritionHasLocalReturn ? safe_redirect_target($nutritionReturnContext) : '/?page=nutrition');
    if (is_post()) {
        if (!csrf_verify()) {
            flash_set('error', t('flash.csrf'));
            redirect($nutritionReturnUrl);
        }
        $action = (string) ($_POST['action'] ?? '');
        $nutritionReturnDate = to_date((string) ($_POST['return_date'] ?? $_POST['entry_date'] ?? null));
        try {
            if ($action === 'save_tdee_profile') {
                $birthDate = trim((string) ($_POST['birth_date'] ?? ''));
                $sex = in_array(($_POST['tdee_sex'] ?? ''), ['female', 'male'], true) ? (string) $_POST['tdee_sex'] : '';
                $activity = in_array(($_POST['activity_level'] ?? ''), ['sedentary', 'light', 'moderate', 'active', 'very_active'], true)
                    ? (string) $_POST['activity_level'] : 'moderate';
                db_execute($pdo, 'UPDATE users SET birth_date = :birth, tdee_sex = :sex, height_cm = :height, activity_level = :activity, tdee_override = :override, updated_at = :now WHERE id = :id', [
                    ':birth' => $birthDate !== '' ? to_date($birthDate) : null,
                    ':sex' => $sex,
                    ':height' => ($_POST['height_cm'] ?? '') !== '' ? max(80, min(260, (float) $_POST['height_cm'])) : null,
                    ':activity' => $activity,
                    ':override' => ($_POST['tdee_override'] ?? '') !== '' ? max(500, min(10000, (float) $_POST['tdee_override'])) : null,
                    ':now' => now_iso(),
                    ':id' => (int) $currentUser['id'],
                ]);
                flash_set('success', t('flash.saved'));
            } elseif ($action === 'create_nutrition_entry') {
                $photoPath = null;
                $upload = is_array($_FILES['photo'] ?? null) ? (array) $_FILES['photo'] : [];
                if ((int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $photoPath = save_uploaded_image($config, $upload, 'meals', 'meal_' . (int) $currentUser['id']);
                }
                $entry = nutrition_create_entry($pdo, (int) $currentUser['id'], $_POST, $photoPath);
                if ($photoPath !== null) {
                    $nutrition = array_intersect_key($entry, array_flip(['calories', 'protein_g', 'carbs_g', 'fat_g', 'fiber_g', 'sugar_g', 'sodium_mg']));
                    $photoInput = [
                        'name' => basename($photoPath),
                        'type' => '',
                        'tmp_name' => '',
                        'error' => UPLOAD_ERR_NO_FILE,
                        'size' => 0,
                    ];
                    db_execute($pdo, 'INSERT INTO photo_entries (user_id, log_date, category, caption, file_path, has_photo, calories, protein_g, carbs_g, fat_g, fiber_g, sugar_g, sodium_mg, created_at, updated_at)
                        VALUES (:user, :date, :category, :caption, :path, 1, :calories, :protein, :carbs, :fat, :fiber, :sugar, :sodium, :now, :now)', [
                        ':user' => (int) $currentUser['id'],
                        ':date' => (string) $entry['entry_date'],
                        ':category' => (string) $entry['meal_type'],
                        ':caption' => (string) $entry['notes'],
                        ':path' => $photoPath,
                        ':calories' => $nutrition['calories'] ?? null,
                        ':protein' => $nutrition['protein_g'] ?? null,
                        ':carbs' => $nutrition['carbs_g'] ?? null,
                        ':fat' => $nutrition['fat_g'] ?? null,
                        ':fiber' => $nutrition['fiber_g'] ?? null,
                        ':sugar' => $nutrition['sugar_g'] ?? null,
                        ':sodium' => $nutrition['sodium_mg'] ?? null,
                        ':now' => now_iso(),
                    ]);
                    db_execute($pdo, 'UPDATE nutrition_entries SET photo_entry_id = :photo WHERE id = :entry AND user_id = :user', [
                        ':photo' => (int) $pdo->lastInsertId(),
                        ':entry' => (int) $entry['id'],
                        ':user' => (int) $currentUser['id'],
                    ]);
                }
                flash_set('success', t('flash.meal_saved'));
            } elseif ($action === 'update_nutrition_entry') {
                $entryId = max(0, (int) ($_POST['entry_id'] ?? 0));
                $beforeEntry = db_fetch_one(
                    $pdo,
                    'SELECT * FROM nutrition_entries WHERE id=:id AND user_id=:user LIMIT 1',
                    [':id' => $entryId, ':user' => (int) $currentUser['id']]
                );
                if ($beforeEntry === null) {
                    throw new RuntimeException(t('flash.not_found'));
                }
                $updatedEntry = nutrition_update_entry($pdo, $config, $entryId, (int) $currentUser['id'], $_POST);
                if ($updatedEntry === null) {
                    throw new RuntimeException(t('flash.not_found'));
                }
                audit_log($pdo, (int) $currentUser['id'], 'nutrition_entry_updated', 'nutrition_entry', (string) $entryId, 'Meal updated.', audit_snapshot($beforeEntry), audit_snapshot($updatedEntry));
                flash_set('success', t('nutrition.meal_updated'));
            } elseif ($action === 'delete_nutrition_entry') {
                $entryId = max(0, (int) ($_POST['entry_id'] ?? 0));
                $deletedEntry = nutrition_delete_entry($pdo, $config, $entryId, (int) $currentUser['id']);
                if ($deletedEntry === null) {
                    throw new RuntimeException(t('flash.not_found'));
                }
                audit_log($pdo, (int) $currentUser['id'], 'nutrition_entry_deleted', 'nutrition_entry', (string) $entryId, 'Meal deleted.', audit_snapshot($deletedEntry), null);
                flash_set('success', t('nutrition.meal_deleted'));
            }
        } catch (Throwable $e) {
            error_log('Nutrition action failed: ' . $e->getMessage());
            flash_set('error', $e instanceof InvalidArgumentException ? $e->getMessage() : t('flash.save_failed'));
        }
        redirect($nutritionReturnContext === 'gallery' || $nutritionHasLocalReturn
            ? $nutritionReturnUrl
            : '/?page=nutrition&date=' . rawurlencode($nutritionReturnDate));
    }
    $currentUser = db_fetch_one($pdo, 'SELECT * FROM users WHERE id = :id', [':id' => (int) $currentUser['id']]) ?? $currentUser;
    $rangeEnd = to_date($_GET['date'] ?? null);
    $rangeStart = (new DateTimeImmutable($rangeEnd))->modify('-13 days')->format('Y-m-d');
    $nutritionLatestWeight = nutrition_latest_weight($pdo, (int) $currentUser['id']);
    $nutritionCalculationWeight = $nutritionLatestWeight
        ?? ((float) ($currentUser['ideal_weight'] ?? 0) > 0 ? (float) $currentUser['ideal_weight'] : null);
    render_view('nutrition', [
        'title' => t('nav.nutrition'),
        'currentPage' => 'nutrition',
        'currentUser' => $currentUser,
        'nutritionSeries' => nutrition_daily_summary($pdo, $currentUser, $rangeStart, $rangeEnd),
        'nutritionEntries' => db_fetch_all($pdo, 'SELECT * FROM nutrition_entries WHERE user_id = :user AND entry_date BETWEEN :from AND :to ORDER BY entry_date DESC, entry_time DESC, id DESC', [
            ':user' => (int) $currentUser['id'],
            ':from' => $rangeStart,
            ':to' => $rangeEnd,
        ]),
        'nutritionTdee' => nutrition_tdee($currentUser, $nutritionCalculationWeight),
        'nutritionCalculationWeight' => $nutritionCalculationWeight,
        'nutritionWeightIsLatest' => $nutritionLatestWeight !== null,
        'rangeStart' => $rangeStart,
        'rangeEnd' => $rangeEnd,
        'nutritionReturnContext' => $nutritionReturnContext === 'gallery'
            ? 'gallery'
            : ($nutritionHasLocalReturn ? $nutritionReturnUrl : ''),
        'nutritionAutoOpen' => (string) ($_GET['create'] ?? '') === '1',
        'nutritionAutoOpenMealId' => max(0, (int) ($_GET['meal_id'] ?? 0)),
        'config' => $config,
    ]);
}

if ($page === 'entries') {
    $entryMode = (string) ($_GET['mode'] ?? 'data');
    if ($entryMode === 'nutrition' && !is_post()) {
        $nutritionDate = trim((string) ($_GET['date'] ?? ''));
        redirect('/?page=nutrition' . ($nutritionDate !== '' ? '&date=' . rawurlencode($nutritionDate) : ''));
    }
    if ($entryMode === 'meal' && !is_post()) {
        $legacyMealDate = to_date((string) ($_GET['date'] ?? null));
        redirect('/?page=entries&mode=nutrition&date=' . rawurlencode($legacyMealDate));
    }
    if (!in_array($entryMode, ['data', 'nutrition', 'calendar'], true)) {
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
                $entryEnabledMetricSet = array_fill_keys(metric_enabled_keys($pdo, $currentUser), true);
                $habitValues = [];
                foreach (list_habit_definitions($pdo, false) as $habit) {
                    $code = (string) $habit['code'];
                    if (!isset($entryEnabledMetricSet['habit:' . $code])) {
                        $habitValues[$code] = !empty($existingLogForHabits['habits'][$code])
                            && (int) ($existingLogForHabits['habits'][$code]['value'] ?? 0) === 1 ? 1 : 0;
                        continue;
                    }
                    if ($code === 'morning_walk' && !isset($_POST['habit'][$code])) {
                        $habitValues[$code] = !empty($existingLogForHabits['habits'][$code]) && (int) ($existingLogForHabits['habits'][$code]['value'] ?? 0) === 1 ? 1 : 0;
                        continue;
                    }
                    $habitValues[$code] = isset($_POST['habit'][$code]) && $_POST['habit'][$code] === '1' ? 1 : 0;
                }

                $rawWorkouts = [];
                if (!isset($entryEnabledMetricSet['workouts'])) {
                    $rawWorkouts = array_values((array) ($existingLogForHabits['workouts'] ?? []));
                }
                $hasWorkoutPayload = isset($_POST['workouts']) || isset($_POST['workout_type_id']) || isset($_POST['workout_type']);
                $isNewWorkoutForm = (string) ($_POST['workout_form_mode'] ?? '') === '1';
                if (
                    isset($entryEnabledMetricSet['workouts'])
                    && (bool_from_form('workout_enabled') === 1 || (!$isNewWorkoutForm && $hasWorkoutPayload))
                ) {
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

                $postedSteps = max(0, (int) ($_POST['steps'] ?? 0));
                $savedSteps = max(0, (int) ($existingLogForHabits['base_steps'] ?? $existingLogForHabits['steps'] ?? 0));
                $savedDistance = $existingLogForHabits['base_distance_km'] ?? $existingLogForHabits['distance_km'] ?? null;
                $savedBurnedCalories = $existingLogForHabits['base_training_calories_burned'] ?? $existingLogForHabits['training_calories_burned'] ?? null;
                $savedWeight = $existingLogForHabits['weight'] ?? null;
                $stepsValue = isset($entryEnabledMetricSet['steps']) ? $postedSteps : $savedSteps;
                $distanceValue = isset($entryEnabledMetricSet['distance'])
                    ? (($_POST['distance_km'] ?? '') !== '' ? (float) $_POST['distance_km'] : null)
                    : $savedDistance;
                $burnedCaloriesValue = isset($entryEnabledMetricSet['calories_burned'])
                    ? (($_POST['training_calories_burned'] ?? '') !== '' ? (float) $_POST['training_calories_burned'] : null)
                    : $savedBurnedCalories;
                $weightValue = isset($entryEnabledMetricSet['weight'])
                    ? (($_POST['weight'] ?? '') !== '' ? (float) $_POST['weight'] : null)
                    : $savedWeight;
                $payload = [
                    'user_id' => $userId,
                    'log_date' => $date,
                    'log_time' => normalize_log_time($_POST['log_time'] ?? '', (new DateTimeImmutable('now'))->format('H:i')),
                    'steps' => $stepsValue,
                    'workout_done' => 0,
                    'workout_type_id' => null,
                    'workout_type' => '',
                    'workouts' => $rawWorkouts,
                    'junk_food' => bool_from_form('junk_food'),
                    'extra_workout' => 0,
                    'base_steps' => $stepsValue,
                    'base_distance_km' => $distanceValue,
                    'base_training_calories_burned' => $burnedCaloriesValue,
                    'distance_km' => $distanceValue,
                    'training_calories_burned' => $burnedCaloriesValue,
                    'weight' => $weightValue,
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
                $missingReason = penalties_enabled($pdo) ? trim((string) ($_POST['missing_reason'] ?? '')) : '';
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
                foreach ((array) ($_POST['custom_metric'] ?? []) as $metricId => $metricValue) {
                    if ($metricValue === '') {
                        continue;
                    }
                    custom_metric_save_value($pdo, (int) $metricId, $userId, $date, $metricValue);
                }
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
                $createdHasPhoto = (int) ($createdPhoto['has_photo'] ?? 0) === 1;
                flash_set('success', t($createdHasPhoto ? 'flash.photo_uploaded' : 'flash.meal_saved'));
                if ($createdHasPhoto && function_exists('xp_grant_action')) {
                    xp_grant_action($pdo, $userId, 'photo', 'photo:' . $date);
                }
                // Notify friends of a meal or training post (privacy-aware, once/day).
                if ($createdHasPhoto && function_exists('social_broadcast_activity')) {
                    $activityType = $category === 'workout'
                        ? 'training'
                        : (in_array($category, ['breakfast', 'lunch', 'dinner', 'meal'], true) ? 'meal' : '');
                    if ($activityType !== '') {
                        social_broadcast_activity($pdo, $userId, $activityType);
                    }
                }
            } catch (Throwable $e) {
                flash_set('error', $e->getMessage());
            }

            if ($createdPhotoId > 0 && !empty($createdPhoto['has_photo'])) {
                redirect('/?page=photo&photo_id=' . $createdPhotoId);
            }
            redirect('/?page=entries&mode=nutrition&date=' . $date);
        }

        if ($action === 'update_meal_entry') {
            $mealId = (int) ($_POST['photo_id'] ?? 0);
            $mealDate = to_date($_POST['log_date'] ?? null);
            try {
                $beforeMeal = db_fetch_one($pdo, 'SELECT * FROM photo_entries WHERE id = :id', [':id' => $mealId]);
                if ($beforeMeal === null) {
                    throw new RuntimeException(t('flash.not_found'));
                }
                if (!is_admin($currentUser) && (int) ($beforeMeal['user_id'] ?? 0) !== (int) $currentUser['id']) {
                    throw new RuntimeException(t('flash.no_permission'));
                }
                $updatedMeal = update_photo_entry(
                    $pdo,
                    $config,
                    $mealId,
                    $mealDate,
                    (string) ($_POST['category'] ?? 'other'),
                    trim((string) ($_POST['caption'] ?? '')),
                    [
                        'calories' => $_POST['photo_calories'] ?? null,
                        'protein_g' => $_POST['photo_protein_g'] ?? null,
                        'carbs_g' => $_POST['photo_carbs_g'] ?? null,
                        'fat_g' => $_POST['photo_fat_g'] ?? null,
                        'fiber_g' => $_POST['photo_fiber_g'] ?? null,
                        'sugar_g' => $_POST['photo_sugar_g'] ?? null,
                        'sodium_mg' => $_POST['photo_sodium_mg'] ?? null,
                    ]
                );
                audit_log($pdo, (int) $currentUser['id'], 'meal_updated', 'photo_entry', (string) $mealId, 'Meal updated.', audit_snapshot($beforeMeal), audit_snapshot($updatedMeal));
                flash_set('success', t('flash.meal_saved'));
            } catch (Throwable $e) {
                flash_set('error', $e->getMessage());
            }
            redirect('/?page=entries&mode=nutrition&date=' . rawurlencode($mealDate));
        }

        if ($action === 'delete_photo') {
            $photoId = (int) ($_POST['photo_id'] ?? 0);
            $redirectMode = (string) ($_POST['redirect_mode'] ?? $entryMode);
            if (!in_array($redirectMode, ['nutrition', 'calendar'], true)) {
                $redirectMode = 'nutrition';
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
        $latestMealEntry = fetch_latest_meal_entry($pdo, $selectedUserId);
        $calendarDateFallback = is_array($latestMealEntry ?? null) && !empty($latestMealEntry['log_date'])
            ? (string) $latestMealEntry['log_date']
            : null;
    }
    $selectedDate = calendar_date_from_request($_GET, $calendarView, $calendarDateFallback);
    $currentLog = fetch_log($pdo, $selectedUserId, $selectedDate);
    $recentPhotos = $entryMode === 'nutrition'
        ? fetch_recent_meals($pdo, 50, $selectedUserId)
        : fetch_recent_photos($pdo, 20, $selectedUserId);
    workouts_ensure_schema($pdo);
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
        'entryMetricDefinitions' => metric_preference_definitions($pdo, $currentUser),
        'entryEnabledMetrics' => metric_enabled_keys($pdo, $currentUser),
        'mealCalendar' => $mealCalendar,
        'calendarView' => $calendarView,
        'workoutTypes' => $workoutTypes,
        'userRoutines' => wk_routines_for_user($pdo, (int) $selectedUserId, false),
        'workoutTypeFields' => list_workout_type_fields_grouped($pdo, true),
        'habits' => list_habit_definitions($pdo, true),
        'entryPrimaryGoals' => user_primary_goals($currentUser),
        'entryCustomMetrics' => custom_metrics_for_user($pdo, (int) $selectedUserId),
        'entryCustomMetricValues' => db_fetch_all($pdo, 'SELECT metric_id, value, version FROM custom_metric_entries WHERE user_id = :user AND entry_date = :date', [
            ':user' => (int) $selectedUserId,
            ':date' => $selectedDate,
        ]),
        'entryRecentCustomMetricValues' => db_fetch_all($pdo, 'SELECT metric_id, entry_date, value FROM custom_metric_entries WHERE user_id = :user ORDER BY entry_date DESC LIMIT 1000', [
            ':user' => (int) $selectedUserId,
        ]),
        'entryRecentHabitValues' => db_fetch_all($pdo, 'SELECT l.log_date, d.code, h.value
            FROM daily_log_habits h
            JOIN daily_logs l ON l.id = h.log_id
            JOIN habit_definitions d ON d.id = h.habit_id
            WHERE l.user_id = :user ORDER BY l.log_date DESC LIMIT 1000', [
            ':user' => (int) $selectedUserId,
        ]),
        'entryRecentNutritionValues' => db_fetch_all($pdo, 'SELECT entry_date, SUM(calories) AS calories
            FROM nutrition_entries WHERE user_id = :user GROUP BY entry_date ORDER BY entry_date DESC LIMIT 31', [
            ':user' => (int) $selectedUserId,
        ]),
        'entryDisplay' => (string) ($_GET['display'] ?? 'cards') === 'table' ? 'table' : 'cards',
        'entryRecentLogs' => db_fetch_all($pdo, 'SELECT log_date, steps, distance_km, weight, training_calories_burned, workout_done, extra_workout, notes FROM daily_logs WHERE user_id = :user ORDER BY log_date DESC LIMIT 31', [
            ':user' => (int) $selectedUserId,
        ]),
        'config' => $config,
    ]);
}

if ($page === 'photo') {
    $photoId = isset($_GET['photo_id']) ? (int) $_GET['photo_id'] : (int) ($_POST['photo_id'] ?? 0);
    $photo = fetch_photo_by_id($pdo, $photoId);
    if ($photo === null) {
        flash_set('error', t('flash.not_found'));
        redirect('/?page=entries&mode=nutrition&date=' . rawurlencode(to_date(null)));
    }
    if ((int) ($photo['has_photo'] ?? 0) !== 1 || trim((string) ($photo['file_path'] ?? '')) === '') {
        redirect('/?page=entries&mode=nutrition&date=' . rawurlencode((string) ($photo['log_date'] ?? to_date(null))));
    }

    $photoOwnerId = (int) ($photo['user_id'] ?? 0);
    $photoViewerId = (int) $currentUser['id'];
    $photoViewerIsAdmin = is_admin($currentUser);
    if (
        !can_view_user_content($pdo, $photoViewerId, $photoOwnerId, $photoViewerIsAdmin, (string) ($photo['profile_visibility'] ?? 'public'))
        || !can_view_user_data($pdo, $photoViewerId, $photoOwnerId, 'nutrition', $photoViewerIsAdmin, $photo)
    ) {
        flash_set('error', t('flash.no_permission'));
        redirect('/?page=gallery&gallery_view=recent');
    }
    $canDeletePhoto = is_admin($currentUser) || $photoOwnerId === (int) $currentUser['id'];
    $canEditPhoto = is_admin($currentUser) || $photoOwnerId === (int) $currentUser['id'];

    if (is_post()) {
        $isSocialCommentFetch = (string) ($_POST['feed_ajax'] ?? '') === '1'
            || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'feed-fetch';
        if (!csrf_verify()) {
            if ($isSocialCommentFetch) {
                http_response_code(403);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode(['ok' => false, 'message' => t('flash.csrf')], JSON_UNESCAPED_UNICODE);
                exit;
            }
            flash_set('error', t('flash.csrf'));
            redirect('/?page=photo&photo_id=' . (int) $photoId);
        }

        $action = (string) ($_POST['action'] ?? '');

        if (in_array($action, ['social_feed_comment', 'social_feed_comment_edit', 'social_feed_comment_delete', 'social_feed_comment_like'], true)) {
            $commentMutation = null;
            $commentError = '';
            try {
                $commentMutation = social_comment_apply_action($pdo, $currentUser, $action, 'photo', $photoId, $_POST);
                if ($action !== 'social_feed_comment_like') {
                    audit_log(
                        $pdo,
                        (int) $currentUser['id'],
                        match ($action) {
                            'social_feed_comment_edit' => 'photo_comment_updated',
                            'social_feed_comment_delete' => 'photo_comment_deleted',
                            default => 'photo_comment_created',
                        },
                        'photo_comment',
                        (string) ($commentMutation['id'] ?? ''),
                        'Photo comment thread updated.'
                    );
                }
            } catch (Throwable $error) {
                $expectedSocialError = $error instanceof InvalidArgumentException || $error instanceof SocialActionException;
                if (!$expectedSocialError) {
                    error_log('Photo social action failed [' . get_debug_type($error) . ']: ' . $error->getMessage());
                }
                $commentError = social_action_public_error($error, t('feed.comment_error'));
            }

            if ($isSocialCommentFetch) {
                $ok = is_array($commentMutation) && $commentError === '';
                if (!$ok) http_response_code(422);
                $commentData = social_comment_response_data(
                    $pdo,
                    $currentUser,
                    'photo',
                    $photoId,
                    '/?page=photo&photo_id=' . $photoId
                );
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode([
                    'ok' => $ok,
                    'comment_count' => (int) ($commentData['comment_count'] ?? 0),
                    'comments_html' => (string) ($commentData['comments_html'] ?? ''),
                    'message' => $ok ? '' : ($commentError !== '' ? $commentError : t('feed.comment_error')),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }

            if ($action !== 'social_feed_comment_like' || !is_array($commentMutation)) {
                flash_set(
                    is_array($commentMutation) ? 'success' : 'error',
                    is_array($commentMutation)
                        ? ($action === 'social_feed_comment_delete' ? t('photo.comment_deleted') : t('photo.comment_added'))
                        : ($commentError !== '' ? $commentError : t('feed.comment_error'))
                );
            }
            redirect('/?page=photo&photo_id=' . $photoId . '#comment-photo-' . (int) ($commentMutation['id'] ?? 0));
        }

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

            redirect('/?page=entries&mode=nutrition&date=' . rawurlencode((string) ($photo['log_date'] ?? to_date(null))));
        }
    }

    $photo = fetch_photo_by_id($pdo, $photoId);
    if ($photo === null) {
        flash_set('error', t('flash.not_found'));
        redirect('/?page=entries&mode=nutrition&date=' . rawurlencode(to_date(null)));
    }

    render_view('photo', [
        'title' => t('photo.title'),
        'currentPage' => 'photo',
        'currentUser' => $currentUser,
        'photo' => $photo,
        'comments' => social_comments_for_entity($pdo, 'photo', $photoId, 250, $photoViewerId),
        'canDeletePhoto' => $canDeletePhoto,
        'canEditPhoto' => $canEditPhoto,
        'config' => $config,
    ]);
}

if ($page === 'social') {
    $socialSection = trim((string) ($_GET['section'] ?? ''));
    if (!in_array($socialSection, ['', 'team', 'community', 'competition'], true)) {
        $socialSection = '';
    }

    if (is_post()) {
        if (!csrf_verify()) {
            flash_set('error', t('flash.csrf'));
            redirect('/?page=social&section=team');
        }
        $socialAction = (string) ($_POST['action'] ?? '');
        if ($socialAction === 'social_team_create') {
            squads_ensure_schema($pdo);
            $newSocialSquadId = squad_create($pdo, (int) $currentUser['id'], (string) ($_POST['name'] ?? ''));
            flash_set($newSocialSquadId > 0 ? 'success' : 'error', $newSocialSquadId > 0 ? t('flash.squad_created') : t('flash.squad_failed'));
        }
        redirect('/?page=social&section=team');
    }

    $socialTeams = [];
    $socialTeamMembers = [];
    $socialTeamMembersByTeam = [];
    $socialFriends = [];
    $socialFriendRequests = [];
    $socialGalleryPreview = [];
    $socialActivity = [];
    $socialDuelsSummary = ['active' => 0, 'pending' => 0, 'total' => 0];
    $socialCompetitionsSummary = ['active' => 0, 'pending' => 0, 'total' => 0];
    $socialCanManageTeam = false;
    $socialManageableTeamId = 0;

    if ($socialSection === '' || $socialSection === 'team') {
        squads_ensure_schema($pdo);
        $socialTeams = list_user_teams($pdo, (int) $currentUser['id']);
        $socialActiveTeamId = (int) ($currentUser['active_team_id'] ?? 0);
        usort($socialTeams, static function (array $left, array $right) use ($socialActiveTeamId): int {
            $leftActive = (int) ($left['id'] ?? 0) === $socialActiveTeamId;
            $rightActive = (int) ($right['id'] ?? 0) === $socialActiveTeamId;
            if ($leftActive !== $rightActive) {
                return $leftActive ? -1 : 1;
            }
            return strcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
        });
        foreach ($socialTeams as $socialTeam) {
            if (can_manage_team($pdo, $currentUser, (int) ($socialTeam['id'] ?? 0))) {
                $socialCanManageTeam = true;
                if ($socialManageableTeamId <= 0) {
                    $socialManageableTeamId = (int) ($socialTeam['id'] ?? 0);
                }
            }
        }
        if ($socialSection === '' && $socialTeams !== []) {
            foreach ($socialTeams as $socialTeam) {
                $socialTeamId = (int) ($socialTeam['id'] ?? 0);
                if ($socialTeamId > 0) {
                    $socialTeamMembersByTeam[$socialTeamId] = list_team_members($pdo, $socialTeamId);
                }
            }
            $socialTeamMembers = (array) ($socialTeamMembersByTeam[(int) ($socialTeams[0]['id'] ?? 0)] ?? []);
        }
    }
    if ($socialSection === '' || $socialSection === 'community') {
        friends_ensure_schema($pdo);
        $socialFriends = friends_list($pdo, (int) $currentUser['id']);
        $socialFriendRequests = friends_incoming($pdo, (int) $currentUser['id']);
        $socialGalleryPreview = fetch_gallery_photos(
            $pdo,
            $socialSection === '' ? 4 : 6,
            0,
            null,
            (int) $currentUser['id'],
            is_admin($currentUser)
        );
    }
    if ($socialSection === '' || $socialSection === 'competition') {
        duels_ensure_schema($pdo);
        squads_ensure_schema($pdo);
        $socialDuelsSummary = duels_summary_for_user($pdo, (int) $currentUser['id']);
        $socialCompetitionsSummary = comp_summary_for_user($pdo, (int) $currentUser['id']);
    }
    if ($socialSection === '') {
        $socialKinds = ['friend_', 'duel_', 'comp_', 'squad_', 'team_goal_'];
        foreach (user_notifications($pdo, (int) $currentUser['id'], 30, true) as $notification) {
            $kind = (string) ($notification['kind'] ?? '');
            $isSocial = false;
            foreach ($socialKinds as $prefix) {
                if (str_starts_with($kind, $prefix)) {
                    $isSocial = true;
                    break;
                }
            }
            if ($isSocial) {
                $socialActivity[] = $notification;
            }
            if (count($socialActivity) >= 4) {
                break;
            }
        }
    }

    render_view('social', [
        'title' => t('social_hub.title'),
        'currentPage' => 'social',
        'currentUser' => $currentUser,
        'socialSection' => $socialSection,
        'socialTeams' => $socialTeams,
        'socialTeamMembers' => $socialTeamMembers,
        'socialTeamMembersByTeam' => $socialTeamMembersByTeam,
        'socialFriends' => $socialFriends,
        'socialFriendRequests' => $socialFriendRequests,
        'socialGalleryPreview' => $socialGalleryPreview,
        'socialActivity' => $socialActivity,
        'socialDuelsSummary' => $socialDuelsSummary,
        'socialCompetitionsSummary' => $socialCompetitionsSummary,
        'socialCanManageTeam' => $socialCanManageTeam,
        'socialManageableTeamId' => $socialManageableTeamId,
        'config' => $config,
    ]);
}

if ($page === 'search') {
    workouts_ensure_schema($pdo);
    $searchQuery = trim((string) ($_GET['q'] ?? ''));
    if (function_exists('mb_substr')) {
        $searchQuery = mb_substr($searchQuery, 0, 80);
    } else {
        $searchQuery = substr($searchQuery, 0, 80);
    }
    $searchUsers = [];
    $searchExercises = [];
    $searchRoutines = [];
    if ($searchQuery !== '') {
        $escapedSearch = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $searchQuery);
        $searchLike = '%' . $escapedSearch . '%';
        $searchUsers = privacy_search_visible_users($pdo, $currentUser, $searchQuery, 12);
        $searchExercises = db_fetch_all(
            $pdo,
            'SELECT * FROM exercise_definitions
             WHERE active = 1
               AND (is_system = 1 OR user_id = :current_user)
               AND (name LIKE :query ESCAPE "\\" OR muscle_group LIKE :query ESCAPE "\\" OR equipment LIKE :query ESCAPE "\\")
             ORDER BY is_system DESC, name COLLATE NOCASE ASC
             LIMIT 16',
            [':query' => $searchLike, ':current_user' => (int) $currentUser['id']]
        );
        foreach ($searchExercises as &$searchExercise) {
            $searchExerciseContent = wk_exercise_content($searchExercise);
            $searchExercise['display_name'] = (string) ($searchExerciseContent['name'] ?? $searchExercise['name'] ?? '');
        }
        unset($searchExercise);
        $searchRoutines = db_fetch_all(
            $pdo,
            'SELECT r.*, (SELECT COUNT(*) FROM routine_exercises re WHERE re.routine_id = r.id) AS exercise_count
             FROM workout_routines r
             WHERE r.user_id = :current_user AND r.is_archived = 0
               AND (r.name LIKE :query ESCAPE "\\" OR r.description LIKE :query ESCAPE "\\")
             ORDER BY r.is_favorite DESC, r.name COLLATE NOCASE ASC
             LIMIT 12',
            [':query' => $searchLike, ':current_user' => (int) $currentUser['id']]
        );
    }

    render_view('search', [
        'title' => t('nav.search'),
        'currentPage' => 'search',
        'currentUser' => $currentUser,
        'searchQuery' => $searchQuery,
        'searchUsers' => $searchUsers,
        'searchExercises' => $searchExercises,
        'searchRoutines' => $searchRoutines,
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
        $galleryRows = fetch_gallery_photos($pdo, $galleryPerPage + 1, $galleryOffset, $galleryUserFilter, (int) $currentUser['id'], is_admin($currentUser));
        $galleryHasMore = count($galleryRows) > $galleryPerPage;
        if ($galleryHasMore) {
            array_pop($galleryRows);
        }
        $galleryPhotos = $galleryRows;
        $galleryNextPage = $galleryHasMore ? $galleryPage + 1 : null;
        if ($galleryOffset > 0) {
            $gallerySeedRows = fetch_gallery_photos($pdo, 1, $galleryOffset - 1, $galleryUserFilter, (int) $currentUser['id'], is_admin($currentUser));
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
    workouts_ensure_schema($pdo);
    $users = list_active_users($pdo);

    $selectedUserId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : (int) $currentUser['id'];

    // Anyone may look at another member's sheet, but only its owner (or an admin)
    // may edit it. The read-only rendering is a courtesy; api_save_row enforces the
    // same rule server-side, so a crafted request cannot write to someone else.
    $canEditSheet = is_admin($currentUser) || $selectedUserId === (int) $currentUser['id'];

    $selectedUser = find_user_by_id($users, $selectedUserId);
    if ($selectedUser === null) {
        $selectedUser = $currentUser;
        $selectedUserId = (int) $selectedUser['id'];
        $canEditSheet = true;
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
        'userRoutines' => wk_routines_for_user($pdo, (int) $selectedUserId, false),
        'habits' => list_habit_definitions($pdo, true),
        // Custom habits are the user-created ones (the seeded challenge habits have
        // no creator). The panel lists these before offering to create another.
        'customHabits' => array_values(array_filter(
            list_habit_definitions($pdo, true),
            static fn(array $habit): bool => (int) ($habit['created_by'] ?? 0) > 0
        )),
        'penaltiesEnabled' => penalties_enabled($pdo),
        'canEditSheet' => $canEditSheet,
        'config' => $config,
    ]);
}

if ($page === 'notifications') {
    $notificationFilter = strtolower(trim((string) ($_GET['filter'] ?? $_POST['notification_filter'] ?? 'all')));
    if (!in_array($notificationFilter, ['all', 'unread', 'action'], true)) {
        $notificationFilter = 'all';
    }
    $notificationsRedirect = '/?page=notifications' . ($notificationFilter !== 'all' ? '&filter=' . rawurlencode($notificationFilter) : '');
    $notificationPreviewAjax = is_post()
        && (string) ($_POST['notification_preview_ajax'] ?? '') === '1'
        && strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    $openNotificationId = isset($_GET['open_notification_id']) ? (int) $_GET['open_notification_id'] : 0;
    if ($openNotificationId > 0) {
        $destination = open_user_notification($pdo, $openNotificationId, (int) $currentUser['id']);
        redirect($destination);
    }

    if (is_post()) {
        if (!csrf_verify()) {
            if ($notificationPreviewAjax) {
                json_response(['ok' => false, 'message' => t('flash.csrf')], 403);
            }
            flash_set('error', t('flash.csrf'));
            redirect($notificationsRedirect);
        }

        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'mark_notification_read') {
            $notificationId = (int) ($_POST['notification_id'] ?? 0);
            mark_user_notification_read($pdo, $notificationId, (int) $currentUser['id']);
            redirect($notificationsRedirect);
        }
        if ($action === 'mark_all_notifications_read') {
            mark_all_user_notifications_read($pdo, (int) $currentUser['id']);
            redirect($notificationsRedirect);
        }
        if ($action === 'delete_notification') {
            $notificationId = (int) ($_POST['notification_id'] ?? 0);
            $deleted = delete_user_notification($pdo, $notificationId, (int) $currentUser['id']);
            if ($notificationPreviewAjax) {
                $unreadCount = user_unread_notifications_count($pdo, (int) $currentUser['id']);
                json_response([
                    'ok' => true,
                    'deleted' => $deleted > 0,
                    'notification_id' => $notificationId,
                    'unread_count' => $unreadCount,
                    'unread_label' => t('notifications.unread_count', ['count' => (string) $unreadCount]),
                    'aria_label' => t('nav.notifications') . ($unreadCount > 0 ? ' (' . $unreadCount . ')' : ''),
                    'empty_label' => t('notifications.empty'),
                ]);
            }
            redirect($notificationsRedirect);
        }
        if ($action === 'delete_read_notifications') {
            delete_user_read_notifications($pdo, (int) $currentUser['id']);
            redirect($notificationsRedirect);
        }
        if ($action === 'delete_all_notifications') {
            delete_all_user_notifications($pdo, (int) $currentUser['id']);
            redirect($notificationsRedirect);
        }
    }

    $selectedNotification = null;
    $selectedNotificationId = isset($_GET['notification_id']) ? (int) $_GET['notification_id'] : 0;
    if ($selectedNotificationId > 0) {
        $selectedNotification = fetch_user_notification($pdo, $selectedNotificationId, (int) $currentUser['id']);
        if (!is_array($selectedNotification)) {
            redirect($notificationsRedirect);
        }
        mark_user_notification_read($pdo, $selectedNotificationId, (int) $currentUser['id']);
        $selectedNotification['is_read'] = 1;
        $selectedNotification['read_at'] = (string) ($selectedNotification['read_at'] ?? '') !== ''
            ? (string) $selectedNotification['read_at']
            : now_iso();
    }

    $notifications = user_notifications($pdo, (int) $currentUser['id'], 200, true);

    render_view('notifications', [
        'title' => t('notifications.title'),
        'currentPage' => 'notifications',
        'currentUser' => $currentUser,
        'notifications' => $notifications,
        'notificationFilter' => $notificationFilter,
        'selectedNotification' => $selectedNotification,
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

if ($page === 'friends') {
    friends_ensure_schema($pdo);
    $meId = (int) $currentUser['id'];

    if (is_post()) {
        if (!csrf_verify()) {
            flash_set('error', t('flash.csrf'));
            redirect('/?page=friends');
        }
        $friendAction = (string) ($_POST['action'] ?? '');
        $friendTargetId = (int) ($_POST['user_id'] ?? 0);
        if ($friendAction === 'friend_request') {
            $friendSent = friends_send_request($pdo, $meId, $friendTargetId);
            flash_set($friendSent ? 'success' : 'error', $friendSent ? t('flash.friend_request_sent') : t('flash.friend_action_failed'));
            redirect('/?page=friends');
        }
        if ($friendAction === 'friend_accept') {
            friends_respond($pdo, $meId, $friendTargetId, true);
            flash_set('success', t('flash.friend_accepted'));
            redirect('/?page=friends');
        }
        if ($friendAction === 'friend_reject') {
            friends_respond($pdo, $meId, $friendTargetId, false);
            flash_set('success', t('flash.friend_rejected'));
            redirect('/?page=friends');
        }
        if ($friendAction === 'friend_remove') {
            friends_remove($pdo, $meId, $friendTargetId);
            flash_set('success', t('flash.friend_removed'));
            redirect('/?page=friends');
        }
        redirect('/?page=friends');
    }

    $friendsList = friends_list($pdo, $meId);
    $friendsIncoming = friends_incoming($pdo, $meId);
    $friendsOutgoing = friends_outgoing($pdo, $meId);
    $friendsAddableCount = friends_addable_count($pdo, $meId);
    $friendsAddable = friends_search_addable_users($pdo, $meId, '', 8);

    // Only offer a page-level back action when this request actually came from
    // another internal screen. This also works with the in-app navigation: its
    // HTML request is made before pushState updates the address bar, so Referer
    // still points at the real source screen.
    $friendsBackUrl = '';
    $friendsBackLabel = '';
    $friendsReferrer = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
    if ($friendsReferrer !== '') {
        $friendsReferrerParts = parse_url($friendsReferrer);
        $friendsKnownHosts = [];
        foreach ([$_SERVER['HTTP_HOST'] ?? '', $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''] as $friendsHostHeader) {
            foreach (explode(',', (string) $friendsHostHeader) as $friendsHostCandidate) {
                $friendsHostCandidate = trim($friendsHostCandidate);
                $friendsNormalizedHost = $friendsHostCandidate !== ''
                    ? strtolower((string) parse_url('http://' . $friendsHostCandidate, PHP_URL_HOST))
                    : '';
                if ($friendsNormalizedHost !== '') {
                    $friendsKnownHosts[$friendsNormalizedHost] = true;
                }
            }
        }

        $friendsReferrerHost = is_array($friendsReferrerParts)
            ? strtolower((string) ($friendsReferrerParts['host'] ?? ''))
            : '';
        if ($friendsReferrerHost !== '' && isset($friendsKnownHosts[$friendsReferrerHost])) {
            $friendsReferrerPath = (string) ($friendsReferrerParts['path'] ?? '/');
            $friendsReferrerQuery = (string) ($friendsReferrerParts['query'] ?? '');
            $friendsReferrerParams = [];
            parse_str($friendsReferrerQuery, $friendsReferrerParams);
            $friendsReferrerPageParam = $friendsReferrerParams['page'] ?? '';
            $friendsReferrerPage = is_string($friendsReferrerPageParam)
                ? trim($friendsReferrerPageParam)
                : '';
            if ($friendsReferrerPage === '') {
                $friendsReferrerPage = trim($friendsReferrerPath, '/');
            }
            if ($friendsReferrerPage === '') {
                $friendsReferrerPage = 'dashboard';
            }

            $friendsBackDestinations = [
                'dashboard' => 'nav.home',
                'workouts' => 'nav.training_short',
                'social' => 'nav.social',
                'profile' => 'nav.profile',
                'settings' => 'nav.settings',
                'team' => 'nav.team',
                'team_settings' => 'nav.team',
                'analytics' => 'nav.analytics',
                'entries' => 'entries.title',
                'gallery' => 'gallery.title',
                'duels' => 'duels.title',
                'competitions' => 'competitions.title',
                'metric' => 'metric.detail_title',
                'notifications' => 'nav.notifications',
                'quests' => 'quests.title',
                'season' => 'season.title',
                'admin' => 'nav.admin',
                'table' => 'nav.training_short',
                'week_editor' => 'nav.home',
            ];
            if ($friendsReferrerPage !== 'friends' && isset($friendsBackDestinations[$friendsReferrerPage])) {
                $friendsBackCandidate = $friendsReferrerPath
                    . ($friendsReferrerQuery !== '' ? '?' . $friendsReferrerQuery : '');
                $friendsBackUrl = safe_redirect_target($friendsBackCandidate);
                $friendsBackLabel = t($friendsBackDestinations[$friendsReferrerPage]);
            }
        }
    }

    $friendsSettings = challenge_settings($pdo, $config);
    $friendsChallengeStart = (string) ($friendsSettings['challenge_start'] ?? to_date(null));
    $friendsChallengeEnd = (string) ($friendsSettings['challenge_end'] ?? to_date(null));

    // Optional side-by-side comparison with one friend.
    $compareId = (int) ($_GET['compare'] ?? 0);
    $friendCompare = null;
    if (
        $compareId > 0
        && friends_status($pdo, $meId, $compareId) === 'friends'
        && can_view_user_content($pdo, $meId, $compareId, is_admin($currentUser))
    ) {
        $compareUser = db_fetch_one($pdo, 'SELECT * FROM users WHERE id = :id AND active = 1', [':id' => $compareId]);
        if ($compareUser !== null) {
            $compareMetrics = compute_challenge_metrics($pdo, [$currentUser, $compareUser], $friendsChallengeStart, $friendsChallengeEnd);
            $compareMetrics = apply_strike_review_overrides_to_metrics($pdo, $compareMetrics);
            $myMetric = null;
            $friendMetric = null;
            foreach ($compareMetrics as $cm) {
                $cmUserId = (int) ($cm['user']['id'] ?? 0);
                if ($cmUserId === $meId) {
                    $myMetric = $cm;
                } elseif ($cmUserId === $compareId) {
                    $friendMetric = $cm;
                }
            }
            $friendCompare = [
                'user' => $compareUser,
                'me' => friends_metric_summary($myMetric),
                'friend' => friends_metric_summary($friendMetric),
            ];
        }
    }

    render_view('friends', [
        'title' => t('friends.title'),
        'currentPage' => 'friends',
        'currentUser' => $currentUser,
        'friendsList' => $friendsList,
        'friendsIncoming' => $friendsIncoming,
        'friendsOutgoing' => $friendsOutgoing,
        'friendsAddable' => $friendsAddable,
        'friendsAddableCount' => $friendsAddableCount,
        'friendCompare' => $friendCompare,
        'friendsBackUrl' => $friendsBackUrl,
        'friendsBackLabel' => $friendsBackLabel,
        'config' => $config,
    ]);
}

if ($page === 'duels') {
    friends_ensure_schema($pdo);
    duels_ensure_schema($pdo);
    workouts_ensure_schema($pdo);
    $meId = (int) $currentUser['id'];

    if (is_post()) {
        if (!csrf_verify()) {
            flash_set('error', t('flash.csrf'));
            redirect('/?page=duels');
        }
        $duelAction = (string) ($_POST['action'] ?? '');
        if ($duelAction === 'duel_create') {
            $ok = duels_create(
                $pdo,
                $meId,
                (int) ($_POST['opponent_id'] ?? 0),
                (string) ($_POST['metric'] ?? ''),
                (int) ($_POST['duration_days'] ?? 7)
            );
            flash_set($ok ? 'success' : 'error', $ok ? t('flash.duel_created') : t('flash.duel_failed'));
            redirect('/?page=duels');
        }
        if ($duelAction === 'duel_accept' || $duelAction === 'duel_decline') {
            duels_respond($pdo, (int) ($_POST['duel_id'] ?? 0), $meId, $duelAction === 'duel_accept');
            flash_set('success', $duelAction === 'duel_accept' ? t('flash.duel_accepted') : t('flash.duel_declined'));
            redirect('/?page=duels');
        }
        if ($duelAction === 'duel_cancel') {
            duels_cancel($pdo, (int) ($_POST['duel_id'] ?? 0), $meId);
            flash_set('success', t('flash.duel_cancelled'));
            redirect('/?page=duels');
        }
        redirect('/?page=duels');
    }

    duels_finalize_due($pdo, $config);

    $duelRows = duels_for_user($pdo, $meId);
    $duelViewModels = [];
    foreach ($duelRows as $duel) {
        $duelStatus = (string) $duel['status'];
        $rangeStart = (string) ($duel['start_date'] ?? to_date(null));
        $rangeEnd = $duelStatus === 'active' ? to_date(null) : (string) ($duel['end_date'] ?? $rangeStart);
        if ($duelStatus === 'active' || $duelStatus === 'completed') {
            $values = duels_values($pdo, $config, (array) $duel, $rangeStart, $rangeEnd);
        } else {
            $values = [
                'challenger' => 0.0,
                'opponent' => 0.0,
                'challenger_user' => db_fetch_one($pdo, 'SELECT * FROM users WHERE id = :id', [':id' => (int) $duel['challenger_id']]),
                'opponent_user' => db_fetch_one($pdo, 'SELECT * FROM users WHERE id = :id', [':id' => (int) $duel['opponent_id']]),
            ];
        }
        $duelViewModels[] = ['duel' => (array) $duel, 'values' => $values];
    }

    render_view('duels', [
        'title' => t('duels.title'),
        'currentPage' => 'duels',
        'currentUser' => $currentUser,
        'duels' => $duelViewModels,
        'duelFriends' => friends_list($pdo, $meId),
        'duelMetrics' => duels_metrics(),
        'config' => $config,
    ]);
}

if ($page === 'competitions') {
    friends_ensure_schema($pdo);
    squads_ensure_schema($pdo);
    workouts_ensure_schema($pdo);
    $meId = (int) $currentUser['id'];

    if (is_post()) {
        if (!csrf_verify()) {
            flash_set('error', t('flash.csrf'));
            redirect('/?page=competitions');
        }
        $compAction = (string) ($_POST['action'] ?? '');
        if ($compAction === 'squad_create') {
            $newSquadId = squad_create($pdo, $meId, (string) ($_POST['name'] ?? ''));
            flash_set($newSquadId > 0 ? 'success' : 'error', $newSquadId > 0 ? t('flash.squad_created') : t('flash.squad_failed'));
            redirect('/?page=competitions');
        }
        if ($compAction === 'squad_add_member') {
            $ok = squad_add_member($pdo, (int) ($_POST['squad_id'] ?? 0), $meId, (int) ($_POST['user_id'] ?? 0));
            flash_set($ok ? 'success' : 'error', $ok ? t('flash.squad_member_added') : t('flash.squad_failed'));
            redirect('/?page=competitions');
        }
        if ($compAction === 'squad_remove_member') {
            squad_remove_member($pdo, (int) ($_POST['squad_id'] ?? 0), $meId, (int) ($_POST['user_id'] ?? 0));
            flash_set('success', t('flash.squad_member_removed'));
            redirect('/?page=competitions');
        }
        if ($compAction === 'comp_create') {
            $ok = comp_create(
                $pdo,
                (int) ($_POST['challenger_squad_id'] ?? 0),
                (int) ($_POST['opponent_squad_id'] ?? 0),
                $meId,
                (string) ($_POST['metric'] ?? ''),
                (int) ($_POST['duration_days'] ?? 7)
            );
            flash_set($ok ? 'success' : 'error', $ok ? t('flash.comp_created') : t('flash.comp_failed'));
            redirect('/?page=competitions');
        }
        if ($compAction === 'comp_accept' || $compAction === 'comp_decline') {
            comp_respond($pdo, (int) ($_POST['comp_id'] ?? 0), $meId, $compAction === 'comp_accept');
            flash_set('success', $compAction === 'comp_accept' ? t('flash.comp_accepted') : t('flash.comp_declined'));
            redirect('/?page=competitions');
        }
        if ($compAction === 'comp_cancel') {
            comp_cancel($pdo, (int) ($_POST['comp_id'] ?? 0), $meId);
            flash_set('success', t('flash.comp_cancelled'));
            redirect('/?page=competitions');
        }
        redirect('/?page=competitions');
    }

    comp_finalize_due($pdo, $config);

    $mySquads = squads_owned($pdo, $meId);
    $mySquadViews = [];
    foreach ($mySquads as $squad) {
        $mySquadViews[] = [
            'squad' => $squad,
            'members' => squad_member_users($pdo, (int) $squad['id']),
            'member_ids' => squad_member_ids($pdo, (int) $squad['id']),
        ];
    }
    $selectedSquadId = (int) ($_GET['squad_id'] ?? 0);
    $ownedSquadIds = array_map(static fn(array $squad): int => (int) ($squad['id'] ?? 0), $mySquads);
    if (!in_array($selectedSquadId, $ownedSquadIds, true)) {
        $selectedSquadId = count($ownedSquadIds) === 1 ? (int) $ownedSquadIds[0] : 0;
    }

    $compRows = comp_for_user($pdo, $meId);
    $compViews = [];
    foreach ($compRows as $comp) {
        $compViews[] = ['comp' => (array) $comp, 'standing' => comp_standing($pdo, $config, (array) $comp)];
    }

    render_view('competitions', [
        'title' => t('competitions.title'),
        'currentPage' => 'competitions',
        'currentUser' => $currentUser,
        'mySquads' => $mySquadViews,
        'competitions' => $compViews,
        'compFriends' => friends_list($pdo, $meId),
        'challengeableSquads' => squads_challengeable($pdo, $meId),
        'compMetrics' => duels_metrics(),
        'selectedSquadId' => $selectedSquadId,
        'participatingSquadIds' => squad_ids_for_user($pdo, $meId),
        'config' => $config,
    ]);
}

if ($page === 'workouts') {
    workouts_ensure_schema($pdo);
    $meId = (int) $currentUser['id'];

    if (is_post()) {
        if (!csrf_verify()) {
            flash_set('error', t('flash.csrf'));
            redirect('/?page=workouts');
        }
        $wkAction = (string) ($_POST['action'] ?? '');
        $backRoutine = (int) ($_POST['routine_id'] ?? 0);
        $routineUrl = $backRoutine > 0 ? '/?page=workouts&routine_id=' . $backRoutine : '/?page=workouts';
        $returnSessionExerciseId = max(0, (int) ($_POST['return_session_exercise_id'] ?? 0));
        $sessionReturnUrl = static function (int $sessionId, int $sessionExerciseId = 0): string {
            $query = ['page' => 'workouts', 'session_id' => $sessionId];
            if ($sessionExerciseId > 0) {
                $query['session_exercise_id'] = $sessionExerciseId;
            }

            return '/?' . http_build_query($query);
        };
        $persistSessionDraft = static function () use ($pdo, $meId): void {
            $draftSessionId = max(0, (int) ($_POST['session_id'] ?? 0));
            $draftSets = is_array($_POST['draft_sets'] ?? null) ? $_POST['draft_sets'] : [];
            if ($draftSessionId > 0 && $draftSets !== []) {
                wk_session_update_draft_sets($pdo, $draftSessionId, $meId, $draftSets);
            }
        };
        $routineMediaPayload = static function (array $existing = []) use ($config, $meId): array {
            $imagePath = trim((string) ($existing['image_path'] ?? ''));
            if (!empty($_POST['remove_routine_image'])) {
                $imagePath = '';
            }
            $imageUpload = (array) ($_FILES['routine_image'] ?? []);
            if ((int) ($imageUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $imagePath = save_uploaded_image(
                    $config,
                    $imageUpload,
                    'workouts/routines/user_' . $meId,
                    'routine'
                );
            }

            return [
                'image_path' => $imagePath,
                'video_url' => (string) ($_POST['video_url'] ?? ''),
                'cover_mode' => (string) ($_POST['cover_mode'] ?? 'auto'),
                'image_position' => (string) ($_POST['image_position'] ?? ($existing['image_position'] ?? 'center')),
            ];
        };

        switch ($wkAction) {
            case 'session_delete':
                $deleteSessionId = max(0, (int) ($_POST['session_id'] ?? 0));
                try {
                    $sessionDeleted = wk_session_delete($pdo, $deleteSessionId, $meId, false);
                    flash_set(
                        $sessionDeleted ? 'success' : 'error',
                        $sessionDeleted ? t('workouts.session_deleted') : t('workouts.session_delete_failed')
                    );
                } catch (Throwable $exception) {
                    flash_set('error', t('workouts.session_delete_failed'));
                }
                redirect('/?page=workouts&view=stats');

            case 'session_share':
                $shareSessionId = max(0, (int) ($_POST['session_id'] ?? 0));
                $shareSession = $shareSessionId > 0 ? wk_session_get($pdo, $shareSessionId, $meId) : null;
                if ($shareSession === null || (string) ($shareSession['status'] ?? '') !== 'completed') {
                    flash_set('error', t('workouts.share_invalid'));
                    redirect('/?page=workouts&view=stats');
                }
                $acceptedFriends = friends_list($pdo, $meId);
                $acceptedFriendIds = array_fill_keys(array_map(
                    static fn(array $friend): int => (int) ($friend['id'] ?? 0),
                    $acceptedFriends
                ), true);
                $recipientIds = array_values(array_unique(array_filter(
                    array_map('intval', (array) ($_POST['friend_ids'] ?? [])),
                    static fn(int $friendId): bool => $friendId > 0 && isset($acceptedFriendIds[$friendId])
                )));
                $recipientIds = array_slice($recipientIds, 0, 50);
                if ($recipientIds === []) {
                    flash_set('error', t('workouts.share_choose_friend'));
                    redirect('/?page=workouts&view=stats&detail_session=' . $shareSessionId);
                }
                $shareExercises = wk_session_exercises($pdo, $shareSessionId);
                $shareVolume = 0.0;
                $shareSets = 0;
                foreach ($shareExercises as $shareExercise) {
                    foreach ((array) ($shareExercise['sets'] ?? []) as $shareSet) {
                        if ((int) ($shareSet['completed'] ?? 0) !== 1) {
                            continue;
                        }
                        $shareVolume += (float) ($shareSet['weight'] ?? 0) * (int) ($shareSet['reps'] ?? 0);
                        $shareSets++;
                    }
                }
                $shareVolumeLabel = number_format($shareVolume, abs($shareVolume - round($shareVolume)) < 0.001 ? 0 : 1, ',', '.') . ' Kg';
                $shareTitle = trim((string) ($shareSession['title'] ?? '')) ?: t('workouts.session');
                $senderName = trim((string) ($currentUser['display_name'] ?? $currentUser['username'] ?? ''));
                foreach ($recipientIds as $recipientId) {
                    upsert_user_notification(
                        $pdo,
                        $recipientId,
                        'workout_share',
                        t('workouts.share_notification_title', ['name' => $senderName]),
                        t('workouts.share_notification_message', [
                            'title' => $shareTitle,
                            'volume' => $shareVolumeLabel,
                            'sets' => $shareSets,
                            'exercises' => count($shareExercises),
                        ]),
                        'workout-share:' . $shareSessionId . ':' . $meId,
                        ['sender_user_id' => $meId, 'session_id' => $shareSessionId]
                    );
                }
                flash_set('success', t('workouts.share_success', ['count' => count($recipientIds)]));
                redirect('/?page=workouts&view=stats&detail_session=' . $shareSessionId);

            case 'library_layout_update':
                $libraryLayout = in_array((string) ($_POST['library_layout'] ?? ''), ['cards', 'compact'], true)
                    ? (string) $_POST['library_layout']
                    : 'cards';
                db_execute(
                    $pdo,
                    'UPDATE users SET workout_library_layout = :layout, updated_at = :updated_at WHERE id = :id',
                    [':layout' => $libraryLayout, ':updated_at' => now_iso(), ':id' => $meId]
                );
                $layoutReturn = ['page' => 'workouts', 'view' => 'library'];
                $layoutTargetRoutineId = max(0, (int) ($_POST['target_routine_id'] ?? 0));
                $layoutTargetSessionId = max(0, (int) ($_POST['target_session_id'] ?? 0));
                if ($layoutTargetRoutineId > 0 && wk_routine_get($pdo, $layoutTargetRoutineId, $meId) !== null) {
                    $layoutReturn['target_routine_id'] = $layoutTargetRoutineId;
                } else {
                    $layoutTargetSession = $layoutTargetSessionId > 0 ? wk_session_get($pdo, $layoutTargetSessionId, $meId) : null;
                    if ($layoutTargetSession !== null && (string) ($layoutTargetSession['status'] ?? '') === 'active') {
                        $layoutReturn['target_session_id'] = $layoutTargetSessionId;
                    }
                }
                $layoutMuscle = (string) ($_POST['muscle'] ?? '');
                if (in_array($layoutMuscle, wk_muscle_groups(), true)) {
                    $layoutReturn['muscle'] = $layoutMuscle;
                }
                $layoutEquipment = (string) ($_POST['equipment'] ?? '');
                if (in_array($layoutEquipment, wk_equipment_options(), true)) {
                    $layoutReturn['equipment'] = $layoutEquipment;
                }
                $layoutScope = (string) ($_POST['scope'] ?? '');
                if (in_array($layoutScope, ['mine', 'favorites'], true)) {
                    $layoutReturn['scope'] = $layoutScope;
                }
                $layoutQuery = trim((string) ($_POST['q'] ?? ''));
                $layoutQuery = function_exists('mb_substr') ? mb_substr($layoutQuery, 0, 80) : substr($layoutQuery, 0, 80);
                if ($layoutQuery !== '') {
                    $layoutReturn['q'] = $layoutQuery;
                }
                $layoutPage = max(1, (int) ($_POST['library_page'] ?? 1));
                if ($layoutPage > 1) {
                    $layoutReturn['library_page'] = $layoutPage;
                }
                redirect('/?' . http_build_query($layoutReturn));
            case 'routine_create':
                try {
                    $routineName = trim((string) ($_POST['name'] ?? ''));
                    if ($routineName === '') {
                        throw new InvalidArgumentException(t('workouts.routine_name_required'));
                    }
                    $routineMedia = $routineMediaPayload();
                    $rid = wk_routine_create(
                        $pdo,
                        $meId,
                        $routineName,
                        (string) ($_POST['icon'] ?? 'dumbbell'),
                        (string) ($_POST['description'] ?? ''),
                        wk_days_mask($_POST['days'] ?? []),
                        (string) ($_POST['accent_color'] ?? '#14b8a6'),
                        (string) ($routineMedia['image_path'] ?? ''),
                        (string) ($routineMedia['video_url'] ?? ''),
                        (string) ($routineMedia['cover_mode'] ?? 'auto'),
                        (string) ($routineMedia['image_position'] ?? 'center')
                    );
                    if ($rid <= 0) {
                        throw new RuntimeException(t('flash.error'));
                    }
                    flash_set('success', t('workouts.routine_media_saved'));
                    if ((string) ($_POST['after_create'] ?? '') === 'add_exercise') {
                        redirect('/?page=workouts&view=library&target_routine_id=' . $rid);
                    }
                    redirect('/?page=workouts&routine_id=' . $rid);
                } catch (Throwable $e) {
                    flash_set('error', $e->getMessage());
                    redirect('/?page=workouts');
                }
            // no break (redirect exits)
            case 'routine_update':
                $existingRoutine = wk_routine_get($pdo, $backRoutine, $meId);
                if ($existingRoutine === null) {
                    flash_set('error', t('flash.error'));
                    redirect('/?page=workouts');
                }
                $postedSettingsView = (string) ($_POST['settings_view'] ?? '');
                $routineSettingsView = in_array($postedSettingsView, ['identity', 'media', 'schedule'], true)
                    ? $postedSettingsView
                    : '';
                $settingsReturnUrl = $routineSettingsView !== ''
                    ? '/?page=workouts&routine_id=' . $backRoutine . '&section=settings&settings_view=' . $routineSettingsView
                    : $routineUrl;
                try {
                    $isLegacyRoutineUpdate = $routineSettingsView === '';
                    $updatesIdentity = $isLegacyRoutineUpdate || $routineSettingsView === 'identity';
                    $updatesMedia = $isLegacyRoutineUpdate || $routineSettingsView === 'media';
                    $updatesSchedule = $isLegacyRoutineUpdate || $routineSettingsView === 'schedule';
                    $routineName = trim((string) ($updatesIdentity ? ($_POST['name'] ?? '') : ($existingRoutine['name'] ?? '')));
                    if ($routineName === '') {
                        throw new InvalidArgumentException(t('workouts.routine_name_required'));
                    }
                    $routineMedia = $updatesMedia ? $routineMediaPayload($existingRoutine) : [
                        'image_path' => (string) ($existingRoutine['image_path'] ?? ''),
                        'video_url' => (string) ($existingRoutine['video_url'] ?? ''),
                        'cover_mode' => (string) ($existingRoutine['cover_mode'] ?? 'auto'),
                        'image_position' => (string) ($existingRoutine['image_position'] ?? 'center'),
                    ];
                    wk_routine_update($pdo, $backRoutine, $meId, [
                        'name' => $routineName,
                        'icon' => (string) ($updatesIdentity ? ($_POST['icon'] ?? 'dumbbell') : ($existingRoutine['icon'] ?? 'dumbbell')),
                        'accent_color' => (string) ($updatesIdentity ? ($_POST['accent_color'] ?? '#14b8a6') : ($existingRoutine['accent_color'] ?? '#14b8a6')),
                        'description' => (string) ($updatesIdentity ? ($_POST['description'] ?? '') : ($existingRoutine['description'] ?? '')),
                        'recommended_days_mask' => $updatesSchedule
                            ? wk_days_mask($_POST['days'] ?? [])
                            : (string) ($existingRoutine['recommended_days_mask'] ?? '0000000'),
                        'image_path' => (string) ($routineMedia['image_path'] ?? ''),
                        'video_url' => (string) ($routineMedia['video_url'] ?? ''),
                        'cover_mode' => (string) ($routineMedia['cover_mode'] ?? 'auto'),
                        'image_position' => (string) ($routineMedia['image_position'] ?? 'center'),
                    ]);
                    flash_set('success', t('workouts.routine_settings_saved'));
                    redirect($settingsReturnUrl);
                } catch (Throwable $e) {
                    flash_set('error', $e->getMessage());
                    redirect($settingsReturnUrl !== $routineUrl
                        ? $settingsReturnUrl
                        : '/?page=workouts&routine_id=' . $backRoutine . '&section=settings');
                }
            case 'routine_favorite':
                wk_routine_set_flag($pdo, $backRoutine, $meId, 'is_favorite', (int) ($_POST['value'] ?? 0));
                redirect($routineUrl);
            case 'routine_archive':
                wk_routine_set_flag($pdo, $backRoutine, $meId, 'is_archived', (int) ($_POST['value'] ?? 1));
                redirect('/?page=workouts');
            case 'routine_delete':
                wk_routine_delete($pdo, $backRoutine, $meId);
                flash_set('success', t('flash.saved'));
                redirect('/?page=workouts');
            case 'routine_duplicate':
                $dup = wk_routine_duplicate($pdo, $backRoutine, $meId);
                redirect($dup > 0 ? '/?page=workouts&routine_id=' . $dup : '/?page=workouts');
            case 'routine_copy_friend':
                $copySourceUser = max(0, (int) ($_POST['source_user_id'] ?? 0));
                $copySourceRoutine = max(0, (int) ($_POST['source_routine_id'] ?? 0));
                if ($copySourceUser > 0 && $copySourceRoutine > 0 && friends_status($pdo, $meId, $copySourceUser) === 'friends') {
                    $copiedRoutineId = wk_routine_copy_from_user($pdo, $copySourceRoutine, $copySourceUser, $meId);
                    if ($copiedRoutineId > 0) {
                        flash_set('success', t('workouts.routine_copied'));
                        redirect('/?page=workouts&routine_id=' . $copiedRoutineId);
                    }
                }
                flash_set('error', t('workouts.routine_copy_failed'));
                redirect($copySourceUser > 0 ? '/?page=profile&user_id=' . $copySourceUser : '/?page=workouts');
            case 'routine_copy_friend_exercises':
                $copySourceUser = max(0, (int) ($_POST['source_user_id'] ?? 0));
                $copySourceRoutine = max(0, (int) ($_POST['source_routine_id'] ?? 0));
                $copyTargetRoutine = max(0, (int) ($_POST['target_routine_id'] ?? 0));
                $copyExerciseRows = (array) ($_POST['routine_exercise_ids'] ?? []);
                try {
                    if ($copySourceUser <= 0
                        || $copySourceRoutine <= 0
                        || $copyTargetRoutine <= 0
                        || friends_status($pdo, $meId, $copySourceUser) !== 'friends') {
                        throw new RuntimeException(t('workouts.routine_copy_failed'));
                    }
                    $addedExercises = wk_routine_copy_exercises_from_user(
                        $pdo,
                        $copySourceRoutine,
                        $copySourceUser,
                        $copyTargetRoutine,
                        $meId,
                        $copyExerciseRows
                    );
                    if ($addedExercises <= 0) {
                        throw new RuntimeException(t('workouts.exercises_already_added'));
                    }
                    flash_set('success', t('workouts.friend_exercises_copied', ['count' => $addedExercises]));
                    redirect('/?page=workouts&routine_id=' . $copyTargetRoutine);
                } catch (Throwable $e) {
                    flash_set('error', $e->getMessage());
                    redirect('/?page=workouts&view=friends#friend-routine-' . $copySourceRoutine);
                }
            case 'routine_reorder':
                $ids = array_map('intval', (array) ($_POST['order'] ?? []));
                wk_routine_reorder($pdo, $meId, $ids);
                flash_set('success', t('workouts.routine_order_saved'));
                redirect((string) ($_POST['return_to'] ?? '') === 'organize' ? '/?page=workouts&view=organize' : '/?page=workouts');
            case 'exercise_create':
                $exId = wk_exercise_create($pdo, $meId, (string) ($_POST['name'] ?? ''), (string) ($_POST['muscle_group'] ?? ''), (string) ($_POST['exercise_type'] ?? 'strength'), (string) ($_POST['equipment'] ?? ''));
                if ($exId > 0 && $backRoutine > 0 && wk_routine_get($pdo, $backRoutine, $meId) !== null) {
                    wk_routine_add_exercise($pdo, $backRoutine, $exId);
                }
                redirect($routineUrl);
            case 'routine_exercise_personalize':
                $personalizeRoutineExerciseId = max(0, (int) ($_POST['routine_exercise_id'] ?? 0));
                $personalizeRoutine = wk_routine_get($pdo, $backRoutine, $meId);
                $personalizeRow = $personalizeRoutine !== null
                    ? wk_routine_exercise_get($pdo, $personalizeRoutineExerciseId, $backRoutine, $meId)
                    : null;
                if ($personalizeRow === null) {
                    flash_set('error', t('workouts.exercise_personalize_failed'));
                    redirect('/?page=workouts');
                }
                try {
                    $personalExerciseId = wk_user_clone_exercise(
                        $pdo,
                        (int) ($personalizeRow['exercise_def_id'] ?? 0),
                        $meId
                    );
                    if (
                        !wk_routine_exercise_replace_definition(
                            $pdo,
                            $personalizeRoutineExerciseId,
                            $backRoutine,
                            $meId,
                            $personalExerciseId
                        )
                    ) {
                        throw new RuntimeException(t('workouts.exercise_personalize_failed'));
                    }
                    flash_set('success', t('workouts.exercise_personalized'));
                    redirect('/?' . http_build_query([
                        'page' => 'workouts',
                        'view' => 'library',
                        'custom_exercise' => $personalExerciseId,
                        'target_routine_id' => $backRoutine,
                        'target_routine_exercise_id' => $personalizeRoutineExerciseId,
                        'editor_section' => 'media',
                    ]));
                } catch (Throwable $e) {
                    flash_set('error', $e->getMessage());
                    redirect('/?page=workouts&routine_id=' . $backRoutine . '&routine_exercise_id=' . $personalizeRoutineExerciseId);
                }
            case 'custom_exercise_save':
                $customExerciseId = max(0, (int) ($_POST['exercise_id'] ?? 0));
                $customTargetRoutineId = max(0, (int) ($_POST['target_routine_id'] ?? 0));
                $customTargetRoutineExerciseId = max(0, (int) ($_POST['target_routine_exercise_id'] ?? 0));
                $customTargetSessionId = max(0, (int) ($_POST['target_session_id'] ?? 0));
                $customEditorSection = in_array((string) ($_POST['editor_section'] ?? ''), ['basics', 'guide', 'media'], true)
                    ? (string) $_POST['editor_section']
                    : 'basics';
                if ($customTargetRoutineId > 0 && wk_routine_get($pdo, $customTargetRoutineId, $meId) === null) {
                    $customTargetRoutineId = 0;
                }
                $customTargetRoutineExercise = $customTargetRoutineId > 0 && $customTargetRoutineExerciseId > 0
                    ? wk_routine_exercise_get($pdo, $customTargetRoutineExerciseId, $customTargetRoutineId, $meId)
                    : null;
                if ($customTargetRoutineExercise === null) {
                    $customTargetRoutineExerciseId = 0;
                }
                $customTargetSession = $customTargetSessionId > 0 ? wk_session_get($pdo, $customTargetSessionId, $meId) : null;
                if ($customTargetSession === null || (string) ($customTargetSession['status'] ?? '') !== 'active') {
                    $customTargetSessionId = 0;
                } else {
                    $customTargetRoutineId = 0;
                }
                $newMediaPaths = [];
                $mediaTransaction = false;
                try {
                    $ownedExercise = $customExerciseId > 0 ? wk_user_exercise_get($pdo, $customExerciseId, $meId) : null;
                    if ($customExerciseId > 0 && $ownedExercise === null) {
                        throw new InvalidArgumentException(t('workouts.custom_not_found'));
                    }
                    $existingMedia = $ownedExercise !== null ? wk_exercise_media_list($pdo, $ownedExercise) : [];
                    $oldMediaPaths = array_values(array_filter(array_map(static fn(array $item): string => trim((string) ($item['path'] ?? '')), $existingMedia)));
                    $galleryEditorSubmitted = !empty($_POST['gallery_editor']);
                    $submittedOrder = $galleryEditorSubmitted
                        ? array_values((array) ($_POST['gallery_order'] ?? []))
                        : $oldMediaPaths;
                    $submittedPositions = $galleryEditorSubmitted
                        ? array_values((array) ($_POST['gallery_position'] ?? []))
                        : array_map(static fn(array $item): string => (string) ($item['position'] ?? 'center'), $existingMedia);
                    $submittedCaptions = $galleryEditorSubmitted
                        ? array_values((array) ($_POST['gallery_caption'] ?? []))
                        : array_map(static fn(array $item): string => (string) ($item['caption'] ?? ''), $existingMedia);
                    $rawCoverToken = $_POST['gallery_cover'] ?? ($ownedExercise['image_path'] ?? '');
                    $coverToken = is_scalar($rawCoverToken) ? trim((string) $rawCoverToken) : '';
                    if (!$galleryEditorSubmitted && isset($_POST['image_position'])) {
                        $legacyCoverIndex = array_search($coverToken, $submittedOrder, true);
                        if (is_int($legacyCoverIndex)) {
                            $submittedPositions[$legacyCoverIndex] = (string) $_POST['image_position'];
                        }
                    }
                    if (!empty($_POST['remove_image'])) {
                        $submittedOrder = [];
                        $submittedPositions = [];
                        $submittedCaptions = [];
                        $coverToken = '';
                    }

                    $legacyUploads = normalize_uploaded_file_list((array) ($_FILES['exercise_image'] ?? []));
                    $galleryUploads = normalize_uploaded_file_list((array) ($_FILES['exercise_images'] ?? []));
                    if ($legacyUploads !== []) {
                        $galleryUploads = [$legacyUploads[0]];
                        $existingMedia = [];
                        $submittedOrder = ['new:0'];
                        $submittedPositions = [(string) ($_POST['image_position'] ?? 'center')];
                        $submittedCaptions = [''];
                        $coverToken = 'new:0';
                    }
                    if (count($galleryUploads) > 4) {
                        throw new InvalidArgumentException(t('workouts.gallery_limit'));
                    }
                    foreach ($galleryUploads as $galleryUpload) {
                        $newMediaPaths[] = save_uploaded_image(
                            $config,
                            $galleryUpload,
                            'workouts/exercises/user_' . $meId,
                            'exercise'
                        );
                    }
                    $mediaSubmission = wk_exercise_media_resolve_submission(
                        $existingMedia,
                        $submittedOrder,
                        $newMediaPaths,
                        $coverToken,
                        $_POST['image_position'] ?? ($ownedExercise['image_position'] ?? 'center'),
                        $submittedPositions,
                        $submittedCaptions
                    );
                    $payload = $_POST;
                    $payload['image_path'] = $mediaSubmission['cover_path'];
                    $payload['image_position'] = $mediaSubmission['cover_position'];
                    if (!$pdo->inTransaction()) {
                        $pdo->beginTransaction();
                        $mediaTransaction = true;
                    }
                    $savedExerciseId = wk_user_save_exercise(
                        $pdo,
                        $meId,
                        $customExerciseId > 0 ? $customExerciseId : null,
                        $payload
                    );
                    if ($customTargetRoutineExerciseId > 0) {
                        wk_routine_exercise_replace_definition(
                            $pdo,
                            $customTargetRoutineExerciseId,
                            $customTargetRoutineId,
                            $meId,
                            $savedExerciseId
                        );
                    } elseif ($customTargetSessionId > 0) {
                        wk_session_add_exercise($pdo, $customTargetSessionId, $savedExerciseId, $meId);
                    } elseif ($customTargetRoutineId > 0) {
                        wk_routine_add_exercise($pdo, $customTargetRoutineId, $savedExerciseId);
                    }
                    wk_exercise_media_replace($pdo, $savedExerciseId, $mediaSubmission['items']);
                    if ($mediaTransaction && $pdo->inTransaction()) {
                        $pdo->commit();
                        $mediaTransaction = false;
                    }
                    $keptMediaPaths = array_map(static fn(array $item): string => (string) $item['path'], $mediaSubmission['items']);
                    wk_exercise_media_cleanup_unreferenced(
                        $pdo,
                        $config,
                        array_values(array_diff(array_merge($oldMediaPaths, $newMediaPaths), $keptMediaPaths))
                    );
                    flash_set('success', t('workouts.custom_saved'));
                    if ($customTargetRoutineExerciseId > 0) {
                        redirect('/?page=workouts&routine_id=' . $customTargetRoutineId . '&routine_exercise_id=' . $customTargetRoutineExerciseId);
                    }
                    if ($customTargetSessionId > 0) {
                        redirect('/?page=workouts&session_id=' . $customTargetSessionId);
                    }
                    if ($customTargetRoutineId > 0) {
                        redirect('/?page=workouts&routine_id=' . $customTargetRoutineId);
                    }
                    redirect('/?page=workouts&exercise_id=' . $savedExerciseId . '&scope=mine');
                } catch (Throwable $e) {
                    if ($mediaTransaction && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    if ($newMediaPaths !== []) {
                        wk_exercise_media_cleanup_unreferenced($pdo, $config, $newMediaPaths);
                    }
                    flash_set('error', $e->getMessage());
                    $customReturn = [
                        'page' => 'workouts',
                        'view' => 'library',
                        'custom_exercise' => $customExerciseId > 0 ? $customExerciseId : 'new',
                        'editor_section' => $customEditorSection,
                    ];
                    if ($customTargetRoutineId > 0) {
                        $customReturn['target_routine_id'] = $customTargetRoutineId;
                        if ($customTargetRoutineExerciseId > 0) {
                            $customReturn['target_routine_exercise_id'] = $customTargetRoutineExerciseId;
                        }
                    } elseif ($customTargetSessionId > 0) {
                        $customReturn['target_session_id'] = $customTargetSessionId;
                    }
                    redirect('/?' . http_build_query($customReturn));
                }
            case 'custom_exercise_delete':
                $customExerciseId = max(0, (int) ($_POST['exercise_id'] ?? 0));
                $customExerciseBeforeDelete = wk_user_exercise_get($pdo, $customExerciseId, $meId);
                $customExerciseMediaPaths = $customExerciseBeforeDelete !== null
                    ? array_map(static fn(array $item): string => (string) ($item['path'] ?? ''), wk_exercise_media_list($pdo, $customExerciseBeforeDelete))
                    : [];
                if (!wk_user_delete_exercise($pdo, $customExerciseId, $meId)) {
                    flash_set('error', t('workouts.custom_not_found'));
                } else {
                    wk_exercise_media_cleanup_unreferenced($pdo, $config, $customExerciseMediaPaths);
                    flash_set('success', t('workouts.custom_deleted'));
                }
                redirect('/?page=workouts&view=library&scope=mine');
            case 'exercise_clone':
                $cloneTargetRoutineId = max(0, (int) ($_POST['target_routine_id'] ?? 0));
                $cloneTargetSessionId = max(0, (int) ($_POST['target_session_id'] ?? 0));
                if ($cloneTargetRoutineId > 0 && wk_routine_get($pdo, $cloneTargetRoutineId, $meId) === null) {
                    $cloneTargetRoutineId = 0;
                }
                $cloneTargetSession = $cloneTargetSessionId > 0 ? wk_session_get($pdo, $cloneTargetSessionId, $meId) : null;
                if ($cloneTargetSession === null || (string) ($cloneTargetSession['status'] ?? '') !== 'active') {
                    $cloneTargetSessionId = 0;
                } else {
                    $cloneTargetRoutineId = 0;
                }
                try {
                    $copyId = wk_user_clone_exercise($pdo, max(0, (int) ($_POST['exercise_id'] ?? 0)), $meId);
                    flash_set('success', t('workouts.personal_copy_created'));
                    $cloneReturn = ['page' => 'workouts', 'view' => 'library', 'custom_exercise' => $copyId];
                    if ($cloneTargetRoutineId > 0) {
                        $cloneReturn['target_routine_id'] = $cloneTargetRoutineId;
                    } elseif ($cloneTargetSessionId > 0) {
                        $cloneReturn['target_session_id'] = $cloneTargetSessionId;
                    }
                    redirect('/?' . http_build_query($cloneReturn));
                } catch (Throwable $e) {
                    flash_set('error', $e->getMessage());
                    $cloneReturn = ['page' => 'workouts', 'view' => 'library'];
                    if ($cloneTargetRoutineId > 0) {
                        $cloneReturn['target_routine_id'] = $cloneTargetRoutineId;
                    } elseif ($cloneTargetSessionId > 0) {
                        $cloneReturn['target_session_id'] = $cloneTargetSessionId;
                    }
                    redirect('/?' . http_build_query($cloneReturn));
                }
            case 'exercise_favorite':
                $favoriteExerciseId = max(0, (int) ($_POST['exercise_id'] ?? 0));
                wk_exercise_set_favorite($pdo, $favoriteExerciseId, $meId, (string) ($_POST['value'] ?? '0') === '1');
                $favoriteTargetRoutineId = max(0, (int) ($_POST['target_routine_id'] ?? 0));
                $favoriteTargetSessionId = max(0, (int) ($_POST['target_session_id'] ?? 0));
                if ($favoriteTargetRoutineId > 0 && wk_routine_get($pdo, $favoriteTargetRoutineId, $meId) === null) {
                    $favoriteTargetRoutineId = 0;
                }
                $favoriteTargetSession = $favoriteTargetSessionId > 0 ? wk_session_get($pdo, $favoriteTargetSessionId, $meId) : null;
                if ($favoriteTargetSession === null || (string) ($favoriteTargetSession['status'] ?? '') !== 'active') {
                    $favoriteTargetSessionId = 0;
                } else {
                    $favoriteTargetRoutineId = 0;
                }
                if ((string) ($_POST['return_to'] ?? '') === 'exercise') {
                    $favoriteGuideReturn = ['page' => 'workouts', 'exercise_id' => $favoriteExerciseId];
                    if ($favoriteTargetRoutineId > 0) {
                        $favoriteGuideReturn['target_routine_id'] = $favoriteTargetRoutineId;
                    } elseif ($favoriteTargetSessionId > 0) {
                        $favoriteGuideReturn['target_session_id'] = $favoriteTargetSessionId;
                    }
                    redirect('/?' . http_build_query($favoriteGuideReturn));
                }
                $favoriteReturn = ['page' => 'workouts', 'view' => 'library'];
                if ($favoriteTargetRoutineId > 0) {
                    $favoriteReturn['target_routine_id'] = $favoriteTargetRoutineId;
                } elseif ($favoriteTargetSessionId > 0) {
                    $favoriteReturn['target_session_id'] = $favoriteTargetSessionId;
                }
                foreach (['muscle', 'equipment', 'context', 'q'] as $filterKey) {
                    $filterValue = trim((string) ($_POST[$filterKey] ?? ''));
                    if ($filterValue !== '') {
                        $favoriteReturn[$filterKey] = $filterValue;
                    }
                }
                $favoriteScope = trim((string) ($_POST['scope'] ?? ''));
                if (in_array($favoriteScope, ['mine', 'favorites'], true)) {
                    $favoriteReturn['scope'] = $favoriteScope;
                }
                $favoritePage = max(1, (int) ($_POST['library_page'] ?? 1));
                if ($favoritePage > 1) {
                    $favoriteReturn['library_page'] = $favoritePage;
                }
                redirect('/?' . http_build_query($favoriteReturn));
            case 'exercise_favorites_reorder':
                wk_exercise_favorites_reorder($pdo, $meId, (array) ($_POST['order'] ?? []));
                flash_set('success', t('workouts.favorite_order_saved'));
                redirect('/?page=workouts&view=library&scope=favorites&library_mode=organize');
            case 'routine_add_exercise':
                $exerciseToAdd = (int) ($_POST['exercise_def_id'] ?? 0);
                if (wk_routine_get($pdo, $backRoutine, $meId) !== null && wk_exercise_get_for_user($pdo, $exerciseToAdd, $meId) !== null) {
                    $routineTargets = [];
                    foreach (['target_sets', 'target_reps', 'target_weight', 'target_distance', 'rest_seconds', 'unit', 'notes'] as $targetKey) {
                        if (array_key_exists($targetKey, $_POST)) {
                            $routineTargets[$targetKey] = $_POST[$targetKey];
                        }
                    }
                    if (trim((string) ($_POST['target_duration_minutes'] ?? '')) !== '') {
                        $routineTargets['target_duration'] = (int) round(max(0.0, (float) $_POST['target_duration_minutes']) * 60);
                    } elseif (trim((string) ($_POST['target_duration_seconds'] ?? '')) !== '') {
                        $routineTargets['target_duration'] = max(0, (int) $_POST['target_duration_seconds']);
                    }
                    wk_routine_add_exercise($pdo, $backRoutine, $exerciseToAdd, $routineTargets);
                }
                redirect($routineUrl);
            case 'library_add_exercise':
            case 'exercise_add_to_routine':
            case 'exercise_add_to_session':
                $targetRoutine = (int) ($_POST['routine_id'] ?? 0);
                $addedRoutineId = 0;
                $contextRoutine = max(0, (int) ($_POST['target_routine_id'] ?? 0));
                $contextSession = max(0, (int) ($_POST['target_session_id'] ?? 0));
                if ($wkAction === 'exercise_add_to_session' && $contextSession <= 0) {
                    $contextSession = max(0, (int) ($_POST['session_id'] ?? 0));
                }
                if ($contextRoutine > 0 && wk_routine_get($pdo, $contextRoutine, $meId) === null) {
                    $contextRoutine = 0;
                }
                $contextSessionRow = $contextSession > 0 ? wk_session_get($pdo, $contextSession, $meId) : null;
                if ($contextSessionRow === null || (string) ($contextSessionRow['status'] ?? '') !== 'active') {
                    $contextSession = 0;
                } else {
                    $contextRoutine = 0;
                }
                $targetExercise = (int) ($_POST['exercise_def_id'] ?? 0);
                if ($contextSession > 0 && $wkAction !== 'exercise_add_to_routine') {
                    if (wk_session_add_exercise($pdo, $contextSession, $targetExercise, $meId) > 0) {
                        flash_set('success', t('workouts.exercise_added_to_session'));
                    }
                } elseif (wk_routine_get($pdo, $targetRoutine, $meId) !== null && wk_exercise_get_for_user($pdo, $targetExercise, $meId) !== null) {
                    $libraryTargets = [];
                    foreach (['target_sets', 'target_reps', 'target_weight', 'target_distance', 'rest_seconds', 'unit', 'notes'] as $targetKey) {
                        if (array_key_exists($targetKey, $_POST)) {
                            $libraryTargets[$targetKey] = $_POST[$targetKey];
                        }
                    }
                    if (trim((string) ($_POST['target_duration_minutes'] ?? '')) !== '') {
                        $libraryTargets['target_duration'] = (int) round(max(0.0, (float) $_POST['target_duration_minutes']) * 60);
                    } elseif (trim((string) ($_POST['target_duration_seconds'] ?? '')) !== '') {
                        $libraryTargets['target_duration'] = max(0, (int) $_POST['target_duration_seconds']);
                    }
                    if (wk_routine_add_exercise($pdo, $targetRoutine, $targetExercise, $libraryTargets) > 0) {
                        $addedRoutineId = $targetRoutine;
                        if ($wkAction !== 'library_add_exercise' || $contextRoutine > 0) {
                            flash_set('success', t('workouts.exercise_added'));
                        }
                    }
                }
                if ($wkAction === 'exercise_add_to_routine') {
                    $exerciseReturn = ['page' => 'workouts', 'exercise_id' => $targetExercise];
                    if ($contextRoutine > 0) {
                        $exerciseReturn['target_routine_id'] = $contextRoutine;
                    }
                    redirect('/?' . http_build_query($exerciseReturn));
                }
                if ($wkAction === 'exercise_add_to_session') {
                    $exerciseReturn = ['page' => 'workouts', 'exercise_id' => $targetExercise];
                    if ($contextSession > 0) {
                        $exerciseReturn['target_session_id'] = $contextSession;
                    }
                    redirect('/?' . http_build_query($exerciseReturn));
                }
                $libraryQuery = ['page' => 'workouts', 'view' => 'library'];
                if ($contextRoutine > 0) {
                    $libraryQuery['target_routine_id'] = $contextRoutine;
                } elseif ($contextSession > 0) {
                    $libraryQuery['target_session_id'] = $contextSession;
                }
                foreach (['muscle', 'equipment', 'context', 'q', 'scope'] as $filterKey) {
                    $filterValue = trim((string) ($_POST[$filterKey] ?? ''));
                    if ($filterValue !== '') {
                        $libraryQuery[$filterKey] = $filterValue;
                    }
                }
                $libraryPage = max(1, (int) ($_POST['library_page'] ?? 1));
                if ($libraryPage > 1) {
                    $libraryQuery['library_page'] = $libraryPage;
                }
                if ($addedRoutineId > 0 && $contextRoutine === 0) {
                    $libraryQuery['added_routine_id'] = $addedRoutineId;
                }
                redirect('/?' . http_build_query($libraryQuery));
            case 'routine_remove_exercise':
                wk_routine_remove_exercise($pdo, (int) ($_POST['routine_exercise_id'] ?? 0), $backRoutine, $meId);
                redirect($routineUrl);
            case 'routine_exercises_reorder':
                $orderSaved = wk_routine_exercises_reorder(
                    $pdo,
                    $backRoutine,
                    $meId,
                    is_array($_POST['order'] ?? null) ? $_POST['order'] : []
                );
                flash_set($orderSaved ? 'success' : 'error', $orderSaved ? t('workouts.exercise_order_saved') : t('flash.error'));
                redirect($routineUrl);
            case 'routine_exercise_update':
                $targetDuration = '';
                if (trim((string) ($_POST['target_duration_minutes'] ?? '')) !== '') {
                    $targetDuration = (int) round(max(0.0, (float) $_POST['target_duration_minutes']) * 60);
                } elseif (trim((string) ($_POST['target_duration_seconds'] ?? '')) !== '') {
                    $targetDuration = max(0, (int) $_POST['target_duration_seconds']);
                }
                $routineExerciseUpdated = wk_routine_exercise_update(
                    $pdo,
                    max(0, (int) ($_POST['routine_exercise_id'] ?? 0)),
                    $backRoutine,
                    $meId,
                    [
                        'target_sets' => $_POST['target_sets'] ?? 3,
                        'target_reps' => $_POST['target_reps'] ?? '',
                        'target_weight' => $_POST['target_weight'] ?? '',
                        'target_duration' => $targetDuration,
                        'target_distance' => $_POST['target_distance'] ?? '',
                        'rest_seconds' => $_POST['rest_seconds'] ?? '',
                        'unit' => $_POST['unit'] ?? 'kg',
                        'notes' => $_POST['notes'] ?? '',
                    ]
                );
                flash_set($routineExerciseUpdated ? 'success' : 'error', $routineExerciseUpdated ? t('workouts.exercise_settings_saved') : t('flash.error'));
                redirect($routineUrl);
            case 'plan_preset_create':
                $createdRoutines = wk_create_plan_from_preset($pdo, $meId, (string) ($_POST['preset'] ?? ''));
                flash_set($createdRoutines !== [] ? 'success' : 'error', $createdRoutines !== [] ? t('workouts.plan_created') : t('flash.error'));
                redirect('/?page=workouts&view=plan');
            case 'session_start':
                $startRoutine = (int) ($_POST['routine_id'] ?? 0);
                if ((string) ($_POST['replace_active'] ?? '') === '1') {
                    $activeToReplace = wk_session_active_for_user($pdo, $meId);
                    if ($activeToReplace !== null) {
                        wk_session_finish($pdo, (int) $activeToReplace['id'], $meId);
                    }
                }
                $sid = wk_session_start($pdo, $meId, $startRoutine > 0 ? $startRoutine : null, (string) ($_POST['title'] ?? ''));
                $startedSessionExercises = wk_session_exercises($pdo, $sid);
                $startedSessionExerciseId = (int) ($startedSessionExercises[0]['id'] ?? 0);
                redirect($sessionReturnUrl($sid, $startedSessionExerciseId));
            case 'session_add_set':
                $persistSessionDraft();
                wk_set_add($pdo, (int) ($_POST['session_exercise_id'] ?? 0), $meId);
                redirect($sessionReturnUrl((int) ($_POST['session_id'] ?? 0), $returnSessionExerciseId));
            case 'session_update_rest':
                $restExerciseId = (int) ($_POST['session_exercise_id'] ?? 0);
                $restSecondsValue = max(0, min(3600, (int) ($_POST['rest_seconds'] ?? 0)));
                $restSaved = wk_session_exercise_set_rest($pdo, $restExerciseId, $restSecondsValue, $meId);
                if ((string) ($_POST['async'] ?? '') === '1') {
                    json_response(['ok' => $restSaved, 'rest_seconds' => $restSecondsValue], $restSaved ? 200 : 422);
                }
                redirect($sessionReturnUrl((int) ($_POST['session_id'] ?? 0), $returnSessionExerciseId));
            case 'session_continue':
                $continueSessionId = max(0, (int) ($_POST['session_id'] ?? 0));
                $continueSessionExerciseId = max(0, (int) ($_POST['current_session_exercise_id'] ?? 0));
                $persistSessionDraft();
                wk_session_complete_exercise_sets($pdo, $continueSessionId, $continueSessionExerciseId, $meId);
                redirect($sessionReturnUrl(
                    $continueSessionId,
                    max(0, (int) ($_POST['next_session_exercise_id'] ?? 0))
                ));
            case 'session_update_set':
                $persistSessionDraft();
                $setDuration = '';
                if (trim((string) ($_POST['duration_minutes'] ?? '')) !== '') {
                    $setDuration = (int) round(max(0.0, wk_decimal_value($_POST['duration_minutes']) ?? 0.0) * 60);
                } elseif (trim((string) ($_POST['duration_seconds'] ?? '')) !== '') {
                    $setDuration = max(0, (int) $_POST['duration_seconds']);
                }
                wk_set_update($pdo, (int) ($_POST['set_id'] ?? 0), [
                    'reps' => $_POST['reps'] ?? '',
                    'weight' => $_POST['weight'] ?? '',
                    'duration' => $setDuration,
                    'distance' => $_POST['distance'] ?? '',
                    'completed' => (int) ($_POST['completed'] ?? 0),
                ], $meId);
                redirect($sessionReturnUrl((int) ($_POST['session_id'] ?? 0), $returnSessionExerciseId));
            case 'session_delete_set':
                $persistSessionDraft();
                $setDeleted = wk_set_delete_for_user($pdo, (int) ($_POST['set_id'] ?? 0), $meId);
                if (!$setDeleted) {
                    flash_set('error', t('workouts.keep_one_set'));
                }
                redirect($sessionReturnUrl((int) ($_POST['session_id'] ?? 0), $returnSessionExerciseId));
            case 'session_add_exercise':
                $addedSessionExerciseId = wk_session_add_exercise($pdo, (int) ($_POST['session_id'] ?? 0), (int) ($_POST['exercise_def_id'] ?? 0), $meId);
                redirect($sessionReturnUrl((int) ($_POST['session_id'] ?? 0), $addedSessionExerciseId > 0 ? $addedSessionExerciseId : $returnSessionExerciseId));
            case 'session_replace_exercise':
                $replaceSessionId = max(0, (int) ($_POST['session_id'] ?? 0));
                $replaceSessionExerciseId = max(0, (int) ($_POST['session_exercise_id'] ?? 0));
                $replacementExerciseId = max(0, (int) ($_POST['replacement_exercise_def_id'] ?? 0));
                $replacedSessionExerciseId = wk_session_replace_exercise(
                    $pdo,
                    $replaceSessionExerciseId,
                    $replacementExerciseId,
                    $meId
                );
                $replaceUrl = $sessionReturnUrl(
                    $replaceSessionId,
                    $replacedSessionExerciseId > 0 ? $replacedSessionExerciseId : $replaceSessionExerciseId
                );
                if ((string) ($_POST['async'] ?? '') === '1') {
                    json_response([
                        'ok' => $replacedSessionExerciseId > 0,
                        'message' => t($replacedSessionExerciseId > 0 ? 'workouts.exercise_replaced' : 'workouts.exercise_replace_failed'),
                        'redirect_url' => $replaceUrl,
                    ], $replacedSessionExerciseId > 0 ? 200 : 422);
                }
                flash_set(
                    $replacedSessionExerciseId > 0 ? 'success' : 'error',
                    t($replacedSessionExerciseId > 0 ? 'workouts.exercise_replaced' : 'workouts.exercise_replace_failed')
                );
                redirect($replaceUrl);
            case 'session_exercises_organize':
                $organizeSessionId = max(0, (int) ($_POST['session_id'] ?? 0));
                $sessionOrderSaved = wk_session_exercises_organize(
                    $pdo,
                    $organizeSessionId,
                    $meId,
                    is_array($_POST['order'] ?? null) ? $_POST['order'] : [],
                    is_array($_POST['remove'] ?? null) ? $_POST['remove'] : []
                );
                flash_set($sessionOrderSaved ? 'success' : 'error', $sessionOrderSaved ? t('workouts.session_order_saved') : t('flash.error'));
                redirect($sessionReturnUrl($organizeSessionId));
            case 'session_finish':
                $persistSessionDraft();
                $finishSessionId = max(0, (int) ($_POST['session_id'] ?? 0));
                $finishCountsTowardChallenge = (string) ($_POST['count_challenge'] ?? '0') === '1';
                $finishMode = (string) ($_POST['finish_mode'] ?? 'current');
                $sessionFinished = $finishMode === 'previous'
                    ? wk_session_finish_with_previous_sets($pdo, $finishSessionId, $meId, $finishCountsTowardChallenge)
                    : wk_session_finish($pdo, $finishSessionId, $meId, $finishCountsTowardChallenge);
                flash_set($sessionFinished ? 'success' : 'error', $sessionFinished
                    ? t('flash.workout_saved')
                    : t($finishMode === 'previous' ? 'workouts.finish_previous_unavailable' : 'flash.error'));
                if (!$sessionFinished) {
                    redirect($sessionReturnUrl($finishSessionId));
                }
                redirect('/?page=workouts');
            case 'session_cancel':
                $sessionCancelled = wk_session_cancel($pdo, (int) ($_POST['session_id'] ?? 0), $meId);
                flash_set($sessionCancelled ? 'success' : 'error', $sessionCancelled ? t('workouts.session_cancelled') : t('flash.error'));
                redirect('/?page=workouts');
            default:
                redirect('/?page=workouts');
        }
    }

    $routineId = isset($_GET['routine_id']) ? (int) $_GET['routine_id'] : 0;
    $routineExerciseId = max(0, (int) ($_GET['routine_exercise_id'] ?? 0));
    $sessionId = isset($_GET['session_id']) ? (int) $_GET['session_id'] : 0;
    $sessionExerciseId = max(0, (int) ($_GET['session_exercise_id'] ?? 0));
    $exerciseId = isset($_GET['exercise_id']) ? (int) $_GET['exercise_id'] : 0;
    $targetRoutineId = max(0, (int) ($_GET['target_routine_id'] ?? 0));
    $targetRoutineExerciseId = max(0, (int) ($_GET['target_routine_exercise_id'] ?? 0));
    $targetSessionId = max(0, (int) ($_GET['target_session_id'] ?? 0));
    $customEditorSection = in_array((string) ($_GET['editor_section'] ?? ''), ['basics', 'guide', 'media'], true)
        ? (string) $_GET['editor_section']
        : ($targetRoutineExerciseId > 0 ? 'media' : 'basics');
    $routineSection = in_array((string) ($_GET['section'] ?? 'overview'), ['overview', 'settings', 'organize'], true)
        ? (string) ($_GET['section'] ?? 'overview')
        : 'overview';
    $routineSettingsView = in_array((string) ($_GET['settings_view'] ?? 'identity'), ['identity', 'media', 'schedule', 'management'], true)
        ? (string) ($_GET['settings_view'] ?? 'identity')
        : 'identity';
    $sessionSection = (string) ($_GET['section'] ?? '') === 'organize' ? 'organize' : 'workout';
    $customExerciseParam = trim((string) ($_GET['custom_exercise'] ?? ''));
    $requestedWorkoutView = (string) ($_GET['view'] ?? 'overview');
    if (!in_array($requestedWorkoutView, ['overview', 'plan', 'library', 'friends', 'ranks', 'stats', 'organize'], true)) {
        $requestedWorkoutView = 'overview';
    }
    $wkView = $requestedWorkoutView === 'overview' ? 'list' : $requestedWorkoutView;
    $wkRoutine = null;
    $wkRoutineExercises = [];
    $wkRoutineExercise = null;
    $wkSession = null;
    $wkSessionExercises = [];
    $wkSessionExerciseMedia = [];
    $wkSessionPreviousSets = [];
    $wkSessionExerciseId = 0;
    $wkExercise = null;
    $wkExerciseMedia = [];
    $wkCustomExercise = null;
    $wkCustomExerciseMedia = [];
    $wkExerciseRank = null;
    $wkTargetRoutine = null;
    $wkTargetRoutineExercise = null;
    $wkTargetRoutineExerciseIds = [];
    $wkTargetSession = null;
    $wkTargetSessionExerciseIds = [];
    $wkLibrary = [];
    $wkLibraryExerciseMedia = [];
    $wkLibraryPage = max(1, (int) ($_GET['library_page'] ?? 1));
    $wkLibraryPerPage = 12;
    $wkLibraryTotal = 0;
    $wkLibraryQuery = trim((string) ($_GET['q'] ?? ''));
    $wkLibraryQuery = function_exists('mb_substr') ? mb_substr($wkLibraryQuery, 0, 80) : substr($wkLibraryQuery, 0, 80);
    // The muscle filter accepts several body parts at once. It arrives either as
    // muscle[]=chest&muscle[]=back (checkbox form submit) or muscle=chest,back
    // (bookmarked/shared link), and is normalized back into one comma-joined
    // string so the rest of the request (URLs, hidden inputs, wk_exercise_library)
    // keeps treating $filters['muscle'] as a single scalar value.
    $muscleParam = $_GET['muscle'] ?? '';
    $muscleCandidates = is_array($muscleParam)
        ? $muscleParam
        : (trim((string) $muscleParam) !== '' ? explode(',', (string) $muscleParam) : []);
    $muscleSelected = array_values(array_unique(array_filter(
        array_map(static fn($m): string => trim((string) $m), $muscleCandidates),
        static fn(string $m): bool => in_array($m, wk_muscle_groups(), true)
    )));
    $wkLibraryFilters = [
        'q' => $wkLibraryQuery,
        'muscle' => implode(',', $muscleSelected),
        'equipment' => in_array((string) ($_GET['equipment'] ?? ''), wk_equipment_options(), true) ? (string) $_GET['equipment'] : '',
        'context' => in_array((string) ($_GET['context'] ?? ''), wk_context_options(), true) ? (string) $_GET['context'] : '',
        'scope' => in_array((string) ($_GET['scope'] ?? ''), ['mine', 'favorites'], true) ? (string) $_GET['scope'] : '',
    ];
    $wkLibraryMode = (string) ($_GET['library_mode'] ?? '') === 'organize' && $wkLibraryFilters['scope'] === 'favorites'
        ? 'organize'
        : 'browse';
    $wkLibraryLayout = in_array((string) ($currentUser['workout_library_layout'] ?? 'cards'), ['cards', 'compact'], true)
        ? (string) $currentUser['workout_library_layout']
        : 'cards';
    $wkExerciseRanks = [];
    $wkMuscleRanks = [];
    $wkOverallRank = null;
    $wkRankLeaderboard = [];
    $wkRankProfile = [];
    $wkRankDivision = in_array((string) ($_GET['rank_division'] ?? 'open'), ['open', 'women', 'men'], true)
        ? (string) ($_GET['rank_division'] ?? 'open')
        : 'open';
    $wkStats = null;

    if ($targetRoutineId > 0) {
        $wkTargetRoutine = wk_routine_get($pdo, $targetRoutineId, $meId);
        if ($wkTargetRoutine === null) {
            $targetRoutineId = 0;
            $targetRoutineExerciseId = 0;
        } else {
            $wkTargetRoutineExerciseIds = array_values(array_unique(array_map(
                static fn(array $exercise): int => (int) ($exercise['exercise_def_id'] ?? 0),
                wk_routine_exercises($pdo, $targetRoutineId)
            )));
            if ($targetRoutineExerciseId > 0) {
                $wkTargetRoutineExercise = wk_routine_exercise_get(
                    $pdo,
                    $targetRoutineExerciseId,
                    $targetRoutineId,
                    $meId
                );
                if ($wkTargetRoutineExercise === null) {
                    $targetRoutineExerciseId = 0;
                }
            }
        }
    }

    if ($targetSessionId > 0) {
        $wkTargetSession = wk_session_get($pdo, $targetSessionId, $meId);
        if ($wkTargetSession === null || (string) ($wkTargetSession['status'] ?? '') !== 'active') {
            $targetSessionId = 0;
            $wkTargetSession = null;
        } else {
            // A live workout is the most immediate context. Do not let a crafted
            // URL ambiguously add the same exercise to a routine as well.
            $targetRoutineId = 0;
            $targetRoutineExerciseId = 0;
            $wkTargetRoutine = null;
            $wkTargetRoutineExercise = null;
            $wkTargetRoutineExerciseIds = [];
            $wkTargetSessionExerciseIds = array_values(array_unique(array_map(
                static fn(array $exercise): int => (int) ($exercise['exercise_def_id'] ?? 0),
                wk_session_exercises($pdo, $targetSessionId)
            )));
        }
    }

    if ($customExerciseParam !== '') {
        if ($customExerciseParam === 'new') {
            $wkCustomExercise = [];
        } else {
            $customExerciseId = max(0, (int) $customExerciseParam);
            $wkCustomExercise = wk_user_exercise_get($pdo, $customExerciseId, $meId);
            if ($wkCustomExercise === null) {
                flash_set('error', t('workouts.custom_not_found'));
                $customMissingReturn = ['page' => 'workouts', 'view' => 'library', 'scope' => 'mine'];
                if ($targetRoutineId > 0) {
                    $customMissingReturn['target_routine_id'] = $targetRoutineId;
                } elseif ($targetSessionId > 0) {
                    $customMissingReturn['target_session_id'] = $targetSessionId;
                }
                redirect('/?' . http_build_query($customMissingReturn));
            }
            if (
                $wkTargetRoutineExercise !== null
                && (int) ($wkTargetRoutineExercise['exercise_def_id'] ?? 0) !== (int) ($wkCustomExercise['id'] ?? 0)
            ) {
                $targetRoutineExerciseId = 0;
                $wkTargetRoutineExercise = null;
            }
        }
        if ((int) ($wkCustomExercise['id'] ?? 0) > 0) {
            $wkCustomExerciseMedia = wk_exercise_media_list($pdo, $wkCustomExercise);
        }
        $wkView = 'custom_exercise';
    } elseif ($sessionId > 0) {
        $wkSession = wk_session_get($pdo, $sessionId, $meId);
        if ($wkSession === null) {
            redirect('/?page=workouts');
        }
        $wkView = 'session';
        $wkSessionExercises = wk_session_exercises($pdo, $sessionId);
        $wkSessionExerciseMedia = wk_exercise_media_map($pdo, array_map(
            static fn(array $exercise): array => array_merge($exercise, ['id' => (int) ($exercise['exercise_def_id'] ?? 0)]),
            $wkSessionExercises
        ));
        $wkSessionPreviousSets = wk_last_completed_sets_for_exercises(
            $pdo,
            $meId,
            array_map(
                static fn(array $exercise): int => (int) ($exercise['exercise_def_id'] ?? 0),
                $wkSessionExercises
            ),
            $sessionId
        );
        $validSessionExerciseIds = array_map(
            static fn(array $exercise): int => (int) ($exercise['id'] ?? 0),
            $wkSessionExercises
        );
        if ($sessionExerciseId > 0 && in_array($sessionExerciseId, $validSessionExerciseIds, true)) {
            $wkSessionExerciseId = $sessionExerciseId;
        } else {
            $wkSessionExerciseId = (int) ($validSessionExerciseIds[0] ?? 0);
            foreach ($wkSessionExercises as $candidateExercise) {
                $candidateSets = (array) ($candidateExercise['sets'] ?? []);
                $hasIncompleteSet = $candidateSets === [] || count(array_filter(
                    $candidateSets,
                    static fn(array $set): bool => (int) ($set['completed'] ?? 0) !== 1
                )) > 0;
                if ($hasIncompleteSet) {
                    $wkSessionExerciseId = (int) ($candidateExercise['id'] ?? 0);
                    break;
                }
            }
        }
    } elseif ($routineId > 0) {
        $wkRoutine = wk_routine_get($pdo, $routineId, $meId);
        if ($wkRoutine === null) {
            redirect('/?page=workouts');
        }
        if ($routineExerciseId > 0) {
            $wkRoutineExercise = wk_routine_exercise_get($pdo, $routineExerciseId, $routineId, $meId);
            if ($wkRoutineExercise === null) {
                redirect('/?page=workouts&routine_id=' . $routineId);
            }
            $wkView = 'routine_exercise';
        } else {
            $wkView = 'routine';
            $wkRoutineExercises = wk_routine_exercises($pdo, $routineId);
        }
    } elseif ($exerciseId > 0) {
        $wkExercise = wk_exercise_get_for_user($pdo, $exerciseId, $meId);
        if ($wkExercise === null) {
            redirect('/?page=workouts&view=library');
        }
        $wkView = 'exercise';
        $wkExerciseMedia = wk_exercise_media_list($pdo, $wkExercise);
        $wkExerciseRanks = wk_exercise_ranks_for_user($pdo, $meId);
        foreach ($wkExerciseRanks as $rankedExercise) {
            if ((int) $rankedExercise['id'] === $exerciseId) {
                $wkExerciseRank = (array) ($rankedExercise['rank'] ?? []);
                break;
            }
        }
    } elseif ($requestedWorkoutView === 'stats') {
        $wkView = 'analytics';
        // Read-only detail sub-page for a completed session (distinct from the
        // editable session focus mode reached via session_id).
        $statsDetailSessionId = max(0, (int) ($_GET['detail_session'] ?? 0));
        $wkStatsSession = null;
        $wkStatsSessionExercises = [];
        $wkStatsSessionExerciseMedia = [];
        if ($statsDetailSessionId > 0) {
            $wkStatsSession = wk_session_get($pdo, $statsDetailSessionId, $meId);
            if ($wkStatsSession !== null) {
                $wkStatsSessionExercises = wk_session_exercises($pdo, $statsDetailSessionId);
                $wkStatsSessionExerciseMedia = wk_exercise_media_map($pdo, array_map(
                    static fn(array $exercise): array => array_merge($exercise, ['id' => (int) ($exercise['exercise_def_id'] ?? 0)]),
                    $wkStatsSessionExercises
                ));
                $wkStatsSessionShareToken = wk_session_share_token($pdo, $statsDetailSessionId, $meId);
                $wkStatsSessionShareUrl = rtrim(request_app_base_url(), '/') . '/?page=shared_workout&token=' . urlencode($wkStatsSessionShareToken);
            }
        }
        // Per-exercise stats sub-page (volume / est. 1RM over time).
        $statsExerciseId = max(0, (int) ($_GET['exercise_stats'] ?? 0));
        $wkStatsExercise = null;
        $wkStatsExerciseHistory = [];
        if ($statsExerciseId > 0) {
            $wkStatsExercise = wk_exercise_get_for_user($pdo, $statsExerciseId, $meId);
            if ($wkStatsExercise !== null) {
                $wkStatsExerciseHistory = wk_exercise_history($pdo, $meId, $statsExerciseId, 30);
            }
        }
        // Month-over-month volume: previous month = [prevStart, thisStart).
        $statsMonthNowStart = (new DateTimeImmutable('first day of this month'))->format('Y-m-d 00:00:00');
        $statsMonthPrevStart = (new DateTimeImmutable('first day of last month'))->format('Y-m-d 00:00:00');
        $statsMonthNowVol = (float) wk_summary_for_user($pdo, $meId, $statsMonthNowStart)['volume'];
        $statsMonthPrevVol = max(0.0, (float) wk_summary_for_user($pdo, $meId, $statsMonthPrevStart)['volume'] - $statsMonthNowVol);
        $wkStatsCompare = ['month_now' => $statsMonthNowVol, 'month_prev' => $statsMonthPrevVol];
        $wkStats = [
            'weekly' => wk_weekly_series($pdo, $meId, 8),
            'streak' => wk_streak_days($pdo, $meId),
            'frequent' => wk_frequent_exercises($pdo, $meId, 6),
            'muscles' => wk_muscle_distribution($pdo, $meId),
            'messages' => wk_motivational_messages($pdo, $meId),
        ];
    } elseif ($requestedWorkoutView === 'library') {
        if ($targetRoutineId > 0 || $targetSessionId > 0) {
            $wkLibraryMode = 'browse';
        }
        $wkLibraryAll = wk_exercise_library($pdo, $meId, $wkLibraryFilters);
        $wkLibraryTotal = count($wkLibraryAll);
        if ($wkLibraryMode === 'organize') {
            $wkLibraryPage = 1;
            $wkLibrary = $wkLibraryAll;
        } else {
            $wkExerciseRanks = wk_exercise_ranks_for_user($pdo, $meId);
            $rankByExercise = [];
            foreach ($wkExerciseRanks as $rankedExercise) {
                $rankByExercise[(int) $rankedExercise['id']] = (array) ($rankedExercise['rank'] ?? []);
            }
            $wkLibraryPages = max(1, (int) ceil($wkLibraryTotal / $wkLibraryPerPage));
            $wkLibraryPage = min($wkLibraryPage, $wkLibraryPages);
            $wkLibrary = array_slice($wkLibraryAll, ($wkLibraryPage - 1) * $wkLibraryPerPage, $wkLibraryPerPage);
            foreach ($wkLibrary as &$libraryExercise) {
                $libraryExercise['rank'] = $rankByExercise[(int) $libraryExercise['id']] ?? wk_rank_from_score(0.0);
            }
            unset($libraryExercise);
        }
        $wkLibraryExerciseMedia = wk_exercise_media_map($pdo, $wkLibrary);
    } elseif ($requestedWorkoutView === 'ranks') {
        $wkExerciseRanks = wk_exercise_ranks_for_user($pdo, $meId);
        $wkMuscleRanks = wk_muscle_ranks_for_user($pdo, $meId);
        $wkOverallRank = wk_overall_rank_for_user($pdo, $meId);
        $wkRankProfile = wk_user_rank_profile($pdo, $meId);
        $wkRankLeaderboard = wk_rank_leaderboard($pdo, 20, $wkRankDivision);
        // Record today's rank so the history accrues whenever ranks are viewed.
        wk_capture_rank_snapshots($pdo, $meId, $wkMuscleRanks, $wkOverallRank);
        // Body-zone rank detail: what contributes + volume/rank history for one muscle.
        $wkRankZone = trim((string) ($_GET['zone'] ?? ''));
        $wkRankZoneWeekly = [];
        $wkRankZoneHistory = [];
        if ($wkRankZone !== '') {
            $wkRankZoneWeekly = wk_muscle_weekly_volume($pdo, $meId, $wkRankZone, 8);
            $wkRankZoneHistory = wk_rank_snapshot_history($pdo, $meId, 'muscle', $wkRankZone, 30);
        }
    }

    // Friends' routines you can browse and copy, surfaced on the training overview.
    $wkFriendRoutines = [];
    if (in_array($wkView, ['list', 'friends'], true)) {
        friends_ensure_schema($pdo);
        $wkFriendRoutines = wk_friends_routines($pdo, $meId);
    }

    $sinceMonth = (new DateTimeImmutable('first day of this month'))->format('Y-m-d 00:00:00');
    $wkActiveSessionContext = wk_active_session_context($pdo, $meId, true);
    $wkActiveSession = $wkActiveSessionContext['session'];
    $wkActiveSessionSummary = $wkActiveSessionContext['summary'];
    $wkAllRoutines = wk_routines_for_user($pdo, $meId, true);
    $wkRoutineExerciseNamePreviews = $wkView === 'list'
        ? wk_routine_exercise_name_previews($pdo, array_column($wkAllRoutines, 'id'))
        : [];

    render_view('workouts', [
        'title' => t('nav.table'),
        'currentPage' => 'workouts',
        'currentUser' => $currentUser,
        'wkView' => $wkView,
        'wkStats' => $wkStats,
        'wkStatsSession' => $wkStatsSession ?? null,
        'wkStatsSessionExercises' => $wkStatsSessionExercises ?? [],
        'wkStatsSessionExerciseMedia' => $wkStatsSessionExerciseMedia ?? [],
        'wkStatsSessionShareUrl' => $wkStatsSessionShareUrl ?? '',
        'wkShareFriends' => friends_list($pdo, $meId),
        'wkStatsExercise' => $wkStatsExercise ?? null,
        'wkStatsExerciseHistory' => $wkStatsExerciseHistory ?? [],
        'wkFriendRoutines' => $wkFriendRoutines ?? [],
        'wkStatsCompare' => $wkStatsCompare ?? null,
        'wkRoutines' => $wkAllRoutines,
        'wkRoutineExerciseNamePreviews' => $wkRoutineExerciseNamePreviews,
        'wkRoutine' => $wkRoutine,
        'wkRoutineExercises' => $wkRoutineExercises,
        'wkRoutineExercise' => $wkRoutineExercise,
        'wkSession' => $wkSession,
        'wkSessionExercises' => $wkSessionExercises,
        'wkSessionExerciseMedia' => $wkSessionExerciseMedia,
        'wkSessionPreviousSets' => $wkSessionPreviousSets,
        'wkSessionExerciseId' => $wkSessionExerciseId,
        'wkSessionSection' => $sessionSection,
        'wkExercise' => $wkExercise,
        'wkExerciseMedia' => $wkExerciseMedia,
        'wkCustomExercise' => $wkCustomExercise,
        'wkCustomExerciseMedia' => $wkCustomExerciseMedia,
        'wkCustomEditorSection' => $customEditorSection,
        'wkMediaSearchEnabled' => media_search_enabled($pdo),
        'wkExerciseRank' => $wkExerciseRank,
        'wkTargetRoutine' => $wkTargetRoutine,
        'wkTargetRoutineExercise' => $wkTargetRoutineExercise,
        'wkTargetRoutineExerciseIds' => $wkTargetRoutineExerciseIds,
        'wkTargetSession' => $wkTargetSession,
        'wkTargetSessionExerciseIds' => $wkTargetSessionExerciseIds,
        'wkRoutineSection' => $routineSection,
        'wkRoutineSettingsView' => $routineSettingsView,
        'wkLibrary' => $wkLibrary,
        'wkLibraryExerciseMedia' => $wkLibraryExerciseMedia,
        'wkLibraryPage' => $wkLibraryPage,
        'wkLibraryPerPage' => $wkLibraryPerPage,
        'wkLibraryTotal' => $wkLibraryTotal,
        'wkLibraryFilters' => $wkLibraryFilters,
        'wkLibraryMode' => $wkLibraryMode,
        'wkLibraryLayout' => $wkLibraryLayout,
        'wkMuscleGroups' => wk_muscle_groups(),
        'wkEquipmentOptions' => wk_equipment_options(),
        'wkContextOptions' => wk_context_options(),
        'wkExerciseRanks' => $wkExerciseRanks,
        'wkMuscleRanks' => $wkMuscleRanks,
        'wkRankZone' => $wkRankZone ?? '',
        'wkRankZoneWeekly' => $wkRankZoneWeekly ?? [],
        'wkRankZoneHistory' => $wkRankZoneHistory ?? [],
        'wkOverallRank' => $wkOverallRank,
        'wkRankLeaderboard' => $wkRankLeaderboard,
        'wkRankProfile' => $wkRankProfile,
        'wkRankDivision' => $wkRankDivision,
        'wkRoutinesByDay' => wk_routines_by_day($pdo, $meId),
        'wkPlanPresets' => wk_builtin_plan_presets(),
        'wkExercises' => wk_exercises_for_user($pdo, $meId),
        'wkActiveSession' => $wkActiveSession,
        'wkActiveSessionSummary' => $wkActiveSessionSummary,
        'wkRecentSessions' => wk_sessions_for_user($pdo, $meId, 8),
        'wkPersonalRecords' => wk_personal_records_for_user($pdo, $meId, 8),
        'immersiveMobile' => $wkView === 'custom_exercise'
            || ($wkView === 'session' && (string) (($wkSession['status'] ?? '')) === 'active'),
        'wkSummaryMonth' => wk_summary_for_user($pdo, $meId, $sinceMonth),
        'wkSummaryAll' => wk_summary_for_user($pdo, $meId, null),
        'config' => $config,
    ]);
}

if ($page === 'settings') {
    $settingsView = (string) ($_GET['view'] ?? '');
    $allowedSettingsViews = ['avatar', 'body', 'goals', 'preferences', 'privacy', 'integrations', 'account'];
    if (!in_array($settingsView, $allowedSettingsViews, true)) {
        $settingsView = '';
    }
    $settingsRedirect = static function (?string $view = null) use ($allowedSettingsViews): string {
        if ($view === null || !in_array($view, $allowedSettingsViews, true)) {
            return '/?page=settings';
        }
        return '/?page=settings&view=' . rawurlencode($view) . ($view === 'avatar' ? '#avatar' : '');
    };

    if (is_post()) {
        if (!csrf_verify()) {
            flash_set('error', t('flash.csrf'));
            redirect($settingsRedirect($settingsView));
        }

        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'restart_onboarding') {
            restart_user_onboarding($pdo, (int) $currentUser['id']);
            flash_set('success', t('onboarding.restarted'));
            redirect('/?page=onboarding');
        }
        if ($action === 'weekly_report_update') {
            db_execute($pdo, 'UPDATE users SET weekly_report_enabled = :enabled, weekly_report_day = :day, weekly_report_time = :time, weekly_report_tz = :tz, updated_at = :now WHERE id = :id', [
                ':enabled' => !empty($_POST['weekly_report_enabled']) ? 1 : 0,
                ':day' => max(1, min(7, (int) ($_POST['weekly_report_day'] ?? 1))),
                ':time' => telegram_normalize_time((string) ($_POST['weekly_report_time'] ?? '09:00')),
                ':tz' => telegram_normalize_tz((string) ($_POST['weekly_report_tz'] ?? '')),
                ':now' => now_iso(),
                ':id' => (int) $currentUser['id'],
            ]);
            flash_set('success', t('flash.saved'));
            redirect($settingsRedirect('integrations'));
        }
        if ($action === 'change_password') {
            $currentPassword = (string) ($_POST['current_password'] ?? '');
            $newPassword = (string) ($_POST['new_password'] ?? '');
            $newPasswordConfirm = (string) ($_POST['new_password_confirm'] ?? '');
            if ($newPassword !== $newPasswordConfirm) {
                flash_set('error', t('flash.password_mismatch'));
                redirect($settingsRedirect($settingsView));
            }
            if (strlen($newPassword) < 8) {
                flash_set('error', t('flash.password_short'));
                redirect($settingsRedirect($settingsView));
            }
            if (!change_password($pdo, (int) $currentUser['id'], $currentPassword, $newPassword)) {
                flash_set('error', t('flash.current_password_wrong'));
                redirect($settingsRedirect($settingsView));
            }
            audit_log($pdo, (int) $currentUser['id'], 'password_changed', 'user', (string) $currentUser['id'], 'Password changed.', null, ['password_changed' => true]);
            flash_set('success', t('flash.password_updated'));
            redirect($settingsRedirect($settingsView));
        }
        if ($action === 'create_custom_metric') {
            try {
                $metric = custom_metric_create($pdo, (int) $currentUser['id'], [
                    'name' => trim((string) ($_POST['custom_metric_name'] ?? '')),
                    'unit' => trim((string) ($_POST['custom_metric_unit'] ?? '')),
                    'frequency' => (string) ($_POST['custom_metric_frequency'] ?? 'daily'),
                    'target_value' => $_POST['custom_metric_target'] ?? null,
                    'improvement_direction' => (string) ($_POST['custom_metric_direction'] ?? 'higher'),
                    'color' => (string) ($_POST['custom_metric_color'] ?? '#6d5dfc'),
                    'icon' => 'chart',
                ]);
                $enabledMetrics = metric_enabled_keys($pdo, $currentUser);
                $enabledMetrics[] = custom_metric_key((int) $metric['id']);
                save_user_metric_preferences($pdo, $currentUser, $enabledMetrics);
                flash_set('success', 'Métrica personal creada.');
            } catch (Throwable $exception) {
                flash_set('error', $exception->getMessage());
            }
            redirect($settingsRedirect('preferences'));
        }

        if ($action === 'update_preferences') {
            $before = db_fetch_one($pdo, 'SELECT * FROM users WHERE id = :id', [':id' => (int) $currentUser['id']]);
            $primaryType = in_array(($_POST['primary_goal_type'] ?? 'none'), ['none', 'steps', 'km', 'workouts'], true) ? (string) $_POST['primary_goal_type'] : 'none';
            $primaryValue = ($_POST['primary_goal_value'] ?? '') !== '' && is_numeric($_POST['primary_goal_value'])
                ? max(0.0, (float) $_POST['primary_goal_value'])
                : 0.0;
            if ($primaryType !== 'none' && $primaryValue <= 0) {
                $primaryType = 'none';
            }
            $settingsStepGoal = 0;
            if (trim((string) ($_POST['step_goal'] ?? '')) !== '') {
                $settingsStepGoal = parse_localized_positive_integer($_POST['step_goal'] ?? '') ?? -1;
                if ($settingsStepGoal < 0) {
                    flash_set('error', t('onboarding.steps_invalid'));
                    redirect($settingsRedirect($settingsView));
                }
            }
            $settingsWorkoutRaw = trim((string) ($_POST['workout_target'] ?? ''));
            if ($settingsWorkoutRaw !== '' && (!is_numeric($settingsWorkoutRaw) || (int) $settingsWorkoutRaw < 0 || (int) $settingsWorkoutRaw > 14)) {
                flash_set('error', t('onboarding.workouts_invalid'));
                redirect($settingsRedirect($settingsView));
            }
            $settingsWorkoutTarget = $settingsWorkoutRaw !== '' ? (int) $settingsWorkoutRaw : 0;
            $settingsDistanceRaw = trim((string) ($_POST['distance_goal'] ?? ''));
            if ($settingsDistanceRaw !== '' && (!is_numeric($settingsDistanceRaw) || (float) $settingsDistanceRaw <= 0)) {
                flash_set('error', t('metric.invalid'));
                redirect($settingsRedirect($settingsView));
            }
            $settingsDistanceGoal = $settingsDistanceRaw !== '' ? (float) $settingsDistanceRaw : 0.0;
            $settingsBurnRaw = trim((string) ($_POST['calorie_burn_goal'] ?? ''));
            $settingsConsumedRaw = trim((string) ($_POST['calorie_consumed_max'] ?? ''));
            if (
                ($settingsBurnRaw !== '' && (!is_numeric($settingsBurnRaw) || (float) $settingsBurnRaw <= 0))
                || ($settingsConsumedRaw !== '' && (!is_numeric($settingsConsumedRaw) || (float) $settingsConsumedRaw <= 0))
            ) {
                flash_set('error', t('metric.invalid'));
                redirect($settingsRedirect($settingsView));
            }
            $settingsDailyGoals = [];
            if ($settingsStepGoal > 0) {
                $settingsDailyGoals[] = ['type' => 'steps', 'value' => (float) $settingsStepGoal];
            }
            if ($settingsDistanceGoal > 0) {
                $settingsDailyGoals[] = ['type' => 'km', 'value' => $settingsDistanceGoal];
            }
            if ($primaryType === 'workouts' && $primaryValue > 0) {
                $settingsDailyGoals[] = ['type' => 'workouts', 'value' => $primaryValue];
            }
            $settingsLegacyGoal = $settingsDailyGoals[0] ?? null;
            $primaryType = is_array($settingsLegacyGoal) ? (string) ($settingsLegacyGoal['type'] ?? 'none') : 'none';
            $primaryValue = is_array($settingsLegacyGoal) ? (float) ($settingsLegacyGoal['value'] ?? 0) : 0.0;
            $themeMode = in_array(($_POST['theme_mode'] ?? 'auto'), ['auto', 'light', 'dark'], true) ? (string) $_POST['theme_mode'] : 'auto';
            $layoutJson = (string) ($before['dashboard_layout_json'] ?? '[]');
            $hasWidgetPayload = array_key_exists('dashboard_widgets', $_POST) || array_key_exists('dashboard_order', $_POST);
            if ($hasWidgetPayload) {
                $allowedWidgets = ['motivation', 'kpis', 'nutrition', 'training_rank', 'training_progress', 'distance_walked', 'approvals', 'steps', 'steps_cumulative', 'distance_cumulative', 'weight', 'comparison', 'ranking', 'meals', 'weekly', 'calories', 'achievements', 'achievement_progress', 'duels', 'competitions', 'quests', 'season'];
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
                     primary_goals_spec = :primary_goals_spec,
                     step_goal = :step_goal,
                     workout_target = :workout_target,
                     calorie_burn_goal = :calorie_burn_goal,
                     calorie_consumed_max = :calorie_consumed_max,
                     theme_mode = :theme_mode,
                     dashboard_view = :dashboard_view,
                     dashboard_layout_json = :dashboard_layout_json,
                     updated_at = :updated_at
                 WHERE id = :id',
                [
                    ':primary_goal_type' => $primaryType,
                    ':primary_goal_value' => $primaryType !== 'none' ? $primaryValue : null,
                    ':primary_goals_spec' => $settingsDailyGoals !== [] ? format_primary_goals_spec($settingsDailyGoals) : null,
                    ':step_goal' => $settingsStepGoal,
                    ':workout_target' => $settingsWorkoutTarget,
                    ':calorie_burn_goal' => $settingsBurnRaw !== '' ? (float) $settingsBurnRaw : null,
                    ':calorie_consumed_max' => $settingsConsumedRaw !== '' ? (float) $settingsConsumedRaw : null,
                    ':theme_mode' => $themeMode,
                    ':dashboard_view' => (string) ($_POST['dashboard_view'] ?? 'current_week'),
                    ':dashboard_layout_json' => $layoutJson,
                    ':updated_at' => now_iso(),
                    ':id' => (int) $currentUser['id'],
                ]
            );
            $after = db_fetch_one($pdo, 'SELECT * FROM users WHERE id = :id', [':id' => (int) $currentUser['id']]);
            if (array_key_exists('enabled_metrics', $_POST) || array_key_exists('metric_preferences_present', $_POST)) {
                try {
                    save_user_metric_preferences($pdo, $after ?? $currentUser, (array) ($_POST['enabled_metrics'] ?? []));
                } catch (InvalidArgumentException $preferenceError) {
                    flash_set('error', $preferenceError->getMessage());
                    redirect($settingsRedirect($settingsView));
                }
            }
            audit_log($pdo, (int) $currentUser['id'], 'user_preferences_updated', 'user', (string) $currentUser['id'], 'User preferences updated.', audit_snapshot($before, ['password_hash']), audit_snapshot($after, ['password_hash']));
            flash_set('success', t('flash.preferences_updated'));
            redirect($settingsRedirect($settingsView));
        }

        if ($action === 'update_body_settings') {
            $idealWeightRaw = trim((string) ($_POST['ideal_weight'] ?? ''));
            $heightRaw = trim((string) ($_POST['height_cm'] ?? ''));
            $division = (string) ($_POST['competitive_division'] ?? 'open');
            $idealWeight = null;
            $height = null;
            if ($idealWeightRaw !== '') {
                if (!is_numeric($idealWeightRaw) || (float) $idealWeightRaw < 25 || (float) $idealWeightRaw > 400) {
                    flash_set('error', t('settings.weight_invalid'));
                    redirect($settingsRedirect('body'));
                }
                $idealWeight = round((float) $idealWeightRaw, 1);
            }
            if ($heightRaw !== '') {
                if (!is_numeric($heightRaw) || (float) $heightRaw < 100 || (float) $heightRaw > 250) {
                    flash_set('error', t('settings.height_invalid'));
                    redirect($settingsRedirect('body'));
                }
                $height = round((float) $heightRaw, 1);
            }
            if (!in_array($division, ['open', 'women', 'men'], true)) {
                flash_set('error', t('settings.division_invalid'));
                redirect($settingsRedirect('body'));
            }
            $before = db_fetch_one($pdo, 'SELECT id, ideal_weight, height_cm, competitive_division FROM users WHERE id = :id', [':id' => (int) $currentUser['id']]);
            db_execute(
                $pdo,
                'UPDATE users SET ideal_weight = :ideal_weight, height_cm = :height_cm,
                    competitive_division = :competitive_division, updated_at = :updated_at WHERE id = :id',
                [
                    ':ideal_weight' => $idealWeight,
                    ':height_cm' => $height,
                    ':competitive_division' => $division,
                    ':updated_at' => now_iso(),
                    ':id' => (int) $currentUser['id'],
                ]
            );
            $after = db_fetch_one($pdo, 'SELECT id, ideal_weight, height_cm, competitive_division FROM users WHERE id = :id', [':id' => (int) $currentUser['id']]);
            audit_log($pdo, (int) $currentUser['id'], 'body_settings_updated', 'user', (string) $currentUser['id'], 'Body settings updated.', $before, $after);
            flash_set('success', t('settings.body_profile_saved'));
            redirect($settingsRedirect('body'));
        }

        if ($action === 'save_weight_entry') {
            $weightDate = to_date((string) ($_POST['weight_date'] ?? null));
            $weightRaw = trim((string) ($_POST['weight'] ?? ''));
            if ($weightRaw === '' || !is_numeric($weightRaw) || (float) $weightRaw < 25 || (float) $weightRaw > 400) {
                flash_set('error', t('settings.weight_entry_invalid'));
                redirect($settingsRedirect('body'));
            }
            $weightValue = round((float) $weightRaw, 1);
            $weightUserId = (int) $currentUser['id'];
            $before = fetch_log($pdo, $weightUserId, $weightDate);
            $now = now_iso();
            db_execute(
                $pdo,
                'INSERT INTO daily_logs (user_id, log_date, log_time, weight, created_at, updated_at)
                 VALUES (:user_id, :log_date, :log_time, :weight, :created_at, :updated_at)
                 ON CONFLICT(user_id, log_date) DO UPDATE SET
                    weight = excluded.weight,
                    version = daily_logs.version + 1,
                    updated_at = excluded.updated_at',
                [
                    ':user_id' => $weightUserId,
                    ':log_date' => $weightDate,
                    ':log_time' => (new DateTimeImmutable('now'))->format('H:i'),
                    ':weight' => $weightValue,
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]
            );
            $enabledMetrics = metric_enabled_keys($pdo, $currentUser);
            $enabledMetrics[] = 'weight';
            save_user_metric_preferences($pdo, $currentUser, array_values(array_unique($enabledMetrics)));
            $after = fetch_log($pdo, $weightUserId, $weightDate);
            audit_log(
                $pdo,
                $weightUserId,
                'weight_entry_saved',
                'daily_log',
                $weightUserId . ':' . $weightDate,
                'Weight entry saved from body settings.',
                audit_snapshot($before),
                audit_snapshot($after)
            );
            flash_set('success', t('settings.weight_entry_saved'));
            redirect($settingsRedirect('body'));
        }

        if ($action === 'telegram_generate_link') {
            telegram_generate_link_code($pdo, (int) $currentUser['id']);
            flash_set('success', t('flash.telegram_link_ready'));
            redirect($settingsRedirect($settingsView) . '#telegram');
        }

        if ($action === 'telegram_update_prefs') {
            telegram_update_user_prefs($pdo, (int) $currentUser['id'], $_POST);
            flash_set('success', t('flash.telegram_prefs_updated'));
            redirect($settingsRedirect($settingsView) . '#telegram');
        }

        if ($action === 'telegram_unlink') {
            telegram_unlink_user($pdo, (int) $currentUser['id']);
            flash_set('success', t('flash.telegram_unlinked'));
            redirect($settingsRedirect($settingsView) . '#telegram');
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
            redirect($settingsRedirect($settingsView) . '#telegram');
        }

        if ($action === 'remove_avatar') {
            $existingAvatarPath = trim((string) ($currentUser['avatar_path'] ?? ''));
            db_execute(
                $pdo,
                'UPDATE users SET avatar_path = NULL, updated_at = :updated_at WHERE id = :id',
                [':updated_at' => now_iso(), ':id' => (int) $currentUser['id']]
            );
            if ($existingAvatarPath !== '') {
                $existingAvatarFile = resolve_media_storage_path($config, $existingAvatarPath);
                if ($existingAvatarFile !== null && is_file($existingAvatarFile)) {
                    @unlink($existingAvatarFile);
                }
            }
            audit_log(
                $pdo,
                (int) $currentUser['id'],
                'avatar_removed',
                'user',
                (string) $currentUser['id'],
                'Avatar removed.',
                ['avatar_path' => $existingAvatarPath],
                ['avatar_path' => null]
            );
            flash_set('success', t('flash.avatar_updated'));
            redirect($settingsRedirect('avatar'));
        }

        if ($action === 'update_settings_privacy') {
            privacy_set_preferences(
                $pdo,
                (int) $currentUser['id'],
                (string) ($_POST['profile_visibility'] ?? 'public'),
                (array) ($_POST['data_visibility'] ?? [])
            );
            flash_set('success', t('flash.preferences_updated'));
            redirect($settingsRedirect('privacy'));
        }

        if ($action === 'upload_avatar') {
            $storedPath = null;
            $persisted = false;
            try {
                $cropped = trim((string) ($_POST['avatar_cropped'] ?? ''));
                if ($cropped !== '') {
                    $storedPath = save_uploaded_image_from_data_url($config, $cropped, 'avatars', 'user_' . (string) $currentUser['id']);
                } else {
                    $avatarUpload = $_FILES['avatar'] ?? [];
                    if ((int) ($avatarUpload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                        $avatarUpload = $_FILES['avatar_camera'] ?? [];
                    }
                    $storedPath = save_uploaded_image($config, $avatarUpload, 'avatars', 'user_' . (string) $currentUser['id']);
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
    $settingsWeightHistory = db_fetch_all(
        $pdo,
        'SELECT log_date AS date, weight
         FROM daily_logs
         WHERE user_id = :user_id AND weight IS NOT NULL
         ORDER BY log_date DESC
         LIMIT 50',
        [':user_id' => (int) $currentUser['id']]
    );
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
        $settingsGoalRows = hydrate_user_goal_metric_targets(
            $pdo,
            $settingsGoalRows,
            (int) $currentUser['id'],
            (string) $settingsChallenge['challenge_start'],
            (string) $settingsChallenge['challenge_end'],
            is_array($settingsGoalMetric) ? $settingsGoalMetric : []
        );
    } catch (Throwable) {
        $settingsGoalMetric = [];
    }

    render_view('settings', [
        'title' => t('settings.title'),
        'currentPage' => 'settings',
        'currentUser' => $currentUser,
        'settingsView' => $settingsView,
        'settingsMetric' => is_array($settingsGoalMetric) ? $settingsGoalMetric : [],
        'settingsWeightHistory' => $settingsWeightHistory,
        'settingsGoalCards' => build_user_goal_view_models($settingsGoalRows, is_array($settingsGoalMetric) ? $settingsGoalMetric : [], $settingsHabitDefinitions),
        'settingsMetricDefinitions' => metric_preference_definitions($pdo, $currentUser),
        'settingsEnabledMetrics' => metric_enabled_keys($pdo, $currentUser),
        'telegramSettings' => telegram_settings($pdo),
        'config' => $config,
    ]);
}

if ($page === 'profile') {
    if (!is_post()) {
        $legacyProfileSection = trim((string) ($_GET['section'] ?? ''));
        if ($legacyProfileSection === 'social') {
            redirect('/?page=social');
        }
        if ($legacyProfileSection === 'training') {
            $legacyProfileUserId = isset($_GET['user_id']) ? max(0, (int) $_GET['user_id']) : 0;
            if ($legacyProfileUserId > 0 && $legacyProfileUserId !== (int) $currentUser['id']) {
                redirect('/?page=profile&user_id=' . $legacyProfileUserId);
            }
            redirect('/?page=workouts&view=ranks');
        }
    }
    workouts_ensure_schema($pdo);
    profile_custom_widgets_ensure_schema($pdo);
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
    friends_ensure_schema($pdo);
    if (
        !can_view_user_content(
            $pdo,
            (int) $currentUser['id'],
            (int) $profileUser['id'],
            is_admin($currentUser),
            (string) ($profileUser['profile_visibility'] ?? 'public')
        )
    ) {
        flash_set('error', t('flash.no_permission'));
        redirect('/?page=profile');
    }
    $profileDataAccess = [];
    foreach (PRIVACY_DATA_KEYS as $privacyDataKey) {
        $profileDataAccess[$privacyDataKey] = can_view_user_data(
            $pdo,
            (int) $currentUser['id'],
            (int) $profileUser['id'],
            $privacyDataKey,
            is_admin($currentUser),
            $profileUser
        );
    }

    // Editing is an owner capability. Administrators keep their explicit export and
    // moderation tools, but visiting somebody else's public profile must never expose
    // controls that look like the profile belongs to them.
    $canEditProfile = $isOwnProfile;
    // Achievement awards are moderation records. They can only be removed from
    // Administration, never from a public or personal profile.
    $canDeleteProfileAchievements = false;
    $profileBaseQuery = ['page' => 'profile'];
    if (!$isOwnProfile) {
        $profileBaseQuery['user_id'] = (int) $profileUser['id'];
    }
    $requestedProfileChallengeKey = trim((string) ($_GET['challenge'] ?? 'current'));
    if ($requestedProfileChallengeKey !== '' && $requestedProfileChallengeKey !== 'current') {
        $profileBaseQuery['challenge'] = $requestedProfileChallengeKey;
    }
    $profileBackUrl = '';
    $profileBackParams = [];
    $requestedBack = trim((string) ($_GET['back'] ?? ''));
    $requestedBackTeamId = isset($_GET['team_id']) ? (int) $_GET['team_id'] : 0;
    if ($requestedBack === 'team' && $requestedBackTeamId > 0) {
        $viewerCanReturnToTeam = user_is_active_team_member(
            $pdo,
            $requestedBackTeamId,
            (int) $currentUser['id']
        );
        $targetBelongsToTeam = user_is_active_team_member(
            $pdo,
            $requestedBackTeamId,
            (int) $profileUser['id']
        );
        if ($viewerCanReturnToTeam && $targetBelongsToTeam) {
            $profileBackParams = [
                'back' => 'team',
                'team_id' => $requestedBackTeamId,
            ];
            $profileBackQuery = [
                'page' => 'team',
                'team_id' => $requestedBackTeamId,
            ];
            $requestedBackView = trim((string) ($_GET['back_view'] ?? ''));
            if (
                in_array($requestedBackView, ['current_week', 'total'], true)
                || preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedBackView) === 1
            ) {
                $profileBackParams['back_view'] = $requestedBackView;
                $profileBackQuery['view'] = $requestedBackView;
            }
            $profileBackUrl = '/?' . http_build_query($profileBackQuery);
            $profileBaseQuery = array_merge($profileBaseQuery, $profileBackParams);
        }
    }
    $profileUrl = static function (?string $section = null, array $extra = []) use (&$profileBaseQuery): string {
        $query = array_merge($profileBaseQuery, $extra);
        if ($section !== null && $section !== '') {
            $query['section'] = $section;
        }
        return '/?' . http_build_query($query);
    };
    squads_ensure_schema($pdo);
    $profileTeams = list_user_teams($pdo, (int) $profileUser['id']);
    $profileGoalTeams = $isOwnProfile
        ? array_values(array_filter(
            $profileTeams,
            static fn(array $profileTeam): bool => can_manage_team($pdo, $currentUser, (int) ($profileTeam['id'] ?? 0))
        ))
        : [];
    $profileCustomWidgets = profile_custom_widgets_for_user(
        $pdo,
        (int) $profileUser['id'],
        $isOwnProfile
    );

    if (is_post()) {
        if (!csrf_verify()) {
            flash_set('error', t('flash.csrf'));
            redirect($profileUrl());
        }

        $action = (string) ($_POST['action'] ?? '');
        if (in_array($action, ['friend_request', 'friend_accept', 'friend_reject', 'friend_remove'], true)) {
            $friendTargetId = (int) ($_POST['user_id'] ?? 0);
            if ($friendTargetId <= 0 || $friendTargetId === (int) $currentUser['id']) {
                flash_set('error', t('flash.friend_action_failed'));
                redirect($profileUrl());
            }

            if ($action === 'friend_request') {
                $friendSent = friends_send_request($pdo, (int) $currentUser['id'], $friendTargetId);
                flash_set($friendSent ? 'success' : 'error', $friendSent ? t('flash.friend_request_sent') : t('flash.friend_action_failed'));
                redirect($profileUrl());
            }

            if ($action === 'friend_accept') {
                $friendAccepted = friends_respond($pdo, (int) $currentUser['id'], $friendTargetId, true);
                flash_set($friendAccepted ? 'success' : 'error', $friendAccepted ? t('flash.friend_accepted') : t('flash.friend_action_failed'));
                redirect($profileUrl());
            }

            if ($action === 'friend_reject') {
                $friendRejected = friends_respond($pdo, (int) $currentUser['id'], $friendTargetId, false);
                flash_set($friendRejected ? 'success' : 'error', $friendRejected ? t('flash.friend_rejected') : t('flash.friend_action_failed'));
                redirect($profileUrl());
            }

            if ($action === 'friend_remove') {
                $friendRemoved = friends_remove($pdo, (int) $currentUser['id'], $friendTargetId);
                flash_set($friendRemoved ? 'success' : 'error', $friendRemoved ? t('flash.friend_removed') : t('flash.friend_action_failed'));
                redirect($profileUrl());
            }
        }

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

        if ($action === 'update_profile_cover') {
            if (!$canEditProfile || !$isOwnProfile) {
                flash_set('error', t('flash.no_permission'));
                redirect($profileUrl());
            }

            $before = db_fetch_one($pdo, 'SELECT * FROM users WHERE id = :id', [':id' => (int) $profileUser['id']]);
            $existingCoverPath = trim((string) ($before['profile_cover_path'] ?? ''));
            $storedCoverPath = '';
            $persisted = false;
            try {
                $croppedCover = trim((string) ($_POST['profile_cover_cropped'] ?? ''));
                $removeCover = !empty($_POST['remove_profile_cover']);
                $coverUpload = is_array($_FILES['profile_cover'] ?? null) ? (array) $_FILES['profile_cover'] : [];
                $hasCoverUpload = (int) ($coverUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

                if ($croppedCover !== '') {
                    $storedCoverPath = save_uploaded_image_from_data_url(
                        $config,
                        $croppedCover,
                        'profile_covers',
                        'user_' . (string) $profileUser['id']
                    );
                } elseif ($hasCoverUpload) {
                    $storedCoverPath = save_uploaded_image(
                        $config,
                        $coverUpload,
                        'profile_covers',
                        'user_' . (string) $profileUser['id']
                    );
                } elseif (!$removeCover) {
                    throw new RuntimeException(t('profile.cover_select_image'));
                }

                if ($storedCoverPath !== '') {
                    $resolvedCoverPath = resolve_media_storage_path($config, $storedCoverPath);
                    if ($resolvedCoverPath === null || !is_file($resolvedCoverPath)) {
                        throw new RuntimeException(t('upload.move_failed'));
                    }
                }

                $updatedAt = now_iso();
                $pdo->beginTransaction();
                db_execute(
                    $pdo,
                    'UPDATE users SET profile_cover_path = :profile_cover_path, updated_at = :updated_at WHERE id = :id',
                    [
                        ':profile_cover_path' => $storedCoverPath !== '' ? $storedCoverPath : null,
                        ':updated_at' => $updatedAt,
                        ':id' => (int) $profileUser['id'],
                    ]
                );
                $after = db_fetch_one($pdo, 'SELECT * FROM users WHERE id = :id', [':id' => (int) $profileUser['id']]);
                if ($after === null || trim((string) ($after['profile_cover_path'] ?? '')) !== $storedCoverPath) {
                    throw new RuntimeException(t('upload.persist_failed'));
                }
                $pdo->commit();
                $persisted = true;

                if ($existingCoverPath !== '' && $existingCoverPath !== $storedCoverPath) {
                    $existingCoverFile = resolve_media_storage_path($config, $existingCoverPath);
                    if ($existingCoverFile !== null && is_file($existingCoverFile)) {
                        @unlink($existingCoverFile);
                    }
                }

                try {
                    audit_log(
                        $pdo,
                        (int) $currentUser['id'],
                        'profile_cover_updated',
                        'user',
                        (string) $profileUser['id'],
                        'Profile cover updated.',
                        ['profile_cover_path' => $existingCoverPath !== '' ? $existingCoverPath : null],
                        ['profile_cover_path' => $storedCoverPath !== '' ? $storedCoverPath : null]
                    );
                } catch (Throwable) {
                    // A cover that was persisted successfully must not be rolled back for audit issues.
                }
                flash_set('success', t('profile.cover_updated'));
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if (!$persisted && $storedCoverPath !== '') {
                    $failedCoverFile = resolve_media_storage_path($config, $storedCoverPath);
                    if ($failedCoverFile !== null && is_file($failedCoverFile)) {
                        @unlink($failedCoverFile);
                    }
                }
                flash_set('error', $e->getMessage());
            }
            redirect($profileUrl());
        }

        if ($action === 'update_profile_tagline') {
            if (!$canEditProfile || !$isOwnProfile) {
                flash_set('error', t('flash.no_permission'));
                redirect($profileUrl());
            }
            $before = db_fetch_one($pdo, 'SELECT * FROM users WHERE id = :id', [':id' => (int) $profileUser['id']]);
            $tagline = normalize_profile_tagline((string) ($_POST['profile_tagline'] ?? ''));
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

        if ($action === 'equip_frame') {
            if (!$isOwnProfile) {
                flash_set('error', t('flash.no_permission'));
                redirect($profileUrl());
            }
            // cosmetics_equip() re-checks the unlock, so a forged POST cannot equip
            // a frame the user has not earned.
            $equipped = cosmetics_equip($pdo, $currentUser, (string) ($_POST['frame'] ?? 'none'));
            flash_set($equipped ? 'success' : 'error', t($equipped ? 'flash.preferences_updated' : 'cosmetic.locked'));
            redirect($profileUrl());
        }

        if (in_array($action, ['create_profile_widget', 'update_profile_widget'], true)) {
            if (!$isOwnProfile) {
                flash_set('error', t('flash.no_permission'));
                redirect($profileUrl());
            }

            $widgetId = $action === 'update_profile_widget' ? (int) ($_POST['widget_id'] ?? 0) : 0;
            $existingWidget = $widgetId > 0
                ? profile_custom_widget_for_owner($pdo, $widgetId, (int) $currentUser['id'])
                : null;
            if ($action === 'update_profile_widget' && $existingWidget === null) {
                flash_set('error', t('flash.not_found'));
                redirect($profileUrl());
            }
            if (
                $action === 'create_profile_widget'
                && count(profile_custom_widgets_for_user($pdo, (int) $currentUser['id'], true)) >= PROFILE_CUSTOM_WIDGET_LIMIT
            ) {
                flash_set('error', t('profile.widget_limit_reached', ['count' => PROFILE_CUSTOM_WIDGET_LIMIT]));
                redirect($profileUrl());
            }

            $title = trim((string) ($_POST['widget_title'] ?? ''));
            $title = function_exists('mb_substr') ? mb_substr($title, 0, 80) : substr($title, 0, 80);
            $body = trim((string) ($_POST['widget_body'] ?? ''));
            $body = function_exists('mb_substr') ? mb_substr($body, 0, 600) : substr($body, 0, 600);
            if ($title === '') {
                flash_set('error', t('profile.widget_title_required'));
                redirect($profileUrl());
            }

            $widgetType = profile_custom_widget_type((string) ($_POST['widget_type'] ?? 'media'));
            $accent = profile_custom_widget_accent((string) ($_POST['accent_color'] ?? '#7c3aed'));
            $externalUrl = profile_custom_widget_url((string) ($_POST['external_url'] ?? ''));
            if (
                $widgetType === 'media'
                && $externalUrl !== ''
                && preg_match('/\.(?:gif|png|jpe?g|webp)(?:[?#].*)?$/i', $externalUrl) !== 1
            ) {
                flash_set('error', t('profile.widget_media_invalid'));
                redirect($profileUrl());
            }
            $linkUrl = profile_custom_widget_url((string) ($_POST['link_url'] ?? ''));
            $achievementIds = profile_custom_widget_achievement_ids($_POST['achievement_ids'] ?? []);
            $mediaPath = trim((string) ($existingWidget['media_path'] ?? ''));
            $mediaMime = trim((string) ($existingWidget['media_mime'] ?? ''));
            $newMediaPath = '';
            $oldMediaPath = $mediaPath;

            try {
                $upload = is_array($_FILES['widget_media'] ?? null) ? (array) $_FILES['widget_media'] : [];
                $hasUpload = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
                if ($hasUpload) {
                    $storedMedia = save_profile_custom_widget_media($config, $upload, (int) $currentUser['id']);
                    $mediaPath = (string) $storedMedia['path'];
                    $mediaMime = (string) $storedMedia['mime'];
                    $newMediaPath = $mediaPath;
                } elseif (!empty($_POST['remove_widget_media'])) {
                    $mediaPath = '';
                    $mediaMime = '';
                }

                $timestamp = now_iso();
                if ($existingWidget === null) {
                    $maxOrderRow = db_fetch_one(
                        $pdo,
                        'SELECT COALESCE(MAX(sort_order), 0) AS max_order FROM profile_custom_widgets WHERE user_id = :user_id',
                        [':user_id' => (int) $currentUser['id']]
                    );
                    db_execute(
                        $pdo,
                        'INSERT INTO profile_custom_widgets
                         (user_id, widget_type, title, body, media_path, media_mime, external_url, link_url, accent_color, achievement_ids_json, sort_order, is_visible, created_at, updated_at)
                         VALUES
                         (:user_id, :widget_type, :title, :body, :media_path, :media_mime, :external_url, :link_url, :accent_color, :achievement_ids_json, :sort_order, 1, :created_at, :updated_at)',
                        [
                            ':user_id' => (int) $currentUser['id'],
                            ':widget_type' => $widgetType,
                            ':title' => $title,
                            ':body' => $body,
                            ':media_path' => $mediaPath !== '' ? $mediaPath : null,
                            ':media_mime' => $mediaMime !== '' ? $mediaMime : null,
                            ':external_url' => $externalUrl !== '' ? $externalUrl : null,
                            ':link_url' => $linkUrl !== '' ? $linkUrl : null,
                            ':accent_color' => $accent,
                            ':achievement_ids_json' => json_encode($achievementIds, JSON_UNESCAPED_SLASHES),
                            ':sort_order' => (int) ($maxOrderRow['max_order'] ?? 0) + 1,
                            ':created_at' => $timestamp,
                            ':updated_at' => $timestamp,
                        ]
                    );
                } else {
                    db_execute(
                        $pdo,
                        'UPDATE profile_custom_widgets SET
                            widget_type = :widget_type, title = :title, body = :body,
                            media_path = :media_path, media_mime = :media_mime,
                            external_url = :external_url, link_url = :link_url,
                            accent_color = :accent_color, achievement_ids_json = :achievement_ids_json,
                            is_visible = :is_visible, updated_at = :updated_at
                         WHERE id = :id AND user_id = :user_id',
                        [
                            ':widget_type' => $widgetType,
                            ':title' => $title,
                            ':body' => $body,
                            ':media_path' => $mediaPath !== '' ? $mediaPath : null,
                            ':media_mime' => $mediaMime !== '' ? $mediaMime : null,
                            ':external_url' => $externalUrl !== '' ? $externalUrl : null,
                            ':link_url' => $linkUrl !== '' ? $linkUrl : null,
                            ':accent_color' => $accent,
                            ':achievement_ids_json' => json_encode($achievementIds, JSON_UNESCAPED_SLASHES),
                            ':is_visible' => bool_from_form('widget_visible'),
                            ':updated_at' => $timestamp,
                            ':id' => (int) $existingWidget['id'],
                            ':user_id' => (int) $currentUser['id'],
                        ]
                    );
                }

                if ($oldMediaPath !== '' && $oldMediaPath !== $mediaPath) {
                    $oldMediaFile = resolve_media_storage_path($config, $oldMediaPath);
                    if ($oldMediaFile !== null && is_file($oldMediaFile)) {
                        @unlink($oldMediaFile);
                    }
                }
                flash_set('success', t($existingWidget === null ? 'profile.widget_created' : 'profile.widget_updated'));
            } catch (Throwable $e) {
                if ($newMediaPath !== '') {
                    $newMediaFile = resolve_media_storage_path($config, $newMediaPath);
                    if ($newMediaFile !== null && is_file($newMediaFile)) {
                        @unlink($newMediaFile);
                    }
                }
                flash_set('error', $e->getMessage());
            }
            redirect($profileUrl());
        }

        if ($action === 'delete_profile_widget') {
            if (!$isOwnProfile) {
                flash_set('error', t('flash.no_permission'));
                redirect($profileUrl());
            }
            $widget = profile_custom_widget_for_owner(
                $pdo,
                (int) ($_POST['widget_id'] ?? 0),
                (int) $currentUser['id']
            );
            if ($widget === null) {
                flash_set('error', t('flash.not_found'));
                redirect($profileUrl());
            }
            db_execute(
                $pdo,
                'DELETE FROM profile_custom_widgets WHERE id = :id AND user_id = :user_id',
                [':id' => (int) $widget['id'], ':user_id' => (int) $currentUser['id']]
            );
            $storedPath = trim((string) ($widget['media_path'] ?? ''));
            if ($storedPath !== '') {
                $storedFile = resolve_media_storage_path($config, $storedPath);
                if ($storedFile !== null && is_file($storedFile)) {
                    @unlink($storedFile);
                }
            }
            $savedLayout = json_decode((string) ($profileUser['profile_layout_json'] ?? ''), true);
            if (is_array($savedLayout)) {
                $deletedKey = profile_custom_widget_key((int) $widget['id']);
                $savedLayout = array_values(array_filter($savedLayout, static fn(mixed $key): bool => (string) $key !== $deletedKey));
                db_execute(
                    $pdo,
                    'UPDATE users SET profile_layout_json = :layout, updated_at = :updated_at WHERE id = :id',
                    [
                        ':layout' => $savedLayout !== [] ? json_encode($savedLayout, JSON_UNESCAPED_SLASHES) : null,
                        ':updated_at' => now_iso(),
                        ':id' => (int) $currentUser['id'],
                    ]
                );
            }
            flash_set('success', t('profile.widget_deleted'));
            redirect($profileUrl());
        }

        if ($action === 'save_profile_layout') {
            if (!$isOwnProfile) {
                flash_set('error', t('flash.no_permission'));
                redirect($profileUrl());
            }
            $allowedProfileBlocks = ['goals', 'friends', 'teams', 'training_rank', 'training_progress', 'achievements', 'duels', 'competitions', 'setup', 'activity'];
            foreach (profile_custom_widgets_for_user($pdo, (int) $currentUser['id'], true) as $customWidget) {
                $allowedProfileBlocks[] = profile_custom_widget_key((int) $customWidget['id']);
            }
            $resetProfileLayout = bool_from_form('reset_profile_layout') === 1;
            $layoutValue = null;
            if (!$resetProfileLayout) {
                $orderInput = (array) ($_POST['profile_order'] ?? []);
                $blocks = array_values(array_intersect(
                    array_map('strval', (array) ($_POST['profile_blocks'] ?? [])),
                    $allowedProfileBlocks
                ));
                $blocks = array_values(array_unique($blocks));
                usort($blocks, static function (string $left, string $right) use ($orderInput, $allowedProfileBlocks): int {
                    $leftOrder = isset($orderInput[$left]) ? (int) $orderInput[$left] : (int) array_search($left, $allowedProfileBlocks, true);
                    $rightOrder = isset($orderInput[$right]) ? (int) $orderInput[$right] : (int) array_search($right, $allowedProfileBlocks, true);
                    return $leftOrder <=> $rightOrder;
                });
                if ($blocks !== []) {
                    $layoutValue = json_encode($blocks, JSON_UNESCAPED_SLASHES) ?: null;
                }
            }
            db_execute(
                $pdo,
                'UPDATE users SET profile_layout_json = :layout, profile_widgets_known = :known, updated_at = :updated_at WHERE id = :id',
                [
                    ':layout' => $layoutValue,
                    ':known' => json_encode($allowedProfileBlocks, JSON_UNESCAPED_SLASHES),
                    ':updated_at' => now_iso(),
                    ':id' => (int) $profileUser['id'],
                ]
            );
            flash_set('success', t('flash.preferences_updated'));
            redirect($profileUrl());
        }

        if ($action === 'update_privacy') {
            if (!$canEditProfile || !$isOwnProfile) {
                flash_set('error', t('flash.no_permission'));
                redirect($profileUrl('config'));
            }
            privacy_set_visibility($pdo, (int) $profileUser['id'], (string) ($_POST['profile_visibility'] ?? 'public'));
            flash_set('success', t('flash.preferences_updated'));
            redirect($profileUrl('config'));
        }

        if ($action === 'create_goal') {
            if (!$canEditProfile) {
                flash_set('error', t('flash.no_permission'));
                redirect($profileUrl('goals'));
            }
            $title = trim((string) ($_POST['title'] ?? ''));
            if ($title !== '') {
                $goalTeamId = max(0, (int) ($_POST['goal_team_id'] ?? 0));
                $goalTeam = null;
                if ($goalTeamId > 0) {
                    foreach ($profileGoalTeams as $profileGoalTeam) {
                        if ((int) ($profileGoalTeam['id'] ?? 0) === $goalTeamId) {
                            $goalTeam = $profileGoalTeam;
                            break;
                        }
                    }
                    if ($goalTeam === null) {
                        flash_set('error', t('flash.no_permission'));
                        redirect($profileUrl('goals', ['goal_new' => 1]));
                    }
                }
                $goalType = normalize_goal_target_type((string) ($_POST['target_type'] ?? 'custom'));
                $targetValue = ($_POST['target_value'] ?? '') !== '' ? (float) $_POST['target_value'] : null;
                try {
                    $metricTargets = goal_metric_targets_from_form($_POST, $goalType, (float) ($targetValue ?? 0));
                } catch (InvalidArgumentException $exception) {
                    flash_set('error', $exception->getMessage());
                    redirect($profileUrl('goals', ['goal_new' => 1]));
                }
                $dueDate = ($_POST['due_date'] ?? '') !== '' ? to_date((string) $_POST['due_date']) : null;
                $nowDateTime = new DateTimeImmutable('now');
                $dueAt = $dueDate !== null ? log_datetime_from_values($dueDate, '23:59', '23:59') : null;
                $scheduleErrorKey = goal_schedule_error_key(null, $dueAt, true, $nowDateTime);
                if ($scheduleErrorKey !== null) {
                    flash_set('error', t($scheduleErrorKey));
                    redirect($profileUrl('goals', ['goal_new' => 1]));
                }
                if ($goalTeam !== null) {
                    $settingsForProfileGoal = challenge_settings($pdo, $config);
                    $teamUsersForProfileGoal = list_active_team_users($pdo, $goalTeamId);
                    $teamMetricsForProfileGoal = compute_challenge_metrics(
                        $pdo,
                        $teamUsersForProfileGoal,
                        (string) $settingsForProfileGoal['challenge_start'],
                        (string) $settingsForProfileGoal['challenge_end']
                    );
                    $teamMetricsForProfileGoal = apply_strike_review_overrides_to_metrics($pdo, $teamMetricsForProfileGoal);
                    $teamSummaryForProfileGoal = team_summary_from_rows(team_rows_for_view(array_values($teamMetricsForProfileGoal), 'total'));
                    $teamCaloriesForProfileGoal = resolve_team_calories_summary(
                        $pdo,
                        $goalTeamId,
                        (string) $settingsForProfileGoal['challenge_start'],
                        (string) $settingsForProfileGoal['challenge_end']
                    );
                    $teamSummaryForProfileGoal['calories_burned'] = (float) ($teamCaloriesForProfileGoal['burned'] ?? 0);
                    $teamSummaryForProfileGoal['calories_consumed'] = (float) ($teamCaloriesForProfileGoal['consumed'] ?? 0);
                    $baselineValue = goal_team_metric_value_for_type($goalType, $teamSummaryForProfileGoal, 0);
                    $dueTime = normalize_goal_due_time($dueDate, '');
                    create_goal($pdo, [
                        'scope' => 'team',
                        'team_id' => $goalTeamId,
                        'user_id' => null,
                        'title' => $title,
                        'target_type' => $goalType,
                        'target_value' => $targetValue,
                        'baseline_value' => $baselineValue,
                        'current_value' => 0,
                        'unit_label' => goal_target_default_unit($goalType),
                        'start_date' => $nowDateTime->format('Y-m-d'),
                        'start_time' => $nowDateTime->format('H:i'),
                        'due_date' => $dueDate,
                        'due_time' => $dueTime,
                        'metric_targets' => $metricTargets,
                    ], (int) $currentUser['id']);
                    auto_complete_team_goals_for_team(
                        $pdo,
                        $goalTeamId,
                        (string) $settingsForProfileGoal['challenge_start'],
                        (string) $settingsForProfileGoal['challenge_end'],
                        (int) $currentUser['id']
                    );
                    flash_set('success', t('flash.goal_created'));
                    redirect('/?' . http_build_query(['page' => 'team', 'team_id' => $goalTeamId, 'section' => 'challenge']));
                }
                create_goal($pdo, [
                    'scope' => 'user',
                    'team_id' => null,
                    'user_id' => (int) $profileUser['id'],
                    'title' => $title,
                    'target_type' => $goalType,
                    'target_value' => $targetValue,
                    'current_value' => 0,
                    'due_date' => $dueDate,
                    'metric_targets' => $metricTargets,
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
            $updatedGoalType = normalize_goal_target_type((string) ($_POST['target_type'] ?? 'custom'));
            $updatedGoalValue = ($_POST['target_value'] ?? '') !== '' ? (float) $_POST['target_value'] : null;
            try {
                $updatedMetricTargets = goal_metric_targets_from_form($_POST, $updatedGoalType, (float) ($updatedGoalValue ?? 0));
            } catch (InvalidArgumentException $exception) {
                flash_set('error', $exception->getMessage());
                redirect($profileUrl('goals', ['goal_id' => $goalId]));
            }
            update_goal($pdo, $goalId, [
                'title' => trim((string) ($_POST['title'] ?? '')),
                'target_type' => $updatedGoalType,
                'target_value' => $updatedGoalValue,
                'due_date' => ($_POST['due_date'] ?? '') !== '' ? to_date((string) $_POST['due_date']) : null,
                'metric_targets' => $updatedMetricTargets,
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
            $primaryGoalType = in_array((string) ($_POST['primary_goal_type'] ?? 'none'), ['none', 'steps', 'km', 'workouts'], true)
                ? (string) $_POST['primary_goal_type']
                : 'none';
            $primaryGoalValue = ($_POST['primary_goal_value'] ?? '') !== '' && is_numeric($_POST['primary_goal_value'])
                ? max(0.0, (float) $_POST['primary_goal_value'])
                : 0.0;
            if ($primaryGoalType !== 'none' && $primaryGoalValue <= 0) {
                $primaryGoalType = 'none';
            }
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
                    ':primary_goal_value' => $primaryGoalType !== 'none' ? $primaryGoalValue : null,
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
            flash_set('error', t('flash.no_permission'));
            redirect($profileUrl('achievements'));
        }
    }

    $settings = challenge_settings($pdo, $config);
    $profileChallengeArchives = list_challenge_archives($pdo);
    $profileChallengeOptions = [
        [
            'key' => 'current',
            'id' => null,
            'name' => (string) ($settings['challenge_name'] ?? 'Fitness Challenge'),
            'start' => (string) ($settings['challenge_start'] ?? to_date(null)),
            'end' => (string) ($settings['challenge_end'] ?? to_date(null)),
            'is_archive' => false,
            'archived_at' => '',
        ]
    ];
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
           AND has_photo = 1
           AND TRIM(COALESCE(file_path, "")) != ""
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
    $personalGoals = hydrate_user_goal_metric_targets(
        $pdo,
        $personalGoals,
        (int) $profileUser['id'],
        $profileChallengeStart,
        $profileChallengeEnd,
        is_array($profileMetric) ? $profileMetric : []
    );
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

    $profileAllWidgets = ['goals', 'friends', 'teams', 'training_rank', 'training_progress', 'achievements', 'duels', 'competitions', 'setup', 'activity'];
    foreach ($profileCustomWidgets as $profileCustomWidget) {
        $profileAllWidgets[] = profile_custom_widget_key((int) $profileCustomWidget['id']);
    }
    $profileSavedWidgets = json_decode((string) ($profileUser['profile_layout_json'] ?? ''), true);
    $profileKnownWidgets = json_decode((string) ($profileUser['profile_widgets_known'] ?? ''), true);
    $profileKnownWidgets = is_array($profileKnownWidgets) ? array_values(array_map('strval', $profileKnownWidgets)) : [];
    $profileUnknownWidgets = array_values(array_diff($profileAllWidgets, $profileKnownWidgets));
    if ($profileUnknownWidgets !== [] && $isOwnProfile) {
        $profileLayoutUpdate = null;
        if (is_array($profileSavedWidgets) && $profileSavedWidgets !== []) {
            $profileLayoutUpdate = array_values(array_unique(array_map('strval', $profileSavedWidgets)));
            $newTeamsWidgets = array_values(array_intersect(['teams'], $profileUnknownWidgets));
            if ($newTeamsWidgets !== []) {
                $friendsPosition = array_search('friends', $profileLayoutUpdate, true);
                $insertAt = $friendsPosition === false ? min(2, count($profileLayoutUpdate)) : (int) $friendsPosition + 1;
                array_splice($profileLayoutUpdate, $insertAt, 0, $newTeamsWidgets);
            }
            $newTrainingWidgets = array_values(array_intersect(['training_rank', 'training_progress'], $profileUnknownWidgets));
            if ($newTrainingWidgets !== []) {
                $friendsPosition = array_search('friends', $profileLayoutUpdate, true);
                $insertAt = $friendsPosition === false ? min(2, count($profileLayoutUpdate)) : (int) $friendsPosition + 1;
                array_splice($profileLayoutUpdate, $insertAt, 0, $newTrainingWidgets);
            }
            $profileLayoutUpdate = array_values(array_unique(array_merge(
                $profileLayoutUpdate,
                array_values(array_diff($profileUnknownWidgets, $newTeamsWidgets, $newTrainingWidgets, $profileLayoutUpdate))
            )));
        }
        db_execute(
            $pdo,
            'UPDATE users SET profile_layout_json = COALESCE(:layout, profile_layout_json), profile_widgets_known = :known, updated_at = :updated_at WHERE id = :id',
            [
                ':layout' => $profileLayoutUpdate !== null ? json_encode($profileLayoutUpdate, JSON_UNESCAPED_SLASHES) : null,
                ':known' => json_encode($profileAllWidgets, JSON_UNESCAPED_SLASHES),
                ':updated_at' => now_iso(),
                ':id' => (int) $profileUser['id'],
            ]
        );
        if ($profileLayoutUpdate !== null) {
            $profileUser['profile_layout_json'] = json_encode($profileLayoutUpdate, JSON_UNESCAPED_SLASHES);
        }
        $profileUser['profile_widgets_known'] = json_encode($profileAllWidgets, JSON_UNESCAPED_SLASHES);
    }

    $profileTrainingUserId = (int) $profileUser['id'];
    $profileTrainingRank = wk_overall_rank_for_user($pdo, $profileTrainingUserId);
    $profileTrainingPosition = null;
    foreach (wk_rank_leaderboard($pdo, 100) as $profileTrainingRow) {
        if ((int) ($profileTrainingRow['id'] ?? 0) === $profileTrainingUserId) {
            $profileTrainingPosition = isset($profileTrainingRow['position'])
                ? (int) $profileTrainingRow['position']
                : null;
            break;
        }
    }
    $profileTrainingMonth = wk_summary_for_user(
        $pdo,
        $profileTrainingUserId,
        (new DateTimeImmutable('first day of this month'))->format('Y-m-d 00:00:00')
    );
    $profileTrainingAll = wk_summary_for_user($pdo, $profileTrainingUserId);
    $profileTrainingStreak = wk_streak_days($pdo, $profileTrainingUserId);
    $profileTrainingRecentSessions = wk_sessions_for_user($pdo, $profileTrainingUserId, 3);
    $profileTrainingRecords = wk_personal_records_for_user($pdo, $profileTrainingUserId, 3);
    $profileTrainingMuscles = array_values(array_filter(
        wk_muscle_ranks_for_user($pdo, $profileTrainingUserId),
        static fn(array $row): bool => (float) ($row['rank']['score'] ?? 0) > 0
    ));
    usort(
        $profileTrainingMuscles,
        static fn(array $left, array $right): int =>
        (float) ($right['rank']['score'] ?? 0) <=> (float) ($left['rank']['score'] ?? 0)
    );
    $profileTrainingMuscles = array_slice($profileTrainingMuscles, 0, 4);

    $profileUser = db_fetch_one($pdo, 'SELECT * FROM users WHERE id = :id', [':id' => (int) $profileUser['id']]) ?? $profileUser;

    $profileGoalCards = build_user_goal_view_models($personalGoals, is_array($profileMetric) ? $profileMetric : [], $habitDefinitions);
    duels_ensure_schema($pdo);
    $profileDuelsSummary = duels_summary_for_user($pdo, (int) $profileUser['id']);
    $profileCompetitionsSummary = comp_summary_for_user($pdo, (int) $profileUser['id']);
    $profileFriends = friends_list($pdo, (int) $profileUser['id']);
    $profileFriendStatus = $isOwnProfile ? 'self' : friends_status($pdo, (int) $currentUser['id'], (int) $profileUser['id']);
    $profileFriendIncoming = $isOwnProfile ? friends_incoming($pdo, (int) $currentUser['id']) : [];
    $profileFriendOutgoing = $isOwnProfile ? friends_outgoing($pdo, (int) $currentUser['id']) : [];
    $profileFriendAddable = $isOwnProfile ? friends_addable_users($pdo, (int) $currentUser['id']) : [];

    render_view('profile', [
        'title' => t('profile.title'),
        'currentPage' => 'profile',
        'currentUser' => $currentUser,
        'profileUser' => $profileUser,
        'profileMetric' => $profileMetric,
        'profileXp' => xp_user_level_info($pdo, (int) $profileUser['id']),
        'profileCosmetics' => $isOwnProfile ? cosmetics_for_user($pdo, $currentUser) : [],
        'isOwnProfile' => $isOwnProfile,
        'canEditProfile' => $canEditProfile,
        'canExportProfilePdf' => is_admin($currentUser),
        'profileDataAccess' => $profileDataAccess,
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
        'profileBackUrl' => $profileBackUrl,
        'profileBackParams' => $profileBackParams,
        'personalGoals' => $personalGoals,
        'profileGoalCards' => $profileGoalCards,
        'profileFriends' => $profileFriends,
        'profileFriendStatus' => $profileFriendStatus,
        'profileFriendIncoming' => $profileFriendIncoming,
        'profileFriendOutgoing' => $profileFriendOutgoing,
        'profileFriendAddable' => $profileFriendAddable,
        'profileCustomMetrics' => $isOwnProfile ? custom_metrics_for_user($pdo, (int) $profileUser['id']) : [],
        'userAchievements' => list_awarded_achievements($pdo, (int) $profileUser['id'], null),
        'profileAchievementCollection' => list_achievement_collection(
            $pdo,
            'user',
            (int) $profileUser['id'],
            null,
            $metrics
        ),
        'profileDuelsSummary' => $profileDuelsSummary,
        'profileCompetitionsSummary' => $profileCompetitionsSummary,
        'profileTeams' => $profileTeams,
        'profileGoalTeams' => $profileGoalTeams,
        'profileCustomWidgets' => $profileCustomWidgets,
        'profileTrainingRank' => $profileTrainingRank,
        'profileTrainingPosition' => $profileTrainingPosition,
        'profileTrainingMonth' => $profileTrainingMonth,
        'profileTrainingAll' => $profileTrainingAll,
        'profileTrainingStreak' => $profileTrainingStreak,
        'profileTrainingRecentSessions' => $profileTrainingRecentSessions,
        'profileTrainingRecords' => $profileTrainingRecords,
        'profileTrainingMuscles' => $profileTrainingMuscles,
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
        if ((string) ($_GET['back'] ?? '') === 'dashboard') {
            $backParams = ['page' => 'dashboard'];
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
    $achievementFilter = strtolower(trim((string) ($_GET['filter'] ?? 'all')));
    if (!in_array($achievementFilter, ['all', 'unlocked', 'locked'], true)) {
        $achievementFilter = 'all';
    }
    $achievementsRedirect = $achievementsUrl . ($achievementFilter !== 'all' ? '&filter=' . rawurlencode($achievementFilter) : '');

    if (is_post()) {
        if (!csrf_verify()) {
            flash_set('error', t('flash.csrf'));
            redirect($achievementsRedirect);
        }
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'delete_achievement_award') {
            flash_set('error', t('flash.no_permission'));
            redirect($achievementsRedirect);
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
        'achievementsUrl' => $achievementsUrl,
        'backHref' => $backHref,
        'config' => $config,
    ]);
}

if ($page === 'quests') {
    quests_ensure_schema($pdo);
    $questsBoard = quests_for_user($pdo, $currentUser);
    $questsCompletionCounts = quests_completion_counts_for_user($pdo, (int) $currentUser['id']);
    foreach ($questsBoard as &$questRow) {
        $history = (array) ($questsCompletionCounts[(string) ($questRow['key'] ?? '')] ?? []);
        $questRow['completion_count'] = max(0, (int) ($history['count'] ?? 0));
        $questRow['last_completed_at'] = (string) ($history['last_completed_at'] ?? '');
    }
    unset($questRow);
    $questsLevel = (int) (xp_user_level_info($pdo, (int) $currentUser['id'])['level'] ?? 1);

    render_view('quests', [
        'title' => t('quests.title'),
        'currentPage' => 'quests',
        'currentUser' => $currentUser,
        'questsBoard' => $questsBoard,
        'questsRank' => quests_rank_for_level($questsLevel),
        'questsLevel' => $questsLevel,
        'questsStreak' => quests_active_streak($pdo, (int) $currentUser['id']),
        'questsCompletionTotal' => array_sum(array_map(
            static fn(array $history): int => max(0, (int) ($history['count'] ?? 0)),
            $questsCompletionCounts
        )),
        'config' => $config,
    ]);
}

if ($page === 'pwa_install_guide') {
    render_view('pwa_install_guide', [
        'title' => t('pwa_guide.title'),
        'currentPage' => 'pwa_install_guide',
        'currentUser' => $currentUser,
    ]);
}

if ($page === 'season') {
    seasons_ensure_schema($pdo);
    $season = seasons_current($pdo);
    $seasonBoard = season_leaderboard($pdo, $season, 50);
    $seasonUserXp = season_xp_for_user($pdo, (int) $currentUser['id'], $season);
    $seasonUserPosition = null;
    foreach ($seasonBoard as $seasonRow) {
        if ((int) ($seasonRow['user_id'] ?? 0) === (int) $currentUser['id']) {
            $seasonUserPosition = (int) ($seasonRow['rank'] ?? 0);
            break;
        }
    }

    render_view('season', [
        'title' => t('season.leaderboard'),
        'currentPage' => 'season',
        'currentUser' => $currentUser,
        'season' => $season,
        'seasonBoard' => $seasonBoard,
        'seasonUserXp' => $seasonUserXp,
        'seasonUserPosition' => $seasonUserPosition,
        'seasonDaysLeft' => season_days_left($season),
        'config' => $config,
    ]);
}

if ($page === 'admin') {
    require_admin($currentUser);
    workouts_ensure_schema($pdo);
    seasons_ensure_schema($pdo);

    if (is_post()) {
        if (!csrf_verify()) {
            flash_set('error', t('flash.csrf'));
            redirect('/?page=admin');
        }

        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'send_admin_notification') {
            try {
                $notificationResult = send_admin_notification(
                    $pdo,
                    (int) $currentUser['id'],
                    (string) ($_POST['notification_target'] ?? ''),
                    (string) ($_POST['notification_kind'] ?? ''),
                    (string) ($_POST['notification_title'] ?? ''),
                    (string) ($_POST['notification_message'] ?? '')
                );
                flash_set(
                    'success',
                    t('flash.admin_notification_sent', [
                        'count' => (int) $notificationResult['recipient_count'],
                    ])
                );
            } catch (Throwable $exception) {
                flash_set(
                    'error',
                    $exception->getMessage() !== '' ? $exception->getMessage() : t('flash.save_failed')
                );
            }
            redirect('/?page=admin&section=notifications');
        }

        if ($action === 'update_security_settings') {
            try {
                $runtimeSecurity = security_runtime_settings($pdo, $config);
                if ($runtimeSecurity['allowed_hosts_source'] === 'environment') {
                    $allowedHosts = array_values((array) ($runtimeSecurity['allowed_hosts'] ?? []));
                } else {
                    $allowedHosts = security_parse_allowed_hosts((string) ($_POST['allowed_hosts'] ?? ''), true);
                    $requestHost = security_request_host($config);
                    if (
                        $allowedHosts !== []
                        && ($requestHost === null || !security_host_matches_allowed($requestHost['host'], $allowedHosts))
                    ) {
                        throw new InvalidArgumentException(t('security.hosts_must_include_current'));
                    }
                    set_app_setting($pdo, 'security_allowed_hosts', implode("\n", $allowedHosts), (int) $currentUser['id']);
                }
                $retentionDays = max(7, min(365, (int) ($_POST['retention_days'] ?? 90)));
                set_app_setting($pdo, 'security_auto_block', bool_from_form('auto_block') === 1 ? '1' : '0', (int) $currentUser['id']);
                set_app_setting($pdo, 'security_log_retention_days', (string) $retentionDays, (int) $currentUser['id']);
                flash_set('success', t('security.settings_saved'));
            } catch (Throwable $exception) {
                flash_set('error', $exception->getMessage() !== '' ? $exception->getMessage() : t('flash.save_failed'));
            }
            redirect('/?page=admin&section=security');
        }

        if ($action === 'security_unblock_ip') {
            $blockedIp = trim((string) ($_POST['ip_address'] ?? ''));
            $beforeBlock = db_fetch_one($pdo, 'SELECT * FROM security_ip_blocks WHERE ip_address = :ip', [':ip' => $blockedIp]);
            if ($beforeBlock !== null && security_unblock_ip($pdo, $blockedIp)) {
                audit_log(
                    $pdo,
                    (int) $currentUser['id'],
                    'security_ip_unblocked',
                    'security_ip',
                    $blockedIp,
                    'Security IP block removed.',
                    audit_snapshot($beforeBlock),
                    null
                );
                flash_set('success', t('security.ip_unblocked', ['ip' => $blockedIp]));
            } else {
                flash_set('error', t('security.ip_unblock_failed'));
            }
            redirect('/?page=admin&section=security');
        }

        if ($action === 'save_media_search_settings') {
            media_search_set_enabled(
                $pdo,
                (string) ($_POST['media_search_enabled'] ?? '0') === '1',
                (int) $currentUser['id']
            );
            flash_set('success', t('admin.media_search_saved'));
            redirect('/?page=admin&section=training');
        }

        if ($action === 'save_media_search_credentials') {
            try {
                media_search_update_credentials($pdo, $_POST, (int) $currentUser['id']);
                flash_set('success', t('admin.media_search_credentials_saved'));
            } catch (Throwable $e) {
                flash_set('error', $e->getMessage() !== '' ? $e->getMessage() : t('flash.save_failed'));
            }
            redirect('/?page=admin&section=training#media-providers');
        }

        if ($action === 'test_media_search_provider') {
            $provider = (string) ($_POST['provider'] ?? '');
            $type = $provider === 'youtube' ? 'video' : 'image';
            try {
                $mediaSearchConfig = media_search_effective_config($pdo, $config);
                media_search_query($mediaSearchConfig, $type, 'fitness exercise technique', (int) $currentUser['id'], current_locale());
                flash_set('success', t('admin.media_search_test_ok', ['provider' => $provider === 'youtube' ? 'YouTube' : 'Google Images']));
            } catch (Throwable $e) {
                flash_set('error', t('admin.media_search_test_failed', ['error' => $e->getMessage()]));
            }
            redirect('/?page=admin&section=training#media-providers');
        }

        if ($action === 'save_rank_tiers') {
            try {
                wk_admin_save_rank_tiers($pdo, (array) ($_POST['tiers'] ?? []), (int) $currentUser['id']);
                flash_set('success', 'Rank tiers saved.');
            } catch (Throwable $e) {
                flash_set('error', $e->getMessage());
            }
            redirect('/?page=admin&section=training');
        }

        if ($action === 'save_season') {
            $seasonId = (int) ($_POST['season_id'] ?? 0);
            $existingSeason = $seasonId > 0 ? db_fetch_one($pdo, 'SELECT * FROM seasons WHERE id = :id', [':id' => $seasonId]) : null;
            $previousCoverPath = trim((string) ($existingSeason['cover_path'] ?? ''));
            $nextCoverPath = $previousCoverPath;
            $createdCoverPath = '';
            try {
                $seasonCoverUpload = is_array($_FILES['season_cover'] ?? null) ? (array) $_FILES['season_cover'] : [];
                if ((int) ($seasonCoverUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $createdCoverPath = save_uploaded_image($config, $seasonCoverUpload, 'seasons/covers', 'season_' . ($seasonId > 0 ? $seasonId : 'new'));
                    $nextCoverPath = $createdCoverPath;
                } elseif (!empty($_POST['remove_season_cover'])) {
                    $nextCoverPath = '';
                }
                $savedSeasonId = season_admin_save(
                    $pdo,
                    $seasonId > 0 ? $seasonId : null,
                    (string) ($_POST['season_key'] ?? ''),
                    (string) ($_POST['season_name'] ?? ''),
                    (string) ($_POST['start_date'] ?? ''),
                    (string) ($_POST['end_date'] ?? ''),
                    (int) $currentUser['id'],
                    (string) ($_POST['icon_key'] ?? 'trophy'),
                    $nextCoverPath,
                    (string) ($_POST['accent_color'] ?? '#8b5cf6'),
                    $existingSeason !== null ? (string) ($existingSeason['generation_source'] ?? 'manual') : 'manual'
                );
                if ($previousCoverPath !== '' && $previousCoverPath !== $nextCoverPath) {
                    $oldCoverFile = resolve_media_storage_path($config, $previousCoverPath);
                    if ($oldCoverFile !== null && is_file($oldCoverFile)) {
                        @unlink($oldCoverFile);
                    }
                }
                flash_set('success', t('admin.season_saved'));
            } catch (Throwable $e) {
                if ($createdCoverPath !== '') {
                    $createdCoverFile = resolve_media_storage_path($config, $createdCoverPath);
                    if ($createdCoverFile !== null && is_file($createdCoverFile)) {
                        @unlink($createdCoverFile);
                    }
                }
                flash_set('error', $e->getMessage());
            }
            redirect('/?page=admin&section=training#season-planner');
        }

        if ($action === 'save_season_automation') {
            try {
                $settings = seasons_automation_update(
                    $pdo,
                    (string) ($_POST['season_auto_enabled'] ?? '0') === '1',
                    (int) ($_POST['duration_weeks'] ?? 12),
                    (int) ($_POST['ahead_count'] ?? 4),
                    (int) $currentUser['id']
                );
                $generated = $settings['enabled'] ? seasons_generate_upcoming($pdo, (int) $currentUser['id'], null, true) : ['created' => 0];
                flash_set('success', t('admin.season_automation_saved', ['count' => (int) ($generated['created'] ?? 0)]));
            } catch (Throwable $e) {
                flash_set('error', $e->getMessage() !== '' ? $e->getMessage() : t('flash.save_failed'));
            }
            redirect('/?page=admin&section=training#season-planner');
        }

        if ($action === 'delete_season') {
            try {
                $seasonId = (int) ($_POST['season_id'] ?? 0);
                $seasonBeforeDelete = db_fetch_one($pdo, 'SELECT * FROM seasons WHERE id = :id', [':id' => $seasonId]);
                if (!season_admin_delete($pdo, $seasonId, (int) $currentUser['id'])) {
                    throw new InvalidArgumentException('Season not found.');
                }
                $seasonCoverPath = trim((string) ($seasonBeforeDelete['cover_path'] ?? ''));
                if ($seasonCoverPath !== '') {
                    $seasonCoverFile = resolve_media_storage_path($config, $seasonCoverPath);
                    if ($seasonCoverFile !== null && is_file($seasonCoverFile)) {
                        @unlink($seasonCoverFile);
                    }
                }
                flash_set('success', t('admin.season_removed'));
            } catch (Throwable $e) {
                flash_set('error', $e->getMessage());
            }
            redirect('/?page=admin&section=training#season-planner');
        }

        if ($action === 'save_training_exercise') {
            $exerciseId = (int) ($_POST['exercise_id'] ?? 0);
            $newMediaPaths = [];
            $mediaTransaction = false;
            try {
                $existingExercise = $exerciseId > 0 ? wk_exercise_get($pdo, $exerciseId) : null;
                if ($exerciseId > 0 && $existingExercise === null) {
                    throw new InvalidArgumentException('Exercise not found.');
                }
                $existingMedia = $existingExercise !== null ? wk_exercise_media_list($pdo, $existingExercise) : [];
                $oldMediaPaths = array_values(array_filter(array_map(static fn(array $item): string => trim((string) ($item['path'] ?? '')), $existingMedia)));
                $galleryEditorSubmitted = !empty($_POST['gallery_editor']);
                $submittedOrder = $galleryEditorSubmitted
                    ? array_values((array) ($_POST['gallery_order'] ?? []))
                    : $oldMediaPaths;
                $submittedPositions = $galleryEditorSubmitted
                    ? array_values((array) ($_POST['gallery_position'] ?? []))
                    : array_map(static fn(array $item): string => (string) ($item['position'] ?? 'center'), $existingMedia);
                $submittedCaptions = $galleryEditorSubmitted
                    ? array_values((array) ($_POST['gallery_caption'] ?? []))
                    : array_map(static fn(array $item): string => (string) ($item['caption'] ?? ''), $existingMedia);
                $rawCoverToken = $_POST['gallery_cover'] ?? ($existingExercise['image_path'] ?? '');
                $coverToken = is_scalar($rawCoverToken) ? trim((string) $rawCoverToken) : '';
                if (!$galleryEditorSubmitted && isset($_POST['image_position'])) {
                    $legacyCoverIndex = array_search($coverToken, $submittedOrder, true);
                    if (is_int($legacyCoverIndex)) {
                        $submittedPositions[$legacyCoverIndex] = (string) $_POST['image_position'];
                    }
                }
                if (!empty($_POST['remove_image'])) {
                    $submittedOrder = [];
                    $submittedPositions = [];
                    $submittedCaptions = [];
                    $coverToken = '';
                }
                $legacyUploads = normalize_uploaded_file_list((array) ($_FILES['exercise_image'] ?? []));
                $galleryUploads = normalize_uploaded_file_list((array) ($_FILES['exercise_images'] ?? []));
                if ($legacyUploads !== []) {
                    $galleryUploads = [$legacyUploads[0]];
                    $existingMedia = [];
                    $submittedOrder = ['new:0'];
                    $submittedPositions = [(string) ($_POST['image_position'] ?? 'center')];
                    $submittedCaptions = [''];
                    $coverToken = 'new:0';
                }
                if (count($galleryUploads) > 4) {
                    throw new InvalidArgumentException(t('workouts.gallery_limit'));
                }
                foreach ($galleryUploads as $galleryUpload) {
                    $newMediaPaths[] = save_uploaded_image($config, $galleryUpload, 'workouts/exercises', 'exercise');
                }
                $mediaSubmission = wk_exercise_media_resolve_submission(
                    $existingMedia,
                    $submittedOrder,
                    $newMediaPaths,
                    $coverToken,
                    $_POST['image_position'] ?? ($existingExercise['image_position'] ?? 'center'),
                    $submittedPositions,
                    $submittedCaptions
                );
                $payload = $_POST;
                $payload['image_path'] = $mediaSubmission['cover_path'];
                $payload['image_position'] = $mediaSubmission['cover_position'];
                if (!$pdo->inTransaction()) {
                    $pdo->beginTransaction();
                    $mediaTransaction = true;
                }
                $savedId = wk_admin_save_exercise(
                    $pdo,
                    $exerciseId > 0 ? $exerciseId : null,
                    $payload,
                    (int) $currentUser['id']
                );
                wk_exercise_media_replace($pdo, $savedId, $mediaSubmission['items']);
                if ($mediaTransaction && $pdo->inTransaction()) {
                    $pdo->commit();
                    $mediaTransaction = false;
                }
                $keptMediaPaths = array_map(static fn(array $item): string => (string) $item['path'], $mediaSubmission['items']);
                wk_exercise_media_cleanup_unreferenced(
                    $pdo,
                    $config,
                    array_values(array_diff(array_merge($oldMediaPaths, $newMediaPaths), $keptMediaPaths))
                );
                flash_set('success', 'Training exercise saved.');
                redirect('/?page=admin&section=training&exercise_id=' . $savedId);
            } catch (Throwable $e) {
                if ($mediaTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($newMediaPaths !== []) {
                    wk_exercise_media_cleanup_unreferenced($pdo, $config, $newMediaPaths);
                }
                flash_set('error', $e->getMessage());
                $target = '/?page=admin&section=training';
                if ($exerciseId > 0) {
                    $target .= '&exercise_id=' . $exerciseId;
                }
                redirect($target);
            }
        }

        if ($action === 'delete_training_exercise') {
            try {
                $deleteExerciseId = (int) ($_POST['exercise_id'] ?? 0);
                $deleteExercise = wk_exercise_get($pdo, $deleteExerciseId);
                $deleteMediaPaths = $deleteExercise !== null
                    ? array_map(static fn(array $item): string => (string) ($item['path'] ?? ''), wk_exercise_media_list($pdo, $deleteExercise))
                    : [];
                if (!wk_admin_delete_exercise($pdo, $deleteExerciseId, (int) $currentUser['id'])) {
                    throw new InvalidArgumentException('Exercise not found.');
                }
                wk_exercise_media_cleanup_unreferenced($pdo, $config, $deleteMediaPaths);
                flash_set('success', 'Training exercise removed.');
            } catch (Throwable $e) {
                flash_set('error', $e->getMessage());
            }
            redirect('/?page=admin&section=training');
        }

        if ($action === 'admin_delete_workout_session') {
            try {
                $deleteSessionId = max(0, (int) ($_POST['session_id'] ?? 0));
                if (!wk_session_delete($pdo, $deleteSessionId, (int) $currentUser['id'], true)) {
                    throw new InvalidArgumentException(t('workouts.session_delete_failed'));
                }
                flash_set('success', t('workouts.session_deleted'));
            } catch (Throwable $exception) {
                flash_set('error', t('workouts.session_delete_failed'));
            }
            redirect('/?page=admin&section=training#session-management');
        }

        if ($action === 'update_app_name') {
            set_app_setting($pdo, 'app_name', trim((string) ($_POST['app_name'] ?? '')) ?: (string) ($config['app_name'] ?? 'Fitness Challenge Tracker'), (int) $currentUser['id']);
            flash_set('success', t('flash.app_name_updated'));
            redirect('/?page=admin&section=app');
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
            try {
                telegram_update_settings($pdo, $_POST, (int) $currentUser['id']);
                flash_set('success', t('flash.telegram_settings_updated'));
            } catch (InvalidArgumentException $exception) {
                flash_set('error', $exception->getMessage());
            }
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
            try {
                if (bool_from_form('backup_before_update') === 1) {
                    create_system_backup($pdo, $config, 'pre_restore', (int) $currentUser['id']);
                    prune_system_backups($pdo, $config, max(1, (int) (system_backup_settings($pdo)['retention_count'] ?? 20)));
                }
                update_challenge_settings(
                    $pdo,
                    (string) ($_POST['challenge_name'] ?? ''),
                    (string) ($_POST['challenge_start'] ?? ''),
                    (string) ($_POST['challenge_end'] ?? ''),
                    (int) $currentUser['id']
                );
                flash_set('success', t('flash.challenge_updated'));
            } catch (Throwable $e) {
                flash_set('error', t('flash.challenge_change_failed', ['error' => $e->getMessage()]));
            }
            redirect('/?page=admin&section=challenge');
        }

        if ($action === 'start_new_challenge') {
            try {
                if (bool_from_form('backup_before_start') === 1) {
                    create_system_backup($pdo, $config, 'pre_restore', (int) $currentUser['id']);
                    prune_system_backups($pdo, $config, max(1, (int) (system_backup_settings($pdo)['retention_count'] ?? 20)));
                }
                start_new_challenge(
                    $pdo,
                    (string) ($_POST['new_challenge_name'] ?? ''),
                    (string) ($_POST['new_challenge_start'] ?? ''),
                    (string) ($_POST['new_challenge_end'] ?? ''),
                    (int) $currentUser['id']
                );
                $newChallengeName = trim((string) ($_POST['new_challenge_name'] ?? '')) ?: 'Fitness Challenge';
                $newChallengeStart = to_date((string) ($_POST['new_challenge_start'] ?? ''));
                $newChallengeEnd = to_date((string) ($_POST['new_challenge_end'] ?? ''), $newChallengeStart);
                if ((string) ($_POST['ranked_season_action'] ?? 'keep') === 'reset') {
                    seasons_ensure_schema($pdo);
                    season_admin_save(
                        $pdo,
                        null,
                        'challenge-' . str_replace('-', '', $newChallengeStart) . '-' . time(),
                        $newChallengeName,
                        $newChallengeStart,
                        $newChallengeEnd,
                        (int) $currentUser['id'],
                        'trophy',
                        '',
                        '#8b5cf6',
                        'manual'
                    );
                }
                if ((string) ($_POST['xp_action'] ?? 'keep') === 'reset') {
                    foreach (list_active_users($pdo) as $xpUser) {
                        $xpUserId = (int) ($xpUser['id'] ?? 0);
                        $xpCurrentTotal = xp_total($pdo, $xpUserId);
                        if ($xpCurrentTotal > 0) {
                            xp_adjust(
                                $pdo,
                                $xpUserId,
                                -$xpCurrentTotal,
                                'Reset when starting challenge: ' . $newChallengeName,
                                (int) $currentUser['id']
                            );
                        }
                    }
                }
                flash_set('success', t('flash.challenge_started'));
            } catch (Throwable $e) {
                flash_set('error', t('flash.challenge_change_failed', ['error' => $e->getMessage()]));
            }
            redirect('/?page=admin&section=challenge');
        }

        if ($action === 'archive_challenge') {
            try {
                if ((string) ($_POST['confirm_archive'] ?? '') !== 'ARCHIVE') {
                    throw new RuntimeException(t('admin.challenge_archive_confirmation_error'));
                }
                if (bool_from_form('backup_before_archive') === 1) {
                    create_system_backup($pdo, $config, 'pre_restore', (int) $currentUser['id']);
                    prune_system_backups($pdo, $config, max(1, (int) (system_backup_settings($pdo)['retention_count'] ?? 20)));
                }
                archive_challenge($pdo, (int) $currentUser['id']);
                flash_set('success', t('flash.challenge_archived'));
            } catch (Throwable $e) {
                flash_set('error', t('flash.challenge_change_failed', ['error' => $e->getMessage()]));
            }
            redirect('/?page=admin&section=challenge');
        }

        if ($action === 'reactivate_challenge') {
            try {
                $archiveId = (int) ($_POST['archive_id'] ?? 0);
                if ((string) ($_POST['confirm_restore'] ?? '') !== 'RESTORE') {
                    throw new RuntimeException(t('admin.challenge_restore_confirmation_error'));
                }
                if (bool_from_form('backup_before_restore') === 1) {
                    create_system_backup($pdo, $config, 'pre_restore', (int) $currentUser['id']);
                    prune_system_backups($pdo, $config, max(1, (int) (system_backup_settings($pdo)['retention_count'] ?? 20)));
                }
                if ($archiveId <= 0 || !reactivate_challenge($pdo, $archiveId, (int) $currentUser['id'])) {
                    throw new RuntimeException(t('flash.challenge_reactivate_failed'));
                }
                flash_set('success', t('flash.challenge_reactivated_safe'));
            } catch (Throwable $e) {
                flash_set('error', t('flash.challenge_change_failed', ['error' => $e->getMessage()]));
            }
            redirect('/?page=admin&section=challenge');
        }

        if ($action === 'create_challenge_backup') {
            try {
                $backup = create_system_backup($pdo, $config, 'manual', (int) $currentUser['id']);
                $settings = system_backup_settings($pdo);
                prune_system_backups($pdo, $config, max(1, (int) ($settings['retention_count'] ?? 20)));
                audit_log(
                    $pdo,
                    (int) $currentUser['id'],
                    'backup_created',
                    'system_backup',
                    (string) ($backup['id'] ?? ''),
                    'Manual backup created from challenge administration.',
                    null,
                    ['trigger' => 'manual', 'source' => 'challenge_admin']
                );
                flash_set('success', t('flash.backup_created'));
            } catch (Throwable $e) {
                flash_set('error', t('flash.backup_failed', ['error' => $e->getMessage()]));
            }
            redirect('/?page=admin&section=challenge');
        }

        if ($action === 'create_registration_invite') {
            try {
                $createdInvite = create_registration_invite(
                    $pdo,
                    (int) $currentUser['id'],
                    (string) ($_POST['invite_label'] ?? ''),
                    (int) ($_POST['expires_in_days'] ?? 7),
                    (int) ($_POST['max_uses'] ?? 1)
                );
                $_SESSION['registration_invite_url'] = registration_app_base_url($pdo)
                    . '/?page=register&token=' . rawurlencode((string) $createdInvite['token']);
                flash_set('success', t('admin.invite_created'));
            } catch (Throwable $e) {
                flash_set('error', $e->getMessage() !== '' ? $e->getMessage() : t('admin.invite_create_failed'));
            }
            redirect('/?page=admin&section=registration_links');
        }

        if ($action === 'update_public_registration') {
            $enabled = bool_from_form('public_registration_enabled') === 1;
            set_app_setting($pdo, 'public_registration_enabled', $enabled ? '1' : '0', (int) $currentUser['id']);
            flash_set('success', t($enabled ? 'admin.public_registration_enabled_flash' : 'admin.public_registration_disabled_flash'));
            redirect('/?page=admin&section=users');
        }

        if ($action === 'revoke_registration_invite') {
            $revoked = revoke_registration_invite($pdo, (int) ($_POST['invite_id'] ?? 0), (int) $currentUser['id']);
            flash_set($revoked ? 'success' : 'error', $revoked ? t('admin.invite_revoked') : t('admin.invite_revoke_failed'));
            redirect('/?page=admin&section=registration_links');
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
                'onboarding_status' => bool_from_form('require_onboarding') === 1 ? 'pending' : 'complete',
                'active' => bool_from_form('active'),
            ];

            if ($payload['username'] === '' || $payload['display_name'] === '' || $payload['password'] === '') {
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

            redirect('/?page=admin&section=users');
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
            $typeId = (int) ($_POST['type_id'] ?? 0);
            $typeTranslationsInput = is_array($_POST['translations'] ?? null) ? (array) $_POST['translations'] : null;
            $typeEnglishInput = is_array($typeTranslationsInput['en'] ?? null) ? (array) $typeTranslationsInput['en'] : [];
            try {
                rename_workout_type($pdo, $typeId, (string) ($_POST['name'] ?? ($typeEnglishInput['name'] ?? '')), bool_from_form('active') === 1, (int) $currentUser['id'], $typeTranslationsInput);
                flash_set('success', t('flash.workout_type_updated'));
            } catch (Throwable $e) {
                flash_set('error', $e->getMessage() !== '' ? $e->getMessage() : t('flash.save_failed'));
            }
            redirect('/?page=admin&section=workout_types&type_id=' . $typeId);
        }

        if ($action === 'create_workout_type') {
            $typeTranslationsInput = is_array($_POST['translations'] ?? null) ? (array) $_POST['translations'] : null;
            $typeEnglishInput = is_array($typeTranslationsInput['en'] ?? null) ? (array) $typeTranslationsInput['en'] : [];
            $createdTypeId = null;
            try {
                $createdTypeId = save_workout_type_if_needed($pdo, (string) ($_POST['name'] ?? ($typeEnglishInput['name'] ?? '')), (int) $currentUser['id'], $typeTranslationsInput);
                flash_set('success', t('flash.workout_type_updated'));
            } catch (Throwable $e) {
                flash_set('error', $e->getMessage() !== '' ? $e->getMessage() : t('flash.save_failed'));
            }
            redirect('/?page=admin&section=workout_types' . ($createdTypeId !== null ? '&type_id=' . (int) $createdTypeId : ''));
        }

        if ($action === 'delete_workout_type') {
            delete_workout_type($pdo, (int) ($_POST['type_id'] ?? 0), (int) $currentUser['id']);
            flash_set('success', t('flash.workout_type_removed'));
            redirect('/?page=admin&section=workout_types');
        }

        if ($action === 'save_workout_type_field') {
            $typeId = (int) ($_POST['type_id'] ?? 0);
            $fieldTranslationsInput = is_array($_POST['translations'] ?? null) ? (array) $_POST['translations'] : null;
            $fieldEnglishInput = is_array($fieldTranslationsInput['en'] ?? null) ? (array) $fieldTranslationsInput['en'] : [];
            try {
                save_workout_type_field(
                    $pdo,
                    $typeId,
                    !empty($_POST['field_id']) ? (int) $_POST['field_id'] : null,
                    (string) ($_POST['label'] ?? ($fieldEnglishInput['label'] ?? '')),
                    (string) ($_POST['input_kind'] ?? 'number'),
                    (string) ($_POST['data_key'] ?? ''),
                    bool_from_form('required') === 1,
                    bool_from_form('active') === 1,
                    (int) ($_POST['sort_order'] ?? 0),
                    (int) $currentUser['id'],
                    $fieldTranslationsInput
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
            $habitId = !empty($_POST['habit_id']) ? (int) $_POST['habit_id'] : null;
            $habitTranslationsInput = is_array($_POST['translations'] ?? null) ? (array) $_POST['translations'] : null;
            $habitEnglishInput = is_array($habitTranslationsInput['en'] ?? null) ? (array) $habitTranslationsInput['en'] : [];
            $habitFallbackLabel = (string) ($_POST['label'] ?? ($habitEnglishInput['label'] ?? ''));
            try {
                save_habit_definition(
                    $pdo,
                    $habitId,
                    (string) ($_POST['code'] ?? ''),
                    $habitFallbackLabel,
                    bool_from_form('active') === 1,
                    (int) ($_POST['sort_order'] ?? 0),
                    (int) $currentUser['id'],
                    $habitTranslationsInput
                );
                flash_set('success', t('flash.habit_saved'));
            } catch (Throwable $e) {
                flash_set('error', $e->getMessage() !== '' ? $e->getMessage() : t('flash.save_failed'));
            }
            redirect('/?page=admin&section=habits' . ($habitId !== null ? '&habit_id=' . $habitId : ''));
        }

        if ($action === 'delete_habit') {
            deactivate_habit_definition($pdo, (int) ($_POST['habit_id'] ?? 0), (int) $currentUser['id']);
            flash_set('success', t('flash.habit_saved'));
            redirect('/?page=admin&section=habits');
        }

        if ($action === 'create_achievement') {
            $createdAchievementImagePath = '';
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
                    $createdAchievementImagePath = save_uploaded_image($config, $_FILES['image'], 'achievements', 'achievement');
                    $imagePath = $createdAchievementImagePath;
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
                if ($createdAchievementImagePath !== '') {
                    $failedImageFile = resolve_media_storage_path($config, $createdAchievementImagePath);
                    if ($failedImageFile !== null && is_file($failedImageFile)) {
                        @unlink($failedImageFile);
                    }
                }
                flash_set('error', $e->getMessage() !== '' ? $e->getMessage() : 'Achievement could not be created.');
            }
            redirect('/?page=admin&section=achievements');
        }

        if ($action === 'update_achievement') {
            $uploadedAchievementImagePath = '';
            $previousAchievementImagePath = '';
            try {
                $achievementId = (int) ($_POST['achievement_id'] ?? 0);
                if ($achievementId <= 0) {
                    throw new RuntimeException('Achievement not found.');
                }

                $existing = db_fetch_one($pdo, 'SELECT * FROM achievements WHERE id = :id', [':id' => $achievementId]);
                if ($existing === null) {
                    throw new RuntimeException('Achievement not found.');
                }

                $previousAchievementImagePath = (string) ($existing['image_path'] ?? '');
                $imagePath = bool_from_form('remove_image') === 1 ? '' : $previousAchievementImagePath;
                if (!empty($_FILES['image']['name'])) {
                    $uploadedAchievementImagePath = save_uploaded_image($config, $_FILES['image'], 'achievements', 'achievement');
                    $imagePath = $uploadedAchievementImagePath;
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
                if ($previousAchievementImagePath !== '' && $previousAchievementImagePath !== $imagePath) {
                    $previousAchievementImageFile = resolve_media_storage_path($config, $previousAchievementImagePath);
                    if ($previousAchievementImageFile !== null && is_file($previousAchievementImageFile)) {
                        @unlink($previousAchievementImageFile);
                    }
                }
                flash_set('success', t('flash.achievement_created'));
            } catch (Throwable $e) {
                if ($uploadedAchievementImagePath !== '') {
                    $failedImageFile = resolve_media_storage_path($config, $uploadedAchievementImagePath);
                    if ($failedImageFile !== null && is_file($failedImageFile)) {
                        @unlink($failedImageFile);
                    }
                }
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

        if ($action === 'update_xp_amounts') {
            xp_set_action_amounts($pdo, (array) ($_POST['xp_amounts'] ?? []), (int) $currentUser['id']);
            flash_set('success', t('flash.xp_amounts_updated'));
            redirect('/?page=admin&section=xp');
        }

        if ($action === 'reset_xp_amounts') {
            xp_set_action_amounts($pdo, xp_default_action_amounts(), (int) $currentUser['id']);
            flash_set('success', t('flash.xp_amounts_reset'));
            redirect('/?page=admin&section=xp');
        }

        if ($action === 'adjust_user_xp') {
            $applied = xp_adjust($pdo, (int) ($_POST['user_id'] ?? 0), (int) ($_POST['amount'] ?? 0), (string) ($_POST['note'] ?? ''), (int) $currentUser['id']);
            flash_set($applied !== 0 ? 'success' : 'error', $applied !== 0 ? t('flash.xp_adjusted', ['amount' => ($applied > 0 ? '+' : '') . $applied]) : t('flash.xp_adjust_failed'));
            redirect('/?page=admin&section=xp');
        }

        if ($action === 'create_motivational_quote') {
            try {
                create_motivational_quote($pdo, (string) ($_POST['quote_text'] ?? ''), (int) $currentUser['id'], (string) ($_POST['quote_locale'] ?? 'any'));
                flash_set('success', t('flash.motivational_quote_created'));
            } catch (Throwable $e) {
                flash_set('error', $e->getMessage() !== '' ? $e->getMessage() : 'Motivational quote could not be created.');
            }
            redirect('/?page=admin&section=motivational_quotes');
        }

        if ($action === 'update_motivational_quote') {
            try {
                update_motivational_quote(
                    $pdo,
                    (int) ($_POST['quote_id'] ?? 0),
                    (string) ($_POST['quote_text'] ?? ''),
                    (string) ($_POST['quote_locale'] ?? 'any'),
                    bool_from_form('quote_active') === 1,
                    (int) $currentUser['id']
                );
                flash_set('success', t('flash.motivational_quote_updated'));
            } catch (Throwable $e) {
                flash_set('error', $e->getMessage() !== '' ? $e->getMessage() : 'Motivational quote could not be updated.');
            }
            redirect('/?page=admin&section=motivational_quotes');
        }

        if ($action === 'delete_motivational_quote') {
            delete_motivational_quote($pdo, (int) ($_POST['quote_id'] ?? 0), (int) $currentUser['id']);
            flash_set('success', t('flash.motivational_quote_deleted'));
            redirect('/?page=admin&section=motivational_quotes');
        }

        if ($action === 'resolve_join_request') {
            resolve_team_join_request($pdo, (int) ($_POST['request_id'] ?? 0), (string) ($_POST['decision'] ?? '') === 'approve', (int) $currentUser['id']);
            flash_set('success', t('flash.team_updated'));
            redirect('/?page=admin');
        }

        if ($action === 'update_deploy_ports') {
            require_admin($currentUser);
            $httpPort = (int) ($_POST['http_port'] ?? 0);
            $httpsPort = (int) ($_POST['https_port'] ?? 0);
            $beforeStatus = deploy_port_settings_status();
            try {
                $afterStatus = deploy_port_settings_save($httpPort, $httpsPort);
                audit_log(
                    $pdo,
                    (int) $currentUser['id'],
                    'deploy_ports_updated',
                    'deploy_port_settings',
                    $afterStatus['mode'],
                    'Published HTTP/HTTPS ports updated.',
                    ['http_port' => $beforeStatus['http_port'], 'https_port' => $beforeStatus['https_port'], 'path' => $beforeStatus['path']],
                    ['http_port' => $afterStatus['http_port'], 'https_port' => $afterStatus['https_port'], 'path' => $afterStatus['path']]
                );
                flash_set('success', t('admin.deploy_ports_saved', ['command' => $afterStatus['apply_command']]));
            } catch (Throwable $e) {
                flash_set('error', $e->getMessage() !== '' ? $e->getMessage() : t('flash.save_failed'));
            }
            redirect('/?page=admin&section=backups#deploy-ports');
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

        if ($action === 'verify_backup') {
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
            try {
                $manifest = validate_system_backup_archive($absolutePath);
                mark_system_backup_restore_result($pdo, $backupId, 'verified', (int) $currentUser['id']);
                audit_log(
                    $pdo,
                    (int) $currentUser['id'],
                    'backup_verified',
                    'system_backup',
                    (string) $backupId,
                    'Backup integrity verified.',
                    null,
                    ['manifest_version' => (int) ($manifest['version'] ?? 1)]
                );
                flash_set('success', t('flash.backup_verified'));
            } catch (Throwable $e) {
                mark_system_backup_restore_result($pdo, $backupId, 'error', (int) $currentUser['id'], $e->getMessage());
                audit_log(
                    $pdo,
                    (int) $currentUser['id'],
                    'backup_error',
                    'system_backup',
                    (string) $backupId,
                    'Backup verification failed.',
                    null,
                    ['error' => $e->getMessage()]
                );
                flash_set('error', t('flash.backup_verify_failed', ['error' => $e->getMessage()]));
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
                restore_system_backup_archive($pdo, $config, $absolutePath);
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
            redirect('/?page=admin&section=appearance');
        }

        if ($action === 'set_login_background') {
            $selectedPath = trim((string) ($_POST['login_background_path'] ?? ''));
            if ($selectedPath !== '' && !is_valid_login_background_path($config, $selectedPath)) {
                flash_set('error', t('flash.not_found'));
                redirect('/?page=admin&section=appearance');
            }
            set_app_setting($pdo, 'login_background_path', $selectedPath !== '' ? $selectedPath : null, (int) $currentUser['id']);
            flash_set('success', t('flash.login_background_updated'));
            redirect('/?page=admin&section=appearance');
        }

        if ($action === 'clear_login_background') {
            set_app_setting($pdo, 'login_background_path', null, (int) $currentUser['id']);
            flash_set('success', t('flash.login_background_cleared'));
            redirect('/?page=admin&section=appearance');
        }

        if ($action === 'set_login_style') {
            $style = login_style_normalize($_POST['login_style'] ?? 'split');
            set_app_setting($pdo, 'login_style', $style, (int) $currentUser['id']);
            flash_set('success', t('flash.login_style_updated'));
            redirect('/?page=admin&section=appearance');
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
            redirect('/?page=admin&section=appearance');
        }
    }

    $adminRequestedSection = trim((string) ($_GET['section'] ?? ''));
    $adminRenderableSections = ['users', 'registration_links', 'notifications', 'challenge', 'app', 'appearance', 'notion', 'telegram', 'backups', 'habits', 'workout_types', 'training', 'achievements', 'motivational_quotes', 'xp', 'audit', 'security'];
    if (!in_array($adminRequestedSection, $adminRenderableSections, true)) {
        render_view('admin', [
            'title' => t('admin.title'),
            'currentPage' => 'admin',
            'currentUser' => $currentUser,
            'config' => $config,
        ]);
    }

    $team = default_team($pdo);
    $users = db_fetch_all($pdo, 'SELECT * FROM users ORDER BY created_at ASC');
    $registrationInvites = list_registration_invites($pdo);
    $publicRegistrationEnabled = public_registration_enabled($pdo);
    $registrationInviteUrl = trim((string) ($_SESSION['registration_invite_url'] ?? ''));
    unset($_SESSION['registration_invite_url']);
    $challengeSettings = challenge_settings($pdo, $config);
    $challengeArchives = list_challenge_archives($pdo);
    $challengeCurrentSummary = challenge_period_summary(
        $pdo,
        (string) ($challengeSettings['challenge_start'] ?? ''),
        (string) ($challengeSettings['challenge_end'] ?? '')
    );
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
    $integrationStatuses = integration_runtime_statuses($pdo);
    $workoutTypeFields = list_workout_type_fields_grouped($pdo, false);
    $adminWorkoutTypes = list_workout_types($pdo, false);
    $adminWorkoutTypeTranslations = fetch_workout_type_translations($pdo, array_column($adminWorkoutTypes, 'id'));
    $adminWorkoutFieldIds = [];
    foreach ($workoutTypeFields as $adminWorkoutFieldRows) {
        foreach ((array) $adminWorkoutFieldRows as $adminWorkoutFieldRow) {
            $adminWorkoutFieldIds[] = (int) ($adminWorkoutFieldRow['id'] ?? 0);
        }
    }
    $adminWorkoutFieldTranslations = fetch_workout_field_translations($pdo, $adminWorkoutFieldIds);
    $loginBackgroundLibrary = list_login_background_library($config);
    $adminAchievements = list_achievements_for_admin($pdo);
    $adminTrainingExercises = wk_admin_exercises($pdo);
    $adminTrainingExerciseMedia = wk_exercise_media_map($pdo, $adminTrainingExercises);
    $adminWorkoutSessions = wk_admin_sessions($pdo, 100);
    $adminRankTiers = db_fetch_all($pdo, 'SELECT * FROM workout_rank_tiers ORDER BY sort_order ASC, threshold ASC');
    $adminSeasonAutomation = seasons_automation_settings($pdo);
    if (!empty($adminSeasonAutomation['enabled'])) {
        seasons_generate_upcoming($pdo);
    }
    $adminSeasons = seasons_list($pdo);
    $adminSeasonSchedule = seasons_schedule_status($pdo);
    $adminLatestSeason = $adminSeasons !== [] ? $adminSeasons[0] : null;
    try {
        $adminNextSeasonStartDate = $adminLatestSeason !== null
            ? (new DateTimeImmutable((string) ($adminLatestSeason['end_date'] ?? 'today')))->modify('+1 day')
            : new DateTimeImmutable('today');
    } catch (Throwable) {
        $adminNextSeasonStartDate = new DateTimeImmutable('today');
    }
    $adminNextSeasonEndDate = $adminNextSeasonStartDate->modify('+' . ((int) ($adminSeasonAutomation['duration_weeks'] ?? 12) * 7 - 1) . ' days');
    $adminMediaSearchCredentials = media_search_credentials_status($pdo, $config);
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
    $adminHabits = list_habit_definitions($pdo, false);
    $adminHabitTranslations = fetch_habit_translations($pdo, array_column($adminHabits, 'id'));
    $securityIpFilter = trim((string) ($_GET['security_ip'] ?? ''));
    if ($securityIpFilter !== '' && filter_var($securityIpFilter, FILTER_VALIDATE_IP) === false) {
        $securityIpFilter = '';
    }
    $adminSecuritySettings = security_runtime_settings($pdo, $config);
    $adminSecuritySummary = security_access_summary($pdo);
    $adminSecurityLogs = security_recent_access_logs($pdo, 200, $securityIpFilter);
    $adminSecurityBlocks = security_active_ip_blocks($pdo);

    render_view('admin', [
        'title' => t('admin.title'),
        'currentPage' => 'admin',
        'currentUser' => $currentUser,
        'users' => $users,
        'registrationInvites' => $registrationInvites,
        'publicRegistrationEnabled' => $publicRegistrationEnabled,
        'registrationInviteUrl' => $registrationInviteUrl,
        'team' => $team,
        'teamMembers' => list_team_members($pdo, (int) $team['id'], false),
        'joinRequests' => pending_team_join_requests($pdo, (int) $team['id']),
        'availableUsers' => list_users_not_in_active_team($pdo, (int) $team['id']),
        'workoutTypes' => $adminWorkoutTypes,
        'workoutTypeTranslations' => $adminWorkoutTypeTranslations,
        'workoutTypeFields' => $workoutTypeFields,
        'workoutFieldTranslations' => $adminWorkoutFieldTranslations,
        'habits' => $adminHabits,
        'habitTranslations' => $adminHabitTranslations,
        'achievements' => list_achievements($pdo, true),
        'adminAchievements' => $adminAchievements,
        'adminTrainingExercises' => $adminTrainingExercises,
        'adminTrainingExerciseMedia' => $adminTrainingExerciseMedia,
        'adminWorkoutSessions' => $adminWorkoutSessions,
        'adminRankTiers' => $adminRankTiers,
        'adminSeasons' => $adminSeasons,
        'mediaSearchEnabled' => media_search_enabled($pdo),
        'mediaSearchGoogleReady' => !empty($adminMediaSearchCredentials['google_ready']),
        'mediaSearchYoutubeReady' => !empty($adminMediaSearchCredentials['youtube_ready']),
        'mediaSearchCredentials' => $adminMediaSearchCredentials,
        'seasonAutomation' => $adminSeasonAutomation,
        'seasonSchedule' => $adminSeasonSchedule,
        'nextSeasonStart' => $adminNextSeasonStartDate->format('Y-m-d'),
        'nextSeasonEnd' => $adminNextSeasonEndDate->format('Y-m-d'),
        'selectedAdminAchievementId' => $selectedAdminAchievementId,
        'adminAchievementStats' => $adminAchievementStats,
        'achievementAwards' => list_recent_achievement_awards($pdo, 300),
        'motivationalQuotes' => list_motivational_quotes($pdo),
        'xpAmounts' => xp_action_amounts($pdo),
        'xpUsers' => xp_admin_user_rows($pdo, array_values((array) $users)),
        'xpActionStats' => xp_action_event_stats($pdo),
        'xpRecentEvents' => xp_recent_events($pdo, 60),
        'appIconPath' => $appIconPath,
        'appIconVersion' => $appIconVersion,
        'appNameSetting' => app_setting($pdo, 'app_name', (string) ($config['app_name'] ?? 'Fitness Challenge Tracker')),
        'penaltiesEnabled' => penalties_enabled($pdo),
        'notionSettings' => notion_settings($pdo),
        'notionStatus' => notion_sync_status($pdo),
        'notionFieldLabels' => notion_field_labels(),
        'notionFieldMap' => notion_field_map($pdo),
        'notionSchemaCache' => notion_schema_cache($pdo),
        'telegramSettings' => telegram_settings($pdo),
        'telegramLinkedUsers' => telegram_linked_users($pdo),
        'loginBackgroundPath' => $loginBackgroundPath,
        'loginBackgroundLibrary' => $loginBackgroundLibrary,
        'loginStyle' => login_style_normalize(app_setting($pdo, 'login_style', 'split')),
        'backupSettings' => $backupSettings,
        'systemBackups' => $systemBackups,
        'deployPortSettings' => deploy_port_settings_status(),
        'integrationStatuses' => $integrationStatuses,
        'challengeSettings' => $challengeSettings,
        'challengeArchives' => $challengeArchives,
        'challengeCurrentSummary' => $challengeCurrentSummary,
        'auditLogs' => fetch_audit_logs($pdo, $auditFilters, 100),
        'auditFilters' => $auditFilters,
        'securitySettings' => $adminSecuritySettings,
        'securitySummary' => $adminSecuritySummary,
        'securityLogs' => $adminSecurityLogs,
        'securityBlocks' => $adminSecurityBlocks,
        'securityIpFilter' => $securityIpFilter,
        'config' => $config,
    ]);
}

if ($page === 'team_settings') {
    $teamSettingsSection = trim((string) ($_GET['section'] ?? ''));
    if (!in_array($teamSettingsSection, ['', 'general', 'members', 'requests', 'danger'], true)) {
        $teamSettingsSection = '';
    }
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

    $teamSettingsUrl = static function (int $teamId, string $section = ''): string {
        $params = ['page' => 'team_settings', 'team_id' => $teamId];
        if ($section !== '') {
            $params['section'] = $section;
        }

        return '/?' . http_build_query($params);
    };

    if (is_post()) {
        if (!csrf_verify()) {
            flash_set('error', t('flash.csrf'));
            redirect($teamSettingsUrl((int) $team['id'], $teamSettingsSection));
        }

        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'team_settings') {
            $existingTeamIconPath = trim((string) ($team['icon_path'] ?? ''));
            $existingTeamCoverPath = trim((string) ($team['cover_path'] ?? ''));
            $nextTeamIconPath = $existingTeamIconPath;
            $nextTeamCoverPath = $existingTeamCoverPath;
            $createdTeamMediaPaths = [];
            try {
                $teamIconUpload = is_array($_FILES['team_icon'] ?? null) ? (array) $_FILES['team_icon'] : [];
                $teamCoverUpload = is_array($_FILES['team_cover'] ?? null) ? (array) $_FILES['team_cover'] : [];
                if ((int) ($teamIconUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $nextTeamIconPath = save_uploaded_image($config, $teamIconUpload, 'teams/icons', 'team_' . (int) $team['id'] . '_icon');
                    $createdTeamMediaPaths[] = $nextTeamIconPath;
                } elseif (!empty($_POST['remove_team_icon'])) {
                    $nextTeamIconPath = '';
                }
                if ((int) ($teamCoverUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $nextTeamCoverPath = save_uploaded_image($config, $teamCoverUpload, 'teams/covers', 'team_' . (int) $team['id'] . '_cover');
                    $createdTeamMediaPaths[] = $nextTeamCoverPath;
                } elseif (!empty($_POST['remove_team_cover'])) {
                    $nextTeamCoverPath = '';
                }

                $pdo->beginTransaction();
                update_team_settings(
                    $pdo,
                    (int) $team['id'],
                    (string) ($_POST['name'] ?? ''),
                    (string) ($_POST['description'] ?? ''),
                    (string) ($_POST['join_mode'] ?? 'closed'),
                    (string) ($_POST['visibility'] ?? 'visible'),
                    (int) $currentUser['id']
                );
                db_execute(
                    $pdo,
                    'UPDATE teams SET icon_path = :icon_path, cover_path = :cover_path, updated_at = :updated_at WHERE id = :id',
                    [
                        ':icon_path' => $nextTeamIconPath !== '' ? $nextTeamIconPath : null,
                        ':cover_path' => $nextTeamCoverPath !== '' ? $nextTeamCoverPath : null,
                        ':updated_at' => now_iso(),
                        ':id' => (int) $team['id'],
                    ]
                );
                audit_log(
                    $pdo,
                    (int) $currentUser['id'],
                    'team_identity_updated',
                    'team',
                    (string) $team['id'],
                    'Team icon and cover updated.',
                    ['icon_path' => $existingTeamIconPath, 'cover_path' => $existingTeamCoverPath],
                    ['icon_path' => $nextTeamIconPath, 'cover_path' => $nextTeamCoverPath]
                );
                $pdo->commit();

                foreach ([[$existingTeamIconPath, $nextTeamIconPath], [$existingTeamCoverPath, $nextTeamCoverPath]] as [$oldTeamMediaPath, $newTeamMediaPath]) {
                    if ($oldTeamMediaPath === '' || $oldTeamMediaPath === $newTeamMediaPath) {
                        continue;
                    }
                    $oldTeamMediaFile = resolve_media_storage_path($config, $oldTeamMediaPath);
                    if ($oldTeamMediaFile !== null && is_file($oldTeamMediaFile)) {
                        @unlink($oldTeamMediaFile);
                    }
                }
                flash_set('success', t('flash.team_updated'));
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                foreach ($createdTeamMediaPaths as $createdTeamMediaPath) {
                    $createdTeamMediaFile = resolve_media_storage_path($config, (string) $createdTeamMediaPath);
                    if ($createdTeamMediaFile !== null && is_file($createdTeamMediaFile)) {
                        @unlink($createdTeamMediaFile);
                    }
                }
                flash_set('error', $e->getMessage());
            }
            redirect($teamSettingsUrl((int) $team['id'], 'general'));
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
            redirect($teamSettingsUrl((int) $team['id'], 'members'));
        }

        if ($action === 'team_role') {
            update_team_member_role($pdo, (int) $team['id'], (int) ($_POST['user_id'] ?? 0), (string) ($_POST['role'] ?? 'member'), (int) $currentUser['id']);
            flash_set('success', t('flash.team_updated'));
            redirect($teamSettingsUrl((int) $team['id'], 'members'));
        }

        if ($action === 'transfer_admin') {
            $ok = transfer_team_admin($pdo, (int) $team['id'], (int) ($_POST['user_id'] ?? 0), (int) $currentUser['id']);
            flash_set($ok ? 'success' : 'error', $ok ? t('flash.team_admin_transferred') : t('flash.team_action_failed'));
            redirect($teamSettingsUrl((int) $team['id'], 'danger'));
        }

        if ($action === 'delete_team') {
            if (delete_team($pdo, (int) $team['id'], (int) $currentUser['id'])) {
                flash_set('success', t('flash.team_deleted'));
                redirect('/?page=team');
            }
            flash_set('error', t('flash.team_delete_blocked'));
            redirect($teamSettingsUrl((int) $team['id'], 'danger'));
        }

        if ($action === 'resolve_join_request') {
            resolve_team_join_request($pdo, (int) ($_POST['request_id'] ?? 0), (string) ($_POST['decision'] ?? '') === 'approve', (int) $currentUser['id']);
            flash_set('success', t('flash.team_updated'));
            redirect($teamSettingsUrl((int) $team['id'], 'requests'));
        }
    }

    $loadTeamMembers = in_array($teamSettingsSection, ['', 'members', 'danger'], true);
    $loadJoinRequests = in_array($teamSettingsSection, ['', 'requests'], true);

    render_view('team_settings', [
        'title' => t('team.settings'),
        'currentPage' => 'team',
        'currentUser' => $currentUser,
        'team' => $team,
        'teamSettingsSection' => $teamSettingsSection,
        'teamMembers' => $loadTeamMembers ? list_team_members($pdo, (int) $team['id'], false) : [],
        'availableUsers' => $teamSettingsSection === 'members' ? list_users_not_in_active_team($pdo, (int) $team['id']) : [],
        'joinRequests' => $loadJoinRequests ? pending_team_join_requests($pdo, (int) $team['id']) : [],
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

        if ($action === 'leave_team') {
            $leaveResult = leave_team($pdo, (int) ($_POST['team_id'] ?? 0), (int) $currentUser['id']);
            flash_set($leaveResult === 'left' ? 'success' : 'error', t('flash.team_leave_' . $leaveResult));
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
        $redirectTeamSection = trim((string) ($_POST['redirect_section'] ?? ($_GET['section'] ?? '')));
        if (in_array($redirectTeamSection, ['challenge', 'leaderboard', 'members', 'stats', 'achievements'], true)) {
            $redirectTeamParams['section'] = $redirectTeamSection;
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
                $targetExerciseId = $goalType === 'weight_lifted' ? (int) ($_POST['target_exercise_id'] ?? 0) : 0;
                if ($targetExerciseId > 0 && wk_exercise_get_for_user($pdo, $targetExerciseId, (int) $currentUser['id']) === null) {
                    $targetExerciseId = 0;
                }
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
                $secondaryExerciseId = $secondaryType === 'weight_lifted' ? (int) ($_POST['secondary_target_exercise_id'] ?? 0) : 0;
                if ($secondaryExerciseId > 0 && wk_exercise_get_for_user($pdo, $secondaryExerciseId, (int) $currentUser['id']) === null) {
                    $secondaryExerciseId = 0;
                }
                if (!$secondaryEnabled || $secondaryTargetValueRaw === null || $secondaryTargetValueRaw <= 0) {
                    $secondaryEnabled = false;
                    $secondaryType = 'custom';
                    $secondaryTargetValueRaw = null;
                }
                if (!$penaltiesEnabled && $secondaryEnabled && $secondaryType === 'penalties') {
                    flash_set('error', t('metric.invalid'));
                    redirect($teamRedirectUrl);
                }
                try {
                    $metricTargets = goal_metric_targets_from_form(
                        $_POST,
                        $goalType,
                        (float) ($targetValue ?? 0),
                        $secondaryEnabled ? $secondaryType : null,
                        $secondaryEnabled ? $secondaryTargetValueRaw : null
                    );
                } catch (InvalidArgumentException $exception) {
                    flash_set('error', $exception->getMessage());
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
                $nowDateTime = new DateTimeImmutable('now');
                $scheduleErrorKey = goal_schedule_error_key($startAt, $dueAt, true, $nowDateTime);
                if ($scheduleErrorKey !== null) {
                    flash_set('error', t($scheduleErrorKey));
                    redirect($teamRedirectUrl);
                }
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
                    'target_exercise_id' => $targetExerciseId > 0 ? $targetExerciseId : null,
                    'baseline_value' => $baselineValue,
                    'current_value' => 0,
                    'secondary_enabled' => $secondaryEnabled ? 1 : 0,
                    'secondary_target_type' => $secondaryEnabled ? $secondaryType : null,
                    'secondary_target_value' => $secondaryEnabled ? $secondaryTargetValueRaw : null,
                    'secondary_exercise_id' => $secondaryEnabled && $secondaryExerciseId > 0 ? $secondaryExerciseId : null,
                    'secondary_baseline_value' => $secondaryEnabled ? $secondaryBaselineValue : null,
                    'secondary_current_value' => 0,
                    'secondary_unit_label' => $secondaryUnitLabel,
                    'unit_label' => $unitLabel,
                    'reward_text' => $rewardText,
                    'start_date' => $startDate !== '' ? $startDate : null,
                    'start_time' => $startTime !== '' ? $startTime : null,
                    'due_date' => $dueDate,
                    'due_time' => $dueTime,
                    'metric_targets' => $metricTargets,
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
            try {
                $metricTargets = goal_metric_targets_from_form(
                    $_POST,
                    $goalType,
                    (float) ($_POST['target_value'] ?? 0),
                    $secondaryEnabled ? $secondaryType : null,
                    $secondaryEnabled ? $secondaryTargetValueRaw : null
                );
            } catch (InvalidArgumentException $exception) {
                flash_set('error', $exception->getMessage());
                redirect($teamRedirectUrl);
            }
            $targetExerciseId = $goalType === 'weight_lifted' ? (int) ($_POST['target_exercise_id'] ?? 0) : 0;
            if ($targetExerciseId > 0 && wk_exercise_get_for_user($pdo, $targetExerciseId, (int) $currentUser['id']) === null) {
                $targetExerciseId = 0;
            }
            $secondaryExerciseId = $secondaryEnabled && $secondaryType === 'weight_lifted' ? (int) ($_POST['secondary_target_exercise_id'] ?? 0) : 0;
            if ($secondaryExerciseId > 0 && wk_exercise_get_for_user($pdo, $secondaryExerciseId, (int) $currentUser['id']) === null) {
                $secondaryExerciseId = 0;
            }
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
            $scheduleErrorKey = goal_schedule_error_key($startAt, $dueAt);
            if ($scheduleErrorKey !== null) {
                flash_set('error', t($scheduleErrorKey));
                redirect($teamRedirectUrl);
            }
            $updatePayload = [
                'title' => trim((string) ($_POST['title'] ?? '')),
                'target_type' => $goalType,
                'target_value' => ($_POST['target_value'] ?? '') !== '' ? (float) $_POST['target_value'] : null,
                'target_exercise_id' => $targetExerciseId > 0 ? $targetExerciseId : null,
                'secondary_enabled' => $secondaryEnabled ? 1 : 0,
                'secondary_target_type' => $secondaryEnabled ? $secondaryType : null,
                'secondary_target_value' => $secondaryEnabled ? $secondaryTargetValueRaw : null,
                'secondary_exercise_id' => $secondaryEnabled && $secondaryExerciseId > 0 ? $secondaryExerciseId : null,
                'secondary_unit_label' => $secondaryUnitLabel,
                'unit_label' => $unitLabel,
                'reward_text' => $rewardText,
                'start_date' => $startDate !== '' ? $startDate : null,
                'start_time' => $startTime !== '' ? $startTime : null,
                'due_date' => $dueDate,
                'due_time' => $dueTime,
                'metric_targets' => $metricTargets,
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
            flash_set('error', t('flash.no_permission'));
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

    // Active team: an explicit ?team_id wins, otherwise the one the user last chose,
    // otherwise their first. Persisting it means the rest of the app stops silently
    // defaulting to "whichever team came first out of the database".
    $storedTeamId = (int) ($currentUser['active_team_id'] ?? 0);
    $teamId = isset($_GET['team_id']) ? (int) $_GET['team_id'] : $storedTeamId;
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

    // Backwards compatibility for old bookmarks and notifications. Member detail is
    // now the shared public profile, with an explicit route back to this team.
    if ((string) ($_GET['section'] ?? '') === 'member') {
        $teamMemberId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
        if ($teamMemberId <= 0 || !user_is_active_team_member($pdo, (int) $team['id'], $teamMemberId)) {
            flash_set('error', t('flash.no_permission'));
            redirect('/?' . http_build_query([
                'page' => 'team',
                'team_id' => (int) $team['id'],
            ]));
        }
        $legacyMemberProfileQuery = [
            'page' => 'profile',
            'user_id' => $teamMemberId,
            'back' => 'team',
            'team_id' => (int) $team['id'],
        ];
        $legacyMemberView = trim((string) ($_GET['view'] ?? ''));
        if ($legacyMemberView !== '') {
            $legacyMemberProfileQuery['back_view'] = $legacyMemberView;
        }
        redirect('/?' . http_build_query($legacyMemberProfileQuery));
    }
    if ((int) $team['id'] !== $storedTeamId) {
        db_execute(
            $pdo,
            'UPDATE users SET active_team_id = :t WHERE id = :id',
            [':t' => (int) $team['id'], ':id' => (int) $currentUser['id']]
        );
        $currentUser['active_team_id'] = (int) $team['id'];
    }

    // A saved team layout only stores the widgets that were visible when it was saved, so
    // a widget added afterwards (competitions) would never appear. Append the ones this
    // user has never been offered, and remember the full set so a deliberately hidden
    // widget is not resurrected on the next release.
    $teamAllWidgets = team_layout_widgets_default();
    $teamKnown = json_decode((string) ($currentUser['team_widgets_known'] ?? ''), true);
    $teamKnown = is_array($teamKnown) ? array_map('strval', $teamKnown) : [];
    $teamUnknown = array_values(array_diff($teamAllWidgets, $teamKnown));
    if ($teamUnknown !== []) {
        $savedTeamLayout = json_decode((string) ($currentUser['team_layout_json'] ?? ''), true);
        if (is_array($savedTeamLayout) && $savedTeamLayout !== []) {
            $mergedTeamLayout = array_values(array_unique(array_merge(
                array_map('strval', $savedTeamLayout),
                array_values(array_diff($teamUnknown, $savedTeamLayout))
            )));
            db_execute(
                $pdo,
                'UPDATE users SET team_layout_json = :layout, team_widgets_known = :known WHERE id = :id',
                [
                    ':layout' => json_encode($mergedTeamLayout, JSON_UNESCAPED_SLASHES),
                    ':known' => json_encode($teamAllWidgets, JSON_UNESCAPED_SLASHES),
                    ':id' => (int) $currentUser['id'],
                ]
            );
            $currentUser['team_layout_json'] = json_encode($mergedTeamLayout, JSON_UNESCAPED_SLASHES);
        } else {
            db_execute(
                $pdo,
                'UPDATE users SET team_widgets_known = :known WHERE id = :id',
                [':known' => json_encode($teamAllWidgets, JSON_UNESCAPED_SLASHES), ':id' => (int) $currentUser['id']]
            );
        }
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

    $teamSection = trim((string) ($_GET['section'] ?? ''));
    if (!in_array($teamSection, ['', 'challenge', 'leaderboard', 'members', 'stats', 'achievements'], true)) {
        $teamSection = '';
    }

    $nowDateTime = new DateTimeImmutable('now');
    $teamChallengeData = team_challenge_view_data($pdo, $team, $teamSummaryTotal, $settings);
    $teamGoals = $teamChallengeData['goals'];
    $teamActiveChallenge = $teamChallengeData['active'];
    $teamActiveChallengeContributions = $teamActiveChallenge !== null
        ? team_challenge_contributions($pdo, $teamActiveChallenge, $teamUsers, $metricsByUser)
        : [];

    $teamGoalDebugEnabled = isset($_GET['debug_goal']) && (string) $_GET['debug_goal'] === '1';

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
    if ($teamSection !== '') {
        $teamTopbarQuery['section'] = $teamSection;
    }
    if ($teamGoalDebugEnabled) {
        $teamTopbarQuery['debug_goal'] = '1';
    }
    $teamEditLayoutUrl = '/?' . http_build_query($teamTopbarQuery + ['layout_edit' => '1']);

    ob_start();
    ?>
    <?php if ($teamLayoutEditMode): ?>
        <button class="btn btn-primary btn-topbar" type="submit"
            form="team-layout-edit-form"><?= e(t('common.save')) ?></button>
    <?php else: ?>
        <details class="topbar-context">
            <summary class="btn btn-ghost btn-topbar"><?= e(t('dashboard.view_mode')) ?></summary>
            <div class="topbar-context-panel">
                <form method="get" class="stack">
                    <input type="hidden" name="page" value="team">
                    <?php if (count($userTeams) > 1): ?>
                        <label>
                            <?= e(t('team.your_teams')) ?>
                            <select name="team_id" onchange="this.form.submit()">
                                <?php foreach ($userTeams as $userTeamOption): ?>
                                    <option value="<?= (int) ($userTeamOption['id'] ?? 0) ?>" <?= (int) ($userTeamOption['id'] ?? 0) === (int) ($team['id'] ?? 0) ? 'selected' : '' ?>>
                                        <?= e((string) ($userTeamOption['name'] ?? '')) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    <?php else: ?>
                        <input type="hidden" name="team_id" value="<?= (int) ($team['id'] ?? 0) ?>">
                    <?php endif; ?>
                    <?php if ($teamSection !== ''): ?><input type="hidden" name="section"
                            value="<?= e($teamSection) ?>"><?php endif; ?>
                    <?php if ($teamGoalDebugEnabled): ?>
                        <input type="hidden" name="debug_goal" value="1">
                    <?php endif; ?>
                    <?php if ($teamMetricDetail !== null): ?>
                        <input type="hidden" name="metric" value="<?= e((string) ($teamMetricDetail['key'] ?? '')) ?>">
                    <?php endif; ?>
                    <label>
                        <?= e(t('dashboard.view_mode')) ?>
                        <select name="view" onchange="this.form.submit()">
                            <option value="current_week" <?= $teamView === 'current_week' ? 'selected' : '' ?>>
                                <?= e(t('dashboard.current_week')) ?></option>
                            <option value="total" <?= $teamView === 'total' ? 'selected' : '' ?>><?= e(t('metric.total')) ?>
                            </option>
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

    $teamMissions = team_missions_for_team($pdo, (int) $team['id']);

    render_view('team', [
        'title' => t('team.title'),
        'currentPage' => 'team',
        'currentUser' => $currentUser,
        'team' => $team,
        'userTeams' => $userTeams,
        'joinableTeams' => list_joinable_teams($pdo, (int) $currentUser['id']),
        'members' => list_team_members($pdo, (int) $team['id'], true),
        'availableUsers' => list_users_not_in_active_team($pdo, (int) $team['id']),
        'metricsOrdered' => $metricsOrdered,
        'teamSummary' => $teamSummary,
        'teamView' => $teamView,
        // Competitions render inside the team page now, so the team route has to carry
        // them. Same shape the competitions page builds, trimmed to what the panel shows.
        'teamCompetitions' => (static function () use ($pdo, $config, $currentUser): array{
            $rows = [];
            foreach (comp_for_user($pdo, (int) $currentUser['id']) as $comp) {
                $status = (string) ($comp['status'] ?? '');
                if (!in_array($status, ['active', 'pending'], true)) {
                    continue;
                }
                $standing = comp_standing($pdo, $config, (array) $comp);
                $challenger = (string) ((($standing['challenger_squad'] ?? [])['name']) ?? '');
                $opponent = (string) ((($standing['opponent_squad'] ?? [])['name']) ?? '');
                $rows[] = [
                    'title' => trim($challenger . ' vs ' . $opponent),
                    'meta' => duels_metric_label((string) ($comp['metric'] ?? ''))
                        . ' · ' . ($status === 'active' ? t('competitions.active') : t('duels.waiting')),
                ];
            }

            return $rows;
        })(),
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
        'teamGoals' => $teamGoals,
        'teamActiveChallenge' => $teamActiveChallenge,
        'teamActiveChallengeContributions' => $teamActiveChallengeContributions,
        'teamGoalExercises' => wk_exercises_for_user($pdo, (int) $currentUser['id']),
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

    // Metric detail is deliberately personal. Even administrators see their
    // own values here; team comparisons belong in the dedicated social views.
    $users = [$currentUser];

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

    $selectedUserId = (int) $currentUser['id'];

    $selectedMetric = $metricsById[$selectedUserId] ?? null;
    if ($selectedMetric === null) {
        flash_set('error', t('flash.no_active_users'));
        redirect('/?page=dashboard');
    }

    $penaltiesEnabled = penalties_enabled($pdo);
    $allowedMetrics = [
        'steps' => t('metric.steps'),
        'distance' => t('metric.total_km'),
        'workouts' => t('metric.workouts'),
        'score' => t('metric.score'),
        'calories_consumed' => t('dashboard.calories_consumed'),
        'calories_burned' => t('dashboard.calories_burned'),
    ];
    if ($penaltiesEnabled) {
        $allowedMetrics['strikes'] = t('metric.strikes');
        $allowedMetrics['money'] = t('metric.penalty');
    }
    foreach (custom_metrics_for_user($pdo, (int) $currentUser['id']) as $customMetricOption) {
        $allowedMetrics[custom_metric_key((int) $customMetricOption['id'])] = (string) $customMetricOption['name'];
    }
    foreach (list_habit_definitions($pdo, true, null, (int) $currentUser['id']) as $habitMetricOption) {
        $allowedMetrics['habit:' . (string) $habitMetricOption['code']] = (string) $habitMetricOption['label'];
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
    $habitAnalytics = null;
    if (str_starts_with($metricKey, 'custom:')) {
        $customMetricId = (int) substr($metricKey, 7);
        $customMetric = custom_metric_get($pdo, $customMetricId, (int) $currentUser['id']);
        if ($customMetric === null) {
            flash_set('error', t('metric.invalid'));
            redirect('/?page=dashboard');
        }
        $customFrom = $dashboardView === 'total'
            ? (string) $settings['challenge_start']
            : $selectedWeekStart;
        $customTo = $dashboardView === 'total'
            ? (string) $settings['challenge_end']
            : (new DateTimeImmutable($selectedWeekStart))->modify('+6 days')->format('Y-m-d');
        $customSeries = custom_metric_series($pdo, $customMetricId, (int) $currentUser['id'], $customFrom, $customTo);
        $seriesLabels = array_map(static fn(array $row): string => format_date_eu((string) $row['entry_date']), $customSeries);
        $seriesValues = array_map(static fn(array $row): float => (float) $row['value'], $customSeries);
        $currentValue = $seriesValues !== [] ? (float) $seriesValues[count($seriesValues) - 1] : 0.0;
        $currentValueSuffix = trim((string) ($customMetric['unit'] ?? '')) !== '' ? ' ' . trim((string) $customMetric['unit']) : '';
    }
    if (str_starts_with($metricKey, 'habit:')) {
        $habitCode = substr($metricKey, 6);
        $habitFrom = $dashboardView === 'total' ? (string) $settings['challenge_start'] : $selectedWeekStart;
        $habitTo = $dashboardView === 'total'
            ? (string) $settings['challenge_end']
            : (new DateTimeImmutable($selectedWeekStart))->modify('+6 days')->format('Y-m-d');
        $habitRows = db_fetch_all(
            $pdo,
            'SELECT l.log_date AS entry_date, MAX(COALESCE(h.value, 0)) AS value
             FROM daily_logs l
             JOIN daily_log_habits h ON h.log_id = l.id
             JOIN habit_definitions d ON d.id = h.habit_id AND d.code = :code
             WHERE l.user_id = :user AND l.log_date BETWEEN :start AND :end
             GROUP BY l.log_date ORDER BY l.log_date',
            [':user' => (int) $currentUser['id'], ':start' => $habitFrom, ':end' => $habitTo, ':code' => $habitCode]
        );
        $habitByDate = [];
        foreach ($habitRows as $habitRow) {
            $habitByDate[(string) $habitRow['entry_date']] = (int) ($habitRow['value'] ?? 0);
        }
        $habitSeries = [];
        $cursor = new DateTimeImmutable($habitFrom);
        $lastHabitDate = new DateTimeImmutable($habitTo);
        while ($cursor <= $lastHabitDate) {
            $dateKey = $cursor->format('Y-m-d');
            $habitSeries[] = ['entry_date' => $dateKey, 'value' => (int) ($habitByDate[$dateKey] ?? 0)];
            $cursor = $cursor->modify('+1 day');
        }
        $seriesLabels = array_map(static fn(array $row): string => format_date_eu((string) $row['entry_date']), $habitSeries);
        $seriesValues = array_map(static fn(array $row): int => (int) ($row['value'] ?? 0), $habitSeries);
        $currentValue = (float) array_sum($seriesValues);
        $currentValueSuffix = ' días';
        $currentStreak = 0;
        $maxStreak = 0;
        foreach ($seriesValues as $completed) {
            $currentStreak = $completed === 1 ? $currentStreak + 1 : 0;
            $maxStreak = max($maxStreak, $currentStreak);
        }
        $trailingStreak = 0;
        for ($index = count($seriesValues) - 1; $index >= 0 && $seriesValues[$index] === 1; $index--) {
            $trailingStreak++;
        }
        $habitAnalytics = [
            'completed_days' => (int) array_sum($seriesValues),
            'total_days' => count($seriesValues),
            'completion_pct' => count($seriesValues) > 0 ? round((array_sum($seriesValues) / count($seriesValues)) * 100, 1) : 0,
            'current_streak' => $trailingStreak,
            'max_streak' => $maxStreak,
        ];
    }
    $score_for_week = static function (array $row) use ($selectedMetric): float {
        $weekStart = (string) ($row['week_start'] ?? '');
        if ($weekStart === '') {
            return 0.0;
        }
        $snapshot = metric_snapshot_for_view($selectedMetric, $weekStart);

        return $snapshot['score'] !== null ? (float) $snapshot['score'] : 0.0;
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
    }

    if (in_array($metricKey, ['calories_consumed', 'calories_burned'], true)) {
        $calorieRangeStart = (string) ($settings['challenge_start'] ?? to_date(null));
        $calorieRangeEnd = (string) ($settings['challenge_end'] ?? $calorieRangeStart);
        if ($dashboardView !== 'total') {
            $weekStartDate = new DateTimeImmutable($selectedWeekStart);
            $weekEndDate = $weekStartDate->modify('+6 days');
            $challengeStartDate = new DateTimeImmutable($calorieRangeStart);
            $challengeEndDate = new DateTimeImmutable($calorieRangeEnd);
            if ($weekStartDate < $challengeStartDate) {
                $weekStartDate = $challengeStartDate;
            }
            if ($weekEndDate > $challengeEndDate) {
                $weekEndDate = $challengeEndDate;
            }
            if ($weekEndDate < $weekStartDate) {
                $weekEndDate = $weekStartDate;
            }
            $calorieRangeStart = $weekStartDate->format('Y-m-d');
            $calorieRangeEnd = $weekEndDate->format('Y-m-d');
        }
        $calorieStats = fetch_user_calorie_stats(
            $pdo,
            (int) ($selectedMetric['user']['id'] ?? 0),
            $calorieRangeStart,
            $calorieRangeEnd,
            ($selectedMetric['user']['maintenance_calories'] ?? null) !== null
            ? (float) $selectedMetric['user']['maintenance_calories']
            : null
        );
        $calorieSeriesKey = $metricKey === 'calories_consumed' ? 'consumed' : 'burned';
        $seriesLabels = array_map(
            static fn(array $row): string => format_date_eu((string) ($row['date'] ?? '')),
            (array) ($calorieStats['series'] ?? [])
        );
        $seriesValues = array_map(
            static fn(array $row): float => round((float) ($row[$calorieSeriesKey] ?? 0), 2),
            (array) ($calorieStats['series'] ?? [])
        );
        $currentValue = (float) array_sum($seriesValues);
        $currentValueSuffix = ' kcal';
    }

    $scoreSnapshotView = $dashboardView === 'total' ? 'total' : $selectedWeekStart;
    $scoreSnapshot = metric_snapshot_for_view($selectedMetric, $scoreSnapshotView);
    $scoreBreakdown = score_breakdown_from_snapshot($selectedMetric, $scoreSnapshot);
    if ($metricKey === 'score') {
        // Keep the detail value identical to the dashboard. The chart remains a
        // weekly history based on the same active-metric snapshot contract.
        $currentValue = (float) ($scoreBreakdown['score'] ?? 0.0);
    }

    $numericSeries = array_values(array_map(static fn(mixed $value): float => (float) $value, $seriesValues));
    $metricSummary = [
        'average' => $numericSeries !== [] ? array_sum($numericSeries) / count($numericSeries) : 0.0,
        'best' => $numericSeries !== [] ? max($numericSeries) : 0.0,
        'latest' => $numericSeries !== [] ? $numericSeries[count($numericSeries) - 1] : 0.0,
        'points' => count($numericSeries),
    ];
    $periodLabel = t('dashboard.current_week');
    if ($dashboardView === 'total') {
        $periodLabel = t('common.from_to', [
            'start' => format_date_eu((string) ($settings['challenge_start'] ?? '')),
            'end' => format_date_eu((string) ($settings['challenge_end'] ?? '')),
        ]);
    } elseif ($selectedWeekStart !== '') {
        try {
            $periodStart = new DateTimeImmutable($selectedWeekStart);
            $periodLabel = t('common.from_to', [
                'start' => format_date_eu($periodStart->format('Y-m-d')),
                'end' => format_date_eu($periodStart->modify('+6 days')->format('Y-m-d')),
            ]);
        } catch (Throwable) {
            $periodLabel = t('dashboard.current_week');
        }
    }

    $backUrl = '/?' . http_build_query([
        'page' => 'dashboard',
        'user_id' => (int) ($selectedMetric['user']['id'] ?? 0),
        'view' => $dashboardView,
    ]);

    render_view('metric', [
        'title' => t('metric.detail_title'),
        'currentPage' => 'metric',
        'currentUser' => $currentUser,
        'selectedMetric' => $selectedMetric,
        'metricKey' => $metricKey,
        'metricLabel' => $allowedMetrics[$metricKey],
        'seriesLabels' => $seriesLabels,
        'seriesValues' => $seriesValues,
        'currentValue' => $currentValue,
        'currentValueSuffix' => $currentValueSuffix,
        'chartLabel' => $chartLabel,
        'allowedMetrics' => $allowedMetrics,
        'metricSummary' => $metricSummary,
        'periodLabel' => $periodLabel,
        'scoreBreakdown' => $scoreBreakdown,
        'habitAnalytics' => $habitAnalytics,
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
    $analyticsSection = trim((string) ($_GET['section'] ?? ''));
    if (!in_array($analyticsSection, ['', 'activity', 'nutrition', 'food', 'body', 'comparison'], true)) {
        $analyticsSection = '';
    }
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

    // Per-user analytics view persistence (#8): default to the user's last
    // selected view (falling back to "total"); when an explicit period is
    // requested via the filter, remember it for next time.
    $analyticsAllowedPeriods = ['current_week', 'week', 'month', 'total'];
    $analyticsStoredView = (string) ($currentUser['analytics_view'] ?? 'total');
    if (!in_array($analyticsStoredView, $analyticsAllowedPeriods, true)) {
        $analyticsStoredView = 'total';
    }
    $analyticsPeriodExplicit = isset($_GET['analytics_period']);
    $analyticsPeriod = (string) ($_GET['analytics_period'] ?? $analyticsStoredView);
    if (!in_array($analyticsPeriod, $analyticsAllowedPeriods, true)) {
        $analyticsPeriod = 'total';
    }
    if ($analyticsPeriodExplicit && $analyticsPeriod !== $analyticsStoredView) {
        db_execute(
            $pdo,
            'UPDATE users SET analytics_view = :analytics_view WHERE id = :id',
            [':analytics_view' => $analyticsPeriod, ':id' => (int) $currentUser['id']]
        );
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

    $distanceByDate = [];
    if ($analyticsSection === '' || $analyticsSection === 'activity') {
        $distanceByDate = fetch_distance_totals_by_date_for_user_between(
            $pdo,
            (int) ($selectedMetric['user']['id'] ?? 0),
            (string) $settings['challenge_start'],
            (string) $settings['challenge_end']
        );
    }
    $maintenanceCalories = ($selectedMetric['user']['maintenance_calories'] ?? null) !== null
        ? (float) $selectedMetric['user']['maintenance_calories']
        : null;
    $dashboardCalorieStats = [];
    if ($analyticsSection === '' || $analyticsSection === 'nutrition') {
        $dashboardCalorieStats = fetch_user_calorie_stats(
            $pdo,
            (int) ($selectedMetric['user']['id'] ?? 0),
            $analyticsStartDate,
            $analyticsEndDate,
            $maintenanceCalories
        );
    }
    $analyticsFoodStats = [];
    if ($analyticsSection === '' || in_array($analyticsSection, ['nutrition', 'food'], true)) {
        $analyticsFoodStats = fetch_user_food_stats(
            $pdo,
            (int) ($selectedMetric['user']['id'] ?? 0),
            $analyticsStartDate,
            $analyticsEndDate
        );
    }

    render_view('analytics', [
        'title' => t('nav.analytics'),
        'currentPage' => 'analytics',
        'currentUser' => $currentUser,
        'analyticsSection' => $analyticsSection,
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

if ($page === 'dashboard' || $page === 'overview') {
    $dashboardStandaloneOverview = $page === 'overview';
    $dashboardRoutePage = $dashboardStandaloneOverview ? 'overview' : 'dashboard';
    $dashboardRouteUrl = '/?page=' . $dashboardRoutePage;
    if (
        !$dashboardStandaloneOverview
        && !is_post()
        && (
            (string) ($_GET['home'] ?? '') === 'classic'
            || trim((string) ($_GET['section'] ?? '')) !== ''
            || (string) ($_GET['layout_edit'] ?? '') === '1'
        )
    ) {
        // Classic used to be a second Home. Keep old bookmarks working while
        // making Home unambiguously the social feed.
        $overviewQuery = $_GET;
        $overviewQuery['page'] = 'overview';
        unset($overviewQuery['home'], $overviewQuery['feed'], $overviewQuery['post_type'], $overviewQuery['post_id']);
        redirect('/?' . http_build_query($overviewQuery));
    }
    workouts_ensure_schema($pdo);
    if (!is_post() && array_key_exists('user_id', $_GET)) {
        $dashboardCanonicalQuery = $_GET;
        unset($dashboardCanonicalQuery['user_id']);
        $dashboardCanonicalQuery['page'] = $dashboardRoutePage;
        redirect('/?' . http_build_query($dashboardCanonicalQuery));
    }
    $dashboardSection = trim((string) ($_GET['section'] ?? ''));
    if (!in_array($dashboardSection, ['', 'progress', 'rewards', 'history', 'alerts'], true)) {
        $dashboardSection = '';
    }
    if (is_post()) {
        if (!csrf_verify()) {
            if ((string) ($_POST['feed_ajax'] ?? '') === '1' || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'feed-fetch') {
                http_response_code(403);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode(['ok' => false, 'message' => t('flash.csrf')], JSON_UNESCAPED_UNICODE);
                exit;
            }
            flash_set('error', t('flash.csrf'));
            redirect($dashboardRouteUrl);
        }

        $action = (string) ($_POST['action'] ?? '');
        $socialCommentActions = ['social_feed_comment', 'social_feed_comment_edit', 'social_feed_comment_delete', 'social_feed_comment_like'];
        if ($action === 'social_feed_like' || $action === 'social_feed_copy_workout' || in_array($action, $socialCommentActions, true)) {
            $feedType = (string) ($_POST['entity_type'] ?? '');
            $feedId = max(0, (int) ($_POST['entity_id'] ?? 0));
            $feedScope = (string) ($_POST['feed_scope'] ?? 'friends') === 'global' ? 'global' : 'friends';
            $saved = null;
            $feedError = '';
            try {
                if ($action === 'social_feed_like') {
                    $saved = social_feed_toggle_like($pdo, (int) $currentUser['id'], $feedType, $feedId);
                } elseif ($action === 'social_feed_copy_workout') {
                    $validType = social_feed_entity_type($feedType);
                    $sourceOwnerId = $validType === 'workout' ? social_feed_entity_owner_id($pdo, $validType, $feedId) : 0;
                    if ($sourceOwnerId <= 0
                        || $sourceOwnerId === (int) $currentUser['id']
                        || !social_feed_entity_visible($pdo, (int) $currentUser['id'], $validType, $feedId)) {
                        throw new RuntimeException(t('workouts.routine_copy_failed'));
                    }
                    $saved = wk_routine_copy_from_session($pdo, $feedId, $sourceOwnerId, (int) $currentUser['id']);
                    if ((int) $saved <= 0) {
                        throw new RuntimeException(t('workouts.routine_copy_failed'));
                    }
                } else {
                    $saved = social_comment_apply_action($pdo, $currentUser, $action, $feedType, $feedId, $_POST);
                }
            } catch (Throwable $error) {
                $expectedSocialError = $error instanceof InvalidArgumentException
                    || $error instanceof SocialActionException
                    || ($action === 'social_feed_copy_workout'
                        && $error instanceof RuntimeException
                        && !($error instanceof PDOException));
                if (!$expectedSocialError) {
                    error_log('Feed action failed [' . get_debug_type($error) . ']: ' . $error->getMessage());
                }
                $feedFallback = $action === 'social_feed_copy_workout'
                    ? t('workouts.routine_copy_failed')
                    : t('feed.comment_error');
                $feedError = social_action_public_error($error, $feedFallback);
            }
            $isFeedFetch = (string) ($_POST['feed_ajax'] ?? '') === '1'
                || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'feed-fetch';
            if ($isFeedFetch) {
                $validType = social_feed_entity_type($feedType);
                $visible = $validType !== '' && social_feed_entity_visible($pdo, (int) $currentUser['id'], $validType, $feedId);
                $liked = $visible && db_fetch_one($pdo, 'SELECT 1 FROM social_feed_likes WHERE user_id=:user AND entity_type=:type AND entity_id=:id', [':user' => (int) $currentUser['id'], ':type' => $validType, ':id' => $feedId]) !== null;
                $likeCount = $visible ? (int) (db_fetch_one($pdo, 'SELECT COUNT(*) AS n FROM social_feed_likes WHERE entity_type=:type AND entity_id=:id', [':type' => $validType, ':id' => $feedId])['n'] ?? 0) : 0;
                $commentData = $visible
                    ? social_comment_response_data($pdo, $currentUser, $validType, $feedId, '/?page=dashboard', $feedScope)
                    : ['comment_count' => 0, 'comments_html' => ''];
                $ok = $visible && $feedError === '' && (
                    $action === 'social_feed_like'
                    || ($action === 'social_feed_copy_workout' && (int) $saved > 0)
                    || is_array($saved)
                );
                if (!$ok) http_response_code(422);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode([
                    'ok' => $ok,
                    'liked' => $liked,
                    'like_count' => $likeCount,
                    'comment_count' => (int) ($commentData['comment_count'] ?? 0),
                    'comments_html' => in_array($action, $socialCommentActions, true) ? (string) ($commentData['comments_html'] ?? '') : '',
                    'routine_id' => $action === 'social_feed_copy_workout' ? (int) $saved : 0,
                    'routine_url' => $action === 'social_feed_copy_workout' && (int) $saved > 0 ? '/?page=workouts&routine_id=' . (int) $saved : '',
                    'message' => $ok ? '' : ($feedError !== '' ? $feedError : t('feed.comment_error')),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                exit;
            }
            if (in_array($action, $socialCommentActions, true) && (!is_array($saved) || $feedError !== '')) {
                flash_set('error', $feedError !== '' ? $feedError : t('feed.comment_error'));
            }
            if ($action === 'social_feed_copy_workout') {
                if ((int) $saved > 0 && $feedError === '') {
                    flash_set('success', t('workouts.workout_copied'));
                    redirect('/?page=workouts&routine_id=' . (int) $saved);
                }
                flash_set('error', $feedError !== '' ? $feedError : t('workouts.routine_copy_failed'));
            }
            $commentQuery = in_array($action, $socialCommentActions, true) ? '&comments=' . rawurlencode($feedType . '-' . $feedId) : '';
            redirect('/?page=dashboard&home=feed&feed=' . $feedScope . $commentQuery . '#feed-' . rawurlencode($feedType) . '-' . $feedId);
        }
        if ($action === 'restart_onboarding') {
            restart_user_onboarding($pdo, (int) $currentUser['id']);
            flash_set('success', t('onboarding.restarted'));
            redirect('/?page=onboarding');
        }
        if ($action === 'dismiss_onboarding_prompt') {
            dismiss_user_onboarding_prompt($pdo, (int) $currentUser['id']);
            flash_set('success', t('onboarding.prompt_dismissed'));
            redirect($dashboardRouteUrl);
        }
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
                'page' => $dashboardRoutePage,
            ];
            if (!empty($_POST['redirect_week_start'])) {
                $query['week_start'] = (string) $_POST['redirect_week_start'];
            }

            redirect('/?' . http_build_query($query));
        }

        if ($action === 'save_dashboard_layout' || $action === 'save_dashboard_prefs') {
            $allowedWidgets = ['mobile_today', 'mobile_primary', 'mobile_progress', 'mobile_shortcuts', 'motivation', 'kpis', 'nutrition', 'training_rank', 'training_progress', 'active_challenge', 'approvals', 'ranking', 'weekly', 'achievements', 'achievement_progress', 'duels', 'competitions', 'quests', 'season'];
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
            if (array_key_exists('metric_preferences_present', $_POST)) {
                $dashboardStepRaw = trim((string) ($_POST['metric_step_goal'] ?? ''));
                $dashboardStepGoal = $dashboardStepRaw !== ''
                    ? (parse_localized_positive_integer($dashboardStepRaw) ?? -1)
                    : 0;
                $dashboardDistanceRaw = trim((string) ($_POST['metric_distance_goal'] ?? ''));
                $dashboardWorkoutRaw = trim((string) ($_POST['metric_workout_target'] ?? ''));
                $dashboardBurnRaw = trim((string) ($_POST['metric_calorie_burn_goal'] ?? ''));
                $dashboardConsumedRaw = trim((string) ($_POST['metric_calorie_consumed_max'] ?? ''));
                $dashboardIdealRaw = trim((string) ($_POST['metric_ideal_weight'] ?? ''));
                $invalidDashboardTargets = $dashboardStepGoal < 0
                    || ($dashboardDistanceRaw !== '' && (!is_numeric($dashboardDistanceRaw) || (float) $dashboardDistanceRaw <= 0))
                    || ($dashboardWorkoutRaw !== '' && (!is_numeric($dashboardWorkoutRaw) || (int) $dashboardWorkoutRaw < 1 || (int) $dashboardWorkoutRaw > 14))
                    || ($dashboardBurnRaw !== '' && (!is_numeric($dashboardBurnRaw) || (float) $dashboardBurnRaw <= 0))
                    || ($dashboardConsumedRaw !== '' && (!is_numeric($dashboardConsumedRaw) || (float) $dashboardConsumedRaw <= 0))
                    || ($dashboardIdealRaw !== '' && (!is_numeric($dashboardIdealRaw) || (float) $dashboardIdealRaw < 25 || (float) $dashboardIdealRaw > 400));
                if ($invalidDashboardTargets) {
                    flash_set('error', $dashboardStepGoal < 0 ? t('onboarding.steps_invalid') : t('metric.invalid'));
                    redirect($dashboardRouteUrl . '&layout_edit=1');
                }
                $dashboardGoals = array_values(array_filter(
                    user_primary_goals($currentUser),
                    static fn(array $goal): bool => !in_array((string) ($goal['type'] ?? ''), ['steps', 'km'], true)
                ));
                if ($dashboardStepGoal > 0) {
                    array_unshift($dashboardGoals, ['type' => 'steps', 'value' => (float) $dashboardStepGoal]);
                }
                if ($dashboardDistanceRaw !== '') {
                    $dashboardGoals[] = ['type' => 'km', 'value' => (float) $dashboardDistanceRaw];
                }
                $dashboardLegacyGoal = $dashboardGoals[0] ?? null;
                db_execute(
                    $pdo,
                    'UPDATE users SET step_goal = :step_goal, workout_target = :workout_target,
                        primary_goal_type = :primary_goal_type, primary_goal_value = :primary_goal_value,
                        primary_goals_spec = :primary_goals_spec, calorie_burn_goal = :calorie_burn_goal,
                        calorie_consumed_max = :calorie_consumed_max, ideal_weight = :ideal_weight,
                        updated_at = :updated_at WHERE id = :id',
                    [
                        ':step_goal' => $dashboardStepGoal,
                        ':workout_target' => $dashboardWorkoutRaw !== '' ? (int) $dashboardWorkoutRaw : 0,
                        ':primary_goal_type' => is_array($dashboardLegacyGoal) ? (string) ($dashboardLegacyGoal['type'] ?? 'none') : 'none',
                        ':primary_goal_value' => is_array($dashboardLegacyGoal) ? (float) ($dashboardLegacyGoal['value'] ?? 0) : null,
                        ':primary_goals_spec' => $dashboardGoals !== [] ? format_primary_goals_spec($dashboardGoals) : null,
                        ':calorie_burn_goal' => $dashboardBurnRaw !== '' ? (float) $dashboardBurnRaw : null,
                        ':calorie_consumed_max' => $dashboardConsumedRaw !== '' ? (float) $dashboardConsumedRaw : null,
                        ':ideal_weight' => $dashboardIdealRaw !== '' ? (float) $dashboardIdealRaw : null,
                        ':updated_at' => now_iso(),
                        ':id' => (int) $currentUser['id'],
                    ]
                );
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
            if (array_key_exists('metric_preferences_present', $_POST)) {
                $dashboardPreferenceUser = db_fetch_one(
                    $pdo,
                    'SELECT * FROM users WHERE id = :id',
                    [':id' => (int) $currentUser['id']]
                ) ?? $currentUser;
                try {
                    save_user_metric_preferences($pdo, $dashboardPreferenceUser, (array) ($_POST['enabled_metrics'] ?? []));
                } catch (InvalidArgumentException $preferenceError) {
                    flash_set('error', $preferenceError->getMessage());
                    redirect($dashboardRouteUrl . '&layout_edit=1');
                }
            }
            audit_log($pdo, (int) $currentUser['id'], 'dashboard_preferences_updated', 'user', (string) $currentUser['id'], 'Dashboard preferences updated.', null, ['dashboard_view' => $_POST['dashboard_view'] ?? 'current_week', 'widgets' => $widgets, 'reset' => $resetLayout]);
            flash_set('success', t('flash.preferences_updated'));
            $dashboardRedirectParams = [
                'page' => $dashboardRoutePage,
                'view' => (string) ($_POST['dashboard_view'] ?? 'current_week'),
            ];
            redirect('/?' . http_build_query($dashboardRedirectParams));
        }
    }

    // Home is the social feed only. Resolve and render it before any of the
    // challenge metrics, dashboard widgets or Overview preferences below are
    // loaded; those belong exclusively to the standalone Overview route.
    if (!$dashboardStandaloneOverview) {
        $dashboardFeedScope = (string) ($_GET['feed'] ?? 'friends') === 'global' ? 'global' : 'friends';
        $dashboardFeedFocusType = social_feed_entity_type((string) ($_GET['post_type'] ?? ''));
        $dashboardFeedFocusId = max(0, (int) ($_GET['post_id'] ?? 0));
        $dashboardFeedFocused = $dashboardFeedFocusType !== '' && $dashboardFeedFocusId > 0;
        $dashboardFeedItems = social_feed_items(
            $pdo,
            (int) $currentUser['id'],
            $dashboardFeedScope,
            $dashboardFeedFocused ? 1 : 18,
            $dashboardFeedFocusType,
            $dashboardFeedFocusId
        );
        if ($dashboardFeedFocused && $dashboardFeedItems === []) {
            http_response_code(404);
        }

        render_view('dashboard', [
            'title' => t('feed.title'),
            'currentPage' => 'dashboard',
            'currentUser' => $currentUser,
            'dashboardStandaloneOverview' => false,
            'dashboardSection' => '',
            'dashboardFeedScope' => $dashboardFeedScope,
            'dashboardFeedItems' => $dashboardFeedItems,
            'dashboardFeedFocused' => $dashboardFeedFocused,
            'config' => $config,
        ]);
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

    // Home is always personal. Rankings can still contain teammates, but no
    // query parameter or administrator privilege may replace the owner of the
    // dashboard widgets.
    $selectedUserId = (int) $currentUser['id'];

    $selectedMetric = $metricsById[$selectedUserId] ?? null;
    if ($selectedMetric === null) {
        $personalMetrics = compute_challenge_metrics(
            $pdo,
            [$currentUser],
            (string) $settings['challenge_start'],
            (string) $settings['challenge_end']
        );
        $personalMetrics = apply_strike_review_overrides_to_metrics($pdo, $personalMetrics);
        $selectedMetric = $personalMetrics[$selectedUserId] ?? null;
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
    // The mobile "Today" card must never inherit the challenge/week range.
    // A challenge can already be over while the user keeps logging normally.
    $dashboardTodayDate = to_date(null);
    $dashboardTodayLog = fetch_log($pdo, (int) $currentUser['id'], $dashboardTodayDate) ?? [];
    $dashboardTodayCalorieStats = fetch_user_calorie_stats(
        $pdo,
        (int) $currentUser['id'],
        $dashboardTodayDate,
        $dashboardTodayDate,
        ($currentUser['maintenance_calories'] ?? null) !== null ? (float) $currentUser['maintenance_calories'] : null
    );
    $dashboardAchievementUserId = (int) ($selectedMetric['user']['id'] ?? $selectedUserId);
    if (challenge_is_active($settings)) {
        evaluate_automatic_achievements(
            $pdo,
            [$dashboardAchievementUserId => $selectedMetric]
        );
    }
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

    duels_ensure_schema($pdo);
    squads_ensure_schema($pdo);
    // Reuse the same team/metrics already computed above (default_team()) so the
    // challenge card matches whatever the team page would show, instead of
    // re-resolving "the user's team" independently and risking a mismatch.
    $dashboardTeamSummaryRows = team_rows_for_view(array_values($metricsByUser), 'total');
    $dashboardTeamSummary = team_summary_from_rows($dashboardTeamSummaryRows);
    $dashboardTeamCalories = resolve_team_calories_summary(
        $pdo,
        (int) $team['id'],
        (string) $settings['challenge_start'],
        (string) $settings['challenge_end']
    );
    $dashboardTeamSummary['calories_burned'] = (float) ($dashboardTeamCalories['burned'] ?? 0);
    $dashboardTeamSummary['calories_consumed'] = (float) ($dashboardTeamCalories['consumed'] ?? 0);
    $dashboardChallengeData = team_challenge_view_data($pdo, $team, $dashboardTeamSummary, $settings);
    $dashboardActiveChallenge = $dashboardChallengeData['active'];
    $dashboardActiveChallengeContributions = $dashboardActiveChallenge !== null
        ? team_challenge_contributions($pdo, $dashboardActiveChallenge, $users, $metricsByUser)
        : [];
    $dashboardDuelsSummary = duels_summary_for_user($pdo, (int) $currentUser['id']);
    $dashboardQuests = quests_for_user($pdo, $currentUser);
    $dashboardQuestRank = quests_rank_for_level((int) xp_user_level_info($pdo, (int) $currentUser['id'])['level']);
    $dashboardQuestStreak = quests_active_streak($pdo, (int) $currentUser['id']);
    $dashboardBadges = badges_for_user($pdo, (int) $currentUser['id']);
    $dashboardCompetitionsSummary = comp_summary_for_user($pdo, (int) $currentUser['id']);
    $dashboardSeason = seasons_current($pdo);
    $dashboardSeasonBoard = season_leaderboard($pdo, $dashboardSeason, 50);
    $dashboardSeasonXp = season_xp_for_user($pdo, (int) $currentUser['id'], $dashboardSeason);
    $dashboardSeasonDaysLeft = season_days_left($dashboardSeason);

    // Reuse the ranked-training domain on Home so the user sees their actual
    // strength position without opening the full training hub first.
    $dashboardTrainingUserId = (int) ($selectedMetric['user']['id'] ?? $selectedUserId);
    $dashboardTrainingLeaderboard = wk_rank_leaderboard($pdo, 20);
    $dashboardTrainingRank = null;
    $dashboardTrainingPosition = null;
    foreach ($dashboardTrainingLeaderboard as $trainingRow) {
        if ((int) ($trainingRow['id'] ?? 0) !== $dashboardTrainingUserId) {
            continue;
        }
        $dashboardTrainingRank = (array) ($trainingRow['rank'] ?? []);
        $dashboardTrainingPosition = isset($trainingRow['position'])
            ? (int) $trainingRow['position']
            : null;
        break;
    }
    if (!is_array($dashboardTrainingRank)) {
        $dashboardTrainingRank = wk_overall_rank_for_user($pdo, $dashboardTrainingUserId);
    }
    $dashboardTrainingLeaderboardPreview = array_slice($dashboardTrainingLeaderboard, 0, 3);
    $previewHasSelectedUser = false;
    foreach ($dashboardTrainingLeaderboardPreview as $trainingRow) {
        if ((int) ($trainingRow['id'] ?? 0) === $dashboardTrainingUserId) {
            $previewHasSelectedUser = true;
            break;
        }
    }
    if (!$previewHasSelectedUser) {
        foreach ($dashboardTrainingLeaderboard as $trainingRow) {
            if ((int) ($trainingRow['id'] ?? 0) === $dashboardTrainingUserId) {
                $dashboardTrainingLeaderboardPreview[] = $trainingRow;
                break;
            }
        }
    }
    $dashboardTrainingMonthStart = (new DateTimeImmutable('first day of this month'))->format('Y-m-d 00:00:00');
    $dashboardTrainingMonth = wk_summary_for_user($pdo, $dashboardTrainingUserId, $dashboardTrainingMonthStart);
    $dashboardTrainingAll = wk_summary_for_user($pdo, $dashboardTrainingUserId, null);
    $dashboardTrainingStreak = wk_streak_days($pdo, $dashboardTrainingUserId);
    $dashboardTrainingRecentSessions = wk_sessions_for_user($pdo, $dashboardTrainingUserId, 1);

    // A saved dashboard layout only stores the *visible* widgets, so a widget
    // added after the user last saved would stay invisible forever. Reconcile
    // once: append widgets this user has never been offered, and remember the
    // full set so a deliberately hidden widget is not resurrected later.
    $dashMobileSurfaces = ['mobile_today', 'mobile_primary', 'mobile_progress', 'mobile_shortcuts'];
    $dashDesktopWidgets = penalties_enabled($pdo)
        ? ['motivation', 'kpis', 'nutrition', 'training_rank', 'training_progress', 'active_challenge', 'quests', 'season', 'achievements', 'achievement_progress', 'duels', 'competitions', 'approvals', 'ranking', 'weekly']
        : ['motivation', 'kpis', 'nutrition', 'training_rank', 'training_progress', 'active_challenge', 'quests', 'season', 'achievements', 'achievement_progress', 'duels', 'competitions', 'ranking', 'weekly'];
    $dashAllWidgets = array_merge($dashMobileSurfaces, $dashDesktopWidgets);
    $savedDashLayout = json_decode((string) ($currentUser['dashboard_layout_json'] ?? ''), true);
    $knownDashWidgets = json_decode((string) ($currentUser['dashboard_widgets_known'] ?? ''), true);
    $knownDashWidgets = is_array($knownDashWidgets) ? $knownDashWidgets : [];
    $unknownDashWidgets = array_values(array_diff($dashAllWidgets, $knownDashWidgets));
    if ($unknownDashWidgets !== []) {
        if (is_array($savedDashLayout) && $savedDashLayout !== []) {
            $mergedDashLayout = array_values(array_unique(array_map('strval', $savedDashLayout)));
            $newMobileSurfaces = array_values(array_intersect($dashMobileSurfaces, $unknownDashWidgets));
            if ($newMobileSurfaces !== []) {
                array_splice($mergedDashLayout, 0, 0, $newMobileSurfaces);
            }
            $newTrainingWidgets = array_values(array_intersect(['training_rank', 'training_progress'], $unknownDashWidgets));
            if ($newTrainingWidgets !== []) {
                $kpiPosition = array_search('kpis', $mergedDashLayout, true);
                $insertAt = $kpiPosition === false ? 0 : (int) $kpiPosition + 1;
                array_splice($mergedDashLayout, $insertAt, 0, $newTrainingWidgets);
            }
            // The motivation quote leads the feed, so slot it in just above the
            // KPI strip rather than letting it fall to the end with the rest.
            if (in_array('motivation', $unknownDashWidgets, true) && !in_array('motivation', $mergedDashLayout, true)) {
                $kpiPosition = array_search('kpis', $mergedDashLayout, true);
                $insertAt = $kpiPosition === false ? 0 : (int) $kpiPosition;
                array_splice($mergedDashLayout, $insertAt, 0, ['motivation']);
            }
            $remainingUnknownWidgets = array_values(array_diff($unknownDashWidgets, $newMobileSurfaces, $newTrainingWidgets, $mergedDashLayout));
            $mergedDashLayout = array_values(array_unique(array_merge($mergedDashLayout, $remainingUnknownWidgets)));
            db_execute(
                $pdo,
                'UPDATE users SET dashboard_layout_json = :layout, dashboard_widgets_known = :known WHERE id = :id',
                [
                    ':layout' => json_encode($mergedDashLayout, JSON_UNESCAPED_SLASHES),
                    ':known' => json_encode($dashAllWidgets, JSON_UNESCAPED_SLASHES),
                    ':id' => (int) $currentUser['id'],
                ]
            );
            $currentUser['dashboard_layout_json'] = json_encode($mergedDashLayout, JSON_UNESCAPED_SLASHES);
        } else {
            db_execute(
                $pdo,
                'UPDATE users SET dashboard_widgets_known = :known WHERE id = :id',
                [':known' => json_encode($dashAllWidgets, JSON_UNESCAPED_SLASHES), ':id' => (int) $currentUser['id']]
            );
        }
    }

    $dashboardPanelPreferences = dashboard_panel_preferences($pdo, (int) $currentUser['id']);
    $dashboardCustomMetrics = custom_metrics_for_user($pdo, (int) $currentUser['id']);
    foreach ($dashboardCustomMetrics as &$dashboardCustomMetric) {
        $latestCustomValue = db_fetch_one(
            $pdo,
            'SELECT value, entry_date FROM custom_metric_entries
             WHERE metric_id = :metric AND user_id = :user
             ORDER BY entry_date DESC, id DESC LIMIT 1',
            [':metric' => (int) $dashboardCustomMetric['id'], ':user' => (int) $currentUser['id']]
        );
        $dashboardCustomMetric['latest_value'] = $latestCustomValue['value'] ?? null;
        $dashboardCustomMetric['latest_date'] = $latestCustomValue['entry_date'] ?? null;
    }
    unset($dashboardCustomMetric);

    render_view('dashboard', [
        'title' => t('overview.title'),
        // Keep the established dashboard body scope. dashboard.css contains a
        // large, intentional body[data-page="dashboard"] contract; the
        // standalone Overview is a route/surface distinction, not a new CSS
        // component namespace. Home intentionally stays highlighted as the
        // primary hub while Overview is selected from the avatar menu.
        'currentPage' => 'dashboard',
        'currentUser' => $currentUser,
        'dashboardStandaloneOverview' => $dashboardStandaloneOverview,
        'dashboardShowOnboardingPrompt' => user_should_show_onboarding_prompt($currentUser),
        'dashboardSection' => $dashboardSection,
        'dashboardActiveChallenge' => $dashboardActiveChallenge,
        'dashboardActiveChallengeContributions' => $dashboardActiveChallengeContributions,
        'dashboardTeam' => $team,
        'dashboardDuelsSummary' => $dashboardDuelsSummary,
        'dashboardQuests' => $dashboardQuests,
        'dashboardQuestRank' => $dashboardQuestRank,
        'dashboardQuestStreak' => $dashboardQuestStreak,
        'dashboardBadges' => $dashboardBadges,
        'dashboardCompetitionsSummary' => $dashboardCompetitionsSummary,
        'dashboardSeason' => $dashboardSeason,
        'dashboardSeasonBoard' => $dashboardSeasonBoard,
        'dashboardSeasonXp' => $dashboardSeasonXp,
        'dashboardSeasonDaysLeft' => $dashboardSeasonDaysLeft,
        'dashboardTrainingRank' => $dashboardTrainingRank,
        'dashboardTrainingPosition' => $dashboardTrainingPosition,
        'dashboardTrainingLeaderboardPreview' => $dashboardTrainingLeaderboardPreview,
        'dashboardTrainingMonth' => $dashboardTrainingMonth,
        'dashboardTrainingAll' => $dashboardTrainingAll,
        'dashboardTrainingStreak' => $dashboardTrainingStreak,
        'dashboardTrainingRecentSessions' => $dashboardTrainingRecentSessions,
        'settings' => $settings,
        'selectedMetric' => $selectedMetric,
        'selectedMetricSnapshot' => $selectedMetricSnapshot,
        'dashboardMetricDefinitions' => metric_preference_definitions($pdo, $currentUser),
        'dashboardEnabledMetrics' => metric_enabled_keys($pdo, $currentUser),
        'dashboardPanelPreferences' => $dashboardPanelPreferences,
        'dashboardCustomMetrics' => $dashboardCustomMetrics,
        'metricsOrdered' => array_values($metricsByUser),
        'selectedWeekStart' => $selectedWeekStart,
        'dashboardView' => $dashboardView,
        'weekOptions' => $weekOptions,
        'settlementSummary' => $settlementSummary,
        'pendingApprovals' => $pendingApprovals,
        'dashboardCalorieStats' => $dashboardCalorieStats,
        'dashboardCalorieRangeStart' => $calorieStartDate,
        'dashboardCalorieRangeEnd' => $calorieEndDate,
        'dashboardTodayDate' => $dashboardTodayDate,
        'dashboardTodayLog' => $dashboardTodayLog,
        'dashboardTodayCalorieStats' => $dashboardTodayCalorieStats,
        'dashboardAchievements' => $dashboardAchievements,
        'motivationQuote' => random_motivation_quote_from_db($pdo, (string) ($currentUser['locale'] ?? 'en')),
        'config' => $config,
    ]);
}

if ($page === 'ranks') {
    redirect('/?page=workouts&view=ranks');
}

http_response_code(404);
echo e(t('flash.not_found'));
