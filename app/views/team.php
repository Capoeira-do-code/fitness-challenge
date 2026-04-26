<?php

declare(strict_types=1);

$summary = $teamSummary ?? [];
$teamView = (string) ($teamView ?? 'current_week');
$teamWeekOptions = (array) ($teamWeekOptions ?? []);
$teamSelectedWeekStart = (string) ($teamSelectedWeekStart ?? ($teamWeekOptions !== [] ? (string) $teamWeekOptions[count($teamWeekOptions) - 1] : to_date(null)));
$today = to_date(null);

$goals = (array) ($teamGoals ?? []);
$activeTeamGoals = array_values(array_filter(
    $goals,
    static function (array $goal) use ($today): bool {
        $status = (string) ($goal['status'] ?? 'active');
        if ($status === 'complete') {
            return false;
        }
        $dueDate = (string) ($goal['due_date'] ?? '');
        return $dueDate === '' || $dueDate >= $today;
    }
));

$goalProgressValue = static function (array $goal) use ($summary): float {
    $type = (string) ($goal['target_type'] ?? 'custom');
    return match ($type) {
        'steps' => (float) ($summary['total_steps'] ?? 0),
        'workouts' => (float) ($summary['workout_success'] ?? 0),
        'score' => (float) ($summary['score_avg'] ?? 0),
        default => (float) ($goal['current_value'] ?? 0),
    };
};
?>
<section class="screen stack-lg">
    <div class="hero-panel">
        <div>
            <p class="eyebrow"><?= e(t('nav.team')) ?></p>
            <h1><?= e((string) $team['name']) ?></h1>
            <p class="muted"><?= e((string) (($team['description'] ?? '') !== '' ? $team['description'] : t('team.subtitle'))) ?></p>
        </div>
        <?php if (!empty($canManageTeam)): ?>
            <a class="btn btn-secondary icon-btn team-settings-top" href="/?page=team_settings&team_id=<?= (int) $team['id'] ?>" aria-label="<?= e(t('team.settings')) ?>"><?= e(t('team.settings_short')) ?></a>
        <?php endif; ?>
    </div>

    <article class="panel">
        <form method="get" class="control-strip wrap">
            <input type="hidden" name="page" value="team">
            <input type="hidden" name="team_id" value="<?= (int) $team['id'] ?>">
            <label>
                <?= e(t('dashboard.view_mode')) ?>
                <select name="view" onchange="this.form.submit()">
                    <option value="current_week" <?= $teamView === 'current_week' ? 'selected' : '' ?>><?= e(t('dashboard.current_week')) ?></option>
                    <option value="total" <?= $teamView === 'total' ? 'selected' : '' ?>><?= e(t('metric.total')) ?></option>
                    <?php foreach ($teamWeekOptions as $weekStart): ?>
                        <option value="<?= e((string) $weekStart) ?>" <?= $teamView === (string) $weekStart ? 'selected' : '' ?>><?= e(format_date_eu((string) $weekStart)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>
    </article>

    <div class="metric-grid">
        <article class="metric-card">
            <div class="progress-ring" style="--value: 100;"><span><?= e((string) ($summary['total_steps'] ?? 0)) ?></span></div>
            <div><span><?= e(t('metric.total_steps')) ?></span><strong><?= e((string) ($summary['total_steps'] ?? 0)) ?></strong><p><?= e(t('team.all_members')) ?></p></div>
        </article>
        <article class="metric-card">
            <div class="progress-ring" style="--value: <?= e((string) min(100, (float) ($summary['total_km'] ?? 0))) ?>;"><span><?= e((string) ($summary['total_km'] ?? 0)) ?></span></div>
            <div><span><?= e(t('metric.total_km')) ?></span><strong><?= e((string) ($summary['total_km'] ?? 0)) ?> km</strong><p><?= e(t('team.all_members')) ?></p></div>
        </article>
        <article class="metric-card">
            <div class="progress-ring" style="--value: <?= e((string) (($summary['workout_target'] ?? 0) > 0 ? min(100, round(((int) $summary['workout_success'] / (int) $summary['workout_target']) * 100)) : 0)) ?>;"><span><?= e((string) ($summary['workout_success'] ?? 0)) ?></span></div>
            <div><span><?= e(t('metric.workouts')) ?></span><strong><?= e((string) ($summary['workout_success'] ?? 0)) ?></strong><p><?= e(t('team.all_members')) ?></p></div>
        </article>
        <article class="metric-card">
            <div class="progress-ring" style="--value: <?= e((string) min(100, (float) ($summary['score_avg'] ?? 0))) ?>;"><span><?= e((string) ($summary['score_avg'] ?? 0)) ?></span></div>
            <div><span><?= e(t('metric.score')) ?></span><strong><?= e((string) ($summary['score_avg'] ?? 0)) ?></strong><p><?= e(t('team.avg_score')) ?></p></div>
        </article>
        <article class="metric-card">
            <div class="progress-ring" style="--value: <?= e((string) max(0, 100 - ((int) ($summary['strikes'] ?? 0) * 8))) ?>;"><span><?= e((string) ($summary['strikes'] ?? 0)) ?></span></div>
            <div><span><?= e(t('metric.strikes')) ?></span><strong><?= e((string) ($summary['strikes'] ?? 0)) ?></strong><p><?= e(t('team.all_members')) ?></p></div>
        </article>
        <article class="metric-card">
            <div class="progress-ring" style="--value: <?= e((string) max(0, 100 - min(100, (float) ($summary['penalty'] ?? 0) / 5))) ?>;"><span>€<?= e((string) ($summary['penalty'] ?? 0)) ?></span></div>
            <div><span><?= e(t('metric.penalty')) ?></span><strong>€<?= e((string) ($summary['penalty'] ?? 0)) ?></strong><p><?= e(t('team.all_members')) ?></p></div>
        </article>
    </div>

    <div class="grid-two">
        <article class="panel chart-card">
            <h2>Steps over time</h2>
            <canvas id="teamStepsChart" height="170"></canvas>
        </article>
        <article class="panel chart-card">
            <h2>Distance over time</h2>
            <canvas id="teamDistanceChart" height="170"></canvas>
        </article>
    </div>

    <div class="grid-two">
        <article class="panel chart-card">
            <h2>Workouts over time</h2>
            <canvas id="teamWorkoutsChart" height="170"></canvas>
        </article>
        <article class="panel chart-card">
            <h2>Score / Strikes / Penalties</h2>
            <canvas id="teamWeeklyChart" height="170"></canvas>
        </article>
    </div>

    <article class="panel">
        <h2>User comparison</h2>
        <div class="table-wrap">
            <table class="table compact">
                <thead>
                <tr>
                    <th><?= e(t('common.user')) ?></th>
                    <th><?= e(t('metric.score')) ?></th>
                    <th><?= e(t('metric.steps')) ?></th>
                    <th><?= e(t('metric.distance_km')) ?></th>
                    <th><?= e(t('metric.workouts')) ?></th>
                    <th><?= e(t('metric.strikes')) ?></th>
                    <th><?= e(t('metric.penalty')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach (($teamComparisonRows ?? []) as $row): ?>
                    <tr>
                        <td><?= e((string) $row['display_name']) ?></td>
                        <td><?= e((string) $row['score']) ?></td>
                        <td><?= e((string) $row['steps']) ?></td>
                        <td><?= e((string) $row['distance']) ?> km</td>
                        <td><?= e((string) $row['workouts']) ?></td>
                        <td><?= e((string) $row['strikes']) ?></td>
                        <td>€<?= e((string) $row['penalties']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (($teamComparisonRows ?? []) === []): ?>
                    <tr><td colspan="7" class="muted"><?= e(t('common.none')) ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>

    <div class="grid-two">
        <article class="panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow"><?= e(t('team.members')) ?></p>
                    <h2><?= e(t('team.active_members')) ?></h2>
                </div>
                <span class="badge"><?= count($members ?? []) ?></span>
            </div>
            <div class="card-list">
                <?php foreach (($members ?? []) as $member): ?>
                    <article class="mini-card member-card">
                        <div>
                            <strong><?= e((string) $member['display_name']) ?></strong>
                            <span><?= e((string) $member['username']) ?> · <?= e((string) $member['role']) ?></span>
                        </div>
                        <a class="btn small btn-ghost" href="/?page=profile&user_id=<?= (int) $member['user_id'] ?>" aria-label="Ver perfil de <?= e((string) $member['username']) ?>">Ver perfil</a>
                    </article>
                <?php endforeach; ?>
            </div>
        </article>

        <article class="panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow"><?= e(t('goals.team')) ?></p>
                    <h2>Objetivos activos</h2>
                </div>
            </div>
            <?php if ($activeTeamGoals === []): ?>
                <p class="muted">No hay objetivos activos</p>
            <?php else: ?>
                <div class="card-list goal-progress-list">
                    <?php foreach ($activeTeamGoals as $goal): ?>
                        <?php
                        $target = max(0.0, (float) ($goal['target_value'] ?? 0));
                        $current = $goalProgressValue($goal);
                        $progress = $target > 0 ? min(100.0, round(($current / $target) * 100, 1)) : 0.0;
                        ?>
                        <article class="mini-card goal-progress-card">
                            <div>
                                <strong><?= e((string) $goal['title']) ?></strong>
                                <span><?= e((string) $goal['target_type']) ?> · <?= e((string) ($goal['target_value'] ?? '-')) ?><?php if (!empty($goal['due_date'])): ?> · <?= e(format_date_eu((string) $goal['due_date'])) ?><?php endif; ?></span>
                                <span><?= e((string) round($current, 1)) ?> / <?= e((string) ($goal['target_value'] ?? '-')) ?></span>
                                <div class="goal-progress"><span style="width: <?= e((string) $progress) ?>%"></span></div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </article>
    </div>

    <div class="grid-two">
        <article class="panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow"><?= e(t('goals.team')) ?></p>
                    <h2><?= e(t('goals.title')) ?></h2>
                </div>
            </div>
            <form method="post" action="/?page=team" class="stack compact-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="create_goal">
                <label>
                    <?= e(t('goals.goal_name')) ?>
                    <input type="text" name="title" placeholder="<?= e(t('goals.team_placeholder')) ?>" required>
                </label>
                <div class="grid-inline two">
                    <label>
                        <?= e(t('goals.type')) ?>
                        <select name="target_type">
                            <option value="steps"><?= e(t('metric.steps')) ?></option>
                            <option value="workouts"><?= e(t('metric.workouts')) ?></option>
                            <option value="score"><?= e(t('metric.score')) ?></option>
                            <option value="custom"><?= e(t('common.other')) ?></option>
                        </select>
                    </label>
                    <label>
                        <?= e(t('goals.target')) ?>
                        <input type="number" step="0.1" name="target_value">
                    </label>
                    <label>
                        <?= e(t('goals.due_date')) ?>
                        <input type="date" name="due_date">
                    </label>
                </div>
                <button class="btn btn-primary" type="submit"><?= e(t('goals.add')) ?></button>
            </form>
            <div class="card-list">
                <?php if (($goals ?? []) === []): ?>
                    <p class="muted"><?= e(t('goals.empty')) ?></p>
                <?php else: ?>
                    <?php foreach ($goals as $goal): ?>
                        <article class="mini-card">
                            <div>
                                <strong><?= e((string) $goal['title']) ?></strong>
                                <span><?= e((string) $goal['status']) ?> · <?= e((string) $goal['target_type']) ?> <?= e((string) ($goal['target_value'] ?? '')) ?><?php if (!empty($goal['due_date']) && (string) $goal['due_date'] < $today && (string) $goal['status'] !== 'complete'): ?> · Expirado<?php endif; ?></span>
                            </div>
                            <?php if (!empty($canManageTeam)): ?>
                                <form method="post" action="/?page=team" class="inline-actions-mini">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="goal_id" value="<?= (int) $goal['id'] ?>">
                                    <button class="btn small btn-ghost" name="action" value="goal_status" type="submit" formaction="/?page=team" onclick="this.form.status.value='complete'"><?= e(t('common.complete')) ?></button>
                                    <button class="btn small btn-ghost" name="action" value="goal_status" type="submit" formaction="/?page=team" onclick="this.form.status.value='archived'"><?= e(t('goals.archive')) ?></button>
                                    <button class="btn small btn-ghost" name="action" value="delete_goal" type="submit" formaction="/?page=team"><?= e(t('common.delete')) ?></button>
                                    <input type="hidden" name="status" value="active">
                                </form>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </article>

        <article class="panel">
            <h2><?= e(t('dashboard.ranking')) ?></h2>
            <div class="stat-list">
                <?php foreach (($metricsOrdered ?? []) as $metric): ?>
                    <article class="leaderboard-row">
                        <div class="leaderboard-name">
                            <strong><?= e((string) $metric['user']['display_name']) ?></strong>
                            <span><?= e(t('metric.warnings')) ?> <?= e((string) ($metric['skip_warning_events'] ?? 0)) ?></span>
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
    </div>

    <article class="panel">
        <div class="panel-head">
            <div>
                <p class="eyebrow"><?= e(t('achievements.title')) ?></p>
                <h2><?= e(t('team.achievements')) ?></h2>
            </div>
            <span class="badge"><?= count($teamAchievements ?? []) ?></span>
        </div>
        <div class="achievement-grid achievement-grid-collapsible" data-achievement-grid>
            <?php if (($teamAchievements ?? []) === []): ?>
                <p class="muted"><?= e(t('achievements.empty')) ?></p>
            <?php else: ?>
                <?php foreach ($teamAchievements as $achievement): ?>
                    <?php $deleteFormId = 'delete-achievement-team-' . (int) $achievement['id']; ?>
                    <article class="achievement-card">
                        <?php if (!empty($achievement['image_path'])): ?><img src="<?= e((string) $achievement['image_path']) ?>" alt="<?= e((string) $achievement['name']) ?>"><?php else: ?><span>*</span><?php endif; ?>
                        <strong><?= e((string) $achievement['name']) ?></strong>
                        <p><?= e((string) $achievement['description']) ?></p>
                        <?php if (!empty($achievement['reward_text'])): ?><small><?= e(t('achievements.reward')) ?>: <?= e((string) $achievement['reward_text']) ?></small><?php endif; ?>
                        <small><?= e((string) $achievement['awarded_at']) ?></small>
                        <?php if (!empty($canDeleteAchievements)): ?>
                            <form method="post" action="/?page=team" class="achievement-remove" id="<?= e($deleteFormId) ?>">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete_achievement_award">
                                <input type="hidden" name="award_id" value="<?= (int) $achievement['id'] ?>">
                                <button class="achievement-delete-btn" type="button" aria-label="Eliminar logro" data-achievement-delete-trigger data-form-id="<?= e($deleteFormId) ?>">×</button>
                            </form>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php if (count($teamAchievements ?? []) > 4): ?>
            <button class="btn btn-ghost btn-block js-toggle-achievements" type="button" data-expand-label="<?= e(t('common.view_all')) ?>" data-collapse-label="<?= e(t('common.view_less')) ?>"><?= e(t('common.view_all')) ?></button>
        <?php endif; ?>
    </article>

    <?php if (!empty($canManageTeam)): ?>
        <a class="btn btn-secondary btn-block team-settings-bottom" href="/?page=team_settings&team_id=<?= (int) $team['id'] ?>"><?= e(t('team.settings')) ?></a>
    <?php endif; ?>
</section>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
(function () {
    const lineOpts = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom' } }
    };

    const stepsCtx = document.getElementById('teamStepsChart');
    if (stepsCtx) {
        new Chart(stepsCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode($teamDailyLabels ?? []) ?>,
                datasets: [{
                    label: <?= json_encode(t('metric.steps')) ?>,
                    data: <?= json_encode($teamDailySteps ?? []) ?>,
                    borderColor: '#14a38b',
                    backgroundColor: 'rgba(20, 163, 139, 0.16)',
                    fill: true,
                    tension: 0.3,
                }]
            },
            options: lineOpts
        });
    }

    const distanceCtx = document.getElementById('teamDistanceChart');
    if (distanceCtx) {
        new Chart(distanceCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode($teamDailyLabels ?? []) ?>,
                datasets: [{
                    label: <?= json_encode(t('metric.distance_km')) ?>,
                    data: <?= json_encode($teamDailyDistance ?? []) ?>,
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.14)',
                    fill: true,
                    tension: 0.3,
                }]
            },
            options: lineOpts
        });
    }

    const workoutsCtx = document.getElementById('teamWorkoutsChart');
    if (workoutsCtx) {
        new Chart(workoutsCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode($teamDailyLabels ?? []) ?>,
                datasets: [{
                    label: <?= json_encode(t('metric.workouts')) ?>,
                    data: <?= json_encode($teamDailyWorkouts ?? []) ?>,
                    backgroundColor: 'rgba(244, 114, 182, 0.35)',
                    borderColor: '#ec4899',
                    borderWidth: 1,
                }]
            },
            options: lineOpts
        });
    }

    const weeklyCtx = document.getElementById('teamWeeklyChart');
    if (weeklyCtx) {
        new Chart(weeklyCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode($teamWeeklyLabels ?? []) ?>,
                datasets: [
                    {
                        label: <?= json_encode(t('metric.score')) ?>,
                        data: <?= json_encode($teamWeeklyScore ?? []) ?>,
                        borderColor: '#14a38b',
                        backgroundColor: 'rgba(20, 163, 139, 0.14)',
                        fill: false,
                        tension: 0.25,
                        yAxisID: 'y',
                    },
                    {
                        label: <?= json_encode(t('metric.strikes')) ?>,
                        data: <?= json_encode($teamWeeklyStrikes ?? []) ?>,
                        borderColor: '#f97316',
                        backgroundColor: 'rgba(249, 115, 22, 0.1)',
                        fill: false,
                        tension: 0.25,
                        yAxisID: 'y1',
                    },
                    {
                        label: <?= json_encode(t('metric.penalty')) ?>,
                        data: <?= json_encode($teamWeeklyPenalties ?? []) ?>,
                        borderColor: '#ef4444',
                        backgroundColor: 'rgba(239, 68, 68, 0.1)',
                        fill: false,
                        tension: 0.25,
                        yAxisID: 'y1',
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } },
                scales: {
                    y: { beginAtZero: true, suggestedMax: 100 },
                    y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false } }
                }
            }
        });
    }
})();
</script>
