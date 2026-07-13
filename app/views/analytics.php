<?php

declare(strict_types=1);

$selectedUser = is_array($selectedMetric['user'] ?? null) ? $selectedMetric['user'] : [];
$rangeStartDate = to_date((string) ($dashboardAnalyticsRangeStart ?? $selectedWeekStart ?? null), to_date(null));
$rangeEndDate = to_date((string) ($dashboardAnalyticsRangeEnd ?? $rangeStartDate), $rangeStartDate);
$analyticsPeriod = (string) ($dashboardAnalyticsPeriod ?? 'current_week');
$analyticsWeek = (string) ($dashboardAnalyticsWeek ?? $selectedWeekStart ?? to_date(null));
$analyticsMonth = (string) ($dashboardAnalyticsMonth ?? substr((string) ($analyticsWeek ?: to_date(null)), 0, 7));
$analyticsRangeText = t('common.from_to', ['start' => format_date_eu($rangeStartDate), 'end' => format_date_eu($rangeEndDate)]);

$analyticsLayout = normalize_analytics_layout_sections((string) ($currentUser['analytics_layout_json'] ?? ''));
$analyticsLayoutIndex = array_flip($analyticsLayout);
$analyticsLayoutEditMode = (string) ($_GET['layout_edit'] ?? '') === '1';
$analyticsLayoutSections = analytics_layout_sections_default();
$analyticsLayoutEditorSections = array_values(array_unique(array_merge($analyticsLayout, $analyticsLayoutSections)));
$showAnalyticsSection = static fn(string $section): bool => in_array($section, $analyticsLayout, true);
$analyticsSectionStyle = static function (string $section) use ($analyticsLayoutIndex): string {
    if (!isset($analyticsLayoutIndex[$section])) {
        return 'display:none; order:999;';
    }

    return 'order:' . (string) (((int) $analyticsLayoutIndex[$section] + 1) * 10) . ';';
};
$analyticsSectionLabels = [
    'summary' => t('analytics.section_summary'),
    'activity' => t('dashboard.analytics_activity'),
    'nutrition' => t('analytics.section_nutrition'),
    'food' => t('analytics.section_food'),
    'body' => t('analytics.section_body'),
    'comparison' => t('dashboard.analytics_comparison'),
];

$stepsSeries = array_values((array) ($selectedMetric['steps_series'] ?? []));
usort($stepsSeries, static fn(array $left, array $right): int => strcmp((string) ($left['date'] ?? ''), (string) ($right['date'] ?? '')));
$stepsTail = [];
foreach ($stepsSeries as $row) {
    $rowDate = (string) ($row['date'] ?? '');
    if ($rowDate === '' || $rowDate < $rangeStartDate || $rowDate > $rangeEndDate) {
        continue;
    }
    $stepsTail[] = $row;
}
$stepsLabels = array_map(static fn(array $row): string => format_date_eu((string) ($row['date'] ?? '')), $stepsTail);
$stepsValues = array_map(static fn(array $row): int => (int) ($row['steps'] ?? 0), $stepsTail);
$stepsGoals = array_map(static fn(array $row): int => (int) ($row['goal'] ?? 0), $stepsTail);

$distanceByDate = is_array($dashboardDistanceByDate ?? null) ? $dashboardDistanceByDate : [];
$distanceLabels = $stepsLabels;
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

$weightSeries = array_values(array_filter(
    (array) ($selectedMetric['weight_series'] ?? []),
    static function (array $row) use ($rangeStartDate, $rangeEndDate): bool {
        $rowDate = (string) ($row['date'] ?? '');

        return $rowDate !== '' && $rowDate >= $rangeStartDate && $rowDate <= $rangeEndDate;
    }
));
$weightLabels = array_map(static fn(array $row): string => format_date_eu((string) $row['date']), $weightSeries);
$weightValues = array_map(static fn(array $row): float => (float) $row['weight'], $weightSeries);
$latestWeight = $weightValues !== [] ? (float) $weightValues[count($weightValues) - 1] : (float) ($selectedMetric['latest_weight'] ?? 0);

$viewSnapshot = is_array($selectedMetricSnapshot ?? null) ? (array) $selectedMetricSnapshot : [];
$compareSnapshot = is_array($compareMetricSnapshot ?? null) ? (array) $compareMetricSnapshot : [];
$compareName = $compareMetric !== null ? (string) $compareMetric['user']['display_name'] : null;
$compareTitle = t('dashboard.compare', ['name' => $compareName !== null ? 'vs ' . $compareName : '']);
$comparisonDetailHref = '/?' . http_build_query([
    'page' => 'comparison_detail',
    'user_id' => (int) ($selectedUser['id'] ?? 0),
    'view' => $analyticsPeriod === 'total' ? 'total' : $rangeStartDate,
]);
$compareBar = [
    'labels' => [t('metric.steps') . ' %', t('metric.workouts') . ' %', t('metric.score')],
    'datasets' => [
        [
            'label' => (string) ($selectedUser['display_name'] ?? t('common.user')),
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
$formatCalories = static fn(float $value): string => number_format($value, 0, '.', '');
$formatDecimal = static function (float $value, int $decimals = 1): string {
    $formatted = number_format($value, $decimals, '.', '');
    $formatted = rtrim(rtrim($formatted, '0'), '.');

    return $formatted === '' ? '0' : $formatted;
};
$calorieRangeText = t('common.from_to', ['start' => format_date_eu((string) ($dashboardCalorieRangeStart ?? $rangeStartDate)), 'end' => format_date_eu((string) ($dashboardCalorieRangeEnd ?? $rangeEndDate))]);
$calorieMaintenanceTotal = (float) ($calorieStats['maintenance_total'] ?? 0);
$calorieConsumedTotal = (float) ($calorieStats['total_consumed'] ?? 0);
$calorieBurnedTotal = (float) ($calorieStats['total_burned'] ?? 0);
$calorieDeficitTotal = (float) ($calorieStats['deficit'] ?? 0);

$foodStats = is_array($analyticsFoodStats ?? null) ? (array) $analyticsFoodStats : [];
$foodTotals = is_array($foodStats['totals'] ?? null) ? (array) $foodStats['totals'] : [];
$foodCategoryRows = array_values((array) ($foodStats['categories'] ?? []));
$categoryLabels = [
    'breakfast' => t('entries.breakfast'),
    'lunch' => t('entries.lunch'),
    'dinner' => t('entries.dinner'),
    'other' => t('common.other'),
    'meal' => t('entries.lunch'),
    'workout' => t('entries.workout'),
];
$macroValues = [
    round((float) ($foodTotals['protein_g'] ?? 0), 1),
    round((float) ($foodTotals['carbs_g'] ?? 0), 1),
    round((float) ($foodTotals['fat_g'] ?? 0), 1),
    round((float) ($foodTotals['fiber_g'] ?? 0), 1),
    round((float) ($foodTotals['sugar_g'] ?? 0), 1),
];
$macroLabels = [
    t('entries.photo_protein'),
    t('entries.photo_carbs'),
    t('entries.photo_fat'),
    t('entries.photo_fiber'),
    t('entries.photo_sugar'),
];
$foodCategoryLabels = array_map(
    static function (array $row) use ($categoryLabels): string {
        $category = (string) ($row['category'] ?? 'other');

        return (string) ($categoryLabels[$category] ?? $category);
    },
    $foodCategoryRows
);
$foodCategoryCounts = array_map(static fn(array $row): int => (int) ($row['photo_count'] ?? 0), $foodCategoryRows);

$analyticsBaseQuery = [
    'page' => 'analytics',
    'user_id' => (int) ($selectedUser['id'] ?? 0),
    'analytics_period' => $analyticsPeriod,
    'analytics_week' => $analyticsWeek,
    'analytics_month' => $analyticsMonth,
];
$analyticsEditLayoutUrl = '/?' . http_build_query($analyticsBaseQuery + ['layout_edit' => '1']);
$analyticsCancelEditLayoutUrl = '/?' . http_build_query($analyticsBaseQuery);
$analyticsPeriodLabels = [
    'current_week' => t('dashboard.analytics_current_week'),
    'week' => t('dashboard.analytics_specific_week'),
    'month' => t('dashboard.analytics_month'),
    'total' => t('metric.total'),
];
$analyticsWeekPrev = $analyticsWeek;
$analyticsWeekNext = $analyticsWeek;
try {
    $analyticsWeekPrev = week_start_for((new DateTimeImmutable($analyticsWeek))->modify('-7 days'))->format('Y-m-d');
    $analyticsWeekNext = week_start_for((new DateTimeImmutable($analyticsWeek))->modify('+7 days'))->format('Y-m-d');
} catch (Throwable) {
    $analyticsWeekPrev = $analyticsWeek;
    $analyticsWeekNext = $analyticsWeek;
}
$analyticsMonthPrev = $analyticsMonth;
$analyticsMonthNext = $analyticsMonth;
try {
    $analyticsMonthPrev = (new DateTimeImmutable($analyticsMonth . '-01'))->modify('-1 month')->format('Y-m');
    $analyticsMonthNext = (new DateTimeImmutable($analyticsMonth . '-01'))->modify('+1 month')->format('Y-m');
} catch (Throwable) {
    $analyticsMonthPrev = $analyticsMonth;
    $analyticsMonthNext = $analyticsMonth;
}

$analyticsChartPayload = [
    'charts' => [
        [
            'id' => 'stepsChart',
            'type' => 'line',
            'labels' => $stepsLabels,
            'datasets' => [
                ['label' => t('metric.steps'), 'data' => $stepsValues, 'borderColor' => '#14a38b', 'backgroundColor' => 'rgba(20, 163, 139, 0.16)', 'tension' => 0.35, 'fill' => true],
                ['label' => t('metric.goal'), 'data' => $stepsGoals, 'borderColor' => '#ff6b4a', 'borderDash' => [6, 4], 'pointRadius' => 0],
            ],
        ],
        [
            'id' => 'distanceWalkedChart',
            'type' => 'line',
            'labels' => $distanceLabels,
            'datasets' => [
                ['label' => t('metric.distance_km'), 'data' => $distanceValues, 'borderColor' => '#3b82f6', 'backgroundColor' => 'rgba(59, 130, 246, 0.16)', 'tension' => 0.35, 'fill' => true],
            ],
        ],
        [
            'id' => 'stepsCumulativeChart',
            'type' => 'line',
            'labels' => $stepsLabels,
            'datasets' => [
                ['label' => t('dashboard.steps_cumulative_chart'), 'data' => $stepsCumulativeValues, 'borderColor' => '#0f766e', 'backgroundColor' => 'rgba(15, 118, 110, 0.16)', 'tension' => 0.35, 'fill' => true],
            ],
        ],
        [
            'id' => 'distanceCumulativeChart',
            'type' => 'line',
            'labels' => $distanceLabels,
            'datasets' => [
                ['label' => t('dashboard.distance_cumulative_chart'), 'data' => $distanceCumulativeValues, 'borderColor' => '#1d4ed8', 'backgroundColor' => 'rgba(29, 78, 216, 0.14)', 'tension' => 0.35, 'fill' => true],
            ],
        ],
        [
            'id' => 'calorieChart',
            'type' => 'line',
            'labels' => $calorieLabels,
            'datasets' => [
                ['label' => t('dashboard.calories_consumed'), 'data' => $calorieConsumedValues, 'borderColor' => '#ef4444', 'backgroundColor' => 'rgba(239, 68, 68, 0.16)', 'tension' => 0.3, 'fill' => true],
                ['label' => t('dashboard.calories_burned'), 'data' => $calorieBurnedValues, 'borderColor' => '#2563eb', 'backgroundColor' => 'rgba(37, 99, 235, 0.14)', 'tension' => 0.3, 'fill' => true],
                ['label' => t('dashboard.calories_deficit'), 'data' => $calorieDeficitValues, 'borderColor' => '#059669', 'backgroundColor' => 'rgba(5, 150, 105, 0.12)', 'tension' => 0.3, 'fill' => false],
            ],
        ],
        [
            'id' => 'macroChart',
            'type' => 'bar',
            'labels' => $macroLabels,
            'datasets' => [
                ['label' => t('analytics.macros'), 'data' => $macroValues, 'backgroundColor' => ['rgba(20, 163, 139, 0.55)', 'rgba(59, 130, 246, 0.5)', 'rgba(245, 158, 11, 0.55)', 'rgba(132, 204, 22, 0.5)', 'rgba(236, 72, 153, 0.45)']],
            ],
        ],
        [
            'id' => 'foodCategoryChart',
            'type' => 'bar',
            'labels' => $foodCategoryLabels,
            'datasets' => [
                ['label' => t('analytics.food_photos'), 'data' => $foodCategoryCounts, 'backgroundColor' => 'rgba(34, 197, 94, 0.42)', 'borderColor' => '#16a34a', 'borderWidth' => 1],
            ],
        ],
        [
            'id' => 'weightChart',
            'type' => 'line',
            'labels' => $weightLabels,
            'datasets' => [
                ['label' => t('metric.weight') . ' (kg)', 'data' => $weightValues, 'borderColor' => '#22313f', 'backgroundColor' => 'rgba(34, 49, 63, 0.12)', 'tension' => 0.2, 'fill' => true],
            ],
        ],
    ],
    'compare' => [
        'id' => 'compareChart',
        'labels' => $compareBar['labels'] ?? [],
        'datasets' => $compareBar['datasets'] ?? [],
    ],
];

$workoutValue = max(0, (int) ($viewSnapshot['workouts'] ?? 0));
$workoutTarget = max(0, (int) ($viewSnapshot['workout_target'] ?? 0));
$analyticsSummaryCards = [
    ['label' => t('metric.steps'), 'value' => number_format($stepsCumulativeTotal, 0, '.', ''), 'meta' => t('metric.current_value')],
    ['label' => t('metric.distance_km'), 'value' => $formatDecimal((float) $distanceRangeTotal, 2) . ' km', 'meta' => t('dashboard.distance_walked_chart')],
    ['label' => t('metric.workouts'), 'value' => $workoutTarget > 0 ? (string) $workoutValue . ' / ' . (string) $workoutTarget : (string) $workoutValue, 'meta' => t('metric.progress')],
    ['label' => t('analytics.food_photos'), 'value' => (string) ((int) ($foodStats['photo_count'] ?? 0)), 'meta' => t('analytics.meal_days') . ': ' . (string) ((int) ($foodStats['meal_days'] ?? 0))],
    ['label' => t('dashboard.calories_consumed'), 'value' => $formatCalories($calorieConsumedTotal) . ' kcal', 'meta' => t('dashboard.calories_title')],
    ['label' => t('dashboard.calories_burned'), 'value' => $formatCalories($calorieBurnedTotal) . ' kcal', 'meta' => t('entries.training_calories_burned')],
    ['label' => t('dashboard.calories_deficit'), 'value' => $formatCalories($calorieDeficitTotal) . ' kcal', 'meta' => t('dashboard.calories_hint_label')],
    ['label' => t('entries.photo_protein'), 'value' => $formatDecimal((float) ($foodTotals['protein_g'] ?? 0), 1) . 'g', 'meta' => t('analytics.macros')],
    ['label' => t('entries.photo_carbs'), 'value' => $formatDecimal((float) ($foodTotals['carbs_g'] ?? 0), 1) . 'g', 'meta' => t('analytics.macros')],
    ['label' => t('entries.photo_fat'), 'value' => $formatDecimal((float) ($foodTotals['fat_g'] ?? 0), 1) . 'g', 'meta' => t('analytics.macros')],
    ['label' => t('analytics.junk_days'), 'value' => (string) ((int) ($foodStats['junk_days'] ?? 0)), 'meta' => t('entries.junk_food')],
    ['label' => t('metric.weight'), 'value' => $latestWeight > 0 ? $formatDecimal($latestWeight, 1) . ' kg' : t('common.none'), 'meta' => t('dashboard.weight_chart')],
];

ob_start();
?>
<?php if ($analyticsLayoutEditMode): ?>
<button class="btn btn-primary btn-topbar" type="submit" form="analytics-layout-edit-form"><?= e(t('common.save')) ?></button>
<a class="btn btn-ghost btn-topbar" href="<?= e($analyticsCancelEditLayoutUrl) ?>"><?= e(t('common.back')) ?></a>
<?php else: ?>
<details class="topbar-context analytics-view-menu">
    <summary class="btn btn-ghost btn-topbar">View</summary>
    <div class="topbar-context-panel analytics-view-panel">
        <form method="get" action="/" class="analytics-controls analytics-filter-panel analytics-filter-panel-topbar" data-analytics-filter>
            <input type="hidden" name="page" value="analytics">
            <input type="hidden" name="analytics_period" value="<?= e($analyticsPeriod) ?>">
            <div class="analytics-viewing-summary">
                <span class="eyebrow"><?= e(t('dashboard.viewing')) ?></span>
                <strong><?= e((string) ($selectedUser['display_name'] ?? t('common.user'))) ?></strong>
                <small><?= e((string) ($analyticsPeriodLabels[$analyticsPeriod] ?? $analyticsPeriod)) ?> - <?= e($analyticsRangeText) ?></small>
            </div>
            <label class="analytics-user-filter">
                <?= e(t('dashboard.viewing')) ?>
                <select name="user_id">
                    <?php foreach ($users as $user): ?>
                        <option value="<?= (int) $user['id'] ?>" <?= (int) $user['id'] === (int) ($selectedUser['id'] ?? 0) ? 'selected' : '' ?>><?= e((string) $user['display_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="analytics-period-segments" role="group" aria-label="<?= e(t('dashboard.analytics_period')) ?>">
                <?php foreach ($analyticsPeriodLabels as $periodKey => $periodLabel): ?>
                    <a class="<?= $analyticsPeriod === $periodKey ? 'active' : '' ?>" href="/?<?= e(http_build_query(array_replace($analyticsBaseQuery, ['analytics_period' => $periodKey]))) ?>" data-analytics-period="<?= e($periodKey) ?>"><?= e((string) $periodLabel) ?></a>
                <?php endforeach; ?>
            </div>
            <?php if ($analyticsPeriod === 'week'): ?>
                <label class="analytics-date-filter"><?= e(t('common.week')) ?><input type="date" name="analytics_week" value="<?= e($analyticsWeek) ?>"></label>
            <?php else: ?>
                <input type="hidden" name="analytics_week" value="<?= e($analyticsWeek) ?>">
            <?php endif; ?>
            <?php if ($analyticsPeriod === 'month'): ?>
                <label class="analytics-date-filter"><?= e(t('dashboard.analytics_month')) ?><input type="month" name="analytics_month" value="<?= e($analyticsMonth) ?>"></label>
            <?php else: ?>
                <input type="hidden" name="analytics_month" value="<?= e($analyticsMonth) ?>">
            <?php endif; ?>
            <div class="analytics-nav-links">
                <?php if ($analyticsPeriod === 'week'): ?>
                    <div class="analytics-nav-group" aria-label="<?= e(t('common.week')) ?>">
                        <a class="btn btn-ghost small" href="/?<?= e(http_build_query(array_replace($analyticsBaseQuery, ['analytics_period' => 'week', 'analytics_week' => $analyticsWeekPrev]))) ?>">&larr; <?= e(t('common.previous')) ?></a>
                        <a class="btn btn-ghost small" href="/?<?= e(http_build_query(array_replace($analyticsBaseQuery, ['analytics_period' => 'week', 'analytics_week' => $analyticsWeekNext]))) ?>"><?= e(t('common.next')) ?> &rarr;</a>
                    </div>
                <?php elseif ($analyticsPeriod === 'month'): ?>
                    <div class="analytics-nav-group" aria-label="<?= e(t('dashboard.analytics_month')) ?>">
                        <a class="btn btn-ghost small" href="/?<?= e(http_build_query(array_replace($analyticsBaseQuery, ['analytics_period' => 'month', 'analytics_month' => $analyticsMonthPrev]))) ?>">&larr; <?= e(t('dashboard.analytics_prev_month')) ?></a>
                        <a class="btn btn-ghost small" href="/?<?= e(http_build_query(array_replace($analyticsBaseQuery, ['analytics_period' => 'month', 'analytics_month' => $analyticsMonthNext]))) ?>"><?= e(t('dashboard.analytics_next_month')) ?> &rarr;</a>
                    </div>
                <?php endif; ?>
                <a class="btn btn-ghost small analytics-dashboard-link" href="/?page=dashboard&user_id=<?= (int) ($selectedUser['id'] ?? 0) ?>"><?= e(t('nav.dashboard')) ?></a>
            </div>
            <button class="btn btn-primary small analytics-apply-btn" type="submit"><?= e(t('audit.filter')) ?></button>
        </form>
        <div class="analytics-view-panel-actions">
            <a class="btn btn-ghost btn-block analytics-layout-link" href="<?= e($analyticsEditLayoutUrl) ?>"><?= e(t('dashboard.edit_layout')) ?></a>
        </div>
    </div>
</details>
<?php endif; ?>
<?php
$topbarControls = ob_get_clean();
?>
<section class="screen stack-lg analytics-page" data-analytics-page>
    <?php if ($analyticsLayoutEditMode): ?>
        <?php // Same compact editor as Home: a sticky bar with the section list tucked
              // into a "visible widgets" disclosure. The old full panel was caught by the
              // mobile edit-mode blur rule, which made it unusable on a phone. ?>
        <div class="layout-editbar dashboard-layout-editbar analytics-layout-editbar">
            <form id="analytics-layout-edit-form" method="post" action="/?page=analytics" class="dashboard-layout-editor analytics-layout-editor" data-analytics-layout-editor>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="save_analytics_layout">
                <input type="hidden" name="redirect_user_id" value="<?= (int) ($selectedUser['id'] ?? 0) ?>">
                <input type="hidden" name="analytics_period" value="<?= e($analyticsPeriod) ?>">
                <input type="hidden" name="analytics_week" value="<?= e($analyticsWeek) ?>">
                <input type="hidden" name="analytics_month" value="<?= e($analyticsMonth) ?>">

                <div class="dashboard-editbar-row">
                    <p class="dashboard-editbar-hint">
                        <strong><?= e(t('dashboard.edit_layout')) ?></strong>
                        <small><?= e(t('analytics.layout_hint')) ?></small>
                    </p>
                    <div class="dashboard-editbar-actions">
                        <a class="btn btn-ghost small" href="<?= e($analyticsCancelEditLayoutUrl) ?>"><?= e(t('common.cancel')) ?></a>
                        <button class="btn btn-primary small" type="submit"><?= e(t('common.save')) ?></button>
                    </div>
                </div>

                <details class="dashboard-layout-visibility" open>
                    <summary><?= e(t('dashboard.visible_widgets')) ?></summary>
                    <div class="team-layout-editor-list analytics-layout-editor-list dashboard-layout-editor-list" data-analytics-layout-list>
                        <?php foreach ($analyticsLayoutEditorSections as $idx => $section): ?>
                            <div class="team-layout-editor-item analytics-layout-editor-item analytics-layout-edit-card" data-analytics-layout-item>
                                <div class="dashboard-layout-mobile-actions analytics-layout-mobile-actions">
                                    <button class="btn btn-ghost small" type="button" data-layout-move="up" aria-label="<?= e(t('common.previous')) ?>">&uarr;</button>
                                    <button class="btn btn-ghost small" type="button" data-layout-move="down" aria-label="<?= e(t('common.next')) ?>">&darr;</button>
                                </div>
                                <label class="dashboard-layout-toggle">
                                    <input type="checkbox" name="analytics_sections[]" value="<?= e($section) ?>" <?= $showAnalyticsSection((string) $section) ? 'checked' : '' ?>>
                                    <span><?= e((string) ($analyticsSectionLabels[$section] ?? $section)) ?></span>
                                </label>
                                <input type="hidden" name="analytics_order[<?= e($section) ?>]" value="<?= e((string) ($idx + 1)) ?>" data-analytics-order-input>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button class="btn btn-ghost small dashboard-editbar-reset" type="submit" name="reset_analytics_layout" value="1"><?= e(t('dashboard.reset_layout')) ?></button>
                </details>
            </form>
        </div>
    <?php endif; ?>

    <section class="analytics-section analytics-summary-section analytics-layout-item" style="<?= e($analyticsSectionStyle('summary')) ?>">
        <div class="analytics-section-title">
            <h2><?= e(t('analytics.section_summary')) ?></h2>
            <span class="badge"><?= e($analyticsRangeText) ?></span>
        </div>
        <div class="analytics-stat-grid">
            <?php foreach ($analyticsSummaryCards as $card): ?>
                <article class="metric-card analytics-stat-card">
                    <span><?= e((string) ($card['label'] ?? '')) ?></span>
                    <strong><?= e((string) ($card['value'] ?? '')) ?></strong>
                    <p><?= e((string) ($card['meta'] ?? '')) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="analytics-section analytics-layout-item" style="<?= e($analyticsSectionStyle('activity')) ?>">
        <div class="analytics-section-title">
            <h2><?= e(t('dashboard.analytics_activity')) ?></h2>
            <span class="badge"><?= e(t('metric.steps')) ?> / <?= e(t('metric.distance_km')) ?></span>
        </div>
        <div class="analytics-grid">
            <article class="chart-card analytics-chart-card">
                <h3><?= e(t('dashboard.distance_walked_chart')) ?></h3>
                <canvas id="distanceWalkedChart" height="150"></canvas>
                <p class="muted small"><?= e(t('metric.distance_km')) ?>: <?= e(number_format($distanceRangeTotal, 2, '.', '')) ?> km</p>
            </article>
            <article class="chart-card analytics-chart-card">
                <h3><?= e(t('dashboard.steps_chart')) ?></h3>
                <canvas id="stepsChart" height="150"></canvas>
            </article>
            <article class="chart-card analytics-chart-card">
                <h3><?= e(t('dashboard.steps_cumulative_chart')) ?></h3>
                <?php if ($stepsCumulativeValues === []): ?><p class="muted"><?= e(t('common.none')) ?></p><?php else: ?><canvas id="stepsCumulativeChart" height="150"></canvas><p class="muted small"><?= e(t('metric.current_value')) ?>: <?= e((string) $stepsCumulativeTotal) ?></p><?php endif; ?>
            </article>
            <article class="chart-card analytics-chart-card">
                <h3><?= e(t('dashboard.distance_cumulative_chart')) ?></h3>
                <?php if ($distanceCumulativeValues === []): ?><p class="muted"><?= e(t('common.none')) ?></p><?php else: ?><canvas id="distanceCumulativeChart" height="150"></canvas><p class="muted small"><?= e(t('metric.current_value')) ?>: <?= e(number_format((float) $distanceCumulativeTotal, 2, '.', '')) ?> km</p><?php endif; ?>
            </article>
        </div>
    </section>

    <section class="analytics-section analytics-layout-item" style="<?= e($analyticsSectionStyle('nutrition')) ?>">
        <div class="analytics-section-title">
            <h2><?= e(t('analytics.section_nutrition')) ?></h2>
            <span class="badge"><?= e((string) ($calorieStats['tracked_days'] ?? 0)) ?> <?= e(t('dashboard.calories_tracked_days')) ?></span>
        </div>
        <div class="analytics-grid">
            <article class="chart-card analytics-chart-card dashboard-calories">
                <div class="panel-head dashboard-calories-head">
                    <div>
                        <h3><?= e(t('dashboard.calories_title')) ?></h3>
                        <p class="muted small"><?= e($calorieRangeText) ?></p>
                    </div>
                </div>
                <div class="calories-overview">
                    <div class="metric-box"><span class="metric-title"><?= e(t('dashboard.calories_maintenance')) ?></span><strong class="metric-value"><?= e($formatCalories($calorieMaintenanceTotal)) ?> kcal</strong></div>
                    <div class="metric-box"><span class="metric-title"><?= e(t('dashboard.calories_deficit')) ?></span><strong class="metric-value"><?= e($formatCalories($calorieDeficitTotal)) ?> kcal</strong></div>
                </div>
                <?php if ($calorieSeries === []): ?><p class="muted"><?= e(t('dashboard.no_calorie_data')) ?></p><?php else: ?><canvas id="calorieChart" height="150"></canvas><?php endif; ?>
            </article>
            <article class="chart-card analytics-chart-card">
                <h3><?= e(t('analytics.macros')) ?></h3>
                <?php if (array_sum($macroValues) <= 0): ?>
                    <p class="muted"><?= e(t('analytics.no_food_data')) ?></p>
                <?php else: ?>
                    <canvas id="macroChart" height="150"></canvas>
                    <p class="muted small"><?= e(t('entries.photo_sodium')) ?>: <?= e($formatDecimal((float) ($foodTotals['sodium_mg'] ?? 0), 0)) ?> mg</p>
                <?php endif; ?>
            </article>
        </div>
    </section>

    <section class="analytics-section analytics-layout-item" style="<?= e($analyticsSectionStyle('food')) ?>">
        <div class="analytics-section-title">
            <h2><?= e(t('analytics.section_food')) ?></h2>
            <span class="badge"><?= e((string) ((int) ($foodStats['photo_count'] ?? 0))) ?> <?= e(t('entries.photo_plural')) ?></span>
        </div>
        <div class="analytics-grid">
            <article class="chart-card analytics-chart-card">
                <h3><?= e(t('analytics.food_categories')) ?></h3>
                <?php if ($foodCategoryRows === []): ?>
                    <p class="muted"><?= e(t('analytics.no_food_data')) ?></p>
                <?php else: ?>
                    <canvas id="foodCategoryChart" height="150"></canvas>
                <?php endif; ?>
            </article>
            <article class="chart-card analytics-chart-card analytics-food-breakdown">
                <h3><?= e(t('analytics.food_breakdown')) ?></h3>
                <div class="analytics-food-list">
                    <?php if ($foodCategoryRows === []): ?>
                        <p class="muted"><?= e(t('analytics.no_food_data')) ?></p>
                    <?php else: ?>
                        <?php foreach ($foodCategoryRows as $row): ?>
                            <?php $category = (string) ($row['category'] ?? 'other'); ?>
                            <div>
                                <span><?= e((string) ($categoryLabels[$category] ?? $category)) ?></span>
                                <strong><?= e((string) ((int) ($row['photo_count'] ?? 0))) ?></strong>
                                <small><?= e($formatCalories((float) ($row['calories'] ?? 0))) ?> kcal</small>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </article>
        </div>
    </section>

    <section class="analytics-section analytics-layout-item" style="<?= e($analyticsSectionStyle('body')) ?>">
        <div class="analytics-section-title">
            <h2><?= e(t('analytics.section_body')) ?></h2>
            <span class="badge"><?= e(t('metric.weight')) ?></span>
        </div>
        <div class="analytics-grid analytics-grid-single">
            <article class="chart-card analytics-chart-card analytics-chart-wide">
                <h3><?= e(t('dashboard.weight_chart')) ?></h3>
                <?php if ($weightValues === []): ?>
                    <p class="muted"><?= e(t('dashboard.no_weight')) ?></p>
                <?php else: ?>
                    <canvas id="weightChart" height="150"></canvas>
                    <?php if (($selectedMetric['ideal_weight'] ?? null) !== null): ?>
                        <p class="muted"><?= e(t('metric.goal')) ?>: <?= e((string) $selectedMetric['ideal_weight']) ?> kg - <?= e(t('metric.progress')) ?>: <?= e((string) ($selectedMetric['weight_progress_pct'] ?? 0)) ?>%</p>
                    <?php endif; ?>
                <?php endif; ?>
            </article>
        </div>
    </section>

    <section class="analytics-section analytics-layout-item" style="<?= e($analyticsSectionStyle('comparison')) ?>">
        <div class="analytics-section-title">
            <h2><?= e(t('dashboard.analytics_comparison')) ?></h2>
            <a class="btn btn-ghost small" href="<?= e($comparisonDetailHref) ?>"><?= e(t('dashboard.view_full_breakdown')) ?></a>
        </div>
        <div class="analytics-grid analytics-grid-single">
            <article class="chart-card analytics-chart-card analytics-chart-wide">
                <h3><?= e(trim($compareTitle)) ?></h3>
                <canvas id="compareChart" height="170"></canvas>
            </article>
        </div>
    </section>
    <script type="application/json" data-analytics-chart-data><?= json_encode($analyticsChartPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?></script>
</section>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
