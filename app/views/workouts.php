<?php

declare(strict_types=1);

$wkView = (string) ($wkView ?? 'list');
$csrf = csrf_token();
$exercises = (array) ($wkExercises ?? []);
$personalExerciseCount = count(array_filter($exercises, static fn(array $exercise): bool => (int) ($exercise['is_system'] ?? 0) === 0));
$favoriteExerciseCount = count(array_filter($exercises, static fn(array $exercise): bool => (int) ($exercise['is_favorite'] ?? 0) === 1));
$exerciseTypeLabel = static function (string $type): string {
    return t('workouts.type_' . (in_array($type, ['strength', 'cardio', 'isometric', 'bodyweight', 'freeform'], true) ? $type : 'strength'));
};
$muscleLabel = static fn(string $m): string => $m !== '' ? t('workouts.muscle_' . $m) : '';
$equipmentLabel = static fn(string $equipment): string => $equipment !== '' ? t('workouts.equipment_' . $equipment) : '';
$difficultyLabel = static fn(string $difficulty): string => t('workouts.difficulty_' . (in_array($difficulty, ['beginner', 'intermediate', 'advanced', 'custom'], true) ? $difficulty : 'beginner'));
$rankLabel = static fn(string $rank): string => t('workouts.rank_' . (array_key_exists($rank, wk_rank_tiers()) ? $rank : 'unranked'));
$dayLabel = static fn(string $day): string => t('workouts.day_' . $day);
$routineIconOptions = wk_routine_icon_options();
$routineColorOptions = wk_routine_color_options();
$workoutVideoSource = static fn(?string $rawUrl): ?array => wk_exercise_video_source($rawUrl);
$workoutCoverAsset = static function (array $exercise) use ($workoutVideoSource): ?array {
    $mode = wk_normalize_exercise_cover_mode($exercise['cover_mode'] ?? 'auto');
    if ($mode === 'simple') {
        return null;
    }
    $imagePath = trim((string) ($exercise['image_path'] ?? ''));
    $video = $workoutVideoSource((string) ($exercise['video_url'] ?? ''));
    $videoThumbnail = trim((string) ($video['thumbnail_url'] ?? ''));
    if ($imagePath !== '' && in_array($mode, ['auto', 'photo'], true)) {
        return [
            'kind' => 'photo',
            'url' => media_url($imagePath),
            'has_video' => $video !== null,
            'position' => wk_image_position_css($exercise['image_position'] ?? 'center'),
        ];
    }
    if ($videoThumbnail !== '' && in_array($mode, ['auto', 'video'], true)) {
        return ['kind' => 'video', 'url' => $videoThumbnail, 'has_video' => true, 'position' => '50% 50%'];
    }

    return null;
};
$workoutCoverPosition = static fn(?array $cover): string => (string) ($cover['position'] ?? '50% 50%');
$workoutExerciseAccent = static fn(array $exercise): string => wk_normalize_exercise_color(
    $exercise['accent_color'] ?? wk_exercise_default_color($exercise['muscle_group'] ?? '')
);
$workoutExerciseStyle = static fn(array $exercise): string => '--exercise-accent: ' . $workoutExerciseAccent($exercise);
$workoutExerciseMark = static fn(array $exercise): string => wk_exercise_visual_mark($exercise);
$hubViews = ['list', 'plan', 'library', 'ranks', 'analytics'];
$activeRoutines = array_values(array_filter((array) ($wkRoutines ?? []), static fn($r) => (int) ($r['is_archived'] ?? 0) === 0));
$targetRoutine = is_array($wkTargetRoutine ?? null) ? (array) $wkTargetRoutine : [];
$targetRoutineId = (int) ($targetRoutine['id'] ?? 0);
$targetRoutineExerciseIds = array_map('intval', (array) ($wkTargetRoutineExerciseIds ?? []));
$targetSession = is_array($wkTargetSession ?? null) ? (array) $wkTargetSession : [];
$targetSessionId = (int) ($targetSession['id'] ?? 0);
$targetSessionExerciseIds = array_map('intval', (array) ($wkTargetSessionExerciseIds ?? []));
$hasLibraryTarget = $targetRoutineId > 0 || $targetSessionId > 0;
$libraryTargetExerciseIds = $targetSessionId > 0 ? $targetSessionExerciseIds : $targetRoutineExerciseIds;
$routineSection = in_array((string) ($wkRoutineSection ?? 'overview'), ['overview', 'settings', 'organize'], true)
    ? (string) ($wkRoutineSection ?? 'overview')
    : 'overview';
$sessionSection = (string) ($wkSessionSection ?? 'workout') === 'organize' ? 'organize' : 'workout';
$libraryReturnQuery = ['page' => 'workouts', 'view' => 'library'];
foreach ((array) ($wkLibraryFilters ?? []) as $filterKey => $filterValue) {
    if ((string) $filterValue !== '') {
        $libraryReturnQuery[(string) $filterKey] = (string) $filterValue;
    }
}
if ((int) ($wkLibraryPage ?? 1) > 1) {
    $libraryReturnQuery['library_page'] = (int) $wkLibraryPage;
}
if ($targetRoutineId > 0) {
    $libraryReturnQuery['target_routine_id'] = $targetRoutineId;
} elseif ($targetSessionId > 0) {
    $libraryReturnQuery['target_session_id'] = $targetSessionId;
}
$workoutLibraryReturnUrl = '/?' . http_build_query($libraryReturnQuery);
?>
<section class="screen stack-lg workouts-screen">
    <?php if ($wkView !== 'custom_exercise'): ?>
    <div class="hero-panel workouts-hero<?= in_array($wkView, $hubViews, true) ? ' workouts-hero-hub' : '' ?>">
        <div class="hero-copy hero-copy-page-title">
            <p class="eyebrow"><?= e(t('nav.table')) ?></p>
            <h1><?= e($wkView === 'organize' ? t('workouts.organize_routines') : t('workouts.title')) ?></h1>
            <p class="muted"><?= e($wkView === 'organize' ? t('workouts.organize_routines_hint') : t('workouts.subtitle')) ?></p>
        </div>
        <?php if (!in_array($wkView, $hubViews, true)): ?>
            <?php $workoutBack = $wkView === 'exercise'
                ? $workoutLibraryReturnUrl
                : ($wkView === 'routine'
                    ? (in_array($routineSection, ['settings', 'organize'], true) && !empty($wkRoutine) ? '/?page=workouts&routine_id=' . (int) $wkRoutine['id'] : '/?page=workouts&view=plan')
                    : ($wkView === 'session' && $sessionSection === 'organize' && !empty($wkSession)
                        ? '/?page=workouts&session_id=' . (int) $wkSession['id']
                        : ($wkView === 'routine_exercise' && !empty($wkRoutine)
                            ? '/?page=workouts&routine_id=' . (int) $wkRoutine['id']
                            : ($wkView === 'custom_exercise' ? $workoutLibraryReturnUrl : '/?page=workouts')))); ?>
            <a class="btn btn-ghost small" href="<?= e($workoutBack) ?>">← <?= e(t('common.back')) ?></a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (in_array($wkView, $hubViews, true)): ?>
        <?php $tabView = $wkView === 'analytics' ? 'stats' : ($wkView === 'list' ? 'overview' : $wkView); ?>
        <nav class="workouts-hub-tabs workouts-hub-tabs-desktop" aria-label="<?= e(t('workouts.title')) ?>" data-workouts-tabs>
            <?php foreach ([
                'overview' => t('workouts.tab_overview'),
                'plan' => t('workouts.tab_plan'),
                'library' => t('workouts.tab_library'),
                'ranks' => t('workouts.tab_ranks'),
                'stats' => t('workouts.stats'),
            ] as $viewKey => $viewLabel): ?>
                <a href="/?page=workouts<?= $viewKey !== 'overview' ? '&view=' . e($viewKey) : '' ?>"<?= $tabView === $viewKey ? ' aria-current="page"' : '' ?>><?= e($viewLabel) ?></a>
            <?php endforeach; ?>
            <a href="/?page=week_editor&range=week"><?= e(t('workouts.challenge_log')) ?></a>
        </nav>
        <?php if ($wkView === 'list'): ?>
            <nav class="workouts-mobile-navigation hierarchy-nav-list" aria-label="<?= e(t('workouts.title')) ?>">
                <a class="hierarchy-nav-row" href="/?page=workouts&view=plan"><span class="hierarchy-nav-icon" aria-hidden="true">7</span><span class="hierarchy-nav-copy"><strong><?= e(t('workouts.tab_plan')) ?></strong><small><?= e(t('workouts.plan_subtitle')) ?></small></span><span class="hierarchy-nav-chevron" aria-hidden="true">&rsaquo;</span></a>
                <a class="hierarchy-nav-row" href="/?page=workouts&view=library"><span class="hierarchy-nav-icon" aria-hidden="true">&#9876;</span><span class="hierarchy-nav-copy"><strong><?= e(t('workouts.tab_library')) ?></strong><small><?= e(t('workouts.library_subtitle')) ?></small></span><span class="hierarchy-nav-chevron" aria-hidden="true">&rsaquo;</span></a>
                <a class="hierarchy-nav-row" href="/?page=workouts&view=ranks"><span class="hierarchy-nav-icon" aria-hidden="true">#</span><span class="hierarchy-nav-copy"><strong><?= e(t('workouts.tab_ranks')) ?></strong><small><?= e(t('workouts.rank_subtitle')) ?></small></span><span class="hierarchy-nav-chevron" aria-hidden="true">&rsaquo;</span></a>
                <a class="hierarchy-nav-row" href="/?page=workouts&view=stats"><span class="hierarchy-nav-icon" aria-hidden="true">&#8645;</span><span class="hierarchy-nav-copy"><strong><?= e(t('workouts.stats')) ?></strong><small><?= e(t('workouts.stats_subtitle')) ?></small></span><span class="hierarchy-nav-chevron" aria-hidden="true">&rsaquo;</span></a>
                <a class="hierarchy-nav-row" href="/?page=week_editor&range=week"><span class="hierarchy-nav-icon" aria-hidden="true">&#10003;</span><span class="hierarchy-nav-copy"><strong><?= e(t('workouts.challenge_log')) ?></strong><small><?= e(t('workouts.challenge_log_hint')) ?></small></span><span class="hierarchy-nav-chevron" aria-hidden="true">&rsaquo;</span></a>
            </nav>
        <?php else: ?>
            <header class="workouts-mobile-subheader hierarchy-page-header">
                <button class="hierarchy-back" type="button" data-hierarchy-back data-fallback="/?page=workouts" aria-label="<?= e(t('common.back')) ?>">&larr;</button>
                <div><p class="eyebrow"><?= e(t('workouts.title')) ?></p><h1><?= e($tabView === 'stats' ? t('workouts.stats') : t('workouts.tab_' . $tabView)) ?></h1></div>
            </header>
        <?php endif; ?>
    <?php endif; ?>

<?php if ($wkView === 'organize'): ?>
    <?php
    $routineOrganizerGroups = [
        [
            'key' => 'favorites',
            'title' => t('workouts.favorite_routines'),
            'hint' => t('workouts.favorite_routines_hint'),
            'routines' => array_values(array_filter($activeRoutines, static fn(array $routine): bool => (int) ($routine['is_favorite'] ?? 0) === 1)),
        ],
        [
            'key' => 'others',
            'title' => t('workouts.other_routines'),
            'hint' => t('workouts.other_routines_hint'),
            'routines' => array_values(array_filter($activeRoutines, static fn(array $routine): bool => (int) ($routine['is_favorite'] ?? 0) !== 1)),
        ],
    ];
    ?>
    <article class="panel workouts-routine-library-organizer">
        <div class="panel-head workouts-routine-library-organizer-head">
            <div><p class="eyebrow"><?= count($activeRoutines) ?> <?= e(t('workouts.routines')) ?></p><h2><?= e(t('workouts.your_routines')) ?></h2></div>
        </div>
        <?php if ($activeRoutines === []): ?>
            <div class="empty-state"><span class="empty-state-icon"><?= activity_icon_svg('dumbbell') ?></span><p class="muted"><?= e(t('workouts.no_routines')) ?></p></div>
        <?php else: ?>
            <div class="workouts-routine-library-groups">
                <?php foreach ($routineOrganizerGroups as $routineOrganizerGroup): ?>
                    <?php $groupRoutines = (array) $routineOrganizerGroup['routines']; if ($groupRoutines === []) { continue; } ?>
                    <section class="workouts-routine-order-group" data-routine-order-group="<?= e((string) $routineOrganizerGroup['key']) ?>">
                        <header><span aria-hidden="true"><?= (string) $routineOrganizerGroup['key'] === 'favorites' ? '&#9733;' : activity_icon_svg('dumbbell') ?></span><div><h3><?= e((string) $routineOrganizerGroup['title']) ?></h3><p><?= e((string) $routineOrganizerGroup['hint']) ?></p></div><b><?= count($groupRoutines) ?></b></header>
                        <form method="post" action="/?page=workouts" data-exercise-organizer data-routine-library-organizer>
                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                            <input type="hidden" name="action" value="routine_reorder">
                            <input type="hidden" name="return_to" value="organize">
                            <ol class="workouts-routine-organizer-list workouts-routine-library-organizer-list" data-exercise-organizer-list data-routine-library-list>
                                <?php foreach ($groupRoutines as $routineIndex => $routine): ?>
                                    <?php
                                    $organizeRoutineId = (int) ($routine['id'] ?? 0);
                                    $organizeRoutineName = (string) ($routine['name'] ?? '');
                                    $organizeRoutineCover = $workoutCoverAsset((array) $routine);
                                    $organizeRoutineIcon = wk_normalize_routine_icon($routine['icon'] ?? 'dumbbell');
                                    $organizeRoutineAccent = wk_normalize_routine_color($routine['accent_color'] ?? '#14b8a6');
                                    $organizeRoutineDays = wk_days_from_mask((string) ($routine['recommended_days_mask'] ?? '0000000'));
                                    $organizeRoutineExerciseCount = (int) ($routine['exercise_count'] ?? 0);
                                    $organizeRoutineMeta = t($organizeRoutineExerciseCount === 1 ? 'workouts.exercise_count_one' : 'workouts.exercise_count', ['count' => $organizeRoutineExerciseCount]);
                                    if ($organizeRoutineDays !== []) {
                                        $organizeRoutineMeta .= ' · ' . implode(', ', array_map($dayLabel, $organizeRoutineDays));
                                    }
                                    ?>
                                    <li class="workouts-routine-organizer-item workouts-routine-library-organizer-item" style="--routine-accent: <?= e($organizeRoutineAccent) ?>" data-exercise-organizer-item data-routine-library-item data-exercise-name="<?= e($organizeRoutineName) ?>">
                                        <input type="hidden" name="order[]" value="<?= $organizeRoutineId ?>">
                                        <span class="workouts-routine-organizer-position" aria-label="<?= e(t('workouts.position')) ?>"><span data-exercise-organizer-position data-routine-library-position><?= $routineIndex + 1 ?></span></span>
                                        <span class="workouts-exercise-cover workouts-routine-order-cover<?= $organizeRoutineCover !== null ? ' has-media' : '' ?>" aria-hidden="true"><?php if ($organizeRoutineCover !== null): ?><img src="<?= e((string) $organizeRoutineCover['url']) ?>" alt="" style="object-position: <?= e($workoutCoverPosition($organizeRoutineCover)) ?>"><?php else: ?><?= activity_icon_svg($organizeRoutineIcon) ?><?php endif; ?><?php if (!empty($organizeRoutineCover['has_video'])): ?><b>&#9654;</b><?php endif; ?></span>
                                        <a class="workouts-routine-organizer-copy workouts-routine-order-copy" href="/?page=workouts&routine_id=<?= $organizeRoutineId ?>"><strong><?= e($organizeRoutineName) ?><?php if ((int) ($routine['is_favorite'] ?? 0) === 1): ?> <span aria-label="<?= e(t('workouts.favorite')) ?>">&#9733;</span><?php endif; ?></strong><small><?= e($organizeRoutineMeta) ?></small></a>
                                        <?php if (count($groupRoutines) > 1): ?><div class="workouts-routine-organizer-controls">
                                            <button class="btn btn-ghost btn-icon" type="button" data-exercise-organizer-move="up" data-routine-library-move="up" data-announcement="<?= e(t('workouts.moved_up', ['name' => $organizeRoutineName])) ?>" aria-label="<?= e(t('workouts.move_up')) ?>: <?= e($organizeRoutineName) ?>"<?= $routineIndex === 0 ? ' disabled' : '' ?>>&uarr;</button>
                                            <button class="btn btn-ghost btn-icon" type="button" data-exercise-organizer-move="down" data-routine-library-move="down" data-announcement="<?= e(t('workouts.moved_down', ['name' => $organizeRoutineName])) ?>" aria-label="<?= e(t('workouts.move_down')) ?>: <?= e($organizeRoutineName) ?>"<?= $routineIndex === count($groupRoutines) - 1 ? ' disabled' : '' ?>>&darr;</button>
                                        </div><?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                            <p class="sr-only" data-exercise-organizer-status data-routine-library-status aria-live="polite"></p>
                            <?php if (count($groupRoutines) > 1): ?><div class="workouts-routine-order-save"><button class="btn btn-primary" type="submit"><?= e(t('workouts.save_order')) ?></button></div><?php endif; ?>
                        </form>
                    </section>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>

<?php elseif ($wkView === 'list'): ?>
    <?php
    $summaryMonth = (array) ($wkSummaryMonth ?? []);
    $summaryAll = (array) ($wkSummaryAll ?? []);
    ?>
    <div class="workouts-overview-summary" aria-label="<?= e(t('workouts.stats')) ?>">
        <article class="workouts-summary-period">
            <span class="workouts-summary-period-label"><?= e(t('workouts.this_month')) ?></span>
            <div class="workouts-summary-period-metrics">
                <span><strong><?= (int) ($summaryMonth['sessions'] ?? 0) ?></strong><small><?= e(t('workouts.stat_sessions')) ?></small></span>
                <span><strong><?= e(number_format((float) ($summaryMonth['volume'] ?? 0), 0, '.', ' ')) ?></strong><small><?= e(t('workouts.stat_volume')) ?></small></span>
            </div>
        </article>
        <article class="workouts-summary-period">
            <span class="workouts-summary-period-label"><?= e(t('workouts.all_time')) ?></span>
            <div class="workouts-summary-period-metrics">
                <span><strong><?= (int) ($summaryAll['sessions'] ?? 0) ?></strong><small><?= e(t('workouts.stat_sessions')) ?></small></span>
                <span><strong><?= e(number_format((int) ($summaryAll['reps'] ?? 0), 0, '.', ' ')) ?></strong><small><?= e(t('workouts.stat_reps')) ?></small></span>
            </div>
        </article>
    </div>

    <?php // Continuing a live session beats every other action on this page, so it gets
          // the top slot and the only primary button while it exists. ?>
    <?php if (!empty($wkActiveSession)): ?>
        <a class="workouts-resume-banner" href="/?page=workouts&session_id=<?= (int) $wkActiveSession['id'] ?>">
            <span class="workouts-resume-dot" aria-hidden="true"></span>
            <span class="workouts-resume-copy">
                <strong><?= e(t('workouts.resume_session')) ?></strong>
                <small><?= e((string) ($wkActiveSession['title'] ?? '') !== '' ? (string) $wkActiveSession['title'] : t('workouts.session')) ?></small>
            </span>
            <span class="workouts-resume-go" aria-hidden="true">&rarr;</span>
        </a>
    <?php endif; ?>

    <?php // Two distinct things, spelled out: log a workout right now without a routine,
          // or build a routine you can reuse. Starting from a routine lives on each card. ?>
    <div class="workouts-start-grid">
        <form method="post" action="/?page=workouts" class="workouts-start-card">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="session_start">
            <span class="workouts-start-icon" aria-hidden="true"><?= activity_icon_svg('dumbbell') ?></span>
            <span class="workouts-start-copy">
                <strong><?= e(t('workouts.start_empty')) ?></strong>
                <small><?= e(t('workouts.start_empty_hint')) ?></small>
            </span>
            <button type="submit" class="btn btn-primary small"<?= !empty($wkActiveSession) ? ' disabled title="' . e(t('workouts.finish_active_first')) . '"' : '' ?>><?= e(t('workouts.start_now')) ?></button>
        </form>

        <div class="workouts-start-card">
            <span class="workouts-start-icon" aria-hidden="true"><?= activity_icon_svg('check') ?></span>
            <span class="workouts-start-copy">
                <strong><?= e(t('workouts.new_routine')) ?></strong>
                <small><?= e(t('workouts.new_routine_hint')) ?></small>
            </span>
            <button type="button" class="btn btn-ghost small" data-app-modal-open="wk-new-routine-modal"><?= e(t('common.create')) ?></button>
        </div>
    </div>

    <article class="panel">
        <div class="panel-head">
            <div>
                <p class="eyebrow"><?= count(array_filter((array) ($wkRoutines ?? []), static fn($r) => (int) ($r['is_archived'] ?? 0) === 0)) ?> <?= e(t('workouts.routines')) ?></p>
                <h2><?= e(t('workouts.your_routines')) ?></h2>
            </div>
            <?php if (count($activeRoutines) > 1): ?><a class="btn btn-ghost small workouts-organize-routines-link" href="/?page=workouts&view=organize"><?= e(t('workouts.organize')) ?></a><?php endif; ?>
        </div>

        <?php
        $archivedRoutines = array_values(array_filter((array) ($wkRoutines ?? []), static fn($r) => (int) ($r['is_archived'] ?? 0) === 1));
        ?>
        <?php if ($activeRoutines === []): ?>
            <div class="empty-state">
                <span class="empty-state-icon"><?= activity_icon_svg('dumbbell') ?></span>
                <p class="muted"><?= e(t('workouts.no_routines')) ?></p>
                <p class="muted small"><?= e(t('workouts.no_routines_hint')) ?></p>
            </div>
        <?php else: ?>
            <div class="workouts-routine-grid">
                <?php foreach ($activeRoutines as $routine): ?>
                    <?php
                    $rid = (int) $routine['id'];
                    $routineIcon = wk_normalize_routine_icon($routine['icon'] ?? 'dumbbell');
                    $routineAccent = wk_normalize_routine_color($routine['accent_color'] ?? '#14b8a6');
                    $routineCover = $workoutCoverAsset((array) $routine);
                    $routineMenu = render_kebab_menu([
                        ['label' => t('common.edit'), 'href' => '/?page=workouts&routine_id=' . $rid],
                        ['label' => t('menu.organize'), 'children' => [
                            ['label' => (int) ($routine['is_favorite'] ?? 0) === 1 ? t('workouts.favorite') . ' ✓' : t('workouts.favorite'), 'attrs' => ['data-wk-submit' => 'routine_favorite', 'data-wk-routine' => (string) $rid, 'data-wk-value' => (int) ($routine['is_favorite'] ?? 0) === 1 ? '0' : '1']],
                            ['label' => t('workouts.duplicate'), 'attrs' => ['data-wk-submit' => 'routine_duplicate', 'data-wk-routine' => (string) $rid]],
                            ['label' => t('workouts.archive'), 'attrs' => ['data-wk-submit' => 'routine_archive', 'data-wk-routine' => (string) $rid, 'data-wk-value' => '1']],
                        ]],
                        ['label' => t('workouts.delete_routine'), 'danger' => true, 'attrs' => ['data-wk-submit' => 'routine_delete', 'data-wk-routine' => (string) $rid, 'data-wk-confirm' => t('common.confirm_delete')]],
                    ], ['align' => 'end', 'title' => (string) $routine['name']]);
                    ?>
                    <article class="workouts-routine-card<?= (int) ($routine['is_favorite'] ?? 0) === 1 ? ' is-favorite' : '' ?><?= $routineCover !== null ? ' has-cover' : '' ?>" style="--routine-accent: <?= e($routineAccent) ?>">
                        <?php if ($routineCover !== null): ?><a class="workouts-routine-card-cover" href="/?page=workouts&routine_id=<?= $rid ?>" aria-label="<?= e((string) $routine['name']) ?>"><img src="<?= e((string) $routineCover['url']) ?>" alt="" style="object-position: <?= e($workoutCoverPosition($routineCover)) ?>"><?php if (!empty($routineCover['has_video'])): ?><span aria-hidden="true">&#9654;</span><?php endif; ?></a><?php endif; ?>
                        <div class="workouts-routine-card-head">
                            <a class="workouts-routine-title" href="/?page=workouts&routine_id=<?= $rid ?>">
                                <span class="workouts-routine-icon" aria-hidden="true"><?= activity_icon_svg($routineIcon) ?></span>
                                <span class="workouts-routine-title-copy"><strong><?= e((string) $routine['name']) ?></strong><?php if ((int) ($routine['is_favorite'] ?? 0) === 1): ?><span class="workouts-fav-star" aria-label="<?= e(t('workouts.favorite')) ?>">★</span><?php endif; ?></span>
                            </a>
                            <?= $routineMenu ?>
                        </div>
                        <?php if ((string) ($routine['description'] ?? '') !== ''): ?>
                            <p class="muted small workouts-routine-desc"><?= e((string) $routine['description']) ?></p>
                        <?php endif; ?>
                        <?php $routineDays = wk_days_from_mask((string) ($routine['recommended_days_mask'] ?? '0000000')); ?>
                        <?php if ($routineDays !== []): ?>
                            <div class="workouts-day-chips" aria-label="<?= e(t('workouts.select_days')) ?>">
                                <?php foreach ($routineDays as $day): ?><span><?= e($dayLabel($day)) ?></span><?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <div class="workouts-routine-card-foot">
                            <span class="badge"><?= (int) ($routine['exercise_count'] ?? 0) ?> <?= e(t('workouts.exercises')) ?></span>
                            <form method="post" action="/?page=workouts" class="inline-form">
                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                <input type="hidden" name="action" value="session_start">
                                <input type="hidden" name="routine_id" value="<?= $rid ?>">
                                <button type="submit" class="btn btn-primary small"<?= !empty($wkActiveSession) ? ' disabled title="' . e(t('workouts.finish_active_first')) . '"' : '' ?>><?= e(t('workouts.start_routine')) ?></button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($archivedRoutines !== []): ?>
            <details class="workouts-archived">
                <summary><?= e(t('workouts.archived')) ?> (<?= count($archivedRoutines) ?>)</summary>
                <div class="workouts-archived-list">
                    <?php foreach ($archivedRoutines as $routine): ?>
                        <div class="workouts-archived-row">
                            <span><?= e((string) $routine['name']) ?></span>
                            <form method="post" action="/?page=workouts" class="inline-form">
                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                <input type="hidden" name="action" value="routine_archive">
                                <input type="hidden" name="routine_id" value="<?= (int) $routine['id'] ?>">
                                <input type="hidden" name="value" value="0">
                                <button type="submit" class="btn btn-ghost small"><?= e(t('workouts.unarchive')) ?></button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endif; ?>
    </article>

    <div class="grid-two">
        <article class="panel">
            <div class="panel-head"><div><p class="eyebrow"><?= e(t('workouts.est_1rm')) ?></p><h2><?= e(t('workouts.personal_records')) ?></h2></div></div>
            <?php if (($wkPersonalRecords ?? []) === []): ?>
                <p class="muted"><?= e(t('workouts.no_records')) ?></p>
            <?php else: ?>
                <ul class="workouts-pr-list">
                    <?php foreach ((array) $wkPersonalRecords as $pr): ?>
                        <li><span><?= e((string) $pr['exercise_name']) ?></span><strong><?= e(number_format((float) $pr['value'], 1, '.', '')) ?> kg</strong></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </article>
        <article class="panel">
            <div class="panel-head"><div><p class="eyebrow"><?= count((array) ($wkRecentSessions ?? [])) ?></p><h2><?= e(t('workouts.recent_sessions')) ?></h2></div></div>
            <?php if (($wkRecentSessions ?? []) === []): ?>
                <p class="muted"><?= e(t('workouts.no_sessions')) ?></p>
            <?php else: ?>
                <ul class="workouts-session-list">
                    <?php foreach ((array) $wkRecentSessions as $sess): ?>
                        <li>
                            <strong><?= e((string) ($sess['title'] ?? '') !== '' ? (string) $sess['title'] : t('workouts.session')) ?></strong>
                            <span class="muted small"><?= e(human_time_ago((string) ($sess['started_at'] ?? ''))) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </article>
    </div>

<?php elseif ($wkView === 'plan'): ?>
    <?php $routinesByDay = (array) ($wkRoutinesByDay ?? []); ?>
    <article class="panel workouts-section-intro">
        <div>
            <p class="eyebrow"><?= e(t('workouts.week_schedule')) ?></p>
            <h2><?= e(t('workouts.plan_title')) ?></h2>
            <p class="muted"><?= e(t('workouts.plan_subtitle')) ?></p>
        </div>
        <button type="button" class="btn btn-primary" data-app-modal-open="wk-new-routine-modal">+ <?= e(t('workouts.new_routine')) ?></button>
    </article>

    <div class="workouts-week-grid">
        <?php foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $day): ?>
            <?php $dayRoutines = (array) ($routinesByDay[$day] ?? []); ?>
            <article class="workouts-day-card<?= $dayRoutines === [] ? ' is-rest' : '' ?>">
                <header><span><?= e($dayLabel($day)) ?></span><strong><?= count($dayRoutines) ?></strong></header>
                <?php if ($dayRoutines === []): ?>
                    <p><?= e(t('workouts.rest_day')) ?></p>
                <?php else: ?>
                    <div class="workouts-day-routines">
                        <?php foreach ($dayRoutines as $routine): ?>
                            <?php $dayRoutineIcon = wk_normalize_routine_icon($routine['icon'] ?? 'dumbbell'); $dayRoutineAccent = wk_normalize_routine_color($routine['accent_color'] ?? '#14b8a6'); $dayRoutineCover = $workoutCoverAsset((array) $routine); ?>
                            <div class="workouts-day-routine" style="--routine-accent: <?= e($dayRoutineAccent) ?>">
                                <a href="/?page=workouts&routine_id=<?= (int) $routine['id'] ?>">
                                    <span class="workouts-routine-icon is-small<?= $dayRoutineCover !== null ? ' has-media' : '' ?>" aria-hidden="true"><?php if ($dayRoutineCover !== null): ?><img src="<?= e((string) $dayRoutineCover['url']) ?>" alt="" style="object-position: <?= e($workoutCoverPosition($dayRoutineCover)) ?>"><?php else: ?><?= activity_icon_svg($dayRoutineIcon) ?><?php endif; ?><?php if (!empty($dayRoutineCover['has_video'])): ?><b>&#9654;</b><?php endif; ?></span>
                                    <span class="workouts-day-routine-copy"><strong><?= e((string) $routine['name']) ?></strong><span><?= (int) ($routine['exercise_count'] ?? 0) ?> <?= e(t('workouts.exercises')) ?></span></span>
                                </a>
                                <form method="post" action="/?page=workouts" class="inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                    <input type="hidden" name="action" value="session_start">
                                    <input type="hidden" name="routine_id" value="<?= (int) $routine['id'] ?>">
                                    <button class="btn btn-primary btn-icon small" type="submit" aria-label="<?= e(t('workouts.start_routine')) ?>"<?= !empty($wkActiveSession) ? ' disabled' : '' ?>>▶</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>

    <?php $unscheduledRoutines = (array) ($routinesByDay['unscheduled'] ?? []); ?>
    <?php if ($unscheduledRoutines !== []): ?>
        <article class="panel">
            <div class="panel-head"><div><p class="eyebrow"><?= count($unscheduledRoutines) ?></p><h2><?= e(t('workouts.unscheduled')) ?></h2></div></div>
                <div class="workouts-unscheduled-list">
                <?php foreach ($unscheduledRoutines as $routine): ?>
                    <?php $unscheduledIcon = wk_normalize_routine_icon($routine['icon'] ?? 'dumbbell'); $unscheduledAccent = wk_normalize_routine_color($routine['accent_color'] ?? '#14b8a6'); $unscheduledCover = $workoutCoverAsset((array) $routine); ?>
                    <a href="/?page=workouts&routine_id=<?= (int) $routine['id'] ?>" style="--routine-accent: <?= e($unscheduledAccent) ?>"><span class="workouts-routine-icon is-small<?= $unscheduledCover !== null ? ' has-media' : '' ?>" aria-hidden="true"><?php if ($unscheduledCover !== null): ?><img src="<?= e((string) $unscheduledCover['url']) ?>" alt="" style="object-position: <?= e($workoutCoverPosition($unscheduledCover)) ?>"><?php else: ?><?= activity_icon_svg($unscheduledIcon) ?><?php endif; ?><?php if (!empty($unscheduledCover['has_video'])): ?><b>&#9654;</b><?php endif; ?></span><span><strong><?= e((string) $routine['name']) ?></strong><small><?= (int) ($routine['exercise_count'] ?? 0) ?> <?= e(t('workouts.exercises')) ?></small></span></a>
                <?php endforeach; ?>
            </div>
        </article>
    <?php endif; ?>

    <article class="panel">
        <div class="panel-head">
            <div><p class="eyebrow"><?= count((array) ($wkPlanPresets ?? [])) ?></p><h2><?= e(t('workouts.starter_plans')) ?></h2><p class="muted small"><?= e(t('workouts.starter_plans_hint')) ?></p></div>
        </div>
        <div class="workouts-preset-grid">
            <?php foreach ((array) ($wkPlanPresets ?? []) as $presetKey => $preset): ?>
                <?php
                $presetNames = (array) ($preset['name'] ?? []);
                $presetDescriptions = (array) ($preset['description'] ?? []);
                $presetLocale = current_locale();
                $presetExerciseCount = 0;
                foreach ((array) ($preset['routines'] ?? []) as $presetRoutine) {
                    $presetExerciseCount += count((array) ($presetRoutine['exercises'] ?? []));
                }
                ?>
                <article class="workouts-preset-card">
                    <span class="workouts-preset-icon"><?= activity_icon_svg('dumbbell') ?></span>
                    <div><h3><?= e((string) ($presetNames[$presetLocale] ?? $presetNames['en'] ?? $presetKey)) ?></h3><p><?= e((string) ($presetDescriptions[$presetLocale] ?? $presetDescriptions['en'] ?? '')) ?></p></div>
                    <span class="badge"><?= count((array) ($preset['routines'] ?? [])) ?> <?= e(t('workouts.routines')) ?> · <?= $presetExerciseCount ?> <?= e(t('workouts.exercises')) ?></span>
                    <form method="post" action="/?page=workouts" class="inline-form">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="plan_preset_create">
                        <input type="hidden" name="preset" value="<?= e((string) $presetKey) ?>">
                        <button class="btn btn-ghost btn-block" type="submit"><?= e(t('workouts.use_plan')) ?></button>
                    </form>
                </article>
            <?php endforeach; ?>
        </div>
    </article>

<?php elseif ($wkView === 'custom_exercise'): ?>
    <?php require __DIR__ . '/partials/workout_custom_exercise_form.php'; ?>

<?php elseif ($wkView === 'library'): ?>
    <?php
    $filters = (array) ($wkLibraryFilters ?? []);
    $libraryExercises = (array) ($wkLibrary ?? []);
    $libraryScope = (string) ($filters['scope'] ?? '');
    $libraryMode = (string) ($wkLibraryMode ?? 'browse') === 'organize' && $libraryScope === 'favorites' && !$hasLibraryTarget ? 'organize' : 'browse';
    $libraryLayout = in_array((string) ($wkLibraryLayout ?? 'cards'), ['cards', 'compact'], true) ? (string) $wkLibraryLayout : 'cards';
    $useCompactLibrary = !$hasLibraryTarget && $libraryLayout === 'compact';
    $libraryHeading = $libraryMode === 'organize'
        ? t('workouts.organize_favorites')
        : ($libraryScope === 'mine'
        ? t('workouts.my_exercises')
        : ($libraryScope === 'favorites' ? t('workouts.favorite_exercises') : t('workouts.library_title')));
    $libraryHint = $libraryMode === 'organize'
        ? t('workouts.organize_favorites_hint')
        : ($libraryScope === 'mine'
        ? t('workouts.my_exercises_hint')
        : ($libraryScope === 'favorites' ? t('workouts.favorite_exercises_hint') : t('workouts.library_subtitle')));
    $libraryUrl = static function (array $changes = []) use ($filters, $targetRoutineId, $targetSessionId): string {
        $base = ['page' => 'workouts', 'view' => 'library'];
        if ($targetRoutineId > 0) {
            $base['target_routine_id'] = $targetRoutineId;
        } elseif ($targetSessionId > 0) {
            $base['target_session_id'] = $targetSessionId;
        }
        $query = array_merge($base, array_filter($filters, static fn($value): bool => (string) $value !== ''), $changes);
        foreach ($query as $key => $value) {
            if ($value === '' || $value === null) {
                unset($query[$key]);
            }
        }
        return '/?' . http_build_query($query);
    };
    $customExerciseUrl = $libraryUrl(['custom_exercise' => 'new', 'library_page' => null]);
    ?>
    <?php if ($hasLibraryTarget): ?>
        <aside class="workouts-library-target" aria-label="<?= e(t('workouts.add_exercises')) ?>">
            <span class="workouts-library-target-icon"<?= $targetRoutineId > 0 ? ' style="--routine-accent: ' . e(wk_normalize_routine_color($targetRoutine['accent_color'] ?? '#14b8a6')) . '"' : '' ?>><?= activity_icon_svg($targetSessionId > 0 ? 'fire' : wk_normalize_routine_icon($targetRoutine['icon'] ?? 'dumbbell')) ?></span>
            <div><small><?= e($targetSessionId > 0 ? t('workouts.adding_to_session') : t('workouts.add_exercises_to')) ?></small><strong><?= e($targetSessionId > 0 ? ((string) ($targetSession['title'] ?? '') !== '' ? (string) $targetSession['title'] : t('workouts.active_session')) : (string) ($targetRoutine['name'] ?? '')) ?></strong></div>
            <a class="btn btn-primary small" href="<?= e($targetSessionId > 0 ? '/?page=workouts&session_id=' . $targetSessionId : '/?page=workouts&routine_id=' . $targetRoutineId) ?>"><?= e($targetSessionId > 0 ? t('workouts.back_to_session') : t('workouts.finish_adding')) ?></a>
        </aside>
    <?php endif; ?>
    <article class="panel workouts-library-head<?= $libraryMode === 'organize' ? ' is-organizing' : '' ?>">
        <div class="panel-head">
            <div><p class="eyebrow"><?= e(t('workouts.library_results', ['count' => (int) ($wkLibraryTotal ?? count($libraryExercises))])) ?></p><h2><?= e($libraryHeading) ?></h2><p class="muted"><?= e($libraryHint) ?></p></div>
            <div class="workouts-library-actions">
                <?php if ($libraryMode === 'organize'): ?>
                    <a class="btn btn-primary small" href="<?= e($libraryUrl(['scope' => 'favorites', 'library_mode' => null, 'library_page' => null, 'q' => null, 'muscle' => null, 'equipment' => null])) ?>"><?= e(t('workouts.finish_adding')) ?></a>
                <?php else: ?>
                    <?php if ($libraryScope === 'favorites' && $favoriteExerciseCount > 1 && !$hasLibraryTarget): ?><a class="btn btn-ghost small" href="<?= e($libraryUrl(['scope' => 'favorites', 'library_mode' => 'organize', 'library_page' => null, 'q' => null, 'muscle' => null, 'equipment' => null])) ?>"><?= e(t('workouts.organize')) ?></a><?php endif; ?>
                    <a class="btn btn-primary small" href="<?= e($customExerciseUrl) ?>">+ <?= e(t('workouts.create_custom')) ?></a>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($libraryMode !== 'organize'): ?>
        <nav class="workouts-library-scope-tabs" aria-label="<?= e(t('workouts.library_scope')) ?>">
            <a href="<?= e($libraryUrl(['scope' => null, 'library_page' => null])) ?>"<?= $libraryScope === '' ? ' aria-current="page"' : '' ?>><span><?= e(t('workouts.all_exercises')) ?></span><strong><?= count($exercises) ?></strong></a>
            <a href="<?= e($libraryUrl(['scope' => 'favorites', 'library_page' => null])) ?>"<?= $libraryScope === 'favorites' ? ' aria-current="page"' : '' ?>><span><?= e(t('workouts.favorites')) ?></span><strong><?= $favoriteExerciseCount ?></strong></a>
            <a href="<?= e($libraryUrl(['scope' => 'mine', 'library_page' => null])) ?>"<?= $libraryScope === 'mine' ? ' aria-current="page"' : '' ?>><span><?= e(t('workouts.my_exercises')) ?></span><strong><?= $personalExerciseCount ?></strong></a>
        </nav>
        <?php if (!$hasLibraryTarget): ?>
        <form method="post" action="/?page=workouts" class="workouts-library-layout-switch" data-library-layout-switch>
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="library_layout_update">
            <input type="hidden" name="muscle" value="<?= e((string) ($filters['muscle'] ?? '')) ?>">
            <input type="hidden" name="equipment" value="<?= e((string) ($filters['equipment'] ?? '')) ?>">
            <input type="hidden" name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>">
            <input type="hidden" name="scope" value="<?= e($libraryScope) ?>">
            <input type="hidden" name="library_page" value="<?= (int) ($wkLibraryPage ?? 1) ?>">
            <span class="workouts-library-layout-copy"><strong><?= e(t('workouts.library_layout')) ?></strong><small><?= e(t($libraryLayout === 'compact' ? 'workouts.layout_compact_hint' : 'workouts.layout_cards_hint')) ?></small></span>
            <span class="workouts-library-layout-options" role="group" aria-label="<?= e(t('workouts.library_layout')) ?>">
                <button type="submit" name="library_layout" value="cards" class="<?= $libraryLayout === 'cards' ? 'is-active' : '' ?>" aria-pressed="<?= $libraryLayout === 'cards' ? 'true' : 'false' ?>" title="<?= e(t('workouts.layout_cards_hint')) ?>"><span class="workouts-layout-icon is-cards" aria-hidden="true"></span><span><?= e(t('workouts.layout_cards')) ?></span></button>
                <button type="submit" name="library_layout" value="compact" class="<?= $libraryLayout === 'compact' ? 'is-active' : '' ?>" aria-pressed="<?= $libraryLayout === 'compact' ? 'true' : 'false' ?>" title="<?= e(t('workouts.layout_compact_hint')) ?>"><span class="workouts-layout-icon is-compact" aria-hidden="true"></span><span><?= e(t('workouts.layout_compact')) ?></span></button>
            </span>
        </form>
        <?php endif; ?>
        <form method="get" action="/" class="workouts-library-filters">
            <input type="hidden" name="page" value="workouts">
            <input type="hidden" name="view" value="library">
            <?php if ($targetRoutineId > 0): ?><input type="hidden" name="target_routine_id" value="<?= $targetRoutineId ?>"><?php endif; ?>
            <?php if ($targetSessionId > 0): ?><input type="hidden" name="target_session_id" value="<?= $targetSessionId ?>"><?php endif; ?>
            <?php if ($libraryScope !== ''): ?><input type="hidden" name="scope" value="<?= e($libraryScope) ?>"><?php endif; ?>
            <label><span class="sr-only"><?= e(t('workouts.search_exercises')) ?></span><input type="search" name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>" placeholder="<?= e(t('workouts.search_exercises')) ?>"></label>
            <label><span class="sr-only"><?= e(t('workouts.equipment')) ?></span><select name="equipment">
                <option value=""><?= e(t('workouts.all_equipment')) ?></option>
                <?php foreach ((array) ($wkEquipmentOptions ?? []) as $equipment): ?><option value="<?= e((string) $equipment) ?>"<?= (string) ($filters['equipment'] ?? '') === (string) $equipment ? ' selected' : '' ?>><?= e($equipmentLabel((string) $equipment)) ?></option><?php endforeach; ?>
            </select></label>
            <?php if ((string) ($filters['muscle'] ?? '') !== ''): ?><input type="hidden" name="muscle" value="<?= e((string) $filters['muscle']) ?>"><?php endif; ?>
            <button class="btn btn-primary" type="submit"><?= e(t('common.filter')) ?></button>
        </form>
        <nav class="workouts-muscle-filters" aria-label="<?= e(t('workouts.all_body_parts')) ?>">
            <a href="<?= e($libraryUrl(['muscle' => ''])) ?>"<?= (string) ($filters['muscle'] ?? '') === '' ? ' aria-current="page"' : '' ?>><?= e(t('workouts.all_body_parts')) ?></a>
            <?php foreach ((array) ($wkMuscleGroups ?? []) as $muscle): ?>
                <a href="<?= e($libraryUrl(['muscle' => $muscle])) ?>"<?= (string) ($filters['muscle'] ?? '') === (string) $muscle ? ' aria-current="page"' : '' ?>><?= e($muscleLabel((string) $muscle)) ?></a>
            <?php endforeach; ?>
        </nav>
        <?php endif; ?>
    </article>

    <?php if ($libraryMode === 'organize' && $libraryExercises !== []): ?>
        <article class="panel workouts-favorite-order-panel">
            <section class="workouts-routine-order-group workouts-favorite-order-group" data-favorite-order-group>
                <header><span aria-hidden="true">&#9733;</span><div><h3><?= e(t('workouts.favorite_exercises')) ?></h3><p><?= e(t('workouts.favorite_order_group_hint')) ?></p></div><b><?= count($libraryExercises) ?></b></header>
                <form method="post" action="/?page=workouts" data-exercise-organizer data-favorite-exercise-organizer>
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="exercise_favorites_reorder">
                    <ol class="workouts-routine-organizer-list workouts-favorite-order-list" data-exercise-organizer-list data-favorite-exercise-list>
                        <?php foreach ($libraryExercises as $favoriteIndex => $favoriteExercise): ?>
                            <?php
                            $favoriteExerciseId = (int) ($favoriteExercise['id'] ?? 0);
                            $favoriteExerciseName = (string) ($favoriteExercise['display_name'] ?? $favoriteExercise['name'] ?? '');
                            $favoriteExerciseCover = $workoutCoverAsset((array) $favoriteExercise);
                            $favoriteExerciseMeta = $muscleLabel((string) ($favoriteExercise['muscle_group'] ?? '')) . ' · ' . $equipmentLabel((string) ($favoriteExercise['equipment'] ?? ''));
                            $favoriteExerciseGuideUrl = $libraryUrl(['exercise_id' => $favoriteExerciseId, 'library_mode' => null, 'library_page' => null]);
                            ?>
                            <li class="workouts-routine-organizer-item workouts-favorite-order-item" style="<?= e($workoutExerciseStyle((array) $favoriteExercise)) ?>" data-exercise-organizer-item data-favorite-exercise-item data-exercise-name="<?= e($favoriteExerciseName) ?>">
                                <input type="hidden" name="order[]" value="<?= $favoriteExerciseId ?>">
                                <span class="workouts-routine-organizer-position" aria-label="<?= e(t('workouts.position')) ?>"><span data-exercise-organizer-position data-favorite-exercise-position><?= $favoriteIndex + 1 ?></span></span>
                                <span class="workouts-exercise-cover<?= $favoriteExerciseCover !== null ? ' has-media' : '' ?>" aria-hidden="true"><?php if ($favoriteExerciseCover !== null): ?><img src="<?= e((string) $favoriteExerciseCover['url']) ?>" alt="" style="object-position: <?= e($workoutCoverPosition($favoriteExerciseCover)) ?>"><?php else: ?><?= e($workoutExerciseMark((array) $favoriteExercise)) ?><?php endif; ?><?php if (!empty($favoriteExerciseCover['has_video'])): ?><b>&#9654;</b><?php endif; ?></span>
                                <a class="workouts-routine-organizer-copy workouts-routine-order-copy" href="<?= e($favoriteExerciseGuideUrl) ?>"><strong><?= e($favoriteExerciseName) ?><?php if ((int) ($favoriteExercise['is_system'] ?? 1) !== 1): ?> <span><?= e(t('workouts.mine')) ?></span><?php endif; ?></strong><small><?= e($favoriteExerciseMeta) ?></small></a>
                                <div class="workouts-routine-organizer-controls">
                                    <button class="btn btn-ghost btn-icon" type="button" data-exercise-organizer-move="up" data-favorite-exercise-move="up" data-announcement="<?= e(t('workouts.moved_up', ['name' => $favoriteExerciseName])) ?>" aria-label="<?= e(t('workouts.move_up')) ?>: <?= e($favoriteExerciseName) ?>"<?= $favoriteIndex === 0 ? ' disabled' : '' ?>>&uarr;</button>
                                    <button class="btn btn-ghost btn-icon" type="button" data-exercise-organizer-move="down" data-favorite-exercise-move="down" data-announcement="<?= e(t('workouts.moved_down', ['name' => $favoriteExerciseName])) ?>" aria-label="<?= e(t('workouts.move_down')) ?>: <?= e($favoriteExerciseName) ?>"<?= $favoriteIndex === count($libraryExercises) - 1 ? ' disabled' : '' ?>>&darr;</button>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                    <p class="sr-only" data-exercise-organizer-status data-favorite-exercise-status aria-live="polite"></p>
                    <div class="workouts-routine-order-save"><button class="btn btn-primary" type="submit"><?= e(t('workouts.save_order')) ?></button></div>
                </form>
            </section>
        </article>
    <?php elseif ($libraryExercises === []): ?>
        <?php $emptyLibraryText = $libraryScope === 'mine' ? t('workouts.no_personal_exercises') : ($libraryScope === 'favorites' ? t('workouts.no_favorite_exercises') : t('workouts.no_library_results')); ?>
        <div class="panel empty-state"><span class="empty-state-icon"><?= activity_icon_svg($libraryScope === 'favorites' ? 'star' : 'search') ?></span><p><?= e($emptyLibraryText) ?></p><?php if ($libraryScope === 'mine'): ?><a class="btn btn-primary" href="<?= e($customExerciseUrl) ?>"><?= e(t('workouts.create_custom')) ?></a><?php elseif ($libraryScope === 'favorites'): ?><a class="btn btn-ghost" href="<?= e($libraryUrl(['scope' => null])) ?>"><?= e(t('workouts.explore_exercises')) ?></a><?php endif; ?></div>
    <?php else: ?>
        <div class="workouts-library-grid<?= $hasLibraryTarget ? ' is-contextual' : '' ?><?= $useCompactLibrary ? ' is-compact' : '' ?>" data-library-layout="<?= e($useCompactLibrary ? 'compact' : 'cards') ?>">
            <?php foreach ($libraryExercises as $exercise): ?>
                <?php
                $content = (array) ($exercise['content'] ?? []);
                $rank = (array) ($exercise['rank'] ?? wk_rank_from_score(0));
                $rankKey = (string) ($rank['key'] ?? 'unranked');
                $exerciseGuideUrl = $libraryUrl(['exercise_id' => (int) $exercise['id'], 'library_page' => (int) ($wkLibraryPage ?? 1)]);
                $isPersonalExercise = (int) ($exercise['is_system'] ?? 0) === 0;
                $exerciseVideoSource = $workoutVideoSource((string) ($exercise['video_url'] ?? ''));
                $hasExerciseVideo = $exerciseVideoSource !== null;
                $exerciseCoverMode = wk_normalize_exercise_cover_mode($exercise['cover_mode'] ?? 'auto');
                $exerciseImagePath = trim((string) ($exercise['image_path'] ?? ''));
                $exerciseImagePosition = wk_image_position_css($exercise['image_position'] ?? 'center');
                $useExercisePhoto = $exerciseImagePath !== '' && in_array($exerciseCoverMode, ['auto', 'photo'], true);
                $libraryVideoThumbnail = trim((string) ($exerciseVideoSource['thumbnail_url'] ?? ''));
                $useVideoThumbnail = $libraryVideoThumbnail !== ''
                    && ($exerciseCoverMode === 'video' || ($exerciseCoverMode === 'auto' && !$useExercisePhoto));
                $isFavoriteExercise = (int) ($exercise['is_favorite'] ?? 0) === 1;
                $isInLibraryTarget = $hasLibraryTarget && in_array((int) $exercise['id'], $libraryTargetExerciseIds, true);
                $libraryTrainingDefaults = wk_exercise_training_defaults((array) $exercise);
                $libraryTrainingLabel = match ((string) ($exercise['exercise_type'] ?? 'strength')) {
                    'cardio' => $libraryTrainingDefaults['target_sets'] . '×' . ($libraryTrainingDefaults['target_duration'] !== null ? rtrim(rtrim(number_format((int) $libraryTrainingDefaults['target_duration'] / 60, 1, '.', ''), '0'), '.') . ' min' : '—'),
                    'isometric' => $libraryTrainingDefaults['target_sets'] . '×' . ($libraryTrainingDefaults['target_duration'] !== null ? (int) $libraryTrainingDefaults['target_duration'] . 's' : '—'),
                    default => $libraryTrainingDefaults['target_sets'] . '×' . ($libraryTrainingDefaults['target_reps'] ?? '—'),
                };
                ?>
                <article class="workouts-library-card<?= $isPersonalExercise ? ' is-personal' : '' ?><?= $isFavoriteExercise ? ' is-favorite' : '' ?>" style="<?= e($workoutExerciseStyle((array) $exercise)) ?>" data-muscle="<?= e((string) ($exercise['muscle_group'] ?? '')) ?>" data-cover-mode="<?= e($exerciseCoverMode) ?>">
                    <?php $libraryImageUrl = $useExercisePhoto ? media_thumbnail_url($exerciseImagePath, 400) : ''; ?>
                    <?php if ($libraryImageUrl !== ''): ?>
                        <a class="workouts-library-media" href="<?= e($exerciseGuideUrl) ?>" aria-label="<?= e((string) ($exercise['display_name'] ?? $exercise['name'])) ?>">
                            <img src="<?= e($libraryImageUrl) ?>" srcset="<?= e(media_thumbnail_srcset($exerciseImagePath, [200, 400, 800])) ?>" sizes="(max-width: 700px) calc(100vw - 32px), 360px" width="400" height="225" alt="" loading="lazy" decoding="async" style="object-position: <?= e($exerciseImagePosition) ?>">
                            <span class="workouts-library-media-badges"><?php if ($isPersonalExercise): ?><em><?= e(t('workouts.mine')) ?></em><?php endif; ?><?php if ($hasExerciseVideo): ?><em aria-label="<?= e(t('workouts.custom_video')) ?>">&#9654;</em><?php endif; ?></span>
                        </a>
                    <?php elseif ($useVideoThumbnail): ?>
                        <a class="workouts-library-media is-video-thumbnail" href="<?= e($exerciseGuideUrl) ?>" aria-label="<?= e((string) ($exercise['display_name'] ?? $exercise['name'])) ?>" style="--workout-video-thumb: url('<?= e($libraryVideoThumbnail) ?>')">
                            <span class="workouts-library-video-fallback" aria-hidden="true"><?= e($workoutExerciseMark((array) $exercise)) ?></span>
                            <span class="workouts-library-video-thumb" aria-hidden="true"></span>
                            <span class="workouts-library-play" aria-hidden="true">&#9654;</span>
                            <span class="workouts-library-media-badges"><?php if ($isPersonalExercise): ?><em><?= e(t('workouts.mine')) ?></em><?php endif; ?><em><?= e(t('workouts.youtube')) ?></em></span>
                        </a>
                    <?php else: ?>
                        <a class="workouts-library-media is-placeholder" href="<?= e($exerciseGuideUrl) ?>" aria-label="<?= e((string) ($exercise['display_name'] ?? $exercise['name'])) ?>"><span><?= e($workoutExerciseMark((array) $exercise)) ?></span><small><?= e($hasExerciseVideo ? t('workouts.video_available') : $muscleLabel((string) ($exercise['muscle_group'] ?? ''))) ?></small><span class="workouts-library-media-badges"><?php if ($isPersonalExercise): ?><em><?= e(t('workouts.mine')) ?></em><?php endif; ?><?php if ($hasExerciseVideo): ?><em aria-label="<?= e(t('workouts.custom_video')) ?>">&#9654;</em><?php endif; ?></span></a>
                    <?php endif; ?>
                    <div class="workouts-library-card-head">
                        <span class="workouts-muscle-token" aria-hidden="true"><?= e(strtoupper(substr((string) ($exercise['muscle_group'] ?? 'X'), 0, 2))) ?></span>
                        <div class="workouts-library-card-status">
                            <form method="post" action="/?page=workouts" class="workouts-favorite-form">
                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="exercise_favorite"><input type="hidden" name="exercise_id" value="<?= (int) $exercise['id'] ?>"><input type="hidden" name="value" value="<?= $isFavoriteExercise ? 0 : 1 ?>">
                                <input type="hidden" name="muscle" value="<?= e((string) ($filters['muscle'] ?? '')) ?>"><input type="hidden" name="equipment" value="<?= e((string) ($filters['equipment'] ?? '')) ?>"><input type="hidden" name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>"><input type="hidden" name="scope" value="<?= e($libraryScope) ?>"><input type="hidden" name="library_page" value="<?= (int) ($wkLibraryPage ?? 1) ?>">
                                <?php if ($targetRoutineId > 0): ?><input type="hidden" name="target_routine_id" value="<?= $targetRoutineId ?>"><?php endif; ?>
                                <?php if ($targetSessionId > 0): ?><input type="hidden" name="target_session_id" value="<?= $targetSessionId ?>"><?php endif; ?>
                                <button class="workouts-favorite-toggle<?= $isFavoriteExercise ? ' is-active' : '' ?>" type="submit" aria-pressed="<?= $isFavoriteExercise ? 'true' : 'false' ?>" aria-label="<?= e($isFavoriteExercise ? t('workouts.remove_favorite') : t('workouts.add_favorite')) ?>"><?= $isFavoriteExercise ? '&#9733;' : '&#9734;' ?></button>
                            </form>
                            <span class="workouts-rank-badge" data-rank="<?= e($rankKey) ?>"><?= e($rankLabel($rankKey)) ?></span>
                        </div>
                    </div>
                    <div class="workouts-library-copy">
                        <h3><a href="<?= e($exerciseGuideUrl) ?>"><?= e((string) ($exercise['display_name'] ?? $exercise['name'])) ?></a></h3>
                        <p><?= e((string) ($content['summary'] ?? '')) ?></p>
                    </div>
                    <div class="workouts-exercise-tags"><span><?= e($muscleLabel((string) ($exercise['muscle_group'] ?? ''))) ?></span><span><?= e($equipmentLabel((string) ($exercise['equipment'] ?? ''))) ?></span><span><?= e($difficultyLabel((string) ($exercise['difficulty'] ?? 'beginner'))) ?></span><?php if ($isPersonalExercise): ?><span class="workouts-training-default-chip" title="<?= e(t('workouts.training_defaults')) ?>"><?= e($libraryTrainingLabel) ?></span><?php endif; ?></div>
                    <div class="workouts-library-card-foot">
                        <div class="inline-actions"><a class="btn btn-ghost small" href="<?= e($exerciseGuideUrl) ?>"><?= e(t('workouts.view_guide')) ?></a><?php if ($isPersonalExercise): ?><a class="btn btn-ghost small" href="<?= e($libraryUrl(['custom_exercise' => (int) $exercise['id'], 'library_page' => null])) ?>"><?= e(t('common.edit')) ?></a><?php endif; ?></div>
                        <?php if ($targetSessionId > 0): ?>
                            <form method="post" action="/?page=workouts" class="workouts-library-add is-contextual">
                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="library_add_exercise"><input type="hidden" name="exercise_def_id" value="<?= (int) $exercise['id'] ?>"><input type="hidden" name="target_session_id" value="<?= $targetSessionId ?>">
                                <input type="hidden" name="muscle" value="<?= e((string) ($filters['muscle'] ?? '')) ?>"><input type="hidden" name="equipment" value="<?= e((string) ($filters['equipment'] ?? '')) ?>"><input type="hidden" name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>"><input type="hidden" name="scope" value="<?= e((string) ($filters['scope'] ?? '')) ?>"><input type="hidden" name="library_page" value="<?= (int) ($wkLibraryPage ?? 1) ?>">
                                <button class="btn <?= $isInLibraryTarget ? 'btn-ghost is-added' : 'btn-primary' ?> small" type="submit"<?= $isInLibraryTarget ? ' disabled' : '' ?>><?= e($isInLibraryTarget ? t('workouts.added_to_session') : t('workouts.add_to_session')) ?></button>
                            </form>
                        <?php elseif ($targetRoutineId > 0): ?>
                            <form method="post" action="/?page=workouts" class="workouts-library-add is-contextual">
                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="library_add_exercise"><input type="hidden" name="exercise_def_id" value="<?= (int) $exercise['id'] ?>"><input type="hidden" name="routine_id" value="<?= $targetRoutineId ?>"><input type="hidden" name="target_routine_id" value="<?= $targetRoutineId ?>">
                                <input type="hidden" name="muscle" value="<?= e((string) ($filters['muscle'] ?? '')) ?>"><input type="hidden" name="equipment" value="<?= e((string) ($filters['equipment'] ?? '')) ?>"><input type="hidden" name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>"><input type="hidden" name="scope" value="<?= e((string) ($filters['scope'] ?? '')) ?>"><input type="hidden" name="library_page" value="<?= (int) ($wkLibraryPage ?? 1) ?>">
                                <button class="btn <?= $isInLibraryTarget ? 'btn-ghost is-added' : 'btn-primary' ?> small" type="submit"<?= $isInLibraryTarget ? ' disabled' : '' ?>><?= e($isInLibraryTarget ? t('workouts.added_to_routine') : t('workouts.add_to_routine')) ?></button>
                            </form>
                        <?php elseif ($activeRoutines !== []): ?>
                            <form method="post" action="/?page=workouts" class="workouts-library-add">
                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="library_add_exercise"><input type="hidden" name="exercise_def_id" value="<?= (int) $exercise['id'] ?>">
                                <input type="hidden" name="muscle" value="<?= e((string) ($filters['muscle'] ?? '')) ?>"><input type="hidden" name="equipment" value="<?= e((string) ($filters['equipment'] ?? '')) ?>"><input type="hidden" name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>"><input type="hidden" name="scope" value="<?= e((string) ($filters['scope'] ?? '')) ?>">
                                <label><span class="sr-only"><?= e(t('workouts.choose_routine')) ?></span><select name="routine_id" required><?php foreach ($activeRoutines as $routine): ?><option value="<?= (int) $routine['id'] ?>"><?= e((string) $routine['name']) ?></option><?php endforeach; ?></select></label>
                                <button class="btn btn-primary btn-icon small" type="submit" aria-label="<?= e(t('workouts.add_to_routine')) ?>">+</button>
                            </form>
                        <?php else: ?>
                            <button class="btn btn-primary small" type="button" data-app-modal-open="wk-new-routine-modal"><?= e(t('workouts.new_routine')) ?></button>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php
        $libraryTotal = max(0, (int) ($wkLibraryTotal ?? count($libraryExercises)));
        $libraryPage = max(1, (int) ($wkLibraryPage ?? 1));
        $libraryPages = max(1, (int) ceil($libraryTotal / max(1, (int) ($wkLibraryPerPage ?? 12))));
        ?>
        <?php if ($libraryPages > 1): ?>
            <nav class="pagination workouts-library-pagination" aria-label="<?= e(t('common.pagination')) ?>">
                <?php if ($libraryPage > 1): ?><a class="btn btn-ghost" href="<?= e($libraryUrl(['library_page' => $libraryPage - 1])) ?>">&larr; <?= e(t('common.previous')) ?></a><?php endif; ?>
                <span><?= e(t('common.page_of', ['page' => $libraryPage, 'pages' => $libraryPages])) ?></span>
                <?php if ($libraryPage < $libraryPages): ?><a class="btn btn-primary" href="<?= e($libraryUrl(['library_page' => $libraryPage + 1])) ?>"><?= e(t('common.next')) ?> &rarr;</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>

<?php elseif ($wkView === 'ranks'): ?>
    <?php $overallRank = (array) ($wkOverallRank ?? wk_rank_from_score(0)); $overallKey = (string) ($overallRank['key'] ?? 'unranked'); ?>
    <article class="workouts-rank-hero" data-rank="<?= e($overallKey) ?>">
        <div class="workouts-rank-emblem"><span><?= e($rankLabel($overallKey)) ?></span><strong><?= e(number_format((float) ($overallRank['score'] ?? 0), 1, '.', '')) ?></strong></div>
        <div><p class="eyebrow"><?= e(t('workouts.overall_rank')) ?></p><h2><?= e(t('workouts.rank_title')) ?></h2><p><?= e(t('workouts.rank_subtitle')) ?></p><small><?= e(t('workouts.rank_formula_hint')) ?></small></div>
    </article>

    <?php if (wk_user_bodyweight($GLOBALS['pdo'], (int) $currentUser['id']) === null): ?>
        <div class="workouts-rank-notice"><?= activity_icon_svg('info') ?><span><?= e(t('workouts.bodyweight_required')) ?></span><a href="/?page=entries&mode=data"><?= e(t('entries.quick_data')) ?></a></div>
    <?php endif; ?>

    <article class="panel">
        <div class="panel-head"><div><h2><?= e(t('workouts.body_part_ranks')) ?></h2></div></div>
        <div class="workouts-body-rank-grid">
            <?php foreach ((array) ($wkMuscleRanks ?? []) as $muscleRank): ?>
                <?php $rank = (array) ($muscleRank['rank'] ?? []); $rankKey = (string) ($rank['key'] ?? 'unranked'); ?>
                <a class="workouts-body-rank-card" data-rank="<?= e($rankKey) ?>" href="/?page=workouts&view=library&muscle=<?= e((string) $muscleRank['muscle']) ?>">
                    <span class="workouts-muscle-token"><?= e(strtoupper(substr((string) $muscleRank['muscle'], 0, 2))) ?></span>
                    <span><strong><?= e($muscleLabel((string) $muscleRank['muscle'])) ?></strong><small><?= e(t('workouts.ranked_count', ['ranked' => (int) $muscleRank['ranked_count'], 'total' => (int) $muscleRank['catalog_count']])) ?></small></span>
                    <span class="workouts-rank-badge" data-rank="<?= e($rankKey) ?>"><?= e($rankLabel($rankKey)) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </article>

    <div class="workouts-rank-columns">
        <article class="panel">
            <div class="panel-head"><div><h2><?= e(t('workouts.exercise_ranks')) ?></h2></div></div>
            <div class="workouts-exercise-rank-list">
                <?php foreach ((array) ($wkExerciseRanks ?? []) as $exercise): ?>
                    <?php if (!(bool) ($exercise['rank']['rankable'] ?? true)) { continue; } $rank = (array) $exercise['rank']; $rankKey = (string) ($rank['key'] ?? 'unranked'); ?>
                    <a href="/?page=workouts&exercise_id=<?= (int) $exercise['id'] ?>" class="workouts-exercise-rank-row">
                        <span class="workouts-rank-position"><?= e($workoutExerciseMark((array) $exercise)) ?></span>
                        <span><strong><?= e((string) ($exercise['display_name'] ?? $exercise['name'])) ?></strong><small><?= e($muscleLabel((string) ($exercise['muscle_group'] ?? ''))) ?> · <?= e(number_format((float) ($rank['score'] ?? 0), 1, '.', '')) ?> <?= e(t('workouts.lift_points')) ?></small></span>
                        <span class="workouts-rank-badge" data-rank="<?= e($rankKey) ?>"><?= e($rankLabel($rankKey)) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </article>
        <article class="panel">
            <div class="panel-head"><div><h2><?= e(t('workouts.team_leaderboard')) ?></h2></div></div>
            <div class="workouts-leaderboard-list">
                <?php foreach ((array) ($wkRankLeaderboard ?? []) as $rankedUser): ?>
                    <?php $userRank = (array) ($rankedUser['rank'] ?? []); $userRankKey = (string) ($userRank['key'] ?? 'unranked'); ?>
                    <a href="/?page=profile&user_id=<?= (int) $rankedUser['id'] ?>" class="workouts-leaderboard-row<?= (int) $rankedUser['id'] === (int) $currentUser['id'] ? ' is-me' : '' ?>">
                        <strong>#<?= (int) $rankedUser['position'] ?></strong><span class="avatar-small"><?= e(initials_for((string) $rankedUser['display_name'])) ?></span><span><strong><?= e((string) $rankedUser['display_name']) ?></strong><small>@<?= e((string) $rankedUser['username']) ?></small></span><span class="workouts-rank-badge" data-rank="<?= e($userRankKey) ?>"><?= e($rankLabel($userRankKey)) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </article>
    </div>

<?php elseif ($wkView === 'exercise' && !empty($wkExercise)): ?>
    <?php
    $exercise = (array) $wkExercise;
    $content = (array) ($exercise['content'] ?? wk_exercise_content($exercise));
    $rank = (array) ($wkExerciseRank ?? wk_rank_from_score(0));
    $rankKey = (string) ($rank['key'] ?? 'unranked');
    $secondaryMuscles = json_decode((string) ($exercise['secondary_muscles'] ?? '[]'), true);
    $secondaryMuscles = is_array($secondaryMuscles) ? $secondaryMuscles : [];
    $exerciseGallery = (array) ($wkExerciseMedia ?? []);
    $exerciseGalleryImages = array_map(static fn(array $item): array => [
        'url' => media_url((string) ($item['path'] ?? '')),
        'position' => $item['position'] ?? 'center',
        'caption' => $item['caption'] ?? '',
    ], $exerciseGallery);
    $exerciseImageUrl = trim((string) ($exercise['image_path'] ?? '')) !== '' ? media_url((string) $exercise['image_path']) : '';
    $exerciseImagePosition = wk_image_position_css($exercise['image_position'] ?? 'center');
    $exerciseVideo = $workoutVideoSource((string) ($exercise['video_url'] ?? ''));
    $exerciseAccentStyle = $workoutExerciseStyle($exercise);
    $exerciseTrainingDefaults = wk_exercise_training_defaults($exercise);
    $exerciseDefaultDuration = (int) ($exerciseTrainingDefaults['target_duration'] ?? 0);
    $exerciseDefaultDurationMinutes = $exerciseDefaultDuration > 0
        ? rtrim(rtrim(number_format($exerciseDefaultDuration / 60, 1, '.', ''), '0'), '.')
        : '';
    $exerciseIsCardio = (string) ($exercise['exercise_type'] ?? 'strength') === 'cardio';
    $exerciseIsIsometric = (string) ($exercise['exercise_type'] ?? 'strength') === 'isometric';
    $guideContextSuffix = $targetSessionId > 0
        ? '&target_session_id=' . $targetSessionId
        : ($targetRoutineId > 0 ? '&target_routine_id=' . $targetRoutineId : '');
    ?>
    <article class="workouts-exercise-hero panel" style="<?= e($exerciseAccentStyle) ?>" data-muscle="<?= e((string) ($exercise['muscle_group'] ?? '')) ?>">
        <div class="workouts-exercise-hero-icon"><?= e($workoutExerciseMark($exercise)) ?></div>
        <div class="workouts-exercise-hero-copy"><p class="eyebrow"><?= e(t('workouts.guide')) ?></p><h2><?= e((string) ($exercise['display_name'] ?? $exercise['name'])) ?></h2><p><?= e((string) ($content['summary'] ?? '')) ?></p><div class="workouts-exercise-tags"><span><?= e($muscleLabel((string) ($exercise['muscle_group'] ?? ''))) ?></span><span><?= e($equipmentLabel((string) ($exercise['equipment'] ?? ''))) ?></span><span><?= e($difficultyLabel((string) ($exercise['difficulty'] ?? 'beginner'))) ?></span></div></div>
        <div class="workouts-exercise-rank-card"><span class="workouts-rank-badge" data-rank="<?= e($rankKey) ?>"><?= e($rankLabel($rankKey)) ?></span><strong><?= e(number_format((float) ($rank['score'] ?? 0), 1, '.', '')) ?></strong><small><?= e(t('workouts.lift_points')) ?></small><div class="workouts-rank-progress"><span style="width: <?= (int) ($rank['progress'] ?? 0) ?>%"></span></div></div>
        <div class="workouts-exercise-hero-actions">
            <?php $guideIsFavorite = (int) ($exercise['is_favorite'] ?? 0) === 1; ?>
            <form method="post" action="/?page=workouts" class="workouts-favorite-form"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="exercise_favorite"><input type="hidden" name="exercise_id" value="<?= (int) $exercise['id'] ?>"><input type="hidden" name="value" value="<?= $guideIsFavorite ? 0 : 1 ?>"><input type="hidden" name="return_to" value="exercise"><?php if ($targetRoutineId > 0): ?><input type="hidden" name="target_routine_id" value="<?= $targetRoutineId ?>"><?php elseif ($targetSessionId > 0): ?><input type="hidden" name="target_session_id" value="<?= $targetSessionId ?>"><?php endif; ?><button class="btn btn-ghost small" type="submit" aria-pressed="<?= $guideIsFavorite ? 'true' : 'false' ?>"><?= $guideIsFavorite ? '&#9733; ' . e(t('workouts.favorite')) : '&#9734; ' . e(t('workouts.add_favorite')) ?></button></form>
            <?php if ((int) ($exercise['is_system'] ?? 0) === 0): ?><a class="btn btn-ghost small workouts-exercise-edit" href="/?page=workouts&view=library&custom_exercise=<?= (int) $exercise['id'] ?><?= e($guideContextSuffix) ?>"><?= e(t('workouts.edit_custom')) ?></a><?php endif; ?>
            <form method="post" action="/?page=workouts" class="inline-form"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="exercise_clone"><input type="hidden" name="exercise_id" value="<?= (int) $exercise['id'] ?>"><?php if ($targetRoutineId > 0): ?><input type="hidden" name="target_routine_id" value="<?= $targetRoutineId ?>"><?php elseif ($targetSessionId > 0): ?><input type="hidden" name="target_session_id" value="<?= $targetSessionId ?>"><?php endif; ?><button class="btn <?= (int) ($exercise['is_system'] ?? 0) === 1 ? 'btn-primary' : 'btn-ghost' ?> small" type="submit"><?= e((int) ($exercise['is_system'] ?? 0) === 1 ? t('workouts.customize_exercise') : t('workouts.duplicate')) ?></button></form>
        </div>
    </article>

    <?php if ($exerciseImageUrl !== '' || $exerciseVideo !== null): ?>
        <?php
        $exerciseCoverMode = wk_normalize_exercise_cover_mode($exercise['cover_mode'] ?? 'auto');
        $workoutMediaViewer = [
            'id' => 'exercise-guide-media-' . (int) $exercise['id'],
            'image_url' => $exerciseImageUrl,
            'image_position' => $exercise['image_position'] ?? 'center',
            'images' => $exerciseGalleryImages,
            'video' => $exerciseVideo,
            'title' => (string) ($exercise['display_name'] ?? $exercise['name']),
            'mark' => $workoutExerciseMark($exercise),
            'default' => $exerciseVideo !== null && ($exerciseCoverMode === 'video' || $exerciseImageUrl === '') ? 'video' : 'photo',
            'show_header' => true,
        ];
        ?>
        <section class="panel workouts-guide-media" style="<?= e($exerciseAccentStyle) ?>" aria-label="<?= e(t('workouts.technique_media')) ?>">
            <?php require __DIR__ . '/partials/workout_exercise_media_viewer.php'; ?>
        </section>
    <?php endif; ?>

    <div class="workouts-guide-layout" style="<?= e($exerciseAccentStyle) ?>">
        <article class="panel workouts-guide-steps">
            <div class="panel-head"><div><h2><?= e(t('workouts.how_to')) ?></h2></div></div>
            <?php if ((array) ($content['steps'] ?? []) === []): ?><p class="muted"><?= e(t('workouts.no_data')) ?></p><?php else: ?><ol><?php foreach ((array) $content['steps'] as $index => $step): ?><li><span><?= $index + 1 ?></span><p><?= e((string) $step) ?></p></li><?php endforeach; ?></ol><?php endif; ?>
        </article>
        <aside class="stack">
            <?php foreach (['tips' => 'tips', 'mistakes' => 'mistakes'] as $contentKey => $labelKey): ?>
                <?php if ((array) ($content[$contentKey] ?? []) !== []): ?><article class="panel workouts-guide-note <?= e($contentKey) ?>"><h3><?= e(t('workouts.' . $labelKey)) ?></h3><?php foreach ((array) $content[$contentKey] as $note): ?><p><?= e((string) $note) ?></p><?php endforeach; ?></article><?php endif; ?>
            <?php endforeach; ?>
            <article class="panel workouts-guide-meta"><dl><div><dt><?= e(t('workouts.primary_muscle')) ?></dt><dd><?= e($muscleLabel((string) ($exercise['muscle_group'] ?? ''))) ?></dd></div><?php if ($secondaryMuscles !== []): ?><div><dt><?= e(t('workouts.secondary_muscles')) ?></dt><dd><?= e(implode(', ', array_map($muscleLabel, $secondaryMuscles))) ?></dd></div><?php endif; ?><div><dt><?= e(t('workouts.equipment')) ?></dt><dd><?= e($equipmentLabel((string) ($exercise['equipment'] ?? ''))) ?></dd></div></dl></article>
        </aside>
    </div>

    <article class="panel workouts-exercise-add-panel" style="<?= e($exerciseAccentStyle) ?>">
        <div><h2><?= e($targetSessionId > 0 ? t('workouts.add_to_session') : t('workouts.add_to_routine')) ?></h2><p class="muted"><?= e($targetSessionId > 0 ? t('workouts.add_to_session_hint') : t('workouts.new_routine_hint')) ?></p></div>
        <?php if ($targetSessionId > 0): ?>
            <?php $guideIsInTarget = in_array((int) $exercise['id'], $targetSessionExerciseIds, true); ?>
            <form method="post" action="/?page=workouts" class="workouts-exercise-add-form is-contextual">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="exercise_add_to_session"><input type="hidden" name="exercise_def_id" value="<?= (int) $exercise['id'] ?>"><input type="hidden" name="session_id" value="<?= $targetSessionId ?>"><input type="hidden" name="target_session_id" value="<?= $targetSessionId ?>">
                <strong><?= e((string) ($targetSession['title'] ?? '') !== '' ? (string) $targetSession['title'] : t('workouts.active_session')) ?></strong>
                <button class="btn <?= $guideIsInTarget ? 'btn-ghost is-added' : 'btn-primary' ?>" type="submit"<?= $guideIsInTarget ? ' disabled' : '' ?>><?= e($guideIsInTarget ? t('workouts.added_to_session') : t('workouts.add_to_session')) ?></button>
            </form>
        <?php elseif ($targetRoutineId > 0): ?>
            <?php $guideIsInTarget = in_array((int) $exercise['id'], $targetRoutineExerciseIds, true); ?>
            <form method="post" action="/?page=workouts" class="workouts-exercise-add-form is-contextual">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="exercise_add_to_routine"><input type="hidden" name="exercise_def_id" value="<?= (int) $exercise['id'] ?>"><input type="hidden" name="routine_id" value="<?= $targetRoutineId ?>"><input type="hidden" name="target_routine_id" value="<?= $targetRoutineId ?>">
                <strong><?= e((string) ($targetRoutine['name'] ?? '')) ?></strong>
                <button class="btn <?= $guideIsInTarget ? 'btn-ghost is-added' : 'btn-primary' ?>" type="submit"<?= $guideIsInTarget ? ' disabled' : '' ?>><?= e($guideIsInTarget ? t('workouts.added_to_routine') : t('workouts.add_to_routine')) ?></button>
            </form>
        <?php elseif ($activeRoutines !== []): ?>
            <form method="post" action="/?page=workouts" class="workouts-exercise-add-form">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="exercise_add_to_routine"><input type="hidden" name="exercise_def_id" value="<?= (int) $exercise['id'] ?>">
                <label><?= e(t('workouts.choose_routine')) ?><select name="routine_id" required><?php foreach ($activeRoutines as $routine): ?><option value="<?= (int) $routine['id'] ?>"><?= e((string) $routine['name']) ?></option><?php endforeach; ?></select></label>
                <label><?= e($exerciseIsCardio ? t('workouts.rounds') : t('workouts.target_sets')) ?><input type="number" name="target_sets" min="1" max="20" value="<?= (int) $exerciseTrainingDefaults['target_sets'] ?>"></label>
                <?php if ($exerciseIsCardio): ?><label><?= e(t('workouts.duration_minutes')) ?><input type="number" name="target_duration_minutes" min="0" max="1440" step="0.5" value="<?= e($exerciseDefaultDurationMinutes) ?>"></label><?php elseif ($exerciseIsIsometric): ?><label><?= e(t('workouts.duration_seconds')) ?><input type="number" name="target_duration_seconds" min="0" max="86400" value="<?= $exerciseDefaultDuration > 0 ? $exerciseDefaultDuration : '' ?>"></label><?php else: ?><label><?= e(t('workouts.target_reps')) ?><input type="number" name="target_reps" min="0" max="999" value="<?= $exerciseTrainingDefaults['target_reps'] !== null ? (int) $exerciseTrainingDefaults['target_reps'] : '' ?>"></label><?php endif; ?>
                <?php if ($exerciseTrainingDefaults['target_weight'] !== null): ?><input type="hidden" name="target_weight" value="<?= e((string) $exerciseTrainingDefaults['target_weight']) ?>"><?php endif; ?>
                <?php if ($exerciseTrainingDefaults['target_distance'] !== null): ?><input type="hidden" name="target_distance" value="<?= e((string) $exerciseTrainingDefaults['target_distance']) ?>"><?php endif; ?>
                <?php if ($exerciseTrainingDefaults['rest_seconds'] !== null): ?><input type="hidden" name="rest_seconds" value="<?= (int) $exerciseTrainingDefaults['rest_seconds'] ?>"><?php endif; ?>
                <input type="hidden" name="unit" value="<?= e((string) $exerciseTrainingDefaults['unit']) ?>"><?php if ($exerciseTrainingDefaults['notes'] !== ''): ?><input type="hidden" name="notes" value="<?= e((string) $exerciseTrainingDefaults['notes']) ?>"><?php endif; ?>
                <button class="btn btn-primary" type="submit"><?= e(t('workouts.add_to_routine')) ?></button>
            </form>
        <?php else: ?><button class="btn btn-primary" type="button" data-app-modal-open="wk-new-routine-modal"><?= e(t('workouts.new_routine')) ?></button><?php endif; ?>
    </article>

<?php elseif ($wkView === 'routine_exercise' && !empty($wkRoutine) && !empty($wkRoutineExercise)): ?>
    <?php
    $routine = (array) $wkRoutine;
    $routineExercise = (array) $wkRoutineExercise;
    $rid = (int) $routine['id'];
    $routineExerciseId = (int) $routineExercise['id'];
    $routineExerciseType = (string) ($routineExercise['exercise_type'] ?? 'strength');
    $isCardioTarget = $routineExerciseType === 'cardio';
    $isIsometricTarget = $routineExerciseType === 'isometric';
    $targetDurationSeconds = (int) ($routineExercise['target_duration'] ?? 0);
    $targetWeightValue = $routineExercise['target_weight'] !== null ? rtrim(rtrim(number_format((float) $routineExercise['target_weight'], 1, '.', ''), '0'), '.') : '';
    $targetDistanceValue = $routineExercise['target_distance'] !== null ? rtrim(rtrim(number_format((float) $routineExercise['target_distance'], 2, '.', ''), '0'), '.') : '';
    $routineExerciseCover = $workoutCoverAsset($routineExercise);
    $routineExerciseIsPersonal = (int) ($routineExercise['is_system'] ?? 1) === 0
        && (int) ($routineExercise['exercise_owner_id'] ?? 0) === (int) ($currentUser['id'] ?? 0);
    ?>
    <article class="panel workouts-routine-exercise-editor" style="<?= e($workoutExerciseStyle($routineExercise)) ?>">
        <header class="workouts-routine-exercise-editor-head">
            <span class="workouts-exercise-cover is-editor<?= $routineExerciseCover !== null ? ' has-media' : '' ?>" aria-hidden="true"><?php if ($routineExerciseCover !== null): ?><img src="<?= e((string) $routineExerciseCover['url']) ?>" alt="" style="object-position: <?= e($workoutCoverPosition($routineExerciseCover)) ?>"><?php else: ?><?= e($workoutExerciseMark($routineExercise)) ?><?php endif; ?><?php if (!empty($routineExerciseCover['has_video'])): ?><b>&#9654;</b><?php endif; ?></span>
            <div><p class="eyebrow"><?= e((string) $routine['name']) ?></p><h2><?= e((string) $routineExercise['exercise_name']) ?></h2><p><?= e($exerciseTypeLabel($routineExerciseType)) ?> · <?= e($muscleLabel((string) ($routineExercise['muscle_group'] ?? ''))) ?></p></div>
            <a class="btn btn-ghost small" href="/?page=workouts&exercise_id=<?= (int) $routineExercise['exercise_def_id'] ?>"><?= e(t('workouts.view_guide')) ?></a>
        </header>

        <section class="workouts-exercise-appearance">
            <div class="workouts-exercise-appearance-preview<?= $routineExerciseCover !== null ? ' has-media' : '' ?>" aria-hidden="true"><?php if ($routineExerciseCover !== null): ?><img src="<?= e((string) $routineExerciseCover['url']) ?>" alt="" style="object-position: <?= e($workoutCoverPosition($routineExerciseCover)) ?>"><?php else: ?><span><?= e($workoutExerciseMark($routineExercise)) ?></span><?php endif; ?><?php if (!empty($routineExerciseCover['has_video'])): ?><b>&#9654;</b><?php endif; ?></div>
            <div><p class="eyebrow"><?= e(t('workouts.exercise_appearance')) ?></p><strong><?= e(t('workouts.exercise_appearance_hint')) ?></strong><small><?= e(t('workouts.exercise_appearance_detail')) ?></small></div>
            <?php if ($routineExerciseIsPersonal): ?>
                <a class="btn btn-ghost small" href="/?page=workouts&view=library&custom_exercise=<?= (int) $routineExercise['exercise_def_id'] ?>&target_routine_id=<?= $rid ?>&target_routine_exercise_id=<?= $routineExerciseId ?>&editor_section=media"><?= e(t('workouts.edit_photo_video')) ?></a>
            <?php else: ?>
                <form method="post" action="/?page=workouts"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="routine_exercise_personalize"><input type="hidden" name="routine_id" value="<?= $rid ?>"><input type="hidden" name="routine_exercise_id" value="<?= $routineExerciseId ?>"><button class="btn btn-ghost small" type="submit"><?= e(t('workouts.make_it_yours')) ?></button></form>
            <?php endif; ?>
        </section>

        <form method="post" action="/?page=workouts" class="workouts-routine-exercise-form">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="routine_exercise_update"><input type="hidden" name="routine_id" value="<?= $rid ?>"><input type="hidden" name="routine_exercise_id" value="<?= $routineExerciseId ?>">
            <section class="workouts-target-section">
                <div><span>1</span><div><h3><?= e(t('workouts.training_target')) ?></h3><p><?= e(t('workouts.training_target_hint')) ?></p></div></div>
                <div class="workouts-target-grid">
                    <label><?= e($isCardioTarget ? t('workouts.rounds') : t('workouts.target_sets')) ?><input type="number" name="target_sets" min="1" max="20" value="<?= (int) ($routineExercise['target_sets'] ?? 3) ?>" inputmode="numeric" required></label>
                    <?php if ($isCardioTarget): ?>
                        <label><?= e(t('workouts.duration_minutes')) ?><input type="number" name="target_duration_minutes" min="0" max="1440" step="0.5" value="<?= $targetDurationSeconds > 0 ? e(rtrim(rtrim(number_format($targetDurationSeconds / 60, 1, '.', ''), '0'), '.')) : '' ?>" inputmode="decimal"></label>
                        <label><?= e(t('workouts.distance_km')) ?><input type="number" name="target_distance" min="0" max="99999" step="0.01" value="<?= e($targetDistanceValue) ?>" inputmode="decimal"></label>
                    <?php elseif ($isIsometricTarget): ?>
                        <label><?= e(t('workouts.duration_seconds')) ?><input type="number" name="target_duration_seconds" min="0" max="86400" value="<?= $targetDurationSeconds > 0 ? $targetDurationSeconds : '' ?>" inputmode="numeric"></label>
                        <label><?= e(t('workouts.target_weight')) ?><input type="number" name="target_weight" min="0" max="99999" step="0.5" value="<?= e($targetWeightValue) ?>" inputmode="decimal"></label>
                        <label><?= e(t('workouts.unit')) ?><select name="unit"><?php foreach (WK_UNITS as $unit): ?><option value="<?= e($unit) ?>"<?= (string) ($routineExercise['unit'] ?? 'kg') === $unit ? ' selected' : '' ?>><?= e(strtoupper($unit)) ?></option><?php endforeach; ?></select></label>
                    <?php else: ?>
                        <label><?= e(t('workouts.target_reps')) ?><input type="number" name="target_reps" min="0" max="999" value="<?= $routineExercise['target_reps'] !== null ? (int) $routineExercise['target_reps'] : '' ?>" inputmode="numeric"></label>
                        <label><?= e(t('workouts.target_weight')) ?><input type="number" name="target_weight" min="0" max="99999" step="0.5" value="<?= e($targetWeightValue) ?>" inputmode="decimal"></label>
                        <label><?= e(t('workouts.unit')) ?><select name="unit"><?php foreach (WK_UNITS as $unit): ?><option value="<?= e($unit) ?>"<?= (string) ($routineExercise['unit'] ?? 'kg') === $unit ? ' selected' : '' ?>><?= e(strtoupper($unit)) ?></option><?php endforeach; ?></select></label>
                    <?php endif; ?>
                </div>
            </section>

            <section class="workouts-target-section">
                <div><span>2</span><div><h3><?= e(t('workouts.recovery_notes')) ?></h3><p><?= e(t('workouts.recovery_notes_hint')) ?></p></div></div>
                <div class="workouts-target-grid is-secondary">
                    <label><?= e(t('workouts.rest_time')) ?><select name="rest_seconds"><option value=""><?= e(t('workouts.no_rest_target')) ?></option><?php foreach ([30, 45, 60, 90, 120, 180, 300] as $rest): ?><option value="<?= $rest ?>"<?= (int) ($routineExercise['rest_seconds'] ?? 0) === $rest ? ' selected' : '' ?>><?= e(t('workouts.seconds_short', ['count' => $rest])) ?></option><?php endforeach; ?></select></label>
                    <label class="workouts-target-notes"><?= e(t('workouts.coaching_notes')) ?><textarea name="notes" rows="4" maxlength="500" placeholder="<?= e(t('workouts.coaching_notes_placeholder')) ?>"><?= e((string) ($routineExercise['notes'] ?? '')) ?></textarea></label>
                </div>
            </section>

            <div class="workouts-target-actions"><a class="btn btn-ghost" href="/?page=workouts&routine_id=<?= $rid ?>"><?= e(t('common.cancel')) ?></a><button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button></div>
        </form>
    </article>

    <article class="panel workouts-routine-exercise-danger">
        <div><strong><?= e(t('workouts.remove_from_routine')) ?></strong><small><?= e(t('workouts.remove_from_routine_hint')) ?></small></div>
        <form method="post" action="/?page=workouts" onsubmit="return confirm('<?= e(t('common.confirm_delete')) ?>');"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="routine_remove_exercise"><input type="hidden" name="routine_id" value="<?= $rid ?>"><input type="hidden" name="routine_exercise_id" value="<?= $routineExerciseId ?>"><button class="btn btn-ghost btn-danger-ghost" type="submit"><?= e(t('workouts.remove_exercise')) ?></button></form>
    </article>

<?php elseif ($wkView === 'routine' && !empty($wkRoutine)): ?>
    <?php
    $r = (array) $wkRoutine;
    $rid = (int) $r['id'];
    $selectedRoutineIcon = wk_normalize_routine_icon($r['icon'] ?? 'dumbbell');
    $selectedRoutineAccent = wk_normalize_routine_color($r['accent_color'] ?? '#14b8a6');
    $selectedRoutineDays = wk_days_from_mask((string) ($r['recommended_days_mask'] ?? '0000000'));
    $selectedRoutineImagePath = trim((string) ($r['image_path'] ?? ''));
    $selectedRoutineImageUrl = $selectedRoutineImagePath !== '' ? media_url($selectedRoutineImagePath) : '';
    $selectedRoutineVideoUrl = trim((string) ($r['video_url'] ?? ''));
    $selectedRoutineVideo = $workoutVideoSource($selectedRoutineVideoUrl);
    $selectedRoutineCoverMode = wk_normalize_exercise_cover_mode($r['cover_mode'] ?? 'auto');
    $selectedRoutineImagePosition = wk_normalize_image_position($r['image_position'] ?? 'center');
    $selectedRoutineCover = $workoutCoverAsset($r);
    ?>

    <?php if ($routineSection === 'settings'): ?>
        <article class="panel workouts-routine-editor" style="--routine-accent: <?= e($selectedRoutineAccent) ?>">
            <div class="panel-head workouts-routine-settings-head"><div><p class="eyebrow"><?= e(t('workouts.routine_settings')) ?></p><h2><?= e((string) $r['name']) ?></h2><p class="muted"><?= e(t('workouts.routine_settings_hint')) ?></p></div></div>
            <form method="post" action="/?page=workouts" enctype="multipart/form-data" class="stack compact-form workouts-routine-form" data-workout-media-editor>
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="routine_update">
                <input type="hidden" name="routine_id" value="<?= $rid ?>">
                <label><?= e(t('workouts.routine_name')) ?><input type="text" name="name" value="<?= e((string) $r['name']) ?>" maxlength="80" required></label>
                <label><?= e(t('workouts.description')) ?><input type="text" name="description" value="<?= e((string) ($r['description'] ?? '')) ?>" maxlength="200"></label>
                <fieldset class="workouts-routine-identity-picker" data-routine-identity-picker data-workout-color-picker data-workout-color-property="--routine-accent" style="--routine-accent: <?= e($selectedRoutineAccent) ?>; --workout-accent: <?= e($selectedRoutineAccent) ?>">
                    <legend><?= e(t('workouts.routine_identity')) ?></legend>
                    <p class="muted small"><?= e(t('workouts.routine_identity_hint')) ?></p>
                    <div class="workouts-routine-icon-options" aria-label="<?= e(t('workouts.routine_icon')) ?>">
                        <?php foreach ($routineIconOptions as $iconKey => $iconLabelKey): ?><label title="<?= e(t($iconLabelKey)) ?>"><input type="radio" name="icon" value="<?= e($iconKey) ?>"<?= $selectedRoutineIcon === $iconKey ? ' checked' : '' ?>><span><?= activity_icon_svg($iconKey) ?><small><?= e(t($iconLabelKey)) ?></small></span></label><?php endforeach; ?>
                    </div>
                    <div class="workouts-routine-color-options" aria-label="<?= e(t('workouts.routine_color')) ?>">
                        <?php foreach ($routineColorOptions as $colorKey => $colorValue): ?><label title="<?= e(t('workouts.color_' . $colorKey)) ?>"><input type="radio" name="accent_color_preset" value="<?= e($colorValue) ?>" data-routine-color-preset data-workout-color-preset<?= $selectedRoutineAccent === $colorValue ? ' checked' : '' ?>><span style="--swatch: <?= e($colorValue) ?>"><span class="sr-only"><?= e(t('workouts.color_' . $colorKey)) ?></span></span></label><?php endforeach; ?>
                    </div>
                    <label class="workouts-routine-custom-color"><span><strong><?= e(t('workouts.custom_color')) ?></strong><small><?= e(t('workouts.custom_color_hint')) ?></small></span><span class="workouts-routine-custom-color-control"><input type="color" name="accent_color" value="<?= e($selectedRoutineAccent) ?>" data-routine-color-input data-workout-color-input aria-label="<?= e(t('workouts.custom_color')) ?>"><output data-routine-color-output data-workout-color-output><?= e(strtoupper($selectedRoutineAccent)) ?></output></span></label>
                </fieldset>
                <fieldset class="workouts-routine-media-settings">
                    <legend><?= e(t('workouts.routine_media')) ?></legend>
                    <p class="muted small"><?= e(t('workouts.routine_media_hint')) ?></p>
                    <fieldset class="workouts-custom-cover-picker" data-workout-cover-picker>
                        <legend><?= e(t('workouts.routine_cover')) ?></legend>
                        <p><?= e(t('workouts.routine_cover_hint')) ?></p>
                        <div>
                            <?php foreach (['auto' => ['spark', 'workouts.cover_auto'], 'photo' => ['image', 'workouts.cover_photo'], 'video' => ['play', 'workouts.cover_video'], 'simple' => ['simple', 'workouts.cover_simple']] as $coverMode => [$coverIcon, $coverLabelKey]): ?>
                                <label><input type="radio" name="cover_mode" value="<?= e($coverMode) ?>"<?= $selectedRoutineCoverMode === $coverMode ? ' checked' : '' ?>><span><?php if ($coverIcon === 'play'): ?><b aria-hidden="true">&#9654;</b><?php elseif ($coverIcon === 'simple'): ?><b aria-hidden="true">AB</b><?php else: ?><?= activity_icon_svg($coverIcon) ?><?php endif; ?><strong><?= e(t($coverLabelKey)) ?></strong></span></label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                    <div class="workouts-custom-media-grid">
                        <div class="workouts-custom-media-control">
                            <label><?= e(t('workouts.routine_photo')) ?><input type="file" name="routine_image" accept="image/jpeg,image/png,image/webp" data-workout-image-input></label>
                            <small><?= e(t('workouts.routine_photo_hint')) ?></small>
                            <?php if ($selectedRoutineImageUrl !== ''): ?><label class="check"><input type="checkbox" name="remove_routine_image" value="1" data-workout-remove-image><?= e(t('workouts.remove_routine_photo')) ?></label><?php endif; ?>
                        </div>
                        <div class="workouts-custom-media-control">
                            <label><?= e(t('workouts.routine_video')) ?><input type="url" name="video_url" value="<?= e($selectedRoutineVideoUrl) ?>" placeholder="https://www.youtube.com/watch?v=..." inputmode="url" data-workout-video-input></label>
                            <small><?= e(t('workouts.routine_video_hint')) ?></small>
                            <button class="btn btn-ghost small" type="button" data-workout-clear-video><?= e(t('common.clear')) ?></button>
                        </div>
                    </div>
                    <fieldset class="workouts-image-focus-picker" data-workout-image-position-picker>
                        <legend><?= e(t('workouts.image_focus')) ?></legend>
                        <p><?= e(t('workouts.image_focus_hint')) ?></p>
                        <div class="workouts-image-focus-presets">
                            <?php foreach (['top' => ['&#8593;', 'workouts.focus_top'], 'left' => ['&#8592;', 'workouts.focus_left'], 'center' => ['&#9679;', 'workouts.focus_center'], 'right' => ['&#8594;', 'workouts.focus_right'], 'bottom' => ['&#8595;', 'workouts.focus_bottom']] as $position => [$positionIcon, $positionLabelKey]): ?>
                                <label><input type="radio" name="image_position" value="<?= e($position) ?>" data-workout-image-position-input<?= $selectedRoutineImagePosition === $position ? ' checked' : '' ?>><span><b aria-hidden="true"><?= $positionIcon ?></b><strong><?= e(t($positionLabelKey)) ?></strong></span></label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                    <div class="workouts-custom-preview" aria-label="<?= e(t('workouts.media_preview')) ?>">
                        <figure data-workout-image-preview-wrap<?= $selectedRoutineImageUrl === '' ? ' hidden' : '' ?>><img src="<?= e($selectedRoutineImageUrl) ?>" alt="" data-workout-image-preview style="object-position: <?= e(wk_image_position_css($selectedRoutineImagePosition)) ?>"></figure>
                        <div class="workouts-custom-preview-empty" data-workout-image-empty<?= $selectedRoutineImageUrl !== '' ? ' hidden' : '' ?>><span aria-hidden="true">&#9638;</span><strong><?= e(t('workouts.photo_preview')) ?></strong></div>
                        <div class="workouts-custom-video-preview" data-workout-video-preview data-video-title="<?= e((string) $r['name']) ?>" data-empty-label="<?= e(t('workouts.video_preview')) ?>"></div>
                    </div>
                </fieldset>
                <fieldset class="workouts-day-picker"><legend><?= e(t('workouts.select_days')) ?></legend><div>
                    <?php foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $day): ?><label><input type="checkbox" name="days[]" value="<?= e($day) ?>"<?= in_array($day, $selectedRoutineDays, true) ? ' checked' : '' ?>><span><?= e($dayLabel($day)) ?></span></label><?php endforeach; ?>
                </div></fieldset>
                <div class="inline-actions"><button type="submit" class="btn btn-primary"><?= e(t('common.save')) ?></button><a class="btn btn-ghost" href="/?page=workouts&routine_id=<?= $rid ?>"><?= e(t('common.cancel')) ?></a></div>
            </form>
        </article>

        <article class="panel workouts-routine-danger">
            <div><strong><?= e(t('workouts.routine_management')) ?></strong><small><?= e(t('workouts.routine_management_hint')) ?></small></div>
            <div class="inline-actions">
                <form method="post" action="/?page=workouts" class="inline-form"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="routine_archive"><input type="hidden" name="routine_id" value="<?= $rid ?>"><input type="hidden" name="value" value="1"><button type="submit" class="btn btn-ghost small"><?= e(t('workouts.archive')) ?></button></form>
                <form method="post" action="/?page=workouts" class="inline-form" onsubmit="return confirm('<?= e(t('common.confirm_delete')) ?>');"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="routine_delete"><input type="hidden" name="routine_id" value="<?= $rid ?>"><button type="submit" class="btn btn-ghost small btn-danger-ghost"><?= e(t('workouts.delete_routine')) ?></button></form>
            </div>
        </article>
    <?php elseif ($routineSection === 'organize'): ?>
        <?php $organizerExercises = array_values((array) ($wkRoutineExercises ?? [])); ?>
        <article class="panel workouts-routine-organizer" style="--routine-accent: <?= e($selectedRoutineAccent) ?>">
            <div class="panel-head workouts-routine-organizer-head">
                <div><p class="eyebrow"><?= e(t('workouts.organize_routine')) ?></p><h2><?= e((string) $r['name']) ?></h2><p class="muted"><?= e(t('workouts.organize_routine_hint')) ?></p></div>
            </div>
            <?php if ($organizerExercises === []): ?>
                <div class="workouts-routine-empty"><span aria-hidden="true">+</span><div><strong><?= e(t('workouts.no_exercises')) ?></strong><small><?= e(t('workouts.add_exercises_hint')) ?></small></div></div>
                <div class="workouts-routine-organizer-actions"><a class="btn btn-ghost" href="/?page=workouts&routine_id=<?= $rid ?>"><?= e(t('common.back')) ?></a><a class="btn btn-primary" href="/?page=workouts&view=library&target_routine_id=<?= $rid ?>"><?= e(t('workouts.add_exercises')) ?></a></div>
            <?php else: ?>
                <form method="post" action="/?page=workouts" data-exercise-organizer data-routine-exercise-organizer>
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="routine_exercises_reorder">
                    <input type="hidden" name="routine_id" value="<?= $rid ?>">
                    <ol class="workouts-routine-organizer-list" data-exercise-organizer-list data-routine-exercise-list>
                        <?php foreach ($organizerExercises as $index => $ex): ?>
                            <?php $organizerCover = $workoutCoverAsset((array) $ex); $organizerName = (string) ($ex['exercise_name'] ?? ''); ?>
                            <li class="workouts-routine-organizer-item" style="<?= e($workoutExerciseStyle((array) $ex)) ?>" data-exercise-organizer-item data-routine-exercise-item data-exercise-name="<?= e($organizerName) ?>">
                                <input type="hidden" name="order[]" value="<?= (int) $ex['id'] ?>">
                                <span class="workouts-routine-organizer-position" aria-label="<?= e(t('workouts.position')) ?>"><span data-exercise-organizer-position data-routine-exercise-position><?= $index + 1 ?></span></span>
                                <span class="workouts-exercise-cover<?= $organizerCover !== null ? ' has-media' : '' ?>" aria-hidden="true"><?php if ($organizerCover !== null): ?><img src="<?= e((string) $organizerCover['url']) ?>" alt="" style="object-position: <?= e($workoutCoverPosition($organizerCover)) ?>"><?php else: ?><?= e($workoutExerciseMark((array) $ex)) ?><?php endif; ?><?php if (!empty($organizerCover['has_video'])): ?><b>&#9654;</b><?php endif; ?></span>
                                <div class="workouts-routine-organizer-copy"><strong><?= e($organizerName) ?></strong><small><?= e($exerciseTypeLabel((string) ($ex['exercise_type'] ?? 'strength'))) ?><?php if ($muscleLabel((string) ($ex['muscle_group'] ?? '')) !== ''): ?> · <?= e($muscleLabel((string) $ex['muscle_group'])) ?><?php endif; ?></small></div>
                                <div class="workouts-routine-organizer-controls">
                                    <button class="btn btn-ghost btn-icon" type="button" data-exercise-organizer-move="up" data-routine-exercise-move="up" data-announcement="<?= e(t('workouts.moved_up', ['name' => $organizerName])) ?>" aria-label="<?= e(t('workouts.move_up')) ?>: <?= e($organizerName) ?>"<?= $index === 0 ? ' disabled' : '' ?>>&uarr;</button>
                                    <button class="btn btn-ghost btn-icon" type="button" data-exercise-organizer-move="down" data-routine-exercise-move="down" data-announcement="<?= e(t('workouts.moved_down', ['name' => $organizerName])) ?>" aria-label="<?= e(t('workouts.move_down')) ?>: <?= e($organizerName) ?>"<?= $index === count($organizerExercises) - 1 ? ' disabled' : '' ?>>&darr;</button>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                    <p class="sr-only" data-exercise-organizer-status data-routine-exercise-status aria-live="polite"></p>
                    <div class="workouts-routine-organizer-actions"><a class="btn btn-ghost" href="/?page=workouts&routine_id=<?= $rid ?>"><?= e(t('common.cancel')) ?></a><button class="btn btn-primary" type="submit"><?= e(t('workouts.save_order')) ?></button></div>
                </form>
            <?php endif; ?>
        </article>
    <?php else: ?>
        <article class="panel workouts-routine-summary<?= $selectedRoutineCover !== null ? ' has-cover' : '' ?>" style="--routine-accent: <?= e($selectedRoutineAccent) ?>">
            <?php if ($selectedRoutineCover !== null): ?><div class="workouts-routine-summary-cover"><img src="<?= e((string) $selectedRoutineCover['url']) ?>" alt="" style="object-position: <?= e($workoutCoverPosition($selectedRoutineCover)) ?>"><?php if (!empty($selectedRoutineCover['has_video'])): ?><span aria-hidden="true">&#9654;</span><?php endif; ?></div><?php endif; ?>
            <div class="workouts-routine-summary-main">
                <span class="workouts-routine-summary-icon" aria-hidden="true"><?= activity_icon_svg($selectedRoutineIcon) ?></span>
                <div><p class="eyebrow"><?= e(t('workouts.routine_overview')) ?></p><h2><?= e((string) $r['name']) ?></h2><?php if (trim((string) ($r['description'] ?? '')) !== ''): ?><p><?= e((string) $r['description']) ?></p><?php endif; ?></div>
            </div>
            <?php if ($selectedRoutineDays !== []): ?><div class="workouts-routine-summary-days" aria-label="<?= e(t('workouts.select_days')) ?>"><?php foreach ($selectedRoutineDays as $day): ?><span><?= e($dayLabel($day)) ?></span><?php endforeach; ?></div><?php endif; ?>
            <div class="workouts-routine-summary-actions"><a class="btn btn-primary" href="/?page=workouts&view=library&target_routine_id=<?= $rid ?>">+ <?= e(t('workouts.add_exercises')) ?></a><a class="btn btn-ghost" href="/?page=workouts&routine_id=<?= $rid ?>&section=settings"><?= e(t('workouts.edit_routine')) ?></a><?php if ($selectedRoutineVideo !== null): ?><a class="btn btn-ghost" href="<?= e($selectedRoutineVideoUrl) ?>" target="_blank" rel="noopener noreferrer"><?= e(t('workouts.watch_routine_video')) ?> &nearr;</a><?php endif; ?></div>
        </article>

        <article class="panel workouts-routine-exercises-panel">
            <?php $routineExerciseCount = count((array) ($wkRoutineExercises ?? [])); ?>
            <div class="panel-head"><div><p class="eyebrow"><?= e(t($routineExerciseCount === 1 ? 'workouts.exercise_count_one' : 'workouts.exercise_count', ['count' => $routineExerciseCount])) ?></p><h2><?= e(t('workouts.exercises')) ?></h2></div>
                <div class="inline-actions workouts-routine-exercises-actions"><?php if ($routineExerciseCount > 1): ?><a class="btn btn-ghost small" href="/?page=workouts&routine_id=<?= $rid ?>&section=organize"><?= e(t('workouts.organize')) ?></a><?php endif; ?><form method="post" action="/?page=workouts" class="inline-form"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="session_start"><input type="hidden" name="routine_id" value="<?= $rid ?>"><button type="submit" class="btn btn-primary small"<?= !empty($wkActiveSession) ? ' disabled title="' . e(t('workouts.finish_active_first')) . '"' : '' ?>><?= e(t('workouts.start_routine')) ?></button></form></div>
            </div>
            <?php if (($wkRoutineExercises ?? []) === []): ?>
                <div class="workouts-routine-empty"><span aria-hidden="true">+</span><div><strong><?= e(t('workouts.no_exercises')) ?></strong><small><?= e(t('workouts.add_exercises_hint')) ?></small></div></div>
            <?php else: ?>
                <ul class="workouts-exercise-list">
                    <?php foreach ((array) $wkRoutineExercises as $ex): ?>
                        <?php
                        $routineTargetParts = [];
                        $routineTargetType = (string) ($ex['exercise_type'] ?? 'strength');
                        $routineTargetParts[] = e($exerciseTypeLabel($routineTargetType));
                        if ($muscleLabel((string) ($ex['muscle_group'] ?? '')) !== '') {
                            $routineTargetParts[] = e($muscleLabel((string) $ex['muscle_group']));
                        }
                        if ($routineTargetType === 'cardio') {
                            $cardioTarget = (int) ($ex['target_sets'] ?? 1) . '×';
                            if ((int) ($ex['target_duration'] ?? 0) > 0) {
                                $durationMinutes = rtrim(rtrim(number_format((int) $ex['target_duration'] / 60, 1, '.', ''), '0'), '.');
                                $cardioTarget .= e(t('workouts.minutes_short', ['count' => $durationMinutes]));
                            } else {
                                $cardioTarget .= '—';
                            }
                            if ($ex['target_distance'] !== null) {
                                $cardioTarget .= ' · ' . e(rtrim(rtrim(number_format((float) $ex['target_distance'], 2, '.', ''), '0'), '.')) . ' km';
                            }
                            $routineTargetParts[] = $cardioTarget;
                        } elseif ($routineTargetType === 'isometric') {
                            $routineTargetParts[] = (int) ($ex['target_sets'] ?? 1) . '×' . ((int) ($ex['target_duration'] ?? 0) > 0 ? e(t('workouts.seconds_short', ['count' => (int) $ex['target_duration']])) : '—');
                        } else {
                            $strengthTarget = (int) ($ex['target_sets'] ?? 1) . '×' . ($ex['target_reps'] !== null ? (int) $ex['target_reps'] : '—');
                            if ($ex['target_weight'] !== null) {
                                $strengthTarget .= ' @ ' . e(rtrim(rtrim(number_format((float) $ex['target_weight'], 1, '.', ''), '0'), '.')) . e((string) $ex['unit']);
                            }
                            $routineTargetParts[] = $strengthTarget;
                        }
                        if ((int) ($ex['rest_seconds'] ?? 0) > 0) {
                            $routineTargetParts[] = e(t('workouts.rest_short', ['count' => (int) $ex['rest_seconds']]));
                        }
                        $routineRowCover = $workoutCoverAsset((array) $ex);
                        ?>
                        <li class="workouts-exercise-row" style="<?= e($workoutExerciseStyle((array) $ex)) ?>">
                            <span class="workouts-exercise-cover<?= $routineRowCover !== null ? ' has-media' : '' ?>" aria-hidden="true"><?php if ($routineRowCover !== null): ?><img src="<?= e((string) $routineRowCover['url']) ?>" alt="" style="object-position: <?= e($workoutCoverPosition($routineRowCover)) ?>"><?php else: ?><?= e($workoutExerciseMark((array) $ex)) ?><?php endif; ?><?php if (!empty($routineRowCover['has_video'])): ?><b>&#9654;</b><?php endif; ?></span>
                            <div class="workouts-exercise-info">
                                <strong><a href="/?page=workouts&exercise_id=<?= (int) $ex['exercise_def_id'] ?>"><?= e((string) $ex['exercise_name']) ?></a></strong>
                                <span class="muted small"><?= implode(' · ', $routineTargetParts) ?></span>
                                <?php if (trim((string) ($ex['notes'] ?? '')) !== ''): ?><span class="muted small workouts-routine-exercise-summary"><?= e((string) $ex['notes']) ?></span><?php endif; ?>
                            </div>
                            <a class="btn btn-ghost small btn-icon workouts-routine-exercise-edit" href="/?page=workouts&routine_id=<?= $rid ?>&routine_exercise_id=<?= (int) $ex['id'] ?>" aria-label="<?= e(t('workouts.edit_exercise_target')) ?>">&rsaquo;</a>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <a class="workouts-routine-add-more" href="/?page=workouts&view=library&target_routine_id=<?= $rid ?>"><span aria-hidden="true">+</span><strong><?= e(t('workouts.add_exercises')) ?></strong><small><?= e(t('workouts.open_library')) ?></small></a>
            <?php endif; ?>
        </article>
    <?php endif; ?>

<?php elseif ($wkView === 'session' && !empty($wkSession)): ?>
    <?php
    $s = (array) $wkSession;
    $sid = (int) $s['id'];
    $sessionDone = (string) $s['status'] !== 'active';
    $sessionRoutineAccent = wk_normalize_routine_color($s['routine_accent_color'] ?? '#14b8a6');
    $sessionRoutineCover = $workoutCoverAsset([
        'image_path' => $s['routine_image_path'] ?? null,
        'video_url' => $s['routine_video_url'] ?? null,
        'cover_mode' => $s['routine_cover_mode'] ?? 'auto',
        'image_position' => $s['routine_image_position'] ?? 'center',
    ]);
    $sessionExercises = array_values((array) ($wkSessionExercises ?? []));
    $activeSessionExerciseId = (int) ($wkSessionExerciseId ?? ($sessionExercises[0]['id'] ?? 0));
    $sessionExerciseIsComplete = static function (array $exercise): bool {
        $sets = (array) ($exercise['sets'] ?? []);
        return $sets !== [] && count(array_filter(
            $sets,
            static fn(array $set): bool => (int) ($set['completed'] ?? 0) !== 1
        )) === 0;
    };
    $activeSessionExerciseIndex = 0;
    $completedSessionExerciseCount = 0;
    foreach ($sessionExercises as $sessionExerciseIndex => $sessionExercise) {
        if ((int) ($sessionExercise['id'] ?? 0) === $activeSessionExerciseId) {
            $activeSessionExerciseIndex = (int) $sessionExerciseIndex;
        }
        if ($sessionExerciseIsComplete((array) $sessionExercise)) {
            $completedSessionExerciseCount++;
        }
    }
    $activeSessionExercise = (array) ($sessionExercises[$activeSessionExerciseIndex] ?? []);
    $previousSessionExercise = $activeSessionExerciseIndex > 0 ? (array) $sessionExercises[$activeSessionExerciseIndex - 1] : null;
    $nextSessionExercise = isset($sessionExercises[$activeSessionExerciseIndex + 1]) ? (array) $sessionExercises[$activeSessionExerciseIndex + 1] : null;
    $sessionHasRestTargets = count(array_filter(
        $sessionExercises,
        static fn(array $exercise): bool => (int) ($exercise['rest_seconds'] ?? 0) > 0
    )) > 0;
    $sessionExerciseUrl = static fn(int $exerciseRowId): string => '/?' . http_build_query([
        'page' => 'workouts',
        'session_id' => $sid,
        'session_exercise_id' => $exerciseRowId,
    ]);
    ?>
    <?php if ($sessionSection === 'organize' && !$sessionDone): ?>
        <article class="panel workouts-routine-organizer workouts-session-organizer">
            <div class="panel-head workouts-routine-organizer-head">
                <div><p class="eyebrow"><?= e(t('workouts.organize_session')) ?></p><h2><?= e((string) ($s['title'] ?? '') !== '' ? (string) $s['title'] : t('workouts.session')) ?></h2><p class="muted"><?= e(t('workouts.organize_session_hint')) ?></p></div>
            </div>
            <?php if ($sessionExercises === []): ?>
                <div class="workouts-routine-empty"><span aria-hidden="true">+</span><div><strong><?= e(t('workouts.no_exercises')) ?></strong><small><?= e(t('workouts.add_from_library_hint')) ?></small></div></div>
                <div class="workouts-routine-organizer-actions"><a class="btn btn-ghost" href="/?page=workouts&session_id=<?= $sid ?>"><?= e(t('common.back')) ?></a><a class="btn btn-primary" href="/?page=workouts&view=library&target_session_id=<?= $sid ?>"><?= e(t('workouts.add_exercise')) ?></a></div>
            <?php else: ?>
                <form method="post" action="/?page=workouts" data-exercise-organizer data-session-exercise-organizer>
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="session_exercises_organize">
                    <input type="hidden" name="session_id" value="<?= $sid ?>">
                    <ol class="workouts-routine-organizer-list" data-exercise-organizer-list data-session-exercise-list>
                        <?php foreach ($sessionExercises as $index => $ex): ?>
                            <?php
                            $sessionOrganizerCover = $workoutCoverAsset((array) $ex);
                            $sessionOrganizerName = (string) ($ex['exercise_name'] ?? '');
                            $sessionOrganizerHasCompletedSets = count(array_filter(
                                (array) ($ex['sets'] ?? []),
                                static fn(array $set): bool => (int) ($set['completed'] ?? 0) === 1
                            )) > 0;
                            ?>
                            <li class="workouts-routine-organizer-item workouts-session-organizer-item" style="<?= e($workoutExerciseStyle((array) $ex)) ?>" data-exercise-organizer-item data-session-exercise-item data-exercise-name="<?= e($sessionOrganizerName) ?>">
                                <input type="hidden" name="order[]" value="<?= (int) $ex['id'] ?>">
                                <span class="workouts-routine-organizer-position" aria-label="<?= e(t('workouts.position')) ?>"><span data-exercise-organizer-position data-session-exercise-position><?= $index + 1 ?></span></span>
                                <span class="workouts-exercise-cover<?= $sessionOrganizerCover !== null ? ' has-media' : '' ?>" aria-hidden="true"><?php if ($sessionOrganizerCover !== null): ?><img src="<?= e((string) $sessionOrganizerCover['url']) ?>" alt="" style="object-position: <?= e($workoutCoverPosition($sessionOrganizerCover)) ?>"><?php else: ?><?= e($workoutExerciseMark((array) $ex)) ?><?php endif; ?><?php if (!empty($sessionOrganizerCover['has_video'])): ?><b>&#9654;</b><?php endif; ?></span>
                                <div class="workouts-routine-organizer-copy"><strong><?= e($sessionOrganizerName) ?></strong><small><?= e($exerciseTypeLabel((string) ($ex['exercise_type'] ?? 'strength'))) ?><?php if ($muscleLabel((string) ($ex['muscle_group'] ?? '')) !== ''): ?> · <?= e($muscleLabel((string) $ex['muscle_group'])) ?><?php endif; ?></small></div>
                                <div class="workouts-routine-organizer-controls workouts-session-organizer-controls">
                                    <button class="btn btn-ghost btn-icon" type="button" data-exercise-organizer-move="up" data-session-exercise-move="up" data-announcement="<?= e(t('workouts.moved_up', ['name' => $sessionOrganizerName])) ?>" aria-label="<?= e(t('workouts.move_up')) ?>: <?= e($sessionOrganizerName) ?>"<?= $index === 0 ? ' disabled' : '' ?>>&uarr;</button>
                                    <button class="btn btn-ghost btn-icon" type="button" data-exercise-organizer-move="down" data-session-exercise-move="down" data-announcement="<?= e(t('workouts.moved_down', ['name' => $sessionOrganizerName])) ?>" aria-label="<?= e(t('workouts.move_down')) ?>: <?= e($sessionOrganizerName) ?>"<?= $index === count($sessionExercises) - 1 ? ' disabled' : '' ?>>&darr;</button>
                                    <label class="workouts-session-organizer-remove<?= $sessionOrganizerHasCompletedSets ? ' is-locked' : '' ?>" title="<?= e($sessionOrganizerHasCompletedSets ? t('workouts.completed_sets_protected') : t('workouts.remove_on_save')) ?>">
                                        <input type="checkbox" name="remove[]" value="<?= (int) $ex['id'] ?>" data-exercise-organizer-remove data-announcement="<?= e(t('workouts.marked_for_removal', ['name' => $sessionOrganizerName])) ?>"<?= $sessionOrganizerHasCompletedSets ? ' disabled' : '' ?>>
                                        <span aria-hidden="true">&times;</span><span class="sr-only"><?= e($sessionOrganizerHasCompletedSets ? t('workouts.completed_sets_protected') : t('workouts.remove_on_save')) ?>: <?= e($sessionOrganizerName) ?></span>
                                    </label>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                    <p class="workouts-session-organizer-note"><?= e(t('workouts.completed_sets_protected')) ?></p>
                    <p class="sr-only" data-exercise-organizer-status data-session-exercise-status aria-live="polite"></p>
                    <div class="workouts-routine-organizer-actions"><a class="btn btn-ghost" href="/?page=workouts&session_id=<?= $sid ?>"><?= e(t('common.cancel')) ?></a><button class="btn btn-primary" type="submit"><?= e(t('workouts.save_session')) ?></button></div>
                </form>
            <?php endif; ?>
        </article>
    <?php else: ?>
    <article class="panel workouts-session-panel<?= $sessionRoutineCover !== null ? ' has-routine-cover' : '' ?>" style="--routine-accent: <?= e($sessionRoutineAccent) ?>">
        <div class="panel-head workouts-session-panel-head">
            <div class="workouts-session-title"><?php if ($sessionRoutineCover !== null): ?><span class="workouts-session-routine-cover" aria-hidden="true"><img src="<?= e((string) $sessionRoutineCover['url']) ?>" alt="" style="object-position: <?= e($workoutCoverPosition($sessionRoutineCover)) ?>"><?php if (!empty($sessionRoutineCover['has_video'])): ?><b>&#9654;</b><?php endif; ?></span><?php endif; ?><div><p class="eyebrow"><?= e(t('workouts.active_session')) ?></p><h2><?= e((string) ($s['title'] ?? '') !== '' ? (string) $s['title'] : t('workouts.session')) ?></h2></div></div>
            <?php if (!$sessionDone && $sessionExercises !== []): ?><a class="btn btn-ghost small workouts-session-organize-link" href="/?page=workouts&session_id=<?= $sid ?>&section=organize"><?= e(t('workouts.edit_session')) ?></a><?php endif; ?>
        </div>

        <?php if ($sessionExercises === []): ?>
            <p class="muted"><?= e(t('workouts.no_exercises')) ?></p>
        <?php else: ?>
            <?php if (count($sessionExercises) > 1): ?>
                <?php $activeSessionCover = $workoutCoverAsset($activeSessionExercise); ?>
                <nav class="workouts-session-exercise-nav" style="<?= e($workoutExerciseStyle($activeSessionExercise)) ?>" aria-label="<?= e(t('workouts.session_exercise_navigation')) ?>">
                    <div class="workouts-session-exercise-nav-main">
                        <?php if ($previousSessionExercise !== null): ?><a href="<?= e($sessionExerciseUrl((int) $previousSessionExercise['id'])) ?>" aria-label="<?= e(t('common.previous')) ?>">&larr;</a><?php else: ?><span aria-hidden="true">&larr;</span><?php endif; ?>
                        <div class="workouts-session-exercise-nav-current">
                            <span class="workouts-exercise-cover<?= $activeSessionCover !== null ? ' has-media' : '' ?>" aria-hidden="true"><?php if ($activeSessionCover !== null): ?><img src="<?= e((string) $activeSessionCover['url']) ?>" alt="" style="object-position: <?= e($workoutCoverPosition($activeSessionCover)) ?>"><?php else: ?><?= e($workoutExerciseMark($activeSessionExercise)) ?><?php endif; ?><?php if (!empty($activeSessionCover['has_video'])): ?><b>&#9654;</b><?php endif; ?></span>
                            <div><small><?= e(t('workouts.exercise_of', ['current' => $activeSessionExerciseIndex + 1, 'total' => count($sessionExercises)])) ?></small><strong><?= e((string) ($activeSessionExercise['exercise_name'] ?? '')) ?></strong><span><?= e(t('workouts.session_progress', ['completed' => $completedSessionExerciseCount, 'total' => count($sessionExercises)])) ?></span></div>
                        </div>
                        <?php if ($nextSessionExercise !== null): ?><a href="<?= e($sessionExerciseUrl((int) $nextSessionExercise['id'])) ?>" aria-label="<?= e(t('common.next')) ?>">&rarr;</a><?php else: ?><span aria-hidden="true">&rarr;</span><?php endif; ?>
                    </div>
                    <div class="workouts-session-exercise-rail" aria-label="<?= e(t('workouts.session_progress', ['completed' => $completedSessionExerciseCount, 'total' => count($sessionExercises)])) ?>">
                        <?php foreach ($sessionExercises as $sessionExerciseIndex => $sessionExercise): ?>
                            <?php $sessionRailId = (int) ($sessionExercise['id'] ?? 0); ?>
                            <a class="<?= $sessionRailId === $activeSessionExerciseId ? 'is-active ' : '' ?><?= $sessionExerciseIsComplete((array) $sessionExercise) ? 'is-complete' : '' ?>" style="<?= e($workoutExerciseStyle((array) $sessionExercise)) ?>" href="<?= e($sessionExerciseUrl($sessionRailId)) ?>" aria-label="<?= e((string) ($sessionExercise['exercise_name'] ?? '')) ?>"<?= $sessionRailId === $activeSessionExerciseId ? ' aria-current="step"' : '' ?>><span><?= $sessionExerciseIndex + 1 ?></span></a>
                        <?php endforeach; ?>
                    </div>
                </nav>
            <?php endif; ?>
            <?php if (!$sessionDone && $sessionHasRestTargets): ?>
                <?php
                $workoutRestTimerSessionId = $sid;
                $workoutRestTimerDefaultSeconds = max(0, (int) ($activeSessionExercise['rest_seconds'] ?? 0));
                require __DIR__ . '/partials/workout_rest_timer.php';
                ?>
            <?php endif; ?>
            <div class="workouts-session-exercises">
                <?php foreach ($sessionExercises as $sessionExerciseIndex => $ex): ?>
                    <?php
                    $sessionExerciseType = (string) ($ex['exercise_type'] ?? 'strength');
                    $sessionIsCardio = $sessionExerciseType === 'cardio';
                    $sessionIsIsometric = $sessionExerciseType === 'isometric';
                    $sessionExerciseCover = $workoutCoverAsset((array) $ex);
                    $sessionExerciseGallery = (array) (($wkSessionExerciseMedia ?? [])[(int) ($ex['exercise_def_id'] ?? 0)] ?? []);
                    $sessionExerciseGalleryImages = array_map(static fn(array $item): array => [
                        'url' => media_url((string) ($item['path'] ?? '')),
                        'position' => $item['position'] ?? 'center',
                        'caption' => $item['caption'] ?? '',
                    ], $sessionExerciseGallery);
                    $sessionExerciseImagePath = trim((string) ($ex['image_path'] ?? ''));
                    $sessionExerciseImageUrl = $sessionExerciseImagePath !== '' ? media_url($sessionExerciseImagePath) : '';
                    $sessionExerciseVideo = $workoutVideoSource((string) ($ex['video_url'] ?? ''));
                    $sessionExerciseHasPhoto = $sessionExerciseImageUrl !== '';
                    $sessionExerciseHasVideo = $sessionExerciseVideo !== null;
                    $sessionExerciseMediaLabel = $sessionExerciseHasPhoto && $sessionExerciseHasVideo
                        ? t('workouts.media_photo_and_video')
                        : t($sessionExerciseHasPhoto ? 'workouts.cover_photo' : 'workouts.cover_video');
                    $sessionExerciseCoverMode = wk_normalize_exercise_cover_mode($ex['cover_mode'] ?? 'auto');
                    ?>
                    <article class="workouts-session-exercise<?= (int) ($ex['id'] ?? 0) === $activeSessionExerciseId ? ' is-active' : '' ?>" style="<?= e($workoutExerciseStyle((array) $ex)) ?>" data-exercise-type="<?= e($sessionExerciseType) ?>" data-session-exercise-id="<?= (int) ($ex['id'] ?? 0) ?>">
                        <div class="workouts-session-exercise-head">
                            <span class="workouts-exercise-cover<?= $sessionExerciseCover !== null ? ' has-media' : '' ?>" aria-hidden="true"><?php if ($sessionExerciseCover !== null): ?><img src="<?= e((string) $sessionExerciseCover['url']) ?>" alt="" style="object-position: <?= e($workoutCoverPosition($sessionExerciseCover)) ?>"><?php else: ?><?= e($workoutExerciseMark((array) $ex)) ?><?php endif; ?><?php if (!empty($sessionExerciseCover['has_video'])): ?><b>&#9654;</b><?php endif; ?></span>
                            <strong><a href="/?page=workouts&exercise_id=<?= (int) $ex['exercise_def_id'] ?>"><?= e((string) $ex['exercise_name']) ?></a></strong>
                            <span class="muted small"><?= e($exerciseTypeLabel($sessionExerciseType)) ?></span>
                        </div>
                        <?php if ($sessionExerciseHasPhoto || $sessionExerciseHasVideo): ?>
                            <details class="workouts-session-technique" data-workout-session-technique>
                                <summary>
                                    <span class="workouts-session-technique-icon" aria-hidden="true"><?= $sessionExerciseHasVideo ? '&#9654;' : activity_icon_svg('image') ?></span>
                                    <span><strong><?= e(t('workouts.open_technique')) ?></strong><small><?= e($sessionExerciseMediaLabel) ?> · <?= e(t('workouts.inline_technique_hint')) ?></small></span>
                                    <b aria-hidden="true">+</b>
                                </summary>
                                <div class="workouts-session-technique-body">
                                    <?php
                                    $workoutMediaViewer = [
                                        'id' => 'session-' . $sid . '-exercise-media-' . (int) ($ex['id'] ?? 0),
                                        'image_url' => $sessionExerciseImageUrl,
                                        'image_position' => $ex['image_position'] ?? 'center',
                                        'images' => $sessionExerciseGalleryImages,
                                        'video' => $sessionExerciseVideo,
                                        'title' => (string) ($ex['exercise_name'] ?? t('workouts.session')),
                                        'mark' => $workoutExerciseMark((array) $ex),
                                        'default' => $sessionExerciseHasVideo && ($sessionExerciseCoverMode === 'video' || !$sessionExerciseHasPhoto) ? 'video' : 'photo',
                                        'compact' => true,
                                        'show_header' => false,
                                    ];
                                    require __DIR__ . '/partials/workout_exercise_media_viewer.php';
                                    ?>
                                </div>
                            </details>
                        <?php endif; ?>
                        <?php if ((int) ($ex['rest_seconds'] ?? 0) > 0 || trim((string) ($ex['notes'] ?? '')) !== ''): ?><div class="workouts-session-prescription"><?php if ((int) ($ex['rest_seconds'] ?? 0) > 0): ?><span><?= e(t('workouts.rest_short', ['count' => (int) $ex['rest_seconds']])) ?></span><?php endif; ?><?php if (trim((string) ($ex['notes'] ?? '')) !== ''): ?><p><?= e((string) $ex['notes']) ?></p><?php endif; ?></div><?php endif; ?>
                        <?php $sessionGuide = (array) ($ex['content'] ?? []); ?>
                        <?php if ((array) ($sessionGuide['steps'] ?? []) !== []): ?>
                            <details class="workouts-session-guide">
                                <summary><?= e(t('workouts.how_to')) ?></summary>
                                <ol><?php foreach ((array) $sessionGuide['steps'] as $step): ?><li><?= e((string) $step) ?></li><?php endforeach; ?></ol>
                                <?php if ((array) ($sessionGuide['tips'] ?? []) !== []): ?><p><strong><?= e(t('workouts.tips')) ?>:</strong> <?= e((string) $sessionGuide['tips'][0]) ?></p><?php endif; ?>
                            </details>
                        <?php endif; ?>
                        <div class="workouts-set-legend" aria-hidden="true">
                            <span>#</span>
                            <span><?= e($sessionIsCardio ? t('workouts.minutes_label') : ($sessionIsIsometric ? t('workouts.seconds_label') : t('workouts.weight'))) ?><?= !$sessionIsCardio && !$sessionIsIsometric ? ' (' . e(strtoupper((string) ($ex['unit'] ?? 'kg'))) . ')' : '' ?></span>
                            <span></span>
                            <span><?= e($sessionIsCardio ? t('workouts.km_label') : ($sessionIsIsometric ? t('workouts.weight') : t('workouts.reps'))) ?><?= $sessionIsIsometric ? ' (' . e(strtoupper((string) ($ex['unit'] ?? 'kg'))) . ')' : '' ?></span>
                            <span>&#10003;</span>
                        </div>
                        <div class="workouts-set-rows">
                            <?php foreach ((array) ($ex['sets'] ?? []) as $set): ?>
                                <form method="post" action="/?page=workouts" class="workouts-set-row<?= (int) ($set['completed'] ?? 0) === 1 ? ' is-done' : '' ?>" data-workout-set-form data-session-id="<?= $sid ?>" data-rest-seconds="<?= max(0, (int) ($ex['rest_seconds'] ?? 0)) ?>">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                    <input type="hidden" name="action" value="session_update_set">
                                    <input type="hidden" name="session_id" value="<?= $sid ?>">
                                    <input type="hidden" name="return_session_exercise_id" value="<?= (int) ($ex['id'] ?? 0) ?>">
                                    <input type="hidden" name="set_id" value="<?= (int) $set['id'] ?>">
                                    <span class="workouts-set-index"><?= (int) $set['set_index'] ?></span>
                                    <?php if ($sessionIsCardio): ?>
                                        <label class="workouts-set-field"><span class="sr-only"><?= e(t('workouts.duration_minutes')) ?></span><input type="number" name="duration_minutes" step="0.5" min="0" value="<?= $set['duration'] !== null ? e(rtrim(rtrim(number_format((int) $set['duration'] / 60, 1, '.', ''), '0'), '.')) : '' ?>" placeholder="<?= e(t('workouts.minutes_placeholder')) ?>" inputmode="decimal"></label>
                                        <span class="workouts-set-x">·</span>
                                        <label class="workouts-set-field"><span class="sr-only"><?= e(t('workouts.distance_km')) ?></span><input type="number" name="distance" step="0.01" min="0" value="<?= $set['distance'] !== null ? e(rtrim(rtrim(number_format((float) $set['distance'], 2, '.', ''), '0'), '.')) : '' ?>" placeholder="<?= e(t('workouts.km_placeholder')) ?>" inputmode="decimal"></label>
                                    <?php elseif ($sessionIsIsometric): ?>
                                        <label class="workouts-set-field"><span class="sr-only"><?= e(t('workouts.duration_seconds')) ?></span><input type="number" name="duration_seconds" min="0" value="<?= $set['duration'] !== null ? (int) $set['duration'] : '' ?>" placeholder="<?= e(t('workouts.seconds_placeholder')) ?>" inputmode="numeric"></label>
                                        <span class="workouts-set-x">·</span>
                                        <label class="workouts-set-field"><span class="sr-only"><?= e(t('workouts.weight')) ?></span><input type="number" name="weight" step="0.5" min="0" value="<?= $set['weight'] !== null ? e(rtrim(rtrim(number_format((float) $set['weight'], 1, '.', ''), '0'), '.')) : '' ?>" placeholder="<?= e(t('workouts.weight')) ?>" inputmode="decimal"></label>
                                    <?php else: ?>
                                        <label class="workouts-set-field"><span class="sr-only"><?= e(t('workouts.weight')) ?></span><input type="number" name="weight" step="0.5" min="0" value="<?= $set['weight'] !== null ? e(rtrim(rtrim(number_format((float) $set['weight'], 1, '.', ''), '0'), '.')) : '' ?>" placeholder="<?= e(t('workouts.weight')) ?>" inputmode="decimal"></label>
                                        <span class="workouts-set-x">×</span>
                                        <label class="workouts-set-field"><span class="sr-only"><?= e(t('workouts.reps')) ?></span><input type="number" name="reps" min="0" max="999" value="<?= $set['reps'] !== null ? (int) $set['reps'] : '' ?>" placeholder="<?= e(t('workouts.reps')) ?>" inputmode="numeric"></label>
                                    <?php endif; ?>
                                    <button type="submit" name="completed" value="<?= (int) ($set['completed'] ?? 0) === 1 ? '0' : '1' ?>" class="btn workouts-set-done<?= (int) ($set['completed'] ?? 0) === 1 ? ' btn-primary' : ' btn-ghost' ?> small" aria-label="<?= e(t('workouts.done')) ?>" data-workout-set-toggle data-next-completed="<?= (int) ($set['completed'] ?? 0) === 1 ? '0' : '1' ?>">✓</button>
                                </form>
                            <?php endforeach; ?>
                        </div>
                        <?php if (!$sessionDone): ?>
                            <form method="post" action="/?page=workouts" class="inline-form">
                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                <input type="hidden" name="action" value="session_add_set">
                                <input type="hidden" name="session_id" value="<?= $sid ?>">
                                <input type="hidden" name="session_exercise_id" value="<?= (int) $ex['id'] ?>">
                                <input type="hidden" name="return_session_exercise_id" value="<?= (int) ($ex['id'] ?? 0) ?>">
                                <button type="submit" class="btn btn-ghost small workouts-add-set-btn">+ <?= e(t('workouts.add_set')) ?></button>
                            </form>
                        <?php endif; ?>
                        <?php $followingExercise = isset($sessionExercises[$sessionExerciseIndex + 1]) ? (array) $sessionExercises[$sessionExerciseIndex + 1] : null; ?>
                        <?php if ($followingExercise !== null): ?><a class="workouts-session-next-exercise" href="<?= e($sessionExerciseUrl((int) $followingExercise['id'])) ?>"><span><small><?= e(t('workouts.next_exercise')) ?></small><strong><?= e((string) ($followingExercise['exercise_name'] ?? '')) ?></strong></span><b aria-hidden="true">&rarr;</b></a><?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!$sessionDone): ?>
            <a class="workouts-session-add-exercise" href="/?page=workouts&view=library&target_session_id=<?= $sid ?>">
                <span aria-hidden="true">+</span>
                <span><strong><?= e(t('workouts.add_exercise')) ?></strong><small><?= e(t('workouts.add_from_library_hint')) ?></small></span>
                <b aria-hidden="true">&rsaquo;</b>
            </a>

            <div class="workouts-session-footer">
                <form method="post" action="/?page=workouts" class="inline-form" data-workout-session-end data-session-id="<?= $sid ?>" onsubmit="return confirm('<?= e(t('workouts.cancel_session')) ?>?');">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="session_cancel">
                    <input type="hidden" name="session_id" value="<?= $sid ?>">
                    <button type="submit" class="btn btn-ghost"><?= e(t('workouts.cancel_session')) ?></button>
                </form>
                <form method="post" action="/?page=workouts" class="stack workouts-finish-form" data-workout-session-end data-session-id="<?= $sid ?>">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="session_finish">
                    <input type="hidden" name="session_id" value="<?= $sid ?>">
                    <label class="check standalone-check workouts-count-check">
                        <input type="checkbox" name="count_challenge" value="1" checked>
                        <span><?= e(t('workouts.count_challenge')) ?></span>
                    </label>
                    <button type="submit" class="btn btn-primary btn-block"><?= e(t('workouts.finish')) ?></button>
                </form>
            </div>
        <?php else: ?>
            <p class="muted"><?= e(t('workouts.recent_sessions')) ?></p>
        <?php endif; ?>
    </article>
    <?php endif; ?>

<?php elseif ($wkView === 'analytics' && !empty($wkStats)): ?>
    <?php
    $stats = (array) $wkStats;
    $weekly = (array) ($stats['weekly'] ?? []);
    $maxVolume = 0.0;
    $maxSessions = 0;
    foreach ($weekly as $w) {
        $maxVolume = max($maxVolume, (float) $w['volume']);
        $maxSessions = max($maxSessions, (int) $w['sessions']);
    }
    $muscles = (array) ($stats['muscles'] ?? []);
    $muscleTotal = array_sum(array_map(static fn($m) => (int) $m['sets'], $muscles));
    ?>

    <?php if (($stats['messages'] ?? []) !== []): ?>
        <div class="workouts-messages">
            <?php foreach ((array) $stats['messages'] as $msg): ?>
                <div class="workouts-message">
                    <span class="workouts-message-icon"><?= activity_icon_svg((string) $msg['icon']) ?></span>
                    <span><?= e((string) $msg['text']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="workouts-stats-grid">
        <article class="workouts-stat-card">
            <span class="workouts-stat-value"><?= (int) ($stats['streak'] ?? 0) ?></span>
            <span class="workouts-stat-label"><?= e(t('workouts.streak')) ?></span>
        </article>
    </div>

    <article class="panel">
        <div class="panel-head"><div><h2><?= e(t('workouts.weekly_volume')) ?></h2></div></div>
        <?php if ($maxVolume <= 0 && $maxSessions <= 0): ?>
            <p class="muted"><?= e(t('workouts.no_data')) ?></p>
        <?php else: ?>
            <div class="workouts-bar-chart" role="img" aria-label="<?= e(t('workouts.weekly_volume')) ?>">
                <?php foreach ($weekly as $w): ?>
                    <?php $h = $maxVolume > 0 ? max(2, (int) round(((float) $w['volume'] / $maxVolume) * 100)) : 2; ?>
                    <div class="workouts-bar-col">
                        <div class="workouts-bar" style="height: <?= $h ?>%" title="<?= e(number_format((float) $w['volume'], 0, '.', ' ')) ?> kg · <?= (int) $w['sessions'] ?>"></div>
                        <span class="workouts-bar-label"><?= e((string) $w['label']) ?></span>
                        <span class="workouts-bar-sub"><?= (int) $w['sessions'] ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>

    <div class="grid-two">
        <article class="panel">
            <div class="panel-head"><div><h2><?= e(t('workouts.frequent_exercises')) ?></h2></div></div>
            <?php if (($stats['frequent'] ?? []) === []): ?>
                <p class="muted"><?= e(t('workouts.no_data')) ?></p>
            <?php else: ?>
                <ul class="workouts-pr-list">
                    <?php foreach ((array) $stats['frequent'] as $fx): ?>
                        <li><span><?= e((string) $fx['name']) ?></span><strong><?= (int) $fx['count'] ?>×</strong></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </article>
        <article class="panel">
            <div class="panel-head"><div><h2><?= e(t('workouts.muscle_focus')) ?></h2></div></div>
            <?php if ($muscles === [] || $muscleTotal <= 0): ?>
                <p class="muted"><?= e(t('workouts.no_data')) ?></p>
            <?php else: ?>
                <div class="workouts-muscle-list">
                    <?php foreach ($muscles as $m): ?>
                        <?php $pct = (int) round(((int) $m['sets'] / $muscleTotal) * 100); ?>
                        <div class="workouts-muscle-row">
                            <span class="workouts-muscle-name"><?= e(ucfirst((string) $m['muscle'])) ?></span>
                            <div class="workouts-muscle-bar"><span style="width: <?= $pct ?>%"></span></div>
                            <span class="workouts-muscle-pct"><?= $pct ?>%</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </article>
    </div>
<?php endif; ?>

<div class="app-modal" id="wk-new-routine-modal" hidden role="dialog" aria-modal="true" aria-labelledby="wk-new-routine-title">
    <div class="app-modal-card workouts-routine-modal-card">
        <div class="app-modal-head">
            <div><p class="eyebrow"><?= e(t('workouts.tab_plan')) ?></p><h2 id="wk-new-routine-title"><?= e(t('workouts.new_routine')) ?></h2></div>
            <button type="button" class="app-modal-close" data-app-modal-close aria-label="<?= e(t('common.back')) ?>">&times;</button>
        </div>
        <form method="post" action="/?page=workouts" enctype="multipart/form-data" class="stack" data-workout-media-editor>
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="routine_create">
            <label><?= e(t('workouts.routine_name')) ?><input type="text" name="name" maxlength="80" required placeholder="Push / Pull / Legs…"></label>
            <label><?= e(t('workouts.description')) ?><input type="text" name="description" maxlength="200"></label>
            <fieldset class="workouts-routine-identity-picker" data-routine-identity-picker data-workout-color-picker data-workout-color-property="--routine-accent" style="--routine-accent: #14b8a6; --workout-accent: #14b8a6">
                <legend><?= e(t('workouts.routine_identity')) ?></legend>
                <p class="muted small"><?= e(t('workouts.routine_identity_hint')) ?></p>
                <div class="workouts-routine-icon-options" aria-label="<?= e(t('workouts.routine_icon')) ?>">
                    <?php foreach ($routineIconOptions as $iconKey => $iconLabelKey): ?><label title="<?= e(t($iconLabelKey)) ?>"><input type="radio" name="icon" value="<?= e($iconKey) ?>"<?= $iconKey === 'dumbbell' ? ' checked' : '' ?>><span><?= activity_icon_svg($iconKey) ?><small><?= e(t($iconLabelKey)) ?></small></span></label><?php endforeach; ?>
                </div>
                <div class="workouts-routine-color-options" aria-label="<?= e(t('workouts.routine_color')) ?>">
                    <?php foreach ($routineColorOptions as $colorKey => $colorValue): ?><label title="<?= e(t('workouts.color_' . $colorKey)) ?>"><input type="radio" name="accent_color_preset" value="<?= e($colorValue) ?>" data-routine-color-preset data-workout-color-preset<?= $colorKey === 'teal' ? ' checked' : '' ?>><span style="--swatch: <?= e($colorValue) ?>"><span class="sr-only"><?= e(t('workouts.color_' . $colorKey)) ?></span></span></label><?php endforeach; ?>
                </div>
                <label class="workouts-routine-custom-color"><span><strong><?= e(t('workouts.custom_color')) ?></strong><small><?= e(t('workouts.custom_color_hint')) ?></small></span><span class="workouts-routine-custom-color-control"><input type="color" name="accent_color" value="#14b8a6" data-routine-color-input data-workout-color-input aria-label="<?= e(t('workouts.custom_color')) ?>"><output data-routine-color-output data-workout-color-output>#14B8A6</output></span></label>
            </fieldset>
            <details class="workouts-routine-create-media">
                <summary><span><?= activity_icon_svg('image') ?></span><span><strong><?= e(t('workouts.routine_media')) ?></strong><small><?= e(t('workouts.routine_media_optional')) ?></small></span><b aria-hidden="true">+</b></summary>
                <div class="workouts-routine-create-media-body">
                    <fieldset class="workouts-custom-cover-picker" data-workout-cover-picker>
                        <legend><?= e(t('workouts.routine_cover')) ?></legend>
                        <p><?= e(t('workouts.routine_cover_hint')) ?></p>
                        <div>
                            <?php foreach (['auto' => ['spark', 'workouts.cover_auto'], 'photo' => ['image', 'workouts.cover_photo'], 'video' => ['play', 'workouts.cover_video'], 'simple' => ['simple', 'workouts.cover_simple']] as $coverMode => [$coverIcon, $coverLabelKey]): ?>
                                <label><input type="radio" name="cover_mode" value="<?= e($coverMode) ?>"<?= $coverMode === 'auto' ? ' checked' : '' ?>><span><?php if ($coverIcon === 'play'): ?><b aria-hidden="true">&#9654;</b><?php elseif ($coverIcon === 'simple'): ?><b aria-hidden="true">AB</b><?php else: ?><?= activity_icon_svg($coverIcon) ?><?php endif; ?><strong><?= e(t($coverLabelKey)) ?></strong></span></label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                    <div class="workouts-custom-media-grid">
                        <div class="workouts-custom-media-control"><label><?= e(t('workouts.routine_photo')) ?><input type="file" name="routine_image" accept="image/jpeg,image/png,image/webp" data-workout-image-input></label><small><?= e(t('workouts.routine_photo_hint')) ?></small></div>
                        <div class="workouts-custom-media-control"><label><?= e(t('workouts.routine_video')) ?><input type="url" name="video_url" placeholder="https://www.youtube.com/watch?v=..." inputmode="url" data-workout-video-input></label><small><?= e(t('workouts.routine_video_hint')) ?></small><button class="btn btn-ghost small" type="button" data-workout-clear-video><?= e(t('common.clear')) ?></button></div>
                    </div>
                    <fieldset class="workouts-image-focus-picker" data-workout-image-position-picker>
                        <legend><?= e(t('workouts.image_focus')) ?></legend>
                        <p><?= e(t('workouts.image_focus_hint')) ?></p>
                        <div class="workouts-image-focus-presets">
                            <?php foreach (['top' => ['&#8593;', 'workouts.focus_top'], 'left' => ['&#8592;', 'workouts.focus_left'], 'center' => ['&#9679;', 'workouts.focus_center'], 'right' => ['&#8594;', 'workouts.focus_right'], 'bottom' => ['&#8595;', 'workouts.focus_bottom']] as $position => [$positionIcon, $positionLabelKey]): ?>
                                <label><input type="radio" name="image_position" value="<?= e($position) ?>" data-workout-image-position-input<?= $position === 'center' ? ' checked' : '' ?>><span><b aria-hidden="true"><?= $positionIcon ?></b><strong><?= e(t($positionLabelKey)) ?></strong></span></label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                    <div class="workouts-custom-preview" aria-label="<?= e(t('workouts.media_preview')) ?>">
                        <figure data-workout-image-preview-wrap hidden><img src="" alt="" data-workout-image-preview style="object-position: <?= e(wk_image_position_css('center')) ?>"></figure>
                        <div class="workouts-custom-preview-empty" data-workout-image-empty><span aria-hidden="true">&#9638;</span><strong><?= e(t('workouts.photo_preview')) ?></strong></div>
                        <div class="workouts-custom-video-preview" data-workout-video-preview data-empty-label="<?= e(t('workouts.video_preview')) ?>"></div>
                    </div>
                </div>
            </details>
            <fieldset class="workouts-day-picker"><legend><?= e(t('workouts.select_days')) ?></legend><div>
                <?php foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $day): ?><label><input type="checkbox" name="days[]" value="<?= e($day) ?>"><span><?= e($dayLabel($day)) ?></span></label><?php endforeach; ?>
            </div></fieldset>
            <button type="submit" class="btn btn-primary btn-block"><?= e(t('common.create')) ?></button>
        </form>
    </div>
</div>
</section>
