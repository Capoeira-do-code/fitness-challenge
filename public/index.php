<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$page = $_GET['page'] ?? null;
if ($page === null) {
    $pathPage = trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/');
    if (in_array($pathPage, ['dashboard', 'entries', 'table', 'week_editor', 'profile', 'settings', 'team', 'team_settings', 'admin', 'metric', 'penalties', 'comparison_detail', 'strikes_detail', 'login'], true)) {
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

    if (!empty($_POST['async']) || strpos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false) {
        json_response(['ok' => true, 'locale' => $locale]);
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
            $currentUser = current_user($pdo);
            set_current_locale(resolve_locale($config, $currentUser));
            flash_set('success', t('flash.welcome'));
            redirect('/?page=dashboard');
        }

        register_failed_login_attempt($pdo, $username, $ipAddress);
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

$currentUser = require_login($pdo);

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

                $workoutTypeIds = is_array($_POST['workout_type_id'] ?? null) ? array_values((array) $_POST['workout_type_id']) : [];
                $workoutTypes = is_array($_POST['workout_type'] ?? null) ? array_values((array) $_POST['workout_type']) : [];
                if ($workoutTypeIds === [] && isset($_POST['workout_type_id']) && !is_array($_POST['workout_type_id'])) {
                    $workoutTypeIds[] = (string) $_POST['workout_type_id'];
                }
                if ($workoutTypes === [] && isset($_POST['workout_type']) && !is_array($_POST['workout_type'])) {
                    $workoutTypes[] = (string) $_POST['workout_type'];
                }
                $workoutRowCount = max(count($workoutTypeIds), count($workoutTypes));
                $rawWorkouts = [];
                for ($i = 0; $i < $workoutRowCount; $i++) {
                    $rawWorkouts[] = [
                        'workout_type_id' => $workoutTypeIds[$i] ?? null,
                        'workout_type' => $workoutTypes[$i] ?? '',
                    ];
                }

                $payload = [
                    'user_id' => $userId,
                    'log_date' => $date,
                    'steps' => max(0, (int) ($_POST['steps'] ?? 0)),
                    'workout_done' => 0,
                    'workout_type_id' => null,
                    'workout_type' => '',
                    'workouts' => $rawWorkouts,
                    'junk_food' => bool_from_form('junk_food'),
                    'extra_workout' => bool_from_form('extra_workout'),
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

    $users = [$currentUser];
    $selectedUserId = (int) $currentUser['id'];

    $selectedDate = to_date($_GET['date'] ?? null);
    $calendarView = (string) ($_GET['calendar_view'] ?? ($currentUser['meal_calendar_view'] ?? 'month'));
    if (!in_array($calendarView, ['month', 'week', 'day'], true)) {
        $calendarView = 'month';
    }
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
        'selectedDate' => $selectedDate,
        'currentLog' => $currentLog,
        'recentPhotos' => $recentPhotos,
        'mealCalendar' => $mealCalendar,
        'calendarView' => $calendarView,
        'workoutTypes' => $workoutTypes,
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

    if (is_post()) {
        if (!csrf_verify()) {
            flash_set('error', t('flash.csrf'));
            redirect('/?page=photo&photo_id=' . (int) $photoId);
        }

        $action = (string) ($_POST['action'] ?? '');

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
        'currentPage' => 'entries',
        'currentUser' => $currentUser,
        'photo' => $photo,
        'comments' => fetch_photo_comments($pdo, $photoId, 250),
        'canDeletePhoto' => $canDeletePhoto,
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

    $defaultMonday = (new DateTimeImmutable('monday this week'))->format('Y-m-d');
    $weekInput = isset($_GET['week']) && $_GET['week'] !== ''
        ? week_to_monday((string) $_GET['week'], $defaultMonday)
        : to_date($_GET['week_start'] ?? null, $defaultMonday);
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
    $approvalRequestsByDate = fetch_approval_requests_by_user_between($pdo, $selectedUserId, $weekStart, $weekEnd);

    $settings = challenge_settings($pdo, $config);
    if (!challenge_is_active($settings)) {
        flash_set('error', t('flash.challenge_inactive'));
        redirect('/?page=admin');
    }
    $metrics = compute_challenge_metrics($pdo, [$selectedUser], (string) $settings['challenge_start'], (string) $settings['challenge_end']);
    $metrics = apply_strike_review_overrides_to_metrics($pdo, $metrics);
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
        'approvalRequestsByDate' => $approvalRequestsByDate,
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
            $layoutJson = (string) ($before['dashboard_layout_json'] ?? '[]');
            $hasWidgetPayload = array_key_exists('dashboard_widgets', $_POST) || array_key_exists('dashboard_order', $_POST);
            if ($hasWidgetPayload) {
                $allowedWidgets = ['kpis', 'distance_walked', 'approvals', 'steps', 'steps_cumulative', 'distance_cumulative', 'weight', 'comparison', 'ranking', 'meals', 'weekly', 'calories'];
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
                     dashboard_view = :dashboard_view,
                     dashboard_layout_json = :dashboard_layout_json,
                     updated_at = :updated_at
                 WHERE id = :id',
                [
                    ':primary_goal_type' => $primaryType,
                    ':primary_goal_value' => ($_POST['primary_goal_value'] ?? '') !== '' ? (float) $_POST['primary_goal_value'] : null,
                    ':calorie_burn_goal' => ($_POST['calorie_burn_goal'] ?? '') !== '' ? max(0.0, (float) $_POST['calorie_burn_goal']) : null,
                    ':calorie_consumed_max' => ($_POST['calorie_consumed_max'] ?? '') !== '' ? max(0.0, (float) $_POST['calorie_consumed_max']) : null,
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
    $profileUrl = static function (?string $section = null, array $extra = []) use ($profileBaseQuery): string {
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
    auto_complete_user_goals(
        $pdo,
        (int) $profileUser['id'],
        (string) $settings['challenge_start'],
        (string) $settings['challenge_end'],
        null
    );
    $metrics = compute_challenge_metrics(
        $pdo,
        [$profileUser],
        (string) $settings['challenge_start'],
        (string) $settings['challenge_end']
    );
    $metrics = apply_strike_review_overrides_to_metrics($pdo, $metrics);
    $profileMetric = array_values($metrics)[0] ?? null;
    $profileDistanceWeekly = [];
    $profileWorkoutWeekly = [];
    $profileScoreWeekly = [];
    $profileChallengeStart = (string) $settings['challenge_start'];
    $profileChallengeEnd = (string) $settings['challenge_end'];
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
            $workoutValue = max(0, (int) ($profileMetric['workout_target'] ?? 0) - (int) ($weekRow['workout_failures'] ?? 0));
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

    $rangeStart = new DateTimeImmutable($profileChallengeStart);
    $rangeEnd = new DateTimeImmutable($profileChallengeEnd);
    if ($rangeEnd < $rangeStart) {
        $rangeEnd = $rangeStart;
    }

    $profileDailyDetails = [];
    $profileDailyPhotoNutrition = [];
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
            $habitValues[] = [
                'code' => $code,
                'label' => (string) ($habitLabelsByCode[$code] ?? $code),
                'value' => !empty($log['habits'][$code]) && (int) ($log['habits'][$code]['value'] ?? 0) === 1 ? 1 : 0,
            ];
        }

        $stepReason = trim((string) ($log['step_exception_reason'] ?? ''));
        $workoutReason = trim((string) ($log['workout_exception_reason'] ?? ''));
        $combinedReason = $stepReason !== '' ? $stepReason : $workoutReason;
        $approvalsForDate = is_array($approvalsByDate[$date] ?? null) ? (array) $approvalsByDate[$date] : [];

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
            'habits' => $habitValues,
        ];

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

    evaluate_automatic_achievements($pdo, $metrics);
    $profileUser = db_fetch_one($pdo, 'SELECT * FROM users WHERE id = :id', [':id' => (int) $profileUser['id']]) ?? $profileUser;

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
        ],
        'profileDailyDetails' => $profileDailyDetails,
        'profileDailyPhotoNutrition' => $profileDailyPhotoNutrition,
        'profileBaseUrl' => $profileUrl(),
        'personalGoals' => list_goals($pdo, 'user', (int) $profileUser['id']),
        'userAchievements' => list_awarded_achievements($pdo, (int) $profileUser['id'], null),
        'canDeleteAchievements' => $canDeleteProfileAchievements,
        'recentActivity' => fetch_audit_logs($pdo, ['actor_user_id' => (int) $profileUser['id']], 30),
        'habits' => $habitDefinitions,
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

        if ($action === 'create_workout_type') {
            save_workout_type_if_needed($pdo, (string) ($_POST['name'] ?? ''), (int) $currentUser['id']);
            flash_set('success', t('flash.workout_type_updated'));
            redirect('/?page=admin&section=workout_types');
        }

        if ($action === 'delete_workout_type') {
            delete_workout_type($pdo, (int) ($_POST['type_id'] ?? 0), (int) $currentUser['id']);
            flash_set('success', t('flash.workout_type_removed'));
            redirect('/?page=admin&section=workout_types');
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
                $name = trim((string) ($_POST['name'] ?? ''));
                $code = trim((string) ($_POST['code'] ?? ''));
                $scope = (string) ($_POST['scope'] ?? 'user');
                $active = bool_from_form('active') === 1;
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
                        'description' => trim((string) ($_POST['description'] ?? '')),
                        'scope' => $scope,
                        'active' => $active ? 1 : 0,
                        'image_path' => $imagePath,
                        'reward_text' => trim((string) ($_POST['reward_text'] ?? '')),
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
                        trim((string) ($_POST['description'] ?? '')),
                        $scope,
                        (int) $currentUser['id'],
                        $imagePath,
                        trim((string) ($_POST['reward_text'] ?? '')),
                        $code,
                        $active,
                        null
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

                $imagePath = (string) ($existing['image_path'] ?? '');
                if (!empty($_FILES['image']['name'])) {
                    $imagePath = save_uploaded_image($config, $_FILES['image'], 'achievements', 'achievement');
                }

                update_achievement($pdo, $achievementId, [
                    'code' => trim((string) ($_POST['code'] ?? '')),
                    'name' => trim((string) ($_POST['name'] ?? '')),
                    'scope' => (string) ($_POST['scope'] ?? 'user'),
                    'description' => trim((string) ($_POST['description'] ?? '')),
                    'reward_text' => trim((string) ($_POST['reward_text'] ?? '')),
                    'image_path' => $imagePath !== '' ? $imagePath : null,
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

        if ($action === 'resolve_join_request') {
            resolve_team_join_request($pdo, (int) ($_POST['request_id'] ?? 0), (string) ($_POST['decision'] ?? '') === 'approve', (int) $currentUser['id']);
            flash_set('success', t('flash.team_updated'));
            redirect('/?page=admin');
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
        'adminAchievements' => list_achievements_for_admin($pdo),
        'achievementAwards' => list_recent_achievement_awards($pdo, 300),
        'appIconPath' => $appIconPath,
        'appIconVersion' => $appIconVersion,
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
                $goalType = normalize_goal_target_type((string) ($_POST['target_type'] ?? 'custom'));
                $targetValue = ($_POST['target_value'] ?? '') !== '' ? (float) $_POST['target_value'] : null;
                $rewardEnabled = bool_from_form('reward_enabled');
                $rewardTextRaw = trim((string) ($_POST['reward_text'] ?? ''));
                $rewardText = $rewardEnabled && $rewardTextRaw !== '' ? substr($rewardTextRaw, 0, 120) : null;
                $customUnit = trim((string) ($_POST['custom_unit'] ?? ''));
                $unitLabel = $goalType === 'custom'
                    ? ($customUnit !== '' ? substr($customUnit, 0, 24) : null)
                    : goal_target_default_unit($goalType);

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
                $baselineValue = goal_team_metric_value(['target_type' => $goalType], $teamSummaryForGoal);

                create_goal($pdo, [
                    'scope' => 'team',
                    'team_id' => (int) $team['id'],
                    'user_id' => null,
                    'title' => $title,
                    'target_type' => $goalType,
                    'target_value' => $targetValue,
                    'baseline_value' => $baselineValue,
                    'current_value' => 0,
                    'unit_label' => $unitLabel,
                    'reward_text' => $rewardText,
                    'due_date' => ($_POST['due_date'] ?? '') !== '' ? to_date((string) $_POST['due_date']) : null,
                ], (int) $currentUser['id']);

                auto_complete_team_goals_for_team(
                    $pdo,
                    (int) $team['id'],
                    (string) $settingsForGoal['challenge_start'],
                    (string) $settingsForGoal['challenge_end'],
                    (int) $currentUser['id']
                );
                flash_set('success', t('flash.goal_created'));
            }
            redirect('/?page=team');
        }

        if ($action === 'update_goal') {
            $goalType = normalize_goal_target_type((string) ($_POST['target_type'] ?? 'custom'));
            $rewardEnabled = bool_from_form('reward_enabled');
            $rewardTextRaw = trim((string) ($_POST['reward_text'] ?? ''));
            $rewardText = $rewardEnabled && $rewardTextRaw !== '' ? substr($rewardTextRaw, 0, 120) : null;
            $customUnit = trim((string) ($_POST['custom_unit'] ?? ''));
            $unitLabel = $goalType === 'custom'
                ? ($customUnit !== '' ? substr($customUnit, 0, 24) : null)
                : goal_target_default_unit($goalType);
            update_goal($pdo, (int) ($_POST['goal_id'] ?? 0), [
                'title' => trim((string) ($_POST['title'] ?? '')),
                'target_type' => $goalType,
                'target_value' => ($_POST['target_value'] ?? '') !== '' ? (float) $_POST['target_value'] : null,
                'unit_label' => $unitLabel,
                'reward_text' => $rewardText,
                'due_date' => ($_POST['due_date'] ?? '') !== '' ? to_date((string) $_POST['due_date']) : null,
            ], (int) $currentUser['id']);
            flash_set('success', t('flash.goal_updated'));
            redirect('/?page=team');
        }

        if ($action === 'delete_goal') {
            require_team_manager($pdo, $currentUser, (int) $team['id']);
            $goalId = (int) ($_POST['goal_id'] ?? 0);
            $goal = db_fetch_one($pdo, 'SELECT * FROM goals WHERE id = :id', [':id' => $goalId]);
            if ($goal !== null && (string) $goal['scope'] === 'team' && (int) ($goal['team_id'] ?? 0) === (int) $team['id']) {
                delete_goal($pdo, $goalId, (int) $currentUser['id']);
                flash_set('success', t('flash.goal_deleted'));
            } else {
                flash_set('error', t('flash.no_permission'));
            }
            redirect('/?page=team');
        }

        if ($action === 'goal_status') {
            update_goal_status($pdo, (int) ($_POST['goal_id'] ?? 0), (string) ($_POST['status'] ?? 'active'), (int) $currentUser['id']);
            flash_set('success', t('flash.goal_updated'));
            redirect('/?page=team');
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

        if ($action === 'mark_notification_read') {
            $notificationId = (int) ($_POST['notification_id'] ?? 0);
            mark_user_notification_read($pdo, $notificationId, (int) $currentUser['id']);
            $redirectParams = [
                'page' => 'team',
                'team_id' => (int) $team['id'],
            ];
            if (!empty($_POST['redirect_view'])) {
                $redirectParams['view'] = (string) $_POST['redirect_view'];
            }
            redirect('/?' . http_build_query($redirectParams));
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
    $teamNotifications = user_notifications($pdo, (int) $currentUser['id'], 20, false);
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

    foreach ($metricsOrdered as $metric) {
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
            $dailyTotals[$date]['steps'] += max(0, (int) ($seriesPoint['steps'] ?? 0));
            $dailyTotals[$date]['distance'] += max(0.0, (float) ($seriesPoint['km'] ?? 0));
            $dailyTotals[$date]['workouts'] += $workoutsByDate[$date] ?? 0;
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
            'summary_value' => (float) ($teamSummary['workout_success'] ?? 0),
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
    foreach ($teamGoalsRaw as $goal) {
        $type = normalize_goal_target_type((string) ($goal['target_type'] ?? 'custom'));
        $unitLabel = trim((string) ($goal['unit_label'] ?? ''));
        if ($unitLabel === '') {
            $unitLabel = goal_target_default_unit($type);
        }
        $currentMetricValue = goal_team_metric_value($goal, $teamSummaryTotal);
        $progressValue = goal_progress_from_baseline($goal, $currentMetricValue);
        $targetValue = max(0.0, (float) ($goal['target_value'] ?? 0));
        if ((string) ($goal['status'] ?? '') === 'complete' && $targetValue > 0 && $progressValue < $targetValue) {
            $progressValue = $targetValue;
        }
        $baselineValue = is_numeric($goal['baseline_value'] ?? null)
            ? (float) $goal['baseline_value']
            : $currentMetricValue;
        $teamGoals[] = array_merge($goal, [
            'target_type_normalized' => $type,
            'target_type_label' => $goalTypeLabel($type),
            'unit_label_resolved' => $unitLabel,
            'is_lower_better' => goal_target_type_is_lower_better($type),
            'direction_label' => goal_target_type_is_lower_better($type) ? t('goals.lower_better') : t('goals.higher_better'),
            'progress_value' => $progressValue,
            'progress_pct' => $targetValue > 0 ? min(100.0, round(($progressValue / $targetValue) * 100, 1)) : 0.0,
            'progress_display' => $formatGoalValue($progressValue, $type, $unitLabel),
            'target_display' => $formatGoalValue($targetValue, $type, $unitLabel),
            'baseline_display' => $formatGoalValue($baselineValue, $type, $unitLabel),
        ]);
    }

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
        'teamWeekOptions' => $weekOptions,
        'teamSelectedWeekStart' => $selectedWeekStart,
        'teamDailyLabels' => $teamDailyLabels,
        'teamDailySteps' => $teamDailySteps,
        'teamDailyDistance' => $teamDailyDistance,
        'teamDailyWorkouts' => $teamDailyWorkouts,
        'teamWeeklyLabels' => $teamWeeklyLabels,
        'teamWeeklyScore' => $teamWeeklyScore,
        'teamWeeklyStrikes' => $teamWeeklyStrikes,
        'teamWeeklyPenalties' => $teamWeeklyPenalties,
        'teamComparisonRows' => $teamComparisonRows,
        'teamMetricDetail' => $teamMetricDetail,
        'teamSection' => $teamSection,
        'teamMemberDetail' => $teamMemberDetail,
        'teamGoals' => $teamGoals,
        'teamNotifications' => $teamNotifications,
        'teamAchievements' => list_awarded_achievements($pdo, null, (int) $team['id']),
        'canDeleteAchievements' => can_manage_team($pdo, $currentUser, (int) $team['id']),
        'canManageTeam' => can_manage_team($pdo, $currentUser, (int) $team['id']),
        'config' => $config,
    ]);
}

if ($page === 'metric') {
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

    $allowedMetrics = [
        'steps' => t('metric.steps'),
        'distance' => t('metric.distance_km'),
        'workouts' => t('metric.workouts'),
        'money' => t('metric.penalty'),
        'strikes' => t('metric.strikes'),
        'score' => t('metric.score'),
    ];
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
        $workoutSuccess = isset($row['workout_success_week'])
            ? max(0, (int) ($row['workout_success_week'] ?? 0))
            : max(0, $workoutTarget - (int) ($row['workout_failures'] ?? 0));
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
        if (isset($row['workout_success_week'])) {
            return (int) ($row['workout_success_week'] ?? 0);
        }
        if (isset($row['workout_target_week'])) {
            return max(0, (int) ($row['workout_target_week'] ?? 0) - (int) ($row['workout_failures'] ?? 0));
        }

        return (int) ($row['workouts'] ?? 0);
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
            if (!is_admin($currentUser) && $targetUserId !== (int) $currentUser['id']) {
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

        if ($action === 'resend_strike_review_request') {
            $requestId = (int) ($_POST['request_id'] ?? 0);
            $result = resend_strike_review_request(
                $pdo,
                $requestId,
                (string) ($_POST['request_comment'] ?? ''),
                (int) $currentUser['id'],
                is_admin($currentUser)
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
            $allowedWidgets = ['kpis', 'distance_walked', 'approvals', 'steps', 'steps_cumulative', 'distance_cumulative', 'weight', 'comparison', 'ranking', 'meals', 'weekly', 'calories'];
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
            redirect('/?page=dashboard&view=' . rawurlencode((string) ($_POST['dashboard_view'] ?? 'current_week')));
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

    $settlementSummary = weekly_settlement_summary(array_values($metricsByUser), $selectedWeekStart);
    $pendingApprovals = fetch_pending_approvals($pdo, $currentUser, null, 80);
    $distanceByDate = [];
    $distanceLogs = fetch_logs_for_user_between(
        $pdo,
        (int) ($selectedMetric['user']['id'] ?? 0),
        (string) $settings['challenge_start'],
        (string) $settings['challenge_end']
    );
    foreach ($distanceLogs as $distanceLog) {
        $dateKey = (string) ($distanceLog['log_date'] ?? '');
        if ($dateKey === '') {
            continue;
        }
        if (!isset($distanceByDate[$dateKey])) {
            $distanceByDate[$dateKey] = 0.0;
        }
        $distanceByDate[$dateKey] += (float) ($distanceLog['distance_km'] ?? 0);
    }

    $challengeStartObj = new DateTimeImmutable((string) $settings['challenge_start']);
    $challengeConfiguredEnd = new DateTimeImmutable((string) $settings['challenge_end']);
    $todayObj = new DateTimeImmutable('today');
    $challengeEndObj = $challengeConfiguredEnd > $todayObj ? $todayObj : $challengeConfiguredEnd;
    if ($challengeEndObj < $challengeStartObj) {
        $challengeEndObj = $challengeStartObj;
    }
    if ($dashboardView === 'total') {
        $calorieStartDate = $challengeStartObj->format('Y-m-d');
        $calorieEndDate = $challengeEndObj->format('Y-m-d');
    } else {
        $weekStartObj = new DateTimeImmutable($selectedWeekStart);
        $weekEndObj = $weekStartObj->modify('+6 days');
        if ($weekStartObj < $challengeStartObj) {
            $weekStartObj = $challengeStartObj;
        }
        if ($weekEndObj > $challengeEndObj) {
            $weekEndObj = $challengeEndObj;
        }
        if ($weekEndObj < $weekStartObj) {
            $weekEndObj = $weekStartObj;
        }
        $calorieStartDate = $weekStartObj->format('Y-m-d');
        $calorieEndDate = $weekEndObj->format('Y-m-d');
    }
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

    $dashboardMealDate = to_date($_GET['meal_date'] ?? $selectedWeekStart);
    $dashboardMealCalendar = fetch_meal_calendar($pdo, $dashboardMealDate, (int) $selectedMetric['user']['id'], 'week');
    if ($dashboardView !== (string) ($currentUser['dashboard_view'] ?? 'current_week')) {
        db_execute($pdo, 'UPDATE users SET dashboard_view = :view, updated_at = :updated_at WHERE id = :id', [':view' => $dashboardView, ':updated_at' => now_iso(), ':id' => (int) $currentUser['id']]);
        $currentUser['dashboard_view'] = $dashboardView;
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
        'dashboardDistanceByDate' => $distanceByDate,
        'dashboardMealDate' => $dashboardMealDate,
        'dashboardMealCalendar' => $dashboardMealCalendar,
        'dashboardCalorieStats' => $dashboardCalorieStats,
        'dashboardCalorieRangeStart' => $calorieStartDate,
        'dashboardCalorieRangeEnd' => $calorieEndDate,
        'motivationQuote' => random_motivation_quote(),
        'config' => $config,
    ]);
}

http_response_code(404);
echo e(t('flash.not_found'));
