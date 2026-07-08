<?php

declare(strict_types=1);

/**
 * Per-user profile visibility for social features. Governs who may see a user's
 * profile detail, posts/photos, and stat comparisons, and who is told about
 * their activity. It does NOT affect the shared challenge leaderboard/table —
 * that stays visible to all participants. Self-contained: adds the
 * users.profile_visibility column via the shared ensure_column migration and
 * owns the friend-activity throttle table.
 *
 * Levels: private (only me), friends (me + accepted friends), public (everyone).
 */

const PRIVACY_LEVELS = ['private', 'friends', 'public'];

function privacy_ensure_schema(PDO $pdo): void
{
    if (function_exists('ensure_column')) {
        ensure_column($pdo, 'users', 'profile_visibility', "TEXT NOT NULL DEFAULT 'public'");
    }
    // Friend-activity relies on the friendships table; make sure it exists.
    if (function_exists('friends_ensure_schema')) {
        friends_ensure_schema($pdo);
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS friend_activity_pings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            activity_type TEXT NOT NULL,
            ping_date TEXT NOT NULL,
            created_at TEXT NOT NULL,
            UNIQUE(user_id, activity_type, ping_date)
        )'
    );
}

function privacy_normalize(string $value): string
{
    $value = strtolower(trim($value));

    return in_array($value, PRIVACY_LEVELS, true) ? $value : 'public';
}

/** Visibility level for a user row/array. */
function user_visibility(?array $user): string
{
    return privacy_normalize((string) ($user['profile_visibility'] ?? 'public'));
}

function privacy_set_visibility(PDO $pdo, int $userId, string $value): void
{
    if ($userId <= 0) {
        return;
    }
    db_execute(
        $pdo,
        'UPDATE users SET profile_visibility = :v, updated_at = :now WHERE id = :id',
        [':v' => privacy_normalize($value), ':now' => now_iso(), ':id' => $userId]
    );
}

/**
 * May $viewerId see $targetId's social content (profile detail, posts, stat
 * comparisons, activity)? Self and admins always may. Pass the target's
 * visibility to avoid a lookup when it is already loaded.
 */
function can_view_user_content(PDO $pdo, int $viewerId, int $targetId, bool $viewerIsAdmin = false, ?string $visibility = null): bool
{
    if ($targetId <= 0) {
        return false;
    }
    if ($viewerId === $targetId || $viewerIsAdmin) {
        return true;
    }
    if ($visibility === null) {
        $row = db_fetch_one($pdo, 'SELECT profile_visibility FROM users WHERE id = :id', [':id' => $targetId]);
        $visibility = user_visibility($row);
    } else {
        $visibility = privacy_normalize($visibility);
    }
    if ($visibility === 'public') {
        return true;
    }
    if ($visibility === 'private') {
        return false;
    }
    // friends
    return $viewerId > 0 && function_exists('friends_status') && friends_status($pdo, $viewerId, $targetId) === 'friends';
}

/**
 * SQL condition (for a users alias) restricting rows to owners whose content
 * $viewerId may see. Appends the needed bindings to $params. Use in list
 * queries (e.g. the shared gallery) to hide posts from users who hid themselves.
 */
function privacy_visible_owner_sql(string $usersAlias, int $viewerId, bool $viewerIsAdmin, array &$params): string
{
    if ($viewerIsAdmin) {
        return '1=1';
    }
    $params[':pv_viewer'] = $viewerId;

    return '(' . $usersAlias . ".profile_visibility = 'public'"
        . ' OR ' . $usersAlias . '.id = :pv_viewer'
        . ' OR (' . $usersAlias . ".profile_visibility = 'friends' AND EXISTS ("
        . '   SELECT 1 FROM friendships pf WHERE pf.status = \'accepted\''
        . '     AND ((pf.requester_id = :pv_viewer AND pf.addressee_id = ' . $usersAlias . '.id)'
        . '       OR (pf.requester_id = ' . $usersAlias . '.id AND pf.addressee_id = :pv_viewer))'
        . ' )))';
}

/**
 * Tell a user's friends they logged a meal or training, at most once per day per
 * type. Skips users whose visibility is private. Pushes to both the in-app
 * notifications and Telegram (via social_notify).
 */
function social_broadcast_activity(PDO $pdo, int $actorId, string $type): void
{
    if (!in_array($type, ['meal', 'training'], true) || $actorId <= 0) {
        return;
    }
    if (!function_exists('friends_list') || !function_exists('social_notify')) {
        return;
    }
    $actor = db_fetch_one($pdo, 'SELECT display_name, profile_visibility FROM users WHERE id = :id AND active = 1', [':id' => $actorId]);
    if ($actor === null || user_visibility($actor) === 'private') {
        return;
    }
    privacy_ensure_schema($pdo);
    $today = to_date(null);
    $already = db_fetch_one(
        $pdo,
        'SELECT id FROM friend_activity_pings WHERE user_id = :u AND activity_type = :t AND ping_date = :d',
        [':u' => $actorId, ':t' => $type, ':d' => $today]
    );
    if ($already !== null) {
        return;
    }
    db_execute(
        $pdo,
        'INSERT OR IGNORE INTO friend_activity_pings (user_id, activity_type, ping_date, created_at)
         VALUES (:u, :t, :d, :c)',
        [':u' => $actorId, ':t' => $type, ':d' => $today, ':c' => now_iso()]
    );
    $name = trim((string) ($actor['display_name'] ?? ''));
    if ($name === '') {
        $name = t('social.someone');
    }
    $kind = 'friend_activity_' . $type;
    $title = t('notif.friend_' . $type . '_title');
    $body = t('notif.friend_' . $type . '_body', ['name' => $name]);
    foreach (friends_list($pdo, $actorId) as $friend) {
        social_notify($pdo, (int) $friend['id'], $kind, $title, $body);
    }
}
