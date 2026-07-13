<?php

declare(strict_types=1);

$profileUser = $profileUser ?? $currentUser;
$isOwnProfile = (bool) ($isOwnProfile ?? ((int) ($profileUser['id'] ?? 0) === (int) ($currentUser['id'] ?? 0)));
$canEditProfile = (bool) ($canEditProfile ?? $isOwnProfile);
$profileBaseUrl = (string) ($profileBaseUrl ?? '/?page=profile');
$profileFriends = is_array($profileFriends ?? null) ? array_values((array) $profileFriends) : [];
$profileFriendIncoming = is_array($profileFriendIncoming ?? null) ? array_values((array) $profileFriendIncoming) : [];
$profileFriendOutgoing = is_array($profileFriendOutgoing ?? null) ? array_values((array) $profileFriendOutgoing) : [];
$profileFriendAddable = is_array($profileFriendAddable ?? null) ? array_values((array) $profileFriendAddable) : [];
$profileFriendStatus = (string) ($profileFriendStatus ?? ($isOwnProfile ? 'self' : 'none'));
$profileFriendCount = count($profileFriends);
$profileFriendPreview = array_slice($profileFriends, 0, 5);
$profileFriendIncomingPreview = array_slice($profileFriendIncoming, 0, 2);
$profileFriendOutgoingCount = count($profileFriendOutgoing);

$activeSection = (string) ($_GET['section'] ?? '');
$allowedSections = ['goals', 'achievements', 'config', 'activity'];
if (!in_array($activeSection, $allowedSections, true)) {
    $activeSection = '';
}

$goalCreateMode = (string) ($_GET['goal_new'] ?? '') === '1';
$goalDetailId = isset($_GET['goal_id']) ? (int) $_GET['goal_id'] : 0;
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
$profileTagline = trim((string) ($profileUser['profile_tagline'] ?? ''));
$profileHeroMessage = $profileTagline !== '' ? $profileTagline : (string) t('profile.subtitle');

$profileQueryBase = ['page' => 'profile'];
if (!$isOwnProfile) {
    $profileQueryBase['user_id'] = (int) ($profileUser['id'] ?? 0);
}
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

// #15 — customizable Profile home layout. Order comes from the profile owner's
// saved arrangement; default (no saved layout) keeps the original DOM order.
$profileLayoutBlocks = ['goals', 'friends', 'achievements', 'duels', 'competitions', 'setup', 'activity'];
$profileLayoutLabels = [
    'goals' => t('goals.personal'),
    'friends' => t('nav.friends'),
    'achievements' => t('profile.achievements'),
    'duels' => t('nav.duels'),
    'competitions' => t('nav.competitions'),
    'setup' => t('profile.current_config'),
    'activity' => t('profile.recent_activity'),
];
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
$renderProfileFriendActions = static function (string $status, int $targetUserId, string $contextClass = '') use ($profileFriendActionForm): void {
    if ($targetUserId <= 0) {
        return;
    }

    if ($status === 'none') {
        $profileFriendActionForm('friend_request', $targetUserId, t('friends.send_request'), 'btn-primary', $contextClass);
        return;
    }
    if ($status === 'pending_out') {
        $profileFriendActionForm('friend_remove', $targetUserId, t('friends.cancel_request'), 'btn-ghost', $contextClass);
        return;
    }
    if ($status === 'pending_in') {
        $profileFriendActionForm('friend_accept', $targetUserId, t('friends.accept'), 'btn-primary', $contextClass);
        $profileFriendActionForm('friend_reject', $targetUserId, t('friends.reject'), 'btn-ghost', $contextClass);
        return;
    }
    if ($status === 'friends') {
        ?>
        <a class="btn btn-primary small <?= e($contextClass) ?>" href="/?page=friends&amp;compare=<?= $targetUserId ?>" data-spa-link><?= e(t('friends.compare')) ?></a>
        <?php
        $profileFriendActionForm('friend_remove', $targetUserId, t('friends.remove'), 'btn-ghost', $contextClass);
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
if (!in_array($profilePrimaryGoalType, allowed_primary_goal_types(), true)) {
    $profilePrimaryGoalType = 'steps';
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
];
?>
<section class="screen stack-lg spa-shell" data-spa-page="profile">
    <div class="hero-panel profile-hero">
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
                <p class="muted">@<?= e((string) $profileUser['username']) ?> &middot; <?= e($profileHeroMessage) ?><?php if (!$isOwnProfile): ?> &middot; <?= e(t('profile.read_only')) ?><?php endif; ?></p>
                <?php $profileTeamsList = (array) ($profileTeams ?? []); ?>
                <?php if ($profileTeamsList !== []): ?>
                    <div class="profile-team-badges" aria-label="<?= e(t('nav.team')) ?>">
                        <?php foreach ($profileTeamsList as $profileTeamItem): ?>
                            <a class="profile-team-badge" href="/?page=team&team_id=<?= (int) ($profileTeamItem['id'] ?? 0) ?>">
                                <span class="profile-team-badge-dot" aria-hidden="true"></span>
                                <?= e((string) ($profileTeamItem['name'] ?? '')) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php $xp = (array) ($profileXp ?? []); ?>
                <button type="button" class="profile-level profile-level-trigger" data-app-modal-open="profile-level-modal" title="<?= e(t('xp.progress_title')) ?>" aria-haspopup="dialog">
                    <span class="profile-level-badge"><?= e(t('xp.level_short')) ?> <?= (int) ($xp['level'] ?? 1) ?></span>
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
                    <div class="profile-friend-actions profile-friend-actions-hero" aria-label="<?= e(t('profile.friendship')) ?>">
                        <span class="profile-friend-status"><?= e($profileFriendStatusText) ?></span>
                        <?php $renderProfileFriendActions($profileFriendStatus, (int) $profileUser['id'], 'profile-friend-hero-action'); ?>
                    </div>
                <?php endif; ?>
                <?php if ($isOwnProfile || !empty($canExportProfilePdf)): ?>
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
                            'label' => t('profile.edit_tagline'),
                            'attrs' => ['data-app-modal-open' => 'profile-tagline-modal'],
                        ];
                    }
                    if (!empty($canExportProfilePdf)) {
                        $profileMenuItems[] = [
                            'label' => t('profile.export_data'),
                            'attrs' => ['data-profile-pdf-export' => ''],
                        ];
                    }
                    if ($isOwnProfile) {
                        $profileMenuItems[] = [
                            'label' => t('profile.customize_layout'),
                            'href' => $profileUrl('', ['layout_edit' => '1']),
                        ];
                    }
                    echo render_kebab_menu($profileMenuItems, [
                        'label' => t('profile.manage'),
                        'align' => 'end',
                        'class' => 'profile-hero-menu',
                    ]);
                    ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

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
                            <div class="profile-challenge-history-metrics" aria-label="<?= e(t('profile.challenge_history_metrics')) ?>">
                                <span><small><?= e(t('metric.score')) ?></small><strong><?= e($challengeScore) ?></strong></span>
                                <span><small><?= e(t('metric.steps')) ?></small><strong><?= e($challengeSteps) ?></strong></span>
                                <span><small><?= e(t('metric.workouts')) ?></small><strong><?= e($challengeWorkoutText) ?></strong></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </article>
    <?php endif; ?>

    <?php if ($isOwnProfile): ?>
    <article class="panel profile-data-overview<?= $activeSection !== '' ? ' hidden' : '' ?>" data-spa-home-extra <?= $activeSection !== '' ? 'hidden' : '' ?>>
        <div class="panel-head">
            <div>
                <p class="eyebrow"><?= e(t('profile.my_data')) ?></p>
                <h2><?= e(t('profile.my_data')) ?></h2>
                <p class="muted small"><?= e(t('profile.my_data_subtitle')) ?> <?= e($profileSelectedChallengeRangeLabel) ?></p>
            </div>
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

                <details class="dashboard-layout-visibility" open>
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

    <section class="profile-home-grid<?= $activeSection !== '' ? ' hidden' : '' ?>" data-spa-main <?= $activeSection !== '' ? 'hidden' : '' ?>>
        <article class="panel profile-home-card profile-home-goals" data-profile-block="goals" style="<?= e($profileBlockStyle('goals')) ?>">
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

        <article class="panel profile-home-card profile-friends-card" data-profile-block="friends" style="<?= e($profileBlockStyle('friends')) ?>">
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
                <div class="inline-actions-mini">
                    <?php if (!$isOwnProfile): ?>
                        <span class="profile-friend-status compact"><?= e($profileFriendStatusText) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($isOwnProfile && $profileFriendIncomingPreview !== []): ?>
                <div class="profile-friend-request-list">
                    <strong><?= e(t('friends.incoming')) ?></strong>
                    <?php foreach ($profileFriendIncomingPreview as $requester): ?>
                        <div class="profile-friend-row">
                            <a class="profile-friend-id" href="/?page=profile&amp;user_id=<?= (int) ($requester['id'] ?? 0) ?>" data-spa-link>
                                <?php $profileFriendAvatar($requester); ?>
                                <span>
                                    <strong><?= e((string) ($requester['display_name'] ?? $requester['username'] ?? '')) ?></strong>
                                    <small>@<?= e((string) ($requester['username'] ?? '')) ?></small>
                                </span>
                            </a>
                            <div class="profile-friend-actions">
                                <?php $profileFriendActionForm('friend_accept', (int) ($requester['id'] ?? 0), t('friends.accept'), 'btn-primary', 'profile-friend-card-action'); ?>
                                <?php $profileFriendActionForm('friend_reject', (int) ($requester['id'] ?? 0), t('friends.reject'), 'btn-ghost', 'profile-friend-card-action'); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($profileFriendPreview === []): ?>
                <p class="muted"><?= e($isOwnProfile ? t('friends.none') : t('profile.friends_empty_other')) ?></p>
            <?php else: ?>
                <div class="profile-friend-list">
                    <?php foreach ($profileFriendPreview as $friend): ?>
                        <div class="profile-friend-row">
                            <a class="profile-friend-id" href="/?page=profile&amp;user_id=<?= (int) ($friend['id'] ?? 0) ?>" data-spa-link>
                                <?php $profileFriendAvatar($friend); ?>
                                <span>
                                    <strong><?= e((string) ($friend['display_name'] ?? $friend['username'] ?? '')) ?></strong>
                                    <small>@<?= e((string) ($friend['username'] ?? '')) ?></small>
                                </span>
                            </a>
                            <?php if ($isOwnProfile): ?>
                                <div class="profile-friend-actions">
                                    <a class="btn btn-primary small profile-friend-card-action" href="/?page=friends&amp;compare=<?= (int) ($friend['id'] ?? 0) ?>" data-spa-link><?= e(t('friends.compare')) ?></a>
                                    <?php $profileFriendActionForm('friend_remove', (int) ($friend['id'] ?? 0), t('friends.remove'), 'btn-ghost', 'profile-friend-card-action'); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($profileFriendCount > count($profileFriendPreview)): ?>
                    <a class="profile-friend-more" href="/?page=friends" data-spa-link><?= e(t('common.view_all')) ?></a>
                <?php endif; ?>
            <?php endif; ?>

        </article>

        <article class="panel profile-home-card" data-profile-block="achievements" style="<?= e($profileBlockStyle('achievements')) ?>">
            <div class="profile-home-card-head">
                <div>
                    <p class="eyebrow"><?= e((string) $achievementCount) ?> <?= e(t('profile.unlocked_suffix')) ?></p>
                    <h2><?= e(t('profile.achievements')) ?></h2>
                </div>
                <a class="btn btn-ghost small" href="<?= e($profileUrl('achievements')) ?>" data-spa-link><?= e(t('common.view_all')) ?></a>
            </div>
            <?php if ($latestAchievements === []): ?>
                <p class="muted"><?= e(t('achievements.empty')) ?></p>
            <?php else: ?>
                <div class="profile-home-list">
                    <?php foreach ($latestAchievements as $achievement): ?>
                        <div>
                            <strong><?= e((string) ($achievement['name'] ?? '')) ?></strong>
                            <span><?= e(!empty($achievement['awarded_at']) ? format_date_eu((string) $achievement['awarded_at']) : (string) ($achievement['description'] ?? '')) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </article>

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

        <article class="panel profile-home-card profile-current-setup-card" data-profile-block="setup" style="<?= e($profileBlockStyle('setup')) ?>">
            <div class="profile-home-card-head">
                <div>
                    <p class="eyebrow"><?= e(t('profile.current_config')) ?></p>
                    <h2><?= e(t('profile.current_config')) ?></h2>
                </div>
                <?php if ($canEditProfile): ?>
                    <a class="btn btn-ghost small" href="<?= e($profileUrl('config', ['edit' => 1])) ?>" data-spa-link><?= e(t('common.edit')) ?></a>
                <?php endif; ?>
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
                <?php foreach ($profileSetupRows as $row): ?>
                    <div>
                        <dt><?= e((string) $row['label']) ?></dt>
                        <dd><?= e((string) $row['value']) ?></dd>
                    </div>
                <?php endforeach; ?>
            </dl>
        </article>

        <article class="panel profile-home-card" data-profile-block="activity" style="<?= e($profileBlockStyle('activity')) ?>">
            <div class="profile-home-card-head">
                <div>
                    <p class="eyebrow"><?= e((string) $activityCount) ?> events</p>
                    <h2><?= e(t('profile.recent_activity')) ?></h2>
                </div>
                <a class="btn btn-ghost small" href="<?= e($profileUrl('activity')) ?>" data-spa-link><?= e(t('common.view_all')) ?></a>
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
    </section>

    <article class="panel settings-panel<?= $activeSection === 'goals' ? ' active' : '' ?>" data-spa-section="goals" <?= $activeSection === 'goals' ? '' : 'hidden' ?>>
        <div class="stack profile-section-list" data-spa-show-when-no-param="goal_id,goal_new" <?= $goalCreateMode || $goalDetailId > 0 ? 'hidden' : '' ?>>
            <div class="panel-head">
                <h2><?= e(t('goals.personal')) ?></h2>
                <div class="inline-actions-mini">
                    <?php if ($canEditProfile): ?>
                        <a class="btn btn-primary" href="<?= e($profileUrl('goals', ['goal_new' => 1])) ?>" data-spa-link><?= e(t('profile.new_goal')) ?></a>
                    <?php endif; ?>
                    <a class="btn btn-ghost" href="<?= e($profileUrl()) ?>" data-spa-back aria-label="<?= e(t('common.back')) ?>">← <?= e(t('common.back')) ?></a>
                </div>
            </div>

            <div class="settings-list" data-profile-goals-list>
                <?php if (($personalGoals ?? []) === []): ?>
                    <p class="muted panel-inline-empty"><?= e(t('goals.empty')) ?></p>
                <?php else: ?>
                    <?php foreach ($personalGoals as $goal): ?>
                        <?php $goalType = normalize_goal_target_type((string) ($goal['target_type'] ?? 'custom')); ?>
                        <?php $goalTarget = (float) ($goal['target_value'] ?? 0); ?>
                        <?php $goalCurrent = $goalCurrentValue($goal); ?>
                        <a class="settings-row goal-row" href="<?= e($profileUrl('goals', ['goal_id' => (int) $goal['id']])) ?>" data-spa-link>
                            <span>
                                <strong><?= e((string) $goal['title']) ?></strong>
                                <small class="muted">
                                    <?= e($goalTypeLabel((string) ($goal['target_type'] ?? ''))) ?> ·
                                    <?= e($formatGoalValue($goalCurrent, $goalType)) ?> / <?= e($formatGoalValue($goalTarget, $goalType)) ?> ·
                                    <?= e($goalStatusLabel((string) ($goal['status'] ?? 'active'))) ?>
                                </small>
                            </span>
                            <span class="settings-chevron" aria-hidden="true">›</span>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($canEditProfile): ?>
            <div class="stack goal-subview profile-create-view" data-spa-param-show="goal_new" data-spa-value="1" <?= $goalCreateMode ? '' : 'hidden' ?>>
                <div class="panel-head compact-head">
                    <h3><?= e(t('profile.new_goal')) ?></h3>
                    <a class="btn btn-ghost" href="<?= e($profileUrl('goals')) ?>" data-spa-back aria-label="<?= e(t('common.back')) ?>">← <?= e(t('common.back')) ?></a>
                </div>
                <form method="post" action="<?= e($profileUrl('goals')) ?>" class="stack compact-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="create_goal">
                    <input type="hidden" name="profile_user_id" value="<?= (int) $profileUser['id'] ?>">
                    <label><?= e(t('goals.goal_name')) ?><input type="text" name="title" placeholder="<?= e(t('goals.placeholder')) ?>" required></label>
                    <div class="grid-inline two">
                        <label>
                            <?= e(t('goals.type')) ?>
                            <select name="target_type">
                                <?php foreach ($goalTypeOptions as $option): ?>
                                    <option value="<?= e((string) $option['value']) ?>"><?= e((string) $option['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label><?= e(t('goals.target')) ?><input type="number" step="0.1" name="target_value"></label>
                        <label><?= e(t('goals.due_date')) ?><input type="date" name="due_date"></label>
                    </div>
                    <button class="btn btn-primary" type="submit"><?= e(t('goals.add')) ?></button>
                </form>
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
            <div class="stack goal-subview profile-detail-view" data-spa-param-show="goal_id" data-spa-value="<?= (int) $goal['id'] ?>" <?= $isActiveGoalDetail ? '' : 'hidden' ?>>
                <div class="panel-head compact-head">
                    <h3><?= e((string) $goal['title']) ?></h3>
                    <a class="btn btn-ghost" href="<?= e($profileUrl('goals')) ?>" data-spa-back data-spa-history aria-label="<?= e(t('common.back')) ?>">← <?= e(t('common.back')) ?></a>
                </div>

                <article class="mini-card goal-detail-card goal-detail-summary">
                    <div class="goal-summary-grid">
                        <div class="goal-summary-item">
                            <strong><?= e(t('goals.goal_name')) ?></strong>
                            <span><?= e((string) $goal['title']) ?></span>
                        </div>
                        <div class="goal-summary-item">
                            <strong><?= e(t('goals.type')) ?></strong>
                            <span><?= e($goalTypeLabel($goalRawType)) ?></span>
                        </div>
                        <div class="goal-summary-item">
                            <strong><?= e(t('goals.target')) ?></strong>
                            <span><?= e($formatGoalValue($goalTarget, $goalType)) ?></span>
                        </div>
                        <div class="goal-summary-item">
                            <strong><?= e(t('profile.current_progress')) ?></strong>
                            <span><?= e($formatGoalValue($goalCurrent, $goalType)) ?></span>
                        </div>
                        <div class="goal-summary-item">
                            <strong><?= e(t('common.status')) ?></strong>
                            <span class="goal-status-chip"><?= e($goalStatusLabel((string) ($goal['status'] ?? 'active'))) ?></span>
                        </div>
                        <?php if ($goalDueDate !== ''): ?>
                            <div class="goal-summary-item">
                                <strong><?= e(t('goals.due_date')) ?></strong>
                                <span><?= e(format_date_eu($goalDueDate)) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="goal-progress-wrap">
                        <div class="goal-progress"><span style="width: <?= e((string) $goalProgress) ?>%"></span></div>
                        <small><?= e((string) $goalProgress) ?>%</small>
                    </div>
                    <?php if ($canEditProfile): ?>
                        <div class="goal-detail-actions">
                            <?php // Editing opens in a modal. It used to unfold inside the goal card,
                                  // which shoved the rest of the page down and left you editing in the
                                  // middle of the thing you were reading. ?>
                            <button class="btn small btn-ghost" type="button" data-app-modal-open="goal-edit-modal-<?= (int) $goal['id'] ?>" aria-haspopup="dialog"><?= e(t('common.edit')) ?></button>
                            <?php if ((string) ($goal['status'] ?? 'active') !== 'complete'): ?>
                                <form method="post" action="<?= e($profileUrl('goals')) ?>">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="goal_status">
                                    <input type="hidden" name="goal_id" value="<?= (int) $goal['id'] ?>">
                                    <input type="hidden" name="status" value="complete">
                                    <input type="hidden" name="profile_user_id" value="<?= (int) $profileUser['id'] ?>">
                                    <button class="btn small btn-ghost" type="submit"><?= e(t('common.complete')) ?></button>
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
                <div class="app-modal" id="goal-edit-modal-<?= (int) $goal['id'] ?>" hidden role="dialog" aria-modal="true" aria-labelledby="goal-edit-title-<?= (int) $goal['id'] ?>">
                    <div class="app-modal-card">
                        <div class="app-modal-head">
                            <div>
                                <p class="eyebrow"><?= e(t('goals.personal')) ?></p>
                                <h2 id="goal-edit-title-<?= (int) $goal['id'] ?>"><?= e((string) $goal['title']) ?></h2>
                            </div>
                            <button type="button" class="app-modal-close" data-app-modal-close aria-label="<?= e(t('common.cancel')) ?>">&times;</button>
                        </div>
                    <form method="post" action="<?= e($profileUrl('goals')) ?>" class="goal-editor" id="<?= e($editFormId) ?>" data-goal-edit-form>
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="update_goal">
                        <input type="hidden" name="goal_id" value="<?= (int) $goal['id'] ?>">
                        <input type="hidden" name="profile_user_id" value="<?= (int) $profileUser['id'] ?>">
                        <div class="goal-editor-grid">
                            <label><?= e(t('goals.goal_name')) ?><input type="text" name="title" value="<?= e((string) $goal['title']) ?>"></label>
                            <label>
                                <?= e(t('goals.type')) ?>
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
                            <label><?= e(t('goals.target')) ?><input type="number" step="0.1" name="target_value" value="<?= e((string) ($goal['target_value'] ?? '')) ?>"></label>
                            <label><?= e(t('goals.due_date')) ?><input type="date" name="due_date" value="<?= e((string) ($goal['due_date'] ?? '')) ?>"></label>
                        </div>
                        <div class="goal-editor-actions">
                            <button class="btn small btn-primary" type="submit"><?= e(t('common.save')) ?></button>
                            <button class="btn small btn-ghost" type="button" data-app-modal-close><?= e(t('common.cancel')) ?></button>
                        </div>
                    </form>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </article>

    <article class="panel settings-panel profile-achievements-panel<?= $activeSection === 'achievements' ? ' active' : '' ?>" data-spa-section="achievements" <?= $activeSection === 'achievements' ? '' : 'hidden' ?>>
        <div class="panel-head">
            <h2><?= e(t('profile.achievements')) ?></h2>
            <a class="btn btn-ghost" href="<?= e($profileUrl()) ?>" data-spa-back aria-label="<?= e(t('common.back')) ?>">← <?= e(t('common.back')) ?></a>
        </div>
        <div class="achievement-grid achievement-grid-collapsible" data-achievement-grid>
            <?php if (($userAchievements ?? []) === []): ?>
                <p class="muted"><?= e(t('achievements.empty')) ?></p>
            <?php else: ?>
                <?php foreach ($userAchievements as $achievementIndex => $achievement): ?>
                    <?php $awardId = (int) ($achievement['award_id'] ?? $achievement['id'] ?? 0); ?>
                    <?php $deleteFormId = 'delete-achievement-profile-' . $awardId . '-' . (int) $achievementIndex; ?>
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
                        <?php if (!empty($canDeleteAchievements)): ?>
                            <form method="post" action="<?= e($profileUrl('achievements')) ?>" class="achievement-remove" id="<?= e($deleteFormId) ?>">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete_achievement_award">
                                <input type="hidden" name="award_id" value="<?= $awardId ?>">
                                <button class="achievement-delete-btn" type="button" aria-label="<?= e(t('achievements.delete_award')) ?>" data-achievement-delete-trigger data-form-id="<?= e($deleteFormId) ?>">×</button>
                            </form>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div class="achievement-toggle-wrap">
            <a class="btn btn-ghost small achievement-toggle-btn" href="<?= e($profileAchievementsUrl) ?>"><?= e(t('common.view_all')) ?></a>
        </div>
    </article>

    <article class="panel settings-panel<?= $activeSection === 'config' ? ' active' : '' ?>" data-spa-section="config" <?= $activeSection === 'config' ? '' : 'hidden' ?>>
        <div class="panel-head">
            <h2><?= e(t('profile.current_config')) ?></h2>
            <div class="inline-actions-mini">
                <?php if ($canEditProfile): ?>
                    <a class="btn btn-ghost" href="<?= e($profileUrl('config', ['edit' => 1])) ?>" data-config-edit-link><?= e(t('common.edit')) ?></a>
                <?php endif; ?>
                <a class="btn btn-ghost" href="<?= e($profileUrl()) ?>" data-spa-back aria-label="<?= e(t('common.back')) ?>">← <?= e(t('common.back')) ?></a>
            </div>
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
                            <select name="primary_goal_type">
                                <?php foreach ($primaryGoalOptions as $option): ?>
                                    <option value="<?= e((string) $option['value']) ?>" <?= $profilePrimaryGoalType === (string) $option['value'] ? 'selected' : '' ?>><?= e((string) $option['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label><?= e(t('settings.primary_goal_value')) ?><input type="number" step="0.1" name="primary_goal_value" value="<?= e((string) ($profileUser['primary_goal_value'] ?? '')) ?>"></label>
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
                                                    <?php foreach ($primaryGoalOptions as $option): ?>
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
                                                <?php foreach ($primaryGoalOptions as $option): ?>
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

    <article class="panel settings-panel<?= $activeSection === 'activity' ? ' active' : '' ?>" data-spa-section="activity" <?= $activeSection === 'activity' ? '' : 'hidden' ?>>
        <div class="panel-head">
            <h2><?= e(t('profile.recent_activity')) ?></h2>
            <div class="inline-actions-mini">
                <a class="btn btn-ghost" href="<?= e($profileUrl()) ?>" data-spa-back aria-label="<?= e(t('common.back')) ?>">← <?= e(t('common.back')) ?></a>
            </div>
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
</section>

<?php $xp = (array) ($profileXp ?? []); ?>
<div class="app-modal" id="profile-level-modal" hidden role="dialog" aria-modal="true" aria-labelledby="profile-level-modal-title">
    <div class="app-modal-card">
        <div class="app-modal-head">
            <div>
                <p class="eyebrow"><?= e(t('xp.level')) ?> <?= (int) ($xp['level'] ?? 1) ?></p>
                <h2 id="profile-level-modal-title"><?= e(t('xp.progress_title')) ?></h2>
            </div>
            <button type="button" class="app-modal-close" data-app-modal-close aria-label="<?= e(t('common.back')) ?>">&times;</button>
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
<div class="app-modal" id="profile-tagline-modal" hidden role="dialog" aria-modal="true" aria-labelledby="profile-tagline-modal-title">
    <div class="app-modal-card">
        <div class="app-modal-head">
            <h2 id="profile-tagline-modal-title"><?= e(t('profile.tagline_modal_title')) ?></h2>
            <button type="button" class="app-modal-close" data-app-modal-close aria-label="<?= e(t('common.back')) ?>">&times;</button>
        </div>
        <form method="post" action="<?= e($profileUrl()) ?>" class="stack profile-tagline-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_profile_tagline">
            <label>
                <?= e(t('profile.custom_message')) ?>
                <input type="text" name="profile_tagline" maxlength="160" value="<?= e($profileTagline) ?>" placeholder="<?= e(t('profile.subtitle')) ?>">
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
        <a class="avatar-menu-option" href="/?page=settings#avatar">
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
                                <?= empty($cosmetic['unlocked']) ? '&#128274;' : '' ?>
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
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
