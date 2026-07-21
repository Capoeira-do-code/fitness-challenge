<?php

declare(strict_types=1);

$libraryToolbarVariant = in_array((string) ($libraryToolbarVariant ?? ''), ['desktop', 'mobile'], true)
    ? (string) $libraryToolbarVariant
    : 'desktop';
?>
<div class="workouts-library-actions workouts-library-toolbar is-<?= e($libraryToolbarVariant) ?>">
    <?php if ($libraryMode === 'organize'): ?>
        <a class="btn btn-primary small" href="<?= e($libraryUrl(['scope' => 'favorites', 'library_mode' => null, 'library_page' => null, 'q' => null, 'muscle' => null, 'equipment' => null])) ?>"><?= e(t('workouts.finish_adding')) ?></a>
    <?php else: ?>
        <?php if (!$hasLibraryTarget): ?>
        <form method="post" action="/?page=workouts" class="workouts-library-layout-switch" data-library-layout-switch>
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="library_layout_update">
            <input type="hidden" name="muscle" value="<?= e((string) ($filters['muscle'] ?? '')) ?>">
            <input type="hidden" name="equipment" value="<?= e((string) ($filters['equipment'] ?? '')) ?>">
            <input type="hidden" name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>">
            <input type="hidden" name="scope" value="<?= e($libraryScope) ?>">
            <input type="hidden" name="library_page" value="<?= (int) ($wkLibraryPage ?? 1) ?>">
            <span class="workouts-library-layout-options" role="group" aria-label="<?= e(t('workouts.library_layout')) ?>">
                <button type="submit" name="library_layout" value="cards" class="<?= $libraryLayout === 'cards' ? 'is-active' : '' ?>" aria-pressed="<?= $libraryLayout === 'cards' ? 'true' : 'false' ?>" aria-label="<?= e(t('workouts.layout_cards')) ?>" title="<?= e(t('workouts.layout_cards_hint')) ?>"><?= activity_icon_svg('grid') ?></button>
                <button type="submit" name="library_layout" value="compact" class="<?= $libraryLayout === 'compact' ? 'is-active' : '' ?>" aria-pressed="<?= $libraryLayout === 'compact' ? 'true' : 'false' ?>" aria-label="<?= e(t('workouts.layout_compact')) ?>" title="<?= e(t('workouts.layout_compact_hint')) ?>"><?= activity_icon_svg('list') ?></button>
            </span>
        </form>
        <?php endif; ?>
        <button class="workouts-library-tool<?= $hasLibrarySearch ? ' is-active' : '' ?>" type="button" data-workout-search-toggle aria-expanded="<?= $hasLibrarySearch ? 'true' : 'false' ?>" aria-controls="workouts-library-search" aria-label="<?= e(t('workouts.search_exercises')) ?>" title="<?= e(t('workouts.search_exercises')) ?>"><?= activity_icon_svg('search') ?></button>
        <a class="workouts-library-tool is-primary" href="<?= e($customExerciseUrl) ?>" aria-label="<?= e(t('workouts.create_custom')) ?>" title="<?= e(t('workouts.create_custom')) ?>"><?= activity_icon_svg('plus') ?></a>
        <button class="workouts-library-tool workouts-filter-open<?= $hasActiveLibraryFilters ? ' is-active' : '' ?>" type="button" data-workout-filter-open aria-expanded="false" aria-controls="workouts-library-filters" aria-label="<?= e(t('common.filter')) ?>" title="<?= e(t('common.filter')) ?>"><?= activity_icon_svg('sliders') ?><?php if ($hasActiveLibraryFilters): ?><span class="workouts-library-tool-dot" aria-hidden="true"></span><?php endif; ?></button>
    <?php endif; ?>
</div>
