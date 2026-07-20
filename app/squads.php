<?php

declare(strict_types=1);

/**
 * User-created teams (squads) and team-vs-team competitions. Every squad is
 * linked to a regular app team so Social, Profile, shared goals and the Team
 * dashboard all expose the same membership instead of two incompatible ideas
 * both labelled "team".
 */

function squads_ensure_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS squads (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            owner_id INTEGER NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );
    ensure_column($pdo, 'squads', 'team_id', 'INTEGER');
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS squad_members (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            squad_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            created_at TEXT NOT NULL,
            UNIQUE(squad_id, user_id)
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS squad_competitions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            challenger_squad_id INTEGER NOT NULL,
            opponent_squad_id INTEGER NOT NULL,
            metric TEXT NOT NULL,
            duration_days INTEGER NOT NULL DEFAULT 7,
            status TEXT NOT NULL DEFAULT "pending",
            start_date TEXT,
            end_date TEXT,
            winner_squad_id INTEGER,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );

    foreach (db_fetch_all($pdo, 'SELECT * FROM squads WHERE team_id IS NULL OR team_id <= 0') as $legacySquad) {
        squad_link_core_team($pdo, $legacySquad);
    }
}

/* ---- Squads (teams) ---- */

/** Multibyte-safe truncate that degrades gracefully when mbstring is absent. */
function squad_clip(string $value, int $limit): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $limit);
    }

    return substr($value, 0, $limit);
}

/** Two-letter uppercase badge for a squad name, mbstring-safe. */
function squad_badge(string $name): string
{
    $two = squad_clip(trim($name), 2);

    return function_exists('mb_strtoupper') ? mb_strtoupper($two) : strtoupper($two);
}

/** Ensure a competition squad also exists as a regular app team. */
function squad_link_core_team(PDO $pdo, array $squad): int
{
    $squadId = (int) ($squad['id'] ?? 0);
    $ownerId = (int) ($squad['owner_id'] ?? 0);
    $name = trim((string) ($squad['name'] ?? ''));
    if ($squadId <= 0 || $ownerId <= 0 || $name === '') {
        return 0;
    }

    $teamId = (int) ($squad['team_id'] ?? 0);
    $team = $teamId > 0 ? db_fetch_one($pdo, 'SELECT * FROM teams WHERE id = :id', [':id' => $teamId]) : null;
    $slug = 'squad-' . $squadId;
    if ($team === null) {
        $team = db_fetch_one($pdo, 'SELECT * FROM teams WHERE slug = :slug', [':slug' => $slug]);
    }
    $now = now_iso();
    if ($team === null) {
        db_execute(
            $pdo,
            'INSERT INTO teams (name, description, slug, join_mode, visibility, active, created_at, updated_at)
             VALUES (:name, "", :slug, "closed", "private", 1, :created_at, :updated_at)',
            [':name' => squad_clip($name, 60), ':slug' => $slug, ':created_at' => $now, ':updated_at' => $now]
        );
        $teamId = (int) $pdo->lastInsertId();
    } else {
        $teamId = (int) ($team['id'] ?? 0);
        db_execute(
            $pdo,
            'UPDATE teams SET name = :name, active = 1, updated_at = :updated_at WHERE id = :id',
            [':name' => squad_clip($name, 60), ':updated_at' => $now, ':id' => $teamId]
        );
    }
    if ($teamId <= 0) {
        return 0;
    }

    db_execute($pdo, 'UPDATE squads SET team_id = :team_id WHERE id = :id', [':team_id' => $teamId, ':id' => $squadId]);
    $memberRows = db_fetch_all($pdo, 'SELECT user_id FROM squad_members WHERE squad_id = :id', [':id' => $squadId]);
    $memberIds = array_values(array_unique(array_merge(
        [$ownerId],
        array_map(static fn(array $row): int => (int) ($row['user_id'] ?? 0), $memberRows)
    )));
    foreach ($memberIds as $memberId) {
        if ($memberId <= 0) {
            continue;
        }
        $role = $memberId === $ownerId ? 'owner' : 'member';
        db_execute(
            $pdo,
            'INSERT INTO team_memberships (team_id, user_id, role, active, joined_at, removed_at, created_at, updated_at)
             VALUES (:team_id, :user_id, :role, 1, :joined_at, NULL, :created_at, :updated_at)
             ON CONFLICT(team_id, user_id) DO UPDATE SET
                role = CASE WHEN excluded.role = "owner" THEN "owner" ELSE team_memberships.role END,
                active = 1,
                removed_at = NULL,
                updated_at = excluded.updated_at',
            [
                ':team_id' => $teamId,
                ':user_id' => $memberId,
                ':role' => $role,
                ':joined_at' => $now,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]
        );
    }

    return $teamId;
}

function squad_create(PDO $pdo, int $ownerId, string $name): int
{
    $name = trim($name);
    if ($name === '' || $ownerId <= 0) {
        return 0;
    }
    $now = now_iso();
    db_execute(
        $pdo,
        'INSERT INTO squads (name, owner_id, team_id, created_at, updated_at) VALUES (:n, :o, NULL, :now, :now)',
        [':n' => squad_clip($name, 60), ':o' => $ownerId, ':now' => $now]
    );
    $squadId = (int) $pdo->lastInsertId();
    db_execute(
        $pdo,
        'INSERT OR IGNORE INTO squad_members (squad_id, user_id, created_at) VALUES (:s, :u, :now)',
        [':s' => $squadId, ':u' => $ownerId, ':now' => $now]
    );
    $createdSquad = squad_get($pdo, $squadId);
    if ($createdSquad !== null) {
        squad_link_core_team($pdo, $createdSquad);
    }

    return $squadId;
}

function squad_get(PDO $pdo, int $squadId): ?array
{
    return db_fetch_one($pdo, 'SELECT * FROM squads WHERE id = :id', [':id' => $squadId]);
}

function squad_is_owner(PDO $pdo, int $squadId, int $userId): bool
{
    $squad = squad_get($pdo, $squadId);

    return $squad !== null && (int) $squad['owner_id'] === $userId;
}

function squad_rename(PDO $pdo, int $squadId, int $ownerId, string $name): bool
{
    $name = trim($name);
    if ($name === '' || !squad_is_owner($pdo, $squadId, $ownerId)) {
        return false;
    }
    db_execute($pdo, 'UPDATE squads SET name = :n, updated_at = :now WHERE id = :id', [':n' => squad_clip($name, 60), ':now' => now_iso(), ':id' => $squadId]);
    $squad = squad_get($pdo, $squadId);
    $teamId = (int) ($squad['team_id'] ?? 0);
    if ($teamId > 0) {
        db_execute($pdo, 'UPDATE teams SET name = :name, updated_at = :now WHERE id = :id', [':name' => squad_clip($name, 60), ':now' => now_iso(), ':id' => $teamId]);
    }

    return true;
}

function squad_delete(PDO $pdo, int $squadId, int $ownerId): bool
{
    if (!squad_is_owner($pdo, $squadId, $ownerId)) {
        return false;
    }
    $squad = squad_get($pdo, $squadId);
    $linkedTeamId = (int) ($squad['team_id'] ?? 0);
    db_execute($pdo, 'DELETE FROM squad_members WHERE squad_id = :id', [':id' => $squadId]);
    db_execute(
        $pdo,
        'UPDATE squad_competitions SET status = "cancelled", updated_at = :now
         WHERE (challenger_squad_id = :id OR opponent_squad_id = :id) AND status IN ("pending", "active")',
        [':now' => now_iso(), ':id' => $squadId]
    );
    db_execute($pdo, 'DELETE FROM squads WHERE id = :id', [':id' => $squadId]);
    if ($linkedTeamId > 0) {
        delete_team($pdo, $linkedTeamId, $ownerId);
    }

    return true;
}

/** Owner adds one of their friends to the squad. */
function squad_add_member(PDO $pdo, int $squadId, int $ownerId, int $userId): bool
{
    if (!squad_is_owner($pdo, $squadId, $ownerId) || $userId <= 0) {
        return false;
    }
    if ($userId !== $ownerId && function_exists('friends_status') && friends_status($pdo, $ownerId, $userId) !== 'friends') {
        return false;
    }
    db_execute(
        $pdo,
        'INSERT OR IGNORE INTO squad_members (squad_id, user_id, created_at) VALUES (:s, :u, :now)',
        [':s' => $squadId, ':u' => $userId, ':now' => now_iso()]
    );
    $squad = squad_get($pdo, $squadId);
    $linkedTeamId = $squad !== null ? squad_link_core_team($pdo, $squad) : 0;
    if ($linkedTeamId > 0) {
        set_team_membership($pdo, $linkedTeamId, $userId, true, $ownerId);
    }

    if (function_exists('social_notify')) {
        $squad = squad_get($pdo, $squadId);
        social_notify(
            $pdo,
            $userId,
            'squad_added',
            t('notif.squad_added_title'),
            t('notif.squad_added_body', ['squad' => (string) ($squad['name'] ?? '')])
        );
    }

    return true;
}

function squad_remove_member(PDO $pdo, int $squadId, int $ownerId, int $userId): bool
{
    if (!squad_is_owner($pdo, $squadId, $ownerId) || $userId === $ownerId) {
        return false;
    }
    $squad = squad_get($pdo, $squadId);
    db_execute($pdo, 'DELETE FROM squad_members WHERE squad_id = :s AND user_id = :u', [':s' => $squadId, ':u' => $userId]);
    $linkedTeamId = (int) ($squad['team_id'] ?? 0);
    if ($linkedTeamId > 0) {
        set_team_membership($pdo, $linkedTeamId, $userId, false, $ownerId);
    }

    return true;
}

/** Squads owned by the user. */
function squads_owned(PDO $pdo, int $ownerId): array
{
    return db_fetch_all($pdo, 'SELECT * FROM squads WHERE owner_id = :o ORDER BY name COLLATE NOCASE ASC', [':o' => $ownerId]);
}

/** Member user rows of a squad. */
function squad_member_users(PDO $pdo, int $squadId): array
{
    return db_fetch_all(
        $pdo,
        'SELECT u.* FROM squad_members m JOIN users u ON u.id = m.user_id
         WHERE m.squad_id = :s AND u.active = 1 ORDER BY u.display_name COLLATE NOCASE ASC',
        [':s' => $squadId]
    );
}

function squad_member_ids(PDO $pdo, int $squadId): array
{
    $rows = db_fetch_all($pdo, 'SELECT user_id FROM squad_members WHERE squad_id = :s', [':s' => $squadId]);

    return array_map(static fn(array $r): int => (int) $r['user_id'], $rows);
}

/** IDs of every squad the user owns or belongs to. */
function squad_ids_for_user(PDO $pdo, int $userId): array
{
    $rows = db_fetch_all(
        $pdo,
        'SELECT DISTINCT s.id
         FROM squads s
         LEFT JOIN squad_members sm ON sm.squad_id = s.id AND sm.user_id = :member_user
         WHERE s.owner_id = :owner_user OR sm.user_id IS NOT NULL',
        [':member_user' => $userId, ':owner_user' => $userId]
    );

    return array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $rows);
}

/** Other squads (not owned by the user) that can be challenged. */
function squads_challengeable(PDO $pdo, int $ownerId): array
{
    return db_fetch_all(
        $pdo,
        'SELECT s.*, u.display_name AS owner_name,
                (SELECT COUNT(1) FROM squad_members sm WHERE sm.squad_id = s.id) AS member_count
         FROM squads s
         LEFT JOIN users u ON u.id = s.owner_id
         WHERE s.owner_id <> :o ORDER BY s.name COLLATE NOCASE ASC',
        [':o' => $ownerId]
    );
}

/* ---- Competitions ---- */

function comp_create(PDO $pdo, int $challengerSquadId, int $opponentSquadId, int $ownerId, string $metric, int $days): bool
{
    if ($challengerSquadId === $opponentSquadId || !squad_is_owner($pdo, $challengerSquadId, $ownerId)) {
        return false;
    }
    if (squad_get($pdo, $opponentSquadId) === null) {
        return false;
    }
    if (!array_key_exists($metric, duels_metrics())) {
        return false;
    }
    $days = max(DUEL_MIN_DAYS, min(DUEL_MAX_DAYS, $days));
    $existing = db_fetch_one(
        $pdo,
        'SELECT id FROM squad_competitions
         WHERE status IN ("pending", "active")
           AND ((challenger_squad_id = :a AND opponent_squad_id = :b) OR (challenger_squad_id = :b AND opponent_squad_id = :a))',
        [':a' => $challengerSquadId, ':b' => $opponentSquadId]
    );
    if ($existing !== null) {
        return false;
    }
    $now = now_iso();
    db_execute(
        $pdo,
        'INSERT INTO squad_competitions (challenger_squad_id, opponent_squad_id, metric, duration_days, status, created_at, updated_at)
         VALUES (:c, :o, :m, :d, "pending", :now, :now)',
        [':c' => $challengerSquadId, ':o' => $opponentSquadId, ':m' => $metric, ':d' => $days, ':now' => $now]
    );

    if (function_exists('social_notify')) {
        $challenger = squad_get($pdo, $challengerSquadId);
        $opponent = squad_get($pdo, $opponentSquadId);
        social_notify(
            $pdo,
            (int) ($opponent['owner_id'] ?? 0),
            'comp_invite',
            t('notif.comp_invite_title'),
            t('notif.comp_invite_body', [
                'squad' => (string) ($challenger['name'] ?? ''),
                'metric' => duels_metric_label($metric),
            ])
        );
    }

    return true;
}

/** Opponent squad owner accepts (starts) or declines. */
function comp_respond(PDO $pdo, int $compId, int $userId, bool $accept): bool
{
    $comp = db_fetch_one($pdo, 'SELECT * FROM squad_competitions WHERE id = :id AND status = "pending"', [':id' => $compId]);
    if ($comp === null || !squad_is_owner($pdo, (int) $comp['opponent_squad_id'], $userId)) {
        return false;
    }
    if (!$accept) {
        db_execute($pdo, 'UPDATE squad_competitions SET status = "declined", updated_at = :now WHERE id = :id', [':now' => now_iso(), ':id' => $compId]);

        return true;
    }
    $start = to_date(null);
    $end = (new DateTimeImmutable($start))->modify('+' . (max(1, (int) $comp['duration_days']) - 1) . ' days')->format('Y-m-d');
    db_execute(
        $pdo,
        'UPDATE squad_competitions SET status = "active", start_date = :s, end_date = :e, updated_at = :now WHERE id = :id',
        [':s' => $start, ':e' => $end, ':now' => now_iso(), ':id' => $compId]
    );

    if (function_exists('social_notify')) {
        $challenger = squad_get($pdo, (int) $comp['challenger_squad_id']);
        $opponent = squad_get($pdo, (int) $comp['opponent_squad_id']);
        social_notify(
            $pdo,
            (int) ($challenger['owner_id'] ?? 0),
            'comp_accepted',
            t('notif.comp_accepted_title'),
            t('notif.comp_accepted_body', ['squad' => (string) ($opponent['name'] ?? '')])
        );
    }

    return true;
}

function comp_cancel(PDO $pdo, int $compId, int $userId): bool
{
    $comp = db_fetch_one($pdo, 'SELECT * FROM squad_competitions WHERE id = :id AND status IN ("pending", "active")', [':id' => $compId]);
    if ($comp === null) {
        return false;
    }
    if (!squad_is_owner($pdo, (int) $comp['challenger_squad_id'], $userId) && !squad_is_owner($pdo, (int) $comp['opponent_squad_id'], $userId)) {
        return false;
    }
    db_execute($pdo, 'UPDATE squad_competitions SET status = "cancelled", updated_at = :now WHERE id = :id', [':now' => now_iso(), ':id' => $compId]);

    return true;
}

/** Aggregate metric value for all members of a squad over a date range. */
function comp_squad_value(PDO $pdo, array $config, int $squadId, string $metric, string $rangeStart, string $rangeEnd): float
{
    $members = squad_member_users($pdo, $squadId);
    if ($members === []) {
        return 0.0;
    }

    // Workout-powered metrics sum each member's workout value over the range.
    if (function_exists('duels_metric_is_workout') && duels_metric_is_workout($metric) && function_exists('wk_metric_over_range')) {
        $total = 0.0;
        foreach ($members as $member) {
            $total += wk_metric_over_range($pdo, (int) ($member['id'] ?? 0), $metric, $rangeStart, $rangeEnd);
        }

        return round($total, 2);
    }

    $metrics = compute_challenge_metrics($pdo, $members, $rangeStart, $rangeEnd);
    if (function_exists('apply_strike_review_overrides_to_metrics')) {
        $metrics = apply_strike_review_overrides_to_metrics($pdo, $metrics);
    }
    $total = 0.0;
    foreach ($metrics as $m) {
        $total += duels_metric_value($m, $metric);
    }

    return round($total, 2);
}

/**
 * Per-member contribution for a squad competition.
 *
 * @return array<int,array{user:array,value:float}>
 */
function comp_squad_member_values(PDO $pdo, int $squadId, string $metric, string $rangeStart, string $rangeEnd): array
{
    $members = squad_member_users($pdo, $squadId);
    if ($members === []) {
        return [];
    }

    $valuesByUser = [];
    if (function_exists('duels_metric_is_workout') && duels_metric_is_workout($metric) && function_exists('wk_metric_over_range')) {
        foreach ($members as $member) {
            $memberId = (int) ($member['id'] ?? 0);
            $valuesByUser[$memberId] = wk_metric_over_range($pdo, $memberId, $metric, $rangeStart, $rangeEnd);
        }
    } else {
        $metrics = compute_challenge_metrics($pdo, $members, $rangeStart, $rangeEnd);
        if (function_exists('apply_strike_review_overrides_to_metrics')) {
            $metrics = apply_strike_review_overrides_to_metrics($pdo, $metrics);
        }
        foreach ($metrics as $memberMetrics) {
            $memberId = (int) (($memberMetrics['user'] ?? [])['id'] ?? 0);
            $valuesByUser[$memberId] = duels_metric_value($memberMetrics, $metric);
        }
    }

    $rows = [];
    foreach ($members as $member) {
        $memberId = (int) ($member['id'] ?? 0);
        $rows[] = ['user' => $member, 'value' => round((float) ($valuesByUser[$memberId] ?? 0), 2)];
    }
    usort($rows, static function (array $left, array $right): int {
        $valueOrder = ((float) ($right['value'] ?? 0)) <=> ((float) ($left['value'] ?? 0));
        if ($valueOrder !== 0) {
            return $valueOrder;
        }

        return strcasecmp(
            (string) (($left['user'] ?? [])['display_name'] ?? ''),
            (string) (($right['user'] ?? [])['display_name'] ?? '')
        );
    });

    return $rows;
}

/** Competition view model: names, aggregate values, leader. */
function comp_standing(PDO $pdo, array $config, array $comp): array
{
    $status = (string) $comp['status'];
    $rangeStart = (string) ($comp['start_date'] ?? to_date(null));
    $rangeEnd = $status === 'active' ? to_date(null) : (string) ($comp['end_date'] ?? $rangeStart);
    $challenger = squad_get($pdo, (int) $comp['challenger_squad_id']);
    $opponent = squad_get($pdo, (int) $comp['opponent_squad_id']);
    $metric = (string) $comp['metric'];

    $cVal = 0.0;
    $oVal = 0.0;
    $challengerMembers = array_map(
        static fn(array $user): array => ['user' => $user, 'value' => null],
        squad_member_users($pdo, (int) $comp['challenger_squad_id'])
    );
    $opponentMembers = array_map(
        static fn(array $user): array => ['user' => $user, 'value' => null],
        squad_member_users($pdo, (int) $comp['opponent_squad_id'])
    );
    if ($status === 'active' || $status === 'completed') {
        $challengerMembers = comp_squad_member_values($pdo, (int) $comp['challenger_squad_id'], $metric, $rangeStart, $rangeEnd);
        $opponentMembers = comp_squad_member_values($pdo, (int) $comp['opponent_squad_id'], $metric, $rangeStart, $rangeEnd);
        $cVal = round(array_sum(array_map(static fn(array $row): float => (float) ($row['value'] ?? 0), $challengerMembers)), 2);
        $oVal = round(array_sum(array_map(static fn(array $row): float => (float) ($row['value'] ?? 0), $opponentMembers)), 2);
    }

    return [
        'challenger_squad' => $challenger,
        'opponent_squad' => $opponent,
        'challenger_value' => $cVal,
        'opponent_value' => $oVal,
        'challenger_members' => $challengerMembers,
        'opponent_members' => $opponentMembers,
    ];
}

function comp_finalize_due(PDO $pdo, array $config): void
{
    $today = to_date(null);
    $due = db_fetch_all(
        $pdo,
        'SELECT * FROM squad_competitions WHERE status = "active" AND end_date IS NOT NULL AND end_date < :today',
        [':today' => $today]
    );
    foreach ($due as $comp) {
        $s = comp_standing($pdo, $config, $comp);
        $winner = null;
        if ($s['challenger_value'] > $s['opponent_value']) {
            $winner = (int) $comp['challenger_squad_id'];
        } elseif ($s['opponent_value'] > $s['challenger_value']) {
            $winner = (int) $comp['opponent_squad_id'];
        }
        db_execute(
            $pdo,
            'UPDATE squad_competitions SET status = "completed", winner_squad_id = :w, updated_at = :now WHERE id = :id',
            [':w' => $winner, ':now' => now_iso(), ':id' => (int) $comp['id']]
        );

        if (function_exists('social_notify')) {
            $winnerName = $winner !== null ? (string) (squad_get($pdo, $winner)['name'] ?? '') : '';
            $owners = [
                (int) ($s['challenger_squad']['owner_id'] ?? 0) => (int) $comp['challenger_squad_id'],
                (int) ($s['opponent_squad']['owner_id'] ?? 0) => (int) $comp['opponent_squad_id'],
            ];
            foreach ($owners as $ownerId => $squadId) {
                $body = $winner === null
                    ? t('notif.comp_finished_tie_body')
                    : ($winner === $squadId
                        ? t('notif.comp_finished_won_body')
                        : t('notif.comp_finished_lost_body', ['squad' => $winnerName]));
                social_notify($pdo, $ownerId, 'comp_finished', t('notif.comp_finished_title'), $body);
            }
        }
    }
}

/** Competitions involving any squad the user owns or belongs to. */
function comp_for_user(PDO $pdo, int $userId): array
{
    return db_fetch_all(
        $pdo,
        'SELECT c.* FROM squad_competitions c
         WHERE EXISTS (
             SELECT 1
             FROM squads s
             LEFT JOIN squad_members sm ON sm.squad_id = s.id AND sm.user_id = :member_user
             WHERE s.id IN (c.challenger_squad_id, c.opponent_squad_id)
               AND (s.owner_id = :owner_user OR sm.user_id IS NOT NULL)
         )
         ORDER BY (c.status = "active") DESC, (c.status = "pending") DESC, c.updated_at DESC',
        [':member_user' => $userId, ':owner_user' => $userId]
    );
}

/**
 * Compact status summary for a user's squad competitions.
 *
 * @return array{active:int,pending:int,won:int,total:int}
 */
function comp_summary_for_user(PDO $pdo, int $userId): array
{
    $summary = ['active' => 0, 'pending' => 0, 'won' => 0, 'total' => 0];
    $participatingSquadIds = array_fill_keys(squad_ids_for_user($pdo, $userId), true);
    foreach (comp_for_user($pdo, $userId) as $comp) {
        $status = (string) ($comp['status'] ?? '');
        $summary['total']++;
        if ($status === 'active') {
            $summary['active']++;
        } elseif ($status === 'pending') {
            $summary['pending']++;
        } elseif ($status === 'completed' && isset($participatingSquadIds[(int) ($comp['winner_squad_id'] ?? 0)])) {
            $summary['won']++;
        }
    }

    return $summary;
}
