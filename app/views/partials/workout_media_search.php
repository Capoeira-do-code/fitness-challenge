<?php

declare(strict_types=1);

$mediaSearchKind = ($workoutMediaSearchType ?? '') === 'video' ? 'video' : 'image';
$mediaSearchProvider = $mediaSearchKind === 'video' ? 'YouTube' : t('workouts.media_search_google_provider');
$mediaSearchId = 'workout-media-search-' . $mediaSearchKind . '-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($workoutMediaSearchId ?? 'exercise'));
?>

<section
    class="workouts-media-search is-<?= e($mediaSearchKind) ?>"
    data-workout-media-search
    data-media-search-type="<?= e($mediaSearchKind) ?>"
    data-media-search-endpoint="/?page=api_workout_media_search"
    data-media-import-endpoint="/?page=api_workout_media_import"
    data-media-csrf="<?= e(csrf_token()) ?>"
    data-media-searching-label="<?= e(t('workouts.media_search_searching')) ?>"
    data-media-query-invalid-label="<?= e(t('workouts.media_search_query_invalid')) ?>"
    data-media-empty-label="<?= e(t('workouts.media_search_empty')) ?>"
    data-media-error-label="<?= e(t('workouts.media_search_error')) ?>"
    data-media-select-label="<?= e(t('workouts.media_search_select')) ?>"
    data-media-importing-label="<?= e(t('workouts.media_search_importing')) ?>"
    data-media-selected-label="<?= e($mediaSearchKind === 'video' ? t('workouts.media_search_video_selected') : t('workouts.media_search_image_selected')) ?>"
    data-media-chosen-label="<?= e(t('workouts.media_search_chosen')) ?>"
    data-media-results-template="<?= e(t('workouts.media_search_results', ['count' => '{count}', 'query' => '{query}'])) ?>"
    data-media-source-label="<?= e(t('workouts.media_search_source')) ?>"
    data-media-limit-label="<?= e(t('workouts.gallery_limit')) ?>"
    data-media-unsupported-label="<?= e(t('workouts.media_search_import_unsupported')) ?>"
>
    <button class="workouts-media-search-trigger" type="button" data-workout-media-search-toggle aria-expanded="false" aria-controls="<?= e($mediaSearchId) ?>">
        <span class="workouts-media-search-brand" aria-hidden="true"><?= $mediaSearchKind === 'video' ? '&#9654;' : 'G' ?></span>
        <span><strong><?= e(t($mediaSearchKind === 'video' ? 'workouts.media_search_youtube' : 'workouts.media_search_google')) ?></strong><small><?= e(t($mediaSearchKind === 'video' ? 'workouts.media_search_youtube_hint' : 'workouts.media_search_google_hint')) ?></small></span>
        <b aria-hidden="true">+</b>
    </button>
    <div id="<?= e($mediaSearchId) ?>" class="workouts-media-search-panel" data-workout-media-search-panel hidden>
        <div class="workouts-media-search-bar">
            <label class="sr-only" for="<?= e($mediaSearchId) ?>-query"><?= e(t('workouts.media_search_query')) ?></label>
            <input id="<?= e($mediaSearchId) ?>-query" type="search" maxlength="80" autocomplete="off" enterkeyhint="search" placeholder="<?= e(t($mediaSearchKind === 'video' ? 'workouts.media_search_video_placeholder' : 'workouts.media_search_image_placeholder')) ?>" data-workout-media-search-input>
            <button class="btn btn-primary" type="button" data-workout-media-search-submit aria-label="<?= e(t('workouts.media_search_action')) ?>"><?= activity_icon_svg('search') ?><span><?= e(t('workouts.media_search_action')) ?></span></button>
        </div>
        <p class="workouts-media-search-status" data-workout-media-search-status aria-live="polite"><?= e(t('workouts.media_search_initial_hint', ['provider' => $mediaSearchProvider])) ?></p>
        <div class="workouts-media-search-results" data-workout-media-search-results role="list"></div>
        <p class="workouts-media-search-usage"><?= e(t('workouts.media_search_usage_hint', ['provider' => $mediaSearchProvider])) ?></p>
    </div>
</section>
