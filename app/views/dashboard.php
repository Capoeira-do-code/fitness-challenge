<?php

declare(strict_types=1);

$selectedUser = $selectedMetric['user'];
$stepsSeries = array_values((array) ($selectedMetric['steps_series'] ?? []));
usort(
    $stepsSeries,
    static fn(array $left, array $right): int => strcmp((string) ($left['date'] ?? ''), (string) ($right['date'] ?? ''))
);
$rangeStartDate = null;
$rangeEndDate = null;
if (($dashboardView ?? '') !== 'total') {
    $rangeStartDate = to_date((string) ($selectedWeekStart ?? null), to_date(null));
    try {
        $rangeEndDate = (new DateTimeImmutable($rangeStartDate))->modify('+6 days')->format('Y-m-d');
    } catch (Throwable) {
        $rangeEndDate = $rangeStartDate;
    }
}
$stepsTail = [];
foreach ($stepsSeries as $row) {
    $rowDate = (string) ($row['date'] ?? '');
    if ($rowDate === '') {
        continue;
    }
    if ($rangeStartDate !== null && $rangeEndDate !== null && ($rowDate < $rangeStartDate || $rowDate > $rangeEndDate)) {
        continue;
    }
    $stepsTail[] = $row;
}
$stepsLabels = array_map(static fn(array $row): string => format_date_eu((string) ($row['date'] ?? '')), $stepsTail);
$stepsValues = array_map(static fn(array $row): int => (int) ($row['steps'] ?? 0), $stepsTail);
$stepsGoals = array_map(static fn(array $row): int => (int) ($row['goal'] ?? 0), $stepsTail);

$weightLabels = array_map(static fn(array $row): string => format_date_eu((string) $row['date']), $selectedMetric['weight_series']);
$weightValues = array_map(static fn(array $row): float => (float) $row['weight'], $selectedMetric['weight_series']);

$dashboardLayout = json_decode((string) ($currentUser['dashboard_layout_json'] ?? ''), true);
$visibleWidgets = [];
if (is_array($dashboardLayout) && $dashboardLayout !== []) {
    foreach ($dashboardLayout as $widget) {
        if (!is_string($widget) || $widget === '') {
            continue;
        }
        $normalizedWidget = $widget === 'money' ? 'distance_walked' : $widget;
        if (!in_array($normalizedWidget, $visibleWidgets, true)) {
            $visibleWidgets[] = $normalizedWidget;
        }
    }
}
if ($visibleWidgets === []) {
    $visibleWidgets = ['kpis', 'distance_walked', 'calories', 'approvals', 'steps', 'steps_cumulative', 'distance_cumulative', 'weight', 'comparison', 'ranking', 'meals', 'weekly'];
}
if (!in_array('meals', $visibleWidgets, true)) {
    $visibleWidgets[] = 'meals';
}
if (!in_array('steps_cumulative', $visibleWidgets, true)) {
    $visibleWidgets[] = 'steps_cumulative';
}
if (!in_array('distance_cumulative', $visibleWidgets, true)) {
    $visibleWidgets[] = 'distance_cumulative';
}
$showWidget = static fn(string $widget): bool => in_array($widget, $visibleWidgets, true);
$dashboardWidgets = ['kpis', 'distance_walked', 'calories', 'approvals', 'steps', 'steps_cumulative', 'distance_cumulative', 'weight', 'comparison', 'ranking', 'meals', 'weekly'];
$layoutOrder = array_flip($visibleWidgets);
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
$isTotalMoneyView = ($dashboardView ?? '') === 'total';
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

$distanceByDate = is_array($dashboardDistanceByDate ?? null) ? $dashboardDistanceByDate : [];
$distanceLabels = array_map(static fn(array $row): string => format_date_eu((string) ($row['date'] ?? '')), $stepsTail);
$distanceValues = array_map(
    static function (array $row) use ($distanceByDate): float {
        $date = (string) ($row['date'] ?? '');
        if ($date !== '' && isset($distanceByDate[$date])) {
            return round((float) $distanceByDate[$date], 2);
        }

        return round((float) ($row['km'] ?? 0), 2);
    },
    $stepsTail
);
$distanceRangeTotal = round(array_sum($distanceValues), 2);
$stepsCumulativeValues = [];
$distanceCumulativeValues = [];
$runningSteps = 0;
$runningDistance = 0.0;
foreach ($stepsTail as $row) {
    $date = (string) ($row['date'] ?? '');
    $stepValue = (int) ($row['steps'] ?? 0);
    $distanceValue = $date !== '' && isset($distanceByDate[$date])
        ? round((float) $distanceByDate[$date], 2)
        : round((float) ($row['km'] ?? 0), 2);
    $runningSteps += $stepValue;
    $runningDistance += $distanceValue;
    $stepsCumulativeValues[] = $runningSteps;
    $distanceCumulativeValues[] = round($runningDistance, 2);
}
$stepsCumulativeTotal = $stepsCumulativeValues !== [] ? $stepsCumulativeValues[count($stepsCumulativeValues) - 1] : 0;
$distanceCumulativeTotal = $distanceCumulativeValues !== [] ? $distanceCumulativeValues[count($distanceCumulativeValues) - 1] : 0.0;

$compareName = $compareMetric !== null ? (string) $compareMetric['user']['display_name'] : null;
$compareTitle = t('dashboard.compare', ['name' => $compareName !== null ? 'vs ' . $compareName : '']);
$comparisonHelpLabel = t('dashboard.comparison_help_label');
$comparisonHelpText = t('dashboard.comparison_help_text');
$viewSnapshot = is_array($selectedMetricSnapshot ?? null) ? (array) $selectedMetricSnapshot : [];
$compareSnapshot = is_array($compareMetricSnapshot ?? null) ? (array) $compareMetricSnapshot : [];

$compareBar = [
    'labels' => [t('metric.steps') . ' %', t('metric.workouts') . ' %', t('metric.score')],
    'datasets' => [
        [
            'label' => (string) $selectedUser['display_name'],
            'data' => [
                (float) ($viewSnapshot['step_completion_pct'] ?? $selectedMetric['step_completion_pct'] ?? 0),
                (float) ($viewSnapshot['workout_completion_pct'] ?? $selectedMetric['workout_completion_pct'] ?? 0),
                (float) ($viewSnapshot['score'] ?? $selectedMetric['score'] ?? 0),
            ],
        ],
    ],
];
if ($compareMetric !== null) {
    $compareBar['datasets'][] = [
        'label' => (string) $compareMetric['user']['display_name'],
        'data' => [
            (float) ($compareSnapshot['step_completion_pct'] ?? $compareMetric['step_completion_pct'] ?? 0),
            (float) ($compareSnapshot['workout_completion_pct'] ?? $compareMetric['workout_completion_pct'] ?? 0),
            (float) ($compareSnapshot['score'] ?? $compareMetric['score'] ?? 0),
        ],
    ];
}
$calorieStats = is_array($dashboardCalorieStats ?? null) ? (array) $dashboardCalorieStats : [];
$calorieSeries = array_values((array) ($calorieStats['series'] ?? []));
$calorieLabels = array_map(static fn(array $row): string => format_date_eu((string) ($row['date'] ?? '')), $calorieSeries);
$calorieConsumedValues = array_map(static fn(array $row): float => (float) ($row['consumed'] ?? 0), $calorieSeries);
$calorieBurnedValues = array_map(static fn(array $row): float => (float) ($row['burned'] ?? 0), $calorieSeries);
$calorieDeficitValues = array_map(static fn(array $row): float => (float) ($row['deficit'] ?? 0), $calorieSeries);
$formatCalories = static function (float $value): string {
    return number_format($value, 0, '.', '');
};
$calorieRangeStart = (string) ($dashboardCalorieRangeStart ?? to_date(null));
$calorieRangeEnd = (string) ($dashboardCalorieRangeEnd ?? $calorieRangeStart);
$calorieRangeText = t('common.from_to', ['start' => format_date_eu($calorieRangeStart), 'end' => format_date_eu($calorieRangeEnd)]);
$calorieConsumedTotal = (float) ($calorieStats['total_consumed'] ?? 0);
$calorieBurnedTotal = (float) ($calorieStats['total_burned'] ?? 0);
$calorieMaintenanceTotal = (float) ($calorieStats['maintenance_total'] ?? 0);
$calorieDeficitTotal = (float) ($calorieStats['deficit'] ?? 0);
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
$viewPenaltyLabel = '€' . number_format($viewPenalty, 2, '.', '');
$comparisonDetailHref = '/?' . http_build_query([
    'page' => 'comparison_detail',
    'user_id' => (int) ($selectedUser['id'] ?? 0),
    'view' => (string) ($dashboardView ?? 'current_week'),
]);

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
    [
        'key' => 'strikes',
        'label' => t('metric.strikes'),
        'value' => (string) $viewStrikes,
        'meta' => t('dashboard.accumulated_penalty', ['amount' => $viewPenaltyLabel]),
        'ring' => (string) $viewStrikes,
        'progress' => max(0, 100 - ($viewStrikes * 10)),
    ],
];
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

ob_start();
?>
<details class="topbar-context">
    <summary class="btn btn-ghost btn-topbar">Vista</summary>
    <div class="topbar-context-panel">
        <form method="get" class="stack">
        <input type="hidden" name="page" value="dashboard">
        <label>
            <?= e(t('dashboard.viewing')) ?>
            <select name="user_id" onchange="this.form.submit()" data-testid="dashboard-user-select">
                <?php foreach ($users as $user): ?>
                    <option value="<?= (int) $user['id'] ?>" <?= (int) $user['id'] === (int) $selectedUser['id'] ? 'selected' : '' ?>>
                        <?= e((string) $user['display_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <?= e(t('dashboard.view_mode')) ?>
            <select name="view" onchange="this.form.submit()" data-testid="dashboard-week-select">
                <option value="current_week" <?= ($dashboardView ?? '') === 'current_week' ? 'selected' : '' ?>><?= e(t('dashboard.current_week')) ?></option>
                <option value="total" <?= ($dashboardView ?? '') === 'total' ? 'selected' : '' ?>><?= e(t('metric.total')) ?></option>
                <?php foreach ($weekOptions as $weekStart): ?>
                    <option value="<?= e($weekStart) ?>" <?= ($dashboardView ?? '') === $weekStart ? 'selected' : '' ?>><?= e(format_date_eu($weekStart)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        </form>
        <details class="inline-context-sub">
            <summary class="btn btn-ghost btn-block"><?= e(t('dashboard.edit_layout')) ?></summary>
            <form method="post" action="/?page=dashboard" class="stack">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="save_dashboard_layout">
                <input type="hidden" name="dashboard_view" value="<?= e((string) ($dashboardView ?? 'current_week')) ?>">
                <div class="chip-group">
                    <?php foreach ($dashboardWidgets as $widget): ?>
                        <label class="chip">
                            <input type="checkbox" name="dashboard_widgets[]" value="<?= e($widget) ?>" <?= in_array($widget, $visibleWidgets, true) ? 'checked' : '' ?>>
                            <?= e(t('dashboard.widget_' . $widget)) ?>
                            <input type="number" name="dashboard_order[<?= e($widget) ?>]" value="<?= e((string) (($layoutOrder[$widget] ?? array_search($widget, $dashboardWidgets, true)) + 1)) ?>" min="1" max="<?= count($dashboardWidgets) ?>">
                        </label>
                    <?php endforeach; ?>
                </div>
                <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
            </form>
        </details>
    </div>
</details>
<?php
$topbarControls = ob_get_clean();
?>
<section class="screen stack-lg">
    <div class="motivation-band">
        <span><?= e(t('dashboard.motivation')) ?></span>
        <strong>"<?= e((string) ($motivationQuote ?? t('dashboard.default_quote'))) ?>"</strong>
    </div>

    <div class="dashboard-layout">
        <?php if ($showWidget('kpis')): ?>
        <div class="metric-grid dashboard-span-full dashboard-kpis" style="order: <?= $widgetOrder('kpis') ?>">
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

        <?php if ($showWidget('distance_walked')): ?>
        <article class="panel chart-card dashboard-panel" style="order: <?= $widgetOrder('distance_walked') ?>">
            <h2>Distance walked</h2>
            <canvas id="distanceWalkedChart" height="150"></canvas>
            <p class="muted small"><?= e(t('metric.distance_km')) ?> · <?= e((string) $distanceRangeTotal) ?> km</p>
        </article>
        <?php endif; ?>

        <?php if ($showWidget('calories')): ?>
        <article class="panel chart-card dashboard-panel dashboard-calories" style="order: <?= $widgetOrder('calories') ?>">
            <div class="panel-head">
                <div>
                    <h2><?= e(t('dashboard.calories_title')) ?></h2>
                    <p class="muted small"><?= e($calorieRangeText) ?></p>
                </div>
                <span class="badge"><?= e((string) ($calorieStats['tracked_days'] ?? 0)) ?> <?= e(t('dashboard.calories_tracked_days')) ?></span>
            </div>
            <div class="calories-overview">
                <div class="metric-box">
                    <span class="metric-title"><?= e(t('dashboard.calories_maintenance')) ?></span>
                    <strong class="metric-value"><?= e($formatCalories($calorieMaintenanceTotal)) ?> kcal</strong>
                </div>
                <div class="metric-box">
                    <span class="metric-title"><?= e(t('dashboard.calories_deficit')) ?></span>
                    <strong class="metric-value"><?= e($formatCalories($calorieDeficitTotal)) ?> kcal</strong>
                </div>
            </div>
            <?php if ($calorieSeries === []): ?>
                <p class="muted"><?= e(t('dashboard.no_calorie_data')) ?></p>
            <?php else: ?>
                <canvas id="calorieChart" height="150"></canvas>
            <?php endif; ?>
            <div class="panel-head panel-head-help">
                <span class="muted small"><?= e(t('dashboard.calories_hint_label')) ?></span>
                <details class="metric-help-popover">
                    <summary aria-label="<?= e(t('dashboard.calories_hint_label')) ?>" title="<?= e(t('dashboard.calories_hint_label')) ?>">?</summary>
                    <div class="metric-help-popover-content"><?= e(t('dashboard.calories_hint')) ?></div>
                </details>
            </div>
        </article>
        <?php endif; ?>

        <?php if ($showWidget('approvals')): ?>
        <article class="panel dashboard-panel dashboard-approvals" data-testid="pending-approvals" style="order: <?= $widgetOrder('approvals') ?>">
            <div class="panel-head">
                <div>
                    <p class="eyebrow"><?= e(t('common.pending')) ?></p>
                    <h2><?= e(t('dashboard.approvals_title')) ?></h2>
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
                            <p class="small muted"><?= e(t('dashboard.requested_by')) ?>: <?= e((string) $approval['requested_by_name']) ?></p>

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

        <?php if ($showWidget('steps')): ?>
        <article class="panel chart-card dashboard-panel" style="order: <?= $widgetOrder('steps') ?>">
            <h2><?= e(t('dashboard.steps_chart')) ?></h2>
            <canvas id="stepsChart" height="150"></canvas>
        </article>
        <?php endif; ?>

        <?php if ($showWidget('steps_cumulative')): ?>
        <article class="panel chart-card dashboard-panel" style="order: <?= $widgetOrder('steps_cumulative') ?>">
            <h2><?= e(t('dashboard.steps_cumulative_chart')) ?></h2>
            <?php if ($stepsCumulativeValues === []): ?>
                <p class="muted"><?= e(t('common.none')) ?></p>
            <?php else: ?>
                <canvas id="stepsCumulativeChart" height="150"></canvas>
                <p class="muted small"><?= e(t('metric.current_value')) ?>: <?= e((string) $stepsCumulativeTotal) ?></p>
            <?php endif; ?>
        </article>
        <?php endif; ?>

        <?php if ($showWidget('distance_cumulative')): ?>
        <article class="panel chart-card dashboard-panel" style="order: <?= $widgetOrder('distance_cumulative') ?>">
            <h2><?= e(t('dashboard.distance_cumulative_chart')) ?></h2>
            <?php if ($distanceCumulativeValues === []): ?>
                <p class="muted"><?= e(t('common.none')) ?></p>
            <?php else: ?>
                <canvas id="distanceCumulativeChart" height="150"></canvas>
                <p class="muted small"><?= e(t('metric.current_value')) ?>: <?= e(number_format((float) $distanceCumulativeTotal, 2, '.', '')) ?> km</p>
            <?php endif; ?>
        </article>
        <?php endif; ?>

        <?php if ($showWidget('weight')): ?>
        <article class="panel chart-card dashboard-panel" style="order: <?= $widgetOrder('weight') ?>">
            <h2><?= e(t('dashboard.weight_chart')) ?></h2>
            <?php if ($weightValues === []): ?>
                <p class="muted"><?= e(t('dashboard.no_weight')) ?></p>
            <?php else: ?>
                <canvas id="weightChart" height="150"></canvas>
                <?php if ($selectedMetric['ideal_weight'] !== null): ?>
                    <p class="muted">
                        <?= e(t('metric.goal')) ?>: <?= e((string) $selectedMetric['ideal_weight']) ?> kg ·
                        <?= e(t('metric.progress')) ?>: <?= e((string) ($selectedMetric['weight_progress_pct'] ?? 0)) ?>%
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        </article>
        <?php endif; ?>

        <?php if ($showWidget('comparison')): ?>
        <article class="panel chart-card dashboard-panel" style="order: <?= $widgetOrder('comparison') ?>">
            <div class="panel-head panel-head-help">
                <h2><?= e(trim($compareTitle)) ?></h2>
                <details class="metric-help-popover">
                    <summary aria-label="<?= e($comparisonHelpLabel) ?>" title="<?= e($comparisonHelpLabel) ?>">?</summary>
                    <div class="metric-help-popover-content">
                        <p><?= e($comparisonHelpText) ?></p>
                        <p class="small">
                            Score = (steps_weight x steps_progress) + (workouts_weight x workouts_progress) + (discipline_weight x discipline_score) + (weight_weight x weight_progress_if_available)
                        </p>
                        <a class="btn btn-ghost small btn-block" href="<?= e($comparisonDetailHref) ?>"><?= e(t('dashboard.view_full_breakdown')) ?></a>
                    </div>
                </details>
            </div>
            <canvas id="compareChart" height="170"></canvas>
        </article>
        <?php endif; ?>

        <?php if ($showWidget('ranking')): ?>
        <article class="panel dashboard-panel" style="order: <?= $widgetOrder('ranking') ?>">
            <h2><?= e(t('dashboard.ranking')) ?></h2>
            <div class="leaderboard-list">
                <?php foreach ($metricsOrdered as $metric): ?>
                    <?php
                    $rankingStrikesHref = '/?' . http_build_query([
                        'page' => 'strikes_detail',
                        'user_id' => (int) ($metric['user']['id'] ?? 0),
                        'view' => (string) ($dashboardView ?? 'current_week'),
                    ]);
                    ?>
                    <article class="leaderboard-row">
                        <div class="leaderboard-name">
                            <strong><?= e((string) $metric['user']['display_name']) ?></strong>
                            <span><?= e(t('metric.warnings')) ?>: <?= e((string) ($metric['skip_warning_events'] ?? 0)) ?></span>
                        </div>
                        <div class="leaderboard-stats">
                            <span class="badge"><?= e(t('metric.score')) ?> <?= e((string) $metric['score']) ?></span>
                            <a class="badge" href="<?= e($rankingStrikesHref) ?>">
                                <?= e(t('metric.strikes')) ?> <?= e((string) $metric['current_strikes']) ?> · €<?= e(number_format((float) ($metric['total_penalty'] ?? 0), 2, '.', '')) ?>
                            </a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </article>
        <?php endif; ?>

        <?php if ($showWidget('meals')): ?>
        <article class="panel dashboard-panel" style="order: <?= $widgetOrder('meals') ?>">
            <div class="panel-head">
                <div>
                    <p class="eyebrow"><?= e(t('common.week')) ?></p>
                    <h2><?= e(t('dashboard.meal_calendar')) ?></h2>
                </div>
                <form method="get" action="/" class="control-strip">
                    <input type="hidden" name="page" value="dashboard">
                    <input type="hidden" name="user_id" value="<?= (int) ($selectedUser['id'] ?? 0) ?>">
                    <input type="hidden" name="view" value="<?= e((string) ($dashboardView ?? 'current_week')) ?>">
                    <label class="entry-date-inline">
                        <?= e(t('common.date')) ?>
                        <input type="date" name="meal_date" value="<?= e((string) ($dashboardMealDate ?? to_date(null))) ?>" onchange="this.form.submit()">
                    </label>
                </form>
            </div>
            <?php if (($dashboardMealCalendar ?? []) === []): ?>
                <p class="muted"><?= e(t('entries.no_photos')) ?></p>
            <?php else: ?>
                <div class="meal-calendar entries-calendar">
                    <?php foreach (($dashboardMealCalendar ?? []) as $dateKey => $day): ?>
                        <?php
                        $photoCount = (int) ($day['count'] ?? 0);
                        $hasLog = $photoCount > 0;
                        $preview = $day['preview'] ?? null;
                        $previewUrl = is_array($preview) ? media_url((string) ($preview['file_path'] ?? '')) : '';
                        $previewPhotoId = is_array($preview) ? (int) ($preview['id'] ?? 0) : 0;
                        $previewHref = $previewPhotoId > 0
                            ? '/?page=photo&photo_id=' . $previewPhotoId
                            : '/?page=entries&mode=meal&date=' . rawurlencode((string) $dateKey);
                        ?>
                        <a class="entries-calendar-day<?= $hasLog ? ' has-log' : '' ?>" href="<?= e($previewHref) ?>">
                            <article>
                                <strong><?= e(format_date_eu((string) $dateKey)) ?></strong>
                                <?php if ($previewUrl !== ''): ?>
                                    <img src="<?= e($previewUrl) ?>" alt="<?= e(t('common.photo')) ?>">
                                <?php else: ?>
                                    <div class="entries-calendar-empty"><?= e(t('entries.no_photo')) ?></div>
                                <?php endif; ?>
                                <span class="badge"><?= $photoCount ?> <?= e($photoCount === 1 ? t('entries.photo_singular') : t('entries.photo_plural')) ?></span>
                            </article>
                        </a>
                    <?php endforeach; ?>
                </div>
                <div class="panel-inline-empty chip-group">
                    <a class="btn btn-ghost" href="/?page=entries&mode=calendar&calendar_view=month&date=<?= e((string) ($dashboardMealDate ?? to_date(null))) ?>"><?= e(t('calendar.view_month')) ?></a>
                    <a class="btn btn-ghost" href="/?page=entries&mode=calendar&calendar_view=week&date=<?= e((string) ($dashboardMealDate ?? to_date(null))) ?>"><?= e(t('calendar.view_week')) ?></a>
                    <a class="btn btn-ghost" href="/?page=entries&mode=calendar&calendar_view=day&date=<?= e((string) ($dashboardMealDate ?? to_date(null))) ?>"><?= e(t('calendar.view_day')) ?></a>
                </div>
            <?php endif; ?>
        </article>
        <?php endif; ?>

        <?php if ($showWidget('weekly')): ?>
        <article class="panel dashboard-panel dashboard-span-full dashboard-weekly-history" style="order: 999">
            <div class="panel-head">
                <h2><?= e(t('dashboard.weekly_history')) ?></h2>
                <a class="btn btn-ghost" href="/?page=week_editor&user_id=<?= (int) $selectedUser['id'] ?>&week=<?= e(date_to_iso_week((string) $selectedWeekStart)) ?>"><?= e(t('table.open_editor')) ?></a>
            </div>
            <div class="table-wrap">
                <table class="table compact">
                    <thead>
                    <tr>
                        <th><?= e(t('common.week')) ?></th>
                        <th><?= e(t('common.status')) ?></th>
                        <th><?= e(t('metric.step_failures')) ?></th>
                        <th><?= e(t('metric.workout_failures')) ?></th>
                        <th><?= e(t('metric.warnings')) ?></th>
                        <th><?= e(t('strikes.economic_impact')) ?></th>
                        <th><?= e(t('dashboard.strike_reduction')) ?></th>
                        <th><?= e(t('dashboard.strikes_after_week')) ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($selectedMetric['weekly'] as $week): ?>
                        <?php
                        $weekStrikesHref = '/?' . http_build_query([
                            'page' => 'strikes_detail',
                            'user_id' => (int) ($selectedUser['id'] ?? 0),
                            'view' => (string) ($week['week_start'] ?? ''),
                        ]);
                        ?>
                        <tr onclick="window.location='<?= e($weekStrikesHref) ?>'" style="cursor:pointer;">
                            <td><a href="<?= e($weekStrikesHref) ?>"><?= e(format_date_eu((string) $week['week_start'])) ?> -> <?= e(format_date_eu((string) $week['week_end'])) ?></a></td>
                            <td><?= e(label_for_status((string) $week['status'])) ?></td>
                            <td><?= e((string) $week['step_failures']) ?></td>
                            <td><?= e((string) $week['workout_failures']) ?></td>
                            <td><?= e((string) ($week['skip_warnings'] ?? 0)) ?></td>
                            <td><a href="<?= e($weekStrikesHref) ?>" class="penalty-chip penalty-chip-<?= e($penaltySeverityClass((int) ($week['penalty'] ?? 0))) ?>">€<?= e(number_format((float) ($week['penalty'] ?? 0), 2, '.', '')) ?></a></td>
                            <td><?= (int) $week['strike_reduction'] > 0 ? '-1' : '-' ?></td>
                            <td><?= e((string) $week['strikes_after_week']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </article>
        <?php endif; ?>

        <article class="panel dashboard-panel dashboard-settlement dashboard-span-full" data-testid="settlement-panel" style="order: 9999">
            <div class="panel-head">
                <div>
                    <p class="eyebrow"><?= e(t('metric.strikes')) ?></p>
                    <h2><?= e(t('dashboard.strikes_summary_title')) ?></h2>
                    <p class="muted small"><?= e(t('strikes.lower_better_hint')) ?></p>
                </div>
                <?php if ($isTotalMoneyView): ?>
                    <span class="badge"><?= e(t('metric.total')) ?></span>
                <?php elseif (!empty($settlementSummary['is_provisional'])): ?>
                    <span class="badge badge-warn"><?= e(t('common.provisional_week')) ?></span>
                <?php else: ?>
                    <span class="badge badge-ok"><?= e(t('common.closed_week')) ?></span>
                <?php endif; ?>
            </div>
            <p class="muted">
                <?php if ($isTotalMoneyView): ?>
                    <a href="<?= e($strikesHref) ?>" class="penalty-link-inline"><?= e(t('dashboard.accumulated_penalty', ['amount' => '€' . number_format((float) ($selectedMetric['total_penalty'] ?? 0), 2, '.', '')])) ?></a>
                <?php else: ?>
                    <a href="<?= e($strikesHref) ?>" class="penalty-link-inline"><?= e(t('dashboard.settlement_hint', ['week' => format_date_eu((string) $selectedWeekStart), 'amount' => '€' . number_format((float) ($settlementSummary['total_penalty'] ?? 0), 2, '.', '')])) ?></a>
                <?php endif; ?>
            </p>

            <div class="table-wrap compact-wrap">
                <table class="table compact">
                    <?php if ($isTotalMoneyView): ?>
                    <thead>
                    <tr>
                        <th><?= e(t('common.user')) ?></th>
                        <th><?= e(t('metric.strikes')) ?></th>
                        <th><?= e(t('metric.skip_warnings')) ?></th>
                        <th><?= e(t('strikes.economic_impact')) ?></th>
                        <th><?= e(t('metric.score')) ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($metricsOrdered as $metric): ?>
                        <?php
                        $penaltyValue = (int) ($metric['total_penalty'] ?? 0);
                        $metricStrikesHref = '/?' . http_build_query([
                            'page' => 'strikes_detail',
                            'user_id' => (int) ($metric['user']['id'] ?? 0),
                            'view' => (string) ($dashboardView ?? 'current_week'),
                        ]);
                        ?>
                        <tr onclick="window.location='<?= e($metricStrikesHref) ?>'" style="cursor:pointer;">
                            <td><?= e((string) $metric['user']['display_name']) ?></td>
                            <td><?= e((string) $metric['current_strikes']) ?></td>
                            <td><?= e((string) ($metric['skip_warning_events'] ?? 0)) ?></td>
                            <td><a href="<?= e($metricStrikesHref) ?>" class="penalty-chip penalty-chip-<?= e($penaltySeverityClass($penaltyValue)) ?>">€<?= e(number_format((float) $penaltyValue, 2, '.', '')) ?></a></td>
                            <td><?= e((string) $metric['score']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <?php else: ?>
                    <thead>
                    <tr>
                        <th><?= e(t('common.user')) ?></th>
                        <th><?= e(t('metric.step_failures')) ?></th>
                        <th><?= e(t('metric.workout_failures')) ?></th>
                        <th><?= e(t('metric.skip_warnings')) ?></th>
                        <th><?= e(t('strikes.economic_impact')) ?></th>
                        <th><?= e(t('common.status')) ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach (($settlementSummary['entries'] ?? []) as $entry): ?>
                        <?php
                        $penaltyValue = (int) ($entry['penalty'] ?? 0);
                        $entryStrikesHref = '/?' . http_build_query([
                            'page' => 'strikes_detail',
                            'user_id' => (int) ($entry['user_id'] ?? 0),
                            'view' => (string) ($selectedWeekStart ?? 'current_week'),
                        ]);
                        ?>
                        <tr onclick="window.location='<?= e($entryStrikesHref) ?>'" style="cursor:pointer;">
                            <td><?= e((string) $entry['display_name']) ?></td>
                            <td><?= e((string) $entry['step_failures']) ?></td>
                            <td><?= e((string) $entry['workout_failures']) ?></td>
                            <td><?= e((string) $entry['skip_warnings']) ?></td>
                            <td><a href="<?= e($entryStrikesHref) ?>" class="penalty-chip penalty-chip-<?= e($penaltySeverityClass($penaltyValue)) ?>">€<?= e(number_format((float) $penaltyValue, 2, '.', '')) ?></a></td>
                            <td><?= e(label_for_status((string) $entry['status'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <?php endif; ?>
                </table>
            </div>
        </article>
    </div>
</section>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
(function () {
    const stepsCtx = document.getElementById('stepsChart');
    if (stepsCtx) {
        new Chart(stepsCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode($stepsLabels) ?>,
                datasets: [
                    {
                        label: <?= json_encode(t('metric.steps')) ?>,
                        data: <?= json_encode($stepsValues) ?>,
                        borderColor: '#14a38b',
                        backgroundColor: 'rgba(20, 163, 139, 0.16)',
                        tension: 0.35,
                        fill: true,
                    },
                    {
                        label: <?= json_encode(t('metric.goal')) ?>,
                        data: <?= json_encode($stepsGoals) ?>,
                        borderColor: '#ff6b4a',
                        borderDash: [6, 4],
                        pointRadius: 0,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } }
            }
        });
    }

    const distanceCtx = document.getElementById('distanceWalkedChart');
    if (distanceCtx) {
        new Chart(distanceCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode($distanceLabels) ?>,
                datasets: [
                    {
                        label: <?= json_encode(t('metric.distance_km')) ?>,
                        data: <?= json_encode($distanceValues) ?>,
                        borderColor: '#3b82f6',
                        backgroundColor: 'rgba(59, 130, 246, 0.16)',
                        tension: 0.35,
                        fill: true,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } }
            }
        });
    }

    const stepsCumulativeCtx = document.getElementById('stepsCumulativeChart');
    if (stepsCumulativeCtx) {
        new Chart(stepsCumulativeCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode($stepsLabels) ?>,
                datasets: [
                    {
                        label: <?= json_encode(t('dashboard.steps_cumulative_chart')) ?>,
                        data: <?= json_encode($stepsCumulativeValues) ?>,
                        borderColor: '#0f766e',
                        backgroundColor: 'rgba(15, 118, 110, 0.16)',
                        tension: 0.35,
                        fill: true,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } }
            }
        });
    }

    const distanceCumulativeCtx = document.getElementById('distanceCumulativeChart');
    if (distanceCumulativeCtx) {
        new Chart(distanceCumulativeCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode($distanceLabels) ?>,
                datasets: [
                    {
                        label: <?= json_encode(t('dashboard.distance_cumulative_chart')) ?>,
                        data: <?= json_encode($distanceCumulativeValues) ?>,
                        borderColor: '#1d4ed8',
                        backgroundColor: 'rgba(29, 78, 216, 0.14)',
                        tension: 0.35,
                        fill: true,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } }
            }
        });
    }

    const calorieCtx = document.getElementById('calorieChart');
    if (calorieCtx) {
        new Chart(calorieCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode($calorieLabels) ?>,
                datasets: [
                    {
                        label: <?= json_encode(t('dashboard.calories_consumed')) ?>,
                        data: <?= json_encode($calorieConsumedValues) ?>,
                        borderColor: '#ef4444',
                        backgroundColor: 'rgba(239, 68, 68, 0.16)',
                        tension: 0.3,
                        fill: true,
                    },
                    {
                        label: <?= json_encode(t('dashboard.calories_burned')) ?>,
                        data: <?= json_encode($calorieBurnedValues) ?>,
                        borderColor: '#2563eb',
                        backgroundColor: 'rgba(37, 99, 235, 0.14)',
                        tension: 0.3,
                        fill: true,
                    },
                    {
                        label: <?= json_encode(t('dashboard.calories_deficit')) ?>,
                        data: <?= json_encode($calorieDeficitValues) ?>,
                        borderColor: '#059669',
                        backgroundColor: 'rgba(5, 150, 105, 0.12)',
                        tension: 0.3,
                        fill: false,
                    },
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } }
            }
        });
    }

    const weightCtx = document.getElementById('weightChart');
    if (weightCtx) {
        new Chart(weightCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode($weightLabels) ?>,
                datasets: [{
                    label: <?= json_encode(t('metric.weight') . ' (kg)') ?>,
                    data: <?= json_encode($weightValues) ?>,
                    borderColor: '#22313f',
                    backgroundColor: 'rgba(34, 49, 63, 0.12)',
                    tension: 0.2,
                    fill: true,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } }
            }
        });
    }

    const compareCtx = document.getElementById('compareChart');
    if (compareCtx) {
        new Chart(compareCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode($compareBar['labels']) ?>,
                datasets: <?= json_encode($compareBar['datasets']) ?>
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } },
                scales: { y: { beginAtZero: true, max: 100 } }
            }
        });
    }
})();
</script>
