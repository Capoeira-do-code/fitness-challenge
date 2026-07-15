<?php

declare(strict_types=1);

$guideBuilderContent = is_array($workoutGuideBuilderContent ?? null) ? (array) $workoutGuideBuilderContent : [];
$guideBuilderLimit = max(1, min(50, (int) ($workoutGuideBuilderLimit ?? 20)));
$guideBuilderId = preg_replace('/[^a-z0-9_-]+/i', '-', (string) ($workoutGuideBuilderId ?? 'exercise-guide')) ?: 'exercise-guide';
$guideBuilderSections = [
    'steps' => [
        'label' => t('workouts.steps'),
        'hint' => t('workouts.guide_steps_hint'),
        'add' => t('workouts.add_guide_step'),
        'symbol' => '1',
    ],
    'tips' => [
        'label' => t('workouts.tips'),
        'hint' => t('workouts.guide_tips_hint'),
        'add' => t('workouts.add_guide_tip'),
        'symbol' => '✓',
    ],
    'mistakes' => [
        'label' => t('workouts.mistakes'),
        'hint' => t('workouts.guide_mistakes_hint'),
        'add' => t('workouts.add_guide_mistake'),
        'symbol' => '!',
    ],
];
?>
<div
    class="workouts-guide-builder"
    data-workout-guide-builder
    data-max-items="<?= $guideBuilderLimit ?>"
    data-count-template="<?= e(t('workouts.guide_items_count', ['count' => '{count}'])) ?>"
    data-item-placeholder="<?= e(t('workouts.guide_item_placeholder')) ?>"
    data-move-up-label="<?= e(t('workouts.move_up')) ?>"
    data-move-down-label="<?= e(t('workouts.move_down')) ?>"
    data-remove-label="<?= e(t('workouts.remove_guide_item')) ?>"
>
    <?php foreach ($guideBuilderSections as $guideKey => $guideSection): ?>
        <?php
        $guideValues = wk_exercise_guide_lines($guideBuilderContent[$guideKey] ?? [], $guideBuilderLimit);
        $guideSectionId = $guideBuilderId . '-' . $guideKey;
        ?>
        <details class="workouts-guide-builder-section" data-guide-section data-guide-key="<?= e($guideKey) ?>">
            <summary>
                <span class="workouts-guide-builder-icon" aria-hidden="true"><?= e((string) $guideSection['symbol']) ?></span>
                <span><strong><?= e((string) $guideSection['label']) ?></strong><small><?= e((string) $guideSection['hint']) ?></small></span>
                <em data-guide-count aria-label="<?= e(t('workouts.guide_items_count', ['count' => count($guideValues)])) ?>"><?= count($guideValues) ?></em>
                <b aria-hidden="true">+</b>
            </summary>
            <div class="workouts-guide-builder-body" id="<?= e($guideSectionId) ?>">
                <textarea name="<?= e($guideKey) ?>" data-guide-output hidden><?= e(implode("\n", $guideValues)) ?></textarea>
                <div class="workouts-guide-builder-items" data-guide-items>
                    <?php foreach ($guideValues as $guideIndex => $guideValue): ?>
                        <div class="workouts-guide-builder-item" data-guide-item>
                            <span data-guide-index><?= $guideIndex + 1 ?></span>
                            <label><span class="sr-only"><?= e((string) $guideSection['label']) ?> <?= $guideIndex + 1 ?></span><textarea name="<?= e($guideKey) ?>_items[]" rows="1" maxlength="280" data-guide-item-input placeholder="<?= e(t('workouts.guide_item_placeholder')) ?>"><?= e($guideValue) ?></textarea></label>
                            <div class="workouts-guide-builder-controls">
                                <button type="button" data-guide-move="up" aria-label="<?= e(t('workouts.move_up')) ?>">&uarr;</button>
                                <button type="button" data-guide-move="down" aria-label="<?= e(t('workouts.move_down')) ?>">&darr;</button>
                                <button type="button" data-guide-remove aria-label="<?= e(t('workouts.remove_guide_item')) ?>">&times;</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p class="workouts-guide-builder-empty" data-guide-empty<?= $guideValues !== [] ? ' hidden' : '' ?>><?= e((string) $guideSection['hint']) ?></p>
                <button class="btn btn-ghost workouts-guide-builder-add" type="button" data-guide-add>+ <?= e((string) $guideSection['add']) ?></button>
                <template data-guide-item-template>
                    <div class="workouts-guide-builder-item" data-guide-item>
                        <span data-guide-index></span>
                        <label><span class="sr-only"><?= e((string) $guideSection['label']) ?></span><textarea name="<?= e($guideKey) ?>_items[]" rows="1" maxlength="280" data-guide-item-input placeholder="<?= e(t('workouts.guide_item_placeholder')) ?>"></textarea></label>
                        <div class="workouts-guide-builder-controls">
                            <button type="button" data-guide-move="up" aria-label="<?= e(t('workouts.move_up')) ?>">&uarr;</button>
                            <button type="button" data-guide-move="down" aria-label="<?= e(t('workouts.move_down')) ?>">&darr;</button>
                            <button type="button" data-guide-remove aria-label="<?= e(t('workouts.remove_guide_item')) ?>">&times;</button>
                        </div>
                    </div>
                </template>
            </div>
        </details>
    <?php endforeach; ?>
</div>
