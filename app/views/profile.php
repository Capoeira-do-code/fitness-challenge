<?php

declare(strict_types=1);

$profileUser = $profileUser ?? $currentUser;
$isOwnProfile = (bool) ($isOwnProfile ?? ((int) ($profileUser['id'] ?? 0) === (int) ($currentUser['id'] ?? 0)));
$canEditProfile = (bool) ($canEditProfile ?? $isOwnProfile);
$canExportProfilePdf = is_admin($currentUser);
$profileBaseUrl = (string) ($profileBaseUrl ?? '/?page=profile');
$profileBackUrl = (string) ($profileBackUrl ?? '');
$profileBackParams = is_array($profileBackParams ?? null) ? (array) $profileBackParams : [];
$profileFriends = is_array($profileFriends ?? null) ? array_values((array) $profileFriends) : [];
$profileFriendIncoming = is_array($profileFriendIncoming ?? null) ? array_values((array) $profileFriendIncoming) : [];
$profileFriendOutgoing = is_array($profileFriendOutgoing ?? null) ? array_values((array) $profileFriendOutgoing) : [];
$profileFriendAddable = is_array($profileFriendAddable ?? null) ? array_values((array) $profileFriendAddable) : [];
$profileFriendStatus = (string) ($profileFriendStatus ?? ($isOwnProfile ? 'self' : 'none'));
$profileFriendCount = count($profileFriends);
$profileFriendPreview = array_slice($profileFriends, 0, 5);
$profileFriendIncomingPreview = array_slice($profileFriendIncoming, 0, 2);
$profileFriendOutgoingCount = count($profileFriendOutgoing);
$profileTrainingRank = is_array($profileTrainingRank ?? null) ? (array) $profileTrainingRank : wk_rank_from_score(0.0);
$profileTrainingMonth = is_array($profileTrainingMonth ?? null) ? (array) $profileTrainingMonth : [];
$profileTrainingAll = is_array($profileTrainingAll ?? null) ? (array) $profileTrainingAll : [];
$profileTrainingRecentSessions = is_array($profileTrainingRecentSessions ?? null) ? array_values((array) $profileTrainingRecentSessions) : [];
$profileTrainingRecords = is_array($profileTrainingRecords ?? null) ? array_values((array) $profileTrainingRecords) : [];
$profileTrainingMuscles = is_array($profileTrainingMuscles ?? null) ? array_values((array) $profileTrainingMuscles) : [];
$profileTeamsList = is_array($profileTeams ?? null) ? array_values((array) $profileTeams) : [];
$profileGoalTeamsList = is_array($profileGoalTeams ?? null) ? array_values((array) $profileGoalTeams) : [];
$profileCustomWidgets = is_array($profileCustomWidgets ?? null) ? array_values((array) $profileCustomWidgets) : [];
$profileDataAccess = is_array($profileDataAccess ?? null) ? $profileDataAccess : array_fill_keys(PRIVACY_DATA_KEYS, true);
$canViewProfileWorkouts = $isOwnProfile || !empty($profileDataAccess['workouts']);
$canViewProfilePerformance = $isOwnProfile || (!empty($profileDataAccess['steps']) && !empty($profileDataAccess['distance']) && !empty($profileDataAccess['workouts']));

$activeSection = (string) ($_GET['section'] ?? '');
$allowedSections = $isOwnProfile
    ? ['goals', 'training', 'social', 'achievements', 'config', 'activity']
    : array_values(array_filter(['goals', 'training', 'social', 'achievements'], static fn(string $section): bool => $section !== 'training' || $canViewProfileWorkouts));
if (!in_array($activeSection, $allowedSections, true)) {
    $activeSection = '';
}

$goalCreateMode = (string) ($_GET['goal_new'] ?? '') === '1';
$goalDetailId = isset($_GET['goal_id']) ? (int) $_GET['goal_id'] : 0;
$profileTodayDate = (new DateTimeImmutable('today'))->format('Y-m-d');
$configEditMode = $canEditProfile && (string) ($_GET['edit'] ?? '') === '1';
$profileMetric = is_array($profileMetric ?? null) ? (array) $profileMetric : [];
$penaltiesEnabled = penalties_enabled($GLOBALS['pdo']);
$isPenaltyRelatedProfileItem = static function (array $item): bool {
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
    $personalGoals = array_values(array_filter((array) ($personalGoals ?? []), static fn(array $goal): bool => !$isPenaltyRelatedProfileItem($goal)));
    $profileGoalCards = array_values(array_filter((array) ($profileGoalCards ?? []), static fn(array $goal): bool => !$isPenaltyRelatedProfileItem($goal)));
    $userAchievements = array_values(array_filter((array) ($userAchievements ?? []), static fn(array $achievement): bool => !$isPenaltyRelatedProfileItem($achievement)));
}
$primaryGoalsSpec = trim((string) ($profileUser['primary_goals_spec'] ?? ''));
$profileTaglineLimit = profile_tagline_max_length();
$profileTagline = normalize_profile_tagline((string) ($profileUser['profile_tagline'] ?? ''));
$profileHeroMessage = $profileTagline !== '' ? $profileTagline : (string) t('profile.subtitle');
$profileCoverPath = trim((string) ($profileUser['profile_cover_path'] ?? ''));
$profileCoverUrl = '';
if ($profileCoverPath !== '') {
    $profileCoverFile = resolve_media_storage_path((array) $GLOBALS['config'], $profileCoverPath);
    if ($profileCoverFile !== null && is_file($profileCoverFile)) {
        $profileCoverVersion = !empty($profileUser['updated_at']) ? strtotime((string) $profileUser['updated_at']) : false;
        $profileCoverUrl = media_url($profileCoverPath, $profileCoverVersion !== false ? (string) $profileCoverVersion : null);
    }
}

$profileQueryBase = ['page' => 'profile'];
if (!$isOwnProfile) {
    $profileQueryBase['user_id'] = (int) ($profileUser['id'] ?? 0);
}
$profileQueryBase = array_merge($profileQueryBase, $profileBackParams);
$profileSelectedChallengeKey = (string) ($profileSelectedChallengeKey ?? 'current');
if ($profileSelectedChallengeKey !== '' && $profileSelectedChallengeKey !== 'current') {
    $profileQueryBase['challenge'] = $profileSelectedChallengeKey;
}
$profileUrl = static function (string $section = '', array $extra = []) use ($profileQueryBase): string {
    $query = array_merge($profileQueryBase, $extra);
    if ($section !== '') {
        $query['section'] = $section;
    }

    return '/?' . http_build_query($query);
};
$profileCollapseStateKey = static fn(string $block): string => 'profile.' . (int) ($profileUser['id'] ?? 0) . '.' . $block;
$profileCollapseControl = static function (): string {
    $collapseLabel = t('profile.show_less');
    $expandLabel = t('profile.show_more');

    return '<button class="profile-panel-collapse-toggle" type="button" data-profile-panel-toggle'
        . ' data-label-collapse="' . e($collapseLabel) . '" data-label-expand="' . e($expandLabel) . '"'
        . ' aria-expanded="true" aria-label="' . e($collapseLabel) . '" title="' . e($collapseLabel) . '">'
        . '<span aria-hidden="true">&rsaquo;</span></button>';
};
$profileAchievementById = [];
foreach ((array) ($userAchievements ?? []) as $profileAchievement) {
    $profileAchievementId = (int) ($profileAchievement['achievement_id'] ?? $profileAchievement['id'] ?? 0);
    if ($profileAchievementId > 0) {
        $profileAchievementById[$profileAchievementId] = $profileAchievement;
    }
}

// #15 — customizable Profile home layout. Order comes from the profile owner's
// saved arrangement; default (no saved layout) keeps the original DOM order.
$profileLayoutBlocks = $isOwnProfile
    ? ['goals', 'friends', 'teams', 'training_rank', 'training_progress', 'achievements', 'duels', 'competitions', 'setup', 'activity']
    : array_values(array_filter(['goals', 'friends', 'teams', 'training_rank', 'training_progress', 'achievements'], static fn(string $block): bool => $canViewProfileWorkouts || !in_array($block, ['training_rank', 'training_progress'], true)));
$profileLayoutLabels = [
    'goals' => t('goals.personal'),
    'friends' => t('nav.friends'),
    'teams' => t('social_hub.teams'),
    'training_rank' => t('dashboard.training_rank_title'),
    'training_progress' => t('dashboard.training_progress_title'),
    'achievements' => t('profile.achievements'),
    'duels' => t('nav.duels'),
    'competitions' => t('nav.competitions'),
    'setup' => t('profile.current_config'),
    'activity' => t('profile.recent_activity'),
];
foreach ($profileCustomWidgets as $profileCustomWidget) {
    if (!$isOwnProfile && empty($profileCustomWidget['is_visible'])) {
        continue;
    }
    $profileCustomWidgetKey = profile_custom_widget_key((int) ($profileCustomWidget['id'] ?? 0));
    $profileLayoutBlocks[] = $profileCustomWidgetKey;
    $profileLayoutLabels[$profileCustomWidgetKey] = t('profile.widget_label', [
        'title' => (string) ($profileCustomWidget['title'] ?? t('profile.widget_default_title')),
    ]);
}
$profileSavedLayout = json_decode((string) ($profileUser['profile_layout_json'] ?? ''), true);
$profileBlockOrder = [];
$profileOrderIndex = 0;
if (is_array($profileSavedLayout)) {
    foreach ($profileSavedLayout as $blockKey) {
        if (is_string($blockKey) && in_array($blockKey, $profileLayoutBlocks, true) && !isset($profileBlockOrder[$blockKey])) {
            $profileBlockOrder[$blockKey] = ++$profileOrderIndex;
        }
    }
}
foreach ($profileLayoutBlocks as $blockKey) {
    if (!isset($profileBlockOrder[$blockKey])) {
        $profileBlockOrder[$blockKey] = ++$profileOrderIndex;
    }
}
// A saved layout lists exactly the blocks the user kept. Anything saved is visible;
// blocks missing from a *saved* layout were deliberately hidden. With no saved
// layout at all, everything shows.
$profileHiddenBlocks = [];
if (is_array($profileSavedLayout) && $profileSavedLayout !== []) {
    $profileHiddenBlocks = array_values(array_diff(
        $profileLayoutBlocks,
        array_map('strval', $profileSavedLayout)
    ));
}
$profileBlockVisible = static function (string $key) use ($profileHiddenBlocks): bool {
    return !in_array($key, $profileHiddenBlocks, true);
};
$profileBlockStyle = static function (string $key) use ($profileBlockOrder, $profileHiddenBlocks): string {
    if (in_array($key, $profileHiddenBlocks, true)) {
        return 'display:none;';
    }

    return isset($profileBlockOrder[$key]) ? 'order:' . (int) $profileBlockOrder[$key] . ';' : '';
};
// Blocks in their current (saved or default) order, for the editor list.
$profileOrderedBlocks = $profileLayoutBlocks;
usort($profileOrderedBlocks, static function (string $a, string $b) use ($profileBlockOrder): int {
    return ($profileBlockOrder[$a] ?? 99) <=> ($profileBlockOrder[$b] ?? 99);
});
$profileLayoutEditMode = !empty($isOwnProfile) && (string) ($_GET['layout_edit'] ?? '') === '1' && ($activeSection ?? '') === '';

$profileFriendAvatar = static function (array $user): void {
    $url = avatar_url($user);
    $name = (string) ($user['display_name'] ?? $user['username'] ?? '');
    if ($url !== '') {
        echo '<img class="profile-friend-avatar" src="' . e($url) . '" alt="' . e($name) . '">';
        return;
    }

    echo '<span class="profile-friend-avatar initials">' . e(initials_for($name !== '' ? $name : '?')) . '</span>';
};
$profileIdentityMediaUrl = static function (array $item, string $field): string {
    $path = trim((string) ($item[$field] ?? ''));
    $width = in_array($field, ['icon_path', 'avatar_path'], true) ? 160 : 720;
    return $path !== '' ? media_thumbnail_url($path, $width) : '';
};
$profileTeamIcon = static function (array $team) use ($profileIdentityMediaUrl): void {
    $name = (string) ($team['name'] ?? t('nav.team'));
    $iconUrl = $profileIdentityMediaUrl($team, 'icon_path');
    if ($iconUrl !== '') {
        echo '<img class="profile-friend-avatar profile-team-icon" src="' . e($iconUrl) . '" alt="' . e($name) . '">';
        return;
    }
    echo '<span class="profile-friend-avatar profile-team-icon initials">' . e(initials_for($name)) . '</span>';
};
$profileFriendActionForm = static function (string $action, int $userId, string $label, string $btnClass, string $contextClass = '') use ($profileUrl): void {
    ?>
    <form method="post" action="<?= e($profileUrl()) ?>" class="inline-form profile-friend-action-form <?= e($contextClass) ?>">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="<?= e($action) ?>">
        <input type="hidden" name="user_id" value="<?= $userId ?>">
        <button class="btn <?= e($btnClass) ?> small" type="submit"><?= e($label) ?></button>
    </form>
    <?php
};
$profileFriendSecondaryMenu = static function (
    string $action,
    int $userId,
    string $label,
    bool $danger = false,
    string $contextClass = '',
    array $extraItems = []
) use ($profileUrl): void {
    static $menuCounter = 0;
    $menuCounter++;
    $formId = 'profile-friend-secondary-' . $userId . '-' . $menuCounter;
    ?>
    <form id="<?= e($formId) ?>" method="post" action="<?= e($profileUrl()) ?>" hidden>
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="user_id" value="<?= $userId ?>">
    </form>
    <?php
    $menuItems = array_values($extraItems);
    $menuItems[] = [
        'label' => $label,
        'danger' => $danger,
        'type' => 'submit',
        'attrs' => array_filter([
            'form' => $formId,
            'name' => 'action',
            'value' => $action,
            'data-confirm-action' => $danger ? t('friends.remove_confirm') : '',
        ], static fn(string $value): bool => $value !== ''),
    ];
    echo render_kebab_menu($menuItems, [
        'label' => t('common.actions'),
        'class' => trim('profile-friend-secondary-menu ' . $contextClass),
    ]);
};
$renderProfileFriendActions = static function (string $status, int $targetUserId, string $contextClass = '', array $extraMenuItems = []) use ($profileFriendActionForm, $profileFriendSecondaryMenu): void {
    if ($targetUserId <= 0) {
        return;
    }

    if ($status === 'none') {
        $profileFriendActionForm('friend_request', $targetUserId, t('friends.send_request'), 'btn-primary', $contextClass);
        if ($extraMenuItems !== []) {
            echo render_kebab_menu($extraMenuItems, [
                'label' => t('common.actions'),
                'class' => trim('profile-friend-secondary-menu ' . $contextClass),
            ]);
        }
        return;
    }
    if ($status === 'pending_out') {
        $profileFriendSecondaryMenu('friend_remove', $targetUserId, t('friends.cancel_request'), false, $contextClass, $extraMenuItems);
        return;
    }
    if ($status === 'pending_in') {
        $profileFriendActionForm('friend_accept', $targetUserId, t('friends.accept'), 'btn-primary', $contextClass);
        $profileFriendSecondaryMenu('friend_reject', $targetUserId, t('friends.reject'), false, $contextClass, $extraMenuItems);
        return;
    }
    if ($status === 'friends') {
        array_unshift($extraMenuItems, [
            'label' => t('friends.compare'),
            'href' => '/?page=friends&compare=' . $targetUserId,
        ]);
        $profileFriendSecondaryMenu('friend_remove', $targetUserId, t('friends.remove'), true, $contextClass, $extraMenuItems);
    }
};
$profileFriendStatusText = match ($profileFriendStatus) {
    'friends' => (string) t('profile.friend_status_friends'),
    'pending_out' => (string) t('profile.friend_status_pending_out'),
    'pending_in' => (string) t('profile.friend_status_pending_in'),
    'self' => (string) t('profile.friend_status_self'),
    default => (string) t('profile.friend_status_none'),
};
$showProfileFriendActions = !$isOwnProfile && (int) ($profileUser['id'] ?? 0) > 0;
$profileAchievementsUrl = '/?' . http_build_query([
    'page' => 'achievements',
    'scope' => 'user',
    'user_id' => (int) ($profileUser['id'] ?? 0),
]);

$activeGoals = array_values(array_filter((array) ($personalGoals ?? []), static fn(array $goal): bool => (string) ($goal['status'] ?? 'active') === 'active'));
$profileGoalCards = array_values((array) ($profileGoalCards ?? []));
$profileActiveGoalCards = array_values(array_filter($profileGoalCards, static fn(array $goal): bool => (string) ($goal['status'] ?? 'active') === 'active'));
$profileCompletedGoalCards = array_values(array_filter($profileGoalCards, static fn(array $goal): bool => (string) ($goal['status'] ?? '') === 'complete'));
$achievementCount = count($userAchievements ?? []);
$activityCount = count($recentActivity ?? []);
$goalPreview = array_slice(array_map(static fn(array $goal): string => (string) $goal['title'], $activeGoals), 0, 2);
$achievementPreview = array_slice(array_map(static fn(array $achievement): string => (string) $achievement['name'], (array) ($userAchievements ?? [])), 0, 3);
$activityPreview = array_slice(array_map(static fn(array $item): string => (string) $item['summary'], (array) ($recentActivity ?? [])), 0, 3);

$habitsList = (array) ($habits ?? []);
$habitLabels = [];
foreach ($habitsList as $habit) {
    $habitLabels[(string) $habit['code']] = (string) ($habit['label'] ?? $habit['code']);
}

$goalTypeOptions = [
    ['value' => 'steps', 'label' => (string) t('metric.steps')],
    ['value' => 'km', 'label' => (string) t('metric.distance_km')],
    ['value' => 'workouts', 'label' => (string) t('metric.workouts')],
    ['value' => 'weight', 'label' => (string) t('metric.weight')],
];
foreach ($habitsList as $habit) {
    $goalTypeOptions[] = [
        'value' => 'habit:' . (string) $habit['code'],
        'label' => (string) $habit['label'],
    ];
}

$goalTypeLabel = static function (string $rawType) use ($habitLabels): string {
    $type = normalize_goal_target_type($rawType);

    return match (true) {
        $type === 'steps' => (string) t('metric.steps'),
        $type === 'km' => (string) t('metric.distance_km'),
        $type === 'workouts' => (string) t('metric.workouts'),
        $type === 'weight' => (string) t('metric.weight'),
        str_starts_with($type, 'habit:') => $habitLabels[substr($type, 6)] ?? $type,
        default => $rawType !== '' ? $rawType : (string) t('common.other'),
    };
};

$goalCurrentValue = static function (array $goal) use ($profileMetric): float {
    if ($profileMetric === []) {
        return (float) ($goal['current_value'] ?? 0);
    }

    return goal_progress_value_from_metric($goal, $profileMetric);
};

$goalProgressPercent = static function (array $goal, float $currentValue) use ($profileMetric): float {
    $type = normalize_goal_target_type((string) ($goal['target_type'] ?? 'custom'));
    $targetValue = (float) ($goal['target_value'] ?? 0);
    if ($targetValue <= 0) {
        return 0.0;
    }

    if ($type === 'weight') {
        $startWeight = $profileMetric['first_weight'] ?? null;
        if (is_numeric($startWeight)) {
            $start = (float) $startWeight;
            if ($start > $targetValue) {
                $denominator = max(0.001, $start - $targetValue);
                return max(0.0, min(100.0, round((($start - $currentValue) / $denominator) * 100, 1)));
            }
            if ($start < $targetValue) {
                $denominator = max(0.001, $targetValue - $start);
                return max(0.0, min(100.0, round((($currentValue - $start) / $denominator) * 100, 1)));
            }
            return abs($currentValue - $targetValue) < 0.0001 ? 100.0 : 0.0;
        }

        return $currentValue <= $targetValue
            ? 100.0
            : max(0.0, min(100.0, round(($targetValue / max(0.001, $currentValue)) * 100, 1)));
    }

    if (in_array($type, ['strikes', 'penalties'], true)) {
        if ($currentValue <= $targetValue) {
            return 100.0;
        }
        return max(0.0, round((1 - (($currentValue - $targetValue) / max(0.001, $targetValue))) * 100, 1));
    }

    return max(0.0, min(100.0, round(($currentValue / $targetValue) * 100, 1)));
};

$formatGoalValue = static function (float $value, string $rawType): string {
    $type = normalize_goal_target_type($rawType);
    $decimals = ($type === 'steps' || $type === 'workouts' || str_starts_with($type, 'habit:')) ? 0 : 1;

    return number_format($value, $decimals, '.', '');
};

$goalStatusLabel = static function (string $status): string {
    return match ($status) {
        'complete' => (string) t('common.complete'),
        'archived' => (string) t('goals.archive'),
        default => (string) t('common.active'),
    };
};

$profileStepsChart = [];
foreach ((array) ($profileMetric['steps_series'] ?? []) as $row) {
    $profileStepsChart[] = [
        'label' => format_date_eu((string) ($row['date'] ?? '')),
        'value' => (int) ($row['steps'] ?? 0),
    ];
}
$profileWeightChart = [];
foreach ((array) ($profileMetric['weight_series'] ?? []) as $row) {
    $profileWeightChart[] = [
        'label' => format_date_eu((string) ($row['date'] ?? '')),
        'value' => (float) ($row['weight'] ?? 0),
    ];
}
$profileChallengeRange = is_array($profileChallengeRange ?? null) ? (array) $profileChallengeRange : [
    'start' => '',
    'end' => '',
];
$profileChallengeOptions = is_array($profileChallengeOptions ?? null) ? array_values((array) $profileChallengeOptions) : [];
$profileSelectedChallenge = is_array($profileSelectedChallenge ?? null) ? (array) $profileSelectedChallenge : [];
$profileSelectedChallengeName = (string) ($profileSelectedChallenge['name'] ?? ($profileChallengeRange['name'] ?? t('challenges.unnamed')));
$profileSelectedChallengeIsArchive = !empty($profileSelectedChallenge['is_archive'] ?? $profileChallengeRange['is_archive'] ?? false);
$profileSelectedChallengeRangeLabel = trim(format_date_eu((string) ($profileChallengeRange['start'] ?? '')) . ' - ' . format_date_eu((string) ($profileChallengeRange['end'] ?? '')));
$profileDailyDetails = is_array($profileDailyDetails ?? null) ? array_values((array) $profileDailyDetails) : [];
$profileDailyPhotoNutrition = is_array($profileDailyPhotoNutrition ?? null) ? array_values((array) $profileDailyPhotoNutrition) : [];
$profileWeeklySummary = is_array($profileWeeklySummary ?? null) ? array_values((array) $profileWeeklySummary) : [];
$profileMonthlySummary = is_array($profileMonthlySummary ?? null) ? array_values((array) $profileMonthlySummary) : [];
$profileTotalSummary = is_array($profileTotalSummary ?? null) ? (array) $profileTotalSummary : [];
$habitGoalCodes = is_array($habitGoalCodes ?? null) ? array_values((array) $habitGoalCodes) : [];
$profileExportPayload = [
    'username' => (string) ($profileUser['username'] ?? ''),
    'display_name' => (string) ($profileUser['display_name'] ?? ''),
    'generated_at' => now_iso(),
    'i18n' => [
        'pdf_title' => (string) t('profile.pdf_title'),
        'pdf_section_overview' => (string) t('profile.pdf_section_overview'),
        'pdf_section_daily' => (string) t('profile.pdf_section_daily'),
        'pdf_section_nutrition' => (string) t('profile.pdf_section_nutrition'),
        'pdf_section_weekly' => (string) t('profile.pdf_section_weekly'),
        'pdf_section_monthly' => (string) t('profile.pdf_section_monthly'),
        'pdf_section_total' => (string) t('profile.pdf_section_total'),
        'pdf_section_goals' => (string) t('profile.pdf_section_goals'),
        'pdf_section_achievements' => (string) t('profile.pdf_section_achievements'),
        'pdf_executive_summary' => (string) t('profile.pdf_executive_summary'),
        'pdf_weekly_progress_table' => (string) t('profile.pdf_weekly_progress_table'),
        'pdf_monthly_summary_table' => (string) t('profile.pdf_monthly_summary_table'),
        'pdf_total_summary_table' => (string) t('profile.pdf_total_summary_table'),
        'pdf_daily_input_table' => (string) t('profile.pdf_daily_input_table'),
        'pdf_nutrition_day_table' => (string) t('profile.pdf_nutrition_day_table'),
        'pdf_flags_notes' => (string) t('profile.pdf_flags_notes'),
        'pdf_chart_weekly_progress' => (string) t('profile.pdf_chart_weekly_progress'),
        'pdf_chart_monthly_progress' => (string) t('profile.pdf_chart_monthly_progress'),
        'pdf_chart_monthly_steps' => (string) t('profile.pdf_chart_monthly_steps'),
        'pdf_chart_monthly_workouts' => (string) t('profile.pdf_chart_monthly_workouts'),
        'pdf_chart_nutrition_calories' => (string) t('profile.pdf_chart_nutrition_calories'),
        'pdf_generating' => (string) t('profile.pdf_generating'),
        'pdf_generated' => (string) t('profile.pdf_generated'),
        'pdf_challenge_range' => (string) t('profile.pdf_challenge_range'),
        'pdf_key_metrics' => (string) t('profile.pdf_key_metrics'),
        'pdf_current_setup' => (string) t('profile.pdf_current_setup'),
        'pdf_totals' => (string) t('profile.pdf_totals'),
        'pdf_daily_table' => (string) t('profile.pdf_daily_table'),
        'pdf_nutrition_summary' => (string) t('profile.pdf_nutrition_summary'),
        'pdf_food_items' => (string) t('profile.pdf_food_items'),
        'pdf_goals_table' => (string) t('profile.pdf_goals_table'),
        'pdf_achievements_table' => (string) t('profile.pdf_achievements_table'),
        'pdf_no_data' => (string) t('profile.pdf_no_data'),
        'pdf_approvals' => (string) t('profile.pdf_approvals'),
        'pdf_habits' => (string) t('profile.pdf_habits'),
        'pdf_missing_reason' => (string) t('profile.pdf_missing_reason'),
        'pdf_export_failed' => (string) t('profile.pdf_export_failed'),
        'pdf_yes' => (string) t('profile.pdf_yes'),
        'pdf_no' => (string) t('profile.pdf_no'),
    ],
    'labels' => [
        'username' => (string) t('common.username'),
        'date' => (string) t('common.date'),
        'status' => (string) t('common.status'),
        'notes' => (string) t('common.notes'),
        'actions' => (string) t('common.actions'),
        'category' => (string) t('common.category'),
        'caption' => (string) t('common.caption'),
        'total' => (string) t('metric.total'),
        'steps' => (string) t('metric.steps'),
        'distance' => (string) t('metric.distance_km'),
        'workouts' => (string) t('metric.workouts'),
        'score' => (string) t('metric.score'),
        'weight' => (string) t('metric.weight'),
        'strikes' => (string) t('metric.strikes'),
        'penalty' => (string) t('metric.penalty'),
        'primary_goal' => (string) t('settings.primary_goal'),
        'primary_goal_value' => (string) t('settings.primary_goal_value'),
        'primary_goals_spec' => (string) t('settings.primary_goals_spec'),
        'workout_target' => (string) t('profile.workout_target'),
        'maintenance_calories' => (string) t('profile.maintenance_calories'),
        'calorie_burn_goal' => (string) t('settings.calorie_burn_goal'),
        'calorie_consumed_max' => (string) t('settings.calorie_consumed_max'),
        'ideal_weight' => (string) t('metric.ideal_weight'),
        'photos' => (string) t('entries.photo_plural'),
        'calories' => (string) t('entries.photo_calories'),
        'protein' => (string) t('entries.photo_protein'),
        'carbs' => (string) t('entries.photo_carbs'),
        'fat' => (string) t('entries.photo_fat'),
        'fiber' => (string) t('entries.photo_fiber'),
        'sugar' => (string) t('entries.photo_sugar'),
        'sodium' => (string) t('entries.photo_sodium'),
        'training_calories_burned' => (string) t('entries.training_calories_burned'),
        'junk_food' => (string) t('entries.junk_food'),
        'extra_workout' => (string) t('entries.extra_workout'),
        'goal_name' => (string) t('goals.goal_name'),
        'type' => (string) t('goals.type'),
        'target' => (string) t('goals.target'),
        'due_date' => (string) t('goals.due_date'),
        'achievement_name' => (string) t('achievements.name'),
        'description' => (string) t('achievements.description'),
        'reward' => (string) t('achievements.reward'),
        'week' => (string) t('common.week'),
        'month' => (string) t('dashboard.month'),
        'progress' => (string) t('profile.pdf_progress'),
        'current' => (string) t('profile.pdf_current'),
        'compliance' => (string) t('profile.pdf_compliance'),
        'input_days' => (string) t('profile.pdf_input_days'),
        'photo_days' => (string) t('profile.pdf_photo_days'),
        'failures' => (string) t('profile.pdf_failures'),
        'calories_consumed' => (string) t('profile.pdf_calories_consumed'),
        'average_weight' => (string) t('profile.pdf_average_weight'),
        'weight_change' => (string) t('profile.pdf_weight_change'),
    ],
    'challenge_range' => [
        'start' => (string) ($profileChallengeRange['start'] ?? ''),
        'end' => (string) ($profileChallengeRange['end'] ?? ''),
        'name' => $profileSelectedChallengeName,
        'is_archive' => $profileSelectedChallengeIsArchive,
    ],
    'config' => [
        'primary_goal_type' => (string) ($profileUser['primary_goal_type'] ?? 'steps'),
        'primary_goal_value' => (float) ($profileUser['primary_goal_value'] ?? 0),
        'primary_goals_spec' => $primaryGoalsSpec,
        'workout_target' => (int) ($profileUser['workout_target'] ?? 0),
        'maintenance_calories' => $profileUser['maintenance_calories'] !== null ? (float) $profileUser['maintenance_calories'] : null,
        'calorie_burn_goal' => $profileUser['calorie_burn_goal'] !== null ? (float) $profileUser['calorie_burn_goal'] : null,
        'calorie_consumed_max' => $profileUser['calorie_consumed_max'] !== null ? (float) $profileUser['calorie_consumed_max'] : null,
        'ideal_weight' => $profileUser['ideal_weight'] !== null ? (float) $profileUser['ideal_weight'] : null,
    ],
    'totals' => [
        'steps' => (int) ($profileMetric['total_steps'] ?? 0),
        'distance_km' => (float) ($profileMetric['total_km'] ?? 0),
        'workouts' => (int) max((int) ($profileMetric['workout_count'] ?? 0), (int) ($profileMetric['workout_success'] ?? 0)),
        'score' => (float) ($profileMetric['score'] ?? 0),
        'strikes' => (int) ($profileMetric['current_strikes'] ?? 0),
        'penalty' => (int) ($profileMetric['total_penalty'] ?? 0),
    ],
    'charts' => [
        'steps' => $profileStepsChart,
        'distance' => array_values((array) ($profileDistanceWeekly ?? [])),
        'workouts' => array_values((array) ($profileWorkoutWeekly ?? [])),
        'score' => array_values((array) ($profileScoreWeekly ?? [])),
        'weight' => $profileWeightChart,
        'weekly_progress' => array_map(
            static fn(array $week): array => [
                'label' => format_date_eu((string) ($week['week_start'] ?? '')),
                'value' => (float) ($week['progress_pct'] ?? 0),
            ],
            $profileWeeklySummary
        ),
        'monthly_progress' => array_map(
            static fn(array $month): array => [
                'label' => (string) ($month['label'] ?? $month['month'] ?? ''),
                'value' => (float) ($month['progress_pct'] ?? 0),
            ],
            $profileMonthlySummary
        ),
        'monthly_steps' => array_map(
            static fn(array $month): array => [
                'label' => (string) ($month['label'] ?? $month['month'] ?? ''),
                'value' => (int) ($month['steps'] ?? 0),
            ],
            $profileMonthlySummary
        ),
        'monthly_workouts' => array_map(
            static fn(array $month): array => [
                'label' => (string) ($month['label'] ?? $month['month'] ?? ''),
                'value' => (int) ($month['workouts'] ?? 0),
            ],
            $profileMonthlySummary
        ),
        'nutrition_calories' => array_map(
            static fn(array $month): array => [
                'label' => (string) ($month['label'] ?? $month['month'] ?? ''),
                'value' => (float) ($month['calories'] ?? 0),
            ],
            $profileMonthlySummary
        ),
    ],
    'daily_details' => $profileDailyDetails,
    'daily_photo_nutrition' => $profileDailyPhotoNutrition,
    'weekly_summary' => $profileWeeklySummary,
    'monthly_summary' => $profileMonthlySummary,
    'total_summary' => $profileTotalSummary,
    'habit_goal_codes' => $habitGoalCodes,
    'goals' => array_map(
        static function (array $goal): array {
            return [
                'title' => (string) ($goal['title'] ?? ''),
                'target_type' => (string) ($goal['type_label'] ?? $goal['target_type'] ?? $goal['type'] ?? ''),
                'current_value' => (float) ($goal['current'] ?? $goal['current_value'] ?? 0),
                'current_label' => (string) ($goal['current_label'] ?? ''),
                'target_value' => (float) ($goal['target'] ?? $goal['target_value'] ?? 0),
                'target_label' => (string) ($goal['target_label'] ?? ''),
                'progress_pct' => (float) ($goal['progress_pct'] ?? 0),
                'status' => (string) ($goal['status'] ?? 'active'),
                'status_label' => (string) ($goal['status_label'] ?? $goal['status'] ?? 'active'),
                'due_date' => (string) ($goal['due_date'] ?? ''),
            ];
        },
        (array) ($profileGoalCards ?? $personalGoals ?? [])
    ),
    'achievements' => array_map(
        static function (array $achievement): array {
            return [
                'name' => (string) ($achievement['name'] ?? ''),
                'description' => (string) ($achievement['description'] ?? ''),
                'reward_text' => (string) ($achievement['reward_text'] ?? ''),
                'awarded_at' => (string) ($achievement['awarded_at'] ?? ''),
            ];
        },
        (array) ($userAchievements ?? [])
    ),
];
if (!$penaltiesEnabled) {
    unset(
        $profileExportPayload['labels']['strikes'],
        $profileExportPayload['labels']['penalty'],
        $profileExportPayload['totals']['strikes'],
        $profileExportPayload['totals']['penalty']
    );
}
$profileExportJson = json_encode(
    $profileExportPayload,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
if (!is_string($profileExportJson)) {
    $profileExportJson = '{}';
}
$latestWeight = null;
if ($profileWeightChart !== []) {
    $latestWeightRow = $profileWeightChart[count($profileWeightChart) - 1];
    $latestWeight = is_numeric($latestWeightRow['value'] ?? null) ? (float) $latestWeightRow['value'] : null;
}
$profileWorkoutTotal = (int) max((int) ($profileMetric['workout_count'] ?? 0), (int) ($profileMetric['workout_success'] ?? 0));
$profileDataCards = [
    ['label' => t('metric.steps'), 'value' => number_format((int) ($profileMetric['total_steps'] ?? 0), 0, '.', ''), 'meta' => t('metric.total'), 'metric' => 'steps'],
    ['label' => t('metric.total_km'), 'value' => number_format((float) ($profileMetric['total_km'] ?? 0), 2, '.', '') . ' km', 'meta' => t('metric.distance_km'), 'metric' => 'distance'],
    ['label' => t('metric.workouts'), 'value' => (string) $profileWorkoutTotal, 'meta' => t('metric.total'), 'metric' => 'workouts'],
    ['label' => t('metric.score'), 'value' => number_format((float) ($profileMetric['score'] ?? 0), 1, '.', ''), 'meta' => t('metric.current_value'), 'metric' => 'score'],
];
if ($penaltiesEnabled) {
    $profileDataCards[] = ['label' => t('metric.strikes'), 'value' => (string) (int) ($profileMetric['current_strikes'] ?? 0), 'meta' => t('metric.current_value'), 'metric' => 'strikes'];
    $profileDataCards[] = ['label' => t('metric.penalty'), 'value' => "\u{20AC}" . number_format((float) ($profileMetric['total_penalty'] ?? 0), 2, '.', ''), 'meta' => t('metric.total'), 'metric' => 'money'];
}
if ($latestWeight !== null) {
    $profileDataCards[] = ['label' => t('profile.latest_weight'), 'value' => number_format($latestWeight, 1, '.', '') . ' kg', 'meta' => t('metric.weight')];
}
$calorieConfigParts = [];
if (($profileUser['maintenance_calories'] ?? null) !== null) {
    $calorieConfigParts[] = t('dashboard.calories_maintenance') . ': ' . number_format((float) $profileUser['maintenance_calories'], 0, '.', '') . ' kcal';
}
if (($profileUser['calorie_burn_goal'] ?? null) !== null) {
    $calorieConfigParts[] = t('dashboard.calories_burned') . ': ' . number_format((float) $profileUser['calorie_burn_goal'], 0, '.', '') . ' kcal';
}
if (($profileUser['calorie_consumed_max'] ?? null) !== null) {
    $calorieConfigParts[] = t('dashboard.calories_consumed') . ': ' . number_format((float) $profileUser['calorie_consumed_max'], 0, '.', '') . ' kcal';
}
if ($calorieConfigParts !== []) {
    $profileDataCards[] = ['label' => t('profile.calorie_config'), 'value' => (string) count($calorieConfigParts), 'meta' => implode(' / ', $calorieConfigParts)];
}
$primaryGoalOptions = [
    ['value' => 'none', 'label' => (string) t('onboarding.no_primary_goal'), 'step' => '1', 'placeholder' => ''],
    ['value' => 'steps', 'label' => (string) t('metric.steps'), 'step' => '1', 'placeholder' => '13000'],
    ['value' => 'km', 'label' => (string) t('metric.distance_km'), 'step' => '0.1', 'placeholder' => '8'],
    ['value' => 'workouts', 'label' => (string) t('metric.workouts'), 'step' => '1', 'placeholder' => '3'],
];
$primaryGoalLabels = [];
foreach ($primaryGoalOptions as $option) {
    $primaryGoalLabels[(string) $option['value']] = (string) $option['label'];
}
$formatPrimarySetupValue = static function (float $value, string $type): string {
    if ($value <= 0) {
        return '-';
    }

    if (in_array($type, ['steps', 'workouts'], true)) {
        return number_format((int) round($value), 0, '.', '');
    }

    return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . ' km';
};
$profilePrimaryGoalType = strtolower((string) ($profileUser['primary_goal_type'] ?? 'steps'));
if ($profilePrimaryGoalType !== 'none' && !in_array($profilePrimaryGoalType, allowed_primary_goal_types(), true)) {
    $profilePrimaryGoalType = 'none';
}
$profilePrimaryGoalValueSource = $profileUser['primary_goal_value'] ?? null;
if ($profilePrimaryGoalType === 'steps' && ($profilePrimaryGoalValueSource === null || $profilePrimaryGoalValueSource === '')) {
    $profilePrimaryGoalValueSource = $profileUser['step_goal'] ?? null;
}
$profilePrimaryGoalValue = is_numeric($profilePrimaryGoalValueSource) ? (float) $profilePrimaryGoalValueSource : 0.0;
$profilePrimaryGoalLabel = $primaryGoalLabels[$profilePrimaryGoalType] ?? $profilePrimaryGoalType;
$profilePrimaryGoalValueDisplay = $formatPrimarySetupValue($profilePrimaryGoalValue, $profilePrimaryGoalType);
$profilePrimaryGoalsParsed = $primaryGoalsSpec !== '' ? parse_primary_goals_spec($primaryGoalsSpec, false) : [];
$profilePrimaryGoalsSpecValue = $profilePrimaryGoalsParsed !== [] ? format_primary_goals_spec($profilePrimaryGoalsParsed) : '';
$profileCurrentGoalRows = $profilePrimaryGoalsParsed !== []
    ? $profilePrimaryGoalsParsed
    : [[
        'type' => $profilePrimaryGoalType,
        'value' => $profilePrimaryGoalValue,
    ]];
$profileCurrentGoalChips = array_values(array_filter(array_map(
    static function (array $goal) use ($primaryGoalLabels, $formatPrimarySetupValue): ?array {
        $type = strtolower((string) ($goal['type'] ?? ''));
        $value = is_numeric($goal['value'] ?? null) ? (float) $goal['value'] : 0.0;
        if ($type === '' || $value <= 0) {
            return null;
        }

        return [
            'label' => $primaryGoalLabels[$type] ?? $type,
            'value' => $formatPrimarySetupValue($value, $type),
        ];
    },
    $profileCurrentGoalRows
)));
$profileCalorieConfigDisplay = $calorieConfigParts !== [] ? implode(' / ', $calorieConfigParts) : '-';
$profileTrainingRankKey = (string) ($profileTrainingRank['key'] ?? 'unranked');
if (!array_key_exists($profileTrainingRankKey, wk_rank_tiers())) {
    $profileTrainingRankKey = 'unranked';
}
$featuredGoal = $activeGoals[0] ?? null;
$featuredGoalCurrent = is_array($featuredGoal) ? $goalCurrentValue($featuredGoal) : 0.0;
$featuredGoalProgress = is_array($featuredGoal) ? $goalProgressPercent($featuredGoal, $featuredGoalCurrent) : 0.0;
$featuredGoalType = is_array($featuredGoal) ? normalize_goal_target_type((string) ($featuredGoal['target_type'] ?? 'custom')) : 'custom';
$latestAchievements = array_slice(array_values((array) ($userAchievements ?? [])), 0, 3);
$latestActivity = array_slice(array_values((array) ($recentActivity ?? [])), 0, 5);
$profileSetupRows = [
    ['label' => t('common.username'), 'value' => '@' . (string) ($profileUser['username'] ?? '')],
    ['label' => t('settings.primary_goal'), 'value' => $profilePrimaryGoalLabel . ' ' . $profilePrimaryGoalValueDisplay],
    ['label' => t('profile.workout_target'), 'value' => (string) ($profileUser['workout_target'] ?? 0) . '/' . strtolower(t('common.week'))],
    ['label' => t('metric.ideal_weight'), 'value' => ($profileUser['ideal_weight'] ?? null) !== null ? (string) $profileUser['ideal_weight'] . ' kg' : '-'],
    ['label' => t('profile.calorie_config'), 'value' => $profileCalorieConfigDisplay],
    ['label' => t('metric.steps'), 'value' => number_format((int) ($profileUser['step_goal'] ?? 0))],
    ['label' => t('dashboard.training_rank_title'), 'value' => t('workouts.rank_' . $profileTrainingRankKey) . ' · ' . number_format((float) ($profileTrainingRank['score'] ?? 0), 1, '.', '')],
    ['label' => t('workouts.stat_sessions') . ' · ' . t('workouts.this_month'), 'value' => number_format((int) ($profileTrainingMonth['sessions'] ?? 0))],
    ['label' => t('workouts.stat_sessions') . ' · ' . t('workouts.all_time'), 'value' => number_format((int) ($profileTrainingAll['sessions'] ?? 0))],
    ['label' => t('workouts.stat_sets') . ' · ' . t('workouts.all_time'), 'value' => number_format((int) ($profileTrainingAll['sets'] ?? 0))],
    ['label' => t('workouts.stat_reps') . ' · ' . t('workouts.all_time'), 'value' => number_format((int) ($profileTrainingAll['reps'] ?? 0))],
];
$profileSetupVisibleRows = array_slice($profileSetupRows, 0, 4);
$profileSetupMoreRows = array_slice($profileSetupRows, 4);
?>
<section class="screen stack-lg spa-shell profile-hierarchy-screen<?= !$isOwnProfile ? ' profile-external-view' : '' ?>" data-spa-page="profile" data-profile-section="<?= e($activeSection) ?>">
    <?php if (!$isOwnProfile && $profileBackUrl !== ''): ?>
        <nav class="context-back-nav" aria-label="<?= e(t('common.back')) ?>">
            <a class="hierarchy-back destination-back profile-section-back profile-context-back" href="<?= e($profileBackUrl) ?>" aria-label="<?= e(t('profile.back_to_team')) ?>"><span aria-hidden="true">&larr;</span><strong><?= e(t('nav.team')) ?></strong></a>
        </nav>
    <?php endif; ?>
    <div class="hero-panel profile-hero compact-panel glass-panel<?= $profileCoverUrl !== '' ? ' has-profile-cover' : '' ?>">
        <?php if ($profileCoverUrl !== ''): ?>
            <span class="profile-cover-media" aria-hidden="true"><img src="<?= e($profileCoverUrl) ?>" alt=""></span>
        <?php endif; ?>
        <div class="profile-title">
            <?php $profileAvatarUrl = avatar_url($profileUser); ?>
            <?php $profileFrameClass = cosmetic_frame_class($profileUser); ?>
            <?php if (!empty($isOwnProfile)): ?>
                <?php // Tapping your own avatar opens the picture / frame chooser. ?>
                <button type="button" class="profile-avatar-trigger" data-app-modal-open="profile-avatar-modal"
                        aria-haspopup="dialog" aria-label="<?= e(t('profile.avatar_menu')) ?>">
                    <?php if ($profileAvatarUrl !== ''): ?>
                        <img class="profile-avatar<?= e($profileFrameClass) ?>" src="<?= e($profileAvatarUrl) ?>" alt="<?= e((string) $profileUser['display_name']) ?>">
                    <?php else: ?>
                        <span class="profile-avatar initials<?= e($profileFrameClass) ?>"><?= e(initials_for((string) $profileUser['display_name'])) ?></span>
                    <?php endif; ?>
                    <span class="profile-avatar-edit-hint" aria-hidden="true">&#9998;</span>
                </button>
            <?php elseif ($profileAvatarUrl !== ''): ?>
                <img class="profile-avatar<?= e($profileFrameClass) ?>" src="<?= e($profileAvatarUrl) ?>" alt="<?= e((string) $profileUser['display_name']) ?>">
            <?php else: ?>
                <span class="profile-avatar initials<?= e($profileFrameClass) ?>"><?= e(initials_for((string) $profileUser['display_name'])) ?></span>
            <?php endif; ?>
            <div class="hero-copy">
                <p class="eyebrow"><?= e(t('nav.profile')) ?></p>
                <h1><?= e((string) $profileUser['display_name']) ?></h1>
                <p class="muted profile-hero-meta">@<?= e((string) $profileUser['username']) ?><?php if (!$isOwnProfile): ?> &middot; <?= e(t('profile.read_only')) ?><?php endif; ?></p>
                <p class="profile-hero-message" title="<?= e($profileHeroMessage) ?>"><?= e($profileHeroMessage) ?></p>
                <?php
                $xp = (array) ($profileXp ?? []);
                $profileLevel = max(1, (int) ($xp['level'] ?? 1));
                // Level 10, 20, 30… each unlock a new badge colour tier.
                $profileLevelTier = min(9, intdiv($profileLevel, 10));
                ?>
                <button type="button" class="profile-level profile-level-trigger profile-level-tier-<?= $profileLevelTier ?>" data-level-tier="<?= $profileLevelTier ?>" data-app-modal-open="profile-level-modal" title="<?= e(t('xp.progress_title')) ?>" aria-haspopup="dialog">
                    <span class="profile-level-badge"><?= e(t('xp.level_short')) ?> <?= $profileLevel ?></span>
                    <span class="profile-xp">
                        <span class="profile-xp-bar"><span style="width: <?= max(0, min(100, (int) ($xp['progress_pct'] ?? 0))) ?>%"></span></span>
                        <span class="profile-xp-label"><?= e(number_format((int) ($xp['total_xp'] ?? 0))) ?> <?= e(t('xp.points')) ?> &middot; <?= e(t('xp.to_next', ['xp' => number_format((int) ($xp['xp_to_next'] ?? 0))])) ?></span>
                    </span>
                </button>
            </div>
        </div>
        <?php if ($isOwnProfile || !empty($canExportProfilePdf) || $showProfileFriendActions): ?>
            <?php
            $profileHeroActionClasses = ['profile-hero-actions'];
            if ($showProfileFriendActions) {
                $profileHeroActionClasses[] = 'has-friend-actions';
            }
            if (!empty($canExportProfilePdf)) {
                $profileHeroActionClasses[] = 'has-pdf-action';
            }
            if ($isOwnProfile) {
                $profileHeroActionClasses[] = 'has-tagline-action';
            }
            ?>
            <div class="<?= e(implode(' ', $profileHeroActionClasses)) ?>">
                <?php if ($showProfileFriendActions): ?>
                    <div class="profile-friend-actions profile-friend-actions-hero" data-friend-status="<?= e($profileFriendStatus) ?>" aria-label="<?= e(t('profile.friendship')) ?>">
                        <span class="profile-friend-status-icon" aria-hidden="true"><?= activity_icon_svg('users') ?></span>
                        <span class="profile-friend-status">
                            <strong><?= e($profileFriendStatusText) ?></strong>
                            <small><?= e($profileFriendStatus === 'none' ? t('profile.friend_add_hint') : '@' . (string) $profileUser['username']) ?></small>
                        </span>
                        <div class="profile-friend-action-controls">
                            <?php
                            $profileFriendHeroMenuItems = [];
                            if (!empty($canExportProfilePdf)) {
                                $profileFriendHeroMenuItems[] = [
                                    'label' => t('profile.export_data'),
                                    'attrs' => ['data-profile-pdf-export' => ''],
                                ];
                            }
                            $renderProfileFriendActions($profileFriendStatus, (int) $profileUser['id'], 'profile-friend-hero-action', $profileFriendHeroMenuItems);
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($isOwnProfile): ?>
                    <?php
                    $profileMenuItems = [];
                    if ($isOwnProfile) {
                        $profileMenuItems[] = [
                            'label' => t('nav.workouts'),
                            'href' => '/?page=workouts',
                        ];
                        $profileMenuItems[] = [
                            'label' => t('profile.edit_profile'),
                            'href' => '/?page=settings',
                        ];
                        $profileMenuItems[] = [
                            'label' => t('menu.personalize'),
                            'children' => [[
                                'label' => t('profile.edit_tagline'),
                                'attrs' => ['data-app-modal-open' => 'profile-tagline-modal'],
                            ], [
                                'label' => t('profile.edit_cover'),
                                'attrs' => ['data-app-modal-open' => 'profile-cover-modal'],
                            ], [
                                'label' => t('profile.add_widget'),
                                'attrs' => ['data-app-modal-open' => 'profile-widget-create-modal'],
                            ], [
                                'label' => t('profile.customize_layout'),
                                'href' => $profileUrl('', ['layout_edit' => '1']),
                            ]],
                        ];
                    }
                    if (!empty($canExportProfilePdf)) {
                        $profileMenuItems[] = [
                            'label' => t('profile.export_data'),
                            'attrs' => ['data-profile-pdf-export' => ''],
                        ];
                    }
                    echo render_kebab_menu($profileMenuItems, [
                        'label' => t('profile.manage'),
                        'title' => t('profile.manage'),
                        'align' => 'end',
                        'class' => 'profile-hero-menu',
                    ]);
                    ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($activeSection === ''): ?>
        <div class="profile-mobile-root">
            <?php if ($isOwnProfile): ?>
                <div class="hierarchy-status-strip">
                    <span><strong><?= count($profileActiveGoalCards) ?></strong><small><?= e(t('goals.personal')) ?></small></span>
                    <span><strong><?= count((array) ($userAchievements ?? [])) ?></strong><small><?= e(t('profile.achievements')) ?></small></span>
                    <span><strong><?= count($profileFriends) ?></strong><small><?= e(t('nav.friends')) ?></small></span>
                </div>
            <?php endif; ?>
            <nav class="hierarchy-nav-list mobile-hub-section-grid" aria-label="<?= e(t('nav.profile')) ?>">
                <a class="hierarchy-nav-row" data-tone="orange" href="<?= e($profileUrl('goals')) ?>" data-spa-link><span class="hierarchy-nav-icon" aria-hidden="true"><?= activity_icon_svg('target') ?></span><span class="hierarchy-nav-copy"><strong><?= e(t('profile.mobile_goals')) ?></strong><small><?= e(t('profile.mobile_goals_hint')) ?></small></span><span class="hierarchy-nav-meta"><?= count($profileActiveGoalCards) ?></span><span class="hierarchy-nav-chevron" aria-hidden="true">&rsaquo;</span></a>
                <a class="hierarchy-nav-row" data-tone="blue" href="<?= e($profileUrl('training')) ?>" data-spa-link><span class="hierarchy-nav-icon" aria-hidden="true"><?= activity_icon_svg('dumbbell') ?></span><span class="hierarchy-nav-copy"><strong><?= e(t('nav.training_short')) ?></strong><small><?= e(t('profile.mobile_training_hint')) ?></small></span><span class="hierarchy-nav-meta"><?= e(t('workouts.rank_' . $profileTrainingRankKey)) ?></span><span class="hierarchy-nav-chevron" aria-hidden="true">&rsaquo;</span></a>
                <a class="hierarchy-nav-row" data-tone="green" href="<?= e($profileUrl('social')) ?>" data-spa-link><span class="hierarchy-nav-icon" aria-hidden="true"><?= activity_icon_svg('users') ?></span><span class="hierarchy-nav-copy"><strong><?= e(t('profile.mobile_social')) ?></strong><small><?= e(t('profile.mobile_social_hint')) ?></small></span><span class="hierarchy-nav-meta"><?= count($profileFriends) ?></span><span class="hierarchy-nav-chevron" aria-hidden="true">&rsaquo;</span></a>
                <a class="hierarchy-nav-row" data-tone="amber" href="<?= e($profileUrl('achievements')) ?>" data-spa-link><span class="hierarchy-nav-icon" aria-hidden="true"><?= activity_icon_svg('medal') ?></span><span class="hierarchy-nav-copy"><strong><?= e(t('profile.achievements')) ?></strong><small><?= e(t('profile.mobile_achievements_hint')) ?></small></span><span class="hierarchy-nav-meta"><?= count((array) ($userAchievements ?? [])) ?></span><span class="hierarchy-nav-chevron" aria-hidden="true">&rsaquo;</span></a>
                <?php if ($isOwnProfile): ?>
                    <a class="hierarchy-nav-row" data-tone="violet" href="<?= e($profileUrl('activity')) ?>" data-spa-link><span class="hierarchy-nav-icon" aria-hidden="true"><?= activity_icon_svg('spark') ?></span><span class="hierarchy-nav-copy"><strong><?= e(t('profile.mobile_activity')) ?></strong><small><?= e(t('profile.mobile_activity_hint')) ?></small></span><span class="hierarchy-nav-chevron" aria-hidden="true">&rsaquo;</span></a>
                    <a class="hierarchy-nav-row" data-tone="slate" href="/?page=settings"><span class="hierarchy-nav-icon" aria-hidden="true"><?= activity_icon_svg('sliders') ?></span><span class="hierarchy-nav-copy"><strong><?= e(t('nav.settings')) ?></strong><small><?= e(t('profile.mobile_settings_hint')) ?></small></span><span class="hierarchy-nav-chevron" aria-hidden="true">&rsaquo;</span></a>
                <?php endif; ?>
            </nav>
        </div>
    <?php endif; ?>

    <?php if (count($profileChallengeOptions) > 1): ?>
        <article class="panel profile-challenge-switcher">
            <div class="profile-challenge-switcher-head">
                <div>
                    <p class="eyebrow"><?= e($profileSelectedChallengeIsArchive ? t('profile.challenge_archived') : t('profile.challenge_current')) ?></p>
                    <h2><?= e($profileSelectedChallengeName) ?></h2>
                    <p class="muted small"><?= e(t('profile.selected_challenge', ['range' => $profileSelectedChallengeRangeLabel])) ?></p>
                </div>
                <form method="get" class="control-strip profile-challenge-form">
                    <input type="hidden" name="page" value="profile">
                    <?php if (!$isOwnProfile): ?>
                        <input type="hidden" name="user_id" value="<?= (int) ($profileUser['id'] ?? 0) ?>">
                    <?php endif; ?>
                    <?php if ($activeSection !== ''): ?>
                        <input type="hidden" name="section" value="<?= e($activeSection) ?>">
                    <?php endif; ?>
                    <label>
                        <?= e(t('profile.challenge_selector')) ?>
                        <select name="challenge" onchange="this.form.submit()">
                            <?php foreach ($profileChallengeOptions as $challengeOption): ?>
                                <?php
                                $challengeKey = (string) ($challengeOption['key'] ?? 'current');
                                $challengeName = (string) ($challengeOption['name'] ?? t('challenges.unnamed'));
                                $challengeRange = format_date_eu((string) ($challengeOption['start'] ?? '')) . ' - ' . format_date_eu((string) ($challengeOption['end'] ?? ''));
                                $challengeStatus = !empty($challengeOption['is_archive']) ? t('profile.challenge_archived') : t('profile.challenge_current');
                                ?>
                                <option value="<?= e($challengeKey) ?>" <?= $challengeKey === $profileSelectedChallengeKey ? 'selected' : '' ?>>
                                    <?= e($challengeName . ' | ' . $challengeRange . ' | ' . $challengeStatus) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </form>
            </div>
            <div class="profile-challenge-history">
                <div>
                    <strong><?= e(t('profile.challenge_history_title')) ?></strong>
                    <span class="muted small"><?= e(t('profile.challenge_history_hint')) ?></span>
                </div>
                <div class="profile-challenge-history-list" role="list">
                    <?php foreach ($profileChallengeOptions as $challengeOption): ?>
                        <?php
                        $challengeKey = (string) ($challengeOption['key'] ?? 'current');
                        $challengeName = (string) ($challengeOption['name'] ?? t('challenges.unnamed'));
                        $challengeRange = format_date_eu((string) ($challengeOption['start'] ?? '')) . ' - ' . format_date_eu((string) ($challengeOption['end'] ?? ''));
                        $challengeStatus = !empty($challengeOption['is_archive']) ? t('profile.challenge_archived') : t('profile.challenge_current');
                        $challengeSummary = is_array($challengeOption['summary'] ?? null) ? (array) $challengeOption['summary'] : [];
                        $challengeScore = number_format((float) ($challengeSummary['score'] ?? 0), 1, '.', '');
                        $challengeSteps = number_format((int) ($challengeSummary['steps'] ?? 0), 0, '.', '');
                        $challengeWorkouts = (int) ($challengeSummary['workouts'] ?? 0);
                        $challengeWorkoutTarget = (int) ($challengeSummary['workout_target'] ?? 0);
                        $challengeWorkoutText = $challengeWorkoutTarget > 0
                            ? (string) $challengeWorkouts . '/' . (string) $challengeWorkoutTarget
                            : (string) $challengeWorkouts;
                        $challengeUrlQuery = ['page' => 'profile'];
                        if (!$isOwnProfile) {
                            $challengeUrlQuery['user_id'] = (int) ($profileUser['id'] ?? 0);
                        }
                        if ($activeSection !== '') {
                            $challengeUrlQuery['section'] = $activeSection;
                        }
                        if ($challengeKey !== 'current') {
                            $challengeUrlQuery['challenge'] = $challengeKey;
                        }
                        $challengeUrl = '/?' . http_build_query($challengeUrlQuery);
                        ?>
                        <a class="profile-challenge-history-card<?= $challengeKey === $profileSelectedChallengeKey ? ' active' : '' ?>" href="<?= e($challengeUrl) ?>" role="listitem" <?= $challengeKey === $profileSelectedChallengeKey ? 'aria-current="page"' : '' ?>>
                            <span><?= e($challengeStatus) ?></span>
                            <strong><?= e($challengeName) ?></strong>
                            <small><?= e($challengeRange) ?></small>
                            <?php if ($canViewProfilePerformance): ?><div class="profile-challenge-history-metrics" aria-label="<?= e(t('profile.challenge_history_metrics')) ?>">
                                <span><small><?= e(t('metric.score')) ?></small><strong><?= e($challengeScore) ?></strong></span>
                                <span><small><?= e(t('metric.steps')) ?></small><strong><?= e($challengeSteps) ?></strong></span>
                                <span><small><?= e(t('metric.workouts')) ?></small><strong><?= e($challengeWorkoutText) ?></strong></span>
                            </div><?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </article>
    <?php endif; ?>

    <?php if ($isOwnProfile): ?>
        <article class="panel profile-data-overview compact-panel glass-panel<?= $activeSection !== '' ? ' hidden' : '' ?>" data-spa-home-extra data-profile-collapsible="<?= e($profileCollapseStateKey('data-overview')) ?>" <?= $activeSection !== '' ? 'hidden' : '' ?>>
        <div class="panel-head">
            <div>
                <p class="eyebrow"><?= e(t('profile.my_data')) ?></p>
                <h2><?= e(t('profile.my_data')) ?></h2>
                <p class="muted small"><?= e(t('profile.my_data_subtitle')) ?> <?= e($profileSelectedChallengeRangeLabel) ?></p>
            </div>
            <?= $profileCollapseControl() ?>
        </div>
        <div class="profile-data-grid">
            <?php foreach ($profileDataCards as $card): ?>
                <?php
                $cardMetric = (string) ($card['metric'] ?? '');
                $cardMetricHref = '';
                if ($cardMetric !== '' && $isOwnProfile) {
                    // My Data shows challenge totals, so open the detail in the
                    // matching "total" view rather than the user's dashboard view.
                    $cardMetricHref = '/?' . http_build_query([
                        'page' => 'metric',
                        'user_id' => (int) ($profileUser['id'] ?? 0),
                        'metric' => $cardMetric,
                        'view' => 'total',
                    ]);
                }
                ?>
                <?php if ($cardMetricHref !== ''): ?>
                    <a class="profile-data-card profile-data-card-link" href="<?= e($cardMetricHref) ?>" aria-label="<?= e((string) $card['label']) ?>">
                        <span><?= e((string) $card['label']) ?></span>
                        <strong><?= e((string) $card['value']) ?></strong>
                        <small><?= e((string) $card['meta']) ?></small>
                        <span class="profile-data-card-go" aria-hidden="true">›</span>
                    </a>
                <?php else: ?>
                    <article class="profile-data-card">
                        <span><?= e((string) $card['label']) ?></span>
                        <strong><?= e((string) $card['value']) ?></strong>
                        <small><?= e((string) $card['meta']) ?></small>
                    </article>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </article>
    <?php endif; ?>

    <?php if ($profileLayoutEditMode): ?>
        <?php // Same compact editbar as Home and Analytics. The old fixed panel sat
              // underneath the edit-mode blur overlay, so its Save button could not be
              // tapped on a phone at all. ?>
        <div class="layout-editbar dashboard-layout-editbar profile-layout-editbar" data-spa-home-extra>
            <form method="post" action="<?= e($profileUrl()) ?>" class="dashboard-layout-editor" data-profile-layout-editor>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="save_profile_layout">

                <div class="dashboard-editbar-row">
                    <p class="dashboard-editbar-hint">
                        <strong><?= e(t('profile.customize_layout')) ?></strong>
                        <small><?= e(t('profile.customize_hint')) ?></small>
                    </p>
                    <div class="dashboard-editbar-actions">
                        <a class="btn btn-ghost small" href="<?= e($profileUrl()) ?>"><?= e(t('common.cancel')) ?></a>
                        <button class="btn btn-primary small" type="submit"><?= e(t('common.save')) ?></button>
                    </div>
                </div>

                <details class="dashboard-layout-visibility" data-persist-disclosure="<?= e($profileCollapseStateKey('layout-visibility')) ?>" open>
                    <summary><?= e(t('dashboard.visible_widgets')) ?></summary>
                    <div class="team-layout-editor-list dashboard-layout-editor-list" data-profile-layout-list>
                        <?php foreach ($profileOrderedBlocks as $idx => $blk): ?>
                            <div class="team-layout-editor-item dashboard-layout-editor-item" data-profile-layout-item>
                                <div class="dashboard-layout-mobile-actions team-layout-mobile-actions">
                                    <button class="btn btn-ghost small" type="button" data-layout-move="up" aria-label="<?= e(t('common.previous')) ?>">&uarr;</button>
                                    <button class="btn btn-ghost small" type="button" data-layout-move="down" aria-label="<?= e(t('common.next')) ?>">&darr;</button>
                                </div>
                                <label class="dashboard-layout-toggle">
                                    <input type="checkbox" name="profile_blocks[]" value="<?= e($blk) ?>" <?= $profileBlockVisible($blk) ? 'checked' : '' ?>>
                                    <span><?= e((string) ($profileLayoutLabels[$blk] ?? $blk)) ?></span>
                                </label>
                                <input type="hidden" name="profile_order[<?= e($blk) ?>]" value="<?= (int) $idx + 1 ?>" data-profile-order-input>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button class="btn btn-ghost small dashboard-editbar-reset" type="submit" name="reset_profile_layout" value="1"><?= e(t('dashboard.reset_layout')) ?></button>
                </details>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($activeSection === ''): ?><header class="mobile-widget-feed-head profile-widget-feed-head"><div><p><?= e(t('dashboard.visible_widgets')) ?></p><h2><?= e(t('nav.profile')) ?></h2></div><?php if ($isOwnProfile): ?><div class="profile-widget-feed-actions"><button type="button" data-app-modal-open="profile-widget-create-modal"><?= e(t('profile.add_widget_short')) ?></button><a href="<?= e($profileUrl('', ['layout_edit' => '1'])) ?>"><?= e(t('profile.customize_layout')) ?></a></div><?php endif; ?></header><?php endif; ?>
    <section class="profile-home-grid<?= $activeSection !== '' ? ' hidden' : '' ?>" data-spa-main <?= $activeSection !== '' ? 'hidden' : '' ?>>
        <article class="panel profile-home-card profile-home-goals compact-panel glass-panel" data-profile-block="goals" data-profile-collapsible="<?= e($profileCollapseStateKey('goals')) ?>" style="<?= e($profileBlockStyle('goals')) ?>">
            <div class="profile-home-card-head">
                <div>
                    <p class="eyebrow"><?= count($profileActiveGoalCards) ?> <?= e(t('profile.active_goals_suffix')) ?> · <?= count($profileCompletedGoalCards) ?> <?= e(t('settings.completed_goals')) ?></p>
                    <h2><?= e(t('goals.personal')) ?></h2>
                </div>
                <div class="inline-actions-mini">
                    <?php if ($canEditProfile): ?>
                        <a class="btn btn-primary small" href="<?= e($profileUrl('goals', ['goal_new' => 1])) ?>" data-spa-link><?= e(t('profile.new_goal')) ?></a>
                        <a class="btn btn-ghost small" href="<?= e($profileUrl('goals')) ?>" data-spa-link><?= e(t('settings.edit_goals')) ?></a>
                    <?php else: ?>
                        <a class="btn btn-ghost small" href="<?= e($profileUrl('goals')) ?>" data-spa-link><?= e(t('common.view_all')) ?></a>
                    <?php endif; ?>
                    <?= $profileCollapseControl() ?>
                </div>
            </div>
            <?php if ($profileActiveGoalCards !== []): ?>
                <div class="profile-home-goal-list">
                    <?php foreach (array_slice($profileActiveGoalCards, 0, 4) as $goalCard): ?>
                        <a class="profile-home-goal-row" href="<?= e($profileUrl('goals', ['goal_id' => (int) ($goalCard['id'] ?? 0)])) ?>" data-spa-link>
                            <span>
                                <strong><?= e((string) ($goalCard['title'] ?? '')) ?></strong>
                                <small><?= e((string) ($goalCard['type_label'] ?? '')) ?> · <?= e((string) ($goalCard['current_label'] ?? '0')) ?> / <?= e((string) ($goalCard['target_label'] ?? '0')) ?></small>
                                <?php if ((string) ($goalCard['due_label'] ?? '') !== ''): ?>
                                    <small><?= e(t('goals.due_date')) ?>: <?= e((string) $goalCard['due_label']) ?></small>
                                <?php endif; ?>
                            </span>
                            <span class="profile-home-goal-meter" aria-hidden="true"><span style="width: <?= e((string) ($goalCard['progress_pct'] ?? 0)) ?>%"></span></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state profile-goals-empty">
                    <span class="empty-state-icon"><?= activity_icon_svg('target') ?></span>
                    <p><strong><?= e(t('goals.empty')) ?></strong></p>
                    <p class="muted small"><?= e(t('goals.empty_hint')) ?></p>
                    <?php if ($canEditProfile): ?>
                        <a class="btn btn-primary small" href="<?= e($profileUrl('goals', ['goal_new' => 1])) ?>" data-spa-link><?= e(t('profile.new_goal')) ?></a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </article>

        <article class="panel profile-home-card profile-friends-card compact-panel glass-panel" data-profile-block="friends" data-profile-collapsible="<?= e($profileCollapseStateKey('friends')) ?>" style="<?= e($profileBlockStyle('friends')) ?>">
            <div class="profile-home-card-head">
                <div>
                    <p class="eyebrow">
                        <?= e((string) $profileFriendCount) ?> <?= e(t('friends.count_label')) ?>
                        <?php if ($isOwnProfile && count($profileFriendIncoming) > 0): ?>
                            &middot; <?= e((string) count($profileFriendIncoming)) ?> <?= e(t('friends.incoming')) ?>
                        <?php endif; ?>
                    </p>
                    <h2><?= e($isOwnProfile ? t('friends.your_friends') : t('profile.friends_of', ['name' => (string) ($profileUser['display_name'] ?? '')])) ?></h2>
                </div>
                <?= $profileCollapseControl() ?>
            </div>

            <?php if ($isOwnProfile && $profileFriendIncomingPreview !== []): ?>
                <div class="profile-friend-request-list">
                    <strong><?= e(t('friends.incoming')) ?></strong>
                    <?php foreach ($profileFriendIncomingPreview as $requester): ?>
                        <?php $profileRequesterCoverUrl = $profileIdentityMediaUrl($requester, 'profile_cover_path'); ?>
                        <div class="profile-friend-row profile-identity-row<?= $profileRequesterCoverUrl !== '' ? ' has-cover' : '' ?>">
                            <a class="profile-friend-id" href="/?page=profile&amp;user_id=<?= (int) ($requester['id'] ?? 0) ?>" data-spa-link>
                                <?php $profileFriendAvatar($requester); ?>
                                <span>
                                    <strong><?= e((string) ($requester['display_name'] ?? $requester['username'] ?? '')) ?></strong>
                                    <small>@<?= e((string) ($requester['username'] ?? '')) ?></small>
                                </span>
                            </a>
                                <div class="profile-friend-actions">
                                    <?php $renderProfileFriendActions('pending_in', (int) ($requester['id'] ?? 0), 'profile-friend-card-action'); ?>
                                </div>
                            <?php if ($profileRequesterCoverUrl !== ''): ?><img class="profile-identity-cover" src="<?= e($profileRequesterCoverUrl) ?>" alt="" loading="lazy" aria-hidden="true"><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($profileFriendPreview === []): ?>
                <p class="muted"><?= e($isOwnProfile ? t('friends.none') : t('profile.friends_empty_other')) ?></p>
            <?php else: ?>
                <div class="profile-friend-list">
                    <?php foreach ($profileFriendPreview as $friend): ?>
                        <?php $profileFriendCoverUrl = $profileIdentityMediaUrl($friend, 'profile_cover_path'); ?>
                        <div class="profile-friend-row profile-identity-row<?= $profileFriendCoverUrl !== '' ? ' has-cover' : '' ?>">
                            <a class="profile-friend-id" href="/?page=profile&amp;user_id=<?= (int) ($friend['id'] ?? 0) ?>" data-spa-link>
                                <?php $profileFriendAvatar($friend); ?>
                                <span>
                                    <strong><?= e((string) ($friend['display_name'] ?? $friend['username'] ?? '')) ?></strong>
                                    <small>@<?= e((string) ($friend['username'] ?? '')) ?></small>
                                </span>
                            </a>
                            <?php if ($isOwnProfile): ?>
                                <div class="profile-friend-actions">
                                    <?php $renderProfileFriendActions('friends', (int) ($friend['id'] ?? 0), 'profile-friend-card-action'); ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($profileFriendCoverUrl !== ''): ?><img class="profile-identity-cover" src="<?= e($profileFriendCoverUrl) ?>" alt="" loading="lazy" aria-hidden="true"><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($profileFriendCount > count($profileFriendPreview)): ?>
                    <a class="profile-friend-more" href="/?page=friends" data-spa-link><?= e(t('common.view_all')) ?></a>
                <?php endif; ?>
            <?php endif; ?>

        </article>

        <article class="panel profile-home-card profile-teams-card compact-panel glass-panel" data-profile-block="teams" data-profile-collapsible="<?= e($profileCollapseStateKey('teams')) ?>" style="<?= e($profileBlockStyle('teams')) ?>">
            <div class="profile-home-card-head">
                <div><p class="eyebrow"><?= count($profileTeamsList) ?></p><h2><?= e(t('social_hub.teams')) ?></h2></div>
                <div class="profile-panel-head-actions"><?php if ($isOwnProfile): ?><a class="btn btn-ghost small" href="/?page=social&amp;section=team"><?= e(t('common.view_all')) ?></a><?php endif; ?><?= $profileCollapseControl() ?></div>
            </div>
            <?php if ($profileTeamsList === []): ?>
                <p class="muted small"><?= e(t('social_hub.team_empty')) ?></p>
            <?php else: ?>
                <div class="profile-team-list">
                    <?php foreach ($profileTeamsList as $profileTeamItem): ?>
                        <?php $profileTeamCoverUrl = $profileIdentityMediaUrl($profileTeamItem, 'cover_path'); ?>
                        <a class="profile-team-row profile-identity-row<?= $profileTeamCoverUrl !== '' ? ' has-cover' : '' ?>" href="/?page=team&amp;team_id=<?= (int) ($profileTeamItem['id'] ?? 0) ?>">
                            <?php $profileTeamIcon($profileTeamItem); ?>
                            <span><strong><?= e((string) ($profileTeamItem['name'] ?? '')) ?></strong><small><?= e(t('social_hub.members_count', ['count' => (string) ((int) ($profileTeamItem['member_count'] ?? 0))])) ?></small></span>
                            <span class="settings-chevron" aria-hidden="true">&rsaquo;</span>
                            <?php if ($profileTeamCoverUrl !== ''): ?><img class="profile-identity-cover" src="<?= e($profileTeamCoverUrl) ?>" alt="" loading="lazy" aria-hidden="true"><?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </article>

        <?php if ($canViewProfileWorkouts): ?>
        <article class="panel profile-home-card profile-training-rank-card compact-panel glass-panel" data-profile-block="training_rank" data-profile-collapsible="<?= e($profileCollapseStateKey('training-rank')) ?>" data-rank="<?= e($profileTrainingRankKey) ?>" style="<?= e($profileBlockStyle('training_rank')) ?>; --rank-color: <?= e((string) ($profileTrainingRank['color'] ?? '#64748b')) ?>">
            <div class="profile-home-card-head">
                <a class="profile-rank-heading-link" href="/?page=workouts&amp;view=ranks" aria-label="<?= e(t('workouts.overall_rank')) ?>: <?= e(t('common.view_all')) ?>">
                    <p class="eyebrow"><?= e(t('workouts.overall_rank')) ?></p>
                    <h2><?= e(t('dashboard.training_rank_title')) ?></h2>
                </a>
                <div class="profile-panel-head-actions"><a class="btn btn-ghost small" href="/?page=workouts&amp;view=ranks"><?= e(t('common.view_all')) ?></a><?= $profileCollapseControl() ?></div>
            </div>
            <div class="profile-training-rank-main">
                <span class="profile-training-rank-emblem">
                    <strong><?= e(t('workouts.rank_' . $profileTrainingRankKey)) ?></strong>
                    <b><?= e(number_format((float) ($profileTrainingRank['score'] ?? 0), 1, '.', '')) ?></b>
                    <small><?= e(t('workouts.lift_points')) ?></small>
                </span>
                <span class="profile-training-rank-detail">
                    <strong><?= ($profileTrainingPosition ?? null) !== null ? e(t('dashboard.training_rank_position', ['position' => (int) $profileTrainingPosition])) : e(t('workouts.rank_unranked')) ?></strong>
                    <small><?= e(t('workouts.ranked_count', [
                        'ranked' => (int) ($profileTrainingRank['body_parts_ranked'] ?? 0),
                        'total' => (int) ($profileTrainingRank['body_parts_total'] ?? 0),
                    ])) ?></small>
                    <span class="profile-training-rank-bar"><i style="width: <?= (int) ($profileTrainingRank['progress'] ?? 0) ?>%"></i></span>
                </span>
            </div>
            <?php if ($profileTrainingMuscles !== []): ?>
                <div class="profile-training-muscles">
                    <?php foreach ($profileTrainingMuscles as $muscleRank): ?>
                        <?php $muscleRankInfo = (array) ($muscleRank['rank'] ?? []); ?>
                        <span>
                            <small><?= e(t('workouts.muscle_' . (string) ($muscleRank['muscle'] ?? 'other'))) ?></small>
                            <strong><?= e(t('workouts.rank_' . (string) ($muscleRankInfo['key'] ?? 'unranked'))) ?></strong>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <a class="profile-training-empty" href="/?page=workouts"><?= e(t('dashboard.training_empty')) ?></a>
            <?php endif; ?>
        </article>

        <article class="panel profile-home-card profile-training-progress-card compact-panel glass-panel" data-profile-block="training_progress" data-profile-collapsible="<?= e($profileCollapseStateKey('training-progress')) ?>" style="<?= e($profileBlockStyle('training_progress')) ?>">
            <div class="profile-home-card-head">
                <div>
                    <p class="eyebrow"><?= e(t('workouts.this_month')) ?></p>
                    <h2><?= e(t('dashboard.training_progress_title')) ?></h2>
                </div>
                <div class="profile-panel-head-actions"><?php if ($isOwnProfile): ?><a class="btn btn-ghost small" href="/?page=workouts&amp;view=stats"><?= e(t('common.view_all')) ?></a><?php endif; ?><?= $profileCollapseControl() ?></div>
            </div>
            <div class="profile-training-stat-grid compact-metrics-row glass-panel">
                <span><small><?= e(t('workouts.stat_sessions')) ?></small><strong><?= (int) ($profileTrainingMonth['sessions'] ?? 0) ?></strong></span>
                <span><small><?= e(t('workouts.stat_volume')) ?></small><strong><?= e(number_format((float) ($profileTrainingMonth['volume'] ?? 0), 0, '.', ' ')) ?></strong></span>
                <span><small><?= e(t('workouts.streak')) ?></small><strong><?= (int) ($profileTrainingStreak ?? 0) ?></strong></span>
                <span><small><?= e(t('workouts.stat_reps')) ?></small><strong><?= e(number_format((int) ($profileTrainingAll['reps'] ?? 0), 0, '.', ' ')) ?></strong></span>
            </div>
            <?php if ($profileTrainingRecentSessions !== [] || $profileTrainingRecords !== []): ?>
                <div class="profile-training-recent">
                    <?php foreach (array_slice($profileTrainingRecentSessions, 0, 2) as $trainingSession): ?>
                        <span><small><?= e(format_date_eu(substr((string) ($trainingSession['started_at'] ?? ''), 0, 10))) ?></small><strong><?= e((string) (($trainingSession['title'] ?? '') !== '' ? $trainingSession['title'] : t('workouts.session'))) ?></strong></span>
                    <?php endforeach; ?>
                    <?php foreach (array_slice($profileTrainingRecords, 0, 1) as $trainingRecord): ?>
                        <span><small><?= e(t('workouts.personal_records')) ?></small><strong><?= e((string) ($trainingRecord['exercise_name'] ?? '')) ?> · <?= e(number_format((float) ($trainingRecord['value'] ?? 0), 1, '.', '')) ?> kg</strong></span>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <a class="profile-training-empty" href="/?page=workouts"><?= e(t('dashboard.training_empty')) ?></a>
            <?php endif; ?>
        </article>
        <?php endif; ?>

        <article class="panel profile-home-card profile-home-achievements compact-panel glass-panel" data-profile-block="achievements" data-profile-collapsible="<?= e($profileCollapseStateKey('achievements')) ?>" style="<?= e($profileBlockStyle('achievements')) ?>">
            <div class="profile-home-card-head">
                <div>
                    <p class="eyebrow"><?= e((string) $achievementCount) ?> <?= e(t('profile.unlocked_suffix')) ?></p>
                    <h2><?= e(t('profile.achievements')) ?></h2>
                </div>
                <div class="profile-panel-head-actions"><a class="profile-achievement-preview-link" href="<?= e($profileUrl('achievements')) ?>" data-spa-link><span><?= e(t('common.view_all')) ?></span><b aria-hidden="true">›</b></a><?= $profileCollapseControl() ?></div>
            </div>
            <?php if ($latestAchievements === []): ?>
                <p class="muted"><?= e(t('achievements.empty')) ?></p>
            <?php else: ?>
                <div class="profile-achievement-preview-list">
                    <?php foreach ($latestAchievements as $achievement): ?>
                        <button type="button" class="profile-achievement-preview-item" <?= achievement_modal_attrs($achievement) ?>>
                            <?= achievement_visual_html($achievement, 'achievement-visual') ?>
                            <span><strong><?= e((string) ($achievement['name'] ?? '')) ?></strong><small><?= e(!empty($achievement['awarded_at']) ? format_date_eu((string) $achievement['awarded_at']) : (string) ($achievement['description'] ?? '')) ?></small></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </article>

        <?php foreach ($profileCustomWidgets as $profileCustomWidget): ?>
            <?php
            if (!$isOwnProfile && empty($profileCustomWidget['is_visible'])) {
                continue;
            }
            $customWidgetId = (int) ($profileCustomWidget['id'] ?? 0);
            $customWidgetKey = profile_custom_widget_key($customWidgetId);
            $customWidgetType = profile_custom_widget_type((string) ($profileCustomWidget['widget_type'] ?? 'media'));
            $customWidgetMediaPath = trim((string) ($profileCustomWidget['media_path'] ?? ''));
            $customWidgetMediaUrl = $customWidgetMediaPath !== '' ? media_url($customWidgetMediaPath, $profileCustomWidget['updated_at'] ?? null) : '';
            $customWidgetMediaMime = strtolower(trim((string) ($profileCustomWidget['media_mime'] ?? '')));
            $customWidgetExternalUrl = profile_custom_widget_url((string) ($profileCustomWidget['external_url'] ?? ''));
            $customWidgetEmbedUrl = profile_custom_widget_video_embed($customWidgetExternalUrl);
            $customWidgetExternalIsImage = $customWidgetExternalUrl !== '' && preg_match('/\.(?:gif|png|jpe?g|webp)(?:[?#].*)?$/i', $customWidgetExternalUrl) === 1;
            $customWidgetExternalIsVideo = $customWidgetExternalUrl !== '' && preg_match('/\.(?:mp4|webm|mov)(?:[?#].*)?$/i', $customWidgetExternalUrl) === 1;
            $customWidgetAchievementIds = profile_custom_widget_achievement_ids((string) ($profileCustomWidget['achievement_ids_json'] ?? ''));
            if ($customWidgetType === 'achievements' && $customWidgetAchievementIds === []) {
                $customWidgetAchievementIds = array_slice(array_keys($profileAchievementById), 0, PROFILE_CUSTOM_WIDGET_ACHIEVEMENT_LIMIT);
            }
            ?>
            <article class="panel profile-home-card profile-custom-showcase glass-panel<?= empty($profileCustomWidget['is_visible']) ? ' is-owner-hidden' : '' ?>" data-profile-block="<?= e($customWidgetKey) ?>" data-profile-collapsible="<?= e($profileCollapseStateKey($customWidgetKey)) ?>" style="<?= e($profileBlockStyle($customWidgetKey)) ?>--showcase-accent:<?= e(profile_custom_widget_accent((string) ($profileCustomWidget['accent_color'] ?? ''))) ?>;">
                <div class="profile-home-card-head profile-showcase-head">
                    <div>
                        <p class="eyebrow"><?= e(t('profile.widget_type_' . $customWidgetType)) ?><?php if ($isOwnProfile && empty($profileCustomWidget['is_visible'])): ?> · <?= e(t('profile.widget_hidden')) ?><?php endif; ?></p>
                        <h2><?= e((string) ($profileCustomWidget['title'] ?? '')) ?></h2>
                    </div>
                    <div class="profile-panel-head-actions"><?php if ($isOwnProfile): ?><button class="profile-showcase-edit" type="button" data-app-modal-open="profile-widget-edit-modal-<?= $customWidgetId ?>" aria-label="<?= e(t('profile.edit_widget')) ?>" title="<?= e(t('profile.edit_widget')) ?>"><?= activity_icon_svg('sliders') ?></button><?php endif; ?><?= $profileCollapseControl() ?></div>
                </div>

                <?php if (trim((string) ($profileCustomWidget['body'] ?? '')) !== ''): ?>
                    <p class="profile-showcase-body"><?= nl2br(e((string) $profileCustomWidget['body'])) ?></p>
                <?php endif; ?>

                <?php if ($customWidgetType === 'achievements'): ?>
                    <div class="profile-showcase-achievements" aria-label="<?= e(t('profile.achievements')) ?>">
                        <?php foreach ($customWidgetAchievementIds as $achievementId): ?>
                            <?php if (!isset($profileAchievementById[$achievementId])) { continue; } $showcaseAchievement = $profileAchievementById[$achievementId]; ?>
                            <button type="button" class="profile-showcase-achievement" <?= achievement_modal_attrs($showcaseAchievement) ?>>
                                <?= achievement_visual_html($showcaseAchievement, 'achievement-visual') ?>
                                <span><strong><?= e((string) ($showcaseAchievement['name'] ?? '')) ?></strong><small><?= e(!empty($showcaseAchievement['awarded_at']) ? format_date_eu((string) $showcaseAchievement['awarded_at']) : t('achievements.unlocked')) ?></small></span>
                            </button>
                        <?php endforeach; ?>
                        <?php if (array_intersect($customWidgetAchievementIds, array_keys($profileAchievementById)) === []): ?>
                            <p class="muted panel-inline-empty"><?= e(t('profile.widget_no_achievements')) ?></p>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <?php if ($customWidgetMediaUrl !== '' && str_starts_with($customWidgetMediaMime, 'image/')): ?>
                        <div class="profile-showcase-media"><img src="<?= e($customWidgetMediaUrl) ?>" alt="<?= e((string) ($profileCustomWidget['title'] ?? '')) ?>" loading="lazy"></div>
                    <?php elseif ($customWidgetMediaUrl !== '' && str_starts_with($customWidgetMediaMime, 'video/')): ?>
                        <div class="profile-showcase-media"><video src="<?= e($customWidgetMediaUrl) ?>" controls playsinline preload="metadata"></video></div>
                    <?php elseif ($customWidgetEmbedUrl !== ''): ?>
                        <div class="profile-showcase-media profile-showcase-video"><iframe src="<?= e($customWidgetEmbedUrl) ?>" title="<?= e((string) ($profileCustomWidget['title'] ?? '')) ?>" loading="lazy" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe></div>
                    <?php elseif ($customWidgetExternalIsImage): ?>
                        <div class="profile-showcase-media"><img src="<?= e($customWidgetExternalUrl) ?>" alt="<?= e((string) ($profileCustomWidget['title'] ?? '')) ?>" loading="lazy" referrerpolicy="no-referrer"></div>
                    <?php elseif ($customWidgetExternalIsVideo): ?>
                        <div class="profile-showcase-media"><video src="<?= e($customWidgetExternalUrl) ?>" controls playsinline preload="metadata"></video></div>
                    <?php elseif ($customWidgetExternalUrl !== ''): ?>
                        <a class="profile-showcase-external" href="<?= e($customWidgetExternalUrl) ?>" target="_blank" rel="noopener noreferrer"><span aria-hidden="true">↗</span><strong><?= e(t('profile.widget_open_media')) ?></strong></a>
                    <?php endif; ?>
                <?php endif; ?>

                <?php $customWidgetLinkUrl = profile_custom_widget_url((string) ($profileCustomWidget['link_url'] ?? '')); ?>
                <?php if ($customWidgetLinkUrl !== ''): ?>
                    <a class="profile-showcase-link" href="<?= e($customWidgetLinkUrl) ?>" target="_blank" rel="noopener noreferrer"><?= e(t('profile.widget_visit_link')) ?> <span aria-hidden="true">↗</span></a>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>

        <?php if ($isOwnProfile): ?>
            <div class="profile-home-block-wrap" data-profile-block="duels" style="<?= e($profileBlockStyle('duels')) ?>">
            <?php
            $pDuels = (array) ($profileDuelsSummary ?? []);
            echo render_status_summary_card(
                t('nav.duels'),
                t('nav.duels'),
                [
                    ['label' => t('common.active'), 'value' => (int) ($pDuels['active'] ?? 0), 'tone' => 'active'],
                    ['label' => t('common.pending'), 'value' => (int) ($pDuels['pending'] ?? 0), 'tone' => 'pending'],
                    ['label' => t('common.won'), 'value' => (int) ($pDuels['won'] ?? 0), 'tone' => 'won'],
                ],
                '/?page=duels',
                t('common.view_all'),
                'profile-home-card'
            );
            ?>
            </div>
            <div class="profile-home-block-wrap" data-profile-block="competitions" style="<?= e($profileBlockStyle('competitions')) ?>">
            <?php
            $pComps = (array) ($profileCompetitionsSummary ?? []);
            echo render_status_summary_card(
                t('nav.competitions'),
                t('nav.competitions'),
                [
                    ['label' => t('common.active'), 'value' => (int) ($pComps['active'] ?? 0), 'tone' => 'active'],
                    ['label' => t('common.pending'), 'value' => (int) ($pComps['pending'] ?? 0), 'tone' => 'pending'],
                    ['label' => t('common.won'), 'value' => (int) ($pComps['won'] ?? 0), 'tone' => 'won'],
                ],
                '/?page=competitions',
                t('common.view_all'),
                'profile-home-card'
            );
            ?>
            </div>
        <?php endif; ?>

        <?php if ($isOwnProfile): ?>
        <article class="panel profile-home-card profile-current-setup-card compact-panel glass-panel" data-profile-block="setup" data-profile-collapsible="<?= e($profileCollapseStateKey('setup')) ?>" style="<?= e($profileBlockStyle('setup')) ?>">
            <div class="profile-home-card-head">
                <div>
                    <p class="eyebrow"><?= e(t('profile.current_config')) ?></p>
                    <h2><?= e(t('profile.current_config')) ?></h2>
                </div>
                <div class="profile-panel-head-actions"><?php if ($canEditProfile): ?><a class="btn btn-ghost small" href="<?= e($profileUrl('config', ['edit' => 1])) ?>" data-spa-link><?= e(t('common.edit')) ?></a><?php endif; ?><?= $profileCollapseControl() ?></div>
            </div>
            <div class="profile-current-primary">
                <span><?= e(t('settings.primary_goal')) ?></span>
                <strong>
                    <span><?= e($profilePrimaryGoalLabel) ?></span>
                    <b><?= e($profilePrimaryGoalValueDisplay) ?></b>
                </strong>
                <small><?= e(count($profilePrimaryGoalsParsed) > 0 ? t('settings.primary_goals_spec') : t('settings.no_extra_goals')) ?></small>
            </div>
            <div class="profile-current-goals">
                <span><?= e(t('settings.primary_goals_spec')) ?></span>
                <div class="profile-current-goal-chips">
                    <?php foreach ($profileCurrentGoalChips as $goalChip): ?>
                        <span aria-label="<?= e((string) $goalChip['value'] . ' ' . (string) $goalChip['label']) ?>"><strong><?= e((string) $goalChip['value']) ?></strong><small><?= e((string) $goalChip['label']) ?></small></span>
                    <?php endforeach; ?>
                    <?php if ($profileCurrentGoalChips === []): ?>
                        <span class="is-empty"><?= e(t('settings.no_extra_goals')) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <dl class="profile-home-facts">
                <?php foreach ($profileSetupVisibleRows as $row): ?>
                    <div>
                        <dt><?= e((string) $row['label']) ?></dt>
                        <dd><?= e((string) $row['value']) ?></dd>
                    </div>
                <?php endforeach; ?>
            </dl>
            <?php if ($profileSetupMoreRows !== []): ?>
                <details class="profile-setup-more" data-persist-disclosure="<?= e($profileCollapseStateKey('setup-more')) ?>">
                    <summary><span><?= e(t('profile.show_more')) ?></span><span><?= e(t('profile.show_less')) ?></span></summary>
                    <dl class="profile-home-facts">
                        <?php foreach ($profileSetupMoreRows as $row): ?>
                            <div>
                                <dt><?= e((string) $row['label']) ?></dt>
                                <dd><?= e((string) $row['value']) ?></dd>
                            </div>
                        <?php endforeach; ?>
                    </dl>
                </details>
            <?php endif; ?>
        </article>

        <article class="panel profile-home-card compact-panel glass-panel" data-profile-block="activity" data-profile-collapsible="<?= e($profileCollapseStateKey('activity')) ?>" style="<?= e($profileBlockStyle('activity')) ?>">
            <div class="profile-home-card-head">
                <div>
                    <p class="eyebrow"><?= e((string) $activityCount) ?> events</p>
                    <h2><?= e(t('profile.recent_activity')) ?></h2>
                </div>
                <div class="profile-panel-head-actions"><a class="btn btn-ghost small" href="<?= e($profileUrl('activity')) ?>" data-spa-link><?= e(t('common.view_all')) ?></a><?= $profileCollapseControl() ?></div>
            </div>
            <?php
            $humanActivity = [];
            foreach ($latestActivity as $item) {
                $h = humanize_activity_item((array) $item, (int) ($currentUser['id'] ?? 0));
                if ($h !== null) {
                    $humanActivity[] = $h;
                }
            }
            ?>
            <?php if ($humanActivity === []): ?>
                <div class="empty-state empty-state-compact">
                    <span class="empty-state-icon"><?= activity_icon_svg('spark') ?></span>
                    <p class="muted"><?= e(t('activity.empty')) ?></p>
                </div>
            <?php else: ?>
                <ul class="activity-feed">
                    <?php foreach ($humanActivity as $h): ?>
                        <li class="activity-item">
                            <span class="activity-item-icon"><?= activity_icon_svg((string) $h['icon']) ?></span>
                            <span class="activity-item-body">
                                <strong><?= e((string) $h['text']) ?></strong>
                                <?php if ((string) $h['when'] !== ''): ?><span class="activity-item-when"><?= e((string) $h['when']) ?></span><?php endif; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </article>
        <?php endif; ?>
    </section>

    <?php
    $profileGoalRows = array_values((array) ($personalGoals ?? []));
    $profileGoalStatusCounts = ['active' => 0, 'complete' => 0];
    foreach ($profileGoalRows as $profileGoalRow) {
        $profileGoalRowStatus = (string) ($profileGoalRow['status'] ?? 'active');
        if ($profileGoalRowStatus === 'complete') {
            ++$profileGoalStatusCounts['complete'];
        } elseif ($profileGoalRowStatus === 'active') {
            ++$profileGoalStatusCounts['active'];
        }
    }
    ?>
    <article class="panel settings-panel profile-native-section profile-goals-section<?= $activeSection === 'goals' ? ' active' : '' ?>" data-spa-section="goals" <?= $activeSection === 'goals' ? '' : 'hidden' ?>>
        <div class="stack profile-section-list" data-spa-show-when-no-param="goal_id,goal_new" <?= $goalCreateMode || $goalDetailId > 0 ? 'hidden' : '' ?>>
            <div class="panel-head profile-section-header">
                <a class="hierarchy-back destination-back profile-section-back profile-goals-back" href="<?= e($profileUrl()) ?>" data-spa-back aria-label="<?= e(t('common.back')) ?>: <?= e(t('nav.profile')) ?>">
                    <span aria-hidden="true">&larr;</span><strong><?= e(t('nav.profile')) ?></strong>
                </a>
                <div class="profile-section-heading"><p class="eyebrow"><?= e(t('nav.profile')) ?></p><h2 data-navigation-focus tabindex="-1"><?= e(t('profile.mobile_goals')) ?></h2></div>
                <?php if ($canEditProfile): ?>
                    <a class="btn btn-primary profile-section-action profile-goal-new-button" href="<?= e($profileUrl('goals', ['goal_new' => 1])) ?>" data-spa-link>
                        <span aria-hidden="true">+</span><strong><?= e(t('profile.new_goal')) ?></strong>
                    </a>
                <?php endif; ?>
            </div>

            <?php if (count($profileGoalRows) >= 5): ?>
                <div class="profile-goals-toolbar" data-profile-goal-toolbar>
                    <label class="profile-goals-search">
                        <span aria-hidden="true"><?= activity_icon_svg('search') ?></span>
                        <span class="sr-only"><?= e(t('profile.search_goals')) ?></span>
                        <input type="search" autocomplete="off" placeholder="<?= e(t('profile.search_goals')) ?>" data-profile-goal-search>
                    </label>
                    <div class="profile-goal-filters" role="group" aria-label="<?= e(t('common.status')) ?>">
                        <button type="button" data-profile-goal-filter="all" aria-pressed="true"><?= e(t('profile.all_goals')) ?><b><?= count($profileGoalRows) ?></b></button>
                        <button type="button" data-profile-goal-filter="active" aria-pressed="false"><?= e(t('common.active')) ?><b><?= $profileGoalStatusCounts['active'] ?></b></button>
                        <button type="button" data-profile-goal-filter="complete" aria-pressed="false"><?= e(t('common.complete')) ?><b><?= $profileGoalStatusCounts['complete'] ?></b></button>
                    </div>
                </div>
            <?php endif; ?>

            <div class="profile-goals-grid" data-profile-goals-list>
                <?php if ($profileGoalRows === []): ?>
                    <p class="muted panel-inline-empty"><?= e(t('goals.empty')) ?></p>
                <?php else: ?>
                    <?php foreach ($profileGoalRows as $goal): ?>
                        <?php $goalType = normalize_goal_target_type((string) ($goal['target_type'] ?? 'custom')); ?>
                        <?php $goalTarget = (float) ($goal['target_value'] ?? 0); ?>
                        <?php $goalCurrent = $goalCurrentValue($goal); ?>
                        <?php $goalProgress = $goalProgressPercent($goal, $goalCurrent); ?>
                        <?php $goalRowStatus = (string) ($goal['status'] ?? 'active'); ?>
                        <a class="profile-goal-list-row is-<?= e($goalRowStatus) ?>" href="<?= e($profileUrl('goals', ['goal_id' => (int) $goal['id']])) ?>" data-spa-link data-profile-goal-item data-goal-status="<?= e($goalRowStatus) ?>" data-goal-title="<?= e((string) $goal['title']) ?>">
                            <span class="profile-goal-list-icon" aria-hidden="true"><?= activity_icon_svg($goalRowStatus === 'complete' ? 'check' : 'target') ?></span>
                            <span class="profile-goal-list-copy">
                                <strong><?= e((string) $goal['title']) ?></strong>
                                <small><?= e($goalTypeLabel((string) ($goal['target_type'] ?? ''))) ?> · <?= e($formatGoalValue($goalCurrent, $goalType)) ?> / <?= e($formatGoalValue($goalTarget, $goalType)) ?></small>
                                <span class="profile-goal-mini-progress" aria-hidden="true"><i style="width: <?= e((string) $goalProgress) ?>%"></i></span>
                            </span>
                            <span class="profile-goal-list-meta">
                                <strong><?= e((string) round($goalProgress)) ?>%</strong>
                                <small><?= e((string) ($goal['due_date'] ?? '') !== '' ? format_date_eu((string) $goal['due_date']) : $goalStatusLabel($goalRowStatus)) ?></small>
                            </span>
                            <span class="settings-chevron" aria-hidden="true">›</span>
                        </a>
                    <?php endforeach; ?>
                    <p class="muted panel-inline-empty profile-goals-filter-empty" hidden data-profile-goal-empty><?= e(t('profile.no_goals_match')) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($canEditProfile): ?>
            <div class="stack goal-subview profile-create-view" data-spa-param-show="goal_new" data-spa-value="1" <?= $goalCreateMode ? '' : 'hidden' ?>>
                <div class="panel-head compact-head profile-section-header">
                    <a class="hierarchy-back destination-back profile-section-back profile-goals-back" href="<?= e($profileUrl('goals')) ?>" data-spa-back aria-label="<?= e(t('common.back')) ?>: <?= e(t('profile.mobile_goals')) ?>">
                        <span aria-hidden="true">&larr;</span><strong><?= e(t('profile.mobile_goals')) ?></strong>
                    </a>
                    <div class="profile-section-heading"><p class="eyebrow"><?= e(t('profile.mobile_goals')) ?></p><h3 data-navigation-focus tabindex="-1"><?= e(t('profile.new_goal')) ?></h3></div>
                </div>
                <div class="profile-goal-form-card">
                    <div class="profile-goal-form-intro">
                        <span aria-hidden="true"><?= activity_icon_svg('target') ?></span>
                        <div><strong><?= e(t('profile.goal_create_title')) ?></strong><small><?= e(t('profile.goal_create_hint')) ?></small></div>
                    </div>
                    <form method="post" action="<?= e($profileUrl('goals')) ?>" class="profile-goal-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="create_goal">
                        <input type="hidden" name="profile_user_id" value="<?= (int) $profileUser['id'] ?>">
                        <div class="profile-goal-form-grid">
                            <label class="profile-goal-title-field"><span><?= e(t('goals.goal_name')) ?></span><input type="text" name="title" placeholder="<?= e(t('goals.placeholder')) ?>" maxlength="120" required></label>
                            <?php if ($profileGoalTeamsList !== []): ?>
                                <label class="profile-goal-scope-field">
                                    <span><?= e(t('goals.scope')) ?></span>
                                    <select name="goal_team_id">
                                        <option value="0"><?= e(t('goals.personal')) ?></option>
                                        <?php foreach ($profileGoalTeamsList as $profileGoalTeam): ?>
                                            <option value="<?= (int) ($profileGoalTeam['id'] ?? 0) ?>"><?= e(t('goals.team')) ?> · <?= e((string) ($profileGoalTeam['name'] ?? '')) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                            <?php endif; ?>
                            <label><span><?= e(t('goals.type')) ?></span><select name="target_type">
                                <?php foreach ($goalTypeOptions as $option): ?>
                                    <option value="<?= e((string) $option['value']) ?>"><?= e((string) $option['label']) ?></option>
                                <?php endforeach; ?>
                            </select></label>
                            <label><span><?= e(t('goals.target')) ?></span><input type="number" min="0" step="0.1" name="target_value" required></label>
                            <label><span><?= e(t('goals.due_date')) ?></span><input type="date" name="due_date" min="<?= e($profileTodayDate) ?>"></label>
                        </div>
                        <div class="profile-goal-form-actions">
                            <a class="btn btn-ghost" href="<?= e($profileUrl('goals')) ?>" data-spa-link><?= e(t('common.cancel')) ?></a>
                            <button class="btn btn-primary" type="submit"><span aria-hidden="true">+</span><?= e(t('profile.create_goal_action')) ?></button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php foreach (($personalGoals ?? []) as $goal): ?>
            <?php $isActiveGoalDetail = $goalDetailId === (int) $goal['id']; ?>
            <?php $editFormId = 'goal-edit-form-' . (int) $goal['id']; ?>
            <?php $goalRawType = (string) ($goal['target_type'] ?? 'custom'); ?>
            <?php $goalType = normalize_goal_target_type($goalRawType); ?>
            <?php $goalTarget = (float) ($goal['target_value'] ?? 0); ?>
            <?php $goalCurrent = $goalCurrentValue($goal); ?>
            <?php $goalProgress = $goalProgressPercent($goal, $goalCurrent); ?>
            <?php $goalDueDate = (string) ($goal['due_date'] ?? ''); ?>
            <?php $goalDetailStatus = (string) ($goal['status'] ?? 'active'); ?>
            <div class="stack goal-subview profile-detail-view" data-spa-param-show="goal_id" data-spa-value="<?= (int) $goal['id'] ?>" <?= $isActiveGoalDetail ? '' : 'hidden' ?>>
                <div class="panel-head compact-head profile-section-header">
                    <a class="hierarchy-back destination-back profile-section-back profile-goals-back" href="<?= e($profileUrl('goals')) ?>" data-spa-back data-spa-history aria-label="<?= e(t('common.back')) ?>: <?= e(t('profile.mobile_goals')) ?>">
                        <span aria-hidden="true">&larr;</span><strong><?= e(t('profile.mobile_goals')) ?></strong>
                    </a>
                    <div class="profile-section-heading"><p class="eyebrow"><?= e(t('profile.mobile_goals')) ?></p><h3 data-navigation-focus tabindex="-1"><?= e((string) $goal['title']) ?></h3></div>
                </div>

                <article class="mini-card goal-detail-card goal-detail-summary is-<?= e($goalDetailStatus) ?>">
                    <div class="profile-goal-detail-overview">
                        <span class="profile-goal-detail-icon" aria-hidden="true"><?= activity_icon_svg($goalDetailStatus === 'complete' ? 'check' : 'target') ?></span>
                        <div class="profile-goal-detail-copy">
                            <span><b><?= e($goalTypeLabel($goalRawType)) ?></b><i aria-hidden="true">·</i><em class="goal-status-chip"><?= e($goalStatusLabel($goalDetailStatus)) ?></em></span>
                            <strong><?= e($formatGoalValue($goalCurrent, $goalType)) ?> <small>/ <?= e($formatGoalValue($goalTarget, $goalType)) ?></small></strong>
                            <div class="goal-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= e((string) $goalProgress) ?>" aria-label="<?= e(t('profile.current_progress')) ?>"><span style="width: <?= e((string) $goalProgress) ?>%"></span></div>
                        </div>
                        <strong class="profile-goal-detail-percent"><?= e((string) round($goalProgress)) ?><small>%</small></strong>
                    </div>
                    <?php if ($goalDueDate !== ''): ?>
                        <div class="profile-goal-detail-due"><span aria-hidden="true">&#128197;</span><small><?= e(t('goals.due_date')) ?></small><strong><?= e(format_date_eu($goalDueDate)) ?></strong></div>
                    <?php endif; ?>
                    <?php if ($canEditProfile): ?>
                        <div class="goal-detail-actions">
                            <?php // Editing opens in a modal. It used to unfold inside the goal card,
                                  // which shoved the rest of the page down and left you editing in the
                                  // middle of the thing you were reading. ?>
                            <button class="btn small btn-ghost" type="button" data-app-modal-open="goal-edit-modal-<?= (int) $goal['id'] ?>" aria-haspopup="dialog"><span aria-hidden="true"><?= activity_icon_svg('sliders') ?></span><?= e(t('common.edit')) ?></button>
                            <?php if ($goalDetailStatus !== 'complete'): ?>
                                <form method="post" action="<?= e($profileUrl('goals')) ?>">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="goal_status">
                                    <input type="hidden" name="goal_id" value="<?= (int) $goal['id'] ?>">
                                    <input type="hidden" name="status" value="complete">
                                    <input type="hidden" name="profile_user_id" value="<?= (int) $profileUser['id'] ?>">
                                    <button class="btn small btn-ghost" type="submit"><span aria-hidden="true"><?= activity_icon_svg('check') ?></span><?= e(t('common.complete')) ?></button>
                                </form>
                            <?php endif; ?>
                            <form method="post" action="<?= e($profileUrl('goals')) ?>">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete_goal">
                                <input type="hidden" name="goal_id" value="<?= (int) $goal['id'] ?>">
                                <input type="hidden" name="profile_user_id" value="<?= (int) $profileUser['id'] ?>">
                                <button class="btn small btn-ghost" type="submit" data-goal-delete-confirm><?= e(t('common.delete')) ?></button>
                            </form>
                        </div>
                    <?php endif; ?>
                </article>

                <?php if ($canEditProfile): ?>
                <div class="app-modal profile-goal-modal" id="goal-edit-modal-<?= (int) $goal['id'] ?>" hidden role="dialog" aria-modal="true" aria-labelledby="goal-edit-title-<?= (int) $goal['id'] ?>">
                    <div class="app-modal-card">
                        <div class="app-modal-head">
                            <div>
                                <p class="eyebrow"><?= e(t('goals.personal')) ?></p>
                                <h2 id="goal-edit-title-<?= (int) $goal['id'] ?>"><?= e((string) $goal['title']) ?></h2>
                                <small><?= e(t('profile.goal_edit_hint')) ?></small>
                            </div>
                            <button type="button" class="app-modal-close" data-app-modal-close aria-label="<?= e(t('common.cancel')) ?>">&times;</button>
                        </div>
                    <div class="profile-goal-editor-context">
                        <span aria-hidden="true"><?= activity_icon_svg('target') ?></span>
                        <div><small><?= e(t('profile.current_progress')) ?></small><strong><?= e($formatGoalValue($goalCurrent, $goalType)) ?> / <?= e($formatGoalValue($goalTarget, $goalType)) ?> · <?= e($goalTypeLabel($goalRawType)) ?></strong></div>
                        <b><?= e((string) round($goalProgress)) ?>%</b>
                    </div>
                    <form method="post" action="<?= e($profileUrl('goals')) ?>" class="goal-editor profile-goal-form" id="<?= e($editFormId) ?>" data-goal-edit-form>
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="update_goal">
                        <input type="hidden" name="goal_id" value="<?= (int) $goal['id'] ?>">
                        <input type="hidden" name="profile_user_id" value="<?= (int) $profileUser['id'] ?>">
                        <div class="goal-editor-grid profile-goal-form-grid">
                            <label class="profile-goal-title-field"><span><?= e(t('goals.goal_name')) ?></span><input type="text" name="title" value="<?= e((string) $goal['title']) ?>" maxlength="120" required></label>
                            <label>
                                <span><?= e(t('goals.type')) ?></span>
                                <select name="target_type">
                                    <?php
                                    $hasCurrentOption = false;
                                    foreach ($goalTypeOptions as $option):
                                        $selected = (string) $option['value'] === $goalType;
                                        if ($selected) {
                                            $hasCurrentOption = true;
                                        }
                                        ?>
                                        <option value="<?= e((string) $option['value']) ?>" <?= $selected ? 'selected' : '' ?>><?= e((string) $option['label']) ?></option>
                                    <?php endforeach; ?>
                                    <?php if (!$hasCurrentOption): ?>
                                        <option value="<?= e($goalRawType) ?>" selected><?= e($goalTypeLabel($goalRawType)) ?> (actual)</option>
                                    <?php endif; ?>
                                </select>
                            </label>
                            <label><span><?= e(t('goals.target')) ?></span><input type="number" min="0" step="0.1" name="target_value" value="<?= e((string) ($goal['target_value'] ?? '')) ?>" required></label>
                            <label><span><?= e(t('goals.due_date')) ?></span><input type="date" name="due_date" value="<?= e((string) ($goal['due_date'] ?? '')) ?>"></label>
                        </div>
                        <div class="goal-editor-actions profile-goal-form-actions">
                            <button class="btn btn-ghost" type="button" data-app-modal-close><?= e(t('common.cancel')) ?></button>
                            <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
                        </div>
                    </form>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </article>

    <?php if ($canViewProfileWorkouts): ?>
    <article class="panel settings-panel profile-native-section<?= $activeSection === 'training' ? ' active' : '' ?>" data-spa-section="training" <?= $activeSection === 'training' ? '' : 'hidden' ?>>
        <div class="panel-head profile-native-section-head profile-section-header"><a class="hierarchy-back destination-back profile-section-back" href="<?= e($profileUrl()) ?>" data-spa-back aria-label="<?= e(t('common.back')) ?>: <?= e(t('nav.profile')) ?>"><span aria-hidden="true">&larr;</span><strong><?= e(t('nav.profile')) ?></strong></a><div class="profile-section-heading"><p class="eyebrow"><?= e(t('nav.profile')) ?></p><h2 data-navigation-focus tabindex="-1"><?= e(t('profile.mobile_training')) ?></h2></div></div>
        <div class="mobile-kpi-grid">
            <div><small><?= e(t('dashboard.training_rank_title')) ?></small><strong><?= e(t('workouts.rank_' . $profileTrainingRankKey)) ?></strong><span><?= e(number_format((float) ($profileTrainingRank['score'] ?? 0), 1, '.', '')) ?> <?= e(t('workouts.lift_points')) ?></span></div>
            <div><small><?= e(t('workouts.stat_sessions')) ?> · <?= e(t('workouts.this_month')) ?></small><strong><?= (int) ($profileTrainingMonth['sessions'] ?? 0) ?></strong><span><?= e(number_format((float) ($profileTrainingMonth['volume'] ?? 0), 0, '.', ' ')) ?> kg</span></div>
            <div><small><?= e(t('workouts.streak')) ?></small><strong><?= (int) ($profileTrainingStreak ?? 0) ?></strong><span><?= e(t('common.days')) ?></span></div>
            <div><small><?= e(t('workouts.stat_reps')) ?> · <?= e(t('workouts.all_time')) ?></small><strong><?= e(number_format((int) ($profileTrainingAll['reps'] ?? 0), 0, '.', ' ')) ?></strong><span><?= e(t('workouts.stat_sets')) ?> <?= e(number_format((int) ($profileTrainingAll['sets'] ?? 0), 0, '.', ' ')) ?></span></div>
        </div>
        <nav class="hierarchy-nav-list">
            <a class="hierarchy-nav-row" href="/?page=workouts&view=ranks"><span class="hierarchy-nav-icon" aria-hidden="true"><?= activity_icon_svg('trophy') ?></span><span class="hierarchy-nav-copy"><strong><?= e(t('workouts.tab_ranks')) ?></strong><small><?= e(t('workouts.rank_subtitle')) ?></small></span><span class="hierarchy-nav-chevron" aria-hidden="true">&rsaquo;</span></a>
            <a class="hierarchy-nav-row" href="/?page=workouts&view=stats"><span class="hierarchy-nav-icon" aria-hidden="true"><?= activity_icon_svg('run') ?></span><span class="hierarchy-nav-copy"><strong><?= e(t('workouts.stats')) ?></strong><small><?= e(t('workouts.stats_subtitle')) ?></small></span><span class="hierarchy-nav-chevron" aria-hidden="true">&rsaquo;</span></a>
        </nav>
        <?php if ($profileTrainingRecentSessions !== []): ?><article class="native-list-card"><h3><?= e(t('workouts.recent_sessions')) ?></h3><?php foreach ($profileTrainingRecentSessions as $session): ?><div class="native-list-row"><span><strong><?= e((string) (($session['title'] ?? '') !== '' ? $session['title'] : t('workouts.session'))) ?></strong><small><?= e(human_time_ago((string) ($session['started_at'] ?? ''))) ?></small></span><strong><?= e(number_format((float) ($session['total_volume'] ?? 0), 0, '.', ' ')) ?> kg</strong></div><?php endforeach; ?></article><?php endif; ?>
    </article>
    <?php endif; ?>

    <article class="panel settings-panel profile-native-section<?= $activeSection === 'social' ? ' active' : '' ?>" data-spa-section="social" <?= $activeSection === 'social' ? '' : 'hidden' ?>>
        <div class="panel-head profile-native-section-head profile-section-header"><a class="hierarchy-back destination-back profile-section-back" href="<?= e($profileUrl()) ?>" data-spa-back aria-label="<?= e(t('common.back')) ?>: <?= e(t('nav.profile')) ?>"><span aria-hidden="true">&larr;</span><strong><?= e(t('nav.profile')) ?></strong></a><div class="profile-section-heading"><p class="eyebrow"><?= e(t('nav.profile')) ?></p><h2 data-navigation-focus tabindex="-1"><?= e(t('profile.mobile_social')) ?></h2></div></div>
        <div class="hierarchy-status-strip"><span><strong><?= count($profileFriends) ?></strong><small><?= e(t('nav.friends')) ?></small></span><span><strong><?= count((array) ($profileTeams ?? [])) ?></strong><small><?= e(t('social_hub.teams')) ?></small></span><span><strong><?= (int) (($profileDuelsSummary ?? [])['won'] ?? 0) + (int) (($profileCompetitionsSummary ?? [])['won'] ?? 0) ?></strong><small><?= e(t('common.won')) ?></small></span></div>
        <nav class="hierarchy-nav-list">
            <a class="hierarchy-nav-row" href="/?page=friends"><span class="hierarchy-nav-icon" aria-hidden="true"><?= activity_icon_svg('users') ?></span><span class="hierarchy-nav-copy"><strong><?= e(t('nav.friends')) ?></strong><small><?= e(t('social_hub.friends_hint')) ?></small></span><span class="hierarchy-nav-meta"><?= count($profileFriends) ?></span><span class="hierarchy-nav-chevron" aria-hidden="true">&rsaquo;</span></a>
            <a class="hierarchy-nav-row" href="/?page=duels"><span class="hierarchy-nav-icon" aria-hidden="true"><?= activity_icon_svg('sword') ?></span><span class="hierarchy-nav-copy"><strong><?= e(t('nav.duels')) ?></strong><small><?= e(t('social_hub.duels_hint')) ?></small></span><span class="hierarchy-nav-meta"><?= (int) (($profileDuelsSummary ?? [])['active'] ?? 0) ?></span><span class="hierarchy-nav-chevron" aria-hidden="true">&rsaquo;</span></a>
            <a class="hierarchy-nav-row" href="/?page=competitions"><span class="hierarchy-nav-icon" aria-hidden="true"><?= activity_icon_svg('trophy') ?></span><span class="hierarchy-nav-copy"><strong><?= e(t('nav.competitions')) ?></strong><small><?= e(t('social_hub.competitions_hint')) ?></small></span><span class="hierarchy-nav-meta"><?= (int) (($profileCompetitionsSummary ?? [])['active'] ?? 0) ?></span><span class="hierarchy-nav-chevron" aria-hidden="true">&rsaquo;</span></a>
            <a class="hierarchy-nav-row" href="/?page=team"><span class="hierarchy-nav-icon" aria-hidden="true"><?= activity_icon_svg('users') ?></span><span class="hierarchy-nav-copy"><strong><?= e(t('nav.team')) ?></strong><small><?= e(t('social_hub.team_hint')) ?></small></span><span class="hierarchy-nav-meta"><?= count((array) ($profileTeams ?? [])) ?></span><span class="hierarchy-nav-chevron" aria-hidden="true">&rsaquo;</span></a>
        </nav>
    </article>

    <article class="panel settings-panel profile-native-section profile-achievements-panel<?= $activeSection === 'achievements' ? ' active' : '' ?>" data-spa-section="achievements" <?= $activeSection === 'achievements' ? '' : 'hidden' ?>>
        <div class="panel-head profile-section-header">
            <a class="hierarchy-back destination-back profile-section-back" href="<?= e($profileUrl()) ?>" data-spa-back aria-label="<?= e(t('common.back')) ?>: <?= e(t('nav.profile')) ?>"><span aria-hidden="true">&larr;</span><strong><?= e(t('nav.profile')) ?></strong></a>
            <div class="profile-section-heading"><p class="eyebrow"><?= e(t('nav.profile')) ?></p><h2 data-navigation-focus tabindex="-1"><?= e(t('profile.achievements')) ?></h2></div>
        </div>
        <div class="profile-achievement-collection-summary">
            <span><strong><?= count((array) ($userAchievements ?? [])) ?></strong><small><?= e(t('achievements.unlocked')) ?></small></span>
            <p><?= e(t('profile.achievement_collection_hint')) ?></p>
        </div>
        <div class="achievement-grid profile-achievement-collection" data-achievement-grid>
            <?php if (($userAchievements ?? []) === []): ?>
                <p class="muted"><?= e(t('achievements.empty')) ?></p>
            <?php else: ?>
                <?php foreach ($userAchievements as $achievementIndex => $achievement): ?>
                    <article class="achievement-card profile-achievement-card" <?= achievement_modal_attrs($achievement) ?>>
                        <?= achievement_visual_html($achievement, 'achievement-visual profile-achievement-media') ?>
                        <div class="profile-achievement-content">
                            <strong><?= e((string) $achievement['name']) ?></strong>
                            <p><?= e((string) $achievement['description']) ?></p>
                            <div class="achievement-card-meta">
                                <?php if (!empty($achievement['reward_text'])): ?>
                                    <span class="achievement-chip"><?= e(t('achievements.reward')) ?>: <?= e((string) $achievement['reward_text']) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($achievement['awarded_at'])): ?>
                                    <span class="achievement-chip achievement-unlocked-date"><?= e(t('profile.unlocked_on')) ?> <?= e(format_date_eu((string) $achievement['awarded_at'])) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </article>

    <?php if ($isOwnProfile): ?>
    <article class="panel settings-panel profile-native-section<?= $activeSection === 'config' ? ' active' : '' ?>" data-spa-section="config" <?= $activeSection === 'config' ? '' : 'hidden' ?>>
        <div class="panel-head profile-section-header">
            <a class="hierarchy-back destination-back profile-section-back" href="<?= e($profileUrl()) ?>" data-spa-back aria-label="<?= e(t('common.back')) ?>: <?= e(t('nav.profile')) ?>"><span aria-hidden="true">&larr;</span><strong><?= e(t('nav.profile')) ?></strong></a>
            <div class="profile-section-heading"><p class="eyebrow"><?= e(t('nav.profile')) ?></p><h2 data-navigation-focus tabindex="-1"><?= e(t('profile.current_config')) ?></h2></div>
            <?php if ($canEditProfile): ?>
                <a class="btn btn-ghost profile-section-action profile-config-edit-action" href="<?= e($profileUrl('config', ['edit' => 1])) ?>" data-config-edit-link aria-label="<?= e(t('common.edit')) ?>">
                    <span aria-hidden="true"><?= activity_icon_svg('sliders') ?></span><strong><?= e(t('common.edit')) ?></strong>
                </a>
            <?php endif; ?>
        </div>

        <div class="profile-config" data-config-editor>
            <div class="profile-config-readonly" data-config-readonly data-spa-show-when-no-param="edit" <?= $configEditMode ? 'hidden' : '' ?>>
                <div class="profile-current-primary">
                    <span><?= e(t('settings.primary_goal')) ?></span>
                    <strong>
                        <span><?= e($profilePrimaryGoalLabel) ?></span>
                        <b><?= e($profilePrimaryGoalValueDisplay) ?></b>
                    </strong>
                    <small><?= e(count($profilePrimaryGoalsParsed) > 0 ? t('settings.primary_goals_spec') : t('settings.no_extra_goals')) ?></small>
                </div>
                <div class="profile-current-goals">
                    <span><?= e(t('settings.primary_goals_spec')) ?></span>
                    <div class="profile-current-goal-chips">
                        <?php foreach ($profileCurrentGoalChips as $goalChip): ?>
                            <span aria-label="<?= e((string) $goalChip['value'] . ' ' . (string) $goalChip['label']) ?>"><strong><?= e((string) $goalChip['value']) ?></strong><small><?= e((string) $goalChip['label']) ?></small></span>
                        <?php endforeach; ?>
                        <?php if ($profileCurrentGoalChips === []): ?>
                            <span class="is-empty"><?= e(t('settings.no_extra_goals')) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <dl class="profile-home-facts profile-config-facts">
                    <?php foreach ($profileSetupRows as $row): ?>
                        <div>
                            <dt><?= e((string) $row['label']) ?></dt>
                            <dd><?= e((string) $row['value']) ?></dd>
                        </div>
                    <?php endforeach; ?>
                </dl>
            </div>

            <?php if ($isOwnProfile && $canEditProfile): ?>
                <?php $currentVisibility = function_exists('privacy_normalize') ? privacy_normalize((string) ($profileUser['profile_visibility'] ?? 'public')) : 'public'; ?>
                <form method="post" action="<?= e($profileUrl('config')) ?>" class="stack profile-privacy-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="update_privacy">
                    <div class="profile-privacy-head">
                        <h3><?= e(t('privacy.title')) ?></h3>
                        <p class="muted"><?= e(t('privacy.subtitle')) ?></p>
                    </div>
                    <div class="privacy-options">
                        <?php foreach (['public' => 'privacy.public', 'friends' => 'privacy.friends', 'private' => 'privacy.private'] as $value => $labelKey): ?>
                            <label class="privacy-option<?= $currentVisibility === $value ? ' is-selected' : '' ?>">
                                <input type="radio" name="profile_visibility" value="<?= e($value) ?>" <?= $currentVisibility === $value ? 'checked' : '' ?>>
                                <span class="privacy-option-label"><?= e(t($labelKey)) ?></span>
                                <span class="privacy-option-hint muted"><?= e(t($labelKey . '_hint')) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="inline-actions">
                        <button type="submit" class="btn btn-primary"><?= e(t('common.save')) ?></button>
                    </div>
                </form>
            <?php endif; ?>

            <?php if ($canEditProfile): ?>
                <form method="post" action="<?= e($profileUrl('config')) ?>" class="stack profile-config-form" data-config-form data-spa-param-show="edit" data-spa-value="1" <?= $configEditMode ? '' : 'hidden' ?>>
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="update_profile_config">
                    <input type="hidden" name="profile_user_id" value="<?= (int) $profileUser['id'] ?>">
                    <div class="grid-inline two profile-config-editor-grid">
                        <label><?= e(t('common.username')) ?><input type="text" value="<?= e((string) $profileUser['username']) ?>" disabled></label>
                        <label>
                            <?= e(t('settings.primary_goal')) ?>
                            <select name="primary_goal_type" data-optional-primary-goal>
                                <?php foreach ($primaryGoalOptions as $option): ?>
                                    <option value="<?= e((string) $option['value']) ?>" <?= $profilePrimaryGoalType === (string) $option['value'] ? 'selected' : '' ?>><?= e((string) $option['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label data-optional-primary-value <?= $profilePrimaryGoalType === 'none' ? 'hidden' : '' ?>><?= e(t('settings.primary_goal_value')) ?><input type="number" min="0.1" step="0.1" name="primary_goal_value" value="<?= e((string) ($profileUser['primary_goal_value'] ?? '')) ?>" <?= $profilePrimaryGoalType === 'none' ? 'disabled' : '' ?>></label>
                        <div class="profile-primary-goals-field">
                            <div class="profile-field-head">
                                <span><?= e(t('settings.primary_goals_spec')) ?></span>
                                <small class="muted"><?= e(t('settings.primary_goals_spec_hint')) ?></small>
                            </div>
                            <input type="hidden" name="primary_goals_spec" value="<?= e($profilePrimaryGoalsSpecValue) ?>" data-primary-goals-spec-input>
                            <div class="primary-goals-editor" data-primary-goals-editor>
                                <div class="primary-goals-list" data-primary-goals-list>
                                    <?php foreach ($profilePrimaryGoalsParsed as $goal): ?>
                                        <?php
                                        $goalType = strtolower((string) ($goal['type'] ?? 'steps'));
                                        $goalValue = is_numeric($goal['value'] ?? null) ? (float) $goal['value'] : 0.0;
                                        ?>
                                        <div class="primary-goal-row" data-primary-goal-row>
                                            <label>
                                                <span><?= e(t('settings.primary_goal')) ?></span>
                                                <select data-primary-goal-type aria-label="<?= e(t('settings.primary_goal')) ?>">
                                                    <?php foreach (array_filter($primaryGoalOptions, static fn(array $option): bool => (string) $option['value'] !== 'none') as $option): ?>
                                                        <option value="<?= e((string) $option['value']) ?>" data-step="<?= e((string) $option['step']) ?>" data-placeholder="<?= e((string) $option['placeholder']) ?>" <?= $goalType === (string) $option['value'] ? 'selected' : '' ?>><?= e((string) $option['label']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </label>
                                            <label>
                                                <span><?= e(t('settings.primary_goal_value')) ?></span>
                                                <input type="number" min="0" step="<?= e($goalType === 'km' ? '0.1' : '1') ?>" value="<?= e($goalType === 'km' ? rtrim(rtrim(number_format($goalValue, 2, '.', ''), '0'), '.') : (string) (int) round($goalValue)) ?>" data-primary-goal-value aria-label="<?= e(t('settings.primary_goal_value')) ?>">
                                            </label>
                                            <button class="btn btn-ghost small primary-goal-remove" type="button" data-primary-goal-remove aria-label="<?= e(t('settings.remove_primary_goal')) ?>">&times;</button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <p class="muted small primary-goals-empty" data-primary-goals-empty <?= $profilePrimaryGoalsParsed === [] ? '' : 'hidden' ?>><?= e(t('settings.no_extra_goals')) ?></p>
                                <button class="btn btn-ghost primary-goal-add" type="button" data-primary-goal-add><?= e(t('settings.add_primary_goal')) ?></button>
                                <template data-primary-goal-template>
                                    <div class="primary-goal-row" data-primary-goal-row>
                                        <label>
                                            <span><?= e(t('settings.primary_goal')) ?></span>
                                            <select data-primary-goal-type aria-label="<?= e(t('settings.primary_goal')) ?>">
                                                <?php foreach (array_filter($primaryGoalOptions, static fn(array $option): bool => (string) $option['value'] !== 'none') as $option): ?>
                                                    <option value="<?= e((string) $option['value']) ?>" data-step="<?= e((string) $option['step']) ?>" data-placeholder="<?= e((string) $option['placeholder']) ?>"><?= e((string) $option['label']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label>
                                            <span><?= e(t('settings.primary_goal_value')) ?></span>
                                            <input type="number" min="0" step="1" data-primary-goal-value aria-label="<?= e(t('settings.primary_goal_value')) ?>">
                                        </label>
                                        <button class="btn btn-ghost small primary-goal-remove" type="button" data-primary-goal-remove aria-label="<?= e(t('settings.remove_primary_goal')) ?>">&times;</button>
                                    </div>
                                </template>
                            </div>
                        </div>
                        <label><?= e(t('profile.workout_target')) ?><input type="number" min="0" name="workout_target" value="<?= e((string) ($profileUser['workout_target'] ?? 0)) ?>"></label>
                        <label><?= e(t('profile.maintenance_calories')) ?><input type="number" min="0" step="1" name="maintenance_calories" value="<?= e((string) ($profileUser['maintenance_calories'] ?? '')) ?>"></label>
                        <label><?= e(t('settings.calorie_burn_goal')) ?><input type="number" min="0" step="1" name="calorie_burn_goal" value="<?= e((string) ($profileUser['calorie_burn_goal'] ?? '')) ?>"></label>
                        <label><?= e(t('settings.calorie_consumed_max')) ?><input type="number" min="0" step="1" name="calorie_consumed_max" value="<?= e((string) ($profileUser['calorie_consumed_max'] ?? '')) ?>"></label>
                        <label><?= e(t('metric.ideal_weight')) ?><input type="number" step="0.1" name="ideal_weight" value="<?= e((string) ($profileUser['ideal_weight'] ?? '')) ?>"></label>
                    </div>
                    <div class="goal-editor-actions">
                        <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
                        <a class="btn btn-ghost" href="<?= e($profileUrl('config')) ?>" data-config-cancel-link><?= e(t('common.cancel')) ?></a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </article>

    <article class="panel settings-panel profile-native-section<?= $activeSection === 'activity' ? ' active' : '' ?>" data-spa-section="activity" <?= $activeSection === 'activity' ? '' : 'hidden' ?>>
        <div class="panel-head profile-section-header">
            <a class="hierarchy-back destination-back profile-section-back" href="<?= e($profileUrl()) ?>" data-spa-back aria-label="<?= e(t('common.back')) ?>: <?= e(t('nav.profile')) ?>"><span aria-hidden="true">&larr;</span><strong><?= e(t('nav.profile')) ?></strong></a>
            <div class="profile-section-heading"><p class="eyebrow"><?= e(t('nav.profile')) ?></p><h2 data-navigation-focus tabindex="-1"><?= e(t('profile.recent_activity')) ?></h2></div>
        </div>
        <?php
        $humanActivityFull = [];
        foreach (($recentActivity ?? []) as $item) {
            $h = humanize_activity_item((array) $item, (int) ($currentUser['id'] ?? 0));
            if ($h !== null) {
                $humanActivityFull[] = $h;
            }
        }
        ?>
        <?php if ($humanActivityFull === []): ?>
            <div class="empty-state">
                <span class="empty-state-icon"><?= activity_icon_svg('spark') ?></span>
                <p class="muted"><?= e(t('activity.empty')) ?></p>
            </div>
        <?php else: ?>
            <ul class="activity-feed">
                <?php foreach ($humanActivityFull as $h): ?>
                    <li class="activity-item">
                        <span class="activity-item-icon"><?= activity_icon_svg((string) $h['icon']) ?></span>
                        <span class="activity-item-body">
                            <strong><?= e((string) $h['text']) ?></strong>
                            <?php if ((string) $h['when'] !== ''): ?><span class="activity-item-when"><?= e((string) $h['when']) ?></span><?php endif; ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </article>
    <?php endif; ?>
</section>

<?php $xp = (array) ($profileXp ?? []); ?>
<div class="app-modal" id="profile-level-modal" hidden role="dialog" aria-modal="true" aria-labelledby="profile-level-modal-title">
    <div class="app-modal-card">
        <div class="app-modal-head">
            <div>
                <p class="eyebrow"><?= e(t('xp.level')) ?> <?= (int) ($xp['level'] ?? 1) ?></p>
                <h2 id="profile-level-modal-title"><?= e(t('xp.progress_title')) ?></h2>
            </div>
            <button type="button" class="app-modal-close" data-app-modal-close aria-label="<?= e(t('common.close_action')) ?>">&times;</button>
        </div>
        <div class="level-progress-ring-wrap">
            <div class="profile-xp-bar level-progress-bar-lg"><span style="width: <?= max(0, min(100, (int) ($xp['progress_pct'] ?? 0))) ?>%"></span></div>
            <p class="level-progress-pct"><?= (int) ($xp['progress_pct'] ?? 0) ?>%</p>
        </div>
        <div class="level-progress-stats">
            <div class="level-progress-stat">
                <span class="level-progress-stat-label"><?= e(t('xp.total_xp')) ?></span>
                <strong><?= e(number_format((int) ($xp['total_xp'] ?? 0))) ?></strong>
            </div>
            <div class="level-progress-stat">
                <span class="level-progress-stat-label"><?= e(t('xp.into_level')) ?></span>
                <strong><?= e(number_format((int) ($xp['into_level'] ?? 0))) ?> / <?= e(number_format((int) ($xp['level_span'] ?? 0))) ?></strong>
            </div>
            <div class="level-progress-stat">
                <span class="level-progress-stat-label"><?= e(t('xp.needed_next', ['level' => (int) ($xp['level'] ?? 1) + 1])) ?></span>
                <strong><?= e(number_format((int) ($xp['xp_to_next'] ?? 0))) ?> <?= e(t('xp.points')) ?></strong>
            </div>
        </div>
        <p class="muted level-progress-hint"><?= e(t('xp.progress_hint')) ?></p>

    </div>
</div>

<?php if (!empty($isOwnProfile)): ?>
<div class="app-modal profile-cover-modal" id="profile-cover-modal" hidden role="dialog" aria-modal="true" aria-labelledby="profile-cover-modal-title">
    <div class="app-modal-card">
        <div class="app-modal-head">
            <div><p class="eyebrow"><?= e(t('nav.profile')) ?></p><h2 id="profile-cover-modal-title"><?= e(t('profile.cover_modal_title')) ?></h2></div>
            <button type="button" class="app-modal-close" data-app-modal-close aria-label="<?= e(t('common.close_action')) ?>">&times;</button>
        </div>
        <form id="profile-cover-form" method="post" action="<?= e($profileUrl()) ?>" enctype="multipart/form-data" class="stack profile-cover-form" data-image-cropper-form>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_profile_cover">
            <input type="hidden" name="profile_cover_cropped" value="" data-image-crop-output>

            <?php if ($profileCoverUrl !== ''): ?>
                <figure class="profile-cover-current">
                    <img src="<?= e($profileCoverUrl) ?>" alt="">
                    <figcaption><?= e(t('profile.cover_current')) ?></figcaption>
                </figure>
            <?php endif; ?>

            <label class="profile-cover-upload">
                <span aria-hidden="true"><?= activity_icon_svg('image') ?></span>
                <span><strong><?= e(t('profile.cover_upload')) ?></strong><small><?= e(t('profile.cover_upload_hint')) ?></small></span>
                <input class="sr-only" type="file" name="profile_cover" accept="image/jpeg,image/png,image/webp" data-image-crop-input>
            </label>

            <div class="image-cropper profile-cover-cropper" data-image-cropper hidden>
                <canvas width="1200" height="400" data-image-crop-canvas></canvas>
                <p class="muted small" data-image-crop-empty><?= e(t('admin.image_crop_hint')) ?></p>
                <label><span><?= e(t('common.zoom')) ?></span><input type="range" min="1" max="3" step="0.01" value="1" data-image-crop-zoom></label>
            </div>

            <?php if ($profileCoverUrl !== ''): ?>
                <label class="check profile-cover-remove"><input type="checkbox" name="remove_profile_cover" value="1"><?= e(t('profile.remove_cover')) ?></label>
            <?php endif; ?>

        </form>
        <div class="profile-cover-actions">
            <button class="btn btn-ghost" type="button" data-app-modal-close><?= e(t('common.cancel')) ?></button>
            <button class="btn btn-primary" type="submit" form="profile-cover-form"><?= e(t('common.save')) ?></button>
        </div>
    </div>
</div>

<div class="app-modal profile-tagline-modal" id="profile-tagline-modal" hidden role="dialog" aria-modal="true" aria-labelledby="profile-tagline-modal-title">
    <div class="app-modal-card">
        <div class="app-modal-head">
            <h2 id="profile-tagline-modal-title"><?= e(t('profile.tagline_modal_title')) ?></h2>
            <button type="button" class="app-modal-close" data-app-modal-close aria-label="<?= e(t('common.close_action')) ?>">&times;</button>
        </div>
        <form method="post" action="<?= e($profileUrl()) ?>" class="stack profile-tagline-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_profile_tagline">
            <label class="profile-tagline-field">
                <span><?= e(t('profile.custom_message')) ?></span>
                <input id="profile-tagline-input" type="text" name="profile_tagline" maxlength="<?= $profileTaglineLimit ?>" value="<?= e($profileTagline) ?>" placeholder="<?= e(t('profile.subtitle')) ?>" aria-describedby="profile-tagline-counter" data-character-count-input autofocus>
                <small id="profile-tagline-counter" class="profile-tagline-counter" aria-live="polite"><span data-character-count><?= function_exists('mb_strlen') ? mb_strlen($profileTagline) : strlen($profileTagline) ?></span> / <?= $profileTaglineLimit ?></small>
            </label>
            <div class="profile-tagline-actions">
                <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($canExportProfilePdf)): ?>
<script id="profile-pdf-data" type="application/json"><?= $profileExportJson ?></script>
<?php endif; ?>

<?php if (!empty($isOwnProfile)): ?>
<?php
$renderProfileWidgetForm = static function (?array $widget = null) use ($profileUrl, $profileAchievementById): void {
    $isEdit = $widget !== null;
    $widgetId = (int) ($widget['id'] ?? 0);
    $widgetType = profile_custom_widget_type((string) ($widget['widget_type'] ?? 'media'));
    $selectedAchievementIds = profile_custom_widget_achievement_ids((string) ($widget['achievement_ids_json'] ?? ''));
    $accent = profile_custom_widget_accent((string) ($widget['accent_color'] ?? '#7c3aed'));
    $accentOptions = ['#7c3aed', '#2563eb', '#0891b2', '#059669', '#ea580c', '#e11d48'];
    ?>
    <form method="post" action="<?= e($profileUrl()) ?>" enctype="multipart/form-data" class="profile-widget-form" data-profile-widget-form data-widget-type="<?= e($widgetType) ?>">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="<?= $isEdit ? 'update_profile_widget' : 'create_profile_widget' ?>">
        <?php if ($isEdit): ?><input type="hidden" name="widget_id" value="<?= $widgetId ?>"><?php endif; ?>

        <div class="profile-widget-form-grid">
            <label class="profile-widget-title-field"><span><?= e(t('profile.widget_title')) ?></span><input type="text" name="widget_title" maxlength="80" value="<?= e((string) ($widget['title'] ?? '')) ?>" placeholder="<?= e(t('profile.widget_title_placeholder')) ?>" required autofocus></label>
            <label><span><?= e(t('profile.widget_type')) ?></span><select name="widget_type" data-profile-widget-type>
                <option value="media"<?= $widgetType === 'media' ? ' selected' : '' ?>><?= e(t('profile.widget_type_media')) ?></option>
                <option value="text"<?= $widgetType === 'text' ? ' selected' : '' ?>><?= e(t('profile.widget_type_text')) ?></option>
                <option value="achievements"<?= $widgetType === 'achievements' ? ' selected' : '' ?>><?= e(t('profile.widget_type_achievements')) ?></option>
            </select></label>
        </div>

        <label><span><?= e(t('profile.widget_description')) ?></span><textarea name="widget_body" maxlength="600" rows="3" placeholder="<?= e(t('profile.widget_description_placeholder')) ?>"><?= e((string) ($widget['body'] ?? '')) ?></textarea></label>

        <section class="profile-widget-media-fields" data-profile-widget-fields="media">
            <div class="profile-widget-source-grid">
                <label class="profile-widget-upload"><span><?= e(t('profile.widget_upload')) ?></span><input type="file" name="widget_media" accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm,video/quicktime"><small><?= e(t('profile.widget_upload_hint')) ?></small></label>
                <span class="profile-widget-or"><?= e(t('common.or')) ?></span>
                <label><span><?= e(t('profile.widget_external_url')) ?></span><input type="url" name="external_url" maxlength="1000" value="<?= e((string) ($widget['external_url'] ?? '')) ?>" inputmode="url" placeholder="https://youtube.com/..."><small><?= e(t('profile.widget_external_hint')) ?></small></label>
            </div>
            <?php if ($isEdit && trim((string) ($widget['media_path'] ?? '')) !== ''): ?>
                <label class="profile-widget-remove-media"><input type="checkbox" name="remove_widget_media" value="1"><span><?= e(t('profile.widget_remove_media')) ?></span></label>
            <?php endif; ?>
        </section>

        <section class="profile-widget-achievement-fields" data-profile-widget-fields="achievements">
            <div class="profile-widget-field-head"><span><?= e(t('profile.widget_choose_achievements')) ?></span><small><?= e(t('profile.widget_choose_achievements_hint', ['count' => PROFILE_CUSTOM_WIDGET_ACHIEVEMENT_LIMIT])) ?></small></div>
            <div class="profile-widget-achievement-picker">
                <?php foreach ($profileAchievementById as $achievementId => $achievement): ?>
                    <label class="profile-widget-achievement-option">
                        <input type="checkbox" name="achievement_ids[]" value="<?= (int) $achievementId ?>" <?= in_array((int) $achievementId, $selectedAchievementIds, true) ? 'checked' : '' ?>>
                        <?= achievement_visual_html($achievement, 'achievement-visual') ?>
                        <span><strong><?= e((string) ($achievement['name'] ?? '')) ?></strong><small><?= e(t('achievements.unlocked')) ?></small></span>
                    </label>
                <?php endforeach; ?>
                <?php if ($profileAchievementById === []): ?><p class="muted panel-inline-empty"><?= e(t('profile.widget_no_achievements')) ?></p><?php endif; ?>
            </div>
        </section>

        <div class="profile-widget-form-grid profile-widget-footer-fields">
            <label><span><?= e(t('profile.widget_link')) ?></span><input type="url" name="link_url" maxlength="1000" value="<?= e((string) ($widget['link_url'] ?? '')) ?>" inputmode="url" placeholder="https://..."></label>
            <fieldset class="profile-widget-accent"><legend><?= e(t('profile.widget_accent')) ?></legend><div>
                <?php foreach ($accentOptions as $accentOption): ?><label style="--accent-option:<?= e($accentOption) ?>"><input type="radio" name="accent_color" value="<?= e($accentOption) ?>" <?= $accent === $accentOption ? 'checked' : '' ?>><span aria-label="<?= e($accentOption) ?>"></span></label><?php endforeach; ?>
            </div></fieldset>
        </div>

        <?php if ($isEdit): ?><label class="profile-widget-visible"><input type="checkbox" name="widget_visible" value="1" <?= !empty($widget['is_visible']) ? 'checked' : '' ?>><span><strong><?= e(t('profile.widget_visible')) ?></strong><small><?= e(t('profile.widget_visible_hint')) ?></small></span></label><?php endif; ?>

        <div class="profile-widget-form-actions">
            <button class="btn btn-ghost" type="button" data-app-modal-close><?= e(t('common.cancel')) ?></button>
            <button class="btn btn-primary" type="submit"><?= e($isEdit ? t('common.save') : t('profile.add_widget')) ?></button>
        </div>
    </form>
    <?php if ($isEdit): ?>
        <form method="post" action="<?= e($profileUrl()) ?>" class="profile-widget-delete" onsubmit="return confirm('<?= e(t('profile.widget_delete_confirm')) ?>')">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete_profile_widget">
            <input type="hidden" name="widget_id" value="<?= $widgetId ?>">
            <button type="submit"><?= e(t('profile.delete_widget')) ?></button>
        </form>
    <?php endif; ?>
    <?php
};
?>
<div class="app-modal profile-widget-modal" id="profile-widget-create-modal" hidden role="dialog" aria-modal="true" aria-labelledby="profile-widget-create-title">
    <div class="app-modal-card">
        <div class="app-modal-head"><div><p class="eyebrow"><?= e(t('profile.showcase')) ?></p><h2 id="profile-widget-create-title"><?= e(t('profile.add_widget')) ?></h2><small><?= e(t('profile.widget_create_hint')) ?></small></div><button type="button" class="app-modal-close" data-app-modal-close aria-label="<?= e(t('common.cancel')) ?>">&times;</button></div>
        <?php $renderProfileWidgetForm(); ?>
    </div>
</div>
<?php foreach ($profileCustomWidgets as $editableProfileWidget): ?>
    <div class="app-modal profile-widget-modal" id="profile-widget-edit-modal-<?= (int) $editableProfileWidget['id'] ?>" hidden role="dialog" aria-modal="true" aria-labelledby="profile-widget-edit-title-<?= (int) $editableProfileWidget['id'] ?>">
        <div class="app-modal-card">
            <div class="app-modal-head"><div><p class="eyebrow"><?= e(t('profile.showcase')) ?></p><h2 id="profile-widget-edit-title-<?= (int) $editableProfileWidget['id'] ?>"><?= e(t('profile.edit_widget')) ?></h2></div><button type="button" class="app-modal-close" data-app-modal-close aria-label="<?= e(t('common.cancel')) ?>">&times;</button></div>
            <?php $renderProfileWidgetForm($editableProfileWidget); ?>
        </div>
    </div>
<?php endforeach; ?>

<div class="app-modal" id="profile-avatar-modal" hidden role="dialog" aria-modal="true" aria-labelledby="profile-avatar-modal-title">
    <div class="app-modal-card">
        <div class="app-modal-head">
            <div>
                <p class="eyebrow"><?= e(t('nav.profile')) ?></p>
                <h2 id="profile-avatar-modal-title"><?= e(t('profile.avatar_menu')) ?></h2>
            </div>
            <button type="button" class="app-modal-close" data-app-modal-close aria-label="<?= e(t('celebration.dismiss')) ?>">&times;</button>
        </div>

        <?php // Two clearly separate things: the picture itself, and the frame around it. ?>
        <a class="avatar-menu-option" href="/?page=settings&amp;view=avatar">
            <span class="avatar-menu-icon" aria-hidden="true">&#128247;</span>
            <span class="avatar-menu-copy">
                <strong><?= e(t('settings.change_avatar')) ?></strong>
                <small><?= e(t('profile.avatar_photo_hint')) ?></small>
            </span>
        </a>

        <?php $cosmetics = (array) ($profileCosmetics ?? []); ?>
        <?php if ($cosmetics !== []): ?>
            <form method="post" action="<?= e($profileUrl()) ?>" class="cosmetics-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="equip_frame">
                <p class="cosmetics-title"><?= e(t('cosmetic.title')) ?></p>
                <p class="muted small cosmetics-subtitle"><?= e(t('profile.avatar_frame_hint')) ?></p>
                <div class="cosmetics-grid">
                    <?php foreach ($cosmetics as $cosmetic): ?>
                        <label class="cosmetic-option<?= empty($cosmetic['unlocked']) ? ' is-locked' : '' ?><?= !empty($cosmetic['equipped']) ? ' is-equipped' : '' ?>"
                               title="<?= e((string) ($cosmetic['hint'] !== '' ? $cosmetic['hint'] : $cosmetic['label'])) ?>">
                            <input type="radio" name="frame" value="<?= e((string) $cosmetic['key']) ?>"
                                   <?= !empty($cosmetic['equipped']) ? 'checked' : '' ?>
                                   <?= empty($cosmetic['unlocked']) ? 'disabled' : '' ?>>
                            <span class="cosmetic-swatch avatar-frame frame-<?= e((string) $cosmetic['key']) ?>" aria-hidden="true">
                                <?php if ($profileAvatarUrl !== ''): ?>
                                    <img src="<?= e($profileAvatarUrl) ?>" alt="">
                                <?php else: ?>
                                    <span><?= e(initials_for((string) ($profileUser['display_name'] ?? ''))) ?></span>
                                <?php endif; ?>
                                <?php if (empty($cosmetic['unlocked'])): ?><b class="cosmetic-lock">&#128274;</b><?php endif; ?>
                            </span>
                            <span class="cosmetic-label"><?= e((string) $cosmetic['label']) ?></span>
                            <?php if (!empty($cosmetic['hint'])): ?>
                                <small class="cosmetic-hint"><?= e((string) $cosmetic['hint']) ?></small>
                            <?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="cosmetics-actions">
                    <button class="btn btn-primary small" type="submit"><?= e(t('cosmetic.equip')) ?></button>
                    <button class="btn btn-ghost small" type="submit" name="frame" value="none"><?= e(t('cosmetic.reset')) ?></button>
                    <button class="btn btn-ghost small" type="button" data-app-modal-close><?= e(t('common.cancel')) ?></button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
