<?php

declare(strict_types=1);

/**
 * Quests (#14) — daily and weekly missions that reward consistency.
 *
 * Anti-cheat by construction:
 *  - Progress is never self-reported. It is DERIVED on read from data the user
 *    already logged (daily_logs, daily_log_habits, workout_sessions), so there
 *    is no "claim" endpoint to spam.
 *  - XP is granted through xp_award() with a unique_key of
 *    "quest:<key>:<period_key>", and xp_events has a UNIQUE index on it, so a
 *    quest can pay out exactly once no matter how often the page is loaded.
 *  - Rewards are tied to a period, so repeating a valueless action inside the
 *    same day/week cannot farm XP.
 */

function quests_ensure_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS user_quests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            quest_key TEXT NOT NULL,
            period TEXT NOT NULL,
            period_key TEXT NOT NULL,
            target REAL NOT NULL DEFAULT 1,
            xp_reward INTEGER NOT NULL DEFAULT 10,
            completed_at TEXT,
            created_at TEXT NOT NULL,
            UNIQUE (user_id, quest_key, period_key),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_quests_user ON user_quests(user_id, period_key)');
}

/** Period keys: daily = Y-m-d, weekly = ISO year-week. */
function quests_period_key(string $period, ?string $date = null): string
{
    $d = $date ?? date('Y-m-d');
    try {
        $dt = new DateTimeImmutable($d);
    } catch (Throwable) {
        $dt = new DateTimeImmutable('today');
    }

    return $period === 'weekly' ? $dt->format('o-\WW') : $dt->format('Y-m-d');
}

/**
 * Quest catalogue. `target` may be a closure resolved per user (e.g. their own
 * step goal), so quests scale to the person rather than a global number.
 *
 * @return array<string,array<string,mixed>>
 */
function quests_catalogue(): array
{
    return [
        // --- daily ---
        'daily_log' => [
            'period' => 'daily', 'xp' => 10, 'icon' => 'check',
            'label' => 'quests.daily_log', 'target' => 1,
        ],
        'daily_steps' => [
            'period' => 'daily', 'xp' => 20, 'icon' => 'spark',
            'label' => 'quests.daily_steps', 'target' => 'step_goal',
        ],
        'daily_workout' => [
            'period' => 'daily', 'xp' => 25, 'icon' => 'dumbbell',
            'label' => 'quests.daily_workout', 'target' => 1,
        ],
        'daily_habits' => [
            'period' => 'daily', 'xp' => 15, 'icon' => 'target',
            'label' => 'quests.daily_habits', 'target' => 2,
        ],
        // --- weekly ---
        'weekly_workouts' => [
            'period' => 'weekly', 'xp' => 60, 'icon' => 'dumbbell',
            'label' => 'quests.weekly_workouts', 'target' => 3,
        ],
        'weekly_active_days' => [
            'period' => 'weekly', 'xp' => 70, 'icon' => 'trophy',
            'label' => 'quests.weekly_active_days', 'target' => 5,
        ],
    ];
}

/** Resolve a quest's target for one user (supports per-user goals). */
function quests_resolve_target(array $quest, array $user): float
{
    $target = $quest['target'];
    if ($target === 'step_goal') {
        $goal = (int) ($user['step_goal'] ?? 0);

        return (float) ($goal > 0 ? $goal : 10000);
    }

    return (float) $target;
}

/**
 * Current progress for a quest, computed from data the user actually logged.
 * Never trusts client input.
 */
function quests_progress(PDO $pdo, int $userId, string $key, string $period): float
{
    $today = date('Y-m-d');
    try {
        $weekStart = (new DateTimeImmutable('monday this week'))->format('Y-m-d');
        $weekEnd = (new DateTimeImmutable('sunday this week'))->format('Y-m-d');
    } catch (Throwable) {
        $weekStart = $today;
        $weekEnd = $today;
    }

    switch ($key) {
        case 'daily_log':
            $r = db_fetch_one(
                $pdo,
                'SELECT COUNT(*) AS c FROM daily_logs WHERE user_id = :u AND log_date = :d
                 AND (COALESCE(steps,0) > 0 OR workout_done = 1 OR COALESCE(distance_km,0) > 0 OR weight IS NOT NULL)',
                [':u' => $userId, ':d' => $today]
            );

            return (float) min(1, (int) ($r['c'] ?? 0));

        case 'daily_steps':
            $r = db_fetch_one(
                $pdo,
                'SELECT COALESCE(steps,0) AS v FROM daily_logs WHERE user_id = :u AND log_date = :d',
                [':u' => $userId, ':d' => $today]
            );

            return (float) ($r['v'] ?? 0);

        case 'daily_workout':
            $r = db_fetch_one(
                $pdo,
                'SELECT COUNT(*) AS c FROM daily_logs WHERE user_id = :u AND log_date = :d AND workout_done = 1',
                [':u' => $userId, ':d' => $today]
            );

            return (float) min(1, (int) ($r['c'] ?? 0));

        case 'daily_habits':
            $r = db_fetch_one(
                $pdo,
                'SELECT COUNT(*) AS c FROM daily_log_habits h
                 JOIN daily_logs l ON l.id = h.log_id
                 WHERE l.user_id = :u AND l.log_date = :d AND h.value = 1',
                [':u' => $userId, ':d' => $today]
            );

            return (float) (int) ($r['c'] ?? 0);

        case 'weekly_workouts':
            $r = db_fetch_one(
                $pdo,
                'SELECT COUNT(*) AS c FROM daily_logs
                 WHERE user_id = :u AND workout_done = 1 AND log_date BETWEEN :a AND :b',
                [':u' => $userId, ':a' => $weekStart, ':b' => $weekEnd]
            );

            return (float) (int) ($r['c'] ?? 0);

        case 'weekly_active_days':
            $r = db_fetch_one(
                $pdo,
                'SELECT COUNT(DISTINCT log_date) AS c FROM daily_logs
                 WHERE user_id = :u AND log_date BETWEEN :a AND :b
                   AND (COALESCE(steps,0) > 0 OR workout_done = 1 OR COALESCE(distance_km,0) > 0)',
                [':u' => $userId, ':a' => $weekStart, ':b' => $weekEnd]
            );

            return (float) (int) ($r['c'] ?? 0);
    }

    return 0.0;
}

/**
 * Build the user's live quest board: ensures a row per quest/period, recomputes
 * progress from real data, and pays out XP exactly once when a quest completes.
 *
 * @return array<int,array<string,mixed>>
 */
function quests_for_user(PDO $pdo, array $user): array
{
    quests_ensure_schema($pdo);
    $userId = (int) ($user['id'] ?? 0);
    if ($userId <= 0) {
        return [];
    }

    $now = now_iso();
    $board = [];

    foreach (quests_catalogue() as $key => $quest) {
        $period = (string) $quest['period'];
        $periodKey = quests_period_key($period);
        $target = quests_resolve_target($quest, $user);

        db_execute(
            $pdo,
            'INSERT OR IGNORE INTO user_quests (user_id, quest_key, period, period_key, target, xp_reward, created_at)
             VALUES (:u, :k, :p, :pk, :t, :xp, :now)',
            [':u' => $userId, ':k' => $key, ':p' => $period, ':pk' => $periodKey,
             ':t' => $target, ':xp' => (int) $quest['xp'], ':now' => $now]
        );

        $row = db_fetch_one(
            $pdo,
            'SELECT * FROM user_quests WHERE user_id = :u AND quest_key = :k AND period_key = :pk',
            [':u' => $userId, ':k' => $key, ':pk' => $periodKey]
        );
        if ($row === null) {
            continue;
        }

        $progress = quests_progress($pdo, $userId, $key, $period);
        $done = $progress >= $target;

        // Pay out once. xp_events has a UNIQUE unique_key, so even if two
        // requests race here only one award can ever land.
        if ($done && empty($row['completed_at'])) {
            xp_award(
                $pdo,
                $userId,
                (int) $row['xp_reward'],
                'quest:' . $key,
                'quest:' . $key . ':' . $periodKey
            );
            db_execute(
                $pdo,
                'UPDATE user_quests SET completed_at = :now WHERE id = :id AND completed_at IS NULL',
                [':now' => $now, ':id' => (int) $row['id']]
            );
            $row['completed_at'] = $now;
            celebration_enqueue(
                $pdo,
                $userId,
                'quest',
                $key . ':' . $periodKey,
                t((string) $quest['label'], ['n' => (int) round($target)]),
                (int) $row['xp_reward']
            );
        }

        $board[] = [
            'key' => $key,
            'period' => $period,
            'icon' => (string) $quest['icon'],
            'label' => t((string) $quest['label'], ['n' => (int) round($target)]),
            'progress' => $progress,
            'target' => $target,
            'pct' => $target > 0 ? (int) min(100, round(($progress / $target) * 100)) : 0,
            'xp' => (int) $row['xp_reward'],
            'completed' => !empty($row['completed_at']),
        ];
    }

    return $board;
}

/* ---------------------------------------------------------------------------
 * Ranks & streaks
 * ------------------------------------------------------------------------- */

/**
 * Rank tier derived from level. Purely cosmetic progression on top of XP, so it
 * cannot be farmed independently.
 *
 * @return array{key:string,label:string,min_level:int,next_level:?int}
 */
function quests_rank_for_level(int $level): array
{
    $tiers = [
        ['key' => 'bronze', 'min' => 1],
        ['key' => 'silver', 'min' => 5],
        ['key' => 'gold', 'min' => 10],
        ['key' => 'platinum', 'min' => 18],
        ['key' => 'diamond', 'min' => 28],
        ['key' => 'legend', 'min' => 40],
    ];
    $current = $tiers[0];
    $next = null;
    foreach ($tiers as $i => $tier) {
        if ($level >= $tier['min']) {
            $current = $tier;
            $next = $tiers[$i + 1] ?? null;
        }
    }

    return [
        'key' => $current['key'],
        'label' => t('rank.' . $current['key']),
        'min_level' => (int) $current['min'],
        'next_level' => $next !== null ? (int) $next['min'] : null,
    ];
}

/** Consecutive days (ending today or yesterday) with a real logged day. */
function quests_active_streak(PDO $pdo, int $userId): int
{
    $rows = db_fetch_all(
        $pdo,
        'SELECT DISTINCT log_date AS d FROM daily_logs
         WHERE user_id = :u
           AND (COALESCE(steps,0) > 0 OR workout_done = 1 OR COALESCE(distance_km,0) > 0)
         ORDER BY d DESC LIMIT 400',
        [':u' => $userId]
    );
    if ($rows === []) {
        return 0;
    }
    $days = array_map(static fn ($r) => (string) $r['d'], $rows);
    $today = new DateTimeImmutable('today');
    $expected = $today;
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

/* ---------------------------------------------------------------------------
 * Badges (#14 phase 2)
 *
 * Same anti-farm contract as quests: a badge is EARNED by a condition read from
 * real data, stored once (UNIQUE user+badge), and its XP bonus goes through
 * xp_award() with a unique_key so it can never pay twice.
 * ------------------------------------------------------------------------- */

function badges_ensure_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS user_badges (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            badge_key TEXT NOT NULL,
            earned_at TEXT NOT NULL,
            UNIQUE (user_id, badge_key),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )'
    );
}

/**
 * Badge catalogue. Each entry declares how its progress is measured from real
 * data, so a locked badge can still be shown as "3/7".
 *
 * @return array<string,array<string,mixed>>
 */
function badges_catalogue(): array
{
    return [
        'streak_7' => ['tier' => 'bronze', 'xp' => 50, 'icon' => 'spark', 'target' => 7, 'metric' => 'streak'],
        'streak_30' => ['tier' => 'gold', 'xp' => 200, 'icon' => 'trophy', 'target' => 30, 'metric' => 'streak'],
        'workouts_10' => ['tier' => 'bronze', 'xp' => 60, 'icon' => 'dumbbell', 'target' => 10, 'metric' => 'workouts'],
        'workouts_50' => ['tier' => 'gold', 'xp' => 250, 'icon' => 'dumbbell', 'target' => 50, 'metric' => 'workouts'],
        'level_10' => ['tier' => 'silver', 'xp' => 100, 'icon' => 'medal', 'target' => 10, 'metric' => 'level'],
        'quests_25' => ['tier' => 'silver', 'xp' => 120, 'icon' => 'check', 'target' => 25, 'metric' => 'quests'],
    ];
}

/** Current value of the metric a badge tracks. Real data only. */
function badges_metric_value(PDO $pdo, int $userId, string $metric): float
{
    if ($metric === 'streak') {
        return (float) quests_active_streak($pdo, $userId);
    }
    if ($metric === 'workouts') {
        $r = db_fetch_one($pdo, 'SELECT COUNT(*) AS c FROM daily_logs WHERE user_id = :u AND workout_done = 1', [':u' => $userId]);

        return (float) (int) ($r['c'] ?? 0);
    }
    if ($metric === 'level') {
        return (float) (int) (xp_user_level_info($pdo, $userId)['level'] ?? 1);
    }
    if ($metric === 'quests') {
        $r = db_fetch_one($pdo, 'SELECT COUNT(*) AS c FROM user_quests WHERE user_id = :u AND completed_at IS NOT NULL', [':u' => $userId]);

        return (float) (int) ($r['c'] ?? 0);
    }

    return 0.0;
}

/**
 * Evaluate every badge: award newly earned ones (once) and return the full
 * board, so locked badges can still show progress.
 *
 * @return array<int,array<string,mixed>>
 */
function badges_for_user(PDO $pdo, int $userId): array
{
    badges_ensure_schema($pdo);
    quests_ensure_schema($pdo);

    $earned = [];
    foreach (db_fetch_all($pdo, 'SELECT badge_key, earned_at FROM user_badges WHERE user_id = :u', [':u' => $userId]) as $row) {
        $earned[(string) $row['badge_key']] = (string) $row['earned_at'];
    }

    $board = [];
    $now = now_iso();

    foreach (badges_catalogue() as $key => $badge) {
        $value = badges_metric_value($pdo, $userId, (string) $badge['metric']);
        $target = (float) $badge['target'];
        $has = isset($earned[$key]);

        if (!$has && $value >= $target) {
            db_execute(
                $pdo,
                'INSERT OR IGNORE INTO user_badges (user_id, badge_key, earned_at) VALUES (:u, :k, :now)',
                [':u' => $userId, ':k' => $key, ':now' => $now]
            );
            // Bonus XP, idempotent: a badge is earned exactly once, ever.
            xp_award($pdo, $userId, (int) $badge['xp'], 'badge:' . $key, 'badge:' . $key);
            celebration_enqueue($pdo, $userId, 'badge', $key, t('badge.' . $key), (int) $badge['xp']);
            $has = true;
            $earned[$key] = $now;
        }

        $board[] = [
            'key' => $key,
            'tier' => (string) $badge['tier'],
            'icon' => (string) $badge['icon'],
            'label' => t('badge.' . $key),
            'xp' => (int) $badge['xp'],
            'value' => $value,
            'target' => $target,
            'pct' => $target > 0 ? (int) min(100, round(($value / $target) * 100)) : 0,
            'earned' => $has,
            'earned_at' => $earned[$key] ?? null,
        ];
    }

    return $board;
}

/* ---------------------------------------------------------------------------
 * Team missions — one shared weekly goal the whole team contributes to.
 * ------------------------------------------------------------------------- */

/** @return array<string,array<string,mixed>> */
function team_missions_catalogue(): array
{
    return [
        'team_active_days' => ['xp' => 40, 'icon' => 'users', 'per_member' => 4],
        'team_workouts' => ['xp' => 50, 'icon' => 'dumbbell', 'per_member' => 3],
    ];
}

/**
 * Weekly team mission board. The target scales with team size (per_member x
 * members) so a big team is not trivially easier than a small one, and every
 * member's contribution is listed so nobody free-rides invisibly.
 *
 * @return array<int,array<string,mixed>>
 */
function team_missions_for_team(PDO $pdo, int $teamId): array
{
    $members = db_fetch_all(
        $pdo,
        'SELECT u.id, u.display_name FROM team_memberships m
         JOIN users u ON u.id = m.user_id
         WHERE m.team_id = :t AND m.active = 1 AND u.active = 1',
        [':t' => $teamId]
    );
    if ($members === []) {
        return [];
    }
    $ids = array_map(static fn ($m) => (int) $m['id'], $members);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    try {
        $weekStart = (new DateTimeImmutable('monday this week'))->format('Y-m-d');
        $weekEnd = (new DateTimeImmutable('sunday this week'))->format('Y-m-d');
    } catch (Throwable) {
        $weekStart = date('Y-m-d');
        $weekEnd = $weekStart;
    }

    $board = [];
    foreach (team_missions_catalogue() as $key => $mission) {
        $target = (float) ((int) $mission['per_member'] * count($ids));

        $sql = $key === 'team_workouts'
            ? 'SELECT user_id, COUNT(*) AS v FROM daily_logs
               WHERE workout_done = 1 AND log_date BETWEEN ? AND ? AND user_id IN (' . $placeholders . ')
               GROUP BY user_id'
            : 'SELECT user_id, COUNT(DISTINCT log_date) AS v FROM daily_logs
               WHERE log_date BETWEEN ? AND ?
                 AND (COALESCE(steps,0) > 0 OR workout_done = 1 OR COALESCE(distance_km,0) > 0)
                 AND user_id IN (' . $placeholders . ')
               GROUP BY user_id';

        $rows = db_fetch_all($pdo, $sql, array_merge([$weekStart, $weekEnd], $ids));
        $byUser = [];
        foreach ($rows as $r) {
            $byUser[(int) $r['user_id']] = (int) $r['v'];
        }

        $total = (float) array_sum($byUser);
        $contributions = [];
        foreach ($members as $m) {
            $contributions[] = [
                'name' => (string) $m['display_name'],
                'value' => (int) ($byUser[(int) $m['id']] ?? 0),
            ];
        }
        usort($contributions, static fn ($a, $b) => $b['value'] <=> $a['value']);

        $board[] = [
            'key' => $key,
            'icon' => (string) $mission['icon'],
            'label' => t('team_mission.' . $key, ['n' => (int) $target]),
            'progress' => $total,
            'target' => $target,
            'pct' => $target > 0 ? (int) min(100, round(($total / $target) * 100)) : 0,
            'completed' => $total >= $target,
            'contributions' => $contributions,
        ];
    }

    return $board;
}

/* ---------------------------------------------------------------------------
 * Seasons (#14 phase 3)
 *
 * A season is just a time window. Because xp_events is an append-only ledger
 * with created_at, "season XP" is derived by summing the ledger inside that
 * window — there is no second XP counter to keep in sync, and a season reset
 * never destroys history: lifetime XP and levels are untouched.
 * ------------------------------------------------------------------------- */

function seasons_ensure_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS seasons (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            season_key TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            start_date TEXT NOT NULL,
            end_date TEXT NOT NULL,
            created_at TEXT NOT NULL
        )'
    );
}

/**
 * The season covering a date, created on demand. Seasons are quarterly, so they
 * roll over on their own without an admin having to remember to open one.
 *
 * @return array<string,mixed>
 */
function seasons_current(PDO $pdo, ?string $date = null): array
{
    seasons_ensure_schema($pdo);

    try {
        $dt = new DateTimeImmutable($date ?? 'today');
    } catch (Throwable) {
        $dt = new DateTimeImmutable('today');
    }

    $year = (int) $dt->format('Y');
    $quarter = (int) ceil(((int) $dt->format('n')) / 3);
    $key = $year . '-Q' . $quarter;

    $existing = db_fetch_one($pdo, 'SELECT * FROM seasons WHERE season_key = :k', [':k' => $key]);
    if ($existing !== null) {
        return $existing;
    }

    $startMonth = ($quarter - 1) * 3 + 1;
    $start = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $startMonth)));
    $end = $start->modify('+3 months')->modify('-1 day');

    db_execute(
        $pdo,
        'INSERT OR IGNORE INTO seasons (season_key, name, start_date, end_date, created_at)
         VALUES (:k, :n, :s, :e, :now)',
        [
            ':k' => $key,
            ':n' => t('season.name', ['q' => $quarter, 'y' => $year]),
            ':s' => $start->format('Y-m-d'),
            ':e' => $end->format('Y-m-d'),
            ':now' => now_iso(),
        ]
    );

    $row = db_fetch_one($pdo, 'SELECT * FROM seasons WHERE season_key = :k', [':k' => $key]);

    return $row ?? [
        'season_key' => $key,
        'name' => $key,
        'start_date' => $start->format('Y-m-d'),
        'end_date' => $end->format('Y-m-d'),
    ];
}

/** Days left in a season (0 on the final day). */
function season_days_left(array $season): int
{
    try {
        $end = new DateTimeImmutable((string) ($season['end_date'] ?? 'today'));
        $today = new DateTimeImmutable('today');
    } catch (Throwable) {
        return 0;
    }

    return max(0, (int) $today->diff($end)->days * ($end >= $today ? 1 : 0));
}

/** XP a user earned inside the season window (derived from the ledger). */
function season_xp_for_user(PDO $pdo, int $userId, array $season): int
{
    $row = db_fetch_one(
        $pdo,
        'SELECT COALESCE(SUM(amount), 0) AS total FROM xp_events
         WHERE user_id = :u AND DATE(created_at) BETWEEN :a AND :b',
        [':u' => $userId, ':a' => (string) $season['start_date'], ':b' => (string) $season['end_date']]
    );

    return (int) ($row['total'] ?? 0);
}

/**
 * Season leaderboard: every active user ranked by XP earned this season.
 * Lifetime XP is deliberately NOT used, so a newcomer can still win a season.
 *
 * @return array<int,array<string,mixed>>
 */
function season_leaderboard(PDO $pdo, array $season, int $limit = 10): array
{
    $rows = db_fetch_all(
        $pdo,
        'SELECT u.id, u.display_name, COALESCE(SUM(e.amount), 0) AS season_xp
         FROM users u
         LEFT JOIN xp_events e
           ON e.user_id = u.id AND DATE(e.created_at) BETWEEN :a AND :b
         WHERE u.active = 1
         GROUP BY u.id
         ORDER BY season_xp DESC, u.display_name COLLATE NOCASE ASC
         LIMIT ' . max(1, min(50, $limit)),
        [':a' => (string) $season['start_date'], ':b' => (string) $season['end_date']]
    );

    $out = [];
    $rank = 0;
    foreach ($rows as $r) {
        $rank++;
        $out[] = [
            'rank' => $rank,
            'user_id' => (int) $r['id'],
            'name' => (string) $r['display_name'],
            'xp' => (int) $r['season_xp'],
        ];
    }

    return $out;
}

/* ---------------------------------------------------------------------------
 * Celebrations
 *
 * A tiny outbox. The two places that can *newly* unlock something (a quest
 * completing, a badge being earned) enqueue a row here; the layout drains the
 * queue once and shows a toast. UNIQUE(user_id, kind, ckey) plus a shown_at
 * stamp means a reload can never replay a celebration — same guarantee the XP
 * ledger gives, so the toast and the payout can never disagree.
 * ------------------------------------------------------------------------- */

function celebrations_ensure_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS celebrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            kind TEXT NOT NULL,
            ckey TEXT NOT NULL,
            label TEXT NOT NULL,
            xp INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            shown_at TEXT,
            UNIQUE (user_id, kind, ckey),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )'
    );
}

function celebration_enqueue(PDO $pdo, int $userId, string $kind, string $key, string $label, int $xp): void
{
    celebrations_ensure_schema($pdo);
    db_execute(
        $pdo,
        'INSERT OR IGNORE INTO celebrations (user_id, kind, ckey, label, xp, created_at)
         VALUES (:u, :kind, :k, :l, :xp, :now)',
        [':u' => $userId, ':kind' => $kind, ':k' => $key, ':l' => $label, ':xp' => $xp, ':now' => now_iso()]
    );
}

/**
 * Drain: return what has not been shown yet and immediately stamp it, so the
 * same unlock is celebrated exactly once even across two open tabs.
 *
 * @return array<int,array<string,mixed>>
 */
function celebrations_drain(PDO $pdo, int $userId): array
{
    celebrations_ensure_schema($pdo);

    $rows = db_fetch_all(
        $pdo,
        'SELECT id, kind, ckey, label, xp FROM celebrations
         WHERE user_id = :u AND shown_at IS NULL
         ORDER BY id ASC LIMIT 5',
        [':u' => $userId]
    );
    if ($rows === []) {
        return [];
    }

    $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        'UPDATE celebrations SET shown_at = ? WHERE id IN (' . $placeholders . ') AND shown_at IS NULL'
    );
    $stmt->execute(array_merge([now_iso()], $ids));

    return $rows;
}

/* ---------------------------------------------------------------------------
 * Cosmetics
 *
 * Purely visual rewards (avatar frames). They cannot be bought or claimed: a
 * frame is unlocked iff the user already meets a requirement that is itself
 * derived from real progress (level, or an earned badge). So cosmetics add no
 * new surface to farm - they only re-express progress that already happened.
 * ------------------------------------------------------------------------- */

/**
 * @return array<string,array{label:string,require:string,value:mixed}>
 */
function cosmetics_catalogue(): array
{
    return [
        'none' => ['label' => 'cosmetic.none', 'require' => 'always', 'value' => null],
        'steel' => ['label' => 'cosmetic.steel', 'require' => 'level', 'value' => 3],
        'ember' => ['label' => 'cosmetic.ember', 'require' => 'level', 'value' => 8],
        'aurora' => ['label' => 'cosmetic.aurora', 'require' => 'level', 'value' => 15],
        'gold' => ['label' => 'cosmetic.gold', 'require' => 'level', 'value' => 25],
        'legend' => ['label' => 'cosmetic.legend', 'require' => 'level', 'value' => 40],
        'iron' => ['label' => 'cosmetic.iron', 'require' => 'badge', 'value' => 'workouts_10'],
        'streaker' => ['label' => 'cosmetic.streaker', 'require' => 'badge', 'value' => 'streak_7'],
        // Second wave. Same rule as the first: every frame hangs off progress that was
        // already earned somewhere else, so cosmetics never become a thing to farm.
        'mint' => ['label' => 'cosmetic.mint', 'require' => 'level', 'value' => 5],
        'ocean' => ['label' => 'cosmetic.ocean', 'require' => 'level', 'value' => 12],
        'sunset' => ['label' => 'cosmetic.sunset', 'require' => 'level', 'value' => 20],
        'carbon' => ['label' => 'cosmetic.carbon', 'require' => 'level', 'value' => 32],
        'titan' => ['label' => 'cosmetic.titan', 'require' => 'badge', 'value' => 'workouts_50'],
        'marathon' => ['label' => 'cosmetic.marathon', 'require' => 'badge', 'value' => 'streak_30'],
    ];
}

function cosmetics_normalize(string $key): string
{
    return array_key_exists($key, cosmetics_catalogue()) ? $key : 'none';
}

/**
 * The catalogue with each entry resolved against this user's real progress.
 *
 * @return array<int,array<string,mixed>>
 */
function cosmetics_for_user(PDO $pdo, array $user): array
{
    badges_ensure_schema($pdo);

    $userId = (int) ($user['id'] ?? 0);
    $level = (int) (xp_user_level_info($pdo, $userId)['level'] ?? 1);
    $equipped = cosmetics_normalize((string) ($user['avatar_frame'] ?? 'none'));

    $earnedBadges = [];
    foreach (db_fetch_all($pdo, 'SELECT badge_key FROM user_badges WHERE user_id = :u', [':u' => $userId]) as $row) {
        $earnedBadges[(string) $row['badge_key']] = true;
    }

    $out = [];
    foreach (cosmetics_catalogue() as $key => $item) {
        $requirement = (string) $item['require'];
        if ($requirement === 'level') {
            $unlocked = $level >= (int) $item['value'];
            $hint = t('cosmetic.need_level', ['n' => (int) $item['value']]);
        } elseif ($requirement === 'badge') {
            $unlocked = isset($earnedBadges[(string) $item['value']]);
            $hint = t('cosmetic.need_badge', ['b' => t('badge.' . (string) $item['value'])]);
        } else {
            $unlocked = true;
            $hint = '';
        }

        $out[] = [
            'key' => $key,
            'label' => t((string) $item['label']),
            'unlocked' => $unlocked,
            'equipped' => $key === $equipped,
            'hint' => $unlocked ? '' : $hint,
        ];
    }

    return $out;
}

/**
 * Equip a frame. Re-checks the unlock server-side, so a hand-crafted POST cannot
 * equip something the user has not earned.
 */
function cosmetics_equip(PDO $pdo, array $user, string $key): bool
{
    $key = cosmetics_normalize($key);

    foreach (cosmetics_for_user($pdo, $user) as $item) {
        if ($item['key'] === $key && empty($item['unlocked'])) {
            return false;
        }
    }

    db_execute(
        $pdo,
        'UPDATE users SET avatar_frame = :f WHERE id = :id',
        [':f' => $key, ':id' => (int) $user['id']]
    );

    return true;
}

/** Class for an avatar element, so a frame follows the user everywhere. */
function cosmetic_frame_class(array $user): string
{
    $key = cosmetics_normalize((string) ($user['avatar_frame'] ?? 'none'));

    return $key === 'none' ? '' : ' avatar-frame frame-' . $key;
}
