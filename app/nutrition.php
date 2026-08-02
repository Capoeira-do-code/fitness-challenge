<?php

declare(strict_types=1);

/**
 * Keep the optional Nutrition workspace fields available on upgraded installs.
 *
 * This lives next to the feature instead of making archived meals depend on a
 * one-off migration being run manually. The static guard keeps the schema check
 * to once per PDO connection/request.
 */
function nutrition_ensure_schema(PDO $pdo): void
{
    static $ready = [];
    $connectionId = spl_object_id($pdo);
    if (isset($ready[$connectionId])) {
        return;
    }

    ensure_column($pdo, 'nutrition_entries', 'archived_at', 'TEXT');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_nutrition_entries_user_archive_date
        ON nutrition_entries(user_id, archived_at, entry_date DESC, entry_time DESC)');
    $ready[$connectionId] = true;
}

/** Parse a localized non-negative number without silently truncating `12,5`. */
function nutrition_optional_number(array $input, string $key): ?float
{
    $raw = trim((string) ($input[$key] ?? ''));
    if ($raw === '') {
        return null;
    }
    $normalized = str_replace([' ', ','], ['', '.'], $raw);
    if (!is_numeric($normalized)) {
        throw new InvalidArgumentException(t('metric.invalid'));
    }

    return max(0.0, (float) $normalized);
}

function nutrition_activity_factor(string $level): float
{
    return match ($level) {
        'sedentary' => 1.2,
        'light' => 1.375,
        'active' => 1.725,
        'very_active' => 1.9,
        default => 1.55,
    };
}

function nutrition_tdee(array $user, ?float $weight = null, ?string $onDate = null): ?array
{
    if (($user['tdee_override'] ?? '') !== '' && (float) $user['tdee_override'] > 0) {
        return ['value' => round((float) $user['tdee_override']), 'estimated' => false, 'source' => 'manual'];
    }
    $height = (float) ($user['height_cm'] ?? 0);
    $weight = $weight ?? (float) ($user['ideal_weight'] ?? 0);
    $birthDate = trim((string) ($user['birth_date'] ?? ''));
    $sex = trim((string) ($user['tdee_sex'] ?? ''));
    if ($height <= 0 || $weight <= 0 || $birthDate === '' || !in_array($sex, ['female', 'male'], true)) {
        return null;
    }
    try {
        $age = (new DateTimeImmutable($birthDate))->diff(new DateTimeImmutable($onDate ?: 'today'))->y;
    } catch (Throwable) {
        return null;
    }
    $bmr = (10 * $weight) + (6.25 * $height) - (5 * $age) + ($sex === 'male' ? 5 : -161);
    return [
        'value' => round($bmr * nutrition_activity_factor((string) ($user['activity_level'] ?? 'moderate'))),
        'estimated' => true,
        'source' => 'mifflin',
    ];
}

function nutrition_latest_weight(PDO $pdo, int $userId): ?float
{
    $row = db_fetch_one(
        $pdo,
        'SELECT weight FROM daily_logs
         WHERE user_id = :user AND weight IS NOT NULL AND weight > 0
         ORDER BY log_date DESC, id DESC LIMIT 1',
        [':user' => $userId]
    );
    $weight = (float) ($row['weight'] ?? 0);
    return $weight > 0 ? $weight : null;
}

/**
 * Return one active meal when its owner allows the viewer to see nutrition.
 *
 * This is the canonical read model for links outside the owner's Nutrition
 * workspace. Archived meals deliberately behave like missing meals so a
 * guessed URL cannot reveal either their contents or their existence.
 */
function nutrition_public_entry_for_viewer(PDO $pdo, int $entryId, array $viewer): ?array
{
    nutrition_ensure_schema($pdo);
    $viewerId = (int) ($viewer['id'] ?? 0);
    if ($entryId <= 0 || $viewerId <= 0) {
        return null;
    }

    $entry = db_fetch_one(
        $pdo,
        'SELECT n.*, u.username, u.display_name, u.avatar_path, u.profile_cover_path,
                u.profile_visibility, u.data_visibility_json, u.active AS owner_active
         FROM nutrition_entries n
         JOIN users u ON u.id=n.user_id
         WHERE n.id=:id AND n.archived_at IS NULL AND u.active=1
         LIMIT 1',
        [':id' => $entryId]
    );
    if ($entry === null) {
        return null;
    }

    $ownerId = (int) ($entry['user_id'] ?? 0);
    $viewerIsAdmin = is_admin($viewer);
    if (
        !can_view_user_content(
            $pdo,
            $viewerId,
            $ownerId,
            $viewerIsAdmin,
            (string) ($entry['profile_visibility'] ?? 'public')
        )
        || !can_view_user_data($pdo, $viewerId, $ownerId, 'nutrition', $viewerIsAdmin, $entry)
    ) {
        return null;
    }

    return $entry;
}

function nutrition_create_entry(PDO $pdo, int $userId, array $input, ?string $photoPath = null): array
{
    nutrition_ensure_schema($pdo);
    $date = to_date((string) ($input['entry_date'] ?? null));
    $mealType = in_array(($input['meal_type'] ?? ''), ['breakfast', 'lunch', 'dinner', 'snack', 'other'], true)
        ? (string) $input['meal_type'] : 'other';
    $numeric = static fn(string $key): ?float => nutrition_optional_number($input, $key);
    $calories = $numeric('calories') ?? 0.0;
    if ($calories <= 0 && trim((string) ($input['notes'] ?? '')) === '' && $photoPath === null) {
        throw new InvalidArgumentException(t('metric.invalid'));
    }
    db_execute(
        $pdo,
        'INSERT INTO nutrition_entries
            (user_id, entry_date, entry_time, meal_type, notes, photo_path, calories, protein_g, carbs_g, fat_g,
             fiber_g, sugar_g, sodium_mg, version, created_at, updated_at)
         VALUES (:user, :date, :time, :type, :notes, :photo, :calories, :protein, :carbs, :fat,
                 :fiber, :sugar, :sodium, 1, :now, :now)',
        [
            ':user' => $userId, ':date' => $date,
            ':time' => normalize_log_time($input['entry_time'] ?? '', (new DateTimeImmutable())->format('H:i')),
            ':type' => $mealType, ':notes' => trim((string) ($input['notes'] ?? '')), ':photo' => $photoPath,
            ':calories' => $calories, ':protein' => $numeric('protein_g'), ':carbs' => $numeric('carbs_g'),
            ':fat' => $numeric('fat_g'), ':fiber' => $numeric('fiber_g'), ':sugar' => $numeric('sugar_g'),
            ':sodium' => $numeric('sodium_mg'), ':now' => now_iso(),
        ]
    );
    return db_fetch_one($pdo, 'SELECT * FROM nutrition_entries WHERE id = :id AND user_id = :user', [
        ':id' => (int) $pdo->lastInsertId(), ':user' => $userId,
    ]) ?? [];
}

/** Update one meal owned by $userId and keep its gallery photo in sync. */
function nutrition_update_entry(PDO $pdo, array $config, int $entryId, int $userId, array $input): ?array
{
    nutrition_ensure_schema($pdo);
    $entry = db_fetch_one(
        $pdo,
        'SELECT * FROM nutrition_entries WHERE id=:id AND user_id=:user LIMIT 1',
        [':id' => $entryId, ':user' => $userId]
    );
    if ($entry === null) {
        return null;
    }
    $stateAction = trim((string) ($input['nutrition_entry_state'] ?? ''));
    if ($stateAction !== '') {
        if (!in_array($stateAction, ['archive', 'unarchive'], true)) {
            throw new InvalidArgumentException(t('metric.invalid'));
        }
        db_execute(
            $pdo,
            'UPDATE nutrition_entries
             SET archived_at=:archived, version=version+1, updated_at=:now
             WHERE id=:id AND user_id=:user',
            [
                ':archived' => $stateAction === 'archive' ? now_iso() : null,
                ':now' => now_iso(),
                ':id' => $entryId,
                ':user' => $userId,
            ]
        );

        return db_fetch_one(
            $pdo,
            'SELECT * FROM nutrition_entries WHERE id=:id AND user_id=:user LIMIT 1',
            [':id' => $entryId, ':user' => $userId]
        );
    }
    $date = to_date((string) ($input['entry_date'] ?? $entry['entry_date'] ?? null));
    $mealType = in_array(($input['meal_type'] ?? ''), ['breakfast', 'lunch', 'dinner', 'snack', 'other'], true)
        ? (string) $input['meal_type'] : 'other';
    $numeric = static fn(string $key): ?float => nutrition_optional_number($input, $key);
    $calories = $numeric('calories') ?? 0.0;
    $notes = trim((string) ($input['notes'] ?? ''));
    if ($calories <= 0 && $notes === '' && trim((string) ($entry['photo_path'] ?? '')) === '') {
        throw new InvalidArgumentException(t('metric.invalid'));
    }
    $time = normalize_log_time($input['entry_time'] ?? '', (string) ($entry['entry_time'] ?? ''));
    $nutrition = [
        'calories' => $calories,
        'protein_g' => $numeric('protein_g'),
        'carbs_g' => $numeric('carbs_g'),
        'fat_g' => $numeric('fat_g'),
        'fiber_g' => $numeric('fiber_g'),
        'sugar_g' => $numeric('sugar_g'),
        'sodium_mg' => $numeric('sodium_mg'),
    ];

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $photoId = (int) ($entry['photo_entry_id'] ?? 0);
        if ($photoId > 0) {
            $updatedPhoto = update_photo_entry($pdo, $config, $photoId, $date, $mealType, $notes, $nutrition);
            if ($updatedPhoto === null) {
                throw new RuntimeException(t('flash.not_found'));
            }
        }
        // Write the canonical meal row after the optional gallery sync. This also
        // preserves meal-only types such as "snack", which photo categories do not expose.
        db_execute(
            $pdo,
            'UPDATE nutrition_entries SET entry_date=:date, entry_time=:time, meal_type=:type, notes=:notes,
                calories=:calories, protein_g=:protein, carbs_g=:carbs, fat_g=:fat, fiber_g=:fiber,
                sugar_g=:sugar, sodium_mg=:sodium, version=version+1, updated_at=:now
             WHERE id=:id AND user_id=:user',
            [
                ':date' => $date, ':time' => $time, ':type' => $mealType, ':notes' => $notes,
                ':calories' => $nutrition['calories'], ':protein' => $nutrition['protein_g'],
                ':carbs' => $nutrition['carbs_g'], ':fat' => $nutrition['fat_g'], ':fiber' => $nutrition['fiber_g'],
                ':sugar' => $nutrition['sugar_g'], ':sodium' => $nutrition['sodium_mg'], ':now' => now_iso(),
                ':id' => $entryId, ':user' => $userId,
            ]
        );
        if ($ownsTransaction) {
            $pdo->commit();
        }

        return db_fetch_one($pdo, 'SELECT * FROM nutrition_entries WHERE id=:id AND user_id=:user', [':id' => $entryId, ':user' => $userId]);
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Delete one meal and its linked gallery post/media when present. */
function nutrition_delete_entry(PDO $pdo, array $config, int $entryId, int $userId): ?array
{
    nutrition_ensure_schema($pdo);
    $entry = db_fetch_one(
        $pdo,
        'SELECT * FROM nutrition_entries WHERE id=:id AND user_id=:user LIMIT 1',
        [':id' => $entryId, ':user' => $userId]
    );
    if ($entry === null) {
        return null;
    }
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $photoId = (int) ($entry['photo_entry_id'] ?? 0);
        $mediaPath = trim((string) ($entry['photo_path'] ?? ''));
        if ($photoId > 0) {
            $deletedPhoto = delete_photo_entry($pdo, $config, $photoId, false);
            $mediaPath = trim((string) ($deletedPhoto['file_path'] ?? $mediaPath));
        }
        db_execute($pdo, 'DELETE FROM social_feed_likes WHERE entity_type="meal" AND entity_id=:id', [':id' => $entryId]);
        db_execute($pdo, 'DELETE FROM social_feed_comments WHERE entity_type="meal" AND entity_id=:id', [':id' => $entryId]);
        db_execute(
            $pdo,
            'DELETE FROM user_notifications
             WHERE kind IN ("social_like", "social_comment", "social_reply")
               AND json_extract(
                   CASE WHEN json_valid(payload_json) THEN payload_json ELSE "{}" END,
                   "$.entity_type"
               ) = "meal"
               AND CAST(json_extract(
                   CASE WHEN json_valid(payload_json) THEN payload_json ELSE "{}" END,
                   "$.entity_id"
               ) AS INTEGER) = :id',
            [':id' => $entryId]
        );
        db_execute($pdo, 'DELETE FROM nutrition_entries WHERE id=:id AND user_id=:user', [':id' => $entryId, ':user' => $userId]);
        if ($ownsTransaction) {
            $pdo->commit();
            remove_media_file_if_unreferenced($pdo, $config, $mediaPath, 'delete_nutrition_entry');
        }

        return $entry;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function nutrition_daily_summary(PDO $pdo, array $user, string $from, string $to): array
{
    nutrition_ensure_schema($pdo);
    $mealRows = db_fetch_all(
        $pdo,
        'SELECT n.entry_date, COALESCE(SUM(n.calories), 0) AS consumed,
                COALESCE(SUM(n.protein_g), 0) AS protein_g,
                COALESCE(SUM(n.carbs_g), 0) AS carbs_g,
                COALESCE(SUM(n.fat_g), 0) AS fat_g
         FROM nutrition_entries n
         WHERE n.user_id = :user AND n.entry_date BETWEEN :from AND :to
         GROUP BY n.entry_date ORDER BY n.entry_date',
        [':user' => (int) $user['id'], ':from' => to_date($from), ':to' => to_date($to)]
    );
    $mealsByDate = [];
    foreach ($mealRows as $mealRow) {
        $mealsByDate[(string) $mealRow['entry_date']] = $mealRow;
    }
    $logs = db_fetch_all(
        $pdo,
        'SELECT log_date, COALESCE(training_calories_burned, 0) AS exercise, weight
         FROM daily_logs WHERE user_id = :user AND log_date BETWEEN :from AND :to',
        [':user' => (int) $user['id'], ':from' => to_date($from), ':to' => to_date($to)]
    );
    $logsByDate = [];
    $latestWeight = nutrition_latest_weight($pdo, (int) $user['id']);
    foreach ($logs as $log) {
        $logsByDate[(string) $log['log_date']] = $log;
    }
    $rows = [];
    $cursor = new DateTimeImmutable(to_date($from));
    $lastDate = new DateTimeImmutable(to_date($to));
    while ($cursor <= $lastDate) {
        $date = $cursor->format('Y-m-d');
        $hasMealData = isset($mealsByDate[$date]);
        $row = $mealsByDate[$date] ?? [
            'entry_date' => $date,
            'consumed' => 0,
            'protein_g' => 0,
            'carbs_g' => 0,
            'fat_g' => 0,
        ];
        $log = $logsByDate[$date] ?? [];
        $tdee = nutrition_tdee($user, ($log['weight'] ?? '') !== '' ? (float) $log['weight'] : $latestWeight, $date);
        $row['has_meal_data'] = $hasMealData;
        $row['exercise'] = (float) ($log['exercise'] ?? 0);
        $row['tdee'] = $tdee['value'] ?? null;
        $row['estimated'] = $tdee['estimated'] ?? null;
        $row['balance'] = !$hasMealData || $tdee === null
            ? null
            : (float) $row['consumed'] - ((float) $tdee['value'] + $row['exercise']);
        $rows[] = $row;
        $cursor = $cursor->modify('+1 day');
    }
    return $rows;
}
