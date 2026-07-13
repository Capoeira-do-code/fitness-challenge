<?php

declare(strict_types=1);

$metric = is_array($selectedMetric ?? null) ? (array) $selectedMetric : [];
$penaltiesEnabled = penalties_enabled($GLOBALS['pdo']);
$canSwitchTrainingUser = is_admin($currentUser ?? []);
$trainingTableScope = (string) ($trainingTableScope ?? 'all');
$isAllTrainingScope = $trainingTableScope === 'all';
$trainingRangeStart = (string) ($trainingRangeStart ?? $weekStart ?? to_date(null));
$trainingRangeEnd = (string) ($trainingRangeEnd ?? $weekEnd ?? $trainingRangeStart);
$weeklyRows = array_values((array) ($metric['weekly'] ?? []));
usort($weeklyRows, static fn(array $left, array $right): int => strcmp((string) ($right['week_start'] ?? ''), (string) ($left['week_start'] ?? '')));

$selectedWeek = null;
foreach ($weeklyRows as $candidate) {
    if ((string) ($candidate['week_start'] ?? '') === (string) $weekStart) {
        $selectedWeek = $candidate;
        break;
    }
}
$selectedWeek = $selectedWeek ?? ['step_failures' => 0, 'workout_failures' => 0, 'skip_warnings' => 0, 'penalty' => 0, 'status' => 'in_progress'];

$allSheetUrl = '/?' . http_build_query([
    'page' => 'week_editor',
    'user_id' => (int) ($selectedUser['id'] ?? 0),
    'range' => 'all',
]);
$weekSheetUrl = '/?' . http_build_query([
    'page' => 'week_editor',
    'user_id' => (int) ($selectedUser['id'] ?? 0),
    'range' => 'week',
    'week' => date_to_iso_week((string) ($weekStart ?? to_date(null))),
]);
$allSummaryUrl = '/?' . http_build_query([
    'page' => 'table',
    'user_id' => (int) ($selectedUser['id'] ?? 0),
    'range' => 'all',
]);
$weekSummaryUrl = '/?' . http_build_query([
    'page' => 'table',
    'user_id' => (int) ($selectedUser['id'] ?? 0),
    'range' => 'week',
    'week' => date_to_iso_week((string) ($weekStart ?? to_date(null))),
]);
$summaryEditUrl = $isAllTrainingScope ? $allSheetUrl : $weekSheetUrl;

$habitLabelMap = [];
if (function_exists('list_habit_definitions')) {
    foreach (list_habit_definitions($GLOBALS['pdo'], false) as $habitDef) {
        $habitLabelMap[(string) ($habitDef['code'] ?? '')] = (string) ($habitDef['label'] ?? '');
    }
}
$habitLabelFor = static function (string $code) use ($habitLabelMap): string {
    $label = trim((string) ($habitLabelMap[$code] ?? ''));
    if ($label !== '') {
        return $label;
    }
    return ucwords(str_replace(['_', '-'], ' ', $code));
};

$workoutTotal = max((int) ($metric['workout_count'] ?? 0), (int) ($metric['workout_success'] ?? 0));
$weeklyStepFailures = array_sum(array_map(static fn(array $row): int => (int) ($row['step_failures'] ?? 0), $weeklyRows));
$weeklyWorkoutFailures = array_sum(array_map(static fn(array $row): int => (int) ($row['workout_failures'] ?? 0), $weeklyRows));
$weeklyWarnings = array_sum(array_map(static fn(array $row): int => (int) ($row['skip_warnings'] ?? 0), $weeklyRows));
$weeklyPenalty = array_sum(array_map(static fn(array $row): float => (float) ($row['penalty'] ?? 0), $weeklyRows));
?>
<section class="screen stack-lg">
    <div class="hero-panel">
        <div class="hero-copy hero-copy-page-title">
            <p class="eyebrow"><?= e(t('nav.table')) ?></p>
            <h1><?= e($isAllTrainingScope ? t('table.all_summary') : t('table.week_summary')) ?></h1>
            <p class="muted">
                <?= $isAllTrainingScope
                    ? e(t('table.all_subtitle', ['start' => format_date_eu($trainingRangeStart), 'end' => format_date_eu($trainingRangeEnd)]))
                    : e(format_date_eu($weekStart) . ' - ' . format_date_eu($weekEnd ?? $weekStart)) ?>
            </p>
        </div>
        <a class="btn btn-primary" href="<?= e($summaryEditUrl) ?>"><?= e(t('table.open_editor')) ?></a>
    </div>

    <form method="get" class="control-strip wrap training-summary-controls">
        <input type="hidden" name="page" value="table">
        <input type="hidden" name="range" value="<?= $isAllTrainingScope ? 'all' : 'week' ?>">
        <?php if ($canSwitchTrainingUser): ?>
            <label>
                <?= e(t('common.user')) ?>
                <select name="user_id" onchange="this.form.submit()">
                    <?php foreach ($users as $user): ?>
                        <option value="<?= (int) $user['id'] ?>" <?= (int) $selectedUser['id'] === (int) $user['id'] ? 'selected' : '' ?>><?= e((string) $user['display_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php else: ?>
            <input type="hidden" name="user_id" value="<?= (int) $selectedUser['id'] ?>">
        <?php endif; ?>
        <div class="training-sheet-view-tabs" role="group" aria-label="<?= e(t('dashboard.view_mode')) ?>">
            <a class="<?= $isAllTrainingScope ? 'active' : '' ?>" href="<?= e($allSummaryUrl) ?>" <?= $isAllTrainingScope ? 'aria-current="page"' : '' ?>><?= e(t('dashboard.all_challenge')) ?></a>
            <a class="<?= !$isAllTrainingScope ? 'active' : '' ?>" href="<?= e($weekSummaryUrl) ?>" <?= !$isAllTrainingScope ? 'aria-current="page"' : '' ?>><?= e(t('common.week')) ?></a>
        </div>
        <?php if (!$isAllTrainingScope): ?>
            <label>
                <?= e(t('common.week')) ?>
                <input type="week" name="week" value="<?= e(date_to_iso_week($weekStart)) ?>" onchange="this.form.submit()">
            </label>
        <?php endif; ?>
    </form>

    <div class="metric-grid">
        <article class="metric-card"><div class="progress-ring" style="--value: <?= e((string) ($metric['step_completion_pct'] ?? 0)) ?>;"><span><?= e((string) ($metric['step_completion_pct'] ?? 0)) ?>%</span></div><div><span><?= e(t('metric.steps')) ?></span><strong><?= e((string) ($metric['steps_success'] ?? 0)) ?>/<?= e((string) ($metric['steps_required'] ?? 0)) ?></strong><p><?= e(t('metric.total')) ?> <?= e((string) ($metric['total_steps'] ?? 0)) ?></p></div></article>
        <article class="metric-card"><div class="progress-ring" style="--value: <?= e((string) ($metric['workout_completion_pct'] ?? 0)) ?>;"><span><?= e((string) ($metric['workout_completion_pct'] ?? 0)) ?>%</span></div><div><span><?= e(t('metric.workouts')) ?></span><strong><?= e((string) $workoutTotal) ?>/<?= e((string) ($metric['workout_target'] ?? 0)) ?></strong><p><?= e(t('metric.progress')) ?></p></div></article>
        <article class="metric-card"><div class="progress-ring" style="--value: <?= e((string) min(100, (float) ($metric['total_km'] ?? 0))) ?>;"><span><?= e((string) ($metric['total_km'] ?? 0)) ?></span></div><div><span><?= e(t('metric.distance_km')) ?></span><strong><?= e((string) ($metric['total_km'] ?? 0)) ?> km</strong><p><?= e(t('metric.total')) ?></p></div></article>
        <?php if ($penaltiesEnabled): ?>
            <article class="metric-card"><div class="progress-ring" style="--value: <?= e((string) max(0, 100 - ((int) ($metric['current_strikes'] ?? 0) * 10))) ?>;"><span><?= e((string) ($metric['current_strikes'] ?? 0)) ?></span></div><div><span><?= e(t('metric.strikes')) ?></span><strong><?= e((string) ($metric['current_strikes'] ?? 0)) ?></strong><p><?= e(t('metric.current_value')) ?></p></div></article>
        <?php endif; ?>
    </div>

    <?php if ($isAllTrainingScope): ?>
        <div class="grid-two">
            <article class="panel">
                <h2><?= e(t('table.all_result')) ?></h2>
                <div class="training-result-grid">
                    <div class="training-result-stat<?= $weeklyStepFailures > 0 ? ' is-warn' : ' is-ok' ?>">
                        <span class="training-result-value"><?= e((string) $weeklyStepFailures) ?></span>
                        <span class="training-result-label"><?= e(t('metric.step_failures')) ?></span>
                    </div>
                    <div class="training-result-stat<?= $weeklyWorkoutFailures > 0 ? ' is-warn' : ' is-ok' ?>">
                        <span class="training-result-value"><?= e((string) $weeklyWorkoutFailures) ?></span>
                        <span class="training-result-label"><?= e(t('metric.workout_failures')) ?></span>
                    </div>
                    <div class="training-result-stat<?= $weeklyWarnings > 0 ? ' is-warn' : ' is-ok' ?>">
                        <span class="training-result-value"><?= e((string) $weeklyWarnings) ?></span>
                        <span class="training-result-label"><?= e(t('metric.skip_warnings')) ?></span>
                    </div>
                    <?php if ($penaltiesEnabled): ?>
                        <div class="training-result-stat<?= $weeklyPenalty > 0 ? ' is-warn' : ' is-ok' ?>">
                            <span class="training-result-value">&euro;<?= e(number_format($weeklyPenalty, 2, '.', '')) ?></span>
                            <span class="training-result-label"><?= e(t('metric.penalty')) ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </article>
            <article class="panel">
                <h2><?= e(t('table.habits_section')) ?></h2>
                <?php
                $habitCountsAll = (array) ($metric['habit_counts'] ?? []);
                $habitCountsShown = array_slice($habitCountsAll, 0, 3, true);
                $habitCountsRest = array_slice($habitCountsAll, 3, null, true);
                ?>
                <div class="training-habit-chips training-habit-chips-compact">
                    <?php foreach ($habitCountsShown as $code => $count): ?>
                        <span class="training-habit-chip">
                            <span class="training-habit-chip-label"><?= e($habitLabelFor((string) $code)) ?></span>
                            <span class="training-habit-chip-count"><?= e((string) $count) ?></span>
                        </span>
                    <?php endforeach; ?>
                    <?php if ($habitCountsRest !== []): ?>
                        <details class="kebab-menu training-habit-more" data-kebab-menu data-align="end">
                            <summary class="kebab-menu-trigger btn btn-ghost small training-habit-more-btn" aria-label="<?= e(t('common.view_all')) ?>">+<?= count($habitCountsRest) ?></summary>
                            <div class="kebab-menu-panel" role="menu">
                                <?php foreach ($habitCountsRest as $code => $count): ?>
                                    <span class="kebab-menu-item training-habit-more-item">
                                        <span class="training-habit-chip-label"><?= e($habitLabelFor((string) $code)) ?></span>
                                        <span class="training-habit-chip-count"><?= e((string) $count) ?></span>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </details>
                    <?php endif; ?>
                    <?php if ($habitCountsAll === []): ?>
                        <p class="muted"><?= e(t('common.none')) ?></p>
                    <?php endif; ?>
                </div>
            </article>
        </div>

        <article class="panel training-summary-weeks-panel">
            <div class="panel-head">
                <h2><?= e(t('dashboard.weekly_history')) ?></h2>
                <a class="btn btn-ghost small" href="<?= e($allSheetUrl) ?>"><?= e(t('table.open_editor')) ?></a>
            </div>
            <div class="table-wrap">
                <table class="table compact training-summary-week-table">
                    <thead>
                    <tr>
                        <th><?= e(t('common.week')) ?></th>
                        <th><?= e(t('common.status')) ?></th>
                        <th><?= e(t('metric.steps')) ?></th>
                        <th><?= e(t('metric.distance_km')) ?></th>
                        <th><?= e(t('metric.workouts')) ?></th>
                        <th><?= e(t('metric.warnings')) ?></th>
                        <?php if ($penaltiesEnabled): ?>
                            <th><?= e(t('dashboard.strike_reduction')) ?></th>
                            <th><?= e(t('dashboard.strikes_after_week')) ?></th>
                            <th><?= e(t('metric.penalty')) ?></th>
                        <?php endif; ?>
                        <th><?= e(t('common.actions')) ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($weeklyRows as $row): ?>
                        <?php
                        $rowWeekStart = (string) ($row['week_start'] ?? '');
                        $rowWeekEditorHref = '/?' . http_build_query([
                            'page' => 'week_editor',
                            'user_id' => (int) ($selectedUser['id'] ?? 0),
                            'range' => 'week',
                            'week' => date_to_iso_week($rowWeekStart !== '' ? $rowWeekStart : (string) $weekStart),
                        ]);
                        ?>
                        <tr>
                            <td data-label="<?= e(t('common.week')) ?>"><?= e(format_date_eu((string) ($row['week_start'] ?? ''))) ?> - <?= e(format_date_eu((string) ($row['week_end'] ?? ''))) ?></td>
                            <td data-label="<?= e(t('common.status')) ?>"><?= e(label_for_status((string) ($row['status'] ?? ''))) ?></td>
                            <td data-label="<?= e(t('metric.steps')) ?>"><?= e((string) ($row['steps'] ?? 0)) ?></td>
                            <td data-label="<?= e(t('metric.distance_km')) ?>"><?= e((string) ($row['km'] ?? 0)) ?> km</td>
                            <td data-label="<?= e(t('metric.workouts')) ?>"><?= e((string) ($row['workouts'] ?? 0)) ?></td>
                            <td data-label="<?= e(t('metric.warnings')) ?>"><?= e((string) ($row['skip_warnings'] ?? 0)) ?></td>
                            <?php if ($penaltiesEnabled): ?>
                                <td data-label="<?= e(t('dashboard.strike_reduction')) ?>"><?= (int) ($row['strike_reduction'] ?? 0) > 0 ? '-1' : '-' ?></td>
                                <td data-label="<?= e(t('dashboard.strikes_after_week')) ?>"><?= e((string) ($row['strikes_after_week'] ?? 0)) ?></td>
                                <td data-label="<?= e(t('metric.penalty')) ?>">&euro;<?= e(number_format((float) ($row['penalty'] ?? 0), 2, '.', '')) ?></td>
                            <?php endif; ?>
                            <td data-label="<?= e(t('common.actions')) ?>"><a class="btn btn-ghost small" href="<?= e($rowWeekEditorHref) ?>"><?= e(t('dashboard.edit_week')) ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($weeklyRows === []): ?>
                        <tr><td colspan="<?= $penaltiesEnabled ? 10 : 7 ?>" class="muted"><?= e(t('common.none')) ?></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>
    <?php else: ?>
        <div class="grid-two">
            <article class="panel">
                <h2><?= e(t('table.week_result')) ?></h2>
                <ul class="facts">
                    <li><strong><?= e(t('common.status')) ?>:</strong> <?= e(label_for_status((string) $selectedWeek['status'])) ?></li>
                    <li><strong><?= e(t('metric.step_failures')) ?>:</strong> <?= e((string) $selectedWeek['step_failures']) ?></li>
                    <li><strong><?= e(t('metric.workout_failures')) ?>:</strong> <?= e((string) $selectedWeek['workout_failures']) ?></li>
                    <li><strong><?= e(t('metric.skip_warnings')) ?>:</strong> <?= e((string) $selectedWeek['skip_warnings']) ?></li>
                    <?php if ($penaltiesEnabled): ?>
                        <li><strong><?= e(t('metric.penalty')) ?>:</strong> &euro;<?= e((string) $selectedWeek['penalty']) ?></li>
                    <?php endif; ?>
                </ul>
            </article>
            <article class="panel">
                <h2><?= e(t('goals.title')) ?></h2>
                <div class="stat-list">
                    <?php foreach (($metric['habit_counts'] ?? []) as $code => $count): ?>
                        <article><strong><?= e((string) $code) ?></strong><span><?= e((string) $count) ?></span></article>
                    <?php endforeach; ?>
                    <?php if (($metric['habit_counts'] ?? []) === []): ?>
                        <p class="muted"><?= e(t('common.none')) ?></p>
                    <?php endif; ?>
                </div>
            </article>
        </div>
    <?php endif; ?>
</section>
