<?php

declare(strict_types=1);

$summary = $teamSummary ?? [];
$teamView = (string) ($teamView ?? 'current_week');
$teamWeekOptions = (array) ($teamWeekOptions ?? []);
$goals = (array) ($teamGoals ?? []);
$activeChallenge = is_array($teamActiveChallenge ?? null) ? (array) $teamActiveChallenge : null;
$teamSection = (string) ($teamSection ?? '');
$teamMemberDetail = is_array($teamMemberDetail ?? null) ? (array) $teamMemberDetail : null;
$nowDateTime = new DateTimeImmutable('now');

$formatInt = static fn(float|int $value): string => number_format((int) round((float) $value), 0, '.', '');
$formatKm = static fn(float|int $value): string => number_format((float) $value, 2, '.', '');
$formatScore = static fn(float|int $value): string => number_format((float) $value, 1, '.', '');
$formatMoney = static fn(float|int $value): string => number_format((float) $value, 2, '.', '');
$formatPercent = static function (float|int $value): string {
    $rounded = round((float) $value, 1);
    if (abs($rounded - round($rounded)) < 0.00001) {
        return (string) (int) round($rounded);
    }

    return number_format($rounded, 1, '.', '');
};
$goalStatusLabel = static function (string $status): string {
    return match ($status) {
        'complete' => t('common.complete'),
        'archived' => t('goals.archive'),
        default => t('common.in_progress'),
    };
};
$formatCountdownFromNow = static function (?DateTimeImmutable $deadline, DateTimeImmutable $now): string {
    if (!$deadline instanceof DateTimeImmutable || $deadline <= $now) {
        return '0d 00h 00m 00s';
    }
    $seconds = $deadline->getTimestamp() - $now->getTimestamp();
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $secs = $seconds % 60;

    return sprintf('%dd %02dh %02dm %02ds', $days, $hours, $minutes, $secs);
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

    <?php if ($activeChallenge !== null): ?>
        <?php
        $activeRewardText = trim((string) ($activeChallenge['reward_text'] ?? ''));
        $activeProgressRaw = (float) ($activeChallenge['progress_pct_raw'] ?? 0);
        $activeProgressVisual = (float) ($activeChallenge['progress_pct_visual'] ?? max(0, min(100, $activeProgressRaw)));
        $activeIsPreStart = !empty($activeChallenge['is_pre_start']);
        $activeIsExpired = (bool) ($activeChallenge['is_expired'] ?? false);
        $activeStatusText = $activeIsExpired
            ? t('goals.expired')
            : ($activeIsPreStart ? t('team.active_challenge_starts_in') : t('team.active_challenge_started'));
        $activeStatusClass = $activeIsExpired
            ? 'status-expired'
            : ($activeIsPreStart ? 'status-pending' : 'status-active');
        $activeStartDate = trim((string) ($activeChallenge['start_date_resolved'] ?? ''));
        $activeStartTime = trim((string) ($activeChallenge['start_time_resolved'] ?? ''));
        $activeCountdownLabel = trim((string) ($activeChallenge['countdown_label'] ?? t('team.active_challenge_time_left')));
        $activeCountdownMode = trim((string) ($activeChallenge['countdown_mode'] ?? ($activeIsPreStart ? 'start' : 'end')));
        $countdownDeadlineIso = trim((string) ($activeChallenge['countdown_deadline_iso'] ?? ''));
        $countdownNextDeadlineIso = trim((string) ($activeChallenge['countdown_next_deadline_iso'] ?? ''));
        $countdownDeadline = null;
        if ($countdownDeadlineIso !== '') {
            try {
                $countdownDeadline = new DateTimeImmutable($countdownDeadlineIso);
            } catch (Throwable) {
                $countdownDeadline = null;
            }
        }
        $countdownText = $formatCountdownFromNow($countdownDeadline, $nowDateTime);
        ?>
        <article class="panel team-active-challenge-panel<?= $activeIsExpired ? ' is-expired' : '' ?><?= $activeIsPreStart ? ' is-pending' : '' ?>" data-active-challenge-panel>
            <div class="panel-head team-active-challenge-head">
                <div>
                    <p class="eyebrow"><?= e(t('team.active_challenge_title')) ?></p>
                    <h2><?= e((string) ($activeChallenge['title'] ?? t('team.challenges'))) ?></h2>
                </div>
                <span class="team-active-challenge-status <?= e($activeStatusClass) ?>" data-active-challenge-status><?= e((string) $activeStatusText) ?></span>
            </div>
            <div class="team-active-challenge-grid">
                <div class="team-active-challenge-main">
                    <div class="team-active-challenge-values">
                        <span><?= e((string) ($activeChallenge['progress_display'] ?? '0')) ?></span>
                        <small><?= e(t('team.active_challenge_progress')) ?></small>
                    </div>
                    <div class="goal-progress-wrap team-active-challenge-progress">
                        <div class="goal-progress"><span style="width: <?= e((string) $activeProgressVisual) ?>%"></span></div>
                        <small><?= e($formatPercent($activeProgressRaw)) ?>%</small>
                    </div>
                </div>
                <div class="team-active-challenge-meta">
                    <span><strong><?= e(t('team.active_challenge_metric')) ?></strong><small><?= e((string) ($activeChallenge['target_type_label'] ?? t('common.other'))) ?></small></span>
                    <span><strong><?= e(t('team.active_challenge_target')) ?></strong><small><?= e((string) ($activeChallenge['target_display'] ?? '-')) ?></small></span>
                    <span><strong><?= e(t('goals.start_date')) ?></strong><small><?= $activeStartDate !== '' ? e(format_date_eu($activeStartDate)) . ($activeStartTime !== '' ? ' ' . e($activeStartTime) : '') : '-' ?></small></span>
                    <span><strong><?= e(t('team.active_challenge_reward')) ?></strong><small><?= e($activeRewardText !== '' ? $activeRewardText : t('team.active_challenge_reward_none')) ?></small></span>
                    <span><strong><?= e(t('team.active_challenge_status')) ?></strong><small data-active-challenge-status-text><?= e((string) $activeStatusText) ?></small></span>
                    <span><strong data-active-challenge-countdown-label><?= e($activeCountdownLabel) ?></strong><small data-active-challenge-countdown data-challenge-countdown data-countdown-mode="<?= e($activeCountdownMode) ?>" data-countdown-pending-label="<?= e(t('team.active_challenge_starts_in')) ?>" data-countdown-active-label="<?= e(t('team.active_challenge_started')) ?>" data-countdown-expired-label="<?= e(t('goals.expired')) ?>"<?= $countdownNextDeadlineIso !== '' ? ' data-countdown-next-deadline="' . e($countdownNextDeadlineIso) . '"' : '' ?><?= $countdownDeadlineIso !== '' ? ' data-deadline="' . e($countdownDeadlineIso) . '"' : '' ?>><?= e($countdownText) ?></small></span>
                </div>
            </div>
        </article>
    <?php endif; ?>

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

    <?php if ($teamSection === 'member' && $teamMemberDetail !== null && $memberUser !== [] && $memberMetric !== []): ?>
        <article class="panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow"><?= e(t('team.member_detail')) ?></p>
                    <h2><?= e((string) ($memberUser['display_name'] ?? '')) ?></h2>
                    <p class="muted">@<?= e((string) ($memberUser['username'] ?? '')) ?> · <?= e(t('team.members')) ?></p>
                </div>
                <a class="btn btn-ghost" href="<?= e($teamBaseUrl) ?>">← <?= e(t('common.back')) ?></a>
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
                    <article class="mini-card"><div><strong><?= e(t('metric.penalty')) ?></strong><span class="penalty-chip penalty-chip-<?= (int) ($memberMetric['total_penalty'] ?? 0) <= 0 ? 'good' : ((int) ($memberMetric['total_penalty'] ?? 0) <= 50 ? 'warn' : 'bad') ?>">€<?= e((string) ($memberMetric['total_penalty'] ?? 0)) ?></span><small class="muted"><?= e(t('team.lower_is_better')) ?></small></div></article>
                </div>
            </div>
        </article>

        <div class="grid-two">
            <article class="panel chart-card">
                <h2><?= e(t('metric.steps')) ?></h2>
                <canvas id="memberStepsChart" height="170"></canvas>
            </article>
            <article class="panel chart-card">
                <h2><?= e(t('metric.distance_km')) ?></h2>
                <canvas id="memberDistanceChart" height="170"></canvas>
            </article>
        </div>

        <div class="grid-two">
            <article class="panel chart-card">
                <h2><?= e(t('team.workouts_by_week')) ?></h2>
                <canvas id="memberWorkoutChart" height="170"></canvas>
            </article>
            <article class="panel chart-card">
                <h2><?= e(t('team.score_penalty_by_week')) ?></h2>
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
                    <p class="eyebrow"><?= e(t('team.metric_detail')) ?></p>
                    <h2><?= e($teamMetricTitle) ?></h2>
                    <?php if ((string) ($teamMetricDetail['key'] ?? '') === 'penalty'): ?>
                        <p class="muted"><?= e(t('team.penalty_rank_hint')) ?></p>
                    <?php endif; ?>
                </div>
                <a class="btn btn-ghost" href="<?= e($teamBaseUrl) ?>" data-history-back>← <?= e(t('common.back')) ?></a>
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
                    <p><?= e(t('team.all_members')) ?> · <?= e(t('team.lower_is_better')) ?></p>
                </div>
            </a>
        </div>

        <div class="grid-two">
            <article class="panel chart-card">
                <h2><?= e(t('team.steps_over_time')) ?></h2>
                <canvas id="teamStepsChart" height="170"></canvas>
            </article>
            <article class="panel chart-card">
                <h2><?= e(t('team.distance_over_time')) ?></h2>
                <canvas id="teamDistanceChart" height="170"></canvas>
            </article>
        </div>

        <div class="grid-two">
            <article class="panel chart-card">
                <h2><?= e(t('team.workouts_over_time')) ?></h2>
                <canvas id="teamWorkoutsChart" height="170"></canvas>
            </article>
            <article class="panel chart-card">
                <h2><?= e(t('team.score_strikes_penalties')) ?></h2>
                <canvas id="teamWeeklyChart" height="170"></canvas>
            </article>
        </div>

        <article class="panel team-leaderboard-panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow"><?= e(t('dashboard.ranking')) ?></p>
                    <h2><?= e(t('team.members')) ?></h2>
                </div>
                <details class="metric-help-popover">
                    <summary aria-label="<?= e(t('team.leaderboard_help_label')) ?>" title="<?= e(t('team.leaderboard_help_label')) ?>">?</summary>
                    <div class="metric-help-popover-content"><?= e(t('team.leaderboard_help_text')) ?></div>
                </details>
            </div>
            <div class="team-leaderboard-cards">
                <?php foreach ($leaderboardRows as $idx => $row): ?>
                    <?php
                    $penaltyValue = (float) ($row['penalties'] ?? 0);
                    $penaltyClass = $penaltyValue <= 0 ? 'good' : ($penaltyValue <= 50 ? 'warn' : 'bad');
                    ?>
                    <a class="team-leaderboard-card<?= $idx === 0 ? ' is-top' : '' ?>" href="<?= e($teamMemberUrl((int) ($row['user_id'] ?? 0))) ?>" aria-label="<?= e(t('team.view_member_detail', ['name' => (string) ($row['display_name'] ?? '')])) ?>">
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
                            <span><strong class="penalty-chip penalty-chip-<?= e($penaltyClass) ?>">€<?= e($formatMoney((float) $penaltyValue)) ?></strong><small><?= e(t('metric.penalty')) ?></small></span>
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
                            <a class="btn small btn-ghost" href="/?page=profile&user_id=<?= (int) $member['user_id'] ?>" aria-label="<?= e(t('team.view_profile_of', ['name' => (string) $member['username']])) ?>"><?= e(t('team.view_profile')) ?></a>
                        </article>
                    <?php endforeach; ?>
                </div>
            </article>

            <article class="panel">
                <div class="panel-head">
                    <div>
                        <p class="eyebrow"><?= e(t('team.challenges')) ?></p>
                        <h2><?= e(t('team.challenges')) ?></h2>
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
                            $progressRaw = (float) ($goal['progress_pct_raw'] ?? $goal['progress_pct'] ?? 0);
                            $progressVisual = (float) ($goal['progress_pct_visual'] ?? max(0, min(100, $progressRaw)));
                            $rewardText = trim((string) ($goal['reward_text'] ?? ''));
                            $startDate = trim((string) ($goal['start_date_resolved'] ?? $goal['start_date'] ?? ''));
                            $startTime = trim((string) ($goal['start_time_resolved'] ?? $goal['start_time'] ?? ''));
                            $hasStarted = !empty($goal['has_started']);
                            $dueDate = trim((string) ($goal['due_date'] ?? ''));
                            $dueTime = trim((string) ($goal['due_time_resolved'] ?? $goal['due_time'] ?? ''));
                            $countdownMode = trim((string) ($goal['countdown_mode'] ?? 'end'));
                            $countdownDeadlineIso = trim((string) ($goal['countdown_deadline_iso'] ?? ''));
                            $countdownDeadline = null;
                            if ($countdownDeadlineIso !== '') {
                                try {
                                    $countdownDeadline = new DateTimeImmutable($countdownDeadlineIso);
                                } catch (Throwable) {
                                    $countdownDeadline = null;
                                }
                            }
                            $countdownText = $formatCountdownFromNow($countdownDeadline, $nowDateTime);
                            $isExpired = (bool) ($goal['is_expired'] ?? false);
                            $statusBadgeClass = $status;
                            $statusBadgeText = $goalStatusLabel($status);
                            if ($status === 'active' && !$hasStarted) {
                                $statusBadgeClass = 'pending';
                                $statusBadgeText = t('common.pending');
                            } elseif ($status === 'active' && $isExpired) {
                                $statusBadgeClass = 'expired';
                                $statusBadgeText = t('goals.expired');
                            }
                            $goalTargetType = (string) ($goal['target_type_normalized'] ?? normalize_goal_target_type((string) ($goal['target_type'] ?? 'custom')));
                            $goalCustomUnit = $goalTargetType === 'custom' ? trim((string) ($goal['unit_label'] ?? '')) : '';
                            ?>
                            <article class="mini-card team-goal-card">
                                <div class="team-goal-main">
                                    <div class="team-goal-head">
                                        <strong><?= e((string) $goal['title']) ?></strong>
                                        <span class="team-goal-status status-<?= e($statusBadgeClass) ?>"><?= e($statusBadgeText) ?></span>
                                    </div>
                                    <span>
                                        <?= e((string) ($goal['target_type_label'] ?? t('common.other'))) ?>
                                        · <?= e((string) ($goal['target_display'] ?? '-')) ?>
                                        <?php if ($startDate !== ''): ?>
                                            · <?= e(t('goals.start_date')) ?>: <?= e(format_date_eu($startDate)) ?><?= $startTime !== '' ? ' ' . e($startTime) : '' ?>
                                        <?php endif; ?>
                                        <?php if ($dueDate !== ''): ?>
                                            · <?= e(t('goals.due_date')) ?>: <?= e(format_date_eu($dueDate)) ?><?= $dueTime !== '' ? ' ' . e($dueTime) : '' ?>
                                        <?php endif; ?>
                                        <?php if ($isExpired): ?> · <?= e(t('goals.expired')) ?><?php endif; ?>
                                    </span>
                                    <?php if ($status === 'active' && !$hasStarted): ?>
                                        <small class="muted">
                                            <?= e(t('team.active_challenge_starts_in')) ?>:
                                            <span
                                                data-challenge-countdown
                                                data-countdown-mode="<?= e($countdownMode) ?>"
                                                data-countdown-pending-label="<?= e(t('team.active_challenge_starts_in')) ?>"
                                                data-countdown-active-label="<?= e(t('common.in_progress')) ?>"
                                                data-countdown-expired-label="<?= e(t('goals.expired')) ?>"
                                                <?= $countdownDeadlineIso !== '' ? ' data-deadline="' . e($countdownDeadlineIso) . '"' : '' ?>
                                            ><?= e($countdownText) ?></span>
                                        </small>
                                    <?php endif; ?>
                                    <span><?= e((string) ($goal['progress_display'] ?? '0')) ?> / <?= e((string) ($goal['target_display'] ?? '-')) ?></span>
                                    <?php if ($rewardText !== ''): ?>
                                        <small class="team-goal-reward"><?= e(t('achievements.reward')) ?>: <?= e($rewardText) ?></small>
                                    <?php endif; ?>
                                    <div class="goal-progress-wrap team-goal-progress-wrap">
                                        <div class="goal-progress"><span style="width: <?= e((string) $progressVisual) ?>%"></span></div>
                                        <small><?= e($formatPercent($progressRaw)) ?>%</small>
                                    </div>
                                </div>
                                <?php if (!empty($canManageTeam)): ?>
                                    <details class="photo-post-menu team-goal-actions-menu">
                                        <summary class="btn btn-ghost small" aria-label="<?= e(t('team.challenge_actions')) ?>" title="<?= e(t('team.challenge_actions')) ?>">•••</summary>
                                        <div class="photo-post-menu-panel">
                                            <button
                                                class="btn btn-ghost small"
                                                type="button"
                                                data-team-goal-open
                                                data-goal-mode="edit"
                                                data-goal-id="<?= (int) $goal['id'] ?>"
                                                data-goal-title="<?= e((string) ($goal['title'] ?? '')) ?>"
                                                data-goal-target-type="<?= e($goalTargetType) ?>"
                                                data-goal-target-value="<?= e((string) ($goal['target_value'] ?? '')) ?>"
                                                data-goal-custom-unit="<?= e($goalCustomUnit) ?>"
                                                data-goal-reward-text="<?= e($rewardText) ?>"
                                                data-goal-start-date="<?= e($startDate) ?>"
                                                data-goal-start-time="<?= e($startTime) ?>"
                                                data-goal-due-date="<?= e($dueDate) ?>"
                                                data-goal-due-time="<?= e($dueTime) ?>"
                                            ><?= e(t('common.edit')) ?></button>
                                            <?php if ($status !== 'complete'): ?>
                                                <form method="post" action="/?page=team">
                                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                    <input type="hidden" name="team_id" value="<?= (int) ($team['id'] ?? 0) ?>">
                                                    <input type="hidden" name="goal_id" value="<?= (int) $goal['id'] ?>">
                                                    <input type="hidden" name="redirect_view" value="<?= e($teamView) ?>">
                                                    <input type="hidden" name="status" value="complete">
                                                    <button class="btn btn-ghost small" name="action" value="goal_status" type="submit"><?= e(t('common.complete')) ?></button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if ($status !== 'archived'): ?>
                                                <form method="post" action="/?page=team">
                                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                    <input type="hidden" name="team_id" value="<?= (int) ($team['id'] ?? 0) ?>">
                                                    <input type="hidden" name="goal_id" value="<?= (int) $goal['id'] ?>">
                                                    <input type="hidden" name="redirect_view" value="<?= e($teamView) ?>">
                                                    <input type="hidden" name="status" value="archived">
                                                    <button class="btn btn-ghost small" name="action" value="goal_status" type="submit"><?= e(t('goals.archive')) ?></button>
                                                </form>
                                            <?php endif; ?>
                                            <form method="post" action="/?page=team">
                                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="team_id" value="<?= (int) ($team['id'] ?? 0) ?>">
                                                <input type="hidden" name="goal_id" value="<?= (int) $goal['id'] ?>">
                                                <input type="hidden" name="redirect_view" value="<?= e($teamView) ?>">
                                                <button class="btn btn-ghost small photo-delete-text-btn" name="action" value="delete_goal" type="submit" onclick="return window.confirm('<?= e(t('goals.delete_confirm')) ?>');"><?= e(t('common.delete')) ?></button>
                                            </form>
                                        </div>
                                    </details>
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
                                    <button class="achievement-delete-btn" type="button" aria-label="<?= e(t('achievements.delete_award')) ?>" data-achievement-delete-trigger data-form-id="<?= e($deleteFormId) ?>">×</button>
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
                <h3 id="team-goal-title" data-team-goal-modal-title data-title-create="<?= e(t('goals.create_team_challenge')) ?>" data-title-edit="<?= e(t('goals.edit_team_challenge')) ?>"><?= e(t('goals.create_team_challenge')) ?></h3>
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
                        <?= e(t('goals.start_date')) ?>
                        <input type="date" name="start_date" data-goal-start-date-input>
                    </label>
                    <label>
                        <?= e(t('goals.start_time')) ?>
                        <input type="time" name="start_time" data-goal-start-time-input>
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
                    label: <?= json_encode((string) ($teamMetricDetail['title'] ?? t('common.metric'))) ?>,
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

    const countdownNodes = document.querySelectorAll('[data-challenge-countdown][data-deadline]');
    if (countdownNodes.length > 0) {
        const panelNode = document.querySelector('[data-active-challenge-panel]');
        const statusNode = document.querySelector('[data-active-challenge-status]');
        const statusTextNode = document.querySelector('[data-active-challenge-status-text]');
        const countdownLabelNode = document.querySelector('[data-active-challenge-countdown-label]');
        const activeCountdownNode = document.querySelector('[data-active-challenge-countdown][data-deadline]');
        const zeroText = '0d 00h 00m 00s';
        const countdownPendingTitle = <?= json_encode(t('team.active_challenge_starts_in')) ?>;
        const countdownActiveTitle = <?= json_encode(t('team.active_challenge_time_left')) ?>;
        const pad2 = (value) => String(Math.max(0, value)).padStart(2, '0');
        const formatCountdown = (secondsLeft) => {
            if (!Number.isFinite(secondsLeft) || secondsLeft <= 0) {
                return zeroText;
            }
            const total = Math.floor(secondsLeft);
            const days = Math.floor(total / 86400);
            const hours = Math.floor((total % 86400) / 3600);
            const minutes = Math.floor((total % 3600) / 60);
            const seconds = total % 60;
            return `${days}d ${pad2(hours)}h ${pad2(minutes)}m ${pad2(seconds)}s`;
        };
        const toDeadlineMs = (value) => {
            const ms = Date.parse(String(value || '').trim());
            return Number.isFinite(ms) ? ms : null;
        };
        const promoteCountdownNodeIfNeeded = (node) => {
            if (!(node instanceof HTMLElement)) {
                return;
            }
            const mode = String(node.dataset.countdownMode || '').trim();
            if (mode !== 'start') {
                return;
            }
            const deadlineMs = toDeadlineMs(node.dataset.deadline || '');
            if (deadlineMs === null || deadlineMs > Date.now()) {
                return;
            }
            const nextDeadline = String(node.dataset.countdownNextDeadline || '').trim();
            if (nextDeadline !== '') {
                node.dataset.deadline = nextDeadline;
                node.dataset.countdownMode = 'end';
            }
        };
        const applyPanelState = (state, label) => {
            const isExpired = state === 'expired';
            const isPending = state === 'pending';
            if (panelNode instanceof HTMLElement) {
                panelNode.classList.toggle('is-expired', isExpired);
                panelNode.classList.toggle('is-pending', isPending);
            }
            if (statusNode instanceof HTMLElement) {
                statusNode.classList.toggle('status-expired', isExpired);
                statusNode.classList.toggle('status-pending', isPending);
                statusNode.classList.toggle('status-active', !isExpired && !isPending);
                if (label) {
                    statusNode.textContent = label;
                }
            }
            if (statusTextNode instanceof HTMLElement && label) {
                statusTextNode.textContent = label;
            }
            if (countdownLabelNode instanceof HTMLElement && activeCountdownNode instanceof HTMLElement) {
                countdownLabelNode.textContent = isPending ? countdownPendingTitle : countdownActiveTitle;
            }
        };
        const tickCountdown = () => {
            countdownNodes.forEach((node) => {
                if (!(node instanceof HTMLElement)) {
                    return;
                }
                promoteCountdownNodeIfNeeded(node);
                const deadlineMs = toDeadlineMs(node.dataset.deadline || '');
                if (deadlineMs === null) {
                    node.textContent = zeroText;
                    return;
                }
                const remainingSeconds = (deadlineMs - Date.now()) / 1000;
                if (remainingSeconds > 0) {
                    node.textContent = formatCountdown(remainingSeconds);
                    return;
                }
                node.textContent = zeroText;
            });

            if (activeCountdownNode instanceof HTMLElement) {
                promoteCountdownNodeIfNeeded(activeCountdownNode);
                const mode = String(activeCountdownNode.dataset.countdownMode || '').trim();
                const deadlineMs = toDeadlineMs(activeCountdownNode.dataset.deadline || '');
                const pendingLabel = String(activeCountdownNode.dataset.countdownPendingLabel || '').trim();
                const activeLabel = String(activeCountdownNode.dataset.countdownActiveLabel || '').trim();
                const expiredLabel = String(activeCountdownNode.dataset.countdownExpiredLabel || '').trim();
                const remainingSeconds = deadlineMs === null ? 0 : (deadlineMs - Date.now()) / 1000;
                if (mode === 'start' && remainingSeconds > 0) {
                    applyPanelState('pending', pendingLabel !== '' ? pendingLabel : activeLabel);
                } else if (mode === 'end' && remainingSeconds <= 0) {
                    applyPanelState('expired', expiredLabel !== '' ? expiredLabel : activeLabel);
                } else {
                    applyPanelState('active', activeLabel !== '' ? activeLabel : pendingLabel);
                }
            }
        };
        tickCountdown();
        window.setInterval(tickCountdown, 1000);
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
        const startDateInput = goalModal.querySelector('[data-goal-start-date-input]');
        const startTimeInput = goalModal.querySelector('[data-goal-start-time-input]');
        const dueDateInput = goalModal.querySelector('[data-goal-due-date-input]');
        const dueTimeInput = goalModal.querySelector('[data-goal-due-time-input]');
        const customUnitWrap = goalModal.querySelector('[data-goal-custom-unit-wrap]');
        const customUnitInput = goalModal.querySelector('input[name="custom_unit"]');
        const rewardToggle = goalModal.querySelector('[data-goal-reward-toggle]');
        const rewardWrap = goalModal.querySelector('[data-goal-reward-wrap]');
        const rewardInput = goalModal.querySelector('input[name="reward_text"]');
        const submitButton = goalModal.querySelector('[data-team-goal-submit]');
        const modalCard = goalModal.querySelector('.confirm-modal-card');
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
            if (trigger instanceof HTMLElement) {
                const parentMenu = trigger.closest('details');
                if (parentMenu instanceof HTMLDetailsElement) {
                    parentMenu.open = false;
                }
            }
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
            if (startDateInput instanceof HTMLInputElement) {
                startDateInput.value = isEdit && trigger instanceof HTMLElement ? String(trigger.dataset.goalStartDate || '').trim() : '';
            }
            if (startTimeInput instanceof HTMLInputElement) {
                startTimeInput.value = isEdit && trigger instanceof HTMLElement ? String(trigger.dataset.goalStartTime || '').trim() : '';
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
                    ? String(modalTitle.dataset.titleEdit || 'Edit challenge')
                    : String(modalTitle.dataset.titleCreate || 'Create challenge');
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
