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
$primaryGoalsSpec = trim((string) ($profileUser['primary_goals_spec'] ?? ''));
$profileTagline = trim((string) ($profileUser['profile_tagline'] ?? ''));
$profileHeroMessage = $profileTagline !== '' ? $profileTagline : (string) t('profile.subtitle');

$profileQueryBase = ['page' => 'profile'];
if (!$isOwnProfile) {
    $profileQueryBase['user_id'] = (int) ($profileUser['id'] ?? 0);
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
$profileDailyDetails = is_array($profileDailyDetails ?? null) ? array_values((array) $profileDailyDetails) : [];
$profileDailyPhotoNutrition = is_array($profileDailyPhotoNutrition ?? null) ? array_values((array) $profileDailyPhotoNutrition) : [];
$profileExportPayload = [
    'username' => (string) ($profileUser['username'] ?? ''),
    'display_name' => (string) ($profileUser['display_name'] ?? ''),
    'generated_at' => now_iso(),
    'i18n' => [
        'pdf_title' => (string) t('profile.pdf_title'),
        'pdf_section_overview' => (string) t('profile.pdf_section_overview'),
        'pdf_section_charts' => (string) t('profile.pdf_section_charts'),
        'pdf_section_daily' => (string) t('profile.pdf_section_daily'),
        'pdf_section_nutrition' => (string) t('profile.pdf_section_nutrition'),
        'pdf_section_activity' => (string) t('profile.pdf_section_activity'),
        'pdf_generating' => (string) t('profile.pdf_generating'),
    ],
    'challenge_range' => [
        'start' => (string) ($profileChallengeRange['start'] ?? ''),
        'end' => (string) ($profileChallengeRange['end'] ?? ''),
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
    ],
    'daily_details' => $profileDailyDetails,
    'daily_photo_nutrition' => $profileDailyPhotoNutrition,
    'goals' => array_map(
        static function (array $goal): array {
            return [
                'title' => (string) ($goal['title'] ?? ''),
                'target_type' => (string) ($goal['target_type'] ?? ''),
                'target_value' => (float) ($goal['target_value'] ?? 0),
                'status' => (string) ($goal['status'] ?? 'active'),
                'due_date' => (string) ($goal['due_date'] ?? ''),
            ];
        },
        (array) ($personalGoals ?? [])
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
    'recent_activity' => array_map(
        static function (array $item): array {
            return [
                'summary' => (string) ($item['summary'] ?? ''),
                'action' => (string) ($item['action'] ?? ''),
                'created_at' => (string) ($item['created_at'] ?? ''),
            ];
        },
        (array) ($recentActivity ?? [])
    ),
];
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
    ['label' => t('metric.strikes'), 'value' => (string) (int) ($profileMetric['current_strikes'] ?? 0), 'meta' => t('metric.current_value')],
    ['label' => t('metric.penalty'), 'value' => "\u{20AC}" . number_format((float) ($profileMetric['total_penalty'] ?? 0), 2, '.', ''), 'meta' => t('metric.total')],
];
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
$featuredGoal = $activeGoals[0] ?? null;
$featuredGoalCurrent = is_array($featuredGoal) ? $goalCurrentValue($featuredGoal) : 0.0;
$featuredGoalProgress = is_array($featuredGoal) ? $goalProgressPercent($featuredGoal, $featuredGoalCurrent) : 0.0;
$featuredGoalType = is_array($featuredGoal) ? normalize_goal_target_type((string) ($featuredGoal['target_type'] ?? 'custom')) : 'custom';
$latestAchievements = array_slice(array_values((array) ($userAchievements ?? [])), 0, 3);
$latestActivity = array_slice(array_values((array) ($recentActivity ?? [])), 0, 5);
$profileSetupRows = [
    ['label' => t('common.username'), 'value' => '@' . (string) ($profileUser['username'] ?? '')],
    ['label' => t('settings.primary_goal'), 'value' => (string) ($profileUser['primary_goal_type'] ?? 'steps') . ' ' . (string) ($profileUser['primary_goal_value'] ?? $profileUser['step_goal'] ?? '')],
    ['label' => t('profile.workout_target'), 'value' => (string) ($profileUser['workout_target'] ?? 0) . '/' . strtolower(t('common.week'))],
    ['label' => t('metric.ideal_weight'), 'value' => ($profileUser['ideal_weight'] ?? null) !== null ? (string) $profileUser['ideal_weight'] . ' kg' : '-'],
    ['label' => t('profile.calorie_config'), 'value' => $calorieConfigParts !== [] ? implode(' / ', $calorieConfigParts) : '-'],
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
            </div>
        </div>
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

    <?php if ($isOwnProfile): ?>
    <article class="panel profile-data-overview<?= $activeSection !== '' ? ' hidden' : '' ?>" data-spa-home-extra <?= $activeSection !== '' ? 'hidden' : '' ?>>
        <div class="panel-head">
            <div>
                <p class="eyebrow"><?= e(t('profile.my_data')) ?></p>
                <h2><?= e(t('profile.my_data')) ?></h2>
                <p class="muted small"><?= e(t('profile.my_data_subtitle')) ?></p>
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

        <article class="panel profile-home-card">
            <div class="profile-home-card-head">
                <div>
                    <p class="eyebrow"><?= e(t('profile.current_config')) ?></p>
                    <h2><?= e(t('profile.current_config')) ?></h2>
                </div>
                <?php if ($canEditProfile): ?>
                    <a class="btn btn-ghost small" href="<?= e($profileUrl('config', ['edit' => 1])) ?>" data-spa-link><?= e(t('common.edit')) ?></a>
                <?php endif; ?>
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
            <ul class="facts" data-config-readonly data-spa-show-when-no-param="edit" <?= $configEditMode ? 'hidden' : '' ?>>
                <li><strong><?= e(t('common.username')) ?>:</strong> <?= e((string) $profileUser['username']) ?></li>
                <li><strong><?= e(t('settings.primary_goal')) ?>:</strong> <?= e((string) ($profileUser['primary_goal_type'] ?? 'steps')) ?> <?= e((string) ($profileUser['primary_goal_value'] ?? $profileUser['step_goal'])) ?></li>
                <li><strong><?= e(t('settings.primary_goals_spec')) ?>:</strong> <?= e($primaryGoalsSpec !== '' ? $primaryGoalsSpec : '-') ?></li>
                <li><strong><?= e(t('profile.workout_target')) ?>:</strong> <?= e((string) $profileUser['workout_target']) ?>/<?= e(strtolower(t('common.week'))) ?></li>
                <li><strong><?= e(t('profile.maintenance_calories')) ?>:</strong> <?= $profileUser['maintenance_calories'] !== null ? e((string) $profileUser['maintenance_calories']) . ' kcal' : '-' ?></li>
                <li><strong><?= e(t('settings.calorie_burn_goal')) ?>:</strong> <?= $profileUser['calorie_burn_goal'] !== null ? e((string) $profileUser['calorie_burn_goal']) . ' kcal' : '-' ?></li>
                <li><strong><?= e(t('settings.calorie_consumed_max')) ?>:</strong> <?= $profileUser['calorie_consumed_max'] !== null ? e((string) $profileUser['calorie_consumed_max']) . ' kcal' : '-' ?></li>
                <li><strong><?= e(t('metric.ideal_weight')) ?>:</strong> <?= $profileUser['ideal_weight'] !== null ? e((string) $profileUser['ideal_weight']) . ' kg' : '-' ?></li>
            </ul>

            <?php if ($canEditProfile): ?>
                <form method="post" action="<?= e($profileUrl('config')) ?>" class="stack" data-config-form data-spa-param-show="edit" data-spa-value="1" <?= $configEditMode ? '' : 'hidden' ?>>
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="update_profile_config">
                    <input type="hidden" name="profile_user_id" value="<?= (int) $profileUser['id'] ?>">
                    <div class="grid-inline two">
                        <label><?= e(t('common.username')) ?><input type="text" value="<?= e((string) $profileUser['username']) ?>" disabled></label>
                        <label>
                            <?= e(t('settings.primary_goal')) ?>
                            <select name="primary_goal_type">
                                <option value="steps" <?= ($profileUser['primary_goal_type'] ?? 'steps') === 'steps' ? 'selected' : '' ?>><?= e(t('metric.steps')) ?></option>
                                <option value="km" <?= ($profileUser['primary_goal_type'] ?? 'steps') === 'km' ? 'selected' : '' ?>><?= e(t('metric.distance_km')) ?></option>
                                <option value="workouts" <?= ($profileUser['primary_goal_type'] ?? 'steps') === 'workouts' ? 'selected' : '' ?>><?= e(t('metric.workouts')) ?></option>
                            </select>
                        </label>
                        <label><?= e(t('settings.primary_goal_value')) ?><input type="number" step="0.1" name="primary_goal_value" value="<?= e((string) ($profileUser['primary_goal_value'] ?? '')) ?>"></label>
                        <label>
                            <?= e(t('settings.primary_goals_spec')) ?>
                            <input type="text" name="primary_goals_spec" value="<?= e($primaryGoalsSpec) ?>" placeholder="<?= e(t('settings.primary_goals_spec_placeholder')) ?>">
                            <small class="muted"><?= e(t('settings.primary_goals_spec_hint')) ?></small>
                        </label>
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
                <?php if (!empty($canExportProfilePdf)): ?>
                    <button class="btn btn-primary" type="button" data-profile-pdf-export><?= e(t('profile.export_pdf')) ?></button>
                <?php endif; ?>
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
<script id="profile-pdf-data" type="application/json"><?= e(json_encode($profileExportPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}') ?></script>
<?php endif; ?>
