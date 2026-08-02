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
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS social_feed_likes (
            user_id INTEGER NOT NULL,
            entity_type TEXT NOT NULL,
            entity_id INTEGER NOT NULL,
            created_at TEXT NOT NULL,
            PRIMARY KEY (user_id, entity_type, entity_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS social_feed_comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            entity_type TEXT NOT NULL,
            entity_id INTEGER NOT NULL,
            comment TEXT NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            parent_comment_id INTEGER,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )'
    );
    ensure_column($pdo, 'social_feed_comments', 'parent_comment_id', 'INTEGER');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_social_feed_comments_entity ON social_feed_comments(entity_type, entity_id, created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_social_feed_comments_thread ON social_feed_comments(entity_type, entity_id, parent_comment_id, created_at, id)');
    $hasPhotoComments = db_fetch_one($pdo, "SELECT 1 FROM sqlite_master WHERE type='table' AND name='photo_comments'") !== null;
    if ($hasPhotoComments) {
        // Photo posts have one canonical conversation. Move comments created by
        // the first feed implementation into the existing photo detail thread.
        $pdo->exec(
            'INSERT INTO photo_comments (photo_id,user_id,comment,created_at,updated_at)
             SELECT s.entity_id,s.user_id,s.comment,s.created_at,s.updated_at
             FROM social_feed_comments s
             WHERE s.entity_type="photo"
               AND EXISTS (SELECT 1 FROM photo_entries p WHERE p.id=s.entity_id)
               AND NOT EXISTS (
                   SELECT 1 FROM photo_comments c
                   WHERE c.photo_id=s.entity_id AND c.user_id=s.user_id
                     AND c.comment=s.comment AND c.created_at=s.created_at
               )'
        );
        $pdo->exec('DELETE FROM social_feed_comments WHERE entity_type="photo"');
    }
}

function social_feed_entity_type(string $type): string
{
    return in_array($type, ['workout', 'photo', 'meal'], true) ? $type : '';
}

function social_feed_entity_exists(PDO $pdo, string $type, int $id): bool
{
    if ($type === 'workout') {
        return db_fetch_one(
            $pdo,
            'SELECT ws.id
             FROM workout_sessions ws
             WHERE ws.id=:id AND ws.status="completed"
               AND EXISTS (
                   SELECT 1
                   FROM session_exercises se
                   JOIN workout_sets wset ON wset.session_exercise_id=se.id
                   WHERE se.session_id=ws.id AND wset.completed=1
               )',
            [':id' => $id]
        ) !== null;
    }
    if ($type === 'photo') {
        return db_fetch_one($pdo, 'SELECT id FROM photo_entries WHERE id=:id AND has_photo=1', [':id' => $id]) !== null;
    }
    if ($type === 'meal') {
        return db_fetch_one($pdo, 'SELECT id FROM nutrition_entries WHERE id=:id', [':id' => $id]) !== null;
    }
    return false;
}

function social_feed_entity_visible(PDO $pdo, int $viewerId, string $type, int $id): bool
{
    $source = match ($type) { 'workout' => 'workout_sessions', 'photo' => 'photo_entries', 'meal' => 'nutrition_entries', default => '' };
    if ($source === '') return false;
    $row = db_fetch_one($pdo, 'SELECT u.id AS user_id,u.profile_visibility,u.data_visibility_json FROM ' . $source . ' e JOIN users u ON u.id=e.user_id WHERE e.id=:id', [':id' => $id]);
    if ($row === null) return false;
    $key = $type === 'workout' ? 'workouts' : 'nutrition';
    return !function_exists('can_view_user_data') || can_view_user_data($pdo, $viewerId, (int) $row['user_id'], $key, false, $row);
}

function social_feed_entity_owner_id(PDO $pdo, string $type, int $id): int
{
    $source = match (social_feed_entity_type($type)) {
        'workout' => 'workout_sessions',
        'photo' => 'photo_entries',
        'meal' => 'nutrition_entries',
        default => '',
    };
    if ($source === '' || $id <= 0) return 0;
    $row = db_fetch_one($pdo, 'SELECT user_id FROM ' . $source . ' WHERE id=:id', [':id' => $id]);

    return max(0, (int) ($row['user_id'] ?? 0));
}

function social_feed_notify_interaction(
    PDO $pdo,
    int $actorUserId,
    string $type,
    int $id,
    string $interaction,
    string $comment = ''
): void {
    $type = social_feed_entity_type($type);
    $ownerId = social_feed_entity_owner_id($pdo, $type, $id);
    if ($type === '' || $ownerId <= 0 || $ownerId === $actorUserId) return;

    $actorName = social_user_name($pdo, $actorUserId);
    $item = t('notif.social_item_' . $type);
    $payload = [
        'actor_user_id' => $actorUserId,
        'entity_type' => $type,
        'entity_id' => $id,
    ];
    if ($interaction === 'like') {
        social_notify(
            $pdo,
            $ownerId,
            'social_like',
            t('notif.social_like_title'),
            t('notif.social_like_body', ['name' => $actorName, 'item' => $item]),
            $payload,
            'social_like:' . $type . ':' . $id . ':' . $actorUserId
        );
        return;
    }
    if ($interaction !== 'comment') return;
    $comment = trim($comment);
    $excerpt = app_text_substr($comment, 0, 120);
    if (app_text_length($comment) > 120) $excerpt .= '…';
    social_notify(
        $pdo,
        $ownerId,
        'social_comment',
        t('notif.social_comment_title'),
        t('notif.social_comment_body', ['name' => $actorName, 'item' => $item, 'comment' => $excerpt]),
        $payload
    );
}

function social_feed_toggle_like(PDO $pdo, int $userId, string $type, int $id): bool
{
    $type = social_feed_entity_type($type);
    if ($userId <= 0 || $id <= 0 || $type === '' || !social_feed_entity_exists($pdo, $type, $id) || !social_feed_entity_visible($pdo, $userId, $type, $id)) return false;
    $existing = db_fetch_one($pdo, 'SELECT user_id FROM social_feed_likes WHERE user_id=:user AND entity_type=:type AND entity_id=:id', [':user' => $userId, ':type' => $type, ':id' => $id]);
    if ($existing !== null) {
        db_execute($pdo, 'DELETE FROM social_feed_likes WHERE user_id=:user AND entity_type=:type AND entity_id=:id', [':user' => $userId, ':type' => $type, ':id' => $id]);
        return false;
    }
    db_execute($pdo, 'INSERT INTO social_feed_likes (user_id,entity_type,entity_id,created_at) VALUES (:user,:type,:id,:now)', [':user' => $userId, ':type' => $type, ':id' => $id, ':now' => now_iso()]);
    social_feed_notify_interaction($pdo, $userId, $type, $id, 'like');
    return true;
}

function social_feed_add_comment(PDO $pdo, int $userId, string $type, int $id, string $comment): bool
{
    try {
        return social_comment_create($pdo, $userId, $type, $id, $comment) !== [];
    } catch (Throwable) {
        return false;
    }
}

/** @return array<string,mixed>|null */
function social_comment_find(PDO $pdo, string $type, int $entityId, int $commentId): ?array
{
    $type = social_feed_entity_type($type);
    if ($type === '' || $entityId <= 0 || $commentId <= 0) return null;

    if ($type === 'photo') {
        return db_fetch_one(
            $pdo,
            'SELECT c.*,u.display_name,u.username,u.avatar_path,u.updated_at AS user_updated_at
             FROM photo_comments c JOIN users u ON u.id=c.user_id
             WHERE c.id=:comment_id AND c.photo_id=:entity_id LIMIT 1',
            [':comment_id' => $commentId, ':entity_id' => $entityId]
        );
    }

    return db_fetch_one(
        $pdo,
        'SELECT c.*,u.display_name,u.username,u.avatar_path,u.updated_at AS user_updated_at
         FROM social_feed_comments c JOIN users u ON u.id=c.user_id
         WHERE c.id=:comment_id AND c.entity_type=:type AND c.entity_id=:entity_id LIMIT 1',
        [':comment_id' => $commentId, ':type' => $type, ':entity_id' => $entityId]
    );
}

/** @return array<int,array<string,mixed>> */
function social_comments_for_entity(PDO $pdo, string $type, int $entityId, int $limit = 250): array
{
    $type = social_feed_entity_type($type);
    if ($type === '' || $entityId <= 0) return [];
    $safeLimit = max(1, min(500, $limit));

    if ($type === 'photo') {
        return db_fetch_all(
            $pdo,
            'SELECT c.*,u.display_name,u.username,u.avatar_path,u.updated_at AS user_updated_at
             FROM photo_comments c JOIN users u ON u.id=c.user_id
             WHERE c.photo_id=:entity_id
             ORDER BY CASE WHEN c.parent_comment_id IS NULL THEN c.created_at ELSE (SELECT p.created_at FROM photo_comments p WHERE p.id=c.parent_comment_id) END ASC,
                      CASE WHEN c.parent_comment_id IS NULL THEN 0 ELSE 1 END ASC,c.created_at ASC,c.id ASC
             LIMIT ' . $safeLimit,
            [':entity_id' => $entityId]
        );
    }

    return db_fetch_all(
        $pdo,
        'SELECT c.*,u.display_name,u.username,u.avatar_path,u.updated_at AS user_updated_at
         FROM social_feed_comments c JOIN users u ON u.id=c.user_id
         WHERE c.entity_type=:type AND c.entity_id=:entity_id
         ORDER BY CASE WHEN c.parent_comment_id IS NULL THEN c.created_at ELSE (SELECT p.created_at FROM social_feed_comments p WHERE p.id=c.parent_comment_id) END ASC,
                  CASE WHEN c.parent_comment_id IS NULL THEN 0 ELSE 1 END ASC,c.created_at ASC,c.id ASC
         LIMIT ' . $safeLimit,
        [':type' => $type, ':entity_id' => $entityId]
    );
}

function social_comment_count(PDO $pdo, string $type, int $entityId): int
{
    $type = social_feed_entity_type($type);
    if ($type === '' || $entityId <= 0) return 0;
    $row = $type === 'photo'
        ? db_fetch_one($pdo, 'SELECT COUNT(*) AS n FROM photo_comments WHERE photo_id=:id', [':id' => $entityId])
        : db_fetch_one($pdo, 'SELECT COUNT(*) AS n FROM social_feed_comments WHERE entity_type=:type AND entity_id=:id', [':type' => $type, ':id' => $entityId]);
    return max(0, (int) ($row['n'] ?? 0));
}

function social_comment_text(string $comment): string
{
    $comment = trim($comment);
    if ($comment === '') throw new InvalidArgumentException(t('photo.comment_required'));
    if (app_text_length($comment) > 1200) throw new InvalidArgumentException(t('photo.comment_too_long'));
    return $comment;
}

/** @return array<string,mixed> */
function social_comment_create(
    PDO $pdo,
    int $userId,
    string $type,
    int $entityId,
    string $comment,
    int $replyToCommentId = 0
): array {
    $type = social_feed_entity_type($type);
    $comment = social_comment_text($comment);
    if ($userId <= 0 || $entityId <= 0 || $type === ''
        || !social_feed_entity_exists($pdo, $type, $entityId)
        || !social_feed_entity_visible($pdo, $userId, $type, $entityId)
    ) {
        throw new RuntimeException(t('flash.no_permission'));
    }

    $replyTarget = null;
    $parentCommentId = null;
    if ($replyToCommentId > 0) {
        $replyTarget = social_comment_find($pdo, $type, $entityId, $replyToCommentId);
        if ($replyTarget === null) throw new RuntimeException(t('flash.not_found'));
        $parentCommentId = (int) ($replyTarget['parent_comment_id'] ?? 0) > 0
            ? (int) $replyTarget['parent_comment_id']
            : (int) $replyTarget['id'];
    }

    $now = now_iso();
    if ($type === 'photo') {
        db_execute(
            $pdo,
            'INSERT INTO photo_comments (photo_id,user_id,parent_comment_id,comment,created_at,updated_at)
             VALUES (:entity_id,:user_id,:parent_id,:comment,:now,:now)',
            [':entity_id' => $entityId, ':user_id' => $userId, ':parent_id' => $parentCommentId, ':comment' => $comment, ':now' => $now]
        );
    } else {
        db_execute(
            $pdo,
            'INSERT INTO social_feed_comments (user_id,entity_type,entity_id,parent_comment_id,comment,created_at,updated_at)
             VALUES (:user_id,:type,:entity_id,:parent_id,:comment,:now,:now)',
            [':user_id' => $userId, ':type' => $type, ':entity_id' => $entityId, ':parent_id' => $parentCommentId, ':comment' => $comment, ':now' => $now]
        );
    }
    $created = social_comment_find($pdo, $type, $entityId, (int) $pdo->lastInsertId());
    if ($created === null) throw new RuntimeException(t('flash.save_failed'));

    social_comment_notify_created($pdo, $userId, $type, $entityId, $created, $replyTarget);
    return $created;
}

function social_comment_notify_created(
    PDO $pdo,
    int $actorUserId,
    string $type,
    int $entityId,
    array $created,
    ?array $replyTarget
): void {
    if ($replyTarget === null) {
        social_feed_notify_interaction($pdo, $actorUserId, $type, $entityId, 'comment', (string) ($created['comment'] ?? ''));
        return;
    }

    $actorName = social_user_name($pdo, $actorUserId);
    $item = t('notif.social_item_' . $type);
    $comment = trim((string) ($created['comment'] ?? ''));
    $excerpt = app_text_substr($comment, 0, 120);
    if (app_text_length($comment) > 120) $excerpt .= '…';
    $payload = [
        'actor_user_id' => $actorUserId,
        'entity_type' => $type,
        'entity_id' => $entityId,
        'comment_id' => (int) ($created['id'] ?? 0),
        'parent_comment_id' => (int) ($created['parent_comment_id'] ?? 0),
        'reply_to_comment_id' => (int) ($replyTarget['id'] ?? 0),
    ];
    $targetAuthorId = (int) ($replyTarget['user_id'] ?? 0);
    if ($targetAuthorId > 0 && $targetAuthorId !== $actorUserId) {
        social_notify(
            $pdo,
            $targetAuthorId,
            'social_reply',
            t('notif.social_reply_title'),
            t('notif.social_reply_body', ['name' => $actorName, 'item' => $item, 'comment' => $excerpt]),
            $payload
        );
    }
    $ownerId = social_feed_entity_owner_id($pdo, $type, $entityId);
    if ($ownerId > 0 && $ownerId !== $actorUserId && $ownerId !== $targetAuthorId) {
        social_notify(
            $pdo,
            $ownerId,
            'social_reply',
            t('notif.social_reply_post_title'),
            t('notif.social_reply_post_body', ['name' => $actorName, 'item' => $item, 'comment' => $excerpt]),
            $payload
        );
    }
}

/** @return array<string,mixed> */
function social_comment_update(PDO $pdo, int $actorUserId, string $type, int $entityId, int $commentId, string $comment): array
{
    $type = social_feed_entity_type($type);
    $existing = social_comment_find($pdo, $type, $entityId, $commentId);
    if ($existing === null) throw new RuntimeException(t('flash.not_found'));
    if ((int) ($existing['user_id'] ?? 0) !== $actorUserId) throw new RuntimeException(t('flash.no_permission'));
    $comment = social_comment_text($comment);
    $params = [':comment' => $comment, ':now' => now_iso(), ':id' => $commentId];
    if ($type === 'photo') {
        db_execute($pdo, 'UPDATE photo_comments SET comment=:comment,updated_at=:now WHERE id=:id', $params);
    } else {
        db_execute($pdo, 'UPDATE social_feed_comments SET comment=:comment,updated_at=:now WHERE id=:id', $params);
    }
    $updated = social_comment_find($pdo, $type, $entityId, $commentId);
    if ($updated === null) throw new RuntimeException(t('flash.save_failed'));
    return $updated;
}

/** @return array<string,mixed> */
function social_comment_delete(PDO $pdo, array $actor, string $type, int $entityId, int $commentId): array
{
    $type = social_feed_entity_type($type);
    $existing = social_comment_find($pdo, $type, $entityId, $commentId);
    if ($existing === null) throw new RuntimeException(t('flash.not_found'));
    $actorId = (int) ($actor['id'] ?? 0);
    $ownerId = social_feed_entity_owner_id($pdo, $type, $entityId);
    if ($actorId <= 0 || (!is_admin($actor) && $actorId !== (int) ($existing['user_id'] ?? 0) && $actorId !== $ownerId)) {
        throw new RuntimeException(t('flash.no_permission'));
    }

    $started = !$pdo->inTransaction();
    if ($started) $pdo->beginTransaction();
    try {
        if ($type === 'photo') {
            db_execute($pdo, 'DELETE FROM photo_comments WHERE parent_comment_id=:id', [':id' => $commentId]);
            db_execute($pdo, 'DELETE FROM photo_comments WHERE id=:id', [':id' => $commentId]);
        } else {
            db_execute($pdo, 'DELETE FROM social_feed_comments WHERE parent_comment_id=:id', [':id' => $commentId]);
            db_execute($pdo, 'DELETE FROM social_feed_comments WHERE id=:id', [':id' => $commentId]);
        }
        if ($started) $pdo->commit();
    } catch (Throwable $error) {
        if ($started && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    return $existing;
}

function social_comment_thread_html(
    array $comments,
    array $currentUser,
    int $socialCommentOwnerId,
    string $endpoint,
    string $entityType,
    int $entityId,
    string $feedScope = ''
): string {
    $socialComments = array_values($comments);
    $socialCommentCurrentUser = $currentUser;
    $socialCommentEndpoint = $endpoint;
    $socialCommentEntityType = $entityType;
    $socialCommentEntityId = $entityId;
    $socialCommentFeedScope = $feedScope;
    ob_start();
    require __DIR__ . '/views/partials/social_comment_thread.php';
    return (string) ob_get_clean();
}

/** @return array<string,mixed> */
function social_comment_apply_action(PDO $pdo, array $actor, string $action, string $type, int $entityId, array $input): array
{
    $type = social_feed_entity_type($type);
    $actorId = (int) ($actor['id'] ?? 0);
    if ($actorId <= 0 || $type === '' || $entityId <= 0
        || !social_feed_entity_exists($pdo, $type, $entityId)
        || !social_feed_entity_visible($pdo, $actorId, $type, $entityId)
    ) {
        throw new RuntimeException(t('flash.no_permission'));
    }

    return match ($action) {
        'social_feed_comment' => social_comment_create(
            $pdo,
            $actorId,
            $type,
            $entityId,
            (string) ($input['comment'] ?? ''),
            max(0, (int) ($input['parent_comment_id'] ?? 0))
        ),
        'social_feed_comment_edit' => social_comment_update(
            $pdo,
            $actorId,
            $type,
            $entityId,
            max(0, (int) ($input['comment_id'] ?? 0)),
            (string) ($input['comment'] ?? '')
        ),
        'social_feed_comment_delete' => social_comment_delete(
            $pdo,
            $actor,
            $type,
            $entityId,
            max(0, (int) ($input['comment_id'] ?? 0))
        ),
        default => throw new RuntimeException(t('flash.not_found')),
    };
}

/** @return array<string,mixed> */
function social_comment_response_data(
    PDO $pdo,
    array $currentUser,
    string $type,
    int $entityId,
    string $endpoint,
    string $feedScope = ''
): array {
    $comments = social_comments_for_entity($pdo, $type, $entityId, 250);
    return [
        'comment_count' => count($comments),
        'comments_html' => social_comment_thread_html(
            $comments,
            $currentUser,
            social_feed_entity_owner_id($pdo, $type, $entityId),
            $endpoint,
            $type,
            $entityId,
            $feedScope
        ),
    ];
}

/** @return array<int,array<string,mixed>> */
function social_feed_items(
    PDO $pdo,
    int $viewerId,
    string $scope = 'friends',
    int $limit = 18,
    string $focusType = '',
    int $focusId = 0
): array
{
    $scope = $scope === 'global' ? 'global' : 'friends';
    $limit = max(1, min(40, $limit));
    $focusType = social_feed_entity_type($focusType);
    $focusId = max(0, $focusId);
    $isFocused = $focusType !== '' && $focusId > 0;
    $friendIds = array_map(static fn(array $friend): int => (int) ($friend['id'] ?? 0), friends_list($pdo, $viewerId));
    $allowedIds = array_values(array_unique(array_filter(array_merge([$viewerId], $scope === 'friends' ? $friendIds : []))));
    $candidateLimit = $isFocused ? 1 : $limit * 3;
    $workoutFocus = $isFocused ? ($focusType === 'workout' ? ' AND ws.id=' . $focusId : ' AND 1=0') : '';
    $photoFocus = $isFocused ? ($focusType === 'photo' ? ' AND p.id=' . $focusId : ' AND 1=0') : '';
    $mealFocus = $isFocused ? ($focusType === 'meal' ? ' AND n.id=' . $focusId : ' AND 1=0') : '';
    $workouts = db_fetch_all($pdo, 'SELECT ws.id,ws.user_id,ws.started_at AS occurred_at,ws.ended_at,ws.title,wr.name AS routine_name,u.display_name,u.username,u.avatar_path,u.profile_visibility,u.data_visibility_json,u.updated_at AS user_updated_at FROM workout_sessions ws JOIN users u ON u.id=ws.user_id LEFT JOIN workout_routines wr ON wr.id=ws.routine_id AND wr.user_id=ws.user_id WHERE ws.status="completed" AND u.active=1 AND EXISTS (SELECT 1 FROM session_exercises se JOIN workout_sets wset ON wset.session_exercise_id=se.id WHERE se.session_id=ws.id AND wset.completed=1)' . $workoutFocus . ' ORDER BY ws.started_at DESC LIMIT ' . $candidateLimit);
    $photos = db_fetch_all($pdo, 'SELECT p.id,p.user_id,p.created_at AS occurred_at,p.caption,p.category,p.file_path,p.calories,p.protein_g,p.carbs_g,p.fat_g,u.display_name,u.username,u.avatar_path,u.profile_visibility,u.data_visibility_json,u.updated_at AS user_updated_at FROM photo_entries p JOIN users u ON u.id=p.user_id WHERE p.has_photo=1 AND TRIM(COALESCE(p.file_path,""))!="" AND u.active=1' . $photoFocus . ' ORDER BY p.created_at DESC LIMIT ' . $candidateLimit);
    $meals = db_fetch_all($pdo, 'SELECT n.id,n.user_id,n.created_at AS occurred_at,n.notes,n.meal_type,n.calories,n.protein_g,n.carbs_g,n.fat_g,u.display_name,u.username,u.avatar_path,u.profile_visibility,u.data_visibility_json,u.updated_at AS user_updated_at FROM nutrition_entries n JOIN users u ON u.id=n.user_id WHERE n.photo_entry_id IS NULL AND u.active=1' . $mealFocus . ' ORDER BY n.created_at DESC LIMIT ' . $candidateLimit);
    $items = [];
    foreach ($workouts as $row) {
        $ownerId = (int) $row['user_id'];
        if (!$isFocused && $scope === 'friends' && !in_array($ownerId, $allowedIds, true)) continue;
        if (function_exists('can_view_user_data') && !can_view_user_data($pdo, $viewerId, $ownerId, 'workouts', false, $row)) continue;
        $row['type'] = 'workout';
        $row['exercises'] = wk_session_completed_exercises(wk_session_exercises($pdo, (int) $row['id']));
        $row['volume'] = 0.0; $row['set_count'] = 0;
        foreach ($row['exercises'] as $exercise) foreach ((array) ($exercise['sets'] ?? []) as $set) if ((int) ($set['completed'] ?? 0) === 1) { $row['volume'] += (float) ($set['weight'] ?? 0) * (int) ($set['reps'] ?? 0); $row['set_count']++; }
        if ($row['set_count'] < 1) continue;
        $shareToken = wk_session_share_token($pdo, (int) $row['id'], $ownerId);
        $row['share_url'] = $shareToken !== '' ? '/?page=shared_workout&token=' . rawurlencode($shareToken) : '';
        if ($ownerId === $viewerId) {
            $row['detail_url'] = '/?page=workouts&view=stats&detail_session=' . (int) $row['id'];
        } else {
            $row['detail_url'] = $row['share_url'];
        }
        $items[] = $row;
    }
    foreach ($photos as $row) {
        $ownerId = (int) $row['user_id'];
        if (!$isFocused && $scope === 'friends' && !in_array($ownerId, $allowedIds, true)) continue;
        if (function_exists('can_view_user_data') && !can_view_user_data($pdo, $viewerId, $ownerId, 'nutrition', false, $row)) continue;
        $row['type'] = 'photo'; $items[] = $row;
    }
    foreach ($meals as $row) {
        $ownerId = (int) $row['user_id'];
        if (!$isFocused && $scope === 'friends' && !in_array($ownerId, $allowedIds, true)) continue;
        if (function_exists('can_view_user_data') && !can_view_user_data($pdo, $viewerId, $ownerId, 'nutrition', false, $row)) continue;
        $row['type'] = 'meal'; $items[] = $row;
    }
    usort($items, static fn(array $a, array $b): int => strcmp((string) $b['occurred_at'], (string) $a['occurred_at']));
    $items = array_slice($items, 0, $limit);
    foreach ($items as &$item) {
        $params = [':type' => $item['type'], ':id' => (int) $item['id']];
        $item['like_count'] = (int) (db_fetch_one($pdo, 'SELECT COUNT(*) AS n FROM social_feed_likes WHERE entity_type=:type AND entity_id=:id', $params)['n'] ?? 0);
        $item['liked'] = db_fetch_one($pdo, 'SELECT 1 FROM social_feed_likes WHERE user_id=:user AND entity_type=:type AND entity_id=:id', $params + [':user' => $viewerId]) !== null;
        $item['comments'] = social_comments_for_entity($pdo, (string) $item['type'], (int) $item['id'], 100);
        $item['comment_count'] = social_comment_count($pdo, (string) $item['type'], (int) $item['id']);
        $item['copied_routine_id'] = 0;
        $item['can_copy_workout'] = false;
        if ((string) $item['type'] === 'workout' && (int) ($item['user_id'] ?? 0) !== $viewerId) {
            $copiedRoutine = db_fetch_one(
                $pdo,
                'SELECT id FROM workout_routines
                 WHERE user_id=:viewer AND source_user_id=:owner AND source_session_id=:session
                 LIMIT 1',
                [':viewer' => $viewerId, ':owner' => (int) $item['user_id'], ':session' => (int) $item['id']]
            );
            $item['copied_routine_id'] = (int) ($copiedRoutine['id'] ?? 0);
            $item['can_copy_workout'] = true;
        }
    }
    unset($item);
    return $items;
}

/**
 * Display name for a user id, for use in social notifications. Falls back to a
 * generic label so a notification is never built with an empty actor name.
 */
function social_user_name(PDO $pdo, int $userId): string
{
    $row = db_fetch_one($pdo, 'SELECT display_name FROM users WHERE id = :id', [':id' => $userId]);
    $name = trim((string) ($row['display_name'] ?? ''));

    return $name !== '' ? $name : t('social.someone');
}

/**
 * Fire a user notification for a social event if the notification API is
 * available. Central wrapper so the friends/duels/squads modules stay tidy and
 * degrade gracefully if the notifications feature is ever absent.
 */
function social_notify(PDO $pdo, int $userId, string $kind, string $title, string $message, array $payload = [], ?string $uniqueKey = null): void
{
    if ($userId <= 0) {
        return;
    }
    $created = !function_exists('create_user_notification')
        || create_user_notification($pdo, $userId, $kind, $title, $message, $uniqueKey, $payload);
    // Also push to Telegram if the recipient is linked and opted in. Queued to an
    // outbox so the web request never blocks on a Telegram API call.
    if ($created && function_exists('telegram_enqueue')) {
        telegram_enqueue($pdo, $userId, trim($title . "\n" . $message), $kind);
    }
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

    $name = social_user_name($pdo, $me);
    social_notify(
        $pdo,
        $target,
        'friend_request',
        t('notif.friend_request_title'),
        t('notif.friend_request_body', ['name' => $name]),
        ['requester_id' => $me]
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
    if (function_exists('resolve_user_notification_by_payload')) {
        resolve_user_notification_by_payload($pdo, $me, 'friend_request', 'requester_id', $requesterId);
    }
    if ($accept) {
        db_execute(
            $pdo,
            'UPDATE friendships SET status = "accepted", updated_at = :now WHERE id = :id',
            [':now' => now_iso(), ':id' => (int) $row['id']]
        );
        social_notify(
            $pdo,
            $requesterId,
            'friend_accepted',
            t('notif.friend_accepted_title'),
            t('notif.friend_accepted_body', ['name' => social_user_name($pdo, $me)])
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
    if ((string) $row['status'] === 'pending' && function_exists('resolve_user_notification_by_payload')) {
        // If $me is cancelling a request they sent, $other's "new friend
        // request" CTA is now pointing at nothing - clear it.
        $pendingRecipient = (int) $row['addressee_id'];
        resolve_user_notification_by_payload($pdo, $pendingRecipient, 'friend_request', 'requester_id', (int) $row['requester_id']);
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

function friends_addable_count(PDO $pdo, int $me): int
{
    $row = db_fetch_one(
        $pdo,
        'SELECT COUNT(*) AS total FROM users
         WHERE active = 1 AND id <> :me
           AND id NOT IN (
               SELECT CASE WHEN requester_id = :me THEN addressee_id ELSE requester_id END
               FROM friendships WHERE requester_id = :me OR addressee_id = :me
           )',
        [':me' => $me]
    );
    return max(0, (int) ($row['total'] ?? 0));
}

function friends_search_addable_users(PDO $pdo, int $me, string $query, int $limit = 10): array
{
    $limit = max(1, min(10, $limit));
    $query = trim($query);
    $params = [':me' => $me];
    $search = '';
    if ($query !== '') {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);
        $params[':query'] = '%' . $escaped . '%';
        $search = ' AND (display_name LIKE :query ESCAPE "\\" OR username LIKE :query ESCAPE "\\")';
    }

    return db_fetch_all(
        $pdo,
        'SELECT * FROM users
         WHERE active = 1 AND id <> :me
           AND id NOT IN (
               SELECT CASE WHEN requester_id = :me THEN addressee_id ELSE requester_id END
               FROM friendships WHERE requester_id = :me OR addressee_id = :me
           )' . $search . '
         ORDER BY display_name COLLATE NOCASE ASC
         LIMIT ' . $limit,
        $params
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
