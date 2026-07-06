<?php

declare(strict_types=1);

$selectedUser = $selectedMetric['user'];
$penaltiesEnabled = penalties_enabled($GLOBALS['pdo']);
$dashboardLayout = json_decode((string) ($currentUser['dashboard_layout_json'] ?? ''), true);
$dashboardWidgets = ['kpis', 'achievements', 'approvals', 'ranking', 'weekly'];
$visibleWidgets = [];
if (is_array($dashboardLayout) && $dashboardLayout !== []) {
    foreach ($dashboardLayout as $widget) {
        if (!is_string($widget) || $widget === '') {
            continue;
        }
        $normalizedWidget = $widget === 'money' ? 'distance_walked' : $widget;
        if (in_array($normalizedWidget, $dashboardWidgets, true) && !in_array($normalizedWidget, $visibleWidgets, true)) {
            $visibleWidgets[] = $normalizedWidget;
        }
    }
}
if ($visibleWidgets === []) {
    $visibleWidgets = $dashboardWidgets;
}
$showWidget = static fn(string $widget): bool => in_array($widget, $visibleWidgets, true);
$dashboardEditorWidgets = $dashboardWidgets;
$dashboardLayoutEditMode = (string) ($_GET['layout_edit'] ?? '') === '1';
$widgetOrder = static function (string ...$widgets) use ($visibleWidgets): int {
    $orders = [];
    foreach ($widgets as $widget) {
        $index = array_search($widget, $visibleWidgets, true);
        if ($index !== false) {
            $orders[] = (int) $index + 1;
        }
    }

    return $orders === [] ? 99 : min($orders);
};
$contentWidgetOrder = static fn(string ...$widgets): int => 100 + $widgetOrder(...$widgets);
$dashboardAchievementsAll = array_values((array) ($dashboardAchievements ?? []));
if (!$penaltiesEnabled) {
    $dashboardAchievementsAll = array_values(array_filter($dashboardAchievementsAll, static function (array $achievement): bool {
        $metric = strtolower(trim((string) ($achievement['metric'] ?? $achievement['target_type'] ?? '')));
        if (in_array($metric, ['strikes', 'penalties', 'penalty'], true)) {
            return false;
        }

        $text = strtolower(implode(' ', array_map('strval', [
            $achievement['name'] ?? '',
            $achievement['description'] ?? '',
            $achievement['reward_text'] ?? '',
        ])));

        return !str_contains($text, 'strike') && !str_contains($text, 'penalt');
    }));
}
$dashboardUnlockedAchievements = array_values(array_filter($dashboardAchievementsAll, static fn(array $achievement): bool => !empty($achievement['is_unlocked'])));
usort(
    $dashboardUnlockedAchievements,
    static fn(array $left, array $right): int => strcmp((string) ($right['awarded_at'] ?? ''), (string) ($left['awarded_at'] ?? ''))
);
$dashboardAchievementPreview = array_slice($dashboardUnlockedAchievements, 0, 4);
$dashboardAchievementsUrl = '/?' . http_build_query([
    'page' => 'achievements',
    'scope' => 'user',
    'user_id' => (int) ($selectedUser['id'] ?? 0),
    'back' => 'dashboard',
    'view' => (string) ($dashboardView ?? 'current_week'),
]);
$penaltySeverityClass = static function (int|float $penalty): string {
    $value = (float) $penalty;
    if ($value <= 0) {
        return 'good';
    }
    if ($value <= 50) {
        return 'warn';
    }

    return 'bad';
};

$calorieStats = is_array($dashboardCalorieStats ?? null) ? (array) $dashboardCalorieStats : [];
$formatCalories = static function (float $value): string {
    return number_format($value, 0, '.', '');
};
$calorieRangeStart = (string) ($dashboardCalorieRangeStart ?? to_date(null));
$calorieRangeEnd = (string) ($dashboardCalorieRangeEnd ?? $calorieRangeStart);
$calorieConsumedTotal = (float) ($calorieStats['total_consumed'] ?? 0);
$calorieBurnedTotal = (float) ($calorieStats['total_burned'] ?? 0);
$calorieRangeDays = 1;
try {
    $calorieRangeDays = max(
        1,
        ((new DateTimeImmutable($calorieRangeStart))->diff(new DateTimeImmutable($calorieRangeEnd))->days ?? 0) + 1
    );
} catch (Throwable) {
    $calorieRangeDays = 1;
}
$calorieBurnGoalDaily = ($selectedUser['calorie_burn_goal'] ?? null) !== null
    ? max(0.0, (float) $selectedUser['calorie_burn_goal'])
    : null;
$calorieConsumedMaxDaily = ($selectedUser['calorie_consumed_max'] ?? null) !== null
    ? max(0.0, (float) $selectedUser['calorie_consumed_max'])
    : null;
$calorieBurnGoalTotal = $calorieBurnGoalDaily !== null ? $calorieBurnGoalDaily * $calorieRangeDays : 0.0;
$calorieConsumedMaxTotal = $calorieConsumedMaxDaily !== null ? $calorieConsumedMaxDaily * $calorieRangeDays : 0.0;
$calorieBurnProgress = $calorieBurnGoalTotal > 0
    ? max(0.0, min(100.0, round(($calorieBurnedTotal / $calorieBurnGoalTotal) * 100, 1)))
    : 0.0;
$calorieConsumedProgress = 0.0;
if ($calorieConsumedMaxTotal > 0) {
    if ($calorieConsumedTotal <= $calorieConsumedMaxTotal) {
        $calorieConsumedProgress = 100.0;
    } else {
        $calorieConsumedProgress = max(
            0.0,
            min(100.0, round(($calorieConsumedMaxTotal / max(0.001, $calorieConsumedTotal)) * 100, 1))
        );
    }
}
$calorieBurnRing = (string) round($calorieBurnProgress) . '%';
$calorieConsumedRing = (string) round($calorieConsumedProgress) . '%';
$calorieBurnMeta = $calorieBurnGoalTotal > 0
    ? t('metric.goal') . ': ' . $formatCalories($calorieBurnGoalTotal) . ' kcal'
    : t('dashboard.calories_goal_not_set');
$calorieConsumedMeta = $calorieConsumedMaxTotal > 0
    ? t('dashboard.calories_max') . ': ' . $formatCalories($calorieConsumedMaxTotal) . ' kcal'
    : t('dashboard.calories_goal_not_set');
$viewSnapshot = is_array($selectedMetricSnapshot ?? null) ? (array) $selectedMetricSnapshot : [];
$viewSteps = max(0, (int) ($viewSnapshot['steps'] ?? ($selectedMetric['total_steps'] ?? 0)));
$viewDistance = round((float) ($viewSnapshot['distance_km'] ?? ($selectedMetric['total_km'] ?? 0)), 2);
$viewWorkoutSuccess = max(0, (int) ($viewSnapshot['workouts'] ?? ($selectedMetric['workout_success'] ?? 0)));
$viewWorkoutTarget = max(0, (int) ($viewSnapshot['workout_target'] ?? ($selectedMetric['workout_target'] ?? 0)));
$viewStepCompletionPct = (float) ($viewSnapshot['step_completion_pct'] ?? ($selectedMetric['step_completion_pct'] ?? 0));
$viewWorkoutCompletionPct = $viewWorkoutTarget > 0
    ? round(($viewWorkoutSuccess / $viewWorkoutTarget) * 100, 1)
    : (float) ($selectedMetric['workout_completion_pct'] ?? 0);
$viewStrikes = max(0, (int) ($viewSnapshot['strikes'] ?? ($selectedMetric['current_strikes'] ?? 0)));
$viewPenalty = max(0.0, (float) ($viewSnapshot['penalty'] ?? ($selectedMetric['total_penalty'] ?? 0)));
$viewPenaltyLabel = "\u{20AC}" . number_format($viewPenalty, 2, '.', '');

$kpis = [
    [
        'key' => 'steps',
        'label' => t('metric.steps'),
        'value' => (string) $viewSteps,
        'meta' => number_format($viewStepCompletionPct, 1, '.', '') . '%',
        'ring' => number_format($viewStepCompletionPct, 1, '.', '') . '%',
        'progress' => $viewStepCompletionPct,
    ],
    [
        'key' => 'distance',
        'label' => t('metric.total_km'),
        'value' => number_format($viewDistance, 2, '.', '') . ' km',
        'meta' => t('metric.distance_km'),
        'ring' => number_format($viewDistance, 1, '.', ''),
        'progress' => min(100, $viewDistance),
    ],
    [
        'key' => 'workouts',
        'label' => t('metric.workouts'),
        'value' => (string) $viewWorkoutSuccess . ' / ' . (string) $viewWorkoutTarget,
        'meta' => (string) $viewWorkoutCompletionPct . '%',
        'ring' => (string) $viewWorkoutCompletionPct . '%',
        'progress' => (float) $viewWorkoutCompletionPct,
    ],
];
if ($penaltiesEnabled) {
    $kpis[] = [
        'key' => 'strikes',
        'label' => t('metric.strikes'),
        'value' => (string) $viewStrikes,
        'meta' => t('dashboard.accumulated_penalty', ['amount' => $viewPenaltyLabel]),
        'ring' => (string) $viewStrikes,
        'progress' => max(0, 100 - ($viewStrikes * 10)),
    ];
}
$metricQueryBase = [
    'page' => 'metric',
    'user_id' => (int) ($selectedUser['id'] ?? 0),
    'view' => (string) ($dashboardView ?? 'current_week'),
];
$strikesQueryBase = [
    'page' => 'strikes_detail',
    'user_id' => (int) ($selectedUser['id'] ?? 0),
    'view' => (string) ($dashboardView ?? 'current_week'),
];
$strikesHref = '/?' . http_build_query($strikesQueryBase);
$penaltiesHref = '/?' . http_build_query([
    'page' => 'penalties',
    'user_id' => (int) ($selectedUser['id'] ?? 0),
    'view' => (string) ($dashboardView ?? 'current_week'),
]);
$dashboardViewParam = (string) ($dashboardView ?? 'current_week');
$dashboardTopbarQuery = [
    'page' => 'dashboard',
    'user_id' => (int) ($selectedUser['id'] ?? 0),
    'view' => $dashboardViewParam,
];
$dashboardEditLayoutUrl = '/?' . http_build_query($dashboardTopbarQuery + ['layout_edit' => '1']);
$dashboardCancelEditLayoutUrl = '/?' . http_build_query($dashboardTopbarQuery);
$selectedWeekPenalty = 0.0;
foreach ((array) ($selectedMetric['weekly'] ?? []) as $weekRow) {
    if ((string) ($weekRow['week_start'] ?? '') === (string) ($selectedWeekStart ?? '')) {
        $selectedWeekPenalty = max(0.0, (float) ($weekRow['penalty'] ?? 0));
        break;
    }
}
ob_start();
?>
<?php if ($dashboardLayoutEditMode): ?>
<button class="btn btn-primary btn-topbar" type="submit" form="dashboard-layout-edit-form"><?= e(t('common.save')) ?></button>
<?php else: ?>
<details class="topbar-context dashboard-mobile-controls">
    <summary class="btn btn-ghost btn-topbar dashboard-controls-trigger"><?= e(t('dashboard.view_mode')) ?></summary>
    <div class="topbar-context-panel">
        <form method="get" class="stack dashboard-control-form" data-dashboard-control-form>
        <input type="hidden" name="page" value="dashboard">
        <label class="dashboard-control-field">
            <?= e(t('dashboard.viewing')) ?>
            <select class="glass-select" name="user_id" data-testid="dashboard-user-select">
                <?php foreach ($users as $user): ?>
                    <option value="<?= (int) $user['id'] ?>" <?= (int) $user['id'] === (int) $selectedUser['id'] ? 'selected' : '' ?>>
                        <?= e((string) $user['display_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="dashboard-control-field">
            <?= e(t('dashboard.view_mode')) ?>
            <select class="glass-select" name="view" data-testid="dashboard-week-select">
                <option value="current_week" <?= ($dashboardView ?? '') === 'current_week' ? 'selected' : '' ?>><?= e(t('dashboard.current_week')) ?></option>
                <option value="total" <?= ($dashboardView ?? '') === 'total' ? 'selected' : '' ?>><?= e(t('metric.total')) ?></option>
                <?php foreach ($weekOptions as $weekStart): ?>
                    <option value="<?= e($weekStart) ?>" <?= ($dashboardView ?? '') === $weekStart ? 'selected' : '' ?>><?= e(format_date_eu($weekStart)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        </form>
        <a class="btn btn-primary btn-block dashboard-mobile-edit-entry edit-layout-button" href="<?= e($dashboardEditLayoutUrl) ?>"><?= e(t('dashboard.edit_layout')) ?></a>
        <details class="inline-context-sub dashboard-layout-context">
            <summary class="btn btn-ghost btn-block dashboard-edit-layout-trigger"><?= e(t('dashboard.edit_layout')) ?></summary>
            <form method="post" action="/?page=dashboard" class="team-layout-editor dashboard-layout-editor" data-dashboard-layout-editor>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="save_dashboard_layout">
                <input type="hidden" name="dashboard_view" value="<?= e((string) ($dashboardView ?? 'current_week')) ?>">
                <input type="hidden" name="redirect_user_id" value="<?= (int) ($selectedUser['id'] ?? 0) ?>">
                <div class="team-layout-editor-head">
                    <strong><?= e(t('dashboard.edit_layout')) ?></strong>
                    <small><?= e(t('dashboard.layout_hint')) ?></small>
                </div>
                <div class="team-layout-editor-list dashboard-layout-editor-list" data-dashboard-layout-list>
                    <?php foreach ($dashboardEditorWidgets as $idx => $widget): ?>
                        <div class="team-layout-editor-item dashboard-layout-editor-item" draggable="true" data-dashboard-layout-item>
                            <span class="team-layout-drag-handle" aria-hidden="true">::</span>
                            <label class="dashboard-layout-toggle">
                                <input type="checkbox" name="dashboard_widgets[]" value="<?= e($widget) ?>" <?= in_array($widget, $visibleWidgets, true) ? 'checked' : '' ?>>
                                <span><?= e(t('dashboard.widget_' . $widget)) ?></span>
                            </label>
                            <div class="dashboard-layout-mobile-actions">
                                <button class="btn btn-ghost small" type="button" data-layout-move="up" aria-label="Move up">&uarr;</button>
                                <button class="btn btn-ghost small" type="button" data-layout-move="down" aria-label="Move down">&darr;</button>
                            </div>
                            <input type="hidden" name="dashboard_order[<?= e($widget) ?>]" value="<?= e((string) ($idx + 1)) ?>" data-dashboard-order-input>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="team-layout-editor-actions">
                    <button class="btn btn-ghost small" type="submit" name="reset_dashboard_layout" value="1"><?= e(t('dashboard.reset_layout')) ?></button>
                    <button class="btn btn-primary small" type="submit"><?= e(t('common.save')) ?></button>
                </div>
            </form>
        </details>
    </div>
</details>
<?php endif; ?>
<?php
$topbarControls = ob_get_clean();
?>
<section class="screen stack-lg" data-dashboard-page>
    <div class="motivation-band">
        <span><?= e(t('dashboard.motivation')) ?></span>
        <strong>"<?= e((string) ($motivationQuote ?? t('dashboard.default_quote'))) ?>"</strong>
    </div>

    <?php if ($dashboardLayoutEditMode): ?>
    <article class="panel dashboard-layout-edit-mode-panel">
        <form id="dashboard-layout-edit-form" method="post" action="/?page=dashboard" class="dashboard-layout-editor dashboard-layout-editor-mobile" data-dashboard-layout-editor>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_dashboard_layout">
            <input type="hidden" name="dashboard_view" value="<?= e((string) ($dashboardView ?? 'current_week')) ?>">
            <input type="hidden" name="redirect_user_id" value="<?= (int) ($selectedUser['id'] ?? 0) ?>">
            <div class="team-layout-editor-head">
                <strong><?= e(t('dashboard.edit_layout')) ?></strong>
                <small><?= e(t('dashboard.layout_hint')) ?></small>
            </div>
            <div class="team-layout-editor-list dashboard-layout-editor-list" data-dashboard-layout-list>
                <?php foreach ($dashboardEditorWidgets as $idx => $widget): ?>
                    <div class="team-layout-editor-item dashboard-layout-editor-item dashboard-layout-edit-card" data-dashboard-layout-item>
                        <div class="dashboard-layout-mobile-actions">
                            <button class="btn btn-ghost small" type="button" data-layout-move="up" aria-label="Move up">&uarr;</button>
                            <button class="btn btn-ghost small" type="button" data-layout-move="down" aria-label="Move down">&darr;</button>
                        </div>
                        <label class="dashboard-layout-toggle">
                            <input type="checkbox" name="dashboard_widgets[]" value="<?= e($widget) ?>" <?= in_array($widget, $visibleWidgets, true) ? 'checked' : '' ?>>
                            <span><?= e(t('dashboard.widget_' . $widget)) ?></span>
                        </label>
                        <input type="hidden" name="dashboard_order[<?= e($widget) ?>]" value="<?= e((string) ($idx + 1)) ?>" data-dashboard-order-input>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="team-layout-editor-actions">
                <a class="btn btn-ghost small" href="<?= e($dashboardCancelEditLayoutUrl) ?>"><?= e(t('common.back')) ?></a>
                <button class="btn btn-ghost small" type="submit" name="reset_dashboard_layout" value="1"><?= e(t('dashboard.reset_layout')) ?></button>
                <button class="btn btn-primary small" type="submit"><?= e(t('common.save')) ?></button>
            </div>
        </form>
    </article>
    <?php endif; ?>

    <div class="dashboard-layout">
        <?php if ($showWidget('kpis')): ?>
        <div class="metric-grid dashboard-span-full dashboard-kpis" style="order: -30">
            <?php foreach ($kpis as $kpi): ?>
                <?php
                $metricHref = '/?' . http_build_query($metricQueryBase + ['metric' => (string) $kpi['key']]);
                if ((string) ($kpi['key'] ?? '') === 'strikes') {
                    $metricHref = $strikesHref;
                }
                ?>
                <a class="metric-card metric-card-link" href="<?= e($metricHref) ?>" data-testid="metric-card-link-<?= e((string) $kpi['key']) ?>">
                    <div class="progress-ring" style="--value: <?= e((string) min(100, max(0, (float) $kpi['progress']))) ?>;">
                        <span><?= e((string) $kpi['ring']) ?></span>
                    </div>
                    <div>
                        <span><?= e((string) $kpi['label']) ?></span>
                        <strong><?= e((string) $kpi['value']) ?></strong>
                        <p><?= e((string) $kpi['meta']) ?></p>
                    </div>
                </a>
            <?php endforeach; ?>
            <article class="metric-card metric-card-calorie-goal" data-testid="metric-card-calorie-consumed">
                <div class="progress-ring" style="--value: <?= e((string) min(100, max(0, $calorieConsumedProgress))) ?>;">
                    <span><?= e($calorieConsumedRing) ?></span>
                </div>
                <div>
                    <span><?= e(t('dashboard.calories_consumed')) ?></span>
                    <strong><?= e($formatCalories($calorieConsumedTotal)) ?> kcal</strong>
                    <p><?= e($calorieConsumedMeta) ?></p>
                </div>
            </article>
            <article class="metric-card metric-card-calorie-goal" data-testid="metric-card-calorie-burned">
                <div class="progress-ring" style="--value: <?= e((string) min(100, max(0, $calorieBurnProgress))) ?>;">
                    <span><?= e($calorieBurnRing) ?></span>
                </div>
                <div>
                    <span><?= e(t('dashboard.calories_burned')) ?></span>
                    <strong><?= e($formatCalories($calorieBurnedTotal)) ?> kcal</strong>
                    <p><?= e($calorieBurnMeta) ?></p>
                </div>
            </article>
        </div>
        <?php endif; ?>

        <article class="panel dashboard-panel dashboard-span-full dashboard-quick-actions" style="order: -20">
            <div class="dashboard-quick-actions-grid">
                <a class="btn btn-primary dashboard-quick-action" href="/?page=entries&mode=data">
                    <?= e(t('dashboard.quick_action_training')) ?>
                </a>
                <a class="btn btn-secondary dashboard-quick-action" href="/?page=entries&mode=meal">
                    <?= e(t('dashboard.quick_action_meal')) ?>
                </a>
            </div>
        </article>

        <?php if ($penaltiesEnabled): ?>
        <article class="panel dashboard-panel dashboard-penalty-compact penalties-only" style="order: -15">
            <div class="panel-head dashboard-panel-head-compact dashboard-penalty-head">
                <div>
                    <p class="eyebrow"><?= e(t('metric.penalty')) ?></p>
                </div>
            </div>
            <div class="dashboard-penalty-compact-grid">
                <span>
                    <small><?= e(t('metric.strikes')) ?></small>
                    <strong><?= e((string) $viewStrikes) ?></strong>
                </span>
                <span>
                    <small><?= e(t('metric.week_penalty')) ?></small>
                    <strong>&euro;<?= e(number_format($selectedWeekPenalty, 2, '.', '')) ?></strong>
                </span>
            </div>
            <a class="btn btn-ghost small btn-block" href="<?= e($penaltiesHref) ?>"><?= e(t('dashboard.penalty_details')) ?></a>
        </article>
        <?php endif; ?>

        <article class="panel dashboard-panel dashboard-analytics-cta dashboard-analytics-compact" style="order: -14">
            <div class="dashboard-analytics-compact-copy">
                <p class="eyebrow"><?= e(t('dashboard.analytics_eyebrow')) ?></p>
                <p class="muted small"><?= e(t('dashboard.analytics_dashboard_hint')) ?></p>
            </div>
            <a class="btn btn-primary small dashboard-analytics-compact-action" href="/?<?= e(http_build_query(['page' => 'analytics', 'user_id' => (int) ($selectedUser['id'] ?? 0), 'analytics_period' => 'week', 'analytics_week' => (string) ($selectedWeekStart ?? to_date(null))])) ?>"><?= e(t('dashboard.open_analytics')) ?></a>
        </article>

        <?php if ($showWidget('achievements')): ?>
        <article class="panel dashboard-panel dashboard-achievements-panel dashboard-span-full" style="order: <?= $contentWidgetOrder('achievements') ?>">
            <div class="panel-head dashboard-panel-head-compact">
                <div>
                    <p class="eyebrow"><?= e(t('achievements.title')) ?></p>
                    <p class="muted small"><?= e(t('dashboard.latest_unlocked_achievements')) ?></p>
                </div>
                <div class="dashboard-achievements-summary">
                    <a class="btn btn-ghost small dashboard-panel-action" href="<?= e($dashboardAchievementsUrl) ?>"><?= e(t('common.view_all')) ?></a>
                </div>
            </div>
            <div class="dashboard-achievements-grid">
                <?php foreach ($dashboardAchievementPreview as $achievement): ?>
                    <?php
                    $progressPct = is_numeric($achievement['progress_pct'] ?? null) ? max(0.0, min(100.0, (float) $achievement['progress_pct'])) : null;
                    ?>
                    <article class="achievement-card dashboard-achievement-card is-unlocked" <?= achievement_modal_attrs($achievement) ?>>
                        <?= achievement_visual_html($achievement, 'achievement-visual') ?>
                        <div class="dashboard-achievement-content">
                            <div class="dashboard-achievement-title-row">
                                <strong><?= e((string) ($achievement['name'] ?? '')) ?></strong>
                            </div>
                            <p><?= e((string) ($achievement['description'] ?? '')) ?></p>
                            <?php if ($progressPct !== null): ?>
                                <div class="achievement-progress">
                                    <div class="goal-progress"><span style="width: <?= e((string) $progressPct) ?>%"></span></div>
                                    <small><?= e((string) ($achievement['progress_text'] ?? '')) ?></small>
                                </div>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
                <?php if ($dashboardAchievementPreview === []): ?>
                    <p class="muted panel-inline-empty"><?= e(t('achievements.empty')) ?></p>
                <?php endif; ?>
            </div>
        </article>
        <?php endif; ?>

        <?php if ($showWidget('weekly')): ?>
        <article class="panel dashboard-panel dashboard-span-full dashboard-weekly-history" style="order: -10">
            <div class="panel-head">
                <h2><?= e(t('dashboard.weekly_history')) ?></h2>
                <a class="btn btn-ghost small dashboard-panel-action" href="/?page=week_editor&user_id=<?= (int) $selectedUser['id'] ?>&week=<?= e(date_to_iso_week((string) $selectedWeekStart)) ?>"><?= e(t('table.open_editor')) ?></a>
            </div>
            <div class="table-wrap dashboard-desktop-table-wrap">
                <table class="table compact dashboard-weekly-table">
                    <thead>
                    <tr>
                        <th><?= e(t('common.week')) ?></th>
                        <th><?= e(t('common.status')) ?></th>
                        <th><?= e(t('metric.step_failures')) ?></th>
                        <th><?= e(t('metric.workout_failures')) ?></th>
                        <th><?= e(t('metric.warnings')) ?></th>
                        <?php if ($penaltiesEnabled): ?>
                        <th><?= e(t('strikes.economic_impact')) ?></th>
                        <?php endif; ?>
                        <?php if ($penaltiesEnabled): ?>
                        <th><?= e(t('dashboard.strike_reduction')) ?></th>
                        <th><?= e(t('dashboard.strikes_after_week')) ?></th>
                        <?php endif; ?>
                        <th><?= e(t('common.actions')) ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($selectedMetric['weekly'] as $week): ?>
                        <?php
                        $weekStartDate = (string) ($week['week_start'] ?? $selectedWeekStart ?? '');
                        $weekStrikesHref = '/?' . http_build_query([
                            'page' => 'strikes_detail',
                            'user_id' => (int) ($selectedUser['id'] ?? 0),
                            'view' => $weekStartDate,
                        ]);
                        $weekEditorHref = '/?' . http_build_query([
                            'page' => 'week_editor',
                            'user_id' => (int) ($selectedUser['id'] ?? 0),
                            'week' => date_to_iso_week($weekStartDate !== '' ? $weekStartDate : (string) $selectedWeekStart),
                        ]);
                        ?>
                        <tr>
                            <td data-label="<?= e(t('common.week')) ?>"><?= e(format_date_eu((string) $week['week_start'])) ?> -> <?= e(format_date_eu((string) $week['week_end'])) ?></td>
                            <td data-label="<?= e(t('common.status')) ?>"><?= e(label_for_status((string) $week['status'])) ?></td>
                            <td data-label="<?= e(t('metric.step_failures')) ?>"><?= e((string) $week['step_failures']) ?></td>
                            <td data-label="<?= e(t('metric.workout_failures')) ?>"><?= e((string) $week['workout_failures']) ?></td>
                            <td data-label="<?= e(t('metric.warnings')) ?>"><?= e((string) ($week['skip_warnings'] ?? 0)) ?></td>
                            <?php if ($penaltiesEnabled): ?>
                            <td data-label="<?= e(t('strikes.economic_impact')) ?>"><span class="penalty-chip penalty-chip-<?= e($penaltySeverityClass((int) ($week['penalty'] ?? 0))) ?>">&euro;<?= e(number_format((float) ($week['penalty'] ?? 0), 2, '.', '')) ?></span></td>
                            <?php endif; ?>
                            <?php if ($penaltiesEnabled): ?>
                            <td data-label="<?= e(t('dashboard.strike_reduction')) ?>"><?= (int) $week['strike_reduction'] > 0 ? '-1' : '-' ?></td>
                            <td data-label="<?= e(t('dashboard.strikes_after_week')) ?>"><?= e((string) $week['strikes_after_week']) ?></td>
                            <?php endif; ?>
                            <td data-label="<?= e(t('common.actions')) ?>">
                                <div class="dashboard-week-actions">
                                    <a class="btn btn-ghost small" href="<?= e($weekEditorHref) ?>"><?= e(t('dashboard.edit_week')) ?></a>
                                    <?php if ($penaltiesEnabled): ?>
                                    <a class="btn btn-ghost small" href="<?= e($weekStrikesHref) ?>"><?= e(t('dashboard.penalty_history')) ?></a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="dashboard-mobile-card-list dashboard-weekly-mobile-list dashboard-weekly-compact-list" aria-label="<?= e(t('dashboard.weekly_history')) ?>">
                <?php foreach ($selectedMetric['weekly'] as $week): ?>
                    <?php
                    $weekStartDate = (string) ($week['week_start'] ?? $selectedWeekStart ?? '');
                    $weekEditorHref = '/?' . http_build_query([
                        'page' => 'week_editor',
                        'user_id' => (int) ($selectedUser['id'] ?? 0),
                        'week' => date_to_iso_week($weekStartDate !== '' ? $weekStartDate : (string) $selectedWeekStart),
                    ]);
                    $weeklyPenalty = (float) ($week['penalty'] ?? 0);
                    ?>
                    <a class="dashboard-week-row" href="<?= e($weekEditorHref) ?>">
                        <span class="dashboard-week-row-main">
                            <strong><?= e(format_date_eu((string) $week['week_start'])) ?></strong>
                            <small><?= e(label_for_status((string) $week['status'])) ?></small>
                        </span>
                        <span class="dashboard-week-row-meta">
                            <span><?= e(t('metric.warnings')) ?> <?= e((string) ($week['skip_warnings'] ?? 0)) ?></span>
                            <?php if ($penaltiesEnabled): ?>
                            <span class="penalty-chip penalty-chip-<?= e($penaltySeverityClass((int) $weeklyPenalty)) ?>">&euro;<?= e(number_format($weeklyPenalty, 2, '.', '')) ?></span>
                            <?php else: ?>
                            <span><?= e(t('metric.step_failures')) ?> <?= e((string) ($week['step_failures'] ?? 0)) ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="settings-chevron" aria-hidden="true">&gt;</span>
                    </a>
                <?php endforeach; ?>
            </div>
        </article>
        <?php endif; ?>

        <?php if ($showWidget('approvals')): ?>
        <article class="panel dashboard-panel dashboard-approvals" data-testid="pending-approvals" style="order: <?= $contentWidgetOrder('approvals') ?>">
            <div class="panel-head dashboard-panel-head-compact">
                <div>
                    <p class="eyebrow"><?= e(t('dashboard.approvals_pending_eyebrow')) ?></p>
                </div>
                <span class="badge"><?= count($pendingApprovals) ?></span>
            </div>
            <?php if ($pendingApprovals === []): ?>
                <p class="muted"><?= e(t('dashboard.approvals_empty')) ?></p>
            <?php else: ?>
                <div class="pending-grid">
                    <?php foreach ($pendingApprovals as $approval): ?>
                        <form method="post" action="/?page=dashboard" class="pending-card" data-testid="pending-approval-item">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="resolve_approval">
                            <input type="hidden" name="approval_id" value="<?= (int) $approval['id'] ?>">
                            <input type="hidden" name="redirect_user_id" value="<?= (int) $selectedUser['id'] ?>">
                            <input type="hidden" name="redirect_week_start" value="<?= e((string) $selectedWeekStart) ?>">

                            <div class="pending-head">
                                <strong><?= e((string) $approval['approval_type_label']) ?></strong>
                                <span><?= e((string) $approval['owner_name']) ?> · <?= e(format_date_eu((string) $approval['log_date'])) ?></span>
                            </div>
                            <p class="muted"><?= e((string) ($approval['detail'] ?: t('dashboard.no_detail'))) ?></p>
                            <p class="small muted pending-requested-by"><?= e(t('dashboard.requested_by')) ?>: <?= e((string) $approval['requested_by_name']) ?></p>

                            <label>
                                <?= e(t('common.optional_comment')) ?>
                                <input type="text" name="decision_note" placeholder="<?= e(t('dashboard.decision_placeholder')) ?>">
                            </label>

                            <div class="pending-actions">
                                <button class="btn btn-primary" type="submit" name="decision" value="approve" data-testid="approval-approve"><?= e(t('common.approve')) ?></button>
                                <button class="btn btn-ghost" type="submit" name="decision" value="reject" data-testid="approval-reject"><?= e(t('common.reject')) ?></button>
                            </div>
                        </form>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </article>
        <?php endif; ?>


        <?php if ($showWidget('ranking')): ?>
        <article class="panel dashboard-panel" style="order: <?= $contentWidgetOrder('ranking') ?>">
            <h2><?= e(t('dashboard.ranking')) ?></h2>
            <div class="leaderboard-list">
                <?php foreach ($metricsOrdered as $metric): ?>
                    <?php
                    $leaderboardUser = is_array($metric['user'] ?? null) ? (array) $metric['user'] : [];
                    $leaderboardName = (string) ($leaderboardUser['display_name'] ?? t('common.user'));
                    $leaderboardAvatarUrl = avatar_url($leaderboardUser);
                    ?>
                    <article class="leaderboard-row">
                        <div class="leaderboard-name">
                            <?php if ($leaderboardAvatarUrl !== ''): ?>
                                <img class="member-avatar leaderboard-avatar" src="<?= e($leaderboardAvatarUrl) ?>" alt="<?= e($leaderboardName) ?>">
                            <?php else: ?>
                                <span class="member-avatar member-avatar-initials leaderboard-avatar"><?= e(initials_for($leaderboardName)) ?></span>
                            <?php endif; ?>
                            <span class="leaderboard-name-text">
                                <strong><?= e($leaderboardName) ?></strong>
                                <span><?= e(t('metric.warnings')) ?>: <?= e((string) ($metric['skip_warning_events'] ?? 0)) ?></span>
                            </span>
                        </div>
                        <div class="leaderboard-stats">
                            <span class="badge"><?= e(t('metric.score')) ?> <?= e((string) $metric['score']) ?></span>
                            <span class="badge"><?= e(t('metric.steps')) ?> <?= e((string) ($metric['total_steps'] ?? 0)) ?></span>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </article>
        <?php endif; ?>



    </div>
</section>
