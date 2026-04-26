<?php

declare(strict_types=1);

$selectedUser = $selectedMetric['user'];
$displayValue = is_float($currentValue) ? rtrim(rtrim(number_format($currentValue, 2, '.', ''), '0'), '.') : (string) $currentValue;
?>
<section class="screen stack-lg">
    <div class="hero-panel">
        <div>
            <p class="eyebrow"><?= e(t('metric.detail_title')) ?></p>
            <h1><?= e((string) $metricLabel) ?></h1>
            <p class="muted"><?= e(t('metric.current_value')) ?>: <strong><?= e($displayValue . (string) $currentValueSuffix) ?></strong></p>
        </div>
        <a class="btn btn-ghost" href="<?= e((string) $backUrl) ?>"><?= e(t('metric.back_dashboard')) ?></a>
    </div>

    <article class="panel">
        <form method="get" class="control-strip wrap">
            <input type="hidden" name="page" value="metric">
            <input type="hidden" name="metric" value="<?= e((string) $metricKey) ?>">
            <label>
                <?= e(t('dashboard.viewing')) ?>
                <select name="user_id" onchange="this.form.submit()">
                    <?php foreach ($users as $user): ?>
                        <option value="<?= (int) $user['id'] ?>" <?= (int) $user['id'] === (int) $selectedUser['id'] ? 'selected' : '' ?>>
                            <?= e((string) $user['display_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <?= e(t('dashboard.view_mode')) ?>
                <select name="view" onchange="this.form.submit()">
                    <option value="current_week" <?= ($dashboardView ?? '') === 'current_week' ? 'selected' : '' ?>><?= e(t('dashboard.current_week')) ?></option>
                    <option value="total" <?= ($dashboardView ?? '') === 'total' ? 'selected' : '' ?>><?= e(t('metric.total')) ?></option>
                    <?php foreach ($weekOptions as $weekStart): ?>
                        <option value="<?= e((string) $weekStart) ?>" <?= ($dashboardView ?? '') === (string) $weekStart ? 'selected' : '' ?>>
                            <?= e(format_date_eu((string) $weekStart)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>
    </article>

    <article class="panel chart-card">
        <h2><?= e(t('metric.history')) ?></h2>
        <?php if (($seriesValues ?? []) === []): ?>
            <p class="muted"><?= e(t('common.none')) ?></p>
        <?php else: ?>
            <canvas id="metricDetailChart" height="190"></canvas>
        <?php endif; ?>
    </article>
</section>

<?php if (($seriesValues ?? []) !== []): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
(function () {
    const ctx = document.getElementById('metricDetailChart');
    if (!ctx) {
        return;
    }
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= json_encode($seriesLabels) ?>,
            datasets: [{
                label: <?= json_encode($chartLabel) ?>,
                data: <?= json_encode($seriesValues) ?>,
                borderColor: '#14a38b',
                backgroundColor: 'rgba(20, 163, 139, 0.16)',
                fill: true,
                tension: 0.3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom' } }
        }
    });
})();
</script>
<?php endif; ?>
