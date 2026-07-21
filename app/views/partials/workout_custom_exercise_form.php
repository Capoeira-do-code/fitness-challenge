<?php

declare(strict_types=1);

$customExercise = is_array($wkCustomExercise ?? null) ? (array) $wkCustomExercise : [];
$customExerciseId = (int) ($customExercise['id'] ?? 0);
$customExerciseIsNew = $customExerciseId <= 0;
$customContent = is_array($customExercise['content'] ?? null)
    ? (array) $customExercise['content']
    : wk_exercise_content($customExercise);
$customSecondary = json_decode((string) ($customExercise['secondary_muscles'] ?? '[]'), true);
$customSecondary = is_array($customSecondary) ? array_map('strval', $customSecondary) : [];
$customImagePath = trim((string) ($customExercise['image_path'] ?? ''));
$customImageUrl = $customImagePath !== '' ? media_url($customImagePath) : '';
$customVideoUrl = trim((string) ($customExercise['video_url'] ?? ''));
$customCoverMode = wk_normalize_exercise_cover_mode($customExercise['cover_mode'] ?? 'auto');
$customImagePosition = wk_normalize_image_position($customExercise['image_position'] ?? 'center');
$customAccentColor = wk_normalize_exercise_color($customExercise['accent_color'] ?? wk_exercise_default_color($customExercise['muscle_group'] ?? 'chest'));
$customColorOptions = wk_routine_color_options();
$customVisualMark = wk_exercise_visual_mark(array_merge(['muscle_group' => 'core'], $customExercise));
$customMarkOptions = wk_exercise_mark_options();
$customEditorSection = in_array((string) ($wkCustomEditorSection ?? 'basics'), ['basics', 'guide', 'media'], true)
    ? (string) ($wkCustomEditorSection ?? 'basics')
    : 'basics';
$customTargetRoutineId = (int) (($wkTargetRoutine['id'] ?? 0));
$customTargetRoutineExerciseId = (int) (($wkTargetRoutineExercise['id'] ?? 0));
$customTargetSessionId = (int) (($wkTargetSession['id'] ?? 0));
$customReturnUrl = $customTargetRoutineId > 0 && $customTargetRoutineExerciseId > 0
    ? '/?page=workouts&routine_id=' . $customTargetRoutineId . '&routine_exercise_id=' . $customTargetRoutineExerciseId
    : ($customTargetSessionId > 0
    ? '/?page=workouts&view=library&target_session_id=' . $customTargetSessionId . '&scope=mine'
    : ($customTargetRoutineId > 0
        ? '/?page=workouts&view=library&target_routine_id=' . $customTargetRoutineId . '&scope=mine'
        : '/?page=workouts&view=library&scope=mine'));
$customDraftKey = implode(':', [
    'fitness-challenge',
    'exercise-draft',
    'v1',
    (int) ($currentUser['id'] ?? 0),
    $customExerciseId > 0 ? 'exercise-' . $customExerciseId : 'new',
    'routine-' . $customTargetRoutineId,
    'routine-exercise-' . $customTargetRoutineExerciseId,
    'session-' . $customTargetSessionId,
]);
$customDraftRevision = trim((string) ($customExercise['updated_at'] ?? ''));
$customMediaSearchEnabled = !empty($wkMediaSearchEnabled);
?>

<header class="hierarchy-page-header workouts-custom-header">
    <button class="hierarchy-back destination-back" type="button" data-hierarchy-back data-fallback="<?= e($customReturnUrl) ?>" aria-label="<?= e(t('common.back')) ?>: <?= e(t('workouts.tab_library')) ?>"><span aria-hidden="true">&larr;</span><strong><?= e(t('workouts.tab_library')) ?></strong></button>
    <div>
        <p class="eyebrow"><?= e(t('workouts.my_exercises')) ?></p>
        <h1><?= e($customExerciseIsNew ? t('workouts.create_custom') : t('workouts.edit_custom')) ?></h1>
        <p><?= e($customTargetRoutineExerciseId > 0
            ? t('workouts.personalizing_for_routine', ['name' => (string) ($wkTargetRoutine['name'] ?? '')])
            : t('workouts.custom_editor_hint')) ?></p>
    </div>
</header>

<form method="post" action="/?page=workouts" enctype="multipart/form-data" class="workouts-custom-editor" data-workout-media-editor data-workout-editor-section="<?= e($customEditorSection) ?>" data-workout-draft-key="<?= e($customDraftKey) ?>" data-workout-draft-revision="<?= e($customDraftRevision) ?>">
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
    <input type="hidden" name="action" value="custom_exercise_save">
    <input type="hidden" name="exercise_id" value="<?= $customExerciseId ?>">
    <input type="hidden" name="editor_section" value="<?= e($customEditorSection) ?>" data-workout-editor-section-input>
    <?php if ($customTargetSessionId > 0): ?><input type="hidden" name="target_session_id" value="<?= $customTargetSessionId ?>"><?php elseif ($customTargetRoutineId > 0): ?><input type="hidden" name="target_routine_id" value="<?= $customTargetRoutineId ?>"><?php if ($customTargetRoutineExerciseId > 0): ?><input type="hidden" name="target_routine_exercise_id" value="<?= $customTargetRoutineExerciseId ?>"><?php endif; ?><?php endif; ?>

    <nav class="workouts-custom-step-nav" aria-label="<?= e(t('workouts.editor_sections')) ?>" data-workout-editor-step-nav>
        <?php foreach (['basics' => t('workouts.custom_basics'), 'guide' => t('workouts.custom_guide'), 'media' => t('workouts.editor_media')] as $sectionKey => $sectionLabel): ?>
            <button type="button" data-workout-editor-step-trigger="<?= e($sectionKey) ?>" aria-pressed="<?= $customEditorSection === $sectionKey ? 'true' : 'false' ?>"><span><?= $sectionKey === 'basics' ? '1' : ($sectionKey === 'guide' ? '2' : '3') ?></span><strong><?= e($sectionLabel) ?></strong></button>
        <?php endforeach; ?>
    </nav>

    <aside
        class="workouts-custom-draft is-dismissed"
        data-workout-draft-status
        data-state="ready"
        data-ready-label="<?= e(t('workouts.draft_ready')) ?>"
        data-saving-label="<?= e(t('workouts.draft_saving')) ?>"
        data-saved-template="<?= e(t('workouts.draft_saved', ['time' => '{time}'])) ?>"
        data-found-label="<?= e(t('workouts.draft_found')) ?>"
        data-found-template="<?= e(t('workouts.draft_found_hint', ['time' => '{time}'])) ?>"
        data-restored-label="<?= e(t('workouts.draft_restored')) ?>"
        data-discarded-label="<?= e(t('workouts.draft_discarded')) ?>"
        data-files-label="<?= e(t('workouts.draft_files_hint')) ?>"
        data-unavailable-label="<?= e(t('workouts.draft_unavailable')) ?>"
    >
        <span class="workouts-custom-draft-icon" aria-hidden="true"><span></span></span>
        <span class="workouts-custom-draft-copy" aria-live="polite"><strong data-workout-draft-title><?= e(t('workouts.draft_ready')) ?></strong><small data-workout-draft-hint></small></span>
        <span class="workouts-custom-draft-actions" data-workout-draft-actions hidden>
            <button class="btn btn-primary small" type="button" data-workout-draft-restore><?= e(t('workouts.draft_restore')) ?></button>
            <button class="btn btn-ghost small" type="button" data-workout-draft-discard><?= e(t('workouts.draft_discard')) ?></button>
        </span>
    </aside>

    <section class="panel workouts-custom-section" data-workout-editor-step="basics">
        <div class="workouts-custom-section-head"><span>1</span><div><h2><?= e(t('workouts.custom_basics')) ?></h2><p><?= e(t('workouts.custom_basics_hint')) ?></p></div></div>
        <div class="workouts-custom-fields workouts-custom-fields-primary">
            <label class="workouts-custom-name"><?= e(t('workouts.exercise_name')) ?><input type="text" name="name" value="<?= e((string) ($customExercise['name'] ?? '')) ?>" required maxlength="120" autocomplete="off" placeholder="<?= e(t('workouts.exercise_name_placeholder')) ?>"></label>
            <label><?= e(t('workouts.muscle_group')) ?><select name="muscle_group"><?php foreach ((array) ($wkMuscleGroups ?? []) as $muscle): ?><option value="<?= e((string) $muscle) ?>"<?= (string) ($customExercise['muscle_group'] ?? 'core') === (string) $muscle ? ' selected' : '' ?>><?= e($muscleLabel((string) $muscle)) ?></option><?php endforeach; ?></select></label>
            <label><?= e(t('workouts.exercise_type')) ?><select name="exercise_type" data-workout-exercise-type><?php foreach (WK_EXERCISE_TYPES as $type): ?><option value="<?= e($type) ?>"<?= (string) ($customExercise['exercise_type'] ?? 'strength') === $type ? ' selected' : '' ?>><?= e($exerciseTypeLabel($type)) ?></option><?php endforeach; ?></select></label>
            <label><?= e(t('workouts.equipment')) ?><select name="equipment"><?php foreach ((array) ($wkEquipmentOptions ?? []) as $equipment): ?><option value="<?= e((string) $equipment) ?>"<?= (string) ($customExercise['equipment'] ?? 'none') === (string) $equipment ? ' selected' : '' ?>><?= e($equipmentLabel((string) $equipment)) ?></option><?php endforeach; ?></select></label>
            <label><?= e(t('workouts.difficulty')) ?><select name="difficulty"><?php foreach (['beginner', 'intermediate', 'advanced'] as $difficulty): ?><option value="<?= e($difficulty) ?>"<?= (string) ($customExercise['difficulty'] ?? 'beginner') === $difficulty ? ' selected' : '' ?>><?= e($difficultyLabel($difficulty)) ?></option><?php endforeach; ?></select></label>
        </div>
        <fieldset class="workouts-custom-muscles">
            <legend><?= e(t('workouts.secondary_muscles')) ?></legend>
            <div class="chip-group"><?php foreach ((array) ($wkMuscleGroups ?? []) as $muscle): ?><label class="chip"><input type="checkbox" name="secondary_muscles[]" value="<?= e((string) $muscle) ?>"<?= in_array((string) $muscle, $customSecondary, true) ? ' checked' : '' ?>><span><?= e($muscleLabel((string) $muscle)) ?></span></label><?php endforeach; ?></div>
        </fieldset>
        <?php $workoutTrainingDefaultsFields = ['exercise' => array_merge(['exercise_type' => 'strength'], $customExercise)]; require __DIR__ . '/workout_training_defaults_fields.php'; ?>
    </section>

    <section class="panel workouts-custom-section" data-workout-editor-step="guide">
        <div class="workouts-custom-section-head"><span>2</span><div><h2><?= e(t('workouts.custom_guide')) ?></h2><p><?= e(t('workouts.custom_guide_hint')) ?></p></div></div>
        <label><?= e(t('workouts.short_guide')) ?><textarea name="summary" rows="3" maxlength="800" placeholder="<?= e(t('workouts.short_guide_placeholder')) ?>"><?= e((string) ($customContent['summary'] ?? '')) ?></textarea></label>
        <?php
        $workoutGuideBuilderContent = $customContent;
        $workoutGuideBuilderId = 'custom-exercise-guide-' . ($customExerciseId > 0 ? $customExerciseId : 'new');
        $workoutGuideBuilderLimit = 20;
        require __DIR__ . '/workout_guide_builder.php';
        ?>
    </section>

    <section class="panel workouts-custom-section workouts-custom-media" data-workout-editor-step="media">
        <div class="workouts-custom-section-head"><span>3</span><div><h2><?= e(t('workouts.custom_media')) ?></h2><p><?= e(t('workouts.custom_media_hint')) ?></p></div></div>
        <?php
        $workoutLivePreviewExercise = array_merge($customExercise, ['content' => $customContent]);
        $workoutLivePreviewImageUrl = $customImageUrl;
        $workoutLivePreviewVideoUrl = $customVideoUrl;
        $workoutLivePreviewId = 'custom-exercise-preview-' . ($customExerciseId > 0 ? $customExerciseId : 'new');
        require __DIR__ . '/workout_exercise_live_preview.php';
        ?>
        <div class="workouts-custom-media-group">
            <p class="workouts-custom-media-group-label"><?= e(t('workouts.media_group_appearance')) ?><span><?= e(t('workouts.media_group_appearance_hint')) ?></span></p>
            <details class="workouts-custom-color-details" style="--exercise-accent: <?= e($customAccentColor) ?>; --workout-accent: <?= e($customAccentColor) ?>">
                <summary><span class="workouts-custom-color-swatch" aria-hidden="true"><span data-workout-mark-preview><?= e($customVisualMark) ?></span></span><span><strong><?= e(t('workouts.exercise_identity')) ?></strong><small><?= e(t('workouts.exercise_identity_hint')) ?></small></span><b aria-hidden="true">+</b></summary>
                <div class="workouts-custom-color-details-body">
                    <fieldset class="workouts-exercise-mark-picker" data-workout-mark-picker>
                        <legend><?= e(t('workouts.exercise_symbol')) ?></legend>
                        <p><?= e(t('workouts.exercise_symbol_hint')) ?></p>
                        <div class="workouts-exercise-mark-options" aria-label="<?= e(t('workouts.exercise_symbol')) ?>">
                            <?php foreach ($customMarkOptions as $markKey => $markValue): ?><label title="<?= e(t('workouts.mark_' . $markKey)) ?>"><input type="radio" name="visual_mark_preset" value="<?= e($markValue) ?>" data-workout-mark-preset<?= $customVisualMark === $markValue ? ' checked' : '' ?>><span aria-hidden="true"><?= e($markValue) ?></span><span class="sr-only"><?= e(t('workouts.mark_' . $markKey)) ?></span></label><?php endforeach; ?>
                        </div>
                        <label class="workouts-exercise-custom-mark"><span><strong><?= e(t('workouts.custom_symbol')) ?></strong><small><?= e(t('workouts.custom_symbol_hint')) ?></small></span><input type="text" name="visual_mark" value="<?= e($customVisualMark) ?>" maxlength="12" inputmode="text" autocomplete="off" data-workout-mark-input aria-label="<?= e(t('workouts.custom_symbol')) ?>"></label>
                    </fieldset>
                    <fieldset class="workouts-exercise-color-picker" data-workout-color-picker data-workout-color-property="--exercise-accent" style="--workout-accent: <?= e($customAccentColor) ?>">
                        <legend><?= e(t('workouts.exercise_color')) ?></legend>
                        <div class="workouts-routine-color-options" aria-label="<?= e(t('workouts.exercise_color')) ?>">
                            <?php foreach ($customColorOptions as $colorKey => $colorValue): ?><label title="<?= e(t('workouts.color_' . $colorKey)) ?>"><input type="radio" name="accent_color_preset" value="<?= e($colorValue) ?>" data-workout-color-preset<?= $customAccentColor === $colorValue ? ' checked' : '' ?>><span style="--swatch: <?= e($colorValue) ?>"><span class="sr-only"><?= e(t('workouts.color_' . $colorKey)) ?></span></span></label><?php endforeach; ?>
                        </div>
                        <label class="workouts-routine-custom-color"><span><strong><?= e(t('workouts.custom_color')) ?></strong><small><?= e(t('workouts.custom_color_hint')) ?></small></span><span class="workouts-routine-custom-color-control"><input type="color" name="accent_color" value="<?= e($customAccentColor) ?>" data-workout-color-input aria-label="<?= e(t('workouts.custom_color')) ?>"><output data-workout-color-output><?= e(strtoupper($customAccentColor)) ?></output></span></label>
                    </fieldset>
                </div>
            </details>
            <fieldset class="workouts-custom-cover-picker" data-workout-cover-picker>
                <legend><?= e(t('workouts.custom_cover')) ?></legend>
                <p><?= e(t('workouts.custom_cover_hint')) ?></p>
                <div>
                    <?php foreach ([
                        'auto' => ['spark', t('workouts.cover_auto')],
                        'photo' => ['image', t('workouts.cover_photo')],
                        'video' => ['play', t('workouts.cover_video')],
                        'simple' => ['simple', t('workouts.cover_simple')],
                    ] as $coverMode => [$coverIcon, $coverLabel]): ?>
                        <label><input type="radio" name="cover_mode" value="<?= e($coverMode) ?>"<?= $customCoverMode === $coverMode ? ' checked' : '' ?>><span><?php if ($coverIcon === 'play'): ?><b aria-hidden="true">&#9654;</b><?php elseif ($coverIcon === 'simple'): ?><b aria-hidden="true">AB</b><?php else: ?><?= activity_icon_svg($coverIcon) ?><?php endif; ?><strong><?= e($coverLabel) ?></strong></span></label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
        </div>
        <div class="workouts-custom-media-group">
            <p class="workouts-custom-media-group-label"><?= e(t('workouts.media_group_library')) ?><span><?= e(t('workouts.media_group_library_hint')) ?></span></p>
            <div class="workouts-custom-media-menus">
                <?php
                $workoutGalleryExercise = $customExercise;
                $workoutGalleryItems = (array) ($wkCustomExerciseMedia ?? []);
                $workoutGalleryId = 'custom-exercise-gallery-' . ($customExerciseId > 0 ? $customExerciseId : 'new');
                $workoutGalleryMediaSearch = $customMediaSearchEnabled;
                require __DIR__ . '/workout_exercise_gallery_editor.php';
                ?>
                <details class="workouts-custom-media-details<?= $customVideoUrl !== '' ? ' has-media' : '' ?>" data-workout-video-details>
                    <summary><span aria-hidden="true"><b class="workouts-custom-media-play">&#9654;</b></span><span><strong><?= e(t('workouts.video_panel')) ?></strong><small><?= e(t('workouts.video_panel_hint')) ?></small></span><em data-workout-video-status data-empty-label="<?= e(t('workouts.no_video')) ?>" data-ready-label="<?= e(t('workouts.video_added')) ?>" aria-live="polite"><?= e($customVideoUrl !== '' ? t('workouts.video_added') : t('workouts.no_video')) ?></em><b aria-hidden="true">+</b></summary>
                    <div class="workouts-custom-media-details-body">
                        <?php if ($customMediaSearchEnabled): ?>
                        <?php
                        $workoutMediaSearchType = 'video';
                        $workoutMediaSearchId = 'custom-exercise-video-' . ($customExerciseId > 0 ? $customExerciseId : 'new');
                        require __DIR__ . '/workout_media_search.php';
                        ?>
                        <?php endif; ?>
                        <div class="workouts-custom-media-control">
                            <label><?= e(t('workouts.custom_video')) ?><input type="url" name="video_url" value="<?= e($customVideoUrl) ?>" placeholder="https://www.youtube.com/watch?v=..." inputmode="url" data-workout-video-input></label>
                            <small><?= e(t('workouts.custom_video_hint')) ?></small>
                            <button class="btn btn-ghost small" type="button" data-workout-clear-video><?= e(t('common.clear')) ?></button>
                        </div>
                        <div class="workouts-custom-video-preview" data-workout-video-preview data-empty-label="<?= e(t('workouts.video_preview')) ?>"></div>
                    </div>
                </details>
            </div>
        </div>
    </section>

    <div class="workouts-custom-savebar">
        <a class="btn btn-ghost" href="<?= e($customReturnUrl) ?>"><?= e(t('common.cancel')) ?></a>
        <button class="btn btn-primary" type="submit"><?= e($customExerciseIsNew ? t('workouts.create_custom') : t('common.save')) ?></button>
    </div>
</form>

<?php if (!$customExerciseIsNew): ?>
    <form method="post" action="/?page=workouts" class="panel workouts-custom-danger" data-workout-draft-delete-key="<?= e($customDraftKey) ?>" onsubmit="return confirm('<?= e(t('workouts.delete_custom_confirm')) ?>');">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="custom_exercise_delete">
        <input type="hidden" name="exercise_id" value="<?= $customExerciseId ?>">
        <div><strong><?= e(t('workouts.delete_custom')) ?></strong><small><?= e(t('workouts.delete_custom_hint')) ?></small></div>
        <button class="btn btn-ghost btn-danger-ghost" type="submit"><?= e(t('common.delete')) ?></button>
    </form>
<?php endif; ?>
