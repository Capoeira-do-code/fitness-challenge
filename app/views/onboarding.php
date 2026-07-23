<?php

declare(strict_types=1);

$step = (string) ($onboardingStep ?? 'goals');
$stepIndex = (int) ($onboardingStepIndex ?? 0);
$furthestStepIndex = max($stepIndex, (int) ($onboardingFurthestIndex ?? $stepIndex));
$steps = (array) ($onboardingSteps ?? ['goals', 'profile', 'privacy', 'telegram', 'challenge', 'teams', 'install']);
$totalSteps = count($steps);
$profileAvatarUrl = avatar_url($currentUser);
$profileCoverPath = trim((string) ($currentUser['profile_cover_path'] ?? ''));
$profileCoverUrl = $profileCoverPath !== '' ? media_url($profileCoverPath) : '';
$hasStepGoal = (int) ($currentUser['step_goal'] ?? 0) > 0;
$hasWorkoutGoal = (int) ($currentUser['workout_target'] ?? 0) > 0;
$onboardingGoalInput = is_array($_SESSION['onboarding_goal_input'] ?? null) ? (array) $_SESSION['onboarding_goal_input'] : [];
if ($onboardingGoalInput !== []) {
    $hasStepGoal = isset($onboardingGoalInput['enable_step_goal']);
    $hasWorkoutGoal = isset($onboardingGoalInput['enable_workout_goal']);
}
$onboardingMetricDefinitions = metric_preference_definitions($GLOBALS['pdo'], $currentUser);
$onboardingEnabledMetrics = metric_enabled_keys($GLOBALS['pdo'], $currentUser);
$onboardingRequestedMetrics = array_values(array_unique(array_map(
    'strval',
    (array) ($onboardingGoalInput['enabled_metrics'] ?? $onboardingEnabledMetrics)
)));
$onboardingDailyGoals = user_primary_goals($currentUser);
if (array_key_exists('primary_goals_spec', $onboardingGoalInput)) {
    $onboardingDailyGoals = parse_primary_goals_spec((string) $onboardingGoalInput['primary_goals_spec'], false);
}
$onboardingExtraGoals = array_values(array_filter(
    $onboardingDailyGoals,
    static fn(array $goal): bool => !($hasStepGoal && (string) ($goal['type'] ?? '') === 'steps')
));
$onboardingGoalOptions = [
    ['value' => 'steps', 'label' => (string) t('metric.steps'), 'step' => '1', 'placeholder' => '10000'],
    ['value' => 'km', 'label' => (string) t('metric.distance_km'), 'step' => '0.1', 'placeholder' => '5'],
    ['value' => 'workouts', 'label' => (string) t('metric.workouts'), 'step' => '1', 'placeholder' => '1'],
];
$onboardingExtraGoalsSpec = format_primary_goals_spec($onboardingExtraGoals);
$onboardingPrivacyVisibility = privacy_normalize((string) ($onboardingPrivacyVisibility ?? ($currentUser['profile_visibility'] ?? 'public')));
$onboardingDataVisibility = is_array($onboardingDataVisibility ?? null) ? $onboardingDataVisibility : privacy_data_preferences($currentUser);
$onboardingTelegram = is_array($onboardingTelegramSettings ?? null) ? $onboardingTelegramSettings : [];
$onboardingTelegramAvailable = telegram_is_enabled($onboardingTelegram);
$onboardingTelegramLinked = trim((string) ($currentUser['telegram_chat_id'] ?? '')) !== '';
$onboardingTelegramLinkCode = trim((string) ($currentUser['telegram_link_code'] ?? ''));
$onboardingTelegramDeepLink = telegram_deep_link($onboardingTelegram, $onboardingTelegramLinkCode);
$onboardingTelegramTimezone = trim((string) ($currentUser['telegram_tz'] ?? ''));
$onboardingTelegramTimezones = array_values(array_unique(array_filter([
    $onboardingTelegramTimezone,
    'Europe/Madrid',
    'Europe/London',
    'Europe/Rome',
    'America/Mexico_City',
    'America/Argentina/Buenos_Aires',
    'America/New_York',
    'America/Los_Angeles',
    'UTC',
])));
$onboardingGoal = is_array($onboardingGoal ?? null) ? $onboardingGoal : [];
$onboardingChallengeTypeCandidate = (string) ($onboardingGoal['target_type'] ?? 'steps');
$onboardingChallengeType = in_array($onboardingChallengeTypeCandidate, ['steps', 'km', 'workouts'], true)
    ? $onboardingChallengeTypeCandidate
    : 'steps';
$onboardingInstallUrl = request_app_base_url();
$onboardingAppName = trim((string) (app_setting($GLOBALS['pdo'], 'app_name', (string) ($config['app_name'] ?? 'Fitness Challenge')) ?? 'Fitness Challenge'));
$onboardingAppName = $onboardingAppName !== '' ? $onboardingAppName : 'Fitness Challenge';
$onboardingAppIconPath = trim((string) (app_setting($GLOBALS['pdo'], 'app_icon_path', '') ?? ''));
$onboardingAppIconUrl = $onboardingAppIconPath !== '' && resolve_media_storage_path((array) $config, $onboardingAppIconPath) !== null
    ? '/?page=app_icon'
    : '/?page=app_icon_default&size=192';
try {
    $defaultChallengeDate = (new DateTimeImmutable('+30 days'))->format('Y-m-d');
} catch (Throwable) {
    $defaultChallengeDate = to_date(null);
}
?>
<section class="onboarding-shell" data-onboarding-step="<?= e($step) ?>">
    <header class="onboarding-header">
        <div class="onboarding-brand"><span aria-hidden="true"><?= activity_icon_svg('spark') ?></span><div><p class="eyebrow"><?= e(t('onboarding.welcome', ['name' => (string) $currentUser['display_name']])) ?></p><h1><?= e(t('onboarding.title')) ?></h1></div></div>
        <a class="onboarding-logout" href="/?page=logout"><?= e(t('nav.logout')) ?></a>
    </header>

    <nav class="onboarding-progress" aria-label="<?= e(t('onboarding.progress')) ?>" style="--onboarding-step-count: <?= max(1, $totalSteps) ?>">
        <?php foreach ($steps as $index => $stepKey): ?>
            <?php $progressClass = 'onboarding-progress-item' . ($index === $stepIndex ? ' is-current' : '') . ($index < $furthestStepIndex ? ' is-complete' : ''); ?>
            <?php if ($index <= $furthestStepIndex && $index !== $stepIndex): ?>
                <a class="<?= e($progressClass) ?> is-reachable" href="/?page=onboarding&amp;step=<?= e($stepKey) ?>" aria-label="<?= e(t('onboarding.back_to', ['step' => t('onboarding.step_' . $stepKey)])) ?>">
                    <b><?= $index < $furthestStepIndex ? '✓' : $index + 1 ?></b><small><?= e(t('onboarding.step_' . $stepKey)) ?></small>
                </a>
            <?php else: ?>
                <span class="<?= e($progressClass) ?>" <?= $index === $stepIndex ? 'aria-current="step"' : 'aria-disabled="true"' ?>>
                    <b><?= $index < $furthestStepIndex ? '✓' : $index + 1 ?></b><small><?= e(t('onboarding.step_' . $stepKey)) ?></small>
                </span>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>

    <?php if ($stepIndex > 0): ?>
        <?php $previousStep = (string) $steps[$stepIndex - 1]; ?>
        <a class="onboarding-previous" href="/?page=onboarding&amp;step=<?= e($previousStep) ?>"><span aria-hidden="true">&larr;</span><?= e(t('onboarding.back_to', ['step' => t('onboarding.step_' . $previousStep)])) ?></a>
    <?php endif; ?>

    <article class="onboarding-card onboarding-card-<?= e($step) ?>">
        <div class="onboarding-step-copy">
            <span class="onboarding-step-icon" aria-hidden="true"><?= activity_icon_svg(match ($step) { 'profile' => 'image', 'privacy' => 'shield', 'telegram' => 'bell', 'challenge' => 'target', 'teams' => 'users', 'install' => 'plus', default => 'analytics' }) ?></span>
            <div><p class="eyebrow"><?= e(t('onboarding.step_count', ['current' => $stepIndex + 1, 'total' => $totalSteps])) ?></p><h2><?= e(t('onboarding.' . $step . '_title')) ?></h2><p><?= e(t('onboarding.' . $step . '_hint')) ?></p></div>
        </div>

        <form method="post" action="/?page=onboarding&amp;step=<?= e($step) ?>" class="onboarding-form" <?= $step === 'profile' ? 'enctype="multipart/form-data"' : '' ?>>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="step" value="<?= e($step) ?>">

            <?php if ($step === 'goals'): ?>
                <div class="onboarding-optional-callout"><span aria-hidden="true"><?= activity_icon_svg('spark') ?></span><div><strong><?= e(t('onboarding.all_optional')) ?></strong><small><?= e(t('onboarding.all_optional_hint')) ?></small></div></div>
                <div class="onboarding-goal-options">
                    <section class="onboarding-goal-option<?= $hasStepGoal ? ' is-enabled' : '' ?>" data-onboarding-optional-card>
                        <label class="onboarding-option-toggle"><input type="checkbox" name="enable_step_goal" value="1" <?= $hasStepGoal ? 'checked' : '' ?> data-onboarding-optional-toggle data-primary-goal-reserves="steps"><span class="onboarding-option-icon" aria-hidden="true"><?= activity_icon_svg('run') ?></span><span><strong><?= e(t('onboarding.daily_steps')) ?></strong><small><?= e(t('onboarding.daily_steps_hint')) ?></small></span><i aria-hidden="true"></i></label>
                        <div class="onboarding-option-value" data-onboarding-optional-content <?= $hasStepGoal ? '' : 'hidden' ?>><label><span><?= e(t('onboarding.target_optional')) ?></span><input type="text" name="step_goal" inputmode="numeric" autocomplete="off" value="<?= e((string) ($onboardingGoalInput['step_goal'] ?? ($hasStepGoal ? (int) $currentUser['step_goal'] : 10000))) ?>" <?= $hasStepGoal ? '' : 'disabled' ?>></label></div>
                    </section>
                    <section class="onboarding-goal-option<?= $hasWorkoutGoal ? ' is-enabled' : '' ?>" data-onboarding-optional-card>
                        <label class="onboarding-option-toggle"><input type="checkbox" name="enable_workout_goal" value="1" <?= $hasWorkoutGoal ? 'checked' : '' ?> data-onboarding-optional-toggle><span class="onboarding-option-icon" aria-hidden="true"><?= activity_icon_svg('dumbbell') ?></span><span><strong><?= e(t('onboarding.weekly_workouts')) ?></strong><small><?= e(t('onboarding.weekly_workouts_hint')) ?></small></span><i aria-hidden="true"></i></label>
                        <div class="onboarding-option-value" data-onboarding-optional-content <?= $hasWorkoutGoal ? '' : 'hidden' ?>><label><span><?= e(t('onboarding.target_optional')) ?></span><input type="number" name="workout_target" min="1" max="14" value="<?= e((string) ($onboardingGoalInput['workout_target'] ?? ($hasWorkoutGoal ? (int) $currentUser['workout_target'] : 3))) ?>" <?= $hasWorkoutGoal ? '' : 'disabled' ?>></label></div>
                    </section>
                    <section class="onboarding-goal-option onboarding-multi-goals">
                        <div class="onboarding-primary-head"><span class="onboarding-option-icon" aria-hidden="true"><?= activity_icon_svg('target') ?></span><span><strong><?= e(t('settings.primary_goals_spec')) ?></strong><small><?= e(t('settings.primary_goals_spec_hint')) ?></small></span></div>
                        <input type="hidden" name="primary_goals_spec" value="<?= e($onboardingExtraGoalsSpec) ?>" data-primary-goals-spec-input>
                        <div class="primary-goals-editor onboarding-primary-goals-editor" data-primary-goals-editor>
                            <div class="primary-goals-list" data-primary-goals-list>
                                <?php foreach ($onboardingExtraGoals as $goal): ?>
                                    <?php $goalType = (string) ($goal['type'] ?? 'km'); $goalValue = (float) ($goal['value'] ?? 0); ?>
                                    <div class="primary-goal-row" data-primary-goal-row>
                                        <label><span><?= e(t('onboarding.metric_optional')) ?></span><select data-primary-goal-type><?php foreach ($onboardingGoalOptions as $option): ?><option value="<?= e($option['value']) ?>" data-step="<?= e($option['step']) ?>" data-placeholder="<?= e($option['placeholder']) ?>" <?= $goalType === $option['value'] ? 'selected' : '' ?>><?= e($option['label']) ?></option><?php endforeach; ?></select></label>
                                        <label><span><?= e(t('settings.primary_goal_value')) ?></span><input type="number" min="0.1" step="<?= e($goalType === 'km' ? '0.1' : '1') ?>" value="<?= e($goalType === 'km' ? rtrim(rtrim(number_format($goalValue, 2, '.', ''), '0'), '.') : (string) (int) round($goalValue)) ?>" data-primary-goal-value></label>
                                        <button class="btn btn-ghost primary-goal-remove" type="button" data-primary-goal-remove aria-label="<?= e(t('settings.remove_primary_goal')) ?>">&times;</button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <button class="btn btn-ghost primary-goal-add" type="button" data-primary-goal-add data-label-empty="<?= e(t('settings.add_first_goal')) ?>" data-label-more="<?= e(t('settings.add_primary_goal')) ?>"><span aria-hidden="true">+</span><span data-primary-goal-add-label><?= e($onboardingExtraGoals === [] ? t('settings.add_first_goal') : t('settings.add_primary_goal')) ?></span></button>
                            <template data-primary-goal-template><div class="primary-goal-row" data-primary-goal-row><label><span><?= e(t('onboarding.metric_optional')) ?></span><select data-primary-goal-type><?php foreach ($onboardingGoalOptions as $option): ?><option value="<?= e($option['value']) ?>" data-step="<?= e($option['step']) ?>" data-placeholder="<?= e($option['placeholder']) ?>"><?= e($option['label']) ?></option><?php endforeach; ?></select></label><label><span><?= e(t('settings.primary_goal_value')) ?></span><input type="number" min="0.1" step="1" data-primary-goal-value></label><button class="btn btn-ghost primary-goal-remove" type="button" data-primary-goal-remove aria-label="<?= e(t('settings.remove_primary_goal')) ?>">&times;</button></div></template>
                        </div>
                    </section>
                    <section class="onboarding-goal-option onboarding-multi-goals">
                        <div class="onboarding-primary-head"><span class="onboarding-option-icon" aria-hidden="true"><?= activity_icon_svg('sliders') ?></span><span><strong><?= e(t('settings.tracked_metrics')) ?></strong><small><?= e(t('settings.tracked_metrics_hint')) ?></small></span></div>
                        <div class="onboarding-metric-preferences">
                            <?php foreach ($onboardingMetricDefinitions as $metricKey => $metricDefinition): ?>
                                <?php if (in_array($metricKey, ['steps', 'workouts', 'distance'], true)) {
                                    continue;
                                } ?>
                                <label class="onboarding-metric-toggle">
                                    <input type="checkbox" name="enabled_metrics[]" value="<?= e($metricKey) ?>" <?= in_array($metricKey, $onboardingRequestedMetrics, true) ? 'checked' : '' ?>>
                                    <span aria-hidden="true"><?= activity_icon_svg((string) ($metricDefinition['icon'] ?? 'check')) ?></span>
                                    <strong><?= e((string) ($metricDefinition['label'] ?? $metricKey)) ?></strong>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="grid-inline two onboarding-metric-targets">
                            <label><?= e(t('settings.calorie_burn_goal')) ?><input type="number" min="1" step="1" name="calorie_burn_goal" value="<?= e((string) ($onboardingGoalInput['calorie_burn_goal'] ?? ($currentUser['calorie_burn_goal'] ?? ''))) ?>"></label>
                            <label><?= e(t('settings.calorie_consumed_max')) ?><input type="number" min="1" step="1" name="calorie_consumed_max" value="<?= e((string) ($onboardingGoalInput['calorie_consumed_max'] ?? ($currentUser['calorie_consumed_max'] ?? ''))) ?>"></label>
                            <label><?= e(t('settings.ideal_weight')) ?><input type="number" min="25" max="400" step="0.1" name="ideal_weight" value="<?= e((string) ($onboardingGoalInput['ideal_weight'] ?? ($currentUser['ideal_weight'] ?? ''))) ?>"></label>
                        </div>
                    </section>
                </div>
            <?php elseif ($step === 'profile'): ?>
                <div class="onboarding-media-grid">
                    <label class="onboarding-media-picker onboarding-avatar-picker">
                        <span class="onboarding-media-preview" data-onboarding-preview="avatar"><?php if ($profileAvatarUrl !== ''): ?><img src="<?= e($profileAvatarUrl) ?>" alt=""><?php else: ?><b><?= e(initials_for((string) $currentUser['display_name'])) ?></b><?php endif; ?></span>
                        <span><strong><?= e(t('onboarding.avatar')) ?></strong><small><?= e(t('onboarding.avatar_hint')) ?></small></span>
                        <input class="sr-only" type="file" name="avatar" accept="image/jpeg,image/png,image/webp" data-onboarding-image-input="avatar">
                    </label>
                    <label class="onboarding-media-picker onboarding-cover-picker">
                        <span class="onboarding-media-preview" data-onboarding-preview="cover"><?php if ($profileCoverUrl !== ''): ?><img src="<?= e($profileCoverUrl) ?>" alt=""><?php else: ?><span aria-hidden="true"><?= activity_icon_svg('image') ?></span><?php endif; ?></span>
                        <span><strong><?= e(t('onboarding.cover')) ?></strong><small><?= e(t('onboarding.cover_hint')) ?></small></span>
                        <input class="sr-only" type="file" name="cover" accept="image/jpeg,image/png,image/webp" data-onboarding-image-input="cover">
                    </label>
                </div>
            <?php elseif ($step === 'privacy'): ?>
                <div class="onboarding-privacy-stack" data-privacy-controls>
                    <section class="onboarding-privacy-section">
                        <div class="onboarding-privacy-heading"><span aria-hidden="true"><?= activity_icon_svg('users') ?></span><div><strong><?= e(t('privacy.default_visibility')) ?></strong><small><?= e(t('privacy.default_visibility_hint')) ?></small></div></div>
                        <div class="onboarding-privacy-options">
                            <?php foreach (['public', 'friends', 'private'] as $privacyValue): ?>
                                <label class="onboarding-privacy-option"><input type="radio" name="profile_visibility" value="<?= e($privacyValue) ?>" <?= $onboardingPrivacyVisibility === $privacyValue ? 'checked' : '' ?> data-privacy-default><span><strong><?= e(t('privacy.' . $privacyValue)) ?></strong><small><?= e(t('privacy.' . $privacyValue . '_hint')) ?></small></span><i aria-hidden="true"></i></label>
                            <?php endforeach; ?>
                        </div>
                    </section>
                    <section class="onboarding-privacy-section">
                        <div class="onboarding-privacy-heading"><span aria-hidden="true"><?= activity_icon_svg('shield') ?></span><div><strong><?= e(t('privacy.data_controls')) ?></strong><small><?= e(t('privacy.data_controls_hint')) ?></small></div></div>
                        <div class="onboarding-data-privacy-list">
                            <?php foreach (['weight' => 'weight', 'steps' => 'footsteps', 'distance' => 'run', 'workouts' => 'dumbbell', 'nutrition' => 'flame'] as $privacyKey => $privacyIcon): ?>
                                <label class="onboarding-data-privacy-row"><span class="onboarding-data-privacy-icon" aria-hidden="true"><?= activity_icon_svg($privacyIcon) ?></span><span><strong><?= e(t('privacy.data_' . $privacyKey)) ?></strong><small><?= e(t('privacy.data_' . $privacyKey . '_hint')) ?></small></span><select name="data_visibility[<?= e($privacyKey) ?>]" aria-label="<?= e(t('privacy.data_' . $privacyKey)) ?>" data-privacy-data><?php foreach (['public', 'friends', 'private'] as $privacyValue): ?><option value="<?= e($privacyValue) ?>" <?= ($onboardingDataVisibility[$privacyKey] ?? $onboardingPrivacyVisibility) === $privacyValue ? 'selected' : '' ?>><?= e(t('privacy.' . $privacyValue)) ?></option><?php endforeach; ?></select></label>
                            <?php endforeach; ?>
                        </div>
                    </section>
                </div>
            <?php elseif ($step === 'telegram'): ?>
                <div class="onboarding-telegram-stack">
                    <section class="onboarding-telegram-connect<?= $onboardingTelegramLinked ? ' is-linked' : '' ?><?= !$onboardingTelegramAvailable ? ' is-unavailable' : '' ?>">
                        <span class="onboarding-telegram-mark" aria-hidden="true"><?= activity_icon_svg($onboardingTelegramLinked ? 'check' : 'link') ?></span>
                        <div class="onboarding-telegram-connect-copy">
                            <strong><?= e(t($onboardingTelegramLinked ? 'settings.telegram_linked' : 'onboarding.telegram_connect_title')) ?></strong>
                            <small><?= e(t($onboardingTelegramLinked ? 'onboarding.telegram_linked_hint' : ($onboardingTelegramAvailable ? 'onboarding.telegram_connect_hint' : 'settings.telegram_unavailable'))) ?></small>
                        </div>
                        <?php if ($onboardingTelegramAvailable && !$onboardingTelegramLinked && $onboardingTelegramLinkCode === ''): ?>
                            <button class="btn btn-primary onboarding-telegram-connect-button" type="submit" name="onboarding_telegram_action" value="generate_link"><?= e(t('settings.telegram_link')) ?></button>
                        <?php elseif ($onboardingTelegramAvailable && !$onboardingTelegramLinked && $onboardingTelegramDeepLink !== ''): ?>
                            <a class="btn btn-primary onboarding-telegram-connect-button" href="<?= e($onboardingTelegramDeepLink) ?>" target="_blank" rel="noopener"><?= e(t('settings.telegram_open_bot')) ?></a>
                        <?php elseif ($onboardingTelegramAvailable && !$onboardingTelegramLinked && $onboardingTelegramLinkCode !== ''): ?>
                            <code class="onboarding-telegram-code">/start <?= e($onboardingTelegramLinkCode) ?></code>
                        <?php elseif ($onboardingTelegramLinked): ?>
                            <span class="onboarding-telegram-status"><i aria-hidden="true"></i><?= e(t('settings.integration_linked')) ?></span>
                        <?php endif; ?>
                    </section>

                    <?php if ($onboardingTelegramAvailable): ?>
                        <?php if (!$onboardingTelegramLinked && $onboardingTelegramLinkCode !== ''): ?>
                            <p class="onboarding-telegram-link-note"><?= e(t('settings.telegram_link_steps')) ?> <a href="/?page=onboarding&amp;step=telegram"><?= e(t('onboarding.telegram_check_link')) ?></a></p>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($onboardingTelegramAvailable && $onboardingTelegramLinked): ?>
                        <fieldset class="onboarding-telegram-group">
                            <legend><?= e(t('onboarding.telegram_notifications_title')) ?></legend>
                            <p><?= e(t('onboarding.telegram_notifications_hint')) ?></p>
                            <div class="onboarding-telegram-options">
                                <?php foreach ([
                                    ['telegram_reminders_enabled', 'settings.telegram_reminders', 'dumbbell', 0],
                                    ['telegram_motivation_enabled', 'settings.telegram_motivation', 'spark', 0],
                                    ['telegram_notify_duel', 'settings.telegram_notify_duel', 'sword', 1],
                                    ['telegram_notify_streak', 'settings.telegram_notify_streak', 'flame', 1],
                                    ['telegram_notify_social', 'settings.telegram_notify_social', 'users', 1],
                                ] as [$notificationName, $notificationLabel, $notificationIcon, $notificationDefault]): ?>
                                    <label class="onboarding-telegram-option"><input type="checkbox" name="<?= e($notificationName) ?>" value="1" <?= (int) ($currentUser[$notificationName] ?? $notificationDefault) === 1 ? 'checked' : '' ?>><span aria-hidden="true"><?= activity_icon_svg($notificationIcon) ?></span><strong><?= e(t($notificationLabel)) ?></strong><i aria-hidden="true"></i></label>
                                <?php endforeach; ?>
                            </div>
                        </fieldset>

                        <fieldset class="onboarding-telegram-group onboarding-telegram-delivery">
                            <legend><?= e(t('onboarding.telegram_delivery_title')) ?></legend>
                            <div class="onboarding-telegram-fields">
                                <label><span><?= e(t('settings.telegram_time')) ?></span><input type="time" name="telegram_reminder_time" value="<?= e((string) ($currentUser['telegram_reminder_time'] ?? '20:00')) ?>"></label>
                                <label><span><?= e(t('settings.telegram_tz')) ?></span><select name="telegram_tz"><option value=""><?= e(t('onboarding.telegram_server_time')) ?></option><?php foreach ($onboardingTelegramTimezones as $timezone): ?><option value="<?= e($timezone) ?>" <?= $onboardingTelegramTimezone === $timezone ? 'selected' : '' ?>><?= e($timezone) ?></option><?php endforeach; ?></select></label>
                            </div>
                            <label class="onboarding-telegram-inline-toggle"><input type="checkbox" name="telegram_weekends_off" value="1" <?= (int) ($currentUser['telegram_weekends_off'] ?? 0) === 1 ? 'checked' : '' ?>><span><?= e(t('settings.telegram_weekends_off')) ?></span><i aria-hidden="true"></i></label>
                            <details class="onboarding-telegram-advanced">
                                <summary><?= e(t('onboarding.telegram_quiet_title')) ?></summary>
                                <div class="onboarding-telegram-fields">
                                    <label><span><?= e(t('settings.telegram_quiet_start')) ?></span><input type="time" name="telegram_quiet_start" value="<?= e((string) ($currentUser['telegram_quiet_start'] ?? '')) ?>"></label>
                                    <label><span><?= e(t('settings.telegram_quiet_end')) ?></span><input type="time" name="telegram_quiet_end" value="<?= e((string) ($currentUser['telegram_quiet_end'] ?? '')) ?>"></label>
                                </div>
                                <p><?= e(t('settings.telegram_quiet_hint')) ?></p>
                            </details>
                        </fieldset>
                    <?php endif; ?>
                </div>
            <?php elseif ($step === 'challenge'): ?>
                <div class="onboarding-fields onboarding-challenge-fields">
                    <label class="onboarding-field-wide"><span><?= e(t('onboarding.challenge_name')) ?></span><input type="text" name="title" maxlength="120" placeholder="<?= e(t('onboarding.challenge_placeholder')) ?>" value="<?= e((string) ($onboardingGoal['title'] ?? '')) ?>"></label>
                    <label><span><?= e(t('onboarding.challenge_metric')) ?></span><select name="target_type"><option value="steps" <?= $onboardingChallengeType === 'steps' ? 'selected' : '' ?>><?= e(t('metric.steps')) ?></option><option value="km" <?= $onboardingChallengeType === 'km' ? 'selected' : '' ?>><?= e(t('metric.distance_km')) ?></option><option value="workouts" <?= $onboardingChallengeType === 'workouts' ? 'selected' : '' ?>><?= e(t('metric.workouts')) ?></option></select></label>
                    <label><span><?= e(t('onboarding.challenge_target')) ?></span><input type="number" name="target_value" min="0.1" step="0.1" value="<?= e((string) ($onboardingGoal['target_value'] ?? 10000)) ?>"></label>
                    <label class="onboarding-field-wide"><span><?= e(t('onboarding.challenge_due')) ?> <em><?= e(t('onboarding.optional')) ?></em></span><input type="text" name="due_date" inputmode="numeric" autocomplete="off" placeholder="DD/MM/AAAA" pattern="[0-9]{2}/[0-9]{2}/[0-9]{4}" value="<?= e(format_date_eu((string) ($onboardingGoal['due_date'] ?? $defaultChallengeDate))) ?>" data-eu-date-input><small><?= e(t('onboarding.challenge_date_hint')) ?></small></label>
                </div>
            <?php elseif ($step === 'teams'): ?>
                <?php if ((array) ($joinableTeams ?? []) === []): ?>
                    <div class="onboarding-empty"><span aria-hidden="true"><?= activity_icon_svg('users') ?></span><strong><?= e(t('onboarding.no_teams')) ?></strong><p><?= e(t('onboarding.no_teams_hint')) ?></p></div>
                <?php else: ?>
                    <div class="onboarding-team-list">
                        <?php foreach ((array) $joinableTeams as $team): ?>
                            <label class="onboarding-team-option">
                                <input type="checkbox" name="team_ids[]" value="<?= (int) $team['id'] ?>">
                                <span class="onboarding-team-avatar" aria-hidden="true"><?= e(initials_for((string) $team['name'])) ?></span>
                                <span><strong><?= e((string) $team['name']) ?></strong><small><?= e((string) ($team['description'] ?? '')) ?></small></span>
                                <em><?= e((string) ($team['join_mode'] ?? 'closed') === 'open' ? t('onboarding.team_open') : t('onboarding.team_request')) ?></em>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="onboarding-team-note"><?= e(t('onboarding.teams_multiple_hint')) ?></p>
                <?php endif; ?>
            <?php elseif ($step === 'install'): ?>
                <div class="onboarding-install" data-pwa-install-reminder data-install-default-label="<?= e(t('onboarding.install_not_installed')) ?>" data-install-ready-label="<?= e(t('onboarding.install_ready')) ?>" data-install-installed-label="<?= e(t('onboarding.install_installed')) ?>">
                    <section class="onboarding-install-hero">
                        <div class="onboarding-install-phone" aria-hidden="true">
                            <span class="onboarding-install-phone-speaker"></span>
                            <span class="onboarding-install-app-icon"><img src="<?= e($onboardingAppIconUrl) ?>" alt=""></span>
                            <span class="onboarding-install-app-name"><?= e($onboardingAppName) ?></span>
                            <span class="onboarding-install-app-bars"><i></i><i></i><i></i></span>
                        </div>
                        <div class="onboarding-install-copy">
                            <span class="onboarding-install-status" data-pwa-install-status><i aria-hidden="true"></i><b><?= e(t('onboarding.install_not_installed')) ?></b></span>
                            <h3><?= e(t('onboarding.install_app_title')) ?></h3>
                            <p><?= e(t('onboarding.install_app_hint')) ?></p>
                            <button class="btn btn-primary onboarding-install-native" type="button" data-pwa-install-button hidden><span aria-hidden="true">＋</span><?= e(t('onboarding.install_now')) ?></button>
                        </div>
                    </section>

                    <section class="onboarding-install-instructions" data-pwa-install-instructions="ios" hidden>
                        <div class="onboarding-install-instruction-head"><span aria-hidden="true"></span><div><strong><?= e(t('onboarding.install_ios_title')) ?></strong><small><?= e(t('onboarding.install_ios_hint')) ?></small></div></div>
                        <ol><li><b>1</b><span><?= e(t('onboarding.install_ios_step_share')) ?></span><i aria-hidden="true">□↑</i></li><li><b>2</b><span><?= e(t('onboarding.install_ios_step_home')) ?></span><i aria-hidden="true">＋</i></li><li><b>3</b><span><?= e(t('onboarding.install_ios_step_confirm')) ?></span><i aria-hidden="true">✓</i></li></ol>
                    </section>

                    <section class="onboarding-install-instructions" data-pwa-install-instructions="android" hidden>
                        <div class="onboarding-install-instruction-head"><span aria-hidden="true"><?= activity_icon_svg('plus') ?></span><div><strong><?= e(t('onboarding.install_android_title')) ?></strong><small><?= e(t('onboarding.install_android_hint')) ?></small></div></div>
                        <ol><li><b>1</b><span><?= e(t('onboarding.install_android_step_menu')) ?></span><i aria-hidden="true">⋮</i></li><li><b>2</b><span><?= e(t('onboarding.install_android_step_install')) ?></span><i aria-hidden="true">＋</i></li></ol>
                    </section>

                    <section class="onboarding-install-instructions onboarding-install-desktop" data-pwa-install-instructions="desktop" hidden>
                        <div class="onboarding-install-instruction-head"><span aria-hidden="true"><?= activity_icon_svg('link') ?></span><div><strong><?= e(t('onboarding.install_phone_title')) ?></strong><small><?= e(t('onboarding.install_phone_hint')) ?></small></div></div>
                        <div class="onboarding-install-url"><input type="text" readonly value="<?= e($onboardingInstallUrl) ?>" data-pwa-install-url><button type="button" data-pwa-copy-url data-copy-label="<?= e(t('admin.invite_copy')) ?>" data-copied-label="<?= e(t('admin.invite_copied')) ?>"><?= e(t('admin.invite_copy')) ?></button></div>
                    </section>

                    <section class="onboarding-install-done" data-pwa-install-done hidden><span aria-hidden="true"><?= activity_icon_svg('check') ?></span><div><strong><?= e(t('onboarding.install_done_title')) ?></strong><small><?= e(t('onboarding.install_done_hint')) ?></small></div></section>
                    <p class="onboarding-install-note"><span aria-hidden="true"><?= activity_icon_svg('spark') ?></span><?= e(t('onboarding.install_optional_hint')) ?></p>
                </div>
            <?php endif; ?>

            <div class="onboarding-actions">
                <button class="btn btn-ghost" type="submit" name="action" value="skip_onboarding_step" formnovalidate><?= e($step === 'teams' ? t('onboarding.no_team') : ($step === 'install' ? t('onboarding.install_not_now') : t('onboarding.do_later'))) ?></button>
                <button class="btn btn-primary" type="submit" name="action" value="save_onboarding_step"><?= e($step === 'install' ? t('onboarding.finish') : t('common.continue')) ?></button>
            </div>
            <p class="onboarding-later-note"><?= e(t('onboarding.later_note')) ?></p>
        </form>
    </article>
</section>
