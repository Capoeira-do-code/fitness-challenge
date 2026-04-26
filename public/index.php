<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$page = $_GET['page'] ?? null;
if ($page === null) {
    $pathPage = trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/');
    if (in_array($pathPage, ['dashboard', 'entries', 'table', 'week_editor', 'profile', 'settings', 'team', 'team_settings', 'admin', 'login'], true)) {
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

    redirect(safe_redirect_target($_POST['redirect_to'] ?? '/'));
}

if ($page === 'logout') {
    $logoutMessage = t('flash.logout');
    logout_user();
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    flash_set('success', $logoutMessage);
    redirect('/?page=login');
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

    $payload = [
        'user_id' => $userId,
        'log_date' => to_date((string) ($json['log_date'] ?? null)),
        'steps' => max(0, (int) ($json['steps'] ?? 0)),
        'workout_done' => (int) ($json['workout_done'] ?? 0) === 1 ? 1 : 0,
        'workout_type_id' => !empty($json['workout_type_id']) ? (int) $json['workout_type_id'] : null,
        'workout_type' => trim((string) ($json['workout_type'] ?? '')),
        'junk_food' => (int) ($json['junk_food'] ?? 0) === 1 ? 1 : 0,
        'extra_workout' => (int) ($json['extra_workout'] ?? 0) === 1 ? 1 : 0,
        'distance_km' => ($json['distance_km'] ?? '') !== '' ? (float) $json['distance_km'] : null,
        'weight' => ($json['weight'] ?? '') !== '' ? (float) $json['weight'] : null,
        'notes' => trim((string) ($json['notes'] ?? '')),
        'step_exception_reason' => trim((string) ($json['step_exception_reason'] ?? '')),
        'workout_exception_reason' => trim((string) ($json['workout_exception_reason'] ?? '')),
        'morning_walk' => (int) ($json['morning_walk'] ?? 0) === 1 ? 1 : 0,
        'journaling' => (int) ($json['journaling'] ?? 0) === 1 ? 1 : 0,
        'evening_chores' => (int) ($json['evening_chores'] ?? 0) === 1 ? 1 : 0,
        'reading' => (int) ($json['reading'] ?? 0) === 1 ? 1 : 0,
        'habits' => is_array($json['habits'] ?? null) ? $json['habits'] : [],
    ];

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
    } catch (Throwable $e) {
        json_response(['ok' => false, 'message' => t('flash.save_failed')], 500);
    }

    json_response(['ok' => true]);
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

        if (login_user($pdo, $username, $password)) {
            $currentUser = current_user($pdo);
            set_current_locale(resolve_locale($config, $currentUser));
            flash_set('success', t('flash.welcome'));
            redirect('/?page=dashboard');
        }

        flash_set('error', t('flash.bad_credentials'));
        redirect('/?page=login');
    }

    render_view('login', [
        'title' => t('login.submit'),
        'currentPage' => 'login',
        'currentUser' => null,
        'config' => $config,
    ]);
}

$currentUser = require_login($pdo);

if ($page === 'entries') {
    if (is_post()) {
        if (!csrf_verify()) {
            flash_set('error', t('flash.csrf'));
            redirect('/?page=entries');
        }

        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'save_log') {
            $userId = (int) $currentUser['id'];

            $date = to_date($_POST['log_date'] ?? null);

            try {
                $habitValues = [];
                foreach (list_habit_definitions($pdo, false) as $habit) {
                    $code = (string) $habit['code'];
                    $habitValues[$code] = isset($_POST['habit'][$code]) && $_POST['habit'][$code] === '1' ? 1 : 0;
                }
                $payload = [
                    'user_id' => $userId,
                    'log_date' => $date,
                    'steps' => max(0, (int) ($_POST['steps'] ?? 0)),
                    'workout_done' => bool_from_form('workout_done'),
                    'workout_type_id' => !empty($_POST['workout_type_id']) ? (int) $_POST['workout_type_id'] : null,
                    'workout_type' => trim((string) ($_POST['workout_type'] ?? '')),
                    'junk_food' => bool_from_form('junk_food'),
                    'extra_workout' => bool_from_form('extra_workout'),
                    'distance_km' => ($_POST['distance_km'] ?? '') !== '' ? (float) $_POST['distance_km'] : null,
                    'weight' => ($_POST['weight'] ?? '') !== '' ? (float) $_POST['weight'] : null,
                    'notes' => trim((string) ($_POST['notes'] ?? '')),
                    'step_exception_reason' => trim((string) ($_POST['step_exception_reason'] ?? '')),
                    'workout_exception_reason' => trim((string) ($_POST['workout_exception_reason'] ?? '')),
                    'morning_walk' => bool_from_form('morning_walk'),
                    'journaling' => bool_from_form('journaling'),
                    'evening_chores' => bool_from_form('evening_chores'),
                    'reading' => bool_from_form('reading'),
                    'habits' => $habitValues,
                ];
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
                flash_set('success', t('flash.log_saved'));
            } catch (Throwable $e) {
                flash_set('error', t('flash.save_failed'));
            }

            redirect('/?page=entries&date=' . $date);
        }

        if ($action === 'upload_photo') {
            $userId = (int) $currentUser['id'];

            $date = to_date($_POST['log_date'] ?? null);
            $category = (string) ($_POST['category'] ?? 'other');
            $caption = trim((string) ($_POST['caption'] ?? ''));

            try {
                save_photo_entry($pdo, $config, $userId, $date, $category, $caption, $_FILES['photo'] ?? []);
                audit_log(
                    $pdo,
                    (int) $currentUser['id'],
                    'photo_uploaded',
                    'photo_entry',
                    $userId . ':' . $date,
                    'Proof photo uploaded.',
                    null,
                    ['user_id' => $userId, 'log_date' => $date, 'category' => $category, 'caption' => $caption]
                );
                flash_set('success', t('flash.photo_uploaded'));
            } catch (Throwable $e) {
                flash_set('error', $e->getMessage());
            }

            redirect('/?page=entries&date=' . $date);
        }
    }

    $users = [$currentUser];
    $selectedUserId = (int) $currentUser['id'];

    $selectedDate = to_date($_GET['date'] ?? null);
    $currentLog = fetch_log($pdo, $selectedUserId, $selectedDate);
    $recentPhotos = fetch_recent_photos($pdo, 20);
    $workoutTypes = list_workout_types($pdo, true);

    render_view('entries', [
        'title' => t('entries.title'),
        'currentPage' => 'entries',
        'currentUser' => $currentUser,
        'users' => $users,
        'selectedUserId' => $selectedUserId,
        'selectedDate' => $selectedDate,
        'currentLog' => $currentLog,
        'recentPhotos' => $recentPhotos,
        'workoutTypes' => $workoutTypes,
        'habits' => list_habit_definitions($pdo, true),
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

    $weekInput = to_date($_GET['week_start'] ?? null, (new DateTimeImmutable('monday this week'))->format('Y-m-d'));
    $weekStartObj = week_start_for(new DateTimeImmutable($weekInput));
    $weekStart = $weekStartObj->format('Y-m-d');
    $weekEnd = $weekStartObj->modify('+6 days')->format('Y-m-d');

    $weekDates = array_map(
        static fn(DateTimeImmutable $d): string => $d->format('Y-m-d'),
        day_sequence($weekStartObj, $weekStartObj->modify('+6 days'))
    );

    $logs = fetch_logs_for_user_between($pdo, $selectedUserId, $weekStart, $weekEnd);
    $logsByDate = [];
    foreach ($logs as $log) {
        $logsByDate[$log['log_date']] = $log;
    }

    $settings = challenge_settings($pdo, $config);
    if (!challenge_is_active($settings)) {
        flash_set('error', t('flash.challenge_inactive'));
        redirect('/?page=admin');
    }
    $metrics = compute_challenge_metrics($pdo, [$selectedUser], (string) $settings['challenge_start'], (string) $settings['challenge_end']);
    $viewName = $page === 'week_editor' ? 'week_editor' : 'table';

    render_view($viewName, [
        'title' => $page === 'week_editor' ? t('table.editor_title') : t('table.title'),
        'currentPage' => 'table',
        'currentUser' => $currentUser,
        'users' => $users,
        'selectedUser' => $selectedUser,
        'weekStart' => $weekStart,
        'weekEnd' => $weekEnd,
        'weekDates' => $weekDates,
        'logsByDate' => $logsByDate,
        'selectedMetric' => array_values($metrics)[0] ?? null,
        'workoutTypes' => list_workout_types($pdo, true),
        'habits' => list_habit_definitions($pdo, true),
        'config' => $config,
    ]);
}

if ($page === 'settings') {
    if (is_post()) {
        if (!csrf_verify()) {
            flash_set('error', t('flash.csrf'));
            redirect('/?page=settings');
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
            $allowedWidgets = ['kpis', 'money', 'approvals', 'steps', 'weight', 'comparison', 'meals', 'ranking', 'weekly'];
            $selectedWidgets = array_values(array_intersect(array_map('strval', (array) ($_POST['dashboard_widgets'] ?? [])), $allowedWidgets));
            $widgetOrder = (array) ($_POST['dashboard_order'] ?? []);
            usort($selectedWidgets, static function (string $left, string $right) use ($widgetOrder, $allowedWidgets): int {
                $leftOrder = isset($widgetOrder[$left]) ? (int) $widgetOrder[$left] : (int) array_search($left, $allowedWidgets, true);
                $rightOrder = isset($widgetOrder[$right]) ? (int) $widgetOrder[$right] : (int) array_search($right, $allowedWidgets, true);
                return $leftOrder <=> $rightOrder;
            });
            db_execute(
                $pdo,
                'UPDATE users
                 SET primary_goal_type = :primary_goal_type,
                     primary_goal_value = :primary_goal_value,
                     dashboard_view = :dashboard_view,
                     dashboard_layout_json = :dashboard_layout_json,
                     updated_at = :updated_at
                 WHERE id = :id',
                [
                    ':primary_goal_type' => $primaryType,
                    ':primary_goal_value' => ($_POST['primary_goal_value'] ?? '') !== '' ? (float) $_POST['primary_goal_value'] : null,
                    ':dashboard_view' => (string) ($_POST['dashboard_view'] ?? 'current_week'),
                    ':dashboard_layout_json' => json_encode($selectedWidgets, JSON_UNESCAPED_SLASHES),
                    ':updated_at' => now_iso(),
                    ':id' => (int) $currentUser['id'],
                ]
            );
            $after = db_fetch_one($pdo, 'SELECT * FROM users WHERE id = :id', [':id' => (int) $currentUser['id']]);
            audit_log($pdo, (int) $currentUser['id'], 'user_preferences_updated', 'user', (string) $currentUser['id'], 'User preferences updated.', audit_snapshot($before, ['password_hash']), audit_snapshot($after, ['password_hash']));
            flash_set('success', t('flash.preferences_updated'));
            redirect('/?page=settings');
        }

        if ($action === 'upload_avatar') {
            try {
                $path = save_uploaded_image($config, $_FILES['avatar'] ?? [], 'avatars', 'user_' . (string) $currentUser['id']);
                db_execute($pdo, 'UPDATE users SET avatar_path = :avatar_path, updated_at = :updated_at WHERE id = :id', [':avatar_path' => $path, ':updated_at' => now_iso(), ':id' => (int) $currentUser['id']]);
                audit_log($pdo, (int) $currentUser['id'], 'avatar_updated', 'user', (string) $currentUser['id'], 'Avatar updated.', null, ['avatar_path' => $path]);
                flash_set('success', t('flash.avatar_updated'));
            } catch (Throwable $e) {
                flash_set('error', $e->getMessage());
            }
            redirect('/?page=settings');
        }
    }

    $currentUser = current_user($pdo) ?? $currentUser;
    render_view('settings', [
        'title' => t('settings.title'),
        'currentPage' => 'settings',
        'currentUser' => $currentUser,
        'config' => $config,
    ]);
}

if ($page === 'profile') {
    if (is_post()) {
        if (!csrf_verify()) {
            flash_set('error', t('flash.csrf'));
            redirect('/?page=profile');
        }

        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'change_password') {
            $currentPassword = (string) ($_POST['current_password'] ?? '');
            $newPassword = (string) ($_POST['new_password'] ?? '');
            $newPasswordConfirm = (string) ($_POST['new_password_confirm'] ?? '');

            if ($newPassword !== $newPasswordConfirm) {
                flash_set('error', t('flash.password_mismatch'));
                redirect('/?page=profile');
            }

            if (strlen($newPassword) < 8) {
                flash_set('error', t('flash.password_short'));
                redirect('/?page=profile');
            }

            if (!change_password($pdo, (int) $currentUser['id'], $currentPassword, $newPassword)) {
                flash_set('error', t('flash.current_password_wrong'));
                redirect('/?page=profile');
            }

            audit_log(
                $pdo,
                (int) $currentUser['id'],
                'password_changed',
                'user',
                (string) $currentUser['id'],
                'Password changed.',
                null,
                ['password_changed' => true]
            );
            flash_set('success', t('flash.password_updated'));
            redirect('/?page=profile');
        }

        if ($action === 'create_goal') {
            $title = trim((string) ($_POST['title'] ?? ''));
            if ($title !== '') {
                create_goal($pdo, [
                    'scope' => 'user',
                    'team_id' => null,
                    'user_id' => (int) $currentUser['id'],
                    'title' => $title,
                    'target_type' => trim((string) ($_POST['target_type'] ?? 'custom')) ?: 'custom',
                    'target_value' => ($_POST['target_value'] ?? '') !== '' ? (float) $_POST['target_value'] : null,
                    'current_value' => 0,
                    'due_date' => ($_POST['due_date'] ?? '') !== '' ? to_date((string) $_POST['due_date']) : null,
                ], (int) $currentUser['id']);
                flash_set('success', t('flash.goal_created'));
            }
            redirect('/?page=profile');
        }

        if ($action === 'goal_status') {
            update_goal_status($pdo, (int) ($_POST['goal_id'] ?? 0), (string) ($_POST['status'] ?? 'active'), (int) $currentUser['id']);
            flash_set('success', t('flash.goal_updated'));
            redirect('/?page=profile');
        }

        if ($action === 'update_goal') {
            update_goal($pdo, (int) ($_POST['goal_id'] ?? 0), [
                'title' => trim((string) ($_POST['title'] ?? '')),
                'target_type' => trim((string) ($_POST['target_type'] ?? 'custom')),
                'target_value' => ($_POST['target_value'] ?? '') !== '' ? (float) $_POST['target_value'] : null,
                'due_date' => ($_POST['due_date'] ?? '') !== '' ? to_date((string) $_POST['due_date']) : null,
            ], (int) $currentUser['id']);
            flash_set('success', t('flash.goal_updated'));
            redirect('/?page=profile');
        }
    }

    $settings = challenge_settings($pdo, $config);
    $metrics = compute_challenge_metrics(
        $pdo,
        [$currentUser],
        (string) $settings['challenge_start'],
        (string) $settings['challenge_end']
    );
    evaluate_automatic_achievements($pdo, $metrics);

    render_view('profile', [
        'title' => t('profile.title'),
        'currentPage' => 'profile',
        'currentUser' => $currentUser,
        'personalGoals' => list_goals($pdo, 'user', (int) $currentUser['id']),
        'userAchievements' => list_awarded_achievements($pdo, (int) $currentUser['id'], null),
        'recentActivity' => fetch_audit_logs($pdo, ['actor_user_id' => (int) $currentUser['id']], 12),
        'habits' => list_habit_definitions($pdo, true),
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

        if ($action === 'update_challenge_settings') {
            update_challenge_settings(
                $pdo,
                (string) ($_POST['challenge_name'] ?? ''),
                (string) ($_POST['challenge_start'] ?? ''),
                (string) ($_POST['challenge_end'] ?? ''),
                (int) $currentUser['id']
            );
            flash_set('success', t('flash.challenge_updated'));
            redirect('/?page=admin');
        }

        if ($action === 'archive_challenge') {
            if ((string) ($_POST['confirm_archive'] ?? '') === 'ARCHIVE') {
                archive_challenge($pdo, (int) $currentUser['id']);
                flash_set('success', t('flash.challenge_archived'));
            }
            redirect('/?page=admin');
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
                'motivation_quote' => trim((string) ($_POST['motivation_quote'] ?? '')),
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
                'motivation_quote' => trim((string) ($_POST['motivation_quote'] ?? '')),
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
            redirect('/?page=admin');
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

        if ($action === 'create_achievement') {
            $name = trim((string) ($_POST['name'] ?? ''));
            if ($name !== '') {
                $imagePath = null;
                if (!empty($_FILES['image']['name'])) {
                    $imagePath = save_uploaded_image($config, $_FILES['image'], 'achievements', 'achievement');
                }
                if (!empty($_POST['conditional'])) {
                    create_conditional_achievement($pdo, [
                        'name' => $name,
                        'description' => trim((string) ($_POST['description'] ?? '')),
                        'scope' => (string) ($_POST['scope'] ?? 'user'),
                        'image_path' => $imagePath,
                        'reward_text' => trim((string) ($_POST['reward_text'] ?? '')),
                        'metric_key' => (string) ($_POST['metric_key'] ?? 'steps'),
                        'operator' => (string) ($_POST['operator'] ?? '>='),
                        'target_value' => (float) ($_POST['target_value'] ?? 1),
                        'window' => (string) ($_POST['window'] ?? 'total'),
                    ], (int) $currentUser['id']);
                } else {
                    create_manual_achievement($pdo, $name, trim((string) ($_POST['description'] ?? '')), (string) ($_POST['scope'] ?? 'user'), (int) $currentUser['id'], $imagePath, trim((string) ($_POST['reward_text'] ?? '')));
                }
                flash_set('success', t('flash.achievement_created'));
            }
            redirect('/?page=admin');
        }

        if ($action === 'grant_achievement') {
            $scope = (string) ($_POST['scope'] ?? 'user');
            award_achievement(
                $pdo,
                (int) ($_POST['achievement_id'] ?? 0),
                $scope === 'user' ? (int) ($_POST['user_id'] ?? 0) : null,
                $scope === 'team' ? (int) ($_POST['team_id'] ?? 0) : null,
                (int) $currentUser['id'],
                trim((string) ($_POST['note'] ?? ''))
            );
            flash_set('success', t('flash.achievement_granted'));
            redirect('/?page=admin');
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
            update_team_settings($pdo, (int) ($_POST['team_id'] ?? 0), (string) ($_POST['name'] ?? ''), (string) ($_POST['join_mode'] ?? 'closed'), (string) ($_POST['visibility'] ?? 'visible'), (int) $currentUser['id']);
            flash_set('success', t('flash.team_updated'));
            redirect('/?page=admin');
        }

        if ($action === 'resolve_join_request') {
            resolve_team_join_request($pdo, (int) ($_POST['request_id'] ?? 0), (string) ($_POST['decision'] ?? '') === 'approve', (int) $currentUser['id']);
            flash_set('success', t('flash.team_updated'));
            redirect('/?page=admin');
        }

        if ($action === 'upload_app_icon') {
            try {
                $path = save_uploaded_image($config, $_FILES['app_icon'] ?? [], 'app', 'app_icon');
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
        'habits' => list_habit_definitions($pdo, false),
        'achievements' => list_achievements($pdo, true),
        'appIconPath' => app_setting($pdo, 'app_icon_path'),
        'appNameSetting' => app_setting($pdo, 'app_name', (string) ($config['app_name'] ?? 'Fitness Challenge Tracker')),
        'challengeSettings' => challenge_settings($pdo, $config),
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
            update_team_settings($pdo, (int) $team['id'], (string) ($_POST['name'] ?? ''), (string) ($_POST['join_mode'] ?? 'closed'), (string) ($_POST['visibility'] ?? 'visible'), (int) $currentUser['id']);
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

        if ($action === 'create_goal') {
            $title = trim((string) ($_POST['title'] ?? ''));
            if ($title !== '') {
                create_goal($pdo, [
                    'scope' => 'team',
                    'team_id' => (int) $team['id'],
                    'user_id' => null,
                    'title' => $title,
                    'target_type' => trim((string) ($_POST['target_type'] ?? 'custom')) ?: 'custom',
                    'target_value' => ($_POST['target_value'] ?? '') !== '' ? (float) $_POST['target_value'] : null,
                    'current_value' => 0,
                    'due_date' => ($_POST['due_date'] ?? '') !== '' ? to_date((string) $_POST['due_date']) : null,
                ], (int) $currentUser['id']);
                flash_set('success', t('flash.goal_created'));
            }
            redirect('/?page=team');
        }

        if ($action === 'update_goal') {
            update_goal($pdo, (int) ($_POST['goal_id'] ?? 0), [
                'title' => trim((string) ($_POST['title'] ?? '')),
                'target_type' => trim((string) ($_POST['target_type'] ?? 'custom')),
                'target_value' => ($_POST['target_value'] ?? '') !== '' ? (float) $_POST['target_value'] : null,
                'due_date' => ($_POST['due_date'] ?? '') !== '' ? to_date((string) $_POST['due_date']) : null,
            ], (int) $currentUser['id']);
            flash_set('success', t('flash.goal_updated'));
            redirect('/?page=team');
        }

        if ($action === 'goal_status') {
            update_goal_status($pdo, (int) ($_POST['goal_id'] ?? 0), (string) ($_POST['status'] ?? 'active'), (int) $currentUser['id']);
            flash_set('success', t('flash.goal_updated'));
            redirect('/?page=team');
        }

        if ($action === 'create_team_achievement') {
            require_team_manager($pdo, $currentUser, (int) $team['id']);
            $imagePath = null;
            if (!empty($_FILES['image']['name'])) {
                $imagePath = save_uploaded_image($config, $_FILES['image'], 'achievements', 'team');
            }
            create_manual_achievement($pdo, trim((string) ($_POST['name'] ?? '')), trim((string) ($_POST['description'] ?? '')), 'team', (int) $currentUser['id'], $imagePath, trim((string) ($_POST['reward_text'] ?? '')));
            flash_set('success', t('flash.achievement_created'));
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
        flash_set('error', t('flash.challenge_inactive'));
        redirect('/?page=admin');
    }
    $teamUsers = list_active_team_users($pdo, (int) $team['id']);
    $metricsByUser = compute_challenge_metrics(
        $pdo,
        $teamUsers,
        (string) $settings['challenge_start'],
        (string) $settings['challenge_end']
    );
    evaluate_automatic_achievements($pdo, $metricsByUser, (int) $team['id']);

    render_view('team', [
        'title' => t('team.title'),
        'currentPage' => 'team',
        'currentUser' => $currentUser,
        'team' => $team,
        'members' => list_team_members($pdo, (int) $team['id'], true),
        'availableUsers' => list_users_not_in_active_team($pdo, (int) $team['id']),
        'metricsOrdered' => array_values($metricsByUser),
        'teamSummary' => team_summary_from_metrics(array_values($metricsByUser)),
        'teamGoals' => list_goals($pdo, 'team', null, (int) $team['id']),
        'teamAchievements' => list_awarded_achievements($pdo, null, (int) $team['id']),
        'canManageTeam' => can_manage_team($pdo, $currentUser, (int) $team['id']),
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
            if (!empty($_POST['redirect_steps_range'])) {
                $query['steps_range'] = (string) $_POST['redirect_steps_range'];
            }

            redirect('/?' . http_build_query($query));
        }

        if ($action === 'save_dashboard_layout' || $action === 'save_dashboard_prefs') {
            $allowedWidgets = ['kpis', 'money', 'approvals', 'steps', 'weight', 'comparison', 'meals', 'ranking', 'weekly'];
            $widgets = array_values(array_intersect(array_map('strval', (array) ($_POST['dashboard_widgets'] ?? [])), $allowedWidgets));
            $widgetOrder = (array) ($_POST['dashboard_order'] ?? []);
            usort($widgets, static function (string $left, string $right) use ($widgetOrder, $allowedWidgets): int {
                $leftOrder = isset($widgetOrder[$left]) ? (int) $widgetOrder[$left] : (int) array_search($left, $allowedWidgets, true);
                $rightOrder = isset($widgetOrder[$right]) ? (int) $widgetOrder[$right] : (int) array_search($right, $allowedWidgets, true);
                return $leftOrder <=> $rightOrder;
            });
            db_execute(
                $pdo,
                'UPDATE users SET dashboard_view = :dashboard_view, dashboard_layout_json = :layout, updated_at = :updated_at WHERE id = :id',
                [
                    ':dashboard_view' => (string) ($_POST['dashboard_view'] ?? 'current_week'),
                    ':layout' => json_encode($widgets, JSON_UNESCAPED_SLASHES),
                    ':updated_at' => now_iso(),
                    ':id' => (int) $currentUser['id'],
                ]
            );
            audit_log($pdo, (int) $currentUser['id'], 'dashboard_preferences_updated', 'user', (string) $currentUser['id'], 'Dashboard preferences updated.', null, ['dashboard_view' => $_POST['dashboard_view'] ?? 'current_week', 'widgets' => $widgets]);
            flash_set('success', t('flash.preferences_updated'));
            redirect('/?page=dashboard&view=' . rawurlencode((string) ($_POST['dashboard_view'] ?? 'current_week')) . '&steps_range=' . rawurlencode((string) ($_POST['steps_range'] ?? '30')));
        }

        if ($action === 'save_meal_calendar_view') {
            $mealView = (string) ($_POST['meal_calendar_view'] ?? 'week');
            $mealView = in_array($mealView, ['week', 'month'], true) ? $mealView : 'week';
            db_execute($pdo, 'UPDATE users SET meal_calendar_view = :view, updated_at = :updated_at WHERE id = :id', [':view' => $mealView, ':updated_at' => now_iso(), ':id' => (int) $currentUser['id']]);
            audit_log($pdo, (int) $currentUser['id'], 'meal_calendar_view_updated', 'user', (string) $currentUser['id'], 'Meal calendar view updated.', null, ['meal_calendar_view' => $mealView]);
            redirect('/?page=dashboard&view=' . rawurlencode((string) ($_POST['dashboard_view'] ?? 'current_week')) . '&steps_range=' . rawurlencode((string) ($_POST['steps_range'] ?? '30')));
        }

        if ($action === 'quick_meal_upload') {
            try {
                save_photo_entry($pdo, $config, (int) $currentUser['id'], to_date($_POST['log_date'] ?? null), (string) ($_POST['category'] ?? 'meal'), trim((string) ($_POST['caption'] ?? '')), $_FILES['photo'] ?? []);
                flash_set('success', t('flash.photo_uploaded'));
            } catch (Throwable $e) {
                flash_set('error', $e->getMessage());
            }
            redirect('/?page=dashboard');
        }
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
    $dashboardView = (string) ($_GET['view'] ?? ($currentUser['dashboard_view'] ?? 'current_week'));
    if (!in_array($dashboardView, ['current_week', 'total'], true)) {
        $dashboardView = to_date($dashboardView, $defaultWeekStart);
    }
    $selectedWeekStart = $dashboardView === 'current_week' ? $defaultWeekStart : ($dashboardView === 'total' ? $defaultWeekStart : to_date($dashboardView, $defaultWeekStart));

    if (!in_array($selectedWeekStart, $weekOptions, true) && $weekOptions !== []) {
        $selectedWeekStart = $defaultWeekStart;
    }

    $compareMetric = null;
    foreach ($metricsByUser as $metric) {
        if ((int) $metric['user']['id'] !== (int) $selectedMetric['user']['id']) {
            $compareMetric = $metric;
            break;
        }
    }

    $settlementSummary = weekly_settlement_summary(array_values($metricsByUser), $selectedWeekStart);
    $pendingApprovals = fetch_pending_approvals($pdo, $currentUser, null, 80);
    $stepsRange = (string) ($_GET['steps_range'] ?? '30');
    if (!in_array($stepsRange, ['30', '90', 'all'], true)) {
        $stepsRange = '30';
    }
    if ($dashboardView !== (string) ($currentUser['dashboard_view'] ?? 'current_week')) {
        db_execute($pdo, 'UPDATE users SET dashboard_view = :view, updated_at = :updated_at WHERE id = :id', [':view' => $dashboardView, ':updated_at' => now_iso(), ':id' => (int) $currentUser['id']]);
        $currentUser['dashboard_view'] = $dashboardView;
    }
    $mealView = (string) ($_GET['meal_view'] ?? ($currentUser['meal_calendar_view'] ?? 'week'));
    if (!in_array($mealView, ['week', 'month'], true)) {
        $mealView = 'week';
    }
    $mealStart = $mealView === 'month'
        ? (new DateTimeImmutable('today'))->modify('first day of this month')->format('Y-m-d')
        : week_start_for(new DateTimeImmutable('today'))->format('Y-m-d');

    render_view('dashboard', [
        'title' => t('nav.dashboard'),
        'currentPage' => 'dashboard',
        'currentUser' => $currentUser,
        'settings' => $settings,
        'users' => $users,
        'selectedMetric' => $selectedMetric,
        'compareMetric' => $compareMetric,
        'metricsOrdered' => array_values($metricsByUser),
        'selectedWeekStart' => $selectedWeekStart,
        'dashboardView' => $dashboardView,
        'stepsRange' => $stepsRange,
        'mealView' => $mealView,
        'weekOptions' => $weekOptions,
        'settlementSummary' => $settlementSummary,
        'pendingApprovals' => $pendingApprovals,
        'mealCalendar' => fetch_meal_calendar($pdo, $mealStart, (int) $currentUser['id'], $mealView),
        'latestMeal' => fetch_latest_meal_photo($pdo, (int) $currentUser['id']),
        'config' => $config,
    ]);
}

http_response_code(404);
echo e(t('flash.not_found'));
