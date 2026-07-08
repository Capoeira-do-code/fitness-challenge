<?php

declare(strict_types=1);

$profileUser = $profileUser ?? $currentUser;
$isOwnProfile = (bool) ($isOwnProfile ?? ((int) ($profileUser['id'] ?? 0) === (int) ($currentUser['id'] ?? 0)));
$canEditProfile = (bool) ($canEditProfile ?? $isOwnProfile);
$profileBaseUrl = (string) ($profileBaseUrl ?? '/?page=profile');

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
    ['label' => t('metric.steps'), 'value' => number_format((int) ($profileMetric['total_steps'] ?? 0), 0, '.', ''), 'meta' => t('metric.total')],
    ['label' => t('metric.total_km'), 'value' => number_format((float) ($profileMetric['total_km'] ?? 0), 2, '.', '') . ' km', 'meta' => t('metric.distance_km')],
    ['label' => t('metric.workouts'), 'value' => (string) $profileWorkoutTotal, 'meta' => t('metric.total')],
    ['label' => t('metric.score'), 'value' => number_format((float) ($profileMetric['score'] ?? 0), 1, '.', ''), 'meta' => t('metric.current_value')],
];
if ($penaltiesEnabled) {
    $profileDataCards[] = ['label' => t('metric.strikes'), 'value' => (string) (int) ($profileMetric['current_strikes'] ?? 0), 'meta' => t('metric.current_value')];
    $profileDataCards[] = ['label' => t('metric.penalty'), 'value' => "\u{20AC}" . number_format((float) ($profileMetric['total_penalty'] ?? 0), 2, '.', ''), 'meta' => t('metric.total')];
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
            <?php if ($profileAvatarUrl !== ''): ?>
                <img class="profile-avatar" src="<?= e($profileAvatarUrl) ?>" alt="<?= e((string) $profileUser['display_name']) ?>">
            <?php else: ?>
                <span class="profile-avatar initials"><?= e(initials_for((string) $profileUser['display_name'])) ?></span>
            <?php endif; ?>
            <div>
                <p class="eyebrow"><?= e(t('nav.profile')) ?></p>
                <h1><?= e((string) $profileUser['display_name']) ?></h1>
                <p class="muted">@<?= e((string) $profileUser['username']) ?> &middot; <?= e($profileHeroMessage) ?><?php if (!$isOwnProfile): ?> &middot; <?= e(t('profile.read_only')) ?><?php endif; ?></p>
                <?php $xp = (array) ($profileXp ?? []); ?>
                <div class="profile-level" title="<?= e(t('xp.level') . ' ' . (int) ($xp['level'] ?? 1)) ?>">
                    <span class="profile-level-badge"><?= e(t('xp.level_short')) ?> <?= (int) ($xp['level'] ?? 1) ?></span>
                    <div class="profile-xp">
                        <div class="profile-xp-bar"><span style="width: <?= max(0, min(100, (int) ($xp['progress_pct'] ?? 0))) ?>%"></span></div>
                        <span class="profile-xp-label"><?= e(number_format((int) ($xp['total_xp'] ?? 0))) ?> <?= e(t('xp.points')) ?> &middot; <?= e(t('xp.to_next', ['xp' => number_format((int) ($xp['xp_to_next'] ?? 0))])) ?></span>
                    </div>
                </div>
            </div>
        </div>
        <?php if ($isOwnProfile || !empty($canExportProfilePdf)): ?>
            <div class="profile-hero-actions">
                <?php if (!empty($canExportProfilePdf)): ?>
                    <button class="btn btn-primary profile-pdf-export-btn" type="button" data-profile-pdf-export>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M12 11v6"/><path d="m9 14 3 3 3-3"/></svg>
                        <span data-profile-pdf-export-label><?= e(t('profile.export_pdf')) ?></span>
                    </button>
                <?php endif; ?>
                <?php if ($isOwnProfile): ?>
                    <details class="profile-tagline-editor">
                        <summary class="btn btn-ghost icon-btn profile-tagline-edit" aria-label="<?= e(t('profile.edit_tagline')) ?>">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m4 20 4.7-1.2L19 8.5 15.5 5 5.2 15.3 4 20Z"/><path d="m14 6 4 4"/></svg>
                        </summary>
                        <form method="post" action="<?= e($profileUrl()) ?>" class="profile-tagline-form">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="update_profile_tagline">
                            <label>
                                <?= e(t('profile.custom_message')) ?>
                                <input type="text" name="profile_tagline" maxlength="160" value="<?= e($profileTagline) ?>" placeholder="<?= e(t('profile.subtitle')) ?>">
                            </label>
                            <div class="profile-tagline-actions">
                                <button class="btn btn-primary small" type="submit"><?= e(t('common.save')) ?></button>
                            </div>
                        </form>
                    </details>
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
                <article class="profile-data-card">
                    <span><?= e((string) $card['label']) ?></span>
                    <strong><?= e((string) $card['value']) ?></strong>
                    <small><?= e((string) $card['meta']) ?></small>
                </article>
            <?php endforeach; ?>
        </div>
    </article>
    <?php endif; ?>

    <section class="profile-home-grid<?= $activeSection !== '' ? ' hidden' : '' ?>" data-spa-main <?= $activeSection !== '' ? 'hidden' : '' ?>>
        <article class="panel profile-home-card profile-home-goals">
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
                <p class="muted"><?= e(t('goals.empty')) ?></p>
            <?php endif; ?>
        </article>

        <article class="panel profile-home-card">
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

        <article class="panel profile-home-card profile-current-setup-card">
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
                        <span><strong><?= e((string) $goalChip['value']) ?></strong><?= e((string) $goalChip['label']) ?></span>
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

        <article class="panel profile-home-card">
            <div class="profile-home-card-head">
                <div>
                    <p class="eyebrow"><?= e((string) $activityCount) ?> events</p>
                    <h2><?= e(t('profile.recent_activity')) ?></h2>
                </div>
                <a class="btn btn-ghost small" href="<?= e($profileUrl('activity')) ?>" data-spa-link><?= e(t('common.view_all')) ?></a>
            </div>
            <?php if ($latestActivity === []): ?>
                <p class="muted"><?= e(t('audit.empty')) ?></p>
            <?php else: ?>
                <div class="profile-home-list profile-home-activity">
                    <?php foreach ($latestActivity as $item): ?>
                        <div>
                            <strong><?= e((string) ($item['summary'] ?? '')) ?></strong>
                            <span><?= e((string) ($item['action'] ?? '')) ?> · <?= e(format_date_eu((string) ($item['created_at'] ?? ''))) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
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
                    <a class="btn btn-ghost" href="<?= e($profileUrl('goals')) ?>" data-spa-back aria-label="<?= e(t('common.back')) ?>">← <?= e(t('common.back')) ?></a>
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
                            <button class="btn small btn-ghost" type="button" data-goal-edit-toggle data-target="<?= e($editFormId) ?>"><?= e(t('common.edit')) ?></button>
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
                    <form method="post" action="<?= e($profileUrl('goals')) ?>" class="mini-card editable-card goal-editor" id="<?= e($editFormId) ?>" data-goal-edit-form hidden>
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
                            <button class="btn small btn-ghost" type="button" data-goal-edit-cancel data-target="<?= e($editFormId) ?>"><?= e(t('common.cancel')) ?></button>
                        </div>
                    </form>
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
                <?php foreach ($userAchievements as $achievement): ?>
                    <?php $awardId = (int) ($achievement['award_id'] ?? $achievement['id'] ?? 0); ?>
                    <?php $deleteFormId = 'delete-achievement-profile-' . $awardId; ?>
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
                            <span><strong><?= e((string) $goalChip['value']) ?></strong><?= e((string) $goalChip['label']) ?></span>
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
        <div class="audit-list">
            <?php foreach (($recentActivity ?? []) as $item): ?>
                <article><strong><?= e((string) $item['summary']) ?></strong><span><?= e((string) $item['action']) ?> · <?= e(format_date_eu((string) $item['created_at'])) ?></span></article>
            <?php endforeach; ?>
            <?php if (($recentActivity ?? []) === []): ?><p class="muted"><?= e(t('audit.empty')) ?></p><?php endif; ?>
        </div>
    </article>
</section>
<?php if (!empty($canExportProfilePdf)): ?>
<script id="profile-pdf-data" type="application/json"><?= $profileExportJson ?></script>
<?php endif; ?>
