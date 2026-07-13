<?php

declare(strict_types=1);

$weekdayNames = [];
for ($i = 0; $i < 7; $i++) {
    $weekdayNames[$i] = t('weekday.' . $i);
}

$trainingTableScope = (string) ($trainingTableScope ?? 'week');
$isAllTrainingScope = $trainingTableScope === 'all';
// Excuses only exist to justify a penalty. With penalties off there is nothing to
// excuse, so the column is not rendered at all (not merely hidden with CSS).
$canEditSheet = !isset($canEditSheet) || (bool) $canEditSheet;
$penaltiesEnabled = isset($penaltiesEnabled)
    ? (bool) $penaltiesEnabled
    : penalties_enabled($GLOBALS['pdo']);
$trainingRangeStart = (string) ($trainingRangeStart ?? $weekStart ?? to_date(null));
$trainingRangeEnd = (string) ($trainingRangeEnd ?? $weekEnd ?? $trainingRangeStart);
$allSheetUrl = '/?' . http_build_query([
    'page' => 'week_editor',
    'user_id' => (int) ($selectedUser['id'] ?? 0),
    'range' => 'all',
]);
$weekSheetUrl = '/?' . http_build_query([
    'page' => 'week_editor',
    'user_id' => (int) ($selectedUser['id'] ?? 0),
    'range' => 'week',
    'week' => date_to_iso_week((string) ($weekStart ?? to_date(null))),
]);
$summaryUrl = '/?' . http_build_query(array_filter([
    'page' => 'table',
    'user_id' => (int) ($selectedUser['id'] ?? 0),
    'range' => $isAllTrainingScope ? 'all' : 'week',
    'week' => $isAllTrainingScope ? null : date_to_iso_week((string) ($weekStart ?? to_date(null))),
], static fn($value): bool => $value !== null && $value !== ''));

$userStepGoal = max(0, (int) ($selectedUser['step_goal'] ?? 0));
$userDistanceGoal = 0.0;
if ((string) ($selectedUser['primary_goal_type'] ?? 'steps') === 'km') {
    $userDistanceGoal = max(0.0, (float) ($selectedUser['primary_goal_value'] ?? 0));
}
$approvalRequestsByDate = is_array($approvalRequestsByDate ?? null) ? (array) $approvalRequestsByDate : [];
$canSwitchTrainingUser = is_admin($currentUser ?? []);
$workoutTypeById = [];
foreach ((array) ($workoutTypes ?? []) as $type) {
    $typeId = (int) ($type['id'] ?? 0);
    if ($typeId <= 0) {
        continue;
    }
    $workoutTypeById[$typeId] = (string) ($type['name'] ?? '');
}

$resolveWorkoutSelection = static function (?int $workoutTypeId, string $workoutTypeText, array $workoutTypeById): array {
    $typeId = $workoutTypeId !== null && $workoutTypeId > 0 ? $workoutTypeId : null;
    $typeText = trim($workoutTypeText);
    $isKnownType = $typeId !== null && isset($workoutTypeById[$typeId]);

    $selectValue = '';
    $customValue = '';
    if ($isKnownType) {
        $selectValue = (string) $typeId;
    } elseif ($typeText !== '') {
        $selectValue = '__custom__';
        $customValue = $typeText;
    }

    return [
        'select_value' => $selectValue,
        'custom_value' => $customValue,
        'is_custom' => $selectValue === '__custom__',
        'is_filled' => $selectValue !== '' && ($selectValue !== '__custom__' || $customValue !== ''),
    ];
};
?>
<section class="screen stack-lg">
    <div class="hero-panel">
        <div>
            <p class="eyebrow"><?= e(t('nav.table')) ?></p>
            <h1><?= e(t('table.editor_title')) ?></h1>
            <p class="muted">
                <?= $isAllTrainingScope
                    ? e(t('table.all_subtitle', ['start' => format_date_eu($trainingRangeStart), 'end' => format_date_eu($trainingRangeEnd)]))
                    : e(t('table.subtitle')) ?>
            </p>
        </div>
    </div>

    <article class="panel training-sheet-panel">
        <div class="panel-head training-sheet-head">
            <form method="get" class="control-strip wrap training-sheet-controls">
                <input type="hidden" name="page" value="week_editor">
                <input type="hidden" name="range" value="<?= $isAllTrainingScope ? 'all' : 'week' ?>">
                <?php if ($canSwitchTrainingUser): ?>
                    <label>
                        <?= e(t('common.user')) ?>
                        <select name="user_id" onchange="this.form.submit()">
                            <?php foreach ($users as $user): ?>
                                <option value="<?= (int) $user['id'] ?>" <?= (int) $selectedUser['id'] === (int) $user['id'] ? 'selected' : '' ?>>
                                    <?= e((string) $user['display_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php else: ?>
                    <input type="hidden" name="user_id" value="<?= (int) $selectedUser['id'] ?>">
                <?php endif; ?>

                <div class="training-sheet-view-tabs" role="group" aria-label="<?= e(t('dashboard.view_mode')) ?>">
                    <a class="<?= $isAllTrainingScope ? 'active' : '' ?>" href="<?= e($allSheetUrl) ?>" <?= $isAllTrainingScope ? 'aria-current="page"' : '' ?>><?= e(t('dashboard.all_challenge')) ?></a>
                    <a class="<?= !$isAllTrainingScope ? 'active' : '' ?>" href="<?= e($weekSheetUrl) ?>" <?= !$isAllTrainingScope ? 'aria-current="page"' : '' ?>><?= e(t('common.week')) ?></a>
                </div>

                <?php if (!$isAllTrainingScope): ?>
                <label>
                    <?= e(t('common.week')) ?>
                    <input type="week" name="week" value="<?= e(date_to_iso_week($weekStart)) ?>" onchange="this.form.submit()">
                </label>
                <?php endif; ?>
            </form>
            <a class="btn btn-ghost small" href="<?= e($summaryUrl) ?>"><?= e($isAllTrainingScope ? t('table.all_summary') : t('table.week_summary')) ?></a>
        </div>

        <?php if (!$canEditSheet): ?>
            <p class="sheet-readonly-banner" role="status">
                <span aria-hidden="true">&#128274;</span>
                <?= e(t('table.readonly_other_user', ['name' => (string) ($selectedUser['display_name'] ?? '')])) ?>
            </p>
        <?php endif; ?>

        <div class="training-sheet-wrap week-editor-grid" id="week-editor-grid" data-step-goal="<?= $userStepGoal ?>" data-training-scope="<?= e($trainingTableScope) ?>" data-can-edit="<?= $canEditSheet ? '1' : '0' ?>">
            <?php // A disabled fieldset disables every form control inside it natively, so
                  // a read-only sheet cannot be typed into even if a rule is missed. ?>
            <fieldset class="sheet-fieldset" <?= $canEditSheet ? '' : 'disabled' ?>>
            <table class="table compact training-sheet-table">
                <thead>
                <tr>
                    <th class="sheet-day-col"><?= e(t('common.date')) ?></th>
                    <th><?= e(t('entries.log_time')) ?></th>
                    <th><?= e(t('metric.steps')) ?></th>
                    <th><?= e(t('metric.distance_km')) ?></th>
                    <th><?= e(t('table.completed_workout')) ?></th>
                    <th><?= e(t('table.primary_workout_type')) ?></th>
                    <th><?= e(t('table.extra_wo')) ?></th>
                    <th><?= e(t('entries.training_calories_burned')) ?></th>
                    <th><?= e(t('metric.weight')) ?></th>
                    <th><?= e(t('table.junk')) ?></th>
                    <th><?= e(t('table.habits_section')) ?></th>
                    <?php if ($penaltiesEnabled): ?><th><?= e(t('table.excuses_section')) ?></th><?php endif; ?>
                    <th><?= e(t('common.notes')) ?></th>
                    <?php if ($canEditSheet): ?><th><?= e(t('common.save')) ?></th><?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($weekDates as $idx => $date): ?>
                    <?php
                    try {
                        $weekdayIndex = max(0, min(6, (int) (new DateTimeImmutable($date))->format('N') - 1));
                    } catch (Throwable) {
                        $weekdayIndex = $idx % 7;
                    }
                    $log = $logsByDate[$date] ?? [];
                    $logWorkouts = is_array($log['workouts'] ?? null) ? array_values((array) $log['workouts']) : [];
                    if ($logWorkouts === [] && !empty($log)) {
                        $legacyWorkoutTypeId = !empty($log['workout_type_id']) ? (int) $log['workout_type_id'] : null;
                        $legacyWorkoutType = trim((string) ($log['workout_type'] ?? ''));
                        if ($legacyWorkoutTypeId !== null || $legacyWorkoutType !== '') {
                            $logWorkouts[] = [
                                'workout_type_id' => $legacyWorkoutTypeId,
                                'workout_type' => $legacyWorkoutType,
                            ];
                        }
                    }

                    $primaryWorkout = is_array($logWorkouts[0] ?? null) ? (array) $logWorkouts[0] : ['workout_type_id' => null, 'workout_type' => ''];
                    $extraWorkouts = array_values(array_slice($logWorkouts, 1));

                    $primaryTypeId = !empty($primaryWorkout['workout_type_id']) ? (int) $primaryWorkout['workout_type_id'] : null;
                    $primaryTypeName = trim((string) ($primaryWorkout['workout_type'] ?? ''));
                    $primarySelection = $resolveWorkoutSelection($primaryTypeId, $primaryTypeName, $workoutTypeById);

                    $completedWorkout = (int) ($log['workout_done'] ?? 0) === 1 || $primarySelection['is_filled'] || $extraWorkouts !== [];

                    $stepsRaw = isset($log['steps']) ? (string) $log['steps'] : '';
                    $stepValue = $stepsRaw === '' ? null : (int) $stepsRaw;
                    $logTimeValue = normalize_log_time($log['log_time'] ?? '', '00:00');
                    $showStepExcuse = $userStepGoal > 0 && ($stepValue === null || $stepValue < $userStepGoal);
                    $distanceRaw = isset($log['distance_km']) ? (string) $log['distance_km'] : '';
                    $distanceValue = $distanceRaw === '' ? null : (float) $distanceRaw;
                    $showDistanceExcuse = $userDistanceGoal > 0 && ($distanceValue === null || $distanceValue < $userDistanceGoal);
                    $showWorkoutExcuse = !$completedWorkout && !$primarySelection['is_filled'] && $extraWorkouts === [];
                    $dayApprovals = is_array($approvalRequestsByDate[$date] ?? null) ? (array) $approvalRequestsByDate[$date] : [];
                    $relevantApprovalTypes = [];
                    if ($showStepExcuse) {
                        $relevantApprovalTypes[] = APPROVAL_TYPE_STEP_EXCEPTION;
                    }
                    if ($showDistanceExcuse) {
                        $relevantApprovalTypes[] = APPROVAL_TYPE_DISTANCE_EXCEPTION;
                    }
                    if ($showWorkoutExcuse) {
                        $relevantApprovalTypes[] = APPROVAL_TYPE_WORKOUT_EXCEPTION;
                    }
                    $requestState = 'not_sent';
                    $hasPendingRequest = false;
                    $hasResentRequest = false;
                    $hasRejectedRequest = false;
                    $hasApprovedRequest = false;
                    foreach ($relevantApprovalTypes as $approvalType) {
                        $approvalRow = is_array($dayApprovals[$approvalType] ?? null) ? (array) $dayApprovals[$approvalType] : null;
                        if ($approvalRow === null) {
                            continue;
                        }
                        $status = (string) ($approvalRow['status'] ?? '');
                        if ($status === APPROVAL_STATUS_PENDING) {
                            $hasPendingRequest = true;
                            if ((string) ($approvalRow['request_state'] ?? '') === 'resent' || (int) ($approvalRow['resent_count'] ?? 0) > 0) {
                                $hasResentRequest = true;
                            }
                        } elseif ($status === APPROVAL_STATUS_REJECTED) {
                            $hasRejectedRequest = true;
                        } elseif ($status === APPROVAL_STATUS_APPROVED) {
                            $hasApprovedRequest = true;
                        }
                    }
                    if ($hasPendingRequest) {
                        $requestState = $hasResentRequest ? 'resent' : 'sent';
                    } elseif ($hasRejectedRequest) {
                        $requestState = 'rejected';
                    } elseif ($hasApprovedRequest) {
                        $requestState = 'approved';
                    }
                    $requestStateLabel = match ($requestState) {
                        'sent' => t('table.request_sent'),
                        'resent' => t('table.request_resent'),
                        'approved' => t('table.request_approved'),
                        'rejected' => t('table.request_rejected'),
                        default => t('table.request_not_sent'),
                    };
                    $hasReviewDetail = $requestState !== 'not_sent'
                        || trim((string) ($log['step_exception_reason'] ?? '')) !== ''
                        || trim((string) ($log['distance_exception_reason'] ?? '')) !== ''
                        || trim((string) ($log['workout_exception_reason'] ?? '')) !== '';
                    ?>
                    <tr class="week-day-card"
                        data-date="<?= e($date) ?>"
                        data-step-goal="<?= $userStepGoal ?>"
                        data-distance-goal="<?= e((string) $userDistanceGoal) ?>"
                        data-request-state="<?= e($requestState) ?>">
                        <th scope="row" class="sheet-day-cell" data-label="<?= e(t('common.date')) ?>">
                            <strong><?= e($weekdayNames[$weekdayIndex] ?? $date) ?></strong>
                            <span><?= e(format_date_eu($date)) ?></span>
                        </th>
                        <td class="sheet-time-cell" data-label="<?= e(t('entries.log_time')) ?>">
                            <label class="sheet-field">
                                <span class="sr-only"><?= e(t('entries.log_time')) ?></span>
                                <input type="time" name="log_time" value="<?= e($logTimeValue) ?>">
                            </label>
                        </td>
                        <td class="sheet-number-cell" data-label="<?= e(t('metric.steps')) ?>">
                            <label class="sheet-field">
                                <span class="sr-only"><?= e(t('metric.steps')) ?></span>
                                <input type="number" min="0" name="steps" value="<?= e((string) ($log['steps'] ?? '')) ?>" data-steps-input>
                            </label>
                        </td>
                        <td class="sheet-number-cell" data-label="<?= e(t('metric.distance_km')) ?>">
                            <label class="sheet-field">
                                <span class="sr-only"><?= e(t('metric.distance_km')) ?></span>
                                <input type="number" min="0" step="0.01" name="distance_km" value="<?= e((string) ($log['distance_km'] ?? '')) ?>">
                            </label>
                        </td>
                        <td class="sheet-check-cell" data-label="<?= e(t('table.completed_workout')) ?>">
                            <label class="check week-check sheet-checkbox" data-help="<?= e(t('table.week_help_workout_excuse')) ?>">
                                <input type="checkbox" name="workout_done" value="1" data-workout-done <?= $completedWorkout ? 'checked' : '' ?>>
                                <?= e(t('common.yes')) ?>
                            </label>
                        </td>
                        <td class="sheet-workout-cell" data-label="<?= e(t('table.primary_workout_type')) ?>">
                            <div class="week-help-wrap" data-help="<?= e(t('table.week_help_extra_workout')) ?>">
                                <label class="week-field sheet-field">
                                    <span class="sr-only"><?= e(t('table.primary_workout_type')) ?></span>
                                    <div class="workout-type-control" data-workout-control>
                                        <select name="workout_type_id" data-primary-workout-select <?= $primarySelection['is_custom'] ? 'hidden' : '' ?>>
                                            <option value=""><?= e(t('common.none')) ?></option>
                                            <?php foreach ((array) ($workoutTypes ?? []) as $type): ?>
                                                <option value="<?= (int) $type['id'] ?>" <?= $primarySelection['select_value'] === (string) ((int) $type['id']) ? 'selected' : '' ?>><?= e((string) $type['name']) ?></option>
                                            <?php endforeach; ?>
                                            <?= wk_routine_options_html((array) ($userRoutines ?? []), (string) ($workoutSelectionValue ?? '')) ?>
                                            <option value="__custom__" <?= $primarySelection['is_custom'] ? 'selected' : '' ?>><?= e(t('entries.workout_other')) ?></option>
                                        </select>
                                        <div class="workout-type-custom" data-primary-workout-custom-wrap <?= $primarySelection['is_custom'] ? '' : 'hidden' ?>>
                                            <input type="text" name="workout_type" data-primary-workout-custom list="workout-type-options-week" value="<?= e($primarySelection['custom_value']) ?>" placeholder="<?= e(t('entries.workout_type_placeholder')) ?>">
                                            <button class="btn small btn-ghost" type="button" data-primary-workout-reset><?= e(t('common.cancel')) ?></button>
                                        </div>
                                    </div>
                                </label>
                            </div>
                        </td>
                        <td class="sheet-extra-cell" data-label="<?= e(t('table.extra_wo')) ?>">
                            <div class="week-extra-toolbar week-help-wrap sheet-extra-toolbar" data-help="<?= e(t('table.week_help_extra_workout')) ?>">
                                <button class="btn btn-ghost small" type="button" data-extra-toggle><?= e(t('table.add_extra_workout')) ?></button>
                                <span class="muted small" data-extra-count></span>
                            </div>

                            <div class="week-extra-panel sheet-extra-panel" data-extra-panel <?= $extraWorkouts !== [] ? '' : 'hidden' ?>>
                                <div class="week-extra-list" data-extra-list>
                                    <?php foreach ($extraWorkouts as $extraWorkout): ?>
                                        <?php
                                        $extraTypeId = !empty($extraWorkout['workout_type_id']) ? (int) $extraWorkout['workout_type_id'] : null;
                                        $extraTypeName = trim((string) ($extraWorkout['workout_type'] ?? ''));
                                        $extraSelection = $resolveWorkoutSelection($extraTypeId, $extraTypeName, $workoutTypeById);
                                        ?>
                                        <div class="week-extra-row" data-extra-row>
                                            <label class="week-field">
                                                <span><?= e(t('entries.workout_type')) ?></span>
                                                <select data-workout-select>
                                                    <option value=""><?= e(t('common.none')) ?></option>
                                                    <?php foreach ((array) ($workoutTypes ?? []) as $type): ?>
                                                        <option value="<?= (int) $type['id'] ?>" <?= $extraSelection['select_value'] === (string) ((int) $type['id']) ? 'selected' : '' ?>><?= e((string) $type['name']) ?></option>
                                                    <?php endforeach; ?>
                                                    <?= wk_routine_options_html((array) ($userRoutines ?? []), (string) ($workoutSelectionValue ?? '')) ?>
                                                    <option value="__custom__" <?= $extraSelection['is_custom'] ? 'selected' : '' ?>><?= e(t('entries.workout_other')) ?></option>
                                                </select>
                                            </label>
                                            <label class="week-field week-extra-custom" data-workout-custom-wrap <?= $extraSelection['is_custom'] ? '' : 'hidden' ?>>
                                                <span><?= e(t('entries.custom_workout_type')) ?></span>
                                                <input type="text" data-workout-custom-input value="<?= e($extraSelection['custom_value']) ?>" placeholder="<?= e(t('entries.workout_type_placeholder')) ?>">
                                            </label>
                                            <button type="button" class="btn btn-ghost small" data-extra-remove><?= e(t('entries.remove_workout')) ?></button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <button type="button" class="btn btn-ghost small" data-extra-add><?= e(t('table.add_extra_workout')) ?></button>
                            </div>
                            <template data-extra-template>
                                <div class="week-extra-row" data-extra-row>
                                    <label class="week-field">
                                        <span><?= e(t('entries.workout_type')) ?></span>
                                        <select data-workout-select>
                                            <option value=""><?= e(t('common.none')) ?></option>
                                            <?php foreach ((array) ($workoutTypes ?? []) as $type): ?>
                                                <option value="<?= (int) $type['id'] ?>"><?= e((string) $type['name']) ?></option>
                                            <?php endforeach; ?>
                                            <?= wk_routine_options_html((array) ($userRoutines ?? []), (string) ($workoutSelectionValue ?? '')) ?>
                                            <option value="__custom__"><?= e(t('entries.workout_other')) ?></option>
                                        </select>
                                    </label>
                                    <label class="week-field week-extra-custom" data-workout-custom-wrap hidden>
                                        <span><?= e(t('entries.custom_workout_type')) ?></span>
                                        <input type="text" data-workout-custom-input placeholder="<?= e(t('entries.workout_type_placeholder')) ?>">
                                    </label>
                                    <button type="button" class="btn btn-ghost small" data-extra-remove><?= e(t('entries.remove_workout')) ?></button>
                                </div>
                            </template>
                        </td>
                        <td class="sheet-number-cell" data-label="<?= e(t('entries.training_calories_burned')) ?>">
                            <div class="week-help-wrap" data-help="<?= e(t('table.week_help_training_calories')) ?>">
                                <label class="sheet-field">
                                    <span class="sr-only"><?= e(t('entries.training_calories_burned')) ?></span>
                                    <input type="number" min="0" step="1" name="training_calories_burned" value="<?= e((string) ($log['training_calories_burned'] ?? '')) ?>">
                                </label>
                            </div>
                        </td>
                        <td class="sheet-number-cell" data-label="<?= e(t('metric.weight')) ?>">
                            <label class="sheet-field">
                                <span class="sr-only"><?= e(t('metric.weight')) ?></span>
                                <input type="number" step="0.1" name="weight" value="<?= e((string) ($log['weight'] ?? '')) ?>">
                            </label>
                        </td>
                        <td class="sheet-check-cell" data-label="<?= e(t('table.junk')) ?>">
                            <label class="check sheet-checkbox">
                                <input type="checkbox" name="junk_food" value="1" <?= !empty($log) && (int) ($log['junk_food'] ?? 0) === 1 ? 'checked' : '' ?>>
                                <?= e(t('common.yes')) ?>
                            </label>
                        </td>
                        <td class="sheet-habits-cell" data-label="<?= e(t('table.habits_section')) ?>">
                            <div class="week-help-wrap" data-help="<?= e(t('table.week_help_habits')) ?>">
                                <button type="button" class="btn btn-ghost small sheet-custom-habit-toggle" data-custom-habit-toggle><?= e(t('table.custom_habit')) ?></button>
                            </div>
                            <?php // The panel lives outside .week-help-wrap: that wrapper paints a
                                  // tooltip layer over its own children, which swallowed clicks on
                                  // the panel's buttons. ?>
                            <div class="week-custom-habit" data-custom-habit-panel hidden>
                                    <?php // Existing custom habits come first: creating a duplicate is the
                                          // common mistake when the form opens straight away. ?>
                                    <div class="custom-habit-existing" data-custom-habit-existing>
                                        <p class="custom-habit-section-label"><?= e(t('table.custom_habit_existing')) ?></p>
                                        <?php if ((array) ($customHabits ?? []) === []): ?>
                                            <p class="muted small custom-habit-empty" data-custom-habit-empty><?= e(t('table.custom_habit_empty')) ?></p>
                                        <?php else: ?>
                                            <ul class="custom-habit-list" data-custom-habit-list>
                                                <?php foreach ((array) ($customHabits ?? []) as $customHabit): ?>
                                                    <li class="custom-habit-row" data-custom-habit-code="<?= e((string) $customHabit['code']) ?>">
                                                        <button type="button" class="custom-habit-pick" data-custom-habit-pick="<?= e((string) $customHabit['code']) ?>">
                                                            <span><?= e((string) $customHabit['label']) ?></span>
                                                        </button>
                                                        <?php if ((int) ($customHabit['created_by'] ?? 0) === (int) ($currentUser['id'] ?? 0) || is_admin($currentUser)): ?>
                                                            <button type="button" class="custom-habit-remove" data-custom-habit-remove="<?= e((string) $customHabit['code']) ?>" aria-label="<?= e(t('common.delete')) ?>">&times;</button>
                                                        <?php endif; ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    </div>

                                    <button type="button" class="btn btn-ghost small custom-habit-create-trigger" data-custom-habit-create>
                                        + <?= e(t('table.custom_habit_create')) ?>
                                    </button>

                                    <div class="week-custom-habit-form" data-custom-habit-form hidden>
                                        <label class="week-field">
                                            <span><?= e(t('table.custom_habit')) ?></span>
                                            <input type="text" data-custom-habit-input placeholder="<?= e(t('table.custom_habit_placeholder')) ?>" maxlength="60">
                                        </label>
                                        <div class="week-custom-habit-actions">
                                            <button type="button" class="btn btn-primary small" data-custom-habit-save><?= e(t('common.create')) ?></button>
                                            <button type="button" class="btn btn-ghost small" data-custom-habit-cancel><?= e(t('common.cancel')) ?></button>
                                        </div>
                                    </div>
                                    <p class="muted small" data-custom-habit-status aria-live="polite"></p>
                            </div>
                            <div class="week-day-habits sheet-habits-list" data-habits-list>
                                <?php foreach ((array) ($habits ?? []) as $habit): ?>
                                    <?php $code = (string) $habit['code']; ?>
                                    <label class="check">
                                        <input type="checkbox" name="habit_<?= e($code) ?>" data-habit-code="<?= e($code) ?>" value="1" <?= !empty($log['habits'][$code]) && (int) $log['habits'][$code]['value'] === 1 ? 'checked' : '' ?>>
                                        <?= e((string) $habit['label']) ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <?php if ($penaltiesEnabled): ?>
                        <td class="sheet-review-cell" data-label="<?= e(t('table.excuses_section')) ?>">
                            <details class="sheet-review-details" <?= (!$isAllTrainingScope && $hasReviewDetail) ? 'open' : '' ?>>
                                <summary>
                                    <span><?= e(t('table.review_short')) ?></span>
                                    <small class="muted" data-request-state-label><?= e($requestStateLabel) ?></small>
                                </summary>
                                <div class="week-excuses-grid sheet-excuses-grid">
                                    <div class="week-help-wrap" data-help="<?= e(t('table.week_help_step_excuse')) ?>" data-step-excuse-wrap <?= $showStepExcuse ? '' : 'hidden' ?>>
                                        <label class="week-field week-field-secondary">
                                            <span><?= e(t('table.step_excuse_label')) ?></span>
                                            <input type="text" name="step_exception_reason" value="<?= e((string) ($log['step_exception_reason'] ?? '')) ?>">
                                        </label>
                                    </div>
                                    <div class="week-help-wrap" data-help="<?= e(t('table.week_help_distance_excuse')) ?>" data-distance-excuse-wrap <?= $showDistanceExcuse ? '' : 'hidden' ?>>
                                        <label class="week-field week-field-secondary">
                                            <span><?= e(t('table.distance_excuse_label')) ?></span>
                                            <input type="text" name="distance_exception_reason" value="<?= e((string) ($log['distance_exception_reason'] ?? '')) ?>">
                                        </label>
                                    </div>
                                    <div class="week-help-wrap" data-help="<?= e(t('table.week_help_workout_excuse')) ?>" data-workout-excuse-wrap <?= $showWorkoutExcuse ? '' : 'hidden' ?>>
                                        <label class="week-field week-field-secondary">
                                            <span><?= e(t('table.workout_excuse_label')) ?></span>
                                            <input type="text" name="workout_exception_reason" value="<?= e((string) ($log['workout_exception_reason'] ?? '')) ?>">
                                        </label>
                                    </div>
                                    <div class="week-request-actions" data-request-actions <?= ($showStepExcuse || $showDistanceExcuse || $showWorkoutExcuse) ? '' : 'hidden' ?>>
                                        <span class="muted small" data-request-state-label><?= e($requestStateLabel) ?></span>
                                        <div class="inline-actions-mini">
                                            <button type="button" class="btn btn-ghost small" data-request-send <?= $requestState === 'not_sent' ? '' : 'hidden' ?>><?= e(t('table.request_review')) ?></button>
                                            <button type="button" class="btn btn-ghost small" data-request-resend <?= in_array($requestState, ['sent', 'resent', 'rejected'], true) ? '' : 'hidden' ?>><?= e(t('table.request_resend')) ?></button>
                                        </div>
                                    </div>
                                </div>
                            </details>
                        </td>
                        <?php endif; ?>
                        <td class="sheet-notes-cell" data-label="<?= e(t('common.notes')) ?>">
                            <label class="sheet-field">
                                <span class="sr-only"><?= e(t('common.notes')) ?></span>
                                <input type="text" name="notes" value="<?= e((string) ($log['notes'] ?? '')) ?>">
                            </label>
                        </td>
                        <?php if ($canEditSheet): ?>
                        <td class="sheet-actions-cell" data-label="<?= e(t('common.save')) ?>">
                            <div class="week-day-actions">
                                <button class="btn small btn-primary js-save-row" type="button"><?= e(t('table.save_day')) ?></button>
                                <span class="save-status" aria-live="polite"></span>
                            </div>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <datalist id="workout-type-options-week">
                <?php foreach ((array) ($workoutTypes ?? []) as $type): ?>
                    <option value="<?= e((string) $type['name']) ?>"></option>
                <?php endforeach; ?>
            </datalist>
            </fieldset>
        </div>

        <?php if ($canEditSheet): ?>
        <div class="inline-actions week-save-all-row">
            <button class="btn btn-primary" type="button" id="save-all-rows" data-testid="save-all-rows"><?= e($isAllTrainingScope ? t('table.save_table') : t('table.save_week')) ?></button>
            <span id="save-all-status" class="save-all-status" aria-live="polite"></span>
        </div>
        <?php endif; ?>
    </article>
</section>

<script>
(function () {
    const grid = document.getElementById('week-editor-grid');
    const csrf = <?= json_encode(csrf_token()) ?>;
    const userId = <?= (int) $selectedUser['id'] ?>;
    const labels = {
        saving: <?= json_encode(t('common.saving')) ?>,
        saved: <?= json_encode(t('common.saved')) ?>,
        error: <?= json_encode(t('common.error')) ?>,
        savingWeek: <?= json_encode($isAllTrainingScope ? t('table.saving_table') : t('table.saving_week')) ?>,
        savedWeek: <?= json_encode($isAllTrainingScope ? t('table.saved_table') : t('table.saved_week')) ?>,
        savedWeekWithErrors: <?= json_encode($isAllTrainingScope ? t('table.saved_table_with_errors') : t('table.saved_week_with_errors')) ?>,
        extraCount: <?= json_encode(t('table.extra_workout_count')) ?>,
        extraNone: <?= json_encode(t('table.extra_workout_none')) ?>,
        customHabitSaving: <?= json_encode(t('table.custom_habit_saving')) ?>,
        customHabitCreated: <?= json_encode(t('table.custom_habit_created')) ?>,
        customHabitRequired: <?= json_encode(t('table.custom_habit_required')) ?>,
        customHabitError: <?= json_encode(t('table.custom_habit_error')) ?>,
        requestNotSent: <?= json_encode(t('table.request_not_sent')) ?>,
        requestSent: <?= json_encode(t('table.request_sent')) ?>,
        requestResent: <?= json_encode(t('table.request_resent')) ?>,
        requestApproved: <?= json_encode(t('table.request_approved')) ?>,
        requestRejected: <?= json_encode(t('table.request_rejected')) ?>,
    };

    if (!grid) {
        return;
    }

    // Extra-workout popover: opening the panel inline would grow the whole table
    // row, so float it over the sheet with fixed positioning (no reparenting, so
    // all existing per-card handlers keep working on the same nodes).
    let floatingPanel = null;
    let floatingToggle = null;
    const positionFloatingPanel = () => {
        if (!floatingPanel || !floatingToggle) {
            return;
        }
        const rect = floatingToggle.getBoundingClientRect();
        const width = Math.min(340, window.innerWidth - 24);
        floatingPanel.style.width = width + 'px';
        let left = Math.min(rect.left, window.innerWidth - width - 12);
        left = Math.max(12, left);
        floatingPanel.style.left = left + 'px';
        const spaceBelow = window.innerHeight - rect.bottom;
        const openDown = spaceBelow > 240 || spaceBelow >= rect.top;
        const room = openDown ? spaceBelow : rect.top;
        floatingPanel.style.maxHeight = Math.max(180, Math.min(window.innerHeight * 0.6, room - 16)) + 'px';
        if (openDown) {
            floatingPanel.style.top = (rect.bottom + 4) + 'px';
            floatingPanel.style.bottom = 'auto';
        } else {
            floatingPanel.style.top = 'auto';
            floatingPanel.style.bottom = (window.innerHeight - rect.top + 4) + 'px';
        }
    };
    const closeFloatingPanel = () => {
        if (floatingPanel) {
            floatingPanel.classList.remove('sheet-extra-floating');
            floatingPanel.style.width = '';
            floatingPanel.style.left = '';
            floatingPanel.style.top = '';
            floatingPanel.style.bottom = '';
            floatingPanel.style.maxHeight = '';
            floatingPanel.hidden = true;
        }
        floatingPanel = null;
        floatingToggle = null;
    };
    const openFloatingPanel = (toggle, panel) => {
        if (floatingPanel && floatingPanel !== panel) {
            closeFloatingPanel();
        }
        floatingPanel = panel;
        floatingToggle = toggle;
        panel.hidden = false;
        panel.classList.add('sheet-extra-floating');
        positionFloatingPanel();
    };
    window.addEventListener('scroll', () => { if (floatingPanel) { positionFloatingPanel(); } }, true);
    window.addEventListener('resize', () => { if (floatingPanel) { positionFloatingPanel(); } });
    document.addEventListener('click', (event) => {
        if (!floatingPanel) {
            return;
        }
        const target = event.target;
        if (floatingPanel.contains(target) || (floatingToggle && floatingToggle.contains(target))) {
            return;
        }
        closeFloatingPanel();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && floatingPanel) {
            closeFloatingPanel();
        }
    });

    const formatExtraCount = (count) => {
        if (count <= 0) {
            return '';
        }
        return labels.extraCount.replace('{count}', String(count));
    };

    const isFilled = (value) => String(value || '').trim() !== '';
    const requestStateLabel = (state) => {
        return {
            sent: labels.requestSent,
            resent: labels.requestResent,
            approved: labels.requestApproved,
            rejected: labels.requestRejected,
            not_sent: labels.requestNotSent,
        }[state] || labels.requestNotSent;
    };

    const setWorkoutRowVisibility = (row) => {
        if (!(row instanceof HTMLElement)) {
            return;
        }
        const select = row.querySelector('[data-workout-select]');
        const customWrap = row.querySelector('[data-workout-custom-wrap]');
        if (!(select instanceof HTMLSelectElement) || !(customWrap instanceof HTMLElement)) {
            return;
        }
        const isCustom = select.value === '__custom__';
        customWrap.hidden = !isCustom;
    };

    const parseWorkoutFromControls = (select, customInput) => {
        if (!(select instanceof HTMLSelectElement)) {
            return null;
        }
        const value = String(select.value || '').trim();
        if (value === '') {
            return null;
        }
        if (value === '__custom__') {
            const customValue = customInput instanceof HTMLInputElement ? String(customInput.value || '').trim() : '';
            if (!isFilled(customValue)) {
                return null;
            }
            return {
                workout_type_id: '',
                workout_type: customValue,
            };
        }
        return {
            workout_type_id: value,
            workout_type: '',
        };
    };

    const getPrimaryWorkout = (card) => {
        const select = card.querySelector('[data-primary-workout-select]');
        const customInput = card.querySelector('[data-primary-workout-custom]');
        return parseWorkoutFromControls(select, customInput);
    };

    const getExtraWorkoutRows = (card) => {
        const list = card.querySelector('[data-extra-list]');
        if (!(list instanceof HTMLElement)) {
            return [];
        }
        return [...list.querySelectorAll('[data-extra-row]')];
    };

    const getExtraWorkouts = (card) => {
        const rows = getExtraWorkoutRows(card);
        const workouts = [];
        rows.forEach((row) => {
            if (!(row instanceof HTMLElement)) {
                return;
            }
            const select = row.querySelector('[data-workout-select]');
            const customInput = row.querySelector('[data-workout-custom-input]');
            const parsed = parseWorkoutFromControls(select, customInput);
            if (parsed !== null) {
                workouts.push(parsed);
            }
        });
        return workouts;
    };

    const collectWorkouts = (card) => {
        const workouts = [];
        const primary = getPrimaryWorkout(card);
        if (primary !== null) {
            workouts.push(primary);
        }
        getExtraWorkouts(card).forEach((workout) => workouts.push(workout));
        return workouts;
    };

    const hasWorkoutSelection = (card) => {
        return collectWorkouts(card).length > 0;
    };

    const updateExtraCounter = (card) => {
        const counter = card.querySelector('[data-extra-count]');
        if (!(counter instanceof HTMLElement)) {
            return;
        }
        const count = getExtraWorkouts(card).length;
        counter.textContent = formatExtraCount(count);
    };

    const setRequestState = (card, state) => {
        const normalized = ['not_sent', 'sent', 'resent', 'approved', 'rejected'].includes(String(state))
            ? String(state)
            : 'not_sent';
        card.dataset.requestState = normalized;
        const stateLabels = card.querySelectorAll('[data-request-state-label]');
        stateLabels.forEach((stateLabel) => {
            if (!(stateLabel instanceof HTMLElement)) {
                return;
            }
            stateLabel.textContent = requestStateLabel(normalized);
        });
        const sendButton = card.querySelector('[data-request-send]');
        if (sendButton instanceof HTMLButtonElement) {
            sendButton.hidden = normalized !== 'not_sent';
        }
        const resendButton = card.querySelector('[data-request-resend]');
        if (resendButton instanceof HTMLButtonElement) {
            resendButton.hidden = !['sent', 'resent', 'rejected'].includes(normalized);
        }
    };

    const hasVisibleRequestReason = (card) => {
        const checks = [
            ['[data-step-excuse-wrap]', '[name="step_exception_reason"]'],
            ['[data-distance-excuse-wrap]', '[name="distance_exception_reason"]'],
            ['[data-workout-excuse-wrap]', '[name="workout_exception_reason"]'],
        ];
        for (const [wrapSelector, inputSelector] of checks) {
            const wrap = card.querySelector(wrapSelector);
            const input = card.querySelector(inputSelector);
            if (!(wrap instanceof HTMLElement) || !(input instanceof HTMLInputElement)) {
                continue;
            }
            if (wrap.hidden) {
                continue;
            }
            if (isFilled(input.value)) {
                return true;
            }
        }

        return false;
    };

    const updateExcuseVisibility = (card) => {
        const stepExcuseWrap = card.querySelector('[data-step-excuse-wrap]');
        const distanceExcuseWrap = card.querySelector('[data-distance-excuse-wrap]');
        const workoutExcuseWrap = card.querySelector('[data-workout-excuse-wrap]');
        const stepsInput = card.querySelector('[data-steps-input]');
        const distanceInput = card.querySelector('[name="distance_km"]');
        const workoutDoneInput = card.querySelector('[data-workout-done]');
        const requestActions = card.querySelector('[data-request-actions]');

        const stepGoal = Number(card.dataset.stepGoal || 0);
        const rawSteps = stepsInput instanceof HTMLInputElement ? String(stepsInput.value || '').trim() : '';
        const parsedSteps = rawSteps === '' ? null : Number(rawSteps);
        const stepMissed = stepGoal > 0 && (parsedSteps === null || Number.isNaN(parsedSteps) || parsedSteps < stepGoal);
        if (stepExcuseWrap instanceof HTMLElement) {
            stepExcuseWrap.hidden = !stepMissed;
        }

        const distanceGoal = Number(card.dataset.distanceGoal || 0);
        const rawDistance = distanceInput instanceof HTMLInputElement ? String(distanceInput.value || '').trim() : '';
        const parsedDistance = rawDistance === '' ? null : Number(rawDistance);
        const distanceMissed = distanceGoal > 0 && (parsedDistance === null || Number.isNaN(parsedDistance) || parsedDistance < distanceGoal);
        if (distanceExcuseWrap instanceof HTMLElement) {
            distanceExcuseWrap.hidden = !distanceMissed;
        }

        const completedChecked = workoutDoneInput instanceof HTMLInputElement ? workoutDoneInput.checked : false;
        const primaryWorkout = getPrimaryWorkout(card);
        const extraWorkouts = getExtraWorkouts(card);
        const showWorkoutExcuse = !completedChecked && primaryWorkout === null && extraWorkouts.length === 0;
        if (workoutExcuseWrap instanceof HTMLElement) {
            workoutExcuseWrap.hidden = !showWorkoutExcuse;
        }

        const needsRequest = stepMissed || distanceMissed || showWorkoutExcuse;
        if (requestActions instanceof HTMLElement) {
            requestActions.hidden = !needsRequest;
        }
        if (!needsRequest) {
            setRequestState(card, 'not_sent');
        }
    };

    const enforceCompletedFromSelections = (card) => {
        const workoutDoneInput = card.querySelector('[data-workout-done]');
        if (!(workoutDoneInput instanceof HTMLInputElement)) {
            return;
        }
        if (hasWorkoutSelection(card)) {
            workoutDoneInput.checked = true;
        }
    };

    const clearPrimaryWorkout = (card) => {
        const select = card.querySelector('[data-primary-workout-select]');
        const customWrap = card.querySelector('[data-primary-workout-custom-wrap]');
        const customInput = card.querySelector('[data-primary-workout-custom]');
        if (select instanceof HTMLSelectElement) {
            select.hidden = false;
            select.value = '';
        }
        if (customWrap instanceof HTMLElement) {
            customWrap.hidden = true;
        }
        if (customInput instanceof HTMLInputElement) {
            customInput.value = '';
        }
    };

    const setupExtraRow = (card, row) => {
        if (!(row instanceof HTMLElement)) {
            return;
        }
        const select = row.querySelector('[data-workout-select]');
        if (select instanceof HTMLSelectElement) {
            setWorkoutRowVisibility(row);
            select.addEventListener('change', () => {
                setWorkoutRowVisibility(row);
                enforceCompletedFromSelections(card);
                updateExtraCounter(card);
                updateExcuseVisibility(card);
            });
        }
        const customInput = row.querySelector('[data-workout-custom-input]');
        if (customInput instanceof HTMLInputElement) {
            customInput.addEventListener('input', () => {
                enforceCompletedFromSelections(card);
                updateExtraCounter(card);
                updateExcuseVisibility(card);
            });
        }
        const removeButton = row.querySelector('[data-extra-remove]');
        if (removeButton instanceof HTMLButtonElement) {
            removeButton.addEventListener('click', () => {
                row.remove();
                enforceCompletedFromSelections(card);
                updateExtraCounter(card);
                updateExcuseVisibility(card);
            });
        }
    };

    const appendExtraRow = (card, preset) => {
        const list = card.querySelector('[data-extra-list]');
        const template = card.querySelector('template[data-extra-template]');
        if (!(list instanceof HTMLElement) || !(template instanceof HTMLTemplateElement)) {
            return;
        }
        const fragment = template.content.cloneNode(true);
        const row = fragment.querySelector('[data-extra-row]');
        if (!(row instanceof HTMLElement)) {
            return;
        }

        const select = row.querySelector('[data-workout-select]');
        const customInput = row.querySelector('[data-workout-custom-input]');
        const customWrap = row.querySelector('[data-workout-custom-wrap]');

        if (select instanceof HTMLSelectElement && preset && typeof preset === 'object') {
            const presetTypeId = String(preset.workout_type_id || '').trim();
            const presetType = String(preset.workout_type || '').trim();
            if (presetTypeId !== '') {
                select.value = presetTypeId;
            } else if (presetType !== '') {
                select.value = '__custom__';
            }
            if (customInput instanceof HTMLInputElement && presetType !== '' && select.value === '__custom__') {
                customInput.value = presetType;
            }
            if (customWrap instanceof HTMLElement) {
                customWrap.hidden = select.value !== '__custom__';
            }
        }

        list.appendChild(row);
        setupExtraRow(card, row);
    };

    const addHabitToCard = (card, habit, checked) => {
        const list = card.querySelector('[data-habits-list]');
        if (!(list instanceof HTMLElement)) {
            return;
        }
        const code = String(habit.code || '').trim();
        const label = String(habit.label || '').trim();
        if (!isFilled(code) || !isFilled(label)) {
            return;
        }

        const existing = list.querySelector('[data-habit-code="' + code + '"]');
        if (existing instanceof HTMLInputElement) {
            if (checked) {
                existing.checked = true;
            }
            return;
        }

        const wrapper = document.createElement('label');
        wrapper.className = 'check';

        const input = document.createElement('input');
        input.type = 'checkbox';
        input.name = 'habit_' + code;
        input.value = '1';
        input.dataset.habitCode = code;
        input.checked = Boolean(checked);

        wrapper.appendChild(input);
        wrapper.appendChild(document.createTextNode(' ' + label));
        list.appendChild(wrapper);
    };

    // Keep the "existing custom habits" list in sync after a creation, so the very
    // next time the panel opens the new habit is already listed.
    const addCustomHabitRow = (card, habit) => {
        const wrap = card.querySelector('[data-custom-habit-existing]');
        if (!(wrap instanceof HTMLElement)) {
            return;
        }
        if (wrap.querySelector('[data-custom-habit-code="' + habit.code + '"]')) {
            return;
        }

        let list = wrap.querySelector('[data-custom-habit-list]');
        if (!list) {
            const empty = wrap.querySelector('[data-custom-habit-empty]');
            if (empty) {
                empty.remove();
            }
            list = document.createElement('ul');
            list.className = 'custom-habit-list';
            list.setAttribute('data-custom-habit-list', '');
            wrap.appendChild(list);
        }

        const row = document.createElement('li');
        row.className = 'custom-habit-row';
        row.setAttribute('data-custom-habit-code', habit.code);
        row.innerHTML = '<button type="button" class="custom-habit-pick" data-custom-habit-pick="'
            + habit.code + '"><span></span></button>';
        row.querySelector('span').textContent = habit.label;
        list.appendChild(row);
    };

    const setupCustomHabitForm = (card, allCards) => {
        const toggle = card.querySelector('[data-custom-habit-toggle]');
        const panel = card.querySelector('[data-custom-habit-panel]');
        const form = card.querySelector('[data-custom-habit-form]');
        if (!(toggle instanceof HTMLButtonElement) || !(panel instanceof HTMLElement) || !(form instanceof HTMLElement)) {
            return;
        }

        const input = form.querySelector('[data-custom-habit-input]');
        const saveButton = form.querySelector('[data-custom-habit-save]');
        const cancelButton = form.querySelector('[data-custom-habit-cancel]');
        const createTrigger = card.querySelector('[data-custom-habit-create]');
        const status = card.querySelector('[data-custom-habit-status]');
        const existingWrap = card.querySelector('[data-custom-habit-existing]');

        const setStatus = (message, className) => {
            if (!(status instanceof HTMLElement)) {
                return;
            }
            status.textContent = message;
            status.className = 'muted small ' + className;
        };

        // The panel opens on the list of habits you already have. Creating is a
        // deliberate second step, so you can no longer make a duplicate habit
        // without first seeing that it exists.
        toggle.addEventListener('click', () => {
            panel.hidden = !panel.hidden;
            if (!panel.hidden) {
                form.hidden = true;
                setStatus('', '');
            }
        });

        createTrigger?.addEventListener('click', () => {
            form.hidden = false;
            if (existingWrap instanceof HTMLElement) {
                existingWrap.classList.add('is-collapsed');
            }
            if (input instanceof HTMLInputElement) {
                input.focus();
            }
        });

        // Picking an existing custom habit just ticks it for this day.
        card.querySelectorAll('[data-custom-habit-pick]').forEach((pick) => {
            pick.addEventListener('click', () => {
                const code = String(pick.getAttribute('data-custom-habit-pick') || '');
                const box = card.querySelector('[data-habit-code="' + code + '"]');
                if (box instanceof HTMLInputElement) {
                    box.checked = !box.checked;
                    pick.classList.toggle('is-active', box.checked);
                    box.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
        });

        card.querySelectorAll('[data-custom-habit-remove]').forEach((removeBtn) => {
            removeBtn.addEventListener('click', async () => {
                const code = String(removeBtn.getAttribute('data-custom-habit-remove') || '');
                if (code === '' || !(removeBtn instanceof HTMLButtonElement)) {
                    return;
                }
                removeBtn.disabled = true;
                setStatus(labels.customHabitSaving, 'save-status saving');
                try {
                    const response = await fetch('/?page=api_delete_habit', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: JSON.stringify({ csrf_token: csrf, code: code }),
                    });
                    const json = await response.json();
                    if (!response.ok || !json.ok) {
                        setStatus(json.message || labels.customHabitError, 'save-status error');
                        removeBtn.disabled = false;
                        return;
                    }
                    // Drop it from every day card at once, so the sheet stays consistent.
                    allCards.forEach((targetCard) => {
                        targetCard.querySelectorAll('[data-custom-habit-code="' + code + '"]').forEach((row) => row.remove());
                        const box = targetCard.querySelector('[data-habit-code="' + code + '"]');
                        if (box instanceof HTMLInputElement && box.parentElement) {
                            box.parentElement.remove();
                        }
                    });
                    setStatus('', '');
                } catch (error) {
                    setStatus(labels.customHabitError, 'save-status error');
                    removeBtn.disabled = false;
                }
            });
        });

        cancelButton?.addEventListener('click', () => {
            form.hidden = true;
            if (existingWrap instanceof HTMLElement) {
                existingWrap.classList.remove('is-collapsed');
            }
            if (input instanceof HTMLInputElement) {
                input.value = '';
            }
            setStatus('', '');
        });

        saveButton?.addEventListener('click', async () => {
            if (!(input instanceof HTMLInputElement) || !(saveButton instanceof HTMLButtonElement)) {
                return;
            }

            const label = String(input.value || '').trim();
            if (!isFilled(label)) {
                setStatus(labels.customHabitRequired, 'save-status error');
                return;
            }

            saveButton.disabled = true;
            setStatus(labels.customHabitSaving, 'save-status saving');

            try {
                const response = await fetch('/?page=api_create_habit', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        csrf_token: csrf,
                        label: label,
                    }),
                });

                const json = await response.json();
                if (!response.ok || !json.ok || !json.habit) {
                    setStatus(json.message || labels.customHabitError, 'save-status error');
                    return;
                }

                allCards.forEach((targetCard) => {
                    addHabitToCard(targetCard, json.habit, targetCard === card);
                    addCustomHabitRow(targetCard, json.habit);
                });

                setStatus(labels.customHabitCreated, 'save-status ok');
                input.value = '';
                form.hidden = true;
                if (existingWrap instanceof HTMLElement) {
                    existingWrap.classList.remove('is-collapsed');
                }
            } catch (error) {
                setStatus(labels.customHabitError, 'save-status error');
            } finally {
                saveButton.disabled = false;
            }
        });
    };

    const cards = [...grid.querySelectorAll('.week-day-card')];

    cards.forEach((card) => {
        const primarySelect = card.querySelector('[data-primary-workout-select]');
        const primaryCustomWrap = card.querySelector('[data-primary-workout-custom-wrap]');
        const primaryCustomInput = card.querySelector('[data-primary-workout-custom]');
        const primaryReset = card.querySelector('[data-primary-workout-reset]');
        const workoutDoneInput = card.querySelector('[data-workout-done]');

        const updatePrimaryVisibility = () => {
            if (!(primarySelect instanceof HTMLSelectElement) || !(primaryCustomWrap instanceof HTMLElement)) {
                return;
            }
            const isCustom = primarySelect.value === '__custom__';
            primarySelect.hidden = isCustom;
            primaryCustomWrap.hidden = !isCustom;
            if (!isCustom && primaryCustomInput instanceof HTMLInputElement) {
                primaryCustomInput.value = '';
            }
        };

        if (primarySelect instanceof HTMLSelectElement) {
            primarySelect.addEventListener('change', () => {
                updatePrimaryVisibility();
                enforceCompletedFromSelections(card);
                updateExcuseVisibility(card);
            });
            updatePrimaryVisibility();
        }

        if (primaryCustomInput instanceof HTMLInputElement) {
            primaryCustomInput.addEventListener('input', () => {
                enforceCompletedFromSelections(card);
                updateExcuseVisibility(card);
            });
        }

        if (primaryReset instanceof HTMLButtonElement) {
            primaryReset.addEventListener('click', () => {
                if (primarySelect instanceof HTMLSelectElement) {
                    primarySelect.value = '';
                }
                if (primaryCustomInput instanceof HTMLInputElement) {
                    primaryCustomInput.value = '';
                }
                updatePrimaryVisibility();
                updateExcuseVisibility(card);
            });
        }

        if (workoutDoneInput instanceof HTMLInputElement) {
            workoutDoneInput.addEventListener('change', () => {
                if (!workoutDoneInput.checked) {
                    clearPrimaryWorkout(card);
                }
                enforceCompletedFromSelections(card);
                updateExcuseVisibility(card);
            });
        }

        const stepsInput = card.querySelector('[data-steps-input]');
        if (stepsInput instanceof HTMLInputElement) {
            stepsInput.addEventListener('input', () => updateExcuseVisibility(card));
            stepsInput.addEventListener('change', () => updateExcuseVisibility(card));
        }
        const distanceInput = card.querySelector('[name="distance_km"]');
        if (distanceInput instanceof HTMLInputElement) {
            distanceInput.addEventListener('input', () => updateExcuseVisibility(card));
            distanceInput.addEventListener('change', () => updateExcuseVisibility(card));
        }

        getExtraWorkoutRows(card).forEach((row) => setupExtraRow(card, row));

        const extraToggle = card.querySelector('[data-extra-toggle]');
        const extraPanel = card.querySelector('[data-extra-panel]');
        // Keep rows compact: never leave a panel expanded inline on load.
        if (extraPanel instanceof HTMLElement && !extraPanel.hidden) {
            extraPanel.hidden = true;
        }
        if (extraToggle instanceof HTMLButtonElement && extraPanel instanceof HTMLElement) {
            extraToggle.addEventListener('click', () => {
                if (floatingPanel === extraPanel) {
                    closeFloatingPanel();
                } else {
                    if (getExtraWorkoutRows(card).length === 0) {
                        appendExtraRow(card, null);
                    }
                    openFloatingPanel(extraToggle, extraPanel);
                }
                enforceCompletedFromSelections(card);
                updateExtraCounter(card);
                updateExcuseVisibility(card);
            });
        }

        const extraAdd = card.querySelector('[data-extra-add]');
        if (extraAdd instanceof HTMLButtonElement) {
            extraAdd.addEventListener('click', () => {
                appendExtraRow(card, null);
                const extraPanelNode = card.querySelector('[data-extra-panel]');
                if (extraPanelNode instanceof HTMLElement && extraToggle instanceof HTMLButtonElement) {
                    if (floatingPanel !== extraPanelNode) {
                        openFloatingPanel(extraToggle, extraPanelNode);
                    } else {
                        positionFloatingPanel();
                    }
                }
                enforceCompletedFromSelections(card);
                updateExtraCounter(card);
                updateExcuseVisibility(card);
            });
        }
        const requestSendButton = card.querySelector('[data-request-send]');
        if (requestSendButton instanceof HTMLButtonElement) {
            requestSendButton.addEventListener('click', async () => {
                const ok = await saveRow(card, { forceResend: false });
                if (ok) {
                    setRequestState(card, hasVisibleRequestReason(card) ? 'sent' : 'not_sent');
                    updateExcuseVisibility(card);
                }
            });
        }
        const requestResendButton = card.querySelector('[data-request-resend]');
        if (requestResendButton instanceof HTMLButtonElement) {
            requestResendButton.addEventListener('click', async () => {
                const ok = await saveRow(card, { forceResend: true });
                if (ok) {
                    setRequestState(card, hasVisibleRequestReason(card) ? 'resent' : 'not_sent');
                    updateExcuseVisibility(card);
                }
            });
        }

        setupCustomHabitForm(card, cards);

        setRequestState(card, String(card.dataset.requestState || 'not_sent'));
        enforceCompletedFromSelections(card);
        updateExtraCounter(card);
        updateExcuseVisibility(card);
    });

    async function saveRow(row, options = {}) {
        const workouts = collectWorkouts(row);
        const extraWorkouts = getExtraWorkouts(row);
        const primaryWorkout = getPrimaryWorkout(row);
        const workoutDoneInput = row.querySelector('[data-workout-done]');
        const forceResend = Boolean(options && options.forceResend === true);

        const data = {
            csrf_token: csrf,
            user_id: userId,
            log_date: row.dataset.date,
            log_time: row.querySelector('[name="log_time"]').value,
            steps: row.querySelector('[name="steps"]').value,
            distance_km: row.querySelector('[name="distance_km"]').value,
            training_calories_burned: row.querySelector('[name="training_calories_burned"]').value,
            workout_done: (workoutDoneInput instanceof HTMLInputElement && workoutDoneInput.checked) || workouts.length > 0 ? 1 : 0,
            junk_food: row.querySelector('[name="junk_food"]') instanceof HTMLInputElement ? (row.querySelector('[name="junk_food"]').checked ? 1 : 0) : 0,
            extra_workout: extraWorkouts.length > 0 ? 1 : 0,
            weight: row.querySelector('[name="weight"]').value,
            workout_type_id: primaryWorkout ? (primaryWorkout.workout_type_id || '') : '',
            workout_type: primaryWorkout ? (primaryWorkout.workout_type || '') : '',
            workouts: workouts,
            step_exception_reason: row.querySelector('[name="step_exception_reason"]').value,
            distance_exception_reason: row.querySelector('[name="distance_exception_reason"]').value,
            workout_exception_reason: row.querySelector('[name="workout_exception_reason"]').value,
            resend_requests: forceResend ? 1 : 0,
            habits: {},
            notes: row.querySelector('[name="notes"]').value,
        };

        row.querySelectorAll('[data-habit-code]').forEach((input) => {
            if (!(input instanceof HTMLInputElement)) {
                return;
            }
            data.habits[input.dataset.habitCode] = input.checked ? 1 : 0;
        });

        const status = row.querySelector('.save-status');
        const button = row.querySelector('.js-save-row');
        if (!(status instanceof HTMLElement) || !(button instanceof HTMLButtonElement)) {
            return false;
        }

        button.disabled = true;
        status.textContent = labels.saving;
        status.className = 'save-status saving';

        try {
            const response = await fetch('/?page=api_save_row', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify(data),
            });

            const json = await response.json();
            if (!response.ok || !json.ok) {
                status.textContent = json.message || labels.error;
                status.className = 'save-status error';
                return false;
            }

            status.textContent = labels.saved;
            status.className = 'save-status ok';
            return true;
        } catch (error) {
            status.textContent = labels.error;
            status.className = 'save-status error';
            return false;
        } finally {
            button.disabled = false;
        }
    }

    grid.querySelectorAll('.js-save-row').forEach((button) => {
        button.addEventListener('click', async () => {
            const row = button.closest('.week-day-card');
            if (!(row instanceof HTMLElement)) {
                return;
            }
            await saveRow(row);
        });
    });

    const saveAll = document.getElementById('save-all-rows');
    const saveAllStatus = document.getElementById('save-all-status');
    saveAll?.addEventListener('click', async (event) => {
        const button = event.currentTarget;
        if (!(button instanceof HTMLButtonElement) || !(saveAllStatus instanceof HTMLElement)) {
            return;
        }

        const rows = [...grid.querySelectorAll('.week-day-card')];
        let successCount = 0;
        let errorCount = 0;

        button.disabled = true;
        saveAllStatus.textContent = labels.savingWeek + ' 0/' + rows.length;
        saveAllStatus.className = 'save-all-status saving';

        for (let i = 0; i < rows.length; i++) {
            const ok = await saveRow(rows[i]);
            if (ok) {
                successCount++;
            } else {
                errorCount++;
            }
            saveAllStatus.textContent = labels.savingWeek + ' ' + (i + 1) + '/' + rows.length;
        }

        button.disabled = false;
        if (errorCount > 0) {
            saveAllStatus.textContent = labels.savedWeekWithErrors + ' (' + successCount + '/' + rows.length + ')';
            saveAllStatus.className = 'save-all-status error';
        } else {
            saveAllStatus.textContent = labels.savedWeek + ' (' + successCount + '/' + rows.length + ')';
            saveAllStatus.className = 'save-all-status ok';
        }
    });
})();
</script>
