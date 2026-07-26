<?php

declare(strict_types=1);

$selectedMetric = is_array($selectedMetric ?? null) ? $selectedMetric : [];
$compareMetric = is_array($compareMetric ?? null) ? $compareMetric : null;
$selectedBreakdown = is_array($selectedBreakdown ?? null) ? $selectedBreakdown : [];
$compareBreakdown = is_array($compareBreakdown ?? null) ? $compareBreakdown : null;
$selectedUser = is_array($selectedMetric['user'] ?? null) ? $selectedMetric['user'] : [];
$selectedUserId = (int) ($selectedUser['id'] ?? 0);
$backUrl = (string) ($backUrl ?? '/?page=dashboard');
$dashboardView = (string) ($dashboardView ?? 'current_week');
$weekOptions = array_values((array) ($weekOptions ?? []));
?>
<section class="screen stack-lg comparison-detail-screen">
    <div class="hero-panel app-page-hero">
        <div class="hero-copy hero-copy-page-title">
            <p class="eyebrow"><?= e(t('metric.score')) ?></p>
            <h1><?= e(t('dashboard.comparison_detail_title')) ?></h1>
            <p class="muted"><?= e(t('dashboard.comparison_detail_subtitle')) ?></p>
        </div>
        <a class="btn btn-ghost" href="<?= e($backUrl) ?>"><?= e(t('metric.back_dashboard')) ?></a>
    </div>

    <article class="panel">
        <form method="get" class="control-strip wrap">
            <input type="hidden" name="page" value="comparison_detail">
            <label>
                <?= e(t('dashboard.viewing')) ?>
                <select name="user_id" onchange="this.form.submit()">
                    <?php foreach ((array) ($users ?? []) as $user): ?>
                        <option value="<?= (int) ($user['id'] ?? 0) ?>" <?= (int) ($user['id'] ?? 0) === $selectedUserId ? 'selected' : '' ?>>
                            <?= e((string) ($user['display_name'] ?? t('common.user'))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <?= e(t('dashboard.view_mode')) ?>
                <select name="view" onchange="this.form.submit()">
                    <option value="current_week" <?= $dashboardView === 'current_week' ? 'selected' : '' ?>><?= e(t('dashboard.current_week')) ?></option>
                    <option value="total" <?= $dashboardView === 'total' ? 'selected' : '' ?>><?= e(t('metric.total')) ?></option>
                    <?php foreach ($weekOptions as $weekStart): ?>
                        <option value="<?= e((string) $weekStart) ?>" <?= $dashboardView === (string) $weekStart ? 'selected' : '' ?>>
                            <?= e(format_date_eu((string) $weekStart)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>
    </article>

    <article class="panel">
        <h2><?= e(t('dashboard.comparison_formula_title')) ?></h2>
        <p class="muted"><?= e((string) ($selectedBreakdown['formula'] ?? '')) ?></p>
    </article>

    <div class="grid-two">
        <?php
        $cards = [
            [
                'title' => (string) ($selectedUser['display_name'] ?? t('common.user')),
                'breakdown' => $selectedBreakdown,
            ],
        ];
        if (is_array($compareMetric) && is_array($compareBreakdown)) {
            $cards[] = [
                'title' => (string) (($compareMetric['user']['display_name'] ?? t('common.user'))),
                'breakdown' => $compareBreakdown,
            ];
        }
        ?>
        <?php foreach ($cards as $card): ?>
            <?php
            $b = is_array($card['breakdown'] ?? null) ? $card['breakdown'] : [];
            $cardWeights = is_array($b['weights'] ?? null) ? (array) $b['weights'] : [];
            ?>
            <article class="panel">
                <h3><?= e((string) ($card['title'] ?? t('common.user'))) ?></h3>
                <div class="metric-grid dashboard-kpis">
                    <?php /* Each card below is only rendered when the metric actually carries weight
                             in this user's live score (same weights map the table below filters on).
                             Metrics turned off in Settings > Tracked metrics still report a 0 progress
                             value, and without this guard they showed a misleading "0,0%" card that read
                             as a failing score instead of "not being measured". */ ?>
                    <?php if ((float) ($cardWeights['steps'] ?? 0) > 0): ?>
                        <article class="metric-card"><div><span><?= e(t('metric.steps')) ?> %</span><strong><?= e(number_format((float) ($b['steps_progress'] ?? 0), 1, ',', '.')) ?>%</strong></div></article>
                    <?php endif; ?>
                    <?php if ((float) ($cardWeights['workouts'] ?? 0) > 0): ?>
                        <article class="metric-card"><div><span><?= e(t('metric.workouts')) ?> %</span><strong><?= e(number_format((float) ($b['workouts_progress'] ?? 0), 1, ',', '.')) ?>%</strong></div></article>
                    <?php endif; ?>
                    <?php if ((float) ($cardWeights['discipline'] ?? 0) > 0): ?>
                        <article class="metric-card"><div><span><?= e(t('metric.discipline')) ?></span><strong><?= e(number_format((float) ($b['discipline_score'] ?? 0), 1, ',', '.')) ?>%</strong></div></article>
                    <?php endif; ?>
                    <?php if (($b['weight_progress'] ?? null) !== null && (float) ($cardWeights['weight'] ?? 0) > 0): ?>
                        <article class="metric-card"><div><span><?= e(t('metric.weight')) ?> %</span><strong><?= e(number_format((float) ($b['weight_progress'] ?? 0), 1, ',', '.')) ?>%</strong></div></article>
                    <?php endif; ?>
                </div>
                <div class="table-wrap">
                    <table class="table compact">
                        <thead>
                        <tr>
                            <th><?= e(t('common.category')) ?></th>
                            <th><?= e(t('dashboard.weight_label')) ?></th>
                            <th><?= e(t('metric.current_value')) ?></th>
                            <th><?= e(t('metric.score')) ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ((array) ($b['weights'] ?? []) as $key => $weight): ?>
                            <?php if ((float) $weight <= 0): ?>
                                <?php continue; ?>
                            <?php endif; ?>
                            <?php
                            $progressValue = (float) match ((string) $key) {
                                'steps' => (float) ($b['steps_progress'] ?? 0),
                                'workouts' => (float) ($b['workouts_progress'] ?? 0),
                                'discipline' => (float) ($b['discipline_score'] ?? 0),
                                'weight' => (float) ($b['weight_progress'] ?? 0),
                                default => 0.0,
                            };
                            $contribution = (float) (($b['components'] ?? [])[$key] ?? 0);
                            $label = match ((string) $key) {
                                'steps' => t('metric.steps'),
                                'workouts' => t('metric.workouts'),
                                'discipline' => t('metric.discipline'),
                                'weight' => t('metric.weight'),
                                default => (string) $key,
                            };
                            ?>
                            <tr>
                                <td><?= e($label) ?></td>
                                <td><?= e(number_format(round(((float) $weight) * 100, 0), 0, ',', '.')) ?>%</td>
                                <td><?= e(number_format($progressValue, 1, ',', '.')) ?>%</td>
                                <td><?= e(number_format($contribution, 2, ',', '.')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                        <tr>
                            <th colspan="3"><?= e(t('metric.score')) ?></th>
                            <th><?= e(number_format((float) ($b['score'] ?? 0), 1, ',', '.')) ?></th>
                        </tr>
                        </tfoot>
                    </table>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>
