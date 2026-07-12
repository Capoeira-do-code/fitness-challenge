<?php

declare(strict_types=1);

/**
 * Workouts subsystem — routines, exercises, sessions, sets and personal records.
 *
 * Built alongside the existing daily_logs / workout_types system (not replacing
 * it): a WorkoutSession may link to a daily_log via daily_log_id so a logged
 * session still counts toward the challenge. All tables are created lazily via
 * workouts_ensure_schema(), mirroring friends/duels/squads modules.
 */

const WK_EXERCISE_TYPES = ['strength', 'cardio', 'isometric', 'bodyweight', 'freeform'];
const WK_UNITS = ['kg', 'lb'];

function workouts_ensure_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS exercise_definitions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            name TEXT NOT NULL,
            muscle_group TEXT NOT NULL DEFAULT "",
            exercise_type TEXT NOT NULL DEFAULT "strength",
            equipment TEXT NOT NULL DEFAULT "",
            is_system INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS workout_routines (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            icon TEXT NOT NULL DEFAULT "dumbbell",
            description TEXT NOT NULL DEFAULT "",
            is_favorite INTEGER NOT NULL DEFAULT 0,
            is_archived INTEGER NOT NULL DEFAULT 0,
            sort_order INTEGER NOT NULL DEFAULT 0,
            recommended_days_mask TEXT NOT NULL DEFAULT "0000000",
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS routine_exercises (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            routine_id INTEGER NOT NULL,
            exercise_def_id INTEGER NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            target_sets INTEGER NOT NULL DEFAULT 3,
            target_reps INTEGER,
            target_weight REAL,
            target_duration INTEGER,
            target_distance REAL,
            rest_seconds INTEGER,
            unit TEXT NOT NULL DEFAULT "kg",
            notes TEXT NOT NULL DEFAULT "",
            FOREIGN KEY (routine_id) REFERENCES workout_routines(id) ON DELETE CASCADE,
            FOREIGN KEY (exercise_def_id) REFERENCES exercise_definitions(id) ON DELETE CASCADE
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS workout_sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            routine_id INTEGER,
            daily_log_id INTEGER,
            title TEXT NOT NULL DEFAULT "",
            status TEXT NOT NULL DEFAULT \'active\',
            started_at TEXT NOT NULL,
            ended_at TEXT,
            notes TEXT NOT NULL DEFAULT "",
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (routine_id) REFERENCES workout_routines(id) ON DELETE SET NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS session_exercises (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            session_id INTEGER NOT NULL,
            exercise_def_id INTEGER NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            skipped INTEGER NOT NULL DEFAULT 0,
            unit TEXT NOT NULL DEFAULT "kg",
            notes TEXT NOT NULL DEFAULT "",
            FOREIGN KEY (session_id) REFERENCES workout_sessions(id) ON DELETE CASCADE,
            FOREIGN KEY (exercise_def_id) REFERENCES exercise_definitions(id) ON DELETE CASCADE
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS workout_sets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            session_exercise_id INTEGER NOT NULL,
            set_index INTEGER NOT NULL DEFAULT 1,
            reps INTEGER,
            weight REAL,
            duration INTEGER,
            distance REAL,
            rpe REAL,
            completed INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            FOREIGN KEY (session_exercise_id) REFERENCES session_exercises(id) ON DELETE CASCADE
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS personal_records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            exercise_def_id INTEGER NOT NULL,
            metric TEXT NOT NULL,
            value REAL NOT NULL,
            achieved_at TEXT NOT NULL,
            session_id INTEGER,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (exercise_def_id) REFERENCES exercise_definitions(id) ON DELETE CASCADE
        )'
    );

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_wk_routines_user ON workout_routines(user_id, is_archived, sort_order)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_wk_sessions_user ON workout_sessions(user_id, status, started_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_wk_pr_user ON personal_records(user_id, exercise_def_id)');

    workouts_seed_system_exercises($pdo);
}

/** Seed a small catalogue of common exercises once. */
function workouts_seed_system_exercises(PDO $pdo): void
{
    $existing = db_fetch_one($pdo, 'SELECT COUNT(*) AS c FROM exercise_definitions WHERE is_system = 1');
    if ((int) ($existing['c'] ?? 0) > 0) {
        return;
    }
    $now = now_iso();
    $seed = [
        ['Bench Press', 'chest', 'strength', 'barbell'],
        ['Squat', 'legs', 'strength', 'barbell'],
        ['Deadlift', 'back', 'strength', 'barbell'],
        ['Overhead Press', 'shoulders', 'strength', 'barbell'],
        ['Pull Up', 'back', 'bodyweight', 'bodyweight'],
        ['Push Up', 'chest', 'bodyweight', 'bodyweight'],
        ['Barbell Row', 'back', 'strength', 'barbell'],
        ['Bicep Curl', 'arms', 'strength', 'dumbbell'],
        ['Tricep Extension', 'arms', 'strength', 'dumbbell'],
        ['Lunge', 'legs', 'bodyweight', 'bodyweight'],
        ['Plank', 'core', 'isometric', 'bodyweight'],
        ['Running', 'cardio', 'cardio', 'none'],
        ['Cycling', 'cardio', 'cardio', 'machine'],
        ['Rowing', 'cardio', 'cardio', 'machine'],
    ];
    foreach ($seed as $row) {
        db_execute(
            $pdo,
            'INSERT INTO exercise_definitions (user_id, name, muscle_group, exercise_type, equipment, is_system, created_at, updated_at)
             VALUES (NULL, :n, :m, :t, :e, 1, :now, :now)',
            [':n' => $row[0], ':m' => $row[1], ':t' => $row[2], ':e' => $row[3], ':now' => $now]
        );
    }
}

/* ---------------------------------------------------------------------------
 * Exercise definitions
 * ------------------------------------------------------------------------- */

/** @return array<int,array<string,mixed>> System + this user's exercises. */
function wk_exercises_for_user(PDO $pdo, int $userId): array
{
    return db_fetch_all(
        $pdo,
        'SELECT * FROM exercise_definitions
         WHERE is_system = 1 OR user_id = :u
         ORDER BY is_system DESC, name COLLATE NOCASE ASC',
        [':u' => $userId]
    );
}

function wk_exercise_get(PDO $pdo, int $id): ?array
{
    return db_fetch_one($pdo, 'SELECT * FROM exercise_definitions WHERE id = :id', [':id' => $id]);
}

function wk_exercise_create(PDO $pdo, int $userId, string $name, string $muscle, string $type, string $equipment): int
{
    $name = trim($name);
    if ($name === '') {
        return 0;
    }
    $type = in_array($type, WK_EXERCISE_TYPES, true) ? $type : 'strength';
    $now = now_iso();
    db_execute(
        $pdo,
        'INSERT INTO exercise_definitions (user_id, name, muscle_group, exercise_type, equipment, is_system, created_at, updated_at)
         VALUES (:u, :n, :m, :t, :e, 0, :now, :now)',
        [':u' => $userId, ':n' => $name, ':m' => trim($muscle), ':t' => $type, ':e' => trim($equipment), ':now' => $now]
    );

    return (int) $pdo->lastInsertId();
}

/* ---------------------------------------------------------------------------
 * Routines
 * ------------------------------------------------------------------------- */

/** @return array<int,array<string,mixed>> */
function wk_routines_for_user(PDO $pdo, int $userId, bool $includeArchived = false): array
{
    $sql = 'SELECT r.*, (SELECT COUNT(*) FROM routine_exercises re WHERE re.routine_id = r.id) AS exercise_count
            FROM workout_routines r
            WHERE r.user_id = :u' . ($includeArchived ? '' : ' AND r.is_archived = 0') . '
            ORDER BY r.is_favorite DESC, r.sort_order ASC, r.id ASC';

    return db_fetch_all($pdo, $sql, [':u' => $userId]);
}

function wk_routine_get(PDO $pdo, int $id, int $userId): ?array
{
    return db_fetch_one(
        $pdo,
        'SELECT * FROM workout_routines WHERE id = :id AND user_id = :u',
        [':id' => $id, ':u' => $userId]
    );
}

function wk_routine_create(PDO $pdo, int $userId, string $name, string $icon = 'dumbbell', string $description = ''): int
{
    $name = trim($name);
    if ($name === '') {
        return 0;
    }
    $order = db_fetch_one($pdo, 'SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM workout_routines WHERE user_id = :u', [':u' => $userId]);
    $now = now_iso();
    db_execute(
        $pdo,
        'INSERT INTO workout_routines (user_id, name, icon, description, sort_order, created_at, updated_at)
         VALUES (:u, :n, :i, :d, :o, :now, :now)',
        [':u' => $userId, ':n' => $name, ':i' => trim($icon) ?: 'dumbbell', ':d' => trim($description), ':o' => (int) ($order['n'] ?? 1), ':now' => $now]
    );

    return (int) $pdo->lastInsertId();
}

function wk_routine_update(PDO $pdo, int $id, int $userId, array $fields): bool
{
    if (wk_routine_get($pdo, $id, $userId) === null) {
        return false;
    }
    $allowed = ['name', 'icon', 'description', 'recommended_days_mask'];
    $sets = [];
    $params = [':id' => $id, ':u' => $userId, ':now' => now_iso()];
    foreach ($allowed as $key) {
        if (array_key_exists($key, $fields)) {
            $sets[] = "$key = :$key";
            $params[":$key"] = (string) $fields[$key];
        }
    }
    if ($sets === []) {
        return false;
    }
    $sets[] = 'updated_at = :now';

    return db_execute($pdo, 'UPDATE workout_routines SET ' . implode(', ', $sets) . ' WHERE id = :id AND user_id = :u', $params);
}

function wk_routine_set_flag(PDO $pdo, int $id, int $userId, string $flag, int $value): bool
{
    if (!in_array($flag, ['is_favorite', 'is_archived'], true)) {
        return false;
    }

    return db_execute(
        $pdo,
        "UPDATE workout_routines SET $flag = :v, updated_at = :now WHERE id = :id AND user_id = :u",
        [':v' => $value ? 1 : 0, ':now' => now_iso(), ':id' => $id, ':u' => $userId]
    );
}

function wk_routine_delete(PDO $pdo, int $id, int $userId): bool
{
    return db_execute($pdo, 'DELETE FROM workout_routines WHERE id = :id AND user_id = :u', [':id' => $id, ':u' => $userId]);
}

function wk_routine_duplicate(PDO $pdo, int $id, int $userId): int
{
    $routine = wk_routine_get($pdo, $id, $userId);
    if ($routine === null) {
        return 0;
    }
    $newId = wk_routine_create($pdo, $userId, (string) $routine['name'] . ' (copy)', (string) $routine['icon'], (string) $routine['description']);
    if ($newId <= 0) {
        return 0;
    }
    foreach (wk_routine_exercises($pdo, $id) as $ex) {
        db_execute(
            $pdo,
            'INSERT INTO routine_exercises (routine_id, exercise_def_id, sort_order, target_sets, target_reps, target_weight, target_duration, target_distance, rest_seconds, unit, notes)
             VALUES (:r, :e, :so, :ts, :tr, :tw, :td, :ds, :rs, :un, :no)',
            [
                ':r' => $newId, ':e' => (int) $ex['exercise_def_id'], ':so' => (int) $ex['sort_order'],
                ':ts' => (int) $ex['target_sets'], ':tr' => $ex['target_reps'], ':tw' => $ex['target_weight'],
                ':td' => $ex['target_duration'], ':ds' => $ex['target_distance'], ':rs' => $ex['rest_seconds'],
                ':un' => (string) $ex['unit'], ':no' => (string) $ex['notes'],
            ]
        );
    }

    return $newId;
}

/**
 * Persist a new order for a user's routines.
 *
 * @param array<int,int> $orderedIds
 */
function wk_routine_reorder(PDO $pdo, int $userId, array $orderedIds): void
{
    $i = 0;
    foreach ($orderedIds as $rid) {
        $i++;
        db_execute(
            $pdo,
            'UPDATE workout_routines SET sort_order = :o, updated_at = :now WHERE id = :id AND user_id = :u',
            [':o' => $i, ':now' => now_iso(), ':id' => (int) $rid, ':u' => $userId]
        );
    }
}

/* ---------------------------------------------------------------------------
 * Routine exercises
 * ------------------------------------------------------------------------- */

/** @return array<int,array<string,mixed>> */
function wk_routine_exercises(PDO $pdo, int $routineId): array
{
    return db_fetch_all(
        $pdo,
        'SELECT re.*, ed.name AS exercise_name, ed.muscle_group, ed.exercise_type
         FROM routine_exercises re
         JOIN exercise_definitions ed ON ed.id = re.exercise_def_id
         WHERE re.routine_id = :r
         ORDER BY re.sort_order ASC, re.id ASC',
        [':r' => $routineId]
    );
}

function wk_routine_add_exercise(PDO $pdo, int $routineId, int $exerciseDefId, array $targets = []): int
{
    $order = db_fetch_one($pdo, 'SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM routine_exercises WHERE routine_id = :r', [':r' => $routineId]);
    db_execute(
        $pdo,
        'INSERT INTO routine_exercises (routine_id, exercise_def_id, sort_order, target_sets, target_reps, target_weight, target_duration, target_distance, rest_seconds, unit, notes)
         VALUES (:r, :e, :so, :ts, :tr, :tw, :td, :ds, :rs, :un, :no)',
        [
            ':r' => $routineId, ':e' => $exerciseDefId, ':so' => (int) ($order['n'] ?? 1),
            ':ts' => max(1, (int) ($targets['target_sets'] ?? 3)),
            ':tr' => isset($targets['target_reps']) && $targets['target_reps'] !== '' ? (int) $targets['target_reps'] : null,
            ':tw' => isset($targets['target_weight']) && $targets['target_weight'] !== '' ? (float) $targets['target_weight'] : null,
            ':td' => isset($targets['target_duration']) && $targets['target_duration'] !== '' ? (int) $targets['target_duration'] : null,
            ':ds' => isset($targets['target_distance']) && $targets['target_distance'] !== '' ? (float) $targets['target_distance'] : null,
            ':rs' => isset($targets['rest_seconds']) && $targets['rest_seconds'] !== '' ? (int) $targets['rest_seconds'] : null,
            ':un' => in_array($targets['unit'] ?? 'kg', WK_UNITS, true) ? (string) $targets['unit'] : 'kg',
            ':no' => trim((string) ($targets['notes'] ?? '')),
        ]
    );

    return (int) $pdo->lastInsertId();
}

function wk_routine_remove_exercise(PDO $pdo, int $routineExerciseId, int $routineId): bool
{
    return db_execute(
        $pdo,
        'DELETE FROM routine_exercises WHERE id = :id AND routine_id = :r',
        [':id' => $routineExerciseId, ':r' => $routineId]
    );
}

/* ---------------------------------------------------------------------------
 * Sessions & sets
 * ------------------------------------------------------------------------- */

function wk_session_active_for_user(PDO $pdo, int $userId): ?array
{
    return db_fetch_one(
        $pdo,
        'SELECT * FROM workout_sessions WHERE user_id = :u AND status = \'active\' ORDER BY started_at DESC LIMIT 1',
        [':u' => $userId]
    );
}

function wk_session_get(PDO $pdo, int $id, int $userId): ?array
{
    return db_fetch_one($pdo, 'SELECT * FROM workout_sessions WHERE id = :id AND user_id = :u', [':id' => $id, ':u' => $userId]);
}

/** Start a session, optionally seeded from a routine's exercises. */
function wk_session_start(PDO $pdo, int $userId, ?int $routineId = null, string $title = ''): int
{
    $now = now_iso();
    $routine = $routineId !== null ? wk_routine_get($pdo, $routineId, $userId) : null;
    if ($title === '' && $routine !== null) {
        $title = (string) $routine['name'];
    }
    db_execute(
        $pdo,
        'INSERT INTO workout_sessions (user_id, routine_id, title, status, started_at, created_at, updated_at)
         VALUES (:u, :r, :t, \'active\', :now, :now, :now)',
        [':u' => $userId, ':r' => $routine !== null ? (int) $routine['id'] : null, ':t' => $title, ':now' => $now]
    );
    $sessionId = (int) $pdo->lastInsertId();

    if ($routine !== null) {
        foreach (wk_routine_exercises($pdo, (int) $routine['id']) as $ex) {
            db_execute(
                $pdo,
                'INSERT INTO session_exercises (session_id, exercise_def_id, sort_order, unit, notes)
                 VALUES (:s, :e, :so, :un, :no)',
                [':s' => $sessionId, ':e' => (int) $ex['exercise_def_id'], ':so' => (int) $ex['sort_order'], ':un' => (string) $ex['unit'], ':no' => (string) $ex['notes']]
            );
            $sessionExerciseId = (int) $pdo->lastInsertId();
            $targetSets = max(1, (int) $ex['target_sets']);
            for ($i = 1; $i <= $targetSets; $i++) {
                db_execute(
                    $pdo,
                    'INSERT INTO workout_sets (session_exercise_id, set_index, reps, weight, completed, created_at)
                     VALUES (:se, :idx, :reps, :weight, 0, :now)',
                    [':se' => $sessionExerciseId, ':idx' => $i, ':reps' => $ex['target_reps'], ':weight' => $ex['target_weight'], ':now' => now_iso()]
                );
            }
        }
    }

    return $sessionId;
}

/** @return array<int,array<string,mixed>> Exercises with their sets nested under 'sets'. */
function wk_session_exercises(PDO $pdo, int $sessionId): array
{
    $exercises = db_fetch_all(
        $pdo,
        'SELECT se.*, ed.name AS exercise_name, ed.muscle_group, ed.exercise_type
         FROM session_exercises se
         JOIN exercise_definitions ed ON ed.id = se.exercise_def_id
         WHERE se.session_id = :s
         ORDER BY se.sort_order ASC, se.id ASC',
        [':s' => $sessionId]
    );
    foreach ($exercises as &$ex) {
        $ex['sets'] = db_fetch_all(
            $pdo,
            'SELECT * FROM workout_sets WHERE session_exercise_id = :se ORDER BY set_index ASC, id ASC',
            [':se' => (int) $ex['id']]
        );
    }
    unset($ex);

    return $exercises;
}

function wk_session_add_exercise(PDO $pdo, int $sessionId, int $exerciseDefId): int
{
    $order = db_fetch_one($pdo, 'SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM session_exercises WHERE session_id = :s', [':s' => $sessionId]);
    db_execute(
        $pdo,
        'INSERT INTO session_exercises (session_id, exercise_def_id, sort_order) VALUES (:s, :e, :so)',
        [':s' => $sessionId, ':e' => $exerciseDefId, ':so' => (int) ($order['n'] ?? 1)]
    );
    $seId = (int) $pdo->lastInsertId();
    wk_set_add($pdo, $seId);

    return $seId;
}

function wk_set_add(PDO $pdo, int $sessionExerciseId): int
{
    $order = db_fetch_one($pdo, 'SELECT COALESCE(MAX(set_index), 0) + 1 AS n FROM workout_sets WHERE session_exercise_id = :se', [':se' => $sessionExerciseId]);
    db_execute(
        $pdo,
        'INSERT INTO workout_sets (session_exercise_id, set_index, completed, created_at) VALUES (:se, :idx, 0, :now)',
        [':se' => $sessionExerciseId, ':idx' => (int) ($order['n'] ?? 1), ':now' => now_iso()]
    );

    return (int) $pdo->lastInsertId();
}

function wk_set_update(PDO $pdo, int $setId, array $fields): bool
{
    $allowed = ['reps', 'weight', 'duration', 'distance', 'rpe', 'completed'];
    $sets = [];
    $params = [':id' => $setId];
    foreach ($allowed as $key) {
        if (array_key_exists($key, $fields)) {
            $sets[] = "$key = :$key";
            $val = $fields[$key];
            if ($key === 'completed') {
                $params[":$key"] = $val ? 1 : 0;
            } else {
                $params[":$key"] = ($val === '' || $val === null) ? null : ($key === 'weight' || $key === 'distance' || $key === 'rpe' ? (float) $val : (int) $val);
            }
        }
    }
    if ($sets === []) {
        return false;
    }

    return db_execute($pdo, 'UPDATE workout_sets SET ' . implode(', ', $sets) . ' WHERE id = :id', $params);
}

function wk_set_delete(PDO $pdo, int $setId): bool
{
    return db_execute($pdo, 'DELETE FROM workout_sets WHERE id = :id', [':id' => $setId]);
}

/** Finish a session: mark completed, compute volume, refresh PRs. */
function wk_session_finish(PDO $pdo, int $sessionId, int $userId, bool $countTowardChallenge = true): bool
{
    $session = wk_session_get($pdo, $sessionId, $userId);
    if ($session === null || (string) $session['status'] !== 'active') {
        return false;
    }
    db_execute(
        $pdo,
        'UPDATE workout_sessions SET status = \'completed\', ended_at = :now, updated_at = :now WHERE id = :id AND user_id = :u',
        [':now' => now_iso(), ':id' => $sessionId, ':u' => $userId]
    );
    wk_refresh_personal_records($pdo, $userId, $sessionId);

    // Only count sessions that actually have a completed set toward the
    // challenge, so an accidentally-finished empty session doesn't mark a day.
    if ($countTowardChallenge) {
        $done = db_fetch_one(
            $pdo,
            'SELECT COUNT(*) AS c FROM session_exercises se
             JOIN workout_sets ws ON ws.session_exercise_id = se.id
             WHERE se.session_id = :s AND ws.completed = 1',
            [':s' => $sessionId]
        );
        if ((int) ($done['c'] ?? 0) > 0) {
            $title = trim((string) ($session['title'] ?? ''));
            wk_link_session_to_daily_log($pdo, $userId, $sessionId, $title !== '' ? $title : t('workouts.session'));
        }
    }

    return true;
}

/**
 * Bridge a finished session into the challenge (#13): mark today's daily_log as
 * a completed workout WITHOUT touching steps/weight/other fields, and link the
 * session to that log. Non-destructive — it only turns workout_done on.
 * Counting logic (challenge.php) treats workout_done=1 as one workout when no
 * daily_log_workouts rows exist, so a logged session counts for the day.
 */
function wk_link_session_to_daily_log(PDO $pdo, int $userId, int $sessionId, string $title): void
{
    $today = date('Y-m-d');
    $existing = db_fetch_one(
        $pdo,
        'SELECT id, workout_type FROM daily_logs WHERE user_id = :u AND log_date = :d',
        [':u' => $userId, ':d' => $today]
    );
    $now = now_iso();
    if ($existing === null) {
        db_execute(
            $pdo,
            'INSERT INTO daily_logs (user_id, log_date, workout_done, workout_type, steps, created_at, updated_at)
             VALUES (:u, :d, 1, :t, 0, :now, :now)',
            [':u' => $userId, ':d' => $today, ':t' => $title, ':now' => $now]
        );
        $logId = (int) $pdo->lastInsertId();
    } else {
        $logId = (int) $existing['id'];
        $type = trim((string) ($existing['workout_type'] ?? '')) !== '' ? (string) $existing['workout_type'] : $title;
        db_execute(
            $pdo,
            'UPDATE daily_logs SET workout_done = 1, workout_type = :t, updated_at = :now WHERE id = :id',
            [':t' => $type, ':now' => $now, ':id' => $logId]
        );
    }
    db_execute(
        $pdo,
        'UPDATE workout_sessions SET daily_log_id = :l, updated_at = :now WHERE id = :s AND user_id = :u',
        [':l' => $logId, ':now' => $now, ':s' => $sessionId, ':u' => $userId]
    );
}

function wk_session_cancel(PDO $pdo, int $sessionId, int $userId): bool
{
    return db_execute(
        $pdo,
        'UPDATE workout_sessions SET status = \'cancelled\', ended_at = :now, updated_at = :now WHERE id = :id AND user_id = :u AND status = \'active\'',
        [':now' => now_iso(), ':id' => $sessionId, ':u' => $userId]
    );
}

/** @return array<int,array<string,mixed>> */
function wk_sessions_for_user(PDO $pdo, int $userId, int $limit = 50): array
{
    return db_fetch_all(
        $pdo,
        'SELECT * FROM workout_sessions WHERE user_id = :u AND status = \'completed\' ORDER BY started_at DESC LIMIT ' . max(1, min(200, $limit)),
        [':u' => $userId]
    );
}

/**
 * Recompute personal records touched by a session (best weight, best est. 1RM,
 * best reps per exercise). Uses Epley: 1RM = weight * (1 + reps/30).
 */
function wk_refresh_personal_records(PDO $pdo, int $userId, int $sessionId): void
{
    $rows = db_fetch_all(
        $pdo,
        'SELECT se.exercise_def_id, ws.reps, ws.weight
         FROM session_exercises se
         JOIN workout_sets ws ON ws.session_exercise_id = se.id
         WHERE se.session_id = :s AND ws.completed = 1',
        [':s' => $sessionId]
    );
    $now = now_iso();
    foreach ($rows as $row) {
        $exId = (int) $row['exercise_def_id'];
        $weight = $row['weight'] !== null ? (float) $row['weight'] : null;
        $reps = $row['reps'] !== null ? (int) $row['reps'] : null;
        $candidates = [];
        if ($weight !== null && $weight > 0) {
            $candidates['max_weight'] = $weight;
            if ($reps !== null && $reps > 0) {
                $candidates['est_1rm'] = round($weight * (1 + $reps / 30), 1);
            }
        }
        if ($reps !== null && $reps > 0) {
            $candidates['max_reps'] = (float) $reps;
        }
        foreach ($candidates as $metric => $value) {
            $current = db_fetch_one(
                $pdo,
                'SELECT id, value FROM personal_records WHERE user_id = :u AND exercise_def_id = :e AND metric = :m',
                [':u' => $userId, ':e' => $exId, ':m' => $metric]
            );
            if ($current === null) {
                db_execute(
                    $pdo,
                    'INSERT INTO personal_records (user_id, exercise_def_id, metric, value, achieved_at, session_id)
                     VALUES (:u, :e, :m, :v, :now, :s)',
                    [':u' => $userId, ':e' => $exId, ':m' => $metric, ':v' => $value, ':now' => $now, ':s' => $sessionId]
                );
            } elseif ($value > (float) $current['value']) {
                db_execute(
                    $pdo,
                    'UPDATE personal_records SET value = :v, achieved_at = :now, session_id = :s WHERE id = :id',
                    [':v' => $value, ':now' => $now, ':s' => $sessionId, ':id' => (int) $current['id']]
                );
            }
        }
    }
}

/** @return array<int,array<string,mixed>> */
function wk_personal_records_for_user(PDO $pdo, int $userId, int $limit = 20): array
{
    return db_fetch_all(
        $pdo,
        'SELECT pr.*, ed.name AS exercise_name
         FROM personal_records pr
         JOIN exercise_definitions ed ON ed.id = pr.exercise_def_id
         WHERE pr.user_id = :u AND pr.metric = "est_1rm"
         ORDER BY pr.achieved_at DESC LIMIT ' . max(1, min(100, $limit)),
        [':u' => $userId]
    );
}

/**
 * Aggregate workout stats for a user over an optional date range.
 * Volume = sum(weight * reps) over completed sets. Server-side so the client
 * never recomputes history (#19 performance).
 *
 * @return array{sessions:int,sets:int,reps:int,volume:float,duration_min:int}
 */
function wk_summary_for_user(PDO $pdo, int $userId, ?string $since = null): array
{
    $params = [':u' => $userId];
    $dateCond = '';
    if ($since !== null && $since !== '') {
        $dateCond = ' AND s.started_at >= :since';
        $params[':since'] = $since;
    }
    $row = db_fetch_one(
        $pdo,
        'SELECT
            COUNT(DISTINCT s.id) AS sessions,
            COUNT(ws.id) AS sets,
            COALESCE(SUM(CASE WHEN ws.completed = 1 THEN ws.reps ELSE 0 END), 0) AS reps,
            COALESCE(SUM(CASE WHEN ws.completed = 1 AND ws.weight IS NOT NULL AND ws.reps IS NOT NULL THEN ws.weight * ws.reps ELSE 0 END), 0) AS volume
         FROM workout_sessions s
         LEFT JOIN session_exercises se ON se.session_id = s.id
         LEFT JOIN workout_sets ws ON ws.session_exercise_id = se.id
         WHERE s.user_id = :u AND s.status = \'completed\'' . $dateCond,
        $params
    );

    return [
        'sessions' => (int) ($row['sessions'] ?? 0),
        'sets' => (int) ($row['sets'] ?? 0),
        'reps' => (int) ($row['reps'] ?? 0),
        'volume' => (float) ($row['volume'] ?? 0),
        'duration_min' => 0,
    ];
}

/* ---------------------------------------------------------------------------
 * Analytics (#6) — all server-side aggregation, cheap enough per page load.
 * ------------------------------------------------------------------------- */

/**
 * Sessions + volume grouped by ISO week for the last N weeks (oldest first).
 *
 * @return array<int,array{label:string,week:string,sessions:int,volume:float}>
 */
function wk_weekly_series(PDO $pdo, int $userId, int $weeks = 8): array
{
    $weeks = max(1, min(26, $weeks));
    $rows = db_fetch_all(
        $pdo,
        'SELECT s.id, s.started_at,
                COALESCE(SUM(CASE WHEN ws.completed = 1 AND ws.weight IS NOT NULL AND ws.reps IS NOT NULL THEN ws.weight * ws.reps ELSE 0 END), 0) AS volume
         FROM workout_sessions s
         LEFT JOIN session_exercises se ON se.session_id = s.id
         LEFT JOIN workout_sets ws ON ws.session_exercise_id = se.id
         WHERE s.user_id = :u AND s.status = \'completed\'
         GROUP BY s.id
         ORDER BY s.started_at ASC',
        [':u' => $userId]
    );

    // Build the last N week buckets (Monday-based).
    $buckets = [];
    $cursor = new DateTimeImmutable('monday this week');
    for ($i = $weeks - 1; $i >= 0; $i--) {
        $weekStart = $cursor->modify('-' . $i . ' week');
        $key = $weekStart->format('o-\WW');
        $buckets[$key] = ['label' => $weekStart->format('d/m'), 'week' => $key, 'sessions' => 0, 'volume' => 0.0];
    }
    foreach ($rows as $row) {
        try {
            $key = (new DateTimeImmutable((string) $row['started_at']))->format('o-\WW');
        } catch (Throwable) {
            continue;
        }
        if (isset($buckets[$key])) {
            $buckets[$key]['sessions']++;
            $buckets[$key]['volume'] += (float) $row['volume'];
        }
    }

    return array_values($buckets);
}

/** Current streak: consecutive days (ending today or yesterday) with a session. */
function wk_streak_days(PDO $pdo, int $userId): int
{
    $rows = db_fetch_all(
        $pdo,
        'SELECT DISTINCT DATE(started_at) AS d FROM workout_sessions
         WHERE user_id = :u AND status = \'completed\' ORDER BY d DESC LIMIT 400',
        [':u' => $userId]
    );
    if ($rows === []) {
        return 0;
    }
    $days = array_map(static fn($r) => (string) $r['d'], $rows);
    $today = new DateTimeImmutable('today');
    $expected = $today;
    // Allow the streak to start today or yesterday.
    if ($days[0] !== $today->format('Y-m-d')) {
        $yesterday = $today->modify('-1 day');
        if ($days[0] !== $yesterday->format('Y-m-d')) {
            return 0;
        }
        $expected = $yesterday;
    }
    $streak = 0;
    foreach ($days as $d) {
        if ($d === $expected->format('Y-m-d')) {
            $streak++;
            $expected = $expected->modify('-1 day');
        } elseif ($d < $expected->format('Y-m-d')) {
            break;
        }
    }

    return $streak;
}

/**
 * Most frequently trained exercises (by number of sessions they appear in).
 *
 * @return array<int,array{name:string,count:int}>
 */
function wk_frequent_exercises(PDO $pdo, int $userId, int $limit = 6): array
{
    return db_fetch_all(
        $pdo,
        'SELECT ed.name AS name, COUNT(DISTINCT se.session_id) AS count
         FROM session_exercises se
         JOIN workout_sessions s ON s.id = se.session_id AND s.user_id = :u AND s.status = \'completed\'
         JOIN exercise_definitions ed ON ed.id = se.exercise_def_id
         GROUP BY se.exercise_def_id
         ORDER BY count DESC, name COLLATE NOCASE ASC
         LIMIT ' . max(1, min(20, $limit)),
        [':u' => $userId]
    );
}

/**
 * Completed-set distribution across muscle groups.
 *
 * @return array<int,array{muscle:string,sets:int}>
 */
function wk_muscle_distribution(PDO $pdo, int $userId): array
{
    $rows = db_fetch_all(
        $pdo,
        'SELECT COALESCE(NULLIF(ed.muscle_group, ""), "other") AS muscle, COUNT(ws.id) AS sets
         FROM workout_sets ws
         JOIN session_exercises se ON se.id = ws.session_exercise_id
         JOIN workout_sessions s ON s.id = se.session_id AND s.user_id = :u AND s.status = \'completed\'
         JOIN exercise_definitions ed ON ed.id = se.exercise_def_id
         WHERE ws.completed = 1
         GROUP BY muscle
         ORDER BY sets DESC',
        [':u' => $userId]
    );

    return array_map(static fn($r) => ['muscle' => (string) $r['muscle'], 'sets' => (int) $r['sets']], $rows);
}

/**
 * Human, data-driven motivational messages. Only surfaced when the underlying
 * data actually supports them (never fabricate).
 *
 * @return array<int,array{icon:string,text:string}>
 */
function wk_motivational_messages(PDO $pdo, int $userId): array
{
    $messages = [];

    $thisWeekStart = (new DateTimeImmutable('monday this week'))->format('Y-m-d 00:00:00');
    $lastWeekStart = (new DateTimeImmutable('monday last week'))->format('Y-m-d 00:00:00');
    $thisWeek = wk_summary_for_user($pdo, $userId, $thisWeekStart)['sessions'];
    $lastWeekRow = db_fetch_one(
        $pdo,
        'SELECT COUNT(*) AS c FROM workout_sessions WHERE user_id = :u AND status = \'completed\' AND started_at >= :a AND started_at < :b',
        [':u' => $userId, ':a' => $lastWeekStart, ':b' => $thisWeekStart]
    );
    $lastWeek = (int) ($lastWeekRow['c'] ?? 0);

    if ($thisWeek > 0 && $thisWeek > $lastWeek) {
        $messages[] = ['icon' => 'trophy', 'text' => t('workouts.msg_more_than_last', ['n' => $thisWeek - $lastWeek])];
    }

    $streak = wk_streak_days($pdo, $userId);
    if ($streak >= 3) {
        $messages[] = ['icon' => 'spark', 'text' => t('workouts.msg_streak', ['n' => $streak])];
    }

    // A PR set within the last 7 days?
    $recentPr = db_fetch_one(
        $pdo,
        'SELECT ed.name AS name FROM personal_records pr
         JOIN exercise_definitions ed ON ed.id = pr.exercise_def_id
         WHERE pr.user_id = :u AND pr.metric = "est_1rm" AND pr.achieved_at >= :since
         ORDER BY pr.achieved_at DESC LIMIT 1',
        [':u' => $userId, ':since' => (new DateTimeImmutable('-7 days'))->format('Y-m-d 00:00:00')]
    );
    if ($recentPr !== null) {
        $messages[] = ['icon' => 'medal', 'text' => t('workouts.msg_new_pr', ['name' => (string) $recentPr['name']])];
    }

    return $messages;
}

/* ---------------------------------------------------------------------------
 * Duel / competition integration (#7)
 * ------------------------------------------------------------------------- */

/** Workout metric keys usable in duels/competitions. */
function wk_versus_metrics(): array
{
    return [
        'wk_sessions' => t('workouts.metric_sessions'),
        'wk_days' => t('workouts.metric_days'),
        'wk_volume' => t('workouts.metric_volume'),
        'wk_improvement' => t('workouts.metric_improvement'),
    ];
}

function wk_versus_format(string $metric, float $value): string
{
    return match ($metric) {
        'wk_volume' => number_format($value, 0, '.', ' ') . ' kg',
        'wk_improvement' => ($value > 0 ? '+' : '') . number_format($value, 1, '.', '') . '%',
        default => number_format($value, 0, '.', ' '),
    };
}

/**
 * Fair, level-independent duel metric (#7): how much a user improved their own
 * training volume during the duel window versus their own equally-long window
 * immediately before it. Someone lifting 40kg and someone lifting 140kg compete
 * on equal terms, because each is measured against their own baseline.
 */
function wk_improvement_pct(PDO $pdo, int $userId, string $start, string $end): float
{
    try {
        $startDt = new DateTimeImmutable($start);
        $endDt = new DateTimeImmutable($end);
    } catch (Throwable) {
        return 0.0;
    }
    $days = max(1, (int) $startDt->diff($endDt)->days + 1);
    $prevEnd = $startDt->modify('-1 day');
    $prevStart = $prevEnd->modify('-' . ($days - 1) . ' day');

    $current = wk_metric_over_range($pdo, $userId, 'wk_volume', $startDt->format('Y-m-d'), $endDt->format('Y-m-d'));
    $previous = wk_metric_over_range($pdo, $userId, 'wk_volume', $prevStart->format('Y-m-d'), $prevEnd->format('Y-m-d'));

    if ($previous <= 0.0) {
        // No baseline to improve on: reward showing up, but don't let a single
        // first session yield an infinite percentage that trivially wins.
        return $current > 0.0 ? 100.0 : 0.0;
    }

    return round((($current - $previous) / $previous) * 100, 1);
}

/**
 * Value of a workout versus-metric for one user over [start, end] (inclusive
 * dates). Defensive: returns 0 if the workout schema isn't present yet.
 */
function wk_metric_over_range(PDO $pdo, int $userId, string $metric, string $start, string $end): float
{
    // Relative metric: delegates to its own baseline comparison.
    if ($metric === 'wk_improvement') {
        return wk_improvement_pct($pdo, $userId, $start, $end);
    }

    $startTs = $start . ' 00:00:00';
    $endTs = $end . ' 23:59:59';
    try {
        if ($metric === 'wk_days') {
            $row = db_fetch_one(
                $pdo,
                'SELECT COUNT(DISTINCT DATE(started_at)) AS v FROM workout_sessions
                 WHERE user_id = :u AND status = \'completed\' AND started_at BETWEEN :a AND :b',
                [':u' => $userId, ':a' => $startTs, ':b' => $endTs]
            );

            return (float) ($row['v'] ?? 0);
        }
        if ($metric === 'wk_sessions') {
            $row = db_fetch_one(
                $pdo,
                'SELECT COUNT(*) AS v FROM workout_sessions
                 WHERE user_id = :u AND status = \'completed\' AND started_at BETWEEN :a AND :b',
                [':u' => $userId, ':a' => $startTs, ':b' => $endTs]
            );

            return (float) ($row['v'] ?? 0);
        }
        if ($metric === 'wk_volume') {
            $row = db_fetch_one(
                $pdo,
                'SELECT COALESCE(SUM(CASE WHEN ws.completed = 1 AND ws.weight IS NOT NULL AND ws.reps IS NOT NULL THEN ws.weight * ws.reps ELSE 0 END), 0) AS v
                 FROM workout_sessions s
                 JOIN session_exercises se ON se.session_id = s.id
                 JOIN workout_sets ws ON ws.session_exercise_id = se.id
                 WHERE s.user_id = :u AND s.status = \'completed\' AND s.started_at BETWEEN :a AND :b',
                [':u' => $userId, ':a' => $startTs, ':b' => $endTs]
            );

            return (float) ($row['v'] ?? 0);
        }
    } catch (Throwable) {
        return 0.0;
    }

    return 0.0;
}

/**
 * Render the <optgroup> of a user's routines for the daily-log "Workout type"
 * selector (#13). Values are "routine:<id>"; normalize_workout_row() resolves
 * them server-side to the routine name. Favourites are starred; archived
 * routines are grouped separately so they stay reachable but out of the way.
 *
 * @param array<int,array<string,mixed>> $routines
 */
function wk_routine_options_html(array $routines, string $selectedValue = ''): string
{
    $active = [];
    $archived = [];
    foreach ($routines as $routine) {
        if ((int) ($routine['is_archived'] ?? 0) === 1) {
            $archived[] = $routine;
        } else {
            $active[] = $routine;
        }
    }

    $renderGroup = static function (array $rows, string $label) use ($selectedValue): string {
        if ($rows === []) {
            return '';
        }
        $html = '<optgroup label="' . e($label) . '">';
        foreach ($rows as $routine) {
            $value = 'routine:' . (int) ($routine['id'] ?? 0);
            $name = (string) ($routine['name'] ?? '');
            if ((int) ($routine['is_favorite'] ?? 0) === 1) {
                $name = '★ ' . $name;
            }
            $html .= '<option value="' . e($value) . '"' . ($selectedValue === $value ? ' selected' : '') . '>' . e($name) . '</option>';
        }

        return $html . '</optgroup>';
    };

    return $renderGroup($active, t('workouts.my_routines')) . $renderGroup($archived, t('workouts.archived'));
}
