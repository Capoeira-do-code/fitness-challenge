<?php

declare(strict_types=1);

$selectedUser = $selectedMetric['user'];
$rangeCount = $stepsRange === 'all' ? count($selectedMetric['steps_series']) : (int) $stepsRange;
$stepsTail = array_slice($selectedMetric['steps_series'], -max(1, $rangeCount));
$stepsLabels = array_map(static fn(array $row): string => format_date_eu((string) $row['date']), $stepsTail);
$stepsValues = array_map(static fn(array $row): int => (int) $row['steps'], $stepsTail);
$stepsGoals = array_map(static fn(array $row): int => (int) $row['goal'], $stepsTail);

$weightLabels = array_map(static fn(array $row): string => format_date_eu((string) $row['date']), $selectedMetric['weight_series']);
$weightValues = array_map(static fn(array $row): float => (float) $row['weight'], $selectedMetric['weight_series']);

$dashboardLayout = json_decode((string) ($currentUser['dashboard_layout_json'] ?? ''), true);
$visibleWidgets = is_array($dashboardLayout) && $dashboardLayout !== []
    ? array_values(array_filter($dashboardLayout, 'is_string'))
    : ['kpis', 'money', 'approvals', 'steps', 'weight', 'comparison', 'ranking', 'weekly'];
$showWidget = static fn(string $widget): bool => in_array($widget, $visibleWidgets, true);
$dashboardWidgets = ['kpis', 'money', 'approvals', 'steps', 'weight', 'comparison', 'ranking', 'weekly'];
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

$compareName = $compareMetric !== null ? (string) $compareMetric['user']['display_name'] : null;
$compareTitle = t('dashboard.compare', ['name' => $compareName !== null ? 'vs ' . $compareName : '']);

$compareBar = [
    'labels' => [t('metric.steps') . ' %', t('metric.workouts') . ' %', t('metric.score')],
    'datasets' => [
        [
            'label' => (string) $selectedUser['display_name'],
            'data' => [
                (float) $selectedMetric['step_completion_pct'],
                (float) $selectedMetric['workout_completion_pct'],
                (float) $selectedMetric['score'],
            ],
        ],
    ],
];
if ($compareMetric !== null) {
    $compareBar['datasets'][] = [
        'label' => (string) $compareMetric['user']['display_name'],
        'data' => [
            (float) $compareMetric['step_completion_pct'],
            (float) $compareMetric['workout_completion_pct'],
            (float) $compareMetric['score'],
        ],
    ];
}

$kpis = [
    [
        'key' => 'steps',
        'label' => t('metric.steps'),
        'value' => (string) ($selectedMetric['total_steps'] ?? 0),
        'meta' => (string) ($selectedMetric['steps_success'] ?? 0) . ' / ' . (string) ($selectedMetric['steps_required'] ?? 0) . ' · ' . (string) ($selectedMetric['step_completion_pct'] ?? 0) . '%',
        'ring' => (string) ($selectedMetric['step_completion_pct'] ?? 0) . '%',
        'progress' => (float) $selectedMetric['step_completion_pct'],
    ],
    [
        'key' => 'distance',
        'label' => t('metric.total_km'),
        'value' => (string) ($selectedMetric['total_km'] ?? 0) . ' km',
        'meta' => t('metric.distance_km'),
        'ring' => (string) ($selectedMetric['total_km'] ?? 0),
        'progress' => min(100, (float) ($selectedMetric['total_km'] ?? 0)),
    ],
    [
        'key' => 'workouts',
        'label' => t('metric.workouts'),
        'value' => (string) $selectedMetric['workout_success'] . ' / ' . (string) $selectedMetric['workout_target'],
        'meta' => (string) $selectedMetric['workout_completion_pct'] . '%',
        'ring' => (string) $selectedMetric['workout_completion_pct'] . '%',
        'progress' => (float) $selectedMetric['workout_completion_pct'],
    ],
    [
        'key' => 'money',
        'label' => t('metric.penalty'),
        'value' => '€' . (string) ($selectedMetric['total_penalty'] ?? 0),
        'meta' => t('metric.total_penalty'),
        'ring' => '€' . (string) ($selectedMetric['total_penalty'] ?? 0),
        'progress' => min(100, (float) ($selectedMetric['total_penalty'] ?? 0)),
    ],
    [
        'key' => 'strikes',
        'label' => t('metric.strikes'),
        'value' => (string) $selectedMetric['current_strikes'],
        'meta' => t('dashboard.accumulated_penalty', ['amount' => '€' . (string) $selectedMetric['total_penalty']]),
        'ring' => '€' . (string) $selectedMetric['total_penalty'],
        'progress' => max(0, 100 - ((int) $selectedMetric['current_strikes'] * 10)),
    ],
    [
        'key' => 'score',
        'label' => t('metric.discipline'),
        'value' => (string) $selectedMetric['score'],
        'meta' => t('dashboard.warning_count', ['count' => (string) ($selectedMetric['skip_warning_events'] ?? 0)]),
        'ring' => (string) $selectedMetric['score'],
        'progress' => (float) $selectedMetric['score'],
    ],
];
$metricQueryBase = [
    'page' => 'metric',
    'user_id' => (int) ($selectedUser['id'] ?? 0),
    'view' => (string) ($dashboardView ?? 'current_week'),
];

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

    <?php if ($showWidget('kpis')): ?>
    <div class="metric-grid" style="order: <?= $widgetOrder('kpis') ?>">
        <?php foreach ($kpis as $kpi): ?>
            <?php
            $metricHref = '/?' . http_build_query($metricQueryBase + ['metric' => (string) $kpi['key']]);
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
    </div>
    <?php endif; ?>

    <?php if ($showWidget('money') || ($showWidget('approvals') && $pendingApprovals !== [])): ?>
    <div class="grid-two" style="order: <?= $widgetOrder('money', 'approvals') ?>">
        <?php if ($showWidget('money')): ?>
        <article class="panel" data-testid="settlement-panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow"><?= e(t('metric.penalty')) ?></p>
                    <h2><?= e(t('dashboard.settlement_title')) ?></h2>
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
                    <?= e(t('dashboard.accumulated_penalty', ['amount' => '€' . (string) ($selectedMetric['total_penalty'] ?? 0)])) ?>
                <?php else: ?>
                    <?= e(t('dashboard.settlement_hint', ['week' => format_date_eu((string) $selectedWeekStart), 'amount' => '€' . (string) ($settlementSummary['total_penalty'] ?? 0)])) ?>
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
                        <th><?= e(t('metric.total_penalty')) ?></th>
                        <th><?= e(t('metric.score')) ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($metricsOrdered as $metric): ?>
                        <tr>
                            <td><?= e((string) $metric['user']['display_name']) ?></td>
                            <td><?= e((string) $metric['current_strikes']) ?></td>
                            <td><?= e((string) ($metric['skip_warning_events'] ?? 0)) ?></td>
                            <td>€<?= e((string) $metric['total_penalty']) ?></td>
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
                        <th><?= e(t('metric.week_penalty')) ?></th>
                        <th><?= e(t('common.status')) ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach (($settlementSummary['entries'] ?? []) as $entry): ?>
                        <tr>
                            <td><?= e((string) $entry['display_name']) ?></td>
                            <td><?= e((string) $entry['step_failures']) ?></td>
                            <td><?= e((string) $entry['workout_failures']) ?></td>
                            <td><?= e((string) $entry['skip_warnings']) ?></td>
                            <td>€<?= e((string) $entry['penalty']) ?></td>
                            <td><?= e(label_for_status((string) $entry['status'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <?php endif; ?>
                </table>
            </div>
        </article>
        <?php endif; ?>

        <?php if ($showWidget('approvals') && $pendingApprovals !== []): ?>
        <article class="panel" data-testid="pending-approvals">
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
    </div>
    <?php endif; ?>

    <?php if ($showWidget('steps') || $showWidget('weight')): ?>
    <div class="grid-two" style="order: <?= $widgetOrder('steps', 'weight') ?>">
        <?php if ($showWidget('steps')): ?>
        <article class="panel chart-card">
            <h2><?= e(t('dashboard.steps_chart')) ?></h2>
            <canvas id="stepsChart" height="150"></canvas>
        </article>
        <?php endif; ?>
        <?php if ($showWidget('weight')): ?>
        <article class="panel chart-card">
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
    </div>
    <?php endif; ?>

    <?php if ($showWidget('comparison') || $showWidget('ranking')): ?>
    <div class="grid-two" style="order: <?= $widgetOrder('comparison', 'ranking') ?>">
        <?php if ($showWidget('comparison')): ?>
        <article class="panel chart-card">
            <h2><?= e(trim($compareTitle)) ?></h2>
            <canvas id="compareChart" height="170"></canvas>
        </article>
        <?php endif; ?>
        <?php if ($showWidget('ranking')): ?>
        <article class="panel">
            <h2><?= e(t('dashboard.ranking')) ?></h2>
            <div class="leaderboard-list">
                <?php foreach ($metricsOrdered as $metric): ?>
                    <article class="leaderboard-row">
                        <div class="leaderboard-name">
                            <strong><?= e((string) $metric['user']['display_name']) ?></strong>
                            <span><?= e(t('metric.warnings')) ?>: <?= e((string) ($metric['skip_warning_events'] ?? 0)) ?></span>
                        </div>
                        <div class="leaderboard-stats">
                            <span class="badge"><?= e(t('metric.score')) ?> <?= e((string) $metric['score']) ?></span>
                            <span class="badge"><?= e(t('metric.strikes')) ?> <?= e((string) $metric['current_strikes']) ?></span>
                            <span class="badge">€<?= e((string) $metric['total_penalty']) ?></span>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </article>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($showWidget('weekly')): ?>
    <article class="panel" style="order: <?= $widgetOrder('weekly') ?>">
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
                    <th><?= e(t('metric.penalty')) ?></th>
                    <th><?= e(t('dashboard.strike_reduction')) ?></th>
                    <th><?= e(t('dashboard.strikes_after_week')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($selectedMetric['weekly'] as $week): ?>
                    <tr>
                        <td><?= e(format_date_eu((string) $week['week_start'])) ?> -> <?= e(format_date_eu((string) $week['week_end'])) ?></td>
                        <td><?= e(label_for_status((string) $week['status'])) ?></td>
                        <td><?= e((string) $week['step_failures']) ?></td>
                        <td><?= e((string) $week['workout_failures']) ?></td>
                        <td><?= e((string) ($week['skip_warnings'] ?? 0)) ?></td>
                        <td>€<?= e((string) $week['penalty']) ?></td>
                        <td><?= (int) $week['strike_reduction'] > 0 ? '-1' : '-' ?></td>
                        <td><?= e((string) $week['strikes_after_week']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </article>
    <?php endif; ?>
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
