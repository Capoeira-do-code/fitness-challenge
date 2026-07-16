<?php

declare(strict_types=1);

$liveExercise = is_array($workoutLivePreviewExercise ?? null) ? (array) $workoutLivePreviewExercise : [];
$liveContent = is_array($liveExercise['content'] ?? null) ? (array) $liveExercise['content'] : [];
$liveImageUrl = trim((string) ($workoutLivePreviewImageUrl ?? ''));
$liveVideoUrl = trim((string) ($workoutLivePreviewVideoUrl ?? ''));
$liveId = preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string) ($workoutLivePreviewId ?? 'exercise-live-preview')) ?: 'exercise-live-preview';
$liveName = trim((string) ($liveExercise['name'] ?? ''));
$liveSummary = trim((string) ($liveContent['summary'] ?? ''));
$liveMuscle = trim((string) ($liveExercise['muscle_group'] ?? 'core')) ?: 'core';
$liveEquipment = trim((string) ($liveExercise['equipment'] ?? 'none')) ?: 'none';
$liveDifficulty = trim((string) ($liveExercise['difficulty'] ?? 'beginner')) ?: 'beginner';
$liveType = trim((string) ($liveExercise['exercise_type'] ?? 'strength')) ?: 'strength';
$liveMark = wk_exercise_visual_mark(array_merge(['muscle_group' => $liveMuscle], $liveExercise));
$liveAccent = wk_normalize_exercise_color($liveExercise['accent_color'] ?? wk_exercise_default_color($liveMuscle));
$liveCoverMode = wk_normalize_exercise_cover_mode($liveExercise['cover_mode'] ?? 'auto');
$livePosition = wk_image_position_css($liveExercise['image_position'] ?? 'center');
$liveVideoSource = wk_exercise_video_source($liveVideoUrl);
$liveVideoThumbnail = trim((string) ($liveVideoSource['thumbnail_url'] ?? ''));
$liveHasVideo = $liveVideoSource !== null;
$liveResolvedSource = match ($liveCoverMode) {
    'photo' => $liveImageUrl !== '' ? 'photo' : 'simple',
    'video' => $liveHasVideo ? 'video' : 'simple',
    'simple' => 'simple',
    default => $liveImageUrl !== '' ? 'photo' : ($liveHasVideo ? 'video' : 'simple'),
};
$liveResolvedImage = $liveResolvedSource === 'photo'
    ? $liveImageUrl
    : ($liveResolvedSource === 'video' ? $liveVideoThumbnail : '');
$liveCoverLabels = [
    'auto' => t('workouts.cover_auto'),
    'photo' => t('workouts.cover_photo'),
    'video' => t('workouts.cover_video'),
    'simple' => t('workouts.cover_simple'),
];
$liveSourceLabels = [
    'photo' => t('workouts.cover_photo'),
    'video' => t('workouts.cover_video'),
    'simple' => t('workouts.cover_simple'),
];
$liveCoverStatus = ($liveCoverLabels[$liveCoverMode] ?? $liveCoverLabels['auto'])
    . ' · ' . ($liveSourceLabels[$liveResolvedSource] ?? $liveSourceLabels['simple']);
$liveDefaults = wk_exercise_training_defaults(array_merge(['exercise_type' => $liveType], $liveExercise));
$liveTarget = match ($liveType) {
    'cardio' => (string) $liveDefaults['target_sets'] . '×' . ($liveDefaults['target_duration'] !== null ? rtrim(rtrim(number_format((int) $liveDefaults['target_duration'] / 60, 1, '.', ''), '0'), '.') . ' min' : '—'),
    'isometric' => (string) $liveDefaults['target_sets'] . '×' . ($liveDefaults['target_duration'] !== null ? (int) $liveDefaults['target_duration'] . 's' : '—'),
    default => (string) $liveDefaults['target_sets'] . '×' . ($liveDefaults['target_reps'] ?? '—'),
};
$liveNameDisplay = $liveName !== '' ? $liveName : t('workouts.preview_name_placeholder');
$liveSummaryDisplay = $liveSummary !== '' ? $liveSummary : t('workouts.preview_summary_placeholder');
$liveMuscleLabel = t('workouts.muscle_' . $liveMuscle);
$liveEquipmentLabel = t('workouts.equipment_' . $liveEquipment);
$liveDifficultyLabel = t('workouts.difficulty_' . $liveDifficulty);
$liveTypeLabel = t('workouts.type_' . $liveType);
?>

<details
    class="workouts-exercise-live-preview"
    style="--exercise-accent: <?= e($liveAccent) ?>; --workout-accent: <?= e($liveAccent) ?>"
    data-workout-exercise-live-preview
    data-placeholder-name="<?= e(t('workouts.preview_name_placeholder')) ?>"
    data-placeholder-summary="<?= e(t('workouts.preview_summary_placeholder')) ?>"
    data-cover-auto-label="<?= e($liveCoverLabels['auto']) ?>"
    data-cover-photo-label="<?= e($liveCoverLabels['photo']) ?>"
    data-cover-video-label="<?= e($liveCoverLabels['video']) ?>"
    data-cover-simple-label="<?= e($liveCoverLabels['simple']) ?>"
    open
>
    <summary class="workouts-exercise-live-preview-head">
        <span class="workouts-exercise-live-preview-icon" aria-hidden="true" data-workout-preview-head-mark><?= e($liveMark) ?></span>
        <div>
            <p class="eyebrow"><?= e(t('workouts.live_preview')) ?></p>
            <h3 data-workout-preview-name><?= e($liveNameDisplay) ?></h3>
        </div>
        <em data-workout-preview-cover-status><?= e($liveCoverStatus) ?></em>
        <b aria-hidden="true">+</b>
    </summary>

    <div class="workouts-exercise-live-preview-body">
        <div class="workouts-exercise-live-preview-tabs" role="tablist" aria-label="<?= e(t('workouts.preview_context')) ?>">
            <button id="<?= e($liveId) ?>-library-tab" type="button" role="tab" aria-selected="true" aria-controls="<?= e($liveId) ?>-library" data-workout-preview-mode="library"><?= e(t('workouts.preview_library')) ?></button>
            <button id="<?= e($liveId) ?>-session-tab" type="button" role="tab" aria-selected="false" aria-controls="<?= e($liveId) ?>-session" tabindex="-1" data-workout-preview-mode="session"><?= e(t('workouts.preview_session')) ?></button>
        </div>

        <div class="workouts-exercise-live-preview-stage">
            <article id="<?= e($liveId) ?>-library" class="workouts-exercise-preview-card" role="tabpanel" aria-labelledby="<?= e($liveId) ?>-library-tab" data-workout-preview-panel="library">
            <div class="workouts-exercise-preview-media" data-workout-preview-media data-preview-source="<?= e($liveResolvedSource) ?>">
                <img src="<?= e($liveResolvedImage) ?>" alt="" data-workout-preview-image style="object-position: <?= e($livePosition) ?>"<?= $liveResolvedImage === '' ? ' hidden' : '' ?>>
                <span class="workouts-exercise-preview-mark" aria-hidden="true" data-workout-preview-mark<?= $liveResolvedImage !== '' ? ' hidden' : '' ?>><?= e($liveMark) ?></span>
                <span class="workouts-exercise-preview-play" aria-hidden="true" data-workout-preview-play<?= $liveResolvedSource !== 'video' ? ' hidden' : '' ?>>&#9654;</span>
                <span class="workouts-exercise-preview-badges"><em><?= e(t('workouts.mine')) ?></em><em data-workout-preview-cover-status><?= e($liveCoverStatus) ?></em></span>
            </div>
            <div class="workouts-exercise-preview-copy">
                <span class="workouts-muscle-token" aria-hidden="true" data-workout-preview-muscle-token><?= e(strtoupper(substr($liveMuscle, 0, 2))) ?></span>
                <div>
                    <h4 data-workout-preview-name><?= e($liveNameDisplay) ?></h4>
                    <p data-workout-preview-summary><?= e($liveSummaryDisplay) ?></p>
                </div>
                <div class="workouts-exercise-preview-tags">
                    <span data-workout-preview-muscle><?= e($liveMuscleLabel) ?></span>
                    <span data-workout-preview-equipment><?= e($liveEquipmentLabel) ?></span>
                    <span data-workout-preview-difficulty><?= e($liveDifficultyLabel) ?></span>
                    <span class="is-target" data-workout-preview-target><?= e($liveTarget) ?></span>
                </div>
            </div>
            </article>

            <article id="<?= e($liveId) ?>-session" class="workouts-exercise-preview-session" role="tabpanel" aria-labelledby="<?= e($liveId) ?>-session-tab" data-workout-preview-panel="session" hidden>
            <div class="workouts-exercise-preview-session-cover workouts-exercise-preview-media" data-workout-preview-media data-preview-source="<?= e($liveResolvedSource) ?>">
                <img src="<?= e($liveResolvedImage) ?>" alt="" data-workout-preview-image style="object-position: <?= e($livePosition) ?>"<?= $liveResolvedImage === '' ? ' hidden' : '' ?>>
                <span class="workouts-exercise-preview-mark" aria-hidden="true" data-workout-preview-mark<?= $liveResolvedImage !== '' ? ' hidden' : '' ?>><?= e($liveMark) ?></span>
                <span class="workouts-exercise-preview-play" aria-hidden="true" data-workout-preview-play<?= $liveResolvedSource !== 'video' ? ' hidden' : '' ?>>&#9654;</span>
            </div>
            <div class="workouts-exercise-preview-session-copy">
                <p class="eyebrow"><?= e(t('workouts.preview_session')) ?></p>
                <h4 data-workout-preview-name><?= e($liveNameDisplay) ?></h4>
                <p><span data-workout-preview-type><?= e($liveTypeLabel) ?></span> · <span data-workout-preview-muscle><?= e($liveMuscleLabel) ?></span></p>
            </div>
            <strong class="workouts-exercise-preview-session-target" data-workout-preview-target><?= e($liveTarget) ?></strong>
            </article>
        </div>

        <p class="workouts-exercise-live-preview-note"><span aria-hidden="true">●</span><?= e(t('workouts.preview_updates_hint')) ?></p>
    </div>
</details>
