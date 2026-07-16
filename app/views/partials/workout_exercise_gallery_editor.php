<?php

declare(strict_types=1);

$galleryExercise = is_array($workoutGalleryExercise ?? null) ? (array) $workoutGalleryExercise : [];
$galleryItems = is_array($workoutGalleryItems ?? null) ? array_slice((array) $workoutGalleryItems, 0, 4) : [];
$galleryId = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($workoutGalleryId ?? 'exercise-gallery')) ?: 'exercise-gallery';
$galleryCoverPath = trim((string) ($galleryExercise['image_path'] ?? ''));
$galleryPosition = wk_normalize_image_position($galleryExercise['image_position'] ?? 'center');
$galleryCount = count($galleryItems);
$galleryHasCover = false;
$galleryEditingIndex = 0;
foreach ($galleryItems as $galleryIndex => $galleryItem) {
    if ((string) ($galleryItem['path'] ?? '') === $galleryCoverPath) {
        $galleryHasCover = true;
        $galleryEditingIndex = (int) $galleryIndex;
        break;
    }
}
?>

<details
    class="workouts-custom-media-details workouts-exercise-gallery-editor<?= $galleryItems !== [] ? ' has-media' : '' ?>"
    data-workout-photo-details
    data-workout-gallery-editor
    data-gallery-limit="4"
    data-gallery-cover-label="<?= e(t('workouts.gallery_cover')) ?>"
    data-gallery-photo-label="<?= e(t('workouts.gallery_photo')) ?>"
    data-gallery-caption-label="<?= e(t('workouts.gallery_caption')) ?>"
    data-gallery-remove-label="<?= e(t('workouts.remove_gallery_photo')) ?>"
    data-gallery-adjust-label="<?= e(t('workouts.adjust_photo_number', ['count' => '{count}'])) ?>"
    data-gallery-selected-template="<?= e(t('workouts.gallery_selected', ['count' => '{count}'])) ?>"
    data-gallery-count-template="<?= e(t('workouts.gallery_count', ['count' => '{count}', 'limit' => 4])) ?>"
    data-gallery-focal-template="<?= e(t('workouts.focal_coordinates', ['x' => '{x}', 'y' => '{y}'])) ?>"
    data-gallery-focal-label="<?= e(t('workouts.focal_tap_hint')) ?>"
>
    <summary>
        <span aria-hidden="true"><?= activity_icon_svg('image') ?></span>
        <span><strong><?= e(t('workouts.photo_gallery')) ?></strong><small><?= e(t('workouts.photo_gallery_hint')) ?></small></span>
        <em data-workout-gallery-status aria-live="polite"><?= e(t('workouts.gallery_count', ['count' => $galleryCount, 'limit' => 4])) ?></em>
        <b aria-hidden="true">+</b>
    </summary>
    <div class="workouts-custom-media-details-body workouts-gallery-editor-body">
        <input type="hidden" name="gallery_editor" value="1">

        <div class="workouts-gallery-upload">
            <label for="<?= e($galleryId) ?>-input"><span aria-hidden="true">+</span><strong><?= e(t('workouts.add_gallery_photos')) ?></strong><small><?= e(t('workouts.add_gallery_photos_hint')) ?></small></label>
            <input id="<?= e($galleryId) ?>-input" type="file" name="exercise_images[]" accept="image/jpeg,image/png,image/webp" multiple data-workout-gallery-input>
        </div>

        <div class="workouts-gallery-list" data-workout-gallery-list>
            <?php foreach ($galleryItems as $index => $galleryItem): ?>
                <?php
                $galleryPath = trim((string) ($galleryItem['path'] ?? ''));
                $galleryIsCover = $galleryPath !== '' && ($galleryPath === $galleryCoverPath || (!$galleryHasCover && $index === 0));
                $galleryIsEditing = $index === $galleryEditingIndex;
                ?>
                <article class="workouts-gallery-item<?= $galleryIsCover ? ' is-cover' : '' ?><?= $galleryIsEditing ? ' is-editing' : '' ?>" data-workout-gallery-item data-gallery-token="<?= e($galleryPath) ?>">
                    <input type="hidden" name="gallery_order[]" value="<?= e($galleryPath) ?>" data-workout-gallery-order>
                    <input type="hidden" name="gallery_position[]" value="<?= e(wk_normalize_image_position($galleryItem['position'] ?? $galleryPosition)) ?>" data-workout-gallery-position>
                    <figure data-photo-number="<?= $index + 1 ?>"><img src="<?= e(media_url($galleryPath)) ?>" alt="<?= e((string) ($galleryItem['caption'] ?? '') !== '' ? (string) $galleryItem['caption'] : t('workouts.gallery_photo_number', ['count' => $index + 1])) ?>" loading="lazy" decoding="async" data-workout-gallery-image style="object-position: <?= e(wk_image_position_css($galleryItem['position'] ?? $galleryPosition)) ?>"></figure>
                    <div class="workouts-gallery-item-meta">
                        <label class="workouts-gallery-caption"><span><?= e(t('workouts.gallery_caption')) ?></span><input type="text" name="gallery_caption[]" value="<?= e((string) ($galleryItem['caption'] ?? '')) ?>" maxlength="120" autocomplete="off" placeholder="<?= e(t('workouts.gallery_caption_placeholder')) ?>" data-workout-gallery-caption></label>
                        <label class="workouts-gallery-cover-control"><input type="radio" name="gallery_cover" value="<?= e($galleryPath) ?>" data-workout-gallery-cover<?= $galleryIsCover ? ' checked' : '' ?>><span><b aria-hidden="true">&#9733;</b><strong><?= e(t('workouts.gallery_cover')) ?></strong></span></label>
                        <div class="workouts-gallery-item-controls">
                            <button type="button" data-workout-gallery-focus aria-pressed="<?= $galleryIsEditing ? 'true' : 'false' ?>" aria-label="<?= e(t('workouts.adjust_photo_number', ['count' => $index + 1])) ?>"><span aria-hidden="true">&#9678;</span><span class="workouts-gallery-focus-copy"><?= e(t('workouts.adjust_photo_short')) ?></span></button>
                            <button type="button" data-workout-gallery-move="up" aria-label="<?= e(t('workouts.move_photo_up')) ?>">&uarr;</button>
                            <button type="button" data-workout-gallery-move="down" aria-label="<?= e(t('workouts.move_photo_down')) ?>">&darr;</button>
                            <button type="button" data-workout-gallery-remove aria-label="<?= e(t('workouts.remove_gallery_photo')) ?>">&times;</button>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <p class="workouts-gallery-empty" data-workout-gallery-empty<?= $galleryItems !== [] ? ' hidden' : '' ?>><?= e(t('workouts.gallery_empty')) ?></p>

        <fieldset class="workouts-image-focus-picker" data-workout-image-position-picker>
            <legend><?= e(t('workouts.image_focus')) ?></legend>
            <p id="<?= e($galleryId) ?>-focal-hint"><?= e(t('workouts.gallery_focus_hint')) ?></p>
            <output data-workout-gallery-focus-status aria-live="polite"<?= $galleryItems === [] ? ' hidden' : '' ?>><?= $galleryItems !== [] ? e(t('workouts.gallery_selected', ['count' => $galleryEditingIndex + 1])) : '' ?></output>
            <div class="workouts-image-focal-editor" data-workout-gallery-focal-editor hidden>
                <div class="workouts-image-focal-stage">
                    <img src="" alt="" data-workout-gallery-focal-preview>
                    <button type="button" data-workout-gallery-focal-surface aria-label="<?= e(t('workouts.focal_tap_hint')) ?>" aria-describedby="<?= e($galleryId) ?>-focal-hint"><span data-workout-gallery-focal-marker aria-hidden="true"></span></button>
                </div>
                <div class="workouts-image-focal-controls">
                    <p><?= e(t('workouts.focal_tap_hint')) ?></p>
                    <label><span><?= e(t('workouts.focal_horizontal')) ?><output data-workout-gallery-focal-x-output>50%</output></span><input type="range" min="0" max="100" step="1" value="50" data-workout-gallery-focal-x></label>
                    <label><span><?= e(t('workouts.focal_vertical')) ?><output data-workout-gallery-focal-y-output>50%</output></span><input type="range" min="0" max="100" step="1" value="50" data-workout-gallery-focal-y></label>
                    <output class="workouts-image-focal-value" data-workout-gallery-focal-value aria-live="polite"><?= e(t('workouts.focal_coordinates', ['x' => 50, 'y' => 50])) ?></output>
                </div>
            </div>
            <div class="workouts-image-focus-presets">
                <?php foreach ([
                    'top' => ['&#8593;', 'workouts.focus_top'],
                    'left' => ['&#8592;', 'workouts.focus_left'],
                    'center' => ['&#9679;', 'workouts.focus_center'],
                    'right' => ['&#8594;', 'workouts.focus_right'],
                    'bottom' => ['&#8595;', 'workouts.focus_bottom'],
                ] as $position => [$positionIcon, $positionLabelKey]): ?>
                    <label><input type="radio" name="image_position" value="<?= e($position) ?>" data-workout-image-position-input<?= $galleryPosition === $position ? ' checked' : '' ?>><span><b aria-hidden="true"><?= $positionIcon ?></b><strong><?= e(t($positionLabelKey)) ?></strong></span></label>
                <?php endforeach; ?>
            </div>
        </fieldset>

        <template data-workout-gallery-template>
            <article class="workouts-gallery-item" data-workout-gallery-item data-gallery-token="">
                <input type="hidden" name="gallery_order[]" value="" data-workout-gallery-order>
                <input type="hidden" name="gallery_position[]" value="center" data-workout-gallery-position>
                <figure data-photo-number=""><img src="" alt="" data-workout-gallery-image></figure>
                <div class="workouts-gallery-item-meta">
                    <label class="workouts-gallery-caption"><span><?= e(t('workouts.gallery_caption')) ?></span><input type="text" name="gallery_caption[]" value="" maxlength="120" autocomplete="off" placeholder="<?= e(t('workouts.gallery_caption_placeholder')) ?>" data-workout-gallery-caption></label>
                    <label class="workouts-gallery-cover-control"><input type="radio" name="gallery_cover" value="" data-workout-gallery-cover><span><b aria-hidden="true">&#9733;</b><strong><?= e(t('workouts.gallery_cover')) ?></strong></span></label>
                    <div class="workouts-gallery-item-controls">
                        <button type="button" data-workout-gallery-focus aria-pressed="false" aria-label="<?= e(t('workouts.adjust_photo')) ?>"><span aria-hidden="true">&#9678;</span><span class="workouts-gallery-focus-copy"><?= e(t('workouts.adjust_photo_short')) ?></span></button>
                        <button type="button" data-workout-gallery-move="up" aria-label="<?= e(t('workouts.move_photo_up')) ?>">&uarr;</button>
                        <button type="button" data-workout-gallery-move="down" aria-label="<?= e(t('workouts.move_photo_down')) ?>">&darr;</button>
                        <button type="button" data-workout-gallery-remove aria-label="<?= e(t('workouts.remove_gallery_photo')) ?>">&times;</button>
                    </div>
                </div>
            </article>
        </template>
    </div>
</details>
