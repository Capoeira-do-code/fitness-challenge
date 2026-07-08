<?php

declare(strict_types=1);

const APPROVAL_TYPE_STEP_EXCEPTION = 'step_exception';
const APPROVAL_TYPE_DISTANCE_EXCEPTION = 'distance_exception';
const APPROVAL_TYPE_WORKOUT_EXCEPTION = 'workout_exception';
const APPROVAL_TYPE_EXTRA_WORKOUT_OVERRIDE = 'extra_workout_override';

const APPROVAL_STATUS_PENDING = 'pending';
const APPROVAL_STATUS_APPROVED = 'approved';
const APPROVAL_STATUS_REJECTED = 'rejected';

function app_cache_enabled(): bool
{
    $config = is_array($GLOBALS['config'] ?? null) ? (array) $GLOBALS['config'] : [];
    $raw = strtolower(trim((string) ($config['app_cache_enabled'] ?? getenv('APP_CACHE_ENABLED') ?: '1')));

    return in_array($raw, ['1', 'true', 'yes', 'on'], true);
}

function app_cache_dir(): string
{
    $config = is_array($GLOBALS['config'] ?? null) ? (array) $GLOBALS['config'] : [];
    $dbPath = (string) ($config['db_path'] ?? dirname(__DIR__) . '/storage/fitness.sqlite');

    return dirname($dbPath) . '/cache';
}

function app_cache_path(string $key): string
{
    return app_cache_dir() . '/' . hash('sha256', $key) . '.json';
}

function app_cache_get(string $key, int $ttlSeconds = 300): mixed
{
    if (!app_cache_enabled()) {
        return null;
    }
    $path = app_cache_path($key);
    if (!is_file($path) || filemtime($path) === false || (time() - (int) filemtime($path)) > max(1, $ttlSeconds)) {
        return null;
    }

    $raw = file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $payload = json_decode($raw, true);
    if (!is_array($payload) || !array_key_exists('value', $payload)) {
        return null;
    }

    return $payload['value'];
}

function app_cache_set(string $key, mixed $value): void
{
    if (!app_cache_enabled()) {
        return;
    }
    $dir = app_cache_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $encoded = json_encode(['created_at' => now_iso(), 'value' => $value], JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        return;
    }

    file_put_contents(app_cache_path($key), $encoded, LOCK_EX);
}

function app_cache_clear(): void
{
    $dir = app_cache_dir();
    if (!is_dir($dir)) {
        return;
    }

    foreach (glob($dir . '/*.json') ?: [] as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

function normalize_log_time(mixed $logTimeRaw, string $fallback = '00:00'): string
{
    $time = trim((string) $logTimeRaw);
    if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $time, $matches) === 1) {
        return sprintf('%02d:%02d', (int) $matches[1], (int) $matches[2]);
    }

    if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $fallback, $matches) === 1) {
        return sprintf('%02d:%02d', (int) $matches[1], (int) $matches[2]);
    }

    return '00:00';
}

function log_datetime_from_values(?string $logDate, mixed $logTimeRaw, string $fallbackTime = '00:00'): ?DateTimeImmutable
{
    $date = trim((string) $logDate);
    if ($date === '') {
        return null;
    }

    $time = normalize_log_time($logTimeRaw, $fallbackTime);
    try {
        return new DateTimeImmutable($date . ' ' . $time . ':00');
    } catch (Throwable) {
        return null;
    }
}

function normalize_mask(mixed $selectedDays): string
{
    if (!is_array($selectedDays)) {
        $selectedDays = [];
    }

    $mask = '';
    for ($i = 0; $i < 7; $i++) {
        $mask .= in_array((string) $i, $selectedDays, true) ? '1' : '0';
    }

    return $mask;
}

function workout_field_data_key_options(): array
{
    return ['', 'distance_km', 'training_calories_burned', 'steps'];
}

function normalize_workout_field_data_key(mixed $dataKey): string
{
    $key = strtolower(trim((string) $dataKey));
    if ($key === 'distance' || $key === 'km') {
        $key = 'distance_km';
    }
    if ($key === 'calories' || $key === 'calories_burned') {
        $key = 'training_calories_burned';
    }

    return in_array($key, workout_field_data_key_options(), true) ? $key : '';
}

function normalize_workout_field_input_kind(mixed $inputKind): string
{
    return strtolower(trim((string) $inputKind)) === 'text' ? 'text' : 'number';
}

function normalize_optional_float(mixed $value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    $normalized = str_replace(',', '.', trim((string) $value));
    if ($normalized === '' || !is_numeric($normalized)) {
        return null;
    }

    return (float) $normalized;
}

function normalize_workout_field_values(PDO $pdo, ?int $workoutTypeId, mixed $rawFields): array
{
    if ($workoutTypeId === null || $workoutTypeId <= 0 || !is_array($rawFields)) {
        return [];
    }

    $incoming = [];
    foreach ($rawFields as $key => $rawValue) {
        if (is_array($rawValue) && isset($rawValue['field_id'])) {
            $incoming[(int) $rawValue['field_id']] = $rawValue['value_text'] ?? $rawValue['value_number'] ?? $rawValue['value'] ?? '';
            continue;
        }
        $incoming[(int) $key] = $rawValue;
    }

    $fields = list_workout_type_fields($pdo, $workoutTypeId, true);
    $normalized = [];
    foreach ($fields as $field) {
        $fieldId = (int) ($field['id'] ?? 0);
        if ($fieldId <= 0 || !array_key_exists($fieldId, $incoming)) {
            if (!empty($field['required'])) {
                throw new InvalidArgumentException('Workout field is required.');
            }
            continue;
        }

        $rawValue = $incoming[$fieldId];
        if (is_array($rawValue)) {
            continue;
        }
        $valueText = trim((string) $rawValue);
        if ($valueText === '') {
            if (!empty($field['required'])) {
                throw new InvalidArgumentException('Workout field is required.');
            }
            continue;
        }

        $inputKind = normalize_workout_field_input_kind($field['input_kind'] ?? 'number');
        $valueNumber = null;
        if ($inputKind === 'number') {
            $valueNumber = normalize_optional_float($valueText);
            if ($valueNumber === null) {
                throw new InvalidArgumentException('Workout field value must be numeric.');
            }
            if (in_array((string) ($field['data_key'] ?? ''), ['distance_km', 'training_calories_burned', 'steps'], true)) {
                $valueNumber = max(0.0, $valueNumber);
            }
        }

        $normalized[] = [
            'field_id' => $fieldId,
            'field_label' => (string) ($field['label'] ?? ''),
            'input_kind' => $inputKind,
            'data_key' => normalize_workout_field_data_key($field['data_key'] ?? ''),
            'value_text' => $valueText,
            'value_number' => $valueNumber,
        ];
    }

    return $normalized;
}

function apply_workout_metric_totals(array $payload): array
{
    $baseSteps = max(0, (int) ($payload['base_steps'] ?? $payload['steps'] ?? 0));
    $baseDistance = normalize_optional_float($payload['base_distance_km'] ?? $payload['distance_km'] ?? null);
    $baseCalories = normalize_optional_float($payload['base_training_calories_burned'] ?? $payload['training_calories_burned'] ?? null);

    $extraSteps = 0.0;
    $extraDistance = 0.0;
    $extraCalories = 0.0;
    foreach ((array) ($payload['workouts'] ?? []) as $workout) {
        if (!is_array($workout)) {
            continue;
        }
        foreach ((array) ($workout['fields'] ?? []) as $fieldValue) {
            if (!is_array($fieldValue)) {
                continue;
            }
            $dataKey = normalize_workout_field_data_key($fieldValue['data_key'] ?? '');
            $number = normalize_optional_float($fieldValue['value_number'] ?? $fieldValue['value_text'] ?? null);
            if ($number === null) {
                continue;
            }
            if ($dataKey === 'steps') {
                $extraSteps += max(0.0, $number);
            } elseif ($dataKey === 'distance_km') {
                $extraDistance += max(0.0, $number);
            } elseif ($dataKey === 'training_calories_burned') {
                $extraCalories += max(0.0, $number);
            }
        }
    }

    $payload['base_steps'] = $baseSteps;
    $payload['base_distance_km'] = $baseDistance;
    $payload['base_training_calories_burned'] = $baseCalories;
    $payload['steps'] = $baseSteps + (int) round($extraSteps);
    $payload['distance_km'] = $baseDistance !== null || $extraDistance > 0 ? round((float) ($baseDistance ?? 0) + $extraDistance, 2) : null;
    $payload['training_calories_burned'] = $baseCalories !== null || $extraCalories > 0 ? round((float) ($baseCalories ?? 0) + $extraCalories, 2) : null;

    return $payload;
}

function normalize_log_workouts_payload(PDO $pdo, array $payload, ?int $actorUserId = null): array
{
    $hasWorkouts = isset($payload['workouts']) && is_array($payload['workouts']);
    $rawWorkouts = $hasWorkouts ? array_values((array) $payload['workouts']) : [];

    if (!$hasWorkouts) {
        $legacyWorkoutDone = (int) ($payload['workout_done'] ?? 0) === 1;
        $legacyWorkoutDoneProvided = array_key_exists('workout_done', $payload);
        $legacyWorkoutTypeId = !empty($payload['workout_type_id']) ? (int) $payload['workout_type_id'] : null;
        $legacyWorkoutType = trim((string) ($payload['workout_type'] ?? ''));
        if ($legacyWorkoutDone && $legacyWorkoutTypeId === null && $legacyWorkoutType === '') {
            $legacyWorkoutType = 'Workout';
            $rawWorkouts[] = [
                'workout_type_id' => null,
                'workout_type' => $legacyWorkoutType,
                'skip_type_persist' => true,
            ];
        } elseif ($legacyWorkoutDone || (!$legacyWorkoutDoneProvided && ($legacyWorkoutTypeId !== null || $legacyWorkoutType !== ''))) {
            $rawWorkouts[] = [
                'workout_type_id' => $legacyWorkoutTypeId,
                'workout_type' => $legacyWorkoutType,
            ];
        }
    }

    $normalized = [];
    foreach ($rawWorkouts as $index => $row) {
        if (!is_array($row)) {
            continue;
        }

        $normalizedRow = normalize_workout_row($pdo, $row, $actorUserId);
        if ($normalizedRow === null) {
            continue;
        }
        $normalizedRow['sort_order'] = $index + 1;
        $normalized[] = $normalizedRow;
    }

    usort(
        $normalized,
        static fn(array $a, array $b): int => ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0))
    );
    $normalized = array_values(array_map(
        static function (array $row, int $idx): array {
            $row['sort_order'] = $idx + 1;
            return $row;
        },
        $normalized,
        array_keys($normalized)
    ));

    $payload['workouts'] = $normalized;
    $payload['workout_done'] = $normalized !== [] ? 1 : 0;
    $payload['workout_type_id'] = $normalized !== [] ? ($normalized[0]['workout_type_id'] ?? null) : null;
    $payload['workout_type'] = $normalized !== [] ? (string) ($normalized[0]['workout_type'] ?? '') : '';
    $payload['extra_workout'] = count($normalized) > 1 ? 1 : 0;

    return apply_workout_metric_totals($payload);
}

function normalize_workout_row(PDO $pdo, array $row, ?int $actorUserId = null): ?array
{
    $rawTypeId = $row['workout_type_id'] ?? null;
    $rawType = trim((string) ($row['workout_type'] ?? ''));
    $skipTypePersist = !empty($row['skip_type_persist']);

    $workoutTypeId = null;
    if (is_int($rawTypeId) && $rawTypeId > 0) {
        $workoutTypeId = $rawTypeId;
    } elseif (is_string($rawTypeId)) {
        $trimmedTypeId = trim($rawTypeId);
        if ($trimmedTypeId !== '' && $trimmedTypeId !== '__custom__' && ctype_digit($trimmedTypeId) && (int) $trimmedTypeId > 0) {
            $workoutTypeId = (int) $trimmedTypeId;
        }
    } elseif (is_numeric($rawTypeId) && (int) $rawTypeId > 0) {
        $workoutTypeId = (int) $rawTypeId;
    }

    if ($workoutTypeId !== null) {
        $typeRow = db_fetch_one($pdo, 'SELECT id, name FROM workout_types WHERE id = :id', [':id' => $workoutTypeId]);
        if ($typeRow === null) {
            $workoutTypeId = null;
        } elseif ($rawType === '') {
            $rawType = trim((string) ($typeRow['name'] ?? ''));
        }
    }

    if (!$skipTypePersist && $workoutTypeId === null && $rawType !== '') {
        $savedTypeId = save_workout_type_if_needed($pdo, $rawType, $actorUserId);
        if ($savedTypeId !== null) {
            $workoutTypeId = $savedTypeId;
        }
    }

    if ($workoutTypeId !== null && $rawType === '') {
        $typeRow = db_fetch_one($pdo, 'SELECT name FROM workout_types WHERE id = :id', [':id' => $workoutTypeId]);
        if ($typeRow !== null) {
            $rawType = trim((string) ($typeRow['name'] ?? ''));
        }
    }

    if ($workoutTypeId === null && $rawType === '') {
        return null;
    }
    $fieldValues = normalize_workout_field_values($pdo, $workoutTypeId, $row['fields'] ?? []);

    return [
        'workout_type_id' => $workoutTypeId,
        'workout_type' => $rawType !== '' ? $rawType : 'Workout',
        'fields' => $fieldValues,
    ];
}

function sync_log_workouts(PDO $pdo, int $logId, array $workouts): void
{
    if ($logId <= 0) {
        return;
    }

    db_execute(
        $pdo,
        'DELETE FROM daily_log_workouts WHERE log_id = :log_id',
        [':log_id' => $logId]
    );

    if ($workouts === []) {
        return;
    }

    $now = now_iso();
    foreach (array_values($workouts) as $index => $workout) {
        if (!is_array($workout)) {
            continue;
        }

        $workoutTypeId = !empty($workout['workout_type_id']) ? (int) $workout['workout_type_id'] : null;
        $workoutType = trim((string) ($workout['workout_type'] ?? ''));
        if ($workoutType === '' && $workoutTypeId !== null) {
            $typeRow = db_fetch_one($pdo, 'SELECT name FROM workout_types WHERE id = :id', [':id' => $workoutTypeId]);
            if ($typeRow !== null) {
                $workoutType = trim((string) ($typeRow['name'] ?? ''));
            }
        }
        if ($workoutType === '' && $workoutTypeId === null) {
            continue;
        }
        if ($workoutType === '') {
            $workoutType = 'Workout';
        }

        db_execute(
            $pdo,
            'INSERT INTO daily_log_workouts (log_id, workout_type_id, workout_type, sort_order, created_at, updated_at)
             VALUES (:log_id, :workout_type_id, :workout_type, :sort_order, :created_at, :updated_at)',
            [
                ':log_id' => $logId,
                ':workout_type_id' => $workoutTypeId,
                ':workout_type' => $workoutType,
                ':sort_order' => $index + 1,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]
        );
        $insertedWorkout = db_fetch_one($pdo, 'SELECT id FROM daily_log_workouts WHERE id = last_insert_rowid()');
        $workoutId = (int) ($insertedWorkout['id'] ?? 0);
        if ($workoutId <= 0) {
            continue;
        }
        foreach ((array) ($workout['fields'] ?? []) as $fieldValue) {
            if (!is_array($fieldValue)) {
                continue;
            }
            $fieldLabel = trim((string) ($fieldValue['field_label'] ?? ''));
            if ($fieldLabel === '') {
                continue;
            }
            db_execute(
                $pdo,
                'INSERT INTO daily_log_workout_field_values (
                    workout_id, field_id, field_label, data_key, value_text, value_number, created_at, updated_at
                ) VALUES (
                    :workout_id, :field_id, :field_label, :data_key, :value_text, :value_number, :created_at, :updated_at
                )',
                [
                    ':workout_id' => $workoutId,
                    ':field_id' => !empty($fieldValue['field_id']) ? (int) $fieldValue['field_id'] : null,
                    ':field_label' => $fieldLabel,
                    ':data_key' => normalize_workout_field_data_key($fieldValue['data_key'] ?? ''),
                    ':value_text' => trim((string) ($fieldValue['value_text'] ?? '')),
                    ':value_number' => normalize_optional_float($fieldValue['value_number'] ?? null),
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]
            );
        }
    }
}

function evaluate_primary_goal_failures(array $user, array $payload): array
{
    $primaryGoals = user_primary_goals($user);
    $stepsValue = max(0, (int) ($payload['steps'] ?? 0));
    $kmValue = max(0.0, (float) ($payload['distance_km'] ?? 0));
    $workoutCounted = (int) ($payload['workout_done'] ?? 0) === 1 ? 1.0 : 0.0;

    $missingSteps = false;
    $missingKm = false;
    $missingWorkout = false;

    foreach ($primaryGoals as $goal) {
        $type = strtolower(trim((string) ($goal['type'] ?? '')));
        $target = (float) ($goal['value'] ?? 0);
        if ($target <= 0) {
            continue;
        }

        if ($type === 'steps' && (float) $stepsValue < $target) {
            $missingSteps = true;
        } elseif ($type === 'km' && $kmValue < $target) {
            $missingKm = true;
        } elseif ($type === 'workouts' && $workoutCounted < $target) {
            $missingWorkout = true;
        }
    }

    $failedItems = [];
    if ($missingSteps) {
        $failedItems[] = 'steps';
    }
    if ($missingKm) {
        $failedItems[] = 'km';
    }
    if ($missingWorkout) {
        $failedItems[] = 'workouts';
    }

    return [
        'steps' => $missingSteps || $missingKm,
        'workout' => $missingWorkout,
        'missing_steps' => $missingSteps,
        'missing_km' => $missingKm,
        'missing_workouts' => $missingWorkout,
        'failed_items' => $failedItems,
    ];
}

function upsert_daily_log(PDO $pdo, array $payload): void
{
    $workoutTypeId = null;
    $workoutTypeName = trim((string) ($payload['workout_type'] ?? ''));
    if (!empty($payload['workout_type_id'])) {
        $workoutTypeId = (int) $payload['workout_type_id'];
        $typeRow = db_fetch_one($pdo, 'SELECT name FROM workout_types WHERE id = :id', [':id' => $workoutTypeId]);
        if ($typeRow !== null && $workoutTypeName === '') {
            $workoutTypeName = (string) $typeRow['name'];
        }
    }

    $existing = db_fetch_one(
        $pdo,
        'SELECT id FROM daily_logs WHERE user_id = :user_id AND log_date = :log_date',
        [':user_id' => $payload['user_id'], ':log_date' => $payload['log_date']]
    );

    $now = now_iso();

    if ($existing === null) {
        db_execute(
            $pdo,
            'INSERT INTO daily_logs (
                user_id, log_date, log_time, steps, workout_done, workout_type_id, workout_type,
                junk_food, extra_workout, base_steps, base_distance_km, base_training_calories_burned,
                distance_km, training_calories_burned, weight, notes, step_exception_reason,
                distance_exception_reason, workout_exception_reason, morning_walk, journaling,
                evening_chores, reading, created_at, updated_at
            ) VALUES (
                :user_id, :log_date, :log_time, :steps, :workout_done, :workout_type_id, :workout_type,
                :junk_food, :extra_workout, :base_steps, :base_distance_km, :base_training_calories_burned,
                :distance_km, :training_calories_burned, :weight, :notes, :step_exception_reason,
                :distance_exception_reason, :workout_exception_reason, :morning_walk, :journaling,
                :evening_chores, :reading, :created_at, :updated_at
            )',
            [
                ':user_id' => $payload['user_id'],
                ':log_date' => $payload['log_date'],
                ':log_time' => normalize_log_time($payload['log_time'] ?? '', '00:00'),
                ':steps' => $payload['steps'],
                ':workout_done' => $payload['workout_done'],
                ':workout_type_id' => $workoutTypeId,
                ':workout_type' => $workoutTypeName,
                ':junk_food' => $payload['junk_food'],
                ':extra_workout' => $payload['extra_workout'],
                ':base_steps' => $payload['base_steps'] ?? $payload['steps'],
                ':base_distance_km' => $payload['base_distance_km'] ?? null,
                ':base_training_calories_burned' => $payload['base_training_calories_burned'] ?? null,
                ':distance_km' => $payload['distance_km'] ?? null,
                ':training_calories_burned' => $payload['training_calories_burned'] ?? null,
                ':weight' => $payload['weight'],
                ':notes' => $payload['notes'],
                ':step_exception_reason' => $payload['step_exception_reason'],
                ':distance_exception_reason' => $payload['distance_exception_reason'] ?? '',
                ':workout_exception_reason' => $payload['workout_exception_reason'],
                ':morning_walk' => $payload['morning_walk'],
                ':journaling' => $payload['journaling'],
                ':evening_chores' => $payload['evening_chores'],
                ':reading' => $payload['reading'],
                ':created_at' => $now,
                ':updated_at' => $now,
            ]
        );

        return;
    }

    db_execute(
        $pdo,
        'UPDATE daily_logs
         SET steps = :steps,
             log_time = :log_time,
             workout_done = :workout_done,
             workout_type_id = :workout_type_id,
             workout_type = :workout_type,
             junk_food = :junk_food,
             extra_workout = :extra_workout,
             base_steps = :base_steps,
             base_distance_km = :base_distance_km,
             base_training_calories_burned = :base_training_calories_burned,
             distance_km = :distance_km,
             training_calories_burned = :training_calories_burned,
             weight = :weight,
             notes = :notes,
             step_exception_reason = :step_exception_reason,
             distance_exception_reason = :distance_exception_reason,
             workout_exception_reason = :workout_exception_reason,
             morning_walk = :morning_walk,
             journaling = :journaling,
             evening_chores = :evening_chores,
             reading = :reading,
             updated_at = :updated_at
         WHERE id = :id',
        [
            ':steps' => $payload['steps'],
            ':log_time' => normalize_log_time($payload['log_time'] ?? '', '00:00'),
            ':workout_done' => $payload['workout_done'],
            ':workout_type_id' => $workoutTypeId,
            ':workout_type' => $workoutTypeName,
            ':junk_food' => $payload['junk_food'],
            ':extra_workout' => $payload['extra_workout'],
            ':base_steps' => $payload['base_steps'] ?? $payload['steps'],
            ':base_distance_km' => $payload['base_distance_km'] ?? null,
            ':base_training_calories_burned' => $payload['base_training_calories_burned'] ?? null,
            ':distance_km' => $payload['distance_km'] ?? null,
            ':training_calories_burned' => $payload['training_calories_burned'] ?? null,
            ':weight' => $payload['weight'],
            ':notes' => $payload['notes'],
            ':step_exception_reason' => $payload['step_exception_reason'],
            ':distance_exception_reason' => $payload['distance_exception_reason'] ?? '',
            ':workout_exception_reason' => $payload['workout_exception_reason'],
            ':morning_walk' => $payload['morning_walk'],
            ':journaling' => $payload['journaling'],
            ':evening_chores' => $payload['evening_chores'],
            ':reading' => $payload['reading'],
            ':updated_at' => $now,
            ':id' => (int) $existing['id'],
        ]
    );
}

function upsert_daily_log_and_sync_approvals(PDO $pdo, array $payload, int $actorUserId): void
{
    $pdo->beginTransaction();

    try {
        $payload = normalize_log_workouts_payload($pdo, $payload, $actorUserId);
        upsert_daily_log($pdo, $payload);
        $log = db_fetch_one(
            $pdo,
            'SELECT id FROM daily_logs WHERE user_id = :user_id AND log_date = :log_date LIMIT 1',
            [':user_id' => (int) $payload['user_id'], ':log_date' => (string) $payload['log_date']]
        );
        if ($log !== null) {
            $logId = (int) $log['id'];
            sync_log_workouts($pdo, $logId, is_array($payload['workouts'] ?? null) ? (array) $payload['workouts'] : []);
            sync_log_habits($pdo, $logId, is_array($payload['habits'] ?? null) ? (array) $payload['habits'] : []);
        }
        sync_log_approval_requests($pdo, (int) $payload['user_id'], (string) $payload['log_date'], $actorUserId, $payload);
        $pdo->commit();

        // Tell friends when a user logs their own workout (privacy-aware, once/day).
        if (function_exists('social_broadcast_activity')
            && (int) $payload['user_id'] === $actorUserId
            && (int) ($payload['workout_done'] ?? 0) === 1
        ) {
            social_broadcast_activity($pdo, $actorUserId, 'training');
        }
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function sync_log_approval_requests(PDO $pdo, int $userId, string $date, int $actorUserId, array $payload): void
{
    $log = fetch_log($pdo, $userId, $date);
    if ($log === null) {
        return;
    }

    $stepReason = trim((string) ($payload['step_exception_reason'] ?? ''));
    $distanceReason = trim((string) ($payload['distance_exception_reason'] ?? ''));
    $workoutReason = trim((string) ($payload['workout_exception_reason'] ?? ''));
    $extraWorkout = (int) ($payload['extra_workout'] ?? 0) === 1;
    $junkFood = (int) ($payload['junk_food'] ?? 0) === 1;
    $forceResend = (int) ($payload['resend_requests'] ?? 0) === 1;

    $specs = [
        APPROVAL_TYPE_STEP_EXCEPTION => [
            'enabled' => $stepReason !== '',
            'detail' => $stepReason,
        ],
        APPROVAL_TYPE_DISTANCE_EXCEPTION => [
            'enabled' => $distanceReason !== '',
            'detail' => $distanceReason,
        ],
        APPROVAL_TYPE_WORKOUT_EXCEPTION => [
            'enabled' => $workoutReason !== '',
            'detail' => $workoutReason,
        ],
        APPROVAL_TYPE_EXTRA_WORKOUT_OVERRIDE => [
            'enabled' => $extraWorkout && $junkFood,
            'detail' => t('approval.extra_detail'),
        ],
    ];

    foreach ($specs as $type => $spec) {
        sync_single_approval_request(
            $pdo,
            (int) $log['id'],
            $userId,
            $actorUserId,
            $type,
            (bool) $spec['enabled'],
            (string) $spec['detail'],
            $forceResend
        );
    }
}

function sync_single_approval_request(
    PDO $pdo,
    int $logId,
    int $userId,
    int $actorUserId,
    string $type,
    bool $enabled,
    string $detail,
    bool $forceResend = false
): void {
    $existing = db_fetch_one(
        $pdo,
        'SELECT * FROM approval_requests WHERE log_id = :log_id AND approval_type = :approval_type',
        [':log_id' => $logId, ':approval_type' => $type]
    );

    if (!$enabled) {
        db_execute(
            $pdo,
            'DELETE FROM approval_requests WHERE log_id = :log_id AND approval_type = :approval_type',
            [':log_id' => $logId, ':approval_type' => $type]
        );

        return;
    }

    $now = now_iso();

    if ($existing === null) {
        db_execute(
            $pdo,
            'INSERT INTO approval_requests (
                log_id, user_id, approval_type, status,
                request_state, resent_count, detail, requested_by, approved_by, decision_note,
                created_at, updated_at
            ) VALUES (
                :log_id, :user_id, :approval_type, :status,
                :request_state, :resent_count, :detail, :requested_by, NULL, NULL,
                :created_at, :updated_at
            )',
            [
                ':log_id' => $logId,
                ':user_id' => $userId,
                ':approval_type' => $type,
                ':status' => APPROVAL_STATUS_PENDING,
                ':request_state' => 'sent',
                ':resent_count' => 0,
                ':detail' => $detail,
                ':requested_by' => $actorUserId,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]
        );

        return;
    }

    $mustReset = $forceResend
        || $existing['status'] !== APPROVAL_STATUS_PENDING
        || (string) $existing['detail'] !== $detail;

    if ($mustReset) {
        $resentCount = max(0, (int) ($existing['resent_count'] ?? 0)) + 1;
        db_execute(
            $pdo,
            'UPDATE approval_requests
             SET status = :status,
                 request_state = :request_state,
                 resent_count = :resent_count,
                 detail = :detail,
                 requested_by = :requested_by,
                 approved_by = NULL,
                 decision_note = NULL,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                ':status' => APPROVAL_STATUS_PENDING,
                ':request_state' => 'resent',
                ':resent_count' => $resentCount,
                ':detail' => $detail,
                ':requested_by' => $actorUserId,
                ':updated_at' => $now,
                ':id' => (int) $existing['id'],
            ]
        );

        return;
    }

    db_execute(
        $pdo,
        'UPDATE approval_requests SET detail = :detail, updated_at = :updated_at WHERE id = :id',
        [
            ':detail' => $detail,
            ':updated_at' => $now,
            ':id' => (int) $existing['id'],
        ]
    );
}

function approval_type_label(string $type): string
{
    return match ($type) {
        APPROVAL_TYPE_STEP_EXCEPTION => t('approval.step_exception'),
        APPROVAL_TYPE_DISTANCE_EXCEPTION => t('approval.distance_exception'),
        APPROVAL_TYPE_WORKOUT_EXCEPTION => t('approval.workout_exception'),
        APPROVAL_TYPE_EXTRA_WORKOUT_OVERRIDE => t('approval.extra_workout_override'),
        default => $type,
    };
}

function approval_status_label(string $status): string
{
    return match ($status) {
        APPROVAL_STATUS_PENDING => t('common.pending'),
        APPROVAL_STATUS_APPROVED => t('common.approved'),
        APPROVAL_STATUS_REJECTED => t('common.rejected'),
        default => $status,
    };
}

function can_user_resolve_approval(array $actor, array $approval): bool
{
    if ((int) $actor['id'] === (int) $approval['requested_by']) {
        return false;
    }

    if ((int) ($actor['active'] ?? 0) !== 1) {
        return false;
    }

    if (is_admin($actor)) {
        return true;
    }

    return true;
}

function resolve_approval_request(PDO $pdo, array $actor, int $approvalId, string $decision, string $decisionNote): array
{
    $approval = db_fetch_one(
        $pdo,
        'SELECT * FROM approval_requests WHERE id = :id',
        [':id' => $approvalId]
    );

    if ($approval === null) {
        return ['ok' => false, 'message' => t('approval.not_found')];
    }

    if ($approval['status'] !== APPROVAL_STATUS_PENDING) {
        return ['ok' => false, 'message' => t('approval.already_resolved')];
    }

    if (!can_user_resolve_approval($actor, $approval)) {
        return ['ok' => false, 'message' => t('approval.no_permission')];
    }

    $status = match ($decision) {
        'approve' => APPROVAL_STATUS_APPROVED,
        'reject' => APPROVAL_STATUS_REJECTED,
        default => null,
    };

    if ($status === null) {
        return ['ok' => false, 'message' => t('approval.invalid_decision')];
    }

    db_execute(
        $pdo,
        'UPDATE approval_requests
         SET status = :status,
             request_state = CASE
                WHEN :status = "approved" THEN "approved"
                WHEN :status = "rejected" THEN "rejected"
                ELSE request_state
             END,
             approved_by = :approved_by,
             decision_note = :decision_note,
             updated_at = :updated_at
         WHERE id = :id',
        [
            ':status' => $status,
            ':approved_by' => (int) $actor['id'],
            ':decision_note' => $decisionNote,
            ':updated_at' => now_iso(),
            ':id' => $approvalId,
        ]
    );

    return ['ok' => true, 'message' => t('approval.updated', ['status' => approval_status_label($status)])];
}

function fetch_pending_approvals(PDO $pdo, array $viewer, ?int $forUserId = null, int $limit = 40): array
{
    $limit = max(1, min(200, $limit));
    $params = [
        ':status' => APPROVAL_STATUS_PENDING,
        ':viewer_id' => (int) $viewer['id'],
    ];

    $conditions = ['ar.status = :status', 'ar.requested_by != :viewer_id'];

    if ($forUserId !== null && $forUserId > 0) {
        $conditions[] = 'ar.user_id = :for_user_id';
        $params[':for_user_id'] = $forUserId;
    }

    $sql =
        'SELECT ar.*, dl.log_date, dl.steps, dl.workout_done, dl.junk_food, dl.extra_workout,
                u.display_name AS owner_name,
                req.display_name AS requested_by_name,
                appr.display_name AS approved_by_name
         FROM approval_requests ar
         JOIN daily_logs dl ON dl.id = ar.log_id
         JOIN users u ON u.id = ar.user_id
         JOIN users req ON req.id = ar.requested_by
         LEFT JOIN users appr ON appr.id = ar.approved_by
         WHERE ' . implode(' AND ', $conditions) .
        ' ORDER BY dl.log_date DESC, ar.created_at DESC
          LIMIT ' . $limit;

    $rows = db_fetch_all($pdo, $sql, $params);

    foreach ($rows as &$row) {
        $row['approval_type_label'] = approval_type_label((string) $row['approval_type']);
        $row['status_label'] = approval_status_label((string) $row['status']);
        if ((string) $row['approval_type'] === APPROVAL_TYPE_EXTRA_WORKOUT_OVERRIDE) {
            $row['detail'] = t('approval.extra_detail');
        }
    }
    unset($row);

    return $rows;
}

function load_approval_status_by_user_date(PDO $pdo, string $startDate, string $endDate): array
{
    $rows = db_fetch_all(
        $pdo,
        'SELECT ar.user_id, ar.approval_type, ar.status, dl.log_date
         FROM approval_requests ar
         JOIN daily_logs dl ON dl.id = ar.log_id
         WHERE dl.log_date BETWEEN :start AND :end',
        [':start' => $startDate, ':end' => $endDate]
    );

    $result = [];
    foreach ($rows as $row) {
        $userId = (int) $row['user_id'];
        $date = (string) $row['log_date'];
        $type = (string) $row['approval_type'];
        $status = (string) $row['status'];

        if (!isset($result[$userId])) {
            $result[$userId] = [];
        }
        if (!isset($result[$userId][$date])) {
            $result[$userId][$date] = [];
        }

        $result[$userId][$date][$type] = $status;
    }

    return $result;
}

function fetch_approval_requests_by_user_between(PDO $pdo, int $userId, string $startDate, string $endDate): array
{
    if ($userId <= 0) {
        return [];
    }

    $rows = db_fetch_all(
        $pdo,
        'SELECT ar.*, dl.log_date
         FROM approval_requests ar
         JOIN daily_logs dl ON dl.id = ar.log_id
         WHERE ar.user_id = :user_id
           AND dl.log_date BETWEEN :start AND :end
         ORDER BY dl.log_date ASC, ar.created_at ASC',
        [
            ':user_id' => $userId,
            ':start' => $startDate,
            ':end' => $endDate,
        ]
    );

    $result = [];
    foreach ($rows as $row) {
        $date = (string) ($row['log_date'] ?? '');
        $type = (string) ($row['approval_type'] ?? '');
        if ($date === '' || $type === '') {
            continue;
        }
        if (!isset($result[$date])) {
            $result[$date] = [];
        }
        $result[$date][$type] = $row;
    }

    return $result;
}

function fetch_log_workouts_for_logs(PDO $pdo, array $logs): array
{
    if ($logs === []) {
        return [];
    }

    $logIds = [];
    $legacyTypeIds = [];
    foreach ($logs as $log) {
        $logId = (int) ($log['id'] ?? 0);
        if ($logId <= 0) {
            continue;
        }
        $logIds[$logId] = true;
        $legacyTypeId = !empty($log['workout_type_id']) ? (int) $log['workout_type_id'] : 0;
        if ($legacyTypeId > 0) {
            $legacyTypeIds[$legacyTypeId] = true;
        }
    }

    if ($logIds === []) {
        return [];
    }

    $params = [];
    $logPlaceholders = [];
    foreach (array_keys($logIds) as $index => $logId) {
        $key = ':log_id_' . $index;
        $logPlaceholders[] = $key;
        $params[$key] = $logId;
    }

    $rows = db_fetch_all(
        $pdo,
        'SELECT dlw.id,
                dlw.log_id,
                dlw.workout_type_id,
                dlw.workout_type,
                dlw.sort_order,
                wt.name AS workout_type_name
         FROM daily_log_workouts dlw
         LEFT JOIN workout_types wt ON wt.id = dlw.workout_type_id
         WHERE dlw.log_id IN (' . implode(',', $logPlaceholders) . ')
         ORDER BY dlw.log_id ASC, dlw.sort_order ASC, dlw.id ASC',
        $params
    );

    $workoutIds = [];
    foreach ($rows as $row) {
        $workoutId = (int) ($row['id'] ?? 0);
        if ($workoutId > 0) {
            $workoutIds[$workoutId] = true;
        }
    }
    $fieldValuesByWorkout = [];
    if ($workoutIds !== []) {
        $fieldParams = [];
        $fieldPlaceholders = [];
        foreach (array_keys($workoutIds) as $index => $workoutId) {
            $key = ':workout_id_' . $index;
            $fieldPlaceholders[] = $key;
            $fieldParams[$key] = $workoutId;
        }
        $fieldRows = db_fetch_all(
            $pdo,
            'SELECT workout_id, field_id, field_label, data_key, value_text, value_number
             FROM daily_log_workout_field_values
             WHERE workout_id IN (' . implode(',', $fieldPlaceholders) . ')
             ORDER BY id ASC',
            $fieldParams
        );
        foreach ($fieldRows as $fieldRow) {
            $workoutId = (int) ($fieldRow['workout_id'] ?? 0);
            if ($workoutId <= 0) {
                continue;
            }
            if (!isset($fieldValuesByWorkout[$workoutId])) {
                $fieldValuesByWorkout[$workoutId] = [];
            }
            $fieldValuesByWorkout[$workoutId][] = [
                'field_id' => !empty($fieldRow['field_id']) ? (int) $fieldRow['field_id'] : null,
                'field_label' => (string) ($fieldRow['field_label'] ?? ''),
                'data_key' => normalize_workout_field_data_key($fieldRow['data_key'] ?? ''),
                'value_text' => (string) ($fieldRow['value_text'] ?? ''),
                'value_number' => $fieldRow['value_number'] !== null ? (float) $fieldRow['value_number'] : null,
            ];
        }
    }

    $legacyTypeNames = [];
    if ($legacyTypeIds !== []) {
        $legacyParams = [];
        $legacyPlaceholders = [];
        foreach (array_keys($legacyTypeIds) as $index => $typeId) {
            $key = ':type_id_' . $index;
            $legacyPlaceholders[] = $key;
            $legacyParams[$key] = $typeId;
        }
        $legacyRows = db_fetch_all(
            $pdo,
            'SELECT id, name FROM workout_types WHERE id IN (' . implode(',', $legacyPlaceholders) . ')',
            $legacyParams
        );
        foreach ($legacyRows as $legacyRow) {
            $legacyTypeNames[(int) $legacyRow['id']] = trim((string) ($legacyRow['name'] ?? ''));
        }
    }

    $workoutsByLogId = [];
    foreach ($rows as $row) {
        $logId = (int) ($row['log_id'] ?? 0);
        if ($logId <= 0) {
            continue;
        }
        $workoutTypeId = !empty($row['workout_type_id']) ? (int) $row['workout_type_id'] : null;
        $workoutType = trim((string) ($row['workout_type'] ?? ''));
        if ($workoutType === '') {
            $workoutType = trim((string) ($row['workout_type_name'] ?? ''));
        }
        if ($workoutType === '') {
            $workoutType = 'Workout';
        }
        if (!isset($workoutsByLogId[$logId])) {
            $workoutsByLogId[$logId] = [];
        }
        $workoutId = (int) ($row['id'] ?? 0);
        $workoutsByLogId[$logId][] = [
            'id' => $workoutId,
            'workout_type_id' => $workoutTypeId,
            'workout_type' => $workoutType,
            'fields' => array_values((array) ($fieldValuesByWorkout[$workoutId] ?? [])),
        ];
    }

    foreach ($logs as $log) {
        $logId = (int) ($log['id'] ?? 0);
        if ($logId <= 0 || isset($workoutsByLogId[$logId])) {
            continue;
        }
        if ((int) ($log['workout_done'] ?? 0) !== 1) {
            continue;
        }

        $legacyTypeId = !empty($log['workout_type_id']) ? (int) $log['workout_type_id'] : null;
        $legacyType = trim((string) ($log['workout_type'] ?? ''));
        if ($legacyType === '' && $legacyTypeId !== null) {
            $legacyType = $legacyTypeNames[$legacyTypeId] ?? '';
        }
        if ($legacyType === '') {
            $legacyType = 'Workout';
        }
        $workoutsByLogId[$logId] = [[
            'workout_type_id' => $legacyTypeId,
            'workout_type' => $legacyType,
            'fields' => [],
        ]];
    }

    return $workoutsByLogId;
}

function attach_workouts_to_logs(PDO $pdo, array $logs): array
{
    if ($logs === []) {
        return [];
    }

    $workoutsByLogId = fetch_log_workouts_for_logs($pdo, $logs);
    foreach ($logs as &$log) {
        $logId = (int) ($log['id'] ?? 0);
        $log['workouts'] = array_values((array) ($workoutsByLogId[$logId] ?? []));
    }
    unset($log);

    return $logs;
}

function fetch_logs_for_user_between(PDO $pdo, int $userId, string $startDate, string $endDate): array
{
    $logs = db_fetch_all(
        $pdo,
        'SELECT * FROM daily_logs WHERE user_id = :user_id AND log_date BETWEEN :start AND :end ORDER BY log_date ASC',
        [':user_id' => $userId, ':start' => $startDate, ':end' => $endDate]
    );
    $logs = attach_workouts_to_logs($pdo, $logs);
    foreach ($logs as &$log) {
        $log['habits'] = fetch_log_habit_values($pdo, (int) $log['id']);
    }
    unset($log);

    return $logs;
}

function fetch_distance_totals_by_date_for_user_between(PDO $pdo, int $userId, string $startDate, string $endDate): array
{
    if ($userId <= 0) {
        return [];
    }

    $rows = db_fetch_all(
        $pdo,
        'SELECT log_date, SUM(COALESCE(distance_km, 0)) AS total_distance
         FROM daily_logs
         WHERE user_id = :user_id
           AND log_date BETWEEN :start AND :end
         GROUP BY log_date
         ORDER BY log_date ASC',
        [
            ':user_id' => $userId,
            ':start' => $startDate,
            ':end' => $endDate,
        ]
    );

    $distanceByDate = [];
    foreach ($rows as $row) {
        $dateKey = trim((string) ($row['log_date'] ?? ''));
        if ($dateKey === '') {
            continue;
        }
        $distanceByDate[$dateKey] = round(max(0.0, (float) ($row['total_distance'] ?? 0.0)), 2);
    }

    return $distanceByDate;
}

function fetch_log(PDO $pdo, int $userId, string $date): ?array
{
    $log = db_fetch_one(
        $pdo,
        'SELECT * FROM daily_logs WHERE user_id = :user_id AND log_date = :log_date',
        [':user_id' => $userId, ':log_date' => $date]
    );
    if ($log !== null) {
        $logsWithWorkouts = attach_workouts_to_logs($pdo, [$log]);
        $log = $logsWithWorkouts[0] ?? $log;
        $log['habits'] = fetch_log_habit_values($pdo, (int) $log['id']);
    }

    return $log;
}

function list_habit_definitions(PDO $pdo, bool $activeOnly = true): array
{
    $where = $activeOnly ? 'WHERE active = 1' : '';

    return db_fetch_all($pdo, 'SELECT * FROM habit_definitions ' . $where . ' ORDER BY active DESC, sort_order ASC, label ASC');
}

function fetch_log_habit_values(PDO $pdo, int $logId): array
{
    $rows = db_fetch_all(
        $pdo,
        'SELECT hd.code, hd.id, hd.label, COALESCE(dlh.value, 0) AS value
         FROM habit_definitions hd
         LEFT JOIN daily_log_habits dlh ON dlh.habit_id = hd.id AND dlh.log_id = :log_id
         ORDER BY hd.sort_order ASC, hd.label ASC',
        [':log_id' => $logId]
    );

    $values = [];
    foreach ($rows as $row) {
        $values[(string) $row['code']] = [
            'id' => (int) $row['id'],
            'label' => (string) $row['label'],
            'value' => (int) $row['value'],
        ];
    }

    return $values;
}

function sync_log_habits(PDO $pdo, int $logId, array $values): void
{
    $definitions = list_habit_definitions($pdo, false);
    $now = now_iso();
    foreach ($definitions as $definition) {
        $code = (string) $definition['code'];
        $habitId = (int) $definition['id'];
        $value = isset($values[$code]) && (int) $values[$code] === 1 ? 1 : 0;
        $existing = db_fetch_one(
            $pdo,
            'SELECT id FROM daily_log_habits WHERE log_id = :log_id AND habit_id = :habit_id',
            [':log_id' => $logId, ':habit_id' => $habitId]
        );

        if ($existing === null) {
            db_execute(
                $pdo,
                'INSERT INTO daily_log_habits (log_id, habit_id, value, created_at, updated_at)
                 VALUES (:log_id, :habit_id, :value, :created_at, :updated_at)',
                [':log_id' => $logId, ':habit_id' => $habitId, ':value' => $value, ':created_at' => $now, ':updated_at' => $now]
            );
        } else {
            db_execute(
                $pdo,
                'UPDATE daily_log_habits SET value = :value, updated_at = :updated_at WHERE id = :id',
                [':value' => $value, ':updated_at' => $now, ':id' => (int) $existing['id']]
            );
        }
    }
}

function save_habit_definition(PDO $pdo, ?int $habitId, string $code, string $label, bool $active, int $sortOrder, int $actorUserId): void
{
    $code = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim($code))) ?: '';
    $label = trim($label);
    if ($code === '' || $label === '') {
        return;
    }

    $before = $habitId !== null ? db_fetch_one($pdo, 'SELECT * FROM habit_definitions WHERE id = :id', [':id' => $habitId]) : null;
    $now = now_iso();
    if ($before === null) {
        db_execute(
            $pdo,
            'INSERT INTO habit_definitions (code, label, active, sort_order, created_by, created_at, updated_at)
             VALUES (:code, :label, :active, :sort_order, :created_by, :created_at, :updated_at)',
            [':code' => $code, ':label' => $label, ':active' => $active ? 1 : 0, ':sort_order' => $sortOrder, ':created_by' => $actorUserId, ':created_at' => $now, ':updated_at' => $now]
        );
        $after = db_fetch_one($pdo, 'SELECT * FROM habit_definitions WHERE code = :code', [':code' => $code]);
        audit_log($pdo, $actorUserId, 'habit_created', 'habit_definition', (string) ($after['id'] ?? ''), 'Habit definition created.', null, audit_snapshot($after));
        return;
    }

    db_execute(
        $pdo,
        'UPDATE habit_definitions SET code = :code, label = :label, active = :active, sort_order = :sort_order, updated_at = :updated_at WHERE id = :id',
        [':code' => $code, ':label' => $label, ':active' => $active ? 1 : 0, ':sort_order' => $sortOrder, ':updated_at' => $now, ':id' => $habitId]
    );
    $after = db_fetch_one($pdo, 'SELECT * FROM habit_definitions WHERE id = :id', [':id' => $habitId]);
    audit_log($pdo, $actorUserId, 'habit_updated', 'habit_definition', (string) $habitId, 'Habit definition updated.', audit_snapshot($before), audit_snapshot($after));
}

function create_custom_habit_from_label(PDO $pdo, string $label, int $actorUserId): ?array
{
    $normalizedLabel = trim(preg_replace('/\s+/', ' ', $label) ?? '');
    if ($normalizedLabel === '') {
        return null;
    }

    $baseCode = preg_replace('/[^a-z0-9_]+/', '_', strtolower($normalizedLabel)) ?: '';
    $baseCode = trim($baseCode, '_');
    if ($baseCode === '') {
        $baseCode = 'custom_habit';
    }

    $code = $baseCode;
    $suffix = 2;
    while (db_fetch_one($pdo, 'SELECT id FROM habit_definitions WHERE code = :code', [':code' => $code]) !== null) {
        $code = $baseCode . '_' . $suffix;
        $suffix++;
    }

    $sortRow = db_fetch_one($pdo, 'SELECT MAX(sort_order) AS max_order FROM habit_definitions');
    $nextSort = max(0, (int) ($sortRow['max_order'] ?? 0)) + 10;

    save_habit_definition($pdo, null, $code, $normalizedLabel, true, $nextSort, $actorUserId);

    return db_fetch_one($pdo, 'SELECT * FROM habit_definitions WHERE code = :code', [':code' => $code]);
}

function deactivate_habit_definition(PDO $pdo, int $habitId, int $actorUserId): void
{
    $before = db_fetch_one($pdo, 'SELECT * FROM habit_definitions WHERE id = :id', [':id' => $habitId]);
    if ($before === null) {
        return;
    }

    db_execute(
        $pdo,
        'UPDATE habit_definitions SET active = 0, updated_at = :updated_at WHERE id = :id',
        [':updated_at' => now_iso(), ':id' => $habitId]
    );
    $after = db_fetch_one($pdo, 'SELECT * FROM habit_definitions WHERE id = :id', [':id' => $habitId]);
    audit_log($pdo, $actorUserId, 'habit_deactivated', 'habit_definition', (string) $habitId, 'Habit deactivated.', audit_snapshot($before), audit_snapshot($after));
}

function fetch_recent_photos(PDO $pdo, int $limit = 24, ?int $userId = null): array
{
    $limit = max(1, min(200, $limit));
    $params = [];
    $where = '';
    if ($userId !== null) {
        $where = 'WHERE p.user_id = :user_id';
        $params[':user_id'] = $userId;
    }

    return db_fetch_all(
        $pdo,
        'SELECT p.*, u.display_name FROM photo_entries p
         JOIN users u ON u.id = p.user_id
         ' . $where . '
         ORDER BY p.created_at DESC, p.id DESC
         LIMIT ' . $limit,
        $params
    );
}

function fetch_gallery_photos(PDO $pdo, int $limit = 60, int $offset = 0, ?int $userId = null, ?int $viewerId = null, bool $viewerIsAdmin = false): array
{
    $limit = max(1, min(5000, $limit));
    $offset = max(0, $offset);
    $params = [];
    $conditions = [];
    if ($userId !== null) {
        $conditions[] = 'p.user_id = :user_id';
        $params[':user_id'] = $userId;
    }
    // Hide posts from users whose visibility excludes the viewer (social privacy).
    if ($viewerId !== null && function_exists('privacy_visible_owner_sql')) {
        $conditions[] = privacy_visible_owner_sql('u', $viewerId, $viewerIsAdmin, $params);
    }
    $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';

    return db_fetch_all(
        $pdo,
        'SELECT p.*, u.display_name FROM photo_entries p
         JOIN users u ON u.id = p.user_id
         ' . $where . '
         ORDER BY p.log_date DESC, p.created_at DESC, p.id DESC
         LIMIT ' . $limit . ' OFFSET ' . $offset,
        $params
    );
}

function fetch_latest_meal_photo(PDO $pdo, ?int $userId = null): ?array
{
    $params = [];
    $where = 'WHERE p.category IN ("breakfast", "lunch", "dinner", "other", "meal", "workout")';
    if ($userId !== null) {
        $where .= ' AND p.user_id = :user_id';
        $params[':user_id'] = $userId;
    }

    return db_fetch_one(
        $pdo,
        'SELECT p.*, u.display_name
         FROM photo_entries p
         JOIN users u ON u.id = p.user_id
         ' . $where . '
         ORDER BY p.log_date DESC, p.created_at DESC
         LIMIT 1',
        $params
    );
}

function fetch_meal_calendar(PDO $pdo, string $startDate, ?int $userId = null, string $view = 'week'): array
{
    $start = new DateTimeImmutable($startDate);
    if ($view === 'month') {
        $start = $start->modify('first day of this month');
        $endDate = $start->modify('last day of this month')->format('Y-m-d');
    } elseif ($view === 'day') {
        $endDate = $start->format('Y-m-d');
    } else {
        $view = 'week';
        $endDate = $start->modify('+6 days')->format('Y-m-d');
    }
    $params = [':start' => $start->format('Y-m-d'), ':end' => $endDate];
    $where = 'p.log_date BETWEEN :start AND :end AND p.category IN ("breakfast", "lunch", "dinner", "other", "meal", "workout")';
    if ($userId !== null) {
        $where .= ' AND p.user_id = :user_id';
        $params[':user_id'] = $userId;
    }

    $rows = db_fetch_all(
        $pdo,
        'SELECT p.*, u.display_name
         FROM photo_entries p
         JOIN users u ON u.id = p.user_id
         WHERE ' . $where . '
         ORDER BY p.log_date ASC, p.created_at ASC',
        $params
    );

    $calendar = [];
    foreach (day_sequence($start, new DateTimeImmutable($endDate)) as $day) {
        $calendar[$day->format('Y-m-d')] = ['preview' => null, 'count' => 0, 'photos' => []];
    }
    foreach ($rows as $row) {
        $date = (string) $row['log_date'];
        if (!isset($calendar[$date])) {
            continue;
        }
        if ($calendar[$date]['preview'] === null) {
            $calendar[$date]['preview'] = $row;
        }
        $calendar[$date]['count']++;
        $calendar[$date]['photos'][] = $row;
    }

    return $calendar;
}

function normalize_nullable_float_input(mixed $value, ?float $min = null): ?float
{
    if ($value === null) {
        return null;
    }

    if (is_string($value)) {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
    }

    if (!is_numeric($value)) {
        return null;
    }

    $normalized = (float) $value;
    if ($min !== null && $normalized < $min) {
        $normalized = $min;
    }

    return $normalized;
}

function normalize_photo_entry_category(string $category): string
{
    $normalizedCategory = strtolower(trim($category));
    if ($normalizedCategory === 'meal') {
        $normalizedCategory = 'lunch';
    }
    if ($normalizedCategory === 'workout') {
        $normalizedCategory = 'other';
    }
    $validCategories = ['breakfast', 'lunch', 'dinner', 'other'];
    if (!in_array($normalizedCategory, $validCategories, true)) {
        return 'other';
    }

    return $normalizedCategory;
}

function remove_media_file_if_unreferenced(PDO $pdo, array $config, string $filePath, string $context): void
{
    $path = trim($filePath);
    if ($path === '') {
        return;
    }

    $remaining = db_fetch_one(
        $pdo,
        'SELECT COUNT(*) AS total FROM photo_entries WHERE file_path = :file_path',
        [':file_path' => $path]
    );
    $remainingCount = (int) ($remaining['total'] ?? 0);
    if ($remainingCount > 0) {
        return;
    }

    $resolvedPath = resolve_media_storage_path($config, $path);
    if ($resolvedPath !== null && is_file($resolvedPath)) {
        $deleted = @unlink($resolvedPath);
        media_debug_log($context, [
            'stored_value' => $path,
            'helper_input' => $path,
            'normalized_value' => (string) (normalize_media_reference($path)['normalized'] ?? ''),
            'final_url' => $resolvedPath,
            'reason' => $deleted ? 'file_deleted' : 'unlink_failed',
        ]);
        return;
    }

    media_debug_log($context, [
        'stored_value' => $path,
        'helper_input' => $path,
        'normalized_value' => (string) (normalize_media_reference($path)['normalized'] ?? ''),
        'final_url' => '',
        'reason' => 'resolved_path_missing',
    ]);
}

function save_photo_entry(
    PDO $pdo,
    array $config,
    int $userId,
    string $date,
    string $category,
    string $caption,
    array $file,
    array $nutrition = []
): array
{
    $normalizedCategory = normalize_photo_entry_category($category);

    $storedPath = save_uploaded_image(
        $config,
        $file,
        (new DateTimeImmutable($date))->format('Y/m'),
        (string) $userId,
        image_upload_policy($config, 'photo_entry')
    );
    warm_media_thumbnails($config, $storedPath);
    $now = now_iso();

    db_execute(
        $pdo,
        'INSERT INTO photo_entries (
            user_id, log_date, category, caption, file_path,
            calories, protein_g, carbs_g, fat_g, fiber_g, sugar_g, sodium_mg, created_at, updated_at
        ) VALUES (
            :user_id, :log_date, :category, :caption, :file_path,
            :calories, :protein_g, :carbs_g, :fat_g, :fiber_g, :sugar_g, :sodium_mg, :created_at, :updated_at
        )',
        [
            ':user_id' => $userId,
            ':log_date' => $date,
            ':category' => $normalizedCategory,
            ':caption' => $caption,
            ':file_path' => $storedPath,
            ':calories' => normalize_nullable_float_input($nutrition['calories'] ?? null, 0),
            ':protein_g' => normalize_nullable_float_input($nutrition['protein_g'] ?? null, 0),
            ':carbs_g' => normalize_nullable_float_input($nutrition['carbs_g'] ?? null, 0),
            ':fat_g' => normalize_nullable_float_input($nutrition['fat_g'] ?? null, 0),
            ':fiber_g' => normalize_nullable_float_input($nutrition['fiber_g'] ?? null, 0),
            ':sugar_g' => normalize_nullable_float_input($nutrition['sugar_g'] ?? null, 0),
            ':sodium_mg' => normalize_nullable_float_input($nutrition['sodium_mg'] ?? null, 0),
            ':created_at' => $now,
            ':updated_at' => $now,
        ]
    );

    $created = db_fetch_one($pdo, 'SELECT * FROM photo_entries WHERE id = last_insert_rowid()');
    if ($created === null) {
        throw new RuntimeException(t('flash.save_failed'));
    }

    return $created;
}

function update_photo_entry(
    PDO $pdo,
    array $config,
    int $photoId,
    string $date,
    string $category,
    string $caption,
    array $nutrition = [],
    ?array $file = null
): ?array {
    if ($photoId <= 0) {
        return null;
    }

    $existing = db_fetch_one($pdo, 'SELECT * FROM photo_entries WHERE id = :id', [':id' => $photoId]);
    if ($existing === null) {
        return null;
    }

    $normalizedCategory = normalize_photo_entry_category($category);
    $newFilePath = (string) ($existing['file_path'] ?? '');
    $oldFilePath = trim((string) ($existing['file_path'] ?? ''));
    $hasNewUpload = is_array($file) && isset($file['error']) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if ($hasNewUpload) {
        $newFilePath = save_uploaded_image(
            $config,
            $file,
            (new DateTimeImmutable($date))->format('Y/m'),
            (string) ((int) ($existing['user_id'] ?? 0)),
            image_upload_policy($config, 'photo_entry')
        );
    }

    db_execute(
        $pdo,
        'UPDATE photo_entries
         SET log_date = :log_date,
             category = :category,
             caption = :caption,
             file_path = :file_path,
             calories = :calories,
             protein_g = :protein_g,
             carbs_g = :carbs_g,
             fat_g = :fat_g,
             fiber_g = :fiber_g,
             sugar_g = :sugar_g,
             sodium_mg = :sodium_mg,
             updated_at = :updated_at
         WHERE id = :id',
        [
            ':log_date' => $date,
            ':category' => $normalizedCategory,
            ':caption' => $caption,
            ':file_path' => $newFilePath,
            ':calories' => normalize_nullable_float_input($nutrition['calories'] ?? null, 0),
            ':protein_g' => normalize_nullable_float_input($nutrition['protein_g'] ?? null, 0),
            ':carbs_g' => normalize_nullable_float_input($nutrition['carbs_g'] ?? null, 0),
            ':fat_g' => normalize_nullable_float_input($nutrition['fat_g'] ?? null, 0),
            ':fiber_g' => normalize_nullable_float_input($nutrition['fiber_g'] ?? null, 0),
            ':sugar_g' => normalize_nullable_float_input($nutrition['sugar_g'] ?? null, 0),
            ':sodium_mg' => normalize_nullable_float_input($nutrition['sodium_mg'] ?? null, 0),
            ':updated_at' => now_iso(),
            ':id' => $photoId,
        ]
    );

    if ($hasNewUpload && $oldFilePath !== '' && $oldFilePath !== $newFilePath) {
        remove_media_file_if_unreferenced($pdo, $config, $oldFilePath, 'update_photo_entry');
    }

    return db_fetch_one($pdo, 'SELECT * FROM photo_entries WHERE id = :id', [':id' => $photoId]);
}

function delete_photo_entry(PDO $pdo, array $config, int $photoId): ?array
{
    if ($photoId <= 0) {
        return null;
    }

    $photo = db_fetch_one($pdo, 'SELECT * FROM photo_entries WHERE id = :id', [':id' => $photoId]);
    if ($photo === null) {
        return null;
    }

    $filePath = trim((string) ($photo['file_path'] ?? ''));
    db_execute($pdo, 'DELETE FROM photo_entries WHERE id = :id', [':id' => $photoId]);

    remove_media_file_if_unreferenced($pdo, $config, $filePath, 'delete_photo_entry');

    return $photo;
}

function fetch_photo_by_id(PDO $pdo, int $photoId): ?array
{
    if ($photoId <= 0) {
        return null;
    }

    return db_fetch_one(
        $pdo,
        'SELECT p.*, u.display_name, u.username, u.avatar_path, u.updated_at AS user_updated_at
         FROM photo_entries p
         JOIN users u ON u.id = p.user_id
         WHERE p.id = :id
         LIMIT 1',
        [':id' => $photoId]
    );
}

function fetch_photo_comments(PDO $pdo, int $photoId, int $limit = 250): array
{
    if ($photoId <= 0) {
        return [];
    }

    $safeLimit = max(1, min(500, $limit));

    return db_fetch_all(
        $pdo,
        'SELECT c.*, u.display_name, u.username, u.avatar_path, u.updated_at AS user_updated_at
         FROM photo_comments c
         JOIN users u ON u.id = c.user_id
         WHERE c.photo_id = :photo_id
         ORDER BY c.created_at ASC
         LIMIT ' . $safeLimit,
        [':photo_id' => $photoId]
    );
}

function create_photo_comment(PDO $pdo, int $photoId, int $userId, string $comment): array
{
    if ($photoId <= 0) {
        throw new InvalidArgumentException(t('flash.not_found'));
    }

    $photo = db_fetch_one($pdo, 'SELECT id FROM photo_entries WHERE id = :id', [':id' => $photoId]);
    if ($photo === null) {
        throw new InvalidArgumentException(t('flash.not_found'));
    }

    $text = trim($comment);
    if ($text === '') {
        throw new InvalidArgumentException(t('photo.comment_required'));
    }

    $maxLength = 1200;
    if (function_exists('mb_strlen')) {
        if (mb_strlen($text) > $maxLength) {
            throw new InvalidArgumentException(t('photo.comment_too_long'));
        }
    } elseif (strlen($text) > $maxLength) {
        throw new InvalidArgumentException(t('photo.comment_too_long'));
    }

    $now = now_iso();
    db_execute(
        $pdo,
        'INSERT INTO photo_comments (photo_id, user_id, comment, created_at, updated_at)
         VALUES (:photo_id, :user_id, :comment, :created_at, :updated_at)',
        [
            ':photo_id' => $photoId,
            ':user_id' => $userId,
            ':comment' => $text,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]
    );

    $created = db_fetch_one($pdo, 'SELECT * FROM photo_comments WHERE id = last_insert_rowid()');
    if ($created === null) {
        throw new RuntimeException(t('flash.save_failed'));
    }

    return $created;
}

function delete_photo_comment(PDO $pdo, int $commentId): ?array
{
    if ($commentId <= 0) {
        return null;
    }

    $existing = db_fetch_one($pdo, 'SELECT * FROM photo_comments WHERE id = :id', [':id' => $commentId]);
    if ($existing === null) {
        return null;
    }

    db_execute($pdo, 'DELETE FROM photo_comments WHERE id = :id', [':id' => $commentId]);

    return $existing;
}

function fetch_user_calorie_stats(PDO $pdo, int $userId, string $startDate, string $endDate, ?float $maintenanceCalories): array
{
    $start = new DateTimeImmutable($startDate);
    $end = new DateTimeImmutable($endDate);
    if ($end < $start) {
        $end = $start;
    }

    $seriesByDate = [];
    foreach (day_sequence($start, $end) as $day) {
        $key = $day->format('Y-m-d');
        $seriesByDate[$key] = [
            'date' => $key,
            'consumed' => 0.0,
            'burned' => 0.0,
            'tracked' => false,
        ];
    }

    $dailyLogs = db_fetch_all(
        $pdo,
        'SELECT log_date,
                SUM(COALESCE(training_calories_burned, 0)) AS burned
         FROM daily_logs
         WHERE user_id = :user_id
           AND log_date BETWEEN :start_date AND :end_date
         GROUP BY log_date',
        [':user_id' => $userId, ':start_date' => $start->format('Y-m-d'), ':end_date' => $end->format('Y-m-d')]
    );
    foreach ($dailyLogs as $row) {
        $date = (string) ($row['log_date'] ?? '');
        if (!isset($seriesByDate[$date])) {
            continue;
        }
        $seriesByDate[$date]['burned'] = (float) ($row['burned'] ?? 0);
        $seriesByDate[$date]['tracked'] = true;
    }

    $dailyPhotos = db_fetch_all(
        $pdo,
        'SELECT log_date,
                SUM(COALESCE(calories, 0)) AS consumed
         FROM photo_entries
         WHERE user_id = :user_id
           AND log_date BETWEEN :start_date AND :end_date
         GROUP BY log_date',
        [':user_id' => $userId, ':start_date' => $start->format('Y-m-d'), ':end_date' => $end->format('Y-m-d')]
    );
    foreach ($dailyPhotos as $row) {
        $date = (string) ($row['log_date'] ?? '');
        if (!isset($seriesByDate[$date])) {
            continue;
        }
        $seriesByDate[$date]['consumed'] = (float) ($row['consumed'] ?? 0);
        $seriesByDate[$date]['tracked'] = true;
    }

    $trackedDays = 0;
    $totalConsumed = 0.0;
    $totalBurned = 0.0;
    $maintenanceDaily = max(0.0, (float) ($maintenanceCalories ?? 0.0));
    $series = [];
    foreach ($seriesByDate as $date => $row) {
        $consumed = round((float) $row['consumed'], 2);
        $burned = round((float) $row['burned'], 2);
        $isTracked = (bool) ($row['tracked'] ?? false);
        if ($isTracked) {
            $trackedDays++;
        }
        $totalConsumed += $consumed;
        $totalBurned += $burned;
        $series[] = [
            'date' => $date,
            'consumed' => $consumed,
            'burned' => $burned,
            'deficit' => round(($isTracked ? $maintenanceDaily : 0.0) + $burned - $consumed, 2),
        ];
    }

    $maintenanceTotal = round($maintenanceDaily * $trackedDays, 2);
    $totalConsumed = round($totalConsumed, 2);
    $totalBurned = round($totalBurned, 2);
    $deficit = round($maintenanceTotal + $totalBurned - $totalConsumed, 2);

    return [
        'tracked_days' => $trackedDays,
        'maintenance_daily' => $maintenanceDaily,
        'maintenance_total' => $maintenanceTotal,
        'total_consumed' => $totalConsumed,
        'total_burned' => $totalBurned,
        'deficit' => $deficit,
        'series' => $series,
    ];
}

function fetch_user_food_stats(PDO $pdo, int $userId, string $startDate, string $endDate): array
{
    $emptyTotals = [
        'calories' => 0.0,
        'protein_g' => 0.0,
        'carbs_g' => 0.0,
        'fat_g' => 0.0,
        'fiber_g' => 0.0,
        'sugar_g' => 0.0,
        'sodium_mg' => 0.0,
    ];
    $empty = [
        'photo_count' => 0,
        'meal_days' => 0,
        'junk_days' => 0,
        'logged_days' => 0,
        'training_calories_burned' => 0.0,
        'totals' => $emptyTotals,
        'categories' => [],
    ];

    if ($userId <= 0) {
        return $empty;
    }

    $start = new DateTimeImmutable($startDate);
    $end = new DateTimeImmutable($endDate);
    if ($end < $start) {
        $end = $start;
    }
    $startKey = $start->format('Y-m-d');
    $endKey = $end->format('Y-m-d');

    $photoTotals = db_fetch_one(
        $pdo,
        'SELECT COUNT(*) AS photo_count,
                COUNT(DISTINCT log_date) AS meal_days,
                SUM(COALESCE(calories, 0)) AS calories,
                SUM(COALESCE(protein_g, 0)) AS protein_g,
                SUM(COALESCE(carbs_g, 0)) AS carbs_g,
                SUM(COALESCE(fat_g, 0)) AS fat_g,
                SUM(COALESCE(fiber_g, 0)) AS fiber_g,
                SUM(COALESCE(sugar_g, 0)) AS sugar_g,
                SUM(COALESCE(sodium_mg, 0)) AS sodium_mg
         FROM photo_entries
         WHERE user_id = :user_id
           AND log_date BETWEEN :start_date AND :end_date',
        [':user_id' => $userId, ':start_date' => $startKey, ':end_date' => $endKey]
    ) ?? [];

    $logTotals = db_fetch_one(
        $pdo,
        'SELECT COUNT(*) AS logged_days,
                SUM(CASE WHEN COALESCE(junk_food, 0) = 1 THEN 1 ELSE 0 END) AS junk_days,
                SUM(COALESCE(training_calories_burned, 0)) AS training_calories_burned
         FROM daily_logs
         WHERE user_id = :user_id
           AND log_date BETWEEN :start_date AND :end_date',
        [':user_id' => $userId, ':start_date' => $startKey, ':end_date' => $endKey]
    ) ?? [];

    $categoryRows = db_fetch_all(
        $pdo,
        'SELECT category,
                COUNT(*) AS photo_count,
                SUM(COALESCE(calories, 0)) AS calories
         FROM photo_entries
         WHERE user_id = :user_id
           AND log_date BETWEEN :start_date AND :end_date
         GROUP BY category
         ORDER BY photo_count DESC, category ASC',
        [':user_id' => $userId, ':start_date' => $startKey, ':end_date' => $endKey]
    );

    $categories = [];
    foreach ($categoryRows as $row) {
        $category = trim((string) ($row['category'] ?? ''));
        if ($category === '') {
            $category = 'other';
        }
        $categories[] = [
            'category' => $category,
            'photo_count' => (int) ($row['photo_count'] ?? 0),
            'calories' => round((float) ($row['calories'] ?? 0), 2),
        ];
    }

    return [
        'photo_count' => (int) ($photoTotals['photo_count'] ?? 0),
        'meal_days' => (int) ($photoTotals['meal_days'] ?? 0),
        'junk_days' => (int) ($logTotals['junk_days'] ?? 0),
        'logged_days' => (int) ($logTotals['logged_days'] ?? 0),
        'training_calories_burned' => round((float) ($logTotals['training_calories_burned'] ?? 0), 2),
        'totals' => [
            'calories' => round((float) ($photoTotals['calories'] ?? 0), 2),
            'protein_g' => round((float) ($photoTotals['protein_g'] ?? 0), 2),
            'carbs_g' => round((float) ($photoTotals['carbs_g'] ?? 0), 2),
            'fat_g' => round((float) ($photoTotals['fat_g'] ?? 0), 2),
            'fiber_g' => round((float) ($photoTotals['fiber_g'] ?? 0), 2),
            'sugar_g' => round((float) ($photoTotals['sugar_g'] ?? 0), 2),
            'sodium_mg' => round((float) ($photoTotals['sodium_mg'] ?? 0), 2),
        ],
        'categories' => $categories,
    ];
}

function upload_error_message(int $errorCode): string
{
    return match ($errorCode) {
        UPLOAD_ERR_INI_SIZE => t('upload.too_large_server') . ' (' . (string) ini_get('upload_max_filesize') . ')',
        UPLOAD_ERR_FORM_SIZE => t('upload.too_large_form'),
        UPLOAD_ERR_PARTIAL => t('upload.partial'),
        UPLOAD_ERR_NO_FILE => t('upload.no_file'),
        UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION => t('upload.move_failed'),
        default => t('upload.failed'),
    };
}

function ensure_upload_target_dir(array $config, string $subDir): string
{
    $targetDir = rtrim((string) $config['upload_dir'], '/') . '/' . trim($subDir, '/');
    if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        throw new RuntimeException(t('upload.move_failed'));
    }

    @chmod($targetDir, 0775);
    if (!is_writable($targetDir)) {
        @chmod($targetDir, 0777);
    }
    if (!is_writable($targetDir)) {
        throw new RuntimeException(t('upload.directory_unwritable'));
    }

    return $targetDir;
}

function detect_uploaded_image_mime(string $filePath): string
{
    if ($filePath === '' || !is_file($filePath)) {
        return '';
    }

    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $detected = finfo_file($finfo, $filePath);
            if (is_string($detected) && $detected !== '') {
                $mime = strtolower(trim($detected));
            }
            if (PHP_VERSION_ID < 80500) {
                finfo_close($finfo);
            }
        }
    }

    if ($mime === '') {
        $imageInfo = @getimagesize($filePath);
        $fallbackMime = is_array($imageInfo) ? strtolower((string) ($imageInfo['mime'] ?? '')) : '';
        if ($fallbackMime !== '') {
            $mime = $fallbackMime;
        }
    }

    return $mime;
}

function image_upload_policy(array $config, string $context = 'default'): array
{
    $defaultAllowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if ($context === 'photo_entry') {
        $maxBytes = (int) ($config['photo_upload_max_bytes'] ?? 15728640);
        if ($maxBytes <= 0) {
            $maxBytes = 15728640;
        }

        return [
            'allowed_mimes' => $defaultAllowed + [
                'image/gif' => 'gif',
                'image/heic' => 'heic',
                'image/heif' => 'heif',
                'image/x-heic' => 'heic',
                'image/x-heif' => 'heif',
                'image/heic-sequence' => 'heic',
                'image/heif-sequence' => 'heif',
            ],
            'max_bytes' => $maxBytes,
            'invalid_format_message' => t('upload.invalid_format_photo'),
        ];
    }

    return [
        'allowed_mimes' => $defaultAllowed,
        'max_bytes' => 0,
        'invalid_format_message' => t('upload.invalid_format'),
    ];
}

function format_upload_size(int $bytes): string
{
    $safeBytes = max(0, $bytes);
    if ($safeBytes >= 1048576) {
        return rtrim(rtrim(number_format($safeBytes / 1048576, 1, '.', ''), '0'), '.') . ' MB';
    }
    if ($safeBytes >= 1024) {
        return rtrim(rtrim(number_format($safeBytes / 1024, 1, '.', ''), '0'), '.') . ' KB';
    }

    return (string) $safeBytes . ' B';
}

function save_uploaded_image(array $config, array $file, string $subDir, string $prefix, array $options = []): string
{
    $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errorCode !== UPLOAD_ERR_OK) {
        throw new RuntimeException(upload_error_message($errorCode));
    }

    $policy = image_upload_policy($config, 'default');
    if (isset($options['allowed_mimes']) && is_array($options['allowed_mimes'])) {
        $policy['allowed_mimes'] = $options['allowed_mimes'];
    }
    if (array_key_exists('max_bytes', $options)) {
        $policy['max_bytes'] = (int) $options['max_bytes'];
    }
    if (isset($options['invalid_format_message']) && is_string($options['invalid_format_message'])) {
        $policy['invalid_format_message'] = $options['invalid_format_message'];
    }

    $maxBytes = max(0, (int) ($policy['max_bytes'] ?? 0));
    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException(t('upload.failed'));
    }

    $rawSize = (int) ($file['size'] ?? 0);
    if ($maxBytes > 0) {
        $effectiveSize = $rawSize > 0 ? $rawSize : (int) (@filesize($tmpName) ?: 0);
        if ($effectiveSize > $maxBytes) {
            throw new RuntimeException(t('upload.file_too_large', ['max' => format_upload_size($maxBytes)]));
        }
    }

    $mime = detect_uploaded_image_mime($tmpName);
    if ($mime === '') {
        throw new RuntimeException(t('upload.invalid_image'));
    }

    $allowed = is_array($policy['allowed_mimes'] ?? null) ? $policy['allowed_mimes'] : [];
    $invalidFormatMessage = trim((string) ($policy['invalid_format_message'] ?? t('upload.invalid_format')));
    if ($invalidFormatMessage === '') {
        $invalidFormatMessage = t('upload.invalid_format');
    }

    if (!isset($allowed[$mime])) {
        throw new RuntimeException($invalidFormatMessage);
    }

    $targetDir = ensure_upload_target_dir($config, $subDir);

    $safePrefix = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $prefix) ?: 'image';
    $filename = sprintf('%s_%s_%s.%s', $safePrefix, date('YmdHis'), bin2hex(random_bytes(6)), $allowed[$mime]);
    $targetPath = $targetDir . '/' . $filename;

    if (!move_uploaded_file($tmpName, $targetPath)) {
        throw new RuntimeException(t('upload.move_failed'));
    }

    $relativeDir = trim($subDir, '/');
    if ($relativeDir === '') {
        return $filename;
    }

    return $relativeDir . '/' . $filename;
}

function save_uploaded_image_from_data_url(array $config, string $dataUrl, string $subDir, string $prefix): string
{
    if (!preg_match('/^data:(image\/(?:jpeg|png|webp));base64,(.+)$/si', trim($dataUrl), $matches)) {
        throw new RuntimeException(t('upload.invalid_image'));
    }

    $claimedMime = strtolower((string) $matches[1]);
    $encoded = (string) $matches[2];
    $binary = base64_decode(str_replace(' ', '+', $encoded), true);
    if ($binary === false || $binary === '') {
        throw new RuntimeException(t('upload.invalid_image'));
    }

    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $detected = finfo_buffer($finfo, $binary);
            if (is_string($detected) && $detected !== '') {
                $mime = strtolower(trim($detected));
            }
            if (PHP_VERSION_ID < 80500) {
                finfo_close($finfo);
            }
        }
    }

    $info = @getimagesizefromstring($binary);
    if ($info === false) {
        throw new RuntimeException(t('upload.invalid_image'));
    }
    if ($mime === '') {
        $mime = strtolower((string) ($info['mime'] ?? ''));
    }
    if ($mime === '') {
        throw new RuntimeException(t('upload.invalid_image'));
    }

    $basePolicy = image_upload_policy($config, 'default');
    $allowed = is_array($basePolicy['allowed_mimes'] ?? null) ? $basePolicy['allowed_mimes'] : [];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException(t('upload.invalid_format'));
    }
    if (!isset($allowed[$claimedMime])) {
        throw new RuntimeException(t('upload.invalid_format'));
    }

    $targetDir = ensure_upload_target_dir($config, $subDir);

    $safePrefix = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $prefix) ?: 'image';
    $filename = sprintf('%s_%s_%s.%s', $safePrefix, date('YmdHis'), bin2hex(random_bytes(6)), $allowed[$mime]);
    $targetPath = $targetDir . '/' . $filename;
    if (file_put_contents($targetPath, $binary) === false) {
        throw new RuntimeException(t('upload.move_failed'));
    }

    $relativeDir = trim($subDir, '/');
    if ($relativeDir === '') {
        return $filename;
    }

    return $relativeDir . '/' . $filename;
}

function resolve_media_storage_path(array $config, string $reference): ?string
{
    $normalized = normalize_media_reference($reference);
    $kind = (string) ($normalized['kind'] ?? '');
    $normalizedValue = (string) ($normalized['normalized'] ?? '');
    $attemptedPaths = [];
    if ($kind !== 'media') {
        media_debug_log('resolve_media_storage_path', [
            'stored_value' => $reference,
            'helper_input' => $reference,
            'normalized_value' => $normalizedValue,
            'normalized_kind' => $kind,
            'final_url' => '',
            'reason' => $kind === '' ? 'invalid' : $kind,
            'attempted_paths' => $attemptedPaths,
        ]);

        return null;
    }

    $clean = $normalizedValue;
    if ($clean === '') {
        media_debug_log('resolve_media_storage_path', [
            'stored_value' => $reference,
            'helper_input' => $reference,
            'normalized_value' => '',
            'normalized_kind' => $kind,
            'final_url' => '',
            'reason' => 'normalized_empty',
            'attempted_paths' => $attemptedPaths,
        ]);

        return null;
    }

    $storageRoot = rtrim((string) $config['upload_dir'], '/');
    $legacyRoot = rtrim(dirname(__DIR__) . '/public/uploads', '/');
    $allowedRoots = [$storageRoot, $legacyRoot];
    $decodedReference = media_decode_reference_value(str_replace('\\', '/', trim($reference)));
    if (media_is_absolute_filesystem_path($decodedReference)) {
        $absoluteCandidate = preg_replace('~/+~', '/', $decodedReference) ?? $decodedReference;
        $attemptedPaths[] = $absoluteCandidate;
        if (is_file($absoluteCandidate)) {
            $absoluteReal = realpath($absoluteCandidate) ?: $absoluteCandidate;
            foreach ($allowedRoots as $root) {
                $rootReal = realpath($root) ?: $root;
                if ($rootReal === '') {
                    continue;
                }
                $rootPrefix = rtrim(str_replace('\\', '/', $rootReal), '/') . '/';
                $absoluteCheck = str_replace('\\', '/', $absoluteReal);
                if (str_starts_with($absoluteCheck, $rootPrefix)) {
                    media_debug_log('resolve_media_storage_path', [
                        'stored_value' => $reference,
                        'helper_input' => $reference,
                        'normalized_value' => $clean,
                        'normalized_kind' => $kind,
                        'final_url' => $absoluteReal,
                        'reason' => 'absolute_allowed',
                        'attempted_paths' => $attemptedPaths,
                    ]);

                    return $absoluteReal;
                }
            }
            media_debug_log('resolve_media_storage_path', [
                'stored_value' => $reference,
                'helper_input' => $reference,
                'normalized_value' => $clean,
                'normalized_kind' => $kind,
                'final_url' => '',
                'reason' => 'absolute_outside_allowed_roots',
                'attempted_paths' => $attemptedPaths,
            ]);
        }
    }

    $storagePath = $storageRoot . '/' . $clean;
    $attemptedPaths[] = $storagePath;
    if (is_file($storagePath)) {
        media_debug_log('resolve_media_storage_path', [
            'stored_value' => $reference,
            'helper_input' => $reference,
            'normalized_value' => $clean,
            'normalized_kind' => $kind,
            'final_url' => $storagePath,
            'reason' => 'storage_upload_dir',
            'attempted_paths' => $attemptedPaths,
        ]);

        return $storagePath;
    }

    $legacyPath = $legacyRoot . '/' . $clean;
    $attemptedPaths[] = $legacyPath;
    if (is_file($legacyPath)) {
        media_debug_log('resolve_media_storage_path', [
            'stored_value' => $reference,
            'helper_input' => $reference,
            'normalized_value' => $clean,
            'normalized_kind' => $kind,
            'final_url' => $legacyPath,
            'reason' => 'legacy_public_uploads',
            'attempted_paths' => $attemptedPaths,
        ]);

        return $legacyPath;
    }

    media_debug_log('resolve_media_storage_path', [
        'stored_value' => $reference,
        'helper_input' => $reference,
        'normalized_value' => $clean,
        'normalized_kind' => $kind,
        'final_url' => '',
        'reason' => 'file_not_found',
        'attempted_paths' => $attemptedPaths,
    ]);

    return null;
}

function allowed_primary_goal_types(): array
{
    return ['steps', 'km', 'workouts'];
}

function parse_primary_goals_spec(string $rawSpec, bool $strict = false): array
{
    $spec = trim($rawSpec);
    if ($spec === '') {
        return [];
    }

    $allowedTypes = allowed_primary_goal_types();
    $goalsByType = [];
    $order = [];
    $chunks = explode(';', $spec);
    foreach ($chunks as $chunk) {
        $piece = trim($chunk);
        if ($piece === '') {
            continue;
        }

        if (!str_contains($piece, ':')) {
            if ($strict) {
                throw new InvalidArgumentException('Invalid goal format. Use type:value;type:value');
            }
            continue;
        }

        [$rawType, $rawValue] = array_map('trim', explode(':', $piece, 2));
        $type = strtolower($rawType);
        if (!in_array($type, $allowedTypes, true)) {
            if ($strict) {
                throw new InvalidArgumentException('Invalid goal type. Allowed: steps, km, workouts');
            }
            continue;
        }

        if ($rawValue === '' || !is_numeric($rawValue)) {
            if ($strict) {
                throw new InvalidArgumentException('Invalid goal value for "' . $type . '".');
            }
            continue;
        }

        $value = (float) $rawValue;
        if (in_array($type, ['steps', 'workouts'], true)) {
            $value = (float) max(0, (int) round($value));
        }
        if ($value <= 0) {
            if ($strict) {
                throw new InvalidArgumentException('Goal value for "' . $type . '" must be greater than 0.');
            }
            continue;
        }

        if (!isset($goalsByType[$type])) {
            $order[] = $type;
        }
        $goalsByType[$type] = [
            'type' => $type,
            'value' => $value,
        ];
    }

    $parsed = [];
    foreach ($order as $type) {
        if (isset($goalsByType[$type])) {
            $parsed[] = $goalsByType[$type];
        }
    }

    if ($strict && $parsed === []) {
        throw new InvalidArgumentException('At least one valid goal is required.');
    }

    return $parsed;
}

function format_primary_goals_spec(array $goals): string
{
    $parts = [];
    foreach ($goals as $goal) {
        $type = strtolower(trim((string) ($goal['type'] ?? '')));
        $value = (float) ($goal['value'] ?? 0);
        if ($type === '' || $value <= 0 || !in_array($type, allowed_primary_goal_types(), true)) {
            continue;
        }

        $formattedValue = in_array($type, ['steps', 'workouts'], true)
            ? (string) ((int) round($value))
            : rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');

        $parts[] = $type . ':' . $formattedValue;
    }

    return implode(';', $parts);
}

function normalize_primary_goals_spec(string $rawSpec): string
{
    return format_primary_goals_spec(parse_primary_goals_spec($rawSpec, true));
}

function user_primary_goals(array $user): array
{
    $spec = trim((string) ($user['primary_goals_spec'] ?? ''));
    if ($spec !== '') {
        $parsed = parse_primary_goals_spec($spec, false);
        if ($parsed !== []) {
            return $parsed;
        }
    }

    $legacyType = strtolower(trim((string) ($user['primary_goal_type'] ?? 'steps')));
    if (!in_array($legacyType, allowed_primary_goal_types(), true)) {
        $legacyType = 'steps';
    }

    $legacyValue = match ($legacyType) {
        'steps' => (float) max(1, (int) ($user['step_goal'] ?? 0)),
        'km' => (float) ($user['primary_goal_value'] ?? 0),
        'workouts' => (float) (($user['primary_goal_value'] ?? 1) ?: 1),
        default => (float) max(1, (int) ($user['step_goal'] ?? 0)),
    };

    if ($legacyType === 'km' && $legacyValue <= 0) {
        $legacyType = 'steps';
        $legacyValue = (float) max(1, (int) ($user['step_goal'] ?? 0));
    }
    if ($legacyType === 'workouts') {
        $legacyValue = (float) max(1, (int) round($legacyValue));
    }

    return [[
        'type' => $legacyType,
        'value' => $legacyValue,
    ]];
}

function detect_media_mime_type(string $filePath): string
{
    $mime = 'application/octet-stream';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $detected = finfo_file($finfo, $filePath);
            if (is_string($detected) && $detected !== '') {
                $mime = $detected;
            }
            if (PHP_VERSION_ID < 80500) {
                finfo_close($finfo);
            }
        }
    }

    if ($mime === '' || $mime === 'application/octet-stream') {
        $extension = strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION));
        $mimeByExtension = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'heic' => 'image/heic',
            'heif' => 'image/heif',
            'avif' => 'image/avif',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
        ];
        if (isset($mimeByExtension[$extension])) {
            $mime = $mimeByExtension[$extension];
        }
    }

    return $mime;
}

function media_thumbnail_cache_dir(array $config): string
{
    $dir = rtrim((string) ($config['upload_dir'] ?? ''), '/\\') . '/_thumbs';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create thumbnail cache directory.');
    }

    return $dir;
}

function generate_media_thumbnail(array $config, string $mediaPath, int $width = 360): ?array
{
    $width = max(80, min(1200, $width));
    $normalized = normalize_media_reference($mediaPath);
    if (($normalized['kind'] ?? '') !== 'media') {
        return null;
    }

    $sourcePath = resolve_media_storage_path($config, (string) ($normalized['normalized'] ?? ''));
    if ($sourcePath === null || !is_file($sourcePath)) {
        return null;
    }

    $mime = detect_media_mime_type($sourcePath);
    if (!media_thumbnail_supported($mime)) {
        return null;
    }

    $loaders = [
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png' => 'imagecreatefrompng',
        'image/webp' => 'imagecreatefromwebp',
        'image/gif' => 'imagecreatefromgif',
    ];
    $writeWebp = function_exists('imagewebp');
    $writeJpeg = function_exists('imagejpeg');
    if (!isset($loaders[$mime]) || !function_exists($loaders[$mime]) || !function_exists('imagecreatetruecolor') || (!$writeWebp && !$writeJpeg)) {
        return null;
    }

    $sourceMtime = @filemtime($sourcePath) ?: time();
    $targetMime = $writeWebp ? 'image/webp' : 'image/jpeg';
    $targetExtension = $writeWebp ? 'webp' : 'jpg';
    $cacheKey = sha1((string) ($normalized['normalized'] ?? '') . '|' . (string) $sourceMtime . '|' . (string) $width . '|' . $targetExtension);
    $cachePath = media_thumbnail_cache_dir($config) . '/' . $cacheKey . '.' . $targetExtension;
    if (is_file($cachePath)) {
        return ['path' => $cachePath, 'mime' => $targetMime];
    }

    $size = @getimagesize($sourcePath);
    if (!is_array($size) || (int) ($size[0] ?? 0) <= 0 || (int) ($size[1] ?? 0) <= 0) {
        return null;
    }

    $sourceWidth = (int) $size[0];
    $sourceHeight = (int) $size[1];
    $targetWidth = min($width, $sourceWidth);
    $targetHeight = max(1, (int) round(($sourceHeight / max(1, $sourceWidth)) * $targetWidth));

    $loader = $loaders[$mime];
    $sourceImage = @$loader($sourcePath);
    if (!$sourceImage instanceof GdImage) {
        return null;
    }

    $targetImage = imagecreatetruecolor($targetWidth, $targetHeight);
    if (!$targetImage instanceof GdImage) {
        if (PHP_VERSION_ID < 80500) {
            imagedestroy($sourceImage);
        }
        return null;
    }

    if ($targetMime === 'image/webp') {
        imagealphablending($targetImage, false);
        imagesavealpha($targetImage, true);
    } else {
        $white = imagecolorallocate($targetImage, 255, 255, 255);
        if ($white !== false) {
            imagefilledrectangle($targetImage, 0, 0, $targetWidth, $targetHeight, $white);
        }
    }
    imagecopyresampled($targetImage, $sourceImage, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
    $written = $targetMime === 'image/webp'
        ? imagewebp($targetImage, $cachePath, 78)
        : imagejpeg($targetImage, $cachePath, 78);
    if (PHP_VERSION_ID < 80500) {
        imagedestroy($sourceImage);
        imagedestroy($targetImage);
    }

    return $written && is_file($cachePath) ? ['path' => $cachePath, 'mime' => $targetMime] : null;
}

function warm_media_thumbnails(array $config, string $mediaPath, array $widths = [200, 400, 800]): void
{
    foreach ($widths as $width) {
        try {
            generate_media_thumbnail($config, $mediaPath, (int) $width);
        } catch (Throwable) {
            continue;
        }
    }
}

function regenerate_photo_thumbnails(PDO $pdo, array $config, array $widths = [200, 400, 800]): array
{
    $rows = db_fetch_all(
        $pdo,
        'SELECT id, file_path FROM photo_entries WHERE file_path IS NOT NULL AND file_path != "" ORDER BY id ASC'
    );
    $photos = 0;
    $generated = 0;
    $failed = 0;
    foreach ($rows as $row) {
        $path = trim((string) ($row['file_path'] ?? ''));
        if ($path === '') {
            continue;
        }
        $photos++;
        foreach ($widths as $width) {
            try {
                $thumb = generate_media_thumbnail($config, $path, (int) $width);
                if (is_array($thumb) && is_file((string) ($thumb['path'] ?? ''))) {
                    $generated++;
                } else {
                    $failed++;
                }
            } catch (Throwable) {
                $failed++;
            }
        }
    }

    return [
        'photos' => $photos,
        'generated' => $generated,
        'failed' => $failed,
    ];
}

function create_user(PDO $pdo, array $payload): void
{
    $now = now_iso();

    db_execute(
        $pdo,
        'INSERT INTO users (
            username, password_hash, display_name, role,
            step_goal, step_days_mask, workout_target,
            workout_days_mask, workout_strict, ideal_weight,
            primary_goal_type, primary_goal_value, active, created_at, updated_at
        ) VALUES (
            :username, :password_hash, :display_name, :role,
            :step_goal, :step_days_mask, :workout_target,
            :workout_days_mask, :workout_strict, :ideal_weight,
            :primary_goal_type, :primary_goal_value, :active, :created_at, :updated_at
        )',
        [
            ':username' => $payload['username'],
            ':password_hash' => password_hash($payload['password'], PASSWORD_DEFAULT),
            ':display_name' => $payload['display_name'],
            ':role' => $payload['role'],
            ':step_goal' => $payload['step_goal'],
            ':step_days_mask' => $payload['step_days_mask'],
            ':workout_target' => $payload['workout_target'],
            ':workout_days_mask' => $payload['workout_days_mask'],
            ':workout_strict' => $payload['workout_strict'],
            ':ideal_weight' => $payload['ideal_weight'],
            ':primary_goal_type' => $payload['primary_goal_type'] ?? 'steps',
            ':primary_goal_value' => $payload['primary_goal_value'] ?? null,
            ':active' => $payload['active'],
            ':created_at' => $now,
            ':updated_at' => $now,
        ]
    );
}

function update_user(PDO $pdo, int $userId, array $payload): void
{
    db_execute(
        $pdo,
        'UPDATE users
         SET display_name = :display_name,
             role = :role,
             step_goal = :step_goal,
             step_days_mask = :step_days_mask,
             workout_target = :workout_target,
             workout_days_mask = :workout_days_mask,
             workout_strict = :workout_strict,
             ideal_weight = :ideal_weight,
             primary_goal_type = :primary_goal_type,
             primary_goal_value = :primary_goal_value,
             active = :active,
             updated_at = :updated_at
         WHERE id = :id',
        [
            ':display_name' => $payload['display_name'],
            ':role' => $payload['role'],
            ':step_goal' => $payload['step_goal'],
            ':step_days_mask' => $payload['step_days_mask'],
            ':workout_target' => $payload['workout_target'],
            ':workout_days_mask' => $payload['workout_days_mask'],
            ':workout_strict' => $payload['workout_strict'],
            ':ideal_weight' => $payload['ideal_weight'],
            ':primary_goal_type' => $payload['primary_goal_type'] ?? 'steps',
            ':primary_goal_value' => $payload['primary_goal_value'] ?? null,
            ':active' => $payload['active'],
            ':updated_at' => now_iso(),
            ':id' => $userId,
        ]
    );

    if (($payload['password'] ?? '') !== '') {
        db_execute(
            $pdo,
            'UPDATE users SET password_hash = :password_hash, updated_at = :updated_at WHERE id = :id',
            [
                ':password_hash' => password_hash($payload['password'], PASSWORD_DEFAULT),
                ':updated_at' => now_iso(),
                ':id' => $userId,
            ]
        );
    }
}

function list_motivational_quotes(PDO $pdo, bool $activeOnly = false): array
{
    $where = $activeOnly ? 'WHERE mq.active = 1' : '';

    return db_fetch_all(
        $pdo,
        'SELECT mq.*, u.display_name AS created_by_name
         FROM motivational_quotes mq
         LEFT JOIN users u ON u.id = mq.created_by
         ' . $where . '
         ORDER BY mq.active DESC, mq.created_at DESC, mq.id DESC'
    );
}

function create_motivational_quote(PDO $pdo, string $quoteText, int $actorUserId): int
{
    $quoteText = trim($quoteText);
    if ($quoteText === '') {
        throw new InvalidArgumentException('Motivational quote is required.');
    }

    $now = now_iso();
    db_execute(
        $pdo,
        'INSERT INTO motivational_quotes (quote_text, active, created_by, created_at, updated_at)
         VALUES (:quote_text, 1, :created_by, :created_at, :updated_at)',
        [
            ':quote_text' => $quoteText,
            ':created_by' => $actorUserId,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]
    );

    $quote = db_fetch_one($pdo, 'SELECT * FROM motivational_quotes WHERE id = last_insert_rowid()');
    $quoteId = (int) ($quote['id'] ?? 0);
    audit_log($pdo, $actorUserId, 'motivational_quote_created', 'motivational_quote', (string) $quoteId, 'Motivational quote created.', null, audit_snapshot($quote));

    return $quoteId;
}

function random_motivation_quote_from_db(PDO $pdo): string
{
    $row = db_fetch_one(
        $pdo,
        'SELECT quote_text FROM motivational_quotes WHERE active = 1 ORDER BY RANDOM() LIMIT 1'
    );
    $quote = trim((string) ($row['quote_text'] ?? ''));

    return $quote !== '' ? $quote : random_motivation_quote();
}

function team_layout_widgets_default(): array
{
    return [
        'metrics',
        'active_challenge',
        'leaderboard',
        'challenges',
        'members',
        'daily_charts',
        'cumulative_steps',
        'cumulative_distance',
        'weekly_charts',
        'achievements',
    ];
}

function normalize_team_layout_widgets(mixed $rawLayout): array
{
    $allowed = team_layout_widgets_default();
    $posted = [];
    $hasExplicitLayout = false;

    if (is_string($rawLayout)) {
        if (trim($rawLayout) !== '') {
            $decoded = json_decode($rawLayout, true);
            if (is_array($decoded)) {
                $posted = $decoded;
                $hasExplicitLayout = true;
            }
        }
    } elseif (is_array($rawLayout)) {
        $posted = $rawLayout;
        $hasExplicitLayout = true;
    }

    if (!$hasExplicitLayout) {
        return $allowed;
    }

    $normalized = [];
    foreach ($posted as $widget) {
        $widgetKey = trim((string) $widget);
        if ($widgetKey !== '' && in_array($widgetKey, $allowed, true) && !in_array($widgetKey, $normalized, true)) {
            $normalized[] = $widgetKey;
        }
    }

    return $normalized;
}

function analytics_layout_sections_default(): array
{
    return [
        'summary',
        'activity',
        'nutrition',
        'food',
        'body',
        'comparison',
    ];
}

function normalize_analytics_layout_sections(mixed $rawLayout): array
{
    $allowed = analytics_layout_sections_default();
    $posted = [];
    $hasExplicitLayout = false;

    if (is_string($rawLayout)) {
        if (trim($rawLayout) !== '') {
            $decoded = json_decode($rawLayout, true);
            if (is_array($decoded)) {
                $posted = $decoded;
                $hasExplicitLayout = true;
            }
        }
    } elseif (is_array($rawLayout)) {
        $posted = $rawLayout;
        $hasExplicitLayout = true;
    }

    if (!$hasExplicitLayout) {
        return $allowed;
    }

    $normalized = [];
    foreach ($posted as $section) {
        $sectionKey = trim((string) $section);
        if ($sectionKey !== '' && in_array($sectionKey, $allowed, true) && !in_array($sectionKey, $normalized, true)) {
            $normalized[] = $sectionKey;
        }
    }

    return $normalized;
}

function change_password(PDO $pdo, int $userId, string $currentPassword, string $newPassword): bool
{
    $user = db_fetch_one($pdo, 'SELECT * FROM users WHERE id = :id', [':id' => $userId]);
    if ($user === null) {
        return false;
    }

    if (!password_verify($currentPassword, $user['password_hash'])) {
        return false;
    }

    db_execute(
        $pdo,
        'UPDATE users SET password_hash = :hash, updated_at = :updated_at WHERE id = :id',
        [
            ':hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            ':updated_at' => now_iso(),
            ':id' => $userId,
        ]
    );

    return true;
}

function audit_log(
    PDO $pdo,
    ?int $actorUserId,
    string $action,
    string $entityType,
    string $entityId,
    string $summary,
    ?array $before = null,
    ?array $after = null
): void {
    db_execute(
        $pdo,
        'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, summary, before_json, after_json, created_at)
         VALUES (:actor_user_id, :action, :entity_type, :entity_id, :summary, :before_json, :after_json, :created_at)',
        [
            ':actor_user_id' => $actorUserId,
            ':action' => $action,
            ':entity_type' => $entityType,
            ':entity_id' => $entityId,
            ':summary' => $summary,
            ':before_json' => $before !== null ? json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ':after_json' => $after !== null ? json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ':created_at' => now_iso(),
        ]
    );
}

function audit_snapshot(?array $row, array $exclude = []): ?array
{
    if ($row === null) {
        return null;
    }

    foreach ($exclude as $key) {
        unset($row[$key]);
    }

    return $row;
}

function fetch_audit_logs(PDO $pdo, array $filters = [], int $limit = 80): array
{
    $limit = max(1, min(300, $limit));
    $params = [];
    $conditions = [];

    if (!empty($filters['actor_user_id'])) {
        $conditions[] = 'a.actor_user_id = :actor_user_id';
        $params[':actor_user_id'] = (int) $filters['actor_user_id'];
    }
    if (!empty($filters['entity_type'])) {
        $conditions[] = 'a.entity_type = :entity_type';
        $params[':entity_type'] = (string) $filters['entity_type'];
    }
    if (!empty($filters['date_from'])) {
        $conditions[] = 'DATE(a.created_at) >= :date_from';
        $params[':date_from'] = (string) $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $conditions[] = 'DATE(a.created_at) <= :date_to';
        $params[':date_to'] = (string) $filters['date_to'];
    }

    $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

    return db_fetch_all(
        $pdo,
        'SELECT a.*, u.display_name AS actor_name
         FROM audit_logs a
         LEFT JOIN users u ON u.id = a.actor_user_id
         ' . $where . '
         ORDER BY a.created_at DESC
         LIMIT ' . $limit,
        $params
    );
}

function default_team(PDO $pdo): array
{
    $team = db_fetch_one($pdo, 'SELECT * FROM teams WHERE slug = :slug', [':slug' => 'main']);
    if ($team !== null) {
        return $team;
    }

    seed_default_team($pdo);
    $team = db_fetch_one($pdo, 'SELECT * FROM teams WHERE slug = :slug', [':slug' => 'main']);
    if ($team === null) {
        throw new RuntimeException('Default team unavailable.');
    }

    return $team;
}

function list_team_members(PDO $pdo, int $teamId, bool $activeOnly = true): array
{
    $where = $activeOnly ? 'AND tm.active = 1 AND u.active = 1' : '';

    return db_fetch_all(
        $pdo,
        'SELECT tm.*, u.username, u.display_name, u.role AS user_role, u.step_goal, u.workout_target, u.active AS user_active, u.avatar_path, u.updated_at
         FROM team_memberships tm
         JOIN users u ON u.id = tm.user_id
         WHERE tm.team_id = :team_id ' . $where . '
         ORDER BY tm.active DESC, u.display_name ASC',
        [':team_id' => $teamId]
    );
}

function list_active_team_users(PDO $pdo, int $teamId): array
{
    return db_fetch_all(
        $pdo,
        'SELECT u.*
         FROM team_memberships tm
         JOIN users u ON u.id = tm.user_id
         WHERE tm.team_id = :team_id AND tm.active = 1 AND u.active = 1
         ORDER BY u.display_name ASC',
        [':team_id' => $teamId]
    );
}

function list_users_not_in_active_team(PDO $pdo, int $teamId): array
{
    return db_fetch_all(
        $pdo,
        'SELECT u.* FROM users u
         WHERE u.active = 1
           AND NOT EXISTS (
                SELECT 1 FROM team_memberships tm
                WHERE tm.user_id = u.id AND tm.team_id = :team_id AND tm.active = 1
           )
         ORDER BY u.display_name ASC',
        [':team_id' => $teamId]
    );
}

function set_team_membership(PDO $pdo, int $teamId, int $userId, bool $active, int $actorUserId): void
{
    $existing = db_fetch_one(
        $pdo,
        'SELECT * FROM team_memberships WHERE team_id = :team_id AND user_id = :user_id',
        [':team_id' => $teamId, ':user_id' => $userId]
    );
    $now = now_iso();

    if ($existing === null) {
        db_execute(
            $pdo,
            'INSERT INTO team_memberships (team_id, user_id, role, active, joined_at, removed_at, created_at, updated_at)
             VALUES (:team_id, :user_id, "member", :active, :joined_at, :removed_at, :created_at, :updated_at)',
            [
                ':team_id' => $teamId,
                ':user_id' => $userId,
                ':active' => $active ? 1 : 0,
                ':joined_at' => $now,
                ':removed_at' => $active ? null : $now,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]
        );
    } else {
        db_execute(
            $pdo,
            'UPDATE team_memberships
             SET active = :active,
                 removed_at = :removed_at,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                ':active' => $active ? 1 : 0,
                ':removed_at' => $active ? null : $now,
                ':updated_at' => $now,
                ':id' => (int) $existing['id'],
            ]
        );
    }

    $after = db_fetch_one(
        $pdo,
        'SELECT * FROM team_memberships WHERE team_id = :team_id AND user_id = :user_id',
        [':team_id' => $teamId, ':user_id' => $userId]
    );
    audit_log(
        $pdo,
        $actorUserId,
        $active ? 'team_member_added' : 'team_member_removed',
        'team_membership',
        (string) ($after['id'] ?? $userId),
        $active ? 'Team member added.' : 'Team member removed from team stats.',
        audit_snapshot($existing),
        audit_snapshot($after)
    );
}

function can_manage_team(PDO $pdo, array $user, int $teamId): bool
{
    if (is_admin($user)) {
        return true;
    }

    $membership = db_fetch_one(
        $pdo,
        'SELECT role FROM team_memberships WHERE team_id = :team_id AND user_id = :user_id AND active = 1',
        [':team_id' => $teamId, ':user_id' => (int) $user['id']]
    );

    return $membership !== null && in_array((string) $membership['role'], ['admin', 'owner'], true);
}

function require_team_manager(PDO $pdo, array $user, int $teamId): void
{
    if (!can_manage_team($pdo, $user, $teamId)) {
        http_response_code(403);
        echo e(t('flash.no_permission'));
        exit;
    }
}

function update_team_member_role(PDO $pdo, int $teamId, int $userId, string $role, int $actorUserId): void
{
    $role = $role === 'admin' ? 'admin' : 'member';
    $before = db_fetch_one(
        $pdo,
        'SELECT * FROM team_memberships WHERE team_id = :team_id AND user_id = :user_id',
        [':team_id' => $teamId, ':user_id' => $userId]
    );
    if ($before === null) {
        return;
    }

    db_execute(
        $pdo,
        'UPDATE team_memberships SET role = :role, updated_at = :updated_at WHERE id = :id',
        [':role' => $role, ':updated_at' => now_iso(), ':id' => (int) $before['id']]
    );
    $after = db_fetch_one($pdo, 'SELECT * FROM team_memberships WHERE id = :id', [':id' => (int) $before['id']]);
    audit_log($pdo, $actorUserId, 'team_member_role_updated', 'team_membership', (string) $before['id'], 'Team member role updated.', audit_snapshot($before), audit_snapshot($after));
}

function list_user_teams(PDO $pdo, int $userId): array
{
    return db_fetch_all(
        $pdo,
        'SELECT t.*, tm.role AS membership_role, tm.active AS membership_active
         FROM team_memberships tm
         JOIN teams t ON t.id = tm.team_id
         WHERE tm.user_id = :user_id AND tm.active = 1 AND t.active = 1
         ORDER BY t.name ASC',
        [':user_id' => $userId]
    );
}

function list_joinable_teams(PDO $pdo, int $userId): array
{
    return db_fetch_all(
        $pdo,
        'SELECT t.*,
                (SELECT status FROM team_join_requests tjr WHERE tjr.team_id = t.id AND tjr.user_id = :user_id ORDER BY tjr.requested_at DESC LIMIT 1) AS request_status
         FROM teams t
         WHERE t.active = 1
           AND t.visibility = "visible"
           AND NOT EXISTS (
                SELECT 1 FROM team_memberships tm WHERE tm.team_id = t.id AND tm.user_id = :user_id AND tm.active = 1
           )
         ORDER BY t.name ASC',
        [':user_id' => $userId]
    );
}

function request_or_join_team(PDO $pdo, int $teamId, int $userId): string
{
    $team = db_fetch_one($pdo, 'SELECT * FROM teams WHERE id = :id AND active = 1', [':id' => $teamId]);
    if ($team === null || (string) $team['visibility'] !== 'visible') {
        return 'blocked';
    }

    if ((string) $team['join_mode'] === 'open') {
        set_team_membership($pdo, $teamId, $userId, true, $userId);
        return 'joined';
    }

    $existing = db_fetch_one(
        $pdo,
        'SELECT id FROM team_join_requests WHERE team_id = :team_id AND user_id = :user_id AND status = "pending"',
        [':team_id' => $teamId, ':user_id' => $userId]
    );
    if ($existing === null) {
        db_execute(
            $pdo,
            'INSERT INTO team_join_requests (team_id, user_id, status, requested_at)
             VALUES (:team_id, :user_id, "pending", :requested_at)',
            [':team_id' => $teamId, ':user_id' => $userId, ':requested_at' => now_iso()]
        );
        audit_log($pdo, $userId, 'team_join_requested', 'team', (string) $teamId, 'Team join requested.', null, ['team_id' => $teamId, 'user_id' => $userId]);
    }

    return 'requested';
}

function resolve_team_join_request(PDO $pdo, int $requestId, bool $approve, int $actorUserId): void
{
    $before = db_fetch_one($pdo, 'SELECT * FROM team_join_requests WHERE id = :id', [':id' => $requestId]);
    if ($before === null || (string) $before['status'] !== 'pending') {
        return;
    }

    $status = $approve ? 'approved' : 'rejected';
    db_execute(
        $pdo,
        'UPDATE team_join_requests SET status = :status, resolved_by = :resolved_by, resolved_at = :resolved_at WHERE id = :id',
        [':status' => $status, ':resolved_by' => $actorUserId, ':resolved_at' => now_iso(), ':id' => $requestId]
    );
    if ($approve) {
        set_team_membership($pdo, (int) $before['team_id'], (int) $before['user_id'], true, $actorUserId);
    }
    $after = db_fetch_one($pdo, 'SELECT * FROM team_join_requests WHERE id = :id', [':id' => $requestId]);
    audit_log($pdo, $actorUserId, 'team_join_' . $status, 'team_join_request', (string) $requestId, 'Team join request resolved.', audit_snapshot($before), audit_snapshot($after));
}

function update_team_settings(PDO $pdo, int $teamId, string $name, string $description, string $joinMode, string $visibility, int $actorUserId): void
{
    $joinMode = in_array($joinMode, ['open', 'closed'], true) ? $joinMode : 'closed';
    $visibility = in_array($visibility, ['visible', 'private'], true) ? $visibility : 'visible';
    $before = db_fetch_one($pdo, 'SELECT * FROM teams WHERE id = :id', [':id' => $teamId]);
    if ($before === null) {
        return;
    }

    db_execute(
        $pdo,
        'UPDATE teams
         SET name = :name,
             description = :description,
             join_mode = :join_mode,
             visibility = :visibility,
             updated_at = :updated_at
         WHERE id = :id',
        [
            ':name' => trim($name),
            ':description' => trim($description),
            ':join_mode' => $joinMode,
            ':visibility' => $visibility,
            ':updated_at' => now_iso(),
            ':id' => $teamId,
        ]
    );
    $after = db_fetch_one($pdo, 'SELECT * FROM teams WHERE id = :id', [':id' => $teamId]);
    audit_log($pdo, $actorUserId, 'team_settings_updated', 'team', (string) $teamId, 'Team settings updated.', audit_snapshot($before), audit_snapshot($after));
}

function pending_team_join_requests(PDO $pdo, int $teamId): array
{
    return db_fetch_all(
        $pdo,
        'SELECT tjr.*, u.display_name, u.username
         FROM team_join_requests tjr
         JOIN users u ON u.id = tjr.user_id
         WHERE tjr.team_id = :team_id AND tjr.status = "pending"
         ORDER BY tjr.requested_at ASC',
        [':team_id' => $teamId]
    );
}

function normalize_goal_due_time(?string $dueDate, mixed $dueTimeRaw): ?string
{
    $date = trim((string) $dueDate);
    if ($date === '') {
        return null;
    }

    $time = trim((string) $dueTimeRaw);
    if ($time === '') {
        return '23:59';
    }

    if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $time, $matches) === 1) {
        return sprintf('%02d:%02d', (int) $matches[1], (int) $matches[2]);
    }

    return '23:59';
}

function normalize_goal_start_time(mixed $startTimeRaw, string $fallback = '00:00'): string
{
    return normalize_log_time($startTimeRaw, $fallback);
}

function resolve_goal_start_datetime(mixed $startDateRaw, mixed $startTimeRaw, ?DateTimeImmutable $now = null): array
{
    $nowDateTime = $now ?? new DateTimeImmutable('now');
    $startDateInput = trim((string) $startDateRaw);
    $startTimeInput = trim((string) $startTimeRaw);

    if ($startDateInput === '' && $startTimeInput === '') {
        $startDate = $nowDateTime->format('Y-m-d');
        $startTime = $nowDateTime->format('H:i');
    } else {
        $startDate = $startDateInput !== '' ? to_date($startDateInput) : $nowDateTime->format('Y-m-d');
        $startTime = $startTimeInput !== '' ? normalize_goal_start_time($startTimeInput, '00:00') : '00:00';
    }

    return [
        'start_date' => $startDate,
        'start_time' => $startTime,
        'start_at' => log_datetime_from_values($startDate, $startTime, '00:00'),
    ];
}

function goal_start_datetime(array $goal): ?DateTimeImmutable
{
    $startDate = trim((string) ($goal['start_date'] ?? ''));
    if ($startDate === '') {
        return null;
    }

    $startTime = normalize_goal_start_time((string) ($goal['start_time'] ?? ''), '00:00');

    return log_datetime_from_values($startDate, $startTime, '00:00');
}

function goal_has_started(array $goal, ?DateTimeImmutable $now = null): bool
{
    $startAt = goal_start_datetime($goal);
    if (!($startAt instanceof DateTimeImmutable)) {
        return true;
    }

    $nowDateTime = $now ?? new DateTimeImmutable('now');
    return $nowDateTime >= $startAt;
}

function list_goals(PDO $pdo, string $scope, ?int $userId = null, ?int $teamId = null, bool $includeArchived = false): array
{
    $conditions = ['scope = :scope'];
    $params = [':scope' => $scope];

    if ($userId !== null) {
        $conditions[] = 'user_id = :user_id';
        $params[':user_id'] = $userId;
    }
    if ($teamId !== null) {
        $conditions[] = 'team_id = :team_id';
        $params[':team_id'] = $teamId;
    }
    if (!$includeArchived) {
        $conditions[] = 'status != "archived"';
    }

    return db_fetch_all(
        $pdo,
        'SELECT * FROM goals WHERE ' . implode(' AND ', $conditions) . ' ORDER BY status ASC, due_date IS NULL, due_date ASC, COALESCE(due_time, "23:59") ASC, created_at DESC',
        $params
    );
}

function create_goal(PDO $pdo, array $payload, int $actorUserId): void
{
    $now = now_iso();
    db_execute(
        $pdo,
        'INSERT INTO goals (
            scope, team_id, user_id, title, target_type, target_value,
            baseline_value, current_value,
            secondary_enabled, secondary_target_type, secondary_target_value, secondary_baseline_value, secondary_current_value, secondary_unit_label,
            unit_label, reward_text, start_date, start_time, due_date, due_time,
            status, completed_at, created_by, created_at, updated_at
        )
         VALUES (
            :scope, :team_id, :user_id, :title, :target_type, :target_value,
            :baseline_value, :current_value,
            :secondary_enabled, :secondary_target_type, :secondary_target_value, :secondary_baseline_value, :secondary_current_value, :secondary_unit_label,
            :unit_label, :reward_text, :start_date, :start_time, :due_date, :due_time,
            "active", NULL, :created_by, :created_at, :updated_at
        )',
        [
            ':scope' => $payload['scope'],
            ':team_id' => $payload['team_id'],
            ':user_id' => $payload['user_id'],
            ':title' => $payload['title'],
            ':target_type' => $payload['target_type'],
            ':target_value' => $payload['target_value'],
            ':baseline_value' => $payload['baseline_value'] ?? null,
            ':current_value' => $payload['current_value'] ?? 0,
            ':secondary_enabled' => !empty($payload['secondary_enabled']) ? 1 : 0,
            ':secondary_target_type' => $payload['secondary_target_type'] ?? null,
            ':secondary_target_value' => $payload['secondary_target_value'] ?? null,
            ':secondary_baseline_value' => $payload['secondary_baseline_value'] ?? null,
            ':secondary_current_value' => $payload['secondary_current_value'] ?? 0,
            ':secondary_unit_label' => $payload['secondary_unit_label'] ?? null,
            ':unit_label' => $payload['unit_label'] ?? null,
            ':reward_text' => $payload['reward_text'] ?? null,
            ':start_date' => $payload['start_date'] ?? null,
            ':start_time' => $payload['start_time'] ?? null,
            ':due_date' => $payload['due_date'],
            ':due_time' => $payload['due_time'] ?? null,
            ':created_by' => $actorUserId,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]
    );
    $goal = db_fetch_one($pdo, 'SELECT * FROM goals WHERE id = last_insert_rowid()');
    audit_log($pdo, $actorUserId, 'goal_created', 'goal', (string) ($goal['id'] ?? ''), 'Goal created.', null, audit_snapshot($goal));
}

function update_goal_status(PDO $pdo, int $goalId, string $status, int $actorUserId): void
{
    $allowed = ['active', 'complete', 'archived'];
    if (!in_array($status, $allowed, true)) {
        return;
    }

    $before = db_fetch_one($pdo, 'SELECT * FROM goals WHERE id = :id', [':id' => $goalId]);
    if ($before === null) {
        return;
    }

    db_execute(
        $pdo,
        'UPDATE goals
         SET status = :status,
             completed_at = CASE
                WHEN :status = "complete" AND completed_at IS NULL THEN :updated_at
                WHEN :status != "complete" THEN NULL
                ELSE completed_at
             END,
             updated_at = :updated_at
         WHERE id = :id',
        [':status' => $status, ':updated_at' => now_iso(), ':id' => $goalId]
    );
    $after = db_fetch_one($pdo, 'SELECT * FROM goals WHERE id = :id', [':id' => $goalId]);
    audit_log($pdo, $actorUserId, 'goal_' . $status, 'goal', (string) $goalId, 'Goal status updated.', audit_snapshot($before), audit_snapshot($after));
}

function update_goal(PDO $pdo, int $goalId, array $payload, int $actorUserId): void
{
    $before = db_fetch_one($pdo, 'SELECT * FROM goals WHERE id = :id', [':id' => $goalId]);
    if ($before === null) {
        return;
    }

    $setBaselineValue = array_key_exists('baseline_value', $payload);
    $setCurrentValue = array_key_exists('current_value', $payload);
    $setSecondaryBaselineValue = array_key_exists('secondary_baseline_value', $payload);
    $setSecondaryCurrentValue = array_key_exists('secondary_current_value', $payload);

    db_execute(
        $pdo,
        'UPDATE goals
         SET title = :title,
             target_type = :target_type,
             target_value = :target_value,
             baseline_value = CASE WHEN :set_baseline_value = 1 THEN :baseline_value ELSE baseline_value END,
             current_value = CASE WHEN :set_current_value = 1 THEN :current_value ELSE current_value END,
             secondary_enabled = :secondary_enabled,
             secondary_target_type = :secondary_target_type,
             secondary_target_value = :secondary_target_value,
             secondary_baseline_value = CASE WHEN :set_secondary_baseline_value = 1 THEN :secondary_baseline_value ELSE secondary_baseline_value END,
             secondary_current_value = CASE WHEN :set_secondary_current_value = 1 THEN :secondary_current_value ELSE secondary_current_value END,
             secondary_unit_label = :secondary_unit_label,
             unit_label = :unit_label,
             reward_text = :reward_text,
             start_date = :start_date,
             start_time = :start_time,
             due_date = :due_date,
             due_time = :due_time,
             updated_at = :updated_at
         WHERE id = :id',
        [
            ':title' => trim((string) $payload['title']),
            ':target_type' => trim((string) $payload['target_type']),
            ':target_value' => $payload['target_value'],
            ':set_baseline_value' => $setBaselineValue ? 1 : 0,
            ':baseline_value' => $setBaselineValue ? $payload['baseline_value'] : null,
            ':set_current_value' => $setCurrentValue ? 1 : 0,
            ':current_value' => $setCurrentValue ? $payload['current_value'] : null,
            ':secondary_enabled' => !empty($payload['secondary_enabled']) ? 1 : 0,
            ':secondary_target_type' => $payload['secondary_target_type'] ?? null,
            ':secondary_target_value' => $payload['secondary_target_value'] ?? null,
            ':set_secondary_baseline_value' => $setSecondaryBaselineValue ? 1 : 0,
            ':secondary_baseline_value' => $setSecondaryBaselineValue ? $payload['secondary_baseline_value'] : null,
            ':set_secondary_current_value' => $setSecondaryCurrentValue ? 1 : 0,
            ':secondary_current_value' => $setSecondaryCurrentValue ? $payload['secondary_current_value'] : null,
            ':secondary_unit_label' => $payload['secondary_unit_label'] ?? null,
            ':unit_label' => $payload['unit_label'] ?? null,
            ':reward_text' => $payload['reward_text'] ?? null,
            ':start_date' => $payload['start_date'] ?? null,
            ':start_time' => $payload['start_time'] ?? null,
            ':due_date' => $payload['due_date'],
            ':due_time' => $payload['due_time'] ?? null,
            ':updated_at' => now_iso(),
            ':id' => $goalId,
        ]
    );
    $after = db_fetch_one($pdo, 'SELECT * FROM goals WHERE id = :id', [':id' => $goalId]);
    audit_log($pdo, $actorUserId, 'goal_updated', 'goal', (string) $goalId, 'Goal updated.', audit_snapshot($before), audit_snapshot($after));
}

function delete_goal(PDO $pdo, int $goalId, int $actorUserId): void
{
    $before = db_fetch_one($pdo, 'SELECT * FROM goals WHERE id = :id', [':id' => $goalId]);
    if ($before === null) {
        return;
    }

    db_execute($pdo, 'DELETE FROM goals WHERE id = :id', [':id' => $goalId]);
    audit_log($pdo, $actorUserId, 'goal_deleted', 'goal', (string) $goalId, 'Goal deleted.', audit_snapshot($before), null);
}

function normalize_goal_target_type(string $targetType): string
{
    $normalized = strtolower(trim($targetType));
    if ($normalized === 'distance_km' || $normalized === 'distance') {
        $normalized = 'km';
    }
    if ($normalized === 'penalty') {
        $normalized = 'penalties';
    }
    if ($normalized === 'weight_progress') {
        $normalized = 'weight';
    }
    if (in_array($normalized, ['steps', 'km', 'workouts', 'score', 'strikes', 'penalties', 'weight', 'calories_burned', 'calories_consumed', 'custom'], true)) {
        return $normalized;
    }
    if (str_starts_with($normalized, 'habit:')) {
        $habitCode = trim(substr($normalized, 6));
        $habitCode = preg_replace('/[^a-z0-9_\-]/', '', strtolower($habitCode)) ?? '';
        return $habitCode !== '' ? 'habit:' . $habitCode : 'custom';
    }

    return 'custom';
}

function goal_progress_value_from_metric(array $goal, array $metric): float
{
    $type = normalize_goal_target_type((string) ($goal['target_type'] ?? 'custom'));

    return match (true) {
        $type === 'steps' => (float) ($metric['total_steps'] ?? 0),
        $type === 'km' => (float) ($metric['total_km'] ?? 0),
        $type === 'workouts' => max((float) ($metric['workout_count'] ?? 0), (float) ($metric['workout_success'] ?? 0)),
        $type === 'score' => (float) ($metric['score'] ?? 0),
        $type === 'strikes' => (float) ($metric['current_strikes'] ?? 0),
        $type === 'penalties' => (float) ($metric['total_penalty'] ?? 0),
        $type === 'weight' => (float) ($metric['latest_weight'] ?? 0),
        $type === 'calories_burned' => (float) ($metric['training_calories_burned_total'] ?? 0),
        $type === 'calories_consumed' => (float) ($metric['calories_consumed_total'] ?? 0),
        str_starts_with($type, 'habit:') => (float) (($metric['habit_counts'][substr($type, 6)] ?? 0)),
        default => (float) ($goal['current_value'] ?? 0),
    };
}

function goal_target_type_is_lower_better(string $targetType): bool
{
    $type = normalize_goal_target_type($targetType);
    return in_array($type, ['penalties', 'strikes', 'calories_consumed'], true);
}

function goal_target_default_unit(string $targetType): string
{
    $type = normalize_goal_target_type($targetType);
    return match ($type) {
        'steps' => 'steps',
        'km' => 'km',
        'workouts' => 'workouts',
        'score' => 'pts',
        'calories_burned', 'calories_consumed' => 'kcal',
        'penalties' => 'EUR',
        'strikes' => 'strikes',
        'weight' => '%',
        default => '',
    };
}

function goal_target_type_uses_time_window(string $targetType): bool
{
    $type = normalize_goal_target_type($targetType);
    return in_array($type, ['steps', 'km', 'workouts', 'calories_burned', 'calories_consumed'], true);
}

function goal_target_type_uses_dynamic_window_progress(string $targetType): bool
{
    $type = normalize_goal_target_type($targetType);
    return in_array($type, ['steps', 'km', 'workouts', 'calories_burned'], true);
}

function goal_secondary_target_type(array $goal): string
{
    return normalize_goal_target_type((string) ($goal['secondary_target_type'] ?? 'custom'));
}

function goal_secondary_target_value(array $goal): float
{
    return max(0.0, (float) ($goal['secondary_target_value'] ?? 0));
}

function goal_has_secondary_target(array $goal): bool
{
    return (int) ($goal['secondary_enabled'] ?? 0) === 1 && goal_secondary_target_value($goal) > 0;
}

function goal_team_metric_value_for_type(string $targetType, array $teamSummary, ?float $fallback = null): float
{
    $type = normalize_goal_target_type($targetType);
    return match ($type) {
        'steps' => (float) ($teamSummary['total_steps'] ?? 0),
        'km' => (float) ($teamSummary['total_km'] ?? 0),
        'workouts' => max((float) ($teamSummary['workout_count'] ?? 0), (float) ($teamSummary['workout_success'] ?? 0)),
        'score' => (float) ($teamSummary['score_avg'] ?? 0),
        'strikes' => (float) ($teamSummary['strikes'] ?? 0),
        'penalties' => (float) ($teamSummary['penalty'] ?? 0),
        'calories_burned' => (float) ($teamSummary['calories_burned'] ?? 0),
        'calories_consumed' => (float) ($teamSummary['calories_consumed'] ?? 0),
        'weight' => (float) ($teamSummary['weight_progress'] ?? 0),
        default => (float) ($fallback ?? 0),
    };
}

function goal_time_window_bounds(array $goal, ?DateTimeImmutable $now = null): array
{
    $nowDateTime = $now ?? new DateTimeImmutable('now');
    $startAt = goal_start_datetime($goal);
    if (!($startAt instanceof DateTimeImmutable)) {
        $startAt = $nowDateTime;
        $createdAtRaw = trim((string) ($goal['created_at'] ?? ''));
        if ($createdAtRaw !== '') {
            try {
                $startAt = new DateTimeImmutable($createdAtRaw);
            } catch (Throwable) {
                $startAt = $nowDateTime;
            }
        }
    }

    $endAt = $nowDateTime;
    $dueDate = trim((string) ($goal['due_date'] ?? ''));
    $dueTime = normalize_goal_due_time($dueDate !== '' ? $dueDate : null, (string) ($goal['due_time'] ?? ''));
    if ($dueDate !== '' && $dueTime !== null) {
        try {
            $endAt = new DateTimeImmutable($dueDate . ' ' . $dueTime . ':00');
        } catch (Throwable) {
            $endAt = $nowDateTime;
        }
    }
    if ($endAt > $nowDateTime) {
        $endAt = $nowDateTime;
    }

    if ($endAt < $startAt) {
        $endAt = $startAt;
    }

    return ['start' => $startAt, 'end' => $endAt];
}

function goal_window_metric_value_for_team(PDO $pdo, array $goal, array $teamUsers): float
{
    $type = normalize_goal_target_type((string) ($goal['target_type'] ?? 'custom'));
    if (!goal_target_type_uses_time_window($type)) {
        return 0.0;
    }

    $userIds = [];
    foreach ($teamUsers as $user) {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId > 0) {
            $userIds[$userId] = true;
        }
    }
    if ($userIds === []) {
        return 0.0;
    }

    $bounds = goal_time_window_bounds($goal);
    /** @var DateTimeImmutable $startAt */
    $startAt = $bounds['start'];
    /** @var DateTimeImmutable $endAt */
    $endAt = $bounds['end'];
    $startDate = $startAt->format('Y-m-d');
    $endDate = $endAt->format('Y-m-d');
    $endAtSql = $endAt->format('Y-m-d H:i:s');

    $params = [
        ':start_date' => $startDate,
        ':end_date' => $endDate,
        ':end_at' => $endAtSql,
    ];
    $placeholders = [];
    foreach (array_keys($userIds) as $index => $userId) {
        $key = ':goal_uid_' . $index;
        $placeholders[] = $key;
        $params[$key] = $userId;
    }

    $logRows = db_fetch_all(
        $pdo,
        'SELECT dl.user_id, dl.log_date, dl.log_time, dl.steps, dl.distance_km, dl.workout_done, dl.junk_food, dl.extra_workout, dl.training_calories_burned,
                COALESCE(
                    w.workout_entry_count,
                    CASE
                        WHEN dl.workout_done = 1
                         AND datetime(COALESCE(dl.updated_at, dl.created_at, dl.log_date || " 00:00:00")) <= datetime(:end_at)
                        THEN 1
                        ELSE 0
                    END
                ) AS workout_entry_count
         FROM daily_logs dl
         LEFT JOIN (
            SELECT log_id,
                   SUM(
                       CASE
                           WHEN datetime(COALESCE(created_at, updated_at, "0000-01-01 00:00:00")) <= datetime(:end_at)
                           THEN 1
                           ELSE 0
                       END
                   ) AS workout_entry_count
            FROM daily_log_workouts
            GROUP BY log_id
         ) w ON w.log_id = dl.id
         WHERE dl.log_date BETWEEN :start_date AND :end_date
           AND dl.user_id IN (' . implode(',', $placeholders) . ')
         ORDER BY dl.user_id ASC, dl.log_date ASC',
        $params
    );

    $approvalsByUserDate = load_approval_status_by_user_date($pdo, $startDate, $endDate);
    $sumSteps = 0.0;
    $sumKm = 0.0;
    $sumWorkouts = 0.0;
    $sumCaloriesBurned = 0.0;
    foreach ($logRows as $logRow) {
        $logAt = log_datetime_from_values((string) ($logRow['log_date'] ?? ''), $logRow['log_time'] ?? '', '00:00');
        if ($logAt === null || $logAt < $startAt || $logAt > $endAt) {
            continue;
        }

        $sumSteps += max(0, (int) ($logRow['steps'] ?? 0));
        $sumKm += max(0.0, (float) ($logRow['distance_km'] ?? 0));
        $sumCaloriesBurned += max(0.0, (float) ($logRow['training_calories_burned'] ?? 0));

        $userId = (int) ($logRow['user_id'] ?? 0);
        $logDate = (string) ($logRow['log_date'] ?? '');
        $approvalsByDate = is_array($approvalsByUserDate[$userId] ?? null) ? (array) $approvalsByUserDate[$userId] : [];
        $sumWorkouts += counted_workout_total($logRow, $approvalsByDate, $logDate);
    }

    if ($type === 'calories_consumed') {
        $photoRows = db_fetch_all(
            $pdo,
            'SELECT log_date, calories
             FROM photo_entries
             WHERE log_date BETWEEN :start_date AND :end_date
               AND user_id IN (' . implode(',', $placeholders) . ')',
            $params
        );
        $sumCaloriesConsumed = 0.0;
        foreach ($photoRows as $photoRow) {
            $photoAt = log_datetime_from_values((string) ($photoRow['log_date'] ?? ''), '00:00', '00:00');
            if ($photoAt === null || $photoAt < $startAt || $photoAt > $endAt) {
                continue;
            }
            $sumCaloriesConsumed += max(0.0, (float) ($photoRow['calories'] ?? 0));
        }
        return round($sumCaloriesConsumed, 2);
    }

    return match ($type) {
        'steps' => (float) ((int) round($sumSteps)),
        'km' => round($sumKm, 2),
        'workouts' => (float) ((int) round($sumWorkouts)),
        'calories_burned' => round($sumCaloriesBurned, 2),
        default => 0.0,
    };
}

function goal_team_baseline_from_start(
    PDO $pdo,
    array $goal,
    array $teamUsers,
    float $currentMetricValue,
    ?DateTimeImmutable $now = null
): float {
    $type = normalize_goal_target_type((string) ($goal['target_type'] ?? 'custom'));
    if (!goal_target_type_uses_time_window($type)) {
        return round($currentMetricValue, 2);
    }

    $startAt = goal_start_datetime($goal);
    if (!($startAt instanceof DateTimeImmutable)) {
        return round($currentMetricValue, 2);
    }

    $nowDateTime = $now ?? new DateTimeImmutable('now');
    if ($startAt >= $nowDateTime) {
        return round($currentMetricValue, 2);
    }

    $windowValueSinceStart = goal_window_metric_value_for_team(
        $pdo,
        [
            'target_type' => $type,
            'created_at' => $startAt->format('Y-m-d H:i:s'),
            'due_date' => $nowDateTime->format('Y-m-d'),
            'due_time' => $nowDateTime->format('H:i'),
        ],
        $teamUsers
    );

    return round(max(0.0, $currentMetricValue - $windowValueSinceStart), 2);
}

function goal_team_progress_value(PDO $pdo, array $goal, array $teamSummary, array $teamUsers): float
{
    unset($pdo, $teamUsers);
    $currentMetricValue = goal_team_metric_value($goal, $teamSummary);
    if (!goal_has_started($goal)) {
        return 0.0;
    }

    return goal_progress_from_baseline($goal, $currentMetricValue);
}

function goal_team_metric_value(array $goal, array $teamSummary): float
{
    return goal_team_metric_value_for_type(
        (string) ($goal['target_type'] ?? 'custom'),
        $teamSummary,
        (float) ($goal['current_value'] ?? 0)
    );
}

function goal_progress_from_baseline_for_type(string $targetType, float $baseline, float $currentMetricValue): float
{
    if (goal_target_type_is_lower_better($targetType)) {
        return max(0.0, $baseline - $currentMetricValue);
    }

    return max(0.0, $currentMetricValue - $baseline);
}

function goal_progress_from_baseline(array $goal, float $currentMetricValue): float
{
    $baseline = is_numeric($goal['baseline_value'] ?? null)
        ? (float) $goal['baseline_value']
        : $currentMetricValue;

    return goal_progress_from_baseline_for_type(
        (string) ($goal['target_type'] ?? 'custom'),
        $baseline,
        $currentMetricValue
    );
}

function goal_team_progress_state(PDO $pdo, array $goal, array $teamSummary, ?DateTimeImmutable $now = null): array
{
    $nowDateTime = $now ?? new DateTimeImmutable('now');
    $startAt = goal_start_datetime($goal);
    $hasStarted = !($startAt instanceof DateTimeImmutable) || $nowDateTime >= $startAt;
    $goalId = (int) ($goal['id'] ?? 0);
    $teamId = (int) ($goal['team_id'] ?? 0);
    $teamUsers = null;
    $resolveTeamUsers = static function () use (&$teamUsers, $pdo, $teamId): array {
        if (is_array($teamUsers)) {
            return $teamUsers;
        }
        if ($teamId <= 0) {
            $teamUsers = [];
            return $teamUsers;
        }
        $teamUsers = list_active_team_users($pdo, $teamId);
        return $teamUsers;
    };

    $resolveObjective = static function (
        string $targetTypeField,
        string $targetValueField,
        string $baselineField,
        string $currentField
    ) use (
        $pdo,
        &$goal,
        $teamSummary,
        $nowDateTime,
        $hasStarted,
        $goalId,
        $resolveTeamUsers
    ): array {
        $targetType = normalize_goal_target_type((string) ($goal[$targetTypeField] ?? 'custom'));
        $targetValue = max(0.0, (float) ($goal[$targetValueField] ?? 0));
        $useDynamicWindowProgress = goal_target_type_uses_dynamic_window_progress($targetType);

        $currentMetricValue = $useDynamicWindowProgress
            ? goal_window_metric_value_for_team(
                $pdo,
                array_merge($goal, ['target_type' => $targetType]),
                $resolveTeamUsers()
            )
            : goal_team_metric_value_for_type(
                $targetType,
                $teamSummary,
                (float) ($goal[$currentField] ?? 0)
            );

        if (!$hasStarted) {
            return [
                'target_type' => $targetType,
                'target_value' => $targetValue,
                'current_metric_value' => $currentMetricValue,
                'baseline_value' => is_numeric($goal[$baselineField] ?? null) ? (float) $goal[$baselineField] : null,
                'progress_value' => 0.0,
                'progress_pct_raw' => 0.0,
                'target_reached' => false,
            ];
        }

        if ($useDynamicWindowProgress) {
            $baselineRaw = $goal[$baselineField] ?? null;
            if (!is_numeric($baselineRaw) || abs((float) $baselineRaw) > 0.00001) {
                if ($goalId > 0) {
                    db_execute(
                        $pdo,
                        'UPDATE goals
                         SET ' . $baselineField . ' = 0,
                             updated_at = :updated_at
                         WHERE id = :id',
                        [
                            ':updated_at' => now_iso(),
                            ':id' => $goalId,
                        ]
                    );
                }
            }
            $goal[$baselineField] = 0.0;
        }

        if (!is_numeric($goal[$baselineField] ?? null)) {
            $baselineValue = $currentMetricValue;
            if (!$useDynamicWindowProgress) {
                $baselineValue = goal_team_baseline_from_start(
                    $pdo,
                    [
                        'target_type' => $targetType,
                        'start_date' => $goal['start_date'] ?? null,
                        'start_time' => $goal['start_time'] ?? null,
                    ],
                    $resolveTeamUsers(),
                    $currentMetricValue,
                    $nowDateTime
                );
            }

            if ($goalId > 0) {
                db_execute(
                    $pdo,
                    'UPDATE goals
                     SET ' . $baselineField . ' = :baseline_value,
                         ' . $currentField . ' = 0,
                         updated_at = :updated_at
                     WHERE id = :id AND ' . $baselineField . ' IS NULL',
                    [
                        ':baseline_value' => $baselineValue,
                        ':updated_at' => now_iso(),
                        ':id' => $goalId,
                    ]
                );
            }
            $goal[$baselineField] = $baselineValue;
        }

        $baselineValue = is_numeric($goal[$baselineField] ?? null)
            ? (float) $goal[$baselineField]
            : $currentMetricValue;
        $progressValue = goal_progress_from_baseline_for_type($targetType, $baselineValue, $currentMetricValue);
        $progressPctRaw = $targetValue > 0 ? round(($progressValue / $targetValue) * 100, 1) : 0.0;

        return [
            'target_type' => $targetType,
            'target_value' => $targetValue,
            'current_metric_value' => $currentMetricValue,
            'baseline_value' => $baselineValue,
            'progress_value' => $progressValue,
            'progress_pct_raw' => $progressPctRaw,
            'target_reached' => $targetValue > 0 && $progressValue >= $targetValue,
        ];
    };

    $primaryState = $resolveObjective('target_type', 'target_value', 'baseline_value', 'current_value');
    $secondaryState = goal_has_secondary_target($goal)
        ? $resolveObjective('secondary_target_type', 'secondary_target_value', 'secondary_baseline_value', 'secondary_current_value')
        : null;

    $combinedProgressPctRaw = (float) ($primaryState['progress_pct_raw'] ?? 0.0);
    $targetReached = !empty($primaryState['target_reached']);
    if (is_array($secondaryState)) {
        $combinedProgressPctRaw = round(
            ($combinedProgressPctRaw + (float) ($secondaryState['progress_pct_raw'] ?? 0.0)) / 2,
            1
        );
        $targetReached = $targetReached && !empty($secondaryState['target_reached']);
    }

    return [
        'goal' => $goal,
        'has_started' => $hasStarted,
        'start_at' => $startAt,
        'current_metric_value' => (float) ($primaryState['current_metric_value'] ?? 0.0),
        'progress_value' => (float) ($primaryState['progress_value'] ?? 0.0),
        'progress_pct_raw' => $combinedProgressPctRaw,
        'progress_pct_visual' => max(0.0, min(100.0, $combinedProgressPctRaw)),
        'target_reached' => $targetReached,
        'primary' => $primaryState,
        'secondary' => $secondaryState,
    ];
}

function goal_target_reached_from_progress(array $goal, float $progressValue): bool
{
    $target = (float) ($goal['target_value'] ?? 0);
    if ($target <= 0) {
        return false;
    }

    return $progressValue >= $target;
}

function goal_target_reached(array $goal, array $metric, float $progressValue): bool
{
    $type = normalize_goal_target_type((string) ($goal['target_type'] ?? 'custom'));
    $target = (float) ($goal['target_value'] ?? 0);
    if ($target <= 0) {
        return false;
    }

    if ($type === 'weight') {
        $latestWeight = (float) ($metric['latest_weight'] ?? 0);
        if ($latestWeight <= 0) {
            return false;
        }

        $firstWeight = $metric['first_weight'] ?? null;
        if (is_numeric($firstWeight)) {
            $initialWeight = (float) $firstWeight;
            if ($initialWeight > $target) {
                return $latestWeight <= $target;
            }
            if ($initialWeight < $target) {
                return $latestWeight >= $target;
            }
        }

        return $latestWeight <= $target;
    }

    if (in_array($type, ['strikes', 'penalties', 'calories_consumed'], true)) {
        return $progressValue <= $target;
    }

    return $progressValue >= $target;
}

function goal_target_label_for_type(string $rawType, array $habitLabels = []): string
{
    $type = normalize_goal_target_type($rawType);

    return match (true) {
        $type === 'steps' => (string) t('metric.steps'),
        $type === 'km' => (string) t('metric.distance_km'),
        $type === 'workouts' => (string) t('metric.workouts'),
        $type === 'weight' => (string) t('metric.weight'),
        str_starts_with($type, 'habit:') => $habitLabels[substr($type, 6)] ?? $type,
        default => $rawType !== '' ? $rawType : (string) t('common.other'),
    };
}

function format_goal_display_value(float $value, string $rawType): string
{
    $type = normalize_goal_target_type($rawType);
    $decimals = ($type === 'steps' || $type === 'workouts' || str_starts_with($type, 'habit:')) ? 0 : 1;

    return number_format($value, $decimals, '.', '');
}

function goal_progress_percent_for_metric(array $goal, float $currentValue, array $metric = []): float
{
    $type = normalize_goal_target_type((string) ($goal['target_type'] ?? 'custom'));
    $targetValue = (float) ($goal['target_value'] ?? 0);
    if ($targetValue <= 0) {
        return 0.0;
    }

    if ($type === 'weight') {
        $startWeight = $metric['first_weight'] ?? null;
        if (is_numeric($startWeight)) {
            $start = (float) $startWeight;
            if ($start > $targetValue) {
                $denominator = max(0.001, $start - $targetValue);
                return max(0.0, min(100.0, round((($start - $currentValue) / $denominator) * 100, 1)));
            }
            if ($start < $targetValue) {
                $denominator = max(0.001, $targetValue - $start);
                return max(0.0, min(100.0, round((($currentValue - $start) / $denominator) * 100, 1)));
            }

            return abs($currentValue - $targetValue) < 0.0001 ? 100.0 : 0.0;
        }

        return $currentValue <= $targetValue
            ? 100.0
            : max(0.0, min(100.0, round(($targetValue / max(0.001, $currentValue)) * 100, 1)));
    }

    if (in_array($type, ['strikes', 'penalties'], true)) {
        if ($currentValue <= $targetValue) {
            return 100.0;
        }

        return max(0.0, round((1 - (($currentValue - $targetValue) / max(0.001, $targetValue))) * 100, 1));
    }

    return max(0.0, min(100.0, round(($currentValue / $targetValue) * 100, 1)));
}

function build_user_goal_view_models(array $goals, array $metric = [], array $habits = []): array
{
    $habitLabels = [];
    foreach ($habits as $habit) {
        if (is_array($habit)) {
            $habitLabels[(string) ($habit['code'] ?? '')] = (string) ($habit['label'] ?? $habit['code'] ?? '');
        }
    }

    $rows = [];
    foreach ($goals as $goal) {
        if (!is_array($goal)) {
            continue;
        }
        $type = normalize_goal_target_type((string) ($goal['target_type'] ?? 'custom'));
        $target = (float) ($goal['target_value'] ?? 0);
        $current = $metric !== [] ? goal_progress_value_from_metric($goal, $metric) : (float) ($goal['current_value'] ?? 0);
        $progress = goal_progress_percent_for_metric($goal, $current, $metric);
        $dueDate = trim((string) ($goal['due_date'] ?? ''));
        $status = (string) ($goal['status'] ?? 'active');
        $rows[] = [
            'id' => (int) ($goal['id'] ?? 0),
            'title' => (string) ($goal['title'] ?? ''),
            'status' => $status,
            'status_label' => match ($status) {
                'complete' => (string) t('common.complete'),
                'archived' => (string) t('goals.archive'),
                default => (string) t('common.active'),
            },
            'type' => $type,
            'type_label' => goal_target_label_for_type((string) ($goal['target_type'] ?? ''), $habitLabels),
            'current' => $current,
            'current_label' => format_goal_display_value($current, $type),
            'target' => $target,
            'target_label' => format_goal_display_value($target, $type),
            'progress_pct' => $progress,
            'due_date' => $dueDate,
            'due_label' => $dueDate !== '' ? format_date_eu($dueDate) : '',
        ];
    }

    return $rows;
}

function auto_complete_user_goals(PDO $pdo, int $userId, string $startDate, string $endDate, ?int $actorUserId = null): int
{
    if ($userId <= 0) {
        return 0;
    }

    $user = db_fetch_one(
        $pdo,
        'SELECT * FROM users WHERE id = :id AND active = 1',
        [':id' => $userId]
    );
    if ($user === null) {
        return 0;
    }

    $activeGoals = db_fetch_all(
        $pdo,
        'SELECT * FROM goals WHERE scope = "user" AND user_id = :user_id AND status = "active" ORDER BY created_at ASC',
        [':user_id' => $userId]
    );
    if ($activeGoals === []) {
        return 0;
    }

    $metricsByUser = compute_challenge_metrics($pdo, [$user], $startDate, $endDate);
    $metricsByUser = apply_strike_review_overrides_to_metrics($pdo, $metricsByUser);
    $metric = $metricsByUser[$userId] ?? array_values($metricsByUser)[0] ?? null;
    if (!is_array($metric)) {
        return 0;
    }

    $completedCount = 0;
    foreach ($activeGoals as $goal) {
        $progressValue = goal_progress_value_from_metric($goal, $metric);
        if (!goal_target_reached($goal, $metric, $progressValue)) {
            continue;
        }

        db_execute(
            $pdo,
            'UPDATE goals
             SET status = "complete",
                 current_value = :current_value,
                 completed_at = COALESCE(completed_at, :completed_at),
                 updated_at = :updated_at
             WHERE id = :id AND status = "active"',
            [
                ':current_value' => round($progressValue, 2),
                ':completed_at' => now_iso(),
                ':updated_at' => now_iso(),
                ':id' => (int) $goal['id'],
            ]
        );

        $after = db_fetch_one($pdo, 'SELECT * FROM goals WHERE id = :id', [':id' => (int) $goal['id']]);
        if ($after === null || (string) ($after['status'] ?? '') !== 'complete') {
            continue;
        }

        $completedCount++;
        audit_log(
            $pdo,
            $actorUserId,
            'goal_complete_auto',
            'goal',
            (string) ((int) $goal['id']),
            'Goal auto-completed from progress metrics.',
            audit_snapshot($goal),
            audit_snapshot($after)
        );
    }

    return $completedCount;
}

function list_workout_types(PDO $pdo, bool $activeOnly = true): array
{
    $where = $activeOnly ? 'WHERE active = 1' : '';

    return db_fetch_all($pdo, 'SELECT * FROM workout_types ' . $where . ' ORDER BY active DESC, LOWER(name) ASC');
}

function list_workout_type_fields(PDO $pdo, ?int $workoutTypeId = null, bool $activeOnly = true): array
{
    $conditions = [];
    $params = [];
    if ($workoutTypeId !== null && $workoutTypeId > 0) {
        $conditions[] = 'workout_type_id = :workout_type_id';
        $params[':workout_type_id'] = $workoutTypeId;
    }
    if ($activeOnly) {
        $conditions[] = 'active = 1';
    }
    $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';

    return db_fetch_all(
        $pdo,
        'SELECT * FROM workout_type_fields ' . $where . ' ORDER BY workout_type_id ASC, active DESC, sort_order ASC, id ASC',
        $params
    );
}

function list_workout_type_fields_grouped(PDO $pdo, bool $activeOnly = true): array
{
    $grouped = [];
    foreach (list_workout_type_fields($pdo, null, $activeOnly) as $field) {
        $typeId = (int) ($field['workout_type_id'] ?? 0);
        if ($typeId <= 0) {
            continue;
        }
        if (!isset($grouped[$typeId])) {
            $grouped[$typeId] = [];
        }
        $grouped[$typeId][] = $field;
    }

    return $grouped;
}

function save_workout_type_field(
    PDO $pdo,
    int $workoutTypeId,
    ?int $fieldId,
    string $label,
    string $inputKind,
    string $dataKey,
    bool $required,
    bool $active,
    int $sortOrder,
    int $actorUserId
): void {
    $workoutType = db_fetch_one($pdo, 'SELECT * FROM workout_types WHERE id = :id', [':id' => $workoutTypeId]);
    if ($workoutType === null) {
        throw new InvalidArgumentException('Workout type not found.');
    }
    $label = trim($label);
    if ($label === '') {
        throw new InvalidArgumentException('Field label is required.');
    }
    $inputKind = normalize_workout_field_input_kind($inputKind);
    $dataKey = normalize_workout_field_data_key($dataKey);
    if ($dataKey !== '') {
        $inputKind = 'number';
    }
    $sortOrder = max(0, $sortOrder);
    $now = now_iso();

    if ($fieldId !== null && $fieldId > 0) {
        $before = db_fetch_one($pdo, 'SELECT * FROM workout_type_fields WHERE id = :id AND workout_type_id = :workout_type_id', [':id' => $fieldId, ':workout_type_id' => $workoutTypeId]);
        if ($before === null) {
            throw new InvalidArgumentException('Workout field not found.');
        }
        db_execute(
            $pdo,
            'UPDATE workout_type_fields
             SET label = :label,
                 input_kind = :input_kind,
                 data_key = :data_key,
                 required = :required,
                 active = :active,
                 sort_order = :sort_order,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                ':label' => $label,
                ':input_kind' => $inputKind,
                ':data_key' => $dataKey !== '' ? $dataKey : null,
                ':required' => $required ? 1 : 0,
                ':active' => $active ? 1 : 0,
                ':sort_order' => $sortOrder,
                ':updated_at' => $now,
                ':id' => $fieldId,
            ]
        );
        $after = db_fetch_one($pdo, 'SELECT * FROM workout_type_fields WHERE id = :id', [':id' => $fieldId]);
        audit_log($pdo, $actorUserId, 'workout_type_field_updated', 'workout_type_field', (string) $fieldId, 'Workout type field updated.', audit_snapshot($before), audit_snapshot($after));
        return;
    }

    db_execute(
        $pdo,
        'INSERT INTO workout_type_fields (
            workout_type_id, label, input_kind, data_key, required, active, sort_order, created_by, created_at, updated_at
        ) VALUES (
            :workout_type_id, :label, :input_kind, :data_key, :required, :active, :sort_order, :created_by, :created_at, :updated_at
        )',
        [
            ':workout_type_id' => $workoutTypeId,
            ':label' => $label,
            ':input_kind' => $inputKind,
            ':data_key' => $dataKey !== '' ? $dataKey : null,
            ':required' => $required ? 1 : 0,
            ':active' => $active ? 1 : 0,
            ':sort_order' => $sortOrder,
            ':created_by' => $actorUserId,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]
    );
    $created = db_fetch_one($pdo, 'SELECT * FROM workout_type_fields WHERE id = last_insert_rowid()');
    audit_log($pdo, $actorUserId, 'workout_type_field_created', 'workout_type_field', (string) ($created['id'] ?? ''), 'Workout type field created.', null, audit_snapshot($created));
}

function delete_workout_type_field(PDO $pdo, int $fieldId, int $actorUserId): void
{
    $before = db_fetch_one($pdo, 'SELECT * FROM workout_type_fields WHERE id = :id', [':id' => $fieldId]);
    if ($before === null) {
        return;
    }
    db_execute($pdo, 'UPDATE workout_type_fields SET active = 0, updated_at = :updated_at WHERE id = :id', [':updated_at' => now_iso(), ':id' => $fieldId]);
    $after = db_fetch_one($pdo, 'SELECT * FROM workout_type_fields WHERE id = :id', [':id' => $fieldId]);
    audit_log($pdo, $actorUserId, 'workout_type_field_deactivated', 'workout_type_field', (string) $fieldId, 'Workout type field deactivated.', audit_snapshot($before), audit_snapshot($after));
}

function save_workout_type_if_needed(PDO $pdo, string $name, ?int $actorUserId = null): ?int
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }

    $existing = db_fetch_one($pdo, 'SELECT * FROM workout_types WHERE LOWER(name) = LOWER(:name)', [':name' => $name]);
    if ($existing !== null) {
        if ((int) $existing['active'] !== 1) {
            db_execute(
                $pdo,
                'UPDATE workout_types SET active = 1, updated_at = :updated_at WHERE id = :id',
                [':updated_at' => now_iso(), ':id' => (int) $existing['id']]
            );
            $after = db_fetch_one($pdo, 'SELECT * FROM workout_types WHERE id = :id', [':id' => (int) $existing['id']]);
            audit_log($pdo, $actorUserId, 'workout_type_reactivated', 'workout_type', (string) $existing['id'], 'Workout type reactivated from daily log.', audit_snapshot($existing), audit_snapshot($after));
        }
        return (int) $existing['id'];
    }

    db_execute(
        $pdo,
        'INSERT INTO workout_types (name, active, created_by, created_at, updated_at)
         VALUES (:name, 1, :created_by, :created_at, :updated_at)',
        [
            ':name' => $name,
            ':created_by' => $actorUserId,
            ':created_at' => now_iso(),
            ':updated_at' => now_iso(),
        ]
    );
    $created = db_fetch_one($pdo, 'SELECT * FROM workout_types WHERE LOWER(name) = LOWER(:name)', [':name' => $name]);
    audit_log($pdo, $actorUserId, 'workout_type_created', 'workout_type', (string) ($created['id'] ?? ''), 'Workout type created from daily log.', null, audit_snapshot($created));

    return $created !== null ? (int) $created['id'] : null;
}

function deactivate_workout_type(PDO $pdo, int $typeId, int $actorUserId): void
{
    $before = db_fetch_one($pdo, 'SELECT * FROM workout_types WHERE id = :id', [':id' => $typeId]);
    if ($before === null) {
        return;
    }

    db_execute($pdo, 'UPDATE workout_types SET active = 0, updated_at = :updated_at WHERE id = :id', [':updated_at' => now_iso(), ':id' => $typeId]);
    $after = db_fetch_one($pdo, 'SELECT * FROM workout_types WHERE id = :id', [':id' => $typeId]);
    audit_log($pdo, $actorUserId, 'workout_type_deactivated', 'workout_type', (string) $typeId, 'Workout type deactivated.', audit_snapshot($before), audit_snapshot($after));
}

function rename_workout_type(PDO $pdo, int $typeId, string $name, bool $active, int $actorUserId): void
{
    $name = trim($name);
    if ($name === '') {
        return;
    }
    $before = db_fetch_one($pdo, 'SELECT * FROM workout_types WHERE id = :id', [':id' => $typeId]);
    if ($before === null) {
        return;
    }

    db_execute(
        $pdo,
        'UPDATE workout_types SET name = :name, active = :active, updated_at = :updated_at WHERE id = :id',
        [':name' => $name, ':active' => $active ? 1 : 0, ':updated_at' => now_iso(), ':id' => $typeId]
    );
    $after = db_fetch_one($pdo, 'SELECT * FROM workout_types WHERE id = :id', [':id' => $typeId]);
    audit_log($pdo, $actorUserId, 'workout_type_updated', 'workout_type', (string) $typeId, 'Workout type updated.', audit_snapshot($before), audit_snapshot($after));
}

function delete_workout_type(PDO $pdo, int $typeId, int $actorUserId): void
{
    deactivate_workout_type($pdo, $typeId, $actorUserId);
}

function app_setting(PDO $pdo, string $key, ?string $default = null): ?string
{
    $row = db_fetch_one($pdo, 'SELECT setting_value FROM app_settings WHERE setting_key = :key', [':key' => $key]);

    return $row !== null ? (string) $row['setting_value'] : $default;
}

function set_app_setting(PDO $pdo, string $key, ?string $value, int $actorUserId): void
{
    $before = db_fetch_one($pdo, 'SELECT * FROM app_settings WHERE setting_key = :key', [':key' => $key]);
    db_execute(
        $pdo,
        'INSERT INTO app_settings (setting_key, setting_value, updated_by, updated_at)
         VALUES (:key, :value, :updated_by, :updated_at)
         ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value, updated_by = excluded.updated_by, updated_at = excluded.updated_at',
        [':key' => $key, ':value' => $value, ':updated_by' => $actorUserId, ':updated_at' => now_iso()]
    );
    $after = db_fetch_one($pdo, 'SELECT * FROM app_settings WHERE setting_key = :key', [':key' => $key]);
    audit_log($pdo, $actorUserId, 'app_setting_updated', 'app_setting', $key, 'App setting updated.', audit_snapshot($before), audit_snapshot($after));
}

function penalties_enabled(PDO $pdo): bool
{
    $value = strtolower(trim((string) (app_setting($pdo, 'penalties_enabled', '0') ?? '0')));

    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function update_challenge_settings(PDO $pdo, string $name, string $start, string $end, int $actorUserId): void
{
    $name = trim($name) !== '' ? trim($name) : 'Fitness Challenge';
    $start = to_date($start);
    $end = to_date($end, $start);
    if ($end < $start) {
        $end = $start;
    }

    $before = db_fetch_one($pdo, 'SELECT * FROM challenge_settings WHERE id = 1');
    db_execute(
        $pdo,
        'INSERT INTO challenge_settings (id, challenge_name, challenge_start, challenge_end, active, deleted_at, created_at, updated_at)
         VALUES (1, :name, :start, :end, 1, NULL, :created_at, :updated_at)
         ON CONFLICT(id) DO UPDATE SET challenge_name = excluded.challenge_name, challenge_start = excluded.challenge_start, challenge_end = excluded.challenge_end, active = 1, deleted_at = NULL, updated_at = excluded.updated_at',
        [
            ':name' => $name,
            ':start' => $start,
            ':end' => $end,
            ':created_at' => now_iso(),
            ':updated_at' => now_iso(),
        ]
    );
    $after = db_fetch_one($pdo, 'SELECT * FROM challenge_settings WHERE id = 1');
    audit_log($pdo, $actorUserId, 'challenge_settings_updated', 'challenge_settings', '1', 'Challenge settings updated.', audit_snapshot($before), audit_snapshot($after));
}

function start_new_challenge(PDO $pdo, string $name, string $start, string $end, int $actorUserId): void
{
    $current = db_fetch_one($pdo, 'SELECT * FROM challenge_settings WHERE id = 1');
    if ($current !== null && (int) ($current['active'] ?? 1) === 1 && empty($current['deleted_at'])) {
        archive_challenge($pdo, $actorUserId);
    }

    update_challenge_settings($pdo, $name, $start, $end, $actorUserId);
}

function backfill_challenge_archives_from_audit(PDO $pdo): void
{
    if (app_setting($pdo, 'challenge_archives_backfilled') === '1') {
        return;
    }

    $existingCount = (int) ((db_fetch_one($pdo, 'SELECT COUNT(*) AS total FROM challenge_archives')['total'] ?? 0));
    if ($existingCount > 0) {
        set_app_setting_silent($pdo, 'challenge_archives_backfilled', '1');
        return;
    }

    $rows = db_fetch_all(
        $pdo,
        'SELECT * FROM audit_logs
         WHERE action = "challenge_archived"
         ORDER BY created_at ASC'
    );
    if ($rows === []) {
        set_app_setting_silent($pdo, 'challenge_archives_backfilled', '1');
        return;
    }

    foreach ($rows as $row) {
        $before = json_decode((string) ($row['before_json'] ?? ''), true);
        $after = json_decode((string) ($row['after_json'] ?? ''), true);
        $snapshot = is_array($before) && $before !== [] ? $before : (is_array($after) ? $after : []);
        $name = trim((string) ($snapshot['challenge_name'] ?? ''));
        $start = trim((string) ($snapshot['challenge_start'] ?? ''));
        $end = trim((string) ($snapshot['challenge_end'] ?? ''));
        if ($name === '' || $start === '' || $end === '') {
            continue;
        }

        $archivedAt = trim((string) ($row['created_at'] ?? ''));
        if ($archivedAt === '') {
            $archivedAt = now_iso();
        }

        db_execute(
            $pdo,
            'INSERT INTO challenge_archives (
                challenge_name, challenge_start, challenge_end, archived_at, archived_by, source_settings_json, created_at
            ) VALUES (
                :challenge_name, :challenge_start, :challenge_end, :archived_at, :archived_by, :source_settings_json, :created_at
            )',
            [
                ':challenge_name' => $name,
                ':challenge_start' => $start,
                ':challenge_end' => $end,
                ':archived_at' => $archivedAt,
                ':archived_by' => isset($row['actor_user_id']) ? (int) $row['actor_user_id'] : null,
                ':source_settings_json' => json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':created_at' => $archivedAt,
            ]
        );
    }

    set_app_setting_silent($pdo, 'challenge_archives_backfilled', '1');
}

function list_challenge_archives(PDO $pdo): array
{
    backfill_challenge_archives_from_audit($pdo);

    return db_fetch_all(
        $pdo,
        'SELECT ca.*, u.display_name AS archived_by_name
         FROM challenge_archives ca
         LEFT JOIN users u ON u.id = ca.archived_by
         ORDER BY ca.archived_at DESC, ca.id DESC'
    );
}

function archive_challenge(PDO $pdo, int $actorUserId): void
{
    $before = db_fetch_one($pdo, 'SELECT * FROM challenge_settings WHERE id = 1');
    if ($before === null) {
        return;
    }

    $wasActive = (int) ($before['active'] ?? 1) === 1 && empty($before['deleted_at']);
    if ($wasActive) {
        $archivedAt = now_iso();
        db_execute(
            $pdo,
            'INSERT INTO challenge_archives (
                challenge_name, challenge_start, challenge_end, archived_at, archived_by, source_settings_json, created_at
            ) VALUES (
                :challenge_name, :challenge_start, :challenge_end, :archived_at, :archived_by, :source_settings_json, :created_at
            )',
            [
                ':challenge_name' => (string) ($before['challenge_name'] ?? 'Fitness Challenge'),
                ':challenge_start' => (string) ($before['challenge_start'] ?? to_date(null)),
                ':challenge_end' => (string) ($before['challenge_end'] ?? to_date(null)),
                ':archived_at' => $archivedAt,
                ':archived_by' => $actorUserId,
                ':source_settings_json' => json_encode($before, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':created_at' => $archivedAt,
            ]
        );
    }

    db_execute(
        $pdo,
        'UPDATE challenge_settings SET active = 0, deleted_at = :deleted_at, updated_at = :updated_at WHERE id = 1',
        [':deleted_at' => now_iso(), ':updated_at' => now_iso()]
    );
    $after = db_fetch_one($pdo, 'SELECT * FROM challenge_settings WHERE id = 1');
    audit_log($pdo, $actorUserId, 'challenge_archived', 'challenge_settings', '1', 'Challenge archived.', audit_snapshot($before), audit_snapshot($after));
}

function reactivate_challenge(PDO $pdo, int $archiveId, int $actorUserId): bool
{
    $archive = db_fetch_one($pdo, 'SELECT * FROM challenge_archives WHERE id = :id', [':id' => $archiveId]);
    if ($archive === null) {
        return false;
    }

    $current = db_fetch_one($pdo, 'SELECT * FROM challenge_settings WHERE id = 1');
    if ($current !== null && (int) ($current['active'] ?? 1) === 1 && empty($current['deleted_at'])) {
        archive_challenge($pdo, $actorUserId);
    }

    update_challenge_settings(
        $pdo,
        (string) ($archive['challenge_name'] ?? 'Fitness Challenge'),
        (string) ($archive['challenge_start'] ?? to_date(null)),
        (string) ($archive['challenge_end'] ?? to_date(null)),
        $actorUserId
    );

    db_execute($pdo, 'DELETE FROM challenge_archives WHERE id = :id', [':id' => $archiveId]);

    audit_log(
        $pdo,
        $actorUserId,
        'challenge_reactivated',
        'challenge_settings',
        '1',
        'Challenge reactivated from archive.',
        audit_snapshot($archive),
        audit_snapshot(db_fetch_one($pdo, 'SELECT * FROM challenge_settings WHERE id = 1'))
    );

    return true;
}

function achievement_supported_locales(): array
{
    if (defined('SUPPORTED_LOCALES')) {
        $locales = constant('SUPPORTED_LOCALES');
        if (is_array($locales) && $locales !== []) {
            return array_values(array_filter(array_map('strval', $locales)));
        }
    }

    return ['en', 'es', 'it'];
}

function normalize_achievement_translations_input(mixed $rawTranslations, string $fallbackName, string $fallbackDescription = '', string $fallbackRewardText = ''): array
{
    $incoming = is_array($rawTranslations) ? $rawTranslations : [];
    $fallbackName = trim($fallbackName);
    $fallbackDescription = trim($fallbackDescription);
    $fallbackRewardText = trim($fallbackRewardText);
    $translations = [];

    foreach (achievement_supported_locales() as $locale) {
        $submittedLocale = array_key_exists($locale, $incoming);
        $row = is_array($incoming[$locale] ?? null) ? (array) $incoming[$locale] : [];
        $name = trim((string) ($row['name'] ?? ''));
        $description = trim((string) ($row['description'] ?? ''));
        $rewardText = trim((string) ($row['reward_text'] ?? ''));

        if ($locale === 'en') {
            $name = $name !== '' ? $name : $fallbackName;
            $description = $description !== '' ? $description : $fallbackDescription;
            $rewardText = $rewardText !== '' ? $rewardText : $fallbackRewardText;
        }

        if ($name === '' && $description === '' && $rewardText === '') {
            if (!$submittedLocale) {
                continue;
            }
        }

        $translations[$locale] = [
            'name' => $name,
            'description' => $description,
            'reward_text' => $rewardText,
        ];
    }

    if (!isset($translations['en']) || trim((string) ($translations['en']['name'] ?? '')) === '') {
        if ($fallbackName === '') {
            throw new InvalidArgumentException('Achievement name is required.');
        }
        $translations['en'] = [
            'name' => $fallbackName,
            'description' => $fallbackDescription,
            'reward_text' => $fallbackRewardText,
        ];
    }

    return $translations;
}

function save_achievement_translations(PDO $pdo, int $achievementId, array $translations): void
{
    if ($achievementId <= 0) {
        return;
    }

    $now = now_iso();
    foreach ($translations as $locale => $translation) {
        $locale = normalize_locale((string) $locale, 'en');
        $name = trim((string) ($translation['name'] ?? ''));
        $description = trim((string) ($translation['description'] ?? ''));
        $rewardText = trim((string) ($translation['reward_text'] ?? ''));
        if ($name === '' && $description === '' && $rewardText === '') {
            db_execute(
                $pdo,
                'DELETE FROM achievement_translations WHERE achievement_id = :achievement_id AND locale = :locale AND locale != "en"',
                [':achievement_id' => $achievementId, ':locale' => $locale]
            );
            continue;
        }
        if ($name === '') {
            continue;
        }

        db_execute(
            $pdo,
            'INSERT INTO achievement_translations (achievement_id, locale, name, description, reward_text, created_at, updated_at)
             VALUES (:achievement_id, :locale, :name, :description, :reward_text, :created_at, :updated_at)
             ON CONFLICT(achievement_id, locale) DO UPDATE SET
                name = excluded.name,
                description = excluded.description,
                reward_text = excluded.reward_text,
                updated_at = excluded.updated_at',
            [
                ':achievement_id' => $achievementId,
                ':locale' => $locale,
                ':name' => $name,
                ':description' => $description,
                ':reward_text' => $rewardText,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]
        );
    }
}

function fetch_achievement_translations(PDO $pdo, array $achievementIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $achievementIds), static fn(int $id): bool => $id > 0)));
    if ($ids === []) {
        return [];
    }

    $params = [];
    $placeholders = [];
    foreach ($ids as $index => $id) {
        $key = ':achievement_id_' . $index;
        $placeholders[] = $key;
        $params[$key] = $id;
    }

    $rows = db_fetch_all(
        $pdo,
        'SELECT achievement_id, locale, name, description, reward_text
         FROM achievement_translations
         WHERE achievement_id IN (' . implode(',', $placeholders) . ')',
        $params
    );

    $grouped = [];
    foreach ($rows as $row) {
        $achievementId = (int) ($row['achievement_id'] ?? 0);
        $locale = normalize_locale((string) ($row['locale'] ?? 'en'), 'en');
        if ($achievementId <= 0) {
            continue;
        }
        if (!isset($grouped[$achievementId])) {
            $grouped[$achievementId] = [];
        }
        $grouped[$achievementId][$locale] = [
            'name' => (string) ($row['name'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'reward_text' => (string) ($row['reward_text'] ?? ''),
        ];
    }

    return $grouped;
}

function localize_achievement_rows(PDO $pdo, array $rows, ?string $locale = null): array
{
    if ($rows === []) {
        return [];
    }

    $locale = normalize_locale($locale ?? current_locale(), 'en');
    $ids = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $achievementId = (int) ($row['achievement_id'] ?? $row['id'] ?? 0);
        if ($achievementId > 0) {
            $ids[] = $achievementId;
        }
    }
    $translations = fetch_achievement_translations($pdo, $ids);

    foreach ($rows as &$row) {
        if (!is_array($row)) {
            continue;
        }
        $achievementId = (int) ($row['achievement_id'] ?? $row['id'] ?? 0);
        $byLocale = (array) ($translations[$achievementId] ?? []);
        $row['translations_by_locale'] = $byLocale;
        foreach (['name', 'description', 'reward_text'] as $field) {
            $base = (string) ($row[$field] ?? '');
            $localized = trim((string) ($byLocale[$locale][$field] ?? ''));
            if ($localized === '') {
                $localized = trim((string) ($byLocale['en'][$field] ?? ''));
            }
            if ($localized !== '') {
                $row[$field] = $localized;
            } else {
                $row[$field] = $base;
            }
        }
    }
    unset($row);

    return $rows;
}

function list_achievements(PDO $pdo, bool $activeOnly = true): array
{
    $where = $activeOnly ? 'WHERE active = 1' : '';

    return localize_achievement_rows($pdo, db_fetch_all($pdo, 'SELECT * FROM achievements ' . $where . ' ORDER BY scope ASC, name ASC'));
}

function list_achievements_for_admin(PDO $pdo): array
{
    return localize_achievement_rows($pdo, db_fetch_all(
        $pdo,
        'SELECT a.*,
                ar.id AS rule_id,
                ar.metric_key,
                ar.operator AS trigger_operator,
                ar.target_value AS trigger_target,
                ar."window" AS trigger_window,
                ar.active AS rule_active
         FROM achievements a
          LEFT JOIN achievement_rules ar ON ar.id = (
             SELECT rr.id
             FROM achievement_rules rr
             WHERE rr.achievement_id = a.id AND rr.active = 1
             ORDER BY rr.id DESC
             LIMIT 1
          )
          WHERE a.active = 1
          ORDER BY a.created_at DESC, a.name ASC'
    ));
}

function resolve_unique_achievement_code(PDO $pdo, string $seed, ?int $excludeId = null): string
{
    $raw = trim(strtolower($seed));
    $raw = preg_replace('/[^a-z0-9]+/', '_', $raw) ?: '';
    $raw = trim($raw, '_');
    if ($raw === '') {
        $raw = 'achievement';
    }
    if (preg_match('/^[a-z0-9_]+$/', $raw) !== 1) {
        throw new InvalidArgumentException('Invalid achievement code.');
    }

    $base = $raw;
    $suffix = 2;
    while (true) {
        $params = [':code' => $raw];
        $sql = 'SELECT id FROM achievements WHERE code = :code';
        if ($excludeId !== null) {
            $sql .= ' AND id != :exclude_id';
            $params[':exclude_id'] = $excludeId;
        }
        if (db_fetch_one($pdo, $sql . ' LIMIT 1', $params) === null) {
            return $raw;
        }
        $raw = $base . '_' . $suffix;
        $suffix++;
    }
}

function create_manual_achievement(
    PDO $pdo,
    string $name,
    string $description,
    string $scope,
    int $actorUserId,
    ?string $imagePath = null,
    string $rewardText = '',
    ?string $code = null,
    bool $active = true,
    ?string $triggerKey = null,
    ?string $iconKey = null,
    array $translations = []
): int
{
    $name = trim($name);
    if ($name === '') {
        throw new InvalidArgumentException('Achievement name is required.');
    }

    $scope = in_array($scope, ['user', 'team'], true) ? $scope : 'user';
    $rawCode = trim((string) ($code ?? ''));
    if ($rawCode === '') {
        $rawCode = $name;
    }
    $rawCode = resolve_unique_achievement_code($pdo, $rawCode);

    $now = now_iso();
    $iconKey = normalize_achievement_icon_key($iconKey);

    db_execute(
        $pdo,
        'INSERT INTO achievements (code, name, description, scope, trigger_key, image_path, icon_key, reward_text, active, created_by, created_at, updated_at)
         VALUES (:code, :name, :description, :scope, :trigger_key, :image_path, :icon_key, :reward_text, :active, :created_by, :created_at, :updated_at)',
        [
            ':code' => $rawCode,
            ':name' => $name,
            ':description' => $description,
            ':scope' => $scope,
            ':trigger_key' => $triggerKey,
            ':image_path' => $imagePath,
            ':icon_key' => $iconKey,
            ':reward_text' => $rewardText,
            ':active' => $active ? 1 : 0,
            ':created_by' => $actorUserId,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]
    );
    $achievement = db_fetch_one($pdo, 'SELECT * FROM achievements WHERE code = :code', [':code' => $rawCode]);
    $achievementId = (int) ($achievement['id'] ?? 0);
    if ($achievementId > 0 && $translations !== []) {
        save_achievement_translations($pdo, $achievementId, $translations);
    }
    audit_log($pdo, $actorUserId, 'achievement_created', 'achievement', (string) ($achievement['id'] ?? ''), 'Achievement created.', null, audit_snapshot($achievement));

    return $achievementId;
}

function create_conditional_achievement(PDO $pdo, array $payload, int $actorUserId): void
{
    $metricKey = normalize_achievement_rule_metric(
        (string) ($payload['metric_key'] ?? 'steps'),
        (string) ($payload['habit_code'] ?? '')
    );
    $operator = normalize_achievement_rule_operator((string) ($payload['operator'] ?? '>='));
    $window = normalize_achievement_rule_window((string) ($payload['window'] ?? 'total'));
    $targetValue = (float) ($payload['target_value'] ?? 1);
    if (!is_finite($targetValue)) {
        throw new InvalidArgumentException('Invalid target amount.');
    }

    $achievementId = create_manual_achievement(
        $pdo,
        trim((string) $payload['name']),
        trim((string) ($payload['description'] ?? '')),
        (string) ($payload['scope'] ?? 'user'),
        $actorUserId,
        $payload['image_path'] ?? null,
        trim((string) ($payload['reward_text'] ?? '')),
        (string) ($payload['code'] ?? ''),
        (int) (($payload['active'] ?? 1) ? 1 : 0) === 1,
        $metricKey,
        (string) ($payload['icon_key'] ?? 'trophy'),
        is_array($payload['translations'] ?? null) ? (array) $payload['translations'] : []
    );
    if ($achievementId <= 0) {
        return;
    }

    db_execute(
        $pdo,
        'INSERT INTO achievement_rules (achievement_id, metric_key, operator, target_value, "window", active, created_at, updated_at)
         VALUES (:achievement_id, :metric_key, :operator, :target_value, :window, 1, :created_at, :updated_at)',
        [
            ':achievement_id' => $achievementId,
            ':metric_key' => $metricKey,
            ':operator' => $operator,
            ':target_value' => $targetValue,
            ':window' => $window,
            ':created_at' => now_iso(),
            ':updated_at' => now_iso(),
        ]
    );
    audit_log($pdo, $actorUserId, 'achievement_rule_created', 'achievement', (string) $achievementId, 'Achievement rule created.', null, $payload);
}

function update_achievement(PDO $pdo, int $achievementId, array $payload, int $actorUserId): void
{
    $beforeAchievement = db_fetch_one($pdo, 'SELECT * FROM achievements WHERE id = :id', [':id' => $achievementId]);
    if ($beforeAchievement === null) {
        throw new InvalidArgumentException('Achievement not found.');
    }

    $beforeRule = db_fetch_one(
        $pdo,
        'SELECT * FROM achievement_rules WHERE achievement_id = :achievement_id AND active = 1 ORDER BY id DESC LIMIT 1',
        [':achievement_id' => $achievementId]
    );

    $translations = normalize_achievement_translations_input(
        is_array($payload['translations'] ?? null) ? (array) $payload['translations'] : [],
        (string) ($payload['name'] ?? ''),
        (string) ($payload['description'] ?? ''),
        (string) ($payload['reward_text'] ?? '')
    );
    $english = $translations['en'] ?? [];
    $name = trim((string) ($english['name'] ?? $payload['name'] ?? ''));
    if ($name === '') {
        throw new InvalidArgumentException('Achievement name is required.');
    }
    $description = trim((string) ($english['description'] ?? $payload['description'] ?? ''));
    $rewardText = trim((string) ($english['reward_text'] ?? $payload['reward_text'] ?? ''));
    $scope = in_array(($payload['scope'] ?? 'user'), ['user', 'team'], true) ? (string) $payload['scope'] : 'user';
    $iconKey = normalize_achievement_icon_key((string) ($payload['icon_key'] ?? $beforeAchievement['icon_key'] ?? 'trophy'));
    $desiredCode = trim((string) ($payload['code'] ?? ''));
    if ($desiredCode === '') {
        $desiredCode = $name;
    }
    $code = resolve_unique_achievement_code($pdo, $desiredCode, $achievementId);
    $active = !empty($payload['active']);
    $conditionalEnabled = !empty($payload['conditional_enabled']);

    $triggerKey = null;
    $operator = null;
    $targetValue = null;
    $window = null;

    if ($conditionalEnabled) {
        $triggerKey = normalize_achievement_rule_metric((string) ($payload['metric_key'] ?? 'steps'), (string) ($payload['habit_code'] ?? ''));
        $operator = normalize_achievement_rule_operator((string) ($payload['operator'] ?? '>='));
        $targetValue = (float) ($payload['target_value'] ?? 1);
        if (!is_finite($targetValue)) {
            throw new InvalidArgumentException('Invalid target amount.');
        }
        $window = normalize_achievement_rule_window((string) ($payload['window'] ?? 'total'));
    }

    db_execute(
        $pdo,
        'UPDATE achievements
         SET code = :code,
             name = :name,
             description = :description,
             scope = :scope,
             trigger_key = :trigger_key,
             image_path = :image_path,
             icon_key = :icon_key,
             reward_text = :reward_text,
             active = :active,
             updated_at = :updated_at
         WHERE id = :id',
        [
            ':code' => $code,
            ':name' => $name,
            ':description' => $description,
            ':scope' => $scope,
            ':trigger_key' => $triggerKey,
            ':image_path' => $payload['image_path'] ?? null,
            ':icon_key' => $iconKey,
            ':reward_text' => $rewardText,
            ':active' => $active ? 1 : 0,
            ':updated_at' => now_iso(),
            ':id' => $achievementId,
        ]
    );
    save_achievement_translations($pdo, $achievementId, $translations);

    if ($conditionalEnabled) {
        db_execute(
            $pdo,
            'UPDATE achievement_rules SET active = 0, updated_at = :updated_at WHERE achievement_id = :achievement_id',
            [':updated_at' => now_iso(), ':achievement_id' => $achievementId]
        );
        if ($beforeRule !== null) {
            db_execute(
                $pdo,
                'UPDATE achievement_rules
                 SET metric_key = :metric_key,
                     operator = :operator,
                     target_value = :target_value,
                     "window" = :window,
                     active = 1,
                     updated_at = :updated_at
                 WHERE id = :id',
                [
                    ':metric_key' => $triggerKey,
                    ':operator' => $operator,
                    ':target_value' => $targetValue,
                    ':window' => $window,
                    ':updated_at' => now_iso(),
                    ':id' => (int) $beforeRule['id'],
                ]
            );
        } else {
            db_execute(
                $pdo,
                'INSERT INTO achievement_rules (achievement_id, metric_key, operator, target_value, "window", active, created_at, updated_at)
                 VALUES (:achievement_id, :metric_key, :operator, :target_value, :window, 1, :created_at, :updated_at)',
                [
                    ':achievement_id' => $achievementId,
                    ':metric_key' => $triggerKey,
                    ':operator' => $operator,
                    ':target_value' => $targetValue,
                    ':window' => $window,
                    ':created_at' => now_iso(),
                    ':updated_at' => now_iso(),
                ]
            );
        }
    } else {
        db_execute(
            $pdo,
            'UPDATE achievement_rules SET active = 0, updated_at = :updated_at WHERE achievement_id = :achievement_id',
            [':updated_at' => now_iso(), ':achievement_id' => $achievementId]
        );
    }

    $afterAchievement = db_fetch_one($pdo, 'SELECT * FROM achievements WHERE id = :id', [':id' => $achievementId]);
    $afterRule = db_fetch_one(
        $pdo,
        'SELECT * FROM achievement_rules WHERE achievement_id = :achievement_id AND active = 1 ORDER BY id DESC LIMIT 1',
        [':achievement_id' => $achievementId]
    );
    audit_log(
        $pdo,
        $actorUserId,
        'achievement_updated',
        'achievement',
        (string) $achievementId,
        'Achievement updated.',
        ['achievement' => audit_snapshot($beforeAchievement), 'rule' => audit_snapshot($beforeRule)],
        ['achievement' => audit_snapshot($afterAchievement), 'rule' => audit_snapshot($afterRule)]
    );
}

function deactivate_achievement(PDO $pdo, int $achievementId, int $actorUserId): void
{
    $before = db_fetch_one($pdo, 'SELECT * FROM achievements WHERE id = :id', [':id' => $achievementId]);
    if ($before === null) {
        return;
    }

    db_execute(
        $pdo,
        'UPDATE achievements SET active = 0, updated_at = :updated_at WHERE id = :id',
        [':updated_at' => now_iso(), ':id' => $achievementId]
    );
    db_execute(
        $pdo,
        'UPDATE achievement_rules SET active = 0, updated_at = :updated_at WHERE achievement_id = :achievement_id',
        [':updated_at' => now_iso(), ':achievement_id' => $achievementId]
    );
    $after = db_fetch_one($pdo, 'SELECT * FROM achievements WHERE id = :id', [':id' => $achievementId]);
    audit_log($pdo, $actorUserId, 'achievement_deactivated', 'achievement', (string) $achievementId, 'Achievement deactivated.', audit_snapshot($before), audit_snapshot($after));
}

function normalize_achievement_rule_operator(string $operator): string
{
    if ($operator === '==') {
        $operator = '=';
    }
    if (!in_array($operator, ['>=', '<=', '=', '>', '<'], true)) {
        throw new InvalidArgumentException('Invalid operator.');
    }

    return $operator;
}

function normalize_achievement_rule_window(string $window): string
{
    $window = trim(strtolower($window));
    $window = str_replace([' ', '-'], '_', $window);

    if ($window === 'currentweek') {
        $window = 'current_week';
    } elseif ($window === 'currentmonth') {
        $window = 'current_month';
    } elseif ($window === 'currentchallenge') {
        $window = 'current_challenge';
    } elseif ($window === 'month') {
        $window = 'current_month';
    } elseif ($window === 'challenge') {
        $window = 'current_challenge';
    }

    if ($window === 'week') {
        return 'current_week';
    }

    if (!in_array($window, ['total', 'current_week', 'current_month', 'current_challenge'], true)) {
        throw new InvalidArgumentException('Invalid window.');
    }

    return $window;
}

function normalize_achievement_rule_metric(string $metricKey, string $habitCode = ''): string
{
    $metricKey = trim(strtolower($metricKey));
    $metricKey = str_replace([' ', '-'], '_', $metricKey);
    $habitCode = trim(strtolower($habitCode));
    $habitCode = str_replace([' ', '-'], '_', $habitCode);
    $allowed = ['steps', 'distance_km', 'km', 'workouts', 'score', 'strikes', 'penalties', 'weight'];

    if ($metricKey === 'distance') {
        $metricKey = 'distance_km';
    }
    if ($metricKey === 'habitcompletion') {
        $metricKey = 'habit_completion';
    }

    if ($metricKey === 'habit_completion' || $metricKey === 'habit') {
        if ($habitCode === '' || preg_match('/^[a-z0-9_]+$/', $habitCode) !== 1) {
            throw new InvalidArgumentException('Invalid habit metric.');
        }

        return 'habit:' . $habitCode;
    }

    if (str_starts_with($metricKey, 'habit:')) {
        $code = substr($metricKey, 6);
        if ($code === '' || preg_match('/^[a-z0-9_]+$/', $code) !== 1) {
            throw new InvalidArgumentException('Invalid habit metric.');
        }

        return 'habit:' . $code;
    }

    if (!in_array($metricKey, $allowed, true)) {
        throw new InvalidArgumentException('Invalid metric.');
    }

    return $metricKey === 'km' ? 'distance_km' : $metricKey;
}

function is_achievement_award_suppressed(PDO $pdo, int $achievementId, ?int $userId, ?int $teamId): bool
{
    if ($userId === null && $teamId === null) {
        return false;
    }

    $suppressed = db_fetch_one(
        $pdo,
        'SELECT id
         FROM achievement_award_suppressions
         WHERE achievement_id = :achievement_id
           AND ((:user_id IS NULL AND user_id IS NULL) OR user_id = :user_id)
           AND ((:team_id IS NULL AND team_id IS NULL) OR team_id = :team_id)
         LIMIT 1',
        [
            ':achievement_id' => $achievementId,
            ':user_id' => $userId,
            ':team_id' => $teamId,
        ]
    );

    return $suppressed !== null;
}

function clear_achievement_award_suppression(PDO $pdo, int $achievementId, ?int $userId, ?int $teamId): void
{
    if ($userId === null && $teamId === null) {
        return;
    }

    db_execute(
        $pdo,
        'DELETE FROM achievement_award_suppressions
         WHERE achievement_id = :achievement_id
           AND ((:user_id IS NULL AND user_id IS NULL) OR user_id = :user_id)
           AND ((:team_id IS NULL AND team_id IS NULL) OR team_id = :team_id)',
        [
            ':achievement_id' => $achievementId,
            ':user_id' => $userId,
            ':team_id' => $teamId,
        ]
    );
}

function suppress_achievement_award(PDO $pdo, int $achievementId, ?int $userId, ?int $teamId, ?int $actorUserId, string $reason = ''): void
{
    if ($userId === null && $teamId === null) {
        return;
    }

    $now = now_iso();
    db_execute(
        $pdo,
        'INSERT OR IGNORE INTO achievement_award_suppressions (achievement_id, user_id, team_id, suppressed_by, suppressed_at, reason)
         VALUES (:achievement_id, :user_id, :team_id, :suppressed_by, :suppressed_at, :reason)',
        [
            ':achievement_id' => $achievementId,
            ':user_id' => $userId,
            ':team_id' => $teamId,
            ':suppressed_by' => $actorUserId,
            ':suppressed_at' => $now,
            ':reason' => $reason,
        ]
    );

    db_execute(
        $pdo,
        'UPDATE achievement_award_suppressions
         SET suppressed_by = :suppressed_by, suppressed_at = :suppressed_at, reason = :reason
         WHERE achievement_id = :achievement_id
           AND ((:user_id IS NULL AND user_id IS NULL) OR user_id = :user_id)
           AND ((:team_id IS NULL AND team_id IS NULL) OR team_id = :team_id)',
        [
            ':suppressed_by' => $actorUserId,
            ':suppressed_at' => $now,
            ':reason' => $reason,
            ':achievement_id' => $achievementId,
            ':user_id' => $userId,
            ':team_id' => $teamId,
        ]
    );
}

function award_achievement(PDO $pdo, int $achievementId, ?int $userId, ?int $teamId, ?int $actorUserId, string $note = '', bool $manualGrant = false): void
{
    if ($userId === null && $teamId === null) {
        return;
    }
    $achievement = db_fetch_one($pdo, 'SELECT id, active FROM achievements WHERE id = :id', [':id' => $achievementId]);
    if ($achievement === null || (int) ($achievement['active'] ?? 0) !== 1) {
        return;
    }

    if (!$manualGrant && is_achievement_award_suppressed($pdo, $achievementId, $userId, $teamId)) {
        return;
    }
    if ($manualGrant) {
        clear_achievement_award_suppression($pdo, $achievementId, $userId, $teamId);
    }

    $existing = db_fetch_one(
        $pdo,
        'SELECT id FROM achievement_awards
         WHERE achievement_id = :achievement_id
           AND ((:user_id IS NULL AND user_id IS NULL) OR user_id = :user_id)
           AND ((:team_id IS NULL AND team_id IS NULL) OR team_id = :team_id)',
        [
            ':achievement_id' => $achievementId,
            ':user_id' => $userId,
            ':team_id' => $teamId,
        ]
    );
    if ($existing !== null) {
        return;
    }

    db_execute(
        $pdo,
        'INSERT OR IGNORE INTO achievement_awards (achievement_id, user_id, team_id, awarded_by, awarded_at, note)
         VALUES (:achievement_id, :user_id, :team_id, :awarded_by, :awarded_at, :note)',
        [
            ':achievement_id' => $achievementId,
            ':user_id' => $userId,
            ':team_id' => $teamId,
            ':awarded_by' => $actorUserId,
            ':awarded_at' => now_iso(),
            ':note' => $note,
        ]
    );
    audit_log($pdo, $actorUserId, 'achievement_awarded', 'achievement', (string) $achievementId, 'Achievement awarded.', null, [
        'achievement_id' => $achievementId,
        'user_id' => $userId,
        'team_id' => $teamId,
        'note' => $note,
    ]);
}

function list_awarded_achievements(PDO $pdo, ?int $userId = null, ?int $teamId = null): array
{
    $conditions = [];
    $params = [];
    if ($userId !== null) {
        $conditions[] = 'aa.user_id = :user_id';
        $params[':user_id'] = $userId;
    }
    if ($teamId !== null) {
        $conditions[] = 'aa.team_id = :team_id';
        $params[':team_id'] = $teamId;
    }
    if ($conditions === []) {
        $conditions[] = '1 = 0';
    }

    return localize_achievement_rows($pdo, db_fetch_all(
        $pdo,
        'SELECT aa.*, aa.id AS award_id, a.name, a.description, a.scope, a.code, a.image_path, a.icon_key, a.reward_text, u.display_name AS awarded_by_name
         FROM achievement_awards aa
         JOIN achievements a ON a.id = aa.achievement_id
         LEFT JOIN users u ON u.id = aa.awarded_by
         WHERE a.active = 1 AND ' . implode(' AND ', $conditions) . '
         ORDER BY aa.awarded_at DESC',
        $params
    ));
}

function list_recent_achievement_awards(PDO $pdo, int $limit = 200): array
{
    $safeLimit = max(1, min(1000, $limit));

    return localize_achievement_rows($pdo, db_fetch_all(
        $pdo,
        'SELECT aa.*, a.name, a.description, a.scope, a.code, a.image_path, a.icon_key, a.reward_text,
                u.display_name AS awarded_by_name,
                owner.display_name AS owner_name,
                t.name AS team_name
         FROM achievement_awards aa
         JOIN achievements a ON a.id = aa.achievement_id
         LEFT JOIN users u ON u.id = aa.awarded_by
         LEFT JOIN users owner ON owner.id = aa.user_id
         LEFT JOIN teams t ON t.id = aa.team_id
         WHERE a.active = 1
         ORDER BY aa.awarded_at DESC
         LIMIT ' . $safeLimit
    ));
}

function achievement_metric_unit(string $metricKey): string
{
    $metricKey = normalize_achievement_rule_metric($metricKey);

    return match ($metricKey) {
        'steps' => 'steps',
        'distance_km' => 'km',
        'workouts' => 'workouts',
        'score' => 'score',
        'strikes' => 'strikes',
        'penalties' => 'EUR',
        'weight' => 'kg',
        default => '',
    };
}

function format_achievement_progress_number(float $value, string $unit = ''): string
{
    $absolute = abs($value);
    if ($unit === 'km' || $unit === 'kg' || $unit === 'EUR') {
        $formatted = number_format($value, 2, '.', '');
        $formatted = rtrim(rtrim($formatted, '0'), '.');
    } elseif ($absolute >= 1000 || fmod($value, 1.0) === 0.0) {
        $formatted = number_format($value, 0, '.', '');
    } else {
        $formatted = number_format($value, 1, '.', '');
        $formatted = rtrim(rtrim($formatted, '0'), '.');
    }

    if ($unit === 'EUR') {
        return '€' . $formatted;
    }

    return $unit !== '' ? $formatted . ' ' . $unit : $formatted;
}

function achievement_progress_payload(float $current, float $target, string $unit = ''): ?array
{
    if (!is_finite($target) || $target <= 0.0) {
        return null;
    }
    if (!is_finite($current)) {
        $current = 0.0;
    }

    $pct = max(0.0, min(100.0, round(($current / $target) * 100, 1)));

    return [
        'current' => $current,
        'target' => $target,
        'pct' => $pct,
        'text' => format_achievement_progress_number($current, $unit) . ' / ' . format_achievement_progress_number($target, $unit),
    ];
}

function achievement_metric_for_user(array $metricsByUser, int $userId): array
{
    if (isset($metricsByUser[$userId]) && is_array($metricsByUser[$userId])) {
        return (array) $metricsByUser[$userId];
    }

    foreach ($metricsByUser as $metric) {
        if (!is_array($metric)) {
            continue;
        }
        if ((int) ($metric['user']['id'] ?? 0) === $userId) {
            return $metric;
        }
    }

    return [];
}

function achievement_user_count(PDO $pdo, string $table, string $where, array $params): int
{
    $row = db_fetch_one($pdo, 'SELECT COUNT(*) AS total FROM ' . $table . ' WHERE ' . $where, $params);

    return (int) ($row['total'] ?? 0);
}

function achievement_user_has_complete_week_without_failures(array $metric): bool
{
    foreach ((array) ($metric['weekly'] ?? []) as $week) {
        if ((string) ($week['status'] ?? '') === 'complete' && (int) ($week['total_failures'] ?? 0) === 0) {
            return true;
        }
    }

    return false;
}

function achievement_user_has_clean_week(array $metric): bool
{
    foreach ((array) ($metric['weekly'] ?? []) as $week) {
        if ((string) ($week['status'] ?? '') === 'complete' && (float) ($week['penalty'] ?? 0) <= 0.0) {
            return true;
        }
    }

    return false;
}

function achievement_user_best_weekly_workouts(array $metric): int
{
    $best = 0;
    foreach ((array) ($metric['weekly'] ?? []) as $week) {
        $best = max($best, (int) ($week['workouts'] ?? 0));
    }

    return $best;
}

function achievement_team_best_weekly_steps(array $metricsByUser): int
{
    $byWeek = [];
    foreach ($metricsByUser as $metric) {
        foreach ((array) ($metric['weekly'] ?? []) as $week) {
            $weekStart = (string) ($week['week_start'] ?? '');
            if ($weekStart === '') {
                continue;
            }
            $byWeek[$weekStart] = ($byWeek[$weekStart] ?? 0) + (int) ($week['steps'] ?? 0);
        }
    }

    return $byWeek !== [] ? max($byWeek) : 0;
}

function achievement_progress_for_row(PDO $pdo, array $achievement, string $scope, ?int $userId, ?int $teamId, array $metricsByUser): ?array
{
    $triggerKey = trim((string) ($achievement['trigger_key'] ?? $achievement['code'] ?? ''));
    $code = trim((string) ($achievement['code'] ?? ''));
    $metricRows = $metricsByUser;
    $userMetric = [];
    if ($scope === 'user' && $userId !== null) {
        $userMetric = achievement_metric_for_user($metricsByUser, $userId);
        $metricRows = $userMetric !== [] ? [$userMetric] : [];
    }

    if (!empty($achievement['rule_id']) && (int) ($achievement['rule_active'] ?? 1) === 1) {
        try {
            $operator = normalize_achievement_rule_operator((string) ($achievement['trigger_operator'] ?? '>='));
            $target = (float) ($achievement['trigger_target'] ?? 0);
            if (!in_array($operator, ['>=', '>'], true) || $target <= 0.0) {
                return null;
            }
            $metricKey = normalize_achievement_rule_metric((string) ($achievement['metric_key'] ?? $triggerKey));
            $window = normalize_achievement_rule_window((string) ($achievement['trigger_window'] ?? 'total'));
            $current = metric_value_for_rule($metricRows, $metricKey, $window);

            return achievement_progress_payload($current, $target, achievement_metric_unit($metricKey));
        } catch (Throwable) {
            return null;
        }
    }

    if ($scope === 'user' && $userId !== null) {
        $key = $triggerKey !== '' ? $triggerKey : $code;
        return match ($key) {
            'first_log' => achievement_progress_payload(
                min(1, achievement_user_count($pdo, 'daily_logs', 'user_id = :user_id', [':user_id' => $userId])),
                1
            ),
            'first_photo' => achievement_progress_payload(
                min(1, achievement_user_count($pdo, 'photo_entries', 'user_id = :user_id', [':user_id' => $userId])),
                1
            ),
            'perfect_week' => achievement_progress_payload(achievement_user_has_complete_week_without_failures($userMetric) ? 1 : 0, 1),
            'no_strike_week' => achievement_progress_payload(achievement_user_has_clean_week($userMetric) ? 1 : 0, 1),
            'step_streak' => achievement_progress_payload((float) ($userMetric['steps_success'] ?? 0), 5, 'days'),
            'seven_day_step_streak' => achievement_progress_payload((float) ($userMetric['steps_success'] ?? 0), 7, 'days'),
            'three_workouts_week' => achievement_progress_payload((float) achievement_user_best_weekly_workouts($userMetric), 3, 'workouts'),
            'ten_workouts_total' => achievement_progress_payload(max((float) ($userMetric['workout_count'] ?? 0), (float) ($userMetric['workout_success'] ?? 0)), 10, 'workouts'),
            'distance_50k_total' => achievement_progress_payload((float) ($userMetric['total_km'] ?? 0), 50, 'km'),
            'distance_100k_total' => achievement_progress_payload((float) ($userMetric['total_km'] ?? 0), 100, 'km'),
            'early_logger' => achievement_progress_payload(user_has_early_daily_log($pdo, $userId) ? 1 : 0, 1),
            'habit_reader_streak' => achievement_progress_payload(max((float) ($userMetric['habit_counts']['reading'] ?? 0), (float) user_habit_completion_count($pdo, $userId, 'reading')), 5, 'days'),
            'weight_logged' => achievement_progress_payload(((float) ($userMetric['latest_weight'] ?? 0) > 0 || achievement_user_count($pdo, 'daily_logs', 'user_id = :user_id AND COALESCE(weight, 0) > 0', [':user_id' => $userId]) > 0) ? 1 : 0, 1),
            'calorie_tracker' => achievement_progress_payload(user_has_calorie_tracking($pdo, $userId) ? 1 : 0, 1),
            'five_logs_total' => achievement_progress_payload((float) user_daily_log_count($pdo, $userId), 5, 'logs'),
            'fourteen_logs_total' => achievement_progress_payload((float) user_daily_log_count($pdo, $userId), 14, 'logs'),
            'thirty_logs_total' => achievement_progress_payload((float) user_daily_log_count($pdo, $userId), 30, 'logs'),
            'steps_100k_total' => achievement_progress_payload((float) ($userMetric['total_steps'] ?? 0), 100000, 'steps'),
            'steps_250k_total' => achievement_progress_payload((float) ($userMetric['total_steps'] ?? 0), 250000, 'steps'),
            'steps_500k_total' => achievement_progress_payload((float) ($userMetric['total_steps'] ?? 0), 500000, 'steps'),
            'steps_1m_total' => achievement_progress_payload((float) ($userMetric['total_steps'] ?? 0), 1000000, 'steps'),
            'distance_150k_total' => achievement_progress_payload((float) ($userMetric['total_km'] ?? 0), 150, 'km'),
            'distance_250k_total' => achievement_progress_payload((float) ($userMetric['total_km'] ?? 0), 250, 'km'),
            'distance_500k_total' => achievement_progress_payload((float) ($userMetric['total_km'] ?? 0), 500, 'km'),
            'distance_5k_day' => achievement_progress_payload(user_max_daily_distance($pdo, $userId), 5, 'km'),
            'workouts_25_total' => achievement_progress_payload(max((float) ($userMetric['workout_count'] ?? 0), (float) ($userMetric['workout_success'] ?? 0)), 25, 'workouts'),
            'workouts_50_total' => achievement_progress_payload(max((float) ($userMetric['workout_count'] ?? 0), (float) ($userMetric['workout_success'] ?? 0)), 50, 'workouts'),
            'workouts_100_total' => achievement_progress_payload(max((float) ($userMetric['workout_count'] ?? 0), (float) ($userMetric['workout_success'] ?? 0)), 100, 'workouts'),
            'workout_variety_3' => achievement_progress_payload((float) achievement_workout_type_variety($pdo, $userId, null), 3, 'types'),
            'three_photo_days' => achievement_progress_payload((float) user_photo_day_count($pdo, $userId), 3, 'days'),
            'seven_photos_total' => achievement_progress_payload((float) user_photo_count($pdo, $userId), 7, 'photos'),
            'twenty_photos_total' => achievement_progress_payload((float) user_photo_count($pdo, $userId), 20, 'photos'),
            'calorie_7_days' => achievement_progress_payload((float) user_calorie_tracking_day_count($pdo, $userId), 7, 'days'),
            'calorie_14_days' => achievement_progress_payload((float) user_calorie_tracking_day_count($pdo, $userId), 14, 'days'),
            'weight_5_logs' => achievement_progress_payload((float) user_weight_log_count($pdo, $userId), 5, 'logs'),
            'weight_10_logs' => achievement_progress_payload((float) user_weight_log_count($pdo, $userId), 10, 'logs'),
            'no_junk_7_days' => achievement_progress_payload((float) user_no_junk_log_count($pdo, $userId), 7, 'days'),
            'no_junk_14_days' => achievement_progress_payload((float) user_no_junk_log_count($pdo, $userId), 14, 'days'),
            'clean_two_weeks' => achievement_progress_payload((float) user_clean_week_count($userMetric), 2, 'weeks'),
            'perfect_two_weeks' => achievement_progress_payload((float) user_perfect_week_count($userMetric), 2, 'weeks'),
            'top_score_90' => achievement_progress_payload((float) ($userMetric['score'] ?? 0), 90, 'score'),
            'chores_5_total' => achievement_progress_payload((float) user_habit_completion_count($pdo, $userId, 'evening_chores'), 5, 'days'),
            'reading_10_total' => achievement_progress_payload(max((float) ($userMetric['habit_counts']['reading'] ?? 0), (float) user_habit_completion_count($pdo, $userId, 'reading')), 10, 'days'),
            'reading_25_total' => achievement_progress_payload(max((float) ($userMetric['habit_counts']['reading'] ?? 0), (float) user_habit_completion_count($pdo, $userId, 'reading')), 25, 'days'),
            'morning_logs_5' => achievement_progress_payload((float) user_morning_log_count($pdo, $userId), 5, 'logs'),
            'consistent_week_logger' => achievement_progress_payload((float) user_max_weekly_log_days($pdo, $userId), 7, 'days'),
            default => null,
        };
    }

    if ($scope === 'team' && $teamId !== null) {
        $key = $triggerKey !== '' ? $triggerKey : $code;
        $teamSummary = team_summary_from_metrics($metricsByUser);
        return match ($key) {
            'team_active' => achievement_progress_payload(
                (float) achievement_user_count($pdo, 'team_memberships', 'team_id = :team_id AND active = 1', [':team_id' => $teamId]),
                1,
                'members'
            ),
            'team_first_challenge' => achievement_progress_payload(
                min(1, achievement_user_count($pdo, 'goals', 'scope = "team" AND team_id = :team_id', [':team_id' => $teamId])),
                1
            ),
            'team_challenge_complete' => achievement_progress_payload(
                min(1, achievement_user_count($pdo, 'goals', 'scope = "team" AND team_id = :team_id AND status = "complete"', [':team_id' => $teamId])),
                1
            ),
            'team_100k_steps_week' => achievement_progress_payload((float) achievement_team_best_weekly_steps($metricsByUser), 100000, 'steps'),
            'team_250km_total' => achievement_progress_payload((float) ($teamSummary['total_km'] ?? 0), 250, 'km'),
            'team_clean_week' => achievement_progress_payload(team_has_clean_complete_week($metricsByUser) ? 1 : 0, 1),
            'team_training_mix' => achievement_progress_payload(max((float) ($teamSummary['workout_success'] ?? 0), (float) ($teamSummary['workout_count'] ?? 0)), 5, 'workouts'),
            'team_500k_steps_total' => achievement_progress_payload((float) ($teamSummary['total_steps'] ?? 0), 500000, 'steps'),
            'team_1m_steps_total' => achievement_progress_payload((float) ($teamSummary['total_steps'] ?? 0), 1000000, 'steps'),
            'team_500km_total' => achievement_progress_payload((float) ($teamSummary['total_km'] ?? 0), 500, 'km'),
            'team_1000km_total' => achievement_progress_payload((float) ($teamSummary['total_km'] ?? 0), 1000, 'km'),
            'team_10_workouts_total' => achievement_progress_payload(max((float) ($teamSummary['workout_success'] ?? 0), (float) ($teamSummary['workout_count'] ?? 0)), 10, 'workouts'),
            'team_25_workouts_total' => achievement_progress_payload(max((float) ($teamSummary['workout_success'] ?? 0), (float) ($teamSummary['workout_count'] ?? 0)), 25, 'workouts'),
            'team_50_workouts_total' => achievement_progress_payload(max((float) ($teamSummary['workout_success'] ?? 0), (float) ($teamSummary['workout_count'] ?? 0)), 50, 'workouts'),
            'team_training_variety_4' => achievement_progress_payload((float) achievement_workout_type_variety($pdo, null, $teamId), 4, 'types'),
            'team_3_challenges_created' => achievement_progress_payload((float) team_goal_count($pdo, $teamId), 3, 'challenges'),
            'team_5_challenges_created' => achievement_progress_payload((float) team_goal_count($pdo, $teamId), 5, 'challenges'),
            'team_3_challenges_completed' => achievement_progress_payload((float) team_goal_count($pdo, $teamId, 'complete'), 3, 'challenges'),
            'team_no_penalty_two_weeks' => achievement_progress_payload((float) team_clean_complete_week_count($metricsByUser), 2, 'weeks'),
            'team_everyone_logged_week' => achievement_progress_payload((float) team_everyone_week_count($pdo, $teamId, false), 1),
            'team_everyone_workout_week' => achievement_progress_payload((float) team_everyone_week_count($pdo, $teamId, true), 1),
            'team_photo_wall_20' => achievement_progress_payload((float) team_photo_count($pdo, $teamId), 20, 'photos'),
            'team_1000_calories_burned' => achievement_progress_payload(team_training_calories_burned($pdo, $teamId), 1000, 'kcal'),
            default => null,
        };
    }

    return null;
}

function list_achievement_collection(PDO $pdo, string $scope, ?int $userId, ?int $teamId, array $metricsByUser): array
{
    $scope = $scope === 'team' ? 'team' : 'user';
    $cacheKey = null;
    if (function_exists('app_cache_get') && function_exists('app_cache_set')) {
        $cacheKey = 'achievement_collection:' . hash('sha256', json_encode([
            'scope' => $scope,
            'user_id' => $userId,
            'team_id' => $teamId,
            'locale' => current_locale(),
            'metrics' => $metricsByUser,
        ], JSON_UNESCAPED_SLASHES) ?: '');
        $cached = app_cache_get($cacheKey, 300);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $achievements = localize_achievement_rows($pdo, db_fetch_all(
        $pdo,
        'SELECT a.*,
                ar.id AS rule_id,
                ar.metric_key,
                ar.operator AS trigger_operator,
                ar.target_value AS trigger_target,
                ar."window" AS trigger_window,
                ar.active AS rule_active
         FROM achievements a
          LEFT JOIN achievement_rules ar ON ar.id = (
             SELECT rr.id
             FROM achievement_rules rr
             WHERE rr.achievement_id = a.id AND rr.active = 1
             ORDER BY rr.id DESC
             LIMIT 1
          )
         WHERE a.active = 1 AND a.scope = :scope
         ORDER BY a.created_at ASC, a.name ASC',
        [':scope' => $scope]
    ));

    $awardParams = [];
    if ($scope === 'team') {
        $awardWhere = 'team_id = :team_id';
        $awardParams[':team_id'] = $teamId ?? 0;
    } else {
        $awardWhere = 'user_id = :user_id';
        $awardParams[':user_id'] = $userId ?? 0;
    }
    $awards = db_fetch_all($pdo, 'SELECT * FROM achievement_awards WHERE ' . $awardWhere, $awardParams);
    $awardsByAchievement = [];
    foreach ($awards as $award) {
        $awardsByAchievement[(int) ($award['achievement_id'] ?? 0)] = $award;
    }

    $rows = [];
    foreach ($achievements as $achievement) {
        $achievementId = (int) ($achievement['id'] ?? 0);
        $award = $awardsByAchievement[$achievementId] ?? null;
        $progress = achievement_progress_for_row($pdo, $achievement, $scope, $userId, $teamId, $metricsByUser);
        $row = $achievement;
        $row['is_unlocked'] = $award !== null ? 1 : 0;
        $row['award_id'] = $award !== null ? (int) ($award['id'] ?? 0) : null;
        $row['awarded_at'] = $award !== null ? (string) ($award['awarded_at'] ?? '') : '';
        $row['award_note'] = $award !== null ? (string) ($award['note'] ?? '') : '';
        if ($progress !== null) {
            $row['progress_current'] = $progress['current'];
            $row['progress_target'] = $progress['target'];
            $row['progress_pct'] = $progress['pct'];
            $row['progress_text'] = $progress['text'];
        }
        $rows[] = $row;
    }

    usort($rows, static function (array $left, array $right): int {
        $leftUnlocked = !empty($left['is_unlocked']);
        $rightUnlocked = !empty($right['is_unlocked']);
        if ($leftUnlocked !== $rightUnlocked) {
            return $leftUnlocked ? -1 : 1;
        }
        if ($leftUnlocked && $rightUnlocked) {
            return strcmp((string) ($right['awarded_at'] ?? ''), (string) ($left['awarded_at'] ?? ''));
        }

        return strcmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
    });

    if ($cacheKey !== null) {
        app_cache_set($cacheKey, $rows);
    }

    return $rows;
}

function build_admin_achievement_stats(PDO $pdo, array $achievement, array $users, ?array $team, array $metricsByUser): array
{
    $achievementId = (int) ($achievement['id'] ?? 0);
    if ($achievementId <= 0) {
        return [
            'unlocked' => 0,
            'in_progress' => 0,
            'locked' => 0,
            'total' => 0,
            'avg_progress' => 0.0,
            'recent_unlocks' => [],
            'rows' => [],
        ];
    }

    $scope = (string) ($achievement['scope'] ?? 'user') === 'team' ? 'team' : 'user';
    $rows = [];
    if ($scope === 'team') {
        $teamId = (int) ($team['id'] ?? 0);
        if ($teamId > 0) {
            $collection = list_achievement_collection($pdo, 'team', null, $teamId, $metricsByUser);
            foreach ($collection as $row) {
                if ((int) ($row['id'] ?? 0) === $achievementId) {
                    $rows[] = [
                        'owner' => (string) ($team['name'] ?? t('nav.team')),
                        'is_unlocked' => !empty($row['is_unlocked']),
                        'awarded_at' => (string) ($row['awarded_at'] ?? ''),
                        'progress_pct' => is_numeric($row['progress_pct'] ?? null) ? (float) $row['progress_pct'] : (!empty($row['is_unlocked']) ? 100.0 : 0.0),
                        'progress_text' => (string) ($row['progress_text'] ?? ''),
                    ];
                    break;
                }
            }
        }
    } else {
        foreach ($users as $user) {
            if (!is_array($user) || (int) ($user['active'] ?? 1) !== 1) {
                continue;
            }
            $userId = (int) ($user['id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }
            $collection = list_achievement_collection($pdo, 'user', $userId, null, $metricsByUser);
            foreach ($collection as $row) {
                if ((int) ($row['id'] ?? 0) !== $achievementId) {
                    continue;
                }
                $rows[] = [
                    'owner' => (string) ($user['display_name'] ?? $user['username'] ?? t('common.user')),
                    'is_unlocked' => !empty($row['is_unlocked']),
                    'awarded_at' => (string) ($row['awarded_at'] ?? ''),
                    'progress_pct' => is_numeric($row['progress_pct'] ?? null) ? (float) $row['progress_pct'] : (!empty($row['is_unlocked']) ? 100.0 : 0.0),
                    'progress_text' => (string) ($row['progress_text'] ?? ''),
                ];
                break;
            }
        }
    }

    $unlocked = 0;
    $inProgress = 0;
    $locked = 0;
    $progressTotal = 0.0;
    $recentUnlocks = [];
    foreach ($rows as $row) {
        $progress = max(0.0, min(100.0, (float) ($row['progress_pct'] ?? 0)));
        $progressTotal += $progress;
        if (!empty($row['is_unlocked'])) {
            $unlocked++;
            if ((string) ($row['awarded_at'] ?? '') !== '') {
                $recentUnlocks[] = $row;
            }
        } elseif ($progress > 0) {
            $inProgress++;
        } else {
            $locked++;
        }
    }

    usort($recentUnlocks, static fn(array $left, array $right): int => strcmp((string) ($right['awarded_at'] ?? ''), (string) ($left['awarded_at'] ?? '')));

    return [
        'unlocked' => $unlocked,
        'in_progress' => $inProgress,
        'locked' => $locked,
        'total' => count($rows),
        'avg_progress' => count($rows) > 0 ? round($progressTotal / count($rows), 1) : 0.0,
        'recent_unlocks' => array_slice($recentUnlocks, 0, 6),
        'rows' => $rows,
    ];
}

function delete_achievement_award(PDO $pdo, int $awardId, int $actorUserId): void
{
    if ($awardId <= 0) {
        return;
    }

    $before = db_fetch_one(
        $pdo,
        'SELECT aa.*, a.name, a.scope
         FROM achievement_awards aa
         JOIN achievements a ON a.id = aa.achievement_id
         WHERE aa.id = :id',
        [':id' => $awardId]
    );
    if ($before === null) {
        return;
    }

    db_execute($pdo, 'DELETE FROM achievement_awards WHERE id = :id', [':id' => $awardId]);
    suppress_achievement_award(
        $pdo,
        (int) ($before['achievement_id'] ?? 0),
        ($before['user_id'] ?? null) !== null ? (int) $before['user_id'] : null,
        ($before['team_id'] ?? null) !== null ? (int) $before['team_id'] : null,
        $actorUserId,
        'Deleted achievement award.'
    );
    audit_log($pdo, $actorUserId, 'achievement_award_deleted', 'achievement', (string) ($before['achievement_id'] ?? ''), 'Achievement award deleted.', audit_snapshot($before), null);
}

function user_has_early_daily_log(PDO $pdo, int $userId): bool
{
    return db_fetch_one(
        $pdo,
        'SELECT id FROM daily_logs
         WHERE user_id = :user_id
           AND log_time IS NOT NULL
           AND log_time != ""
           AND substr(log_time, 1, 5) <= "09:00"
         LIMIT 1',
        [':user_id' => $userId]
    ) !== null;
}

function user_has_calorie_tracking(PDO $pdo, int $userId): bool
{
    if (db_fetch_one($pdo, 'SELECT id FROM daily_logs WHERE user_id = :user_id AND COALESCE(training_calories_burned, 0) > 0 LIMIT 1', [':user_id' => $userId]) !== null) {
        return true;
    }

    return db_fetch_one($pdo, 'SELECT id FROM photo_entries WHERE user_id = :user_id AND COALESCE(calories, 0) > 0 LIMIT 1', [':user_id' => $userId]) !== null;
}

function user_habit_completion_count(PDO $pdo, int $userId, string $habitCode): int
{
    $row = db_fetch_one(
        $pdo,
        'SELECT COUNT(*) AS total
         FROM daily_log_habits dlh
         JOIN daily_logs dl ON dl.id = dlh.log_id
         JOIN habit_definitions hd ON hd.id = dlh.habit_id
         WHERE dl.user_id = :user_id
           AND hd.code = :code
           AND dlh.value = 1',
        [':user_id' => $userId, ':code' => $habitCode]
    );

    return (int) ($row['total'] ?? 0);
}

function user_daily_log_count(PDO $pdo, int $userId): int
{
    return achievement_user_count($pdo, 'daily_logs', 'user_id = :user_id', [':user_id' => $userId]);
}

function user_photo_count(PDO $pdo, int $userId): int
{
    return achievement_user_count($pdo, 'photo_entries', 'user_id = :user_id', [':user_id' => $userId]);
}

function user_photo_day_count(PDO $pdo, int $userId): int
{
    $row = db_fetch_one($pdo, 'SELECT COUNT(DISTINCT log_date) AS total FROM photo_entries WHERE user_id = :user_id', [':user_id' => $userId]);

    return (int) ($row['total'] ?? 0);
}

function user_calorie_tracking_day_count(PDO $pdo, int $userId): int
{
    $rows = db_fetch_all(
        $pdo,
        'SELECT log_date
         FROM daily_logs
         WHERE user_id = :user_id AND COALESCE(training_calories_burned, 0) > 0
         UNION
         SELECT log_date
         FROM photo_entries
         WHERE user_id = :user_id AND COALESCE(calories, 0) > 0',
        [':user_id' => $userId]
    );

    return count($rows);
}

function user_weight_log_count(PDO $pdo, int $userId): int
{
    return achievement_user_count($pdo, 'daily_logs', 'user_id = :user_id AND COALESCE(weight, 0) > 0', [':user_id' => $userId]);
}

function user_no_junk_log_count(PDO $pdo, int $userId): int
{
    return achievement_user_count($pdo, 'daily_logs', 'user_id = :user_id AND COALESCE(junk_food, 0) = 0', [':user_id' => $userId]);
}

function user_morning_log_count(PDO $pdo, int $userId): int
{
    return achievement_user_count(
        $pdo,
        'daily_logs',
        'user_id = :user_id AND log_time IS NOT NULL AND log_time != "" AND substr(log_time, 1, 5) <= "09:00"',
        [':user_id' => $userId]
    );
}

function user_max_daily_distance(PDO $pdo, int $userId): float
{
    $row = db_fetch_one($pdo, 'SELECT MAX(COALESCE(distance_km, 0)) AS value FROM daily_logs WHERE user_id = :user_id', [':user_id' => $userId]);

    return (float) ($row['value'] ?? 0);
}

function achievement_week_key(string $date): string
{
    try {
        return week_start_for(new DateTimeImmutable($date))->format('Y-m-d');
    } catch (Throwable) {
        return '';
    }
}

function user_max_weekly_log_days(PDO $pdo, int $userId): int
{
    $rows = db_fetch_all($pdo, 'SELECT DISTINCT log_date FROM daily_logs WHERE user_id = :user_id', [':user_id' => $userId]);
    $byWeek = [];
    foreach ($rows as $row) {
        $week = achievement_week_key((string) ($row['log_date'] ?? ''));
        if ($week === '') {
            continue;
        }
        $byWeek[$week] = ($byWeek[$week] ?? 0) + 1;
    }

    return $byWeek !== [] ? max($byWeek) : 0;
}

function user_clean_week_count(array $metric): int
{
    $count = 0;
    foreach ((array) ($metric['weekly'] ?? []) as $week) {
        if ((string) ($week['status'] ?? '') === 'complete' && (float) ($week['penalty'] ?? 0) <= 0.0) {
            $count++;
        }
    }

    return $count;
}

function user_perfect_week_count(array $metric): int
{
    $count = 0;
    foreach ((array) ($metric['weekly'] ?? []) as $week) {
        if ((string) ($week['status'] ?? '') === 'complete' && (int) ($week['total_failures'] ?? 0) === 0) {
            $count++;
        }
    }

    return $count;
}

function achievement_workout_type_variety(PDO $pdo, ?int $userId = null, ?int $teamId = null): int
{
    $params = [];
    $where = '1 = 1';
    $joinTeam = '';
    if ($userId !== null) {
        $where .= ' AND dl.user_id = :user_id';
        $params[':user_id'] = $userId;
    }
    if ($teamId !== null) {
        $joinTeam = 'JOIN team_memberships tm ON tm.user_id = dl.user_id AND tm.active = 1';
        $where .= ' AND tm.team_id = :team_id';
        $params[':team_id'] = $teamId;
    }

    $rows = db_fetch_all(
        $pdo,
        'SELECT dl.workout_type_id AS legacy_type_id,
                dl.workout_type AS legacy_type,
                dlw.workout_type_id AS row_type_id,
                dlw.workout_type AS row_type
         FROM daily_logs dl
         ' . $joinTeam . '
         LEFT JOIN daily_log_workouts dlw ON dlw.log_id = dl.id
         WHERE ' . $where . ' AND (COALESCE(dl.workout_done, 0) = 1 OR dlw.id IS NOT NULL)',
        $params
    );

    $keys = [];
    foreach ($rows as $row) {
        $typeId = (int) ($row['row_type_id'] ?? 0);
        $typeText = trim((string) ($row['row_type'] ?? ''));
        if ($typeId <= 0) {
            $typeId = (int) ($row['legacy_type_id'] ?? 0);
            $typeText = trim((string) ($row['legacy_type'] ?? $typeText));
        }
        $key = $typeId > 0 ? 'id:' . $typeId : 'text:' . strtolower($typeText);
        if ($key !== 'text:') {
            $keys[$key] = true;
        }
    }

    return count($keys);
}

function team_goal_count(PDO $pdo, int $teamId, ?string $status = null): int
{
    $params = [':team_id' => $teamId];
    $where = 'scope = "team" AND team_id = :team_id';
    if ($status !== null) {
        $where .= ' AND status = :status';
        $params[':status'] = $status;
    }

    return achievement_user_count($pdo, 'goals', $where, $params);
}

function team_photo_count(PDO $pdo, int $teamId): int
{
    $row = db_fetch_one(
        $pdo,
        'SELECT COUNT(*) AS total
         FROM photo_entries pe
         JOIN team_memberships tm ON tm.user_id = pe.user_id AND tm.active = 1
         WHERE tm.team_id = :team_id',
        [':team_id' => $teamId]
    );

    return (int) ($row['total'] ?? 0);
}

function team_training_calories_burned(PDO $pdo, int $teamId): float
{
    $row = db_fetch_one(
        $pdo,
        'SELECT SUM(COALESCE(dl.training_calories_burned, 0)) AS total
         FROM daily_logs dl
         JOIN team_memberships tm ON tm.user_id = dl.user_id AND tm.active = 1
         WHERE tm.team_id = :team_id',
        [':team_id' => $teamId]
    );

    return (float) ($row['total'] ?? 0);
}

function team_clean_complete_week_count(array $metricsByUser): int
{
    $memberCount = count($metricsByUser);
    if ($memberCount <= 0) {
        return 0;
    }

    $weeks = [];
    foreach ($metricsByUser as $metric) {
        foreach ((array) ($metric['weekly'] ?? []) as $week) {
            $weekStart = (string) ($week['week_start'] ?? '');
            if ($weekStart === '') {
                continue;
            }
            if (!isset($weeks[$weekStart])) {
                $weeks[$weekStart] = ['members' => 0, 'complete' => 0, 'penalty' => 0.0];
            }
            $weeks[$weekStart]['members']++;
            if ((string) ($week['status'] ?? '') === 'complete') {
                $weeks[$weekStart]['complete']++;
            }
            $weeks[$weekStart]['penalty'] += (float) ($week['penalty'] ?? 0);
        }
    }

    $count = 0;
    foreach ($weeks as $week) {
        if ((int) $week['members'] >= $memberCount && (int) $week['complete'] >= $memberCount && (float) $week['penalty'] <= 0.0) {
            $count++;
        }
    }

    return $count;
}

function team_everyone_week_count(PDO $pdo, int $teamId, bool $workoutOnly = false): int
{
    $members = db_fetch_all($pdo, 'SELECT user_id FROM team_memberships WHERE team_id = :team_id AND active = 1', [':team_id' => $teamId]);
    $memberIds = array_map(static fn(array $row): int => (int) ($row['user_id'] ?? 0), $members);
    $memberIds = array_values(array_filter($memberIds, static fn(int $id): bool => $id > 0));
    $memberCount = count($memberIds);
    if ($memberCount <= 0) {
        return 0;
    }

    $rows = db_fetch_all(
        $pdo,
        'SELECT dl.user_id, dl.log_date, dl.workout_done
         FROM daily_logs dl
         JOIN team_memberships tm ON tm.user_id = dl.user_id AND tm.active = 1
         WHERE tm.team_id = :team_id' . ($workoutOnly ? ' AND COALESCE(dl.workout_done, 0) = 1' : ''),
        [':team_id' => $teamId]
    );
    $weeks = [];
    foreach ($rows as $row) {
        $week = achievement_week_key((string) ($row['log_date'] ?? ''));
        $userId = (int) ($row['user_id'] ?? 0);
        if ($week === '' || $userId <= 0) {
            continue;
        }
        $weeks[$week][$userId] = true;
    }

    $count = 0;
    foreach ($weeks as $users) {
        if (count($users) >= $memberCount) {
            $count++;
        }
    }

    return $count;
}

function team_has_weekly_steps_total(array $metricsByUser, int $targetSteps): bool
{
    $byWeek = [];
    foreach ($metricsByUser as $metric) {
        foreach ((array) ($metric['weekly'] ?? []) as $week) {
            $weekStart = (string) ($week['week_start'] ?? '');
            if ($weekStart === '') {
                continue;
            }
            $byWeek[$weekStart] = ($byWeek[$weekStart] ?? 0) + (int) ($week['steps'] ?? 0);
            if ($byWeek[$weekStart] >= $targetSteps) {
                return true;
            }
        }
    }

    return false;
}

function team_has_clean_complete_week(array $metricsByUser): bool
{
    $memberCount = count($metricsByUser);
    if ($memberCount <= 0) {
        return false;
    }

    $weeks = [];
    foreach ($metricsByUser as $metric) {
        foreach ((array) ($metric['weekly'] ?? []) as $week) {
            $weekStart = (string) ($week['week_start'] ?? '');
            if ($weekStart === '') {
                continue;
            }
            if (!isset($weeks[$weekStart])) {
                $weeks[$weekStart] = ['members' => 0, 'complete' => 0, 'penalty' => 0.0];
            }
            $weeks[$weekStart]['members']++;
            if ((string) ($week['status'] ?? '') === 'complete') {
                $weeks[$weekStart]['complete']++;
            }
            $weeks[$weekStart]['penalty'] += (float) ($week['penalty'] ?? 0);
        }
    }

    foreach ($weeks as $week) {
        if ((int) $week['members'] >= $memberCount && (int) $week['complete'] >= $memberCount && (float) $week['penalty'] <= 0.0) {
            return true;
        }
    }

    return false;
}

function evaluate_automatic_achievements(PDO $pdo, array $metricsByUser, ?int $teamId = null): void
{
    $achievementRows = db_fetch_all($pdo, 'SELECT * FROM achievements WHERE active = 1 AND trigger_key IS NOT NULL');
    $byTrigger = [];
    foreach ($achievementRows as $achievement) {
        $byTrigger[(string) $achievement['trigger_key']] = $achievement;
    }

    foreach ($metricsByUser as $metric) {
        $userId = (int) $metric['user']['id'];

        if (isset($byTrigger['first_log']) && db_fetch_one($pdo, 'SELECT id FROM daily_logs WHERE user_id = :user_id LIMIT 1', [':user_id' => $userId]) !== null) {
            award_achievement($pdo, (int) $byTrigger['first_log']['id'], $userId, null, null, 'Automatic unlock.');
        }
        if (isset($byTrigger['first_photo']) && db_fetch_one($pdo, 'SELECT id FROM photo_entries WHERE user_id = :user_id LIMIT 1', [':user_id' => $userId]) !== null) {
            award_achievement($pdo, (int) $byTrigger['first_photo']['id'], $userId, null, null, 'Automatic unlock.');
        }
        if (isset($byTrigger['perfect_week'])) {
            foreach (($metric['weekly'] ?? []) as $week) {
                if (($week['status'] ?? '') === 'complete' && (int) ($week['total_failures'] ?? 0) === 0) {
                    award_achievement($pdo, (int) $byTrigger['perfect_week']['id'], $userId, null, null, 'Automatic unlock.');
                    break;
                }
            }
        }
        if (isset($byTrigger['no_strike_week'])) {
            foreach (($metric['weekly'] ?? []) as $week) {
                if (($week['status'] ?? '') === 'complete' && (int) ($week['penalty'] ?? 0) === 0) {
                    award_achievement($pdo, (int) $byTrigger['no_strike_week']['id'], $userId, null, null, 'Automatic unlock.');
                    break;
                }
            }
        }
        if (isset($byTrigger['step_streak']) && (int) ($metric['steps_success'] ?? 0) >= 5) {
            award_achievement($pdo, (int) $byTrigger['step_streak']['id'], $userId, null, null, 'Automatic unlock.');
        }
        if (isset($byTrigger['seven_day_step_streak']) && (int) ($metric['steps_success'] ?? 0) >= 7) {
            award_achievement($pdo, (int) $byTrigger['seven_day_step_streak']['id'], $userId, null, null, 'Automatic unlock.');
        }
        if (isset($byTrigger['three_workouts_week']) && has_three_workouts_in_week($pdo, $userId)) {
            award_achievement($pdo, (int) $byTrigger['three_workouts_week']['id'], $userId, null, null, 'Automatic unlock.');
        }
        $workoutTotal = max((int) ($metric['workout_count'] ?? 0), (int) ($metric['workout_success'] ?? 0));
        if (isset($byTrigger['ten_workouts_total']) && $workoutTotal >= 10) {
            award_achievement($pdo, (int) $byTrigger['ten_workouts_total']['id'], $userId, null, null, 'Automatic unlock.');
        }
        if (isset($byTrigger['distance_50k_total']) && (float) ($metric['total_km'] ?? 0) >= 50.0) {
            award_achievement($pdo, (int) $byTrigger['distance_50k_total']['id'], $userId, null, null, 'Automatic unlock.');
        }
        if (isset($byTrigger['distance_100k_total']) && (float) ($metric['total_km'] ?? 0) >= 100.0) {
            award_achievement($pdo, (int) $byTrigger['distance_100k_total']['id'], $userId, null, null, 'Automatic unlock.');
        }
        if (isset($byTrigger['early_logger']) && user_has_early_daily_log($pdo, $userId)) {
            award_achievement($pdo, (int) $byTrigger['early_logger']['id'], $userId, null, null, 'Automatic unlock.');
        }
        if (isset($byTrigger['habit_reader_streak'])) {
            $readingCount = (int) (($metric['habit_counts']['reading'] ?? 0));
            if ($readingCount >= 5 || user_habit_completion_count($pdo, $userId, 'reading') >= 5) {
                award_achievement($pdo, (int) $byTrigger['habit_reader_streak']['id'], $userId, null, null, 'Automatic unlock.');
            }
        }
        if (isset($byTrigger['weight_logged']) && ((float) ($metric['latest_weight'] ?? 0) > 0 || db_fetch_one($pdo, 'SELECT id FROM daily_logs WHERE user_id = :user_id AND COALESCE(weight, 0) > 0 LIMIT 1', [':user_id' => $userId]) !== null)) {
            award_achievement($pdo, (int) $byTrigger['weight_logged']['id'], $userId, null, null, 'Automatic unlock.');
        }
        if (isset($byTrigger['calorie_tracker']) && user_has_calorie_tracking($pdo, $userId)) {
            award_achievement($pdo, (int) $byTrigger['calorie_tracker']['id'], $userId, null, null, 'Automatic unlock.');
        }

        $logCount = user_daily_log_count($pdo, $userId);
        foreach (['five_logs_total' => 5, 'fourteen_logs_total' => 14, 'thirty_logs_total' => 30] as $trigger => $target) {
            if (isset($byTrigger[$trigger]) && $logCount >= $target) {
                award_achievement($pdo, (int) $byTrigger[$trigger]['id'], $userId, null, null, 'Automatic unlock.');
            }
        }
        foreach (['steps_100k_total' => 100000, 'steps_250k_total' => 250000, 'steps_500k_total' => 500000, 'steps_1m_total' => 1000000] as $trigger => $target) {
            if (isset($byTrigger[$trigger]) && (int) ($metric['total_steps'] ?? 0) >= $target) {
                award_achievement($pdo, (int) $byTrigger[$trigger]['id'], $userId, null, null, 'Automatic unlock.');
            }
        }
        foreach (['distance_150k_total' => 150.0, 'distance_250k_total' => 250.0, 'distance_500k_total' => 500.0] as $trigger => $target) {
            if (isset($byTrigger[$trigger]) && (float) ($metric['total_km'] ?? 0) >= $target) {
                award_achievement($pdo, (int) $byTrigger[$trigger]['id'], $userId, null, null, 'Automatic unlock.');
            }
        }
        if (isset($byTrigger['distance_5k_day']) && user_max_daily_distance($pdo, $userId) >= 5.0) {
            award_achievement($pdo, (int) $byTrigger['distance_5k_day']['id'], $userId, null, null, 'Automatic unlock.');
        }
        foreach (['workouts_25_total' => 25, 'workouts_50_total' => 50, 'workouts_100_total' => 100] as $trigger => $target) {
            if (isset($byTrigger[$trigger]) && $workoutTotal >= $target) {
                award_achievement($pdo, (int) $byTrigger[$trigger]['id'], $userId, null, null, 'Automatic unlock.');
            }
        }
        if (isset($byTrigger['workout_variety_3']) && achievement_workout_type_variety($pdo, $userId, null) >= 3) {
            award_achievement($pdo, (int) $byTrigger['workout_variety_3']['id'], $userId, null, null, 'Automatic unlock.');
        }
        if (isset($byTrigger['three_photo_days']) && user_photo_day_count($pdo, $userId) >= 3) {
            award_achievement($pdo, (int) $byTrigger['three_photo_days']['id'], $userId, null, null, 'Automatic unlock.');
        }
        if (isset($byTrigger['seven_photos_total']) && user_photo_count($pdo, $userId) >= 7) {
            award_achievement($pdo, (int) $byTrigger['seven_photos_total']['id'], $userId, null, null, 'Automatic unlock.');
        }
        if (isset($byTrigger['twenty_photos_total']) && user_photo_count($pdo, $userId) >= 20) {
            award_achievement($pdo, (int) $byTrigger['twenty_photos_total']['id'], $userId, null, null, 'Automatic unlock.');
        }
        if (isset($byTrigger['calorie_7_days']) && user_calorie_tracking_day_count($pdo, $userId) >= 7) {
            award_achievement($pdo, (int) $byTrigger['calorie_7_days']['id'], $userId, null, null, 'Automatic unlock.');
        }
        if (isset($byTrigger['calorie_14_days']) && user_calorie_tracking_day_count($pdo, $userId) >= 14) {
            award_achievement($pdo, (int) $byTrigger['calorie_14_days']['id'], $userId, null, null, 'Automatic unlock.');
        }
        if (isset($byTrigger['weight_5_logs']) && user_weight_log_count($pdo, $userId) >= 5) {
            award_achievement($pdo, (int) $byTrigger['weight_5_logs']['id'], $userId, null, null, 'Automatic unlock.');
        }
        if (isset($byTrigger['weight_10_logs']) && user_weight_log_count($pdo, $userId) >= 10) {
            award_achievement($pdo, (int) $byTrigger['weight_10_logs']['id'], $userId, null, null, 'Automatic unlock.');
        }
        if (isset($byTrigger['no_junk_7_days']) && user_no_junk_log_count($pdo, $userId) >= 7) {
            award_achievement($pdo, (int) $byTrigger['no_junk_7_days']['id'], $userId, null, null, 'Automatic unlock.');
        }
        if (isset($byTrigger['no_junk_14_days']) && user_no_junk_log_count($pdo, $userId) >= 14) {
            award_achievement($pdo, (int) $byTrigger['no_junk_14_days']['id'], $userId, null, null, 'Automatic unlock.');
        }
        if (isset($byTrigger['clean_two_weeks']) && user_clean_week_count($metric) >= 2) {
            award_achievement($pdo, (int) $byTrigger['clean_two_weeks']['id'], $userId, null, null, 'Automatic unlock.');
        }
        if (isset($byTrigger['perfect_two_weeks']) && user_perfect_week_count($metric) >= 2) {
            award_achievement($pdo, (int) $byTrigger['perfect_two_weeks']['id'], $userId, null, null, 'Automatic unlock.');
        }
        if (isset($byTrigger['top_score_90']) && (float) ($metric['score'] ?? 0) >= 90.0) {
            award_achievement($pdo, (int) $byTrigger['top_score_90']['id'], $userId, null, null, 'Automatic unlock.');
        }
        if (isset($byTrigger['chores_5_total']) && user_habit_completion_count($pdo, $userId, 'evening_chores') >= 5) {
            award_achievement($pdo, (int) $byTrigger['chores_5_total']['id'], $userId, null, null, 'Automatic unlock.');
        }
        if (isset($byTrigger['reading_10_total']) && max((int) (($metric['habit_counts']['reading'] ?? 0)), user_habit_completion_count($pdo, $userId, 'reading')) >= 10) {
            award_achievement($pdo, (int) $byTrigger['reading_10_total']['id'], $userId, null, null, 'Automatic unlock.');
        }
        if (isset($byTrigger['reading_25_total']) && max((int) (($metric['habit_counts']['reading'] ?? 0)), user_habit_completion_count($pdo, $userId, 'reading')) >= 25) {
            award_achievement($pdo, (int) $byTrigger['reading_25_total']['id'], $userId, null, null, 'Automatic unlock.');
        }
        if (isset($byTrigger['morning_logs_5']) && user_morning_log_count($pdo, $userId) >= 5) {
            award_achievement($pdo, (int) $byTrigger['morning_logs_5']['id'], $userId, null, null, 'Automatic unlock.');
        }
        if (isset($byTrigger['consistent_week_logger']) && user_max_weekly_log_days($pdo, $userId) >= 7) {
            award_achievement($pdo, (int) $byTrigger['consistent_week_logger']['id'], $userId, null, null, 'Automatic unlock.');
        }
    }

    if ($teamId !== null) {
        if (isset($byTrigger['team_active'])) {
            $activeMembers = db_fetch_one($pdo, 'SELECT COUNT(*) AS total FROM team_memberships WHERE team_id = :team_id AND active = 1', [':team_id' => $teamId]);
            if ((int) ($activeMembers['total'] ?? 0) > 0) {
                award_achievement($pdo, (int) $byTrigger['team_active']['id'], null, $teamId, null, 'Automatic unlock.');
            }
        }
        if (isset($byTrigger['team_first_challenge']) && db_fetch_one($pdo, 'SELECT id FROM goals WHERE scope = "team" AND team_id = :team_id LIMIT 1', [':team_id' => $teamId]) !== null) {
            award_achievement($pdo, (int) $byTrigger['team_first_challenge']['id'], null, $teamId, null, 'Automatic unlock.');
        }
        if (isset($byTrigger['team_challenge_complete']) && db_fetch_one($pdo, 'SELECT id FROM goals WHERE scope = "team" AND team_id = :team_id AND status = "complete" LIMIT 1', [':team_id' => $teamId]) !== null) {
            award_achievement($pdo, (int) $byTrigger['team_challenge_complete']['id'], null, $teamId, null, 'Automatic unlock.');
        }
        if (isset($byTrigger['team_100k_steps_week']) && team_has_weekly_steps_total($metricsByUser, 100000)) {
            award_achievement($pdo, (int) $byTrigger['team_100k_steps_week']['id'], null, $teamId, null, 'Automatic unlock.');
        }
        $teamSummary = team_summary_from_metrics($metricsByUser);
        if (isset($byTrigger['team_250km_total']) && (float) ($teamSummary['total_km'] ?? 0) >= 250.0) {
            award_achievement($pdo, (int) $byTrigger['team_250km_total']['id'], null, $teamId, null, 'Automatic unlock.');
        }
        if (isset($byTrigger['team_clean_week']) && team_has_clean_complete_week($metricsByUser)) {
            award_achievement($pdo, (int) $byTrigger['team_clean_week']['id'], null, $teamId, null, 'Automatic unlock.');
        }
        $teamWorkoutTotal = max((int) ($teamSummary['workout_success'] ?? 0), (int) ($teamSummary['workout_count'] ?? 0));
        if (isset($byTrigger['team_training_mix']) && $teamWorkoutTotal >= 5) {
            award_achievement($pdo, (int) $byTrigger['team_training_mix']['id'], null, $teamId, null, 'Automatic unlock.');
        }
        foreach (['team_500k_steps_total' => 500000, 'team_1m_steps_total' => 1000000] as $trigger => $target) {
            if (isset($byTrigger[$trigger]) && (int) ($teamSummary['total_steps'] ?? 0) >= $target) {
                award_achievement($pdo, (int) $byTrigger[$trigger]['id'], null, $teamId, null, 'Automatic unlock.');
            }
        }
        foreach (['team_500km_total' => 500.0, 'team_1000km_total' => 1000.0] as $trigger => $target) {
            if (isset($byTrigger[$trigger]) && (float) ($teamSummary['total_km'] ?? 0) >= $target) {
                award_achievement($pdo, (int) $byTrigger[$trigger]['id'], null, $teamId, null, 'Automatic unlock.');
            }
        }
        foreach (['team_10_workouts_total' => 10, 'team_25_workouts_total' => 25, 'team_50_workouts_total' => 50] as $trigger => $target) {
            if (isset($byTrigger[$trigger]) && $teamWorkoutTotal >= $target) {
                award_achievement($pdo, (int) $byTrigger[$trigger]['id'], null, $teamId, null, 'Automatic unlock.');
            }
        }
        if (isset($byTrigger['team_training_variety_4']) && achievement_workout_type_variety($pdo, null, $teamId) >= 4) {
            award_achievement($pdo, (int) $byTrigger['team_training_variety_4']['id'], null, $teamId, null, 'Automatic unlock.');
        }
        foreach (['team_3_challenges_created' => 3, 'team_5_challenges_created' => 5] as $trigger => $target) {
            if (isset($byTrigger[$trigger]) && team_goal_count($pdo, $teamId) >= $target) {
                award_achievement($pdo, (int) $byTrigger[$trigger]['id'], null, $teamId, null, 'Automatic unlock.');
            }
        }
        if (isset($byTrigger['team_3_challenges_completed']) && team_goal_count($pdo, $teamId, 'complete') >= 3) {
            award_achievement($pdo, (int) $byTrigger['team_3_challenges_completed']['id'], null, $teamId, null, 'Automatic unlock.');
        }
        if (isset($byTrigger['team_no_penalty_two_weeks']) && team_clean_complete_week_count($metricsByUser) >= 2) {
            award_achievement($pdo, (int) $byTrigger['team_no_penalty_two_weeks']['id'], null, $teamId, null, 'Automatic unlock.');
        }
        if (isset($byTrigger['team_everyone_logged_week']) && team_everyone_week_count($pdo, $teamId, false) >= 1) {
            award_achievement($pdo, (int) $byTrigger['team_everyone_logged_week']['id'], null, $teamId, null, 'Automatic unlock.');
        }
        if (isset($byTrigger['team_everyone_workout_week']) && team_everyone_week_count($pdo, $teamId, true) >= 1) {
            award_achievement($pdo, (int) $byTrigger['team_everyone_workout_week']['id'], null, $teamId, null, 'Automatic unlock.');
        }
        if (isset($byTrigger['team_photo_wall_20']) && team_photo_count($pdo, $teamId) >= 20) {
            award_achievement($pdo, (int) $byTrigger['team_photo_wall_20']['id'], null, $teamId, null, 'Automatic unlock.');
        }
        if (isset($byTrigger['team_1000_calories_burned']) && team_training_calories_burned($pdo, $teamId) >= 1000.0) {
            award_achievement($pdo, (int) $byTrigger['team_1000_calories_burned']['id'], null, $teamId, null, 'Automatic unlock.');
        }
    }

    evaluate_conditional_achievements($pdo, $metricsByUser, $teamId);
}

function evaluate_conditional_achievements(PDO $pdo, array $metricsByUser, ?int $teamId = null): void
{
    $rules = db_fetch_all(
        $pdo,
        'SELECT ar.id AS rule_id,
                ar.achievement_id,
                ar.metric_key,
                ar.operator,
                ar.target_value,
                ar."window" AS trigger_window,
                ar.active AS rule_active,
                a.scope
         FROM achievement_rules ar
         JOIN achievements a ON a.id = ar.achievement_id
         WHERE ar.active = 1 AND a.active = 1 AND a.trigger_key IS NOT NULL'
    );

    foreach ($rules as $rule) {
        try {
            $metricKey = normalize_achievement_rule_metric((string) $rule['metric_key']);
            $window = normalize_achievement_rule_window((string) $rule['trigger_window']);
            $operator = normalize_achievement_rule_operator((string) $rule['operator']);
        } catch (Throwable) {
            continue;
        }

        if ((string) $rule['scope'] === 'team') {
            if ($teamId === null) {
                continue;
            }
            $value = metric_value_for_rule($metricsByUser, $metricKey, $window);
            if (rule_matches($value, $operator, (float) $rule['target_value'])) {
                award_achievement($pdo, (int) $rule['achievement_id'], null, $teamId, null, 'Conditional unlock.');
            }
            continue;
        }

        foreach ($metricsByUser as $metric) {
            $value = metric_value_for_rule([$metric], $metricKey, $window);
            if (rule_matches($value, $operator, (float) $rule['target_value'])) {
                award_achievement($pdo, (int) $rule['achievement_id'], (int) $metric['user']['id'], null, null, 'Conditional unlock.');
            }
        }
    }
}

function metric_value_for_rule(array $metrics, string $metricKey, string $window): float
{
    $metricKey = normalize_achievement_rule_metric($metricKey);
    $window = normalize_achievement_rule_window($window);

    if ($window === 'current_week') {
        $value = 0.0;
        foreach ($metrics as $metric) {
            $weekly = (array) ($metric['weekly'] ?? []);
            if ($weekly === []) {
                continue;
            }
            $week = $weekly[count($weekly) - 1];
            if ($metricKey === 'steps') {
                $metricValue = (float) ($week['steps'] ?? 0);
            } elseif ($metricKey === 'distance_km') {
                $metricValue = (float) ($week['km'] ?? 0);
            } elseif ($metricKey === 'workouts') {
                $metricValue = (float) ($week['workouts'] ?? 0);
            } elseif ($metricKey === 'score') {
                $metricValue = max(
                    0.0,
                    100.0 - (
                        ((int) ($week['step_failures'] ?? 0) * 6) +
                        ((int) ($week['workout_failures'] ?? 0) * 8) +
                        ((int) ($week['skip_warnings'] ?? 0) * 3) +
                        ((int) ($week['strikes_after_week'] ?? 0) * 4)
                    )
                );
            } elseif ($metricKey === 'strikes') {
                $metricValue = (float) ($week['strikes_after_week'] ?? 0);
            } elseif ($metricKey === 'penalties') {
                $metricValue = (float) ($week['penalty'] ?? 0);
            } elseif ($metricKey === 'weight') {
                $metricValue = (float) ($metric['latest_weight'] ?? 0);
            } elseif (str_starts_with($metricKey, 'habit:')) {
                $code = substr($metricKey, 6);
                $metricValue = (float) ($week['habit_counts'][$code] ?? 0);
            } else {
                $metricValue = 0.0;
            }
            $value += $metricValue;
        }
        if (($metricKey === 'score' || $metricKey === 'weight') && count($metrics) > 1) {
            $value = $value / count($metrics);
        }

        return $value;
    }

    if ($window === 'current_month') {
        $value = 0.0;
        foreach ($metrics as $metric) {
            $anchorDate = null;
            foreach ((array) ($metric['steps_series'] ?? []) as $point) {
                $anchorDate = (string) ($point['date'] ?? $anchorDate);
            }
            if ($anchorDate === null || $anchorDate === '') {
                $anchorDate = (new DateTimeImmutable('today'))->format('Y-m-d');
            }
            $monthPrefix = substr($anchorDate, 0, 7);

            if ($metricKey === 'steps' || $metricKey === 'distance_km') {
                $sum = 0.0;
                foreach ((array) ($metric['steps_series'] ?? []) as $point) {
                    $date = (string) ($point['date'] ?? '');
                    if (!str_starts_with($date, $monthPrefix)) {
                        continue;
                    }
                    $sum += $metricKey === 'steps'
                        ? (float) ($point['steps'] ?? 0)
                        : (float) ($point['km'] ?? 0);
                }
                $value += $sum;
            } elseif ($metricKey === 'workouts' || str_starts_with($metricKey, 'habit:')) {
                $sum = 0.0;
                $habitCode = str_starts_with($metricKey, 'habit:') ? substr($metricKey, 6) : '';
                foreach ((array) ($metric['weekly'] ?? []) as $week) {
                    $weekStart = (string) ($week['week_start'] ?? '');
                    if (!str_starts_with($weekStart, $monthPrefix)) {
                        continue;
                    }
                    if ($metricKey === 'workouts') {
                        $sum += (float) ($week['workouts'] ?? 0);
                    } else {
                        $sum += (float) (($week['habit_counts'][$habitCode] ?? 0));
                    }
                }
                $value += $sum;
            } elseif ($metricKey === 'score') {
                $value += (float) ($metric['score'] ?? 0);
            } elseif ($metricKey === 'strikes') {
                $value += (float) ($metric['current_strikes'] ?? 0);
            } elseif ($metricKey === 'penalties') {
                $value += (float) ($metric['total_penalty'] ?? 0);
            } elseif ($metricKey === 'weight') {
                $value += (float) ($metric['latest_weight'] ?? 0);
            }
        }
        if (($metricKey === 'score' || $metricKey === 'weight') && count($metrics) > 1) {
            $value = $value / count($metrics);
        }

        return $value;
    }

    if ($window === 'week') {
        $best = 0.0;
        foreach ($metrics as $metric) {
            foreach (($metric['weekly'] ?? []) as $week) {
                if ($metricKey === 'steps') {
                    $weekValue = (float) ($week['steps'] ?? 0);
                } elseif ($metricKey === 'distance_km') {
                    $weekValue = (float) ($week['km'] ?? 0);
                } elseif ($metricKey === 'workouts') {
                    $weekValue = (float) ($week['workouts'] ?? 0);
                } elseif ($metricKey === 'score') {
                    $weekValue = max(
                        0.0,
                        100.0 - (
                            ((int) ($week['step_failures'] ?? 0) * 6) +
                            ((int) ($week['workout_failures'] ?? 0) * 8) +
                            ((int) ($week['skip_warnings'] ?? 0) * 3) +
                            ((int) ($week['strikes_after_week'] ?? 0) * 4)
                        )
                    );
                } elseif ($metricKey === 'strikes') {
                    $weekValue = (float) ($week['strikes_after_week'] ?? 0);
                } elseif ($metricKey === 'penalties') {
                    $weekValue = (float) ($week['penalty'] ?? 0);
                } elseif ($metricKey === 'weight') {
                    $weekValue = (float) ($metric['latest_weight'] ?? 0);
                } elseif (str_starts_with($metricKey, 'habit:')) {
                    $code = substr($metricKey, 6);
                    $weekValue = (float) (($week['habit_counts'][$code] ?? 0));
                } else {
                    $weekValue = 0.0;
                }
                $best = max($best, $weekValue);
            }
        }

        return $best;
    }

    $value = 0.0;
    foreach ($metrics as $metric) {
        if ($metricKey === 'steps') {
            $value += (float) ($metric['total_steps'] ?? 0);
        } elseif ($metricKey === 'distance_km') {
            $value += (float) ($metric['total_km'] ?? 0);
        } elseif ($metricKey === 'workouts') {
            $value += max((float) ($metric['workout_count'] ?? 0), (float) ($metric['workout_success'] ?? 0));
        } elseif ($metricKey === 'score') {
            $value += (float) ($metric['score'] ?? 0);
        } elseif ($metricKey === 'strikes') {
            $value += (float) ($metric['current_strikes'] ?? 0);
        } elseif ($metricKey === 'penalties') {
            $value += (float) ($metric['total_penalty'] ?? 0);
        } elseif ($metricKey === 'weight') {
            $value += (float) ($metric['latest_weight'] ?? 0);
        } elseif (str_starts_with($metricKey, 'habit:')) {
            $code = substr($metricKey, 6);
            $value += (float) (($metric['habit_counts'][$code] ?? 0));
        }
    }

    if (($metricKey === 'score' || $metricKey === 'weight') && count($metrics) > 1) {
        $value = $value / count($metrics);
    }

    return $value;
}

function rule_matches(float $value, string $operator, float $target): bool
{
    return match ($operator) {
        '>' => $value > $target,
        '<' => $value < $target,
        '<=' => $value <= $target,
        '=' => abs($value - $target) < 0.0001,
        default => $value >= $target,
    };
}

function has_three_workouts_in_week(PDO $pdo, int $userId): bool
{
    $rows = db_fetch_all(
        $pdo,
        'SELECT dl.log_date, dl.workout_done, dl.junk_food, dl.extra_workout,
                COALESCE(w.workout_entry_count, CASE WHEN dl.workout_done = 1 THEN 1 ELSE 0 END) AS workout_entry_count
         FROM daily_logs dl
         LEFT JOIN (
            SELECT log_id, COUNT(*) AS workout_entry_count
            FROM daily_log_workouts
            GROUP BY log_id
         ) w ON w.log_id = dl.id
         WHERE dl.user_id = :user_id AND dl.workout_done = 1
         ORDER BY dl.log_date ASC',
        [':user_id' => $userId]
    );
    if ($rows === []) {
        return false;
    }

    $dates = array_values(array_filter(array_map(static fn(array $row): string => (string) ($row['log_date'] ?? ''), $rows)));
    $approvalsByUser = $dates !== []
        ? load_approval_status_by_user_date($pdo, min($dates), max($dates))
        : [];
    $approvalsByDate = is_array($approvalsByUser[$userId] ?? null) ? (array) $approvalsByUser[$userId] : [];
    $counts = [];
    foreach ($rows as $row) {
        $date = (string) ($row['log_date'] ?? '');
        $week = week_start_for(new DateTimeImmutable($date))->format('Y-m-d');
        $counts[$week] = ($counts[$week] ?? 0) + counted_workout_total($row, $approvalsByDate, $date);
        if ($counts[$week] >= 3) {
            return true;
        }
    }

    return false;
}

function team_summary_from_metrics(array $metrics): array
{
    $totals = [
        'members' => count($metrics),
        'score_avg' => 0,
        'steps_success' => 0,
        'steps_required' => 0,
        'total_steps' => 0,
        'total_km' => 0,
        'workout_count' => 0,
        'workout_success' => 0,
        'workout_target' => 0,
        'strikes' => 0,
        'penalty' => 0,
    ];

    foreach ($metrics as $metric) {
        $totals['score_avg'] += (float) $metric['score'];
        $totals['steps_success'] += (int) $metric['steps_success'];
        $totals['steps_required'] += (int) $metric['steps_required'];
        $totals['total_steps'] += (int) ($metric['total_steps'] ?? 0);
        $totals['total_km'] += (float) ($metric['total_km'] ?? 0);
        $totals['workout_count'] += (int) ($metric['workout_count'] ?? $metric['workout_success'] ?? 0);
        $totals['workout_success'] += (int) $metric['workout_success'];
        $totals['workout_target'] += (int) $metric['workout_target'];
        $totals['strikes'] += (int) $metric['current_strikes'];
        $totals['penalty'] += (int) $metric['total_penalty'];
    }

    if ($totals['members'] > 0) {
        $totals['score_avg'] = round($totals['score_avg'] / $totals['members'], 1);
    }
    $totals['total_km'] = round((float) $totals['total_km'], 2);

    return $totals;
}

function metric_week_row_for_view(array $metric, string $view, ?string $selectedWeekStart = null): ?array
{
    $weekly = array_values((array) ($metric['weekly'] ?? []));
    if ($weekly === []) {
        return null;
    }

    usort(
        $weekly,
        static fn(array $left, array $right): int => strcmp((string) ($left['week_start'] ?? ''), (string) ($right['week_start'] ?? ''))
    );

    if ($view === 'current_week') {
        return $weekly[count($weekly) - 1];
    }

    if ($view !== 'total') {
        $needle = to_date($view, (string) ($weekly[count($weekly) - 1]['week_start'] ?? to_date(null)));
        try {
            $needle = week_start_for(new DateTimeImmutable($needle))->format('Y-m-d');
        } catch (Throwable) {
            // Keep the normalized date fallback from to_date.
        }
        foreach ($weekly as $row) {
            if ((string) ($row['week_start'] ?? '') === $needle) {
                return $row;
            }
        }
    }

    return $weekly[count($weekly) - 1];
}

function score_weights_for_progress(?float $weightProgressPct): array
{
    if ($weightProgressPct === null) {
        return [
            'steps' => 0.4,
            'workouts' => 0.4,
            'discipline' => 0.2,
            'weight' => 0.0,
        ];
    }

    return [
        'steps' => 0.3,
        'workouts' => 0.3,
        'discipline' => 0.2,
        'weight' => 0.2,
    ];
}

function score_components_from_progress(
    float $stepsProgressPct,
    float $workoutsProgressPct,
    float $disciplineScore,
    ?float $weightProgressPct
): array {
    $normalize = static fn(float $value): float => max(0.0, min(100.0, $value));
    $steps = $normalize($stepsProgressPct);
    $workouts = $normalize($workoutsProgressPct);
    $discipline = $normalize($disciplineScore);
    $weight = $weightProgressPct !== null ? $normalize($weightProgressPct) : null;
    $weights = score_weights_for_progress($weight);

    $components = [
        'steps' => round($steps * (float) ($weights['steps'] ?? 0), 2),
        'workouts' => round($workouts * (float) ($weights['workouts'] ?? 0), 2),
        'discipline' => round($discipline * (float) ($weights['discipline'] ?? 0), 2),
    ];
    if ($weight !== null && (float) ($weights['weight'] ?? 0) > 0) {
        $components['weight'] = round($weight * (float) $weights['weight'], 2);
    }

    return $components;
}

function score_value_from_components(array $components): float
{
    return round(array_sum(array_map(static fn(mixed $value): float => (float) $value, $components)), 1);
}

function score_breakdown_from_snapshot(array $metric, array $snapshot): array
{
    $stepsProgress = max(0.0, min(100.0, (float) ($snapshot['step_completion_pct'] ?? 0)));
    $workoutsProgress = max(0.0, min(100.0, (float) ($snapshot['workout_completion_pct'] ?? 0)));
    $disciplineScore = max(0.0, min(100.0, (float) ($snapshot['discipline_score'] ?? 0)));
    $weightProgress = null;
    if (array_key_exists('weight_progress', $snapshot) && $snapshot['weight_progress'] !== null && is_numeric($snapshot['weight_progress'])) {
        $weightProgress = max(0.0, min(100.0, (float) $snapshot['weight_progress']));
    }
    $weights = score_weights_for_progress($weightProgress);
    $components = score_components_from_progress($stepsProgress, $workoutsProgress, $disciplineScore, $weightProgress);
    $score = score_value_from_components($components);

    return [
        'steps_progress' => round($stepsProgress, 1),
        'workouts_progress' => round($workoutsProgress, 1),
        'discipline_score' => round($disciplineScore, 1),
        'weight_progress' => $weightProgress !== null ? round($weightProgress, 1) : null,
        'weights' => $weights,
        'components' => $components,
        'score' => $score,
        'formula' => $weightProgress === null
            ? 'Score = (Steps x 40%) + (Workouts x 40%) + (Discipline x 20%)'
            : 'Score = (Steps x 30%) + (Workouts x 30%) + (Discipline x 20%) + (Weight x 20%)',
        'has_weight' => $weightProgress !== null,
        'current_strikes' => max(0, (int) ($snapshot['strikes'] ?? 0)),
        'current_penalty' => max(0.0, (float) ($snapshot['penalty'] ?? 0)),
    ];
}

function metric_snapshot_for_view(array $metric, string $view): array
{
    $weightProgress = null;
    if (array_key_exists('weight_progress_pct', $metric) && $metric['weight_progress_pct'] !== null && is_numeric($metric['weight_progress_pct'])) {
        $weightProgress = (float) $metric['weight_progress_pct'];
    }
    $workoutsForWeek = static function (array $row): int {
        $workouts = max(0, (int) ($row['workouts'] ?? 0));
        $success = array_key_exists('workout_success_week', $row)
            ? max(0, (int) ($row['workout_success_week'] ?? 0))
            : 0;
        if (array_key_exists('workout_target_week', $row)) {
            $success = max(
                $success,
                max(0, (int) ($row['workout_target_week'] ?? 0) - (int) ($row['workout_failures'] ?? 0))
            );
        }

        return max($workouts, $success);
    };
    $strikesForWeek = static fn(array $row): int => max(
        0,
        (int) ($row['total_failures'] ?? ((int) ($row['step_failures'] ?? 0) + (int) ($row['workout_failures'] ?? 0)))
        - (int) ($row['strike_reduction'] ?? 0)
    );

    if ($view === 'total') {
        $stepsCompletionPct = round((float) ($metric['step_completion_pct'] ?? 0), 1);
        $totalWorkouts = max(0, (int) max((int) ($metric['workout_count'] ?? 0), (int) ($metric['workout_success'] ?? 0)));
        $workoutTarget = max(0, (int) ($metric['workout_target'] ?? 0));
        $workoutCompletionPct = $workoutTarget > 0 ? round(($totalWorkouts / $workoutTarget) * 100, 1) : 0.0;
        $disciplineScore = max(
            0.0,
            100.0 - min(
                100.0,
                (max(0, (int) ($metric['current_strikes'] ?? 0)) * 10)
                + (max(0, (int) ($metric['skip_warning_events'] ?? 0)) * 3)
            )
        );
        $scoreComponents = score_components_from_progress($stepsCompletionPct, $workoutCompletionPct, $disciplineScore, $weightProgress);
        $score = score_value_from_components($scoreComponents);

        return [
            'steps' => (int) ($metric['total_steps'] ?? 0),
            'distance_km' => round((float) ($metric['total_km'] ?? 0), 2),
            'workouts' => $totalWorkouts,
            'workout_target' => $workoutTarget,
            'score' => $score,
            'strikes' => max(0, (int) ($metric['current_strikes'] ?? 0)),
            'penalty' => max(0.0, (float) ($metric['total_penalty'] ?? 0)),
            'weight_progress' => $weightProgress,
            'step_completion_pct' => $stepsCompletionPct,
            'workout_completion_pct' => $workoutCompletionPct,
            'discipline_score' => round($disciplineScore, 1),
            'score_components' => $scoreComponents,
        ];
    }

    $weekRow = metric_week_row_for_view($metric, $view, $view);
    if (!is_array($weekRow)) {
        return [
            'steps' => 0,
            'distance_km' => 0.0,
            'workouts' => 0,
            'workout_target' => 0,
            'score' => 0.0,
            'strikes' => 0,
            'penalty' => 0.0,
            'weight_progress' => $weightProgress,
            'step_completion_pct' => 0.0,
            'workout_completion_pct' => 0.0,
            'discipline_score' => 100.0,
            'score_components' => score_components_from_progress(0.0, 0.0, 100.0, $weightProgress),
        ];
    }

    $weekStepRequired = max(
        0,
        (int) ($weekRow['step_days_required_week'] ?? ((int) ($weekRow['step_days_success_week'] ?? 0) + (int) ($weekRow['step_failures'] ?? 0)))
    );
    $weekStepSuccess = max(
        0,
        min($weekStepRequired, (int) ($weekRow['step_days_success_week'] ?? ($weekStepRequired - (int) ($weekRow['step_failures'] ?? 0))))
    );
    $weekWorkoutTarget = max(0, (int) ($weekRow['workout_target_week'] ?? 0));
    $weekWorkouts = $workoutsForWeek($weekRow);
    $stepCompletionPct = $weekStepRequired > 0 ? round(($weekStepSuccess / $weekStepRequired) * 100, 1) : 0.0;
    $workoutCompletionPct = $weekWorkoutTarget > 0 ? round(($weekWorkouts / $weekWorkoutTarget) * 100, 1) : 0.0;
    $weekStrikes = $strikesForWeek($weekRow);
    $weekWarnings = max(0, (int) ($weekRow['skip_warnings'] ?? 0));
    $disciplineScore = max(0.0, 100.0 - min(100.0, ($weekStrikes * 10) + ($weekWarnings * 3)));
    $scoreComponents = score_components_from_progress($stepCompletionPct, $workoutCompletionPct, $disciplineScore, $weightProgress);
    $weekScore = score_value_from_components($scoreComponents);

    return [
        'steps' => (int) ($weekRow['steps'] ?? 0),
        'distance_km' => round((float) ($weekRow['km'] ?? 0), 2),
        'workouts' => $weekWorkouts,
        'workout_target' => $weekWorkoutTarget,
        'score' => $weekScore,
        'strikes' => $weekStrikes,
        'penalty' => max(0.0, (float) ($weekRow['penalty'] ?? 0)),
        'weight_progress' => $weightProgress,
        'step_completion_pct' => $stepCompletionPct,
        'workout_completion_pct' => $workoutCompletionPct,
        'discipline_score' => round($disciplineScore, 1),
        'score_components' => $scoreComponents,
    ];
}

function normalize_strike_review_reason(string $reason): string
{
    $normalized = strtolower(trim($reason));
    if (in_array($normalized, ['steps', 'step', 'step_miss'], true)) {
        return 'step_miss';
    }
    if (in_array($normalized, ['workout', 'workouts', 'workout_miss'], true)) {
        return 'workout_miss';
    }
    if ($normalized === 'warning') {
        return 'warning';
    }

    return 'step_miss';
}

function strike_event_reason_from_review_reason(string $reason): string
{
    return match (normalize_strike_review_reason($reason)) {
        'workout_miss' => 'workout',
        'warning' => 'warning',
        default => 'steps',
    };
}

function strike_review_reason_from_event_reason(string $reason): string
{
    $normalized = strtolower(trim($reason));

    return match ($normalized) {
        'workout', 'workout_miss' => 'workout_miss',
        'warning' => 'warning',
        default => 'step_miss',
    };
}

function strike_review_reason_label(string $reason): string
{
    return match (normalize_strike_review_reason($reason)) {
        'workout_miss' => t('strikes.reason_workout_miss'),
        'warning' => t('strikes.reason_warning'),
        default => t('strikes.reason_step_miss'),
    };
}

function strike_review_status_label(string $status): string
{
    $normalized = strtolower(trim($status));

    return match ($normalized) {
        'pending' => t('strikes.status_pending'),
        'accepted' => t('strikes.status_accepted'),
        'rejected' => t('strikes.status_rejected'),
        default => t('strikes.status_confirmed'),
    };
}

function strike_review_event_key(int $targetUserId, string $weekStart, string $eventDate, string $reason): string
{
    return $targetUserId . '|' . $weekStart . '|' . $eventDate . '|' . normalize_strike_review_reason($reason);
}

function decode_int_list_json(?string $json): array
{
    if ($json === null || trim($json) === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }
    $ids = array_map(static fn(mixed $value): int => (int) $value, $decoded);
    $ids = array_values(array_filter($ids, static fn(int $id): bool => $id > 0));

    return array_values(array_unique($ids));
}

function build_strike_review_eligible_voters(PDO $pdo, int $requesterUserId): array
{
    $rows = db_fetch_all($pdo, 'SELECT id FROM users WHERE active = 1 ORDER BY display_name ASC');
    $ids = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0 || $id === $requesterUserId) {
            continue;
        }
        $ids[] = $id;
    }

    return array_values(array_unique($ids));
}

function fetch_strike_review_request_for_event(
    PDO $pdo,
    int $targetUserId,
    string $weekStart,
    string $eventDate,
    string $reason
): ?array {
    if ($targetUserId <= 0) {
        return null;
    }

    return db_fetch_one(
        $pdo,
        'SELECT * FROM strike_review_requests
         WHERE target_user_id = :target_user_id
           AND week_start = :week_start
           AND event_date = :event_date
           AND reason = :reason
         LIMIT 1',
        [
            ':target_user_id' => $targetUserId,
            ':week_start' => to_date($weekStart),
            ':event_date' => to_date($eventDate),
            ':reason' => normalize_strike_review_reason($reason),
        ]
    );
}

function fetch_strike_review_requests_for_user_between(PDO $pdo, int $targetUserId, string $startDate, string $endDate): array
{
    if ($targetUserId <= 0) {
        return [];
    }

    return db_fetch_all(
        $pdo,
        'SELECT r.*, requester.display_name AS requested_by_name
         FROM strike_review_requests r
         LEFT JOIN users requester ON requester.id = r.requested_by
         WHERE r.target_user_id = :target_user_id
           AND r.event_date BETWEEN :start_date AND :end_date
         ORDER BY r.event_date ASC, r.created_at ASC',
        [
            ':target_user_id' => $targetUserId,
            ':start_date' => to_date($startDate),
            ':end_date' => to_date($endDate),
        ]
    );
}

function create_strike_review_request(
    PDO $pdo,
    int $targetUserId,
    string $weekStart,
    string $eventDate,
    string $reason,
    string $comment,
    int $requesterUserId
): array {
    if ($targetUserId <= 0 || $requesterUserId <= 0) {
        return ['ok' => false, 'message' => t('flash.no_permission')];
    }
    if ($targetUserId !== $requesterUserId) {
        return ['ok' => false, 'message' => t('strikes.request_only_owner')];
    }
    $trimmedComment = trim($comment);
    if (function_exists('mb_substr')) {
        $trimmedComment = mb_substr($trimmedComment, 0, 1200);
    } else {
        $trimmedComment = substr($trimmedComment, 0, 1200);
    }
    if ($trimmedComment === '') {
        return ['ok' => false, 'message' => t('strikes.comment_required')];
    }

    $normalizedWeekStart = to_date($weekStart);
    try {
        $normalizedWeekStart = week_start_for(new DateTimeImmutable($normalizedWeekStart))->format('Y-m-d');
    } catch (Throwable) {
        // Keep normalized date.
    }
    $normalizedEventDate = to_date($eventDate);
    $normalizedReason = normalize_strike_review_reason($reason);
    $existing = fetch_strike_review_request_for_event(
        $pdo,
        $targetUserId,
        $normalizedWeekStart,
        $normalizedEventDate,
        $normalizedReason
    );
    if ($existing !== null) {
        return ['ok' => false, 'message' => t('strikes.request_already_exists'), 'request' => $existing];
    }

    $eligibleVoters = build_strike_review_eligible_voters($pdo, $requesterUserId);
    $status = $eligibleVoters === [] ? 'rejected' : 'pending';
    $now = now_iso();
    db_execute(
        $pdo,
        'INSERT INTO strike_review_requests (
            target_user_id, week_start, event_date, reason, comment, status, requested_by, eligible_voters_json, resent_count, resolved_at, created_at, updated_at
        ) VALUES (
            :target_user_id, :week_start, :event_date, :reason, :comment, :status, :requested_by, :eligible_voters_json, 0, :resolved_at, :created_at, :updated_at
        )',
        [
            ':target_user_id' => $targetUserId,
            ':week_start' => $normalizedWeekStart,
            ':event_date' => $normalizedEventDate,
            ':reason' => $normalizedReason,
            ':comment' => $trimmedComment,
            ':status' => $status,
            ':requested_by' => $requesterUserId,
            ':eligible_voters_json' => json_encode($eligibleVoters, JSON_UNESCAPED_SLASHES),
            ':resolved_at' => $status === 'pending' ? null : $now,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]
    );
    $created = db_fetch_one($pdo, 'SELECT * FROM strike_review_requests WHERE id = last_insert_rowid()');
    if ($created === null) {
        return ['ok' => false, 'message' => t('flash.save_failed')];
    }

    if ($status === 'pending') {
        foreach ($eligibleVoters as $voterId) {
            upsert_user_notification(
                $pdo,
                $voterId,
                'strike_review_request',
                t('strikes.notification_request_title'),
                t('strikes.notification_request_message'),
                'strike_review_request:' . (int) $created['id'],
                [
                    'request_id' => (int) $created['id'],
                    'target_user_id' => $targetUserId,
                    'week_start' => $normalizedWeekStart,
                    'view' => $normalizedWeekStart,
                    'event_date' => $normalizedEventDate,
                    'reason' => $normalizedReason,
                ]
            );
        }
    }

    return [
        'ok' => true,
        'message' => $status === 'pending' ? t('strikes.request_sent') : t('strikes.request_auto_rejected'),
        'request' => $created,
    ];
}

function resend_strike_review_request(PDO $pdo, int $requestId, string $comment, int $actorUserId, bool $isAdmin = false): array
{
    if ($requestId <= 0 || $actorUserId <= 0) {
        return ['ok' => false, 'message' => t('flash.no_permission')];
    }
    $request = db_fetch_one($pdo, 'SELECT * FROM strike_review_requests WHERE id = :id', [':id' => $requestId]);
    if ($request === null) {
        return ['ok' => false, 'message' => t('flash.not_found')];
    }
    $ownerId = (int) ($request['requested_by'] ?? 0);
    if ($ownerId !== $actorUserId && !$isAdmin) {
        return ['ok' => false, 'message' => t('flash.no_permission')];
    }
    $trimmedComment = trim($comment);
    if (function_exists('mb_substr')) {
        $trimmedComment = mb_substr($trimmedComment, 0, 1200);
    } else {
        $trimmedComment = substr($trimmedComment, 0, 1200);
    }
    if ($trimmedComment === '') {
        return ['ok' => false, 'message' => t('strikes.comment_required')];
    }

    $eligibleVoters = build_strike_review_eligible_voters($pdo, $ownerId);
    $status = $eligibleVoters === [] ? 'rejected' : 'pending';
    $now = now_iso();
    db_execute($pdo, 'DELETE FROM strike_review_votes WHERE request_id = :request_id', [':request_id' => $requestId]);
    db_execute(
        $pdo,
        'UPDATE strike_review_requests
         SET comment = :comment,
             status = :status,
             eligible_voters_json = :eligible_voters_json,
             resent_count = resent_count + 1,
             resolved_at = :resolved_at,
             updated_at = :updated_at
         WHERE id = :id',
        [
            ':comment' => $trimmedComment,
            ':status' => $status,
            ':eligible_voters_json' => json_encode($eligibleVoters, JSON_UNESCAPED_SLASHES),
            ':resolved_at' => $status === 'pending' ? null : $now,
            ':updated_at' => $now,
            ':id' => $requestId,
        ]
    );

    $updated = db_fetch_one($pdo, 'SELECT * FROM strike_review_requests WHERE id = :id', [':id' => $requestId]);
    if ($updated === null) {
        return ['ok' => false, 'message' => t('flash.save_failed')];
    }

    if ($status === 'pending') {
        foreach ($eligibleVoters as $voterId) {
            upsert_user_notification(
                $pdo,
                $voterId,
                'strike_review_request',
                t('strikes.notification_request_title'),
                t('strikes.notification_resend_message'),
                'strike_review_request:' . $requestId,
                [
                    'request_id' => $requestId,
                    'target_user_id' => (int) ($updated['target_user_id'] ?? 0),
                    'week_start' => (string) ($updated['week_start'] ?? ''),
                    'view' => (string) ($updated['week_start'] ?? ''),
                    'event_date' => (string) ($updated['event_date'] ?? ''),
                    'reason' => (string) ($updated['reason'] ?? ''),
                ]
            );
        }
    }

    return [
        'ok' => true,
        'message' => $status === 'pending' ? t('strikes.request_resent') : t('strikes.request_auto_rejected'),
        'request' => $updated,
    ];
}

function vote_strike_review_request(PDO $pdo, int $requestId, int $voterUserId, string $vote): array
{
    if ($requestId <= 0 || $voterUserId <= 0) {
        return ['ok' => false, 'message' => t('flash.no_permission')];
    }
    $normalizedVote = strtolower(trim($vote));
    if (!in_array($normalizedVote, ['accept', 'reject'], true)) {
        return ['ok' => false, 'message' => t('approval.invalid_decision')];
    }

    $request = db_fetch_one($pdo, 'SELECT * FROM strike_review_requests WHERE id = :id', [':id' => $requestId]);
    if ($request === null) {
        return ['ok' => false, 'message' => t('flash.not_found')];
    }
    if ((string) ($request['status'] ?? '') !== 'pending') {
        return ['ok' => false, 'message' => t('strikes.request_not_pending')];
    }

    $eligibleVoters = decode_int_list_json((string) ($request['eligible_voters_json'] ?? '[]'));
    if (!in_array($voterUserId, $eligibleVoters, true)) {
        return ['ok' => false, 'message' => t('flash.no_permission')];
    }

    $now = now_iso();
    db_execute(
        $pdo,
        'INSERT INTO strike_review_votes (request_id, voter_user_id, vote, created_at, updated_at)
         VALUES (:request_id, :voter_user_id, :vote, :created_at, :updated_at)
         ON CONFLICT(request_id, voter_user_id) DO UPDATE SET
            vote = excluded.vote,
            updated_at = excluded.updated_at',
        [
            ':request_id' => $requestId,
            ':voter_user_id' => $voterUserId,
            ':vote' => $normalizedVote,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]
    );

    $votes = db_fetch_all($pdo, 'SELECT * FROM strike_review_votes WHERE request_id = :request_id', [':request_id' => $requestId]);
    $votedUserIds = [];
    $acceptCount = 0;
    $rejectCount = 0;
    foreach ($votes as $row) {
        $uid = (int) ($row['voter_user_id'] ?? 0);
        if ($uid > 0) {
            $votedUserIds[$uid] = true;
        }
        if ((string) ($row['vote'] ?? '') === 'accept') {
            $acceptCount++;
        } else {
            $rejectCount++;
        }
    }

    $allVoted = true;
    foreach ($eligibleVoters as $eligibleId) {
        if (!isset($votedUserIds[$eligibleId])) {
            $allVoted = false;
            break;
        }
    }

    if (!$allVoted) {
        return ['ok' => true, 'message' => t('strikes.vote_saved_pending'), 'resolved' => false];
    }

    $finalStatus = $acceptCount > $rejectCount ? 'accepted' : 'rejected';
    db_execute(
        $pdo,
        'UPDATE strike_review_requests
         SET status = :status, resolved_at = :resolved_at, updated_at = :updated_at
         WHERE id = :id',
        [
            ':status' => $finalStatus,
            ':resolved_at' => $now,
            ':updated_at' => $now,
            ':id' => $requestId,
        ]
    );

    $resolved = db_fetch_one($pdo, 'SELECT * FROM strike_review_requests WHERE id = :id', [':id' => $requestId]);
    if ($resolved !== null) {
        $requesterId = (int) ($resolved['requested_by'] ?? 0);
        if ($requesterId > 0) {
            upsert_user_notification(
                $pdo,
                $requesterId,
                'strike_review_resolved',
                t('strikes.notification_resolved_title'),
                t(
                    'strikes.notification_resolved_message',
                    ['status' => strike_review_status_label((string) ($resolved['status'] ?? 'rejected'))]
                ),
                'strike_review_resolved:' . $requestId,
                [
                    'request_id' => $requestId,
                    'status' => (string) ($resolved['status'] ?? 'rejected'),
                    'target_user_id' => (int) ($resolved['target_user_id'] ?? 0),
                    'week_start' => (string) ($resolved['week_start'] ?? ''),
                    'view' => (string) ($resolved['week_start'] ?? ''),
                    'event_date' => (string) ($resolved['event_date'] ?? ''),
                ]
            );
        }
    }

    return [
        'ok' => true,
        'message' => t(
            'strikes.request_resolved_with_status',
            ['status' => strike_review_status_label($finalStatus)]
        ),
        'resolved' => true,
        'status' => $finalStatus,
    ];
}

function accepted_strike_review_rows_by_user(PDO $pdo, array $userIds): array
{
    $normalizedIds = [];
    foreach ($userIds as $userId) {
        $value = (int) $userId;
        if ($value > 0) {
            $normalizedIds[$value] = true;
        }
    }
    if ($normalizedIds === []) {
        return [];
    }

    $params = [];
    $placeholders = [];
    foreach (array_keys($normalizedIds) as $index => $userId) {
        $key = ':accepted_uid_' . $index;
        $placeholders[] = $key;
        $params[$key] = $userId;
    }

    $rows = db_fetch_all(
        $pdo,
        'SELECT target_user_id, week_start, event_date, reason
         FROM strike_review_requests
         WHERE status = "accepted"
           AND target_user_id IN (' . implode(',', $placeholders) . ')',
        $params
    );

    $grouped = [];
    foreach ($rows as $row) {
        $targetUserId = (int) ($row['target_user_id'] ?? 0);
        if ($targetUserId <= 0) {
            continue;
        }
        if (!isset($grouped[$targetUserId])) {
            $grouped[$targetUserId] = [];
        }
        $grouped[$targetUserId][] = $row;
    }

    return $grouped;
}

function apply_strike_review_overrides_to_metric(PDO $pdo, array $metric, ?array $acceptedRows = null, ?bool $penaltiesEnabled = null): array
{
    $userId = (int) ($metric['user']['id'] ?? 0);
    if ($userId <= 0) {
        return $metric;
    }

    if ($acceptedRows === null) {
        $acceptedRows = db_fetch_all(
            $pdo,
            'SELECT week_start, event_date, reason
             FROM strike_review_requests
             WHERE target_user_id = :target_user_id AND status = "accepted"',
            [':target_user_id' => $userId]
        );
    }
    if ($acceptedRows === []) {
        return $metric;
    }

    $accepted = [];
    foreach ($acceptedRows as $row) {
        $weekStart = to_date((string) ($row['week_start'] ?? ''));
        try {
            $weekStart = week_start_for(new DateTimeImmutable($weekStart))->format('Y-m-d');
        } catch (Throwable) {
            // Keep normalized date.
        }
        $eventDate = to_date((string) ($row['event_date'] ?? ''));
        $reason = normalize_strike_review_reason((string) ($row['reason'] ?? 'step_miss'));
        $accepted[strike_review_event_key($userId, $weekStart, $eventDate, $reason)] = true;
    }

    $weekly = array_values((array) ($metric['weekly'] ?? []));
    if ($weekly === []) {
        return $metric;
    }
    if ($penaltiesEnabled === null) {
        $penaltiesEnabled = penalties_enabled($pdo);
    }
    usort(
        $weekly,
        static fn(array $left, array $right): int => strcmp((string) ($left['week_start'] ?? ''), (string) ($right['week_start'] ?? ''))
    );

    $runningStrikes = 0;
    $totalPenalty = 0;
    $perfectWeekStreak = 0;
    $stepFailuresTotal = 0;
    $workoutFailuresTotal = 0;
    $warningTotal = 0;
    $rebuiltWeekly = [];

    foreach ($weekly as $weekRow) {
        $weekStart = to_date((string) ($weekRow['week_start'] ?? ''));
        $failureEvents = array_values(is_array($weekRow['failure_events'] ?? null) ? (array) $weekRow['failure_events'] : []);
        $warningEvents = array_values(is_array($weekRow['warning_events'] ?? null) ? (array) $weekRow['warning_events'] : []);

        $remainingFailureEvents = [];
        foreach ($failureEvents as $event) {
            $eventDate = to_date((string) ($event['date'] ?? $weekStart), $weekStart);
            $reason = strike_review_reason_from_event_reason((string) ($event['reason'] ?? 'steps'));
            $key = strike_review_event_key($userId, $weekStart, $eventDate, $reason);
            if (isset($accepted[$key])) {
                continue;
            }
            $remainingFailureEvents[] = [
                'date' => $eventDate,
                'reason' => strike_event_reason_from_review_reason($reason),
            ];
        }
        usort(
            $remainingFailureEvents,
            static function (array $a, array $b): int {
                if ((string) ($a['date'] ?? '') === (string) ($b['date'] ?? '')) {
                    return strcmp((string) ($a['reason'] ?? ''), (string) ($b['reason'] ?? ''));
                }

                return strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? ''));
            }
        );

        $remainingWarningEvents = [];
        foreach ($warningEvents as $event) {
            $eventDate = to_date((string) ($event['date'] ?? $weekStart), $weekStart);
            $reason = normalize_strike_review_reason((string) ($event['reason'] ?? 'warning'));
            $key = strike_review_event_key($userId, $weekStart, $eventDate, $reason);
            if (isset($accepted[$key])) {
                continue;
            }
            $remainingWarningEvents[] = [
                'date' => $eventDate,
                'reason' => 'warning',
            ];
        }
        usort(
            $remainingWarningEvents,
            static fn(array $a, array $b): int => strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? ''))
        );

        $weekStepFailures = 0;
        $weekWorkoutFailures = 0;
        foreach ($remainingFailureEvents as $event) {
            if ((string) ($event['reason'] ?? '') === 'workout') {
                $weekWorkoutFailures++;
            } else {
                $weekStepFailures++;
            }
        }

        $weekPenalty = 0;
        $weekFailureDetailed = [];
        foreach ($remainingFailureEvents as $event) {
            $runningStrikes++;
            $amount = $penaltiesEnabled ? penalty_for_strike($runningStrikes) : 0;
            $totalPenalty += $amount;
            $weekPenalty += $amount;
            $weekFailureDetailed[] = [
                'date' => (string) ($event['date'] ?? ''),
                'reason' => (string) ($event['reason'] ?? ''),
                'strike_number' => $runningStrikes,
                'amount' => $amount,
            ];
        }

        $weekReduction = 0;
        $isComplete = (string) ($weekRow['status'] ?? '') === 'complete';
        if ($isComplete) {
            if (count($remainingFailureEvents) === 0) {
                $perfectWeekStreak++;
                if ($perfectWeekStreak === 2 && $runningStrikes > 0) {
                    $runningStrikes--;
                    $weekReduction = 1;
                    $perfectWeekStreak = 0;
                }
            } else {
                $perfectWeekStreak = 0;
            }
        }

        $weekStepRequired = max(
            0,
            (int) ($weekRow['step_days_required_week'] ?? ((int) ($weekRow['step_days_success_week'] ?? 0) + (int) ($weekRow['step_failures'] ?? 0)))
        );
        $weekStepSuccess = max(0, min($weekStepRequired, $weekStepRequired - $weekStepFailures));
        $weekWorkoutTarget = max(0, (int) ($weekRow['workout_target_week'] ?? 0));
        $weekWorkoutLogged = max(0, (int) ($weekRow['workouts'] ?? 0));
        $weekWorkoutComplianceSuccess = max(0, min($weekWorkoutTarget, $weekWorkoutTarget - $weekWorkoutFailures));
        $weekWorkoutSuccess = max($weekWorkoutLogged, $weekWorkoutComplianceSuccess);
        $weekWarnings = count($remainingWarningEvents);

        $stepFailuresTotal += $weekStepFailures;
        $workoutFailuresTotal += $weekWorkoutFailures;
        $warningTotal += $weekWarnings;

        $weekRow['step_failures'] = $weekStepFailures;
        $weekRow['workout_failures'] = $weekWorkoutFailures;
        $weekRow['total_failures'] = $weekStepFailures + $weekWorkoutFailures;
        $weekRow['skip_warnings'] = $weekWarnings;
        $weekRow['penalty'] = $weekPenalty;
        $weekRow['strike_reduction'] = $weekReduction;
        $weekRow['strikes_after_week'] = $runningStrikes;
        $weekRow['step_days_required_week'] = $weekStepRequired;
        $weekRow['step_days_success_week'] = $weekStepSuccess;
        $weekRow['workout_success_week'] = $weekWorkoutSuccess;
        $weekRow['failure_events'] = $weekFailureDetailed;
        $weekRow['warning_events'] = $remainingWarningEvents;
        $rebuiltWeekly[] = $weekRow;
    }

    $stepsRequired = max(0, (int) ($metric['steps_required'] ?? 0));
    $workoutTarget = max(0, (int) ($metric['workout_target'] ?? 0));
    $stepsSuccess = max(0, min($stepsRequired, $stepsRequired - $stepFailuresTotal));
    $workoutSuccess = 0;
    $workoutCount = 0;
    foreach ($rebuiltWeekly as $weekRow) {
        $workoutSuccess += max(0, (int) ($weekRow['workout_success_week'] ?? 0));
        $workoutCount += max(0, (int) ($weekRow['workouts'] ?? 0));
    }
    $workoutSuccess = max($workoutSuccess, $workoutCount, (int) ($metric['workout_count'] ?? 0));
    $workoutCount = max($workoutCount, (int) ($metric['workout_count'] ?? 0));
    $stepCompletionPct = $stepsRequired > 0 ? round(($stepsSuccess / $stepsRequired) * 100, 1) : 0.0;
    $workoutCompletionPct = $workoutTarget > 0 ? round(($workoutSuccess / $workoutTarget) * 100, 1) : 0.0;
    $disciplinePenalty = min(100.0, ($runningStrikes * 10) + ($warningTotal * 3));
    $disciplineScore = max(0.0, 100.0 - $disciplinePenalty);
    $weightProgress = null;
    if (array_key_exists('weight_progress_pct', $metric) && $metric['weight_progress_pct'] !== null && is_numeric($metric['weight_progress_pct'])) {
        $weightProgress = (float) $metric['weight_progress_pct'];
    }
    $scoreComponents = score_components_from_progress($stepCompletionPct, $workoutCompletionPct, $disciplineScore, $weightProgress);
    $score = score_value_from_components($scoreComponents);

    $metric['weekly'] = $rebuiltWeekly;
    $metric['step_failures'] = $stepFailuresTotal;
    $metric['workout_failures'] = $workoutFailuresTotal;
    $metric['total_failures'] = $stepFailuresTotal + $workoutFailuresTotal;
    $metric['skip_warning_events'] = $warningTotal;
    $metric['current_strikes'] = $runningStrikes;
    $metric['total_penalty'] = $totalPenalty;
    $metric['steps_success'] = $stepsSuccess;
    $metric['workout_success'] = $workoutSuccess;
    $metric['workout_count'] = $workoutCount;
    $metric['step_completion_pct'] = $stepCompletionPct;
    $metric['workout_completion_pct'] = $workoutCompletionPct;
    $metric['discipline_score'] = round($disciplineScore, 1);
    $metric['score_components'] = $scoreComponents;
    $metric['score'] = $score;

    return $metric;
}

function apply_strike_review_overrides_to_metrics(PDO $pdo, array $metricsByUser): array
{
    $userIds = [];
    foreach ($metricsByUser as $metric) {
        if (!is_array($metric)) {
            continue;
        }
        $userId = (int) ($metric['user']['id'] ?? 0);
        if ($userId > 0) {
            $userIds[$userId] = true;
        }
    }
    $acceptedRowsByUser = accepted_strike_review_rows_by_user($pdo, array_keys($userIds));
    $penaltiesEnabled = penalties_enabled($pdo);

    $adjusted = [];
    foreach ($metricsByUser as $userId => $metric) {
        $normalizedMetric = is_array($metric) ? $metric : [];
        $metricUserId = (int) ($normalizedMetric['user']['id'] ?? 0);
        $rowsForUser = $metricUserId > 0
            ? (array) ($acceptedRowsByUser[$metricUserId] ?? [])
            : [];
        $adjusted[$userId] = apply_strike_review_overrides_to_metric($pdo, $normalizedMetric, $rowsForUser, $penaltiesEnabled);
    }

    uasort(
        $adjusted,
        static function (array $a, array $b): int {
            $scoreOrder = ((float) ($b['score'] ?? 0)) <=> ((float) ($a['score'] ?? 0));
            if ($scoreOrder !== 0) {
                return $scoreOrder;
            }

            $penaltyOrder = ((int) ($a['total_penalty'] ?? 0)) <=> ((int) ($b['total_penalty'] ?? 0));
            if ($penaltyOrder !== 0) {
                return $penaltyOrder;
            }

            return strcmp(
                strtolower((string) ($a['user']['display_name'] ?? '')),
                strtolower((string) ($b['user']['display_name'] ?? ''))
            );
        }
    );

    return $adjusted;
}

function metric_week_rows_for_view(array $metric, string $view): array
{
    $weekly = array_values((array) ($metric['weekly'] ?? []));
    if ($weekly === []) {
        return [];
    }
    usort(
        $weekly,
        static fn(array $left, array $right): int => strcmp((string) ($left['week_start'] ?? ''), (string) ($right['week_start'] ?? ''))
    );
    if ($view === 'total') {
        return $weekly;
    }

    $selected = metric_week_row_for_view($metric, $view, $view);

    return is_array($selected) ? [$selected] : [];
}

function build_strike_detail_rows_for_view(PDO $pdo, array $rawMetric, array $adjustedMetric, string $view): array
{
    $userId = (int) ($rawMetric['user']['id'] ?? $adjustedMetric['user']['id'] ?? 0);
    if ($userId <= 0) {
        return [];
    }

    $rawWeekRows = metric_week_rows_for_view($rawMetric, $view);
    $adjustedWeekRows = metric_week_rows_for_view($adjustedMetric, $view);
    if ($rawWeekRows === []) {
        return [];
    }

    $adjustedByKey = [];
    foreach ($adjustedWeekRows as $row) {
        $weekStart = to_date((string) ($row['week_start'] ?? ''));
        foreach ((array) ($row['failure_events'] ?? []) as $event) {
            $eventDate = to_date((string) ($event['date'] ?? $weekStart), $weekStart);
            $reason = strike_review_reason_from_event_reason((string) ($event['reason'] ?? 'steps'));
            $key = strike_review_event_key($userId, $weekStart, $eventDate, $reason);
            $adjustedByKey[$key] = [
                'amount' => max(0.0, (float) ($event['amount'] ?? 0)),
                'strike_number' => max(0, (int) ($event['strike_number'] ?? 0)),
                'counted' => true,
            ];
        }
        foreach ((array) ($row['warning_events'] ?? []) as $event) {
            $eventDate = to_date((string) ($event['date'] ?? $weekStart), $weekStart);
            $key = strike_review_event_key($userId, $weekStart, $eventDate, 'warning');
            $adjustedByKey[$key] = [
                'amount' => 0.0,
                'strike_number' => 0,
                'counted' => true,
            ];
        }
    }

    $startDate = to_date((string) ($rawWeekRows[0]['week_start'] ?? to_date(null)));
    $endDate = to_date((string) ($rawWeekRows[count($rawWeekRows) - 1]['week_end'] ?? $startDate), $startDate);
    $requestsRows = fetch_strike_review_requests_for_user_between($pdo, $userId, $startDate, $endDate);
    $requestsByKey = [];
    foreach ($requestsRows as $request) {
        $weekStart = to_date((string) ($request['week_start'] ?? $startDate), $startDate);
        $eventDate = to_date((string) ($request['event_date'] ?? $startDate), $startDate);
        $reason = normalize_strike_review_reason((string) ($request['reason'] ?? 'step_miss'));
        $requestsByKey[strike_review_event_key($userId, $weekStart, $eventDate, $reason)] = $request;
    }

    $rowsByKey = [];
    foreach ($rawWeekRows as $row) {
        $weekStart = to_date((string) ($row['week_start'] ?? ''));
        foreach ((array) ($row['failure_events'] ?? []) as $event) {
            $eventDate = to_date((string) ($event['date'] ?? $weekStart), $weekStart);
            $reason = strike_review_reason_from_event_reason((string) ($event['reason'] ?? 'steps'));
            $key = strike_review_event_key($userId, $weekStart, $eventDate, $reason);
            $request = $requestsByKey[$key] ?? null;
            $adjusted = $adjustedByKey[$key] ?? null;
            $rowsByKey[$key] = [
                'key' => $key,
                'week_start' => $weekStart,
                'event_date' => $eventDate,
                'reason' => $reason,
                'reason_label' => strike_review_reason_label($reason),
                'base_amount' => max(0.0, (float) ($event['amount'] ?? 0)),
                'amount' => $adjusted !== null ? (float) ($adjusted['amount'] ?? 0) : 0.0,
                'strike_number' => $adjusted !== null ? (int) ($adjusted['strike_number'] ?? 0) : 0,
                'is_counted' => $adjusted !== null,
                'status' => $request !== null ? (string) ($request['status'] ?? 'pending') : 'confirmed',
                'status_label' => $request !== null
                    ? strike_review_status_label((string) ($request['status'] ?? 'pending'))
                    : strike_review_status_label('confirmed'),
                'request_id' => $request !== null ? (int) ($request['id'] ?? 0) : 0,
                'request_comment' => $request !== null ? (string) ($request['comment'] ?? '') : '',
                'requested_by_name' => $request !== null ? (string) ($request['requested_by_name'] ?? '') : '',
                'requested_by' => $request !== null ? (int) ($request['requested_by'] ?? 0) : 0,
                'eligible_voters' => $request !== null ? decode_int_list_json((string) ($request['eligible_voters_json'] ?? '[]')) : [],
                'resent_count' => $request !== null ? (int) ($request['resent_count'] ?? 0) : 0,
            ];
        }

        foreach ((array) ($row['warning_events'] ?? []) as $event) {
            $eventDate = to_date((string) ($event['date'] ?? $weekStart), $weekStart);
            $key = strike_review_event_key($userId, $weekStart, $eventDate, 'warning');
            $request = $requestsByKey[$key] ?? null;
            $adjusted = $adjustedByKey[$key] ?? null;
            $rowsByKey[$key] = [
                'key' => $key,
                'week_start' => $weekStart,
                'event_date' => $eventDate,
                'reason' => 'warning',
                'reason_label' => strike_review_reason_label('warning'),
                'base_amount' => 0.0,
                'amount' => $adjusted !== null ? 0.0 : 0.0,
                'strike_number' => 0,
                'is_counted' => $adjusted !== null,
                'status' => $request !== null ? (string) ($request['status'] ?? 'pending') : 'confirmed',
                'status_label' => $request !== null
                    ? strike_review_status_label((string) ($request['status'] ?? 'pending'))
                    : strike_review_status_label('confirmed'),
                'request_id' => $request !== null ? (int) ($request['id'] ?? 0) : 0,
                'request_comment' => $request !== null ? (string) ($request['comment'] ?? '') : '',
                'requested_by_name' => $request !== null ? (string) ($request['requested_by_name'] ?? '') : '',
                'requested_by' => $request !== null ? (int) ($request['requested_by'] ?? 0) : 0,
                'eligible_voters' => $request !== null ? decode_int_list_json((string) ($request['eligible_voters_json'] ?? '[]')) : [],
                'resent_count' => $request !== null ? (int) ($request['resent_count'] ?? 0) : 0,
            ];
        }
    }

    foreach ($requestsByKey as $key => $request) {
        if (isset($rowsByKey[$key])) {
            continue;
        }
        $weekStart = to_date((string) ($request['week_start'] ?? $startDate), $startDate);
        $eventDate = to_date((string) ($request['event_date'] ?? $startDate), $startDate);
        $reason = normalize_strike_review_reason((string) ($request['reason'] ?? 'step_miss'));
        $rowsByKey[$key] = [
            'key' => $key,
            'week_start' => $weekStart,
            'event_date' => $eventDate,
            'reason' => $reason,
            'reason_label' => strike_review_reason_label($reason),
            'base_amount' => 0.0,
            'amount' => 0.0,
            'strike_number' => 0,
            'is_counted' => false,
            'status' => (string) ($request['status'] ?? 'pending'),
            'status_label' => strike_review_status_label((string) ($request['status'] ?? 'pending')),
            'request_id' => (int) ($request['id'] ?? 0),
            'request_comment' => (string) ($request['comment'] ?? ''),
            'requested_by_name' => (string) ($request['requested_by_name'] ?? ''),
            'requested_by' => (int) ($request['requested_by'] ?? 0),
            'eligible_voters' => decode_int_list_json((string) ($request['eligible_voters_json'] ?? '[]')),
            'resent_count' => (int) ($request['resent_count'] ?? 0),
        ];
    }

    $rows = array_values($rowsByKey);
    usort(
        $rows,
        static function (array $left, array $right): int {
            $dateOrder = strcmp((string) ($left['event_date'] ?? ''), (string) ($right['event_date'] ?? ''));
            if ($dateOrder !== 0) {
                return $dateOrder;
            }

            return strcmp((string) ($left['reason'] ?? ''), (string) ($right['reason'] ?? ''));
        }
    );

    return $rows;
}

function team_rows_for_view(array $metrics, string $view): array
{
    $rows = [];
    foreach ($metrics as $metric) {
        $snapshot = metric_snapshot_for_view($metric, $view);
        if ($view === 'total') {
            $snapshot['workouts'] = max(0, (int) max((int) ($metric['workout_count'] ?? 0), (int) ($metric['workout_success'] ?? 0)));
        } else {
            $workoutWeekRow = metric_week_row_for_view($metric, $view);
            if (is_array($workoutWeekRow)) {
                $snapshot['workouts'] = max(
                    0,
                    (int) max((int) ($workoutWeekRow['workouts'] ?? 0), (int) ($workoutWeekRow['workout_success_week'] ?? 0))
                );
                $snapshot['workout_target'] = max(0, (int) ($workoutWeekRow['workout_target_week'] ?? 0));
            }
        }
        $rows[] = [
            'user_id' => (int) ($metric['user']['id'] ?? 0),
            'display_name' => (string) ($metric['user']['display_name'] ?? ''),
            'username' => (string) ($metric['user']['username'] ?? ''),
            'avatar_path' => (string) ($metric['user']['avatar_path'] ?? ''),
            'updated_at' => (string) ($metric['user']['updated_at'] ?? ''),
            'score' => (float) ($snapshot['score'] ?? 0),
            'steps' => (int) ($snapshot['steps'] ?? 0),
            'distance' => (float) ($snapshot['distance_km'] ?? 0),
            'workouts' => (int) ($snapshot['workouts'] ?? 0),
            'workout_target' => (int) ($snapshot['workout_target'] ?? 0),
            'strikes' => (int) ($snapshot['strikes'] ?? 0),
            'penalties' => (float) ($snapshot['penalty'] ?? 0),
            'weight_progress' => (float) ($snapshot['weight_progress'] ?? 0),
        ];
    }

    return $rows;
}

function team_summary_from_rows(array $rows): array
{
    $members = count($rows);
    $totals = [
        'members' => $members,
        'score_avg' => 0.0,
        'total_steps' => 0,
        'total_km' => 0.0,
        'workout_count' => 0,
        'workout_success' => 0,
        'workout_target' => 0,
        'strikes' => 0,
        'penalty' => 0.0,
        'calories_burned' => 0.0,
        'calories_consumed' => 0.0,
        'weight_progress' => 0.0,
    ];

    foreach ($rows as $row) {
        $totals['score_avg'] += (float) ($row['score'] ?? 0);
        $totals['total_steps'] += (int) ($row['steps'] ?? 0);
        $totals['total_km'] += (float) ($row['distance'] ?? 0);
        $totals['workout_count'] += (int) ($row['workouts'] ?? 0);
        $totals['workout_success'] += (int) ($row['workouts'] ?? 0);
        $totals['workout_target'] += (int) ($row['workout_target'] ?? 0);
        $totals['strikes'] += (int) ($row['strikes'] ?? 0);
        $totals['penalty'] += (float) ($row['penalties'] ?? 0);
        $totals['weight_progress'] += (float) ($row['weight_progress'] ?? 0);
    }

    if ($members > 0) {
        $totals['score_avg'] = round($totals['score_avg'] / $members, 1);
        $totals['weight_progress'] = round($totals['weight_progress'] / $members, 1);
    } else {
        $totals['score_avg'] = 0.0;
        $totals['weight_progress'] = 0.0;
    }
    $totals['total_km'] = round((float) $totals['total_km'], 2);
    $totals['penalty'] = round((float) $totals['penalty'], 2);

    return $totals;
}

function user_notifications(PDO $pdo, int $userId, int $limit = 20, bool $includeRead = false): array
{
    $limit = max(1, min(100, $limit));
    $conditions = ['user_id = :user_id'];
    $params = [':user_id' => $userId];
    if (!$includeRead) {
        $conditions[] = 'is_read = 0';
    }

    return db_fetch_all(
        $pdo,
        'SELECT * FROM user_notifications
         WHERE ' . implode(' AND ', $conditions) . '
         ORDER BY created_at DESC
         LIMIT ' . $limit,
        $params
    );
}

function user_unread_notifications_count(PDO $pdo, int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }

    $row = db_fetch_one(
        $pdo,
        'SELECT COUNT(*) AS total
         FROM user_notifications
         WHERE user_id = :user_id AND is_read = 0',
        [':user_id' => $userId]
    );

    return max(0, (int) ($row['total'] ?? 0));
}

function create_user_notification(
    PDO $pdo,
    int $userId,
    string $kind,
    string $title,
    string $message,
    ?string $uniqueKey = null,
    array $payload = []
): bool {
    if ($userId <= 0 || trim($title) === '' || trim($message) === '') {
        return false;
    }

    $now = now_iso();
    $jsonPayload = $payload !== [] ? json_encode($payload, JSON_UNESCAPED_SLASHES) : null;
    if (!is_string($jsonPayload) && $jsonPayload !== null) {
        $jsonPayload = null;
    }

    db_execute(
        $pdo,
        'INSERT OR IGNORE INTO user_notifications (
            user_id, kind, title, message, payload_json, unique_key, is_read, created_at, read_at
        ) VALUES (
            :user_id, :kind, :title, :message, :payload_json, :unique_key, 0, :created_at, NULL
        )',
        [
            ':user_id' => $userId,
            ':kind' => trim($kind) !== '' ? trim($kind) : 'info',
            ':title' => trim($title),
            ':message' => trim($message),
            ':payload_json' => $jsonPayload,
            ':unique_key' => $uniqueKey,
            ':created_at' => $now,
        ]
    );

    return $pdo->lastInsertId() !== '0';
}

function upsert_user_notification(
    PDO $pdo,
    int $userId,
    string $kind,
    string $title,
    string $message,
    ?string $uniqueKey = null,
    array $payload = []
): bool {
    if ($userId <= 0 || trim($title) === '' || trim($message) === '') {
        return false;
    }

    if ($uniqueKey === null || trim($uniqueKey) === '') {
        return create_user_notification($pdo, $userId, $kind, $title, $message, null, $payload);
    }

    $now = now_iso();
    $jsonPayload = $payload !== [] ? json_encode($payload, JSON_UNESCAPED_SLASHES) : null;
    if (!is_string($jsonPayload) && $jsonPayload !== null) {
        $jsonPayload = null;
    }

    db_execute(
        $pdo,
        'INSERT INTO user_notifications (
            user_id, kind, title, message, payload_json, unique_key, is_read, created_at, read_at
        ) VALUES (
            :user_id, :kind, :title, :message, :payload_json, :unique_key, 0, :created_at, NULL
        )
        ON CONFLICT(user_id, unique_key) WHERE unique_key IS NOT NULL DO UPDATE SET
            kind = excluded.kind,
            title = excluded.title,
            message = excluded.message,
            payload_json = excluded.payload_json,
            is_read = 0,
            read_at = NULL,
            created_at = excluded.created_at',
        [
            ':user_id' => $userId,
            ':kind' => trim($kind) !== '' ? trim($kind) : 'info',
            ':title' => trim($title),
            ':message' => trim($message),
            ':payload_json' => $jsonPayload,
            ':unique_key' => trim($uniqueKey),
            ':created_at' => $now,
        ]
    );

    return true;
}

function mark_user_notification_read(PDO $pdo, int $notificationId, int $userId): void
{
    if ($notificationId <= 0 || $userId <= 0) {
        return;
    }

    db_execute(
        $pdo,
        'UPDATE user_notifications
         SET is_read = 1, read_at = :read_at
         WHERE id = :id AND user_id = :user_id',
        [
            ':read_at' => now_iso(),
            ':id' => $notificationId,
            ':user_id' => $userId,
        ]
    );
}

function fetch_user_notification(PDO $pdo, int $notificationId, int $userId): ?array
{
    if ($notificationId <= 0 || $userId <= 0) {
        return null;
    }

    $notification = db_fetch_one(
        $pdo,
        'SELECT *
         FROM user_notifications
         WHERE id = :id AND user_id = :user_id',
        [
            ':id' => $notificationId,
            ':user_id' => $userId,
        ]
    );

    return is_array($notification) ? $notification : null;
}

function mark_all_user_notifications_read(PDO $pdo, int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }

    $statement = $pdo->prepare(
        'UPDATE user_notifications
         SET is_read = 1, read_at = :read_at
         WHERE user_id = :user_id AND is_read = 0'
    );
    $statement->execute([
        ':read_at' => now_iso(),
        ':user_id' => $userId,
    ]);

    return max(0, (int) $statement->rowCount());
}

function delete_user_notification(PDO $pdo, int $notificationId, int $userId): int
{
    if ($notificationId <= 0 || $userId <= 0) {
        return 0;
    }

    $statement = $pdo->prepare(
        'DELETE FROM user_notifications
         WHERE id = :id AND user_id = :user_id'
    );
    $statement->execute([
        ':id' => $notificationId,
        ':user_id' => $userId,
    ]);

    return max(0, (int) $statement->rowCount());
}

function delete_user_read_notifications(PDO $pdo, int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }

    $statement = $pdo->prepare(
        'DELETE FROM user_notifications
         WHERE user_id = :user_id AND is_read = 1'
    );
    $statement->execute([':user_id' => $userId]);

    return max(0, (int) $statement->rowCount());
}

function delete_all_user_notifications(PDO $pdo, int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }

    $statement = $pdo->prepare(
        'DELETE FROM user_notifications
         WHERE user_id = :user_id'
    );
    $statement->execute([':user_id' => $userId]);

    return max(0, (int) $statement->rowCount());
}

function resolve_notification_destination(PDO $pdo, array $notification): string
{
    $kind = strtolower(trim((string) ($notification['kind'] ?? '')));
    $payloadRaw = (string) ($notification['payload_json'] ?? '');
    $payload = [];
    if ($payloadRaw !== '') {
        $decoded = json_decode($payloadRaw, true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }

    if (in_array($kind, ['strike_review_request', 'strike_review_resolved'], true)) {
        $targetUserId = (int) ($payload['target_user_id'] ?? 0);
        $view = trim((string) ($payload['view'] ?? ''));
        $weekStart = to_date((string) ($payload['week_start'] ?? ''), '');
        $requestId = (int) ($payload['request_id'] ?? 0);
        $eventDate = to_date((string) ($payload['event_date'] ?? ''), '');

        if ($weekStart === '' && $eventDate !== '') {
            try {
                $weekStart = week_start_for(new DateTimeImmutable($eventDate))->format('Y-m-d');
            } catch (Throwable) {
                $weekStart = '';
            }
        }

        if (($targetUserId <= 0 || $weekStart === '') && $requestId > 0) {
            $request = db_fetch_one(
                $pdo,
                'SELECT target_user_id, week_start, event_date
                 FROM strike_review_requests
                 WHERE id = :id',
                [':id' => $requestId]
            );
            if (is_array($request)) {
                if ($targetUserId <= 0) {
                    $targetUserId = (int) ($request['target_user_id'] ?? 0);
                }
                if ($weekStart === '') {
                    $weekStart = to_date((string) ($request['week_start'] ?? ''), '');
                    if ($weekStart === '') {
                        $requestEventDate = to_date((string) ($request['event_date'] ?? ''), '');
                        if ($requestEventDate !== '') {
                            try {
                                $weekStart = week_start_for(new DateTimeImmutable($requestEventDate))->format('Y-m-d');
                            } catch (Throwable) {
                                $weekStart = '';
                            }
                        }
                    }
                }
            }
        }

        if ($targetUserId > 0) {
            $query = [
                'page' => 'strikes_detail',
                'user_id' => $targetUserId,
            ];
            if ($view === 'current_week' || $view === 'total') {
                $query['view'] = $view;
            } elseif ($weekStart !== '') {
                $query['view'] = $weekStart;
            }

            return '/?' . http_build_query($query);
        }
    }

    if ($kind === 'team_goal_completed') {
        $teamId = (int) ($payload['team_id'] ?? 0);
        if ($teamId > 0) {
            return '/?' . http_build_query([
                'page' => 'team',
                'team_id' => $teamId,
            ]);
        }

        return '/?page=team';
    }

    if (in_array($kind, ['friend_request', 'friend_accepted'], true)) {
        return '/?page=friends';
    }

    if (in_array($kind, ['duel_challenge', 'duel_accepted', 'duel_finished'], true)) {
        return '/?page=duels';
    }

    if (in_array($kind, ['comp_invite', 'comp_accepted', 'comp_finished', 'squad_added'], true)) {
        return '/?page=competitions';
    }

    if ($kind === 'friend_activity_meal') {
        return '/?page=gallery';
    }

    if ($kind === 'friend_activity_training') {
        return '/?page=table';
    }

    return '/?page=notifications';
}

function open_user_notification(PDO $pdo, int $notificationId, int $userId): string
{
    $notification = fetch_user_notification($pdo, $notificationId, $userId);
    if (!is_array($notification)) {
        return '/?page=notifications';
    }

    mark_user_notification_read($pdo, $notificationId, $userId);
    $destination = resolve_notification_destination($pdo, $notification);

    return safe_redirect_target($destination);
}

function resolve_team_calories_summary(PDO $pdo, int $teamId, string $startDate, string $endDate): array
{
    if ($teamId <= 0) {
        return ['burned' => 0.0, 'consumed' => 0.0];
    }

    $teamUsers = list_active_team_users($pdo, $teamId);
    if ($teamUsers === []) {
        return ['burned' => 0.0, 'consumed' => 0.0];
    }
    $ids = array_values(array_unique(array_map(static fn(array $user): int => (int) ($user['id'] ?? 0), $teamUsers)));
    $ids = array_values(array_filter($ids, static fn(int $id): bool => $id > 0));
    if ($ids === []) {
        return ['burned' => 0.0, 'consumed' => 0.0];
    }

    $params = [':start' => $startDate, ':end' => $endDate];
    $placeholders = [];
    foreach ($ids as $index => $id) {
        $key = ':uid_' . $index;
        $placeholders[] = $key;
        $params[$key] = $id;
    }

    $burnedRow = db_fetch_one(
        $pdo,
        'SELECT SUM(COALESCE(training_calories_burned, 0)) AS total
         FROM daily_logs
         WHERE log_date BETWEEN :start AND :end
           AND user_id IN (' . implode(',', $placeholders) . ')',
        $params
    );

    $consumedRow = db_fetch_one(
        $pdo,
        'SELECT SUM(COALESCE(calories, 0)) AS total
         FROM photo_entries
         WHERE log_date BETWEEN :start AND :end
           AND user_id IN (' . implode(',', $placeholders) . ')',
        $params
    );

    return [
        'burned' => round((float) ($burnedRow['total'] ?? 0), 2),
        'consumed' => round((float) ($consumedRow['total'] ?? 0), 2),
    ];
}

function auto_complete_team_goals(PDO $pdo, int $teamId, array $teamSummary, ?int $actorUserId = null): int
{
    if ($teamId <= 0) {
        return 0;
    }

    $activeGoals = db_fetch_all(
        $pdo,
        'SELECT * FROM goals WHERE scope = "team" AND team_id = :team_id AND status = "active" ORDER BY created_at ASC',
        [':team_id' => $teamId]
    );
    if ($activeGoals === []) {
        return 0;
    }

    $completedCount = 0;
    foreach ($activeGoals as $goal) {
        $progressState = goal_team_progress_state($pdo, $goal, $teamSummary);
        $goal = is_array($progressState['goal'] ?? null) ? (array) $progressState['goal'] : $goal;
        $primaryState = is_array($progressState['primary'] ?? null) ? (array) $progressState['primary'] : [];
        $secondaryState = is_array($progressState['secondary'] ?? null) ? (array) $progressState['secondary'] : [];
        $progressValue = (float) ($primaryState['progress_value'] ?? ($progressState['progress_value'] ?? 0.0));
        $secondaryProgressValue = $secondaryState !== []
            ? (float) ($secondaryState['progress_value'] ?? 0.0)
            : 0.0;
        $hasStarted = !empty($progressState['has_started']);
        $targetReached = !empty($progressState['target_reached']);
        $goalId = (int) ($goal['id'] ?? 0);
        if ($goalId <= 0) {
            continue;
        }

        if (!$hasStarted) {
            $currentValueNeedsReset = abs((float) ($goal['current_value'] ?? 0)) > 0.00001;
            $secondaryCurrentNeedsReset = abs((float) ($goal['secondary_current_value'] ?? 0)) > 0.00001;
            if ($currentValueNeedsReset || $secondaryCurrentNeedsReset) {
                db_execute(
                    $pdo,
                    'UPDATE goals
                     SET current_value = 0,
                         secondary_current_value = 0,
                         updated_at = :updated_at
                     WHERE id = :id AND status = "active"',
                    [
                        ':updated_at' => now_iso(),
                        ':id' => $goalId,
                    ]
                );
            }
            continue;
        }

        if ($targetReached) {
            db_execute(
                $pdo,
                'UPDATE goals
                 SET status = "complete",
                     current_value = :current_value,
                     secondary_current_value = :secondary_current_value,
                     completed_at = COALESCE(completed_at, :completed_at),
                     updated_at = :updated_at
                 WHERE id = :id AND status = "active"',
                [
                    ':current_value' => round($progressValue, 2),
                    ':secondary_current_value' => round($secondaryProgressValue, 2),
                    ':completed_at' => now_iso(),
                    ':updated_at' => now_iso(),
                    ':id' => $goalId,
                ]
            );

            $after = db_fetch_one($pdo, 'SELECT * FROM goals WHERE id = :id', [':id' => $goalId]);
            if ($after === null || (string) ($after['status'] ?? '') !== 'complete') {
                continue;
            }

            $completedCount++;
            audit_log(
                $pdo,
                $actorUserId,
                'goal_complete_auto',
                'goal',
                (string) $goalId,
                'Team goal auto-completed from progress metrics.',
                audit_snapshot($goal),
                audit_snapshot($after)
            );

            $members = list_active_team_users($pdo, $teamId);
            $title = 'Team goal completed!';
            $message = 'Your team reached: ' . (string) ($after['title'] ?? '');
            $rewardText = trim((string) ($after['reward_text'] ?? ''));
            if ($rewardText !== '') {
                $message .= ' | Reward unlocked: ' . $rewardText;
            }
            foreach ($members as $member) {
                $memberId = (int) ($member['id'] ?? 0);
                if ($memberId <= 0) {
                    continue;
                }
                create_user_notification(
                    $pdo,
                    $memberId,
                    'team_goal_completed',
                    $title,
                    $message,
                    'team_goal_completed:' . $goalId,
                    [
                        'goal_id' => $goalId,
                        'team_id' => $teamId,
                    ]
                );
            }
        } else {
            db_execute(
                $pdo,
                'UPDATE goals
                 SET current_value = :current_value,
                     secondary_current_value = :secondary_current_value,
                     updated_at = :updated_at
                 WHERE id = :id AND status = "active"',
                [
                    ':current_value' => round($progressValue, 2),
                    ':secondary_current_value' => round($secondaryProgressValue, 2),
                    ':updated_at' => now_iso(),
                    ':id' => $goalId,
                ]
            );
        }
    }

    return $completedCount;
}

function auto_complete_team_goals_for_team(PDO $pdo, int $teamId, string $startDate, string $endDate, ?int $actorUserId = null): int
{
    if ($teamId <= 0) {
        return 0;
    }

    $teamUsers = list_active_team_users($pdo, $teamId);
    if ($teamUsers === []) {
        return 0;
    }

    $metricsByUser = compute_challenge_metrics($pdo, $teamUsers, $startDate, $endDate);
    $metricsByUser = apply_strike_review_overrides_to_metrics($pdo, $metricsByUser);
    $rows = team_rows_for_view(array_values($metricsByUser), 'total');
    $summary = team_summary_from_rows($rows);
    $calories = resolve_team_calories_summary($pdo, $teamId, $startDate, $endDate);
    $summary['calories_burned'] = (float) ($calories['burned'] ?? 0);
    $summary['calories_consumed'] = (float) ($calories['consumed'] ?? 0);

    return auto_complete_team_goals($pdo, $teamId, $summary, $actorUserId);
}

function auto_complete_team_goals_for_user(PDO $pdo, int $userId, string $startDate, string $endDate, ?int $actorUserId = null): int
{
    if ($userId <= 0) {
        return 0;
    }

    $teams = list_user_teams($pdo, $userId);
    if ($teams === []) {
        return 0;
    }

    $completed = 0;
    $seenTeam = [];
    foreach ($teams as $team) {
        $teamId = (int) ($team['id'] ?? 0);
        if ($teamId <= 0 || isset($seenTeam[$teamId])) {
            continue;
        }
        $seenTeam[$teamId] = true;
        $completed += auto_complete_team_goals_for_team($pdo, $teamId, $startDate, $endDate, $actorUserId);
    }

    return $completed;
}

function normalize_backup_frequency(string $frequency): string
{
    $normalized = strtolower(trim($frequency));
    if (in_array($normalized, ['daily', 'weekly', 'monthly'], true)) {
        return $normalized;
    }

    return 'daily';
}

function system_backup_settings(PDO $pdo): array
{
    $enabledRaw = strtolower(trim((string) (app_setting($pdo, 'backup_auto_enabled', '0') ?? '0')));
    $enabled = in_array($enabledRaw, ['1', 'true', 'yes', 'on'], true);
    $frequency = normalize_backup_frequency((string) (app_setting($pdo, 'backup_frequency', 'daily') ?? 'daily'));
    $runTime = normalize_backup_run_time((string) (app_setting($pdo, 'backup_run_time', '00:00') ?? '00:00'));
    $retention = max(1, min(200, (int) (app_setting($pdo, 'backup_retention_count', '20') ?? '20')));
    $lastAutoAt = trim((string) (app_setting($pdo, 'backup_last_auto_at', '') ?? ''));

    return [
        'enabled' => $enabled,
        'frequency' => $frequency,
        'run_time' => $runTime,
        'retention_count' => $retention,
        'last_auto_at' => $lastAutoAt,
    ];
}

function normalize_backup_run_time(string $time): string
{
    $time = trim($time);
    if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $time, $matches) === 1) {
        return sprintf('%02d:%02d', (int) $matches[1], (int) $matches[2]);
    }

    return '00:00';
}

function set_app_setting_silent(PDO $pdo, string $key, ?string $value, ?int $updatedBy = null): void
{
    db_execute(
        $pdo,
        'INSERT INTO app_settings (setting_key, setting_value, updated_by, updated_at)
         VALUES (:key, :value, :updated_by, :updated_at)
         ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value, updated_by = excluded.updated_by, updated_at = excluded.updated_at',
        [
            ':key' => $key,
            ':value' => $value,
            ':updated_by' => $updatedBy,
            ':updated_at' => now_iso(),
        ]
    );
}

function system_backup_storage_dir(array $config): string
{
    $storageRoot = dirname((string) ($config['db_path'] ?? (dirname(__DIR__) . '/storage/fitness.sqlite')));

    return rtrim($storageRoot, '/\\') . '/backups';
}

function ensure_system_backup_storage_dir(array $config): string
{
    $backupDir = system_backup_storage_dir($config);
    if (!is_dir($backupDir) && !mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
        throw new RuntimeException('Could not create backup directory.');
    }

    return $backupDir;
}

function system_backup_acquire_lock(array $config)
{
    $backupDir = ensure_system_backup_storage_dir($config);
    $lockPath = $backupDir . '/.backup.lock';
    $handle = @fopen($lockPath, 'c+');
    if ($handle === false) {
        return false;
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return false;
    }

    return $handle;
}

function system_backup_release_lock(mixed $lockHandle): void
{
    if (!is_resource($lockHandle)) {
        return;
    }
    @flock($lockHandle, LOCK_UN);
    @fclose($lockHandle);
}

function system_backup_relative_path_from_name(string $fileName): string
{
    return 'backups/' . ltrim($fileName, '/\\');
}

function system_backup_absolute_path(array $config, string $relativePath): ?string
{
    $candidate = str_replace('\\', '/', trim($relativePath));
    if ($candidate === '' || str_contains($candidate, '..')) {
        return null;
    }
    $storageRoot = dirname((string) ($config['db_path'] ?? (dirname(__DIR__) . '/storage/fitness.sqlite')));

    return rtrim($storageRoot, '/\\') . '/' . ltrim($candidate, '/');
}

function system_backup_collect_upload_files(string $uploadDir): array
{
    $files = [];
    if (!is_dir($uploadDir)) {
        return $files;
    }

    $root = rtrim(str_replace('\\', '/', $uploadDir), '/');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($uploadDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $fileInfo) {
        if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile()) {
            continue;
        }
        $absolute = str_replace('\\', '/', $fileInfo->getPathname());
        $relative = ltrim(substr($absolute, strlen($root)), '/');
        if ($relative === '') {
            continue;
        }
        $files[] = [
            'absolute' => $absolute,
            'relative' => $relative,
        ];
    }

    usort(
        $files,
        static fn(array $left, array $right): int => strcmp((string) ($left['relative'] ?? ''), (string) ($right['relative'] ?? ''))
    );

    return $files;
}

function system_backup_recursive_delete(string $path): void
{
    if (!file_exists($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if (!$item instanceof SplFileInfo) {
            continue;
        }
        if ($item->isDir() && !$item->isLink()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($path);
}

function system_backup_recursive_copy(string $source, string $destination): void
{
    if (is_file($source)) {
        $targetDir = dirname($destination);
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new RuntimeException('Could not create backup destination directory.');
        }
        if (!copy($source, $destination)) {
            throw new RuntimeException('Could not copy backup file.');
        }
        return;
    }

    if (!is_dir($source)) {
        throw new RuntimeException('Source path does not exist.');
    }
    if (!is_dir($destination) && !mkdir($destination, 0775, true) && !is_dir($destination)) {
        throw new RuntimeException('Could not create backup destination directory.');
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        if (!$item instanceof SplFileInfo) {
            continue;
        }
        $targetPath = $destination . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
        if ($item->isDir() && !$item->isLink()) {
            if (!is_dir($targetPath) && !mkdir($targetPath, 0775, true) && !is_dir($targetPath)) {
                throw new RuntimeException('Could not create backup destination directory.');
            }
            continue;
        }
        $targetDir = dirname($targetPath);
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new RuntimeException('Could not create backup destination directory.');
        }
        if (!copy($item->getPathname(), $targetPath)) {
            throw new RuntimeException('Could not copy backup file.');
        }
    }
}

function prune_system_backups(PDO $pdo, array $config, int $keepCount): int
{
    $keepCount = max(1, $keepCount);
    $rows = db_fetch_all(
        $pdo,
        'SELECT id, file_path
         FROM system_backups
         ORDER BY created_at DESC, id DESC'
    );
    if (count($rows) <= $keepCount) {
        return 0;
    }

    $deleted = 0;
    $toDelete = array_slice($rows, $keepCount);
    foreach ($toDelete as $row) {
        $backupId = (int) ($row['id'] ?? 0);
        if ($backupId <= 0) {
            continue;
        }
        $absolutePath = system_backup_absolute_path($config, (string) ($row['file_path'] ?? ''));
        if (is_string($absolutePath) && $absolutePath !== '' && is_file($absolutePath)) {
            @unlink($absolutePath);
        }
        db_execute($pdo, 'DELETE FROM system_backups WHERE id = :id', [':id' => $backupId]);
        $deleted++;
    }

    return $deleted;
}

function delete_system_backup(PDO $pdo, array $config, int $backupId): void
{
    if ($backupId <= 0) {
        return;
    }
    $backup = fetch_system_backup($pdo, $backupId);
    if ($backup === null) {
        return;
    }
    $absolutePath = system_backup_absolute_path($config, (string) ($backup['file_path'] ?? ''));
    if (is_string($absolutePath) && $absolutePath !== '' && is_file($absolutePath)) {
        @unlink($absolutePath);
    }
    db_execute($pdo, 'DELETE FROM system_backups WHERE id = :id', [':id' => $backupId]);
}

function reconcile_system_backups(PDO $pdo, array $config): int
{
    $backupDir = ensure_system_backup_storage_dir($config);
    if (!is_dir($backupDir)) {
        return 0;
    }

    $created = 0;
    $iterator = new DirectoryIterator($backupDir);
    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }
        $fileName = $fileInfo->getFilename();
        $lower = strtolower($fileName);
        if (!str_ends_with($lower, '.zip') && !str_ends_with($lower, '.tar.gz')) {
            continue;
        }
        $relativePath = system_backup_relative_path_from_name($fileName);
        $existing = db_fetch_one($pdo, 'SELECT id FROM system_backups WHERE file_path = :file_path', [':file_path' => $relativePath]);
        if ($existing !== null) {
            continue;
        }

        $trigger = str_contains($lower, '_auto_') ? 'auto' : 'manual';
        $createdAt = date('Y-m-d H:i:s', max(0, $fileInfo->getMTime()));
        if (preg_match('/backup_(\d{8})_(\d{6})_(manual|auto)_/i', $fileName, $matches) === 1) {
            $parsed = DateTimeImmutable::createFromFormat('Ymd His', $matches[1] . ' ' . $matches[2]);
            if ($parsed instanceof DateTimeImmutable) {
                $createdAt = $parsed->format('Y-m-d H:i:s');
            }
            $trigger = strtolower((string) $matches[3]);
        }

        $absolutePath = $fileInfo->getPathname();
        db_execute(
            $pdo,
            'INSERT INTO system_backups (
                file_path, trigger_type, scope, size_bytes, checksum_sha256, status, created_by, created_at, restored_by, restored_at, error_message
            ) VALUES (
                :file_path, :trigger_type, :scope, :size_bytes, :checksum_sha256, :status, NULL, :created_at, NULL, NULL, NULL
            )',
            [
                ':file_path' => $relativePath,
                ':trigger_type' => in_array($trigger, ['manual', 'auto'], true) ? $trigger : 'manual',
                ':scope' => 'db_uploads',
                ':size_bytes' => (int) (@filesize($absolutePath) ?: 0),
                ':checksum_sha256' => hash_file('sha256', $absolutePath) ?: null,
                ':status' => 'created',
                ':created_at' => $createdAt,
            ]
        );
        $created++;
    }

    return $created;
}

function system_backup_zip_available(): bool
{
    return class_exists('ZipArchive');
}

function system_backup_tar_available(): bool
{
    return class_exists('PharData');
}

function ensure_system_backup_archive_available(): void
{
    if (!system_backup_zip_available() && !system_backup_tar_available()) {
        throw new RuntimeException('No backup archive method is available. Enable PHP zip or phar support.');
    }
}

function system_backup_is_zip(string $archivePath): bool
{
    return strtolower(pathinfo($archivePath, PATHINFO_EXTENSION)) === 'zip';
}

function system_backup_create_zip_archive(string $archivePath, string $dbPath, array $uploadFiles, array $manifest): void
{
    if (!system_backup_zip_available()) {
        throw new RuntimeException('PHP zip extension is not available.');
    }

    $zip = new ZipArchive();
    $openResult = $zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($openResult !== true) {
        throw new RuntimeException('Could not create backup archive.');
    }
    if (!$zip->addFile($dbPath, 'fitness.sqlite')) {
        $zip->close();
        throw new RuntimeException('Could not add database file to archive.');
    }

    foreach ($uploadFiles as $file) {
        $absolute = (string) ($file['absolute'] ?? '');
        $relative = (string) ($file['relative'] ?? '');
        if ($absolute === '' || $relative === '') {
            continue;
        }
        $zipPath = 'uploads/' . ltrim(str_replace('\\', '/', $relative), '/');
        if (!$zip->addFile($absolute, $zipPath)) {
            $zip->close();
            throw new RuntimeException('Could not add upload file to archive.');
        }
    }

    $manifestJson = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($manifestJson) || !$zip->addFromString('manifest.json', $manifestJson)) {
        $zip->close();
        throw new RuntimeException('Could not write backup manifest.');
    }
    $zip->close();
}

function system_backup_create_tar_gz_archive(string $archivePath, string $backupDir, string $dbPath, array $uploadFiles, array $manifest): void
{
    if (!system_backup_tar_available()) {
        throw new RuntimeException('PHP phar support is not available.');
    }

    $stageDir = $backupDir . '/.backup_stage_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
    $tarPath = preg_replace('/\.gz$/i', '', $archivePath) ?: ($archivePath . '.tar');
    if (!str_ends_with(strtolower($tarPath), '.tar')) {
        $tarPath .= '.tar';
    }

    try {
        if (!mkdir($stageDir, 0775, true) && !is_dir($stageDir)) {
            throw new RuntimeException('Could not prepare backup workspace.');
        }
        if (!copy($dbPath, $stageDir . '/fitness.sqlite')) {
            throw new RuntimeException('Could not stage database file for backup.');
        }
        foreach ($uploadFiles as $file) {
            $absolute = (string) ($file['absolute'] ?? '');
            $relative = (string) ($file['relative'] ?? '');
            if ($absolute === '' || $relative === '') {
                continue;
            }
            $targetPath = $stageDir . '/uploads/' . ltrim(str_replace('\\', '/', $relative), '/');
            $targetDir = dirname($targetPath);
            if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
                throw new RuntimeException('Could not stage upload files for backup.');
            }
            if (!copy($absolute, $targetPath)) {
                throw new RuntimeException('Could not stage upload file for backup.');
            }
        }

        $manifestJson = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($manifestJson) || file_put_contents($stageDir . '/manifest.json', $manifestJson) === false) {
            throw new RuntimeException('Could not write backup manifest.');
        }

        if (is_file($tarPath)) {
            @unlink($tarPath);
        }
        if (is_file($archivePath)) {
            @unlink($archivePath);
        }

        $phar = new PharData($tarPath);
        $phar->buildFromDirectory($stageDir);
        $phar->compress(Phar::GZ);
        unset($phar);
        if (is_file($tarPath)) {
            @unlink($tarPath);
        }
        if (!is_file($archivePath)) {
            throw new RuntimeException('Could not create compressed backup archive.');
        }
    } finally {
        if (is_dir($stageDir)) {
            system_backup_recursive_delete($stageDir);
        }
        if (is_file($tarPath)) {
            @unlink($tarPath);
        }
    }
}

function create_system_backup(PDO $pdo, array $config, string $triggerType, ?int $actorUserId = null): array
{
    ensure_system_backup_archive_available();

    $trigger = in_array($triggerType, ['manual', 'auto'], true) ? $triggerType : 'manual';
    $lockHandle = system_backup_acquire_lock($config);
    if ($lockHandle === false) {
        throw new RuntimeException('A backup process is already running.');
    }

    try {
        $dbPath = (string) ($config['db_path'] ?? '');
        if ($dbPath === '' || !is_file($dbPath)) {
            throw new RuntimeException('Database file not found.');
        }

        $backupDir = ensure_system_backup_storage_dir($config);
        $archiveExtension = system_backup_zip_available() ? 'zip' : 'tar.gz';
        $fileName = sprintf('backup_%s_%s_%s.%s', date('Ymd_His'), $trigger, bin2hex(random_bytes(4)), $archiveExtension);
        $relativePath = system_backup_relative_path_from_name($fileName);
        $archivePath = $backupDir . '/' . $fileName;
        $uploadDir = rtrim((string) ($config['upload_dir'] ?? ''), '/\\');
        $uploadFiles = $uploadDir !== '' ? system_backup_collect_upload_files($uploadDir) : [];

        $manifest = [
            'version' => 1,
            'created_at' => now_iso(),
            'trigger' => $trigger,
            'scope' => 'db_uploads',
            'db' => [
                'path' => 'fitness.sqlite',
                'size_bytes' => (int) (@filesize($dbPath) ?: 0),
                'sha256' => hash_file('sha256', $dbPath) ?: '',
            ],
            'uploads' => [],
        ];

        foreach ($uploadFiles as $file) {
            $absolute = (string) ($file['absolute'] ?? '');
            $relative = (string) ($file['relative'] ?? '');
            if ($absolute === '' || $relative === '') {
                continue;
            }
            $zipPath = 'uploads/' . ltrim(str_replace('\\', '/', $relative), '/');
            $manifest['uploads'][] = [
                'path' => $zipPath,
                'size_bytes' => (int) (@filesize($absolute) ?: 0),
                'sha256' => hash_file('sha256', $absolute) ?: '',
            ];
        }

        if (system_backup_zip_available()) {
            system_backup_create_zip_archive($archivePath, $dbPath, $uploadFiles, $manifest);
        } else {
            system_backup_create_tar_gz_archive($archivePath, $backupDir, $dbPath, $uploadFiles, $manifest);
        }

        $sizeBytes = (int) (@filesize($archivePath) ?: 0);
        $checksum = hash_file('sha256', $archivePath) ?: null;
        db_execute(
            $pdo,
            'INSERT INTO system_backups (
                file_path, trigger_type, scope, size_bytes, checksum_sha256, status, created_by, created_at, restored_by, restored_at, error_message
            ) VALUES (
                :file_path, :trigger_type, :scope, :size_bytes, :checksum_sha256, :status, :created_by, :created_at, NULL, NULL, NULL
            )',
            [
                ':file_path' => $relativePath,
                ':trigger_type' => $trigger,
                ':scope' => 'db_uploads',
                ':size_bytes' => $sizeBytes,
                ':checksum_sha256' => $checksum,
                ':status' => 'created',
                ':created_by' => $actorUserId,
                ':created_at' => now_iso(),
            ]
        );
        $backup = db_fetch_one($pdo, 'SELECT * FROM system_backups WHERE id = last_insert_rowid()');
        if (!is_array($backup)) {
            throw new RuntimeException('Could not read backup metadata.');
        }

        return $backup;
    } catch (Throwable $e) {
        if (isset($archivePath) && is_string($archivePath) && $archivePath !== '' && is_file($archivePath)) {
            @unlink($archivePath);
        }
        throw $e;
    } finally {
        system_backup_release_lock($lockHandle);
    }
}

function fetch_system_backup(PDO $pdo, int $backupId): ?array
{
    if ($backupId <= 0) {
        return null;
    }

    $row = db_fetch_one($pdo, 'SELECT * FROM system_backups WHERE id = :id', [':id' => $backupId]);

    return is_array($row) ? $row : null;
}

function list_system_backups(PDO $pdo, array $config, int $limit = 120): array
{
    $limit = max(1, min(500, $limit));
    $rows = db_fetch_all(
        $pdo,
        'SELECT *
         FROM system_backups
         ORDER BY created_at DESC, id DESC
         LIMIT ' . $limit
    );

    $items = [];
    foreach ($rows as $row) {
        $relativePath = (string) ($row['file_path'] ?? '');
        $absolutePath = system_backup_absolute_path($config, $relativePath);
        $exists = is_string($absolutePath) && $absolutePath !== '' && is_file($absolutePath);
        $sizeBytes = $exists ? (int) (@filesize($absolutePath) ?: 0) : (int) ($row['size_bytes'] ?? 0);

        $row['file_exists'] = $exists ? 1 : 0;
        $row['size_bytes'] = $sizeBytes;
        $row['size_label'] = format_upload_size($sizeBytes);
        $items[] = $row;
    }

    return $items;
}

function mark_system_backup_restore_result(PDO $pdo, int $backupId, string $status, ?int $actorUserId = null, ?string $errorMessage = null): void
{
    if ($backupId <= 0) {
        return;
    }
    $normalizedStatus = in_array($status, ['created', 'restored', 'error'], true) ? $status : 'created';

    db_execute(
        $pdo,
        'UPDATE system_backups
         SET status = :status,
             restored_by = CASE WHEN :set_restored = 1 THEN :restored_by ELSE restored_by END,
             restored_at = CASE WHEN :set_restored = 1 THEN :restored_at ELSE restored_at END,
             error_message = :error_message
         WHERE id = :id',
        [
            ':status' => $normalizedStatus,
            ':set_restored' => $normalizedStatus === 'restored' ? 1 : 0,
            ':restored_by' => $normalizedStatus === 'restored' ? $actorUserId : null,
            ':restored_at' => $normalizedStatus === 'restored' ? now_iso() : null,
            ':error_message' => $errorMessage,
            ':id' => $backupId,
        ]
    );
}

function system_backup_extract_archive_to(string $archivePath, string $destination): void
{
    if (!is_file($archivePath)) {
        throw new RuntimeException('Backup file not found.');
    }
    if (!is_dir($destination) && !mkdir($destination, 0775, true) && !is_dir($destination)) {
        throw new RuntimeException('Could not prepare restore workspace.');
    }

    if (system_backup_is_zip($archivePath)) {
        if (!system_backup_zip_available()) {
            throw new RuntimeException('This backup is a ZIP archive, but PHP zip support is not available.');
        }
        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) {
            throw new RuntimeException('Backup archive could not be opened.');
        }
        if (!$zip->extractTo($destination)) {
            $zip->close();
            throw new RuntimeException('Backup archive could not be extracted.');
        }
        $zip->close();
        return;
    }

    if (!system_backup_tar_available()) {
        throw new RuntimeException('This backup requires PHP phar support to read tar archives.');
    }
    try {
        $phar = new PharData($archivePath);
        $phar->extractTo($destination, null, true);
    } catch (Throwable $e) {
        throw new RuntimeException('Backup archive could not be extracted.');
    }
}

function validate_system_backup_archive(string $archivePath): array
{
    ensure_system_backup_archive_available();

    if (!is_file($archivePath)) {
        throw new RuntimeException('Backup file not found.');
    }

    $tmpDir = rtrim(sys_get_temp_dir(), '/\\') . '/fitness_backup_validate_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
    try {
        system_backup_extract_archive_to($archivePath, $tmpDir);

        $manifestPath = $tmpDir . '/manifest.json';
        $dbPath = $tmpDir . '/fitness.sqlite';
        if (!is_file($manifestPath)) {
            throw new RuntimeException('Backup archive is missing a manifest.');
        }
        $manifestRaw = file_get_contents($manifestPath);
        if (!is_string($manifestRaw) || trim($manifestRaw) === '') {
            throw new RuntimeException('Backup archive is missing a manifest.');
        }
        $manifest = json_decode($manifestRaw, true);
        if (!is_array($manifest)) {
            throw new RuntimeException('Backup manifest is invalid.');
        }
        if (!is_file($dbPath)) {
            throw new RuntimeException('Backup archive is missing database data.');
        }

        $dbHashExpected = trim((string) ($manifest['db']['sha256'] ?? ''));
        if ($dbHashExpected !== '') {
            $dbHashActual = hash_file('sha256', $dbPath) ?: '';
            if (!hash_equals($dbHashExpected, $dbHashActual)) {
                throw new RuntimeException('Backup database checksum mismatch.');
            }
        }

        return $manifest;
    } finally {
        if (is_dir($tmpDir)) {
            system_backup_recursive_delete($tmpDir);
        }
    }
}

function restore_system_backup_archive(array $config, string $archivePath): void
{
    ensure_system_backup_archive_available();
    validate_system_backup_archive($archivePath);

    $backupDir = ensure_system_backup_storage_dir($config);
    $tmpDir = $backupDir . '/.restore_tmp_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
    $stageDir = $backupDir . '/.restore_stage_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
    $dbPath = (string) ($config['db_path'] ?? '');
    $uploadDir = rtrim((string) ($config['upload_dir'] ?? ''), '/\\');
    if ($dbPath === '') {
        throw new RuntimeException('Database path is not configured.');
    }
    if ($uploadDir === '') {
        throw new RuntimeException('Upload directory is not configured.');
    }

    system_backup_extract_archive_to($archivePath, $tmpDir);

    $extractedDb = $tmpDir . '/fitness.sqlite';
    $extractedUploads = $tmpDir . '/uploads';
    if (!is_file($extractedDb)) {
        system_backup_recursive_delete($tmpDir);
        throw new RuntimeException('Extracted backup is missing database data.');
    }

    $rollbackDbPath = $stageDir . '/fitness.sqlite';
    $rollbackUploadsPath = $stageDir . '/uploads_before';
    $movedUploadsToStage = false;

    try {
        if (!is_dir($stageDir) && !mkdir($stageDir, 0775, true) && !is_dir($stageDir)) {
            throw new RuntimeException('Could not prepare restore staging area.');
        }

        if (is_file($dbPath)) {
            if (!copy($dbPath, $rollbackDbPath)) {
                throw new RuntimeException('Could not stage current database for rollback.');
            }
        }

        if (is_dir($uploadDir)) {
            if (!rename($uploadDir, $rollbackUploadsPath)) {
                throw new RuntimeException('Could not stage current uploads for rollback.');
            }
            $movedUploadsToStage = true;
        }

        $dbRestoreTmp = $dbPath . '.restore_tmp';
        if (file_exists($dbRestoreTmp)) {
            @unlink($dbRestoreTmp);
        }
        if (!copy($extractedDb, $dbRestoreTmp)) {
            throw new RuntimeException('Could not copy restored database.');
        }
        if (!@rename($dbRestoreTmp, $dbPath)) {
            if (!@copy($dbRestoreTmp, $dbPath)) {
                @unlink($dbRestoreTmp);
                throw new RuntimeException('Could not replace database with restored backup.');
            }
            @unlink($dbRestoreTmp);
        }

        if (is_dir($uploadDir)) {
            system_backup_recursive_delete($uploadDir);
        }
        if (is_dir($extractedUploads)) {
            system_backup_recursive_copy($extractedUploads, $uploadDir);
        } else {
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                throw new RuntimeException('Could not create uploads directory during restore.');
            }
        }

        if ($movedUploadsToStage && is_dir($rollbackUploadsPath)) {
            system_backup_recursive_delete($rollbackUploadsPath);
        }
        if (is_dir($stageDir)) {
            system_backup_recursive_delete($stageDir);
        }
        system_backup_recursive_delete($tmpDir);
    } catch (Throwable $e) {
        if (is_file($rollbackDbPath)) {
            @copy($rollbackDbPath, $dbPath);
        }
        if ($movedUploadsToStage && is_dir($rollbackUploadsPath)) {
            if (is_dir($uploadDir)) {
                system_backup_recursive_delete($uploadDir);
            }
            @rename($rollbackUploadsPath, $uploadDir);
        }
        if (is_dir($stageDir)) {
            system_backup_recursive_delete($stageDir);
        }
        if (is_dir($tmpDir)) {
            system_backup_recursive_delete($tmpDir);
        }
        throw $e;
    }
}

function should_run_scheduled_backup(string $frequency, string $lastAutoAt, string $runTime = '00:00'): bool
{
    $runTime = normalize_backup_run_time($runTime);
    [$hour, $minute] = array_map('intval', explode(':', $runTime));
    $now = new DateTimeImmutable('now');
    $todayRunAt = $now->setTime($hour, $minute);
    if ($now < $todayRunAt) {
        return false;
    }

    $last = trim($lastAutoAt);
    if ($last === '') {
        return true;
    }
    try {
        $lastAt = new DateTimeImmutable($last);
    } catch (Throwable) {
        return true;
    }

    $interval = new DateInterval(match (normalize_backup_frequency($frequency)) {
        'weekly' => 'P7D',
        'monthly' => 'P1M',
        default => 'P1D',
    });
    $lastScheduledSlot = $lastAt->setTime($hour, $minute);
    if ($lastAt < $lastScheduledSlot) {
        $lastScheduledSlot = $lastScheduledSlot->sub($interval);
    }
    $nextAt = $lastScheduledSlot->add($interval);

    return $now >= $nextAt;
}

function run_system_backup_scheduler(PDO $pdo, array $config, ?int $actorUserId = null): void
{
    $settings = system_backup_settings($pdo);
    if (empty($settings['enabled'])) {
        return;
    }
    $frequency = (string) ($settings['frequency'] ?? 'daily');
    $lastAutoAt = (string) ($settings['last_auto_at'] ?? '');
    $runTime = (string) ($settings['run_time'] ?? '00:00');
    if (!should_run_scheduled_backup($frequency, $lastAutoAt, $runTime)) {
        return;
    }

    try {
        $backup = create_system_backup($pdo, $config, 'auto', $actorUserId);
        set_app_setting_silent($pdo, 'backup_last_auto_at', now_iso(), $actorUserId);
        $retention = max(1, (int) ($settings['retention_count'] ?? 20));
        prune_system_backups($pdo, $config, $retention);

        audit_log(
            $pdo,
            $actorUserId,
            'backup_created',
            'system_backup',
            (string) ($backup['id'] ?? ''),
            'Automatic backup created.',
            null,
            [
                'trigger' => 'auto',
                'file_path' => (string) ($backup['file_path'] ?? ''),
            ]
        );
    } catch (Throwable $e) {
        if (str_contains(strtolower($e->getMessage()), 'already running')) {
            return;
        }
        audit_log(
            $pdo,
            $actorUserId,
            'backup_error',
            'system_backup',
            'auto_backup_error',
            'Automatic backup failed.',
            null,
            ['error' => $e->getMessage()]
        );
    }
}

function list_login_background_library(array $config): array
{
    $directory = rtrim((string) ($config['upload_dir'] ?? ''), '/\\') . '/app/login_backgrounds';
    if (!is_dir($directory)) {
        return [];
    }

    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'heif'];
    $items = [];
    $entries = @scandir($directory);
    if (!is_array($entries)) {
        return [];
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $absolutePath = $directory . '/' . $entry;
        if (!is_file($absolutePath)) {
            continue;
        }
        $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExtensions, true)) {
            continue;
        }
        $items[] = [
            'path' => 'app/login_backgrounds/' . $entry,
            'name' => $entry,
            'updated_at' => (int) (@filemtime($absolutePath) ?: 0),
        ];
    }

    usort(
        $items,
        static fn(array $left, array $right): int => ((int) ($right['updated_at'] ?? 0)) <=> ((int) ($left['updated_at'] ?? 0))
    );

    return $items;
}

function is_valid_login_background_path(array $config, string $path): bool
{
    $normalized = trim(str_replace('\\', '/', $path), '/');
    if ($normalized === '' || str_contains($normalized, '..')) {
        return false;
    }
    if (!str_starts_with($normalized, 'app/login_backgrounds/')) {
        return false;
    }
    $absolutePath = resolve_media_storage_path($config, $normalized);

    return is_string($absolutePath) && $absolutePath !== '' && is_file($absolutePath);
}
