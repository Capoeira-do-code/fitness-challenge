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
            motivation_quote TEXT DEFAULT "",
            locale TEXT NOT NULL DEFAULT "en",
            avatar_path TEXT,
            primary_goal_type TEXT NOT NULL DEFAULT "steps",
            primary_goal_value REAL,
            dashboard_view TEXT NOT NULL DEFAULT "current_week",
            dashboard_layout_json TEXT,
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
            steps INTEGER NOT NULL DEFAULT 0,
            workout_done INTEGER NOT NULL DEFAULT 0,
            workout_type_id INTEGER,
            workout_type TEXT,
            junk_food INTEGER NOT NULL DEFAULT 0,
            extra_workout INTEGER NOT NULL DEFAULT 0,
            distance_km REAL,
            weight REAL,
            notes TEXT,
            step_exception_reason TEXT,
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
            created_at TEXT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
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
        'CREATE TABLE IF NOT EXISTS teams (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
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
            current_value REAL NOT NULL DEFAULT 0,
            due_date TEXT,
            status TEXT NOT NULL DEFAULT "active",
            created_by INTEGER,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
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

    ensure_schema_columns($pdo, $config);
    ensure_indexes($pdo);

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
    seed_workout_types_from_logs($pdo);
    backfill_workout_type_ids($pdo);
    backfill_daily_log_habits($pdo);
}

function ensure_schema_columns(PDO $pdo, array $config): void
{
    $defaultLocale = config_default_locale($config);
    ensure_column($pdo, 'users', 'locale', "TEXT NOT NULL DEFAULT '" . $defaultLocale . "'");
    ensure_column($pdo, 'users', 'avatar_path', 'TEXT');
    ensure_column($pdo, 'users', 'primary_goal_type', 'TEXT NOT NULL DEFAULT "steps"');
    ensure_column($pdo, 'users', 'primary_goal_value', 'REAL');
    ensure_column($pdo, 'users', 'dashboard_view', 'TEXT NOT NULL DEFAULT "current_week"');
    ensure_column($pdo, 'users', 'dashboard_layout_json', 'TEXT');
    ensure_column($pdo, 'users', 'meal_calendar_view', 'TEXT NOT NULL DEFAULT "week"');

    ensure_column($pdo, 'daily_logs', 'extra_workout', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'daily_logs', 'distance_km', 'REAL');
    ensure_column($pdo, 'daily_logs', 'workout_type_id', 'INTEGER');

    ensure_column($pdo, 'teams', 'join_mode', 'TEXT NOT NULL DEFAULT "closed"');
    ensure_column($pdo, 'teams', 'visibility', 'TEXT NOT NULL DEFAULT "visible"');

    ensure_column($pdo, 'achievements', 'image_path', 'TEXT');
    ensure_column($pdo, 'achievements', 'reward_text', 'TEXT');

    ensure_column($pdo, 'challenge_settings', 'active', 'INTEGER NOT NULL DEFAULT 1');
    ensure_column($pdo, 'challenge_settings', 'deleted_at', 'TEXT');
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
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_approval_status ON approval_requests(status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_approval_user ON approval_requests(user_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_daily_logs_date ON daily_logs(log_date)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_team_memberships_team ON team_memberships(team_id, active)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_team_memberships_user ON team_memberships(user_id, active)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_goals_scope ON goals(scope, status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_achievement_awards_user ON achievement_awards(user_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_achievement_awards_team ON achievement_awards(team_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_logs(created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_audit_entity ON audit_logs(entity_type, entity_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_join_requests_team ON team_join_requests(team_id, status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_daily_log_habits_log ON daily_log_habits(log_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_achievement_rules_achievement ON achievement_rules(achievement_id)');
}

function seed_default_team(PDO $pdo): void
{
    $now = now_iso();
    $team = db_fetch_one($pdo, 'SELECT * FROM teams WHERE slug = :slug', [':slug' => 'main']);

    if ($team === null) {
        db_execute(
            $pdo,
            'INSERT INTO teams (name, slug, active, created_at, updated_at)
             VALUES (:name, :slug, 1, :created_at, :updated_at)',
            [
                ':name' => 'Fitness Challenge Team',
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
