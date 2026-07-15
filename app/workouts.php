<?php

declare(strict_types=1);

require_once __DIR__ . '/workout_catalog.php';

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
            visual_mark TEXT NOT NULL DEFAULT "",
            accent_color TEXT NOT NULL DEFAULT "#14b8a6",
            cover_mode TEXT NOT NULL DEFAULT "auto",
            image_position TEXT NOT NULL DEFAULT "center",
            default_sets INTEGER,
            default_reps INTEGER,
            default_weight REAL,
            default_duration INTEGER,
            default_distance REAL,
            default_rest_seconds INTEGER,
            default_unit TEXT,
            default_notes TEXT NOT NULL DEFAULT "",
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
            accent_color TEXT NOT NULL DEFAULT "#14b8a6",
            image_path TEXT,
            video_url TEXT,
            cover_mode TEXT NOT NULL DEFAULT "auto",
            image_position TEXT NOT NULL DEFAULT "center",
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
            rest_seconds INTEGER,
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

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS workout_exercise_preferences (
            user_id INTEGER NOT NULL,
            exercise_def_id INTEGER NOT NULL,
            is_favorite INTEGER NOT NULL DEFAULT 0,
            sort_order INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT NOT NULL,
            PRIMARY KEY (user_id, exercise_def_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (exercise_def_id) REFERENCES exercise_definitions(id) ON DELETE CASCADE
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS exercise_media (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            exercise_def_id INTEGER NOT NULL,
            file_path TEXT NOT NULL,
            image_position TEXT NOT NULL DEFAULT "center",
            caption TEXT NOT NULL DEFAULT "",
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            UNIQUE (exercise_def_id, file_path),
            FOREIGN KEY (exercise_def_id) REFERENCES exercise_definitions(id) ON DELETE CASCADE
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS workout_rank_tiers (
            tier_key TEXT PRIMARY KEY,
            threshold REAL NOT NULL,
            color TEXT NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            active INTEGER NOT NULL DEFAULT 1,
            updated_at TEXT NOT NULL
        )'
    );

    // Older installations already have these tables. Keep the migration local
    // to the workouts module so opening Training upgrades the feature safely.
    ensure_column($pdo, 'exercise_definitions', 'slug', 'TEXT');
    ensure_column($pdo, 'exercise_definitions', 'secondary_muscles', 'TEXT NOT NULL DEFAULT "[]"');
    ensure_column($pdo, 'exercise_definitions', 'difficulty', 'TEXT NOT NULL DEFAULT "beginner"');
    ensure_column($pdo, 'exercise_definitions', 'guide_json', 'TEXT NOT NULL DEFAULT "{}"');
    ensure_column($pdo, 'exercise_definitions', 'rank_factor', 'REAL NOT NULL DEFAULT 1');
    ensure_column($pdo, 'exercise_definitions', 'rankable', 'INTEGER NOT NULL DEFAULT 1');
    ensure_column($pdo, 'exercise_definitions', 'sort_order', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'exercise_definitions', 'image_path', 'TEXT');
    ensure_column($pdo, 'exercise_definitions', 'video_url', 'TEXT');
    ensure_column($pdo, 'exercise_definitions', 'visual_mark', 'TEXT NOT NULL DEFAULT ""');
    ensure_column($pdo, 'exercise_definitions', 'accent_color', 'TEXT NOT NULL DEFAULT "#14b8a6"');
    ensure_column($pdo, 'exercise_definitions', 'cover_mode', 'TEXT NOT NULL DEFAULT "auto"');
    ensure_column($pdo, 'exercise_definitions', 'image_position', 'TEXT NOT NULL DEFAULT "center"');
    ensure_column($pdo, 'exercise_definitions', 'default_sets', 'INTEGER');
    ensure_column($pdo, 'exercise_definitions', 'default_reps', 'INTEGER');
    ensure_column($pdo, 'exercise_definitions', 'default_weight', 'REAL');
    ensure_column($pdo, 'exercise_definitions', 'default_duration', 'INTEGER');
    ensure_column($pdo, 'exercise_definitions', 'default_distance', 'REAL');
    ensure_column($pdo, 'exercise_definitions', 'default_rest_seconds', 'INTEGER');
    ensure_column($pdo, 'exercise_definitions', 'default_unit', 'TEXT');
    ensure_column($pdo, 'exercise_definitions', 'default_notes', 'TEXT NOT NULL DEFAULT ""');
    ensure_column($pdo, 'exercise_definitions', 'active', 'INTEGER NOT NULL DEFAULT 1');
    ensure_column($pdo, 'exercise_definitions', 'admin_override', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'exercise_definitions', 'source_exercise_id', 'INTEGER');
    ensure_column($pdo, 'exercise_media', 'caption', 'TEXT NOT NULL DEFAULT ""');
    ensure_column($pdo, 'workout_routines', 'recommended_days_mask', 'TEXT NOT NULL DEFAULT "0000000"');
    ensure_column($pdo, 'workout_routines', 'accent_color', 'TEXT NOT NULL DEFAULT "#14b8a6"');
    ensure_column($pdo, 'workout_routines', 'image_path', 'TEXT');
    ensure_column($pdo, 'workout_routines', 'video_url', 'TEXT');
    ensure_column($pdo, 'workout_routines', 'cover_mode', 'TEXT NOT NULL DEFAULT "auto"');
    ensure_column($pdo, 'workout_routines', 'image_position', 'TEXT NOT NULL DEFAULT "center"');
    ensure_column($pdo, 'session_exercises', 'rest_seconds', 'INTEGER');

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_wk_routines_user ON workout_routines(user_id, is_archived, sort_order)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_wk_sessions_user ON workout_sessions(user_id, status, started_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_wk_pr_user ON personal_records(user_id, exercise_def_id)');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_wk_exercise_slug ON exercise_definitions(slug) WHERE slug IS NOT NULL AND slug != ""');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_wk_exercise_muscle ON exercise_definitions(muscle_group, is_system, sort_order)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_wk_exercise_preferences_favorite ON workout_exercise_preferences(user_id, is_favorite, sort_order)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_wk_exercise_media_order ON exercise_media(exercise_def_id, sort_order, id)');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_wk_personal_source_active ON exercise_definitions(user_id, source_exercise_id) WHERE source_exercise_id IS NOT NULL AND COALESCE(active, 1) = 1');

    workouts_seed_system_exercises($pdo);
    workouts_seed_rank_tiers($pdo);
}

/** Seed and update the offline catalogue without touching personal exercises. */
function workouts_seed_system_exercises(PDO $pdo): void
{
    $now = now_iso();
    $legacyNames = [
        'bench_press' => 'Bench Press', 'back_squat' => 'Squat', 'deadlift' => 'Deadlift',
        'overhead_press' => 'Overhead Press', 'pull_up' => 'Pull Up', 'push_up' => 'Push Up',
        'barbell_row' => 'Barbell Row', 'dumbbell_curl' => 'Bicep Curl',
        'overhead_triceps_extension' => 'Tricep Extension', 'reverse_lunge' => 'Lunge',
        'plank' => 'Plank', 'running' => 'Running', 'cycling' => 'Cycling',
        'rowing_ergometer' => 'Rowing',
    ];

    foreach (array_values(wk_builtin_exercise_catalog()) as $order => $item) {
        $slug = (string) $item['slug'];
        $name = (string) (($item['names']['en'] ?? '') ?: $slug);
        $existing = db_fetch_one($pdo, 'SELECT id FROM exercise_definitions WHERE slug = :slug LIMIT 1', [':slug' => $slug]);
        if ($existing === null) {
            $legacy = (string) ($legacyNames[$slug] ?? $name);
            $existing = db_fetch_one(
                $pdo,
                'SELECT id FROM exercise_definitions WHERE is_system = 1 AND LOWER(name) = LOWER(:name) LIMIT 1',
                [':name' => $legacy]
            );
        }

        $params = [
            ':slug' => $slug,
            ':name' => $name,
            ':muscle' => (string) ($item['muscle'] ?? ''),
            ':secondary' => json_encode(array_values((array) ($item['secondary'] ?? [])), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':type' => (string) ($item['type'] ?? 'strength'),
            ':equipment' => (string) ($item['equipment'] ?? ''),
            ':difficulty' => (string) ($item['difficulty'] ?? 'beginner'),
            ':visual_mark' => wk_exercise_default_mark($item['muscle'] ?? ''),
            ':accent' => wk_exercise_default_color($item['muscle'] ?? ''),
            ':guide' => json_encode(['names' => (array) ($item['names'] ?? []), 'guides' => (array) ($item['guide'] ?? [])], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':factor' => max(0.01, (float) ($item['rank_factor'] ?? 1.0)),
            ':rankable' => ($item['rankable'] ?? true) ? 1 : 0,
            ':sort' => $order + 1,
            ':now' => $now,
        ];

        if ($existing !== null) {
            $params[':id'] = (int) $existing['id'];
            db_execute(
                $pdo,
                'UPDATE exercise_definitions
                 SET slug = :slug, name = :name, muscle_group = :muscle, secondary_muscles = :secondary,
                     exercise_type = :type, equipment = :equipment, difficulty = :difficulty,
                     guide_json = :guide, rank_factor = :factor, rankable = :rankable,
                     visual_mark = :visual_mark, accent_color = :accent, sort_order = :sort, is_system = 1, updated_at = :now
                 WHERE id = :id AND COALESCE(admin_override, 0) = 0',
                $params
            );
            continue;
        }

        db_execute(
            $pdo,
            'INSERT INTO exercise_definitions (
                user_id, slug, name, muscle_group, secondary_muscles, exercise_type, equipment,
                difficulty, visual_mark, accent_color, guide_json, rank_factor, rankable, sort_order, is_system, created_at, updated_at
             ) VALUES (
                NULL, :slug, :name, :muscle, :secondary, :type, :equipment,
                :difficulty, :visual_mark, :accent, :guide, :factor, :rankable, :sort, 1, :now, :now
             )',
            $params
        );
    }
}

/** @return array<string,array{threshold:float,color:string,sort_order:int}> */
function wk_default_rank_tiers(): array
{
    return [
        'unranked' => ['threshold' => 0.0, 'color' => '#64748b', 'sort_order' => 0],
        'rookie' => ['threshold' => 1.0, 'color' => '#94a3b8', 'sort_order' => 10],
        'bronze' => ['threshold' => 25.0, 'color' => '#b66a3c', 'sort_order' => 20],
        'silver' => ['threshold' => 45.0, 'color' => '#a8b2bd', 'sort_order' => 30],
        'gold' => ['threshold' => 70.0, 'color' => '#d6a928', 'sort_order' => 40],
        'platinum' => ['threshold' => 100.0, 'color' => '#2fb5a5', 'sort_order' => 50],
        'diamond' => ['threshold' => 140.0, 'color' => '#4f8ff7', 'sort_order' => 60],
        'elite' => ['threshold' => 180.0, 'color' => '#9b6df3', 'sort_order' => 70],
    ];
}

function workouts_seed_rank_tiers(PDO $pdo): void
{
    foreach (wk_default_rank_tiers() as $key => $tier) {
        db_execute(
            $pdo,
            'INSERT OR IGNORE INTO workout_rank_tiers (tier_key, threshold, color, sort_order, active, updated_at)
             VALUES (:key, :threshold, :color, :sort_order, 1, :updated_at)',
            [
                ':key' => $key,
                ':threshold' => (float) $tier['threshold'],
                ':color' => (string) $tier['color'],
                ':sort_order' => (int) $tier['sort_order'],
                ':updated_at' => now_iso(),
            ]
        );
    }
}

/* ---------------------------------------------------------------------------
 * Exercise definitions
 * ------------------------------------------------------------------------- */

/** @return array<int,array<string,mixed>> System + this user's exercises. */
function wk_exercises_for_user(PDO $pdo, int $userId): array
{
    $rows = db_fetch_all(
        $pdo,
        'SELECT ed.*,
                COALESCE(pref.is_favorite, 0) AS is_favorite,
                COALESCE(pref.sort_order, 0) AS preference_order
         FROM exercise_definitions ed
         LEFT JOIN workout_exercise_preferences pref
           ON pref.exercise_def_id = ed.id AND pref.user_id = :u
         WHERE COALESCE(ed.active, 1) = 1 AND (ed.is_system = 1 OR ed.user_id = :u)
         ORDER BY COALESCE(pref.is_favorite, 0) DESC,
                  CASE WHEN COALESCE(pref.sort_order, 0) > 0 THEN pref.sort_order ELSE 999999 END ASC,
                  ed.is_system DESC, ed.sort_order ASC, ed.name COLLATE NOCASE ASC',
        [':u' => $userId]
    );
    foreach ($rows as &$row) {
        $row['content'] = wk_exercise_content($row);
        $row['display_name'] = (string) ($row['content']['name'] ?? $row['name']);
    }
    unset($row);

    return $rows;
}

/** @return array<int,array<string,mixed>> */
function wk_admin_exercises(PDO $pdo): array
{
    $rows = db_fetch_all(
        $pdo,
        'SELECT ed.*, u.display_name AS owner_name,
                (SELECT COUNT(*) FROM routine_exercises re WHERE re.exercise_def_id = ed.id) AS routine_uses,
                (SELECT COUNT(*) FROM session_exercises se WHERE se.exercise_def_id = ed.id) AS session_uses
         FROM exercise_definitions ed
         LEFT JOIN users u ON u.id = ed.user_id
         ORDER BY COALESCE(ed.active, 1) DESC, ed.is_system DESC, ed.sort_order ASC, ed.name COLLATE NOCASE ASC'
    );
    foreach ($rows as &$row) {
        $row['content'] = wk_exercise_content($row, 'en');
    }
    unset($row);

    return $rows;
}

function wk_normalize_exercise_video_url(mixed $value): ?string
{
    $url = trim((string) $value);
    if ($url === '') {
        return null;
    }
    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        throw new InvalidArgumentException(t('workouts.invalid_video_url'));
    }
    $parts = parse_url($url);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true) || trim((string) ($parts['host'] ?? '')) === '') {
        throw new InvalidArgumentException(t('workouts.invalid_video_url'));
    }

    return $url;
}

function wk_normalize_exercise_cover_mode(mixed $value): string
{
    $mode = strtolower(trim((string) $value));

    return in_array($mode, ['auto', 'photo', 'video', 'simple'], true) ? $mode : 'auto';
}

function wk_normalize_image_position(mixed $value): string
{
    $position = strtolower(trim((string) $value));

    if (preg_match('/^focal:(\d{1,3}):(\d{1,3})$/D', $position, $matches) === 1) {
        $x = (int) $matches[1];
        $y = (int) $matches[2];
        if ($x >= 0 && $x <= 100 && $y >= 0 && $y <= 100) {
            return 'focal:' . $x . ':' . $y;
        }
    }

    return in_array($position, ['center', 'top', 'bottom', 'left', 'right'], true) ? $position : 'center';
}

/** @return array{x:int,y:int} */
function wk_image_position_coordinates(mixed $value): array
{
    $position = wk_normalize_image_position($value);
    if (preg_match('/^focal:(\d{1,3}):(\d{1,3})$/D', $position, $matches) === 1) {
        return ['x' => (int) $matches[1], 'y' => (int) $matches[2]];
    }

    return match ($position) {
        'top' => ['x' => 50, 'y' => 18],
        'bottom' => ['x' => 50, 'y' => 82],
        'left' => ['x' => 18, 'y' => 50],
        'right' => ['x' => 82, 'y' => 50],
        default => ['x' => 50, 'y' => 50],
    };
}

function wk_image_position_css(mixed $value): string
{
    $coordinates = wk_image_position_coordinates($value);

    return $coordinates['x'] . '% ' . $coordinates['y'] . '%';
}

/**
 * Return the ordered photo gallery for an exercise. Legacy rows with only an
 * image_path transparently become a one-photo gallery.
 *
 * @param array<string,mixed>|int $exercise
 * @return array<int,array{path:string,position:string,caption:string,is_cover:bool}>
 */
/**
 * Load ordered exercise galleries in one query for controller/view models.
 *
 * @param array<int,array<string,mixed>> $exercises
 * @return array<int,array<int,array{path:string,position:string,caption:string,is_cover:bool}>>
 */
function wk_normalize_exercise_media_caption(mixed $caption): string
{
    if (!is_scalar($caption)) {
        return '';
    }
    $normalized = trim((string) preg_replace('/\s+/u', ' ', (string) $caption));

    return function_exists('mb_substr') ? mb_substr($normalized, 0, 120) : substr($normalized, 0, 120);
}

function wk_exercise_media_map(PDO $pdo, array $exercises): array
{
    $exerciseRows = [];
    $params = [];
    foreach ($exercises as $row) {
        $exerciseId = (int) ($row['id'] ?? 0);
        if ($exerciseId <= 0 || isset($exerciseRows[$exerciseId])) {
            continue;
        }
        $exerciseRows[$exerciseId] = $row;
        $params[':exercise_' . count($params)] = $exerciseId;
    }
    if ($exerciseRows === []) {
        return [];
    }

    $mediaRows = db_fetch_all(
        $pdo,
        'SELECT exercise_def_id, file_path, image_position, caption
         FROM exercise_media
         WHERE exercise_def_id IN (' . implode(', ', array_keys($params)) . ')
         ORDER BY exercise_def_id ASC, sort_order ASC, id ASC',
        $params
    );
    $rowsByExercise = [];
    foreach ($mediaRows as $mediaRow) {
        $rowsByExercise[(int) ($mediaRow['exercise_def_id'] ?? 0)][] = $mediaRow;
    }

    $map = [];
    foreach ($exerciseRows as $exerciseId => $exerciseRow) {
        $coverPath = trim((string) ($exerciseRow['image_path'] ?? ''));
        $fallbackPosition = wk_normalize_image_position($exerciseRow['image_position'] ?? 'center');
        $items = [];
        $seen = [];
        foreach ((array) ($rowsByExercise[$exerciseId] ?? []) as $mediaRow) {
            $path = trim((string) ($mediaRow['file_path'] ?? ''));
            if ($path === '' || isset($seen[$path]) || count($items) >= 4) {
                continue;
            }
            $seen[$path] = true;
            $items[] = [
                'path' => $path,
                'position' => wk_normalize_image_position($mediaRow['image_position'] ?? $fallbackPosition),
                'caption' => wk_normalize_exercise_media_caption($mediaRow['caption'] ?? ''),
                'is_cover' => $path === $coverPath,
            ];
        }
        if ($coverPath !== '' && !isset($seen[$coverPath])) {
            array_unshift($items, [
                'path' => $coverPath,
                'position' => $fallbackPosition,
                'caption' => '',
                'is_cover' => true,
            ]);
        }
        $map[$exerciseId] = array_slice($items, 0, 4);
    }

    return $map;
}

function wk_exercise_media_list(PDO $pdo, array|int $exercise): array
{
    $exerciseRow = is_array($exercise) ? $exercise : wk_exercise_get($pdo, $exercise);
    $exerciseId = (int) ($exerciseRow['id'] ?? (is_int($exercise) ? $exercise : 0));
    if ($exerciseId <= 0) {
        $coverPath = trim((string) ($exerciseRow['image_path'] ?? ''));
        return $coverPath === '' ? [] : [[
            'path' => $coverPath,
            'position' => wk_normalize_image_position($exerciseRow['image_position'] ?? 'center'),
            'caption' => '',
            'is_cover' => true,
        ]];
    }
    $map = wk_exercise_media_map($pdo, [$exerciseRow]);

    return $map[$exerciseId] ?? [];
}

/** @param array<int,array<string,mixed>|string> $items */
function wk_exercise_media_replace(PDO $pdo, int $exerciseId, array $items): void
{
    if ($exerciseId <= 0) {
        throw new InvalidArgumentException(t('workouts.custom_not_found'));
    }
    $normalized = [];
    $seen = [];
    foreach ($items as $item) {
        $path = trim((string) (is_array($item) ? ($item['path'] ?? '') : $item));
        if ($path === '' || isset($seen[$path])) {
            continue;
        }
        $seen[$path] = true;
        $normalized[] = [
            'path' => $path,
            'position' => wk_normalize_image_position(is_array($item) ? ($item['position'] ?? 'center') : 'center'),
            'caption' => wk_normalize_exercise_media_caption(is_array($item) ? ($item['caption'] ?? '') : ''),
        ];
        if (count($normalized) >= 4) {
            break;
        }
    }

    db_execute($pdo, 'DELETE FROM exercise_media WHERE exercise_def_id = :exercise', [':exercise' => $exerciseId]);
    foreach ($normalized as $index => $item) {
        db_execute(
            $pdo,
            'INSERT INTO exercise_media (exercise_def_id, file_path, image_position, caption, sort_order, created_at)
             VALUES (:exercise, :path, :position, :caption, :sort, :created)',
            [
                ':exercise' => $exerciseId,
                ':path' => $item['path'],
                ':position' => $item['position'],
                ':caption' => $item['caption'],
                ':sort' => $index + 1,
                ':created' => now_iso(),
            ]
        );
    }
}

/**
 * Resolve submitted gallery tokens against paths already owned by the exercise
 * and freshly uploaded paths. Arbitrary client paths are never accepted.
 *
 * @param array<int,array<string,mixed>> $existing
 * @param array<int,mixed> $order
 * @param array<int,string> $newPaths
 * @param array<int,mixed> $positions Positions aligned with the submitted order.
 * @param array<int,mixed> $captions Captions aligned with the submitted order.
 * @return array{items:array<int,array{path:string,position:string,caption:string}>,cover_path:string,cover_position:string}
 */
function wk_exercise_media_resolve_submission(
    array $existing,
    array $order,
    array $newPaths,
    mixed $coverToken,
    mixed $imagePosition = 'center',
    array $positions = [],
    array $captions = []
): array {
    $position = wk_normalize_image_position(is_scalar($imagePosition) ? $imagePosition : 'center');
    $available = [];
    foreach ($existing as $item) {
        $path = trim((string) ($item['path'] ?? ''));
        if ($path !== '') {
            $available[$path] = [
                'path' => $path,
                'position' => wk_normalize_image_position($item['position'] ?? $position),
                'caption' => wk_normalize_exercise_media_caption($item['caption'] ?? ''),
            ];
        }
    }
    foreach (array_values($newPaths) as $index => $path) {
        $path = trim((string) $path);
        if ($path !== '') {
            $available['new:' . $index] = ['path' => $path, 'position' => $position, 'caption' => ''];
        }
    }

    $tokens = [];
    foreach ($order as $orderIndex => $rawToken) {
        if (is_array($rawToken) || is_object($rawToken) || is_resource($rawToken)) {
            continue;
        }
        $token = trim((string) $rawToken);
        if ($token !== '' && isset($available[$token]) && !in_array($token, $tokens, true)) {
            $submittedPosition = $positions[$orderIndex] ?? null;
            if (is_scalar($submittedPosition) && trim((string) $submittedPosition) !== '') {
                $available[$token]['position'] = wk_normalize_image_position($submittedPosition);
            }
            $available[$token]['caption'] = wk_normalize_exercise_media_caption($captions[$orderIndex] ?? '');
            $tokens[] = $token;
        }
        if (count($tokens) >= 4) {
            break;
        }
    }
    foreach ($available as $token => $_item) {
        $token = (string) $token;
        if (str_starts_with($token, 'new:') && !in_array($token, $tokens, true) && count($tokens) < 4) {
            $tokens[] = $token;
        }
    }

    $requestedCover = is_scalar($coverToken) ? trim((string) $coverToken) : '';
    $coverIndex = array_search($requestedCover, $tokens, true);
    if ($coverIndex === false && $tokens !== []) {
        $coverIndex = 0;
    }
    if (is_int($coverIndex) && $coverIndex > 0) {
        $cover = $tokens[$coverIndex];
        array_splice($tokens, $coverIndex, 1);
        array_unshift($tokens, $cover);
    }
    $items = array_map(static fn(string $token): array => $available[$token], $tokens);

    return [
        'items' => $items,
        'cover_path' => trim((string) ($items[0]['path'] ?? '')),
        'cover_position' => wk_normalize_image_position($items[0]['position'] ?? $position),
    ];
}

/** @param array<int,string> $paths */
function wk_exercise_media_cleanup_unreferenced(PDO $pdo, array $config, array $paths): void
{
    foreach (array_values(array_unique(array_map('strval', $paths))) as $path) {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '' || !str_starts_with($path, 'workouts/exercises/')) {
            continue;
        }
        $referenced = db_fetch_one(
            $pdo,
            'SELECT 1 AS found FROM exercise_definitions WHERE image_path = :definition_path
             UNION ALL SELECT 1 FROM exercise_media WHERE file_path = :media_path
             UNION ALL SELECT 1 FROM workout_routines WHERE image_path = :routine_path
             LIMIT 1',
            [
                ':definition_path' => $path,
                ':media_path' => $path,
                ':routine_path' => $path,
            ]
        );
        if ($referenced !== null) {
            continue;
        }
        $absolute = resolve_media_storage_path($config, $path);
        if ($absolute !== null && is_file($absolute)) {
            @unlink($absolute);
        }
    }
}

/**
 * Resolve the reusable training prescription attached to an exercise.
 *
 * Legacy catalogue rows have nullable columns, so type-aware fallbacks keep
 * old exercises useful while personal/admin exercises can persist every value.
 * A stored rest value of 0 explicitly means "no rest target".
 *
 * @param array<string,mixed> $exercise
 * @return array{target_sets:int,target_reps:?int,target_weight:?float,target_duration:?int,target_distance:?float,rest_seconds:?int,stored_rest_seconds:int,unit:string,notes:string}
 */
function wk_exercise_training_defaults(array $exercise): array
{
    $type = in_array((string) ($exercise['exercise_type'] ?? 'strength'), WK_EXERCISE_TYPES, true)
        ? (string) $exercise['exercise_type']
        : 'strength';
    $fallback = match ($type) {
        'cardio' => ['sets' => 1, 'reps' => null, 'duration' => 1200, 'rest' => 0],
        'isometric' => ['sets' => 3, 'reps' => null, 'duration' => 30, 'rest' => 60],
        'bodyweight' => ['sets' => 3, 'reps' => 10, 'duration' => null, 'rest' => 60],
        'freeform' => ['sets' => 3, 'reps' => 10, 'duration' => null, 'rest' => 60],
        default => ['sets' => 3, 'reps' => 10, 'duration' => null, 'rest' => 90],
    };
    $hasValue = static fn(mixed $value): bool => $value !== null && trim((string) $value) !== '';
    $clampInt = static fn(mixed $value, int $minimum, int $maximum): int => max($minimum, min($maximum, (int) $value));
    $clampFloat = static fn(mixed $value, float $minimum, float $maximum): float => max($minimum, min($maximum, (float) $value));

    $sets = $hasValue($exercise['default_sets'] ?? null)
        ? $clampInt($exercise['default_sets'], 1, 20)
        : (int) $fallback['sets'];
    $reps = $hasValue($exercise['default_reps'] ?? null)
        ? $clampInt($exercise['default_reps'], 0, 999)
        : ($fallback['reps'] !== null ? (int) $fallback['reps'] : null);
    $weight = $hasValue($exercise['default_weight'] ?? null)
        ? $clampFloat($exercise['default_weight'], 0.0, 99999.0)
        : null;
    $distance = $hasValue($exercise['default_distance'] ?? null)
        ? $clampFloat($exercise['default_distance'], 0.0, 99999.0)
        : null;

    $duration = null;
    if ($type === 'cardio' && $hasValue($exercise['default_duration_minutes'] ?? null)) {
        $duration = $clampInt((int) round((float) $exercise['default_duration_minutes'] * 60), 0, 86400);
    } elseif ($type === 'isometric' && $hasValue($exercise['default_duration_seconds'] ?? null)) {
        $duration = $clampInt($exercise['default_duration_seconds'], 0, 86400);
    } elseif ($hasValue($exercise['default_duration'] ?? null)) {
        $duration = $clampInt($exercise['default_duration'], 0, 86400);
    } elseif ($fallback['duration'] !== null) {
        $duration = (int) $fallback['duration'];
    }

    $storedRest = $hasValue($exercise['default_rest_seconds'] ?? null)
        ? $clampInt($exercise['default_rest_seconds'], 0, 3600)
        : (int) $fallback['rest'];
    $unit = in_array((string) ($exercise['default_unit'] ?? ''), WK_UNITS, true)
        ? (string) $exercise['default_unit']
        : 'kg';
    $notes = trim((string) ($exercise['default_notes'] ?? ''));
    $notes = function_exists('mb_substr') ? mb_substr($notes, 0, 500) : substr($notes, 0, 500);

    return [
        'target_sets' => $sets,
        'target_reps' => in_array($type, ['cardio', 'isometric'], true) ? null : $reps,
        'target_weight' => $type === 'cardio' ? null : $weight,
        'target_duration' => in_array($type, ['cardio', 'isometric'], true) ? $duration : null,
        'target_distance' => $type === 'cardio' ? $distance : null,
        'rest_seconds' => $storedRest > 0 ? $storedRest : null,
        'stored_rest_seconds' => $storedRest,
        'unit' => $unit,
        'notes' => $notes,
    ];
}

function wk_normalize_exercise_color(mixed $color): string
{
    return wk_normalize_routine_color($color);
}

function wk_exercise_default_color(mixed $muscleGroup): string
{
    return match (trim((string) $muscleGroup)) {
        'chest' => '#ef4444',
        'back' => '#3b82f6',
        'shoulders' => '#8b5cf6',
        'quads' => '#22c55e',
        'hamstrings' => '#14b8a6',
        'glutes' => '#ec4899',
        'biceps' => '#f97316',
        'triceps' => '#f59e0b',
        'core' => '#64748b',
        'calves' => '#06b6d4',
        'cardio' => '#f43f5e',
        default => '#14b8a6',
    };
}

/** @return array<string,string> */
function wk_exercise_mark_options(): array
{
    return [
        'strength' => '💪',
        'power' => '⚡',
        'run' => '🏃',
        'mobility' => '🧘',
        'target' => '🎯',
        'fire' => '🔥',
        'heart' => '❤',
        'star' => '★',
    ];
}

function wk_normalize_exercise_mark(mixed $mark): string
{
    $value = trim((string) $mark);
    if ($value === '') {
        return '';
    }
    $value = preg_replace('/[\x00-\x1F\x7F<>&]/u', '', $value) ?? '';
    if ($value === '' || preg_match_all('/\X/u', $value, $graphemes) === false) {
        return '';
    }

    return implode('', array_slice((array) ($graphemes[0] ?? []), 0, 3));
}

function wk_exercise_default_mark(mixed $muscleGroup): string
{
    return match (trim((string) $muscleGroup)) {
        'chest', 'biceps' => '💪',
        'back' => '↔',
        'shoulders' => '🏋',
        'quads', 'hamstrings', 'calves' => '🦵',
        'glutes' => '◆',
        'triceps' => '⚡',
        'core' => '🎯',
        'cardio' => '🏃',
        default => '•',
    };
}

/** @param array<string,mixed> $exercise */
function wk_exercise_visual_mark(array $exercise): string
{
    $mark = wk_normalize_exercise_mark($exercise['visual_mark'] ?? '');

    return $mark !== '' ? $mark : wk_exercise_default_mark($exercise['muscle_group'] ?? '');
}

/**
 * Parse exercise media once for guides, cards and previews. Unknown HTTPS URLs
 * stay usable as links, while known providers receive privacy-aware embeds.
 *
 * @return array{type:string,provider:string,url:string,thumbnail_url?:string}|null
 */
function wk_exercise_video_source(mixed $rawValue): ?array
{
    $url = trim((string) $rawValue);
    if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return null;
    }
    $parts = parse_url($url);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return null;
    }
    $host = strtolower((string) ($parts['host'] ?? ''));
    $host = preg_replace('/^www\./', '', $host) ?? $host;
    $path = trim((string) ($parts['path'] ?? ''), '/');
    $youtubeId = '';
    if ($host === 'youtu.be') {
        $youtubeId = explode('/', $path)[0] ?? '';
    } elseif (in_array($host, ['youtube.com', 'm.youtube.com', 'youtube-nocookie.com'], true)) {
        parse_str((string) ($parts['query'] ?? ''), $query);
        $youtubeId = trim((string) ($query['v'] ?? ''));
        if ($youtubeId === '' && preg_match('~^(?:shorts|embed|live)/([A-Za-z0-9_-]{6,20})~', $path, $match) === 1) {
            $youtubeId = (string) $match[1];
        }
    }
    if (preg_match('/^[A-Za-z0-9_-]{6,20}$/', $youtubeId) === 1) {
        $safeId = rawurlencode($youtubeId);

        return [
            'type' => 'iframe',
            'provider' => 'youtube',
            'url' => 'https://www.youtube-nocookie.com/embed/' . $safeId,
            'thumbnail_url' => 'https://i.ytimg.com/vi/' . $safeId . '/hqdefault.jpg',
        ];
    }
    if (in_array($host, ['vimeo.com', 'player.vimeo.com'], true)
        && preg_match('~(?:video/)?([0-9]{5,12})~', $path, $match) === 1) {
        return [
            'type' => 'iframe',
            'provider' => 'vimeo',
            'url' => 'https://player.vimeo.com/video/' . rawurlencode((string) $match[1]),
        ];
    }
    $extension = strtolower(pathinfo((string) ($parts['path'] ?? ''), PATHINFO_EXTENSION));
    if (in_array($extension, ['mp4', 'webm', 'ogv', 'ogg'], true)) {
        return ['type' => 'video', 'provider' => 'direct', 'url' => $url];
    }

    return ['type' => 'link', 'provider' => 'link', 'url' => $url];
}

/** @return array<int,string> */
function wk_exercise_guide_lines(mixed $value, int $limit = 20): array
{
    $rawItems = is_array($value) ? $value : [$value];
    $lines = [];
    foreach ($rawItems as $rawItem) {
        if (is_array($rawItem) || is_object($rawItem) || is_resource($rawItem)) {
            continue;
        }
        foreach (preg_split('/\R+/', (string) $rawItem) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            $lines[] = function_exists('mb_substr') ? mb_substr($line, 0, 280) : substr($line, 0, 280);
            if (count($lines) >= max(1, $limit)) {
                return $lines;
            }
        }
    }

    return $lines;
}

/** @param array<string,mixed> $payload */
function wk_admin_save_exercise(PDO $pdo, ?int $exerciseId, array $payload, int $actorUserId): int
{
    $name = trim((string) ($payload['name'] ?? ''));
    if ($name === '') {
        throw new InvalidArgumentException('Exercise name is required.');
    }
    $muscle = trim((string) ($payload['muscle_group'] ?? ''));
    $muscle = in_array($muscle, wk_muscle_groups(), true) ? $muscle : 'core';
    $type = trim((string) ($payload['exercise_type'] ?? 'strength'));
    $type = in_array($type, WK_EXERCISE_TYPES, true) ? $type : 'strength';
    $equipment = trim((string) ($payload['equipment'] ?? 'none'));
    $equipment = in_array($equipment, wk_equipment_options(), true) ? $equipment : 'none';
    $difficulty = trim((string) ($payload['difficulty'] ?? 'beginner'));
    $difficulty = in_array($difficulty, ['beginner', 'intermediate', 'advanced'], true) ? $difficulty : 'beginner';
    $secondary = array_values(array_filter(array_unique(array_map('strval', (array) ($payload['secondary_muscles'] ?? []))), static fn(string $value): bool => in_array($value, wk_muscle_groups(), true)));
    $videoUrl = wk_normalize_exercise_video_url($payload['video_url'] ?? '');
    $existing = $exerciseId !== null && $exerciseId > 0 ? wk_exercise_get($pdo, $exerciseId) : null;
    if ($exerciseId !== null && $exerciseId > 0 && $existing === null) {
        throw new InvalidArgumentException('Exercise not found.');
    }
    $coverMode = array_key_exists('cover_mode', $payload)
        ? wk_normalize_exercise_cover_mode($payload['cover_mode'])
        : wk_normalize_exercise_cover_mode($existing['cover_mode'] ?? 'auto');
    $imagePosition = array_key_exists('image_position', $payload)
        ? wk_normalize_image_position($payload['image_position'])
        : wk_normalize_image_position($existing['image_position'] ?? 'center');
    $accentColor = array_key_exists('accent_color', $payload)
        ? wk_normalize_exercise_color($payload['accent_color'])
        : wk_normalize_exercise_color($existing['accent_color'] ?? wk_exercise_default_color($muscle));
    $visualMark = array_key_exists('visual_mark', $payload)
        ? wk_normalize_exercise_mark($payload['visual_mark'])
        : wk_normalize_exercise_mark($existing['visual_mark'] ?? wk_exercise_default_mark($muscle));
    $trainingDefaults = wk_exercise_training_defaults(array_merge(
        $existing ?? [],
        $payload,
        ['exercise_type' => $type]
    ));
    $guide = json_decode((string) ($existing['guide_json'] ?? '{}'), true);
    $guide = is_array($guide) ? $guide : [];
    $guide['names'] = is_array($guide['names'] ?? null) ? $guide['names'] : [];
    $guide['guides'] = is_array($guide['guides'] ?? null) ? $guide['guides'] : [];
    $guide['names']['en'] = $name;
    $guide['guides']['en'] = [
        'summary' => trim((string) ($payload['summary'] ?? '')),
        'steps' => wk_exercise_guide_lines($payload['steps_items'] ?? ($payload['steps'] ?? ''), 50),
        'tips' => wk_exercise_guide_lines($payload['tips_items'] ?? ($payload['tips'] ?? ''), 50),
        'mistakes' => wk_exercise_guide_lines($payload['mistakes_items'] ?? ($payload['mistakes'] ?? ''), 50),
    ];
    $imagePath = array_key_exists('image_path', $payload)
        ? (trim((string) ($payload['image_path'] ?? '')) ?: null)
        : (($existing['image_path'] ?? null) !== null ? (string) $existing['image_path'] : null);
    $slug = trim((string) ($existing['slug'] ?? ''));
    if ($slug === '') {
        $slugBase = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '_', $name), '_')) ?: 'exercise';
        $slug = 'admin_' . $slugBase;
        $candidate = $slug;
        $suffix = 2;
        while (db_fetch_one($pdo, 'SELECT id FROM exercise_definitions WHERE slug = :slug', [':slug' => $candidate]) !== null) {
            $candidate = $slug . '_' . $suffix++;
        }
        $slug = $candidate;
    }
    $params = [
        ':slug' => $slug,
        ':name' => $name,
        ':muscle' => $muscle,
        ':secondary' => json_encode($secondary, JSON_UNESCAPED_SLASHES),
        ':type' => $type,
        ':equipment' => $equipment,
        ':difficulty' => $difficulty,
        ':guide' => json_encode($guide, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':factor' => max(0.01, (float) ($payload['rank_factor'] ?? 1.0)),
        ':rankable' => !empty($payload['rankable']) ? 1 : 0,
        ':sort' => (int) ($payload['sort_order'] ?? 9999),
        ':image' => $imagePath,
        ':video' => $videoUrl,
        ':cover_mode' => $coverMode,
        ':image_position' => $imagePosition,
        ':visual_mark' => $visualMark,
        ':accent_color' => $accentColor,
        ':default_sets' => $trainingDefaults['target_sets'],
        ':default_reps' => $trainingDefaults['target_reps'],
        ':default_weight' => $trainingDefaults['target_weight'],
        ':default_duration' => $trainingDefaults['target_duration'],
        ':default_distance' => $trainingDefaults['target_distance'],
        ':default_rest' => $trainingDefaults['stored_rest_seconds'],
        ':default_unit' => $trainingDefaults['unit'],
        ':default_notes' => $trainingDefaults['notes'],
        ':active' => !empty($payload['active']) ? 1 : 0,
        ':now' => now_iso(),
    ];

    if ($existing !== null) {
        $params[':id'] = (int) $existing['id'];
        db_execute(
            $pdo,
            'UPDATE exercise_definitions
             SET slug = :slug, name = :name, muscle_group = :muscle, secondary_muscles = :secondary,
                 exercise_type = :type, equipment = :equipment, difficulty = :difficulty, guide_json = :guide,
                 rank_factor = :factor, rankable = :rankable, sort_order = :sort, image_path = :image,
                 video_url = :video, cover_mode = :cover_mode, image_position = :image_position,
                 visual_mark = :visual_mark, accent_color = :accent_color,
                 default_sets = :default_sets, default_reps = :default_reps, default_weight = :default_weight,
                 default_duration = :default_duration, default_distance = :default_distance,
                 default_rest_seconds = :default_rest, default_unit = :default_unit, default_notes = :default_notes,
                 active = :active, admin_override = 1, updated_at = :now
             WHERE id = :id',
            $params
        );
        audit_log($pdo, $actorUserId, 'training_exercise_updated', 'exercise_definition', (string) $existing['id'], 'Training exercise updated.', audit_snapshot($existing), audit_snapshot(wk_exercise_get($pdo, (int) $existing['id'])));

        return (int) $existing['id'];
    }

    db_execute(
        $pdo,
        'INSERT INTO exercise_definitions (
            user_id, slug, name, muscle_group, secondary_muscles, exercise_type, equipment, difficulty,
            guide_json, rank_factor, rankable, sort_order, image_path, video_url, cover_mode, image_position, visual_mark, accent_color,
            default_sets, default_reps, default_weight, default_duration, default_distance, default_rest_seconds, default_unit, default_notes,
            active, admin_override,
            is_system, created_at, updated_at
         ) VALUES (
            NULL, :slug, :name, :muscle, :secondary, :type, :equipment, :difficulty,
            :guide, :factor, :rankable, :sort, :image, :video, :cover_mode, :image_position, :visual_mark, :accent_color,
            :default_sets, :default_reps, :default_weight, :default_duration, :default_distance, :default_rest, :default_unit, :default_notes,
            :active, 1, 1, :now, :now
         )',
        $params
    );
    $createdId = (int) $pdo->lastInsertId();
    audit_log($pdo, $actorUserId, 'training_exercise_created', 'exercise_definition', (string) $createdId, 'Training exercise created.', null, audit_snapshot(wk_exercise_get($pdo, $createdId)));

    return $createdId;
}

function wk_admin_delete_exercise(PDO $pdo, int $exerciseId, int $actorUserId): bool
{
    $exercise = wk_exercise_get($pdo, $exerciseId);
    if ($exercise === null) {
        return false;
    }
    $uses = db_fetch_one(
        $pdo,
        'SELECT
            (SELECT COUNT(*) FROM routine_exercises WHERE exercise_def_id = :id) +
            (SELECT COUNT(*) FROM session_exercises WHERE exercise_def_id = :id) +
            (SELECT COUNT(*) FROM personal_records WHERE exercise_def_id = :id) AS total',
        [':id' => $exerciseId]
    );
    // Catalogue exercises must keep a tombstone: otherwise the offline seed
    // would recreate them on the next request. Referenced personal exercises
    // also stay in place to preserve historic sessions and records.
    if ((int) ($uses['total'] ?? 0) > 0 || (int) ($exercise['is_system'] ?? 0) === 1) {
        db_execute($pdo, 'UPDATE exercise_definitions SET active = 0, admin_override = 1, updated_at = :now WHERE id = :id', [':now' => now_iso(), ':id' => $exerciseId]);
    } else {
        db_execute($pdo, 'DELETE FROM exercise_media WHERE exercise_def_id = :id', [':id' => $exerciseId]);
        db_execute($pdo, 'DELETE FROM exercise_definitions WHERE id = :id', [':id' => $exerciseId]);
    }
    audit_log($pdo, $actorUserId, 'training_exercise_removed', 'exercise_definition', (string) $exerciseId, 'Training exercise removed.', audit_snapshot($exercise), null);

    return true;
}

function wk_user_exercise_get(PDO $pdo, int $exerciseId, int $userId): ?array
{
    $row = wk_exercise_get($pdo, $exerciseId);
    if ($row === null
        || (int) ($row['is_system'] ?? 0) !== 0
        || (int) ($row['user_id'] ?? 0) !== $userId
        || (int) ($row['active'] ?? 1) !== 1) {
        return null;
    }

    return $row;
}

/** @param array<string,mixed> $payload */
function wk_user_save_exercise(PDO $pdo, int $userId, ?int $exerciseId, array $payload): int
{
    if ($userId <= 0) {
        throw new InvalidArgumentException(t('flash.error'));
    }
    $existing = $exerciseId !== null && $exerciseId > 0
        ? wk_user_exercise_get($pdo, $exerciseId, $userId)
        : null;
    if ($exerciseId !== null && $exerciseId > 0 && $existing === null) {
        throw new InvalidArgumentException(t('workouts.custom_not_found'));
    }

    $name = trim((string) ($payload['name'] ?? ''));
    if ($name === '') {
        throw new InvalidArgumentException(t('workouts.exercise_name_required'));
    }
    $name = function_exists('mb_substr') ? mb_substr($name, 0, 120) : substr($name, 0, 120);
    $muscle = trim((string) ($payload['muscle_group'] ?? ''));
    $muscle = in_array($muscle, wk_muscle_groups(), true) ? $muscle : 'core';
    $type = trim((string) ($payload['exercise_type'] ?? 'strength'));
    $type = in_array($type, WK_EXERCISE_TYPES, true) ? $type : 'strength';
    $equipment = trim((string) ($payload['equipment'] ?? 'none'));
    $equipment = in_array($equipment, wk_equipment_options(), true) ? $equipment : 'none';
    $difficulty = trim((string) ($payload['difficulty'] ?? 'beginner'));
    $difficulty = in_array($difficulty, ['beginner', 'intermediate', 'advanced'], true) ? $difficulty : 'beginner';
    $secondary = array_values(array_filter(
        array_unique(array_map('strval', (array) ($payload['secondary_muscles'] ?? []))),
        static fn(string $value): bool => $value !== $muscle && in_array($value, wk_muscle_groups(), true)
    ));
    $videoUrl = wk_normalize_exercise_video_url($payload['video_url'] ?? '');
    $imagePath = trim((string) ($payload['image_path'] ?? ($existing['image_path'] ?? '')));
    $coverMode = wk_normalize_exercise_cover_mode($payload['cover_mode'] ?? ($existing['cover_mode'] ?? 'auto'));
    $imagePosition = wk_normalize_image_position($payload['image_position'] ?? ($existing['image_position'] ?? 'center'));
    $accentColor = wk_normalize_exercise_color($payload['accent_color'] ?? ($existing['accent_color'] ?? wk_exercise_default_color($muscle)));
    $visualMark = wk_normalize_exercise_mark($payload['visual_mark'] ?? ($existing['visual_mark'] ?? wk_exercise_default_mark($muscle)));
    $trainingDefaults = wk_exercise_training_defaults(array_merge(
        $existing ?? [],
        $payload,
        ['exercise_type' => $type]
    ));

    $guide = json_decode((string) ($existing['guide_json'] ?? '{}'), true);
    $guide = is_array($guide) ? $guide : [];
    $guide['names'] = is_array($guide['names'] ?? null) ? $guide['names'] : [];
    $guide['guides'] = is_array($guide['guides'] ?? null) ? $guide['guides'] : [];
    $locale = normalize_locale(current_locale(), 'en');
    $localizedGuide = [
        'summary' => trim((string) ($payload['summary'] ?? '')),
        'steps' => wk_exercise_guide_lines($payload['steps_items'] ?? ($payload['steps'] ?? ''), 20),
        'tips' => wk_exercise_guide_lines($payload['tips_items'] ?? ($payload['tips'] ?? ''), 20),
        'mistakes' => wk_exercise_guide_lines($payload['mistakes_items'] ?? ($payload['mistakes'] ?? ''), 20),
    ];
    $guide['names'][$locale] = $name;
    $guide['guides'][$locale] = $localizedGuide;
    if (!isset($guide['names']['en'])) {
        $guide['names']['en'] = $name;
    }
    if (!isset($guide['guides']['en'])) {
        $guide['guides']['en'] = $localizedGuide;
    }

    $now = now_iso();
    $params = [
        ':u' => $userId,
        ':name' => $name,
        ':muscle' => $muscle,
        ':secondary' => json_encode($secondary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':type' => $type,
        ':equipment' => $equipment,
        ':difficulty' => $difficulty,
        ':guide' => json_encode($guide, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':image' => $imagePath !== '' ? $imagePath : null,
        ':video' => $videoUrl,
        ':cover_mode' => $coverMode,
        ':image_position' => $imagePosition,
        ':visual_mark' => $visualMark,
        ':accent_color' => $accentColor,
        ':default_sets' => $trainingDefaults['target_sets'],
        ':default_reps' => $trainingDefaults['target_reps'],
        ':default_weight' => $trainingDefaults['target_weight'],
        ':default_duration' => $trainingDefaults['target_duration'],
        ':default_distance' => $trainingDefaults['target_distance'],
        ':default_rest' => $trainingDefaults['stored_rest_seconds'],
        ':default_unit' => $trainingDefaults['unit'],
        ':default_notes' => $trainingDefaults['notes'],
        ':now' => $now,
    ];

    if ($existing !== null) {
        $params[':id'] = (int) $existing['id'];
        db_execute(
            $pdo,
            'UPDATE exercise_definitions
             SET name = :name, muscle_group = :muscle, secondary_muscles = :secondary,
                 exercise_type = :type, equipment = :equipment, difficulty = :difficulty,
                 guide_json = :guide, image_path = :image, video_url = :video, cover_mode = :cover_mode,
                 image_position = :image_position, visual_mark = :visual_mark, accent_color = :accent_color,
                 default_sets = :default_sets, default_reps = :default_reps, default_weight = :default_weight,
                 default_duration = :default_duration, default_distance = :default_distance,
                 default_rest_seconds = :default_rest, default_unit = :default_unit, default_notes = :default_notes,
                 updated_at = :now
             WHERE id = :id AND user_id = :u AND is_system = 0 AND COALESCE(active, 1) = 1',
            $params
        );
        audit_log($pdo, $userId, 'training_custom_exercise_updated', 'exercise_definition', (string) $existing['id'], 'Personal exercise updated.', audit_snapshot($existing), audit_snapshot(wk_exercise_get($pdo, (int) $existing['id'])));

        return (int) $existing['id'];
    }

    db_execute(
        $pdo,
        'INSERT INTO exercise_definitions (
            user_id, name, muscle_group, secondary_muscles, exercise_type, equipment, difficulty,
            guide_json, rank_factor, rankable, sort_order, image_path, video_url, cover_mode, image_position, visual_mark, accent_color,
            default_sets, default_reps, default_weight, default_duration, default_distance, default_rest_seconds, default_unit, default_notes, active,
            admin_override, is_system, created_at, updated_at
         ) VALUES (
            :u, :name, :muscle, :secondary, :type, :equipment, :difficulty,
            :guide, 1, 0, 9999, :image, :video, :cover_mode, :image_position, :visual_mark, :accent_color,
            :default_sets, :default_reps, :default_weight, :default_duration, :default_distance, :default_rest, :default_unit, :default_notes,
            1, 0, 0, :now, :now
         )',
        $params
    );
    $createdId = (int) $pdo->lastInsertId();
    audit_log($pdo, $userId, 'training_custom_exercise_created', 'exercise_definition', (string) $createdId, 'Personal exercise created.', null, audit_snapshot(wk_exercise_get($pdo, $createdId)));

    return $createdId;
}

function wk_user_delete_exercise(PDO $pdo, int $exerciseId, int $userId): bool
{
    $exercise = wk_user_exercise_get($pdo, $exerciseId, $userId);
    if ($exercise === null) {
        return false;
    }
    $uses = db_fetch_one(
        $pdo,
        'SELECT
            (SELECT COUNT(*) FROM routine_exercises WHERE exercise_def_id = :id) +
            (SELECT COUNT(*) FROM session_exercises WHERE exercise_def_id = :id) +
            (SELECT COUNT(*) FROM personal_records WHERE exercise_def_id = :id) AS total',
        [':id' => $exerciseId]
    );
    if ((int) ($uses['total'] ?? 0) > 0) {
        db_execute(
            $pdo,
            'UPDATE exercise_definitions SET active = 0, updated_at = :now WHERE id = :id AND user_id = :u AND is_system = 0',
            [':now' => now_iso(), ':id' => $exerciseId, ':u' => $userId]
        );
    } else {
        db_execute($pdo, 'DELETE FROM exercise_media WHERE exercise_def_id = :id', [':id' => $exerciseId]);
        db_execute(
            $pdo,
            'DELETE FROM exercise_definitions WHERE id = :id AND user_id = :u AND is_system = 0',
            [':id' => $exerciseId, ':u' => $userId]
        );
    }
    audit_log($pdo, $userId, 'training_custom_exercise_removed', 'exercise_definition', (string) $exerciseId, 'Personal exercise removed.', audit_snapshot($exercise), null);

    return true;
}

function wk_exercise_get(PDO $pdo, int $id): ?array
{
    $row = db_fetch_one($pdo, 'SELECT * FROM exercise_definitions WHERE id = :id', [':id' => $id]);
    if ($row !== null) {
        $row['content'] = wk_exercise_content($row);
        $row['display_name'] = (string) ($row['content']['name'] ?? $row['name']);
    }

    return $row;
}

function wk_exercise_get_for_user(PDO $pdo, int $id, int $userId): ?array
{
    $row = wk_exercise_get($pdo, $id);
    if ($row === null || ((int) ($row['is_system'] ?? 0) !== 1 && (int) ($row['user_id'] ?? 0) !== $userId)) {
        return null;
    }

    $preference = db_fetch_one(
        $pdo,
        'SELECT is_favorite, sort_order FROM workout_exercise_preferences WHERE user_id = :user AND exercise_def_id = :exercise',
        [':user' => $userId, ':exercise' => $id]
    );
    $row['is_favorite'] = (int) ($preference['is_favorite'] ?? 0);
    $row['preference_order'] = (int) ($preference['sort_order'] ?? 0);

    return $row;
}

function wk_exercise_set_favorite(PDO $pdo, int $exerciseId, int $userId, bool $favorite): bool
{
    $exercise = wk_exercise_get_for_user($pdo, $exerciseId, $userId);
    if ($exercise === null || (int) ($exercise['active'] ?? 1) !== 1) {
        return false;
    }

    db_execute(
        $pdo,
        'INSERT INTO workout_exercise_preferences (user_id, exercise_def_id, is_favorite, sort_order, updated_at)
         VALUES (:user, :exercise, :favorite, 0, :updated)
         ON CONFLICT(user_id, exercise_def_id) DO UPDATE SET
             is_favorite = excluded.is_favorite,
             updated_at = excluded.updated_at',
        [
            ':user' => $userId,
            ':exercise' => $exerciseId,
            ':favorite' => $favorite ? 1 : 0,
            ':updated' => now_iso(),
        ]
    );

    return true;
}

/**
 * Persist a user's favorite-exercise order without accepting foreign,
 * inactive or non-favorite exercise IDs. Favorites omitted by an older page
 * are preserved at the end so a concurrent change cannot silently drop them.
 *
 * @param array<int,int|string> $orderedIds
 */
function wk_exercise_favorites_reorder(PDO $pdo, int $userId, array $orderedIds): bool
{
    $currentRows = wk_exercise_library($pdo, $userId, ['scope' => 'favorites']);
    $currentIds = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $currentRows);
    $validIds = array_fill_keys($currentIds, true);
    $normalized = [];
    $seen = [];
    foreach ($orderedIds as $rawId) {
        $id = (int) $rawId;
        if ($id <= 0 || isset($seen[$id]) || !isset($validIds[$id])) {
            continue;
        }
        $normalized[] = $id;
        $seen[$id] = true;
    }
    foreach ($currentIds as $id) {
        if ($id > 0 && !isset($seen[$id])) {
            $normalized[] = $id;
        }
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        foreach ($normalized as $index => $id) {
            db_execute(
                $pdo,
                'UPDATE workout_exercise_preferences
                 SET sort_order = :position, updated_at = :updated
                 WHERE user_id = :user AND exercise_def_id = :exercise AND is_favorite = 1',
                [
                    ':position' => $index + 1,
                    ':updated' => now_iso(),
                    ':user' => $userId,
                    ':exercise' => $id,
                ]
            );
        }
        if ($ownsTransaction) {
            $pdo->commit();
        }

        return true;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function wk_user_clone_exercise(PDO $pdo, int $sourceExerciseId, int $userId): int
{
    $source = wk_exercise_get_for_user($pdo, $sourceExerciseId, $userId);
    if ($source === null || (int) ($source['active'] ?? 1) !== 1) {
        throw new InvalidArgumentException(t('workouts.custom_not_found'));
    }

    $existing = db_fetch_one(
        $pdo,
        'SELECT id FROM exercise_definitions
         WHERE user_id = :user AND source_exercise_id = :source AND is_system = 0 AND COALESCE(active, 1) = 1
         LIMIT 1',
        [':user' => $userId, ':source' => $sourceExerciseId]
    );
    if ($existing !== null) {
        return (int) $existing['id'];
    }

    $guide = json_decode((string) ($source['guide_json'] ?? '{}'), true);
    $guide = is_array($guide) ? $guide : [];
    $guide['names'] = is_array($guide['names'] ?? null) ? $guide['names'] : [];
    $suffixes = ['en' => 'Copy', 'es' => 'Copia', 'it' => 'Copia'];
    foreach ($suffixes as $locale => $suffix) {
        $localized = trim((string) ($guide['names'][$locale] ?? ''));
        if ($localized !== '') {
            $guide['names'][$locale] = $localized . ' · ' . $suffix;
        }
    }
    $locale = normalize_locale(current_locale(), 'en');
    $sourceContent = wk_exercise_content($source, $locale);
    $copyName = trim((string) ($guide['names'][$locale] ?? ''));
    if ($copyName === '') {
        $copyName = trim((string) ($sourceContent['name'] ?? $source['name'] ?? '')) . ' · ' . t('workouts.copy_name');
        $guide['names'][$locale] = $copyName;
    }
    if (!isset($guide['names']['en'])) {
        $guide['names']['en'] = $copyName;
    }
    $sourceTrainingDefaults = wk_exercise_training_defaults($source);

    $now = now_iso();
    db_execute(
        $pdo,
        'INSERT INTO exercise_definitions (
            user_id, source_exercise_id, name, muscle_group, secondary_muscles, exercise_type,
            equipment, difficulty, guide_json, rank_factor, rankable, sort_order,
            image_path, video_url, cover_mode, image_position, visual_mark, accent_color,
            default_sets, default_reps, default_weight, default_duration, default_distance, default_rest_seconds, default_unit, default_notes,
            active, admin_override, is_system, created_at, updated_at
         ) VALUES (
            :user, :source, :name, :muscle, :secondary, :type,
            :equipment, :difficulty, :guide, 1, 0, 9999,
            :image, :video, :cover_mode, :image_position, :visual_mark, :accent_color,
            :default_sets, :default_reps, :default_weight, :default_duration, :default_distance, :default_rest, :default_unit, :default_notes,
            1, 0, 0, :created, :updated
         )',
        [
            ':user' => $userId,
            ':source' => $sourceExerciseId,
            ':name' => $copyName,
            ':muscle' => (string) ($source['muscle_group'] ?? 'core'),
            ':secondary' => (string) ($source['secondary_muscles'] ?? '[]'),
            ':type' => (string) ($source['exercise_type'] ?? 'strength'),
            ':equipment' => (string) ($source['equipment'] ?? 'none'),
            ':difficulty' => (string) ($source['difficulty'] ?? 'beginner'),
            ':guide' => json_encode($guide, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':image' => trim((string) ($source['image_path'] ?? '')) !== '' ? (string) $source['image_path'] : null,
            ':video' => wk_normalize_exercise_video_url($source['video_url'] ?? ''),
            ':cover_mode' => wk_normalize_exercise_cover_mode($source['cover_mode'] ?? 'auto'),
            ':image_position' => wk_normalize_image_position($source['image_position'] ?? 'center'),
            ':visual_mark' => wk_normalize_exercise_mark($source['visual_mark'] ?? wk_exercise_default_mark($source['muscle_group'] ?? '')),
            ':accent_color' => wk_normalize_exercise_color($source['accent_color'] ?? wk_exercise_default_color($source['muscle_group'] ?? '')),
            ':default_sets' => $sourceTrainingDefaults['target_sets'],
            ':default_reps' => $sourceTrainingDefaults['target_reps'],
            ':default_weight' => $sourceTrainingDefaults['target_weight'],
            ':default_duration' => $sourceTrainingDefaults['target_duration'],
            ':default_distance' => $sourceTrainingDefaults['target_distance'],
            ':default_rest' => $sourceTrainingDefaults['stored_rest_seconds'],
            ':default_unit' => $sourceTrainingDefaults['unit'],
            ':default_notes' => $sourceTrainingDefaults['notes'],
            ':created' => $now,
            ':updated' => $now,
        ]
    );
    $copyId = (int) $pdo->lastInsertId();
    wk_exercise_media_replace($pdo, $copyId, wk_exercise_media_list($pdo, $source));
    wk_exercise_set_favorite($pdo, $copyId, $userId, true);
    audit_log($pdo, $userId, 'training_custom_exercise_cloned', 'exercise_definition', (string) $copyId, 'Personal exercise cloned.', audit_snapshot($source), audit_snapshot(wk_exercise_get($pdo, $copyId)));

    return $copyId;
}

/** @return array{name:string,summary:string,steps:array<int,string>,tips:array<int,string>,mistakes:array<int,string>} */
function wk_exercise_content(array $exercise, ?string $locale = null): array
{
    $locale = normalize_locale($locale ?? current_locale(), 'en');
    $decoded = json_decode((string) ($exercise['guide_json'] ?? '{}'), true);
    $decoded = is_array($decoded) ? $decoded : [];
    $names = is_array($decoded['names'] ?? null) ? $decoded['names'] : [];
    $guides = is_array($decoded['guides'] ?? null) ? $decoded['guides'] : [];
    $guide = is_array($guides[$locale] ?? null) ? $guides[$locale] : (is_array($guides['en'] ?? null) ? $guides['en'] : []);

    return [
        'name' => trim((string) ($names[$locale] ?? $names['en'] ?? $exercise['name'] ?? '')),
        'summary' => trim((string) ($guide['summary'] ?? '')),
        'steps' => array_values(array_filter(array_map('strval', (array) ($guide['steps'] ?? [])), static fn(string $v): bool => trim($v) !== '')),
        'tips' => array_values(array_filter(array_map('strval', (array) ($guide['tips'] ?? [])), static fn(string $v): bool => trim($v) !== '')),
        'mistakes' => array_values(array_filter(array_map('strval', (array) ($guide['mistakes'] ?? [])), static fn(string $v): bool => trim($v) !== '')),
    ];
}

/** Stable body-part list shared by filters, ranks and routine builder. */
function wk_muscle_groups(): array
{
    return ['chest', 'back', 'shoulders', 'quads', 'hamstrings', 'glutes', 'biceps', 'triceps', 'core', 'calves', 'cardio'];
}

function wk_equipment_options(): array
{
    return ['barbell', 'dumbbell', 'cable', 'machine', 'bodyweight', 'none'];
}

/** @return array<int,array<string,mixed>> */
function wk_exercise_library(PDO $pdo, int $userId, array $filters = []): array
{
    $lower = static fn(string $value): string => function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
    $query = $lower(trim((string) ($filters['q'] ?? '')));
    $muscle = trim((string) ($filters['muscle'] ?? ''));
    $equipment = trim((string) ($filters['equipment'] ?? ''));
    $scope = trim((string) ($filters['scope'] ?? ''));
    $rows = wk_exercises_for_user($pdo, $userId);

    return array_values(array_filter($rows, static function (array $row) use ($query, $muscle, $equipment, $scope, $userId, $lower): bool {
        if ($scope === 'mine' && (int) ($row['user_id'] ?? 0) !== $userId) {
            return false;
        }
        if ($scope === 'favorites' && (int) ($row['is_favorite'] ?? 0) !== 1) {
            return false;
        }
        if ($muscle !== '' && (string) ($row['muscle_group'] ?? '') !== $muscle) {
            return false;
        }
        if ($equipment !== '' && (string) ($row['equipment'] ?? '') !== $equipment) {
            return false;
        }
        if ($query !== '') {
            $haystack = $lower(implode(' ', [
                (string) ($row['name'] ?? ''),
                (string) ($row['display_name'] ?? ''),
                (string) ($row['muscle_group'] ?? ''),
                (string) ($row['equipment'] ?? ''),
                (string) ($row['content']['summary'] ?? ''),
                t('workouts.muscle_' . (string) ($row['muscle_group'] ?? '')),
                t('workouts.equipment_' . (string) ($row['equipment'] ?? '')),
            ]));
            if (!str_contains($haystack, $query)) {
                return false;
            }
        }
        return true;
    }));
}

function wk_exercise_create(PDO $pdo, int $userId, string $name, string $muscle, string $type, string $equipment): int
{
    try {
        return wk_user_save_exercise($pdo, $userId, null, [
            'name' => $name,
            'muscle_group' => $muscle,
            'exercise_type' => $type,
            'equipment' => $equipment,
            'difficulty' => 'beginner',
        ]);
    } catch (Throwable) {
        return 0;
    }
}

function wk_days_mask(mixed $days): string
{
    if (is_string($days) && preg_match('/^[01]{7}$/', $days) === 1) {
        return $days;
    }
    $selected = array_map('strval', is_array($days) ? $days : []);
    $keys = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    return implode('', array_map(static fn(string $key): string => in_array($key, $selected, true) ? '1' : '0', $keys));
}

/** @return array<int,string> */
function wk_days_from_mask(string $mask): array
{
    $mask = wk_days_mask($mask);
    $keys = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    return array_values(array_filter($keys, static fn(string $key, int $index): bool => ($mask[$index] ?? '0') === '1', ARRAY_FILTER_USE_BOTH));
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

/** @return array<string,string> */
function wk_routine_icon_options(): array
{
    return [
        'dumbbell' => 'workouts.routine_icon_strength',
        'bolt' => 'workouts.routine_icon_power',
        'target' => 'workouts.routine_icon_focus',
        'flame' => 'workouts.routine_icon_fire',
        'run' => 'workouts.routine_icon_running',
        'cycle' => 'workouts.routine_icon_cycling',
        'shield' => 'workouts.routine_icon_resilience',
        'spark' => 'workouts.routine_icon_custom',
    ];
}

function wk_normalize_routine_icon(mixed $icon): string
{
    $icon = trim((string) $icon);

    return array_key_exists($icon, wk_routine_icon_options()) ? $icon : 'dumbbell';
}

/** @return array<string,string> */
function wk_routine_color_options(): array
{
    return [
        'teal' => '#14b8a6',
        'blue' => '#3b82f6',
        'violet' => '#8b5cf6',
        'orange' => '#f97316',
        'red' => '#ef4444',
        'green' => '#22c55e',
        'pink' => '#ec4899',
        'slate' => '#64748b',
    ];
}

function wk_normalize_routine_color(mixed $color): string
{
    $color = strtolower(trim((string) $color));

    return preg_match('/^#[0-9a-f]{6}$/', $color) === 1 ? $color : '#14b8a6';
}

function wk_routine_create(
    PDO $pdo,
    int $userId,
    string $name,
    string $icon = 'dumbbell',
    string $description = '',
    string $daysMask = '0000000',
    string $accentColor = '#14b8a6',
    ?string $imagePath = null,
    ?string $videoUrl = null,
    string $coverMode = 'auto',
    string $imagePosition = 'center'
): int
{
    $name = trim($name);
    if ($name === '') {
        return 0;
    }
    $order = db_fetch_one($pdo, 'SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM workout_routines WHERE user_id = :u', [':u' => $userId]);
    $now = now_iso();
    db_execute(
        $pdo,
        'INSERT INTO workout_routines (user_id, name, icon, accent_color, image_path, video_url, cover_mode, image_position, description, sort_order, recommended_days_mask, created_at, updated_at)
         VALUES (:u, :n, :i, :color, :image, :video, :cover_mode, :image_position, :d, :o, :days, :now, :now)',
        [
            ':u' => $userId, ':n' => $name, ':i' => wk_normalize_routine_icon($icon),
            ':color' => wk_normalize_routine_color($accentColor), ':d' => trim($description),
            ':image' => trim((string) $imagePath) !== '' ? trim((string) $imagePath) : null,
            ':video' => wk_normalize_exercise_video_url($videoUrl),
            ':cover_mode' => wk_normalize_exercise_cover_mode($coverMode),
            ':image_position' => wk_normalize_image_position($imagePosition),
            ':o' => (int) ($order['n'] ?? 1), ':days' => wk_days_mask($daysMask), ':now' => $now,
        ]
    );

    return (int) $pdo->lastInsertId();
}

function wk_routine_update(PDO $pdo, int $id, int $userId, array $fields): bool
{
    if (wk_routine_get($pdo, $id, $userId) === null) {
        return false;
    }
    $allowed = ['name', 'icon', 'accent_color', 'image_path', 'video_url', 'cover_mode', 'image_position', 'description', 'recommended_days_mask'];
    $sets = [];
    $params = [':id' => $id, ':u' => $userId, ':now' => now_iso()];
    foreach ($allowed as $key) {
        if (array_key_exists($key, $fields)) {
            $sets[] = "$key = :$key";
            $params[":$key"] = match ($key) {
                'recommended_days_mask' => wk_days_mask($fields[$key]),
                'icon' => wk_normalize_routine_icon($fields[$key]),
                'accent_color' => wk_normalize_routine_color($fields[$key]),
                'image_path' => trim((string) $fields[$key]) !== '' ? trim((string) $fields[$key]) : null,
                'video_url' => wk_normalize_exercise_video_url($fields[$key]),
                'cover_mode' => wk_normalize_exercise_cover_mode($fields[$key]),
                'image_position' => wk_normalize_image_position($fields[$key]),
                default => trim((string) $fields[$key]),
            };
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
    $newId = wk_routine_create(
        $pdo,
        $userId,
        (string) $routine['name'] . ' (copy)',
        (string) $routine['icon'],
        (string) $routine['description'],
        (string) ($routine['recommended_days_mask'] ?? '0000000'),
        (string) ($routine['accent_color'] ?? '#14b8a6'),
        ($routine['image_path'] ?? null) !== null ? (string) $routine['image_path'] : null,
        ($routine['video_url'] ?? null) !== null ? (string) $routine['video_url'] : null,
        (string) ($routine['cover_mode'] ?? 'auto'),
        (string) ($routine['image_position'] ?? 'center')
    );
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

/** @return array<string,array<int,array<string,mixed>>> */
function wk_routines_by_day(PDO $pdo, int $userId): array
{
    $result = ['mon' => [], 'tue' => [], 'wed' => [], 'thu' => [], 'fri' => [], 'sat' => [], 'sun' => [], 'unscheduled' => []];
    foreach (wk_routines_for_user($pdo, $userId, false) as $routine) {
        $days = wk_days_from_mask((string) ($routine['recommended_days_mask'] ?? '0000000'));
        if ($days === []) {
            $result['unscheduled'][] = $routine;
            continue;
        }
        foreach ($days as $day) {
            $result[$day][] = $routine;
        }
    }

    return $result;
}

/** Create normal editable routines from an offline starter plan. @return array<int,int> */
function wk_create_plan_from_preset(PDO $pdo, int $userId, string $presetKey): array
{
    $presets = wk_builtin_plan_presets();
    $preset = $presets[$presetKey] ?? null;
    if (!is_array($preset)) {
        return [];
    }
    $locale = current_locale();
    $created = [];
    $presetIcons = array_keys(wk_routine_icon_options());
    $presetColors = array_values(wk_routine_color_options());
    foreach ((array) ($preset['routines'] ?? []) as $routineIndex => $routine) {
        $names = (array) ($routine['name'] ?? []);
        $presetDescription = (array) ($preset['description'] ?? []);
        $routineId = wk_routine_create(
            $pdo,
            $userId,
            (string) ($names[$locale] ?? $names['en'] ?? t('workouts.routine_name')),
            (string) ($routine['icon'] ?? $presetIcons[$routineIndex % count($presetIcons)]),
            (string) ($presetDescription[$locale] ?? $presetDescription['en'] ?? ''),
            (string) ($routine['days'] ?? '0000000'),
            (string) ($routine['accent_color'] ?? $presetColors[$routineIndex % count($presetColors)])
        );
        if ($routineId <= 0) {
            continue;
        }
        $created[] = $routineId;
        foreach ((array) ($routine['exercises'] ?? []) as $spec) {
            $slug = (string) ($spec[0] ?? '');
            $exercise = db_fetch_one($pdo, 'SELECT id FROM exercise_definitions WHERE slug = :slug AND is_system = 1', [':slug' => $slug]);
            if ($exercise === null) {
                continue;
            }
            wk_routine_add_exercise($pdo, $routineId, (int) $exercise['id'], [
                'target_sets' => (int) ($spec[1] ?? 3),
                'target_reps' => (int) ($spec[2] ?? 10),
                'rest_seconds' => 90,
            ]);
        }
    }

    return $created;
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
    $rows = db_fetch_all(
        $pdo,
        'SELECT re.*, ed.name AS exercise_name, ed.muscle_group, ed.secondary_muscles,
                ed.exercise_type, ed.equipment, ed.difficulty, ed.slug, ed.guide_json,
                ed.rank_factor, ed.rankable, ed.image_path, ed.video_url, ed.cover_mode, ed.image_position, ed.visual_mark, ed.accent_color,
                ed.user_id AS exercise_owner_id, ed.is_system
         FROM routine_exercises re
         JOIN exercise_definitions ed ON ed.id = re.exercise_def_id
         WHERE re.routine_id = :r
         ORDER BY re.sort_order ASC, re.id ASC',
        [':r' => $routineId]
    );
    foreach ($rows as &$row) {
        $row['content'] = wk_exercise_content(array_merge($row, ['name' => $row['exercise_name']]));
        $row['exercise_name'] = (string) ($row['content']['name'] ?? $row['exercise_name']);
    }
    unset($row);

    return $rows;
}

/**
 * Persist the exercise order for one routine without allowing a crafted
 * request to move rows from another routine or silently drop existing rows.
 *
 * @param array<int,int|string> $orderedIds Routine-exercise row IDs.
 */
function wk_routine_exercises_reorder(PDO $pdo, int $routineId, int $userId, array $orderedIds): bool
{
    if (wk_routine_get($pdo, $routineId, $userId) === null) {
        return false;
    }

    $rows = db_fetch_all(
        $pdo,
        'SELECT id FROM routine_exercises WHERE routine_id = :routine ORDER BY sort_order ASC, id ASC',
        [':routine' => $routineId]
    );
    $currentIds = array_map(static fn(array $row): int => (int) $row['id'], $rows);
    $validIds = array_fill_keys($currentIds, true);
    $normalized = [];
    $seen = [];
    foreach ($orderedIds as $rawId) {
        $id = (int) $rawId;
        if ($id <= 0 || isset($seen[$id]) || !isset($validIds[$id])) {
            continue;
        }
        $normalized[] = $id;
        $seen[$id] = true;
    }
    foreach ($currentIds as $id) {
        if (!isset($seen[$id])) {
            $normalized[] = $id;
        }
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        foreach ($normalized as $index => $id) {
            db_execute(
                $pdo,
                'UPDATE routine_exercises SET sort_order = :position WHERE id = :id AND routine_id = :routine',
                [':position' => $index + 1, ':id' => $id, ':routine' => $routineId]
            );
        }
        db_execute(
            $pdo,
            'UPDATE workout_routines SET updated_at = :now WHERE id = :routine AND user_id = :user',
            [':now' => now_iso(), ':routine' => $routineId, ':user' => $userId]
        );
        if ($ownsTransaction) {
            $pdo->commit();
        }

        return true;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** @return array<string,mixed>|null */
function wk_routine_exercise_get(PDO $pdo, int $routineExerciseId, int $routineId, ?int $userId = null): ?array
{
    $params = [':id' => $routineExerciseId, ':routine' => $routineId];
    $ownerJoin = '';
    $ownerWhere = '';
    if ($userId !== null) {
        $ownerJoin = ' JOIN workout_routines r ON r.id = re.routine_id';
        $ownerWhere = ' AND r.user_id = :user';
        $params[':user'] = $userId;
    }
    $row = db_fetch_one(
        $pdo,
        'SELECT re.*, ed.name AS exercise_name, ed.muscle_group, ed.secondary_muscles,
                ed.exercise_type, ed.equipment, ed.difficulty, ed.slug, ed.guide_json,
                ed.rank_factor, ed.rankable, ed.image_path, ed.video_url, ed.cover_mode, ed.image_position, ed.visual_mark, ed.accent_color,
                ed.user_id AS exercise_owner_id, ed.is_system
         FROM routine_exercises re' . $ownerJoin . '
         JOIN exercise_definitions ed ON ed.id = re.exercise_def_id
         WHERE re.id = :id AND re.routine_id = :routine' . $ownerWhere . '
         LIMIT 1',
        $params
    );
    if ($row === null) {
        return null;
    }
    $row['content'] = wk_exercise_content(array_merge($row, ['name' => $row['exercise_name']]));
    $row['exercise_name'] = (string) ($row['content']['name'] ?? $row['exercise_name']);

    return $row;
}

function wk_routine_exercise_update(PDO $pdo, int $routineExerciseId, int $routineId, int $userId, array $targets): bool
{
    if (wk_routine_exercise_get($pdo, $routineExerciseId, $routineId, $userId) === null) {
        return false;
    }
    $nullableInt = static function (mixed $value, int $max): ?int {
        if ($value === '' || $value === null) {
            return null;
        }
        return max(0, min($max, (int) $value));
    };
    $nullableFloat = static function (mixed $value, float $max): ?float {
        if ($value === '' || $value === null) {
            return null;
        }
        return max(0.0, min($max, (float) $value));
    };
    $notes = trim((string) ($targets['notes'] ?? ''));
    $notes = function_exists('mb_substr') ? mb_substr($notes, 0, 500) : substr($notes, 0, 500);
    $updated = db_execute(
        $pdo,
        'UPDATE routine_exercises
         SET target_sets = :sets, target_reps = :reps, target_weight = :weight,
             target_duration = :duration, target_distance = :distance,
             rest_seconds = :rest, unit = :unit, notes = :notes
         WHERE id = :id AND routine_id = :routine',
        [
            ':sets' => max(1, min(20, (int) ($targets['target_sets'] ?? 3))),
            ':reps' => $nullableInt($targets['target_reps'] ?? null, 999),
            ':weight' => $nullableFloat($targets['target_weight'] ?? null, 99999.0),
            ':duration' => $nullableInt($targets['target_duration'] ?? null, 86400),
            ':distance' => $nullableFloat($targets['target_distance'] ?? null, 99999.0),
            ':rest' => $nullableInt($targets['rest_seconds'] ?? null, 3600),
            ':unit' => in_array((string) ($targets['unit'] ?? 'kg'), WK_UNITS, true) ? (string) ($targets['unit'] ?? 'kg') : 'kg',
            ':notes' => $notes,
            ':id' => $routineExerciseId,
            ':routine' => $routineId,
        ]
    );
    if ($updated) {
        db_execute(
            $pdo,
            'UPDATE workout_routines SET updated_at = :now WHERE id = :routine AND user_id = :user',
            [':now' => now_iso(), ':routine' => $routineId, ':user' => $userId]
        );
    }

    return $updated;
}

/**
 * Swap the catalogue definition behind one routine row without losing its
 * targets, notes or sort position. If an old flow already inserted the same
 * personal definition elsewhere in the routine, collapse that duplicate first.
 */
function wk_routine_exercise_replace_definition(
    PDO $pdo,
    int $routineExerciseId,
    int $routineId,
    int $userId,
    int $exerciseDefId
): bool {
    $routineExercise = wk_routine_exercise_get($pdo, $routineExerciseId, $routineId, $userId);
    $replacement = wk_exercise_get_for_user($pdo, $exerciseDefId, $userId);
    if ($routineExercise === null || $replacement === null || (int) ($replacement['active'] ?? 1) !== 1) {
        return false;
    }
    if ((int) ($routineExercise['exercise_def_id'] ?? 0) === $exerciseDefId) {
        return true;
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        db_execute(
            $pdo,
            'DELETE FROM routine_exercises
             WHERE routine_id = :routine AND exercise_def_id = :exercise AND id <> :id',
            [':routine' => $routineId, ':exercise' => $exerciseDefId, ':id' => $routineExerciseId]
        );
        $updated = db_execute(
            $pdo,
            'UPDATE routine_exercises SET exercise_def_id = :exercise
             WHERE id = :id AND routine_id = :routine',
            [':exercise' => $exerciseDefId, ':id' => $routineExerciseId, ':routine' => $routineId]
        );
        if (!$updated) {
            throw new RuntimeException('Routine exercise could not be personalized.');
        }
        db_execute(
            $pdo,
            'UPDATE workout_routines SET updated_at = :now WHERE id = :routine AND user_id = :user',
            [':now' => now_iso(), ':routine' => $routineId, ':user' => $userId]
        );
        if ($ownsTransaction) {
            $pdo->commit();
        }

        return true;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function wk_routine_add_exercise(PDO $pdo, int $routineId, int $exerciseDefId, array $targets = []): int
{
    $exercise = $exerciseDefId > 0 ? wk_exercise_get($pdo, $exerciseDefId) : null;
    if ($routineId <= 0 || $exercise === null) {
        return 0;
    }
    $existing = db_fetch_one(
        $pdo,
        'SELECT id FROM routine_exercises WHERE routine_id = :r AND exercise_def_id = :e LIMIT 1',
        [':r' => $routineId, ':e' => $exerciseDefId]
    );
    if ($existing !== null) {
        return (int) $existing['id'];
    }
    $resolvedTargets = array_replace(wk_exercise_training_defaults($exercise), $targets);
    $order = db_fetch_one($pdo, 'SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM routine_exercises WHERE routine_id = :r', [':r' => $routineId]);
    db_execute(
        $pdo,
        'INSERT INTO routine_exercises (routine_id, exercise_def_id, sort_order, target_sets, target_reps, target_weight, target_duration, target_distance, rest_seconds, unit, notes)
         VALUES (:r, :e, :so, :ts, :tr, :tw, :td, :ds, :rs, :un, :no)',
        [
            ':r' => $routineId, ':e' => $exerciseDefId, ':so' => (int) ($order['n'] ?? 1),
            ':ts' => max(1, min(20, (int) ($resolvedTargets['target_sets'] ?? 3))),
            ':tr' => isset($resolvedTargets['target_reps']) && $resolvedTargets['target_reps'] !== '' ? max(0, min(999, (int) $resolvedTargets['target_reps'])) : null,
            ':tw' => isset($resolvedTargets['target_weight']) && $resolvedTargets['target_weight'] !== '' ? max(0.0, min(99999.0, (float) $resolvedTargets['target_weight'])) : null,
            ':td' => isset($resolvedTargets['target_duration']) && $resolvedTargets['target_duration'] !== '' ? max(0, min(86400, (int) $resolvedTargets['target_duration'])) : null,
            ':ds' => isset($resolvedTargets['target_distance']) && $resolvedTargets['target_distance'] !== '' ? max(0.0, min(99999.0, (float) $resolvedTargets['target_distance'])) : null,
            ':rs' => isset($resolvedTargets['rest_seconds']) && $resolvedTargets['rest_seconds'] !== '' ? max(0, min(3600, (int) $resolvedTargets['rest_seconds'])) : null,
            ':un' => in_array((string) ($resolvedTargets['unit'] ?? 'kg'), WK_UNITS, true) ? (string) $resolvedTargets['unit'] : 'kg',
            ':no' => trim((string) ($resolvedTargets['notes'] ?? '')),
        ]
    );

    return (int) $pdo->lastInsertId();
}

function wk_routine_remove_exercise(PDO $pdo, int $routineExerciseId, int $routineId, ?int $userId = null): bool
{
    if ($userId !== null && wk_routine_get($pdo, $routineId, $userId) === null) {
        return false;
    }

    return db_execute($pdo, 'DELETE FROM routine_exercises WHERE id = :id AND routine_id = :r', [
        ':id' => $routineExerciseId,
        ':r' => $routineId,
    ]);
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
    return db_fetch_one(
        $pdo,
        'SELECT ws.*,
                wr.icon AS routine_icon,
                wr.accent_color AS routine_accent_color,
                wr.image_path AS routine_image_path,
                wr.video_url AS routine_video_url,
                wr.cover_mode AS routine_cover_mode,
                wr.image_position AS routine_image_position
         FROM workout_sessions ws
         LEFT JOIN workout_routines wr ON wr.id = ws.routine_id AND wr.user_id = ws.user_id
         WHERE ws.id = :id AND ws.user_id = :u',
        [':id' => $id, ':u' => $userId]
    );
}

/** Start a session, optionally seeded from a routine's exercises. */
function wk_session_start(PDO $pdo, int $userId, ?int $routineId = null, string $title = ''): int
{
    $active = wk_session_active_for_user($pdo, $userId);
    if ($active !== null) {
        return (int) $active['id'];
    }
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
                'INSERT INTO session_exercises (session_id, exercise_def_id, sort_order, unit, rest_seconds, notes)
                 VALUES (:s, :e, :so, :un, :rest, :no)',
                [
                    ':s' => $sessionId,
                    ':e' => (int) $ex['exercise_def_id'],
                    ':so' => (int) $ex['sort_order'],
                    ':un' => (string) $ex['unit'],
                    ':rest' => $ex['rest_seconds'],
                    ':no' => (string) $ex['notes'],
                ]
            );
            $sessionExerciseId = (int) $pdo->lastInsertId();
            $targetSets = max(1, (int) $ex['target_sets']);
            for ($i = 1; $i <= $targetSets; $i++) {
                db_execute(
                    $pdo,
                'INSERT INTO workout_sets (session_exercise_id, set_index, reps, weight, duration, distance, completed, created_at)
                 VALUES (:se, :idx, :reps, :weight, :duration, :distance, 0, :now)',
                [
                    ':se' => $sessionExerciseId,
                    ':idx' => $i,
                    ':reps' => $ex['target_reps'],
                    ':weight' => $ex['target_weight'],
                    ':duration' => $ex['target_duration'],
                    ':distance' => $ex['target_distance'],
                    ':now' => now_iso(),
                ]
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
        'SELECT se.*, ed.name AS exercise_name, ed.muscle_group, ed.secondary_muscles,
                ed.exercise_type, ed.equipment, ed.difficulty, ed.slug, ed.guide_json,
                ed.rank_factor, ed.rankable, ed.image_path, ed.video_url, ed.cover_mode, ed.image_position, ed.visual_mark, ed.accent_color,
                ed.user_id AS exercise_owner_id, ed.is_system
         FROM session_exercises se
         JOIN exercise_definitions ed ON ed.id = se.exercise_def_id
         WHERE se.session_id = :s
         ORDER BY se.sort_order ASC, se.id ASC',
        [':s' => $sessionId]
    );
    foreach ($exercises as &$ex) {
        $ex['content'] = wk_exercise_content(array_merge($ex, ['name' => $ex['exercise_name']]));
        $ex['exercise_name'] = (string) ($ex['content']['name'] ?? $ex['exercise_name']);
        $ex['sets'] = db_fetch_all(
            $pdo,
            'SELECT * FROM workout_sets WHERE session_exercise_id = :se ORDER BY set_index ASC, id ASC',
            [':se' => (int) $ex['id']]
        );
    }
    unset($ex);

    return $exercises;
}

/**
 * Apply a new exercise sequence to an active session and remove explicitly
 * selected, still-unstarted exercises. Completed work is never deleted, even
 * when a crafted request includes its row ID.
 *
 * @param array<int,int|string> $orderedIds Session-exercise row IDs.
 * @param array<int,int|string> $removeIds Session-exercise row IDs to remove.
 */
function wk_session_exercises_organize(
    PDO $pdo,
    int $sessionId,
    int $userId,
    array $orderedIds,
    array $removeIds = []
): bool {
    $session = wk_session_get($pdo, $sessionId, $userId);
    if ($session === null || (string) ($session['status'] ?? '') !== 'active') {
        return false;
    }

    $rows = db_fetch_all(
        $pdo,
        'SELECT se.id,
                EXISTS(
                    SELECT 1 FROM workout_sets ws
                    WHERE ws.session_exercise_id = se.id AND ws.completed = 1
                ) AS has_completed_sets
         FROM session_exercises se
         WHERE se.session_id = :session
         ORDER BY se.sort_order ASC, se.id ASC',
        [':session' => $sessionId]
    );
    $currentIds = [];
    $removableIds = [];
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $currentIds[] = $id;
        if ((int) ($row['has_completed_sets'] ?? 0) !== 1) {
            $removableIds[$id] = true;
        }
    }
    $validIds = array_fill_keys($currentIds, true);
    $removed = [];
    foreach ($removeIds as $rawId) {
        $id = (int) $rawId;
        if ($id > 0 && isset($validIds[$id], $removableIds[$id])) {
            $removed[$id] = true;
        }
    }

    $normalized = [];
    $seen = [];
    foreach ($orderedIds as $rawId) {
        $id = (int) $rawId;
        if ($id <= 0 || isset($seen[$id]) || isset($removed[$id]) || !isset($validIds[$id])) {
            continue;
        }
        $normalized[] = $id;
        $seen[$id] = true;
    }
    foreach ($currentIds as $id) {
        if (!isset($seen[$id]) && !isset($removed[$id])) {
            $normalized[] = $id;
        }
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        foreach (array_keys($removed) as $id) {
            db_execute(
                $pdo,
                'DELETE FROM session_exercises WHERE id = :id AND session_id = :session',
                [':id' => $id, ':session' => $sessionId]
            );
        }
        foreach ($normalized as $index => $id) {
            db_execute(
                $pdo,
                'UPDATE session_exercises SET sort_order = :position WHERE id = :id AND session_id = :session',
                [':position' => $index + 1, ':id' => $id, ':session' => $sessionId]
            );
        }
        db_execute(
            $pdo,
            'UPDATE workout_sessions SET updated_at = :now WHERE id = :session AND user_id = :user AND status = "active"',
            [':now' => now_iso(), ':session' => $sessionId, ':user' => $userId]
        );
        if ($ownsTransaction) {
            $pdo->commit();
        }

        return true;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function wk_session_add_exercise(PDO $pdo, int $sessionId, int $exerciseDefId, ?int $userId = null): int
{
    $exercise = $userId !== null
        ? wk_exercise_get_for_user($pdo, $exerciseDefId, $userId)
        : wk_exercise_get($pdo, $exerciseDefId);
    if ($userId !== null) {
        $session = wk_session_get($pdo, $sessionId, $userId);
        if ($session === null || (string) ($session['status'] ?? '') !== 'active' || $exercise === null) {
            return 0;
        }
    } elseif ($exercise === null) {
        return 0;
    }
    $existing = db_fetch_one(
        $pdo,
        'SELECT id FROM session_exercises WHERE session_id = :s AND exercise_def_id = :e LIMIT 1',
        [':s' => $sessionId, ':e' => $exerciseDefId]
    );
    if ($existing !== null) {
        return (int) $existing['id'];
    }
    $defaults = wk_exercise_training_defaults($exercise);
    $order = db_fetch_one($pdo, 'SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM session_exercises WHERE session_id = :s', [':s' => $sessionId]);
    db_execute(
        $pdo,
        'INSERT INTO session_exercises (session_id, exercise_def_id, sort_order, unit, rest_seconds, notes)
         VALUES (:s, :e, :so, :unit, :rest, :notes)',
        [
            ':s' => $sessionId,
            ':e' => $exerciseDefId,
            ':so' => (int) ($order['n'] ?? 1),
            ':unit' => $defaults['unit'],
            ':rest' => $defaults['rest_seconds'],
            ':notes' => $defaults['notes'],
        ]
    );
    $seId = (int) $pdo->lastInsertId();
    for ($setIndex = 0; $setIndex < $defaults['target_sets']; $setIndex++) {
        wk_set_add($pdo, $seId, $userId, $defaults);
    }

    return $seId;
}

/** @param array<string,mixed> $initialValues */
function wk_set_add(PDO $pdo, int $sessionExerciseId, ?int $userId = null, array $initialValues = []): int
{
    if ($userId !== null) {
        $owned = db_fetch_one(
            $pdo,
            'SELECT se.id FROM session_exercises se
             JOIN workout_sessions s ON s.id = se.session_id
             WHERE se.id = :se AND s.user_id = :u AND s.status = "active"',
            [':se' => $sessionExerciseId, ':u' => $userId]
        );
        if ($owned === null) {
            return 0;
        }
    }
    $order = db_fetch_one($pdo, 'SELECT COALESCE(MAX(set_index), 0) + 1 AS n FROM workout_sets WHERE session_exercise_id = :se', [':se' => $sessionExerciseId]);
    $previous = db_fetch_one(
        $pdo,
        'SELECT reps, weight, duration, distance FROM workout_sets WHERE session_exercise_id = :se ORDER BY set_index DESC, id DESC LIMIT 1',
        [':se' => $sessionExerciseId]
    );
    db_execute(
        $pdo,
        'INSERT INTO workout_sets (session_exercise_id, set_index, reps, weight, duration, distance, completed, created_at)
         VALUES (:se, :idx, :reps, :weight, :duration, :distance, 0, :now)',
        [
            ':se' => $sessionExerciseId,
            ':idx' => (int) ($order['n'] ?? 1),
            ':reps' => $previous['reps'] ?? ($initialValues['target_reps'] ?? null),
            ':weight' => $previous['weight'] ?? ($initialValues['target_weight'] ?? null),
            ':duration' => $previous['duration'] ?? ($initialValues['target_duration'] ?? null),
            ':distance' => $previous['distance'] ?? ($initialValues['target_distance'] ?? null),
            ':now' => now_iso(),
        ]
    );

    return (int) $pdo->lastInsertId();
}

function wk_set_update(PDO $pdo, int $setId, array $fields, ?int $userId = null): bool
{
    if ($userId !== null) {
        $owned = db_fetch_one(
            $pdo,
            'SELECT ws.id FROM workout_sets ws
             JOIN session_exercises se ON se.id = ws.session_exercise_id
             JOIN workout_sessions s ON s.id = se.session_id
             WHERE ws.id = :id AND s.user_id = :u AND s.status = "active"',
            [':id' => $setId, ':u' => $userId]
        );
        if ($owned === null) {
            return false;
        }
    }
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

/* ---------------------------------------------------------------------------
 * Ranked training — personal exercise scores, body-part ranks and leaderboard.
 * ------------------------------------------------------------------------- */

/** @return array<string,array{threshold:float,color:string}> */
function wk_rank_tiers(): array
{
    $fallback = wk_default_rank_tiers();
    $rankPdo = db_current();
    if (!$rankPdo instanceof PDO) {
        return $fallback;
    }
    try {
        $rows = db_fetch_all($rankPdo, 'SELECT * FROM workout_rank_tiers WHERE active = 1 ORDER BY sort_order ASC, threshold ASC');
    } catch (Throwable) {
        return $fallback;
    }
    if ($rows === []) {
        return $fallback;
    }
    $tiers = [];
    foreach ($rows as $row) {
        $key = trim((string) ($row['tier_key'] ?? ''));
        if ($key === '') {
            continue;
        }
        $tiers[$key] = [
            'threshold' => (float) ($row['threshold'] ?? 0),
            'color' => (string) ($row['color'] ?? '#64748b'),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
        ];
    }

    return $tiers !== [] ? $tiers : $fallback;
}

/** @param array<string,mixed> $tiers */
function wk_admin_save_rank_tiers(PDO $pdo, array $tiers, int $actorUserId): void
{
    workouts_ensure_schema($pdo);
    foreach ($tiers as $key => $row) {
        $key = trim((string) $key);
        if ($key === '' || preg_match('/^[a-z0-9_]+$/', $key) !== 1 || !is_array($row)) {
            continue;
        }
        $color = trim((string) ($row['color'] ?? '#64748b'));
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $color) !== 1) {
            $color = '#64748b';
        }
        db_execute(
            $pdo,
            'INSERT INTO workout_rank_tiers (tier_key, threshold, color, sort_order, active, updated_at)
             VALUES (:key, :threshold, :color, :sort_order, :active, :updated_at)
             ON CONFLICT(tier_key) DO UPDATE SET threshold = excluded.threshold, color = excluded.color,
                 sort_order = excluded.sort_order, active = excluded.active, updated_at = excluded.updated_at',
            [
                ':key' => $key,
                ':threshold' => max(0, (float) ($row['threshold'] ?? 0)),
                ':color' => $color,
                ':sort_order' => (int) ($row['sort_order'] ?? 0),
                ':active' => $key === 'unranked' || !empty($row['active']) ? 1 : 0,
                ':updated_at' => now_iso(),
            ]
        );
    }
    audit_log($pdo, $actorUserId, 'training_rank_tiers_updated', 'workout_rank_tier', 'all', 'Rank tiers updated.', null, $tiers);
}

/** @return array{key:string,score:float,progress:int,next_key:?string,next_score:?float,color:string} */
function wk_rank_from_score(float $score): array
{
    $score = max(0.0, round($score, 1));
    $tiers = wk_rank_tiers();
    $keys = array_keys($tiers);
    $currentIndex = 0;
    foreach ($keys as $index => $key) {
        if ($score >= (float) $tiers[$key]['threshold']) {
            $currentIndex = $index;
        }
    }
    $key = $score <= 0 ? 'unranked' : $keys[$currentIndex];
    $currentIndex = array_search($key, $keys, true);
    $currentIndex = is_int($currentIndex) ? $currentIndex : 0;
    $nextKey = $keys[$currentIndex + 1] ?? null;
    $start = (float) $tiers[$key]['threshold'];
    $end = $nextKey !== null ? (float) $tiers[$nextKey]['threshold'] : max($start + 1.0, $score);
    $progress = $score <= 0 ? 0 : ($nextKey === null ? 100 : (int) round(min(1.0, max(0.0, ($score - $start) / max(1.0, $end - $start))) * 100));

    return [
        'key' => $key,
        'score' => $score,
        'progress' => $progress,
        'next_key' => $nextKey,
        'next_score' => $nextKey !== null ? $end : null,
        'color' => (string) $tiers[$key]['color'],
    ];
}

function wk_user_bodyweight(PDO $pdo, int $userId): ?float
{
    $row = db_fetch_one(
        $pdo,
        'SELECT weight FROM daily_logs
         WHERE user_id = :u AND weight IS NOT NULL AND weight > 0
         ORDER BY log_date DESC, id DESC LIMIT 1',
        [':u' => $userId]
    );
    if ($row !== null && (float) ($row['weight'] ?? 0) > 0) {
        return (float) $row['weight'];
    }
    $user = db_fetch_one($pdo, 'SELECT ideal_weight FROM users WHERE id = :u', [':u' => $userId]);
    $ideal = (float) ($user['ideal_weight'] ?? 0);

    return $ideal > 0 ? $ideal : null;
}

/** @return array<int,array<string,mixed>> */
function wk_exercise_ranks_for_user(PDO $pdo, int $userId): array
{
    $records = db_fetch_all(
        $pdo,
        'SELECT exercise_def_id, metric, MAX(value) AS value
         FROM personal_records WHERE user_id = :u
         GROUP BY exercise_def_id, metric',
        [':u' => $userId]
    );
    $byExercise = [];
    foreach ($records as $record) {
        $byExercise[(int) $record['exercise_def_id']][(string) $record['metric']] = (float) $record['value'];
    }
    $bodyweight = wk_user_bodyweight($pdo, $userId);
    $result = [];
    foreach (wk_exercises_for_user($pdo, $userId) as $exercise) {
        $id = (int) $exercise['id'];
        $exerciseRecords = (array) ($byExercise[$id] ?? []);
        $estOneRm = (float) ($exerciseRecords['est_1rm'] ?? 0.0);
        $maxReps = (float) ($exerciseRecords['max_reps'] ?? 0.0);
        $factor = max(0.01, (float) ($exercise['rank_factor'] ?? 1.0));
        $rankable = (int) ($exercise['rankable'] ?? 1) === 1;
        $score = 0.0;
        $metric = '';
        $value = 0.0;
        $requiresWeight = false;
        if ($rankable && $estOneRm > 0) {
            $metric = 'est_1rm';
            $value = $estOneRm;
            if ($bodyweight !== null && $bodyweight > 0) {
                $score = ($estOneRm / ($bodyweight * $factor)) * 100;
            } else {
                $requiresWeight = true;
            }
        } elseif ($rankable && $maxReps > 0 && in_array((string) ($exercise['exercise_type'] ?? ''), ['bodyweight', 'isometric'], true)) {
            $metric = 'max_reps';
            $value = $maxReps;
            $score = min(220.0, $maxReps * 5.0);
        }
        $exercise['rank'] = array_merge(wk_rank_from_score($score), [
            'metric' => $metric,
            'value' => $value,
            'requires_weight' => $requiresWeight,
            'rankable' => $rankable,
        ]);
        $result[] = $exercise;
    }

    usort($result, static function (array $a, array $b): int {
        $scoreCompare = (float) ($b['rank']['score'] ?? 0) <=> (float) ($a['rank']['score'] ?? 0);
        return $scoreCompare !== 0 ? $scoreCompare : strcasecmp((string) ($a['display_name'] ?? ''), (string) ($b['display_name'] ?? ''));
    });

    return $result;
}

/** @return array<int,array<string,mixed>> */
function wk_muscle_ranks_for_user(PDO $pdo, int $userId): array
{
    $groups = [];
    foreach (wk_muscle_groups() as $muscle) {
        if ($muscle === 'cardio') {
            continue;
        }
        $groups[$muscle] = ['muscle' => $muscle, 'catalog_count' => 0, 'ranked_count' => 0, 'score_sum' => 0.0, 'top_exercises' => []];
    }
    foreach (wk_exercise_ranks_for_user($pdo, $userId) as $exercise) {
        $muscle = (string) ($exercise['muscle_group'] ?? '');
        if (!isset($groups[$muscle]) || !(bool) ($exercise['rank']['rankable'] ?? false)) {
            continue;
        }
        $groups[$muscle]['catalog_count']++;
        $score = (float) ($exercise['rank']['score'] ?? 0.0);
        if ($score > 0) {
            $groups[$muscle]['ranked_count']++;
            $groups[$muscle]['score_sum'] += $score;
            if (count($groups[$muscle]['top_exercises']) < 3) {
                $groups[$muscle]['top_exercises'][] = (string) ($exercise['display_name'] ?? $exercise['name']);
            }
        }
    }
    $result = [];
    foreach ($groups as $group) {
        $score = (int) $group['ranked_count'] > 0 ? (float) $group['score_sum'] / (int) $group['ranked_count'] : 0.0;
        unset($group['score_sum']);
        $group['rank'] = wk_rank_from_score($score);
        $result[] = $group;
    }

    return $result;
}

/** @return array<string,mixed> */
function wk_overall_rank_for_user(PDO $pdo, int $userId): array
{
    $muscles = wk_muscle_ranks_for_user($pdo, $userId);
    $ranked = array_values(array_filter($muscles, static fn(array $row): bool => (float) ($row['rank']['score'] ?? 0) > 0));
    $rankable = array_values(array_filter($muscles, static fn(array $row): bool => (int) ($row['catalog_count'] ?? 0) > 0));
    $score = $ranked === [] || $rankable === []
        ? 0.0
        : array_sum(array_map(static fn(array $row): float => (float) $row['rank']['score'], $ranked)) / count($rankable);

    return array_merge(wk_rank_from_score($score), [
        'body_parts_ranked' => count($ranked),
        'body_parts_total' => count($rankable),
    ]);
}

/** @return array<int,array<string,mixed>> */
function wk_rank_leaderboard(PDO $pdo, int $limit = 20): array
{
    $users = db_fetch_all($pdo, 'SELECT id, display_name, username, avatar_path FROM users WHERE active = 1 ORDER BY display_name COLLATE NOCASE ASC');
    $rows = [];
    foreach ($users as $user) {
        $user['rank'] = wk_overall_rank_for_user($pdo, (int) $user['id']);
        $rows[] = $user;
    }
    usort($rows, static function (array $a, array $b): int {
        $scoreCompare = (float) ($b['rank']['score'] ?? 0) <=> (float) ($a['rank']['score'] ?? 0);
        return $scoreCompare !== 0 ? $scoreCompare : strcasecmp((string) $a['display_name'], (string) $b['display_name']);
    });
    foreach ($rows as $index => &$row) {
        $row['position'] = $index + 1;
    }
    unset($row);

    return array_slice($rows, 0, max(1, min(100, $limit)));
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
