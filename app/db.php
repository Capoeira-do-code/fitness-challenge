<?php

declare(strict_types=1);

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

    initialize_database($pdo, $config);

    return $pdo;
}

function initialize_database(PDO $pdo, array $config): void
{
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
            locale TEXT NOT NULL DEFAULT "en",
            avatar_path TEXT,
            primary_goal_type TEXT NOT NULL DEFAULT "steps",
            primary_goal_value REAL,
            primary_goals_spec TEXT,
            dashboard_view TEXT NOT NULL DEFAULT "current_week",
            dashboard_layout_json TEXT,
            team_layout_json TEXT,
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
            reward_text TEXT,
            active INTEGER NOT NULL DEFAULT 1,
            created_by INTEGER,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
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
}

function ensure_schema_columns(PDO $pdo, array $config): void
{
    $defaultLocale = config_default_locale($config);
    ensure_column($pdo, 'users', 'locale', "TEXT NOT NULL DEFAULT '" . $defaultLocale . "'");
    ensure_column($pdo, 'users', 'avatar_path', 'TEXT');
    ensure_column($pdo, 'users', 'primary_goal_type', 'TEXT NOT NULL DEFAULT "steps"');
    ensure_column($pdo, 'users', 'primary_goal_value', 'REAL');
    ensure_column($pdo, 'users', 'primary_goals_spec', 'TEXT');
    ensure_column($pdo, 'users', 'dashboard_view', 'TEXT NOT NULL DEFAULT "current_week"');
    ensure_column($pdo, 'users', 'dashboard_layout_json', 'TEXT');
    ensure_column($pdo, 'users', 'team_layout_json', 'TEXT');
    ensure_column($pdo, 'users', 'meal_calendar_view', 'TEXT NOT NULL DEFAULT "week"');
    ensure_column($pdo, 'users', 'maintenance_calories', 'REAL');
    ensure_column($pdo, 'users', 'calorie_burn_goal', 'REAL');
    ensure_column($pdo, 'users', 'calorie_consumed_max', 'REAL');

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
    ensure_column($pdo, 'achievements', 'reward_text', 'TEXT');
    ensure_column($pdo, 'achievements', 'active', 'INTEGER NOT NULL DEFAULT 1');
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
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_approval_log_type_unique ON approval_requests(log_id, approval_type)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_daily_logs_date ON daily_logs(log_date)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_photo_entries_user_date ON photo_entries(user_id, log_date)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_photo_comments_photo_created ON photo_comments(photo_id, created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_photo_comments_user ON photo_comments(user_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_team_memberships_team ON team_memberships(team_id, active)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_team_memberships_user ON team_memberships(user_id, active)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_goals_scope ON goals(scope, status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_goal_team ON goals(team_id, status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_achievement_awards_user ON achievement_awards(user_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_achievement_awards_team ON achievement_awards(team_id)');
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
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_notifications_user_unique_key ON user_notifications(user_id, unique_key) WHERE unique_key IS NOT NULL');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_system_backups_created ON system_backups(created_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_system_backups_status ON system_backups(status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_challenge_archives_archived_at ON challenge_archives(archived_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_strike_review_requests_target ON strike_review_requests(target_user_id, week_start, event_date)');
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
    $achievements = [
        ['first_log', 'First Log', 'Saved the first daily log.', 'user', 'first_log'],
        ['first_photo', 'Proof Shot', 'Uploaded the first proof photo.', 'user', 'first_photo'],
        ['three_workouts_week', 'Triple Training', 'Completed 3 workouts in one week.', 'user', 'three_workouts_week'],
        ['perfect_week', 'Perfect Week', 'Completed a week with no failures.', 'user', 'perfect_week'],
        ['step_streak', 'Step Streak', 'Hit step goal for 5 tracked days.', 'user', 'step_streak'],
        ['no_strike_week', 'Clean Sheet', 'Closed a week with no penalty.', 'user', 'no_strike_week'],
        ['team_active', 'Team Pulse', 'The team has active members logging progress.', 'team', 'team_active'],
    ];

    foreach ($achievements as [$code, $name, $description, $scope, $trigger]) {
        $existing = db_fetch_one($pdo, 'SELECT id FROM achievements WHERE code = :code', [':code' => $code]);
        if ($existing !== null) {
            continue;
        }

        db_execute(
            $pdo,
            'INSERT INTO achievements (code, name, description, scope, trigger_key, active, created_by, created_at, updated_at)
             VALUES (:code, :name, :description, :scope, :trigger_key, 1, NULL, :created_at, :updated_at)',
            [
                ':code' => $code,
                ':name' => $name,
                ':description' => $description,
                ':scope' => $scope,
                ':trigger_key' => $trigger,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]
        );
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
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

function db_fetch_all(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function db_execute(PDO $pdo, string $sql, array $params = []): bool
{
    $stmt = $pdo->prepare($sql);

    return $stmt->execute($params);
}
