<?php

declare(strict_types=1);

$step = (string) ($onboardingStep ?? 'profile');
$stepIndex = (int) ($onboardingStepIndex ?? 0);
$furthestStepIndex = max($stepIndex, (int) ($onboardingFurthestIndex ?? $stepIndex));
$steps = (array) ($onboardingSteps ?? ['profile', 'privacy', 'telegram', 'goals', 'teams']);
$totalSteps = count($steps);
$profileAvatarUrl = avatar_url($currentUser);
$profileCoverPath = trim((string) ($currentUser['profile_cover_path'] ?? ''));
$profileCoverUrl = $profileCoverPath !== '' ? media_url($profileCoverPath) : '';
$hasStepGoal = (int) ($currentUser['step_goal'] ?? 0) > 0;
$hasWorkoutGoal = (int) ($currentUser['workout_target'] ?? 0) > 0;
$hasDistanceGoal = false;
$onboardingGoalInput = is_array($_SESSION['onboarding_goal_input'] ?? null) ? (array) $_SESSION['onboarding_goal_input'] : [];
if ($onboardingGoalInput !== []) {
    $hasStepGoal = isset($onboardingGoalInput['enable_step_goal']);
    $hasWorkoutGoal = isset($onboardingGoalInput['enable_workout_goal']);
}
$onboardingMetricDefinitions = metric_preference_definitions($GLOBALS['pdo'], $currentUser);
$onboardingEnabledMetrics = metric_enabled_keys($GLOBALS['pdo'], $currentUser);
$onboardingRequestedMetrics = array_values(array_unique(array_map(
    'strval',
    (array) ($onboardingGoalInput['enabled_metrics'] ?? array_keys($onboardingMetricDefinitions))
)));
$onboardingDailyGoals = user_primary_goals($currentUser);
if (array_key_exists('primary_goals_spec', $onboardingGoalInput)) {
    $onboardingDailyGoals = parse_primary_goals_spec((string) $onboardingGoalInput['primary_goals_spec'], false);
}
$distanceGoalValue = 5.0;
foreach ($onboardingDailyGoals as $onboardingDailyGoal) {
    if ((string) ($onboardingDailyGoal['type'] ?? '') === 'km') {
        $hasDistanceGoal = true;
        $distanceGoalValue = max(0.1, (float) ($onboardingDailyGoal['value'] ?? 5));
        break;
    }
}
if ($onboardingGoalInput !== []) {
    $hasDistanceGoal = isset($onboardingGoalInput['enable_distance_goal']);
    $distanceGoalValue = max(0.1, (float) ($onboardingGoalInput['distance_goal'] ?? $distanceGoalValue));
}
$onboardingExtraGoals = array_values(array_filter(
    $onboardingDailyGoals,
    static fn(array $goal): bool => !($hasStepGoal && (string) ($goal['type'] ?? '') === 'steps')
));
$onboardingGoalOptions = [
    ['value' => 'steps', 'label' => (string) t('metric.steps'), 'step' => '1', 'placeholder' => '10000'],
    ['value' => 'km', 'label' => (string) t('metric.distance_km'), 'step' => '0.1', 'placeholder' => '5'],
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
            <span class="onboarding-step-icon" aria-hidden="true"><?= activity_icon_svg(match ($step) { 'profile' => 'image', 'privacy' => 'shield', 'telegram' => 'bell', 'teams' => 'users', default => 'analytics' }) ?></span>
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
                    <section class="onboarding-goal-option<?= $hasDistanceGoal ? ' is-enabled' : '' ?>" data-onboarding-optional-card>
                        <label class="onboarding-option-toggle"><input type="checkbox" name="enable_distance_goal" value="1" <?= $hasDistanceGoal ? 'checked' : '' ?> data-onboarding-optional-toggle><span class="onboarding-option-icon" aria-hidden="true"><?= activity_icon_svg('run') ?></span><span><strong><?= e(t('metric.distance_km')) ?></strong><small><?= e(t('onboarding.distance_goal_hint')) ?></small></span><i aria-hidden="true"></i></label>
                        <div class="onboarding-option-value" data-onboarding-optional-content <?= $hasDistanceGoal ? '' : 'hidden' ?>><label><span><?= e(t('onboarding.target_optional')) ?></span><input type="number" name="distance_goal" min="0.1" step="0.1" value="<?= e(rtrim(rtrim(number_format($distanceGoalValue, 2, '.', ''), '0'), '.')) ?>" <?= $hasDistanceGoal ? '' : 'disabled' ?>></label></div>
                    </section>
                    <details class="onboarding-goals-more">
                        <summary><span><strong><?= e(t('settings.tracked_metrics')) ?></strong><small><?= e(t('onboarding.metrics_more_hint')) ?></small></span><b aria-hidden="true">⌄</b></summary>
                    <section class="onboarding-goal-option onboarding-multi-goals">
                        <div class="onboarding-primary-head"><span class="onboarding-option-icon" aria-hidden="true"><?= activity_icon_svg('sliders') ?></span><span><strong><?= e(t('settings.tracked_metrics')) ?></strong><small><?= e(t('settings.tracked_metrics_hint')) ?></small></span></div>
                        <div class="onboarding-metric-preferences">
                            <?php foreach ($onboardingMetricDefinitions as $metricKey => $metricDefinition): ?>
                                <?php if (in_array($metricKey, ['steps', 'workouts', 'weight'], true)) {
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
                        </div>
                        <details class="onboarding-custom-metrics">
                            <summary class="btn btn-ghost">+ Crear métricas personales</summary>
                            <p class="muted small">Estas métricas solo aparecerán en tu cuenta. Podrás configurar objetivo y gráficas después.</p>
                            <div class="grid-inline two">
                                <label>Nombre<input type="text" name="custom_metric_name[]" maxlength="60" placeholder="Agua"></label>
                                <label>Unidad<input type="text" name="custom_metric_unit[]" maxlength="20" placeholder="litros"></label>
                            </div>
                        </details>
                    </section>
                    </details>
                </div>
            <?php elseif ($step === 'profile'): ?>
                <div class="onboarding-media-grid">
                    <section class="onboarding-media-editor onboarding-avatar-picker" data-image-cropper-form>
                        <input type="hidden" name="avatar_cropped" value="" data-image-crop-output>
                        <label class="onboarding-media-picker">
                            <span class="onboarding-media-preview" data-onboarding-preview="avatar"><?php if ($profileAvatarUrl !== ''): ?><img src="<?= e($profileAvatarUrl) ?>" alt=""><?php else: ?><b><?= e(initials_for((string) $currentUser['display_name'])) ?></b><?php endif; ?></span>
                            <span><strong><?= e(t('onboarding.avatar')) ?></strong><small><?= e(t('onboarding.avatar_hint')) ?></small></span>
                            <input class="sr-only" type="file" name="avatar" accept="image/jpeg,image/png,image/webp" data-onboarding-image-input="avatar" data-image-crop-input>
                        </label>
                        <div class="image-cropper onboarding-image-cropper is-avatar" data-image-cropper hidden>
                            <canvas width="480" height="480" data-image-crop-canvas></canvas>
                            <p class="muted small" data-image-crop-empty><?= e(t('admin.image_crop_hint')) ?></p>
                            <label><span><?= e(t('common.zoom')) ?></span><input type="range" min="1" max="3" step="0.01" value="1" data-image-crop-zoom></label>
                            <small><?= e(t('onboarding.crop_move_hint')) ?></small>
                        </div>
                    </section>
                    <section class="onboarding-media-editor onboarding-cover-picker" data-image-cropper-form>
                        <input type="hidden" name="cover_cropped" value="" data-image-crop-output>
                        <label class="onboarding-media-picker">
                            <span class="onboarding-media-preview" data-onboarding-preview="cover"><?php if ($profileCoverUrl !== ''): ?><img src="<?= e($profileCoverUrl) ?>" alt=""><?php else: ?><span aria-hidden="true"><?= activity_icon_svg('image') ?></span><?php endif; ?></span>
                            <span><strong><?= e(t('onboarding.cover')) ?></strong><small><?= e(t('onboarding.cover_hint')) ?></small></span>
                            <input class="sr-only" type="file" name="cover" accept="image/jpeg,image/png,image/webp" data-onboarding-image-input="cover" data-image-crop-input>
                        </label>
                        <div class="image-cropper onboarding-image-cropper is-cover" data-image-cropper hidden>
                            <canvas width="1200" height="400" data-image-crop-canvas></canvas>
                            <p class="muted small" data-image-crop-empty><?= e(t('admin.image_crop_hint')) ?></p>
                            <label><span><?= e(t('common.zoom')) ?></span><input type="range" min="1" max="3" step="0.01" value="1" data-image-crop-zoom></label>
                            <small><?= e(t('onboarding.crop_move_hint')) ?></small>
                        </div>
                    </section>
                </div>
                <label class="onboarding-profile-message"><span><?= e(t('profile.edit_tagline')) ?></span><input type="text" name="profile_tagline" maxlength="<?= profile_tagline_max_length() ?>" value="<?= e(normalize_profile_tagline((string) ($currentUser['profile_tagline'] ?? ''))) ?>" placeholder="<?= e(t('profile.subtitle')) ?>"><small><?= e(t('onboarding.profile_message_hint')) ?></small></label>
                <fieldset class="onboarding-theme-choice">
                    <legend>Tema inicial</legend>
                    <div class="onboarding-privacy-options">
                        <label class="onboarding-privacy-option"><input type="radio" name="theme_mode" value="light" <?= ($currentUser['theme_mode'] ?? 'light') !== 'dark' ? 'checked' : '' ?> data-onboarding-theme-choice><span><strong>Claro</strong><small>Fondos luminosos y alto contraste.</small></span><i aria-hidden="true"></i></label>
                        <label class="onboarding-privacy-option"><input type="radio" name="theme_mode" value="dark" <?= ($currentUser['theme_mode'] ?? '') === 'dark' ? 'checked' : '' ?> data-onboarding-theme-choice><span><strong>Oscuro</strong><small>Menos brillo para entrenar de noche.</small></span><i aria-hidden="true"></i></label>
                    </div>
                </fieldset>
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
            <?php elseif ($step === 'teams'): ?>
                <?php if ((array) ($joinableTeams ?? []) === []): ?>
                    <div class="onboarding-empty"><span aria-hidden="true"><?= activity_icon_svg('users') ?></span><strong><?= e(t('onboarding.no_teams')) ?></strong><p><?= e(t('onboarding.no_teams_hint')) ?></p></div>
                <?php else: ?>
                    <div class="onboarding-team-summary"><span aria-hidden="true"><?= activity_icon_svg('users') ?></span><div><strong><?= count((array) $joinableTeams) ?> <?= e(t('onboarding.teams_available')) ?></strong><small><?= e(t('onboarding.teams_multiple_hint')) ?></small></div></div>
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
                <?php endif; ?>
            <?php endif; ?>

            <div class="onboarding-actions">
                <button class="btn btn-ghost" type="submit" name="action" value="skip_onboarding_step" formnovalidate><?= e($step === 'teams' ? t('onboarding.no_team') : t('onboarding.do_later')) ?></button>
                <button class="btn btn-primary" type="submit" name="action" value="save_onboarding_step"><?= e($step === 'teams' ? t('onboarding.finish') : t('common.continue')) ?></button>
            </div>
            <p class="onboarding-later-note"><?= e(t('onboarding.later_note')) ?></p>
        </form>
    </article>
</section>
