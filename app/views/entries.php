<?php

declare(strict_types=1);

$log = $currentLog ?? null;
$categoryLabels = [
    'meal' => t('entries.meal'),
    'dinner' => t('entries.dinner'),
    'workout' => t('entries.workout'),
    'other' => t('common.other'),
];
?>
<section class="screen stack-lg">
    <div class="hero-panel">
        <div>
            <p class="eyebrow"><?= e(t('nav.entries')) ?></p>
            <h1><?= e(t('entries.title')) ?></h1>
            <p class="muted"><?= e(t('entries.subtitle')) ?></p>
        </div>
    </div>

    <div class="grid-two">
        <article class="panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow"><?= e(format_date_eu((string) $selectedDate)) ?></p>
                    <h2><?= e(t('entries.day_data')) ?></h2>
                </div>
            </div>

            <form method="post" action="/?page=entries" class="stack" data-testid="entry-form" data-primary-goal-type="<?= e((string) ($currentUser['primary_goal_type'] ?? 'steps')) ?>" data-step-goal="<?= e((string) ($currentUser['step_goal'] ?? 0)) ?>" data-km-goal="<?= e((string) ($currentUser['primary_goal_value'] ?? 0)) ?>">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="save_log">

                <div class="grid-inline">
                    <label>
                        <?= e(t('common.date')) ?>
                        <input id="entry-date" type="date" name="log_date" value="<?= e($selectedDate) ?>" onchange="window.location='/?page=entries&date='+this.value;" data-testid="entry-date">
                    </label>
                    <label>
                        <?= e(t('metric.steps')) ?>
                        <input type="number" min="0" name="steps" value="<?= e((string) ($log['steps'] ?? 0)) ?>" data-testid="entry-steps">
                    </label>
                    <label>
                        <?= e(t('metric.distance_km')) ?>
                        <input type="number" min="0" step="0.01" name="distance_km" value="<?= e((string) ($log['distance_km'] ?? '')) ?>">
                    </label>
                </div>

                <div class="quick-stats">
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

                <div class="toggle-row pill-toggles">
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

                <div class="grid-inline">
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

        <article class="panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow"><?= e(t('common.photo')) ?></p>
                    <h2><?= e(t('entries.upload_photo')) ?></h2>
                </div>
            </div>

            <form method="post" action="/?page=entries" enctype="multipart/form-data" class="stack">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="upload_photo">

                <div class="grid-inline">
                    <label>
                        <?= e(t('common.date')) ?>
                        <input type="date" name="log_date" value="<?= e($selectedDate) ?>">
                    </label>

                    <label>
                        <?= e(t('common.category')) ?>
                        <select name="category">
                            <option value="meal"><?= e(t('entries.meal')) ?></option>
                            <option value="dinner"><?= e(t('entries.dinner')) ?></option>
                            <option value="workout"><?= e(t('entries.workout')) ?></option>
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
                    <input type="file" name="photo" accept="image/*" capture="environment" required>
                </label>

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
                    <?php $category = (string) $photo['category']; ?>
                    <figure>
                        <img src="<?= e((string) $photo['file_path']) ?>" alt="<?= e(t('common.photo')) ?>">
                        <figcaption>
                            <strong><?= e((string) $photo['display_name']) ?></strong>
                            <span><?= e(format_date_eu((string) $photo['log_date'])) ?> · <?= e($categoryLabels[$category] ?? $category) ?></span>
                            <?php if (!empty($photo['caption'])): ?>
                                <span><?= e((string) $photo['caption']) ?></span>
                            <?php endif; ?>
                        </figcaption>
                    </figure>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>
</section>
