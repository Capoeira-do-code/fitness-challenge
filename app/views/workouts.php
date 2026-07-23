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
$contextLabel = static fn(string $context): string => $context !== '' ? t('workouts.context_' . $context) : '';
$difficultyLabel = static fn(string $difficulty): string => t('workouts.difficulty_' . (in_array($difficulty, ['beginner', 'intermediate', 'advanced', 'custom'], true) ? $difficulty : 'beginner'));
$rankLabel = static fn(string $rank): string => t('workouts.rank_' . (array_key_exists($rank, wk_rank_tiers()) ? $rank : 'unranked'));
$rankProgressData = static function (array $rank): array {
    $score = max(0.0, (float) ($rank['score'] ?? 0));
    $target = isset($rank['next_score']) ? max($score, (float) $rank['next_score']) : null;

    return [
        'score' => $score,
        'target' => $target,
        'remaining' => $target !== null ? max(0.0, $target - $score) : 0.0,
        'progress' => max(0, min(100, (int) ($rank['progress'] ?? 0))),
        'next_key' => isset($rank['next_key']) ? (string) $rank['next_key'] : null,
    ];
};
$dayLabel = static fn(string $day): string => t('workouts.day_' . $day);
$dayShortIndexes = ['mon' => 0, 'tue' => 1, 'wed' => 2, 'thu' => 3, 'fri' => 4, 'sat' => 5, 'sun' => 6];
$dayShortLabel = static fn(string $day): string => t('weekday.' . (string) ($dayShortIndexes[$day] ?? 0));
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
$tabView = $wkView === 'analytics' ? 'stats' : ($wkView === 'list' ? 'overview' : $wkView);
$workoutHubLabels = [
    'overview' => [t('workouts.tab_overview'), t('workouts.subtitle')],
    'plan' => [t('workouts.tab_plan'), t('workouts.plan_subtitle')],
    'library' => [t('workouts.tab_library'), t('workouts.library_subtitle')],
    'ranks' => [t('workouts.tab_ranks'), t('workouts.rank_subtitle')],
    'stats' => [t('workouts.stats'), t('workouts.stats_subtitle')],
];
$workoutHeroTitle = (string) ($workoutHubLabels[$tabView][0] ?? t('workouts.title'));
$workoutHeroHint = (string) ($workoutHubLabels[$tabView][1] ?? t('workouts.subtitle'));
$activeRoutines = array_values(array_filter((array) ($wkRoutines ?? []), static fn($r) => (int) ($r['is_archived'] ?? 0) === 0));
$addedRoutineId = max(0, (int) ($_GET['added_routine_id'] ?? 0));
$addedRoutine = null;
if ($addedRoutineId > 0) {
    foreach ($activeRoutines as $candidateRoutine) {
        if ((int) ($candidateRoutine['id'] ?? 0) === $addedRoutineId) {
            $addedRoutine = (array) $candidateRoutine;
            break;
        }
    }
}
$archivedRoutines = array_values(array_filter((array) ($wkRoutines ?? []), static fn($r) => (int) ($r['is_archived'] ?? 0) === 1));
$routinesByDay = (array) ($wkRoutinesByDay ?? []);
$workoutDayKeys = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
$todayWorkoutDay = $workoutDayKeys[max(0, min(6, (int) date('N') - 1))];
$todayRoutines = array_values((array) ($routinesByDay[$todayWorkoutDay] ?? []));
$activeSessionSummary = is_array($wkActiveSessionSummary ?? null) ? (array) $wkActiveSessionSummary : [];
$hasActiveWorkoutSession = !empty($wkActiveSession);
$activeWorkoutRoutineId = $hasActiveWorkoutSession ? (int) ($wkActiveSession['routine_id'] ?? 0) : 0;
$completedWorkoutRoutineIdsToday = [];
foreach ((array) ($wkRecentSessions ?? []) as $recentWorkoutSession) {
    $recentRoutineId = (int) ($recentWorkoutSession['routine_id'] ?? 0);
    $recentStartedAt = trim((string) ($recentWorkoutSession['started_at'] ?? ''));
    if ($recentRoutineId > 0 && substr($recentStartedAt, 0, 10) === date('Y-m-d')) {
        $completedWorkoutRoutineIdsToday[$recentRoutineId] = true;
    }
}
$workoutRoutineState = static function (array $routine, bool $scheduled = false) use ($hasActiveWorkoutSession, $activeWorkoutRoutineId, $completedWorkoutRoutineIdsToday): array {
    $routineId = (int) ($routine['id'] ?? 0);
    if ($routineId > 0 && $routineId === $activeWorkoutRoutineId) {
        return ['active', t('workouts.state_active')];
    }
    if (isset($completedWorkoutRoutineIdsToday[$routineId])) {
        return ['completed', t('workouts.state_completed_today')];
    }
    if ($hasActiveWorkoutSession) {
        return ['unavailable', t('workouts.state_unavailable')];
    }
    if ($scheduled || wk_days_from_mask((string) ($routine['recommended_days_mask'] ?? '0000000')) !== []) {
        return ['scheduled', t('workouts.state_scheduled')];
    }

    return ['available', t('workouts.state_available')];
};
$activeElapsedMinutes = max(0, (int) ($activeSessionSummary['elapsed_minutes'] ?? 0));
$activeElapsedLabel = $activeElapsedMinutes >= 60
    ? t('workouts.elapsed_hours', ['hours' => intdiv($activeElapsedMinutes, 60), 'minutes' => $activeElapsedMinutes % 60])
    : t('workouts.elapsed_minutes', ['minutes' => $activeElapsedMinutes]);
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
$routineSettingsView = in_array((string) ($wkRoutineSettingsView ?? 'identity'), ['identity', 'media', 'schedule', 'management'], true)
    ? (string) ($wkRoutineSettingsView ?? 'identity')
    : 'identity';
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
$workoutSessionReturnExerciseId = max(0, (int) ($_GET['session_exercise_id'] ?? 0));
$workoutSessionReturnUrl = $targetSessionId > 0
    ? '/?' . http_build_query(array_filter([
        'page' => 'workouts',
        'session_id' => $targetSessionId,
        'session_exercise_id' => $workoutSessionReturnExerciseId,
    ], static fn($value): bool => $value !== 0))
    : '';
$filters = (array) ($wkLibraryFilters ?? []);
$libraryExercises = (array) ($wkLibrary ?? []);
$libraryScope = (string) ($filters['scope'] ?? '');
$libraryMode = (string) ($wkLibraryMode ?? 'browse') === 'organize' && $libraryScope === 'favorites' && !$hasLibraryTarget ? 'organize' : 'browse';
$libraryLayout = in_array((string) ($wkLibraryLayout ?? 'cards'), ['cards', 'compact'], true) ? (string) $wkLibraryLayout : 'cards';
$useCompactLibrary = !$hasLibraryTarget && $libraryLayout === 'compact';
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
$hasLibrarySearch = trim((string) ($filters['q'] ?? '')) !== '';
$hasActiveLibraryFilters = trim((string) ($filters['muscle'] ?? '')) !== ''
    || trim((string) ($filters['equipment'] ?? '')) !== ''
    || trim((string) ($filters['context'] ?? '')) !== '';
$hasAnyLibraryFilter = $hasLibrarySearch || $hasActiveLibraryFilters;
$libraryResultTotal = max(0, (int) ($wkLibraryTotal ?? count($libraryExercises)));
$libraryClearUrl = $libraryUrl([
    'q' => null,
    'muscle' => null,
    'equipment' => null,
    'context' => null,
    'library_page' => null,
]);
?>
<section class="screen stack-lg workouts-screen workouts-view-<?= e($wkView) ?>">
    <?php if ($wkView !== 'custom_exercise'): ?>
    <div class="hero-panel workouts-hero<?= in_array($wkView, $hubViews, true) ? ' workouts-hero-hub' : '' ?>">
        <div class="hero-copy hero-copy-page-title">
            <p class="eyebrow"><?= e(t('nav.table')) ?></p>
            <h1><?= e($wkView === 'organize' ? t('workouts.organize_routines') : (in_array($wkView, $hubViews, true) ? $workoutHeroTitle : t('workouts.title'))) ?></h1>
            <p class="muted"><?= e($wkView === 'organize' ? t('workouts.organize_routines_hint') : (in_array($wkView, $hubViews, true) ? $workoutHeroHint : t('workouts.subtitle'))) ?></p>
        </div>
        <?php if ($wkView === 'library'): ?>
            <?php $libraryToolbarVariant = 'desktop'; require __DIR__ . '/partials/workout_library_toolbar.php'; ?>
        <?php endif; ?>
        <?php if (!in_array($wkView, $hubViews, true)): ?>
            <?php $workoutBack = $wkView === 'exercise'
                ? ($workoutSessionReturnUrl !== '' ? $workoutSessionReturnUrl : $workoutLibraryReturnUrl)
                : ($wkView === 'routine'
                    ? (in_array($routineSection, ['settings', 'organize'], true) && !empty($wkRoutine) ? '/?page=workouts&routine_id=' . (int) $wkRoutine['id'] : '/?page=workouts&view=plan')
                    : ($wkView === 'session' && $sessionSection === 'organize' && !empty($wkSession)
                        ? '/?page=workouts&session_id=' . (int) $wkSession['id']
                        : ($wkView === 'routine_exercise' && !empty($wkRoutine)
                            ? '/?page=workouts&routine_id=' . (int) $wkRoutine['id']
                            : ($wkView === 'custom_exercise' ? $workoutLibraryReturnUrl : '/?page=workouts')))); ?>
            <?php $workoutBackDestination = $wkView === 'exercise' || $wkView === 'custom_exercise'
                ? ($workoutSessionReturnUrl !== '' ? t('workouts.active_session') : t('workouts.tab_library'))
                : ($wkView === 'routine_exercise' || ($wkView === 'routine' && in_array($routineSection, ['settings', 'organize'], true))
                    ? (string) (($wkRoutine['name'] ?? '') !== '' ? $wkRoutine['name'] : t('workouts.my_routines'))
                    : ($wkView === 'session' && $sessionSection === 'organize'
                        ? (string) (($wkSession['title'] ?? '') !== '' ? $wkSession['title'] : t('workouts.session'))
                        : ($wkView === 'routine' ? t('workouts.tab_plan') : t('workouts.title')))); ?>
            <a class="hierarchy-back destination-back" href="<?= e($workoutBack) ?>" aria-label="<?= e(t('common.back')) ?>: <?= e($workoutBackDestination) ?>"><span aria-hidden="true">&larr;</span><strong><?= e($workoutBackDestination) ?></strong></a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($wkView === 'list'): ?>
        <div class="workouts-overview-priority" aria-label="<?= e(t('workouts.today_workout')) ?>">
            <?php if ($hasActiveWorkoutSession): ?>
                <article class="workouts-active-session-card compact-panel glass-panel compact-progress-panel" data-session-progress="<?= (int) ($activeSessionSummary['progress_pct'] ?? 0) ?>">
                    <a class="workouts-resume-banner" href="/?page=workouts&amp;session_id=<?= (int) $wkActiveSession['id'] ?>" aria-label="<?= e(t('workouts.resume_session')) ?>: <?= e((string) ($wkActiveSession['title'] ?? '') !== '' ? (string) $wkActiveSession['title'] : t('workouts.session')) ?>">
                        <span class="workouts-resume-dot" aria-hidden="true"></span>
                        <span class="workouts-resume-copy">
                            <small><?= e(t('workouts.active_session')) ?> · <?= e($activeElapsedLabel) ?></small>
                            <strong><?= e((string) ($wkActiveSession['title'] ?? '') !== '' ? (string) $wkActiveSession['title'] : t('workouts.session')) ?></strong>
                        </span>
                        <span class="workouts-resume-cta"><span class="workouts-resume-cta-label"><?= e(t('workouts.resume_session')) ?></span><span aria-hidden="true">&rarr;</span></span>
                    </a>
                    <div class="workouts-active-session-progress">
                        <span class="goal-progress" aria-label="<?= e(t('workouts.session_progress', ['completed' => (int) ($activeSessionSummary['completed_sets'] ?? 0), 'total' => (int) ($activeSessionSummary['total_sets'] ?? 0)])) ?>"><span style="width: <?= (int) ($activeSessionSummary['progress_pct'] ?? 0) ?>%"></span></span>
                        <span><strong><?= (int) ($activeSessionSummary['completed_sets'] ?? 0) ?>/<?= (int) ($activeSessionSummary['total_sets'] ?? 0) ?></strong><small><?= e(t('workouts.sets')) ?></small></span>
                        <?php if ((string) ($activeSessionSummary['next_exercise'] ?? '') !== ''): ?><span class="workouts-active-next"><small><?= e(t('workouts.next_exercise')) ?></small><strong><?= e((string) $activeSessionSummary['next_exercise']) ?></strong></span><?php endif; ?>
                    </div>
                    <form method="post" action="/?page=workouts" class="workouts-active-session-finish" onsubmit="return confirm('<?= e(t('workouts.finish_confirm')) ?>');">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="session_finish">
                        <input type="hidden" name="session_id" value="<?= (int) $wkActiveSession['id'] ?>">
                        <input type="hidden" name="count_challenge" value="1">
                        <button type="submit" class="btn btn-ghost small"><?= e(t('workouts.finish')) ?></button>
                    </form>
                </article>
            <?php endif; ?>

            <?php if ($todayRoutines !== []): ?>
                <article class="panel workouts-today-card compact-panel glass-panel<?= $hasActiveWorkoutSession ? ' has-active-session' : '' ?>">
                    <div class="panel-head workouts-today-head">
                        <div><p class="eyebrow"><?= e($dayLabel($todayWorkoutDay)) ?></p><h2><?= e(t('workouts.today_workout')) ?></h2></div>
                        <span class="badge"><?= count($todayRoutines) ?> <?= e(t('workouts.routines')) ?></span>
                    </div>
                    <form method="post" action="/?page=workouts" class="workouts-today-picker">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="session_start">
                        <div class="workouts-today-routine-chips" role="radiogroup" aria-label="<?= e(t('workouts.choose_routine')) ?>">
                            <?php foreach ($todayRoutines as $todayRoutineIndex => $todayRoutine): ?>
                                <label><input type="radio" name="routine_id" value="<?= (int) ($todayRoutine['id'] ?? 0) ?>"<?= $todayRoutineIndex === 0 ? ' checked' : '' ?>><span><?= e((string) ($todayRoutine['name'] ?? '')) ?></span></label>
                            <?php endforeach; ?>
                        </div>
                        <button class="btn btn-primary" type="submit"<?= $hasActiveWorkoutSession ? ' disabled title="' . e(t('workouts.finish_active_first')) . '"' : '' ?>><?= e(t('workouts.start_routine')) ?></button>
                    </form>
                </article>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (in_array($wkView, $hubViews, true)): ?>
        <?php if ($wkView === 'list'): ?>
            <details class="workouts-overview-disclosure workouts-tools-disclosure" data-persist-disclosure="workouts.tools" open>
                <summary>
                    <span class="workouts-overview-disclosure-icon" aria-hidden="true"><?= activity_icon_svg('sliders') ?></span>
                    <span><strong><?= e(t('workouts.more_options')) ?></strong><small><?= e(t('workouts.more_options_hint')) ?></small></span>
                    <b aria-hidden="true">&rsaquo;</b>
                </summary>
                <div class="workouts-overview-disclosure-body">
            <nav class="workouts-section-grid hierarchy-nav-list" aria-label="<?= e(t('workouts.title')) ?>">
                <a class="hierarchy-nav-row" data-tone="blue" href="/?page=workouts&view=plan"><span class="hierarchy-nav-icon" aria-hidden="true"><?= activity_icon_svg('check') ?></span><span class="hierarchy-nav-copy"><strong><?= e(t('workouts.tab_plan')) ?></strong><small><?= e(t('workouts.plan_subtitle')) ?></small></span><span class="hierarchy-nav-chevron" aria-hidden="true">&rsaquo;</span></a>
                <a class="hierarchy-nav-row" data-tone="green" href="/?page=workouts&view=library"><span class="hierarchy-nav-icon" aria-hidden="true"><?= activity_icon_svg('dumbbell') ?></span><span class="hierarchy-nav-copy"><strong><?= e(t('workouts.tab_library')) ?></strong><small><?= e(t('workouts.library_subtitle')) ?></small></span><span class="hierarchy-nav-chevron" aria-hidden="true">&rsaquo;</span></a>
                <a class="hierarchy-nav-row" data-tone="amber" href="/?page=workouts&view=ranks"><span class="hierarchy-nav-icon" aria-hidden="true"><?= activity_icon_svg('trophy') ?></span><span class="hierarchy-nav-copy"><strong><?= e(t('workouts.tab_ranks')) ?></strong><small><?= e(t('workouts.rank_subtitle')) ?></small></span><span class="hierarchy-nav-chevron" aria-hidden="true">&rsaquo;</span></a>
                <a class="hierarchy-nav-row" data-tone="violet" href="/?page=workouts&view=stats"><span class="hierarchy-nav-icon" aria-hidden="true"><?= activity_icon_svg('run') ?></span><span class="hierarchy-nav-copy"><strong><?= e(t('workouts.stats')) ?></strong><small><?= e(t('workouts.stats_subtitle')) ?></small></span><span class="hierarchy-nav-chevron" aria-hidden="true">&rsaquo;</span></a>
                <a class="hierarchy-nav-row" data-tone="orange" href="/?page=week_editor&range=week"><span class="hierarchy-nav-icon" aria-hidden="true"><?= activity_icon_svg('target') ?></span><span class="hierarchy-nav-copy"><strong><?= e(t('workouts.challenge_log')) ?></strong><small><?= e(t('workouts.challenge_log_hint')) ?></small></span><span class="hierarchy-nav-chevron" aria-hidden="true">&rsaquo;</span></a>
            </nav>
        <?php else: ?>
            <header class="workouts-mobile-subheader hierarchy-page-header<?= $wkView === 'library' ? ' is-library' : '' ?>">
                <button class="hierarchy-back destination-back" type="button" data-hierarchy-back data-fallback="/?page=workouts" aria-label="<?= e(t('common.back')) ?>: <?= e(t('workouts.title')) ?>"><span aria-hidden="true">&larr;</span><strong><?= e(t('workouts.title')) ?></strong></button>
                <div><p class="eyebrow"><?= e(t('workouts.title')) ?></p><h1><?= e($tabView === 'stats' ? t('workouts.stats') : t('workouts.tab_' . $tabView)) ?></h1></div>
                <?php if ($wkView === 'library'): ?>
                    <?php $libraryToolbarVariant = 'mobile'; require __DIR__ . '/partials/workout_library_toolbar.php'; ?>
                <?php endif; ?>
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
            <div><p class="eyebrow"><?= count((array) ($wkRoutines ?? [])) ?> <?= e(t('workouts.routines')) ?></p><h2><?= e(t('workouts.your_routines')) ?></h2></div>
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
        <?php if ($archivedRoutines !== []): ?>
            <details class="workouts-archived workouts-all-routines-archived" data-persist-disclosure="workouts.archived">
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

<?php elseif ($wkView === 'list'): ?>
    <?php
    $summaryMonth = (array) ($wkSummaryMonth ?? []);
    $summaryAll = (array) ($wkSummaryAll ?? []);
    ?>
    <div class="workouts-overview-summary" aria-label="<?= e(t('workouts.stats')) ?>">
        <article class="workouts-overview-kpi-strip compact-metrics-row glass-panel">
            <span><small><?= e(t('workouts.stat_sessions')) ?> · <?= e(t('workouts.this_month')) ?></small><strong><?= (int) ($summaryMonth['sessions'] ?? 0) ?></strong></span>
            <span><small><?= e(t('workouts.stat_volume')) ?> · <?= e(t('workouts.this_month')) ?></small><strong><?= e(number_format((float) ($summaryMonth['volume'] ?? 0), 0, '.', ' ')) ?></strong></span>
            <span><small><?= e(t('workouts.stat_sessions')) ?> · <?= e(t('workouts.all_time')) ?></small><strong><?= (int) ($summaryAll['sessions'] ?? 0) ?></strong></span>
            <span><small><?= e(t('workouts.stat_reps')) ?> · <?= e(t('workouts.all_time')) ?></small><strong><?= e(number_format((int) ($summaryAll['reps'] ?? 0), 0, '.', ' ')) ?></strong></span>
        </article>
    </div>

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
                </div>
            </details>

    <?php if ($activeRoutines === []): ?>
        <button type="button" class="workouts-routines-empty-create" data-app-modal-open="wk-new-routine-modal">
            <span class="workouts-overview-disclosure-icon" aria-hidden="true"><?= activity_icon_svg('dumbbell') ?></span>
            <strong><?= e(t('workouts.create_new_routine')) ?></strong>
            <span aria-hidden="true">+</span>
        </button>
    <?php else: ?>
        <details class="workouts-overview-disclosure workouts-routines-disclosure" data-persist-disclosure="workouts.routines">
            <summary>
                <span class="workouts-overview-disclosure-icon" aria-hidden="true"><?= activity_icon_svg('dumbbell') ?></span>
                <span><strong><?= e(t('workouts.your_routines')) ?></strong><small><?= count($activeRoutines) ?> <?= e(t('workouts.routines')) ?></small></span>
                <b aria-hidden="true">&rsaquo;</b>
            </summary>
            <div class="workouts-overview-disclosure-body workouts-routines-disclosure-body">
                <div class="workouts-routines-head-actions" aria-label="<?= e(t('workouts.your_routines')) ?>">
                    <button type="button" class="btn btn-primary small" data-app-modal-open="wk-new-routine-modal"><span aria-hidden="true">+</span> <?= e(t('workouts.new_routine')) ?></button>
                    <?php if (count($activeRoutines) > 3): ?><a class="btn btn-ghost small workouts-organize-routines-link" href="/?page=workouts&view=organize"><?= e(t('common.view_all')) ?></a><?php endif; ?>
                </div>
            <div class="workouts-routine-grid workouts-routine-preview-grid">
                <?php foreach (array_slice($activeRoutines, 0, 3) as $routine): ?>
                    <?php
                    $rid = (int) $routine['id'];
                    $routineIcon = wk_normalize_routine_icon($routine['icon'] ?? 'dumbbell');
                    $routineAccent = wk_normalize_routine_color($routine['accent_color'] ?? '#14b8a6');
                    $routineCover = $workoutCoverAsset((array) $routine);
                    [$routineStateKey, $routineStateLabel] = $workoutRoutineState((array) $routine);
                    $routineMenu = render_kebab_menu([
                        ['label' => t('common.edit'), 'href' => '/?page=workouts&routine_id=' . $rid],
                        ['label' => t('menu.organize'), 'href' => '/?page=workouts&routine_id=' . $rid . '&section=organize'],
                        ['label' => (int) ($routine['is_favorite'] ?? 0) === 1 ? t('workouts.favorite') . ' ✓' : t('workouts.favorite'), 'attrs' => ['data-wk-submit' => 'routine_favorite', 'data-wk-routine' => (string) $rid, 'data-wk-value' => (int) ($routine['is_favorite'] ?? 0) === 1 ? '0' : '1']],
                        ['label' => t('workouts.duplicate'), 'attrs' => ['data-wk-submit' => 'routine_duplicate', 'data-wk-routine' => (string) $rid]],
                        ['label' => t('workouts.archive'), 'attrs' => ['data-wk-submit' => 'routine_archive', 'data-wk-routine' => (string) $rid, 'data-wk-value' => '1']],
                        ['label' => t('workouts.delete_routine'), 'danger' => true, 'attrs' => ['data-wk-submit' => 'routine_delete', 'data-wk-routine' => (string) $rid, 'data-wk-confirm' => t('common.confirm_delete')]],
                    ], ['align' => 'end', 'title' => (string) $routine['name']]);
                    ?>
                    <article class="workouts-routine-card compact-list-item is-<?= e($routineStateKey) ?><?= (int) ($routine['is_favorite'] ?? 0) === 1 ? ' is-favorite' : '' ?><?= $routineCover !== null ? ' has-cover' : '' ?>" style="--routine-accent: <?= e($routineAccent) ?>" data-state="<?= e($routineStateKey) ?>">
                        <a class="workouts-routine-preview-media<?= $routineCover !== null ? ' has-image' : '' ?>" href="/?page=workouts&amp;routine_id=<?= $rid ?>" aria-label="<?= e((string) $routine['name']) ?>">
                            <?php if ($routineCover !== null): ?>
                                <img src="<?= e((string) $routineCover['url']) ?>" alt="" style="object-position: <?= e($workoutCoverPosition($routineCover)) ?>">
                                <?php if (!empty($routineCover['has_video'])): ?><span aria-hidden="true">&#9654;</span><?php endif; ?>
                            <?php else: ?>
                                <span aria-hidden="true"><?= activity_icon_svg($routineIcon) ?></span>
                            <?php endif; ?>
                        </a>
                        <a class="workouts-routine-preview-copy" href="/?page=workouts&amp;routine_id=<?= $rid ?>">
                            <span><strong><?= e((string) $routine['name']) ?></strong><?php if ((int) ($routine['is_favorite'] ?? 0) === 1): ?><span class="workouts-fav-star" aria-label="<?= e(t('workouts.favorite')) ?>">★</span><?php endif; ?></span>
                            <small class="workouts-routine-preview-meta"><span class="state-<?= e($routineStateKey) ?>"><i aria-hidden="true"></i><?= e($routineStateLabel) ?></span><span aria-hidden="true">·</span><span class="workouts-routine-preview-count"><?= (int) ($routine['exercise_count'] ?? 0) ?> <?= e(t('workouts.exercises')) ?></span></small>
                        </a>
                        <div class="workouts-routine-preview-actions">
                            <form method="post" action="/?page=workouts" class="inline-form">
                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                <input type="hidden" name="action" value="session_start">
                                <input type="hidden" name="routine_id" value="<?= $rid ?>">
                                <button type="submit" class="btn btn-primary small workouts-routine-start-btn" aria-label="<?= e(t('workouts.start_routine')) ?>: <?= e((string) $routine['name']) ?>" title="<?= e(!empty($wkActiveSession) ? t('workouts.finish_active_first') : t('workouts.start_routine')) ?>"<?= !empty($wkActiveSession) ? ' disabled' : '' ?>><span aria-hidden="true">&#9654;</span><span class="sr-only"><?= e(t('workouts.start_routine')) ?></span></button>
                            </form>
                            <?= $routineMenu ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php if (count($activeRoutines) > 3): ?>
                <a class="workouts-routine-preview-more" href="/?page=workouts&amp;view=organize"><span><?= e(t('common.view_all')) ?></span><strong><?= count($activeRoutines) ?></strong><span aria-hidden="true">&rarr;</span></a>
            <?php endif; ?>
            </div>
        </details>
    <?php endif; ?>

    <details class="workouts-overview-disclosure workouts-history-disclosure" data-persist-disclosure="workouts.history" open>
        <summary>
            <span class="workouts-overview-disclosure-icon" aria-hidden="true"><?= activity_icon_svg('run') ?></span>
            <span><strong><?= e(t('workouts.progress_history')) ?></strong><small><?= e(t('workouts.progress_history_hint')) ?></small></span>
            <b aria-hidden="true">&rsaquo;</b>
        </summary>
        <div class="workouts-overview-disclosure-body">
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
        </div>
    </details>

<?php elseif ($wkView === 'plan'): ?>
    <article class="panel workouts-section-intro">
        <div>
            <p class="eyebrow"><?= e(t('workouts.week_schedule')) ?></p>
            <h2><?= e(t('workouts.plan_title')) ?></h2>
            <p class="muted"><?= e(t('workouts.plan_subtitle')) ?></p>
        </div>
        <button type="button" class="btn btn-primary" data-app-modal-open="wk-new-routine-modal">+ <?= e(t('workouts.new_routine')) ?></button>
    </article>

    <div class="workouts-week-grid workouts-week-agenda">
        <?php foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $day): ?>
            <?php $dayRoutines = (array) ($routinesByDay[$day] ?? []); ?>
            <article class="workouts-day-card<?= $dayRoutines === [] ? ' is-rest' : '' ?>">
                <header><span><?= e($dayLabel($day)) ?></span><strong><?= count($dayRoutines) ?></strong></header>
                <?php if ($dayRoutines === []): ?>
                    <p><?= e(t('workouts.rest_day')) ?></p>
                <?php else: ?>
                    <div class="workouts-day-routines">
                        <?php foreach ($dayRoutines as $routine): ?>
                            <?php
                            $dayRoutineIcon = wk_normalize_routine_icon($routine['icon'] ?? 'dumbbell');
                            $dayRoutineAccent = wk_normalize_routine_color($routine['accent_color'] ?? '#14b8a6');
                            $dayRoutineCover = $workoutCoverAsset((array) $routine);
                            [$dayRoutineStateKey, $dayRoutineStateLabel] = $workoutRoutineState((array) $routine, true);
                            ?>
                            <div class="workouts-day-routine is-<?= e($dayRoutineStateKey) ?>" style="--routine-accent: <?= e($dayRoutineAccent) ?>" data-state="<?= e($dayRoutineStateKey) ?>">
                                <a href="/?page=workouts&routine_id=<?= (int) $routine['id'] ?>">
                                    <span class="workouts-routine-icon is-small<?= $dayRoutineCover !== null ? ' has-media' : '' ?>" aria-hidden="true"><?php if ($dayRoutineCover !== null): ?><img src="<?= e((string) $dayRoutineCover['url']) ?>" alt="" style="object-position: <?= e($workoutCoverPosition($dayRoutineCover)) ?>"><?php else: ?><?= activity_icon_svg($dayRoutineIcon) ?><?php endif; ?><?php if (!empty($dayRoutineCover['has_video'])): ?><b>&#9654;</b><?php endif; ?></span>
                                    <span class="workouts-day-routine-copy"><strong><?= e((string) $routine['name']) ?></strong><span><?= (int) ($routine['exercise_count'] ?? 0) ?> <?= e(t('workouts.exercises')) ?> · <?= e($dayRoutineStateLabel) ?></span></span>
                                </a>
                                <form method="post" action="/?page=workouts" class="inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                    <input type="hidden" name="action" value="session_start">
                                    <input type="hidden" name="routine_id" value="<?= (int) $routine['id'] ?>">
                                    <button class="btn btn-primary small" type="submit"<?= $hasActiveWorkoutSession ? ' disabled title="' . e(t('workouts.finish_active_first')) . '"' : '' ?>><?= e(t('workouts.start_routine')) ?></button>
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
                    <?php
                    $unscheduledIcon = wk_normalize_routine_icon($routine['icon'] ?? 'dumbbell');
                    $unscheduledAccent = wk_normalize_routine_color($routine['accent_color'] ?? '#14b8a6');
                    $unscheduledCover = $workoutCoverAsset((array) $routine);
                    [$unscheduledStateKey, $unscheduledStateLabel] = $workoutRoutineState((array) $routine);
                    ?>
                    <a class="is-<?= e($unscheduledStateKey) ?>" data-state="<?= e($unscheduledStateKey) ?>" href="/?page=workouts&routine_id=<?= (int) $routine['id'] ?>" style="--routine-accent: <?= e($unscheduledAccent) ?>"><span class="workouts-routine-icon is-small<?= $unscheduledCover !== null ? ' has-media' : '' ?>" aria-hidden="true"><?php if ($unscheduledCover !== null): ?><img src="<?= e((string) $unscheduledCover['url']) ?>" alt="" style="object-position: <?= e($workoutCoverPosition($unscheduledCover)) ?>"><?php else: ?><?= activity_icon_svg($unscheduledIcon) ?><?php endif; ?><?php if (!empty($unscheduledCover['has_video'])): ?><b>&#9654;</b><?php endif; ?></span><span><strong><?= e((string) $routine['name']) ?></strong><small><?= (int) ($routine['exercise_count'] ?? 0) ?> <?= e(t('workouts.exercises')) ?> · <?= e($unscheduledStateLabel) ?></small></span></a>
                <?php endforeach; ?>
            </div>
        </article>
    <?php endif; ?>

    <article class="panel compact-panel glass-panel workouts-routines-panel">
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
    <?php if ($hasLibraryTarget): ?>
        <aside class="workouts-library-target" aria-label="<?= e(t('workouts.add_exercises')) ?>">
            <span class="workouts-library-target-icon"<?= $targetRoutineId > 0 ? ' style="--routine-accent: ' . e(wk_normalize_routine_color($targetRoutine['accent_color'] ?? '#14b8a6')) . '"' : '' ?>><?= activity_icon_svg($targetSessionId > 0 ? 'fire' : wk_normalize_routine_icon($targetRoutine['icon'] ?? 'dumbbell')) ?></span>
            <div><small><?= e($targetSessionId > 0 ? t('workouts.adding_to_session') : t('workouts.add_exercises_to')) ?></small><strong><?= e($targetSessionId > 0 ? ((string) ($targetSession['title'] ?? '') !== '' ? (string) $targetSession['title'] : t('workouts.active_session')) : (string) ($targetRoutine['name'] ?? '')) ?></strong></div>
            <a class="btn btn-primary small" href="<?= e($targetSessionId > 0 ? '/?page=workouts&session_id=' . $targetSessionId : '/?page=workouts&routine_id=' . $targetRoutineId) ?>"><?= e($targetSessionId > 0 ? t('workouts.back_to_session') : t('workouts.finish_adding')) ?></a>
        </aside>
    <?php endif; ?>
    <?php if ($libraryMode !== 'organize'): ?>
    <article class="panel workouts-library-head">
        <nav class="workouts-library-scope-tabs" aria-label="<?= e(t('workouts.library_scope')) ?>">
            <a href="<?= e($libraryUrl(['scope' => null, 'library_page' => null])) ?>"<?= $libraryScope === '' ? ' aria-current="page"' : '' ?>><span class="workouts-library-scope-icon" aria-hidden="true"><?= activity_icon_svg('dumbbell') ?></span><span class="workouts-library-scope-label"><span class="workouts-library-scope-label-full"><?= e(t('workouts.all_exercises')) ?></span><span class="workouts-library-scope-label-short"><?= e(t('workouts.all_exercises_short')) ?></span></span><strong><?= count($exercises) ?></strong></a>
            <a href="<?= e($libraryUrl(['scope' => 'favorites', 'library_page' => null])) ?>"<?= $libraryScope === 'favorites' ? ' aria-current="page"' : '' ?>><span class="workouts-library-scope-icon" aria-hidden="true"><?= activity_icon_svg('star') ?></span><span class="workouts-library-scope-label"><span class="workouts-library-scope-label-full"><?= e(t('workouts.favorites')) ?></span><span class="workouts-library-scope-label-short"><?= e(t('workouts.favorites')) ?></span></span><strong><?= $favoriteExerciseCount ?></strong></a>
            <a href="<?= e($libraryUrl(['scope' => 'mine', 'library_page' => null])) ?>"<?= $libraryScope === 'mine' ? ' aria-current="page"' : '' ?>><span class="workouts-library-scope-icon" aria-hidden="true"><?= activity_icon_svg('user') ?></span><span class="workouts-library-scope-label"><span class="workouts-library-scope-label-full"><?= e(t('workouts.my_exercises')) ?></span><span class="workouts-library-scope-label-short"><?= e(t('workouts.my_exercises_short')) ?></span></span><strong><?= $personalExerciseCount ?></strong></a>
        </nav>
        <form method="get" action="/" id="workouts-library-search" class="workouts-library-mobile-search workouts-library-quick-search<?= $hasLibrarySearch ? ' is-open' : '' ?>" data-workout-search-panel aria-hidden="<?= $hasLibrarySearch ? 'false' : 'true' ?>">
            <input type="hidden" name="page" value="workouts"><input type="hidden" name="view" value="library">
            <?php if ($targetRoutineId > 0): ?><input type="hidden" name="target_routine_id" value="<?= $targetRoutineId ?>"><?php endif; ?>
            <?php if ($targetSessionId > 0): ?><input type="hidden" name="target_session_id" value="<?= $targetSessionId ?>"><?php endif; ?>
            <?php if ($libraryScope !== ''): ?><input type="hidden" name="scope" value="<?= e($libraryScope) ?>"><?php endif; ?>
            <?php if ((string) ($filters['equipment'] ?? '') !== ''): ?><input type="hidden" name="equipment" value="<?= e((string) $filters['equipment']) ?>"><?php endif; ?>
            <?php if ((string) ($filters['context'] ?? '') !== ''): ?><input type="hidden" name="context" value="<?= e((string) $filters['context']) ?>"><?php endif; ?>
            <?php if ((string) ($filters['muscle'] ?? '') !== ''): ?><input type="hidden" name="muscle" value="<?= e((string) $filters['muscle']) ?>"><?php endif; ?>
            <label><span class="sr-only"><?= e(t('workouts.search_exercises')) ?></span><input type="search" name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>" placeholder="<?= e(t('workouts.search_exercises')) ?>"></label>
            <button class="btn btn-primary btn-icon" type="submit" aria-label="<?= e(t('workouts.search_exercises')) ?>"><?= activity_icon_svg('search') ?></button>
        </form>
        <div class="workouts-library-results<?= $hasAnyLibraryFilter ? ' is-filtered' : '' ?>" aria-live="polite">
            <span class="workouts-library-results-icon" aria-hidden="true"><?= activity_icon_svg('dumbbell') ?></span>
            <div>
                <strong><?= e(t('workouts.library_results', ['count' => $libraryResultTotal])) ?></strong>
                <?php if ($hasAnyLibraryFilter): ?>
                    <small><?php if ($hasLibrarySearch): ?><span>&ldquo;<?= e(trim((string) $filters['q'])) ?>&rdquo;</span><?php endif; ?><?php if ((string) ($filters['muscle'] ?? '') !== ''): ?><span><?= e($muscleLabel((string) $filters['muscle'])) ?></span><?php endif; ?><?php if ((string) ($filters['equipment'] ?? '') !== ''): ?><span><?= e($equipmentLabel((string) $filters['equipment'])) ?></span><?php endif; ?><?php if ((string) ($filters['context'] ?? '') !== ''): ?><span><?= e($contextLabel((string) $filters['context'])) ?></span><?php endif; ?></small>
                <?php else: ?>
                    <small><?= e(t('workouts.library_subtitle')) ?></small>
                <?php endif; ?>
            </div>
            <?php if ($hasAnyLibraryFilter): ?><a href="<?= e($libraryClearUrl) ?>"><?= e(t('common.clear')) ?><span aria-hidden="true">&times;</span></a><?php endif; ?>
        </div>
    </article>
    <div class="workouts-filter-sheet" id="workouts-library-filters" data-workout-filter-panel aria-hidden="true">
        <div class="workouts-filter-sheet-head"><div><strong><?= e(t('common.filter')) ?></strong><small><?= e(t('workouts.library_results', ['count' => $libraryResultTotal])) ?></small></div><?php if ($hasAnyLibraryFilter): ?><a href="<?= e($libraryClearUrl) ?>"><?= e(t('common.clear')) ?></a><?php endif; ?><button type="button" data-workout-filter-close aria-label="<?= e(t('common.close_action')) ?>">&times;</button></div>
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
            <label><span class="sr-only"><?= e(t('workouts.context')) ?></span><select name="context">
                <option value=""><?= e(t('workouts.all_contexts')) ?></option>
                <?php foreach ((array) ($wkContextOptions ?? []) as $context): ?><option value="<?= e((string) $context) ?>"<?= (string) ($filters['context'] ?? '') === (string) $context ? ' selected' : '' ?>><?= e($contextLabel((string) $context)) ?></option><?php endforeach; ?>
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
    </div>
    <?php endif; ?>

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
        <?php
        $libraryTotal = max(0, (int) ($wkLibraryTotal ?? count($libraryExercises)));
        $libraryPage = max(1, (int) ($wkLibraryPage ?? 1));
        $libraryPerPage = max(1, (int) ($wkLibraryPerPage ?? 12));
        $libraryPages = max(1, (int) ceil($libraryTotal / $libraryPerPage));
        $libraryLoadedCount = min($libraryTotal, $libraryPage * $libraryPerPage);
        ?>
        <div class="workouts-library-grid<?= $hasLibraryTarget ? ' is-contextual' : '' ?><?= $useCompactLibrary ? ' is-compact' : '' ?>"
             data-library-layout="<?= e($useCompactLibrary ? 'compact' : 'cards') ?>"
             data-library-infinite-grid
             data-library-total="<?= $libraryTotal ?>">
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
                $libraryRankScore = (float) ($rank['score'] ?? 0);
                $libraryTrainingDefaults = wk_exercise_training_defaults((array) $exercise);
                $libraryTrainingLabel = match ((string) ($exercise['exercise_type'] ?? 'strength')) {
                    'cardio' => $libraryTrainingDefaults['target_sets'] . '×' . ($libraryTrainingDefaults['target_duration'] !== null ? rtrim(rtrim(number_format((int) $libraryTrainingDefaults['target_duration'] / 60, 1, '.', ''), '0'), '.') . ' min' : '—'),
                    'isometric' => $libraryTrainingDefaults['target_sets'] . '×' . ($libraryTrainingDefaults['target_duration'] !== null ? (int) $libraryTrainingDefaults['target_duration'] . 's' : '—'),
                    default => $libraryTrainingDefaults['target_sets'] . '×' . ($libraryTrainingDefaults['target_reps'] ?? '—'),
                };
                ?>
                <article class="workouts-library-card<?= $isPersonalExercise ? ' is-personal' : '' ?><?= $isFavoriteExercise ? ' is-favorite' : '' ?>" style="<?= e($workoutExerciseStyle((array) $exercise)) ?>" data-exercise-id="<?= (int) $exercise['id'] ?>" data-muscle="<?= e((string) ($exercise['muscle_group'] ?? '')) ?>" data-cover-mode="<?= e($exerciseCoverMode) ?>">
                    <?php $libraryImageUrl = $useExercisePhoto ? media_thumbnail_url($exerciseImagePath, 400) : ''; ?>
                    <?php if ($libraryImageUrl !== ''): ?>
                        <a class="workouts-library-media" href="<?= e($exerciseGuideUrl) ?>" aria-label="<?= e((string) ($exercise['display_name'] ?? $exercise['name'])) ?>">
                            <img src="<?= e($libraryImageUrl) ?>" srcset="<?= e(media_thumbnail_srcset($exerciseImagePath, [200, 400, 800])) ?>" sizes="(max-width: 700px) calc(100vw - 32px), 360px" width="400" height="225" alt="" loading="lazy" decoding="async" style="object-position: <?= e($exerciseImagePosition) ?>">
                            <span class="workouts-library-media-badges"><?php if ($isPersonalExercise): ?><em><?= e(t('workouts.mine')) ?></em><?php endif; ?><?php if ($hasExerciseVideo): ?><em aria-label="<?= e(t('workouts.custom_video')) ?>"><?= activity_icon_svg('play') ?></em><?php endif; ?></span>
                        </a>
                    <?php elseif ($useVideoThumbnail): ?>
                        <a class="workouts-library-media is-video-thumbnail" href="<?= e($exerciseGuideUrl) ?>" aria-label="<?= e((string) ($exercise['display_name'] ?? $exercise['name'])) ?>" style="--workout-video-thumb: url('<?= e($libraryVideoThumbnail) ?>')">
                            <span class="workouts-library-video-fallback" aria-hidden="true"><?= e($workoutExerciseMark((array) $exercise)) ?></span>
                            <span class="workouts-library-video-thumb" aria-hidden="true"></span>
                            <span class="workouts-library-play" aria-hidden="true"><?= activity_icon_svg('play') ?></span>
                            <span class="workouts-library-media-badges"><?php if ($isPersonalExercise): ?><em><?= e(t('workouts.mine')) ?></em><?php endif; ?><em><?= e(t('workouts.youtube')) ?></em></span>
                        </a>
                    <?php else: ?>
                        <a class="workouts-library-media is-placeholder" href="<?= e($exerciseGuideUrl) ?>" aria-label="<?= e((string) ($exercise['display_name'] ?? $exercise['name'])) ?>"><span><?= activity_icon_svg('dumbbell') ?></span><small><?= e($hasExerciseVideo ? t('workouts.video_available') : $muscleLabel((string) ($exercise['muscle_group'] ?? ''))) ?></small><span class="workouts-library-media-badges"><?php if ($isPersonalExercise): ?><em><?= e(t('workouts.mine')) ?></em><?php endif; ?><?php if ($hasExerciseVideo): ?><em aria-label="<?= e(t('workouts.custom_video')) ?>"><?= activity_icon_svg('play') ?></em><?php endif; ?></span></a>
                    <?php endif; ?>
                    <div class="workouts-library-card-head">
                        <form method="post" action="/?page=workouts" class="workouts-favorite-form">
                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="exercise_favorite"><input type="hidden" name="exercise_id" value="<?= (int) $exercise['id'] ?>"><input type="hidden" name="value" value="<?= $isFavoriteExercise ? 0 : 1 ?>">
                                <input type="hidden" name="muscle" value="<?= e((string) ($filters['muscle'] ?? '')) ?>"><input type="hidden" name="equipment" value="<?= e((string) ($filters['equipment'] ?? '')) ?>"><input type="hidden" name="context" value="<?= e((string) ($filters['context'] ?? '')) ?>"><input type="hidden" name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>"><input type="hidden" name="scope" value="<?= e($libraryScope) ?>"><input type="hidden" name="library_page" value="<?= (int) ($wkLibraryPage ?? 1) ?>">
                                <?php if ($targetRoutineId > 0): ?><input type="hidden" name="target_routine_id" value="<?= $targetRoutineId ?>"><?php endif; ?>
                                <?php if ($targetSessionId > 0): ?><input type="hidden" name="target_session_id" value="<?= $targetSessionId ?>"><?php endif; ?>
                                <button class="workouts-favorite-toggle<?= $isFavoriteExercise ? ' is-active' : '' ?>" type="submit" aria-pressed="<?= $isFavoriteExercise ? 'true' : 'false' ?>" aria-label="<?= e($isFavoriteExercise ? t('workouts.remove_favorite') : t('workouts.add_favorite')) ?>"><?= activity_icon_svg('star') ?></button>
                        </form>
                    </div>
                    <div class="workouts-library-copy">
                        <h3><a class="workouts-library-name-link" href="<?= e($exerciseGuideUrl) ?>"><?= e((string) ($exercise['display_name'] ?? $exercise['name'])) ?></a></h3>
                        <p><?= e((string) ($content['summary'] ?? '')) ?></p>
                    </div>
                    <?php if ($libraryRankScore > 0): ?>
                        <div class="workouts-library-card-meta">
                            <span class="workouts-rank-badge" data-rank="<?= e($rankKey) ?>"><?= e($rankLabel($rankKey)) ?></span>
                            <small><?= e(t('workouts.rank_points_short', ['points' => number_format($libraryRankScore, 1, '.', '')])) ?></small>
                        </div>
                    <?php endif; ?>
                    <div class="workouts-exercise-tags"><span><?= e($muscleLabel((string) ($exercise['muscle_group'] ?? ''))) ?></span><span><?= e($equipmentLabel((string) ($exercise['equipment'] ?? ''))) ?></span><span><?= e($difficultyLabel((string) ($exercise['difficulty'] ?? 'beginner'))) ?></span><?php if ($isPersonalExercise): ?><span class="workouts-training-default-chip" title="<?= e(t('workouts.training_defaults')) ?>"><?= e($libraryTrainingLabel) ?></span><?php endif; ?></div>
                    <div class="workouts-library-card-foot">
                        <div class="inline-actions"><a class="btn btn-ghost small" href="<?= e($exerciseGuideUrl) ?>"><?= e(t('workouts.view_guide')) ?></a><?php if ($isPersonalExercise): ?><a class="btn btn-ghost small" href="<?= e($libraryUrl(['custom_exercise' => (int) $exercise['id'], 'library_page' => null])) ?>"><?= e(t('common.edit')) ?></a><?php endif; ?></div>
                        <?php if ($targetSessionId > 0): ?>
                            <form method="post" action="/?page=workouts" class="workouts-library-add is-contextual">
                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="library_add_exercise"><input type="hidden" name="exercise_def_id" value="<?= (int) $exercise['id'] ?>"><input type="hidden" name="target_session_id" value="<?= $targetSessionId ?>">
                                <input type="hidden" name="muscle" value="<?= e((string) ($filters['muscle'] ?? '')) ?>"><input type="hidden" name="equipment" value="<?= e((string) ($filters['equipment'] ?? '')) ?>"><input type="hidden" name="context" value="<?= e((string) ($filters['context'] ?? '')) ?>"><input type="hidden" name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>"><input type="hidden" name="scope" value="<?= e((string) ($filters['scope'] ?? '')) ?>"><input type="hidden" name="library_page" value="<?= (int) ($wkLibraryPage ?? 1) ?>">
                                <button class="btn <?= $isInLibraryTarget ? 'btn-ghost is-added' : 'btn-primary' ?> small" type="submit"<?= $isInLibraryTarget ? ' disabled' : '' ?>><?= e($isInLibraryTarget ? t('workouts.added_to_session') : t('workouts.add_to_session')) ?></button>
                            </form>
                        <?php elseif ($targetRoutineId > 0): ?>
                            <form method="post" action="/?page=workouts" class="workouts-library-add is-contextual">
                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="library_add_exercise"><input type="hidden" name="exercise_def_id" value="<?= (int) $exercise['id'] ?>"><input type="hidden" name="routine_id" value="<?= $targetRoutineId ?>"><input type="hidden" name="target_routine_id" value="<?= $targetRoutineId ?>">
                                <input type="hidden" name="muscle" value="<?= e((string) ($filters['muscle'] ?? '')) ?>"><input type="hidden" name="equipment" value="<?= e((string) ($filters['equipment'] ?? '')) ?>"><input type="hidden" name="context" value="<?= e((string) ($filters['context'] ?? '')) ?>"><input type="hidden" name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>"><input type="hidden" name="scope" value="<?= e((string) ($filters['scope'] ?? '')) ?>"><input type="hidden" name="library_page" value="<?= (int) ($wkLibraryPage ?? 1) ?>">
                                <button class="btn <?= $isInLibraryTarget ? 'btn-ghost is-added' : 'btn-primary' ?> small" type="submit"<?= $isInLibraryTarget ? ' disabled' : '' ?>><?= e($isInLibraryTarget ? t('workouts.added_to_routine') : t('workouts.add_to_routine')) ?></button>
                            </form>
                        <?php elseif ($activeRoutines !== []): ?>
                            <button class="btn btn-primary workouts-library-add-trigger" type="button" data-app-modal-open="wk-add-exercise-modal" data-workout-routine-picker-open data-exercise-id="<?= (int) $exercise['id'] ?>" data-exercise-name="<?= e((string) ($exercise['display_name'] ?? $exercise['name'])) ?>" aria-label="<?= e(t('workouts.add_to_routine')) ?>"><span aria-hidden="true"><?= activity_icon_svg('plus') ?></span><span class="workouts-library-add-label"><?= e(t('workouts.add_to_routine')) ?></span></button>
                        <?php else: ?>
                            <button class="btn btn-primary workouts-library-add-trigger" type="button" data-app-modal-open="wk-new-routine-modal" aria-label="<?= e(t('workouts.new_routine')) ?>"><span aria-hidden="true"><?= activity_icon_svg('plus') ?></span><span class="workouts-library-add-label"><?= e(t('workouts.new_routine')) ?></span></button>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php if ($libraryPages > 1): ?>
            <div class="workouts-library-infinite"
                 data-library-infinite
                 data-loading-label="<?= e(t('workouts.loading_more')) ?>"
                 data-loaded-label="<?= e(t('workouts.loaded_count', ['loaded' => ':loaded', 'total' => ':total'])) ?>"
                 data-complete-label="<?= e(t('workouts.all_loaded')) ?>"
                 data-error-label="<?= e(t('workouts.load_failed')) ?>">
                <span class="workouts-library-loader" aria-hidden="true"></span>
                <span data-library-infinite-status aria-live="polite"><?= e(t('workouts.loaded_count', ['loaded' => $libraryLoadedCount, 'total' => $libraryTotal])) ?></span>
                <span data-library-infinite-sentinel aria-hidden="true"></span>
            </div>
            <nav class="pagination workouts-library-pagination" data-library-pagination aria-label="<?= e(t('common.pagination')) ?>">
                <?php if ($libraryPage > 1): ?><a class="btn btn-ghost" href="<?= e($libraryUrl(['library_page' => $libraryPage - 1])) ?>"><?= activity_icon_svg('chevron-left') ?> <?= e(t('common.previous')) ?></a><?php endif; ?>
                <span><?= e(t('common.page_of', ['page' => $libraryPage, 'pages' => $libraryPages])) ?></span>
                <?php if ($libraryPage < $libraryPages): ?><a class="btn btn-primary" data-library-next href="<?= e($libraryUrl(['library_page' => $libraryPage + 1])) ?>"><?= e(t('common.next')) ?> <?= activity_icon_svg('chevron-right') ?></a><?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>

<?php elseif ($wkView === 'ranks'): ?>
    <?php
    $overallRank = (array) ($wkOverallRank ?? wk_rank_from_score(0));
    $overallKey = (string) ($overallRank['key'] ?? 'unranked');
    $overallProgress = $rankProgressData($overallRank);
    $overallNextLabel = $overallProgress['next_key'] !== null ? $rankLabel((string) $overallProgress['next_key']) : '';
    $rankProfile = (array) ($wkRankProfile ?? []);
    $rankDivision = in_array((string) ($wkRankDivision ?? 'open'), ['open', 'women', 'men'], true) ? (string) ($wkRankDivision ?? 'open') : 'open';
    $rankSection = trim((string) ($_GET['rank_section'] ?? 'overview'));
    if (!in_array($rankSection, ['overview', 'body', 'exercises', 'team'], true)) {
        $rankSection = 'overview';
    }
    $rankableExerciseRanks = array_values(array_filter(
        (array) ($wkExerciseRanks ?? []),
        static fn(array $exercise): bool => (bool) ($exercise['rank']['rankable'] ?? true)
    ));
    $rankCalculationExample = null;
    foreach ($rankableExerciseRanks as $rankedExerciseCandidate) {
        $candidateCalculation = (array) ($rankedExerciseCandidate['rank']['calculation'] ?? []);
        if ((string) ($candidateCalculation['method'] ?? 'none') !== 'none' && (float) ($rankedExerciseCandidate['rank']['score'] ?? 0) > 0) {
            $rankCalculationExample = $rankedExerciseCandidate;
            break;
        }
    }
    $rankMuscleInsights = [];
    foreach ((array) ($wkMuscleRanks ?? []) as $muscleRankInsight) {
        $muscleInsightRank = (array) ($muscleRankInsight['rank'] ?? []);
        $muscleInsightProgress = $rankProgressData($muscleInsightRank);
        $rankMuscleInsights[] = [
            'muscle' => (string) ($muscleRankInsight['muscle'] ?? ''),
            'label' => $muscleLabel((string) ($muscleRankInsight['muscle'] ?? '')),
            'rank_key' => (string) ($muscleInsightRank['key'] ?? 'unranked'),
            'score' => (float) ($muscleInsightProgress['score'] ?? 0),
            'remaining' => (float) ($muscleInsightProgress['remaining'] ?? 0),
            'next_key' => $muscleInsightProgress['next_key'] ?? null,
            'ranked' => max(0, (int) ($muscleRankInsight['ranked_count'] ?? 0)),
            'total' => max(0, (int) ($muscleRankInsight['catalog_count'] ?? 0)),
        ];
    }
    usort($rankMuscleInsights, static function (array $left, array $right): int {
        $scoreOrder = ((float) $right['score']) <=> ((float) $left['score']);
        if ($scoreOrder !== 0) {
            return $scoreOrder;
        }
        $leftCoverage = (int) $left['total'] > 0 ? (int) $left['ranked'] / (int) $left['total'] : 0;
        $rightCoverage = (int) $right['total'] > 0 ? (int) $right['ranked'] / (int) $right['total'] : 0;

        return $rightCoverage <=> $leftCoverage;
    });
    $strongestMuscleRank = $rankMuscleInsights !== [] && (float) $rankMuscleInsights[0]['score'] > 0
        ? $rankMuscleInsights[0]
        : null;
    $weakestMuscleRank = $rankMuscleInsights !== [] ? $rankMuscleInsights[count($rankMuscleInsights) - 1] : null;
    $rankCoverageRanked = array_sum(array_map(static fn(array $item): int => (int) $item['ranked'], $rankMuscleInsights));
    $rankCoverageTotal = array_sum(array_map(static fn(array $item): int => (int) $item['total'], $rankMuscleInsights));
    $rankCoveragePercent = $rankCoverageTotal > 0 ? (int) round(($rankCoverageRanked / $rankCoverageTotal) * 100) : 0;
    $rankMenu = [
        'overview' => ['label' => t('workouts.rank_menu_overview'), 'icon' => 'trophy', 'count' => (int) $overallProgress['progress'] . '%'],
        'body' => ['label' => t('workouts.rank_menu_body'), 'icon' => 'target', 'count' => count((array) ($wkMuscleRanks ?? []))],
        'exercises' => ['label' => t('workouts.rank_menu_exercises'), 'icon' => 'dumbbell', 'count' => count($rankableExerciseRanks)],
        'team' => ['label' => t('workouts.rank_menu_team'), 'icon' => 'users', 'count' => count((array) ($wkRankLeaderboard ?? []))],
    ];
    ?>
    <div class="workouts-rank-page" data-workouts-rank-section="<?= e($rankSection) ?>">
        <nav class="workouts-rank-menu" aria-label="<?= e(t('workouts.tab_ranks')) ?>">
            <?php foreach ($rankMenu as $rankMenuKey => $rankMenuItem): ?>
                <a href="/?page=workouts&amp;view=ranks&amp;rank_section=<?= e($rankMenuKey) ?>&amp;rank_division=<?= e($rankDivision) ?>"<?= $rankSection === $rankMenuKey ? ' aria-current="page"' : '' ?> data-spa-link>
                    <span class="workouts-rank-menu-icon" aria-hidden="true"><?= activity_icon_svg((string) $rankMenuItem['icon']) ?></span>
                    <strong><?= e((string) $rankMenuItem['label']) ?></strong>
                    <small><?= e((string) $rankMenuItem['count']) ?></small>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php if ($rankSection === 'overview'): ?>
            <article class="workouts-rank-hero" data-rank="<?= e($overallKey) ?>">
                <div class="workouts-rank-emblem"><span><?= e($rankLabel($overallKey)) ?></span><strong><?= e(number_format((float) $overallProgress['score'], 1, '.', '')) ?></strong><small><?= e(t('workouts.points_abbr')) ?></small></div>
                <div class="workouts-rank-hero-copy">
                    <p class="eyebrow"><?= e(t('workouts.overall_rank')) ?> · <?= e(t('workouts.ranked_count', ['ranked' => (int) ($overallRank['body_parts_ranked'] ?? 0), 'total' => (int) ($overallRank['body_parts_total'] ?? 0)])) ?></p>
                    <h2><?= e(t('workouts.rank_title')) ?></h2>
                    <p><?= e(t('workouts.rank_subtitle')) ?></p>
                    <div class="workouts-rank-hero-progress">
                        <div>
                            <strong><?= e($overallProgress['target'] !== null ? t('workouts.rank_progress_points', ['current' => number_format((float) $overallProgress['score'], 1, '.', ''), 'target' => number_format((float) $overallProgress['target'], 1, '.', '')]) : t('workouts.rank_points_short', ['points' => number_format((float) $overallProgress['score'], 1, '.', '')])) ?></strong>
                            <span><?= e($overallProgress['next_key'] !== null ? t('workouts.rank_points_remaining', ['points' => number_format((float) $overallProgress['remaining'], 1, '.', ''), 'rank' => $overallNextLabel]) : t('workouts.rank_maximum')) ?></span>
                        </div>
                        <div class="workouts-rank-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) $overallProgress['progress'] ?>" aria-label="<?= e(t('workouts.rank_progress_percent', ['percent' => (int) $overallProgress['progress']])) ?>"><span style="width: <?= (int) $overallProgress['progress'] ?>%"></span></div>
                        <small><?= e(t('workouts.rank_progress_percent', ['percent' => (int) $overallProgress['progress']])) ?></small>
                    </div>
                </div>
            </article>
            <details class="workouts-rank-calculation" open>
                <summary>
                    <span class="workouts-rank-calculation-icon" aria-hidden="true"><?= activity_icon_svg('info') ?></span>
                    <span><strong><?= e(t('workouts.rank_calculation_title')) ?></strong><small><?= e(t('workouts.rank_calculation_hint')) ?></small></span>
                    <b aria-hidden="true"></b>
                </summary>
                <div class="workouts-rank-calculation-body">
                    <div class="workouts-rank-profile-facts">
                        <span><small><?= e(t('workouts.rank_formula_weight')) ?></small><strong><?= !empty($rankProfile['bodyweight']) ? e(number_format((float) $rankProfile['bodyweight'], 1, '.', '') . ' kg') : '—' ?></strong><em><?= !empty($rankProfile['bodyweight_date']) ? e(format_date_eu((string) $rankProfile['bodyweight_date']) . (empty($rankProfile['bodyweight_recent']) ? ' · ' . t('workouts.rank_weight_stale') : '')) : e(t('workouts.rank_profile_missing')) ?></em></span>
                        <span><small><?= e(t('workouts.rank_formula_division')) ?></small><strong><?= e(t('workouts.rank_division_' . (string) ($rankProfile['division'] ?? 'open'))) ?></strong><em><?= e(t('workouts.rank_division_only')) ?></em></span>
                        <span><small><?= e(t('workouts.rank_formula_height')) ?></small><strong><?= !empty($rankProfile['height_cm']) ? e(number_format((float) $rankProfile['height_cm'], 1, '.', '') . ' cm') : '—' ?></strong><em><?= e(t('workouts.rank_height_not_scored')) ?></em></span>
                    </div>
                    <div class="workouts-rank-formulas">
                        <div><b>01</b><span><strong><?= e(t('workouts.rank_formula_strength_title')) ?></strong><code><?= e(t('workouts.rank_formula_strength')) ?></code></span></div>
                        <div><b>02</b><span><strong><?= e(t('workouts.rank_formula_bodyweight_title')) ?></strong><code><?= e(t('workouts.rank_formula_bodyweight')) ?></code></span></div>
                        <div><b>03</b><span><strong><?= e(t('workouts.rank_formula_overall_title')) ?></strong><code><?= e(t('workouts.rank_formula_overall')) ?></code></span></div>
                    </div>
                    <?php if ($rankCalculationExample !== null): ?>
                        <?php
                        $exampleRank = (array) ($rankCalculationExample['rank'] ?? []);
                        $exampleCalculation = (array) ($exampleRank['calculation'] ?? []);
                        $exampleMethod = (string) ($exampleCalculation['method'] ?? 'none');
                        $exampleParams = [
                            'exercise' => (string) ($rankCalculationExample['display_name'] ?? $rankCalculationExample['name'] ?? ''),
                            'value' => number_format((float) ($exampleRank['value'] ?? 0), 1, '.', ''),
                            'weight' => number_format((float) ($exampleCalculation['bodyweight'] ?? 0), 1, '.', ''),
                            'factor' => number_format((float) ($exampleCalculation['factor'] ?? 1), 2, '.', ''),
                            'adjustment' => number_format((float) ($exampleCalculation['adjustment'] ?? 1), 3, '.', ''),
                            'score' => number_format((float) ($exampleRank['score'] ?? 0), 1, '.', ''),
                        ];
                        ?>
                        <div class="workouts-rank-example"><span aria-hidden="true"><?= activity_icon_svg('check') ?></span><p><strong><?= e(t('workouts.rank_example_title')) ?></strong><small><?= e(t($exampleMethod === 'bodyweight_reps' ? 'workouts.rank_example_reps' : 'workouts.rank_example_load', $exampleParams)) ?></small></p></div>
                    <?php endif; ?>
                    <p class="workouts-rank-calculation-note"><?= e(t('workouts.rank_formula_note')) ?> <a href="/?page=settings&amp;view=body"><?= e(t('workouts.rank_edit_profile')) ?></a></p>
                </div>
            </details>
            <section class="workouts-rank-overview" aria-labelledby="workouts-rank-overview-title">
                <div class="workouts-rank-overview-head">
                    <div><p class="eyebrow"><?= e(t('workouts.rank_overview_eyebrow')) ?></p><h2 id="workouts-rank-overview-title"><?= e(t('workouts.rank_overview_title')) ?></h2></div>
                    <a href="/?page=workouts&amp;view=ranks&amp;rank_section=body" data-spa-link><?= e(t('workouts.rank_menu_body')) ?> <span aria-hidden="true">&rsaquo;</span></a>
                </div>
                <div class="workouts-rank-insights">
                    <article class="workouts-rank-insight" data-tone="strong">
                        <span aria-hidden="true"><?= activity_icon_svg('trophy') ?></span>
                        <div><small><?= e(t('workouts.rank_strongest')) ?></small><strong><?= e($strongestMuscleRank !== null ? (string) $strongestMuscleRank['label'] : '—') ?></strong><p><?= e($strongestMuscleRank !== null ? $rankLabel((string) $strongestMuscleRank['rank_key']) . ' · ' . t('workouts.rank_points_short', ['points' => number_format((float) $strongestMuscleRank['score'], 1, '.', '')]) : t('workouts.no_rank_yet')) ?></p></div>
                    </article>
                    <article class="workouts-rank-insight" data-tone="improve">
                        <span aria-hidden="true"><?= activity_icon_svg('target') ?></span>
                        <div><small><?= e(t('workouts.rank_improve')) ?></small><strong><?= e($weakestMuscleRank !== null ? (string) $weakestMuscleRank['label'] : '—') ?></strong><p><?php if ($weakestMuscleRank !== null && $weakestMuscleRank['next_key'] !== null): ?><?= e(t('workouts.rank_points_remaining', ['points' => number_format((float) $weakestMuscleRank['remaining'], 1, '.', ''), 'rank' => $rankLabel((string) $weakestMuscleRank['next_key'])])) ?><?php else: ?><?= e(t('workouts.rank_maximum')) ?><?php endif; ?></p></div>
                    </article>
                    <article class="workouts-rank-insight" data-tone="coverage">
                        <span aria-hidden="true"><?= activity_icon_svg('check') ?></span>
                        <div><small><?= e(t('workouts.rank_coverage')) ?></small><strong><?= $rankCoverageRanked ?> / <?= $rankCoverageTotal ?></strong><p><?= $rankCoveragePercent ?>%</p></div>
                    </article>
                    <article class="workouts-rank-insight" data-tone="next">
                        <span aria-hidden="true"><?= activity_icon_svg('bolt') ?></span>
                        <div><small><?= e(t('workouts.rank_next_goal')) ?></small><strong><?= e($overallProgress['next_key'] !== null ? $overallNextLabel : $rankLabel($overallKey)) ?></strong><p><?= e($overallProgress['next_key'] !== null ? t('workouts.rank_points_remaining', ['points' => number_format((float) $overallProgress['remaining'], 1, '.', ''), 'rank' => $overallNextLabel]) : t('workouts.rank_maximum')) ?></p></div>
                    </article>
                </div>
                <?php if ($weakestMuscleRank !== null): ?>
                    <a class="workouts-rank-recommendation" href="/?page=workouts&amp;view=library&amp;muscle=<?= e((string) $weakestMuscleRank['muscle']) ?>">
                        <span aria-hidden="true"><?= activity_icon_svg('dumbbell') ?></span>
                        <span><small><?= e(t('workouts.rank_recommendation')) ?></small><strong><?= e(t('workouts.rank_recommendation_hint', ['area' => (string) $weakestMuscleRank['label'], 'rank' => $rankLabel((string) $weakestMuscleRank['rank_key'])])) ?></strong></span>
                        <b aria-hidden="true">&rsaquo;</b>
                    </a>
                <?php endif; ?>
            </section>
            <?php if (empty($rankProfile['bodyweight_recent'])): ?>
                <div class="workouts-rank-notice"><?= activity_icon_svg('info') ?><span><?= e(t('workouts.bodyweight_required')) ?></span><a href="/?page=entries&mode=data"><?= e(t('entries.quick_data')) ?></a></div>
            <?php endif; ?>
        <?php elseif ($rankSection === 'body'): ?>
            <article class="panel workouts-rank-section-panel">
                <div class="panel-head"><div><h2><?= e(t('workouts.body_part_ranks')) ?></h2></div><span class="workouts-rank-section-count"><?= count((array) ($wkMuscleRanks ?? [])) ?></span></div>
                <div class="workouts-body-rank-grid">
                    <?php foreach ((array) ($wkMuscleRanks ?? []) as $muscleRank): ?>
                        <?php $rank = (array) ($muscleRank['rank'] ?? []); $rankKey = (string) ($rank['key'] ?? 'unranked'); $bodyRankProgress = $rankProgressData($rank); ?>
                        <a class="workouts-body-rank-card" data-rank="<?= e($rankKey) ?>" href="/?page=workouts&view=library&muscle=<?= e((string) $muscleRank['muscle']) ?>">
                            <span class="workouts-muscle-token"><?= e(strtoupper(substr((string) $muscleRank['muscle'], 0, 2))) ?></span>
                            <span><strong><?= e($muscleLabel((string) $muscleRank['muscle'])) ?></strong><small><?= e(t('workouts.ranked_count', ['ranked' => (int) $muscleRank['ranked_count'], 'total' => (int) $muscleRank['catalog_count']])) ?> · <?= e(t('workouts.rank_points_short', ['points' => number_format((float) $bodyRankProgress['score'], 1, '.', '')])) ?></small><span class="workouts-rank-mini-progress" aria-hidden="true"><i style="width: <?= (int) $bodyRankProgress['progress'] ?>%"></i></span></span>
                            <span class="workouts-rank-badge" data-rank="<?= e($rankKey) ?>"><?= e($rankLabel($rankKey)) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </article>
        <?php elseif ($rankSection === 'exercises'): ?>
            <article class="panel workouts-rank-section-panel">
                <div class="panel-head"><div><h2><?= e(t('workouts.exercise_ranks')) ?></h2></div><span class="workouts-rank-section-count"><?= count($rankableExerciseRanks) ?></span></div>
                <div class="workouts-exercise-rank-list" data-collapsible-list data-persist-collapsible="workouts.ranks.exercises" data-mobile-count="8" data-desktop-count="14">
                    <?php foreach ($rankableExerciseRanks as $exercise): ?>
                        <?php $rank = (array) $exercise['rank']; $rankKey = (string) ($rank['key'] ?? 'unranked'); $exerciseRankProgress = $rankProgressData($rank); ?>
                        <a href="/?page=workouts&exercise_id=<?= (int) $exercise['id'] ?>" class="workouts-exercise-rank-row" data-rank="<?= e($rankKey) ?>" data-collapsible-item>
                            <span class="workouts-rank-position"><?= e($workoutExerciseMark((array) $exercise)) ?></span>
                            <span><strong><?= e((string) ($exercise['display_name'] ?? $exercise['name'])) ?></strong><small><?= e($muscleLabel((string) ($exercise['muscle_group'] ?? ''))) ?> · <?= e(t('workouts.rank_points_short', ['points' => number_format((float) $exerciseRankProgress['score'], 1, '.', '')])) ?></small><span class="workouts-rank-mini-progress" aria-hidden="true"><i style="width: <?= (int) $exerciseRankProgress['progress'] ?>%"></i></span><?php if ($exerciseRankProgress['next_key'] !== null): ?><small><?= e(t('workouts.rank_points_remaining', ['points' => number_format((float) $exerciseRankProgress['remaining'], 1, '.', ''), 'rank' => $rankLabel((string) $exerciseRankProgress['next_key'])])) ?></small><?php else: ?><small><?= e(t('workouts.rank_maximum')) ?></small><?php endif; ?></span>
                            <span class="workouts-rank-badge" data-rank="<?= e($rankKey) ?>"><?= e($rankLabel($rankKey)) ?></span>
                        </a>
                    <?php endforeach; ?>
                    <button class="inline-list-toggle" type="button" data-collapsible-toggle data-label-more="<?= e(t('profile.show_more')) ?>" data-label-less="<?= e(t('profile.show_less')) ?>"><?= e(t('profile.show_more')) ?></button>
                </div>
            </article>
        <?php else: ?>
            <article class="panel workouts-rank-section-panel">
                <div class="panel-head"><div><h2><?= e(t('workouts.team_leaderboard')) ?></h2></div><span class="workouts-rank-section-count"><?= count((array) ($wkRankLeaderboard ?? [])) ?></span></div>
                <nav class="workouts-rank-divisions" aria-label="<?= e(t('workouts.rank_division')) ?>">
                    <?php foreach (['open', 'women', 'men'] as $divisionKey): ?>
                        <a href="/?page=workouts&amp;view=ranks&amp;rank_section=team&amp;rank_division=<?= e($divisionKey) ?>"<?= $rankDivision === $divisionKey ? ' aria-current="page"' : '' ?> data-spa-link><?= e(t('workouts.rank_division_' . $divisionKey)) ?></a>
                    <?php endforeach; ?>
                </nav>
                <p class="workouts-rank-division-hint muted small"><?= e(t('workouts.rank_division_hint')) ?></p>
                <div class="workouts-leaderboard-list" data-collapsible-list data-persist-collapsible="workouts.ranks.leaderboard" data-mobile-count="10" data-desktop-count="16">
                    <?php foreach ((array) ($wkRankLeaderboard ?? []) as $rankedUser): ?>
                        <?php $userRank = (array) ($rankedUser['rank'] ?? []); $userRankKey = (string) ($userRank['key'] ?? 'unranked'); ?>
                        <a href="/?page=profile&user_id=<?= (int) $rankedUser['id'] ?>" class="workouts-leaderboard-row<?= (int) $rankedUser['id'] === (int) $currentUser['id'] ? ' is-me' : '' ?>" data-collapsible-item>
                            <strong>#<?= (int) $rankedUser['position'] ?></strong><span class="avatar-small"><?= e(initials_for((string) $rankedUser['display_name'])) ?></span><span><strong><?= e((string) $rankedUser['display_name']) ?></strong><small>@<?= e((string) $rankedUser['username']) ?> · <?= e(t('workouts.rank_points_short', ['points' => number_format((float) ($userRank['score'] ?? 0), 1, '.', '')])) ?></small></span><span class="workouts-rank-badge" data-rank="<?= e($userRankKey) ?>"><?= e($rankLabel($userRankKey)) ?></span>
                        </a>
                    <?php endforeach; ?>
                    <button class="inline-list-toggle" type="button" data-collapsible-toggle data-label-more="<?= e(t('profile.show_more')) ?>" data-label-less="<?= e(t('profile.show_less')) ?>"><?= e(t('profile.show_more')) ?></button>
                </div>
            </article>
        <?php endif; ?>
    </div>

<?php elseif ($wkView === 'exercise' && !empty($wkExercise)): ?>
    <?php
    $exercise = (array) $wkExercise;
    $content = (array) ($exercise['content'] ?? wk_exercise_content($exercise));
    $rank = (array) ($wkExerciseRank ?? wk_rank_from_score(0));
    $rankKey = (string) ($rank['key'] ?? 'unranked');
    $exerciseGuideRankProgress = $rankProgressData($rank);
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
    $exerciseVideoUrl = trim((string) ($exercise['video_url'] ?? ''));
    $exerciseEquipmentTags = json_decode((string) ($exercise['equipment_tags_json'] ?? '[]'), true);
    $exerciseEquipmentTags = is_array($exerciseEquipmentTags) ? array_values(array_unique(array_map('strval', $exerciseEquipmentTags))) : [];
    $exerciseContexts = json_decode((string) ($exercise['contexts_json'] ?? '[]'), true);
    $exerciseContexts = is_array($exerciseContexts) ? array_values(array_unique(array_map('strval', $exerciseContexts))) : [];
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
        <div class="workouts-exercise-hero-copy"><p class="eyebrow"><?= e(t('workouts.guide')) ?></p><h2><?= e((string) ($exercise['display_name'] ?? $exercise['name'])) ?></h2><p><?= e((string) ($content['summary'] ?? '')) ?></p><div class="workouts-exercise-tags"><span><?= e($muscleLabel((string) ($exercise['muscle_group'] ?? ''))) ?></span><?php foreach ($exerciseEquipmentTags !== [] ? $exerciseEquipmentTags : [(string) ($exercise['equipment'] ?? '')] as $equipmentTag): ?><?php if ($equipmentTag !== ''): ?><span><?= e($equipmentLabel($equipmentTag)) ?></span><?php endif; ?><?php endforeach; ?><?php foreach ($exerciseContexts as $exerciseContext): ?><span><?= e($contextLabel($exerciseContext)) ?></span><?php endforeach; ?><span><?= e($difficultyLabel((string) ($exercise['difficulty'] ?? 'beginner'))) ?></span></div></div>
        <div class="workouts-exercise-rank-card" data-rank="<?= e($rankKey) ?>"><span class="workouts-rank-badge" data-rank="<?= e($rankKey) ?>"><?= e($rankLabel($rankKey)) ?></span><strong><?= e(t('workouts.rank_points_short', ['points' => number_format((float) $exerciseGuideRankProgress['score'], 1, '.', '')])) ?></strong><small><?= e($exerciseGuideRankProgress['next_key'] !== null ? t('workouts.rank_points_remaining', ['points' => number_format((float) $exerciseGuideRankProgress['remaining'], 1, '.', ''), 'rank' => $rankLabel((string) $exerciseGuideRankProgress['next_key'])]) : t('workouts.rank_maximum')) ?></small><div class="workouts-rank-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) $exerciseGuideRankProgress['progress'] ?>"><span style="width: <?= (int) $exerciseGuideRankProgress['progress'] ?>%"></span></div><em><?= e(t('workouts.rank_progress_percent', ['percent' => (int) $exerciseGuideRankProgress['progress']])) ?></em></div>
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
            <?php if ($exerciseVideo === null && $exerciseVideoUrl !== ''): ?><a class="btn btn-ghost small workouts-external-video-link" href="<?= e($exerciseVideoUrl) ?>" target="_blank" rel="noopener noreferrer"><?= e(t('workouts.media_search_youtube')) ?></a><?php endif; ?>
            <?php
            $imageAttribution = trim((string) ($exercise['image_attribution'] ?? ''));
            $imageLicense = trim((string) ($exercise['image_license'] ?? ''));
            $videoAttribution = trim((string) ($exercise['video_attribution'] ?? ''));
            ?>
            <?php if ($imageAttribution !== '' || $imageLicense !== '' || $videoAttribution !== ''): ?>
                <p class="workouts-media-attribution"><strong><?= e(t('workouts.media_attribution')) ?>:</strong> <?= e(implode(' · ', array_filter([$imageAttribution, $imageLicense, $videoAttribution]))) ?></p>
            <?php endif; ?>
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
        <?php
        $routineSettingsTabs = [
            'identity' => ['dumbbell', t('workouts.routine_identity'), t('workouts.routine_identity_hint')],
            'media' => ['image', t('workouts.routine_media'), t('workouts.routine_media_hint')],
            'schedule' => ['target', t('workouts.select_days'), t('workouts.routine_schedule_hint')],
            'management' => ['sliders', t('workouts.routine_management'), t('workouts.routine_management_hint')],
        ];
        $activeRoutineSettingsTab = $routineSettingsTabs[$routineSettingsView];
        ?>
        <div class="workouts-routine-settings-shell" style="--routine-accent: <?= e($selectedRoutineAccent) ?>">
            <aside class="panel workouts-routine-settings-aside">
                <div class="workouts-routine-settings-summary">
                    <span class="workouts-routine-summary-icon" aria-hidden="true"><?= activity_icon_svg($selectedRoutineIcon) ?></span>
                    <span><small><?= e(t('workouts.routine_settings')) ?></small><strong><?= e((string) $r['name']) ?></strong></span>
                </div>
                <nav class="workouts-routine-settings-nav" aria-label="<?= e(t('workouts.routine_settings')) ?>">
                    <?php foreach ($routineSettingsTabs as $settingsKey => [$settingsIcon, $settingsLabel, $settingsHint]): ?>
                        <a href="/?page=workouts&routine_id=<?= $rid ?>&section=settings&settings_view=<?= e($settingsKey) ?>"<?= $routineSettingsView === $settingsKey ? ' aria-current="page"' : '' ?>>
                            <span aria-hidden="true"><?= activity_icon_svg($settingsIcon) ?></span>
                            <span><strong><?= e($settingsLabel) ?></strong><small><?= e($settingsHint) ?></small></span>
                            <b aria-hidden="true">&rsaquo;</b>
                        </a>
                    <?php endforeach; ?>
                </nav>
                <a class="btn btn-ghost workouts-routine-settings-done" href="/?page=workouts&routine_id=<?= $rid ?>"><?= e(t('workouts.back_to_routine')) ?></a>
            </aside>

            <section class="workouts-routine-settings-content">
            <?php if ($routineSettingsView !== 'management'): ?>
        <article class="panel workouts-routine-editor" style="--routine-accent: <?= e($selectedRoutineAccent) ?>">
            <div class="panel-head workouts-routine-settings-head"><div><p class="eyebrow"><?= e(t('workouts.routine_settings')) ?></p><h2><?= e($activeRoutineSettingsTab[1]) ?></h2><p class="muted"><?= e($activeRoutineSettingsTab[2]) ?></p></div></div>
            <form method="post" action="/?page=workouts" enctype="multipart/form-data" class="stack compact-form workouts-routine-form" data-workout-media-editor>
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="routine_update">
                <input type="hidden" name="routine_id" value="<?= $rid ?>">
                <input type="hidden" name="settings_view" value="<?= e($routineSettingsView) ?>">
                <?php if ($routineSettingsView === 'identity'): ?>
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
                <?php endif; ?>
                <?php if ($routineSettingsView === 'media'): ?>
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
                <?php endif; ?>
                <?php if ($routineSettingsView === 'schedule'): ?>
                <fieldset class="workouts-day-picker"><legend><?= e(t('workouts.select_days')) ?></legend><div>
                    <?php foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $day): ?><label><input type="checkbox" name="days[]" value="<?= e($day) ?>"<?= in_array($day, $selectedRoutineDays, true) ? ' checked' : '' ?>><span aria-label="<?= e($dayLabel($day)) ?>"><b class="workouts-day-label-full"><?= e($dayLabel($day)) ?></b><b class="workouts-day-label-short" aria-hidden="true"><?= e($dayShortLabel($day)) ?></b></span></label><?php endforeach; ?>
                </div></fieldset>
                <?php endif; ?>
                <div class="inline-actions"><button type="submit" class="btn btn-primary"><?= e(t('common.save')) ?></button><a class="btn btn-ghost" href="/?page=workouts&routine_id=<?= $rid ?>"><?= e(t('common.cancel')) ?></a></div>
            </form>
        </article>

        <?php else: ?>
        <article class="panel workouts-routine-danger">
            <div><strong><?= e(t('workouts.routine_management')) ?></strong><small><?= e(t('workouts.routine_management_hint')) ?></small></div>
            <div class="inline-actions">
                <form method="post" action="/?page=workouts" class="inline-form"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="routine_archive"><input type="hidden" name="routine_id" value="<?= $rid ?>"><input type="hidden" name="value" value="1"><button type="submit" class="btn btn-ghost small"><?= e(t('workouts.archive')) ?></button></form>
                <form method="post" action="/?page=workouts" class="inline-form" onsubmit="return confirm('<?= e(t('common.confirm_delete')) ?>');"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="routine_delete"><input type="hidden" name="routine_id" value="<?= $rid ?>"><button type="submit" class="btn btn-ghost small btn-danger-ghost"><?= e(t('workouts.delete_routine')) ?></button></form>
            </div>
        </article>
        <?php endif; ?>
            </section>
        </div>
    <?php elseif ($routineSection === 'organize'): ?>
        <?php $organizerExercises = array_values((array) ($wkRoutineExercises ?? [])); ?>
        <article class="panel workouts-routine-organizer" style="--routine-accent: <?= e($selectedRoutineAccent) ?>">
            <div class="panel-head workouts-routine-organizer-head">
                <div><p class="eyebrow"><?= e(t('workouts.organize_routine')) ?></p><h2><?= e((string) $r['name']) ?></h2><p class="muted"><?= e(t('workouts.organize_routine_hint')) ?></p></div>
            </div>
            <?php if ($organizerExercises === []): ?>
                <div class="workouts-routine-empty"><span aria-hidden="true">+</span><div><strong><?= e(t('workouts.no_exercises')) ?></strong><small><?= e(t('workouts.add_exercises_hint')) ?></small></div></div>
                <div class="workouts-routine-organizer-actions"><a class="hierarchy-back destination-back" href="/?page=workouts&routine_id=<?= $rid ?>" aria-label="<?= e(t('common.back')) ?>: <?= e((string) $r['name']) ?>"><span aria-hidden="true">&larr;</span><strong><?= e((string) $r['name']) ?></strong></a><a class="btn btn-primary" href="/?page=workouts&view=library&target_routine_id=<?= $rid ?>"><?= e(t('workouts.add_exercises')) ?></a></div>
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
    $sessionExerciseMediaMap = (array) ($wkSessionExerciseMedia ?? []);
    $sessionExerciseCoverAsset = static function (array $exercise) use ($sessionExerciseMediaMap, $workoutCoverAsset, $workoutVideoSource): ?array {
        $exerciseId = (int) ($exercise['exercise_def_id'] ?? $exercise['id'] ?? 0);
        $imagePath = trim((string) ($exercise['image_path'] ?? ''));
        $imagePosition = $exercise['image_position'] ?? 'center';
        if ($imagePath === '') {
            $gallery = (array) ($sessionExerciseMediaMap[$exerciseId] ?? []);
            $firstPhoto = (array) ($gallery[0] ?? []);
            $imagePath = trim((string) ($firstPhoto['path'] ?? ''));
            $imagePosition = $firstPhoto['position'] ?? $imagePosition;
        }
        if ($imagePath !== '') {
            return [
                'kind' => 'photo',
                'url' => media_url($imagePath),
                'has_video' => $workoutVideoSource((string) ($exercise['video_url'] ?? '')) !== null,
                'position' => wk_image_position_css($imagePosition),
            ];
        }

        return $workoutCoverAsset($exercise);
    };
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
    $sessionTotalSetCount = 0;
    $sessionCompletedSetCount = 0;
    foreach ($sessionExercises as $sessionExerciseIndex => $sessionExercise) {
        $sessionExerciseSets = (array) ($sessionExercise['sets'] ?? []);
        $sessionTotalSetCount += count($sessionExerciseSets);
        $sessionCompletedSetCount += count(array_filter(
            $sessionExerciseSets,
            static fn(array $set): bool => (int) ($set['completed'] ?? 0) === 1
        ));
        if ((int) ($sessionExercise['id'] ?? 0) === $activeSessionExerciseId) {
            $activeSessionExerciseIndex = (int) $sessionExerciseIndex;
        }
        if ($sessionExerciseIsComplete((array) $sessionExercise)) {
            $completedSessionExerciseCount++;
        }
    }
    $activeSessionExercise = (array) ($sessionExercises[$activeSessionExerciseIndex] ?? []);
    $sessionSetProgress = $sessionTotalSetCount > 0
        ? min(100, (int) round(($sessionCompletedSetCount / $sessionTotalSetCount) * 100))
        : 0;
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
                <div class="workouts-routine-organizer-actions"><a class="hierarchy-back destination-back" href="/?page=workouts&session_id=<?= $sid ?>" aria-label="<?= e(t('common.back')) ?>: <?= e((string) (($s['title'] ?? '') !== '' ? $s['title'] : t('workouts.session'))) ?>"><span aria-hidden="true">&larr;</span><strong><?= e((string) (($s['title'] ?? '') !== '' ? $s['title'] : t('workouts.session'))) ?></strong></a><a class="btn btn-primary" href="/?page=workouts&view=library&target_session_id=<?= $sid ?>"><?= e(t('workouts.add_exercise')) ?></a></div>
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
    <article class="workouts-session-panel<?= $sessionRoutineCover !== null ? ' has-routine-cover' : '' ?>" style="--routine-accent: <?= e($sessionRoutineAccent) ?>">
        <div class="panel-head workouts-session-panel-head">
            <div class="workouts-session-title"><?php if ($sessionRoutineCover !== null): ?><span class="workouts-session-routine-cover" aria-hidden="true"><img src="<?= e((string) $sessionRoutineCover['url']) ?>" alt="" style="object-position: <?= e($workoutCoverPosition($sessionRoutineCover)) ?>"><?php if (!empty($sessionRoutineCover['has_video'])): ?><b>&#9654;</b><?php endif; ?></span><?php endif; ?><div><p class="eyebrow"><?= e(t('workouts.active_session')) ?></p><h2><?= e((string) ($s['title'] ?? '') !== '' ? (string) $s['title'] : t('workouts.session')) ?></h2></div></div>
            <?php if (!$sessionDone): ?>
                <div class="workouts-session-head-actions">
                    <a class="btn btn-primary small workouts-session-add-compact" href="/?page=workouts&view=library&target_session_id=<?= $sid ?>" aria-label="<?= e(t('workouts.add_exercise')) ?>"><span aria-hidden="true"><?= activity_icon_svg('plus') ?></span><span class="workouts-session-add-label"><?= e(t('workouts.add_exercise')) ?></span></a>
                    <details class="kebab-menu workouts-session-more" data-kebab-menu data-align="end">
                        <summary class="kebab-menu-trigger btn btn-ghost" aria-label="<?= e(t('common.actions')) ?>"><span class="kebab-menu-dots" aria-hidden="true"><span></span><span></span><span></span></span></summary>
                        <div class="kebab-menu-panel" role="menu">
                            <?php if ($sessionExercises !== []): ?><a class="kebab-menu-item" href="/?page=workouts&session_id=<?= $sid ?>&section=organize"><?= e(t('workouts.edit_session')) ?></a><?php endif; ?>
                            <form method="post" action="/?page=workouts" data-workout-session-end data-session-id="<?= $sid ?>" onsubmit="return confirm('<?= e(t('workouts.cancel_session')) ?>?');">
                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                <input type="hidden" name="action" value="session_cancel">
                                <input type="hidden" name="session_id" value="<?= $sid ?>">
                                <button type="submit" class="kebab-menu-item is-danger"><?= e(t('workouts.cancel_session')) ?></button>
                            </form>
                        </div>
                    </details>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($sessionExercises !== []): ?>
            <div class="workouts-session-status" aria-label="<?= e(t('workouts.session_progress', ['completed' => $sessionCompletedSetCount, 'total' => $sessionTotalSetCount])) ?>">
                <span class="workouts-session-status-copy"><i aria-hidden="true"></i><strong><?= $sessionCompletedSetCount ?> / <?= $sessionTotalSetCount ?> <?= e(t('workouts.sets')) ?></strong></span>
                <span class="goal-progress"><span style="width: <?= $sessionSetProgress ?>%"></span></span>
                <strong><?= $sessionSetProgress ?>%</strong>
            </div>
        <?php endif; ?>

        <?php if ($sessionExercises === []): ?>
            <p class="muted"><?= e(t('workouts.no_exercises')) ?></p>
        <?php else: ?>
            <?php if (count($sessionExercises) > 1): ?>
                <?php $activeSessionCover = $sessionExerciseCoverAsset($activeSessionExercise); ?>
                <nav class="workouts-session-exercise-nav" style="<?= e($workoutExerciseStyle($activeSessionExercise)) ?>" aria-label="<?= e(t('workouts.session_exercise_navigation')) ?>">
                    <div class="workouts-session-exercise-nav-main">
                        <?php if ($previousSessionExercise !== null): ?><a class="workouts-session-nav-arrow" href="<?= e($sessionExerciseUrl((int) $previousSessionExercise['id'])) ?>" aria-label="<?= e(t('common.previous')) ?>"><?= activity_icon_svg('chevron-left') ?></a><?php else: ?><span class="workouts-session-nav-arrow is-disabled" aria-hidden="true"><?= activity_icon_svg('chevron-left') ?></span><?php endif; ?>
                        <div class="workouts-session-exercise-nav-current">
                            <span class="workouts-exercise-cover<?= $activeSessionCover !== null ? ' has-media' : ' is-fallback' ?>" aria-hidden="true"><?php if ($activeSessionCover !== null): ?><img src="<?= e((string) $activeSessionCover['url']) ?>" alt="" style="object-position: <?= e($workoutCoverPosition($activeSessionCover)) ?>"><?php else: ?><?= activity_icon_svg('dumbbell') ?><?php endif; ?><?php if (!empty($activeSessionCover['has_video'])): ?><b>&#9654;</b><?php endif; ?></span>
                            <div><small><?= e(t('workouts.exercise_of', ['current' => $activeSessionExerciseIndex + 1, 'total' => count($sessionExercises)])) ?></small><strong><?= e((string) ($activeSessionExercise['exercise_name'] ?? '')) ?></strong><span><?= e(t('workouts.session_progress', ['completed' => $completedSessionExerciseCount, 'total' => count($sessionExercises)])) ?></span></div>
                            <em><?= $activeSessionExerciseIndex + 1 ?>/<?= count($sessionExercises) ?></em>
                        </div>
                        <?php if ($nextSessionExercise !== null): ?><a class="workouts-session-nav-arrow" href="<?= e($sessionExerciseUrl((int) $nextSessionExercise['id'])) ?>" aria-label="<?= e(t('common.next')) ?>"><?= activity_icon_svg('chevron-right') ?></a><?php else: ?><span class="workouts-session-nav-arrow is-disabled" aria-hidden="true"><?= activity_icon_svg('chevron-right') ?></span><?php endif; ?>
                    </div>
                    <div class="workouts-session-exercise-rail" aria-label="<?= e(t('workouts.session_progress', ['completed' => $completedSessionExerciseCount, 'total' => count($sessionExercises)])) ?>">
                        <?php foreach ($sessionExercises as $sessionExerciseIndex => $sessionExercise): ?>
                            <?php
                            $sessionRailId = (int) ($sessionExercise['id'] ?? 0);
                            $sessionRailComplete = $sessionExerciseIsComplete((array) $sessionExercise);
                            ?>
                            <a class="<?= $sessionRailId === $activeSessionExerciseId ? 'is-active ' : '' ?><?= $sessionRailComplete ? 'is-complete' : '' ?>" href="<?= e($sessionExerciseUrl($sessionRailId)) ?>" aria-label="<?= e((string) ($sessionExercise['exercise_name'] ?? '')) ?>"<?= $sessionRailId === $activeSessionExerciseId ? ' aria-current="step"' : '' ?>><span><?= $sessionRailComplete ? '&#10003;' : $sessionExerciseIndex + 1 ?></span><small><?= e((string) ($sessionExercise['exercise_name'] ?? '')) ?></small></a>
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
                    $sessionExerciseGallery = (array) (($wkSessionExerciseMedia ?? [])[(int) ($ex['exercise_def_id'] ?? 0)] ?? []);
                    $sessionExerciseCover = $sessionExerciseCoverAsset((array) $ex);
                    $sessionExerciseGalleryImages = array_map(static fn(array $item): array => [
                        'url' => media_url((string) ($item['path'] ?? '')),
                        'position' => $item['position'] ?? 'center',
                        'caption' => $item['caption'] ?? '',
                    ], $sessionExerciseGallery);
                    $sessionExerciseImagePath = trim((string) ($ex['image_path'] ?? ''));
                    if ($sessionExerciseImagePath === '') {
                        $sessionExerciseImagePath = trim((string) ($sessionExerciseGallery[0]['path'] ?? ''));
                    }
                    $sessionExerciseImageUrl = $sessionExerciseImagePath !== '' ? media_url($sessionExerciseImagePath) : '';
                    $sessionExerciseVideo = $workoutVideoSource((string) ($ex['video_url'] ?? ''));
                    $sessionExerciseHasPhoto = $sessionExerciseImageUrl !== '';
                    $sessionExerciseHasVideo = $sessionExerciseVideo !== null;
                    $sessionExerciseMediaLabel = $sessionExerciseHasPhoto && $sessionExerciseHasVideo
                        ? t('workouts.media_photo_and_video')
                        : t($sessionExerciseHasPhoto ? 'workouts.cover_photo' : 'workouts.cover_video');
                    $sessionExerciseCoverMode = wk_normalize_exercise_cover_mode($ex['cover_mode'] ?? 'auto');
                    $sessionPreviousExerciseSets = (array) (($wkSessionPreviousSets ?? [])[(int) ($ex['exercise_def_id'] ?? 0)] ?? []);
                    ?>
                    <article class="workouts-session-exercise<?= (int) ($ex['id'] ?? 0) === $activeSessionExerciseId ? ' is-active' : '' ?>" style="<?= e($workoutExerciseStyle((array) $ex)) ?>" data-exercise-type="<?= e($sessionExerciseType) ?>" data-session-exercise-id="<?= (int) ($ex['id'] ?? 0) ?>">
                        <div class="workouts-session-exercise-head">
                            <span class="workouts-exercise-cover<?= $sessionExerciseCover !== null ? ' has-media' : ' is-fallback' ?>" aria-hidden="true"><?php if ($sessionExerciseCover !== null): ?><img src="<?= e((string) $sessionExerciseCover['url']) ?>" alt="" style="object-position: <?= e($workoutCoverPosition($sessionExerciseCover)) ?>"><?php else: ?><?= activity_icon_svg('dumbbell') ?><?php endif; ?><?php if (!empty($sessionExerciseCover['has_video'])): ?><b>&#9654;</b><?php endif; ?></span>
                            <strong><a href="/?page=workouts&exercise_id=<?= (int) $ex['exercise_def_id'] ?>&target_session_id=<?= $sid ?>&session_exercise_id=<?= (int) ($ex['id'] ?? 0) ?>"><?= e((string) $ex['exercise_name']) ?></a></strong>
                            <span class="muted small"><?= e($exerciseTypeLabel($sessionExerciseType)) ?></span>
                        </div>
                        <?php if ($sessionPreviousExerciseSets !== [] && !$sessionDone): ?><p class="workouts-session-previous-hint"><span aria-hidden="true">↺</span><?= e(t('workouts.last_performance_hint')) ?></p><?php endif; ?>
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
                        <?php
                        $sessionSetRows = array_values((array) ($ex['sets'] ?? []));
                        $sessionCanDeleteSet = !$sessionDone && count($sessionSetRows) > 1;
                        ?>
                        <div class="workouts-set-legend<?= $sessionCanDeleteSet ? ' can-delete' : '' ?>" aria-hidden="true">
                            <span>#</span>
                            <span><?= e($sessionIsCardio ? t('workouts.minutes_label') : ($sessionIsIsometric ? t('workouts.seconds_label') : t('workouts.weight'))) ?><?= !$sessionIsCardio && !$sessionIsIsometric ? ' (' . e(strtoupper((string) ($ex['unit'] ?? 'kg'))) . ')' : '' ?></span>
                            <span></span>
                            <span><?= e($sessionIsCardio ? t('workouts.km_label') : ($sessionIsIsometric ? t('workouts.weight') : t('workouts.reps'))) ?><?= $sessionIsIsometric ? ' (' . e(strtoupper((string) ($ex['unit'] ?? 'kg'))) . ')' : '' ?></span>
                            <span>&#10003;</span>
                            <?php if ($sessionCanDeleteSet): ?><span></span><?php endif; ?>
                        </div>
                        <div class="workouts-set-rows">
                            <?php foreach ($sessionSetRows as $set): ?>
                                <?php
                                $previousSet = (array) ($sessionPreviousExerciseSets[(int) ($set['set_index'] ?? 1)] ?? []);
                                $usePreviousSet = !$sessionDone
                                    && (int) ($set['completed'] ?? 0) !== 1
                                    && $previousSet !== [];
                                $sessionSetValue = static function (string $field) use ($set, $previousSet, $usePreviousSet) {
                                    return $usePreviousSet && array_key_exists($field, $previousSet) && $previousSet[$field] !== null
                                        ? $previousSet[$field]
                                        : ($set[$field] ?? null);
                                };
                                $sessionSetSuggested = static function (string $field) use ($previousSet, $usePreviousSet): bool {
                                    return $usePreviousSet && array_key_exists($field, $previousSet) && $previousSet[$field] !== null;
                                };
                                ?>
                                <form method="post" action="/?page=workouts" class="workouts-set-row<?= (int) ($set['completed'] ?? 0) === 1 ? ' is-done' : '' ?><?= $sessionCanDeleteSet ? ' can-delete' : '' ?>" data-workout-set-form data-session-id="<?= $sid ?>" data-rest-seconds="<?= max(0, (int) ($ex['rest_seconds'] ?? 0)) ?>">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                    <input type="hidden" name="action" value="session_update_set">
                                    <input type="hidden" name="session_id" value="<?= $sid ?>">
                                    <input type="hidden" name="return_session_exercise_id" value="<?= (int) ($ex['id'] ?? 0) ?>">
                                    <input type="hidden" name="set_id" value="<?= (int) $set['id'] ?>">
                                    <span class="workouts-set-index"><?= (int) $set['set_index'] ?></span>
                                    <?php if ($sessionIsCardio): ?>
                                        <?php $durationValue = $sessionSetValue('duration'); ?>
                                        <label class="workouts-set-field"><span class="sr-only"><?= e(t('workouts.duration_minutes')) ?></span><input type="number" name="duration_minutes" step="0.5" min="0" value="<?= $durationValue !== null ? e(rtrim(rtrim(number_format((int) $durationValue / 60, 1, '.', ''), '0'), '.')) : '' ?>" placeholder="<?= e(t('workouts.minutes_placeholder')) ?>" inputmode="decimal"<?= $sessionSetSuggested('duration') ? ' data-clear-workout-suggestion="1"' : '' ?>></label>
                                        <span class="workouts-set-x">·</span>
                                        <?php $distanceValue = $sessionSetValue('distance'); ?>
                                        <label class="workouts-set-field"><span class="sr-only"><?= e(t('workouts.distance_km')) ?></span><input type="number" name="distance" step="0.01" min="0" value="<?= $distanceValue !== null ? e(rtrim(rtrim(number_format((float) $distanceValue, 2, '.', ''), '0'), '.')) : '' ?>" placeholder="<?= e(t('workouts.km_placeholder')) ?>" inputmode="decimal"<?= $sessionSetSuggested('distance') ? ' data-clear-workout-suggestion="1"' : '' ?>></label>
                                    <?php elseif ($sessionIsIsometric): ?>
                                        <?php $durationValue = $sessionSetValue('duration'); ?>
                                        <label class="workouts-set-field"><span class="sr-only"><?= e(t('workouts.duration_seconds')) ?></span><input type="number" name="duration_seconds" min="0" value="<?= $durationValue !== null ? (int) $durationValue : '' ?>" placeholder="<?= e(t('workouts.seconds_placeholder')) ?>" inputmode="numeric"<?= $sessionSetSuggested('duration') ? ' data-clear-workout-suggestion="1"' : '' ?>></label>
                                        <span class="workouts-set-x">·</span>
                                        <?php $weightValue = $sessionSetValue('weight'); ?>
                                        <label class="workouts-set-field"><span class="sr-only"><?= e(t('workouts.weight')) ?></span><input type="number" name="weight" step="0.5" min="0" value="<?= $weightValue !== null ? e(rtrim(rtrim(number_format((float) $weightValue, 1, '.', ''), '0'), '.')) : '' ?>" placeholder="<?= e(t('workouts.weight')) ?>" inputmode="decimal"<?= $sessionSetSuggested('weight') ? ' data-clear-workout-suggestion="1"' : '' ?>></label>
                                    <?php else: ?>
                                        <?php $weightValue = $sessionSetValue('weight'); ?>
                                        <label class="workouts-set-field"><span class="sr-only"><?= e(t('workouts.weight')) ?></span><input type="number" name="weight" step="0.5" min="0" value="<?= $weightValue !== null ? e(rtrim(rtrim(number_format((float) $weightValue, 1, '.', ''), '0'), '.')) : '' ?>" placeholder="<?= e(t('workouts.weight')) ?>" inputmode="decimal"<?= $sessionSetSuggested('weight') ? ' data-clear-workout-suggestion="1"' : '' ?>></label>
                                        <span class="workouts-set-x">×</span>
                                        <?php $repsValue = $sessionSetValue('reps'); ?>
                                        <label class="workouts-set-field"><span class="sr-only"><?= e(t('workouts.reps')) ?></span><input type="number" name="reps" min="0" max="999" value="<?= $repsValue !== null ? (int) $repsValue : '' ?>" placeholder="<?= e(t('workouts.reps')) ?>" inputmode="numeric"<?= $sessionSetSuggested('reps') ? ' data-clear-workout-suggestion="1"' : '' ?>></label>
                                    <?php endif; ?>
                                    <button type="submit" name="completed" value="<?= (int) ($set['completed'] ?? 0) === 1 ? '0' : '1' ?>" class="btn workouts-set-done<?= (int) ($set['completed'] ?? 0) === 1 ? ' btn-primary' : ' btn-ghost' ?> small" aria-label="<?= e(t('workouts.done')) ?>" data-workout-set-toggle data-next-completed="<?= (int) ($set['completed'] ?? 0) === 1 ? '0' : '1' ?>">✓</button>
                                    <?php if ($sessionCanDeleteSet): ?><button type="submit" name="action" value="session_delete_set" class="btn btn-ghost workouts-set-delete" aria-label="<?= e(t('workouts.delete_set')) ?> <?= (int) $set['set_index'] ?>" title="<?= e(t('workouts.delete_set')) ?>" formnovalidate onclick="return confirm('<?= e(t('workouts.delete_set_confirm')) ?>');"><?= activity_icon_svg('trash') ?></button><?php endif; ?>
                                </form>
                            <?php endforeach; ?>
                        </div>
                        <?php if (!$sessionDone): ?>
                            <form method="post" action="/?page=workouts" class="inline-form" data-workout-session-action data-session-id="<?= $sid ?>">
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
            <details class="workouts-session-options">
                <summary>
                    <span aria-hidden="true"><?= activity_icon_svg('sliders') ?></span>
                    <strong><?= e(t('workouts.more_options')) ?></strong>
                    <small><?= e(t('workouts.count_challenge')) ?></small>
                    <b aria-hidden="true">+</b>
                </summary>
                <label class="check standalone-check workouts-count-check">
                    <input type="checkbox" name="count_challenge" value="1" checked form="workouts-session-finish-<?= $sid ?>">
                    <span><strong><?= e(t('workouts.count_challenge')) ?></strong><small><?= e(t('workouts.count_challenge_hint')) ?></small></span>
                </label>
            </details>
            <div class="workouts-session-footer">
                <form id="workouts-session-finish-<?= $sid ?>" method="post" action="/?page=workouts" class="stack workouts-finish-form" data-workout-session-end data-workout-session-action data-session-id="<?= $sid ?>">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="session_finish">
                    <input type="hidden" name="session_id" value="<?= $sid ?>">
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
    $summaryMonthStats = (array) ($wkSummaryMonth ?? []);
    $summaryAllStats = (array) ($wkSummaryAll ?? []);
    $maxVolume = 0.0;
    $maxSessions = 0;
    foreach ($weekly as $w) {
        $maxVolume = max($maxVolume, (float) $w['volume']);
        $maxSessions = max($maxSessions, (int) $w['sessions']);
    }
    $currentWeek = (array) ($weekly[count($weekly) - 1] ?? ['sessions' => 0, 'volume' => 0]);
    $previousWeek = (array) ($weekly[count($weekly) - 2] ?? ['sessions' => 0, 'volume' => 0]);
    $periodSessions = array_sum(array_map(static fn(array $week): int => (int) ($week['sessions'] ?? 0), $weekly));
    $periodVolume = array_sum(array_map(static fn(array $week): float => (float) ($week['volume'] ?? 0), $weekly));
    $compactStatNumber = static function (float $value, string $suffix = ''): string {
        if ($value >= 1000000) {
            return rtrim(rtrim(number_format($value / 1000000, 1, '.', ''), '0'), '.') . 'M' . $suffix;
        }
        if ($value >= 1000) {
            return rtrim(rtrim(number_format($value / 1000, 1, '.', ''), '0'), '.') . 'k' . $suffix;
        }

        return number_format($value, $value === floor($value) ? 0 : 1, '.', '') . $suffix;
    };
    $muscles = (array) ($stats['muscles'] ?? []);
    $muscleTotal = array_sum(array_map(static fn($m) => (int) $m['sets'], $muscles));
    ?>

    <section class="workouts-analytics-page">
        <div class="workouts-analytics-kpis" aria-label="<?= e(t('workouts.training_snapshot')) ?>">
            <article class="workouts-analytics-kpi is-streak" data-tone="orange">
                <span class="workouts-analytics-kpi-icon" aria-hidden="true"><?= activity_icon_svg('flame') ?></span>
                <span><small><?= e(t('workouts.streak')) ?></small><strong><?= (int) ($stats['streak'] ?? 0) ?></strong></span>
            </article>
            <article class="workouts-analytics-kpi" data-tone="blue">
                <span class="workouts-analytics-kpi-icon" aria-hidden="true"><?= activity_icon_svg('dumbbell') ?></span>
                <span><small><?= e(t('workouts.stat_sessions')) ?> · <?= e(t('workouts.this_month')) ?></small><strong><?= (int) ($summaryMonthStats['sessions'] ?? 0) ?></strong></span>
            </article>
            <article class="workouts-analytics-kpi" data-tone="violet">
                <span class="workouts-analytics-kpi-icon" aria-hidden="true"><?= activity_icon_svg('run') ?></span>
                <span><small><?= e(t('workouts.stat_volume')) ?> · <?= e(t('workouts.this_month')) ?></small><strong><?= e($compactStatNumber((float) ($summaryMonthStats['volume'] ?? 0), ' kg')) ?></strong></span>
            </article>
            <article class="workouts-analytics-kpi" data-tone="green">
                <span class="workouts-analytics-kpi-icon" aria-hidden="true"><?= activity_icon_svg('trophy') ?></span>
                <span><small><?= e(t('workouts.stat_sessions')) ?> · <?= e(t('workouts.all_time')) ?></small><strong><?= (int) ($summaryAllStats['sessions'] ?? 0) ?></strong></span>
            </article>
        </div>

        <?php if (($stats['messages'] ?? []) !== []): ?>
            <section class="workouts-analytics-insights" aria-label="<?= e(t('workouts.insights')) ?>">
                <div class="workouts-analytics-insights-head"><span aria-hidden="true"><?= activity_icon_svg('spark') ?></span><strong><?= e(t('workouts.insights')) ?></strong></div>
                <div class="workouts-messages">
                    <?php foreach ((array) $stats['messages'] as $msg): ?>
                        <div class="workouts-message">
                            <span class="workouts-message-icon"><?= activity_icon_svg((string) $msg['icon']) ?></span>
                            <span><?= e((string) $msg['text']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

    <article class="panel workouts-analytics-chart-panel">
        <div class="panel-head workouts-analytics-panel-head">
            <div><p class="eyebrow"><?= e(t('workouts.last_8_weeks')) ?></p><h2><?= e(t('workouts.weekly_volume')) ?></h2></div>
            <div class="workouts-analytics-week-now"><span><small><?= e(t('workouts.current_week')) ?></small><strong><?= (int) ($currentWeek['sessions'] ?? 0) ?> <?= e(t('workouts.stat_sessions')) ?></strong></span><span><small><?= e(t('workouts.stat_volume')) ?></small><strong><?= e($compactStatNumber((float) ($currentWeek['volume'] ?? 0), ' kg')) ?></strong></span></div>
        </div>
        <?php if ($maxVolume <= 0 && $maxSessions <= 0): ?>
            <div class="workouts-analytics-empty"><span aria-hidden="true"><?= activity_icon_svg('run') ?></span><p><?= e(t('workouts.no_data')) ?></p></div>
        <?php else: ?>
            <div class="workouts-bar-chart" role="img" aria-label="<?= e(t('workouts.weekly_volume')) ?>">
                <?php foreach ($weekly as $w): ?>
                    <?php
                    $volumeRatio = $maxVolume > 0 ? (float) ($w['volume'] ?? 0) / $maxVolume : 0.0;
                    $sessionRatio = $maxSessions > 0 ? (int) ($w['sessions'] ?? 0) / $maxSessions : 0.0;
                    $h = max(3, (int) round(max($volumeRatio, $sessionRatio) * 100));
                    ?>
                    <div class="workouts-bar-col<?= (string) ($w['week'] ?? '') === (string) ($currentWeek['week'] ?? '') ? ' is-current' : '' ?>">
                        <div class="workouts-bar" style="height: <?= $h ?>%" title="<?= e(number_format((float) $w['volume'], 0, '.', ' ')) ?> kg · <?= (int) $w['sessions'] ?>"></div>
                        <span class="workouts-bar-label"><?= e((string) $w['label']) ?></span>
                        <span class="workouts-bar-sub"><?= (int) $w['sessions'] ?><small><?= e(t('workouts.sessions_short')) ?></small></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="workouts-analytics-period-total"><span><small><?= e(t('workouts.last_8_weeks')) ?></small><strong><?= $periodSessions ?> <?= e(t('workouts.stat_sessions')) ?></strong></span><span><small><?= e(t('workouts.stat_volume')) ?></small><strong><?= e($compactStatNumber($periodVolume, ' kg')) ?></strong></span><span><small><?= e(t('workouts.previous_week')) ?></small><strong><?= (int) ($previousWeek['sessions'] ?? 0) ?> <?= e(t('workouts.stat_sessions')) ?></strong></span></div>
        <?php endif; ?>
    </article>

    <div class="grid-two workouts-analytics-detail-grid">
        <article class="panel workouts-analytics-detail-card">
            <div class="panel-head workouts-analytics-panel-head"><div><p class="eyebrow"><?= count((array) ($stats['frequent'] ?? [])) ?></p><h2><?= e(t('workouts.frequent_exercises')) ?></h2></div></div>
            <?php if (($stats['frequent'] ?? []) === []): ?>
                <div class="workouts-analytics-empty is-small"><span aria-hidden="true"><?= activity_icon_svg('dumbbell') ?></span><p><?= e(t('workouts.no_data')) ?></p></div>
            <?php else: ?>
                <ol class="workouts-analytics-frequency-list">
                    <?php foreach ((array) $stats['frequent'] as $frequencyIndex => $fx): ?>
                        <li><span><?= $frequencyIndex + 1 ?></span><strong><?= e((string) $fx['name']) ?></strong><b><?= (int) $fx['count'] ?>×</b></li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </article>
        <article class="panel workouts-analytics-detail-card">
            <div class="panel-head workouts-analytics-panel-head"><div><p class="eyebrow"><?= $muscleTotal ?> <?= e(t('workouts.stat_sets')) ?></p><h2><?= e(t('workouts.muscle_focus')) ?></h2></div></div>
            <?php if ($muscles === [] || $muscleTotal <= 0): ?>
                <div class="workouts-analytics-empty is-small"><span aria-hidden="true"><?= activity_icon_svg('target') ?></span><p><?= e(t('workouts.no_data')) ?></p></div>
            <?php else: ?>
                <div class="workouts-muscle-list">
                    <?php foreach ($muscles as $m): ?>
                        <?php $pct = (int) round(((int) $m['sets'] / $muscleTotal) * 100); ?>
                        <div class="workouts-muscle-row">
                            <span class="workouts-muscle-name"><?= e($muscleLabel((string) $m['muscle'])) ?></span>
                            <div class="workouts-muscle-bar"><span style="width: <?= $pct ?>%"></span></div>
                            <span class="workouts-muscle-pct"><?= $pct ?>%</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </article>
    </div>
    </section>
<?php endif; ?>

<?php if ($wkView === 'library' && !$hasLibraryTarget && $activeRoutines !== []): ?>
<div class="app-modal workouts-add-routine-modal" id="wk-add-exercise-modal" hidden role="dialog" aria-modal="true" aria-labelledby="wk-add-exercise-title" data-workout-routine-picker data-single-routine="<?= count($activeRoutines) === 1 ? '1' : '0' ?>">
    <div class="app-modal-card workouts-add-routine-card">
        <div class="app-modal-head">
            <div><p class="eyebrow"><?= e(t('workouts.exercises')) ?></p><h2 id="wk-add-exercise-title"><?= e(t('workouts.add_to_routine')) ?></h2><p class="muted" data-workout-routine-picker-copy><?= e(t('workouts.choose_routine')) ?></p></div>
            <button type="button" class="app-modal-close" data-app-modal-close aria-label="<?= e(t('common.close_action')) ?>">&times;</button>
        </div>
        <form method="post" action="/?page=workouts" class="workouts-add-routine-form" data-workout-routine-picker-form>
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="library_add_exercise">
            <input type="hidden" name="exercise_def_id" value="" data-workout-routine-picker-exercise>
            <input type="hidden" name="muscle" value="<?= e((string) ($filters['muscle'] ?? '')) ?>"><input type="hidden" name="equipment" value="<?= e((string) ($filters['equipment'] ?? '')) ?>"><input type="hidden" name="context" value="<?= e((string) ($filters['context'] ?? '')) ?>"><input type="hidden" name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>"><input type="hidden" name="scope" value="<?= e((string) ($filters['scope'] ?? '')) ?>"><input type="hidden" name="library_page" value="<?= (int) ($wkLibraryPage ?? 1) ?>">
            <fieldset class="workouts-routine-choice-list">
                <legend class="sr-only"><?= e(t('workouts.choose_routine')) ?></legend>
                <?php foreach ($activeRoutines as $routine): ?>
                    <?php $routineAccent = wk_normalize_routine_color($routine['accent_color'] ?? '#14b8a6'); ?>
                    <label style="--routine-accent: <?= e($routineAccent) ?>">
                        <input type="radio" name="routine_id" value="<?= (int) $routine['id'] ?>" required<?= count($activeRoutines) === 1 ? ' checked' : '' ?>>
                        <span class="workouts-routine-choice-icon" aria-hidden="true"><?= activity_icon_svg(wk_normalize_routine_icon($routine['icon'] ?? 'dumbbell')) ?></span>
                        <span class="workouts-routine-choice-copy"><strong><?= e((string) $routine['name']) ?></strong><small><?= (int) ($routine['exercise_count'] ?? 0) ?> <?= e(t('workouts.exercises')) ?></small></span>
                        <span class="workouts-routine-choice-check" aria-hidden="true"><?= activity_icon_svg('check') ?></span>
                    </label>
                <?php endforeach; ?>
            </fieldset>
            <div class="workouts-add-routine-actions"><button class="btn btn-ghost" type="button" data-app-modal-close><?= e(t('common.cancel')) ?></button><button class="btn btn-primary" type="submit" data-workout-routine-picker-submit<?= count($activeRoutines) > 1 ? ' disabled' : '' ?>><?= e(t('workouts.add_to_routine')) ?></button></div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($wkView === 'library' && $addedRoutine !== null): ?>
<div class="app-modal workouts-added-success-modal" id="wk-added-success-modal" hidden role="dialog" aria-modal="true" aria-labelledby="wk-added-success-title" data-workout-add-success>
    <div class="app-modal-card workouts-added-success-card">
        <span class="workouts-added-success-icon" aria-hidden="true"><?= activity_icon_svg('check') ?></span>
        <div><p class="eyebrow"><?= e(t('workouts.exercise_added_title')) ?></p><h2 id="wk-added-success-title"><?= e(t('workouts.exercise_added')) ?></h2><p><?= e(t('workouts.exercise_added_to_named', ['routine' => (string) ($addedRoutine['name'] ?? '')])) ?></p></div>
        <div class="workouts-added-success-actions"><a class="btn btn-primary" href="/?page=workouts&amp;routine_id=<?= (int) $addedRoutine['id'] ?>" tabindex="0"><?= e(t('workouts.view_routine')) ?></a><a class="btn btn-ghost" href="<?= e($libraryUrl([])) ?>"><?= e(t('common.close_action')) ?></a></div>
    </div>
</div>
<?php endif; ?>

<div class="app-modal" id="wk-new-routine-modal" hidden role="dialog" aria-modal="true" aria-labelledby="wk-new-routine-title">
    <div class="app-modal-card workouts-routine-modal-card">
        <div class="app-modal-head">
            <div><p class="eyebrow"><?= e(t('workouts.tab_plan')) ?></p><h2 id="wk-new-routine-title"><?= e(t('workouts.new_routine')) ?></h2></div>
            <button type="button" class="app-modal-close" data-app-modal-close aria-label="<?= e(t('common.close_action')) ?>">&times;</button>
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
                <?php foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $day): ?><label><input type="checkbox" name="days[]" value="<?= e($day) ?>"><span aria-label="<?= e($dayLabel($day)) ?>"><b class="workouts-day-label-full"><?= e($dayLabel($day)) ?></b><b class="workouts-day-label-short" aria-hidden="true"><?= e($dayShortLabel($day)) ?></b></span></label><?php endforeach; ?>
            </div></fieldset>
            <button type="submit" class="btn btn-primary btn-block"><?= e(t('common.create')) ?></button>
        </form>
    </div>
</div>
</section>
