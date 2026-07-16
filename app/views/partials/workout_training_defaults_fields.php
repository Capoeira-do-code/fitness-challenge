<?php

declare(strict_types=1);

/** Shared training-prescription fields for personal and admin exercise editors. */
$trainingDefaultsConfig = is_array($workoutTrainingDefaultsFields ?? null) ? $workoutTrainingDefaultsFields : [];
$trainingDefaultsExercise = is_array($trainingDefaultsConfig['exercise'] ?? null) ? (array) $trainingDefaultsConfig['exercise'] : [];
$trainingDefaults = wk_exercise_training_defaults($trainingDefaultsExercise);
$trainingDefaultsType = in_array((string) ($trainingDefaultsExercise['exercise_type'] ?? 'strength'), WK_EXERCISE_TYPES, true)
    ? (string) $trainingDefaultsExercise['exercise_type']
    : 'strength';
$trainingDefaultsDuration = (int) ($trainingDefaults['target_duration'] ?? 0);
$trainingDefaultsDurationMinutes = $trainingDefaultsDuration > 0
    ? rtrim(rtrim(number_format($trainingDefaultsDuration / 60, 1, '.', ''), '0'), '.')
    : '';
$trainingDefaultsWeight = $trainingDefaults['target_weight'] !== null
    ? rtrim(rtrim(number_format((float) $trainingDefaults['target_weight'], 1, '.', ''), '0'), '.')
    : '';
$trainingDefaultsDistance = $trainingDefaults['target_distance'] !== null
    ? rtrim(rtrim(number_format((float) $trainingDefaults['target_distance'], 2, '.', ''), '0'), '.')
    : '';
$trainingDefaultsStatus = match ($trainingDefaultsType) {
    'cardio' => $trainingDefaults['target_sets'] . '×' . ($trainingDefaultsDurationMinutes !== '' ? $trainingDefaultsDurationMinutes . ' min' : '—'),
    'isometric' => $trainingDefaults['target_sets'] . '×' . ($trainingDefaultsDuration > 0 ? $trainingDefaultsDuration . 's' : '—'),
    default => $trainingDefaults['target_sets'] . '×' . ($trainingDefaults['target_reps'] ?? '—'),
};
?>
<details class="workouts-custom-defaults" data-workout-training-defaults>
    <summary>
        <span aria-hidden="true"><?= activity_icon_svg('target') ?></span>
        <span><strong><?= e(t('workouts.training_defaults')) ?></strong><small><?= e(t('workouts.training_defaults_hint')) ?></small></span>
        <em data-workout-default-status aria-live="polite"><?= e($trainingDefaultsStatus) ?></em>
        <b aria-hidden="true">+</b>
    </summary>
    <div class="workouts-custom-defaults-body">
        <p><?= e(t('workouts.training_defaults_detail')) ?></p>
        <div class="workouts-custom-defaults-grid">
            <label><span data-workout-default-sets-label data-sets-label="<?= e(t('workouts.target_sets')) ?>" data-rounds-label="<?= e(t('workouts.rounds')) ?>"><?= e($trainingDefaultsType === 'cardio' ? t('workouts.rounds') : t('workouts.target_sets')) ?></span><input type="number" name="default_sets" min="1" max="20" value="<?= (int) $trainingDefaults['target_sets'] ?>" inputmode="numeric" data-workout-default-value="sets" required></label>

            <div data-workout-default-panel="strength,bodyweight,freeform">
                <label><?= e(t('workouts.target_reps')) ?><input type="number" name="default_reps" min="0" max="999" value="<?= $trainingDefaults['target_reps'] !== null ? (int) $trainingDefaults['target_reps'] : '' ?>" inputmode="numeric" data-workout-default-value="reps"></label>
            </div>
            <div data-workout-default-panel="cardio">
                <label><?= e(t('workouts.duration_minutes')) ?><input type="number" name="default_duration_minutes" min="0" max="1440" step="0.5" value="<?= e($trainingDefaultsDurationMinutes) ?>" inputmode="decimal" data-workout-default-value="minutes"></label>
            </div>
            <div data-workout-default-panel="cardio">
                <label><?= e(t('workouts.distance_km')) ?><input type="number" name="default_distance" min="0" max="99999" step="0.01" value="<?= e($trainingDefaultsDistance) ?>" inputmode="decimal"></label>
            </div>
            <div data-workout-default-panel="isometric">
                <label><?= e(t('workouts.duration_seconds')) ?><input type="number" name="default_duration_seconds" min="0" max="86400" value="<?= $trainingDefaultsDuration > 0 ? $trainingDefaultsDuration : '' ?>" inputmode="numeric" data-workout-default-value="seconds"></label>
            </div>
            <div data-workout-default-panel="strength,bodyweight,freeform,isometric">
                <label><?= e(t('workouts.target_weight')) ?><input type="number" name="default_weight" min="0" max="99999" step="0.5" value="<?= e($trainingDefaultsWeight) ?>" inputmode="decimal"></label>
            </div>
            <div data-workout-default-panel="strength,bodyweight,freeform,isometric">
                <label><?= e(t('workouts.unit')) ?><select name="default_unit"><?php foreach (WK_UNITS as $unit): ?><option value="<?= e($unit) ?>"<?= $trainingDefaults['unit'] === $unit ? ' selected' : '' ?>><?= e(strtoupper($unit)) ?></option><?php endforeach; ?></select></label>
            </div>
            <label><?= e(t('workouts.rest_time')) ?><span class="workouts-default-rest-input"><input type="number" name="default_rest_seconds" min="0" max="3600" step="5" value="<?= (int) $trainingDefaults['stored_rest_seconds'] ?>" inputmode="numeric"><small><?= e(t('workouts.rest_zero_hint')) ?></small></span></label>
            <label class="workouts-custom-default-notes"><?= e(t('workouts.coaching_notes')) ?><textarea name="default_notes" rows="3" maxlength="500" placeholder="<?= e(t('workouts.coaching_notes_placeholder')) ?>"><?= e($trainingDefaults['notes']) ?></textarea></label>
        </div>
    </div>
</details>
