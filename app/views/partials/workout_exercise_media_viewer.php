<?php

declare(strict_types=1);

/**
 * Shared, interaction-ready exercise media viewer.
 *
 * Expected input in $workoutMediaViewer:
 * - id: unique DOM id prefix
 * - images: ordered [{url, position}], with image_url/image_position fallback
 * - video: result of wk_exercise_video_source()
 * - title, mark
 * - default: photo|video
 * - compact, show_header
 */
$mediaViewerConfig = is_array($workoutMediaViewer ?? null) ? $workoutMediaViewer : [];
$mediaViewerImageUrl = trim((string) ($mediaViewerConfig['image_url'] ?? ''));
$mediaViewerImagePosition = wk_image_position_css($mediaViewerConfig['image_position'] ?? 'center');
$mediaViewerImages = [];
foreach ((array) ($mediaViewerConfig['images'] ?? []) as $mediaViewerImage) {
    if (!is_array($mediaViewerImage)) {
        continue;
    }
    $mediaViewerGalleryUrl = trim((string) ($mediaViewerImage['url'] ?? ''));
    if ($mediaViewerGalleryUrl === '') {
        continue;
    }
    $mediaViewerImages[] = [
        'url' => $mediaViewerGalleryUrl,
        'position' => wk_image_position_css($mediaViewerImage['position'] ?? 'center'),
        'caption' => wk_normalize_exercise_media_caption($mediaViewerImage['caption'] ?? ''),
    ];
    if (count($mediaViewerImages) >= 4) {
        break;
    }
}
if ($mediaViewerImages === [] && $mediaViewerImageUrl !== '') {
    $mediaViewerImages[] = ['url' => $mediaViewerImageUrl, 'position' => $mediaViewerImagePosition, 'caption' => ''];
}
$mediaViewerVideo = is_array($mediaViewerConfig['video'] ?? null) ? (array) $mediaViewerConfig['video'] : null;
$mediaViewerHasPhoto = $mediaViewerImages !== [];
$mediaViewerHasVideo = $mediaViewerVideo !== null;

if (!$mediaViewerHasPhoto && !$mediaViewerHasVideo) {
    return;
}

$mediaViewerId = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($mediaViewerConfig['id'] ?? 'exercise-media')) ?: 'exercise-media';
$mediaViewerTitle = trim((string) ($mediaViewerConfig['title'] ?? t('workouts.technique_media')));
$mediaViewerMark = wk_normalize_exercise_mark($mediaViewerConfig['mark'] ?? '');
$mediaViewerMark = $mediaViewerMark !== '' ? $mediaViewerMark : '•';
$mediaViewerDefault = (string) ($mediaViewerConfig['default'] ?? 'photo');
if ($mediaViewerDefault === 'video' && !$mediaViewerHasVideo) {
    $mediaViewerDefault = 'photo';
} elseif ($mediaViewerDefault !== 'video' && !$mediaViewerHasPhoto) {
    $mediaViewerDefault = 'video';
}
$mediaViewerCompact = (bool) ($mediaViewerConfig['compact'] ?? false);
$mediaViewerShowHeader = (bool) ($mediaViewerConfig['show_header'] ?? true);
$mediaViewerHasTabs = $mediaViewerHasPhoto && $mediaViewerHasVideo;
$mediaViewerPhotoPanelId = $mediaViewerId . '-photo';
$mediaViewerVideoPanelId = $mediaViewerId . '-video';
$mediaViewerPhotoTabId = $mediaViewerId . '-photo-tab';
$mediaViewerVideoTabId = $mediaViewerId . '-video-tab';
$mediaViewerProvider = strtolower((string) ($mediaViewerVideo['provider'] ?? 'video'));
$mediaViewerProviderLabel = match ($mediaViewerProvider) {
    'youtube' => 'YouTube',
    'vimeo' => 'Vimeo',
    default => t('workouts.cover_video'),
};
?>
<div class="workouts-exercise-media-viewer<?= $mediaViewerCompact ? ' is-compact' : '' ?>" data-workout-media-viewer data-default-view="<?= e($mediaViewerDefault) ?>">
    <?php if ($mediaViewerShowHeader): ?>
        <header class="workouts-media-viewer-head">
            <div><p class="eyebrow"><?= e(t('workouts.technique_media')) ?></p><h2><?= e($mediaViewerTitle) ?></h2><p><?= e(t('workouts.technique_media_hint')) ?></p></div>
        </header>
    <?php endif; ?>

    <?php if ($mediaViewerHasTabs): ?>
        <div class="workouts-media-tabs" role="tablist" aria-label="<?= e(t('workouts.media_tabs')) ?>">
            <button id="<?= e($mediaViewerPhotoTabId) ?>" type="button" role="tab" aria-selected="<?= $mediaViewerDefault === 'photo' ? 'true' : 'false' ?>" aria-controls="<?= e($mediaViewerPhotoPanelId) ?>" tabindex="<?= $mediaViewerDefault === 'photo' ? '0' : '-1' ?>" data-workout-media-tab="photo"><span aria-hidden="true"><?= activity_icon_svg('image') ?></span><?= e(t('workouts.cover_photo')) ?></button>
            <button id="<?= e($mediaViewerVideoTabId) ?>" type="button" role="tab" aria-selected="<?= $mediaViewerDefault === 'video' ? 'true' : 'false' ?>" aria-controls="<?= e($mediaViewerVideoPanelId) ?>" tabindex="<?= $mediaViewerDefault === 'video' ? '0' : '-1' ?>" data-workout-media-tab="video"><span class="workouts-media-tab-play" aria-hidden="true">&#9654;</span><?= e(t('workouts.cover_video')) ?></button>
        </div>
    <?php endif; ?>

    <div class="workouts-media-stage">
        <?php if ($mediaViewerHasPhoto): ?>
            <figure id="<?= e($mediaViewerPhotoPanelId) ?>" class="workouts-media-panel workouts-media-photo"<?= $mediaViewerHasTabs ? ' role="tabpanel" aria-labelledby="' . e($mediaViewerPhotoTabId) . '"' : '' ?> data-workout-media-panel="photo"<?= $mediaViewerDefault !== 'photo' ? ' hidden' : '' ?>>
                <?php if (count($mediaViewerImages) === 1): ?>
                    <?php $mediaViewerSingleCaption = (string) ($mediaViewerImages[0]['caption'] ?? ''); ?>
                    <img src="<?= e((string) $mediaViewerImages[0]['url']) ?>" alt="<?= e($mediaViewerTitle . ' · ' . ($mediaViewerSingleCaption !== '' ? $mediaViewerSingleCaption : t('workouts.cover_photo'))) ?>" loading="lazy" decoding="async" style="object-position: <?= e((string) $mediaViewerImages[0]['position']) ?>">
                    <?php if ($mediaViewerSingleCaption !== ''): ?><figcaption class="workouts-media-caption"><?= e($mediaViewerSingleCaption) ?></figcaption><?php endif; ?>
                <?php else: ?>
                    <div class="workouts-media-gallery" data-workout-media-gallery>
                        <div class="workouts-media-gallery-stage">
                            <?php foreach ($mediaViewerImages as $mediaViewerImageIndex => $mediaViewerImage): ?>
                                <?php $mediaViewerSlideLabel = (string) ($mediaViewerImage['caption'] ?? '') !== '' ? (string) $mediaViewerImage['caption'] : t('workouts.gallery_photo_number', ['count' => $mediaViewerImageIndex + 1]); ?>
                                <img src="<?= e((string) $mediaViewerImage['url']) ?>" alt="<?= e($mediaViewerTitle . ' · ' . $mediaViewerSlideLabel) ?>" loading="lazy" decoding="async" style="object-position: <?= e((string) $mediaViewerImage['position']) ?>" data-workout-gallery-slide="<?= $mediaViewerImageIndex ?>"<?= $mediaViewerImageIndex > 0 ? ' hidden' : '' ?>>
                                <figcaption class="workouts-media-caption" data-workout-gallery-caption-slide="<?= $mediaViewerImageIndex ?>"<?= $mediaViewerImageIndex > 0 || (string) ($mediaViewerImage['caption'] ?? '') === '' ? ' hidden' : '' ?>><?= e((string) ($mediaViewerImage['caption'] ?? '')) ?></figcaption>
                            <?php endforeach; ?>
                        </div>
                        <div class="workouts-media-gallery-controls">
                            <button type="button" data-workout-gallery-viewer-move="previous" aria-label="<?= e(t('common.previous')) ?>">&larr;</button>
                            <output data-workout-gallery-viewer-status aria-live="polite">1 / <?= count($mediaViewerImages) ?></output>
                            <button type="button" data-workout-gallery-viewer-move="next" aria-label="<?= e(t('common.next')) ?>">&rarr;</button>
                        </div>
                        <div class="workouts-media-gallery-thumbs" aria-label="<?= e(t('workouts.photo_gallery')) ?>">
                            <?php foreach ($mediaViewerImages as $mediaViewerImageIndex => $mediaViewerImage): ?>
                                <?php $mediaViewerThumbLabel = (string) ($mediaViewerImage['caption'] ?? '') !== '' ? (string) $mediaViewerImage['caption'] : t('workouts.gallery_photo_number', ['count' => $mediaViewerImageIndex + 1]); ?>
                                <button type="button" data-workout-gallery-viewer-thumb="<?= $mediaViewerImageIndex ?>" aria-pressed="<?= $mediaViewerImageIndex === 0 ? 'true' : 'false' ?>" aria-label="<?= e($mediaViewerThumbLabel) ?>"><img src="<?= e((string) $mediaViewerImage['url']) ?>" alt="" loading="lazy" decoding="async" style="object-position: <?= e((string) $mediaViewerImage['position']) ?>"></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </figure>
        <?php endif; ?>

        <?php if ($mediaViewerHasVideo): ?>
            <div id="<?= e($mediaViewerVideoPanelId) ?>" class="workouts-media-panel workouts-guide-video"<?= $mediaViewerHasTabs ? ' role="tabpanel" aria-labelledby="' . e($mediaViewerVideoTabId) . '"' : '' ?> data-workout-media-panel="video"<?= $mediaViewerDefault !== 'video' ? ' hidden' : '' ?>>
                <?php if (($mediaViewerVideo['type'] ?? '') === 'iframe'): ?>
                    <div class="workouts-media-lazy-video<?= trim((string) ($mediaViewerVideo['thumbnail_url'] ?? '')) !== '' ? ' has-thumbnail' : '' ?>" data-workout-lazy-video data-video-src="<?= e((string) $mediaViewerVideo['url']) ?>" data-video-title="<?= e($mediaViewerTitle . ' · ' . $mediaViewerProviderLabel) ?>" data-loaded-label="<?= e(t('workouts.video_loaded')) ?>">
                        <?php if (trim((string) ($mediaViewerVideo['thumbnail_url'] ?? '')) !== ''): ?><img src="<?= e((string) $mediaViewerVideo['thumbnail_url']) ?>" alt="" loading="lazy" decoding="async"><?php else: ?><span class="workouts-media-video-mark" aria-hidden="true"><?= e($mediaViewerMark) ?></span><?php endif; ?>
                        <button type="button" data-workout-video-load aria-label="<?= e(t('workouts.play_video') . ': ' . $mediaViewerTitle) ?>"><span aria-hidden="true">&#9654;</span><strong><?= e(t('workouts.play_video')) ?></strong><small><?= e($mediaViewerProviderLabel) ?></small></button>
                        <span class="sr-only" data-workout-video-load-status aria-live="polite"></span>
                    </div>
                <?php elseif (($mediaViewerVideo['type'] ?? '') === 'video'): ?>
                    <video src="<?= e((string) $mediaViewerVideo['url']) ?>" controls preload="metadata" playsinline aria-label="<?= e($mediaViewerTitle . ' · ' . t('workouts.cover_video')) ?>"></video>
                <?php else: ?>
                    <a class="workouts-guide-video-link" href="<?= e((string) $mediaViewerVideo['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e(t('workouts.view_guide')) ?> &nearr;</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
