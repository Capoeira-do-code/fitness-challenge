<?php

declare(strict_types=1);

$wkView = (string) ($wkView ?? 'list');
$csrf = csrf_token();
$exercises = (array) ($wkExercises ?? []);
$exerciseTypeLabel = static function (string $type): string {
    return t('workouts.type_' . (in_array($type, ['strength', 'cardio', 'isometric', 'bodyweight', 'freeform'], true) ? $type : 'strength'));
};
$muscleLabel = static fn(string $m): string => $m !== '' ? ucfirst($m) : '';
?>
<section class="screen stack-lg workouts-screen">
    <div class="hero-panel workouts-hero">
        <div class="hero-copy hero-copy-page-title">
            <p class="eyebrow"><?= e(t('nav.profile')) ?></p>
            <h1><?= e(t('workouts.title')) ?></h1>
            <p class="muted"><?= e(t('workouts.subtitle')) ?></p>
        </div>
        <?php if ($wkView !== 'list'): ?>
            <a class="btn btn-ghost small" href="/?page=workouts">← <?= e(t('workouts.title')) ?></a>
        <?php else: ?>
            <div class="inline-actions-mini">
                <a class="btn btn-ghost small" href="/?page=workouts&view=stats"><?= e(t('workouts.stats')) ?></a>
            </div>
        <?php endif; ?>
    </div>

<?php if ($wkView === 'list'): ?>
    <?php
    $summaryMonth = (array) ($wkSummaryMonth ?? []);
    $summaryAll = (array) ($wkSummaryAll ?? []);
    ?>
    <div class="workouts-stats-grid">
        <article class="workouts-stat-card">
            <span class="workouts-stat-value"><?= (int) ($summaryMonth['sessions'] ?? 0) ?></span>
            <span class="workouts-stat-label"><?= e(t('workouts.stat_sessions')) ?> · <?= e(t('workouts.this_month')) ?></span>
        </article>
        <article class="workouts-stat-card">
            <span class="workouts-stat-value"><?= e(number_format((float) ($summaryMonth['volume'] ?? 0), 0, '.', ' ')) ?></span>
            <span class="workouts-stat-label"><?= e(t('workouts.stat_volume')) ?> · <?= e(t('workouts.this_month')) ?></span>
        </article>
        <article class="workouts-stat-card">
            <span class="workouts-stat-value"><?= (int) ($summaryAll['sessions'] ?? 0) ?></span>
            <span class="workouts-stat-label"><?= e(t('workouts.stat_sessions')) ?> · <?= e(t('workouts.all_time')) ?></span>
        </article>
        <article class="workouts-stat-card">
            <span class="workouts-stat-value"><?= e(number_format((int) ($summaryAll['reps'] ?? 0), 0, '.', ' ')) ?></span>
            <span class="workouts-stat-label"><?= e(t('workouts.stat_reps')) ?> · <?= e(t('workouts.all_time')) ?></span>
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
        </div>

        <?php
        $activeRoutines = array_values(array_filter((array) ($wkRoutines ?? []), static fn($r) => (int) ($r['is_archived'] ?? 0) === 0));
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
                    $routineMenu = render_kebab_menu([
                        ['label' => (int) ($routine['is_favorite'] ?? 0) === 1 ? t('workouts.favorite') . ' ✓' : t('workouts.favorite'), 'attrs' => ['data-wk-submit' => 'routine_favorite', 'data-wk-routine' => (string) $rid, 'data-wk-value' => (int) ($routine['is_favorite'] ?? 0) === 1 ? '0' : '1']],
                        ['label' => t('common.edit'), 'href' => '/?page=workouts&routine_id=' . $rid],
                        ['label' => t('workouts.duplicate'), 'attrs' => ['data-wk-submit' => 'routine_duplicate', 'data-wk-routine' => (string) $rid]],
                        ['label' => t('workouts.archive'), 'attrs' => ['data-wk-submit' => 'routine_archive', 'data-wk-routine' => (string) $rid, 'data-wk-value' => '1']],
                        ['label' => t('workouts.delete_routine'), 'danger' => true, 'attrs' => ['data-wk-submit' => 'routine_delete', 'data-wk-routine' => (string) $rid, 'data-wk-confirm' => t('common.confirm_delete')]],
                    ], ['align' => 'end']);
                    ?>
                    <article class="workouts-routine-card<?= (int) ($routine['is_favorite'] ?? 0) === 1 ? ' is-favorite' : '' ?>">
                        <div class="workouts-routine-card-head">
                            <a class="workouts-routine-title" href="/?page=workouts&routine_id=<?= $rid ?>">
                                <?php if ((int) ($routine['is_favorite'] ?? 0) === 1): ?><span class="workouts-fav-star" aria-hidden="true">★</span><?php endif; ?>
                                <strong><?= e((string) $routine['name']) ?></strong>
                            </a>
                            <?= $routineMenu ?>
                        </div>
                        <?php if ((string) ($routine['description'] ?? '') !== ''): ?>
                            <p class="muted small workouts-routine-desc"><?= e((string) $routine['description']) ?></p>
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

    <!-- New routine modal -->
    <div class="app-modal" id="wk-new-routine-modal" hidden role="dialog" aria-modal="true" aria-labelledby="wk-new-routine-title">
        <div class="app-modal-card">
            <div class="app-modal-head">
                <h2 id="wk-new-routine-title"><?= e(t('workouts.new_routine')) ?></h2>
                <button type="button" class="app-modal-close" data-app-modal-close aria-label="<?= e(t('common.back')) ?>">&times;</button>
            </div>
            <form method="post" action="/?page=workouts" class="stack">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="routine_create">
                <label><?= e(t('workouts.routine_name')) ?><input type="text" name="name" maxlength="80" required autofocus placeholder="Push / Pull / Legs…"></label>
                <label><?= e(t('workouts.description')) ?><input type="text" name="description" maxlength="200"></label>
                <button type="submit" class="btn btn-primary btn-block"><?= e(t('common.create')) ?></button>
            </form>
        </div>
    </div>

<?php elseif ($wkView === 'routine' && !empty($wkRoutine)): ?>
    <?php $r = (array) $wkRoutine; $rid = (int) $r['id']; ?>
    <article class="panel">
        <form method="post" action="/?page=workouts" class="stack compact-form workouts-routine-form">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="routine_update">
            <input type="hidden" name="routine_id" value="<?= $rid ?>">
            <label><?= e(t('workouts.routine_name')) ?><input type="text" name="name" value="<?= e((string) $r['name']) ?>" maxlength="80" required></label>
            <label><?= e(t('workouts.description')) ?><input type="text" name="description" value="<?= e((string) ($r['description'] ?? '')) ?>" maxlength="200"></label>
            <button type="submit" class="btn btn-primary small"><?= e(t('common.save')) ?></button>
        </form>
    </article>

    <article class="panel">
        <div class="panel-head"><div><p class="eyebrow"><?= count((array) ($wkRoutineExercises ?? [])) ?></p><h2><?= e(t('workouts.exercises')) ?></h2></div>
            <form method="post" action="/?page=workouts" class="inline-form">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="session_start">
                <input type="hidden" name="routine_id" value="<?= $rid ?>">
                <button type="submit" class="btn btn-primary small"<?= !empty($wkActiveSession) ? ' disabled title="' . e(t('workouts.finish_active_first')) . '"' : '' ?>><?= e(t('workouts.start_routine')) ?></button>
            </form>
        </div>
        <?php if (($wkRoutineExercises ?? []) === []): ?>
            <p class="muted"><?= e(t('workouts.no_exercises')) ?></p>
        <?php else: ?>
            <ul class="workouts-exercise-list">
                <?php foreach ((array) $wkRoutineExercises as $ex): ?>
                    <li class="workouts-exercise-row">
                        <div class="workouts-exercise-info">
                            <strong><?= e((string) $ex['exercise_name']) ?></strong>
                            <span class="muted small"><?= e($exerciseTypeLabel((string) $ex['exercise_type'])) ?><?= $muscleLabel((string) ($ex['muscle_group'] ?? '')) !== '' ? ' · ' . e($muscleLabel((string) $ex['muscle_group'])) : '' ?> · <?= (int) $ex['target_sets'] ?>×<?= $ex['target_reps'] !== null ? (int) $ex['target_reps'] : '—' ?><?= $ex['target_weight'] !== null ? ' @ ' . e(rtrim(rtrim(number_format((float) $ex['target_weight'], 1, '.', ''), '0'), '.')) . e((string) $ex['unit']) : '' ?></span>
                        </div>
                        <form method="post" action="/?page=workouts" class="inline-form">
                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                            <input type="hidden" name="action" value="routine_remove_exercise">
                            <input type="hidden" name="routine_id" value="<?= $rid ?>">
                            <input type="hidden" name="routine_exercise_id" value="<?= (int) $ex['id'] ?>">
                            <button type="submit" class="btn btn-ghost small btn-icon" aria-label="<?= e(t('common.delete')) ?>">&times;</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <form method="post" action="/?page=workouts" class="workouts-add-exercise">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="routine_add_exercise">
            <input type="hidden" name="routine_id" value="<?= $rid ?>">
            <div class="workouts-add-exercise-grid">
                <label class="workouts-add-exercise-select"><?= e(t('workouts.add_exercise')) ?>
                    <select name="exercise_def_id" required>
                        <?php foreach ($exercises as $ed): ?>
                            <option value="<?= (int) $ed['id'] ?>"><?= e((string) $ed['name']) ?><?= (int) ($ed['is_system'] ?? 0) === 0 ? ' · ' . e(t('workouts.mine')) : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label><?= e(t('workouts.target_sets')) ?><input type="number" name="target_sets" min="1" max="20" value="3"></label>
                <label><?= e(t('workouts.target_reps')) ?><input type="number" name="target_reps" min="0" max="999" placeholder="10"></label>
                <label><?= e(t('workouts.target_weight')) ?><input type="number" name="target_weight" step="0.5" min="0" placeholder="—"></label>
            </div>
            <button type="submit" class="btn btn-primary small"><?= e(t('workouts.add_exercise')) ?></button>
        </form>

        <details class="workouts-custom-exercise">
            <summary><?= e(t('workouts.custom_exercise')) ?></summary>
            <form method="post" action="/?page=workouts" class="stack compact-form">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="exercise_create">
                <input type="hidden" name="routine_id" value="<?= $rid ?>">
                <div class="grid-inline two">
                    <label><?= e(t('workouts.exercise_name')) ?><input type="text" name="name" maxlength="80" required></label>
                    <label><?= e(t('workouts.muscle_group')) ?><input type="text" name="muscle_group" maxlength="40"></label>
                </div>
                <div class="grid-inline two">
                    <label><?= e(t('workouts.exercise_type')) ?>
                        <select name="exercise_type">
                            <?php foreach (['strength', 'cardio', 'isometric', 'bodyweight', 'freeform'] as $tp): ?>
                                <option value="<?= e($tp) ?>"><?= e($exerciseTypeLabel($tp)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label><?= e(t('workouts.equipment')) ?><input type="text" name="equipment" maxlength="40"></label>
                </div>
                <button type="submit" class="btn btn-ghost small"><?= e(t('common.create')) ?></button>
            </form>
        </details>
    </article>

    <article class="panel workouts-routine-danger">
        <div class="inline-actions">
            <form method="post" action="/?page=workouts" class="inline-form">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="routine_archive">
                <input type="hidden" name="routine_id" value="<?= $rid ?>">
                <input type="hidden" name="value" value="1">
                <button type="submit" class="btn btn-ghost small"><?= e(t('workouts.archive')) ?></button>
            </form>
            <form method="post" action="/?page=workouts" class="inline-form" onsubmit="return confirm('<?= e(t('common.confirm_delete')) ?>');">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="routine_delete">
                <input type="hidden" name="routine_id" value="<?= $rid ?>">
                <button type="submit" class="btn btn-ghost small btn-danger-ghost"><?= e(t('workouts.delete_routine')) ?></button>
            </form>
        </div>
    </article>

<?php elseif ($wkView === 'session' && !empty($wkSession)): ?>
    <?php $s = (array) $wkSession; $sid = (int) $s['id']; $sessionDone = (string) $s['status'] !== 'active'; ?>
    <article class="panel workouts-session-panel">
        <div class="panel-head">
            <div><p class="eyebrow"><?= e(t('workouts.active_session')) ?></p><h2><?= e((string) ($s['title'] ?? '') !== '' ? (string) $s['title'] : t('workouts.session')) ?></h2></div>
        </div>

        <?php if (($wkSessionExercises ?? []) === []): ?>
            <p class="muted"><?= e(t('workouts.no_exercises')) ?></p>
        <?php else: ?>
            <div class="workouts-session-exercises">
                <?php foreach ((array) $wkSessionExercises as $ex): ?>
                    <article class="workouts-session-exercise">
                        <div class="workouts-session-exercise-head">
                            <strong><?= e((string) $ex['exercise_name']) ?></strong>
                            <span class="muted small"><?= e($exerciseTypeLabel((string) $ex['exercise_type'])) ?></span>
                        </div>
                        <div class="workouts-set-rows">
                            <?php foreach ((array) ($ex['sets'] ?? []) as $set): ?>
                                <form method="post" action="/?page=workouts" class="workouts-set-row<?= (int) ($set['completed'] ?? 0) === 1 ? ' is-done' : '' ?>">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                    <input type="hidden" name="action" value="session_update_set">
                                    <input type="hidden" name="session_id" value="<?= $sid ?>">
                                    <input type="hidden" name="set_id" value="<?= (int) $set['id'] ?>">
                                    <span class="workouts-set-index"><?= (int) $set['set_index'] ?></span>
                                    <label class="workouts-set-field"><span class="sr-only"><?= e(t('workouts.weight')) ?></span><input type="number" name="weight" step="0.5" min="0" value="<?= $set['weight'] !== null ? e(rtrim(rtrim(number_format((float) $set['weight'], 1, '.', ''), '0'), '.')) : '' ?>" placeholder="<?= e(t('workouts.weight')) ?>" inputmode="decimal"></label>
                                    <span class="workouts-set-x">×</span>
                                    <label class="workouts-set-field"><span class="sr-only"><?= e(t('workouts.reps')) ?></span><input type="number" name="reps" min="0" max="999" value="<?= $set['reps'] !== null ? (int) $set['reps'] : '' ?>" placeholder="<?= e(t('workouts.reps')) ?>" inputmode="numeric"></label>
                                    <button type="submit" name="completed" value="<?= (int) ($set['completed'] ?? 0) === 1 ? '0' : '1' ?>" class="btn workouts-set-done<?= (int) ($set['completed'] ?? 0) === 1 ? ' btn-primary' : ' btn-ghost' ?> small" aria-label="<?= e(t('workouts.done')) ?>">✓</button>
                                </form>
                            <?php endforeach; ?>
                        </div>
                        <?php if (!$sessionDone): ?>
                            <form method="post" action="/?page=workouts" class="inline-form">
                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                <input type="hidden" name="action" value="session_add_set">
                                <input type="hidden" name="session_id" value="<?= $sid ?>">
                                <input type="hidden" name="session_exercise_id" value="<?= (int) $ex['id'] ?>">
                                <button type="submit" class="btn btn-ghost small workouts-add-set-btn">+ <?= e(t('workouts.add_set')) ?></button>
                            </form>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!$sessionDone): ?>
            <form method="post" action="/?page=workouts" class="workouts-session-add-exercise">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="session_add_exercise">
                <input type="hidden" name="session_id" value="<?= $sid ?>">
                <select name="exercise_def_id" required>
                    <?php foreach ($exercises as $ed): ?>
                        <option value="<?= (int) $ed['id'] ?>"><?= e((string) $ed['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-ghost small">+ <?= e(t('workouts.add_exercise')) ?></button>
            </form>

            <div class="workouts-session-footer">
                <form method="post" action="/?page=workouts" class="inline-form" onsubmit="return confirm('<?= e(t('workouts.cancel_session')) ?>?');">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="session_cancel">
                    <input type="hidden" name="session_id" value="<?= $sid ?>">
                    <button type="submit" class="btn btn-ghost"><?= e(t('workouts.cancel_session')) ?></button>
                </form>
                <form method="post" action="/?page=workouts" class="stack workouts-finish-form">
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
</section>
