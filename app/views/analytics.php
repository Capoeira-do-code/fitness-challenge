<?php

declare(strict_types=1);

$selectedUser = is_array($selectedMetric['user'] ?? null) ? $selectedMetric['user'] : [];
$rangeStartDate = to_date((string) ($dashboardAnalyticsRangeStart ?? $selectedWeekStart ?? null), to_date(null));
$rangeEndDate = to_date((string) ($dashboardAnalyticsRangeEnd ?? $rangeStartDate), $rangeStartDate);
$analyticsPeriod = (string) ($dashboardAnalyticsPeriod ?? 'current_week');
$analyticsWeek = (string) ($dashboardAnalyticsWeek ?? $selectedWeekStart ?? to_date(null));
$analyticsMonth = (string) ($dashboardAnalyticsMonth ?? substr((string) ($analyticsWeek ?: to_date(null)), 0, 7));
$analyticsRangeText = t('common.from_to', ['start' => format_date_eu($rangeStartDate), 'end' => format_date_eu($rangeEndDate)]);

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
$calorieRangeText = t('common.from_to', ['start' => format_date_eu((string) ($dashboardCalorieRangeStart ?? $rangeStartDate)), 'end' => format_date_eu((string) ($dashboardCalorieRangeEnd ?? $rangeEndDate))]);
$calorieMaintenanceTotal = (float) ($calorieStats['maintenance_total'] ?? 0);
$calorieDeficitTotal = (float) ($calorieStats['deficit'] ?? 0);

$analyticsBaseQuery = [
    'page' => 'analytics',
    'user_id' => (int) ($selectedUser['id'] ?? 0),
    'analytics_period' => $analyticsPeriod,
    'analytics_week' => $analyticsWeek,
    'analytics_month' => $analyticsMonth,
];
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

ob_start();
?>
<details class="topbar-context">
    <summary class="btn btn-ghost btn-topbar"><?= e(t('dashboard.analytics_period')) ?></summary>
    <div class="topbar-context-panel">
        <form method="get" class="stack">
            <input type="hidden" name="page" value="analytics">
            <label>
                <?= e(t('dashboard.viewing')) ?>
                <select name="user_id" onchange="this.form.submit()">
                    <?php foreach ($users as $user): ?>
                        <option value="<?= (int) $user['id'] ?>" <?= (int) $user['id'] === (int) ($selectedUser['id'] ?? 0) ? 'selected' : '' ?>><?= e((string) $user['display_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <?= e(t('dashboard.analytics_period')) ?>
                <select name="analytics_period" onchange="this.form.submit()">
                    <option value="current_week" <?= $analyticsPeriod === 'current_week' ? 'selected' : '' ?>><?= e(t('dashboard.analytics_current_week')) ?></option>
                    <option value="week" <?= $analyticsPeriod === 'week' ? 'selected' : '' ?>><?= e(t('dashboard.analytics_specific_week')) ?></option>
                    <option value="month" <?= $analyticsPeriod === 'month' ? 'selected' : '' ?>><?= e(t('dashboard.analytics_month')) ?></option>
                    <option value="total" <?= $analyticsPeriod === 'total' ? 'selected' : '' ?>><?= e(t('metric.total')) ?></option>
                </select>
            </label>
            <label><?= e(t('common.week')) ?><input type="date" name="analytics_week" value="<?= e($analyticsWeek) ?>" onchange="this.form.submit()"></label>
            <label><?= e(t('dashboard.analytics_month')) ?><input type="month" name="analytics_month" value="<?= e($analyticsMonth) ?>" onchange="this.form.submit()"></label>
        </form>
    </div>
</details>
<?php
$topbarControls = ob_get_clean();
?>
<section class="screen stack-lg analytics-page">
    <div class="hero-panel analytics-hero">
        <div>
            <p class="eyebrow"><?= e(t('dashboard.analytics_eyebrow')) ?></p>
            <h1><?= e(t('nav.analytics')) ?></h1>
            <p class="muted"><?= e((string) ($selectedUser['display_name'] ?? t('common.user'))) ?> - <?= e($analyticsRangeText) ?></p>
        </div>
        <a class="btn btn-ghost" href="/?page=dashboard&user_id=<?= (int) ($selectedUser['id'] ?? 0) ?>"><?= e(t('nav.dashboard')) ?></a>
    </div>

    <form method="get" action="/" class="panel analytics-controls analytics-filter-panel">
        <input type="hidden" name="page" value="analytics">
        <input type="hidden" name="analytics_period" value="<?= e($analyticsPeriod) ?>">
        <div class="analytics-viewing-summary">
            <span class="eyebrow"><?= e(t('dashboard.viewing')) ?></span>
            <strong><?= e((string) ($selectedUser['display_name'] ?? t('common.user'))) ?></strong>
            <small><?= e((string) ($analyticsPeriodLabels[$analyticsPeriod] ?? $analyticsPeriod)) ?> - <?= e($analyticsRangeText) ?></small>
        </div>
        <label class="analytics-user-filter">
            <?= e(t('dashboard.viewing')) ?>
            <select name="user_id" onchange="this.form.submit()">
                <?php foreach ($users as $user): ?>
                    <option value="<?= (int) $user['id'] ?>" <?= (int) $user['id'] === (int) ($selectedUser['id'] ?? 0) ? 'selected' : '' ?>><?= e((string) $user['display_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="analytics-period-segments" role="group" aria-label="<?= e(t('dashboard.analytics_period')) ?>">
            <?php foreach ($analyticsPeriodLabels as $periodKey => $periodLabel): ?>
                <a class="<?= $analyticsPeriod === $periodKey ? 'active' : '' ?>" href="/?<?= e(http_build_query(array_replace($analyticsBaseQuery, ['analytics_period' => $periodKey]))) ?>"><?= e((string) $periodLabel) ?></a>
            <?php endforeach; ?>
        </div>
        <?php if ($analyticsPeriod === 'week'): ?>
            <label class="analytics-date-filter"><?= e(t('common.week')) ?><input type="date" name="analytics_week" value="<?= e($analyticsWeek) ?>" onchange="this.form.submit()"></label>
        <?php else: ?>
            <input type="hidden" name="analytics_week" value="<?= e($analyticsWeek) ?>">
        <?php endif; ?>
        <?php if ($analyticsPeriod === 'month'): ?>
            <label class="analytics-date-filter"><?= e(t('dashboard.analytics_month')) ?><input type="month" name="analytics_month" value="<?= e($analyticsMonth) ?>" onchange="this.form.submit()"></label>
        <?php else: ?>
            <input type="hidden" name="analytics_month" value="<?= e($analyticsMonth) ?>">
        <?php endif; ?>
        <div class="analytics-nav-links">
            <a class="btn btn-ghost small" href="/?<?= e(http_build_query(array_replace($analyticsBaseQuery, ['analytics_period' => 'week', 'analytics_week' => $analyticsWeekPrev]))) ?>"><?= e(t('common.previous')) ?></a>
            <a class="btn btn-ghost small" href="/?<?= e(http_build_query(array_replace($analyticsBaseQuery, ['analytics_period' => 'week', 'analytics_week' => $analyticsWeekNext]))) ?>"><?= e(t('common.next')) ?></a>
            <a class="btn btn-ghost small" href="/?<?= e(http_build_query(array_replace($analyticsBaseQuery, ['analytics_period' => 'month', 'analytics_month' => $analyticsMonthPrev]))) ?>"><?= e(t('dashboard.analytics_prev_month')) ?></a>
            <a class="btn btn-ghost small" href="/?<?= e(http_build_query(array_replace($analyticsBaseQuery, ['analytics_period' => 'month', 'analytics_month' => $analyticsMonthNext]))) ?>"><?= e(t('dashboard.analytics_next_month')) ?></a>
        </div>
    </form>

    <section class="analytics-section">
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

    <section class="analytics-section">
        <div class="analytics-section-title">
            <h2><?= e(t('dashboard.analytics_calories_body')) ?></h2>
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

    <section class="analytics-section">
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
</section>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
(function () {
    function formatDayMonth(dateString) {
        const parts = String(dateString || '').split('/');
        return parts.length >= 2 ? `${parts[0]}/${parts[1]}` : String(dateString || '');
    }

    const dateChartOptions = () => ({
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'bottom' },
            tooltip: { callbacks: { title: (items) => (items && items[0] ? items[0].label : '') } },
        },
        scales: { x: { ticks: { callback: function (value) { return formatDayMonth(this.getLabelForValue(value)); } } } },
    });

    const charts = [
        ['stepsChart', 'line', <?= json_encode($stepsLabels) ?>, [
            { label: <?= json_encode(t('metric.steps')) ?>, data: <?= json_encode($stepsValues) ?>, borderColor: '#14a38b', backgroundColor: 'rgba(20, 163, 139, 0.16)', tension: 0.35, fill: true },
            { label: <?= json_encode(t('metric.goal')) ?>, data: <?= json_encode($stepsGoals) ?>, borderColor: '#ff6b4a', borderDash: [6, 4], pointRadius: 0 },
        ]],
        ['distanceWalkedChart', 'line', <?= json_encode($distanceLabels) ?>, [
            { label: <?= json_encode(t('metric.distance_km')) ?>, data: <?= json_encode($distanceValues) ?>, borderColor: '#3b82f6', backgroundColor: 'rgba(59, 130, 246, 0.16)', tension: 0.35, fill: true },
        ]],
        ['stepsCumulativeChart', 'line', <?= json_encode($stepsLabels) ?>, [
            { label: <?= json_encode(t('dashboard.steps_cumulative_chart')) ?>, data: <?= json_encode($stepsCumulativeValues) ?>, borderColor: '#0f766e', backgroundColor: 'rgba(15, 118, 110, 0.16)', tension: 0.35, fill: true },
        ]],
        ['distanceCumulativeChart', 'line', <?= json_encode($distanceLabels) ?>, [
            { label: <?= json_encode(t('dashboard.distance_cumulative_chart')) ?>, data: <?= json_encode($distanceCumulativeValues) ?>, borderColor: '#1d4ed8', backgroundColor: 'rgba(29, 78, 216, 0.14)', tension: 0.35, fill: true },
        ]],
        ['calorieChart', 'line', <?= json_encode($calorieLabels) ?>, [
            { label: <?= json_encode(t('dashboard.calories_consumed')) ?>, data: <?= json_encode($calorieConsumedValues) ?>, borderColor: '#ef4444', backgroundColor: 'rgba(239, 68, 68, 0.16)', tension: 0.3, fill: true },
            { label: <?= json_encode(t('dashboard.calories_burned')) ?>, data: <?= json_encode($calorieBurnedValues) ?>, borderColor: '#2563eb', backgroundColor: 'rgba(37, 99, 235, 0.14)', tension: 0.3, fill: true },
            { label: <?= json_encode(t('dashboard.calories_deficit')) ?>, data: <?= json_encode($calorieDeficitValues) ?>, borderColor: '#059669', backgroundColor: 'rgba(5, 150, 105, 0.12)', tension: 0.3, fill: false },
        ]],
        ['weightChart', 'line', <?= json_encode($weightLabels) ?>, [
            { label: <?= json_encode(t('metric.weight') . ' (kg)') ?>, data: <?= json_encode($weightValues) ?>, borderColor: '#22313f', backgroundColor: 'rgba(34, 49, 63, 0.12)', tension: 0.2, fill: true },
        ]],
    ];

    charts.forEach(([id, type, labels, datasets]) => {
        const canvas = document.getElementById(id);
        if (!canvas) {
            return;
        }
        new Chart(canvas, { type, data: { labels, datasets }, options: dateChartOptions() });
    });

    const compareCtx = document.getElementById('compareChart');
    if (compareCtx) {
        new Chart(compareCtx, {
            type: 'bar',
            data: { labels: <?= json_encode($compareBar['labels']) ?>, datasets: <?= json_encode($compareBar['datasets']) ?> },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true, max: 100 } } },
        });
    }
})();
</script>
