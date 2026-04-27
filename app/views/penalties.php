<?php

declare(strict_types=1);

$selectedUser = $selectedMetric['user'];
$summary = is_array($penaltiesSummary ?? null) ? (array) $penaltiesSummary : [];
$rows = is_array($penaltyRows ?? null) ? array_values((array) $penaltyRows) : [];
?>
<section class="screen stack-lg">
    <div class="hero-panel">
        <div>
            <p class="eyebrow"><?= e(t('metric.penalty')) ?></p>
            <h1><?= e(t('penalties.title')) ?></h1>
            <p class="muted"><?= e(t('penalties.subtitle')) ?></p>
        </div>
        <a class="btn btn-ghost" href="<?= e((string) $backUrl) ?>"><?= e(t('penalties.back_dashboard')) ?></a>
    </div>

    <article class="panel">
        <form method="get" class="control-strip wrap">
            <input type="hidden" name="page" value="penalties">
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

    <?php if ($rows === []): ?>
        <article class="panel">
            <p class="muted"><?= e(t('penalties.no_data')) ?></p>
        </article>
    <?php else: ?>
        <article class="panel">
            <div class="metric-grid dashboard-kpis">
                <article class="metric-card">
                    <div>
                        <span><?= e(t('penalties.range_penalty_total')) ?></span>
                        <strong>€<?= e((string) ($summary['penalty_total'] ?? 0)) ?></strong>
                    </div>
                </article>
                <article class="metric-card">
                    <div>
                        <span><?= e(t('metric.step_failures')) ?></span>
                        <strong><?= e((string) ($summary['step_failures'] ?? 0)) ?></strong>
                    </div>
                </article>
                <article class="metric-card">
                    <div>
                        <span><?= e(t('metric.workout_failures')) ?></span>
                        <strong><?= e((string) ($summary['workout_failures'] ?? 0)) ?></strong>
                    </div>
                </article>
                <article class="metric-card">
                    <div>
                        <span><?= e(t('metric.skip_warnings')) ?></span>
                        <strong><?= e((string) ($summary['warnings'] ?? 0)) ?></strong>
                    </div>
                </article>
                <article class="metric-card">
                    <div>
                        <span><?= e(t('penalties.strike_reduction_total')) ?></span>
                        <strong><?= e((string) ($summary['strike_reduction'] ?? 0)) ?></strong>
                    </div>
                </article>
                <article class="metric-card">
                    <div>
                        <span><?= e(t('penalties.net_strikes_total')) ?></span>
                        <strong><?= e((string) ($summary['net_strikes'] ?? 0)) ?></strong>
                    </div>
                </article>
            </div>
        </article>

        <article class="panel">
            <h2><?= e(t('penalties.weekly_detail')) ?></h2>
            <div class="table-wrap">
                <table class="table compact">
                    <thead>
                    <tr>
                        <th><?= e(t('common.week')) ?></th>
                        <th><?= e(t('common.status')) ?></th>
                        <th><?= e(t('metric.step_failures')) ?></th>
                        <th><?= e(t('metric.workout_failures')) ?></th>
                        <th><?= e(t('metric.skip_warnings')) ?></th>
                        <th><?= e(t('dashboard.strike_reduction')) ?></th>
                        <th><?= e(t('penalties.net_strikes')) ?></th>
                        <th><?= e(t('dashboard.strikes_after_week')) ?></th>
                        <th><?= e(t('metric.week_penalty')) ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= e(format_date_eu((string) ($row['week_start'] ?? ''))) ?> -> <?= e(format_date_eu((string) ($row['week_end'] ?? ''))) ?></td>
                            <td><?= e(label_for_status((string) ($row['status'] ?? ''))) ?></td>
                            <td><?= e((string) ($row['step_failures'] ?? 0)) ?></td>
                            <td><?= e((string) ($row['workout_failures'] ?? 0)) ?></td>
                            <td><?= e((string) ($row['warnings'] ?? 0)) ?></td>
                            <td><?= e((string) ($row['strike_reduction'] ?? 0)) ?></td>
                            <td><?= e((string) ($row['net_strikes'] ?? 0)) ?></td>
                            <td><?= e((string) ($row['strikes_after_week'] ?? 0)) ?></td>
                            <td><span class="penalty-chip penalty-chip-<?= e(((int) ($row['penalty'] ?? 0)) <= 0 ? 'good' : (((int) ($row['penalty'] ?? 0)) <= 50 ? 'warn' : 'bad')) ?>">€<?= e((string) ($row['penalty'] ?? 0)) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </article>
    <?php endif; ?>
</section>
