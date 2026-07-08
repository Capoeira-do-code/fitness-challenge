<?php

declare(strict_types=1);

/**
 * User XP and levels. Actions (logging a day, completing a workout, posting a
 * photo, earning an achievement, completing a goal, winning a duel) grant XP,
 * which accrues into levels on a widening curve. Self-contained: owns an
 * append-only xp_events ledger and derives totals/levels from it. Idempotent
 * awards use a unique_key so the same event never double-counts.
 */

/** XP granted per action type. */
function xp_action_amounts(): array
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

function xp_ensure_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS xp_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            amount INTEGER NOT NULL,
            reason TEXT NOT NULL,
            unique_key TEXT,
            created_at TEXT NOT NULL,
            UNIQUE(user_id, unique_key)
        )'
    );
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

/** Grant the standard amount for a named action (see xp_action_amounts). */
function xp_grant_action(PDO $pdo, int $userId, string $action, ?string $uniqueKey = null): int
{
    $amounts = xp_action_amounts();
    if (!array_key_exists($action, $amounts)) {
        return 0;
    }

    return xp_award($pdo, $userId, (int) $amounts[$action], $action, $uniqueKey);
}

function xp_total(PDO $pdo, int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }
    xp_ensure_schema($pdo);

    return (int) (db_fetch_one($pdo, 'SELECT COALESCE(SUM(amount), 0) AS total FROM xp_events WHERE user_id = :u', [':u' => $userId])['total'] ?? 0);
}

/** Cumulative XP required to reach a given level (level 1 = 0). Widening curve. */
function xp_threshold_for_level(int $level): int
{
    $level = max(1, $level);

    return 50 * ($level - 1) * $level;
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
