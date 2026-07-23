<?php

declare(strict_types=1);

/**
 * User duels: 1v1 challenges between friends over a chosen metric for a fixed
 * number of days. The creator picks the metric; the opponent accepts to start
 * the clock; a winner is decided from the challenge metrics when the window
 * ends. Self-contained (own table), builds on the friends graph.
 */

const DUEL_MIN_DAYS = 1;
const DUEL_MAX_DAYS = 60;

function duels_ensure_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS user_duels (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            challenger_id INTEGER NOT NULL,
            opponent_id INTEGER NOT NULL,
            metric TEXT NOT NULL,
            duration_days INTEGER NOT NULL DEFAULT 7,
            status TEXT NOT NULL DEFAULT "pending",
            start_date TEXT,
            end_date TEXT,
            winner_id INTEGER,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );
}

/** Selectable metrics: key => i18n label. */
function duels_metrics(): array
{
    $metrics = [
        'steps' => t('metric.steps'),
        'distance_km' => t('metric.distance_km'),
        'workouts' => t('metric.workouts'),
        'score' => t('metric.score'),
        'consistency' => t('duels.metric_consistency'),
    ];
    if (function_exists('wk_versus_metrics')) {
        $metrics += wk_versus_metrics();
    }

    return $metrics;
}

/** Whether a duel/competition metric is powered by the workout subsystem. */
function duels_metric_is_workout(string $metric): bool
{
    return str_starts_with($metric, 'wk_');
}

function duels_metric_label(string $metric): string
{
    return duels_metrics()[$metric] ?? $metric;
}

/** Extract the duel metric value from a compute_challenge_metrics() row. */
function duels_metric_value(?array $metric, string $key): float
{
    $metric = is_array($metric) ? $metric : [];

    return match ($key) {
        'steps' => (float) ($metric['total_steps'] ?? 0),
        'distance_km' => round((float) ($metric['total_km'] ?? 0), 2),
        'workouts' => (float) max((int) ($metric['workout_count'] ?? 0), (int) ($metric['workout_success'] ?? 0)),
        'score' => round((float) ($metric['score'] ?? 0), 1),
        'consistency' => round((float) ($metric['step_completion_pct'] ?? 0)),
        default => 0.0,
    };
}

function duels_format_value(string $metric, float $value): string
{
    if (duels_metric_is_workout($metric) && function_exists('wk_versus_format')) {
        return wk_versus_format($metric, $value);
    }

    return match ($metric) {
        'distance_km' => number_format($value, 2, '.', '') . ' km',
        'consistency' => (string) (int) $value . '%',
        'score' => rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.'),
        default => number_format($value, 0, '.', '.'),
    };
}

function duels_create(PDO $pdo, int $challengerId, int $opponentId, string $metric, int $days): bool
{
    if ($challengerId === $opponentId || $opponentId <= 0) {
        return false;
    }
    if (!array_key_exists($metric, duels_metrics())) {
        return false;
    }
    // The selector only contains accepted friends, but the domain rule must not
    // depend on the browser payload: forged requests are rejected here too.
    if (friends_status($pdo, $challengerId, $opponentId) !== 'friends') {
        return false;
    }
    $days = max(DUEL_MIN_DAYS, min(DUEL_MAX_DAYS, $days));

    // Avoid duplicate open duels with the same opponent.
    $existing = db_fetch_one(
        $pdo,
        'SELECT id FROM user_duels
         WHERE status IN ("pending", "active")
           AND ((challenger_id = :a AND opponent_id = :b) OR (challenger_id = :b AND opponent_id = :a))',
        [':a' => $challengerId, ':b' => $opponentId]
    );
    if ($existing !== null) {
        return false;
    }

    $now = now_iso();
    db_execute(
        $pdo,
        'INSERT INTO user_duels (challenger_id, opponent_id, metric, duration_days, status, created_at, updated_at)
         VALUES (:c, :o, :m, :d, "pending", :now, :now)',
        [':c' => $challengerId, ':o' => $opponentId, ':m' => $metric, ':d' => $days, ':now' => $now]
    );

    if (function_exists('social_notify')) {
        social_notify(
            $pdo,
            $opponentId,
            'duel_challenge',
            t('notif.duel_challenge_title'),
            t('notif.duel_challenge_body', [
                'name' => social_user_name($pdo, $challengerId),
                'metric' => duels_metric_label($metric),
            ])
        );
    }

    return true;
}

/** Opponent accepts (starts the clock) or declines. */
function duels_respond(PDO $pdo, int $duelId, int $me, bool $accept): bool
{
    $duel = db_fetch_one(
        $pdo,
        'SELECT * FROM user_duels WHERE id = :id AND opponent_id = :me AND status = "pending"',
        [':id' => $duelId, ':me' => $me]
    );
    if ($duel === null) {
        return false;
    }
    if (!$accept) {
        db_execute($pdo, 'UPDATE user_duels SET status = "declined", updated_at = :now WHERE id = :id', [':now' => now_iso(), ':id' => $duelId]);

        return true;
    }
    $start = to_date(null);
    $end = (new DateTimeImmutable($start))->modify('+' . (max(1, (int) $duel['duration_days']) - 1) . ' days')->format('Y-m-d');
    db_execute(
        $pdo,
        'UPDATE user_duels SET status = "active", start_date = :s, end_date = :e, updated_at = :now WHERE id = :id',
        [':s' => $start, ':e' => $end, ':now' => now_iso(), ':id' => $duelId]
    );

    if (function_exists('social_notify')) {
        social_notify(
            $pdo,
            (int) $duel['challenger_id'],
            'duel_accepted',
            t('notif.duel_accepted_title'),
            t('notif.duel_accepted_body', ['name' => social_user_name($pdo, $me)])
        );
    }

    return true;
}

/** Challenger cancels a pending/active duel it is part of. */
function duels_cancel(PDO $pdo, int $duelId, int $me): bool
{
    $duel = db_fetch_one(
        $pdo,
        'SELECT * FROM user_duels WHERE id = :id AND (challenger_id = :me OR opponent_id = :me) AND status IN ("pending", "active")',
        [':id' => $duelId, ':me' => $me]
    );
    if ($duel === null) {
        return false;
    }
    db_execute($pdo, 'UPDATE user_duels SET status = "cancelled", updated_at = :now WHERE id = :id', [':now' => now_iso(), ':id' => $duelId]);

    return true;
}

/** Compute both sides' metric value over a date range. */
function duels_values(PDO $pdo, array $config, array $duel, string $rangeStart, string $rangeEnd): array
{
    $challenger = db_fetch_one($pdo, 'SELECT * FROM users WHERE id = :id', [':id' => (int) $duel['challenger_id']]);
    $opponent = db_fetch_one($pdo, 'SELECT * FROM users WHERE id = :id', [':id' => (int) $duel['opponent_id']]);
    if ($challenger === null || $opponent === null) {
        return ['challenger' => 0.0, 'opponent' => 0.0, 'challenger_user' => $challenger, 'opponent_user' => $opponent];
    }
    $key = (string) $duel['metric'];

    // Workout-powered metrics come from the workout subsystem, not the
    // challenge metrics row.
    if (duels_metric_is_workout($key) && function_exists('wk_metric_over_range')) {
        return [
            'challenger' => wk_metric_over_range($pdo, (int) $duel['challenger_id'], $key, $rangeStart, $rangeEnd),
            'opponent' => wk_metric_over_range($pdo, (int) $duel['opponent_id'], $key, $rangeStart, $rangeEnd),
            'challenger_user' => $challenger,
            'opponent_user' => $opponent,
        ];
    }

    $metrics = compute_challenge_metrics($pdo, [$challenger, $opponent], $rangeStart, $rangeEnd);
    if (function_exists('apply_strike_review_overrides_to_metrics')) {
        $metrics = apply_strike_review_overrides_to_metrics($pdo, $metrics);
    }
    $byUser = [];
    foreach ($metrics as $m) {
        $byUser[(int) ($m['user']['id'] ?? 0)] = $m;
    }

    return [
        'challenger' => duels_metric_value($byUser[(int) $duel['challenger_id']] ?? null, $key),
        'opponent' => duels_metric_value($byUser[(int) $duel['opponent_id']] ?? null, $key),
        'challenger_user' => $challenger,
        'opponent_user' => $opponent,
    ];
}

/** Mark active duels whose window has ended as completed, and record the winner. */
function duels_finalize_due(PDO $pdo, array $config): void
{
    $today = to_date(null);
    $due = db_fetch_all(
        $pdo,
        'SELECT * FROM user_duels WHERE status = "active" AND end_date IS NOT NULL AND end_date < :today',
        [':today' => $today]
    );
    foreach ($due as $duel) {
        $vals = duels_values($pdo, $config, $duel, (string) $duel['start_date'], (string) $duel['end_date']);
        $winner = null;
        if ($vals['challenger'] > $vals['opponent']) {
            $winner = (int) $duel['challenger_id'];
        } elseif ($vals['opponent'] > $vals['challenger']) {
            $winner = (int) $duel['opponent_id'];
        }
        db_execute(
            $pdo,
            'UPDATE user_duels SET status = "completed", winner_id = :w, updated_at = :now WHERE id = :id',
            [':w' => $winner, ':now' => now_iso(), ':id' => (int) $duel['id']]
        );

        if (function_exists('social_notify')) {
            $cId = (int) $duel['challenger_id'];
            $oId = (int) $duel['opponent_id'];
            foreach ([$cId, $oId] as $participant) {
                $body = $winner === null
                    ? t('notif.duel_finished_tie_body')
                    : ($winner === $participant
                        ? t('notif.duel_finished_won_body')
                        : t('notif.duel_finished_lost_body', ['name' => social_user_name($pdo, $winner)]));
                social_notify($pdo, $participant, 'duel_finished', t('notif.duel_finished_title'), $body);
            }
        }
    }
}

/** All duels involving $me, newest first. */
function duels_for_user(PDO $pdo, int $me): array
{
    return db_fetch_all(
        $pdo,
        'SELECT * FROM user_duels
         WHERE challenger_id = :me OR opponent_id = :me
         ORDER BY (status = "active") DESC, (status = "pending") DESC, updated_at DESC',
        [':me' => $me]
    );
}

function duels_active_count(PDO $pdo, int $me): int
{
    $row = db_fetch_one(
        $pdo,
        'SELECT COUNT(*) AS total FROM user_duels
         WHERE (challenger_id = :me OR opponent_id = :me) AND status IN ("pending", "active")',
        [':me' => $me]
    );

    return (int) ($row['total'] ?? 0);
}

/**
 * Compact status summary for a user's duels (for Profile/Dashboard cards).
 *
 * @return array{active:int,pending:int,won:int,lost:int,total:int}
 */
function duels_summary_for_user(PDO $pdo, int $me): array
{
    $summary = ['active' => 0, 'pending' => 0, 'won' => 0, 'lost' => 0, 'total' => 0];
    foreach (duels_for_user($pdo, $me) as $duel) {
        $status = (string) ($duel['status'] ?? '');
        $summary['total']++;
        if ($status === 'active') {
            $summary['active']++;
        } elseif ($status === 'pending') {
            $summary['pending']++;
        } elseif ($status === 'completed') {
            $winnerId = (int) ($duel['winner_id'] ?? 0);
            if ($winnerId === $me) {
                $summary['won']++;
            } elseif ($winnerId > 0) {
                $summary['lost']++;
            }
        }
    }

    return $summary;
}
