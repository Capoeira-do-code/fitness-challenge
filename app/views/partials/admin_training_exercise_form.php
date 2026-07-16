<?php

declare(strict_types=1);

$trainingExercise = is_array($trainingExerciseFormItem ?? null) ? $trainingExerciseFormItem : [];
$trainingExerciseIsNew = !empty($trainingExerciseIsNew);
$trainingExerciseContent = is_array($trainingExercise['content'] ?? null) ? (array) $trainingExercise['content'] : [];
$trainingSecondary = json_decode((string) ($trainingExercise['secondary_muscles'] ?? '[]'), true);
$trainingSecondary = is_array($trainingSecondary) ? array_map('strval', $trainingSecondary) : [];
$trainingMuscleOptions = wk_muscle_groups();
$trainingEquipmentOptions = wk_equipment_options();
$trainingImagePath = trim((string) ($trainingExercise['image_path'] ?? ''));
$trainingImageUrl = $trainingImagePath !== '' ? media_url($trainingImagePath) : '';
$trainingVideoUrl = trim((string) ($trainingExercise['video_url'] ?? ''));
$trainingCoverMode = wk_normalize_exercise_cover_mode($trainingExercise['cover_mode'] ?? 'auto');
$trainingImagePosition = wk_normalize_image_position($trainingExercise['image_position'] ?? 'center');
$trainingAccentColor = wk_normalize_exercise_color($trainingExercise['accent_color'] ?? wk_exercise_default_color($trainingExercise['muscle_group'] ?? 'chest'));
$trainingColorOptions = wk_routine_color_options();
$trainingVisualMark = wk_exercise_visual_mark(array_merge(['muscle_group' => 'chest'], $trainingExercise));
$trainingMarkOptions = wk_exercise_mark_options();
$trainingActive = $trainingExerciseIsNew || (int) ($trainingExercise['active'] ?? 0) === 1;
$trainingRankable = $trainingExerciseIsNew || (int) ($trainingExercise['rankable'] ?? 0) === 1;
?>
<form method="post" action="/?page=admin" enctype="multipart/form-data" class="stack compact-form admin-training-exercise-form" data-workout-media-editor>
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="save_training_exercise">
    <input type="hidden" name="exercise_id" value="<?= (int) ($trainingExercise['id'] ?? 0) ?>">

    <div class="grid-inline admin-training-core-fields">
        <label>
            Name
            <input type="text" name="name" value="<?= e((string) ($trainingExercise['name'] ?? '')) ?>" required maxlength="120" placeholder="e.g. Incline dumbbell press">
        </label>
        <label>
            Primary muscle
            <select name="muscle_group">
                <?php foreach ($trainingMuscleOptions as $muscle): ?>
                    <option value="<?= e($muscle) ?>" <?= (string) ($trainingExercise['muscle_group'] ?? 'chest') === $muscle ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $muscle))) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            Type
            <select name="exercise_type" data-workout-exercise-type>
                <?php foreach (WK_EXERCISE_TYPES as $type): ?>
                    <option value="<?= e($type) ?>" <?= (string) ($trainingExercise['exercise_type'] ?? 'strength') === $type ? 'selected' : '' ?>><?= e(ucwords($type)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            Equipment
            <select name="equipment">
                <?php foreach ($trainingEquipmentOptions as $equipment): ?>
                    <option value="<?= e($equipment) ?>" <?= (string) ($trainingExercise['equipment'] ?? 'none') === $equipment ? 'selected' : '' ?>><?= e(ucwords($equipment)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            Difficulty
            <select name="difficulty">
                <?php foreach (['beginner', 'intermediate', 'advanced'] as $difficulty): ?>
                    <option value="<?= e($difficulty) ?>" <?= (string) ($trainingExercise['difficulty'] ?? 'beginner') === $difficulty ? 'selected' : '' ?>><?= e(ucwords($difficulty)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            Rank factor
            <input type="number" name="rank_factor" min="0.01" max="100" step="0.01" value="<?= e((string) ($trainingExercise['rank_factor'] ?? '1')) ?>">
        </label>
        <label>
            Order
            <input type="number" name="sort_order" min="0" value="<?= (int) ($trainingExercise['sort_order'] ?? 9999) ?>">
        </label>
    </div>

    <fieldset class="admin-training-muscles">
        <legend>Secondary muscles</legend>
        <div class="chip-group">
            <?php foreach ($trainingMuscleOptions as $muscle): ?>
                <label class="chip"><input type="checkbox" name="secondary_muscles[]" value="<?= e($muscle) ?>" <?= in_array($muscle, $trainingSecondary, true) ? 'checked' : '' ?>><?= e(ucwords($muscle)) ?></label>
            <?php endforeach; ?>
        </div>
    </fieldset>

    <?php $workoutTrainingDefaultsFields = ['exercise' => array_merge(['exercise_type' => 'strength'], $trainingExercise)]; require __DIR__ . '/workout_training_defaults_fields.php'; ?>

    <div class="grid-inline admin-training-toggle-fields">
        <label class="check"><input type="checkbox" name="rankable" value="1" <?= $trainingRankable ? 'checked' : '' ?>>Counts toward ranked</label>
        <label class="check"><input type="checkbox" name="active" value="1" <?= $trainingActive ? 'checked' : '' ?>><?= e(t('common.active')) ?></label>
    </div>

    <label>
        Short guide
        <textarea name="summary" rows="2" maxlength="800" placeholder="What this exercise trains and when to use it."><?= e((string) ($trainingExerciseContent['summary'] ?? '')) ?></textarea>
    </label>
    <?php
    $workoutGuideBuilderContent = $trainingExerciseContent;
    $workoutGuideBuilderId = 'admin-exercise-guide-' . ((int) ($trainingExercise['id'] ?? 0));
    $workoutGuideBuilderLimit = 50;
    require __DIR__ . '/workout_guide_builder.php';
    ?>

    <section class="admin-training-media-fields" aria-labelledby="training-media-title-<?= (int) ($trainingExercise['id'] ?? 0) ?>">
        <div>
            <h4 id="training-media-title-<?= (int) ($trainingExercise['id'] ?? 0) ?>">Example media</h4>
            <p class="muted small">Upload a technique photo and optionally link a YouTube, Vimeo or direct video.</p>
        </div>
        <?php
        $workoutLivePreviewExercise = array_merge($trainingExercise, ['content' => $trainingExerciseContent]);
        $workoutLivePreviewImageUrl = $trainingImageUrl;
        $workoutLivePreviewVideoUrl = $trainingVideoUrl;
        $workoutLivePreviewId = 'admin-exercise-preview-' . ((int) ($trainingExercise['id'] ?? 0));
        require __DIR__ . '/workout_exercise_live_preview.php';
        ?>
        <details class="workouts-custom-color-details admin-training-color-details" style="--exercise-accent: <?= e($trainingAccentColor) ?>; --workout-accent: <?= e($trainingAccentColor) ?>">
            <summary><span class="workouts-custom-color-swatch" aria-hidden="true"><span data-workout-mark-preview><?= e($trainingVisualMark) ?></span></span><span><strong><?= e(t('workouts.exercise_identity')) ?></strong><small><?= e(t('workouts.exercise_identity_hint')) ?></small></span><b aria-hidden="true">+</b></summary>
            <div class="workouts-custom-color-details-body">
                <fieldset class="workouts-exercise-mark-picker" data-workout-mark-picker>
                    <legend><?= e(t('workouts.exercise_symbol')) ?></legend>
                    <p><?= e(t('workouts.exercise_symbol_hint')) ?></p>
                    <div class="workouts-exercise-mark-options" aria-label="<?= e(t('workouts.exercise_symbol')) ?>">
                        <?php foreach ($trainingMarkOptions as $markKey => $markValue): ?><label title="<?= e(t('workouts.mark_' . $markKey)) ?>"><input type="radio" name="visual_mark_preset" value="<?= e($markValue) ?>" data-workout-mark-preset<?= $trainingVisualMark === $markValue ? ' checked' : '' ?>><span aria-hidden="true"><?= e($markValue) ?></span><span class="sr-only"><?= e(t('workouts.mark_' . $markKey)) ?></span></label><?php endforeach; ?>
                    </div>
                    <label class="workouts-exercise-custom-mark"><span><strong><?= e(t('workouts.custom_symbol')) ?></strong><small><?= e(t('workouts.custom_symbol_hint')) ?></small></span><input type="text" name="visual_mark" value="<?= e($trainingVisualMark) ?>" maxlength="12" inputmode="text" autocomplete="off" data-workout-mark-input aria-label="<?= e(t('workouts.custom_symbol')) ?>"></label>
                </fieldset>
                <fieldset class="workouts-exercise-color-picker" data-workout-color-picker data-workout-color-property="--exercise-accent" style="--workout-accent: <?= e($trainingAccentColor) ?>">
                    <legend><?= e(t('workouts.exercise_color')) ?></legend>
                    <div class="workouts-routine-color-options" aria-label="<?= e(t('workouts.exercise_color')) ?>">
                        <?php foreach ($trainingColorOptions as $colorKey => $colorValue): ?><label title="<?= e(t('workouts.color_' . $colorKey)) ?>"><input type="radio" name="accent_color_preset" value="<?= e($colorValue) ?>" data-workout-color-preset<?= $trainingAccentColor === $colorValue ? ' checked' : '' ?>><span style="--swatch: <?= e($colorValue) ?>"><span class="sr-only"><?= e(t('workouts.color_' . $colorKey)) ?></span></span></label><?php endforeach; ?>
                    </div>
                    <label class="workouts-routine-custom-color"><span><strong><?= e(t('workouts.custom_color')) ?></strong><small><?= e(t('workouts.custom_color_hint')) ?></small></span><span class="workouts-routine-custom-color-control"><input type="color" name="accent_color" value="<?= e($trainingAccentColor) ?>" data-workout-color-input aria-label="<?= e(t('workouts.custom_color')) ?>"><output data-workout-color-output><?= e(strtoupper($trainingAccentColor)) ?></output></span></label>
                </fieldset>
            </div>
        </details>
        <?php
        $workoutGalleryExercise = $trainingExercise;
        $workoutGalleryItems = (array) (($adminTrainingExerciseMedia ?? [])[(int) ($trainingExercise['id'] ?? 0)] ?? []);
        $workoutGalleryId = 'admin-exercise-gallery-' . ((int) ($trainingExercise['id'] ?? 0));
        require __DIR__ . '/workout_exercise_gallery_editor.php';
        ?>
        <div class="grid-inline">
            <label>Video URL <input type="url" name="video_url" value="<?= e($trainingVideoUrl) ?>" placeholder="https://www.youtube.com/watch?v=..." data-workout-video-input></label>
            <label>Library cover <select name="cover_mode"><option value="auto"<?= $trainingCoverMode === 'auto' ? ' selected' : '' ?>>Auto</option><option value="photo"<?= $trainingCoverMode === 'photo' ? ' selected' : '' ?>>Photo</option><option value="video"<?= $trainingCoverMode === 'video' ? ' selected' : '' ?>>Video</option><option value="simple"<?= $trainingCoverMode === 'simple' ? ' selected' : '' ?>>Simple</option></select></label>
        </div>
        <button class="btn btn-ghost small" type="button" data-workout-clear-video>Clear video</button>
        <div class="workouts-custom-video-preview" data-workout-video-preview data-empty-label="Video preview"></div>
    </section>

    <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
</form>

<?php if (!$trainingExerciseIsNew): ?>
    <form method="post" action="/?page=admin" class="admin-danger-zone" onsubmit="return confirm('Remove this exercise from Training? Existing session history will be preserved.');">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="delete_training_exercise">
        <input type="hidden" name="exercise_id" value="<?= (int) ($trainingExercise['id'] ?? 0) ?>">
        <button class="btn btn-ghost small" type="submit"><?= e(t('common.delete')) ?></button>
    </form>
<?php endif; ?>
