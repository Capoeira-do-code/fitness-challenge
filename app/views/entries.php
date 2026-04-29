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
$entryWorkouts = is_array($log['workouts'] ?? null) ? array_values((array) $log['workouts']) : [];
$logTimeValue = normalize_log_time($log['log_time'] ?? '', '00:00');
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
    ];
}
$missingReasonValue = trim((string) ($log['step_exception_reason'] ?? ''));
if ($missingReasonValue === '') {
    $missingReasonValue = trim((string) ($log['workout_exception_reason'] ?? ''));
}
$calendarSelectedPhotos = [];
if ($entryMode === 'calendar') {
    $selectedDayData = is_array($mealCalendar[$selectedDate] ?? null) ? (array) $mealCalendar[$selectedDate] : [];
    $calendarSelectedPhotos = is_array($selectedDayData['photos'] ?? null) ? array_values((array) $selectedDayData['photos']) : [];
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
?>
<section class="screen stack-lg">
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

    <?php if ($entryMode === 'data'): ?>
        <article class="panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow"><?= e(t('entries.day_data')) ?></p>
                    <h2><?= e(t('entries.day_data')) ?></h2>
                </div>
                <label class="entry-date-inline">
                    <?= e(t('common.date')) ?>
                    <input id="entry-date" type="date" name="log_date" value="<?= e($selectedDate) ?>" onchange="window.location='/?page=entries&mode=data&date='+this.value;" data-testid="entry-date">
                </label>
            </div>

            <form method="post" action="/?page=entries" class="stack" data-testid="entry-form" data-primary-goal-type="<?= e((string) ($currentUser['primary_goal_type'] ?? 'steps')) ?>" data-primary-goal-value="<?= e((string) ($currentUser['primary_goal_value'] ?? 0)) ?>" data-step-goal="<?= e((string) ($currentUser['step_goal'] ?? 0)) ?>" data-km-goal="<?= e((string) ($currentUser['primary_goal_value'] ?? 0)) ?>" data-primary-goals="<?= e($entryPrimaryGoalsJson) ?>" data-label-steps="<?= e(t('metric.steps')) ?>" data-label-km="<?= e(t('metric.distance_km')) ?>" data-label-workouts="<?= e(t('metric.workouts')) ?>" data-missing-label="<?= e(t('entries.missing_reason')) ?>" data-missing-prefix="<?= e(t('entries.missing_reason_for')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="save_log">
                <input type="hidden" name="log_date" value="<?= e($selectedDate) ?>">
                <label>
                    <?= e(t('entries.log_time')) ?>
                    <input type="time" name="log_time" value="<?= e($logTimeValue) ?>">
                </label>

                <div class="grid-inline entries-two-col">
                    <label>
                        <?= e(t('metric.steps')) ?>
                        <input type="number" min="0" name="steps" value="<?= e((string) ($log['steps'] ?? 0)) ?>" data-testid="entry-steps">
                    </label>
                    <label>
                        <?= e(t('metric.distance_km')) ?>
                        <input type="number" min="0" step="0.01" name="distance_km" value="<?= e((string) ($log['distance_km'] ?? '')) ?>">
                    </label>
                </div>

                <div class="quick-stats entries-two-col">
                    <label>
                        <?= e(t('entries.training_calories_burned')) ?>
                        <input type="number" min="0" step="1" name="training_calories_burned" value="<?= e((string) ($log['training_calories_burned'] ?? '')) ?>">
                    </label>
                    <label>
                        <?= e(t('metric.weight')) ?> (kg)
                        <input type="number" step="0.1" name="weight" value="<?= e((string) ($log['weight'] ?? '')) ?>">
                    </label>
                </div>

                <div class="workout-repeater" data-workout-repeater>
                    <div class="panel-head workout-head">
                        <div>
                            <h3><?= e(t('entries.workout_type')) ?></h3>
                            <p class="muted small"><?= e(t('entries.workout_repeater_hint')) ?></p>
                        </div>
                        <button type="button" class="btn btn-ghost small" data-workout-add><?= e(t('entries.add_workout')) ?></button>
                    </div>

                    <div class="workout-rows" data-workout-rows>
                        <?php foreach ($entryWorkouts as $workoutRow): ?>
                            <?php
                            $rowTypeId = !empty($workoutRow['workout_type_id']) ? (int) $workoutRow['workout_type_id'] : null;
                            $rowTypeName = trim((string) ($workoutRow['workout_type'] ?? ''));
                            $isKnownType = $rowTypeId !== null && isset($workoutTypeById[$rowTypeId]);
                            $selectValue = $isKnownType ? (string) $rowTypeId : ($rowTypeName !== '' ? '__custom__' : '');
                            $customValue = $isKnownType ? '' : $rowTypeName;
                            ?>
                            <div class="workout-row" data-workout-row>
                                <label>
                                    <?= e(t('entries.workout_type')) ?>
                                    <select name="workout_type_id[]" data-workout-select>
                                        <option value=""><?= e(t('common.none')) ?></option>
                                        <?php foreach (($workoutTypes ?? []) as $type): ?>
                                            <option value="<?= (int) $type['id'] ?>" <?= $selectValue === (string) ((int) $type['id']) ? 'selected' : '' ?>><?= e((string) $type['name']) ?></option>
                                        <?php endforeach; ?>
                                        <option value="__custom__" <?= $selectValue === '__custom__' ? 'selected' : '' ?>><?= e(t('entries.workout_other')) ?></option>
                                    </select>
                                </label>
                                <label class="workout-custom-field" data-workout-custom <?= $selectValue === '__custom__' ? '' : 'hidden' ?>>
                                    <?= e(t('entries.custom_workout_type')) ?>
                                    <input type="text" name="workout_type[]" placeholder="<?= e(t('entries.workout_type_placeholder')) ?>" value="<?= e($customValue) ?>" data-workout-custom-input>
                                </label>
                                <button type="button" class="btn btn-ghost small" data-workout-remove><?= e(t('entries.remove_workout')) ?></button>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <template data-workout-template>
                        <div class="workout-row" data-workout-row>
                            <label>
                                <?= e(t('entries.workout_type')) ?>
                                <select name="workout_type_id[]" data-workout-select>
                                    <option value=""><?= e(t('common.none')) ?></option>
                                    <?php foreach (($workoutTypes ?? []) as $type): ?>
                                        <option value="<?= (int) $type['id'] ?>"><?= e((string) $type['name']) ?></option>
                                    <?php endforeach; ?>
                                    <option value="__custom__"><?= e(t('entries.workout_other')) ?></option>
                                </select>
                            </label>
                            <label class="workout-custom-field" data-workout-custom hidden>
                                <?= e(t('entries.custom_workout_type')) ?>
                                <input type="text" name="workout_type[]" placeholder="<?= e(t('entries.workout_type_placeholder')) ?>" data-workout-custom-input>
                            </label>
                            <button type="button" class="btn btn-ghost small" data-workout-remove><?= e(t('entries.remove_workout')) ?></button>
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
                                <img src="<?= e($photoUrl) ?>" alt="<?= e(t('common.photo')) ?>">
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
        <article class="panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow"><?= e(t('nav.entries')) ?></p>
                    <h2><?= e(t('entries.calendar_title')) ?></h2>
                </div>
                <a
                    class="btn btn-ghost"
                    href="/?page=entries&mode=meal&date=<?= e($selectedDate) ?>"
                    onclick="if (window.history.length > 1) { event.preventDefault(); window.history.back(); }"
                >← <?= e(t('common.back')) ?></a>
            </div>
            <form method="get" action="/" class="control-strip entries-calendar-controls">
                <input type="hidden" name="page" value="entries">
                <input type="hidden" name="mode" value="calendar">
                <label class="entry-date-inline">
                    <?= e(t('common.date')) ?>
                    <input type="date" name="date" value="<?= e($selectedDate) ?>" onchange="this.form.submit()">
                </label>
                <label>
                    <?= e(t('calendar.view_mode')) ?>
                    <select name="calendar_view" onchange="this.form.submit()">
                        <option value="month" <?= $calendarView === 'month' ? 'selected' : '' ?>><?= e(t('calendar.view_month')) ?></option>
                        <option value="week" <?= $calendarView === 'week' ? 'selected' : '' ?>><?= e(t('calendar.view_week')) ?></option>
                        <option value="day" <?= $calendarView === 'day' ? 'selected' : '' ?>><?= e(t('calendar.view_day')) ?></option>
                    </select>
                </label>
            </form>
            <div class="meal-calendar<?= $calendarView === 'month' ? ' meal-calendar-month' : '' ?> entries-calendar">
                <?php foreach (($mealCalendar ?? []) as $dateKey => $day): ?>
                    <?php
                    $photoCount = (int) ($day['count'] ?? 0);
                    $hasLog = $photoCount > 0;
                    $preview = $day['preview'] ?? null;
                    $previewUrl = is_array($preview) ? media_url((string) ($preview['file_path'] ?? '')) : '';
                    $previewPhotoId = (int) (($preview['id'] ?? 0));
                    $calendarDayUrl = $previewPhotoId > 0
                        ? '/?page=photo&photo_id=' . $previewPhotoId
                        : '/?page=entries&mode=meal&date=' . rawurlencode((string) $dateKey);
                    ?>
                    <a class="entries-calendar-day<?= $hasLog ? ' has-log' : '' ?>" href="<?= e($calendarDayUrl) ?>">
                        <article>
                            <strong><?= e(format_date_eu((string) $dateKey)) ?></strong>
                            <?php if ($previewUrl !== ''): ?>
                                <img src="<?= e($previewUrl) ?>" alt="<?= e(t('common.photo')) ?>">
                            <?php else: ?>
                                <div class="entries-calendar-empty"><?= e(t('entries.no_photo')) ?></div>
                            <?php endif; ?>
                            <span class="badge"><?= $photoCount ?> <?= e($photoCount === 1 ? t('entries.photo_singular') : t('entries.photo_plural')) ?></span>
                        </article>
                    </a>
                <?php endforeach; ?>
            </div>
        </article>

        <article class="panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow"><?= e(t('common.date')) ?> · <?= e(format_date_eu((string) $selectedDate)) ?></p>
                    <h2><?= e(t('entries.recent_photos')) ?></h2>
                </div>
            </div>
            <?php if ($calendarSelectedPhotos === []): ?>
                <p class="muted"><?= e(t('entries.no_photos')) ?></p>
            <?php else: ?>
                <div class="photo-grid">
                    <?php foreach ($calendarSelectedPhotos as $photo): ?>
                        <?php
                        $photoId = (int) ($photo['id'] ?? 0);
                        $calendarPhotoCategory = (string) ($photo['category'] ?? 'other');
                        $calendarPhotoUrl = media_url((string) ($photo['file_path'] ?? ''));
                        $nutritionLine = $nutritionSummary($photo);
                        ?>
                        <figure class="photo-card">
                            <a class="photo-card-media" href="/?page=photo&photo_id=<?= $photoId ?>">
                                <?php if ($calendarPhotoUrl !== ''): ?>
                                    <img src="<?= e($calendarPhotoUrl) ?>" alt="<?= e(t('common.photo')) ?>">
                                <?php else: ?>
                                    <div class="entries-calendar-empty"><?= e(t('entries.no_photo')) ?></div>
                                <?php endif; ?>
                            </a>
                            <figcaption>
                                <strong><?= e((string) ($photo['display_name'] ?? '')) ?></strong>
                                <span><?= e(format_date_eu((string) ($photo['log_date'] ?? $selectedDate))) ?> · <?= e($categoryLabels[$calendarPhotoCategory] ?? $calendarPhotoCategory) ?></span>
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
    <?php endif; ?>
</section>
