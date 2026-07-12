<?php

declare(strict_types=1);

function db_retry(callable $operation, int $maxAttempts = 6): mixed
{
    $attempt = 0;
    while (true) {
        try {
            return $operation();
        } catch (PDOException $exception) {
            $message = strtolower($exception->getMessage());
            $isLockError = str_contains($message, 'database is locked') || str_contains($message, 'database table is locked');
            if (!$isLockError || $attempt >= $maxAttempts - 1) {
                throw $exception;
            }
            $attempt++;
            usleep(250000 * $attempt);
        }
    }
}

function db_connect(array $config): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dbPath = (string) $config['db_path'];
    $dir = dirname($dbPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_TIMEOUT, 5);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');

    try {
        initialize_database($pdo, $config);
    } catch (Throwable $exception) {
        $pdo = null;
        throw $exception;
    }

    db_current($pdo);

    return $pdo;
}

/**
 * The connection db_connect() already opened, for callers that have no $config
 * at hand (views, in particular, only receive the params they were rendered
 * with). Returns null before the first connect.
 */
function db_current(?PDO $pdo = null): ?PDO
{
    static $current = null;

    if ($pdo instanceof PDO) {
        $current = $pdo;
    }

    return $current;
}

function initialize_database(PDO $pdo, array $config): void
{
    db_retry(static function () use ($pdo, $config): void {
        $pdo->exec('PRAGMA foreign_keys = ON');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            display_name TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT "user",
            step_goal INTEGER NOT NULL DEFAULT 10000,
            step_days_mask TEXT NOT NULL DEFAULT "1111111",
            workout_target INTEGER NOT NULL DEFAULT 3,
            workout_days_mask TEXT NOT NULL DEFAULT "0000000",
            workout_strict INTEGER NOT NULL DEFAULT 0,
            ideal_weight REAL,
            maintenance_calories REAL,
            calorie_burn_goal REAL,
            calorie_consumed_max REAL,
            motivation_quote TEXT DEFAULT "",
            profile_tagline TEXT,
            theme_mode TEXT NOT NULL DEFAULT "auto",
            locale TEXT NOT NULL DEFAULT "en",
            avatar_path TEXT,
            primary_goal_type TEXT NOT NULL DEFAULT "steps",
            primary_goal_value REAL,
            primary_goals_spec TEXT,
            dashboard_view TEXT NOT NULL DEFAULT "current_week",
            dashboard_layout_json TEXT,
            team_layout_json TEXT,
            analytics_layout_json TEXT,
            meal_calendar_view TEXT NOT NULL DEFAULT "week",
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS daily_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            log_date TEXT NOT NULL,
            log_time TEXT,
            steps INTEGER NOT NULL DEFAULT 0,
            workout_done INTEGER NOT NULL DEFAULT 0,
            workout_type_id INTEGER,
            workout_type TEXT,
            junk_food INTEGER NOT NULL DEFAULT 0,
            extra_workout INTEGER NOT NULL DEFAULT 0,
            base_steps INTEGER,
            base_distance_km REAL,
            base_training_calories_burned REAL,
            distance_km REAL,
            training_calories_burned REAL,
            weight REAL,
            notes TEXT,
            step_exception_reason TEXT,
            distance_exception_reason TEXT,
            workout_exception_reason TEXT,
            morning_walk INTEGER NOT NULL DEFAULT 0,
            journaling INTEGER NOT NULL DEFAULT 0,
            evening_chores INTEGER NOT NULL DEFAULT 0,
            reading INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            UNIQUE (user_id, log_date),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (workout_type_id) REFERENCES workout_types(id) ON DELETE SET NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS approval_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            log_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            approval_type TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT "pending",
            request_state TEXT NOT NULL DEFAULT "sent",
            resent_count INTEGER NOT NULL DEFAULT 0,
            detail TEXT,
            requested_by INTEGER NOT NULL,
            approved_by INTEGER,
            decision_note TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            UNIQUE (log_id, approval_type),
            FOREIGN KEY (log_id) REFERENCES daily_logs(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS photo_entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            log_date TEXT NOT NULL,
            category TEXT NOT NULL,
            caption TEXT,
            file_path TEXT NOT NULL,
            calories REAL,
            protein_g REAL,
            carbs_g REAL,
            fat_g REAL,
            fiber_g REAL,
            sugar_g REAL,
            sodium_mg REAL,
            created_at TEXT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS photo_comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            photo_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            comment TEXT NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (photo_id) REFERENCES photo_entries(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS strike_review_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            target_user_id INTEGER NOT NULL,
            week_start TEXT NOT NULL,
            event_date TEXT NOT NULL,
            reason TEXT NOT NULL,
            comment TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT "pending",
            requested_by INTEGER NOT NULL,
            eligible_voters_json TEXT NOT NULL DEFAULT "[]",
            resent_count INTEGER NOT NULL DEFAULT 0,
            resolved_at TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE CASCADE
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS strike_review_votes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            request_id INTEGER NOT NULL,
            voter_user_id INTEGER NOT NULL,
            vote TEXT NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (request_id) REFERENCES strike_review_requests(id) ON DELETE CASCADE,
            FOREIGN KEY (voter_user_id) REFERENCES users(id) ON DELETE CASCADE
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS challenge_settings (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            challenge_name TEXT NOT NULL,
            challenge_start TEXT NOT NULL,
            challenge_end TEXT NOT NULL,
            active INTEGER NOT NULL DEFAULT 1,
            deleted_at TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS challenge_archives (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            challenge_name TEXT NOT NULL,
            challenge_start TEXT NOT NULL,
            challenge_end TEXT NOT NULL,
            archived_at TEXT NOT NULL,
            archived_by INTEGER,
            source_settings_json TEXT,
            created_at TEXT NOT NULL,
            FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS teams (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT "",
            slug TEXT NOT NULL UNIQUE,
            join_mode TEXT NOT NULL DEFAULT "closed",
            visibility TEXT NOT NULL DEFAULT "visible",
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS team_memberships (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            team_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            role TEXT NOT NULL DEFAULT "member",
            active INTEGER NOT NULL DEFAULT 1,
            joined_at TEXT NOT NULL,
            removed_at TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            UNIQUE (team_id, user_id),
            FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS goals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            scope TEXT NOT NULL,
            team_id INTEGER,
            user_id INTEGER,
            title TEXT NOT NULL,
            target_type TEXT NOT NULL,
            target_value REAL,
            baseline_value REAL,
            current_value REAL NOT NULL DEFAULT 0,
            secondary_enabled INTEGER NOT NULL DEFAULT 0,
            secondary_target_type TEXT,
            secondary_target_value REAL,
            secondary_baseline_value REAL,
            secondary_current_value REAL NOT NULL DEFAULT 0,
            secondary_unit_label TEXT,
            unit_label TEXT,
            reward_text TEXT,
            start_date TEXT,
            start_time TEXT,
            due_date TEXT,
            due_time TEXT,
            status TEXT NOT NULL DEFAULT "active",
            completed_at TEXT,
            created_by INTEGER,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS user_notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            kind TEXT NOT NULL,
            title TEXT NOT NULL,
            message TEXT NOT NULL,
            payload_json TEXT,
            unique_key TEXT,
            is_read INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            read_at TEXT,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS achievements (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT "",
            scope TEXT NOT NULL DEFAULT "user",
            trigger_key TEXT,
            image_path TEXT,
            icon_key TEXT,
            reward_text TEXT,
            active INTEGER NOT NULL DEFAULT 1,
            created_by INTEGER,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS achievement_translations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            achievement_id INTEGER NOT NULL,
            locale TEXT NOT NULL,
            name TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT "",
            reward_text TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            UNIQUE (achievement_id, locale),
            FOREIGN KEY (achievement_id) REFERENCES achievements(id) ON DELETE CASCADE
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS achievement_awards (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            achievement_id INTEGER NOT NULL,
            user_id INTEGER,
            team_id INTEGER,
            awarded_by INTEGER,
            awarded_at TEXT NOT NULL,
            note TEXT,
            UNIQUE (achievement_id, user_id, team_id),
            FOREIGN KEY (achievement_id) REFERENCES achievements(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
            FOREIGN KEY (awarded_by) REFERENCES users(id) ON DELETE SET NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS achievement_award_suppressions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            achievement_id INTEGER NOT NULL,
            user_id INTEGER,
            team_id INTEGER,
            suppressed_by INTEGER,
            suppressed_at TEXT NOT NULL,
            reason TEXT,
            FOREIGN KEY (achievement_id) REFERENCES achievements(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
            FOREIGN KEY (suppressed_by) REFERENCES users(id) ON DELETE SET NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS workout_types (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            active INTEGER NOT NULL DEFAULT 1,
            created_by INTEGER,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS daily_log_workouts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            log_id INTEGER NOT NULL,
            workout_type_id INTEGER,
            workout_type TEXT,
            sort_order INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (log_id) REFERENCES daily_logs(id) ON DELETE CASCADE,
            FOREIGN KEY (workout_type_id) REFERENCES workout_types(id) ON DELETE SET NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS workout_type_fields (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            workout_type_id INTEGER NOT NULL,
            label TEXT NOT NULL,
            input_kind TEXT NOT NULL DEFAULT "number",
            data_key TEXT,
            required INTEGER NOT NULL DEFAULT 0,
            active INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_by INTEGER,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (workout_type_id) REFERENCES workout_types(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS daily_log_workout_field_values (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            workout_id INTEGER NOT NULL,
            field_id INTEGER,
            field_label TEXT NOT NULL,
            data_key TEXT,
            value_text TEXT,
            value_number REAL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (workout_id) REFERENCES daily_log_workouts(id) ON DELETE CASCADE,
            FOREIGN KEY (field_id) REFERENCES workout_type_fields(id) ON DELETE SET NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            actor_user_id INTEGER,
            action TEXT NOT NULL,
            entity_type TEXT NOT NULL,
            entity_id TEXT,
            summary TEXT NOT NULL,
            before_json TEXT,
            after_json TEXT,
            created_at TEXT NOT NULL,
            FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS team_join_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            team_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT "pending",
            requested_at TEXT NOT NULL,
            resolved_by INTEGER,
            resolved_at TEXT,
            decision_note TEXT,
            UNIQUE (team_id, user_id, status),
            FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS habit_definitions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            label TEXT NOT NULL,
            active INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_by INTEGER,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS daily_log_habits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            log_id INTEGER NOT NULL,
            habit_id INTEGER NOT NULL,
            value INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            UNIQUE (log_id, habit_id),
            FOREIGN KEY (log_id) REFERENCES daily_logs(id) ON DELETE CASCADE,
            FOREIGN KEY (habit_id) REFERENCES habit_definitions(id) ON DELETE CASCADE
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS app_settings (
            setting_key TEXT PRIMARY KEY,
            setting_value TEXT,
            updated_by INTEGER,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS motivational_quotes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            quote_text TEXT NOT NULL,
            active INTEGER NOT NULL DEFAULT 1,
            created_by INTEGER,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS system_backups (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            file_path TEXT NOT NULL,
            trigger_type TEXT NOT NULL,
            scope TEXT NOT NULL DEFAULT "db_uploads",
            size_bytes INTEGER NOT NULL DEFAULT 0,
            checksum_sha256 TEXT,
            status TEXT NOT NULL DEFAULT "created",
            created_by INTEGER,
            created_at TEXT NOT NULL,
            restored_by INTEGER,
            restored_at TEXT,
            error_message TEXT,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (restored_by) REFERENCES users(id) ON DELETE SET NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS achievement_rules (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            achievement_id INTEGER NOT NULL,
            metric_key TEXT NOT NULL,
            operator TEXT NOT NULL DEFAULT ">=",
            target_value REAL NOT NULL DEFAULT 1,
            window TEXT NOT NULL DEFAULT "total",
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (achievement_id) REFERENCES achievements(id) ON DELETE CASCADE
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL,
            ip_address TEXT NOT NULL,
            attempted_at TEXT NOT NULL
        )'
    );

    ensure_schema_columns($pdo, $config);
    ensure_indexes($pdo);
    migrate_photo_categories($pdo);

    $stmt = $pdo->query('SELECT COUNT(*) AS total FROM users');
    $totalUsers = (int) $stmt->fetch()['total'];

    if ($totalUsers === 0) {
        $seedPasswordHash = password_hash((string) $config['seed_password'], PASSWORD_DEFAULT);
        $now = now_iso();

        $insert = $pdo->prepare(
            'INSERT INTO users (
                username, password_hash, display_name, role,
                step_goal, step_days_mask, workout_target,
                workout_days_mask, workout_strict, ideal_weight,
                motivation_quote, locale, created_at, updated_at
            ) VALUES (
                :username, :password_hash, :display_name, :role,
                :step_goal, :step_days_mask, :workout_target,
                :workout_days_mask, :workout_strict, :ideal_weight,
                :motivation_quote, :locale, :created_at, :updated_at
            )'
        );

        $seedUsers = [
            [
                'username' => 'roberto',
                'display_name' => 'Roberto',
                'role' => 'admin',
                'step_goal' => 13000,
                'step_days_mask' => '1111100',
                'workout_target' => 3,
                'workout_days_mask' => '0000000',
                'workout_strict' => 0,
                'ideal_weight' => null,
                'motivation_quote' => 'Consistency beats intensity. Every day counts.',
            ],
            [
                'username' => 'catalina',
                'display_name' => 'Catalina',
                'role' => 'user',
                'step_goal' => 8000,
                'step_days_mask' => '1111111',
                'workout_target' => 3,
                'workout_days_mask' => '1010100',
                'workout_strict' => 1,
                'ideal_weight' => null,
                'motivation_quote' => 'You are one decision away from a stronger self.',
            ],
        ];

        foreach ($seedUsers as $user) {
            $insert->execute([
                ':username' => $user['username'],
                ':password_hash' => $seedPasswordHash,
                ':display_name' => $user['display_name'],
                ':role' => $user['role'],
                ':step_goal' => $user['step_goal'],
                ':step_days_mask' => $user['step_days_mask'],
                ':workout_target' => $user['workout_target'],
                ':workout_days_mask' => $user['workout_days_mask'],
                ':workout_strict' => $user['workout_strict'],
                ':ideal_weight' => $user['ideal_weight'],
                ':motivation_quote' => $user['motivation_quote'],
                ':locale' => config_default_locale($config),
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        }
    }

        $settings = $pdo->query('SELECT id FROM challenge_settings WHERE id = 1')->fetch();
        if ($settings === false) {
            $insertSettings = $pdo->prepare(
                'INSERT INTO challenge_settings (id, challenge_name, challenge_start, challenge_end, created_at, updated_at)
                 VALUES (1, :name, :start, :end, :created_at, :updated_at)'
            );

            $now = now_iso();
            $insertSettings->execute([
                ':name' => 'Fitness Challenge - Catalina & Roberto',
                ':start' => (string) $config['challenge_start'],
                ':end' => (string) $config['challenge_end'],
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        }

        seed_default_team($pdo);
        seed_default_habits($pdo);
        seed_default_achievements($pdo);
        seed_default_motivational_quotes($pdo);
        seed_workout_types_from_logs($pdo);
        backfill_daily_log_base_metrics($pdo);
        backfill_workout_type_ids($pdo);
        backfill_daily_log_workouts($pdo);
        backfill_daily_log_habits($pdo);
    });
}

function ensure_schema_columns(PDO $pdo, array $config): void
{
    $defaultLocale = config_default_locale($config);
    ensure_column($pdo, 'users', 'locale', "TEXT NOT NULL DEFAULT '" . $defaultLocale . "'");
    ensure_column($pdo, 'users', 'avatar_path', 'TEXT');
    ensure_column($pdo, 'users', 'primary_goal_type', 'TEXT NOT NULL DEFAULT "steps"');
    ensure_column($pdo, 'users', 'primary_goal_value', 'REAL');
    ensure_column($pdo, 'users', 'primary_goals_spec', 'TEXT');
    ensure_column($pdo, 'users', 'profile_tagline', 'TEXT');
    ensure_column($pdo, 'users', 'theme_mode', 'TEXT NOT NULL DEFAULT "auto"');
    ensure_column($pdo, 'users', 'dashboard_view', 'TEXT NOT NULL DEFAULT "current_week"');
    ensure_column($pdo, 'users', 'dashboard_layout_json', 'TEXT');
    ensure_column($pdo, 'users', 'dashboard_widgets_known', 'TEXT');
    ensure_column($pdo, 'users', 'team_layout_json', 'TEXT');
    ensure_column($pdo, 'users', 'analytics_layout_json', 'TEXT');
    ensure_column($pdo, 'users', 'analytics_view', 'TEXT NOT NULL DEFAULT "total"');
    ensure_column($pdo, 'users', 'profile_layout_json', 'TEXT');
    ensure_column($pdo, 'users', 'meal_calendar_view', 'TEXT NOT NULL DEFAULT "week"');
    ensure_column($pdo, 'users', 'maintenance_calories', 'REAL');
    ensure_column($pdo, 'users', 'calorie_burn_goal', 'REAL');
    ensure_column($pdo, 'users', 'calorie_consumed_max', 'REAL');
    ensure_column($pdo, 'users', 'telegram_chat_id', 'TEXT');
    ensure_column($pdo, 'users', 'telegram_link_code', 'TEXT');
    ensure_column($pdo, 'users', 'telegram_reminders_enabled', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'users', 'telegram_motivation_enabled', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'users', 'telegram_reminder_time', "TEXT NOT NULL DEFAULT '20:00'");
    ensure_column($pdo, 'users', 'telegram_quiet_start', "TEXT NOT NULL DEFAULT ''");
    ensure_column($pdo, 'users', 'telegram_quiet_end', "TEXT NOT NULL DEFAULT ''");
    ensure_column($pdo, 'users', 'telegram_weekends_off', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'users', 'telegram_tz', "TEXT NOT NULL DEFAULT ''");
    ensure_column($pdo, 'users', 'telegram_notify_duel', 'INTEGER NOT NULL DEFAULT 1');
    ensure_column($pdo, 'users', 'telegram_notify_streak', 'INTEGER NOT NULL DEFAULT 1');
    ensure_column($pdo, 'users', 'telegram_last_reminded_on', 'TEXT');
    ensure_column($pdo, 'users', 'telegram_last_reminded_at', 'TEXT');
    ensure_column($pdo, 'users', 'telegram_reminder_count', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'users', 'telegram_last_motivation_on', 'TEXT');

    ensure_column($pdo, 'daily_logs', 'extra_workout', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'daily_logs', 'base_steps', 'INTEGER');
    ensure_column($pdo, 'daily_logs', 'base_distance_km', 'REAL');
    ensure_column($pdo, 'daily_logs', 'base_training_calories_burned', 'REAL');
    ensure_column($pdo, 'daily_logs', 'distance_km', 'REAL');
    ensure_column($pdo, 'daily_logs', 'workout_type_id', 'INTEGER');
    ensure_column($pdo, 'daily_logs', 'training_calories_burned', 'REAL');
    ensure_column($pdo, 'daily_logs', 'distance_exception_reason', 'TEXT');
    ensure_column($pdo, 'daily_logs', 'log_time', 'TEXT');

    ensure_column($pdo, 'approval_requests', 'request_state', 'TEXT NOT NULL DEFAULT "sent"');
    ensure_column($pdo, 'approval_requests', 'resent_count', 'INTEGER NOT NULL DEFAULT 0');

    ensure_column($pdo, 'goals', 'baseline_value', 'REAL');
    ensure_column($pdo, 'goals', 'unit_label', 'TEXT');
    ensure_column($pdo, 'goals', 'reward_text', 'TEXT');
    ensure_column($pdo, 'goals', 'completed_at', 'TEXT');
    ensure_column($pdo, 'goals', 'start_date', 'TEXT');
    ensure_column($pdo, 'goals', 'start_time', 'TEXT');
    ensure_column($pdo, 'goals', 'due_time', 'TEXT');
    ensure_column($pdo, 'goals', 'secondary_enabled', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'goals', 'secondary_target_type', 'TEXT');
    ensure_column($pdo, 'goals', 'secondary_target_value', 'REAL');
    ensure_column($pdo, 'goals', 'secondary_baseline_value', 'REAL');
    ensure_column($pdo, 'goals', 'secondary_current_value', 'REAL NOT NULL DEFAULT 0');
    ensure_column($pdo, 'goals', 'secondary_unit_label', 'TEXT');

    ensure_column($pdo, 'photo_entries', 'calories', 'REAL');
    ensure_column($pdo, 'photo_entries', 'protein_g', 'REAL');
    ensure_column($pdo, 'photo_entries', 'carbs_g', 'REAL');
    ensure_column($pdo, 'photo_entries', 'fat_g', 'REAL');
    ensure_column($pdo, 'photo_entries', 'fiber_g', 'REAL');
    ensure_column($pdo, 'photo_entries', 'sugar_g', 'REAL');
    ensure_column($pdo, 'photo_entries', 'sodium_mg', 'REAL');
    ensure_column($pdo, 'photo_entries', 'updated_at', 'TEXT');

    ensure_column($pdo, 'teams', 'join_mode', 'TEXT NOT NULL DEFAULT "closed"');
    ensure_column($pdo, 'teams', 'visibility', 'TEXT NOT NULL DEFAULT "visible"');

    ensure_column($pdo, 'achievements', 'trigger_key', 'TEXT');
    ensure_column($pdo, 'achievements', 'image_path', 'TEXT');
    ensure_column($pdo, 'achievements', 'icon_key', 'TEXT');
    ensure_column($pdo, 'achievements', 'reward_text', 'TEXT');
    ensure_column($pdo, 'achievements', 'active', 'INTEGER NOT NULL DEFAULT 1');
    ensure_column($pdo, 'achievement_translations', 'description', 'TEXT NOT NULL DEFAULT ""');
    ensure_column($pdo, 'achievement_translations', 'reward_text', 'TEXT');
    ensure_column($pdo, 'achievement_translations', 'created_at', 'TEXT');
    ensure_column($pdo, 'achievement_translations', 'updated_at', 'TEXT');
    ensure_column($pdo, 'teams', 'description', 'TEXT NOT NULL DEFAULT ""');

    ensure_column($pdo, 'achievement_rules', 'operator', 'TEXT NOT NULL DEFAULT ">="');
    ensure_column($pdo, 'achievement_rules', 'target_value', 'REAL NOT NULL DEFAULT 1');
    ensure_column($pdo, 'achievement_rules', 'window', 'TEXT NOT NULL DEFAULT "total"');
    ensure_column($pdo, 'achievement_rules', 'active', 'INTEGER NOT NULL DEFAULT 1');
    ensure_column($pdo, 'achievement_rules', 'created_at', 'TEXT');
    ensure_column($pdo, 'achievement_rules', 'updated_at', 'TEXT');

    ensure_column($pdo, 'challenge_settings', 'active', 'INTEGER NOT NULL DEFAULT 1');
    ensure_column($pdo, 'challenge_settings', 'deleted_at', 'TEXT');

    ensure_column($pdo, 'strike_review_requests', 'eligible_voters_json', 'TEXT NOT NULL DEFAULT "[]"');
    ensure_column($pdo, 'strike_review_requests', 'resent_count', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'strike_review_requests', 'resolved_at', 'TEXT');

    ensure_column($pdo, 'system_backups', 'scope', 'TEXT NOT NULL DEFAULT "db_uploads"');
    ensure_column($pdo, 'system_backups', 'checksum_sha256', 'TEXT');
    ensure_column($pdo, 'system_backups', 'status', 'TEXT NOT NULL DEFAULT "created"');
    ensure_column($pdo, 'system_backups', 'restored_by', 'INTEGER');
    ensure_column($pdo, 'system_backups', 'restored_at', 'TEXT');
    ensure_column($pdo, 'system_backups', 'error_message', 'TEXT');

    ensure_column($pdo, 'workout_type_fields', 'input_kind', 'TEXT NOT NULL DEFAULT "number"');
    ensure_column($pdo, 'workout_type_fields', 'data_key', 'TEXT');
    ensure_column($pdo, 'workout_type_fields', 'required', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'workout_type_fields', 'active', 'INTEGER NOT NULL DEFAULT 1');
    ensure_column($pdo, 'workout_type_fields', 'sort_order', 'INTEGER NOT NULL DEFAULT 0');

    ensure_column($pdo, 'daily_log_workout_field_values', 'field_label', 'TEXT NOT NULL DEFAULT ""');
    ensure_column($pdo, 'daily_log_workout_field_values', 'data_key', 'TEXT');
    ensure_column($pdo, 'daily_log_workout_field_values', 'value_text', 'TEXT');
    ensure_column($pdo, 'daily_log_workout_field_values', 'value_number', 'REAL');
}

function ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    $columns = db_fetch_all($pdo, 'PRAGMA table_info(' . $table . ')');
    foreach ($columns as $existing) {
        if (($existing['name'] ?? '') === $column) {
            return;
        }
    }

    $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
}

function ensure_indexes(PDO $pdo): void
{
    deduplicate_achievement_awards($pdo);
    deduplicate_achievement_suppressions($pdo);
    deduplicate_approval_requests($pdo);

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_approval_status ON approval_requests(status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_approval_user ON approval_requests(user_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_approval_status_created ON approval_requests(status, created_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_approval_user_status_created ON approval_requests(user_id, status, created_at DESC)');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_approval_log_type_unique ON approval_requests(log_id, approval_type)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_daily_logs_date ON daily_logs(log_date)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_daily_logs_user_date ON daily_logs(user_id, log_date)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_daily_logs_user_updated ON daily_logs(user_id, updated_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_photo_entries_user_date ON photo_entries(user_id, log_date)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_photo_entries_user_log_created ON photo_entries(user_id, log_date, created_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_photo_entries_created ON photo_entries(created_at DESC, id DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_photo_entries_user_created ON photo_entries(user_id, created_at DESC, id DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_photo_comments_photo_created ON photo_comments(photo_id, created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_photo_comments_user ON photo_comments(user_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_team_memberships_team ON team_memberships(team_id, active)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_team_memberships_user ON team_memberships(user_id, active)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_goals_scope ON goals(scope, status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_goal_team ON goals(team_id, status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_goal_user ON goals(user_id, status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_goals_scope_team_status ON goals(scope, team_id, status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_goals_scope_user_status ON goals(scope, user_id, status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_achievement_awards_user ON achievement_awards(user_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_achievement_awards_team ON achievement_awards(team_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_achievement_awards_user_awarded ON achievement_awards(user_id, awarded_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_achievement_awards_team_awarded ON achievement_awards(team_id, awarded_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_achievement_translations_locale ON achievement_translations(locale)');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_achievement_translations_unique ON achievement_translations(achievement_id, locale)');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_achievement_awards_user_unique ON achievement_awards(achievement_id, user_id) WHERE user_id IS NOT NULL');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_achievement_awards_team_unique ON achievement_awards(achievement_id, team_id) WHERE team_id IS NOT NULL');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_achievement_suppressions_user ON achievement_award_suppressions(user_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_achievement_suppressions_team ON achievement_award_suppressions(team_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_achievement_suppressions_achievement ON achievement_award_suppressions(achievement_id)');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_achievement_suppressions_user_unique ON achievement_award_suppressions(achievement_id, user_id) WHERE user_id IS NOT NULL');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_achievement_suppressions_team_unique ON achievement_award_suppressions(achievement_id, team_id) WHERE team_id IS NOT NULL');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_logs(created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_audit_entity ON audit_logs(entity_type, entity_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_join_requests_team ON team_join_requests(team_id, status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_daily_log_habits_log ON daily_log_habits(log_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_daily_log_workouts_log ON daily_log_workouts(log_id, sort_order)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_workout_type_fields_type ON workout_type_fields(workout_type_id, active, sort_order)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_workout_field_values_workout ON daily_log_workout_field_values(workout_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_achievement_rules_achievement ON achievement_rules(achievement_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_login_attempts_user_ip ON login_attempts(username, ip_address, attempted_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_motivational_quotes_active ON motivational_quotes(active, created_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_notifications_user_created ON user_notifications(user_id, created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_notifications_user_read ON user_notifications(user_id, is_read)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_notifications_user_read_created ON user_notifications(user_id, is_read, created_at DESC)');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_notifications_user_unique_key ON user_notifications(user_id, unique_key) WHERE unique_key IS NOT NULL');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_system_backups_created ON system_backups(created_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_system_backups_status ON system_backups(status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_challenge_archives_archived_at ON challenge_archives(archived_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_strike_review_requests_target ON strike_review_requests(target_user_id, week_start, event_date)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_strike_review_requests_target_status ON strike_review_requests(target_user_id, status, week_start, event_date)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_strike_review_requests_status ON strike_review_requests(status)');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_strike_review_requests_event_unique ON strike_review_requests(target_user_id, week_start, event_date, reason)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_strike_review_votes_request ON strike_review_votes(request_id)');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_strike_review_votes_unique ON strike_review_votes(request_id, voter_user_id)');
}

function migrate_photo_categories(PDO $pdo): void
{
    db_execute($pdo, 'UPDATE photo_entries SET category = "lunch" WHERE category = "meal"');
    db_execute($pdo, 'UPDATE photo_entries SET category = "other" WHERE category = "workout"');
}

function deduplicate_achievement_awards(PDO $pdo): void
{
    $pdo->exec(
        'DELETE FROM achievement_awards
         WHERE id NOT IN (
             SELECT MIN(id)
             FROM achievement_awards
             GROUP BY achievement_id, COALESCE(user_id, 0), COALESCE(team_id, 0)
         )'
    );
}

function deduplicate_approval_requests(PDO $pdo): void
{
    $pdo->exec(
        'DELETE FROM approval_requests
         WHERE id NOT IN (
             SELECT MIN(id)
             FROM approval_requests
             GROUP BY log_id, approval_type
         )'
    );
}

function deduplicate_achievement_suppressions(PDO $pdo): void
{
    $pdo->exec(
        'DELETE FROM achievement_award_suppressions
         WHERE id NOT IN (
             SELECT MIN(id)
             FROM achievement_award_suppressions
             GROUP BY achievement_id, COALESCE(user_id, 0), COALESCE(team_id, 0)
         )'
    );
}

function seed_default_team(PDO $pdo): void
{
    $now = now_iso();
    $team = db_fetch_one($pdo, 'SELECT * FROM teams WHERE slug = :slug', [':slug' => 'main']);

    if ($team === null) {
        db_execute(
            $pdo,
            'INSERT INTO teams (name, description, slug, active, created_at, updated_at)
             VALUES (:name, :description, :slug, 1, :created_at, :updated_at)',
            [
                ':name' => 'Fitness Challenge Team',
                ':description' => 'Shared stats, members, goals and achievements for the challenge.',
                ':slug' => 'main',
                ':created_at' => $now,
                ':updated_at' => $now,
            ]
        );
        $team = db_fetch_one($pdo, 'SELECT * FROM teams WHERE slug = :slug', [':slug' => 'main']);
    }

    if ($team === null) {
        return;
    }

    $users = db_fetch_all($pdo, 'SELECT id, role FROM users WHERE active = 1');
    foreach ($users as $user) {
        $existing = db_fetch_one(
            $pdo,
            'SELECT id FROM team_memberships WHERE team_id = :team_id AND user_id = :user_id',
            [':team_id' => (int) $team['id'], ':user_id' => (int) $user['id']]
        );
        if ($existing !== null) {
            continue;
        }

        db_execute(
            $pdo,
            'INSERT INTO team_memberships (team_id, user_id, role, active, joined_at, created_at, updated_at)
             VALUES (:team_id, :user_id, :role, 1, :joined_at, :created_at, :updated_at)',
            [
                ':team_id' => (int) $team['id'],
                ':user_id' => (int) $user['id'],
                ':role' => $user['role'] === 'admin' ? 'owner' : 'member',
                ':joined_at' => $now,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]
        );
    }
}

function seed_default_achievements(PDO $pdo): void
{
    $now = now_iso();
    $defaultIcons = [
        'first_log' => 'calendar-check',
        'first_photo' => 'camera',
        'three_workouts_week' => 'dumbbell',
        'perfect_week' => 'trophy',
        'step_streak' => 'footprints',
        'no_strike_week' => 'shield-check',
        'seven_day_step_streak' => 'footprints',
        'ten_workouts_total' => 'dumbbell',
        'distance_50k_total' => 'target',
        'distance_100k_total' => 'flag',
        'early_logger' => 'calendar-check',
        'habit_reader_streak' => 'sparkles',
        'weight_logged' => 'target',
        'calorie_tracker' => 'flame',
        'five_logs_total' => 'calendar-check',
        'fourteen_logs_total' => 'calendar-check',
        'thirty_logs_total' => 'calendar-check',
        'steps_100k_total' => 'footprints',
        'steps_250k_total' => 'footprints',
        'steps_500k_total' => 'footprints',
        'steps_1m_total' => 'footprints',
        'distance_150k_total' => 'target',
        'distance_250k_total' => 'flag',
        'distance_500k_total' => 'flag',
        'distance_5k_day' => 'target',
        'workouts_25_total' => 'dumbbell',
        'workouts_50_total' => 'dumbbell',
        'workouts_100_total' => 'dumbbell',
        'workout_variety_3' => 'sparkles',
        'three_photo_days' => 'camera',
        'seven_photos_total' => 'camera',
        'twenty_photos_total' => 'camera',
        'calorie_7_days' => 'flame',
        'calorie_14_days' => 'flame',
        'weight_5_logs' => 'target',
        'weight_10_logs' => 'target',
        'no_junk_7_days' => 'shield-check',
        'no_junk_14_days' => 'shield-check',
        'clean_two_weeks' => 'shield-check',
        'perfect_two_weeks' => 'trophy',
        'top_score_90' => 'medal',
        'chores_5_total' => 'shield-check',
        'reading_10_total' => 'sparkles',
        'reading_25_total' => 'sparkles',
        'morning_logs_5' => 'calendar-check',
        'consistent_week_logger' => 'calendar-check',
        'team_active' => 'users',
        'team_first_challenge' => 'flag',
        'team_challenge_complete' => 'trophy',
        'team_100k_steps_week' => 'footprints',
        'team_250km_total' => 'target',
        'team_clean_week' => 'shield-check',
        'team_training_mix' => 'dumbbell',
        'team_500k_steps_total' => 'footprints',
        'team_1m_steps_total' => 'footprints',
        'team_500km_total' => 'target',
        'team_1000km_total' => 'flag',
        'team_10_workouts_total' => 'dumbbell',
        'team_25_workouts_total' => 'dumbbell',
        'team_50_workouts_total' => 'dumbbell',
        'team_training_variety_4' => 'sparkles',
        'team_3_challenges_created' => 'flag',
        'team_5_challenges_created' => 'flag',
        'team_3_challenges_completed' => 'trophy',
        'team_no_penalty_two_weeks' => 'shield-check',
        'team_everyone_logged_week' => 'users',
        'team_everyone_workout_week' => 'users',
        'team_photo_wall_20' => 'camera',
        'team_1000_calories_burned' => 'flame',
    ];
    $achievements = [
        ['first_log', 'user', 'first_log', [
            'en' => ['First Log', 'Saved the first daily log.', ''],
            'es' => ['Primer registro', 'Guardaste el primer registro diario.', ''],
            'it' => ['Primo log', 'Hai salvato il primo log giornaliero.', ''],
        ]],
        ['first_photo', 'user', 'first_photo', [
            'en' => ['Proof Shot', 'Uploaded the first proof photo.', ''],
            'es' => ['Primera prueba', 'Subiste la primera foto de prueba.', ''],
            'it' => ['Prima prova', 'Hai caricato la prima foto di prova.', ''],
        ]],
        ['three_workouts_week', 'user', 'three_workouts_week', [
            'en' => ['Triple Training', 'Completed 3 workouts in one week.', ''],
            'es' => ['Triple entreno', 'Completaste 3 entrenos en una semana.', ''],
            'it' => ['Triplo allenamento', 'Hai completato 3 workout in una settimana.', ''],
        ]],
        ['perfect_week', 'user', 'perfect_week', [
            'en' => ['Perfect Week', 'Completed a week with no failures.', ''],
            'es' => ['Semana perfecta', 'Completaste una semana sin fallos.', ''],
            'it' => ['Settimana perfetta', 'Hai completato una settimana senza errori.', ''],
        ]],
        ['step_streak', 'user', 'step_streak', [
            'en' => ['Step Streak', 'Hit step goal for 5 tracked days.', ''],
            'es' => ['Racha de pasos', 'Cumpliste el objetivo de pasos durante 5 dias registrados.', ''],
            'it' => ['Serie di passi', 'Hai raggiunto l obiettivo passi per 5 giorni registrati.', ''],
        ]],
        ['no_strike_week', 'user', 'no_strike_week', [
            'en' => ['Clean Sheet', 'Closed a week with no penalty.', ''],
            'es' => ['Marcador limpio', 'Cerraste una semana sin penalizacion.', ''],
            'it' => ['Settimana pulita', 'Hai chiuso una settimana senza penalita.', ''],
        ]],
        ['seven_day_step_streak', 'user', 'seven_day_step_streak', [
            'en' => ['Seven-Day Step Streak', 'Hit the step goal for 7 tracked days.', ''],
            'es' => ['Racha de 7 dias', 'Cumpliste el objetivo de pasos durante 7 dias registrados.', ''],
            'it' => ['Serie di 7 giorni', 'Hai raggiunto l obiettivo passi per 7 giorni registrati.', ''],
        ]],
        ['ten_workouts_total', 'user', 'ten_workouts_total', [
            'en' => ['Ten Workout Club', 'Completed 10 workouts in total.', ''],
            'es' => ['Club de 10 entrenos', 'Completaste 10 entrenos en total.', ''],
            'it' => ['Club dei 10 workout', 'Hai completato 10 workout totali.', ''],
        ]],
        ['distance_50k_total', 'user', 'distance_50k_total', [
            'en' => ['50K Distance', 'Logged 50 km in total.', ''],
            'es' => ['50K de distancia', 'Registraste 50 km en total.', ''],
            'it' => ['50K di distanza', 'Hai registrato 50 km totali.', ''],
        ]],
        ['distance_100k_total', 'user', 'distance_100k_total', [
            'en' => ['100K Distance', 'Logged 100 km in total.', ''],
            'es' => ['100K de distancia', 'Registraste 100 km en total.', ''],
            'it' => ['100K di distanza', 'Hai registrato 100 km totali.', ''],
        ]],
        ['early_logger', 'user', 'early_logger', [
            'en' => ['Early Logger', 'Saved a daily log before 09:00.', ''],
            'es' => ['Registro temprano', 'Guardaste un registro diario antes de las 09:00.', ''],
            'it' => ['Log mattutino', 'Hai salvato un log giornaliero prima delle 09:00.', ''],
        ]],
        ['habit_reader_streak', 'user', 'habit_reader_streak', [
            'en' => ['Reader Rhythm', 'Completed the reading habit 5 times.', ''],
            'es' => ['Ritmo lector', 'Completaste el habito de lectura 5 veces.', ''],
            'it' => ['Ritmo lettura', 'Hai completato l abitudine lettura 5 volte.', ''],
        ]],
        ['weight_logged', 'user', 'weight_logged', [
            'en' => ['Weight Check-In', 'Logged body weight for the first time.', ''],
            'es' => ['Control de peso', 'Registraste tu peso por primera vez.', ''],
            'it' => ['Controllo peso', 'Hai registrato il peso per la prima volta.', ''],
        ]],
        ['calorie_tracker', 'user', 'calorie_tracker', [
            'en' => ['Calorie Tracker', 'Logged calories burned or consumed.', ''],
            'es' => ['Seguimiento de calorias', 'Registraste calorias quemadas o consumidas.', ''],
            'it' => ['Tracker calorie', 'Hai registrato calorie bruciate o assunte.', ''],
        ]],
        ['five_logs_total', 'user', 'five_logs_total', [
            'en' => ['Five-Day Foundation', 'Saved 5 daily logs.', ''],
            'es' => ['Base de 5 dias', 'Guardaste 5 registros diarios.', ''],
            'it' => ['Base di 5 giorni', 'Hai salvato 5 log giornalieri.', ''],
        ]],
        ['fourteen_logs_total', 'user', 'fourteen_logs_total', [
            'en' => ['Two-Week Logger', 'Saved 14 daily logs.', ''],
            'es' => ['Dos semanas registradas', 'Guardaste 14 registros diarios.', ''],
            'it' => ['Due settimane registrate', 'Hai salvato 14 log giornalieri.', ''],
        ]],
        ['thirty_logs_total', 'user', 'thirty_logs_total', [
            'en' => ['Thirty Log Club', 'Saved 30 daily logs.', ''],
            'es' => ['Club de 30 registros', 'Guardaste 30 registros diarios.', ''],
            'it' => ['Club dei 30 log', 'Hai salvato 30 log giornalieri.', ''],
        ]],
        ['steps_100k_total', 'user', 'steps_100k_total', [
            'en' => ['100K Steps', 'Logged 100,000 total steps.', ''],
            'es' => ['100K pasos', 'Registraste 100.000 pasos totales.', ''],
            'it' => ['100K passi', 'Hai registrato 100.000 passi totali.', ''],
        ]],
        ['steps_250k_total', 'user', 'steps_250k_total', [
            'en' => ['250K Steps', 'Logged 250,000 total steps.', ''],
            'es' => ['250K pasos', 'Registraste 250.000 pasos totales.', ''],
            'it' => ['250K passi', 'Hai registrato 250.000 passi totali.', ''],
        ]],
        ['steps_500k_total', 'user', 'steps_500k_total', [
            'en' => ['500K Steps', 'Logged 500,000 total steps.', ''],
            'es' => ['500K pasos', 'Registraste 500.000 pasos totales.', ''],
            'it' => ['500K passi', 'Hai registrato 500.000 passi totali.', ''],
        ]],
        ['steps_1m_total', 'user', 'steps_1m_total', [
            'en' => ['Million Steps', 'Logged 1,000,000 total steps.', ''],
            'es' => ['Millon de pasos', 'Registraste 1.000.000 de pasos totales.', ''],
            'it' => ['Milione di passi', 'Hai registrato 1.000.000 passi totali.', ''],
        ]],
        ['distance_150k_total', 'user', 'distance_150k_total', [
            'en' => ['150 km Distance', 'Logged 150 km in total.', ''],
            'es' => ['150 km de distancia', 'Registraste 150 km en total.', ''],
            'it' => ['150 km di distanza', 'Hai registrato 150 km totali.', ''],
        ]],
        ['distance_250k_total', 'user', 'distance_250k_total', [
            'en' => ['250 km Distance', 'Logged 250 km in total.', ''],
            'es' => ['250 km de distancia', 'Registraste 250 km en total.', ''],
            'it' => ['250 km di distanza', 'Hai registrato 250 km totali.', ''],
        ]],
        ['distance_500k_total', 'user', 'distance_500k_total', [
            'en' => ['500 km Distance', 'Logged 500 km in total.', ''],
            'es' => ['500 km de distancia', 'Registraste 500 km en total.', ''],
            'it' => ['500 km di distanza', 'Hai registrato 500 km totali.', ''],
        ]],
        ['distance_5k_day', 'user', 'distance_5k_day', [
            'en' => ['5K Day', 'Logged 5 km in a single day.', ''],
            'es' => ['Dia 5K', 'Registraste 5 km en un solo dia.', ''],
            'it' => ['Giorno 5K', 'Hai registrato 5 km in un solo giorno.', ''],
        ]],
        ['workouts_25_total', 'user', 'workouts_25_total', [
            'en' => ['25 Workout Club', 'Completed 25 workouts in total.', ''],
            'es' => ['Club de 25 entrenos', 'Completaste 25 entrenos en total.', ''],
            'it' => ['Club dei 25 workout', 'Hai completato 25 workout totali.', ''],
        ]],
        ['workouts_50_total', 'user', 'workouts_50_total', [
            'en' => ['50 Workout Club', 'Completed 50 workouts in total.', ''],
            'es' => ['Club de 50 entrenos', 'Completaste 50 entrenos en total.', ''],
            'it' => ['Club dei 50 workout', 'Hai completato 50 workout totali.', ''],
        ]],
        ['workouts_100_total', 'user', 'workouts_100_total', [
            'en' => ['100 Workout Club', 'Completed 100 workouts in total.', ''],
            'es' => ['Club de 100 entrenos', 'Completaste 100 entrenos en total.', ''],
            'it' => ['Club dei 100 workout', 'Hai completato 100 workout totali.', ''],
        ]],
        ['workout_variety_3', 'user', 'workout_variety_3', [
            'en' => ['Training Variety', 'Logged 3 different workout types.', ''],
            'es' => ['Variedad de entreno', 'Registraste 3 tipos de entreno distintos.', ''],
            'it' => ['Varieta allenamento', 'Hai registrato 3 tipi di workout diversi.', ''],
        ]],
        ['three_photo_days', 'user', 'three_photo_days', [
            'en' => ['Three Proof Days', 'Uploaded proof photos on 3 different days.', ''],
            'es' => ['Tres dias con prueba', 'Subiste fotos de prueba en 3 dias distintos.', ''],
            'it' => ['Tre giorni prova', 'Hai caricato foto prova in 3 giorni diversi.', ''],
        ]],
        ['seven_photos_total', 'user', 'seven_photos_total', [
            'en' => ['Seven Proofs', 'Uploaded 7 proof photos.', ''],
            'es' => ['Siete pruebas', 'Subiste 7 fotos de prueba.', ''],
            'it' => ['Sette prove', 'Hai caricato 7 foto prova.', ''],
        ]],
        ['twenty_photos_total', 'user', 'twenty_photos_total', [
            'en' => ['Twenty Proofs', 'Uploaded 20 proof photos.', ''],
            'es' => ['Veinte pruebas', 'Subiste 20 fotos de prueba.', ''],
            'it' => ['Venti prove', 'Hai caricato 20 foto prova.', ''],
        ]],
        ['calorie_7_days', 'user', 'calorie_7_days', [
            'en' => ['Calorie Week', 'Tracked calories on 7 different days.', ''],
            'es' => ['Semana de calorias', 'Registraste calorias en 7 dias distintos.', ''],
            'it' => ['Settimana calorie', 'Hai tracciato calorie in 7 giorni diversi.', ''],
        ]],
        ['calorie_14_days', 'user', 'calorie_14_days', [
            'en' => ['Calorie Fortnight', 'Tracked calories on 14 different days.', ''],
            'es' => ['Quincena de calorias', 'Registraste calorias en 14 dias distintos.', ''],
            'it' => ['Due settimane calorie', 'Hai tracciato calorie in 14 giorni diversi.', ''],
        ]],
        ['weight_5_logs', 'user', 'weight_5_logs', [
            'en' => ['Weight Trend', 'Logged body weight 5 times.', ''],
            'es' => ['Tendencia de peso', 'Registraste tu peso 5 veces.', ''],
            'it' => ['Trend peso', 'Hai registrato il peso 5 volte.', ''],
        ]],
        ['weight_10_logs', 'user', 'weight_10_logs', [
            'en' => ['Weight Routine', 'Logged body weight 10 times.', ''],
            'es' => ['Rutina de peso', 'Registraste tu peso 10 veces.', ''],
            'it' => ['Routine peso', 'Hai registrato il peso 10 volte.', ''],
        ]],
        ['no_junk_7_days', 'user', 'no_junk_7_days', [
            'en' => ['Clean Choices', 'Logged 7 days without junk food.', ''],
            'es' => ['Buenas elecciones', 'Registraste 7 dias sin comida basura.', ''],
            'it' => ['Scelte pulite', 'Hai registrato 7 giorni senza junk food.', ''],
        ]],
        ['no_junk_14_days', 'user', 'no_junk_14_days', [
            'en' => ['Clean Choices Plus', 'Logged 14 days without junk food.', ''],
            'es' => ['Buenas elecciones plus', 'Registraste 14 dias sin comida basura.', ''],
            'it' => ['Scelte pulite plus', 'Hai registrato 14 giorni senza junk food.', ''],
        ]],
        ['clean_two_weeks', 'user', 'clean_two_weeks', [
            'en' => ['Two Clean Weeks', 'Closed 2 weeks with no penalty.', ''],
            'es' => ['Dos semanas limpias', 'Cerraste 2 semanas sin penalizacion.', ''],
            'it' => ['Due settimane pulite', 'Hai chiuso 2 settimane senza penalita.', ''],
        ]],
        ['perfect_two_weeks', 'user', 'perfect_two_weeks', [
            'en' => ['Double Perfect Week', 'Completed 2 weeks with no failures.', ''],
            'es' => ['Doble semana perfecta', 'Completaste 2 semanas sin fallos.', ''],
            'it' => ['Doppia settimana perfetta', 'Hai completato 2 settimane senza errori.', ''],
        ]],
        ['top_score_90', 'user', 'top_score_90', [
            'en' => ['90+ Score', 'Reached a score of 90 or higher.', ''],
            'es' => ['Score 90+', 'Alcanzaste un score de 90 o mas.', ''],
            'it' => ['Score 90+', 'Hai raggiunto uno score di 90 o piu.', ''],
        ]],
        ['chores_5_total', 'user', 'chores_5_total', [
            'en' => ['Chore Rhythm', 'Completed the chores habit 5 times.', ''],
            'es' => ['Ritmo de tareas', 'Completaste el habito de tareas 5 veces.', ''],
            'it' => ['Ritmo faccende', 'Hai completato l abitudine faccende 5 volte.', ''],
        ]],
        ['reading_10_total', 'user', 'reading_10_total', [
            'en' => ['Reading Routine', 'Completed the reading habit 10 times.', ''],
            'es' => ['Rutina lectora', 'Completaste el habito de lectura 10 veces.', ''],
            'it' => ['Routine lettura', 'Hai completato l abitudine lettura 10 volte.', ''],
        ]],
        ['reading_25_total', 'user', 'reading_25_total', [
            'en' => ['Reading Momentum', 'Completed the reading habit 25 times.', ''],
            'es' => ['Impulso lector', 'Completaste el habito de lectura 25 veces.', ''],
            'it' => ['Slancio lettura', 'Hai completato l abitudine lettura 25 volte.', ''],
        ]],
        ['morning_logs_5', 'user', 'morning_logs_5', [
            'en' => ['Morning Logger', 'Saved 5 daily logs before 09:00.', ''],
            'es' => ['Registro matinal', 'Guardaste 5 registros diarios antes de las 09:00.', ''],
            'it' => ['Logger mattutino', 'Hai salvato 5 log giornalieri prima delle 09:00.', ''],
        ]],
        ['consistent_week_logger', 'user', 'consistent_week_logger', [
            'en' => ['Seven Logs in a Week', 'Logged every day in one week.', ''],
            'es' => ['Siete registros en una semana', 'Registraste todos los dias de una semana.', ''],
            'it' => ['Sette log in una settimana', 'Hai registrato ogni giorno in una settimana.', ''],
        ]],
        ['team_active', 'team', 'team_active', [
            'en' => ['Team Pulse', 'The team has active members logging progress.', ''],
            'es' => ['Pulso del equipo', 'El equipo tiene miembros activos registrando progreso.', ''],
            'it' => ['Battito del team', 'Il team ha membri attivi che registrano progressi.', ''],
        ]],
        ['team_first_challenge', 'team', 'team_first_challenge', [
            'en' => ['First Team Challenge', 'The team created its first challenge.', ''],
            'es' => ['Primer reto de equipo', 'El equipo creo su primer reto.', ''],
            'it' => ['Prima sfida team', 'Il team ha creato la prima sfida.', ''],
        ]],
        ['team_challenge_complete', 'team', 'team_challenge_complete', [
            'en' => ['Challenge Finishers', 'The team completed a challenge.', ''],
            'es' => ['Reto completado', 'El equipo completo un reto.', ''],
            'it' => ['Sfida completata', 'Il team ha completato una sfida.', ''],
        ]],
        ['team_100k_steps_week', 'team', 'team_100k_steps_week', [
            'en' => ['100K Step Week', 'The team logged 100,000 steps in a week.', ''],
            'es' => ['Semana de 100K pasos', 'El equipo registro 100.000 pasos en una semana.', ''],
            'it' => ['Settimana da 100K passi', 'Il team ha registrato 100.000 passi in una settimana.', ''],
        ]],
        ['team_250km_total', 'team', 'team_250km_total', [
            'en' => ['250 km Team', 'The team logged 250 km in total.', ''],
            'es' => ['Equipo 250 km', 'El equipo registro 250 km en total.', ''],
            'it' => ['Team 250 km', 'Il team ha registrato 250 km totali.', ''],
        ]],
        ['team_clean_week', 'team', 'team_clean_week', [
            'en' => ['Clean Team Week', 'The team closed a week with no penalty.', ''],
            'es' => ['Semana limpia de equipo', 'El equipo cerro una semana sin penalizacion.', ''],
            'it' => ['Settimana pulita team', 'Il team ha chiuso una settimana senza penalita.', ''],
        ]],
        ['team_training_mix', 'team', 'team_training_mix', [
            'en' => ['Training Mix', 'The team completed 5 workouts in total.', ''],
            'es' => ['Mezcla de entrenos', 'El equipo completo 5 entrenos en total.', ''],
            'it' => ['Mix allenamenti', 'Il team ha completato 5 workout totali.', ''],
        ]],
        ['team_500k_steps_total', 'team', 'team_500k_steps_total', [
            'en' => ['500K Team Steps', 'The team logged 500,000 total steps.', ''],
            'es' => ['500K pasos de equipo', 'El equipo registro 500.000 pasos totales.', ''],
            'it' => ['500K passi team', 'Il team ha registrato 500.000 passi totali.', ''],
        ]],
        ['team_1m_steps_total', 'team', 'team_1m_steps_total', [
            'en' => ['Million Step Team', 'The team logged 1,000,000 total steps.', ''],
            'es' => ['Equipo millon de pasos', 'El equipo registro 1.000.000 de pasos totales.', ''],
            'it' => ['Team milione di passi', 'Il team ha registrato 1.000.000 passi totali.', ''],
        ]],
        ['team_500km_total', 'team', 'team_500km_total', [
            'en' => ['500 km Team', 'The team logged 500 km in total.', ''],
            'es' => ['Equipo 500 km', 'El equipo registro 500 km en total.', ''],
            'it' => ['Team 500 km', 'Il team ha registrato 500 km totali.', ''],
        ]],
        ['team_1000km_total', 'team', 'team_1000km_total', [
            'en' => ['1000 km Team', 'The team logged 1,000 km in total.', ''],
            'es' => ['Equipo 1000 km', 'El equipo registro 1.000 km en total.', ''],
            'it' => ['Team 1000 km', 'Il team ha registrato 1.000 km totali.', ''],
        ]],
        ['team_10_workouts_total', 'team', 'team_10_workouts_total', [
            'en' => ['10 Team Workouts', 'The team completed 10 workouts in total.', ''],
            'es' => ['10 entrenos de equipo', 'El equipo completo 10 entrenos en total.', ''],
            'it' => ['10 workout team', 'Il team ha completato 10 workout totali.', ''],
        ]],
        ['team_25_workouts_total', 'team', 'team_25_workouts_total', [
            'en' => ['25 Team Workouts', 'The team completed 25 workouts in total.', ''],
            'es' => ['25 entrenos de equipo', 'El equipo completo 25 entrenos en total.', ''],
            'it' => ['25 workout team', 'Il team ha completato 25 workout totali.', ''],
        ]],
        ['team_50_workouts_total', 'team', 'team_50_workouts_total', [
            'en' => ['50 Team Workouts', 'The team completed 50 workouts in total.', ''],
            'es' => ['50 entrenos de equipo', 'El equipo completo 50 entrenos en total.', ''],
            'it' => ['50 workout team', 'Il team ha completato 50 workout totali.', ''],
        ]],
        ['team_training_variety_4', 'team', 'team_training_variety_4', [
            'en' => ['Team Training Variety', 'The team logged 4 different workout types.', ''],
            'es' => ['Variedad de entreno de equipo', 'El equipo registro 4 tipos de entreno distintos.', ''],
            'it' => ['Varieta allenamento team', 'Il team ha registrato 4 tipi di workout diversi.', ''],
        ]],
        ['team_3_challenges_created', 'team', 'team_3_challenges_created', [
            'en' => ['Challenge Builders', 'The team created 3 challenges.', ''],
            'es' => ['Creadores de retos', 'El equipo creo 3 retos.', ''],
            'it' => ['Costruttori di sfide', 'Il team ha creato 3 sfide.', ''],
        ]],
        ['team_5_challenges_created', 'team', 'team_5_challenges_created', [
            'en' => ['Challenge Architects', 'The team created 5 challenges.', ''],
            'es' => ['Arquitectos de retos', 'El equipo creo 5 retos.', ''],
            'it' => ['Architetti di sfide', 'Il team ha creato 5 sfide.', ''],
        ]],
        ['team_3_challenges_completed', 'team', 'team_3_challenges_completed', [
            'en' => ['Three Challenge Wins', 'The team completed 3 challenges.', ''],
            'es' => ['Tres retos ganados', 'El equipo completo 3 retos.', ''],
            'it' => ['Tre sfide vinte', 'Il team ha completato 3 sfide.', ''],
        ]],
        ['team_no_penalty_two_weeks', 'team', 'team_no_penalty_two_weeks', [
            'en' => ['Two Clean Team Weeks', 'The team closed 2 weeks with no penalty.', ''],
            'es' => ['Dos semanas limpias de equipo', 'El equipo cerro 2 semanas sin penalizacion.', ''],
            'it' => ['Due settimane pulite team', 'Il team ha chiuso 2 settimane senza penalita.', ''],
        ]],
        ['team_everyone_logged_week', 'team', 'team_everyone_logged_week', [
            'en' => ['Everyone Logged', 'Every active member logged in the same week.', ''],
            'es' => ['Todos registraron', 'Todos los miembros activos registraron en la misma semana.', ''],
            'it' => ['Tutti hanno registrato', 'Ogni membro attivo ha registrato nella stessa settimana.', ''],
        ]],
        ['team_everyone_workout_week', 'team', 'team_everyone_workout_week', [
            'en' => ['Everyone Trained', 'Every active member logged a workout in the same week.', ''],
            'es' => ['Todos entrenaron', 'Todos los miembros activos registraron un entreno en la misma semana.', ''],
            'it' => ['Tutti si sono allenati', 'Ogni membro attivo ha registrato un workout nella stessa settimana.', ''],
        ]],
        ['team_photo_wall_20', 'team', 'team_photo_wall_20', [
            'en' => ['Photo Wall', 'The team uploaded 20 proof photos.', ''],
            'es' => ['Muro de fotos', 'El equipo subio 20 fotos de prueba.', ''],
            'it' => ['Bacheca foto', 'Il team ha caricato 20 foto prova.', ''],
        ]],
        ['team_1000_calories_burned', 'team', 'team_1000_calories_burned', [
            'en' => ['Team Burn 1000', 'The team logged 1,000 training calories burned.', ''],
            'es' => ['Quema 1000 de equipo', 'El equipo registro 1.000 calorias de entreno quemadas.', ''],
            'it' => ['Team burn 1000', 'Il team ha registrato 1.000 calorie allenamento bruciate.', ''],
        ]],
    ];

    foreach ($achievements as [$code, $scope, $trigger, $translations]) {
        $english = $translations['en'] ?? ['', '', ''];
        $name = (string) ($english[0] ?? $code);
        $description = (string) ($english[1] ?? '');
        $rewardText = (string) ($english[2] ?? '');
        $iconKey = normalize_achievement_icon_key((string) ($defaultIcons[$code] ?? 'trophy'));
        $existing = db_fetch_one($pdo, 'SELECT id FROM achievements WHERE code = :code', [':code' => $code]);
        $achievementId = (int) ($existing['id'] ?? 0);

        if ($achievementId <= 0) {
            db_execute(
                $pdo,
                'INSERT INTO achievements (code, name, description, scope, trigger_key, image_path, icon_key, reward_text, active, created_by, created_at, updated_at)
                 VALUES (:code, :name, :description, :scope, :trigger_key, NULL, :icon_key, :reward_text, 1, NULL, :created_at, :updated_at)',
                [
                    ':code' => $code,
                    ':name' => $name,
                    ':description' => $description,
                    ':scope' => $scope,
                    ':trigger_key' => $trigger,
                    ':icon_key' => $iconKey,
                    ':reward_text' => $rewardText !== '' ? $rewardText : null,
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]
            );
            $created = db_fetch_one($pdo, 'SELECT id FROM achievements WHERE code = :code', [':code' => $code]);
            $achievementId = (int) ($created['id'] ?? 0);
        }

        if ($achievementId <= 0) {
            continue;
        }

        db_execute(
            $pdo,
            'UPDATE achievements
             SET icon_key = :icon_key, updated_at = :updated_at
             WHERE id = :id AND (icon_key IS NULL OR TRIM(icon_key) = "")',
            [
                ':icon_key' => $iconKey,
                ':updated_at' => $now,
                ':id' => $achievementId,
            ]
        );

        foreach ($translations as $locale => $translation) {
            db_execute(
                $pdo,
                'INSERT OR IGNORE INTO achievement_translations (achievement_id, locale, name, description, reward_text, created_at, updated_at)
                 VALUES (:achievement_id, :locale, :name, :description, :reward_text, :created_at, :updated_at)',
                [
                    ':achievement_id' => $achievementId,
                    ':locale' => (string) $locale,
                    ':name' => (string) ($translation[0] ?? $name),
                    ':description' => (string) ($translation[1] ?? ''),
                    ':reward_text' => (string) ($translation[2] ?? ''),
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]
            );
        }
    }
}

function seed_default_motivational_quotes(PDO $pdo): void
{
    $existing = db_fetch_one($pdo, 'SELECT COUNT(*) AS total FROM motivational_quotes');
    if ((int) ($existing['total'] ?? 0) > 0) {
        return;
    }

    $quotes = default_motivation_quotes();
    $userQuotes = db_fetch_all(
        $pdo,
        'SELECT DISTINCT TRIM(motivation_quote) AS quote_text
         FROM users
         WHERE motivation_quote IS NOT NULL AND TRIM(motivation_quote) != ""'
    );
    foreach ($userQuotes as $row) {
        $quote = trim((string) ($row['quote_text'] ?? ''));
        if ($quote !== '' && !in_array($quote, $quotes, true)) {
            $quotes[] = $quote;
        }
    }

    $now = now_iso();
    foreach ($quotes as $quote) {
        db_execute(
            $pdo,
            'INSERT INTO motivational_quotes (quote_text, active, created_by, created_at, updated_at)
             VALUES (:quote_text, 1, NULL, :created_at, :updated_at)',
            [
                ':quote_text' => $quote,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]
        );
    }
}

function seed_workout_types_from_logs(PDO $pdo): void
{
    $rows = db_fetch_all(
        $pdo,
        'SELECT DISTINCT TRIM(workout_type) AS name FROM daily_logs
         WHERE workout_type IS NOT NULL AND TRIM(workout_type) != ""'
    );

    foreach ($rows as $row) {
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            continue;
        }

        $existing = db_fetch_one($pdo, 'SELECT id FROM workout_types WHERE LOWER(name) = LOWER(:name)', [':name' => $name]);
        if ($existing !== null) {
            continue;
        }

        db_execute(
            $pdo,
            'INSERT INTO workout_types (name, active, created_by, created_at, updated_at)
             VALUES (:name, 1, NULL, :created_at, :updated_at)',
            [
                ':name' => $name,
                ':created_at' => now_iso(),
                ':updated_at' => now_iso(),
            ]
        );
    }
}

function seed_default_habits(PDO $pdo): void
{
    $now = now_iso();
    $defaults = [
        ['morning_walk', 'Walk / run', 10],
        ['journaling', 'Journaling', 20],
        ['evening_chores', 'Chores', 30],
        ['reading', 'Reading', 40],
    ];

    foreach ($defaults as [$code, $label, $order]) {
        $existing = db_fetch_one($pdo, 'SELECT id FROM habit_definitions WHERE code = :code', [':code' => $code]);
        if ($existing !== null) {
            continue;
        }

        db_execute(
            $pdo,
            'INSERT INTO habit_definitions (code, label, active, sort_order, created_by, created_at, updated_at)
             VALUES (:code, :label, 1, :sort_order, NULL, :created_at, :updated_at)',
            [
                ':code' => $code,
                ':label' => $label,
                ':sort_order' => $order,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]
        );
    }
}

function backfill_workout_type_ids(PDO $pdo): void
{
    $logs = db_fetch_all(
        $pdo,
        'SELECT id, workout_type FROM daily_logs
         WHERE workout_type_id IS NULL AND workout_type IS NOT NULL AND TRIM(workout_type) != ""'
    );

    foreach ($logs as $log) {
        $type = db_fetch_one($pdo, 'SELECT id FROM workout_types WHERE LOWER(name) = LOWER(:name)', [':name' => trim((string) $log['workout_type'])]);
        if ($type === null) {
            continue;
        }

        db_execute($pdo, 'UPDATE daily_logs SET workout_type_id = :type_id WHERE id = :id', [':type_id' => (int) $type['id'], ':id' => (int) $log['id']]);
    }
}

function backfill_daily_log_base_metrics(PDO $pdo): void
{
    db_execute(
        $pdo,
        'UPDATE daily_logs
         SET base_steps = COALESCE(base_steps, steps),
             base_distance_km = CASE WHEN base_distance_km IS NULL THEN distance_km ELSE base_distance_km END,
             base_training_calories_burned = CASE WHEN base_training_calories_burned IS NULL THEN training_calories_burned ELSE base_training_calories_burned END
         WHERE base_steps IS NULL
            OR (base_distance_km IS NULL AND distance_km IS NOT NULL)
            OR (base_training_calories_burned IS NULL AND training_calories_burned IS NOT NULL)'
    );
}

function backfill_daily_log_workouts(PDO $pdo): void
{
    $logs = db_fetch_all(
        $pdo,
        'SELECT id, workout_done, workout_type_id, workout_type, created_at, updated_at
         FROM daily_logs
         WHERE workout_done = 1
            OR workout_type_id IS NOT NULL
            OR (workout_type IS NOT NULL AND TRIM(workout_type) != "")'
    );

    foreach ($logs as $log) {
        $existing = db_fetch_one(
            $pdo,
            'SELECT id FROM daily_log_workouts WHERE log_id = :log_id LIMIT 1',
            [':log_id' => (int) $log['id']]
        );
        if ($existing !== null) {
            continue;
        }

        $workoutType = trim((string) ($log['workout_type'] ?? ''));
        if ($workoutType === '') {
            $workoutType = 'Workout';
        }

        $createdAt = trim((string) ($log['created_at'] ?? ''));
        $updatedAt = trim((string) ($log['updated_at'] ?? ''));
        if ($createdAt === '') {
            $createdAt = now_iso();
        }
        if ($updatedAt === '') {
            $updatedAt = $createdAt;
        }

        db_execute(
            $pdo,
            'INSERT INTO daily_log_workouts (log_id, workout_type_id, workout_type, sort_order, created_at, updated_at)
             VALUES (:log_id, :workout_type_id, :workout_type, 1, :created_at, :updated_at)',
            [
                ':log_id' => (int) $log['id'],
                ':workout_type_id' => !empty($log['workout_type_id']) ? (int) $log['workout_type_id'] : null,
                ':workout_type' => $workoutType,
                ':created_at' => $createdAt,
                ':updated_at' => $updatedAt,
            ]
        );
    }
}

function backfill_daily_log_habits(PDO $pdo): void
{
    $habits = db_fetch_all($pdo, 'SELECT id, code FROM habit_definitions');
    $byCode = [];
    foreach ($habits as $habit) {
        $byCode[(string) $habit['code']] = (int) $habit['id'];
    }

    $columns = [
        'morning_walk' => 'morning_walk',
        'journaling' => 'journaling',
        'evening_chores' => 'evening_chores',
        'reading' => 'reading',
    ];
    $logs = db_fetch_all($pdo, 'SELECT id, morning_walk, journaling, evening_chores, reading FROM daily_logs');
    $now = now_iso();

    foreach ($logs as $log) {
        foreach ($columns as $column => $code) {
            if (!isset($byCode[$code])) {
                continue;
            }
            $existing = db_fetch_one(
                $pdo,
                'SELECT id FROM daily_log_habits WHERE log_id = :log_id AND habit_id = :habit_id',
                [':log_id' => (int) $log['id'], ':habit_id' => $byCode[$code]]
            );
            if ($existing !== null) {
                continue;
            }

            db_execute(
                $pdo,
                'INSERT INTO daily_log_habits (log_id, habit_id, value, created_at, updated_at)
                 VALUES (:log_id, :habit_id, :value, :created_at, :updated_at)',
                [
                    ':log_id' => (int) $log['id'],
                    ':habit_id' => $byCode[$code],
                    ':value' => (int) ($log[$column] ?? 0) === 1 ? 1 : 0,
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]
            );
        }
    }
}

function db_fetch_one(PDO $pdo, string $sql, array $params = []): ?array
{
    return db_retry(static function () use ($pdo, $sql, $params): ?array {
        $startedAt = microtime(true);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        db_profile_record($sql, $params, $startedAt);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    });
}

function db_fetch_all(PDO $pdo, string $sql, array $params = []): array
{
    return db_retry(static function () use ($pdo, $sql, $params): array {
        $startedAt = microtime(true);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        db_profile_record($sql, $params, $startedAt);

        return $stmt->fetchAll();
    });
}

function db_execute(PDO $pdo, string $sql, array $params = []): bool
{
    return db_retry(static function () use ($pdo, $sql, $params): bool {
        $startedAt = microtime(true);
        $stmt = $pdo->prepare($sql);

        $result = $stmt->execute($params);
        db_profile_record($sql, $params, $startedAt);
        if ($result && preg_match('/^\s*(insert|update|delete|replace|create|drop|alter)\b/i', $sql) === 1 && function_exists('app_cache_clear')) {
            app_cache_clear();
        }

        return $result;
    });
}

function db_profile_enabled(): bool
{
    $config = is_array($GLOBALS['config'] ?? null) ? (array) $GLOBALS['config'] : [];
    $raw = strtolower(trim((string) ($config['app_profile_enabled'] ?? getenv('APP_PROFILE') ?: '0')));

    return in_array($raw, ['1', 'true', 'yes', 'on'], true);
}

function db_profile_record(string $sql, array $params, float $startedAt): void
{
    $durationMs = max(0.0, (microtime(true) - $startedAt) * 1000);
    if (!isset($GLOBALS['db_profile']) || !is_array($GLOBALS['db_profile'])) {
        $GLOBALS['db_profile'] = [
            'query_count' => 0,
            'query_time_ms' => 0.0,
            'slow_queries' => [],
        ];
    }

    $GLOBALS['db_profile']['query_count'] = (int) ($GLOBALS['db_profile']['query_count'] ?? 0) + 1;
    $GLOBALS['db_profile']['query_time_ms'] = (float) ($GLOBALS['db_profile']['query_time_ms'] ?? 0.0) + $durationMs;

    if (!db_profile_enabled()) {
        return;
    }

    $config = is_array($GLOBALS['config'] ?? null) ? (array) $GLOBALS['config'] : [];
    $slowMs = max(1.0, (float) ($config['db_slow_query_ms'] ?? 50));
    if ($durationMs < $slowMs) {
        return;
    }

    $normalizedSql = trim((string) preg_replace('/\s+/', ' ', $sql));
    $record = [
        'duration_ms' => round($durationMs, 2),
        'sql' => $normalizedSql,
        'params' => $params,
    ];
    $GLOBALS['db_profile']['slow_queries'][] = $record;
    error_log('[db-slow] ' . (json_encode($record, JSON_UNESCAPED_SLASHES) ?: $normalizedSql));
}

function db_profile_snapshot(): array
{
    $profile = is_array($GLOBALS['db_profile'] ?? null) ? (array) $GLOBALS['db_profile'] : [];

    return [
        'query_count' => (int) ($profile['query_count'] ?? 0),
        'query_time_ms' => round((float) ($profile['query_time_ms'] ?? 0.0), 2),
        'slow_queries' => array_values((array) ($profile['slow_queries'] ?? [])),
    ];
}
