<?php

declare(strict_types=1);

$summary = $teamSummary ?? [];
$teamView = (string) ($teamView ?? 'current_week');
$teamWeekOptions = (array) ($teamWeekOptions ?? []);
$goals = (array) ($teamGoals ?? []);
$teamNotifications = (array) ($teamNotifications ?? []);
$teamSection = (string) ($teamSection ?? '');
$teamMemberDetail = is_array($teamMemberDetail ?? null) ? (array) $teamMemberDetail : null;
$nowDateTime = new DateTimeImmutable('now');

$formatInt = static fn(float|int $value): string => number_format((int) round((float) $value), 0, '.', '');
$formatKm = static fn(float|int $value): string => number_format((float) $value, 2, '.', '');
$formatScore = static fn(float|int $value): string => number_format((float) $value, 1, '.', '');
$formatMoney = static fn(float|int $value): string => number_format((float) $value, 2, '.', '');
$goalStatusLabel = static function (string $status): string {
    return match ($status) {
        'complete' => t('common.complete'),
        'archived' => t('goals.archive'),
        default => t('common.in_progress'),
    };
};

$teamBaseParams = [
    'page' => 'team',
    'team_id' => (int) ($team['id'] ?? 0),
];
if ($teamView !== '') {
    $teamBaseParams['view'] = $teamView;
}
$teamBaseUrl = '/?' . http_build_query($teamBaseParams);
$teamMetricUrl = static function (string $metric) use ($teamBaseParams): string {
    $params = $teamBaseParams;
    $params['metric'] = $metric;
    return '/?' . http_build_query($params);
};
$teamMemberUrl = static function (int $userId) use ($teamBaseParams): string {
    $params = $teamBaseParams;
    $params['section'] = 'member';
    $params['user_id'] = $userId;
    return '/?' . http_build_query($params);
};

$leaderboardRows = (array) ($teamComparisonRows ?? []);
usort(
    $leaderboardRows,
    static function (array $left, array $right): int {
        $scoreOrder = ((float) ($right['score'] ?? 0)) <=> ((float) ($left['score'] ?? 0));
        if ($scoreOrder !== 0) {
            return $scoreOrder;
        }

        $penaltyOrder = ((float) ($left['penalties'] ?? 0)) <=> ((float) ($right['penalties'] ?? 0));
        if ($penaltyOrder !== 0) {
            return $penaltyOrder;
        }

        return strcmp(
            strtolower((string) ($left['display_name'] ?? '')),
            strtolower((string) ($right['display_name'] ?? ''))
        );
    }
);

$rankByUserId = [];
foreach ($leaderboardRows as $idx => $row) {
    $rankByUserId[(int) ($row['user_id'] ?? 0)] = $idx + 1;
}

$teamMetricDetail = is_array($teamMetricDetail ?? null) ? $teamMetricDetail : null;
$teamMetricTitle = is_array($teamMetricDetail) ? (string) ($teamMetricDetail['title'] ?? '') : '';

$memberUser = is_array($teamMemberDetail['user'] ?? null) ? (array) $teamMemberDetail['user'] : [];
$memberMetric = is_array($teamMemberDetail['metric'] ?? null) ? (array) $teamMemberDetail['metric'] : [];
$memberRank = $memberUser !== [] ? ($rankByUserId[(int) ($memberUser['id'] ?? 0)] ?? null) : null;
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
            <?php if ($teamSection === 'member' && !empty($memberUser['id'])): ?>
                <input type="hidden" name="section" value="member">
                <input type="hidden" name="user_id" value="<?= (int) $memberUser['id'] ?>">
            <?php endif; ?>
            <?php if ($teamMetricDetail !== null): ?>
                <input type="hidden" name="metric" value="<?= e((string) ($teamMetricDetail['key'] ?? '')) ?>">
            <?php endif; ?>
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

    <?php if ($teamNotifications !== []): ?>
        <article class="panel team-notifications-panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow"><?= e(t('team.notifications')) ?></p>
                    <h2><?= e(t('team.notifications_title')) ?></h2>
                </div>
                <span class="badge"><?= count($teamNotifications) ?></span>
            </div>
            <div class="card-list">
                <?php foreach ($teamNotifications as $notification): ?>
                    <article class="mini-card team-notification-card">
                        <div class="team-notification-main">
                            <strong><?= e((string) ($notification['title'] ?? '')) ?></strong>
                            <p><?= e((string) ($notification['message'] ?? '')) ?></p>
                            <small class="muted"><?= e(format_date_eu((string) ($notification['created_at'] ?? ''))) ?></small>
                        </div>
                        <form method="post" action="/?page=team" class="team-notification-action">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="team_id" value="<?= (int) ($team['id'] ?? 0) ?>">
                            <input type="hidden" name="action" value="mark_notification_read">
                            <input type="hidden" name="notification_id" value="<?= (int) ($notification['id'] ?? 0) ?>">
                            <input type="hidden" name="redirect_view" value="<?= e($teamView) ?>">
                            <button class="btn btn-ghost small" type="submit"><?= e(t('common.close_action')) ?></button>
                        </form>
                    </article>
                <?php endforeach; ?>
            </div>
        </article>
    <?php endif; ?>

    <?php if ($teamSection === 'member' && $teamMemberDetail !== null && $memberUser !== [] && $memberMetric !== []): ?>
        <article class="panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow">Member detail</p>
                    <h2><?= e((string) ($memberUser['display_name'] ?? '')) ?></h2>
                    <p class="muted">@<?= e((string) ($memberUser['username'] ?? '')) ?> · <?= e(t('team.members')) ?></p>
                </div>
                <a class="btn btn-ghost" href="<?= e($teamBaseUrl) ?>">← Volver</a>
            </div>

            <div class="team-member-summary">
                <div class="team-member-avatar-wrap">
                    <?php $memberUserAvatarUrl = avatar_url($memberUser); ?>
                    <?php if ($memberUserAvatarUrl !== ''): ?>
                        <img class="member-avatar member-avatar-lg" src="<?= e($memberUserAvatarUrl) ?>" alt="<?= e((string) ($memberUser['display_name'] ?? '')) ?>">
                    <?php else: ?>
                        <span class="member-avatar member-avatar-initials member-avatar-lg"><?= e(initials_for((string) ($memberUser['display_name'] ?? ''))) ?></span>
                    <?php endif; ?>
                    <?php if (is_int($memberRank)): ?>
                        <span class="badge">#<?= (int) $memberRank ?></span>
                    <?php endif; ?>
                </div>
                <div class="team-member-stat-grid">
                    <article class="mini-card"><div><strong><?= e(t('metric.score')) ?></strong><span><?= e((string) ($memberMetric['score'] ?? 0)) ?></span></div></article>
                    <article class="mini-card"><div><strong><?= e(t('metric.steps')) ?></strong><span><?= e((string) ($memberMetric['total_steps'] ?? 0)) ?></span></div></article>
                    <article class="mini-card"><div><strong><?= e(t('metric.distance_km')) ?></strong><span><?= e((string) ($memberMetric['total_km'] ?? 0)) ?> km</span></div></article>
                    <article class="mini-card"><div><strong><?= e(t('metric.workouts')) ?></strong><span><?= e((string) ($memberMetric['workout_count'] ?? $memberMetric['workout_success'] ?? 0)) ?></span></div></article>
                    <article class="mini-card"><div><strong><?= e(t('metric.strikes')) ?></strong><span><?= e((string) ($memberMetric['current_strikes'] ?? 0)) ?></span></div></article>
                    <article class="mini-card"><div><strong><?= e(t('metric.penalty')) ?></strong><span class="penalty-chip penalty-chip-<?= (int) ($memberMetric['total_penalty'] ?? 0) <= 0 ? 'good' : ((int) ($memberMetric['total_penalty'] ?? 0) <= 50 ? 'warn' : 'bad') ?>">€<?= e((string) ($memberMetric['total_penalty'] ?? 0)) ?></span><small class="muted">Menor es mejor</small></div></article>
                </div>
            </div>
        </article>

        <div class="grid-two">
            <article class="panel chart-card">
                <h2>Steps</h2>
                <canvas id="memberStepsChart" height="170"></canvas>
            </article>
            <article class="panel chart-card">
                <h2>Distance</h2>
                <canvas id="memberDistanceChart" height="170"></canvas>
            </article>
        </div>

        <div class="grid-two">
            <article class="panel chart-card">
                <h2>Workouts by week</h2>
                <canvas id="memberWorkoutChart" height="170"></canvas>
            </article>
            <article class="panel chart-card">
                <h2>Score / Penalty by week</h2>
                <canvas id="memberScorePenaltyChart" height="170"></canvas>
            </article>
        </div>

        <div class="grid-two">
            <article class="panel">
                <div class="panel-head">
                    <h2><?= e(t('profile.recent_activity')) ?></h2>
                </div>
                <div class="audit-list">
                    <?php foreach ((array) ($teamMemberDetail['recent_activity'] ?? []) as $item): ?>
                        <article>
                            <strong><?= e((string) ($item['summary'] ?? '')) ?></strong>
                            <span><?= e((string) ($item['action'] ?? '')) ?> · <?= e(format_date_eu((string) ($item['created_at'] ?? ''))) ?></span>
                        </article>
                    <?php endforeach; ?>
                    <?php if (((array) ($teamMemberDetail['recent_activity'] ?? [])) === []): ?>
                        <p class="muted"><?= e(t('audit.empty')) ?></p>
                    <?php endif; ?>
                </div>
            </article>

            <article class="panel">
                <div class="panel-head">
                    <h2><?= e(t('profile.achievements')) ?></h2>
                    <span class="badge"><?= count((array) ($teamMemberDetail['achievements'] ?? [])) ?></span>
                </div>
                <div class="achievement-grid">
                    <?php foreach ((array) ($teamMemberDetail['achievements'] ?? []) as $achievement): ?>
                        <article class="achievement-card">
                            <?php if (!empty($achievement['image_path'])): ?>
                                <?php $teamAchievementImageUrl = media_url((string) ($achievement['image_path'] ?? '')); ?>
                                <?php if ($teamAchievementImageUrl !== ''): ?>
                                    <img src="<?= e($teamAchievementImageUrl) ?>" alt="<?= e((string) ($achievement['name'] ?? '')) ?>">
                                <?php else: ?>
                                    <span>*</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span>*</span>
                            <?php endif; ?>
                            <strong><?= e((string) ($achievement['name'] ?? '')) ?></strong>
                            <p><?= e((string) ($achievement['description'] ?? '')) ?></p>
                        </article>
                    <?php endforeach; ?>
                    <?php if (((array) ($teamMemberDetail['achievements'] ?? [])) === []): ?>
                        <p class="muted"><?= e(t('achievements.empty')) ?></p>
                    <?php endif; ?>
                </div>
            </article>
        </div>

    <?php elseif ($teamMetricDetail !== null): ?>
        <article class="panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow">Detalle de métrica</p>
                    <h2><?= e($teamMetricTitle) ?></h2>
                    <?php if ((string) ($teamMetricDetail['key'] ?? '') === 'penalty'): ?>
                        <p class="muted">Menor penalización = mejor posición.</p>
                    <?php endif; ?>
                </div>
                <a class="btn btn-ghost" href="<?= e($teamBaseUrl) ?>" data-history-back>← Volver</a>
            </div>

            <div class="team-metric-detail-grid">
                <article class="mini-card team-metric-total-card">
                    <span class="eyebrow"><?= e(t('metric.total')) ?></span>
                    <strong><?= e((string) ($teamMetricDetail['total_display'] ?? '0')) ?></strong>
                    <small class="muted"><?= e(t('team.all_members')) ?></small>
                </article>
                <article class="mini-card team-metric-chart-card">
                    <canvas id="teamMetricDetailChart" height="210"></canvas>
                </article>
            </div>

            <div class="table-wrap">
                <table class="table compact">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th><?= e(t('common.user')) ?></th>
                        <th><?= e($teamMetricTitle) ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ((array) ($teamMetricDetail['comparison_rows'] ?? []) as $idx => $row): ?>
                        <?php
                        $isPenaltyMetric = (string) ($teamMetricDetail['key'] ?? '') === 'penalty';
                        $value = (float) ($row['value'] ?? 0);
                        $severityClass = $value <= 0 ? 'good' : ($value <= 50 ? 'warn' : 'bad');
                        ?>
                        <tr class="<?= $idx === 0 ? 'team-leaderboard-top-row' : '' ?>">
                            <td><?= $idx + 1 ?></td>
                            <td><?= e((string) ($row['display_name'] ?? '')) ?></td>
                            <td>
                                <?php if ($isPenaltyMetric): ?>
                                    <span class="penalty-chip penalty-chip-<?= e($severityClass) ?>"><?= e((string) ($row['value_display'] ?? '0')) ?></span>
                                <?php else: ?>
                                    <?= e((string) ($row['value_display'] ?? '0')) ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (((array) ($teamMetricDetail['comparison_rows'] ?? [])) === []): ?>
                        <tr><td colspan="3" class="muted"><?= e(t('common.none')) ?></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>
    <?php else: ?>
        <?php
        $summarySteps = (float) ($summary['total_steps'] ?? 0);
        $summaryDistance = (float) ($summary['total_km'] ?? 0);
        $summaryWorkouts = (float) ($summary['workout_success'] ?? 0);
        $summaryWorkoutTarget = max(0, (int) ($summary['workout_target'] ?? 0));
        $summaryScore = (float) ($summary['score_avg'] ?? 0);
        $summaryStrikes = (float) ($summary['strikes'] ?? 0);
        $summaryPenalty = (float) ($summary['penalty'] ?? 0);
        $workoutProgress = $summaryWorkoutTarget > 0
            ? min(100, round(($summaryWorkouts / $summaryWorkoutTarget) * 100))
            : 0;
        ?>
        <div class="metric-grid">
            <a class="metric-card metric-card-link" href="<?= e($teamMetricUrl('steps')) ?>">
                <div class="progress-ring" style="--value: 100;"><span><?= e($formatInt($summarySteps)) ?></span></div>
                <div><span><?= e(t('metric.total_steps')) ?></span><strong><?= e($formatInt($summarySteps)) ?></strong><p><?= e(t('team.all_members')) ?></p></div>
            </a>
            <a class="metric-card metric-card-link" href="<?= e($teamMetricUrl('distance')) ?>">
                <div class="progress-ring" style="--value: <?= e((string) min(100, $summaryDistance)) ?>;"><span><?= e($formatKm($summaryDistance)) ?></span></div>
                <div><span><?= e(t('metric.total_km')) ?></span><strong><?= e($formatKm($summaryDistance)) ?> km</strong><p><?= e(t('team.all_members')) ?></p></div>
            </a>
            <a class="metric-card metric-card-link" href="<?= e($teamMetricUrl('workouts')) ?>">
                <div class="progress-ring" style="--value: <?= e((string) $workoutProgress) ?>;"><span><?= e($formatInt($summaryWorkouts)) ?></span></div>
                <div>
                    <span><?= e(t('metric.workouts')) ?></span>
                    <strong><?= e($formatInt($summaryWorkouts)) ?><?php if ($summaryWorkoutTarget > 0): ?> / <?= e($formatInt($summaryWorkoutTarget)) ?><?php endif; ?></strong>
                    <p><?= e(t('team.all_members')) ?></p>
                </div>
            </a>
            <a class="metric-card metric-card-link" href="<?= e($teamMetricUrl('score')) ?>">
                <div class="progress-ring" style="--value: <?= e((string) min(100, $summaryScore)) ?>;"><span><?= e($formatScore($summaryScore)) ?></span></div>
                <div><span><?= e(t('metric.score')) ?></span><strong><?= e($formatScore($summaryScore)) ?></strong><p><?= e(t('team.avg_score')) ?></p></div>
            </a>
            <a class="metric-card metric-card-link" href="<?= e($teamMetricUrl('strikes')) ?>">
                <div class="progress-ring" style="--value: <?= e((string) max(0, 100 - ((int) $summaryStrikes * 8))) ?>;"><span><?= e($formatInt($summaryStrikes)) ?></span></div>
                <div><span><?= e(t('metric.strikes')) ?></span><strong><?= e($formatInt($summaryStrikes)) ?></strong><p><?= e(t('team.all_members')) ?></p></div>
            </a>
            <a class="metric-card metric-card-link" href="<?= e($teamMetricUrl('penalty')) ?>">
                <div class="progress-ring" style="--value: <?= e((string) max(0, 100 - min(100, $summaryPenalty / 5))) ?>;"><span>€<?= e($formatMoney($summaryPenalty)) ?></span></div>
                <div>
                    <span><?= e(t('metric.penalty')) ?></span>
                    <strong>€<?= e($formatMoney($summaryPenalty)) ?></strong>
                    <p><?= e(t('team.all_members')) ?> · menor es mejor</p>
                </div>
            </a>
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

        <article class="panel team-leaderboard-panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow"><?= e(t('dashboard.ranking')) ?></p>
                    <h2><?= e(t('team.members')) ?></h2>
                    <p class="muted">Desempate: misma puntuación -> menor penalización gana.</p>
                </div>
            </div>
            <div class="team-leaderboard-cards">
                <?php foreach ($leaderboardRows as $idx => $row): ?>
                    <?php
                    $penaltyValue = (float) ($row['penalties'] ?? 0);
                    $penaltyClass = $penaltyValue <= 0 ? 'good' : ($penaltyValue <= 50 ? 'warn' : 'bad');
                    ?>
                    <a class="team-leaderboard-card<?= $idx === 0 ? ' is-top' : '' ?>" href="<?= e($teamMemberUrl((int) ($row['user_id'] ?? 0))) ?>" aria-label="Ver detalle de <?= e((string) ($row['display_name'] ?? '')) ?>">
                        <div class="team-leaderboard-card-head">
                            <span class="team-rank">#<?= $idx + 1 ?></span>
                            <span class="team-leaderboard-user">
                                <?php $leaderboardAvatarUrl = avatar_url($row); ?>
                                <?php if ($leaderboardAvatarUrl !== ''): ?>
                                    <img class="member-avatar" src="<?= e($leaderboardAvatarUrl) ?>" alt="<?= e((string) ($row['display_name'] ?? '')) ?>">
                                <?php else: ?>
                                    <span class="member-avatar member-avatar-initials"><?= e(initials_for((string) ($row['display_name'] ?? ''))) ?></span>
                                <?php endif; ?>
                                <span>
                                    <strong><?= e((string) ($row['display_name'] ?? '')) ?></strong>
                                    <small class="muted">@<?= e((string) ($row['username'] ?? '')) ?></small>
                                </span>
                            </span>
                            <span class="badge"><?= e(t('metric.score')) ?> <?= e($formatScore((float) ($row['score'] ?? 0))) ?></span>
                        </div>
                        <div class="team-leaderboard-metrics">
                            <span><strong><?= e($formatInt((float) ($row['steps'] ?? 0))) ?></strong><small><?= e(t('metric.steps')) ?></small></span>
                            <span><strong><?= e($formatKm((float) ($row['distance'] ?? 0))) ?> km</strong><small><?= e(t('metric.distance_km')) ?></small></span>
                            <span><strong><?= e($formatInt((float) ($row['workouts'] ?? 0))) ?></strong><small><?= e(t('metric.workouts')) ?></small></span>
                            <span><strong><?= e($formatInt((float) ($row['strikes'] ?? 0))) ?></strong><small><?= e(t('metric.strikes')) ?></small></span>
                            <span><strong class="penalty-chip penalty-chip-<?= e($penaltyClass) ?>">€<?= e($formatMoney((float) $penaltyValue)) ?></strong><small><?= e(t('metric.penalty')) ?> · menor es mejor</small></span>
                        </div>
                    </a>
                <?php endforeach; ?>
                <?php if ($leaderboardRows === []): ?>
                    <p class="muted panel-inline-empty"><?= e(t('common.none')) ?></p>
                <?php endif; ?>
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
                            <div class="member-card-title">
                                <?php $teamMemberAvatarUrl = avatar_url($member); ?>
                                <?php if ($teamMemberAvatarUrl !== ''): ?>
                                    <img class="member-avatar" src="<?= e($teamMemberAvatarUrl) ?>" alt="<?= e((string) $member['display_name']) ?>">
                                <?php else: ?>
                                    <span class="member-avatar member-avatar-initials"><?= e(initials_for((string) $member['display_name'])) ?></span>
                                <?php endif; ?>
                            </div>
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
                        <h2><?= e(t('goals.team')) ?></h2>
                    </div>
                    <?php if (!empty($canManageTeam)): ?>
                        <button class="btn btn-primary" type="button" data-team-goal-open data-goal-mode="create"><?= e(t('common.create')) ?></button>
                    <?php endif; ?>
                </div>

                <?php if ($goals === []): ?>
                    <p class="muted"><?= e(t('goals.empty')) ?></p>
                <?php else: ?>
                    <div class="card-list team-goals-list">
                        <?php foreach ($goals as $goal): ?>
                            <?php
                            $status = (string) ($goal['status'] ?? 'active');
                            $progress = (float) ($goal['progress_pct'] ?? 0);
                            $rewardText = trim((string) ($goal['reward_text'] ?? ''));
                            $dueDate = trim((string) ($goal['due_date'] ?? ''));
                            $dueTime = trim((string) ($goal['due_time_resolved'] ?? $goal['due_time'] ?? ''));
                            $dueAtRaw = trim((string) ($goal['due_at'] ?? ''));
                            $isExpired = false;
                            if ($status !== 'complete' && $dueAtRaw !== '') {
                                try {
                                    $isExpired = new DateTimeImmutable($dueAtRaw) < $nowDateTime;
                                } catch (Throwable) {
                                    $isExpired = false;
                                }
                            }
                            $goalTargetType = (string) ($goal['target_type_normalized'] ?? normalize_goal_target_type((string) ($goal['target_type'] ?? 'custom')));
                            $goalCustomUnit = $goalTargetType === 'custom' ? trim((string) ($goal['unit_label'] ?? '')) : '';
                            ?>
                            <article class="mini-card team-goal-card">
                                <div class="team-goal-main">
                                    <div class="team-goal-head">
                                        <strong><?= e((string) $goal['title']) ?></strong>
                                        <span class="team-goal-status status-<?= e($status) ?>"><?= e($goalStatusLabel($status)) ?></span>
                                    </div>
                                    <span>
                                        <?= e((string) ($goal['target_type_label'] ?? t('common.other'))) ?>
                                        · <?= e((string) ($goal['target_display'] ?? '-')) ?>
                                        <?php if ($dueDate !== ''): ?>
                                            · <?= e(format_date_eu($dueDate)) ?><?= $dueTime !== '' ? ' ' . e($dueTime) : '' ?>
                                        <?php endif; ?>
                                        <?php if ($isExpired): ?> · <?= e(t('goals.expired')) ?><?php endif; ?>
                                    </span>
                                    <span><?= e((string) ($goal['progress_display'] ?? '0')) ?> / <?= e((string) ($goal['target_display'] ?? '-')) ?></span>
                                    <small class="muted"><?= e(t('goals.started_from')) ?>: <?= e((string) ($goal['baseline_display'] ?? '0')) ?></small>
                                    <small class="muted"><?= e((string) ($goal['direction_label'] ?? '')) ?></small>
                                    <?php if ($rewardText !== ''): ?>
                                        <small class="team-goal-reward"><?= e(t('achievements.reward')) ?>: <?= e($rewardText) ?></small>
                                    <?php endif; ?>
                                    <div class="goal-progress"><span style="width: <?= e((string) $progress) ?>%"></span></div>
                                </div>
                                <?php if (!empty($canManageTeam)): ?>
                                    <div class="team-goal-actions">
                                        <button
                                            class="btn small btn-ghost"
                                            type="button"
                                            data-team-goal-open
                                            data-goal-mode="edit"
                                            data-goal-id="<?= (int) $goal['id'] ?>"
                                            data-goal-title="<?= e((string) ($goal['title'] ?? '')) ?>"
                                            data-goal-target-type="<?= e($goalTargetType) ?>"
                                            data-goal-target-value="<?= e((string) ($goal['target_value'] ?? '')) ?>"
                                            data-goal-custom-unit="<?= e($goalCustomUnit) ?>"
                                            data-goal-reward-text="<?= e($rewardText) ?>"
                                            data-goal-due-date="<?= e($dueDate) ?>"
                                            data-goal-due-time="<?= e($dueTime) ?>"
                                        ><?= e(t('common.edit')) ?></button>
                                        <form method="post" action="/?page=team" class="inline-actions-mini">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="team_id" value="<?= (int) ($team['id'] ?? 0) ?>">
                                            <input type="hidden" name="goal_id" value="<?= (int) $goal['id'] ?>">
                                            <input type="hidden" name="redirect_view" value="<?= e($teamView) ?>">
                                            <input type="hidden" name="status" value="active">
                                            <button class="btn small btn-ghost" name="action" value="goal_status" type="submit" onclick="this.form.status.value='complete'"><?= e(t('common.complete')) ?></button>
                                            <button class="btn small btn-ghost" name="action" value="goal_status" type="submit" onclick="this.form.status.value='archived'"><?= e(t('goals.archive')) ?></button>
                                            <button class="btn small btn-ghost" name="action" value="delete_goal" type="submit" onclick="return window.confirm('¿Eliminar objetivo?');"><?= e(t('common.delete')) ?></button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
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
                            <?php if (!empty($achievement['image_path'])): ?>
                                <?php $teamAwardImageUrl = media_url((string) ($achievement['image_path'] ?? '')); ?>
                                <?php if ($teamAwardImageUrl !== ''): ?>
                                    <img src="<?= e($teamAwardImageUrl) ?>" alt="<?= e((string) $achievement['name']) ?>">
                                <?php else: ?>
                                    <span>*</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span>*</span>
                            <?php endif; ?>
                            <strong><?= e((string) $achievement['name']) ?></strong>
                            <p><?= e((string) $achievement['description']) ?></p>
                            <?php if (!empty($achievement['reward_text'])): ?><small><?= e(t('achievements.reward')) ?>: <?= e((string) $achievement['reward_text']) ?></small><?php endif; ?>
                            <small><?= e((string) $achievement['awarded_at']) ?></small>
                            <?php if (!empty($canDeleteAchievements)): ?>
                                <form method="post" action="/?page=team" class="achievement-remove" id="<?= e($deleteFormId) ?>">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="team_id" value="<?= (int) ($team['id'] ?? 0) ?>">
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

        <div class="confirm-modal" hidden aria-hidden="true" data-team-goal-modal>
            <div class="confirm-modal-backdrop" data-team-goal-close></div>
            <div class="confirm-modal-card team-goal-modal-card" role="dialog" aria-modal="true" aria-labelledby="team-goal-title">
                <h3 id="team-goal-title" data-team-goal-modal-title data-title-create="<?= e(t('goals.create_team_goal')) ?>" data-title-edit="<?= e(t('goals.edit_team_goal')) ?>"><?= e(t('goals.create_team_goal')) ?></h3>
                <form method="post" action="/?page=team" class="stack compact-form" data-team-goal-form>
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="team_id" value="<?= (int) ($team['id'] ?? 0) ?>">
                    <input type="hidden" name="redirect_view" value="<?= e($teamView) ?>">
                    <input type="hidden" name="action" value="create_goal" data-team-goal-action>
                    <input type="hidden" name="goal_id" value="" data-team-goal-id>
                    <label>
                        <?= e(t('goals.goal_name')) ?>
                        <input type="text" name="title" placeholder="<?= e(t('goals.team_placeholder')) ?>" required data-goal-title-input>
                    </label>
                    <label>
                        <?= e(t('goals.type')) ?>
                        <select name="target_type" data-goal-type-select>
                            <option value="steps" data-goal-placeholder="200000" data-goal-lower-better="0"><?= e(t('metric.steps')) ?></option>
                            <option value="km" data-goal-placeholder="50 km" data-goal-lower-better="0"><?= e(t('metric.distance_km')) ?></option>
                            <option value="workouts" data-goal-placeholder="12 workouts" data-goal-lower-better="0"><?= e(t('metric.workouts')) ?></option>
                            <option value="score" data-goal-placeholder="40 pts" data-goal-lower-better="0"><?= e(t('metric.score')) ?></option>
                            <option value="calories_burned" data-goal-placeholder="12000 kcal" data-goal-lower-better="0"><?= e(t('dashboard.calories_burned')) ?></option>
                            <option value="calories_consumed" data-goal-placeholder="12000 kcal" data-goal-lower-better="1"><?= e(t('dashboard.calories_consumed')) ?></option>
                            <option value="penalties" data-goal-placeholder="30 €" data-goal-lower-better="1"><?= e(t('metric.penalty')) ?></option>
                            <option value="strikes" data-goal-placeholder="3 strikes" data-goal-lower-better="1"><?= e(t('metric.strikes')) ?></option>
                            <option value="weight" data-goal-placeholder="4 %" data-goal-lower-better="0"><?= e(t('metric.weight')) ?></option>
                            <option value="custom"><?= e(t('common.other')) ?></option>
                        </select>
                    </label>
                    <label>
                        <?= e(t('goals.target')) ?>
                        <input type="number" step="0.1" name="target_value" value="" required data-goal-target-input>
                    </label>
                    <p class="muted small team-goal-form-helper"><?= e(t('goals.progress_starts_zero')) ?></p>
                    <p class="muted small team-goal-form-helper" data-goal-direction-label><?= e(t('goals.higher_better')) ?></p>
                    <label data-goal-custom-unit-wrap hidden>
                        <?= e(t('goals.custom_unit')) ?>
                        <input type="text" name="custom_unit" maxlength="24" placeholder="<?= e(t('goals.custom_unit_placeholder')) ?>">
                    </label>
                    <label class="check team-goal-reward-toggle">
                        <input type="checkbox" name="reward_enabled" value="1" data-goal-reward-toggle>
                        <?= e(t('goals.add_reward')) ?>
                    </label>
                    <label data-goal-reward-wrap hidden>
                        <?= e(t('achievements.reward')) ?>
                        <input type="text" name="reward_text" maxlength="120" placeholder="<?= e(t('goals.reward_placeholder')) ?>">
                    </label>
                    <label>
                        <?= e(t('goals.due_date')) ?>
                        <input type="date" name="due_date" data-goal-due-date-input>
                    </label>
                    <label>
                        <?= e(t('goals.due_time')) ?>
                        <input type="time" name="due_time" data-goal-due-time-input>
                    </label>
                    <div class="confirm-modal-actions">
                        <button class="btn btn-ghost" type="button" data-team-goal-close><?= e(t('common.cancel')) ?></button>
                        <button class="btn btn-primary" type="submit" data-team-goal-submit data-label-create="<?= e(t('common.create')) ?>" data-label-save="<?= e(t('common.save')) ?>"><?= e(t('common.create')) ?></button>
                    </div>
                </form>
            </div>
        </div>

        <?php if (!empty($canManageTeam)): ?>
            <a class="btn btn-secondary btn-block team-settings-bottom" href="/?page=team_settings&team_id=<?= (int) $team['id'] ?>"><?= e(t('team.settings')) ?></a>
        <?php endif; ?>
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

    const metricDetailCtx = document.getElementById('teamMetricDetailChart');
    if (metricDetailCtx) {
        new Chart(metricDetailCtx, {
            type: <?= json_encode((string) ($teamMetricDetail['chart_type'] ?? 'line')) ?>,
            data: {
                labels: <?= json_encode((array) ($teamMetricDetail['chart_labels'] ?? [])) ?>,
                datasets: [{
                    label: <?= json_encode((string) ($teamMetricDetail['title'] ?? 'Metric')) ?>,
                    data: <?= json_encode((array) ($teamMetricDetail['chart_values'] ?? [])) ?>,
                    borderColor: <?= json_encode((string) ($teamMetricDetail['chart_color'] ?? '#14a38b')) ?>,
                    backgroundColor: <?= json_encode((string) ($teamMetricDetail['chart_fill'] ?? 'rgba(20, 163, 139, 0.16)')) ?>,
                    fill: <?= ((string) ($teamMetricDetail['chart_type'] ?? 'line')) === 'bar' ? 'false' : 'true' ?>,
                    tension: 0.3,
                    borderWidth: 2,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } },
                scales: {
                    y: { beginAtZero: true }
                }
            }
        });
    }

    const memberStepsCtx = document.getElementById('memberStepsChart');
    if (memberStepsCtx) {
        new Chart(memberStepsCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode((array) ($teamMemberDetail['steps_labels'] ?? [])) ?>,
                datasets: [{
                    label: <?= json_encode(t('metric.steps')) ?>,
                    data: <?= json_encode((array) ($teamMemberDetail['steps_values'] ?? [])) ?>,
                    borderColor: '#14a38b',
                    backgroundColor: 'rgba(20, 163, 139, 0.16)',
                    fill: true,
                    tension: 0.28,
                }]
            },
            options: lineOpts
        });
    }

    const memberDistanceCtx = document.getElementById('memberDistanceChart');
    if (memberDistanceCtx) {
        new Chart(memberDistanceCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode((array) ($teamMemberDetail['steps_labels'] ?? [])) ?>,
                datasets: [{
                    label: <?= json_encode(t('metric.distance_km')) ?>,
                    data: <?= json_encode((array) ($teamMemberDetail['distance_values'] ?? [])) ?>,
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.14)',
                    fill: true,
                    tension: 0.28,
                }]
            },
            options: lineOpts
        });
    }

    const memberWorkoutCtx = document.getElementById('memberWorkoutChart');
    if (memberWorkoutCtx) {
        new Chart(memberWorkoutCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode((array) ($teamMemberDetail['weekly_labels'] ?? [])) ?>,
                datasets: [{
                    label: <?= json_encode(t('metric.workouts')) ?>,
                    data: <?= json_encode((array) ($teamMemberDetail['workout_weekly'] ?? [])) ?>,
                    backgroundColor: 'rgba(244, 114, 182, 0.35)',
                    borderColor: '#ec4899',
                    borderWidth: 1,
                }]
            },
            options: lineOpts
        });
    }

    const memberScorePenaltyCtx = document.getElementById('memberScorePenaltyChart');
    if (memberScorePenaltyCtx) {
        new Chart(memberScorePenaltyCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode((array) ($teamMemberDetail['weekly_labels'] ?? [])) ?>,
                datasets: [
                    {
                        label: <?= json_encode(t('metric.score')) ?>,
                        data: <?= json_encode((array) ($teamMemberDetail['score_weekly'] ?? [])) ?>,
                        borderColor: '#14a38b',
                        backgroundColor: 'rgba(20, 163, 139, 0.14)',
                        fill: false,
                        tension: 0.25,
                        yAxisID: 'y',
                    },
                    {
                        label: <?= json_encode(t('metric.penalty')) ?>,
                        data: <?= json_encode((array) ($teamMemberDetail['penalty_weekly'] ?? [])) ?>,
                        borderColor: '#ef4444',
                        backgroundColor: 'rgba(239, 68, 68, 0.12)',
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

    const goalModal = document.querySelector('[data-team-goal-modal]');
    if (goalModal instanceof HTMLElement) {
        const openButtons = document.querySelectorAll('[data-team-goal-open]');
        const closeButtons = goalModal.querySelectorAll('[data-team-goal-close]');
        const goalForm = goalModal.querySelector('[data-team-goal-form]');
        const firstInput = goalModal.querySelector('[data-goal-title-input]');
        const modalTitle = goalModal.querySelector('[data-team-goal-modal-title]');
        const goalActionInput = goalModal.querySelector('[data-team-goal-action]');
        const goalIdInput = goalModal.querySelector('[data-team-goal-id]');
        const goalTypeSelect = goalModal.querySelector('[data-goal-type-select]');
        const targetInput = goalModal.querySelector('[data-goal-target-input]');
        const dueDateInput = goalModal.querySelector('[data-goal-due-date-input]');
        const dueTimeInput = goalModal.querySelector('[data-goal-due-time-input]');
        const directionLabel = goalModal.querySelector('[data-goal-direction-label]');
        const customUnitWrap = goalModal.querySelector('[data-goal-custom-unit-wrap]');
        const customUnitInput = goalModal.querySelector('input[name="custom_unit"]');
        const rewardToggle = goalModal.querySelector('[data-goal-reward-toggle]');
        const rewardWrap = goalModal.querySelector('[data-goal-reward-wrap]');
        const rewardInput = goalModal.querySelector('input[name="reward_text"]');
        const submitButton = goalModal.querySelector('[data-team-goal-submit]');
        const modalCard = goalModal.querySelector('.confirm-modal-card');
        const directionLowerText = <?= json_encode(t('goals.lower_better')) ?>;
        const directionHigherText = <?= json_encode(t('goals.higher_better')) ?>;
        const defaultPlaceholder = '100';
        let opener = null;

        const updateGoalFormByType = () => {
            if (!(goalTypeSelect instanceof HTMLSelectElement)) {
                return;
            }
            const selected = goalTypeSelect.selectedOptions.length > 0 ? goalTypeSelect.selectedOptions[0] : null;
            const isCustom = goalTypeSelect.value === 'custom';
            if (customUnitWrap instanceof HTMLElement) {
                customUnitWrap.hidden = !isCustom;
            }
            if (!isCustom && customUnitInput instanceof HTMLInputElement) {
                customUnitInput.value = '';
            }

            if (targetInput instanceof HTMLInputElement) {
                const placeholder = selected instanceof HTMLOptionElement
                    ? String(selected.dataset.goalPlaceholder || '').trim()
                    : '';
                targetInput.placeholder = placeholder !== '' ? placeholder : defaultPlaceholder;
            }

            if (directionLabel instanceof HTMLElement) {
                const lowerBetter = selected instanceof HTMLOptionElement
                    ? String(selected.dataset.goalLowerBetter || '0') === '1'
                    : false;
                directionLabel.textContent = lowerBetter ? directionLowerText : directionHigherText;
            }
        };

        const updateRewardVisibility = () => {
            const enabled = rewardToggle instanceof HTMLInputElement && rewardToggle.checked;
            if (rewardWrap instanceof HTMLElement) {
                rewardWrap.hidden = !enabled;
            }
            if (!enabled && rewardInput instanceof HTMLInputElement) {
                rewardInput.value = '';
            }
        };

        const resetAnchoredPosition = () => {
            goalModal.classList.remove('team-goal-modal-anchored');
            if (modalCard instanceof HTMLElement) {
                modalCard.style.top = '';
                modalCard.style.left = '';
            }
        };

        const applyAnchoredPosition = (trigger) => {
            resetAnchoredPosition();
            if (!(trigger instanceof HTMLElement) || !(modalCard instanceof HTMLElement)) {
                return;
            }
            if (!window.matchMedia('(max-width: 768px)').matches) {
                return;
            }
            const triggerRect = trigger.getBoundingClientRect();
            goalModal.classList.add('team-goal-modal-anchored');
            window.requestAnimationFrame(() => {
                const padding = 10;
                const cardRect = modalCard.getBoundingClientRect();
                const baseTop = triggerRect.bottom + 8;
                const baseLeft = triggerRect.left;
                const maxTop = Math.max(padding, window.innerHeight - padding - cardRect.height);
                const maxLeft = Math.max(padding, window.innerWidth - padding - cardRect.width);
                const top = Math.max(padding, Math.min(baseTop, maxTop));
                const left = Math.max(padding, Math.min(baseLeft, maxLeft));
                modalCard.style.top = `${top}px`;
                modalCard.style.left = `${left}px`;
            });
        };

        const closeModal = () => {
            goalModal.hidden = true;
            goalModal.setAttribute('aria-hidden', 'true');
            goalModal.classList.remove('is-open');
            resetAnchoredPosition();
            if (opener instanceof HTMLElement) {
                opener.focus();
            }
            opener = null;
        };

        const openModal = (trigger) => {
            const mode = trigger instanceof HTMLElement
                ? String(trigger.dataset.goalMode || 'create').trim().toLowerCase()
                : 'create';
            const isEdit = mode === 'edit';
            opener = trigger instanceof HTMLElement ? trigger : null;
            if (goalForm instanceof HTMLFormElement) {
                goalForm.reset();
            }
            if (goalActionInput instanceof HTMLInputElement) {
                goalActionInput.value = isEdit ? 'update_goal' : 'create_goal';
            }
            if (goalIdInput instanceof HTMLInputElement) {
                goalIdInput.value = isEdit && trigger instanceof HTMLElement ? String(trigger.dataset.goalId || '').trim() : '';
            }
            if (firstInput instanceof HTMLInputElement) {
                firstInput.value = isEdit && trigger instanceof HTMLElement ? String(trigger.dataset.goalTitle || '').trim() : '';
            }
            if (goalTypeSelect instanceof HTMLSelectElement) {
                const nextType = isEdit && trigger instanceof HTMLElement
                    ? String(trigger.dataset.goalTargetType || '').trim()
                    : 'steps';
                const hasOption = [...goalTypeSelect.options].some((option) => option.value === nextType);
                goalTypeSelect.value = hasOption ? nextType : 'custom';
            }
            if (targetInput instanceof HTMLInputElement) {
                targetInput.value = isEdit && trigger instanceof HTMLElement ? String(trigger.dataset.goalTargetValue || '').trim() : '';
            }
            if (customUnitInput instanceof HTMLInputElement) {
                customUnitInput.value = isEdit && trigger instanceof HTMLElement ? String(trigger.dataset.goalCustomUnit || '').trim() : '';
            }
            if (dueDateInput instanceof HTMLInputElement) {
                dueDateInput.value = isEdit && trigger instanceof HTMLElement ? String(trigger.dataset.goalDueDate || '').trim() : '';
            }
            if (dueTimeInput instanceof HTMLInputElement) {
                dueTimeInput.value = isEdit && trigger instanceof HTMLElement ? String(trigger.dataset.goalDueTime || '').trim() : '';
            }
            if (rewardInput instanceof HTMLInputElement) {
                rewardInput.value = isEdit && trigger instanceof HTMLElement ? String(trigger.dataset.goalRewardText || '').trim() : '';
            }
            if (rewardToggle instanceof HTMLInputElement) {
                rewardToggle.checked = rewardInput instanceof HTMLInputElement && rewardInput.value.trim() !== '';
            }
            if (submitButton instanceof HTMLButtonElement) {
                submitButton.textContent = isEdit
                    ? String(submitButton.dataset.labelSave || 'Save')
                    : String(submitButton.dataset.labelCreate || 'Create');
            }
            if (modalTitle instanceof HTMLElement) {
                modalTitle.textContent = isEdit
                    ? String(modalTitle.dataset.titleEdit || 'Edit team goal')
                    : String(modalTitle.dataset.titleCreate || 'Create team goal');
            }
            goalModal.hidden = false;
            goalModal.setAttribute('aria-hidden', 'false');
            goalModal.classList.add('is-open');
            updateGoalFormByType();
            updateRewardVisibility();
            applyAnchoredPosition(trigger);
            if (firstInput instanceof HTMLElement) {
                window.setTimeout(() => firstInput.focus(), 0);
            }
        };

        openButtons.forEach((button) => {
            button.addEventListener('click', () => openModal(button));
        });
        closeButtons.forEach((button) => {
            button.addEventListener('click', closeModal);
        });
        if (goalTypeSelect instanceof HTMLSelectElement) {
            goalTypeSelect.addEventListener('change', updateGoalFormByType);
            updateGoalFormByType();
        }
        if (rewardToggle instanceof HTMLInputElement) {
            rewardToggle.addEventListener('change', updateRewardVisibility);
            updateRewardVisibility();
        }

        window.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !goalModal.hidden) {
                closeModal();
            }
        });

        window.addEventListener('resize', () => {
            if (!goalModal.hidden && opener instanceof HTMLElement) {
                applyAnchoredPosition(opener);
            }
        });
    }

    document.querySelectorAll('[data-history-back]').forEach((link) => {
        if (!(link instanceof HTMLAnchorElement)) {
            return;
        }
        link.addEventListener('click', (event) => {
            if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }
            const hasSameOriginReferrer = document.referrer !== ''
                && (() => {
                    try {
                        return new URL(document.referrer).origin === window.location.origin;
                    } catch {
                        return false;
                    }
                })();
            if (window.history.length > 1 && hasSameOriginReferrer) {
                event.preventDefault();
                window.history.back();
            }
        });
    });
})();
</script>
