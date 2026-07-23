<?php

declare(strict_types=1);

$selectedUser = (array) ($selectedMetric['user'] ?? []);
$metricSummary = is_array($metricSummary ?? null) ? (array) $metricSummary : [];
$scoreBreakdown = is_array($scoreBreakdown ?? null) ? (array) $scoreBreakdown : [];
$allowedMetrics = is_array($allowedMetrics ?? null) ? (array) $allowedMetrics : [];
$metricVisuals = [
    'steps' => ['footsteps', '#2563eb'],
    'distance' => ['run', '#0891b2'],
    'workouts' => ['dumbbell', '#7c3aed'],
    'score' => ['trophy', '#10b981'],
    'calories_consumed' => ['flame', '#f97316'],
    'calories_burned' => ['bolt', '#eab308'],
    'strikes' => ['shield', '#dc2626'],
    'money' => ['target', '#dc2626'],
];
[$metricIcon, $metricTone] = $metricVisuals[(string) $metricKey] ?? ['spark', '#14a38b'];
$formatMetricValue = static function (float|int $value, string $key, string $suffix = ''): string {
    $decimals = match ($key) {
        'score' => 1,
        'distance' => 2,
        'money' => 2,
        'calories_consumed', 'calories_burned' => 0,
        default => abs((float) $value - round((float) $value)) > 0.001 ? 1 : 0,
    };
    $formatted = number_format((float) $value, $decimals, ',', '.');

    return $formatted . $suffix;
};
$displayValue = $formatMetricValue((float) $currentValue, (string) $metricKey, (string) $currentValueSuffix);
$metricQueryBase = [
    'page' => 'metric',
    'user_id' => (int) ($selectedUser['id'] ?? 0),
    'view' => (string) ($dashboardView ?? 'current_week'),
];
$scoreComponentRows = [
    'steps' => ['label' => t('metric.steps'), 'icon' => 'footsteps', 'progress' => (float) ($scoreBreakdown['steps_progress'] ?? 0)],
    'workouts' => ['label' => t('metric.workouts'), 'icon' => 'dumbbell', 'progress' => (float) ($scoreBreakdown['workouts_progress'] ?? 0)],
    'discipline' => ['label' => t('metric.discipline'), 'icon' => 'shield', 'progress' => (float) ($scoreBreakdown['discipline_score'] ?? 0)],
];
if (!empty($scoreBreakdown['has_weight'])) {
    $scoreComponentRows['weight'] = ['label' => t('metric.weight'), 'icon' => 'target', 'progress' => (float) ($scoreBreakdown['weight_progress'] ?? 0)];
}
?>
<section class="screen metric-detail-screen" style="--metric-tone: <?= e($metricTone) ?>" data-metric-detail="<?= e((string) $metricKey) ?>">
    <header class="hierarchy-page-header metric-page-header">
        <a class="hierarchy-back destination-back" href="<?= e((string) $backUrl) ?>" data-hierarchy-back data-fallback="<?= e((string) $backUrl) ?>" aria-label="<?= e(t('common.back')) ?>: <?= e(t('nav.home')) ?>">
            <span aria-hidden="true">&larr;</span><strong><?= e(t('nav.home')) ?></strong>
        </a>
        <div>
            <p class="eyebrow"><?= e(t('metric.detail_title')) ?></p>
            <h1><?= e((string) $metricLabel) ?></h1>
        </div>
    </header>

    <nav class="metric-switcher" aria-label="<?= e(t('metric.choose_metric')) ?>">
        <?php foreach ($allowedMetrics as $navigationKey => $navigationLabel): ?>
            <?php [$navigationIcon] = $metricVisuals[(string) $navigationKey] ?? ['spark', '#14a38b']; ?>
            <a href="/?<?= e(http_build_query($metricQueryBase + ['metric' => (string) $navigationKey])) ?>" class="<?= (string) $navigationKey === (string) $metricKey ? 'is-active' : '' ?>" <?= (string) $navigationKey === (string) $metricKey ? 'aria-current="page"' : '' ?>>
                <span aria-hidden="true"><?= activity_icon_svg($navigationIcon) ?></span><strong><?= e((string) $navigationLabel) ?></strong>
            </a>
        <?php endforeach; ?>
    </nav>

    <article class="metric-overview-card glass-panel">
        <div class="metric-overview-main">
            <span class="metric-overview-icon" aria-hidden="true"><?= activity_icon_svg($metricIcon) ?></span>
            <div class="metric-overview-copy">
                <small><?= e(t('metric.current_value')) ?></small>
                <strong><?= e($displayValue) ?><?php if ((string) $metricKey === 'score'): ?><em>/ 100</em><?php endif; ?></strong>
                <p><?= e((string) ($periodLabel ?? '')) ?></p>
            </div>
        </div>

        <form method="get" class="metric-controls">
            <input type="hidden" name="page" value="metric">
            <input type="hidden" name="metric" value="<?= e((string) $metricKey) ?>">
            <input type="hidden" name="user_id" value="<?= (int) ($selectedUser['id'] ?? 0) ?>">
            <label>
                <span><?= e(t('dashboard.view_mode')) ?></span>
                <select name="view" onchange="this.form.submit()">
                    <option value="current_week" <?= ($dashboardView ?? '') === 'current_week' ? 'selected' : '' ?>><?= e(t('dashboard.current_week')) ?></option>
                    <option value="total" <?= ($dashboardView ?? '') === 'total' ? 'selected' : '' ?>><?= e(t('metric.total')) ?></option>
                    <?php foreach ($weekOptions as $weekStart): ?>
                        <option value="<?= e((string) $weekStart) ?>" <?= ($dashboardView ?? '') === (string) $weekStart ? 'selected' : '' ?>><?= e(format_date_eu((string) $weekStart)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>
    </article>

    <section class="metric-summary-section" aria-labelledby="metric-summary-title">
        <div class="metric-section-heading">
            <div><p class="eyebrow"><?= e(t('metric.period_summary')) ?></p><h2 id="metric-summary-title"><?= e((string) $metricLabel) ?></h2></div>
            <span><?= e(t('metric.data_points', ['count' => (int) ($metricSummary['points'] ?? 0)])) ?></span>
        </div>
        <div class="metric-summary-grid">
            <?php foreach ([
                ['average', t('metric.average_period')],
                ['best', t('metric.best_period')],
                ['latest', t('metric.latest_record')],
            ] as [$summaryKey, $summaryLabel]): ?>
                <article>
                    <small><?= e((string) $summaryLabel) ?></small>
                    <strong><?= e($formatMetricValue((float) ($metricSummary[$summaryKey] ?? 0), (string) $metricKey, (string) $currentValueSuffix)) ?><?php if ((string) $metricKey === 'score'): ?><em>/100</em><?php endif; ?></strong>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <?php if ((string) $metricKey === 'score'): ?>
        <article class="metric-score-card glass-panel">
            <header class="metric-score-head">
                <span aria-hidden="true"><?= activity_icon_svg('trophy') ?></span>
                <div><p class="eyebrow"><?= e(t('metric.score')) ?></p><h2><?= e(t('metric.score_calculation')) ?></h2><p><?= e(t('metric.score_calculation_hint')) ?></p></div>
                <strong><?= e($formatMetricValue((float) ($scoreBreakdown['score'] ?? 0), 'score')) ?><small>/100</small></strong>
            </header>
            <div class="metric-score-formula"><?= e(!empty($scoreBreakdown['has_weight']) ? t('metric.score_formula_weight') : t('metric.score_formula_no_weight')) ?></div>
            <div class="metric-score-components">
                <?php foreach ($scoreComponentRows as $componentKey => $component): ?>
                    <?php
                    $componentWeight = (float) (($scoreBreakdown['weights'][$componentKey] ?? 0) * 100);
                    $componentPoints = (float) ($scoreBreakdown['components'][$componentKey] ?? 0);
                    $componentProgress = max(0.0, min(100.0, (float) ($component['progress'] ?? 0)));
                    ?>
                    <div class="metric-score-component">
                        <span class="metric-score-component-icon" aria-hidden="true"><?= activity_icon_svg((string) $component['icon']) ?></span>
                        <span class="metric-score-component-copy">
                            <span><strong><?= e((string) $component['label']) ?></strong><small><?= e(t('metric.score_component_detail', ['progress' => number_format($componentProgress, 1, ',', '.'), 'weight' => number_format($componentWeight, 0, ',', '.')])) ?></small></span>
                            <span class="metric-score-track" aria-hidden="true"><i style="width: <?= e((string) $componentProgress) ?>%"></i></span>
                        </span>
                        <strong class="metric-score-component-points"><?= e(t('metric.score_points', ['points' => number_format($componentPoints, 1, ',', '.')])) ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
            <footer class="metric-score-footer">
                <div><strong><?= e(t('metric.score_total')) ?></strong><span><?= e(t('metric.score_points', ['points' => number_format((float) ($scoreBreakdown['score'] ?? 0), 1, ',', '.')])) ?> / 100</span></div>
                <p><?= e(t('metric.score_discipline_help')) ?></p>
                <?php if (empty($scoreBreakdown['has_weight'])): ?><p><?= e(t('metric.score_without_weight_help')) ?></p><?php endif; ?>
            </footer>
        </article>
    <?php endif; ?>

    <article class="metric-chart-card glass-panel">
        <div class="metric-section-heading">
            <div><p class="eyebrow"><?= e(t('metric.history')) ?></p><h2><?= e((string) $chartLabel) ?></h2></div>
            <span><?= e((string) ($periodLabel ?? '')) ?></span>
        </div>
        <?php if (($seriesValues ?? []) === []): ?>
            <div class="metric-empty"><span aria-hidden="true"><?= activity_icon_svg($metricIcon) ?></span><p><?= e(t('common.none')) ?></p></div>
        <?php else: ?>
            <div class="metric-chart-wrap"><canvas id="metricDetailChart" role="img" aria-label="<?= e(t('metric.history')) ?>: <?= e((string) $chartLabel) ?>"></canvas></div>
        <?php endif; ?>
    </article>
</section>

<?php if (($seriesValues ?? []) !== []): ?>
<script src="/asset.php?file=vendor%2Fchart.umd.min.js&amp;v=4.4.3"></script>
<script>
(function () {
    const canvas = document.getElementById('metricDetailChart');
    const screen = document.querySelector('[data-metric-detail]');
    if (!canvas || !screen || typeof Chart === 'undefined') return;
    const styles = getComputedStyle(document.body);
    const screenStyles = getComputedStyle(screen);
    const tone = screenStyles.getPropertyValue('--metric-tone').trim() || '#14a38b';
    const muted = styles.getPropertyValue('--muted').trim() || '#65727e';
    const line = styles.getPropertyValue('--line').trim() || '#d9e2dd';
    new Chart(canvas, {
        type: 'line',
        data: {
            labels: <?= json_encode($seriesLabels) ?>,
            datasets: [{
                label: <?= json_encode($chartLabel) ?>,
                data: <?= json_encode($seriesValues) ?>,
                borderColor: tone,
                backgroundColor: tone + '20',
                pointBackgroundColor: tone,
                pointBorderColor: tone,
                pointRadius: 3,
                pointHoverRadius: 5,
                borderWidth: 2.5,
                fill: true,
                tension: 0.32
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { intersect: false, mode: 'index' },
            scales: {
                x: { grid: { display: false }, ticks: { color: muted, maxRotation: 0, autoSkip: true, maxTicksLimit: 7 } },
                y: { beginAtZero: true, grid: { color: line }, ticks: { color: muted, precision: 0 } }
            },
            plugins: {
                legend: { display: false },
                tooltip: { displayColors: false, padding: 10, cornerRadius: 10 }
            }
        }
    });
})();
</script>
<?php endif; ?>
