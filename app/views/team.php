<?php

declare(strict_types=1);

$summary = $teamSummary ?? [];
$teamView = (string) ($teamView ?? 'current_week');
$teamWeekOptions = (array) ($teamWeekOptions ?? []);
$goals = (array) ($teamGoals ?? []);
$activeChallenge = is_array($teamActiveChallenge ?? null) ? (array) $teamActiveChallenge : null;
$teamGoalDebugEnabled = !empty($teamGoalDebugEnabled) && !empty($canManageTeam);
$teamSection = (string) ($teamSection ?? '');
$teamMemberDetail = is_array($teamMemberDetail ?? null) ? (array) $teamMemberDetail : null;
$penaltiesEnabled = penalties_enabled($GLOBALS['pdo']);
$isPenaltyRelatedItem = static function (array $item): bool {
    $type = strtolower(trim((string) ($item['target_type'] ?? $item['type'] ?? $item['metric'] ?? '')));
    $secondaryType = strtolower(trim((string) ($item['secondary_target_type'] ?? '')));
    if (in_array($type, ['strikes', 'penalties', 'penalty'], true) || in_array($secondaryType, ['strikes', 'penalties', 'penalty'], true)) {
        return true;
    }

    $text = strtolower(implode(' ', array_map('strval', [
        $item['name'] ?? '',
        $item['title'] ?? '',
        $item['description'] ?? '',
        $item['reward_text'] ?? '',
    ])));

    return str_contains($text, 'strike') || str_contains($text, 'penalt');
};
if (!$penaltiesEnabled) {
    $goals = array_values(array_filter($goals, static fn(array $goal): bool => !$isPenaltyRelatedItem($goal)));
    $teamAchievements = array_values(array_filter((array) ($teamAchievements ?? []), static fn(array $achievement): bool => !$isPenaltyRelatedItem($achievement)));
    if ($activeChallenge !== null && $isPenaltyRelatedItem($activeChallenge)) {
        $activeChallenge = null;
    }
    if (is_array($teamMemberDetail)) {
        $teamMemberDetail['achievements'] = array_values(array_filter(
            (array) ($teamMemberDetail['achievements'] ?? []),
            static fn(array $achievement): bool => !$isPenaltyRelatedItem($achievement)
        ));
    }
}
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
if ($teamGoalDebugEnabled) {
    $teamBaseParams['debug_goal'] = '1';
}
if ($teamView !== '') {
    $teamBaseParams['view'] = $teamView;
}
$teamBaseUrl = '/?' . http_build_query($teamBaseParams);
$teamAchievementsUrl = '/?' . http_build_query([
    'page' => 'achievements',
    'scope' => 'team',
    'team_id' => (int) ($team['id'] ?? 0),
]);
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
    static function (array $left, array $right) use ($penaltiesEnabled): int {
        $scoreOrder = ((float) ($right['score'] ?? 0)) <=> ((float) ($left['score'] ?? 0));
        if ($scoreOrder !== 0) {
            return $scoreOrder;
        }

        if ($penaltiesEnabled) {
            $penaltyOrder = ((float) ($left['penalties'] ?? 0)) <=> ((float) ($right['penalties'] ?? 0));
            if ($penaltyOrder !== 0) {
                return $penaltyOrder;
            }
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
$isTeamOverview = $teamSection === '' && $teamMetricDetail === null;
$teamLayoutWidgets = normalize_team_layout_widgets((string) ($currentUser['team_layout_json'] ?? ''));
$teamLayoutIndex = array_flip($teamLayoutWidgets);
$teamLayoutEditMode = !empty($teamLayoutEditMode) && $isTeamOverview;
$teamLayoutEditorWidgets = team_layout_widgets_default();
$teamLayoutLabels = is_array($teamLayoutLabels ?? null) ? (array) $teamLayoutLabels : [
    'metrics' => t('team.widget_metrics'),
    'active_challenge' => t('team.widget_active_challenge'),
    'leaderboard' => t('team.widget_leaderboard'),
    'challenges' => t('team.widget_challenges'),
    'members' => t('team.widget_members'),
    'daily_charts' => t('team.widget_daily_charts'),
    'cumulative_steps' => t('team.widget_cumulative_steps'),
    'cumulative_distance' => t('team.widget_cumulative_distance'),
    'weekly_charts' => t('team.widget_weekly_charts'),
    'achievements' => t('team.widget_achievements'),
];
$teamWidgetStyle = static function (string $widget, int $mobileOrder) use ($teamLayoutIndex): string {
    if (!isset($teamLayoutIndex[$widget])) {
        return 'display:none; --team-order:999; --team-mobile-order:999;';
    }
    $desktopOrder = (int) (($teamLayoutIndex[$widget] ?? 0) + 1) * 10;

    return '--team-order:' . $desktopOrder . '; --team-mobile-order:' . $mobileOrder . ';';
};

$memberUser = is_array($teamMemberDetail['user'] ?? null) ? (array) $teamMemberDetail['user'] : [];
$memberMetric = is_array($teamMemberDetail['metric'] ?? null) ? (array) $teamMemberDetail['metric'] : [];
$memberRank = $memberUser !== [] ? ($rankByUserId[(int) ($memberUser['id'] ?? 0)] ?? null) : null;
?>
<section class="screen stack-lg<?= $teamSection === 'member' ? ' team-member-detail-screen' : '' ?>">
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

    <?php if ($teamLayoutEditMode): ?>
        <article class="panel team-layout-edit-mode-panel">
            <form id="team-layout-edit-form" method="post" action="/?page=team" class="team-layout-editor team-layout-editor-mobile" data-team-layout-editor>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="save_team_layout">
                <input type="hidden" name="team_id" value="<?= (int) ($team['id'] ?? 0) ?>">
                <input type="hidden" name="team_view" value="<?= e($teamView) ?>">
                <div class="team-layout-editor-head">
                    <strong><?= e(t('team.edit_layout')) ?></strong>
                    <small><?= e(t('team.layout_hint')) ?></small>
                </div>
                <div class="team-layout-editor-list" data-team-layout-list>
                    <?php foreach ($teamLayoutEditorWidgets as $widget): ?>
                        <div class="team-layout-editor-item team-layout-edit-card" data-team-layout-item>
                            <div class="dashboard-layout-mobile-actions team-layout-mobile-actions">
                                <button class="btn btn-ghost small" type="button" data-layout-move="up" aria-label="Move up">&uarr;</button>
                                <button class="btn btn-ghost small" type="button" data-layout-move="down" aria-label="Move down">&darr;</button>
                            </div>
                            <label class="dashboard-layout-toggle">
                                <input type="checkbox" name="team_widgets[]" value="<?= e($widget) ?>" <?= in_array($widget, $teamLayoutWidgets, true) ? 'checked' : '' ?>>
                                <span><?= e((string) ($teamLayoutLabels[$widget] ?? $widget)) ?></span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="team-layout-editor-actions">
                    <a class="btn btn-ghost small" href="<?= e($teamBaseUrl) ?>"><?= e(t('common.back')) ?></a>
                    <button class="btn btn-ghost small" type="submit" name="reset_team_layout" value="1"><?= e(t('team.reset_layout')) ?></button>
                    <button class="btn btn-primary small" type="submit"><?= e(t('common.save')) ?></button>
                </div>
            </form>
        </article>
    <?php endif; ?>

    <?php
    $activeChallengeHero = '';
    ?>
    <?php if ($activeChallenge !== null && $isTeamOverview): ?>
        <?php
        $activeRewardText = trim((string) ($activeChallenge['reward_text'] ?? ''));
        $activeProgressRaw = (float) ($activeChallenge['progress_pct_raw'] ?? 0);
        $activeProgressVisual = (float) ($activeChallenge['progress_pct_visual'] ?? max(0, min(100, $activeProgressRaw)));
        $activePrimaryProgressRaw = (float) ($activeChallenge['primary_progress_pct_raw'] ?? $activeProgressRaw);
        $activePrimaryProgressVisual = (float) ($activeChallenge['primary_progress_pct_visual'] ?? max(0, min(100, $activePrimaryProgressRaw)));
        $activeSecondaryEnabled = !empty($activeChallenge['secondary_enabled']);
        $activeSecondaryProgressRaw = $activeSecondaryEnabled ? (float) ($activeChallenge['secondary_progress_pct_raw'] ?? 0) : 0.0;
        $activeSecondaryProgressVisual = $activeSecondaryEnabled
            ? (float) ($activeChallenge['secondary_progress_pct_visual'] ?? max(0, min(100, $activeSecondaryProgressRaw)))
            : 0.0;
        $activePrimaryObjectiveLabel = (string) ($activeChallenge['target_type_label'] ?? t('common.other'));
        $activeSecondaryObjectiveLabel = $activeSecondaryEnabled
            ? (string) ($activeChallenge['secondary_target_type_label'] ?? t('common.other'))
            : '';
        $activePrimaryObjectiveText = (string) ($activeChallenge['primary_progress_display'] ?? $activeChallenge['progress_display'] ?? '0')
            . ' / '
            . (string) ($activeChallenge['primary_target_display'] ?? $activeChallenge['target_display'] ?? '-');
        $activeSecondaryObjectiveText = $activeSecondaryEnabled
            ? (string) ($activeChallenge['secondary_progress_display'] ?? '0') . ' / ' . (string) ($activeChallenge['secondary_target_display'] ?? '-')
            : '';
        $activeDetailTitleId = 'active-challenge-detail-title-' . (int) ($activeChallenge['id'] ?? 0);
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
        $activeDueDate = trim((string) ($activeChallenge['due_date'] ?? ''));
        $activeDueTime = trim((string) ($activeChallenge['due_time_resolved'] ?? $activeChallenge['due_time'] ?? ''));
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
        $activeProgressDebug = is_array($activeChallenge['progress_debug'] ?? null) ? (array) $activeChallenge['progress_debug'] : [];
        ob_start();
        ?>
        <article class="panel team-layout-item team-widget-active-challenge team-active-challenge-panel<?= $activeIsExpired ? ' is-expired' : '' ?><?= $activeIsPreStart ? ' is-pending' : '' ?>" data-active-challenge-panel style="<?= e($teamWidgetStyle('active_challenge', 20)) ?>">
            <div class="panel-head team-active-challenge-head">
                <div>
                    <p class="eyebrow"><?= e(t('team.active_challenge_title')) ?></p>
                    <h2><?= e((string) ($activeChallenge['title'] ?? t('team.challenges'))) ?></h2>
                </div>
                <div class="team-active-challenge-head-actions">
                    <span class="team-active-challenge-status <?= e($activeStatusClass) ?>" data-active-challenge-status><?= e((string) $activeStatusText) ?></span>
                    <button class="btn btn-ghost small team-active-challenge-detail-btn" type="button" data-team-challenge-detail-open><?= e(t('team.challenge_view_detail')) ?></button>
                </div>
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
                    <div class="team-active-challenge-objectives<?= $activeSecondaryEnabled ? ' has-two' : '' ?>">
                        <div class="team-active-objective-card">
                            <div class="team-active-objective-card-head">
                                <span><?= e(t('team.challenge_primary_objective')) ?></span>
                                <strong><?= e($activePrimaryObjectiveLabel) ?></strong>
                            </div>
                            <small><?= e($activePrimaryObjectiveText) ?></small>
                            <div class="goal-progress mini-progress">
                                <span style="width: <?= e((string) $activePrimaryProgressVisual) ?>%"></span>
                            </div>
                            <small><?= e($formatPercent($activePrimaryProgressRaw)) ?>%</small>
                        </div>
                        <?php if ($activeSecondaryEnabled): ?>
                            <div class="team-active-objective-card">
                                <div class="team-active-objective-card-head">
                                    <span><?= e(t('goals.second_objective')) ?></span>
                                    <strong><?= e($activeSecondaryObjectiveLabel) ?></strong>
                                </div>
                                <small><?= e($activeSecondaryObjectiveText) ?></small>
                                <div class="goal-progress mini-progress">
                                    <span style="width: <?= e((string) $activeSecondaryProgressVisual) ?>%"></span>
                                </div>
                                <small><?= e($formatPercent($activeSecondaryProgressRaw)) ?>%</small>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($teamGoalDebugEnabled): ?>
                        <small class="team-goal-debug" data-goal-debug>
                            cur <?= e((string) ($activeProgressDebug['current_metric'] ?? '-')) ?>
                            · base <?= e((string) ($activeProgressDebug['baseline'] ?? '-')) ?>
                            · prog <?= e((string) ($activeProgressDebug['progress'] ?? '-')) ?>
                            · target <?= e((string) ($activeProgressDebug['target'] ?? '-')) ?>
                        </small>
                    <?php endif; ?>
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
            <button class="btn btn-ghost small team-active-challenge-detail-btn team-active-challenge-detail-btn-mobile" type="button" data-team-challenge-detail-open><?= e(t('team.challenge_view_detail')) ?></button>
        </article>
        <div class="confirm-modal team-challenge-detail-modal" hidden aria-hidden="true" data-team-challenge-detail-modal>
            <div class="confirm-modal-backdrop" data-team-challenge-detail-close></div>
            <div class="confirm-modal-card team-challenge-detail-card" role="dialog" aria-modal="true" aria-labelledby="<?= e($activeDetailTitleId) ?>">
                <button class="achievement-info-close" type="button" aria-label="<?= e(t('common.close_action')) ?>" data-team-challenge-detail-close>&times;</button>
                <div class="team-challenge-detail-head">
                    <div>
                        <p class="eyebrow"><?= e(t('team.challenge_detail')) ?></p>
                        <h3 id="<?= e($activeDetailTitleId) ?>"><?= e((string) ($activeChallenge['title'] ?? t('team.challenges'))) ?></h3>
                    </div>
                    <span class="team-active-challenge-status <?= e($activeStatusClass) ?>" data-active-challenge-status><?= e((string) $activeStatusText) ?></span>
                </div>
                <div class="team-challenge-detail-average">
                    <div>
                        <strong><?= e(t('team.challenge_average_progress')) ?></strong>
                        <small><?= e($formatPercent($activeProgressRaw)) ?>%</small>
                    </div>
                    <div class="goal-progress-wrap team-active-challenge-progress">
                        <div class="goal-progress"><span style="width: <?= e((string) $activeProgressVisual) ?>%"></span></div>
                    </div>
                </div>
                <div class="team-challenge-detail-objectives<?= $activeSecondaryEnabled ? ' has-two' : '' ?>">
                    <article class="team-challenge-detail-objective">
                        <div class="team-active-objective-card-head">
                            <span><?= e(t('team.challenge_primary_objective')) ?></span>
                            <strong><?= e($activePrimaryObjectiveLabel) ?></strong>
                        </div>
                        <p><?= e($activePrimaryObjectiveText) ?></p>
                        <div class="goal-progress-wrap">
                            <div class="goal-progress"><span style="width: <?= e((string) $activePrimaryProgressVisual) ?>%"></span></div>
                            <small><?= e($formatPercent($activePrimaryProgressRaw)) ?>%</small>
                        </div>
                    </article>
                    <?php if ($activeSecondaryEnabled): ?>
                        <article class="team-challenge-detail-objective">
                            <div class="team-active-objective-card-head">
                                <span><?= e(t('goals.second_objective')) ?></span>
                                <strong><?= e($activeSecondaryObjectiveLabel) ?></strong>
                            </div>
                            <p><?= e($activeSecondaryObjectiveText) ?></p>
                            <div class="goal-progress-wrap">
                                <div class="goal-progress"><span style="width: <?= e((string) $activeSecondaryProgressVisual) ?>%"></span></div>
                                <small><?= e($formatPercent($activeSecondaryProgressRaw)) ?>%</small>
                            </div>
                        </article>
                    <?php endif; ?>
                </div>
                <div class="team-active-challenge-meta team-challenge-detail-meta">
                    <span><strong><?= e(t('goals.start_date')) ?></strong><small><?= $activeStartDate !== '' ? e(format_date_eu($activeStartDate)) . ($activeStartTime !== '' ? ' ' . e($activeStartTime) : '') : '-' ?></small></span>
                    <span><strong><?= e(t('goals.due_date')) ?></strong><small><?= $activeDueDate !== '' ? e(format_date_eu($activeDueDate)) . ($activeDueTime !== '' ? ' ' . e($activeDueTime) : '') : '-' ?></small></span>
                    <span><strong><?= e(t('team.active_challenge_reward')) ?></strong><small><?= e($activeRewardText !== '' ? $activeRewardText : t('team.active_challenge_reward_none')) ?></small></span>
                    <span><strong><?= e(t('team.active_challenge_status')) ?></strong><small data-active-challenge-status-text><?= e((string) $activeStatusText) ?></small></span>
                    <span><strong data-active-challenge-countdown-label><?= e($activeCountdownLabel) ?></strong><small data-active-challenge-countdown data-challenge-countdown data-countdown-mode="<?= e($activeCountdownMode) ?>" data-countdown-pending-label="<?= e(t('team.active_challenge_starts_in')) ?>" data-countdown-active-label="<?= e(t('team.active_challenge_started')) ?>" data-countdown-expired-label="<?= e(t('goals.expired')) ?>"<?= $countdownNextDeadlineIso !== '' ? ' data-countdown-next-deadline="' . e($countdownNextDeadlineIso) . '"' : '' ?><?= $countdownDeadlineIso !== '' ? ' data-deadline="' . e($countdownDeadlineIso) . '"' : '' ?>><?= e($countdownText) ?></small></span>
                </div>
            </div>
        </div>
        <?php
        $activeChallengeHero = (string) ob_get_clean();
        ?>
    <?php endif; ?>

    <?php if ($teamSection === 'member' && $teamMemberDetail !== null && $memberUser !== [] && $memberMetric !== []): ?>
        <article class="panel team-member-detail-panel">
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
                    <article class="mini-card"><div><strong><?= e(t('metric.workouts')) ?></strong><span><?= e((string) max((int) ($memberMetric['workout_count'] ?? 0), (int) ($memberMetric['workout_success'] ?? 0))) ?></span></div></article>
                    <?php if ($penaltiesEnabled): ?>
                    <article class="mini-card"><div><strong><?= e(t('metric.strikes')) ?></strong><span><?= e((string) ($memberMetric['current_strikes'] ?? 0)) ?></span></div></article>
                    <article class="mini-card"><div><strong><?= e(t('metric.penalty')) ?></strong><span class="penalty-chip penalty-chip-<?= (int) ($memberMetric['total_penalty'] ?? 0) <= 0 ? 'good' : ((int) ($memberMetric['total_penalty'] ?? 0) <= 50 ? 'warn' : 'bad') ?>">&euro;<?= e((string) ($memberMetric['total_penalty'] ?? 0)) ?></span><small class="muted"><?= e(t('team.lower_is_better')) ?></small></div></article>
                    <?php endif; ?>
                </div>
            </div>
        </article>

        <div class="grid-two team-member-chart-grid" style="order: 50">
            <article class="panel chart-card">
                <h2><?= e(t('metric.steps')) ?></h2>
                <canvas id="memberStepsChart" height="170"></canvas>
            </article>
            <article class="panel chart-card">
                <h2><?= e(t('metric.distance_km')) ?></h2>
                <canvas id="memberDistanceChart" height="170"></canvas>
            </article>
        </div>

        <div class="grid-two team-member-chart-grid" style="order: 50">
            <article class="panel chart-card">
                <h2><?= e(t('team.workouts_by_week')) ?></h2>
                <canvas id="memberWorkoutChart" height="170"></canvas>
            </article>
            <?php if ($penaltiesEnabled): ?>
            <article class="panel chart-card">
                <h2><?= e(t('team.score_penalty_by_week')) ?></h2>
                <canvas id="memberScorePenaltyChart" height="170"></canvas>
            </article>
            <?php endif; ?>
        </div>

        <div class="grid-two team-member-secondary-grid">
            <article class="panel team-member-activity-panel">
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

            <article class="panel team-member-achievements-panel">
                <div class="panel-head">
                    <h2><?= e(t('profile.achievements')) ?></h2>
                    <span class="badge"><?= count((array) ($teamMemberDetail['achievements'] ?? [])) ?></span>
                </div>
                <div class="achievement-grid team-member-achievement-grid">
                    <?php foreach ((array) ($teamMemberDetail['achievements'] ?? []) as $achievement): ?>
                        <article class="achievement-card" <?= achievement_modal_attrs($achievement) ?>>
                            <?= achievement_visual_html($achievement, 'achievement-visual') ?>
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
                <a class="btn btn-ghost" href="<?= e($teamBaseUrl) ?>">← <?= e(t('common.back')) ?></a>
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
        $summaryWorkouts = max((float) ($summary['workout_count'] ?? 0), (float) ($summary['workout_success'] ?? 0));
        $summaryWorkoutTarget = max(0, (int) ($summary['workout_target'] ?? 0));
        $summaryScore = (float) ($summary['score_avg'] ?? 0);
        $summaryStrikes = (float) ($summary['strikes'] ?? 0);
        $summaryPenalty = (float) ($summary['penalty'] ?? 0);
        $workoutProgress = $summaryWorkoutTarget > 0
            ? min(100, round(($summaryWorkouts / $summaryWorkoutTarget) * 100))
            : 0;
        ?>
        <div class="team-layout-grid">
        <div class="metric-grid team-layout-item team-widget-metrics" style="<?= e($teamWidgetStyle('metrics', 10)) ?>">
            <a class="metric-card metric-card-link team-metric-steps" href="<?= e($teamMetricUrl('steps')) ?>">
                <div class="progress-ring" style="--value: 100;"><span><?= e($formatInt($summarySteps)) ?></span></div>
                <div><span><?= e(t('metric.total_steps')) ?></span><strong><?= e($formatInt($summarySteps)) ?></strong><p><?= e(t('team.all_members')) ?></p></div>
            </a>
            <a class="metric-card metric-card-link team-metric-distance" href="<?= e($teamMetricUrl('distance')) ?>">
                <div class="progress-ring" style="--value: <?= e((string) min(100, $summaryDistance)) ?>;"><span><?= e($formatKm($summaryDistance)) ?></span></div>
                <div><span><?= e(t('metric.total_km')) ?></span><strong><?= e($formatKm($summaryDistance)) ?> km</strong><p><?= e(t('team.all_members')) ?></p></div>
            </a>
            <article class="metric-card team-mobile-combo-card">
                <a href="<?= e($teamMetricUrl('steps')) ?>">
                    <span><?= e(t('metric.total_steps')) ?></span>
                    <strong><?= e($formatInt($summarySteps)) ?></strong>
                </a>
                <a href="<?= e($teamMetricUrl('distance')) ?>">
                    <span><?= e(t('metric.total_km')) ?></span>
                    <strong><?= e($formatKm($summaryDistance)) ?> km</strong>
                </a>
            </article>
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
            <?php if ($penaltiesEnabled): ?>
            <a class="metric-card metric-card-link team-metric-strikes" href="<?= e($teamMetricUrl('strikes')) ?>">
                <div class="progress-ring" style="--value: <?= e((string) max(0, 100 - ((int) $summaryStrikes * 8))) ?>;"><span><?= e($formatInt($summaryStrikes)) ?></span></div>
                <div>
                    <span><?= e(t('metric.strikes')) ?></span>
                    <strong><?= e($formatInt($summaryStrikes)) ?></strong>
                    <p><?= e(t('metric.penalty')) ?>: &euro;<?= e($formatMoney($summaryPenalty)) ?></p>
                </div>
            </a>
            <?php endif; ?>
        </div>

        <?php if ($activeChallengeHero !== ''): ?>
            <?= $activeChallengeHero ?>
        <?php endif; ?>

        <article class="panel team-layout-item team-widget-leaderboard team-leaderboard-panel" style="<?= e($teamWidgetStyle('leaderboard', 30)) ?>">
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
                            <?php if ($penaltiesEnabled): ?>
                                <span class="team-leaderboard-strikes">
                                    <strong><?= e($formatInt((float) ($row['strikes'] ?? 0))) ?></strong>
                                    <small><?= e(t('metric.strikes')) ?> &middot; <span class="penalty-chip penalty-chip-<?= e($penaltyClass) ?>">&euro;<?= e($formatMoney((float) $penaltyValue)) ?></span></small>
                                </span>
                            <?php endif; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
                <?php if ($leaderboardRows === []): ?>
                    <p class="muted panel-inline-empty"><?= e(t('common.none')) ?></p>
                <?php endif; ?>
            </div>
        </article>

            <article class="panel team-layout-item team-widget-challenges team-challenges-panel" style="<?= e($teamWidgetStyle('challenges', 40)) ?>">
                <div class="panel-head">
                    <div>
                        <p class="eyebrow"><?= e(t('team.challenges')) ?></p>
                        <h2><?= e(t('team.challenges')) ?></h2>
                    </div>
                    <?php if (!empty($canManageTeam)): ?>
                        <button class="btn btn-primary small team-panel-create-btn" type="button" data-team-goal-open data-goal-mode="create"><?= e(t('common.create')) ?></button>
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
                            $goalSecondaryEnabled = !empty($goal['secondary_enabled']);
                            $goalSecondaryType = $goalSecondaryEnabled
                                ? (string) ($goal['secondary_target_type_normalized'] ?? normalize_goal_target_type((string) ($goal['secondary_target_type'] ?? 'custom')))
                                : 'custom';
                            $goalSecondaryCustomUnit = $goalSecondaryEnabled && $goalSecondaryType === 'custom'
                                ? trim((string) ($goal['secondary_unit_label_resolved'] ?? $goal['secondary_unit_label'] ?? ''))
                                : '';
                            $progressDebug = is_array($goal['progress_debug'] ?? null) ? (array) $goal['progress_debug'] : [];
                            ?>
                            <article class="mini-card team-goal-card">
                                <button
                                    class="team-goal-mobile-preview<?= $rewardText !== '' ? ' has-reward' : '' ?>"
                                    type="button"
                                    data-team-goal-detail-trigger
                                    aria-label="<?= e(t('team.challenge_detail')) ?>: <?= e((string) $goal['title']) ?>"
                                >
                                    <strong class="team-goal-mobile-preview-title"><?= e((string) $goal['title']) ?></strong>
                                    <span class="team-goal-mobile-preview-status team-goal-status status-<?= e($statusBadgeClass) ?>"><?= e($statusBadgeText) ?></span>
                                    <span class="team-goal-mobile-preview-progress"><?= e($formatPercent($progressRaw)) ?>%</span>
                                    <?php if ($rewardText !== ''): ?>
                                        <small class="team-goal-mobile-preview-reward"><?= e(t('achievements.reward')) ?>: <?= e($rewardText) ?></small>
                                    <?php endif; ?>
                                    <span class="team-goal-mobile-preview-bar" aria-hidden="true"><span style="width: <?= e((string) $progressVisual) ?>%"></span></span>
                                </button>
                                <div class="team-goal-main">
                                    <div class="team-goal-head">
                                        <strong><?= e((string) $goal['title']) ?></strong>
                                        <span class="team-goal-status status-<?= e($statusBadgeClass) ?>"><?= e($statusBadgeText) ?></span>
                                    </div>
                                    <div class="team-goal-objective-grid<?= $goalSecondaryEnabled ? ' has-two' : '' ?>">
                                        <span class="team-goal-objective-pill">
                                            <small><?= e(t('goals.target')) ?></small>
                                            <strong><?= e((string) ($goal['target_type_label'] ?? t('common.other'))) ?> · <?= e((string) ($goal['target_display'] ?? '-')) ?></strong>
                                        </span>
                                        <?php if ($goalSecondaryEnabled): ?>
                                            <span class="team-goal-objective-pill">
                                                <small><?= e(t('goals.second_objective')) ?></small>
                                                <strong><?= e((string) ($goal['secondary_target_type_label'] ?? t('common.other'))) ?> · <?= e((string) ($goal['secondary_target_display'] ?? '-')) ?></strong>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="team-goal-meta-grid">
                                        <?php if ($startDate !== ''): ?>
                                            <span>
                                                <small><?= e(t('goals.start_date')) ?></small>
                                                <strong><?= e(format_date_eu($startDate)) ?><?= $startTime !== '' ? ' ' . e($startTime) : '' ?></strong>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($dueDate !== ''): ?>
                                            <span>
                                                <small><?= e(t('goals.due_date')) ?></small>
                                                <strong><?= e(format_date_eu($dueDate)) ?><?= $dueTime !== '' ? ' ' . e($dueTime) : '' ?></strong>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($isExpired): ?>
                                            <span>
                                                <small><?= e(t('common.status')) ?></small>
                                                <strong><?= e(t('goals.expired')) ?></strong>
                                            </span>
                                        <?php endif; ?>
                                    </div>
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
                                    <span class="team-goal-progress-text"><?= e((string) ($goal['progress_display'] ?? '0')) ?> / <?= e((string) ($goal['target_display'] ?? '-')) ?></span>
                                    <?php if ($goalSecondaryEnabled): ?>
                                        <span class="team-goal-progress-text team-goal-progress-secondary"><?= e((string) ($goal['secondary_progress_display'] ?? '0')) ?> / <?= e((string) ($goal['secondary_target_display'] ?? '-')) ?></span>
                                    <?php endif; ?>
                                    <?php if ($teamGoalDebugEnabled): ?>
                                        <small class="team-goal-debug" data-goal-debug>
                                            cur <?= e((string) ($progressDebug['current_metric'] ?? '-')) ?>
                                            · base <?= e((string) ($progressDebug['baseline'] ?? '-')) ?>
                                            · prog <?= e((string) ($progressDebug['progress'] ?? '-')) ?>
                                            · target <?= e((string) ($progressDebug['target'] ?? '-')) ?>
                                        </small>
                                    <?php endif; ?>
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
                                                data-goal-secondary-enabled="<?= $goalSecondaryEnabled ? '1' : '0' ?>"
                                                data-goal-secondary-target-type="<?= e($goalSecondaryType) ?>"
                                                data-goal-secondary-target-value="<?= e((string) ($goal['secondary_target_value'] ?? '')) ?>"
                                                data-goal-secondary-custom-unit="<?= e($goalSecondaryCustomUnit) ?>"
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
            <article class="panel team-layout-item team-widget-members team-members-panel" style="<?= e($teamWidgetStyle('members', 50)) ?>">
                <div class="panel-head">
                    <div>
                        <p class="eyebrow"><?= e(t('team.members')) ?></p>
                        <h2><?= e(t('team.active_members')) ?> <span class="badge team-mobile-title-badge"><?= count($members ?? []) ?></span></h2>
                    </div>
                    <span class="badge team-panel-count-badge"><?= count($members ?? []) ?></span>
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

        <div class="grid-two team-layout-item team-widget-daily-charts" style="<?= e($teamWidgetStyle('daily_charts', 60)) ?>">
            <article class="panel chart-card">
                <h2><?= e(t('team.steps_over_time')) ?></h2>
                <canvas id="teamStepsChart" height="170"></canvas>
            </article>
            <article class="panel chart-card">
                <h2><?= e(t('team.distance_over_time')) ?></h2>
                <canvas id="teamDistanceChart" height="170"></canvas>
            </article>
        </div>

        <article class="panel chart-card team-layout-item team-widget-cumulative-steps team-cumulative-chart-card" style="<?= e($teamWidgetStyle('cumulative_steps', 70)) ?>">
            <h2><?= e(t('team.cumulative_steps')) ?></h2>
            <canvas id="teamCumulativeStepsChart" height="170"></canvas>
        </article>
        <article class="panel chart-card team-layout-item team-widget-cumulative-distance team-cumulative-chart-card" style="<?= e($teamWidgetStyle('cumulative_distance', 80)) ?>">
            <h2><?= e(t('team.cumulative_distance')) ?></h2>
            <canvas id="teamCumulativeDistanceChart" height="170"></canvas>
        </article>

        <div class="grid-two team-layout-item team-widget-weekly-charts" style="<?= e($teamWidgetStyle('weekly_charts', 90)) ?>">
            <article class="panel chart-card">
                <h2><?= e(t('team.workouts_over_time')) ?></h2>
                <canvas id="teamWorkoutsChart" height="170"></canvas>
            </article>
            <?php if ($penaltiesEnabled): ?>
            <article class="panel chart-card">
                <h2><?= e(t('team.score_strikes_penalties')) ?></h2>
                <canvas id="teamWeeklyChart" height="170"></canvas>
            </article>
            <?php endif; ?>
        </div>

        <article class="panel team-layout-item team-widget-achievements team-achievements-panel" style="<?= e($teamWidgetStyle('achievements', 100)) ?>">
            <div class="panel-head">
                <div>
                    <p class="eyebrow"><?= e(t('achievements.title')) ?></p>
                    <h2><?= e(t('team.achievements')) ?> <span class="badge team-mobile-title-badge"><?= count($teamAchievements ?? []) ?></span></h2>
                </div>
                <span class="badge team-panel-count-badge"><?= count($teamAchievements ?? []) ?></span>
            </div>
            <div class="achievement-grid achievement-grid-collapsible" data-achievement-grid>
                <?php if (($teamAchievements ?? []) === []): ?>
                    <p class="muted"><?= e(t('achievements.empty')) ?></p>
                <?php else: ?>
                    <?php foreach ($teamAchievements as $achievement): ?>
                        <?php $deleteFormId = 'delete-achievement-team-' . (int) $achievement['id']; ?>
                        <article class="achievement-card team-achievement-card" <?= achievement_modal_attrs($achievement) ?>>
                            <?= achievement_visual_html($achievement, 'achievement-visual team-achievement-media') ?>
                            <div class="team-achievement-body">
                                <strong><?= e((string) $achievement['name']) ?></strong>
                                <p><?= e((string) $achievement['description']) ?></p>
                                <div class="achievement-card-meta">
                                    <?php if (!empty($achievement['reward_text'])): ?><span class="achievement-chip"><?= e(t('achievements.reward')) ?>: <?= e((string) $achievement['reward_text']) ?></span><?php endif; ?>
                                    <span class="achievement-chip team-achievement-date"><?= e(format_date_eu((string) $achievement['awarded_at'])) ?></span>
                                </div>
                            </div>
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
            <div class="achievement-toggle-wrap">
                <a class="btn btn-ghost small achievement-toggle-btn" href="<?= e($teamAchievementsUrl) ?>"><?= e(t('common.view_all')) ?></a>
            </div>
        </article>
        </div>

        <div class="confirm-modal" hidden aria-hidden="true" data-team-goal-modal>
            <div class="confirm-modal-backdrop" data-team-goal-close></div>
            <div class="confirm-modal-card team-goal-modal-card" role="dialog" aria-modal="true" aria-labelledby="team-goal-title">
                <h3 id="team-goal-title" data-team-goal-modal-title data-title-create="<?= e(t('goals.create_team_challenge')) ?>" data-title-edit="<?= e(t('goals.edit_team_challenge')) ?>"><?= e(t('goals.create_team_challenge')) ?></h3>
                <form method="post" action="/?page=team" class="stack compact-form team-goal-form" data-team-goal-form>
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="team_id" value="<?= (int) ($team['id'] ?? 0) ?>">
                    <input type="hidden" name="redirect_view" value="<?= e($teamView) ?>">
                    <input type="hidden" name="action" value="create_goal" data-team-goal-action>
                    <input type="hidden" name="goal_id" value="" data-team-goal-id>
                    <label class="team-goal-form-full">
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
                            <?php if ($penaltiesEnabled): ?>
                            <option value="penalties" data-goal-placeholder="30 €" data-goal-lower-better="1"><?= e(t('metric.penalty')) ?></option>
                            <?php endif; ?>
                            <?php if ($penaltiesEnabled): ?>
                            <option value="strikes" data-goal-placeholder="3 strikes" data-goal-lower-better="1"><?= e(t('metric.strikes')) ?></option>
                            <?php endif; ?>
                            <option value="weight" data-goal-placeholder="4 %" data-goal-lower-better="0"><?= e(t('metric.weight')) ?></option>
                            <option value="custom"><?= e(t('common.other')) ?></option>
                        </select>
                    </label>
                    <label>
                        <?= e(t('goals.target')) ?>
                        <input type="number" step="0.1" name="target_value" value="" required data-goal-target-input>
                    </label>
                    <p class="muted small team-goal-form-helper team-goal-form-full"><?= e(t('goals.progress_starts_zero')) ?></p>
                    <label data-goal-custom-unit-wrap hidden>
                        <?= e(t('goals.custom_unit')) ?>
                        <input type="text" name="custom_unit" maxlength="24" placeholder="<?= e(t('goals.custom_unit_placeholder')) ?>">
                    </label>
                    <label class="check team-goal-secondary-toggle team-goal-form-full">
                        <input type="checkbox" name="secondary_enabled" value="1" data-goal-secondary-toggle>
                        <?= e(t('goals.add_second_objective')) ?>
                    </label>
                    <label data-goal-secondary-wrap hidden>
                        <?= e(t('goals.type')) ?> (<?= e(t('goals.second_objective')) ?>)
                        <select name="secondary_target_type" data-goal-secondary-type-select>
                            <option value="steps" data-goal-placeholder="200000" data-goal-lower-better="0"><?= e(t('metric.steps')) ?></option>
                            <option value="km" data-goal-placeholder="50 km" data-goal-lower-better="0"><?= e(t('metric.distance_km')) ?></option>
                            <option value="workouts" data-goal-placeholder="12 workouts" data-goal-lower-better="0"><?= e(t('metric.workouts')) ?></option>
                            <option value="score" data-goal-placeholder="40 pts" data-goal-lower-better="0"><?= e(t('metric.score')) ?></option>
                            <option value="calories_burned" data-goal-placeholder="12000 kcal" data-goal-lower-better="0"><?= e(t('dashboard.calories_burned')) ?></option>
                            <option value="calories_consumed" data-goal-placeholder="12000 kcal" data-goal-lower-better="1"><?= e(t('dashboard.calories_consumed')) ?></option>
                            <?php if ($penaltiesEnabled): ?>
                            <option value="penalties" data-goal-placeholder="30 €" data-goal-lower-better="1"><?= e(t('metric.penalty')) ?></option>
                            <?php endif; ?>
                            <?php if ($penaltiesEnabled): ?>
                            <option value="strikes" data-goal-placeholder="3 strikes" data-goal-lower-better="1"><?= e(t('metric.strikes')) ?></option>
                            <?php endif; ?>
                            <option value="weight" data-goal-placeholder="4 %" data-goal-lower-better="0"><?= e(t('metric.weight')) ?></option>
                            <option value="custom"><?= e(t('common.other')) ?></option>
                        </select>
                    </label>
                    <label data-goal-secondary-wrap hidden>
                        <?= e(t('goals.target')) ?> (<?= e(t('goals.second_objective')) ?>)
                        <input type="number" step="0.1" name="secondary_target_value" value="" data-goal-secondary-target-input>
                    </label>
                    <label data-goal-secondary-custom-unit-wrap hidden>
                        <?= e(t('goals.custom_unit')) ?> (<?= e(t('goals.second_objective')) ?>)
                        <input type="text" name="secondary_custom_unit" maxlength="24" placeholder="<?= e(t('goals.custom_unit_placeholder')) ?>">
                    </label>
                    <label class="check team-goal-reward-toggle team-goal-form-full">
                        <input type="checkbox" name="reward_enabled" value="1" data-goal-reward-toggle>
                        <?= e(t('goals.add_reward')) ?>
                    </label>
                    <label data-goal-reward-wrap hidden class="team-goal-form-full">
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
                    <div class="confirm-modal-actions team-goal-form-full">
                        <button class="btn btn-ghost" type="button" data-team-goal-close><?= e(t('common.cancel')) ?></button>
                        <button class="btn btn-primary" type="submit" data-team-goal-submit data-label-create="<?= e(t('common.create')) ?>" data-label-save="<?= e(t('common.save')) ?>"><?= e(t('common.create')) ?></button>
                    </div>
                </form>
            </div>
        </div>

        <div class="confirm-modal team-goal-detail-modal" hidden aria-hidden="true" data-team-goal-detail-modal>
            <div class="confirm-modal-backdrop" data-team-goal-detail-close></div>
            <div class="confirm-modal-card team-goal-detail-card" role="dialog" aria-modal="true" aria-label="<?= e(t('team.challenge_detail')) ?>">
                <button class="achievement-info-close" type="button" aria-label="<?= e(t('common.close_action')) ?>" data-team-goal-detail-close>&times;</button>
                <div class="team-goal-detail-content" data-team-goal-detail-content></div>
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

    const cumulativePalette = ['#6366f1', '#f97316', '#ef4444', '#22c55e', '#a855f7', '#0ea5e9', '#f59e0b', '#14b8a6'];
    const cumulativeUsers = <?= json_encode($teamCumulativeByUser ?? []) ?>;
    const cumulativeLabels = <?= json_encode($teamCumulativeLabels ?? []) ?>;

    const cumulativeStepsCtx = document.getElementById('teamCumulativeStepsChart');
    if (cumulativeStepsCtx) {
        const datasets = [{
            label: <?= json_encode(t('team.cumulative_total_steps')) ?>,
            data: <?= json_encode($teamCumulativeSteps ?? []) ?>,
            borderColor: '#14a38b',
            backgroundColor: 'rgba(20, 163, 139, 0.14)',
            fill: true,
            tension: 0.25,
            borderWidth: 2.5,
        }];

        cumulativeUsers.forEach((user, index) => {
            const color = cumulativePalette[index % cumulativePalette.length];
            datasets.push({
                label: String(user.display_name || ''),
                data: Array.isArray(user.steps) ? user.steps : [],
                borderColor: color,
                backgroundColor: 'transparent',
                fill: false,
                tension: 0.22,
                borderWidth: 1.6,
            });
        });

        new Chart(cumulativeStepsCtx, {
            type: 'line',
            data: {
                labels: cumulativeLabels,
                datasets
            },
            options: lineOpts
        });
    }

    const cumulativeDistanceCtx = document.getElementById('teamCumulativeDistanceChart');
    if (cumulativeDistanceCtx) {
        const datasets = [{
            label: <?= json_encode(t('team.cumulative_total_distance')) ?>,
            data: <?= json_encode($teamCumulativeDistance ?? []) ?>,
            borderColor: '#3b82f6',
            backgroundColor: 'rgba(59, 130, 246, 0.14)',
            fill: true,
            tension: 0.25,
            borderWidth: 2.5,
        }];

        cumulativeUsers.forEach((user, index) => {
            const color = cumulativePalette[(index + 2) % cumulativePalette.length];
            datasets.push({
                label: String(user.display_name || ''),
                data: Array.isArray(user.distance) ? user.distance : [],
                borderColor: color,
                backgroundColor: 'transparent',
                fill: false,
                tension: 0.22,
                borderWidth: 1.6,
            });
        });

        new Chart(cumulativeDistanceCtx, {
            type: 'line',
            data: {
                labels: cumulativeLabels,
                datasets
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
                    <?php if ($penaltiesEnabled): ?>
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
                    <?php endif; ?>
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
        const statusNodes = document.querySelectorAll('[data-active-challenge-status]');
        const statusTextNodes = document.querySelectorAll('[data-active-challenge-status-text]');
        const countdownLabelNodes = document.querySelectorAll('[data-active-challenge-countdown-label]');
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
            statusNodes.forEach((statusNode) => {
                if (!(statusNode instanceof HTMLElement)) {
                    return;
                }
                statusNode.classList.toggle('status-expired', isExpired);
                statusNode.classList.toggle('status-pending', isPending);
                statusNode.classList.toggle('status-active', !isExpired && !isPending);
                if (label) {
                    statusNode.textContent = label;
                }
            });
            if (label) {
                statusTextNodes.forEach((statusTextNode) => {
                    if (statusTextNode instanceof HTMLElement) {
                        statusTextNode.textContent = label;
                    }
                });
            }
            if (activeCountdownNode instanceof HTMLElement) {
                countdownLabelNodes.forEach((countdownLabelNode) => {
                    if (countdownLabelNode instanceof HTMLElement) {
                        countdownLabelNode.textContent = isPending ? countdownPendingTitle : countdownActiveTitle;
                    }
                });
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

    const challengeDetailModal = document.querySelector('[data-team-challenge-detail-modal]');
    if (challengeDetailModal instanceof HTMLElement) {
        if (challengeDetailModal.parentElement !== document.body) {
            document.body.appendChild(challengeDetailModal);
        }
        const detailOpenButtons = document.querySelectorAll('[data-team-challenge-detail-open]');
        const detailCloseButtons = challengeDetailModal.querySelectorAll('[data-team-challenge-detail-close]');
        let detailOpener = null;
        const closeChallengeDetail = () => {
            challengeDetailModal.hidden = true;
            challengeDetailModal.setAttribute('aria-hidden', 'true');
            challengeDetailModal.classList.remove('is-open');
            if (detailOpener instanceof HTMLElement) {
                detailOpener.focus();
            }
            detailOpener = null;
        };
        const openChallengeDetail = (trigger) => {
            detailOpener = trigger instanceof HTMLElement ? trigger : null;
            challengeDetailModal.hidden = false;
            challengeDetailModal.setAttribute('aria-hidden', 'false');
            window.requestAnimationFrame(() => challengeDetailModal.classList.add('is-open'));
        };
        detailOpenButtons.forEach((button) => {
            button.addEventListener('click', () => openChallengeDetail(button));
        });
        detailCloseButtons.forEach((button) => {
            button.addEventListener('click', closeChallengeDetail);
        });
        challengeDetailModal.addEventListener('click', (event) => {
            if (event.target === challengeDetailModal) {
                closeChallengeDetail();
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !challengeDetailModal.hidden) {
                closeChallengeDetail();
            }
        });
    }

    const goalDetailModal = document.querySelector('[data-team-goal-detail-modal]');
    if (goalDetailModal instanceof HTMLElement) {
        if (goalDetailModal.parentElement !== document.body) {
            document.body.appendChild(goalDetailModal);
        }
        const goalDetailContent = goalDetailModal.querySelector('[data-team-goal-detail-content]');
        const goalDetailTriggers = document.querySelectorAll('[data-team-goal-detail-trigger]');
        const goalDetailCloseButtons = goalDetailModal.querySelectorAll('[data-team-goal-detail-close]');
        let goalDetailOpener = null;

        const closeGoalDetail = () => {
            goalDetailModal.hidden = true;
            goalDetailModal.setAttribute('aria-hidden', 'true');
            goalDetailModal.classList.remove('is-open');
            if (goalDetailOpener instanceof HTMLElement) {
                goalDetailOpener.focus();
            }
            goalDetailOpener = null;
        };

        const openGoalDetail = (trigger) => {
            const card = trigger instanceof HTMLElement ? trigger.closest('.team-goal-card') : null;
            const main = card instanceof HTMLElement ? card.querySelector('.team-goal-main') : null;
            if (!(goalDetailContent instanceof HTMLElement) || !(main instanceof HTMLElement)) {
                return;
            }
            goalDetailOpener = trigger instanceof HTMLElement ? trigger : null;
            goalDetailContent.replaceChildren(main.cloneNode(true));
            goalDetailModal.hidden = false;
            goalDetailModal.setAttribute('aria-hidden', 'false');
            window.requestAnimationFrame(() => goalDetailModal.classList.add('is-open'));
        };

        goalDetailTriggers.forEach((trigger) => {
            trigger.addEventListener('click', () => openGoalDetail(trigger));
        });
        goalDetailCloseButtons.forEach((button) => {
            button.addEventListener('click', closeGoalDetail);
        });
        goalDetailModal.addEventListener('click', (event) => {
            if (event.target === goalDetailModal) {
                closeGoalDetail();
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !goalDetailModal.hidden) {
                closeGoalDetail();
            }
        });
    }

    const goalModal = document.querySelector('[data-team-goal-modal]');
    if (goalModal instanceof HTMLElement) {
        if (goalModal.parentElement !== document.body) {
            document.body.appendChild(goalModal);
        }
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
        const secondaryToggle = goalModal.querySelector('[data-goal-secondary-toggle]');
        const secondaryWrapNodes = goalModal.querySelectorAll('[data-goal-secondary-wrap]');
        const secondaryTypeSelect = goalModal.querySelector('[data-goal-secondary-type-select]');
        const secondaryTargetInput = goalModal.querySelector('[data-goal-secondary-target-input]');
        const secondaryCustomUnitWrap = goalModal.querySelector('[data-goal-secondary-custom-unit-wrap]');
        const secondaryCustomUnitInput = goalModal.querySelector('input[name="secondary_custom_unit"]');
        const rewardToggle = goalModal.querySelector('[data-goal-reward-toggle]');
        const rewardWrap = goalModal.querySelector('[data-goal-reward-wrap]');
        const rewardInput = goalModal.querySelector('input[name="reward_text"]');
        const submitButton = goalModal.querySelector('[data-team-goal-submit]');
        const defaultPlaceholder = '100';
        let opener = null;

        const updateObjectiveInputs = (typeSelect, targetField, unitWrap, unitInput) => {
            if (!(typeSelect instanceof HTMLSelectElement)) {
                return;
            }
            const selected = typeSelect.selectedOptions.length > 0 ? typeSelect.selectedOptions[0] : null;
            const isCustom = typeSelect.value === 'custom';
            if (unitWrap instanceof HTMLElement) {
                unitWrap.hidden = !isCustom;
            }
            if (!isCustom && unitInput instanceof HTMLInputElement) {
                unitInput.value = '';
            }
            if (targetField instanceof HTMLInputElement) {
                const placeholder = selected instanceof HTMLOptionElement
                    ? String(selected.dataset.goalPlaceholder || '').trim()
                    : '';
                targetField.placeholder = placeholder !== '' ? placeholder : defaultPlaceholder;
            }
        };

        const updateGoalFormByType = () => {
            updateObjectiveInputs(goalTypeSelect, targetInput, customUnitWrap, customUnitInput);
            updateObjectiveInputs(secondaryTypeSelect, secondaryTargetInput, secondaryCustomUnitWrap, secondaryCustomUnitInput);
            const secondaryEnabled = secondaryToggle instanceof HTMLInputElement && secondaryToggle.checked;
            if (!secondaryEnabled && secondaryCustomUnitWrap instanceof HTMLElement) {
                secondaryCustomUnitWrap.hidden = true;
            }
        };

        const updateSecondaryVisibility = () => {
            const enabled = secondaryToggle instanceof HTMLInputElement && secondaryToggle.checked;
            secondaryWrapNodes.forEach((node) => {
                if (node instanceof HTMLElement) {
                    node.hidden = !enabled;
                }
            });
            if (!enabled) {
                if (secondaryTargetInput instanceof HTMLInputElement) {
                    secondaryTargetInput.value = '';
                }
                if (secondaryCustomUnitInput instanceof HTMLInputElement) {
                    secondaryCustomUnitInput.value = '';
                }
            }
            if (secondaryCustomUnitWrap instanceof HTMLElement && !enabled) {
                secondaryCustomUnitWrap.hidden = true;
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

        const closeModal = () => {
            goalModal.hidden = true;
            goalModal.setAttribute('aria-hidden', 'true');
            goalModal.classList.remove('is-open');
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
            if (secondaryTypeSelect instanceof HTMLSelectElement) {
                const nextSecondaryType = isEdit && trigger instanceof HTMLElement
                    ? String(trigger.dataset.goalSecondaryTargetType || '').trim()
                    : 'steps';
                const hasSecondaryOption = [...secondaryTypeSelect.options].some((option) => option.value === nextSecondaryType);
                secondaryTypeSelect.value = hasSecondaryOption ? nextSecondaryType : 'custom';
            }
            if (secondaryTargetInput instanceof HTMLInputElement) {
                secondaryTargetInput.value = isEdit && trigger instanceof HTMLElement ? String(trigger.dataset.goalSecondaryTargetValue || '').trim() : '';
            }
            if (secondaryCustomUnitInput instanceof HTMLInputElement) {
                secondaryCustomUnitInput.value = isEdit && trigger instanceof HTMLElement ? String(trigger.dataset.goalSecondaryCustomUnit || '').trim() : '';
            }
            if (secondaryToggle instanceof HTMLInputElement) {
                secondaryToggle.checked = isEdit && trigger instanceof HTMLElement
                    ? String(trigger.dataset.goalSecondaryEnabled || '').trim() === '1'
                    : false;
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
            updateSecondaryVisibility();
            updateGoalFormByType();
            updateRewardVisibility();
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
        if (secondaryTypeSelect instanceof HTMLSelectElement) {
            secondaryTypeSelect.addEventListener('change', updateGoalFormByType);
        }
        if (secondaryToggle instanceof HTMLInputElement) {
            secondaryToggle.addEventListener('change', () => {
                updateSecondaryVisibility();
                updateGoalFormByType();
            });
            updateSecondaryVisibility();
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
    }

})();
</script>
