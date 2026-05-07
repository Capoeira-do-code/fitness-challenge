<?php

declare(strict_types=1);

$log = $currentLog ?? null;
$categoryLabels = [
    'breakfast' => t('entries.breakfast'),
    'lunch' => t('entries.lunch'),
    'dinner' => t('entries.dinner'),
    'other' => t('common.other'),
    'meal' => t('entries.lunch'),
    'workout' => t('entries.workout'),
];
$entryMode = in_array(($entryMode ?? 'data'), ['data', 'meal', 'calendar'], true) ? (string) $entryMode : 'data';
$calendarView = in_array(($calendarView ?? 'month'), ['month', 'week', 'day'], true) ? (string) $calendarView : 'month';
$entryPrimaryGoals = is_array($entryPrimaryGoals ?? null) ? array_values((array) $entryPrimaryGoals) : [];
$entryPrimaryGoalsJson = json_encode($entryPrimaryGoals, JSON_UNESCAPED_SLASHES);
if (!is_string($entryPrimaryGoalsJson)) {
    $entryPrimaryGoalsJson = '[]';
}
$workoutTypeById = [];
foreach ((array) ($workoutTypes ?? []) as $type) {
    $workoutTypeById[(int) ($type['id'] ?? 0)] = (string) ($type['name'] ?? '');
}
$workoutFieldsByTypeId = [];
foreach ((array) ($workoutTypeFields ?? []) as $typeId => $fields) {
    $typeId = (int) $typeId;
    if ($typeId <= 0) {
        continue;
    }
    $workoutFieldsByTypeId[$typeId] = array_values(array_map(
        static fn(array $field): array => [
            'id' => (int) ($field['id'] ?? 0),
            'label' => (string) ($field['label'] ?? ''),
            'input_kind' => normalize_workout_field_input_kind($field['input_kind'] ?? 'number'),
            'data_key' => normalize_workout_field_data_key($field['data_key'] ?? ''),
            'required' => (int) ($field['required'] ?? 0) === 1,
        ],
        array_values((array) $fields)
    ));
}
$workoutFieldsJson = json_encode($workoutFieldsByTypeId, JSON_UNESCAPED_SLASHES);
if (!is_string($workoutFieldsJson)) {
    $workoutFieldsJson = '{}';
}
$entryWorkouts = is_array($log['workouts'] ?? null) ? array_values((array) $log['workouts']) : [];
$logTimeValue = normalize_log_time($log['log_time'] ?? '', (new DateTimeImmutable('now'))->format('H:i'));
if ($entryWorkouts === [] && !empty($log)) {
    $legacyWorkoutDone = (int) ($log['workout_done'] ?? 0) === 1;
    $legacyWorkoutTypeId = !empty($log['workout_type_id']) ? (int) $log['workout_type_id'] : null;
    $legacyWorkoutType = trim((string) ($log['workout_type'] ?? ''));
    if ($legacyWorkoutDone || $legacyWorkoutTypeId !== null || $legacyWorkoutType !== '') {
        if ($legacyWorkoutType === '' && $legacyWorkoutTypeId !== null) {
            $legacyWorkoutType = trim((string) ($workoutTypeById[$legacyWorkoutTypeId] ?? ''));
        }
        if ($legacyWorkoutType === '') {
            $legacyWorkoutType = 'Workout';
        }
        $entryWorkouts[] = [
            'workout_type_id' => $legacyWorkoutTypeId,
            'workout_type' => $legacyWorkoutType,
        ];
    }
}
if ($entryWorkouts === []) {
    $entryWorkouts[] = [
        'workout_type_id' => null,
        'workout_type' => '',
        'fields' => [],
    ];
}
$workoutEnabled = !empty($log) && ((int) ($log['workout_done'] ?? 0) === 1 || count(array_filter($entryWorkouts, static fn($row): bool => is_array($row) && (trim((string) ($row['workout_type'] ?? '')) !== '' || !empty($row['workout_type_id'])))) > 0);
$baseStepsValue = (string) ($log['base_steps'] ?? $log['steps'] ?? 0);
$baseDistanceValue = (string) ($log['base_distance_km'] ?? $log['distance_km'] ?? '');
$baseCaloriesValue = (string) ($log['base_training_calories_burned'] ?? $log['training_calories_burned'] ?? '');
$missingReasonValue = trim((string) ($log['step_exception_reason'] ?? ''));
if ($missingReasonValue === '') {
    $missingReasonValue = trim((string) ($log['workout_exception_reason'] ?? ''));
}
$nutritionSummary = static function (array $photo): string {
    $parts = [];
    $calories = $photo['calories'] ?? null;
    if ($calories !== null && $calories !== '') {
        $parts[] = rtrim(rtrim(number_format((float) $calories, 1, '.', ''), '0'), '.') . ' kcal';
    }
    $protein = $photo['protein_g'] ?? null;
    if ($protein !== null && $protein !== '') {
        $parts[] = 'P ' . rtrim(rtrim(number_format((float) $protein, 1, '.', ''), '0'), '.') . 'g';
    }
    $carbs = $photo['carbs_g'] ?? null;
    if ($carbs !== null && $carbs !== '') {
        $parts[] = 'C ' . rtrim(rtrim(number_format((float) $carbs, 1, '.', ''), '0'), '.') . 'g';
    }
    $fat = $photo['fat_g'] ?? null;
    if ($fat !== null && $fat !== '') {
        $parts[] = 'F ' . rtrim(rtrim(number_format((float) $fat, 1, '.', ''), '0'), '.') . 'g';
    }

    return implode(' · ', $parts);
};
$calendarVisibleLabel = '';
if ($entryMode === 'calendar') {
    try {
        $calendarVisibleDate = new DateTimeImmutable((string) $selectedDate);
        $calendarVisibleLabel = $calendarView === 'month'
            ? localized_month_label((string) $selectedDate)
            : ($calendarView === 'week' ? date_to_iso_week((string) $selectedDate) : format_date_eu((string) $selectedDate));
    } catch (Throwable) {
        $calendarVisibleLabel = (string) $selectedDate;
    }
}
$galleryUrl = '/?' . http_build_query([
    'page' => 'gallery',
    'user_id' => (int) ($selectedUserId ?? $currentUser['id'] ?? 0),
]);
$calendarModeUrl = '/?' . http_build_query([
    'page' => 'entries',
    'mode' => 'calendar',
    'user_id' => (int) ($selectedUserId ?? $currentUser['id'] ?? 0),
    'calendar_view' => 'month',
    'date' => $selectedDate,
]);
if ($entryMode === 'calendar') {
    ob_start();
    ?>
    <details class="topbar-context calendar-view-menu">
        <summary class="btn btn-ghost btn-topbar"><?= e(t('common.view')) ?></summary>
        <div class="topbar-context-panel calendar-view-panel">
            <form method="get" action="/" class="stack calendar-view-form" data-meal-calendar-form data-calendar-page="entries">
                <input type="hidden" name="page" value="entries">
                <input type="hidden" name="mode" value="calendar">
                <input type="hidden" name="include_photos" value="0">
                <input type="hidden" value="<?= e($selectedDate) ?>" data-meal-calendar-date>
                <div class="calendar-view-summary">
                    <span class="eyebrow"><?= e(t('nav.calendar')) ?></span>
                    <strong data-meal-calendar-visible-period><?= e($calendarVisibleLabel) ?></strong>
                    <small><?= e((string) ($selectedUser['display_name'] ?? $currentUser['display_name'] ?? '')) ?></small>
                </div>
                <label>
                    <?= e(t('dashboard.viewing')) ?>
                    <?php if (is_admin($currentUser) && count((array) ($users ?? [])) > 1): ?>
                        <select name="user_id" onchange="this.form.submit()">
                            <?php foreach ((array) $users as $user): ?>
                                <option value="<?= (int) ($user['id'] ?? 0) ?>" <?= (int) ($user['id'] ?? 0) === (int) ($selectedUserId ?? 0) ? 'selected' : '' ?>>
                                    <?= e((string) ($user['display_name'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="hidden" name="user_id" value="<?= (int) ($selectedUserId ?? $currentUser['id'] ?? 0) ?>">
                        <span class="calendar-view-static"><?= e((string) ($selectedUser['display_name'] ?? $currentUser['display_name'] ?? '')) ?></span>
                    <?php endif; ?>
                </label>
                <nav class="calendar-view-segments" aria-label="<?= e(t('gallery.photo_mode')) ?>">
                    <a href="<?= e('/?' . http_build_query(['page' => 'gallery', 'gallery_view' => 'recent', 'user_id' => (int) ($selectedUserId ?? $currentUser['id'] ?? 0)])) ?>"><?= e(t('gallery.mode_recent')) ?></a>
                    <a class="active" href="<?= e($calendarModeUrl) ?>" aria-current="page"><?= e(t('gallery.mode_calendar')) ?></a>
                </nav>
                <?php if ($calendarView === 'month'): ?>
                    <label>
                        <span data-meal-calendar-period-label><?= e(t('dashboard.month')) ?></span>
                        <input type="month" name="calendar_month" value="<?= e(substr($selectedDate, 0, 7)) ?>" onchange="this.form.submit()" data-meal-calendar-period data-label-month="<?= e(t('dashboard.month')) ?>" data-label-week="<?= e(t('common.week')) ?>" data-label-date="<?= e(t('common.date')) ?>">
                    </label>
                <?php elseif ($calendarView === 'week'): ?>
                    <label>
                        <span data-meal-calendar-period-label><?= e(t('common.week')) ?></span>
                        <input type="week" name="calendar_week" value="<?= e(date_to_iso_week($selectedDate)) ?>" onchange="this.form.submit()" data-meal-calendar-period data-label-month="<?= e(t('dashboard.month')) ?>" data-label-week="<?= e(t('common.week')) ?>" data-label-date="<?= e(t('common.date')) ?>">
                    </label>
                <?php else: ?>
                    <label>
                        <span data-meal-calendar-period-label><?= e(t('common.date')) ?></span>
                        <input type="date" name="date" value="<?= e($selectedDate) ?>" onchange="this.form.submit()" data-meal-calendar-period data-label-month="<?= e(t('dashboard.month')) ?>" data-label-week="<?= e(t('common.week')) ?>" data-label-date="<?= e(t('common.date')) ?>">
                    </label>
                <?php endif; ?>
                <input type="hidden" name="calendar_view" value="<?= e($calendarView) ?>" data-meal-calendar-view>
                <div class="calendar-view-segments" role="group" aria-label="<?= e(t('calendar.view_mode')) ?>">
                    <?php foreach (['month' => t('calendar.view_month'), 'week' => t('calendar.view_week'), 'day' => t('calendar.view_day')] as $viewKey => $viewLabel): ?>
                        <a class="<?= $calendarView === $viewKey ? 'active' : '' ?>" href="/?<?= e(http_build_query(['page' => 'entries', 'mode' => 'calendar', 'user_id' => (int) ($selectedUserId ?? $currentUser['id'] ?? 0), 'calendar_view' => $viewKey, 'date' => $selectedDate])) ?>" data-calendar-view-option="<?= e($viewKey) ?>"><?= e($viewLabel) ?></a>
                    <?php endforeach; ?>
                </div>
                <div class="calendar-view-actions">
                    <a class="btn btn-ghost btn-block" href="<?= e('/?' . http_build_query(['page' => 'gallery', 'gallery_view' => 'calendar', 'user_id' => (int) ($selectedUserId ?? $currentUser['id'] ?? 0), 'calendar_view' => $calendarView, 'date' => $selectedDate])) ?>"><?= e(t('gallery.title')) ?></a>
                    <a class="btn btn-primary btn-block" href="/?page=entries&mode=meal&date=<?= e($selectedDate) ?>"><?= e(t('entries.create_entry')) ?></a>
                </div>
            </form>
        </div>
    </details>
    <?php
    $topbarControls = ob_get_clean();
}
?>
<section class="screen stack-lg<?= $entryMode === 'calendar' ? ' entries-calendar-screen' : '' ?>">
    <?php if ($entryMode !== 'calendar'): ?>
    <div class="hero-panel">
        <div>
            <p class="eyebrow"><?= e(t('nav.entries')) ?></p>
            <h1><?= e(t('entries.title')) ?></h1>
            <p class="muted"><?= e(t('entries.subtitle')) ?></p>
        </div>
        <div class="chip-group">
            <a class="btn <?= $entryMode === 'data' ? 'btn-primary' : 'btn-ghost' ?>" href="/?page=entries&mode=data&date=<?= e($selectedDate) ?>"><?= e(t('entries.quick_data')) ?></a>
            <a class="btn <?= $entryMode === 'meal' ? 'btn-primary' : 'btn-ghost' ?>" href="/?page=entries&mode=meal&date=<?= e($selectedDate) ?>"><?= e(t('entries.quick_meal')) ?></a>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($entryMode === 'data'): ?>
        <article class="panel entry-data-panel">
            <div class="panel-head entry-data-head">
                <div>
                    <p class="eyebrow"><?= e(t('entries.day_data')) ?></p>
                    <h2><?= e(t('entries.day_data')) ?></h2>
                </div>
                <div class="entry-datetime-inline">
                    <label class="entry-date-inline">
                        <?= e(t('common.date')) ?>
                        <input id="entry-date" type="date" name="log_date" value="<?= e($selectedDate) ?>" onchange="window.location='/?page=entries&mode=data&date='+this.value;" data-testid="entry-date">
                    </label>
                    <label class="entry-time-inline">
                        <?= e(t('entries.log_time')) ?>
                        <input type="time" name="log_time" value="<?= e($logTimeValue) ?>" form="entry-data-form">
                    </label>
                </div>
            </div>

            <form id="entry-data-form" method="post" action="/?page=entries" class="stack entry-data-form" data-testid="entry-form" data-workout-fields="<?= e($workoutFieldsJson) ?>" data-primary-goal-type="<?= e((string) ($currentUser['primary_goal_type'] ?? 'steps')) ?>" data-primary-goal-value="<?= e((string) ($currentUser['primary_goal_value'] ?? 0)) ?>" data-step-goal="<?= e((string) ($currentUser['step_goal'] ?? 0)) ?>" data-km-goal="<?= e((string) ($currentUser['primary_goal_value'] ?? 0)) ?>" data-primary-goals="<?= e($entryPrimaryGoalsJson) ?>" data-label-steps="<?= e(t('metric.steps')) ?>" data-label-km="<?= e(t('metric.distance_km')) ?>" data-label-workouts="<?= e(t('metric.workouts')) ?>" data-missing-label="<?= e(t('entries.missing_reason')) ?>" data-missing-prefix="<?= e(t('entries.missing_reason_for')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="save_log">
                <input type="hidden" name="log_date" value="<?= e($selectedDate) ?>">
                <input type="hidden" name="workout_form_mode" value="1">
                <div class="entry-form-section grid-inline entries-two-col">
                    <label>
                        <?= e(t('metric.steps')) ?>
                        <input type="number" min="0" name="steps" value="<?= e($baseStepsValue) ?>" data-testid="entry-steps">
                    </label>
                    <label>
                        <?= e(t('metric.distance_km')) ?>
                        <input type="number" min="0" step="0.01" name="distance_km" value="<?= e($baseDistanceValue) ?>">
                    </label>
                </div>

                <div class="entry-form-section quick-stats entries-two-col">
                    <label>
                        <?= e(t('entries.training_calories_burned')) ?>
                        <input type="number" min="0" step="1" name="training_calories_burned" value="<?= e($baseCaloriesValue) ?>">
                    </label>
                    <label>
                        <?= e(t('metric.weight')) ?> (kg)
                        <input type="number" step="0.1" name="weight" value="<?= e((string) ($log['weight'] ?? '')) ?>">
                    </label>
                </div>

                <div class="entry-form-section workout-repeater" data-workout-repeater>
                    <div class="workout-toggle-row">
                        <label class="check entry-workout-toggle">
                            <input type="checkbox" name="workout_enabled" value="1" data-workout-enabled <?= $workoutEnabled ? 'checked' : '' ?>>
                            <?= e(t('entries.workout')) ?>
                        </label>
                        <button type="button" class="btn btn-ghost small" data-workout-add><?= e(t('entries.add_workout')) ?></button>
                    </div>
                    <div class="workout-panel" data-workout-panel <?= $workoutEnabled ? '' : 'hidden' ?>>
                        <p class="muted small"><?= e(t('entries.workout_repeater_hint')) ?></p>
                        <div class="workout-rows" data-workout-rows>
                            <?php foreach ($entryWorkouts as $rowIndex => $workoutRow): ?>
                                <?php
                                $rowTypeId = !empty($workoutRow['workout_type_id']) ? (int) $workoutRow['workout_type_id'] : null;
                                $rowTypeName = trim((string) ($workoutRow['workout_type'] ?? ''));
                                $isKnownType = $rowTypeId !== null && isset($workoutTypeById[$rowTypeId]);
                                $selectValue = $isKnownType ? (string) $rowTypeId : ($rowTypeName !== '' ? '__custom__' : '');
                                $customValue = $isKnownType ? '' : $rowTypeName;
                                $fieldValuesById = [];
                                foreach ((array) ($workoutRow['fields'] ?? []) as $fieldValue) {
                                    if (!is_array($fieldValue) || empty($fieldValue['field_id'])) {
                                        continue;
                                    }
                                    $fieldValuesById[(int) $fieldValue['field_id']] = (string) ($fieldValue['value_text'] ?? $fieldValue['value_number'] ?? '');
                                }
                                ?>
                                <div class="workout-row" data-workout-row>
                                    <label>
                                        <?= e(t('entries.workout_type')) ?>
                                        <select name="workouts[<?= (int) $rowIndex ?>][workout_type_id]" data-name-template="workouts[__INDEX__][workout_type_id]" data-workout-select>
                                            <option value=""><?= e(t('common.none')) ?></option>
                                            <?php foreach (($workoutTypes ?? []) as $type): ?>
                                                <option value="<?= (int) $type['id'] ?>" <?= $selectValue === (string) ((int) $type['id']) ? 'selected' : '' ?>><?= e((string) $type['name']) ?></option>
                                            <?php endforeach; ?>
                                            <option value="__custom__" <?= $selectValue === '__custom__' ? 'selected' : '' ?>><?= e(t('entries.workout_other')) ?></option>
                                        </select>
                                    </label>
                                    <label class="workout-custom-field" data-workout-custom <?= $selectValue === '__custom__' ? '' : 'hidden' ?>>
                                        <?= e(t('entries.custom_workout_type')) ?>
                                        <input type="text" name="workouts[<?= (int) $rowIndex ?>][workout_type]" data-name-template="workouts[__INDEX__][workout_type]" placeholder="<?= e(t('entries.workout_type_placeholder')) ?>" value="<?= e($customValue) ?>" data-workout-custom-input>
                                    </label>
                                    <div class="workout-subfields" data-workout-subfields>
                                        <?php foreach (($rowTypeId !== null ? ($workoutFieldsByTypeId[$rowTypeId] ?? []) : []) as $field): ?>
                                            <?php $fieldId = (int) ($field['id'] ?? 0); ?>
                                            <?php if ($fieldId <= 0) {
                                                continue;
                                            } ?>
                                            <label>
                                                <?= e((string) $field['label']) ?>
                                                <input
                                                    type="<?= ($field['input_kind'] ?? 'number') === 'text' ? 'text' : 'number' ?>"
                                                    <?= ($field['input_kind'] ?? 'number') === 'text' ? '' : 'step="0.01" min="0"' ?>
                                                    name="workouts[<?= (int) $rowIndex ?>][fields][<?= $fieldId ?>]"
                                                    data-name-template="workouts[__INDEX__][fields][<?= $fieldId ?>]"
                                                    data-workout-field-data-key="<?= e((string) ($field['data_key'] ?? '')) ?>"
                                                    value="<?= e((string) ($fieldValuesById[$fieldId] ?? '')) ?>"
                                                    <?= !empty($field['required']) ? 'required' : '' ?>
                                                >
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <button type="button" class="btn btn-ghost small workout-remove-btn" data-workout-remove><?= e(t('entries.remove_workout')) ?></button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <template data-workout-template>
                        <div class="workout-row" data-workout-row>
                            <label>
                                <?= e(t('entries.workout_type')) ?>
                                <select name="workouts[__INDEX__][workout_type_id]" data-name-template="workouts[__INDEX__][workout_type_id]" data-workout-select>
                                    <option value=""><?= e(t('common.none')) ?></option>
                                    <?php foreach (($workoutTypes ?? []) as $type): ?>
                                        <option value="<?= (int) $type['id'] ?>"><?= e((string) $type['name']) ?></option>
                                    <?php endforeach; ?>
                                    <option value="__custom__"><?= e(t('entries.workout_other')) ?></option>
                                </select>
                            </label>
                            <label class="workout-custom-field" data-workout-custom hidden>
                                <?= e(t('entries.custom_workout_type')) ?>
                                <input type="text" name="workouts[__INDEX__][workout_type]" data-name-template="workouts[__INDEX__][workout_type]" placeholder="<?= e(t('entries.workout_type_placeholder')) ?>" data-workout-custom-input>
                            </label>
                            <div class="workout-subfields" data-workout-subfields></div>
                            <button type="button" class="btn btn-ghost small workout-remove-btn" data-workout-remove><?= e(t('entries.remove_workout')) ?></button>
                        </div>
                    </template>
                </div>

                <div class="toggle-row pill-toggles entries-toggles">
                    <label class="check">
                        <input type="checkbox" name="junk_food" value="1" <?= !empty($log) && (int) $log['junk_food'] === 1 ? 'checked' : '' ?> data-testid="entry-junk-food">
                        <?= e(t('entries.junk_food')) ?>
                    </label>
                    <?php foreach (($habits ?? []) as $habit): ?>
                        <?php $code = (string) $habit['code']; ?>
                        <?php if ($code === 'morning_walk') {
                            continue;
                        } ?>
                        <label class="check">
                            <input type="checkbox" name="habit[<?= e($code) ?>]" value="1" <?= !empty($log['habits'][$code]) && (int) $log['habits'][$code]['value'] === 1 ? 'checked' : '' ?>>
                            <?= e((string) $habit['label']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div class="grid-inline entries-two-col">
                    <label class="conditional-reason" data-reason="missing" hidden>
                        <span data-missing-reason-label><?= e(t('entries.missing_reason')) ?></span>
                        <small class="muted" data-missing-reason-items></small>
                        <input type="text" name="missing_reason" placeholder="<?= e(t('entries.missing_reason_placeholder')) ?>" value="<?= e($missingReasonValue) ?>" data-testid="entry-missing-reason">
                    </label>
                </div>

                <label>
                    <?= e(t('common.notes')) ?>
                    <textarea name="notes" rows="3" placeholder="<?= e(t('entries.notes_placeholder')) ?>"><?= e((string) ($log['notes'] ?? '')) ?></textarea>
                </label>

                <button type="submit" class="btn btn-primary btn-block" data-testid="entry-save"><?= e(t('entries.save_data')) ?></button>
            </form>

            <p class="muted small"><?= e(t('entries.pending_hint')) ?></p>
        </article>
    <?php endif; ?>

    <?php if ($entryMode === 'meal'): ?>
    <article class="panel proof-photo-panel">
        <div class="panel-head">
            <div>
                <p class="eyebrow"><?= e(t('common.photo')) ?></p>
                <h2><?= e(t('entries.upload_photo')) ?></h2>
            </div>
        </div>

        <form method="post" action="/?page=entries" enctype="multipart/form-data" class="stack proof-photo-form" data-proof-photo-form>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="upload_photo">

            <div class="proof-photo-grid">
                <div class="stack proof-photo-main-card">
                    <div class="grid-inline entries-two-col proof-photo-meta">
                        <label>
                            <?= e(t('common.date')) ?>
                            <input type="date" name="log_date" value="<?= e($selectedDate) ?>">
                        </label>

                        <label>
                            <?= e(t('common.category')) ?>
                            <select name="category">
                                <option value="breakfast"><?= e(t('entries.breakfast')) ?></option>
                                <option value="lunch"><?= e(t('entries.lunch')) ?></option>
                                <option value="dinner"><?= e(t('entries.dinner')) ?></option>
                                <option value="other"><?= e(t('common.other')) ?></option>
                            </select>
                        </label>
                    </div>

                    <label>
                        <?= e(t('common.caption')) ?>
                        <input type="text" name="caption" placeholder="<?= e(t('entries.caption_placeholder')) ?>">
                    </label>

                    <div class="proof-photo-upload-block">
                        <label class="proof-photo-upload-label">
                            <span><?= e(t('entries.camera_hint')) ?></span>
                            <input type="file" name="photo" accept="image/*" required data-proof-photo-input>
                        </label>
                        <p class="proof-photo-upload-state muted small" data-proof-photo-state><?= e(t('entries.photo_upload_idle')) ?></p>
                        <p class="muted small"><?= e(t('entries.photo_upload_help')) ?></p>
                    </div>

                    <button type="submit" class="btn btn-secondary btn-block proof-photo-submit"><?= e(t('entries.upload')) ?></button>
                </div>

                <div class="stack proof-photo-side-card">
                    <div class="photo-nutrition-tools">
                        <button type="button" class="btn btn-ghost small" data-photo-nutrition-toggle><?= e(t('entries.add_calorie_info')) ?></button>
                    </div>
                    <div class="photo-nutrition-panel" data-photo-nutrition-panel hidden>
                        <label>
                            <?= e(t('entries.photo_calories')) ?>
                            <input type="number" min="0" step="1" name="photo_calories" placeholder="650">
                        </label>
                        <div class="photo-nutrition-tools">
                            <button type="button" class="btn btn-ghost small" data-photo-nutrition-advanced-toggle><?= e(t('entries.nutrition_advanced')) ?></button>
                        </div>
                        <div class="grid-inline entries-two-col photo-nutrition-advanced" data-photo-nutrition-advanced hidden>
                            <label>
                                <?= e(t('entries.photo_protein')) ?>
                                <input type="number" min="0" step="0.1" name="photo_protein_g" placeholder="35">
                            </label>
                            <label>
                                <?= e(t('entries.photo_carbs')) ?>
                                <input type="number" min="0" step="0.1" name="photo_carbs_g" placeholder="60">
                            </label>
                            <label>
                                <?= e(t('entries.photo_fat')) ?>
                                <input type="number" min="0" step="0.1" name="photo_fat_g" placeholder="22">
                            </label>
                            <label>
                                <?= e(t('entries.photo_fiber')) ?>
                                <input type="number" min="0" step="0.1" name="photo_fiber_g" placeholder="8">
                            </label>
                            <label>
                                <?= e(t('entries.photo_sugar')) ?>
                                <input type="number" min="0" step="0.1" name="photo_sugar_g" placeholder="12">
                            </label>
                            <label>
                                <?= e(t('entries.photo_sodium')) ?>
                                <input type="number" min="0" step="1" name="photo_sodium_mg" placeholder="700">
                            </label>
                        </div>
                    </div>

                    <div
                        class="proof-photo-preview"
                        data-proof-photo-preview
                        data-placeholder-title="<?= e(t('entries.photo_preview_placeholder')) ?>"
                        data-placeholder-hint="<?= e(t('entries.photo_preview_hint')) ?>"
                        data-preview-unsupported-title="<?= e(t('entries.photo_preview_unsupported_title')) ?>"
                        data-preview-unsupported-hint="<?= e(t('entries.photo_preview_unsupported_hint')) ?>"
                        data-preview-alt="<?= e(t('common.photo')) ?>"
                        data-state-idle="<?= e(t('entries.photo_upload_idle')) ?>"
                        data-state-selected="<?= e(t('entries.photo_upload_selected')) ?>"
                        data-state-unsupported="<?= e(t('entries.photo_upload_unsupported')) ?>"
                        data-state-error="<?= e(t('entries.photo_upload_error')) ?>"
                    >
                        <div class="photo-placeholder">
                            <div class="photo-placeholder-content">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Zm0 2v8.59l3.3-3.3a1 1 0 0 1 1.4 0L14 15.6l2.3-2.3a1 1 0 0 1 1.4 0L19 14.6V6Zm3 1.2a1.8 1.8 0 1 0 0 3.6 1.8 1.8 0 0 0 0-3.6Z"/></svg>
                                <p><?= e(t('entries.photo_preview_placeholder')) ?></p>
                                <small><?= e(t('entries.photo_preview_hint')) ?></small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>

        <p class="muted small proof-photo-server-hint"><?= e(t('entries.server_hint')) ?></p>
    </article>

    <article class="panel">
        <div class="panel-head">
            <div>
                <p class="eyebrow"><?= e(t('common.photo')) ?></p>
                <h2><?= e(t('entries.recent_photos')) ?></h2>
            </div>
        </div>
        <?php if ($recentPhotos === []): ?>
            <p class="muted"><?= e(t('entries.no_photos')) ?></p>
        <?php else: ?>
            <div class="photo-grid">
                <?php foreach ($recentPhotos as $photo): ?>
                    <?php
                    $photoId = (int) ($photo['id'] ?? 0);
                    $category = (string) ($photo['category'] ?? 'other');
                    $photoUrl = media_url((string) ($photo['file_path'] ?? ''));
                    $nutritionLine = $nutritionSummary($photo);
                    ?>
                    <figure class="photo-card">
                        <a class="photo-card-media" href="/?page=photo&photo_id=<?= $photoId ?>">
                            <?php if ($photoUrl !== ''): ?>
                                <img src="<?= e($photoUrl) ?>" alt="<?= e(t('common.photo')) ?>" loading="lazy" decoding="async">
                            <?php else: ?>
                                <div class="entries-calendar-empty"><?= e(t('entries.no_photo')) ?></div>
                            <?php endif; ?>
                        </a>
                        <figcaption>
                            <strong><?= e((string) ($photo['display_name'] ?? '')) ?></strong>
                            <span><?= e(format_date_eu((string) ($photo['log_date'] ?? ''))) ?> · <?= e((string) ($categoryLabels[$category] ?? $category)) ?></span>
                            <?php if (!empty($photo['caption'])): ?>
                                <span><?= e((string) $photo['caption']) ?></span>
                            <?php endif; ?>
                            <?php if ($nutritionLine !== ''): ?>
                                <span class="photo-nutrition-line"><?= e($nutritionLine) ?></span>
                            <?php endif; ?>
                        </figcaption>
                    </figure>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>
    <div class="panel panel-inline-empty">
        <a class="btn btn-ghost" href="/?page=entries&mode=calendar&calendar_view=month&date=<?= e($selectedDate) ?>"><?= e(t('entries.view_calendar')) ?></a>
    </div>
    <?php endif; ?>

    <?php if ($entryMode === 'calendar'): ?>
        <article class="panel entries-calendar-panel entries-calendar-focused" data-meal-calendar-root data-calendar-page="entries" data-user-id="<?= (int) ($selectedUserId ?? $currentUser['id'] ?? 0) ?>" data-include-photos="0" data-gallery-url="<?= e($galleryUrl) ?>">
            <div class="entries-calendar-titlebar">
                <p class="eyebrow"><?= e((string) ($selectedUser['display_name'] ?? $currentUser['display_name'] ?? '')) ?></p>
                <h1 data-meal-calendar-visible-period><?= e($calendarVisibleLabel) ?></h1>
            </div>
            <div class="meal-calendar meal-calendar-<?= e($calendarView) ?><?= $calendarView === 'month' ? ' meal-calendar-month' : '' ?> entries-calendar" data-meal-calendar-days>
                <?php foreach (($mealCalendar ?? []) as $dateKey => $day): ?>
                    <?php
                    $photoCount = (int) ($day['count'] ?? 0);
                    $hasLog = $photoCount > 0;
                    $preview = $day['preview'] ?? null;
                    $previewUrl = is_array($preview) ? media_thumbnail_url((string) ($preview['file_path'] ?? ''), 360) : '';
                    $previewPhotoId = (int) (($preview['id'] ?? 0));
                    $previewPhotos = [];
                    foreach (array_slice(array_values((array) ($day['photos'] ?? [])), 0, 3) as $previewPhoto) {
                        if (!is_array($previewPhoto)) {
                            continue;
                        }
                        $previewPhotoPayload = [
                            'thumb_url' => media_thumbnail_url((string) ($previewPhoto['file_path'] ?? ''), 360),
                            'photo_url' => media_url((string) ($previewPhoto['file_path'] ?? '')),
                        ];
                        if ((string) ($previewPhotoPayload['thumb_url'] ?: $previewPhotoPayload['photo_url']) !== '') {
                            $previewPhotos[] = $previewPhotoPayload;
                        }
                    }
                    try {
                        $calendarDayDate = new DateTimeImmutable((string) $dateKey);
                        $calendarDayLabel = $calendarView === 'month'
                            ? $calendarDayDate->format('j')
                            : ($calendarView === 'week' ? $calendarDayDate->format('d/m') : format_date_eu((string) $dateKey));
                    } catch (Throwable) {
                        $calendarDayLabel = (string) $dateKey;
                    }
                    $canCreateForCalendarDay = (int) ($selectedUserId ?? 0) === (int) ($currentUser['id'] ?? 0);
                    $calendarDayUrl = $previewPhotoId > 0
                        ? '/?page=photo&photo_id=' . $previewPhotoId
                        : ($canCreateForCalendarDay
                            ? '/?page=entries&mode=meal&date=' . rawurlencode((string) $dateKey)
                            : '/?' . http_build_query([
                                'page' => 'entries',
                                'mode' => 'calendar',
                                'user_id' => (int) ($selectedUserId ?? 0),
                                'calendar_view' => $calendarView,
                                'date' => (string) $dateKey,
                            ]));
                    ?>
                    <a class="entries-calendar-day<?= $hasLog ? ' has-log' : '' ?><?= (string) $dateKey === $selectedDate ? ' is-selected' : '' ?>" href="<?= e($calendarDayUrl) ?>">
                        <article>
                            <strong><?= e($calendarDayLabel) ?></strong>
                            <?php if ($previewPhotos !== []): ?>
                                <div class="entries-calendar-collage collage-count-<?= min(3, count($previewPhotos)) ?>">
                                    <?php foreach ($previewPhotos as $previewPhoto): ?>
                                        <?php $previewImageUrl = (string) ($previewPhoto['thumb_url'] ?: $previewPhoto['photo_url']); ?>
                                        <?php if ($previewImageUrl !== ''): ?>
                                            <img src="<?= e($previewImageUrl) ?>" alt="<?= e(t('common.photo')) ?>" loading="lazy" decoding="async">
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php elseif ($previewUrl !== ''): ?>
                                <div class="entries-calendar-collage collage-count-1">
                                    <img src="<?= e($previewUrl) ?>" alt="<?= e(t('common.photo')) ?>" loading="lazy" decoding="async">
                                </div>
                            <?php else: ?>
                                <div class="entries-calendar-empty"><?= e(t('entries.no_photo')) ?></div>
                            <?php endif; ?>
                            <span class="badge"><?= $photoCount ?> <?= e($photoCount === 1 ? t('entries.photo_singular') : t('entries.photo_plural')) ?></span>
                        </article>
                    </a>
                <?php endforeach; ?>
            </div>
        </article>
    <?php endif; ?>
</section>
