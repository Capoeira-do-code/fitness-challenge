<?php

declare(strict_types=1);

/**
 * Friends: a lightweight mutual-friendship graph (request / accept) plus helpers
 * to list friends and their pending requests. Comparison of stats reuses the
 * existing challenge metrics. Self-contained (creates its own table) so it does
 * not depend on the main schema migration.
 */

function friends_ensure_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS friendships (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            requester_id INTEGER NOT NULL,
            addressee_id INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT "pending",
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            UNIQUE(requester_id, addressee_id)
        )'
    );
}

/** The friendship row between two users, in either direction, or null. */
function friends_relation(PDO $pdo, int $a, int $b): ?array
{
    return db_fetch_one(
        $pdo,
        'SELECT * FROM friendships
         WHERE (requester_id = :a AND addressee_id = :b)
            OR (requester_id = :b AND addressee_id = :a)
         LIMIT 1',
        [':a' => $a, ':b' => $b]
    );
}

/** none | friends | pending_out (I asked) | pending_in (they asked me). */
function friends_status(PDO $pdo, int $me, int $other): string
{
    if ($me === $other) {
        return 'self';
    }
    $row = friends_relation($pdo, $me, $other);
    if ($row === null) {
        return 'none';
    }
    if ((string) $row['status'] === 'accepted') {
        return 'friends';
    }

    return (int) $row['requester_id'] === $me ? 'pending_out' : 'pending_in';
}

function friends_send_request(PDO $pdo, int $me, int $target): bool
{
    if ($me === $target || $target <= 0) {
        return false;
    }
    $target_user = db_fetch_one($pdo, 'SELECT id FROM users WHERE id = :id AND active = 1', [':id' => $target]);
    if ($target_user === null) {
        return false;
    }
    if (friends_relation($pdo, $me, $target) !== null) {
        return false;
    }
    $now = now_iso();
    db_execute(
        $pdo,
        'INSERT INTO friendships (requester_id, addressee_id, status, created_at, updated_at)
         VALUES (:me, :target, "pending", :now, :now)',
        [':me' => $me, ':target' => $target, ':now' => $now]
    );

    return true;
}

/** Accept or reject an incoming request from $requesterId to $me. */
function friends_respond(PDO $pdo, int $me, int $requesterId, bool $accept): bool
{
    $row = db_fetch_one(
        $pdo,
        'SELECT * FROM friendships WHERE requester_id = :req AND addressee_id = :me AND status = "pending"',
        [':req' => $requesterId, ':me' => $me]
    );
    if ($row === null) {
        return false;
    }
    if ($accept) {
        db_execute(
            $pdo,
            'UPDATE friendships SET status = "accepted", updated_at = :now WHERE id = :id',
            [':now' => now_iso(), ':id' => (int) $row['id']]
        );
    } else {
        db_execute($pdo, 'DELETE FROM friendships WHERE id = :id', [':id' => (int) $row['id']]);
    }

    return true;
}

/** Cancel an outgoing pending request or unfriend an accepted relation. */
function friends_remove(PDO $pdo, int $me, int $other): bool
{
    $row = friends_relation($pdo, $me, $other);
    if ($row === null) {
        return false;
    }
    db_execute($pdo, 'DELETE FROM friendships WHERE id = :id', [':id' => (int) $row['id']]);

    return true;
}

/** Accepted friends of $me as user rows. */
function friends_list(PDO $pdo, int $me): array
{
    return db_fetch_all(
        $pdo,
        'SELECT u.* FROM friendships f
         JOIN users u ON u.id = CASE WHEN f.requester_id = :me THEN f.addressee_id ELSE f.requester_id END
         WHERE (f.requester_id = :me OR f.addressee_id = :me) AND f.status = "accepted" AND u.active = 1
         ORDER BY u.display_name COLLATE NOCASE ASC',
        [':me' => $me]
    );
}

/** Pending requests sent to $me (people who want to be my friend). */
function friends_incoming(PDO $pdo, int $me): array
{
    return db_fetch_all(
        $pdo,
        'SELECT u.*, f.created_at AS requested_at FROM friendships f
         JOIN users u ON u.id = f.requester_id
         WHERE f.addressee_id = :me AND f.status = "pending" AND u.active = 1
         ORDER BY f.created_at DESC',
        [':me' => $me]
    );
}

/** Pending requests I sent that are still waiting. */
function friends_outgoing(PDO $pdo, int $me): array
{
    return db_fetch_all(
        $pdo,
        'SELECT u.*, f.created_at AS requested_at FROM friendships f
         JOIN users u ON u.id = f.addressee_id
         WHERE f.requester_id = :me AND f.status = "pending" AND u.active = 1
         ORDER BY f.created_at DESC',
        [':me' => $me]
    );
}

/** Active users I can still send a request to (not me, not already related). */
function friends_addable_users(PDO $pdo, int $me): array
{
    return db_fetch_all(
        $pdo,
        'SELECT * FROM users
         WHERE active = 1 AND id <> :me
           AND id NOT IN (
               SELECT CASE WHEN requester_id = :me THEN addressee_id ELSE requester_id END
               FROM friendships WHERE requester_id = :me OR addressee_id = :me
           )
         ORDER BY display_name COLLATE NOCASE ASC',
        [':me' => $me]
    );
}

function friends_count(PDO $pdo, int $me): int
{
    $row = db_fetch_one(
        $pdo,
        'SELECT COUNT(*) AS total FROM friendships
         WHERE (requester_id = :me OR addressee_id = :me) AND status = "accepted"',
        [':me' => $me]
    );

    return (int) ($row['total'] ?? 0);
}

/**
 * Build a compact comparison summary for a metric row from
 * compute_challenge_metrics(), for the friend-vs-me view.
 */
function friends_metric_summary(?array $metric): array
{
    $metric = is_array($metric) ? $metric : [];

    return [
        'steps' => (int) ($metric['total_steps'] ?? 0),
        'distance_km' => round((float) ($metric['total_km'] ?? 0), 2),
        'workouts' => (int) max((int) ($metric['workout_count'] ?? 0), (int) ($metric['workout_success'] ?? 0)),
        'score' => round((float) ($metric['score'] ?? 0), 1),
        'step_completion_pct' => round((float) ($metric['step_completion_pct'] ?? 0)),
        'workout_completion_pct' => round((float) ($metric['workout_completion_pct'] ?? 0)),
    ];
}
