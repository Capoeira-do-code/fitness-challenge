<?php

declare(strict_types=1);

$metric = $selectedMetric ?? [];
$week = null;
foreach (($metric['weekly'] ?? []) as $candidate) {
    if (($candidate['week_start'] ?? '') === $weekStart) {
        $week = $candidate;
        break;
    }
}
$week = $week ?? ['step_failures' => 0, 'workout_failures' => 0, 'skip_warnings' => 0, 'penalty' => 0, 'status' => 'in_progress'];
?>
<section class="screen stack-lg">
    <div class="hero-panel">
        <div>
            <p class="eyebrow"><?= e(t('nav.table')) ?></p>
            <h1><?= e(t('table.week_summary')) ?></h1>
            <p class="muted"><?= e(format_date_eu($weekStart)) ?> - <?= e(format_date_eu($weekEnd ?? $weekStart)) ?></p>
        </div>
        <a class="btn btn-primary" href="/?page=week_editor&user_id=<?= (int) $selectedUser['id'] ?>&week=<?= e(date_to_iso_week($weekStart)) ?>"><?= e(t('table.open_editor')) ?></a>
    </div>

    <form method="get" class="control-strip">
        <input type="hidden" name="page" value="table">
        <label>
            <?= e(t('common.user')) ?>
            <select name="user_id" onchange="this.form.submit()">
                <?php foreach ($users as $user): ?>
                    <option value="<?= (int) $user['id'] ?>" <?= (int) $selectedUser['id'] === (int) $user['id'] ? 'selected' : '' ?>><?= e((string) $user['display_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <?= e(t('common.week')) ?>
            <input type="week" name="week" value="<?= e(date_to_iso_week($weekStart)) ?>" onchange="this.form.submit()">
        </label>
    </form>

    <div class="metric-grid">
        <article class="metric-card"><div class="progress-ring" style="--value: <?= e((string) ($metric['step_completion_pct'] ?? 0)) ?>;"><span><?= e((string) ($metric['step_completion_pct'] ?? 0)) ?>%</span></div><div><span><?= e(t('metric.steps')) ?></span><strong><?= e((string) ($metric['steps_success'] ?? 0)) ?>/<?= e((string) ($metric['steps_required'] ?? 0)) ?></strong><p><?= e(t('metric.total')) ?> <?= e((string) ($metric['total_steps'] ?? 0)) ?></p></div></article>
        <article class="metric-card"><div class="progress-ring" style="--value: <?= e((string) ($metric['workout_completion_pct'] ?? 0)) ?>;"><span><?= e((string) ($metric['workout_completion_pct'] ?? 0)) ?>%</span></div><div><span><?= e(t('metric.workouts')) ?></span><strong><?= e((string) max((int) ($metric['workout_count'] ?? 0), (int) ($metric['workout_success'] ?? 0))) ?>/<?= e((string) ($metric['workout_target'] ?? 0)) ?></strong><p><?= e(t('metric.progress')) ?></p></div></article>
        <article class="metric-card"><div class="progress-ring" style="--value: <?= e((string) max(0, 100 - ((int) ($metric['current_strikes'] ?? 0) * 10))) ?>;"><span><?= e((string) ($metric['current_strikes'] ?? 0)) ?></span></div><div><span><?= e(t('metric.strikes')) ?></span><strong><?= e((string) ($metric['current_strikes'] ?? 0)) ?></strong><p>€<?= e((string) ($metric['total_penalty'] ?? 0)) ?></p></div></article>
        <article class="metric-card"><div class="progress-ring" style="--value: <?= e((string) min(100, (float) ($metric['total_km'] ?? 0))) ?>;"><span><?= e((string) ($metric['total_km'] ?? 0)) ?></span></div><div><span><?= e(t('metric.distance_km')) ?></span><strong><?= e((string) ($metric['total_km'] ?? 0)) ?> km</strong><p><?= e(t('metric.total')) ?></p></div></article>
    </div>

    <div class="grid-two">
        <article class="panel">
            <h2><?= e(t('table.week_result')) ?></h2>
            <ul class="facts">
                <li><strong><?= e(t('common.status')) ?>:</strong> <?= e(label_for_status((string) $week['status'])) ?></li>
                <li><strong><?= e(t('metric.step_failures')) ?>:</strong> <?= e((string) $week['step_failures']) ?></li>
                <li><strong><?= e(t('metric.workout_failures')) ?>:</strong> <?= e((string) $week['workout_failures']) ?></li>
                <li><strong><?= e(t('metric.skip_warnings')) ?>:</strong> <?= e((string) $week['skip_warnings']) ?></li>
                <li><strong><?= e(t('metric.penalty')) ?>:</strong> €<?= e((string) $week['penalty']) ?></li>
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
</section>
