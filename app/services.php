<?php

declare(strict_types=1);

const APPROVAL_TYPE_STEP_EXCEPTION = 'step_exception';
const APPROVAL_TYPE_WORKOUT_EXCEPTION = 'workout_exception';
const APPROVAL_TYPE_EXTRA_WORKOUT_OVERRIDE = 'extra_workout_override';

const APPROVAL_STATUS_PENDING = 'pending';
const APPROVAL_STATUS_APPROVED = 'approved';
const APPROVAL_STATUS_REJECTED = 'rejected';

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

    return $payload;
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

    return [
        'workout_type_id' => $workoutTypeId,
        'workout_type' => $rawType !== '' ? $rawType : 'Workout',
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
                user_id, log_date, steps, workout_done, workout_type_id, workout_type,
                junk_food, extra_workout, distance_km, training_calories_burned, weight, notes, step_exception_reason,
                workout_exception_reason, morning_walk, journaling,
                evening_chores, reading, created_at, updated_at
            ) VALUES (
                :user_id, :log_date, :steps, :workout_done, :workout_type_id, :workout_type,
                :junk_food, :extra_workout, :distance_km, :training_calories_burned, :weight, :notes, :step_exception_reason,
                :workout_exception_reason, :morning_walk, :journaling,
                :evening_chores, :reading, :created_at, :updated_at
            )',
            [
                ':user_id' => $payload['user_id'],
                ':log_date' => $payload['log_date'],
                ':steps' => $payload['steps'],
                ':workout_done' => $payload['workout_done'],
                ':workout_type_id' => $workoutTypeId,
                ':workout_type' => $workoutTypeName,
                ':junk_food' => $payload['junk_food'],
                ':extra_workout' => $payload['extra_workout'],
                ':distance_km' => $payload['distance_km'] ?? null,
                ':training_calories_burned' => $payload['training_calories_burned'] ?? null,
                ':weight' => $payload['weight'],
                ':notes' => $payload['notes'],
                ':step_exception_reason' => $payload['step_exception_reason'],
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
             workout_done = :workout_done,
             workout_type_id = :workout_type_id,
             workout_type = :workout_type,
             junk_food = :junk_food,
             extra_workout = :extra_workout,
             distance_km = :distance_km,
             training_calories_burned = :training_calories_burned,
             weight = :weight,
             notes = :notes,
             step_exception_reason = :step_exception_reason,
             workout_exception_reason = :workout_exception_reason,
             morning_walk = :morning_walk,
             journaling = :journaling,
             evening_chores = :evening_chores,
             reading = :reading,
             updated_at = :updated_at
         WHERE id = :id',
        [
            ':steps' => $payload['steps'],
            ':workout_done' => $payload['workout_done'],
            ':workout_type_id' => $workoutTypeId,
            ':workout_type' => $workoutTypeName,
            ':junk_food' => $payload['junk_food'],
            ':extra_workout' => $payload['extra_workout'],
            ':distance_km' => $payload['distance_km'] ?? null,
            ':training_calories_burned' => $payload['training_calories_burned'] ?? null,
            ':weight' => $payload['weight'],
            ':notes' => $payload['notes'],
            ':step_exception_reason' => $payload['step_exception_reason'],
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
    $workoutReason = trim((string) ($payload['workout_exception_reason'] ?? ''));
    $extraWorkout = (int) ($payload['extra_workout'] ?? 0) === 1;
    $junkFood = (int) ($payload['junk_food'] ?? 0) === 1;

    $specs = [
        APPROVAL_TYPE_STEP_EXCEPTION => [
            'enabled' => $stepReason !== '',
            'detail' => $stepReason,
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
            (string) $spec['detail']
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
    string $detail
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
                detail, requested_by, approved_by, decision_note,
                created_at, updated_at
            ) VALUES (
                :log_id, :user_id, :approval_type, :status,
                :detail, :requested_by, NULL, NULL,
                :created_at, :updated_at
            )',
            [
                ':log_id' => $logId,
                ':user_id' => $userId,
                ':approval_type' => $type,
                ':status' => APPROVAL_STATUS_PENDING,
                ':detail' => $detail,
                ':requested_by' => $actorUserId,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]
        );

        return;
    }

    $mustReset = $existing['status'] !== APPROVAL_STATUS_PENDING || (string) $existing['detail'] !== $detail;

    if ($mustReset) {
        db_execute(
            $pdo,
            'UPDATE approval_requests
             SET status = :status,
                 detail = :detail,
                 requested_by = :requested_by,
                 approved_by = NULL,
                 decision_note = NULL,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                ':status' => APPROVAL_STATUS_PENDING,
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
        'SELECT dlw.log_id,
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
        $workoutsByLogId[$logId][] = [
            'workout_type_id' => $workoutTypeId,
            'workout_type' => $workoutType,
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
         ORDER BY p.created_at DESC
         LIMIT ' . $limit,
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

function save_photo_entry(
    PDO $pdo,
    array $config,
    int $userId,
    string $date,
    string $category,
    string $caption,
    array $file,
    array $nutrition = []
): void
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
        $normalizedCategory = 'other';
    }

    $storedPath = save_uploaded_image(
        $config,
        $file,
        (new DateTimeImmutable($date))->format('Y/m'),
        (string) $userId,
        image_upload_policy($config, 'photo_entry')
    );

    db_execute(
        $pdo,
        'INSERT INTO photo_entries (
            user_id, log_date, category, caption, file_path,
            calories, protein_g, carbs_g, fat_g, fiber_g, sugar_g, sodium_mg, created_at
        ) VALUES (
            :user_id, :log_date, :category, :caption, :file_path,
            :calories, :protein_g, :carbs_g, :fat_g, :fiber_g, :sugar_g, :sodium_mg, :created_at
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
            ':created_at' => now_iso(),
        ]
    );
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

    if ($filePath !== '') {
        $remaining = db_fetch_one(
            $pdo,
            'SELECT COUNT(*) AS total FROM photo_entries WHERE file_path = :file_path',
            [':file_path' => $filePath]
        );
        $remainingCount = (int) ($remaining['total'] ?? 0);
        if ($remainingCount === 0) {
            $resolvedPath = resolve_media_storage_path($config, $filePath);
            if ($resolvedPath !== null && is_file($resolvedPath)) {
                $deleted = @unlink($resolvedPath);
                media_debug_log('delete_photo_entry', [
                    'stored_value' => $filePath,
                    'helper_input' => $filePath,
                    'normalized_value' => (string) (normalize_media_reference($filePath)['normalized'] ?? ''),
                    'final_url' => $resolvedPath,
                    'reason' => $deleted ? 'file_deleted' : 'unlink_failed',
                ]);
            } else {
                media_debug_log('delete_photo_entry', [
                    'stored_value' => $filePath,
                    'helper_input' => $filePath,
                    'normalized_value' => (string) (normalize_media_reference($filePath)['normalized'] ?? ''),
                    'final_url' => '',
                    'reason' => 'resolved_path_missing',
                ]);
            }
        }
    }

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
            finfo_close($finfo);
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
            finfo_close($finfo);
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
            finfo_close($finfo);
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

function create_user(PDO $pdo, array $payload): void
{
    $now = now_iso();

    db_execute(
        $pdo,
        'INSERT INTO users (
            username, password_hash, display_name, role,
            step_goal, step_days_mask, workout_target,
            workout_days_mask, workout_strict, ideal_weight,
            motivation_quote, primary_goal_type, primary_goal_value, active, created_at, updated_at
        ) VALUES (
            :username, :password_hash, :display_name, :role,
            :step_goal, :step_days_mask, :workout_target,
            :workout_days_mask, :workout_strict, :ideal_weight,
            :motivation_quote, :primary_goal_type, :primary_goal_value, :active, :created_at, :updated_at
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
            ':motivation_quote' => $payload['motivation_quote'],
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
             motivation_quote = :motivation_quote,
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
            ':motivation_quote' => $payload['motivation_quote'],
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
        'SELECT * FROM goals WHERE ' . implode(' AND ', $conditions) . ' ORDER BY status ASC, due_date IS NULL, due_date ASC, created_at DESC',
        $params
    );
}

function create_goal(PDO $pdo, array $payload, int $actorUserId): void
{
    $now = now_iso();
    db_execute(
        $pdo,
        'INSERT INTO goals (scope, team_id, user_id, title, target_type, target_value, current_value, due_date, status, created_by, created_at, updated_at)
         VALUES (:scope, :team_id, :user_id, :title, :target_type, :target_value, :current_value, :due_date, "active", :created_by, :created_at, :updated_at)',
        [
            ':scope' => $payload['scope'],
            ':team_id' => $payload['team_id'],
            ':user_id' => $payload['user_id'],
            ':title' => $payload['title'],
            ':target_type' => $payload['target_type'],
            ':target_value' => $payload['target_value'],
            ':current_value' => $payload['current_value'] ?? 0,
            ':due_date' => $payload['due_date'],
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
        'UPDATE goals SET status = :status, updated_at = :updated_at WHERE id = :id',
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

    db_execute(
        $pdo,
        'UPDATE goals
         SET title = :title, target_type = :target_type, target_value = :target_value, due_date = :due_date, updated_at = :updated_at
         WHERE id = :id',
        [
            ':title' => trim((string) $payload['title']),
            ':target_type' => trim((string) $payload['target_type']),
            ':target_value' => $payload['target_value'],
            ':due_date' => $payload['due_date'],
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
    if ($normalized === 'distance_km') {
        $normalized = 'km';
    }
    if (in_array($normalized, ['steps', 'km', 'workouts', 'score', 'strikes', 'penalties', 'weight'], true)) {
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
        $type === 'workouts' => (float) ($metric['workout_success'] ?? 0),
        $type === 'score' => (float) ($metric['score'] ?? 0),
        $type === 'strikes' => (float) ($metric['current_strikes'] ?? 0),
        $type === 'penalties' => (float) ($metric['total_penalty'] ?? 0),
        $type === 'weight' => (float) ($metric['latest_weight'] ?? 0),
        str_starts_with($type, 'habit:') => (float) (($metric['habit_counts'][substr($type, 6)] ?? 0)),
        default => (float) ($goal['current_value'] ?? 0),
    };
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

    if (in_array($type, ['strikes', 'penalties'], true)) {
        return $progressValue <= $target;
    }

    return $progressValue >= $target;
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
                 updated_at = :updated_at
             WHERE id = :id AND status = "active"',
            [
                ':current_value' => round($progressValue, 2),
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

function archive_challenge(PDO $pdo, int $actorUserId): void
{
    $before = db_fetch_one($pdo, 'SELECT * FROM challenge_settings WHERE id = 1');
    if ($before === null) {
        return;
    }

    db_execute(
        $pdo,
        'UPDATE challenge_settings SET active = 0, deleted_at = :deleted_at, updated_at = :updated_at WHERE id = 1',
        [':deleted_at' => now_iso(), ':updated_at' => now_iso()]
    );
    $after = db_fetch_one($pdo, 'SELECT * FROM challenge_settings WHERE id = 1');
    audit_log($pdo, $actorUserId, 'challenge_archived', 'challenge_settings', '1', 'Challenge archived.', audit_snapshot($before), audit_snapshot($after));
}

function list_achievements(PDO $pdo, bool $activeOnly = true): array
{
    $where = $activeOnly ? 'WHERE active = 1' : '';

    return db_fetch_all($pdo, 'SELECT * FROM achievements ' . $where . ' ORDER BY scope ASC, name ASC');
}

function list_achievements_for_admin(PDO $pdo): array
{
    return db_fetch_all(
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
         ORDER BY a.created_at DESC, a.name ASC'
    );
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
    ?string $triggerKey = null
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

    db_execute(
        $pdo,
        'INSERT INTO achievements (code, name, description, scope, trigger_key, image_path, reward_text, active, created_by, created_at, updated_at)
         VALUES (:code, :name, :description, :scope, :trigger_key, :image_path, :reward_text, :active, :created_by, :created_at, :updated_at)',
        [
            ':code' => $rawCode,
            ':name' => $name,
            ':description' => $description,
            ':scope' => $scope,
            ':trigger_key' => $triggerKey,
            ':image_path' => $imagePath,
            ':reward_text' => $rewardText,
            ':active' => $active ? 1 : 0,
            ':created_by' => $actorUserId,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]
    );
    $achievement = db_fetch_one($pdo, 'SELECT * FROM achievements WHERE code = :code', [':code' => $rawCode]);
    audit_log($pdo, $actorUserId, 'achievement_created', 'achievement', (string) ($achievement['id'] ?? ''), 'Achievement created.', null, audit_snapshot($achievement));

    return (int) ($achievement['id'] ?? 0);
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
        $metricKey
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

    $name = trim((string) ($payload['name'] ?? ''));
    if ($name === '') {
        throw new InvalidArgumentException('Achievement name is required.');
    }
    $scope = in_array(($payload['scope'] ?? 'user'), ['user', 'team'], true) ? (string) $payload['scope'] : 'user';
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
             reward_text = :reward_text,
             active = :active,
             updated_at = :updated_at
         WHERE id = :id',
        [
            ':code' => $code,
            ':name' => $name,
            ':description' => trim((string) ($payload['description'] ?? '')),
            ':scope' => $scope,
            ':trigger_key' => $triggerKey,
            ':image_path' => $payload['image_path'] ?? null,
            ':reward_text' => trim((string) ($payload['reward_text'] ?? '')),
            ':active' => $active ? 1 : 0,
            ':updated_at' => now_iso(),
            ':id' => $achievementId,
        ]
    );

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

    return db_fetch_all(
        $pdo,
        'SELECT aa.*, aa.id AS award_id, a.name, a.description, a.scope, a.code, a.image_path, a.reward_text, u.display_name AS awarded_by_name
         FROM achievement_awards aa
         JOIN achievements a ON a.id = aa.achievement_id
         LEFT JOIN users u ON u.id = aa.awarded_by
         WHERE ' . implode(' AND ', $conditions) . '
         ORDER BY aa.awarded_at DESC',
        $params
    );
}

function list_recent_achievement_awards(PDO $pdo, int $limit = 200): array
{
    $safeLimit = max(1, min(1000, $limit));

    return db_fetch_all(
        $pdo,
        'SELECT aa.*, a.name, a.description, a.scope, a.code, a.image_path, a.reward_text,
                u.display_name AS awarded_by_name,
                owner.display_name AS owner_name,
                t.name AS team_name
         FROM achievement_awards aa
         JOIN achievements a ON a.id = aa.achievement_id
         LEFT JOIN users u ON u.id = aa.awarded_by
         LEFT JOIN users owner ON owner.id = aa.user_id
         LEFT JOIN teams t ON t.id = aa.team_id
         ORDER BY aa.awarded_at DESC
         LIMIT ' . $safeLimit
    );
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
        if (isset($byTrigger['three_workouts_week']) && has_three_workouts_in_week($pdo, $userId)) {
            award_achievement($pdo, (int) $byTrigger['three_workouts_week']['id'], $userId, null, null, 'Automatic unlock.');
        }
    }

    if ($teamId !== null && isset($byTrigger['team_active'])) {
        $activeMembers = db_fetch_one($pdo, 'SELECT COUNT(*) AS total FROM team_memberships WHERE team_id = :team_id AND active = 1', [':team_id' => $teamId]);
        if ((int) ($activeMembers['total'] ?? 0) > 0) {
            award_achievement($pdo, (int) $byTrigger['team_active']['id'], null, $teamId, null, 'Automatic unlock.');
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
            $value += (float) ($metric['workout_success'] ?? 0);
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
        'SELECT log_date FROM daily_logs WHERE user_id = :user_id AND workout_done = 1 ORDER BY log_date ASC',
        [':user_id' => $userId]
    );
    $counts = [];
    foreach ($rows as $row) {
        $week = week_start_for(new DateTimeImmutable((string) $row['log_date']))->format('Y-m-d');
        $counts[$week] = ($counts[$week] ?? 0) + 1;
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
