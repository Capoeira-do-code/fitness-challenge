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
$canDeletePhoto = static function (array $photo, array $viewer): bool {
    return is_admin($viewer) || (int) ($photo['user_id'] ?? 0) === (int) ($viewer['id'] ?? 0);
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

            <form method="post" action="/?page=entries" class="stack" data-testid="entry-form" data-primary-goal-type="<?= e((string) ($currentUser['primary_goal_type'] ?? 'steps')) ?>" data-step-goal="<?= e((string) ($currentUser['step_goal'] ?? 0)) ?>" data-km-goal="<?= e((string) ($currentUser['primary_goal_value'] ?? 0)) ?>" data-primary-goals="<?= e($entryPrimaryGoalsJson) ?>">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="save_log">
                <input type="hidden" name="log_date" value="<?= e($selectedDate) ?>">

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
                    <label>
                        <?= e(t('entries.workout_type')) ?>
                        <select name="workout_type_id" onchange="document.getElementById('custom-workout-type').value = this.options[this.selectedIndex].dataset.name || '';">
                            <option value=""><?= e(t('common.none')) ?></option>
                            <?php foreach (($workoutTypes ?? []) as $type): ?>
                                <option value="<?= (int) $type['id'] ?>" data-name="<?= e((string) $type['name']) ?>" <?= (int) ($log['workout_type_id'] ?? 0) === (int) $type['id'] ? 'selected' : '' ?>><?= e((string) $type['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <?= e(t('entries.custom_workout_type')) ?>
                        <input id="custom-workout-type" type="text" name="workout_type" placeholder="<?= e(t('entries.workout_type_placeholder')) ?>" value="<?= e((string) ($log['workout_type'] ?? '')) ?>">
                    </label>
                </div>

                <div class="toggle-row pill-toggles entries-toggles">
                    <label class="check">
                        <input type="checkbox" name="workout_done" value="1" <?= !empty($log) && (int) $log['workout_done'] === 1 ? 'checked' : '' ?> data-testid="entry-workout-done">
                        <?= e(t('entries.workout_done')) ?>
                    </label>
                    <label class="check">
                        <input type="checkbox" name="junk_food" value="1" <?= !empty($log) && (int) $log['junk_food'] === 1 ? 'checked' : '' ?> data-testid="entry-junk-food">
                        <?= e(t('entries.junk_food')) ?>
                    </label>
                    <label class="check">
                        <input type="checkbox" name="extra_workout" value="1" <?= !empty($log) && (int) ($log['extra_workout'] ?? 0) === 1 ? 'checked' : '' ?> data-testid="entry-extra-workout">
                        <?= e(t('entries.extra_workout')) ?>
                    </label>
                    <?php foreach (($habits ?? []) as $habit): ?>
                        <?php $code = (string) $habit['code']; ?>
                        <label class="check">
                            <input type="checkbox" name="habit[<?= e($code) ?>]" value="1" <?= !empty($log['habits'][$code]) && (int) $log['habits'][$code]['value'] === 1 ? 'checked' : '' ?>>
                            <?= e((string) $habit['label']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div class="grid-inline entries-two-col">
                    <label class="conditional-reason" data-reason="steps">
                        <?= e(t('entries.step_exception')) ?>
                        <input type="text" name="step_exception_reason" placeholder="<?= e(t('entries.step_exception_placeholder')) ?>" value="<?= e((string) ($log['step_exception_reason'] ?? '')) ?>" data-testid="entry-step-exception">
                    </label>

                    <label class="conditional-reason" data-reason="workout">
                        <?= e(t('entries.workout_exception')) ?>
                        <input type="text" name="workout_exception_reason" placeholder="<?= e(t('entries.workout_exception_placeholder')) ?>" value="<?= e((string) ($log['workout_exception_reason'] ?? '')) ?>" data-testid="entry-workout-exception">
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
    <div class="grid-two">
        <article class="panel">
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
                    <div class="stack proof-photo-fields">
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

                        <label>
                            <?= e(t('entries.camera_hint')) ?>
                            <input type="file" name="photo" accept="image/*" capture="environment" required data-proof-photo-input>
                        </label>

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
                    </div>

                    <div
                        class="proof-photo-preview"
                        data-proof-photo-preview
                        data-placeholder-title="<?= e(t('entries.photo_preview_placeholder')) ?>"
                        data-placeholder-hint="<?= e(t('entries.photo_preview_hint')) ?>"
                        data-preview-alt="<?= e(t('common.photo')) ?>"
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

                <button type="submit" class="btn btn-secondary btn-block"><?= e(t('entries.upload')) ?></button>
            </form>

            <p class="muted small"><?= e(t('entries.server_hint')) ?></p>
        </article>
    </div>

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
                    $photoDeleteFormId = 'photo-delete-form-meal-' . $photoId;
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
                        <?php if ($canDeletePhoto($photo, $currentUser)): ?>
                            <button
                                type="button"
                                class="photo-delete-btn"
                                data-photo-delete-trigger
                                data-photo-delete-form="<?= e($photoDeleteFormId) ?>"
                                data-photo-delete-message="<?= e(t('entries.delete_photo_confirm')) ?>"
                                aria-label="<?= e(t('common.delete')) ?>"
                            >×</button>
                            <form id="<?= e($photoDeleteFormId) ?>" method="post" action="/?page=entries" hidden>
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete_photo">
                                <input type="hidden" name="photo_id" value="<?= $photoId ?>">
                                <input type="hidden" name="redirect_mode" value="meal">
                                <input type="hidden" name="redirect_date" value="<?= e((string) $selectedDate) ?>">
                            </form>
                        <?php endif; ?>
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
                    $hasLog = !empty($loggedDays[$dateKey]);
                    $photoCount = (int) ($day['count'] ?? 0);
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
                        $photoDeleteFormId = 'photo-delete-form-calendar-' . $photoId;
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
                            <?php if ($canDeletePhoto($photo, $currentUser)): ?>
                                <button
                                    type="button"
                                    class="photo-delete-btn"
                                    data-photo-delete-trigger
                                    data-photo-delete-form="<?= e($photoDeleteFormId) ?>"
                                    data-photo-delete-message="<?= e(t('entries.delete_photo_confirm')) ?>"
                                    aria-label="<?= e(t('common.delete')) ?>"
                                >×</button>
                                <form id="<?= e($photoDeleteFormId) ?>" method="post" action="/?page=entries" hidden>
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="delete_photo">
                                    <input type="hidden" name="photo_id" value="<?= $photoId ?>">
                                    <input type="hidden" name="redirect_mode" value="calendar">
                                    <input type="hidden" name="redirect_date" value="<?= e((string) $selectedDate) ?>">
                                    <input type="hidden" name="redirect_calendar_view" value="<?= e((string) $calendarView) ?>">
                                </form>
                            <?php endif; ?>
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

<div class="confirm-modal" hidden aria-hidden="true" data-photo-delete-modal>
    <div class="confirm-modal-backdrop" data-photo-delete-cancel></div>
    <div class="confirm-modal-card" role="dialog" aria-modal="true" aria-labelledby="photo-delete-title">
        <h3 id="photo-delete-title"><?= e(t('entries.delete_photo_confirm')) ?></h3>
        <div class="confirm-modal-actions">
            <button type="button" class="btn btn-ghost" data-photo-delete-cancel><?= e(t('common.cancel')) ?></button>
            <button type="button" class="btn btn-primary" data-photo-delete-confirm><?= e(t('common.delete')) ?></button>
        </div>
    </div>
</div>
