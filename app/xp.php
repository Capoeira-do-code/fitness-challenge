<?php

declare(strict_types=1);

/**
 * User XP and levels. Actions (logging a day, completing a workout, posting a
 * photo, earning an achievement, completing a goal, winning a duel) grant XP,
 * which accrues into levels on a widening curve. Self-contained: owns an
 * append-only xp_events ledger and derives totals/levels from it. Idempotent
 * awards use a unique_key so the same event never double-counts.
 */

/** Built-in default XP granted per action type. */
function xp_default_action_amounts(): array
{
    return [
        'daily_log' => 10,
        'workout' => 15,
        'photo' => 5,
        'achievement' => 50,
        'goal' => 40,
        'duel_win' => 30,
    ];
}

/**
 * XP granted per action type, with admin overrides applied when a PDO is given.
 * Overrides are stored as a JSON blob in the xp_amounts app setting.
 */
function xp_action_amounts(?PDO $pdo = null): array
{
    $defaults = xp_default_action_amounts();
    if ($pdo === null || !function_exists('app_setting')) {
        return $defaults;
    }
    $raw = trim((string) (app_setting($pdo, 'xp_amounts', '') ?? ''));
    if ($raw === '') {
        return $defaults;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $defaults;
    }
    $out = $defaults;
    foreach ($defaults as $key => $value) {
        if (isset($decoded[$key]) && is_numeric($decoded[$key])) {
            $out[$key] = max(0, (int) $decoded[$key]);
        }
    }

    return $out;
}

/** Persist admin-customised XP amounts (unknown/invalid keys ignored). */
function xp_set_action_amounts(PDO $pdo, array $input, int $actorUserId): void
{
    if (!function_exists('set_app_setting')) {
        return;
    }
    $defaults = xp_default_action_amounts();
    $out = [];
    foreach ($defaults as $key => $value) {
        $out[$key] = isset($input[$key]) && is_numeric($input[$key]) ? max(0, min(100000, (int) $input[$key])) : $value;
    }
    set_app_setting($pdo, 'xp_amounts', (string) json_encode($out, JSON_UNESCAPED_SLASHES), $actorUserId);
}

function xp_ensure_schema(PDO $pdo): void
{
    static $readyConnections = [];
    $connectionId = spl_object_id($pdo);
    if (($readyConnections[$connectionId] ?? null) === $pdo) {
        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS xp_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            amount INTEGER NOT NULL,
            reason TEXT NOT NULL,
            unique_key TEXT,
            note TEXT,
            actor_user_id INTEGER,
            created_at TEXT NOT NULL,
            UNIQUE(user_id, unique_key)
        )'
    );
    if (function_exists('ensure_column')) {
        ensure_column($pdo, 'xp_events', 'note', 'TEXT');
        ensure_column($pdo, 'xp_events', 'actor_user_id', 'INTEGER');
    }
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_xp_events_created ON xp_events(created_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_xp_events_user_created ON xp_events(user_id, created_at DESC)');
    $readyConnections[$connectionId] = $pdo;
}

/**
 * Grant XP to a user. When $uniqueKey is given the award is idempotent (the
 * same key for the same user is only ever counted once). Returns the XP added
 * (0 if it was a duplicate or invalid).
 */
function xp_award(PDO $pdo, int $userId, int $amount, string $reason, ?string $uniqueKey = null): int
{
    if ($userId <= 0 || $amount === 0) {
        return 0;
    }
    xp_ensure_schema($pdo);
    if ($uniqueKey !== null) {
        $exists = db_fetch_one(
            $pdo,
            'SELECT id FROM xp_events WHERE user_id = :u AND unique_key = :k',
            [':u' => $userId, ':k' => $uniqueKey]
        );
        if ($exists !== null) {
            return 0;
        }
    }
    db_execute(
        $pdo,
        'INSERT OR IGNORE INTO xp_events (user_id, amount, reason, unique_key, created_at)
         VALUES (:u, :a, :r, :k, :c)',
        [':u' => $userId, ':a' => $amount, ':r' => $reason, ':k' => $uniqueKey, ':c' => now_iso()]
    );

    return $amount;
}

/** Grant the standard (admin-customisable) amount for a named action. */
function xp_grant_action(PDO $pdo, int $userId, string $action, ?string $uniqueKey = null): int
{
    $amounts = xp_action_amounts($pdo);
    if (!array_key_exists($action, $amounts)) {
        return 0;
    }

    return xp_award($pdo, $userId, (int) $amounts[$action], $action, $uniqueKey);
}

/**
 * Admin manual XP adjustment (positive grants, negative removes). Removal is
 * clamped so a user's total never goes below zero. Returns the applied delta.
 */
function xp_adjust(PDO $pdo, int $userId, int $amount, string $note, int $actorUserId): int
{
    $amount = max(-100000, min(100000, $amount));
    if ($userId <= 0 || $amount === 0) {
        return 0;
    }
    xp_ensure_schema($pdo);
    if (db_fetch_one($pdo, 'SELECT id FROM users WHERE id = :id', [':id' => $userId]) === null) {
        return 0;
    }
    if ($amount < 0) {
        $amount = -min(abs($amount), xp_total($pdo, $userId));
        if ($amount === 0) {
            return 0;
        }
    }
    $note = trim($note);
    $note = function_exists('mb_substr') ? mb_substr($note, 0, 160) : substr($note, 0, 160);
    db_execute(
        $pdo,
        'INSERT INTO xp_events (user_id, amount, reason, unique_key, note, actor_user_id, created_at)
         VALUES (:u, :a, :r, NULL, :n, :actor, :c)',
        [
            ':u' => $userId,
            ':a' => $amount,
            ':r' => $amount > 0 ? 'admin_grant' : 'admin_remove',
            ':n' => $note !== '' ? $note : null,
            ':actor' => $actorUserId > 0 ? $actorUserId : null,
            ':c' => now_iso(),
        ]
    );
    if (function_exists('audit_log')) {
        audit_log($pdo, $actorUserId, 'xp_adjusted', 'user', (string) $userId, 'Manual XP adjustment.', null, [
            'user_id' => $userId,
            'amount' => $amount,
            'note' => trim($note),
        ]);
    }

    return $amount;
}

function xp_total(PDO $pdo, int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }
    xp_ensure_schema($pdo);

    return (int) (db_fetch_one($pdo, 'SELECT COALESCE(SUM(amount), 0) AS total FROM xp_events WHERE user_id = :u', [':u' => $userId])['total'] ?? 0);
}

/**
 * Cumulative XP required to reach a given level (level 1 = 0). Gentle, fast-
 * starting curve so early levels come quickly and stay rewarding: thresholds
 * are L2=20, L3=50, L4=90, L5=140, L6=200 ... — each level costs 10 XP more
 * than the last (L2 costs 20, L3 costs 30, ...). With ~25 XP from a logged
 * workout day, the first levels arrive in a day or two.
 */
function xp_threshold_for_level(int $level): int
{
    $level = max(1, $level);

    return 5 * ($level - 1) * $level + 10 * ($level - 1);
}

/**
 * Level breakdown for a total XP value: current level, XP into the level, XP the
 * level spans, percentage progress toward the next level, and the running total.
 */
function xp_level_info(int $totalXp): array
{
    $totalXp = max(0, $totalXp);
    $level = 1;
    while (xp_threshold_for_level($level + 1) <= $totalXp) {
        $level++;
    }
    $base = xp_threshold_for_level($level);
    $next = xp_threshold_for_level($level + 1);
    $span = max(1, $next - $base);
    $into = $totalXp - $base;

    return [
        'level' => $level,
        'total_xp' => $totalXp,
        'into_level' => $into,
        'level_span' => $span,
        'xp_to_next' => max(0, $next - $totalXp),
        'next_level_xp' => $next,
        'progress_pct' => (int) round($into / $span * 100),
    ];
}

/** Convenience: full level info for a user. */
function xp_user_level_info(PDO $pdo, int $userId): array
{
    return xp_level_info(xp_total($pdo, $userId));
}

/**
 * Compact user progression rows for the admin XP screen. Aggregating in one
 * query keeps the page fast even when the community grows.
 */
function xp_admin_user_rows(PDO $pdo, array $users): array
{
    xp_ensure_schema($pdo);
    $aggregates = [];
    foreach (db_fetch_all(
        $pdo,
        'SELECT user_id,
                COALESCE(SUM(amount), 0) AS xp_total,
                COUNT(*) AS event_count,
                MAX(created_at) AS last_xp_at
         FROM xp_events
         GROUP BY user_id'
    ) as $row) {
        $aggregates[(int) ($row['user_id'] ?? 0)] = $row;
    }

    $result = [];
    foreach ($users as $user) {
        if (!is_array($user)) {
            continue;
        }
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            continue;
        }
        $aggregate = (array) ($aggregates[$userId] ?? []);
        $total = max(0, (int) ($aggregate['xp_total'] ?? 0));
        $info = xp_level_info($total);
        $result[] = array_merge($info, [
            'id' => $userId,
            'display_name' => trim((string) ($user['display_name'] ?? '')),
            'username' => trim((string) ($user['username'] ?? '')),
            'avatar_path' => trim((string) ($user['avatar_path'] ?? '')),
            'updated_at' => (string) ($user['updated_at'] ?? ''),
            'active' => (int) ($user['active'] ?? 1),
            'event_count' => (int) ($aggregate['event_count'] ?? 0),
            'last_xp_at' => (string) ($aggregate['last_xp_at'] ?? ''),
        ]);
    }

    usort($result, static function (array $left, array $right): int {
        $byXp = (int) ($right['total_xp'] ?? 0) <=> (int) ($left['total_xp'] ?? 0);
        if ($byXp !== 0) {
            return $byXp;
        }
        return strcasecmp((string) ($left['display_name'] ?? ''), (string) ($right['display_name'] ?? ''));
    });

    return $result;
}

/** Lifetime usage of each automatic XP rule. */
function xp_action_event_stats(PDO $pdo): array
{
    xp_ensure_schema($pdo);
    $allowed = array_fill_keys(array_keys(xp_default_action_amounts()), true);
    $result = [];
    foreach (db_fetch_all(
        $pdo,
        'SELECT reason, COUNT(*) AS event_count, COALESCE(SUM(amount), 0) AS xp_total
         FROM xp_events
         GROUP BY reason'
    ) as $row) {
        $reason = (string) ($row['reason'] ?? '');
        if (!isset($allowed[$reason])) {
            continue;
        }
        $result[$reason] = [
            'event_count' => (int) ($row['event_count'] ?? 0),
            'xp_total' => (int) ($row['xp_total'] ?? 0),
        ];
    }

    return $result;
}

/** Most recent ledger entries, including the affected user and admin actor. */
function xp_recent_events(PDO $pdo, int $limit = 60): array
{
    xp_ensure_schema($pdo);
    $limit = max(1, min(200, $limit));

    return db_fetch_all(
        $pdo,
        'SELECT e.id, e.user_id, e.amount, e.reason, e.note, e.actor_user_id, e.created_at,
                u.display_name, u.username, u.avatar_path, u.updated_at,
                actor.display_name AS actor_name
         FROM xp_events e
         LEFT JOIN users u ON u.id = e.user_id
         LEFT JOIN users actor ON actor.id = e.actor_user_id
         ORDER BY e.id DESC
         LIMIT ' . $limit
    );
}
