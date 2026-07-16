<?php

declare(strict_types=1);

$selectedUser = $selectedMetric['user'];
$dashboardSection = (string) ($dashboardSection ?? '');
if (!in_array($dashboardSection, ['', 'progress', 'rewards', 'history', 'alerts'], true)) {
    $dashboardSection = '';
}
$penaltiesEnabled = penalties_enabled($GLOBALS['pdo']);
$dashboardLayout = json_decode((string) ($currentUser['dashboard_layout_json'] ?? ''), true);
$dashboardMobileSurfaces = ['mobile_today', 'mobile_primary', 'mobile_progress', 'mobile_shortcuts'];
$dashboardDesktopWidgets = $penaltiesEnabled
    ? ['kpis', 'training_rank', 'training_progress', 'quests', 'season', 'achievements', 'achievement_progress', 'duels', 'competitions', 'approvals', 'ranking', 'weekly']
    : ['kpis', 'training_rank', 'training_progress', 'quests', 'season', 'achievements', 'achievement_progress', 'duels', 'competitions', 'ranking', 'weekly'];
$dashboardWidgets = array_merge($dashboardMobileSurfaces, $dashboardDesktopWidgets);
$visibleWidgets = [];
if (is_array($dashboardLayout) && $dashboardLayout !== []) {
    foreach ($dashboardLayout as $widget) {
        if (!is_string($widget) || $widget === '') {
            continue;
        }
        $normalizedWidget = $widget === 'money' ? 'distance_walked' : $widget;
        if (in_array($normalizedWidget, $dashboardWidgets, true) && !in_array($normalizedWidget, $visibleWidgets, true)) {
            $visibleWidgets[] = $normalizedWidget;
        }
    }
}
if ($visibleWidgets === []) {
    $visibleWidgets = $dashboardWidgets;
}
$showWidget = static fn(string $widget): bool => in_array($widget, $visibleWidgets, true);
$dashboardEditorWidgets = $visibleWidgets;
foreach ($dashboardWidgets as $widget) {
    if (!in_array($widget, $dashboardEditorWidgets, true)) {
        $dashboardEditorWidgets[] = $widget;
    }
}
$dashboardVisibleCount = count(array_intersect($dashboardEditorWidgets, $visibleWidgets));
$dashboardHiddenCount = max(0, count($dashboardEditorWidgets) - $dashboardVisibleCount);
$dashboardLayoutEditMode = $dashboardSection === '' && (string) ($_GET['layout_edit'] ?? '') === '1';
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
$contentWidgetOrder = static fn(string ...$widgets): int => $widgetOrder(...$widgets) * 10;
$dashboardUtilityOrder = $showWidget('kpis') ? $contentWidgetOrder('kpis') + 1 : 5;
$dashboardAchievementsAll = array_values((array) ($dashboardAchievements ?? []));
if (!$penaltiesEnabled) {
    $dashboardAchievementsAll = array_values(array_filter($dashboardAchievementsAll, static function (array $achievement): bool {
        $metric = strtolower(trim((string) ($achievement['metric'] ?? $achievement['target_type'] ?? '')));
        if (in_array($metric, ['strikes', 'penalties', 'penalty'], true)) {
            return false;
        }

        $text = strtolower(implode(' ', array_map('strval', [
            $achievement['name'] ?? '',
            $achievement['description'] ?? '',
            $achievement['reward_text'] ?? '',
        ])));

        return !str_contains($text, 'strike') && !str_contains($text, 'penalt');
    }));
}
$dashboardUnlockedAchievements = array_values(array_filter($dashboardAchievementsAll, static fn(array $achievement): bool => !empty($achievement['is_unlocked'])));
usort(
    $dashboardUnlockedAchievements,
    static fn(array $left, array $right): int => strcmp((string) ($right['awarded_at'] ?? ''), (string) ($left['awarded_at'] ?? ''))
);
$dashboardAchievementPreview = array_slice($dashboardUnlockedAchievements, 0, 4);
$dashboardLockedAchievements = array_values(array_filter($dashboardAchievementsAll, static fn(array $achievement): bool => empty($achievement['is_unlocked'])));
usort($dashboardLockedAchievements, static function (array $left, array $right): int {
    $progressCompare = ((float) ($right['progress_pct'] ?? 0)) <=> ((float) ($left['progress_pct'] ?? 0));
    return $progressCompare !== 0 ? $progressCompare : strcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
});
$dashboardAchievementProgressPreview = array_slice($dashboardLockedAchievements, 0, 3);
$dashboardAchievementsUrl = '/?' . http_build_query([
    'page' => 'achievements',
    'scope' => 'user',
    'user_id' => (int) ($selectedUser['id'] ?? 0),
    'back' => 'dashboard',
    'view' => (string) ($dashboardView ?? 'current_week'),
]);
$penaltySeverityClass = static function (int|float $penalty): string {
    $value = (float) $penalty;
    if ($value <= 0) {
        return 'good';
    }
    if ($value <= 50) {
        return 'warn';
    }

    return 'bad';
};

$calorieStats = is_array($dashboardCalorieStats ?? null) ? (array) $dashboardCalorieStats : [];
$formatCalories = static function (float $value): string {
    return number_format($value, 0, '.', '');
};
$calorieRangeStart = (string) ($dashboardCalorieRangeStart ?? to_date(null));
$calorieRangeEnd = (string) ($dashboardCalorieRangeEnd ?? $calorieRangeStart);
$calorieConsumedTotal = (float) ($calorieStats['total_consumed'] ?? 0);
$calorieBurnedTotal = (float) ($calorieStats['total_burned'] ?? 0);
$calorieRangeDays = 1;
try {
    $calorieRangeDays = max(
        1,
        ((new DateTimeImmutable($calorieRangeStart))->diff(new DateTimeImmutable($calorieRangeEnd))->days ?? 0) + 1
    );
} catch (Throwable) {
    $calorieRangeDays = 1;
}
$calorieBurnGoalDaily = ($selectedUser['calorie_burn_goal'] ?? null) !== null
    ? max(0.0, (float) $selectedUser['calorie_burn_goal'])
    : null;
$calorieConsumedMaxDaily = ($selectedUser['calorie_consumed_max'] ?? null) !== null
    ? max(0.0, (float) $selectedUser['calorie_consumed_max'])
    : null;
$calorieBurnGoalTotal = $calorieBurnGoalDaily !== null ? $calorieBurnGoalDaily * $calorieRangeDays : 0.0;
$calorieConsumedMaxTotal = $calorieConsumedMaxDaily !== null ? $calorieConsumedMaxDaily * $calorieRangeDays : 0.0;
$calorieBurnProgress = $calorieBurnGoalTotal > 0
    ? max(0.0, min(100.0, round(($calorieBurnedTotal / $calorieBurnGoalTotal) * 100, 1)))
    : 0.0;
$calorieConsumedProgress = 0.0;
if ($calorieConsumedMaxTotal > 0) {
    if ($calorieConsumedTotal <= $calorieConsumedMaxTotal) {
        $calorieConsumedProgress = 100.0;
    } else {
        $calorieConsumedProgress = max(
            0.0,
            min(100.0, round(($calorieConsumedMaxTotal / max(0.001, $calorieConsumedTotal)) * 100, 1))
        );
    }
}
$calorieBurnRing = (string) round($calorieBurnProgress) . '%';
$calorieConsumedRing = (string) round($calorieConsumedProgress) . '%';
$calorieBurnMeta = $calorieBurnGoalTotal > 0
    ? t('metric.goal') . ': ' . $formatCalories($calorieBurnGoalTotal) . ' kcal'
    : t('dashboard.calories_goal_not_set');
$calorieConsumedMeta = $calorieConsumedMaxTotal > 0
    ? t('dashboard.calories_max') . ': ' . $formatCalories($calorieConsumedMaxTotal) . ' kcal'
    : t('dashboard.calories_goal_not_set');
$viewSnapshot = is_array($selectedMetricSnapshot ?? null) ? (array) $selectedMetricSnapshot : [];
$viewSteps = max(0, (int) ($viewSnapshot['steps'] ?? ($selectedMetric['total_steps'] ?? 0)));
$viewDistance = round((float) ($viewSnapshot['distance_km'] ?? ($selectedMetric['total_km'] ?? 0)), 2);
$viewWorkoutSuccess = max(0, (int) ($viewSnapshot['workouts'] ?? ($selectedMetric['workout_success'] ?? 0)));
$viewWorkoutTarget = max(0, (int) ($viewSnapshot['workout_target'] ?? ($selectedMetric['workout_target'] ?? 0)));
$viewStepCompletionPct = (float) ($viewSnapshot['step_completion_pct'] ?? ($selectedMetric['step_completion_pct'] ?? 0));
$viewWorkoutCompletionPct = $viewWorkoutTarget > 0
    ? round(($viewWorkoutSuccess / $viewWorkoutTarget) * 100, 1)
    : (float) ($selectedMetric['workout_completion_pct'] ?? 0);
$viewStrikes = max(0, (int) ($viewSnapshot['strikes'] ?? ($selectedMetric['current_strikes'] ?? 0)));
$viewPenalty = max(0.0, (float) ($viewSnapshot['penalty'] ?? ($selectedMetric['total_penalty'] ?? 0)));
$viewPenaltyLabel = "\u{20AC}" . number_format($viewPenalty, 2, '.', '');

$kpis = [
    [
        'key' => 'steps',
        'label' => t('metric.steps'),
        'value' => (string) $viewSteps,
        'meta' => number_format($viewStepCompletionPct, 1, '.', '') . '%',
        'ring' => number_format($viewStepCompletionPct, 1, '.', '') . '%',
        'progress' => $viewStepCompletionPct,
    ],
    [
        'key' => 'distance',
        'label' => t('metric.total_km'),
        'value' => number_format($viewDistance, 2, '.', '') . ' km',
        'meta' => t('metric.distance_km'),
        'ring' => number_format($viewDistance, 1, '.', ''),
        'progress' => min(100, $viewDistance),
    ],
    [
        'key' => 'workouts',
        'label' => t('metric.workouts'),
        'value' => (string) $viewWorkoutSuccess . ' / ' . (string) $viewWorkoutTarget,
        'meta' => (string) $viewWorkoutCompletionPct . '%',
        'ring' => (string) $viewWorkoutCompletionPct . '%',
        'progress' => (float) $viewWorkoutCompletionPct,
    ],
];
if ($penaltiesEnabled) {
    $kpis[] = [
        'key' => 'strikes',
        'label' => t('metric.strikes'),
        'value' => (string) $viewStrikes,
        'meta' => t('dashboard.accumulated_penalty', ['amount' => $viewPenaltyLabel]),
        'ring' => (string) $viewStrikes,
        'progress' => max(0, 100 - ($viewStrikes * 10)),
    ];
}
$metricQueryBase = [
    'page' => 'metric',
    'user_id' => (int) ($selectedUser['id'] ?? 0),
    'view' => (string) ($dashboardView ?? 'current_week'),
];
$strikesQueryBase = [
    'page' => 'strikes_detail',
    'user_id' => (int) ($selectedUser['id'] ?? 0),
    'view' => (string) ($dashboardView ?? 'current_week'),
];
$strikesHref = '/?' . http_build_query($strikesQueryBase);
$penaltiesHref = '/?' . http_build_query([
    'page' => 'penalties',
    'user_id' => (int) ($selectedUser['id'] ?? 0),
    'view' => (string) ($dashboardView ?? 'current_week'),
]);
$dashboardViewParam = (string) ($dashboardView ?? 'current_week');
$dashboardTopbarQuery = [
    'page' => 'dashboard',
    'user_id' => (int) ($selectedUser['id'] ?? 0),
    'view' => $dashboardViewParam,
];
$dashboardEditLayoutUrl = '/?' . http_build_query($dashboardTopbarQuery + ['layout_edit' => '1']);
$dashboardCancelEditLayoutUrl = '/?' . http_build_query($dashboardTopbarQuery);
$selectedWeekPenalty = 0.0;
foreach ((array) ($selectedMetric['weekly'] ?? []) as $weekRow) {
    if ((string) ($weekRow['week_start'] ?? '') === (string) ($selectedWeekStart ?? '')) {
        $selectedWeekPenalty = max(0.0, (float) ($weekRow['penalty'] ?? 0));
        break;
    }
}
ob_start();
?>
<?php if ($dashboardLayoutEditMode): ?>
<button class="btn btn-primary btn-topbar" type="submit" form="dashboard-layout-edit-form"><?= e(t('common.save')) ?></button>
<?php else: ?>
<details class="topbar-context dashboard-mobile-controls">
    <summary class="btn btn-ghost btn-topbar dashboard-controls-trigger"><?= e(t('dashboard.view_mode')) ?></summary>
    <div class="topbar-context-panel">
        <form method="get" class="stack dashboard-control-form" data-dashboard-control-form>
        <input type="hidden" name="page" value="dashboard">
        <label class="dashboard-control-field">
            <?= e(t('dashboard.viewing')) ?>
            <select class="glass-select" name="user_id" data-testid="dashboard-user-select">
                <?php foreach ($users as $user): ?>
                    <option value="<?= (int) $user['id'] ?>" <?= (int) $user['id'] === (int) $selectedUser['id'] ? 'selected' : '' ?>>
                        <?= e((string) $user['display_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="dashboard-control-field">
            <?= e(t('dashboard.view_mode')) ?>
            <select class="glass-select" name="view" data-testid="dashboard-week-select">
                <option value="current_week" <?= ($dashboardView ?? '') === 'current_week' ? 'selected' : '' ?>><?= e(t('dashboard.current_week')) ?></option>
                <option value="total" <?= ($dashboardView ?? '') === 'total' ? 'selected' : '' ?>><?= e(t('metric.total')) ?></option>
                <?php foreach ($weekOptions as $weekStart): ?>
                    <option value="<?= e($weekStart) ?>" <?= ($dashboardView ?? '') === $weekStart ? 'selected' : '' ?>><?= e(format_date_eu($weekStart)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        </form>
        <?php // One entry point on every size: edit mode itself is the editor - you drag the
              // real cards on desktop, and use the visible-widgets list on touch. ?>
        <a class="btn btn-primary btn-block edit-layout-button" href="<?= e($dashboardEditLayoutUrl) ?>"><?= e(t('dashboard.edit_layout')) ?></a>
    </div>
</details>
<?php endif; ?>
<?php
$topbarControls = ob_get_clean();
?>
<section class="screen stack-lg dashboard-hierarchy-screen<?= $dashboardSection !== '' ? ' has-section' : '' ?>" data-dashboard-page data-dashboard-section="<?= e($dashboardSection) ?>">
    <?php if ($dashboardSection !== ''): ?>
        <header class="hierarchy-page-header">
            <button class="hierarchy-back" type="button" data-hierarchy-back data-fallback="/?page=dashboard" aria-label="<?= e(t('common.back')) ?>">&larr;</button>
            <div><p class="eyebrow"><?= e(t('nav.home')) ?></p><h1><?= e(t('dashboard.mobile_' . $dashboardSection)) ?></h1><p><?= e(t('dashboard.mobile_' . $dashboardSection . '_hint')) ?></p></div>
        </header>

        <?php if ($dashboardSection === 'progress'): ?>
            <div class="mobile-kpi-grid">
                <?php foreach ($kpis as $kpi): ?>
                    <?php $mobileMetricHref = (string) ($kpi['key'] ?? '') === 'strikes' ? $strikesHref : '/?' . http_build_query($metricQueryBase + ['metric' => (string) $kpi['key']]); ?>
                    <a href="<?= e($mobileMetricHref) ?>"><small><?= e((string) $kpi['label']) ?></small><strong><?= e((string) $kpi['value']) ?></strong><span><?= e((string) $kpi['meta']) ?></span></a>
                <?php endforeach; ?>
            </div>
            <nav class="hierarchy-nav-list">
                <a class="hierarchy-nav-row" href="/?page=analytics"><span class="hierarchy-nav-icon" aria-hidden="true">&#8645;</span><span class="hierarchy-nav-copy"><strong><?= e(t('nav.analytics')) ?></strong><small><?= e(t('dashboard.analytics_dashboard_hint')) ?></small></span><span class="hierarchy-nav-chevron" aria-hidden="true">&rsaquo;</span></a>
                <a class="hierarchy-nav-row" href="/?page=workouts&view=stats"><span class="hierarchy-nav-icon" aria-hidden="true">&#9876;</span><span class="hierarchy-nav-copy"><strong><?= e(t('dashboard.training_progress_title')) ?></strong><small><?= e(t('dashboard.training_progress_hint')) ?></small></span><span class="hierarchy-nav-meta"><?= (int) (($dashboardTrainingMonth ?? [])['sessions'] ?? 0) ?></span><span class="hierarchy-nav-chevron" aria-hidden="true">&rsaquo;</span></a>
                <a class="hierarchy-nav-row" href="/?page=workouts&view=ranks"><span class="hierarchy-nav-icon" aria-hidden="true">#</span><span class="hierarchy-nav-copy"><strong><?= e(t('dashboard.training_rank_title')) ?></strong><small><?= e(t('dashboard.training_rank_hint')) ?></small></span><span class="hierarchy-nav-meta"><?= e(t('workouts.rank_' . (string) (($dashboardTrainingRank ?? [])['key'] ?? 'unranked'))) ?></span><span class="hierarchy-nav-chevron" aria-hidden="true">&rsaquo;</span></a>
            </nav>
        <?php elseif ($dashboardSection === 'rewards'): ?>
            <div class="hierarchy-status-strip">
                <span><strong><?= count($dashboardUnlockedAchievements) ?></strong><small><?= e(t('profile.achievements')) ?></small></span>
                <span><strong><?= e(number_format((float) ($dashboardSeasonXp ?? 0), 0, '.', ' ')) ?></strong><small>XP</small></span>
                <span><strong><?= (int) ($dashboardQuestStreak ?? 0) ?></strong><small><?= e(t('workouts.streak')) ?></small></span>
            </div>
            <nav class="hierarchy-nav-list">
                <a class="hierarchy-nav-row" href="<?= e($dashboardAchievementsUrl) ?>"><span class="hierarchy-nav-icon" aria-hidden="true">&#9733;</span><span class="hierarchy-nav-copy"><strong><?= e(t('profile.achievements')) ?></strong><small><?= e(t('dashboard.mobile_rewards_achievements_hint')) ?></small></span><span class="hierarchy-nav-chevron" aria-hidden="true">&rsaquo;</span></a>
                <a class="hierarchy-nav-row" href="/?page=season"><span class="hierarchy-nav-icon" aria-hidden="true">XP</span><span class="hierarchy-nav-copy"><strong><?= e(t('season.title')) ?></strong><small><?= e((string) (($dashboardSeason ?? [])['name'] ?? '')) ?></small></span><span class="hierarchy-nav-meta"><?= (int) ($dashboardSeasonDaysLeft ?? 0) ?></span><span class="hierarchy-nav-chevron" aria-hidden="true">&rsaquo;</span></a>
            </nav>
            <?php $mobileQuests = array_values((array) ($dashboardQuests['daily'] ?? $dashboardQuests ?? [])); ?>
            <?php if ($mobileQuests !== []): ?>
                <article class="native-list-card" data-collapsible-list data-mobile-count="3" data-desktop-count="3"><h2><?= e(t('quests.title')) ?></h2><?php foreach ($mobileQuests as $quest): ?><div class="native-list-row" data-collapsible-item><span><?= e((string) ($quest['label'] ?? '')) ?></span><strong><?= (int) ($quest['pct'] ?? 0) ?>%</strong></div><?php endforeach; ?><button class="inline-list-toggle" type="button" data-collapsible-toggle data-label-more="<?= e(t('profile.show_more')) ?>" data-label-less="<?= e(t('profile.show_less')) ?>"><?= e(t('profile.show_more')) ?></button></article>
            <?php endif; ?>
        <?php elseif ($dashboardSection === 'history'): ?>
            <article class="native-list-card">
                <div class="native-list-head"><h2><?= e(t('dashboard.weekly_history')) ?></h2><a href="/?page=week_editor&range=week"><?= e(t('workouts.challenge_log')) ?></a></div>
                <?php foreach (array_slice(array_reverse(array_values((array) ($selectedMetric['weekly'] ?? []))), 0, 8) as $week): ?>
                    <a class="native-list-row" href="/?page=week_editor&user_id=<?= (int) ($selectedUser['id'] ?? 0) ?>&week=<?= e(date_to_iso_week((string) ($week['week_start'] ?? ''))) ?>"><span><strong><?= e(format_date_eu((string) ($week['week_start'] ?? ''))) ?></strong><small><?= e(label_for_status((string) ($week['status'] ?? ''))) ?></small></span><span><?= e((string) ($week['workouts'] ?? 0)) ?> <?= e(t('metric.workouts')) ?></span></a>
                <?php endforeach; ?>
            </article>
        <?php else: ?>
            <?php $mobileUnread = user_unread_notifications_count($GLOBALS['pdo'], (int) ($currentUser['id'] ?? 0)); ?>
            <nav class="hierarchy-nav-list">
                <a class="hierarchy-nav-row" href="/?page=notifications"><span class="hierarchy-nav-icon" aria-hidden="true">!</span><span class="hierarchy-nav-copy"><strong><?= e(t('nav.notifications')) ?></strong><small><?= e(t('notifications.subtitle')) ?></small></span><span class="hierarchy-nav-meta"><?= $mobileUnread ?></span><span class="hierarchy-nav-chevron" aria-hidden="true">&rsaquo;</span></a>
                <?php if (!empty($pendingApprovals)): ?><a class="hierarchy-nav-row" href="/?page=dashboard#pending-approvals"><span class="hierarchy-nav-icon" aria-hidden="true">&#10003;</span><span class="hierarchy-nav-copy"><strong><?= e(t('dashboard.approvals_pending_eyebrow')) ?></strong><small><?= e(t('dashboard.mobile_approvals_hint')) ?></small></span><span class="hierarchy-nav-meta"><?= count($pendingApprovals) ?></span><span class="hierarchy-nav-chevron" aria-hidden="true">&rsaquo;</span></a><?php endif; ?>
                <a class="hierarchy-nav-row" href="/?page=duels"><span class="hierarchy-nav-icon" aria-hidden="true">&#9876;</span><span class="hierarchy-nav-copy"><strong><?= e(t('nav.duels')) ?></strong><small><?= e(t('social_hub.duels_hint')) ?></small></span><span class="hierarchy-nav-meta"><?= (int) (($dashboardDuelsSummary ?? [])['active'] ?? 0) ?></span><span class="hierarchy-nav-chevron" aria-hidden="true">&rsaquo;</span></a>
                <a class="hierarchy-nav-row" href="/?page=competitions"><span class="hierarchy-nav-icon" aria-hidden="true">&#9733;</span><span class="hierarchy-nav-copy"><strong><?= e(t('nav.competitions')) ?></strong><small><?= e(t('social_hub.competitions_hint')) ?></small></span><span class="hierarchy-nav-meta"><?= (int) (($dashboardCompetitionsSummary ?? [])['active'] ?? 0) ?></span><span class="hierarchy-nav-chevron" aria-hidden="true">&rsaquo;</span></a>
            </nav>
            <?php if (!empty($pendingApprovals)): ?>
                <article class="native-list-card mobile-approval-list" id="pending-approvals">
                    <h2><?= e(t('dashboard.approvals_pending_eyebrow')) ?></h2>
                    <?php foreach ($pendingApprovals as $approval): ?>
                        <form method="post" action="/?page=dashboard&section=alerts" class="mobile-approval-row">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="resolve_approval"><input type="hidden" name="approval_id" value="<?= (int) ($approval['id'] ?? 0) ?>">
                            <span><strong><?= e((string) ($approval['approval_type_label'] ?? '')) ?></strong><small><?= e((string) ($approval['owner_name'] ?? '')) ?> · <?= e(format_date_eu((string) ($approval['log_date'] ?? ''))) ?></small></span>
                            <span class="mobile-approval-actions"><button class="btn btn-primary small" type="submit" name="decision" value="approve"><?= e(t('common.approve')) ?></button><button class="btn btn-ghost small" type="submit" name="decision" value="reject"><?= e(t('common.reject')) ?></button></span>
                        </form>
                    <?php endforeach; ?>
                </article>
            <?php endif; ?>
        <?php endif; ?>
    <?php else: ?>
    <div class="dashboard-mobile-home">
        <header class="mobile-home-greeting"><p><?= e(t('dashboard.mobile_today')) ?></p><h1><?= e((string) ($selectedUser['display_name'] ?? t('nav.home'))) ?></h1></header>
        <article class="mobile-today-card" data-dashboard-mobile-surface="mobile_today"<?= $showWidget('mobile_today') ? '' : ' hidden' ?>>
            <?php $dashboardScoreDecimal = current_locale() === 'en' ? '.' : ','; ?>
            <a class="mobile-today-head mobile-score-link" href="/?<?= e(http_build_query($metricQueryBase + ['metric' => 'score'])) ?>"><span><?= e(t('metric.score')) ?></span><strong><?= e(number_format((float) ($selectedMetric['score'] ?? 0), 1, $dashboardScoreDecimal, '')) ?> <small>/ 100</small></strong></a>
            <div class="mobile-today-metrics">
                <?php foreach ($kpis as $kpi): ?><?php $mobileKpiHref = (string) ($kpi['key'] ?? '') === 'strikes' ? $strikesHref : '/?' . http_build_query($metricQueryBase + ['metric' => (string) ($kpi['key'] ?? '')]); ?><a href="<?= e($mobileKpiHref) ?>"><strong><?= e((string) $kpi['value']) ?></strong><small><?= e((string) $kpi['label']) ?></small></a><?php endforeach; ?>
                <a href="/?<?= e(http_build_query($metricQueryBase + ['metric' => 'calories_consumed'])) ?>"><strong><?= e($formatCalories($calorieConsumedTotal)) ?> kcal</strong><small><?= e(t('dashboard.calories_consumed')) ?></small></a>
                <a href="/?<?= e(http_build_query($metricQueryBase + ['metric' => 'calories_burned'])) ?>"><strong><?= e($formatCalories($calorieBurnedTotal)) ?> kcal</strong><small><?= e(t('dashboard.calories_burned')) ?></small></a>
            </div>
        </article>
        <a class="mobile-primary-action" data-dashboard-mobile-surface="mobile_primary"<?= $showWidget('mobile_primary') ? '' : ' hidden' ?> href="/?page=entries&mode=data"><span><strong><?= e(t('dashboard.quick_action_training')) ?></strong><small><?= e(t('dashboard.mobile_primary_hint')) ?></small></span><span aria-hidden="true">&rsaquo;</span></a>
        <article class="mobile-progress-brief" data-dashboard-mobile-surface="mobile_progress"<?= $showWidget('mobile_progress') ? '' : ' hidden' ?>><div><small><?= e(t('dashboard.training_rank_title')) ?></small><strong><?= e(t('workouts.rank_' . (string) (($dashboardTrainingRank ?? [])['key'] ?? 'unranked'))) ?></strong></div><div><small><?= e(t('workouts.streak')) ?></small><strong><?= (int) ($dashboardTrainingStreak ?? 0) ?></strong></div><a href="/?page=dashboard&section=progress"><?= e(t('common.view_all')) ?></a></article>
        <nav class="mobile-home-shortcuts" data-dashboard-mobile-surface="mobile_shortcuts"<?= $showWidget('mobile_shortcuts') ? '' : ' hidden' ?> aria-label="<?= e(t('dashboard.mobile_more')) ?>">
            <a href="/?page=dashboard&section=progress" data-tone="blue"><span aria-hidden="true">&#8645;</span><strong><?= e(t('dashboard.mobile_progress')) ?></strong></a>
            <a href="/?page=dashboard&section=rewards" data-tone="amber"><span aria-hidden="true">&#9733;</span><strong><?= e(t('dashboard.mobile_rewards')) ?></strong></a>
            <a href="/?page=dashboard&section=history" data-tone="violet"><span aria-hidden="true">&#8634;</span><strong><?= e(t('dashboard.mobile_history')) ?></strong></a>
            <a href="/?page=dashboard&section=alerts" data-tone="red"><span aria-hidden="true">!</span><strong><?= e(t('dashboard.mobile_alerts')) ?></strong><?php if (count($pendingApprovals) > 0): ?><em><?= count($pendingApprovals) ?></em><?php endif; ?></a>
        </nav>
    </div>
    <div class="dashboard-desktop-root">
    <header class="mobile-widget-feed-head"><div><p><?= e(t('dashboard.visible_widgets')) ?></p><h2><?= e(t('nav.dashboard')) ?></h2></div><a href="<?= e($dashboardEditLayoutUrl) ?>"><?= e(t('dashboard.edit_layout')) ?></a></header>
    <div class="motivation-band">
        <span><?= e(t('dashboard.motivation')) ?></span>
        <strong>"<?= e((string) ($motivationQuote ?? t('dashboard.default_quote'))) ?>"</strong>
    </div>

    <?php if ($dashboardLayoutEditMode): ?>
    <div class="dashboard-layout-editbar">
        <form id="dashboard-layout-edit-form" method="post" action="/?page=dashboard" class="dashboard-layout-editor dashboard-layout-editor-mobile" data-dashboard-layout-editor>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_dashboard_layout">
            <input type="hidden" name="dashboard_view" value="<?= e((string) ($dashboardView ?? 'current_week')) ?>">
            <input type="hidden" name="redirect_user_id" value="<?= (int) ($selectedUser['id'] ?? 0) ?>">

            <div class="dashboard-editbar-row">
                <p class="dashboard-editbar-hint">
                    <strong><?= e(t('dashboard.edit_layout')) ?></strong>
                    <small><?= e(t('dashboard.layout_hint')) ?></small>
                </p>
                <div class="dashboard-editbar-actions">
                    <a class="btn btn-ghost small" href="<?= e($dashboardCancelEditLayoutUrl) ?>"><?= e(t('common.cancel')) ?></a>
                    <button class="btn btn-primary small" type="submit"><?= e(t('common.save')) ?></button>
                </div>
            </div>

            <div class="dashboard-layout-state" data-dashboard-layout-state
                 data-visible-template="<?= e(t('dashboard.layout_visible_count', ['count' => '{count}'])) ?>"
                 data-hidden-template="<?= e(t('dashboard.layout_hidden_count', ['count' => '{count}'])) ?>"
                 data-saved-label="<?= e(t('common.saved')) ?>"
                 data-changed-label="<?= e(t('dashboard.layout_changed')) ?>">
                <span data-layout-visible-count><?= e(t('dashboard.layout_visible_count', ['count' => $dashboardVisibleCount])) ?></span>
                <span data-layout-hidden-count><?= e(t('dashboard.layout_hidden_count', ['count' => $dashboardHiddenCount])) ?></span>
                <strong data-layout-change-state><?= e(t('common.saved')) ?></strong>
            </div>

            <?php // The widget list still backs the form (order inputs + checkboxes the
                  // drag handler syncs), but it is tucked away: reordering happens on the
                  // real cards, so the only reason to open this is to show/hide a widget. ?>
            <details class="dashboard-layout-visibility">
                <summary><?= e(t('dashboard.visible_widgets')) ?></summary>
                <div class="team-layout-editor-list dashboard-layout-editor-list" data-dashboard-layout-list>
                    <?php foreach ($dashboardEditorWidgets as $idx => $widget): ?>
                        <?php $isMobileSurface = in_array($widget, $dashboardMobileSurfaces, true); ?>
                        <div class="team-layout-editor-item dashboard-layout-editor-item dashboard-layout-edit-card<?= $isMobileSurface ? ' is-mobile-surface' : '' ?>" data-dashboard-layout-item data-layout-scope="<?= $isMobileSurface ? 'mobile' : 'desktop' ?>">
                            <?php if (!$isMobileSurface): ?>
                                <div class="dashboard-layout-mobile-actions">
                                    <button class="btn btn-ghost small" type="button" data-layout-move="up" aria-label="<?= e(t('dashboard.move_up')) ?>">&uarr;</button>
                                    <button class="btn btn-ghost small" type="button" data-layout-move="down" aria-label="<?= e(t('dashboard.move_down')) ?>">&darr;</button>
                                </div>
                            <?php endif; ?>
                            <label class="dashboard-layout-toggle">
                                <input type="checkbox" name="dashboard_widgets[]" value="<?= e($widget) ?>" <?= in_array($widget, $visibleWidgets, true) ? 'checked' : '' ?>>
                                <span><strong><?= e(t('dashboard.widget_' . $widget)) ?></strong><small><?= e(t($isMobileSurface ? 'dashboard.layout_scope_mobile' : 'dashboard.layout_scope_desktop')) ?></small></span>
                            </label>
                            <input type="hidden" name="dashboard_order[<?= e($widget) ?>]" value="<?= e((string) ($idx + 1)) ?>" data-dashboard-order-input>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button class="btn btn-ghost small dashboard-editbar-reset" type="submit" name="reset_dashboard_layout" value="1"><?= e(t('dashboard.reset_layout')) ?></button>
            </details>
        </form>
    </div>
    <?php endif; ?>

    <div class="dashboard-layout" data-unsaved-message="<?= e(t('dashboard.unsaved_changes')) ?>">
        <?php if ($showWidget('kpis')): ?>
        <div class="metric-grid dashboard-span-full dashboard-kpis" data-dashboard-widget="kpis" style="order: <?= $contentWidgetOrder('kpis') ?>">
            <?php $dashboardScoreValue = (float) ($selectedMetric['score'] ?? 0); ?>
            <a class="metric-card metric-card-link metric-card-score" href="/?<?= e(http_build_query($metricQueryBase + ['metric' => 'score'])) ?>" data-testid="metric-card-link-score">
                <div class="progress-ring" style="--value: <?= e((string) min(100, max(0, $dashboardScoreValue))) ?>;">
                    <span><?= e(number_format($dashboardScoreValue, 1, $dashboardScoreDecimal, '')) ?></span>
                </div>
                <div>
                    <span><?= e(t('metric.score')) ?></span>
                    <strong><?= e(number_format($dashboardScoreValue, 1, $dashboardScoreDecimal, '')) ?> / 100</strong>
                    <p><?= e(t('dashboard.current_week')) ?></p>
                </div>
            </a>
            <?php foreach ($kpis as $kpi): ?>
                <?php
                $metricHref = '/?' . http_build_query($metricQueryBase + ['metric' => (string) $kpi['key']]);
                if ((string) ($kpi['key'] ?? '') === 'strikes') {
                    $metricHref = $strikesHref;
                }
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
            <a class="metric-card metric-card-link metric-card-calorie-goal" href="/?<?= e(http_build_query($metricQueryBase + ['metric' => 'calories_consumed'])) ?>" data-testid="metric-card-calorie-consumed">
                <div class="progress-ring" style="--value: <?= e((string) min(100, max(0, $calorieConsumedProgress))) ?>;">
                    <span><?= e($calorieConsumedRing) ?></span>
                </div>
                <div>
                    <span><?= e(t('dashboard.calories_consumed')) ?></span>
                    <strong><?= e($formatCalories($calorieConsumedTotal)) ?> kcal</strong>
                    <p><?= e($calorieConsumedMeta) ?></p>
                </div>
            </a>
            <a class="metric-card metric-card-link metric-card-calorie-goal" href="/?<?= e(http_build_query($metricQueryBase + ['metric' => 'calories_burned'])) ?>" data-testid="metric-card-calorie-burned">
                <div class="progress-ring" style="--value: <?= e((string) min(100, max(0, $calorieBurnProgress))) ?>;">
                    <span><?= e($calorieBurnRing) ?></span>
                </div>
                <div>
                    <span><?= e(t('dashboard.calories_burned')) ?></span>
                    <strong><?= e($formatCalories($calorieBurnedTotal)) ?> kcal</strong>
                    <p><?= e($calorieBurnMeta) ?></p>
                </div>
            </a>
        </div>
        <?php endif; ?>

        <article class="panel dashboard-panel dashboard-span-full dashboard-quick-actions" style="order: <?= $dashboardUtilityOrder ?>">
            <div class="dashboard-quick-actions-grid">
                <a class="btn btn-primary dashboard-quick-action" href="/?page=entries&mode=data">
                    <?= e(t('dashboard.quick_action_training')) ?>
                </a>
                <a class="btn btn-secondary dashboard-quick-action" href="/?page=entries&mode=meal">
                    <?= e(t('dashboard.quick_action_meal')) ?>
                </a>
            </div>
        </article>

        <?php if ($penaltiesEnabled): ?>
        <article class="panel dashboard-panel dashboard-penalty-compact penalties-only" style="order: <?= $dashboardUtilityOrder + 1 ?>">
            <div class="panel-head dashboard-panel-head-compact dashboard-penalty-head">
                <div>
                    <p class="eyebrow"><?= e(t('metric.penalty')) ?></p>
                </div>
            </div>
            <div class="dashboard-penalty-compact-grid">
                <span>
                    <small><?= e(t('metric.strikes')) ?></small>
                    <strong><?= e((string) $viewStrikes) ?></strong>
                </span>
                <span>
                    <small><?= e(t('metric.week_penalty')) ?></small>
                    <strong>&euro;<?= e(number_format($selectedWeekPenalty, 2, '.', '')) ?></strong>
                </span>
            </div>
            <a class="btn btn-ghost small btn-block" href="<?= e($penaltiesHref) ?>"><?= e(t('dashboard.penalty_details')) ?></a>
        </article>
        <?php endif; ?>

        <article class="panel dashboard-panel dashboard-analytics-cta dashboard-analytics-compact" style="order: <?= $dashboardUtilityOrder + 2 ?>">
            <div class="dashboard-analytics-compact-copy">
                <p class="eyebrow"><?= e(t('dashboard.analytics_eyebrow')) ?></p>
                <p class="muted small"><?= e(t('dashboard.analytics_dashboard_hint')) ?></p>
            </div>
            <a class="btn btn-primary small dashboard-analytics-compact-action" href="/?<?= e(http_build_query(['page' => 'analytics', 'user_id' => (int) ($selectedUser['id'] ?? 0), 'analytics_period' => 'week', 'analytics_week' => (string) ($selectedWeekStart ?? to_date(null))])) ?>"><?= e(t('dashboard.open_analytics')) ?></a>
        </article>

        <?php if ($showWidget('training_rank')): ?>
            <?php
            $trainingRank = (array) ($dashboardTrainingRank ?? wk_rank_from_score(0.0));
            $trainingRankKey = (string) ($trainingRank['key'] ?? 'unranked');
            if (!array_key_exists($trainingRankKey, wk_rank_tiers())) {
                $trainingRankKey = 'unranked';
            }
            $trainingRankScore = (float) ($trainingRank['score'] ?? 0.0);
            $trainingNextKey = is_string($trainingRank['next_key'] ?? null) ? (string) $trainingRank['next_key'] : '';
            $trainingNextScore = is_numeric($trainingRank['next_score'] ?? null) ? (float) $trainingRank['next_score'] : null;
            $trainingPointsToNext = $trainingNextScore !== null ? max(0.0, $trainingNextScore - $trainingRankScore) : 0.0;
            ?>
            <article class="panel dashboard-panel dashboard-training-rank compact-panel glass-panel" data-dashboard-widget="training_rank" data-rank="<?= e($trainingRankKey) ?>" style="order: <?= $contentWidgetOrder('training_rank') ?>; --rank-color: <?= e((string) ($trainingRank['color'] ?? '#64748b')) ?>">
                <div class="panel-head dashboard-panel-head-compact">
                    <div>
                        <p class="eyebrow"><?= e(t('dashboard.training_rank_title')) ?></p>
                        <p class="muted small"><?= e(t('dashboard.training_rank_hint')) ?></p>
                    </div>
                    <a class="btn btn-ghost small dashboard-panel-action" href="/?page=workouts&amp;view=ranks"><?= e(t('common.view_all')) ?></a>
                </div>
                <div class="dashboard-training-rank-summary">
                    <div class="dashboard-training-rank-emblem">
                        <span><?= e(t('workouts.rank_' . $trainingRankKey)) ?></span>
                        <strong><?= e(number_format($trainingRankScore, 1, '.', '')) ?></strong>
                        <small><?= e(t('workouts.lift_points')) ?></small>
                    </div>
                    <div class="dashboard-training-rank-copy">
                        <strong><?= ($dashboardTrainingPosition ?? null) !== null ? e(t('dashboard.training_rank_position', ['position' => (int) $dashboardTrainingPosition])) : e(t('workouts.rank_unranked')) ?></strong>
                        <small><?= e(t('workouts.ranked_count', [
                            'ranked' => (int) ($trainingRank['body_parts_ranked'] ?? 0),
                            'total' => (int) ($trainingRank['body_parts_total'] ?? 0),
                        ])) ?></small>
                        <div class="dashboard-training-rank-progress" aria-label="<?= e(t('workouts.overall_rank')) ?>"><span style="width: <?= (int) ($trainingRank['progress'] ?? 0) ?>%"></span></div>
                        <?php if ($trainingNextKey !== '' && $trainingNextScore !== null): ?>
                            <small><?= e(t('dashboard.training_rank_next', [
                                'points' => number_format($trainingPointsToNext, 1, '.', ''),
                                'rank' => t('workouts.rank_' . $trainingNextKey),
                            ])) ?></small>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="dashboard-training-board" aria-label="<?= e(t('workouts.team_leaderboard')) ?>">
                    <?php foreach ((array) ($dashboardTrainingLeaderboardPreview ?? []) as $trainingUser): ?>
                        <?php
                        $trainingUserRank = (array) ($trainingUser['rank'] ?? []);
                        $trainingUserRankKey = (string) ($trainingUserRank['key'] ?? 'unranked');
                        $trainingUserName = (string) ($trainingUser['display_name'] ?? t('common.user'));
                        $trainingUserAvatar = avatar_url($trainingUser);
                        ?>
                        <a class="dashboard-training-board-row<?= (int) ($trainingUser['id'] ?? 0) === (int) ($selectedUser['id'] ?? 0) ? ' is-selected' : '' ?>" href="/?page=profile&amp;user_id=<?= (int) ($trainingUser['id'] ?? 0) ?>">
                            <strong>#<?= (int) ($trainingUser['position'] ?? 0) ?></strong>
                            <?php if ($trainingUserAvatar !== ''): ?>
                                <img src="<?= e($trainingUserAvatar) ?>" alt="<?= e($trainingUserName) ?>">
                            <?php else: ?>
                                <span class="dashboard-training-board-avatar"><?= e(initials_for($trainingUserName)) ?></span>
                            <?php endif; ?>
                            <span class="dashboard-training-board-copy"><strong><?= e($trainingUserName) ?></strong><small><?= e(number_format((float) ($trainingUserRank['score'] ?? 0), 1, '.', '')) ?> <?= e(t('workouts.lift_points')) ?></small></span>
                            <span class="workouts-rank-badge" data-rank="<?= e($trainingUserRankKey) ?>"><?= e(t('workouts.rank_' . $trainingUserRankKey)) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </article>
        <?php endif; ?>

        <?php if ($showWidget('training_progress')): ?>
            <?php
            $trainingMonth = (array) ($dashboardTrainingMonth ?? []);
            $trainingAll = (array) ($dashboardTrainingAll ?? []);
            $trainingRecentSession = (array) (($dashboardTrainingRecentSessions ?? [])[0] ?? []);
            ?>
            <article class="panel dashboard-panel dashboard-training-progress compact-panel glass-panel compact-progress-panel" data-dashboard-widget="training_progress" style="order: <?= $contentWidgetOrder('training_progress') ?>">
                <div class="panel-head dashboard-panel-head-compact">
                    <div>
                        <p class="eyebrow"><?= e(t('dashboard.training_progress_title')) ?></p>
                        <p class="muted small"><?= e(t('dashboard.training_progress_hint')) ?></p>
                    </div>
                    <a class="btn btn-ghost small dashboard-panel-action" href="/?page=workouts&amp;view=stats"><?= e(t('common.view_all')) ?></a>
                </div>
                <div class="dashboard-training-progress-grid compact-metrics-row">
                    <span><small><?= e(t('workouts.stat_sessions')) ?> · <?= e(t('workouts.this_month')) ?></small><strong><?= (int) ($trainingMonth['sessions'] ?? 0) ?></strong></span>
                    <span><small><?= e(t('workouts.stat_volume')) ?> · <?= e(t('workouts.this_month')) ?></small><strong><?= e(number_format((float) ($trainingMonth['volume'] ?? 0), 0, '.', ' ')) ?></strong></span>
                    <span><small><?= e(t('workouts.streak')) ?></small><strong><?= (int) ($dashboardTrainingStreak ?? 0) ?></strong></span>
                    <span><small><?= e(t('workouts.stat_reps')) ?> · <?= e(t('workouts.all_time')) ?></small><strong><?= e(number_format((int) ($trainingAll['reps'] ?? 0), 0, '.', ' ')) ?></strong></span>
                </div>
                <?php if ($trainingRecentSession !== []): ?>
                    <div class="dashboard-training-recent">
                        <span class="dashboard-training-recent-icon" aria-hidden="true"><?= activity_icon_svg('dumbbell') ?></span>
                        <span><small><?= e(t('dashboard.training_recent')) ?></small><strong><?= e((string) (($trainingRecentSession['title'] ?? '') !== '' ? $trainingRecentSession['title'] : t('workouts.session'))) ?></strong></span>
                        <time datetime="<?= e((string) ($trainingRecentSession['started_at'] ?? '')) ?>"><?= e(format_date_eu(substr((string) ($trainingRecentSession['started_at'] ?? ''), 0, 10))) ?></time>
                    </div>
                <?php else: ?>
                    <a class="dashboard-training-empty" href="/?page=workouts"><?= e(t('dashboard.training_empty')) ?> <span aria-hidden="true">→</span></a>
                <?php endif; ?>
            </article>
        <?php endif; ?>

        <?php if ($showWidget('achievements')): ?>
        <article class="panel dashboard-panel dashboard-achievements-panel dashboard-span-full compact-panel glass-panel" data-dashboard-widget="achievements" style="order: <?= $contentWidgetOrder('achievements') ?>">
            <div class="panel-head dashboard-panel-head-compact">
                <div>
                    <p class="eyebrow"><?= e(t('achievements.title')) ?></p>
                    <p class="muted small"><?= e(t('dashboard.latest_unlocked_achievements')) ?></p>
                </div>
                <div class="dashboard-achievements-summary">
                    <a class="btn btn-ghost small dashboard-panel-action" href="<?= e($dashboardAchievementsUrl) ?>"><?= e(t('common.view_all')) ?></a>
                </div>
            </div>
            <div class="dashboard-achievements-grid dashboard-achievement-rows">
                <?php foreach ($dashboardAchievementPreview as $achievement): ?>
                    <?php
                    $progressPct = is_numeric($achievement['progress_pct'] ?? null) ? max(0.0, min(100.0, (float) $achievement['progress_pct'])) : null;
                    ?>
                    <article class="achievement-card dashboard-achievement-card dashboard-achievement-row compact-list-item is-unlocked" data-state="unlocked" <?= achievement_modal_attrs($achievement) ?>>
                        <?= achievement_visual_html($achievement, 'achievement-visual') ?>
                        <div class="dashboard-achievement-content">
                            <div class="dashboard-achievement-title-row">
                                <strong><?= e((string) ($achievement['name'] ?? '')) ?></strong>
                            </div>
                            <p><?= e((string) ($achievement['description'] ?? '')) ?></p>
                            <small class="dashboard-achievement-open"><?= e(t('dashboard.achievement_open')) ?> <span aria-hidden="true">&rsaquo;</span></small>
                            <?php if ($progressPct !== null): ?>
                                <div class="achievement-progress">
                                    <div class="goal-progress"><span style="width: <?= e((string) $progressPct) ?>%"></span></div>
                                    <small><?= e((string) ($achievement['progress_text'] ?? '')) ?></small>
                                </div>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
                <?php if ($dashboardAchievementPreview === []): ?>
                    <p class="muted panel-inline-empty"><?= e(t('achievements.empty')) ?></p>
                <?php endif; ?>
            </div>
        </article>
        <?php endif; ?>

        <?php if ($showWidget('achievement_progress')): ?>
        <article class="panel dashboard-panel dashboard-achievement-progress-panel dashboard-span-full compact-panel glass-panel compact-progress-panel" data-dashboard-widget="achievement_progress" style="order: <?= $contentWidgetOrder('achievement_progress') ?>">
            <div class="panel-head dashboard-panel-head-compact">
                <div><p class="eyebrow"><?= e(t('dashboard.widget_achievement_progress')) ?></p><p class="muted small"><?= e(t('dashboard.nearest_achievements')) ?></p></div>
                <a class="btn btn-ghost small dashboard-panel-action" href="<?= e($dashboardAchievementsUrl) ?>"><?= e(t('common.view_all')) ?></a>
            </div>
            <div class="dashboard-achievement-progress-list">
                <?php foreach ($dashboardAchievementProgressPreview as $achievement): ?>
                    <?php $progressPct = max(0.0, min(100.0, (float) ($achievement['progress_pct'] ?? 0))); ?>
                    <button class="dashboard-achievement-progress-row compact-list-item<?= $progressPct >= 75 ? ' is-nearly-complete' : ' is-locked' ?>" type="button" data-state="<?= $progressPct >= 75 ? 'nearly-complete' : 'locked' ?>" <?= achievement_modal_attrs($achievement) ?>>
                        <?= achievement_visual_html($achievement, 'achievement-visual') ?>
                        <span class="dashboard-achievement-progress-copy"><strong><?= e((string) ($achievement['name'] ?? '')) ?></strong><span class="goal-progress"><span style="width: <?= e((string) $progressPct) ?>%"></span></span><small><?= e((string) ($achievement['progress_text'] ?? round($progressPct) . '%')) ?></small></span>
                    </button>
                <?php endforeach; ?>
                <?php if ($dashboardAchievementProgressPreview === []): ?><p class="muted panel-inline-empty"><?= e(t('achievements.empty')) ?></p><?php endif; ?>
            </div>
        </article>
        <?php endif; ?>

        <?php if ($showWidget('quests')): ?>
            <article class="panel dashboard-panel quests-widget dashboard-span-full compact-panel glass-panel" data-dashboard-widget="quests" data-collapsible-list data-mobile-count="3" data-desktop-count="3" style="order: <?= $contentWidgetOrder('quests') ?>">
                <div class="panel-head">
                    <div>
                        <p class="eyebrow"><?= e(t('quests.title')) ?></p>
                        <h2><?= e(t('quests.rank')) ?>: <?= e((string) ($dashboardQuestRank['label'] ?? '')) ?></h2>
                    </div>
                    <span class="quests-streak" title="<?= e(t('quests.streak')) ?>">
                        <span class="quests-streak-flame" aria-hidden="true">&#128293;</span>
                        <strong><?= (int) ($dashboardQuestStreak ?? 0) ?></strong>
                    </span>
                </div>

                <?php
                $questBoard = (array) ($dashboardQuests ?? []);
                $questGroups = ['daily' => [], 'weekly' => []];
                foreach ($questBoard as $q) {
                    $questGroups[(string) $q['period']][] = $q;
                }
                ?>
                <?php foreach ($questGroups as $groupKey => $groupQuests): ?>
                    <?php if ($groupQuests === []) { continue; } ?>
                    <p class="quests-group-label"><?= e(t('quests.' . $groupKey)) ?></p>
                    <ul class="quests-list">
                        <?php foreach ($groupQuests as $q): ?>
                            <li class="quest-item compact-list-item<?= !empty($q['completed']) ? ' is-done' : ' is-available' ?>" data-state="<?= !empty($q['completed']) ? 'completed' : 'available' ?>" data-collapsible-item>
                                <button class="quest-summary" type="button" data-quest-detail-toggle aria-expanded="false">
                                    <span class="quest-icon"><?= activity_icon_svg((string) $q['icon']) ?></span>
                                    <span class="quest-body">
                                        <span class="quest-top">
                                            <strong><?= e((string) $q['label']) ?></strong>
                                            <span class="quest-xp">+<?= (int) $q['xp'] ?> XP</span>
                                        </span>
                                        <span class="quest-bar"><span style="width: <?= (int) $q['pct'] ?>%"></span></span>
                                        <small class="quest-meta">
                                            <?= e(number_format((float) $q['progress'], 0, '.', ' ')) ?> / <?= e(number_format((float) $q['target'], 0, '.', ' ')) ?>
                                        </small>
                                    </span>
                                    <span class="quest-item-actions">
                                        <?php if (!empty($q['completed'])): ?><span class="quest-done" aria-label="<?= e(t('workouts.done')) ?>">&#10003;</span><?php endif; ?>
                                        <span class="quest-chevron" aria-hidden="true">&rsaquo;</span>
                                    </span>
                                </button>
                                <div class="quest-detail" data-quest-detail hidden>
                                    <span><small><?= e(t('common.status')) ?></small><strong><?= e(!empty($q['completed']) ? t('quests.status_completed') : t('quests.status_available')) ?></strong></span>
                                    <span><small><?= e(t('quests.period')) ?></small><strong><?= e(t('quests.' . (string) $q['period'])) ?></strong></span>
                                    <span><small><?= e(t('quests.reward')) ?></small><strong>+<?= (int) $q['xp'] ?> XP</strong></span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endforeach; ?>

                <button class="inline-list-toggle" type="button" data-collapsible-toggle data-label-more="<?= e(t('profile.show_more')) ?>" data-label-less="<?= e(t('profile.show_less')) ?>"><?= e(t('profile.show_more')) ?></button>

                <?php $badgeBoard = (array) ($dashboardBadges ?? []); ?>
                <?php if ($badgeBoard !== []): ?>
                    <p class="quests-group-label"><?= e(t('badges.title')) ?></p>
                    <div class="badge-strip">
                        <?php foreach ($badgeBoard as $bg): ?>
                            <span class="badge-chip tier-<?= e((string) $bg['tier']) ?><?= !empty($bg['earned']) ? ' is-earned' : ' is-locked' ?>"
                                  title="<?= e((string) $bg['label']) ?> - <?= (int) $bg['value'] ?>/<?= (int) $bg['target'] ?>">
                                <span class="badge-chip-icon"><?= activity_icon_svg((string) $bg['icon']) ?></span>
                                <span class="badge-chip-body">
                                    <strong><?= e((string) $bg['label']) ?></strong>
                                    <small><?= !empty($bg['earned']) ? '+' . (int) $bg['xp'] . ' XP' : (int) $bg['value'] . '/' . (int) $bg['target'] ?></small>
                                </span>
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if (($dashboardQuestRank['next_level'] ?? null) !== null): ?>
                    <p class="muted small quests-next-rank"><?= e(t('quests.next_rank', ['n' => (int) $dashboardQuestRank['next_level']])) ?></p>
                <?php endif; ?>
            </article>
        <?php endif; ?>

        <?php if ($showWidget('season')): ?>
            <?php
            $season = (array) ($dashboardSeason ?? []);
            $seasonBoard = (array) ($dashboardSeasonBoard ?? []);
            $seasonDaysLeft = (int) ($dashboardSeasonDaysLeft ?? 0);
            $seasonTop = 0;
            $seasonPosition = null;
            foreach ($seasonBoard as $srow) {
                $seasonTop = max($seasonTop, (int) $srow['xp']);
                if ((int) ($srow['user_id'] ?? 0) === (int) $currentUser['id']) {
                    $seasonPosition = (int) ($srow['rank'] ?? 0);
                }
            }
            $seasonUserXp = (int) ($dashboardSeasonXp ?? 0);
            $seasonGap = max(0, $seasonTop - $seasonUserXp);
            $seasonProgress = $seasonTop > 0 ? min(100, (int) round(($seasonUserXp / $seasonTop) * 100)) : 0;
            ?>
            <article class="panel dashboard-panel season-widget dashboard-span-full compact-panel glass-panel compact-progress-panel" data-dashboard-widget="season" data-collapsible-list data-mobile-count="3" data-desktop-count="6" style="order: <?= $contentWidgetOrder('season') ?>">
                <div class="panel-head">
                    <div>
                        <p class="eyebrow"><?= e(t('season.title')) ?></p>
                        <h2><?= e((string) ($season['name'] ?? '')) ?></h2>
                    </div>
                    <span class="season-countdown">
                        <?= $seasonDaysLeft > 0 ? e(t('season.days_left', ['n' => $seasonDaysLeft])) : e(t('season.ends_today')) ?>
                    </span>
                </div>

                <div class="season-widget-summary compact-metrics-row">
                    <span><small><?= e(t('season.your_xp')) ?></small><strong><?= e(number_format($seasonUserXp, 0, '.', ' ')) ?> XP</strong></span>
                    <span><small><?= e(t('common.position')) ?></small><strong><?= $seasonPosition !== null ? '#' . $seasonPosition : '&mdash;' ?></strong></span>
                    <span><small><?= e(t('season.next_milestone')) ?></small><strong><?= e($seasonPosition === 1 ? t('season.leader') : t('season.xp_to_leader', ['xp' => number_format($seasonGap, 0, '.', ' ')])) ?></strong></span>
                </div>
                <div class="season-user-progress">
                    <span class="goal-progress" role="progressbar" aria-label="<?= e(t('season.progress_to_leader')) ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $seasonProgress ?>"><span style="width: <?= $seasonProgress ?>%"></span></span>
                </div>

                <?php if ($seasonBoard === []): ?>
                    <p class="muted panel-inline-empty">&mdash;</p>
                <?php else: ?>
                    <ol class="season-board">
                        <?php foreach ($seasonBoard as $srow): ?>
                            <?php $pct = $seasonTop > 0 ? (int) round(((int) $srow['xp'] / $seasonTop) * 100) : 0; ?>
                            <li class="season-row compact-list-item<?= (int) $srow['user_id'] === (int) $currentUser['id'] ? ' is-me' : '' ?>" data-collapsible-item>
                                <span class="season-rank">#<?= (int) $srow['rank'] ?></span>
                                <span class="season-name"><?= e((string) $srow['name']) ?></span>
                                <span class="season-bar"><span style="width: <?= $pct ?>%"></span></span>
                                <span class="season-xp"><?= e(number_format((float) $srow['xp'], 0, '.', ' ')) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                    <button class="inline-list-toggle" type="button" data-collapsible-toggle data-label-more="<?= e(t('profile.show_more')) ?>" data-label-less="<?= e(t('profile.show_less')) ?>"><?= e(t('profile.show_more')) ?></button>
                <?php endif; ?>

                <div class="season-widget-footer"><p class="muted small season-hint"><?= e(t('season.hint')) ?></p><a class="btn btn-ghost small" href="/?page=season"><?= e(t('common.view_all')) ?></a></div>
            </article>
        <?php endif; ?>

        <?php if ($showWidget('duels')): ?>
            <div class="dashboard-versus-widget" data-dashboard-widget="duels" style="order: <?= $contentWidgetOrder('duels') ?>">
                <?php
                $dDuels = (array) ($dashboardDuelsSummary ?? []);
                echo render_status_summary_card(
                    t('nav.duels'),
                    t('nav.duels'),
                    [
                        ['label' => t('common.active'), 'value' => (int) ($dDuels['active'] ?? 0), 'tone' => 'active'],
                        ['label' => t('common.pending'), 'value' => (int) ($dDuels['pending'] ?? 0), 'tone' => 'pending'],
                        ['label' => t('common.won'), 'value' => (int) ($dDuels['won'] ?? 0), 'tone' => 'won'],
                    ],
                    '/?page=duels',
                    t('common.view_all'),
                    'dashboard-panel compact-panel glass-panel'
                );
                ?>
            </div>
        <?php endif; ?>

        <?php if ($showWidget('competitions')): ?>
            <div class="dashboard-versus-widget" data-dashboard-widget="competitions" style="order: <?= $contentWidgetOrder('competitions') ?>">
                <?php
                $dComps = (array) ($dashboardCompetitionsSummary ?? []);
                echo render_status_summary_card(
                    t('nav.competitions'),
                    t('nav.competitions'),
                    [
                        ['label' => t('common.active'), 'value' => (int) ($dComps['active'] ?? 0), 'tone' => 'active'],
                        ['label' => t('common.pending'), 'value' => (int) ($dComps['pending'] ?? 0), 'tone' => 'pending'],
                        ['label' => t('common.won'), 'value' => (int) ($dComps['won'] ?? 0), 'tone' => 'won'],
                    ],
                    '/?page=competitions',
                    t('common.view_all'),
                    'dashboard-panel compact-panel glass-panel'
                );
                ?>
            </div>
        <?php endif; ?>

        <?php if ($showWidget('weekly')): ?>
        <article class="panel dashboard-panel dashboard-span-full dashboard-weekly-history compact-panel glass-panel" data-dashboard-widget="weekly" style="order: <?= $contentWidgetOrder('weekly') ?>">
            <div class="panel-head">
                <h2><?= e(t('dashboard.weekly_history')) ?></h2>
                <a class="btn btn-ghost small dashboard-panel-action" href="/?page=week_editor&user_id=<?= (int) $selectedUser['id'] ?>&week=<?= e(date_to_iso_week((string) $selectedWeekStart)) ?>"><?= e(t('table.open_editor')) ?></a>
            </div>
            <div class="table-wrap dashboard-desktop-table-wrap" data-collapsible-list data-mobile-count="6" data-desktop-count="6">
                <table class="table compact dashboard-weekly-table">
                    <thead>
                    <tr>
                        <th><?= e(t('common.week')) ?></th>
                        <th><?= e(t('common.status')) ?></th>
                        <th><?= e(t('metric.step_failures')) ?></th>
                        <th><?= e(t('metric.workout_failures')) ?></th>
                        <?php if ($penaltiesEnabled): ?>
                        <th><?= e(t('metric.warnings')) ?></th>
                        <?php endif; ?>
                        <?php if ($penaltiesEnabled): ?>
                        <th><?= e(t('strikes.economic_impact')) ?></th>
                        <?php endif; ?>
                        <?php if ($penaltiesEnabled): ?>
                        <th><?= e(t('dashboard.strike_reduction')) ?></th>
                        <th><?= e(t('dashboard.strikes_after_week')) ?></th>
                        <?php endif; ?>
                        <th><?= e(t('common.actions')) ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($selectedMetric['weekly'] as $week): ?>
                        <?php
                        $weekStartDate = (string) ($week['week_start'] ?? $selectedWeekStart ?? '');
                        $weekStrikesHref = '/?' . http_build_query([
                            'page' => 'strikes_detail',
                            'user_id' => (int) ($selectedUser['id'] ?? 0),
                            'view' => $weekStartDate,
                        ]);
                        $weekEditorHref = '/?' . http_build_query([
                            'page' => 'week_editor',
                            'user_id' => (int) ($selectedUser['id'] ?? 0),
                            'week' => date_to_iso_week($weekStartDate !== '' ? $weekStartDate : (string) $selectedWeekStart),
                        ]);
                        ?>
                        <tr data-collapsible-item>
                            <td data-label="<?= e(t('common.week')) ?>"><?= e(format_date_eu((string) $week['week_start'])) ?> -> <?= e(format_date_eu((string) $week['week_end'])) ?></td>
                            <td data-label="<?= e(t('common.status')) ?>"><?= e(label_for_status((string) $week['status'])) ?></td>
                            <td data-label="<?= e(t('metric.step_failures')) ?>"><?= e((string) $week['step_failures']) ?></td>
                            <td data-label="<?= e(t('metric.workout_failures')) ?>"><?= e((string) $week['workout_failures']) ?></td>
                            <?php if ($penaltiesEnabled): ?>
                            <td data-label="<?= e(t('metric.warnings')) ?>"><?= e((string) ($week['skip_warnings'] ?? 0)) ?></td>
                            <?php endif; ?>
                            <?php if ($penaltiesEnabled): ?>
                            <td data-label="<?= e(t('strikes.economic_impact')) ?>"><span class="penalty-chip penalty-chip-<?= e($penaltySeverityClass((int) ($week['penalty'] ?? 0))) ?>">&euro;<?= e(number_format((float) ($week['penalty'] ?? 0), 2, '.', '')) ?></span></td>
                            <?php endif; ?>
                            <?php if ($penaltiesEnabled): ?>
                            <td data-label="<?= e(t('dashboard.strike_reduction')) ?>"><?= (int) $week['strike_reduction'] > 0 ? '-1' : '-' ?></td>
                            <td data-label="<?= e(t('dashboard.strikes_after_week')) ?>"><?= e((string) $week['strikes_after_week']) ?></td>
                            <?php endif; ?>
                            <td data-label="<?= e(t('common.actions')) ?>">
                                <div class="dashboard-week-actions">
                                    <a class="btn btn-ghost small" href="<?= e($weekEditorHref) ?>"><?= e(t('dashboard.edit_week')) ?></a>
                                    <?php if ($penaltiesEnabled): ?>
                                    <a class="btn btn-ghost small" href="<?= e($weekStrikesHref) ?>"><?= e(t('dashboard.penalty_history')) ?></a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <button class="inline-list-toggle dashboard-weekly-more" type="button" data-collapsible-toggle data-label-more="<?= e(t('profile.show_more')) ?>" data-label-less="<?= e(t('profile.show_less')) ?>"><?= e(t('profile.show_more')) ?></button>
            </div>
            <div class="dashboard-mobile-card-list dashboard-weekly-mobile-list dashboard-weekly-compact-list" data-collapsible-list data-mobile-count="4" data-desktop-count="4" aria-label="<?= e(t('dashboard.weekly_history')) ?>">
                <?php foreach ($selectedMetric['weekly'] as $week): ?>
                    <?php
                    $weekStartDate = (string) ($week['week_start'] ?? $selectedWeekStart ?? '');
                    $weekEditorHref = '/?' . http_build_query([
                        'page' => 'week_editor',
                        'user_id' => (int) ($selectedUser['id'] ?? 0),
                        'week' => date_to_iso_week($weekStartDate !== '' ? $weekStartDate : (string) $selectedWeekStart),
                    ]);
                    $weeklyPenalty = (float) ($week['penalty'] ?? 0);
                    ?>
                    <a class="dashboard-week-row" data-collapsible-item href="<?= e($weekEditorHref) ?>">
                        <span class="dashboard-week-row-main">
                            <strong><?= e(format_date_eu((string) $week['week_start'])) ?></strong>
                            <small><?= e(label_for_status((string) $week['status'])) ?></small>
                        </span>
                        <span class="dashboard-week-row-meta">
                            <?php if ($penaltiesEnabled): ?>
                            <span><?= e(t('metric.warnings')) ?> <?= e((string) ($week['skip_warnings'] ?? 0)) ?></span>
                            <?php endif; ?>
                            <?php if ($penaltiesEnabled): ?>
                            <span class="penalty-chip penalty-chip-<?= e($penaltySeverityClass((int) $weeklyPenalty)) ?>">&euro;<?= e(number_format($weeklyPenalty, 2, '.', '')) ?></span>
                            <?php else: ?>
                            <span><?= e(t('metric.step_failures')) ?> <?= e((string) ($week['step_failures'] ?? 0)) ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="settings-chevron" aria-hidden="true">&gt;</span>
                    </a>
                <?php endforeach; ?>
                <button class="inline-list-toggle dashboard-weekly-more" type="button" data-collapsible-toggle data-label-more="<?= e(t('profile.show_more')) ?>" data-label-less="<?= e(t('profile.show_less')) ?>"><?= e(t('profile.show_more')) ?></button>
            </div>
        </article>
        <?php endif; ?>

        <?php if ($showWidget('approvals')): ?>
        <article class="panel dashboard-panel dashboard-approvals" data-testid="pending-approvals" data-dashboard-widget="approvals" style="order: <?= $contentWidgetOrder('approvals') ?>">
            <div class="panel-head dashboard-panel-head-compact">
                <div>
                    <p class="eyebrow"><?= e(t('dashboard.approvals_pending_eyebrow')) ?></p>
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
                            <p class="small muted pending-requested-by"><?= e(t('dashboard.requested_by')) ?>: <?= e((string) $approval['requested_by_name']) ?></p>

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


        <?php if ($showWidget('ranking')): ?>
        <article class="panel dashboard-panel dashboard-ranking-panel compact-panel glass-panel" data-dashboard-widget="ranking" data-collapsible-list data-mobile-count="6" data-desktop-count="10" style="order: <?= $contentWidgetOrder('ranking') ?>">
            <h2><?= e(t('dashboard.challenge_ranking')) ?></h2>
            <div class="leaderboard-list">
                <?php $leaderboardTopScore = (float) (($metricsOrdered[0]['score'] ?? 0)); $leaderboardPreviousScore = null; $leaderboardPreviousRank = 0; ?>
                <?php foreach ($metricsOrdered as $leaderboardIndex => $metric): ?>
                    <?php
                    $leaderboardUser = is_array($metric['user'] ?? null) ? (array) $metric['user'] : [];
                    $leaderboardName = (string) ($leaderboardUser['display_name'] ?? t('common.user'));
                    $leaderboardAvatarUrl = avatar_url($leaderboardUser);
                    $leaderboardScore = (float) ($metric['score'] ?? 0);
                    $leaderboardRank = $leaderboardPreviousScore !== null && abs($leaderboardScore - $leaderboardPreviousScore) < 0.0001
                        ? $leaderboardPreviousRank
                        : $leaderboardIndex + 1;
                    $leaderboardDifference = max(0.0, $leaderboardTopScore - $leaderboardScore);
                    $leaderboardPreviousScore = $leaderboardScore;
                    $leaderboardPreviousRank = $leaderboardRank;
                    ?>
                    <article class="leaderboard-row compact-list-item<?= (int) ($leaderboardUser['id'] ?? 0) === (int) ($currentUser['id'] ?? 0) ? ' is-me' : '' ?>" data-collapsible-item>
                        <strong class="leaderboard-position">#<?= $leaderboardRank ?></strong>
                        <a class="leaderboard-name user-profile-link" href="/?page=profile&amp;user_id=<?= (int) ($leaderboardUser['id'] ?? 0) ?>">
                            <?php if ($leaderboardAvatarUrl !== ''): ?>
                                <img class="member-avatar leaderboard-avatar" src="<?= e($leaderboardAvatarUrl) ?>" alt="<?= e($leaderboardName) ?>">
                            <?php else: ?>
                                <span class="member-avatar member-avatar-initials leaderboard-avatar"><?= e(initials_for($leaderboardName)) ?></span>
                            <?php endif; ?>
                            <span class="leaderboard-name-text">
                                <strong><?= e($leaderboardName) ?></strong>
                                <?php if ($penaltiesEnabled): ?>
                                    <span><?= e(t('metric.warnings')) ?>: <?= e((string) ($metric['skip_warning_events'] ?? 0)) ?></span>
                                <?php endif; ?>
                            </span>
                        </a>
                        <div class="leaderboard-stats">
                            <span class="badge"><?= e(t('metric.score')) ?> <?= e((string) $metric['score']) ?></span>
                            <span class="badge"><?= e(t('metric.steps')) ?> <?= e((string) ($metric['total_steps'] ?? 0)) ?></span>
                        </div>
                        <span class="leaderboard-delta" aria-label="<?= e(t('dashboard.ranking_gap')) ?>"><?= $leaderboardDifference > 0 ? '&minus;' . e(number_format($leaderboardDifference, 1, current_locale() === 'en' ? '.' : ',', '')) : '&mdash;' ?></span>
                    </article>
                <?php endforeach; ?>
            </div>
            <button class="inline-list-toggle" type="button" data-collapsible-toggle data-label-more="<?= e(t('profile.show_more')) ?>" data-label-less="<?= e(t('profile.show_less')) ?>"><?= e(t('profile.show_more')) ?></button>
        </article>
        <?php endif; ?>



    </div>
    </div>
    <?php endif; ?>
</section>
