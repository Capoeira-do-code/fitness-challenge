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
        $out[$key] = isset($input[$key]) && is_numeric($input[$key]) ? max(0, (int) $input[$key]) : $value;
    }
    set_app_setting($pdo, 'xp_amounts', (string) json_encode($out, JSON_UNESCAPED_SLASHES), $actorUserId);
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
    if ($userId <= 0 || $amount === 0) {
        return 0;
    }
    xp_ensure_schema($pdo);
    if ($amount < 0) {
        $amount = -min(abs($amount), xp_total($pdo, $userId));
        if ($amount === 0) {
            return 0;
        }
    }
    db_execute(
        $pdo,
        'INSERT INTO xp_events (user_id, amount, reason, unique_key, created_at)
         VALUES (:u, :a, :r, NULL, :c)',
        [':u' => $userId, ':a' => $amount, ':r' => $amount > 0 ? 'admin_grant' : 'admin_remove', ':c' => now_iso()]
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
