<?php

declare(strict_types=1);

$settingsView = (string) ($settingsView ?? '');
$settingsGoalCards = array_values((array) ($settingsGoalCards ?? []));
$settingsActiveGoals = array_values(array_filter($settingsGoalCards, static fn(array $goal): bool => (string) ($goal['status'] ?? 'active') === 'active'));
$settingsCompletedGoals = array_values(array_filter($settingsGoalCards, static fn(array $goal): bool => (string) ($goal['status'] ?? '') === 'complete'));
$settingsAvatarUrl = avatar_url($currentUser);
$renderAvatarEditor = static function (array $currentUser, string $settingsView, string $settingsAvatarUrl, bool $focused = false): void {
    ?>
    <article class="panel settings-avatar-card<?= $focused ? ' settings-avatar-card-focused' : '' ?>" id="avatar">
        <div class="panel-head compact-head">
            <div>
                <p class="eyebrow"><?= e(t('settings.change_avatar')) ?></p>
                <h2><?= e(t('settings.avatar')) ?></h2>
                <p class="muted small"><?= e(t('settings.avatar_upload_hint')) ?></p>
            </div>
        </div>
        <form method="post" action="/?page=settings<?= $settingsView === 'avatar' ? '&view=avatar#avatar' : '' ?>" enctype="multipart/form-data" class="stack settings-avatar-form" data-image-cropper-form>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="upload_avatar">
            <input type="hidden" name="settings_view" value="<?= e($settingsView) ?>">
            <input type="hidden" name="avatar_cropped" value="" data-image-crop-output>
            <div class="settings-avatar-current">
                <?php if ($settingsAvatarUrl !== ''): ?>
                    <img class="settings-avatar-preview settings-avatar-preview-round" src="<?= e($settingsAvatarUrl) ?>" alt="<?= e((string) $currentUser['display_name']) ?>">
                <?php else: ?>
                    <span class="settings-avatar-preview settings-avatar-preview-round"><?= e(initials_for((string) ($currentUser['display_name'] ?? ''))) ?></span>
                <?php endif; ?>
                <div>
                    <strong><?= e((string) ($currentUser['display_name'] ?? '')) ?></strong>
                    <small class="muted">@<?= e((string) ($currentUser['username'] ?? '')) ?></small>
                </div>
            </div>
            <div class="settings-avatar-source-actions">
            <label class="btn btn-secondary settings-avatar-upload-trigger">
                <?= e(t('settings.avatar_file')) ?>
                <input class="sr-only" type="file" name="avatar" accept="image/*" data-image-crop-input>
            </label>
            <label class="btn btn-ghost settings-avatar-upload-trigger">
                <?= e(t('settings.avatar_camera')) ?>
                <input class="sr-only" type="file" name="avatar_camera" accept="image/*" capture="user" data-image-crop-input>
            </label>
            </div>
            <p class="muted small settings-avatar-helper"><?= e(t('settings.avatar_crop_starts_after_select')) ?></p>
            <div class="image-cropper settings-image-cropper" data-image-cropper hidden>
                <canvas width="320" height="320" data-image-crop-canvas></canvas>
                <p class="muted small" data-image-crop-empty><?= e(t('admin.image_crop_hint')) ?></p>
                <label>
                    <?= e(t('common.zoom')) ?>
                    <input type="range" min="1" max="3" step="0.01" value="1" data-image-crop-zoom>
                </label>
            </div>
            <div class="settings-avatar-submit-actions">
                <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
                <a class="btn btn-ghost" href="/?page=settings"><?= e(t('common.cancel')) ?></a>
            </div>
        </form>
        <?php if ($settingsAvatarUrl !== ''): ?>
            <form method="post" action="/?page=settings&amp;view=avatar" class="settings-avatar-remove-form" data-confirm="<?= e(t('settings.avatar_remove_confirm')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="remove_avatar">
                <button class="btn btn-ghost small is-danger" type="submit" data-confirm-action="<?= e(t('settings.avatar_remove_confirm')) ?>"><?= e(t('settings.avatar_remove')) ?></button>
            </form>
        <?php endif; ?>
    </article>
    <?php
};

$settingsSections = [
    'avatar' => ['title' => t('settings.nav_profile'), 'hint' => t('settings.nav_profile_hint'), 'icon' => 'user'],
    'goals' => ['title' => t('settings.goals_title'), 'hint' => t('settings.nav_goals_hint'), 'icon' => 'target'],
    'preferences' => ['title' => t('settings.preferences'), 'hint' => t('settings.nav_preferences_hint'), 'icon' => 'sliders'],
    'privacy' => ['title' => t('privacy.title'), 'hint' => t('privacy.subtitle'), 'icon' => 'shield'],
    'integrations' => ['title' => t('settings.integrations'), 'hint' => t('settings.nav_integrations_hint'), 'icon' => 'link'],
    'account' => ['title' => t('settings.account'), 'hint' => t('settings.nav_account_hint'), 'icon' => 'shield'],
];

if ($settingsView === '') {
    ?>
    <section class="screen stack-lg settings-page settings-index-screen">
        <div class="hero-panel settings-hero">
            <div class="hero-copy hero-copy-page-title">
                <p class="eyebrow"><?= e(t('nav.settings')) ?></p>
                <h1><?= e(t('settings.title')) ?></h1>
                <p class="muted"><?= e(t('settings.subtitle')) ?></p>
            </div>
        </div>
        <nav class="settings-nav-grid" aria-label="<?= e(t('settings.title')) ?>">
            <?php foreach ($settingsSections as $sectionKey => $section): ?>
                <a class="settings-nav-item" href="/?page=settings&amp;view=<?= e($sectionKey) ?>">
                    <span class="settings-nav-icon" aria-hidden="true"><?= activity_icon_svg((string) $section['icon']) ?></span>
                    <span class="settings-nav-copy">
                        <strong><?= e((string) $section['title']) ?></strong>
                        <small><?= e((string) $section['hint']) ?></small>
                    </span>
                    <span class="settings-nav-arrow" aria-hidden="true">›</span>
                </a>
            <?php endforeach; ?>
        </nav>
    </section>
    <?php
    return;
}

if ($settingsView === 'avatar') {
    ?>
    <section class="screen stack-lg settings-page settings-avatar-focused-screen" data-settings-avatar-focused>
        <div class="settings-focused-head">
            <div>
                <p class="eyebrow"><?= e(t('nav.settings')) ?></p>
                <h1><?= e(t('settings.avatar_focus_title')) ?></h1>
                <p class="muted"><?= e(t('settings.avatar_focus_subtitle')) ?></p>
            </div>
            <a class="btn btn-ghost" href="/?page=settings"><?= e(t('common.back')) ?></a>
        </div>
        <?php $renderAvatarEditor($currentUser, $settingsView, $settingsAvatarUrl, true); ?>
    </section>
    <?php
    return;
}
?>
<section class="screen stack-lg settings-page" data-settings-section data-unsaved-message="<?= e(t('settings.unsaved_confirm')) ?>">
    <div class="settings-focused-head settings-section-head">
        <div>
            <p class="eyebrow"><?= e(t('nav.settings')) ?></p>
            <h1><?= e((string) ($settingsSections[$settingsView]['title'] ?? t('settings.title'))) ?></h1>
            <p class="muted"><?= e((string) ($settingsSections[$settingsView]['hint'] ?? t('settings.subtitle'))) ?></p>
        </div>
        <a class="btn btn-ghost" href="/?page=settings">← <?= e(t('common.back')) ?></a>
    </div>

    <?php if ($settingsView === 'account'): ?>
        <article class="panel settings-security-card">
            <h2><?= e(t('profile.security')) ?></h2>
            <p class="muted small"><?= e(t('settings.account_security_hint')) ?></p>
            <form method="post" action="/?page=settings&amp;view=account" class="stack compact-form settings-dirty-form" data-settings-dirty-form>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="change_password">
                <label><?= e(t('common.current_password')) ?><input type="password" name="current_password" required></label>
                <label><?= e(t('common.new_password')) ?><input type="password" name="new_password" minlength="8" required></label>
                <label><?= e(t('common.repeat_password')) ?><input type="password" name="new_password_confirm" minlength="8" required></label>
                <button class="btn btn-secondary" type="submit"><?= e(t('profile.update_password')) ?></button>
            </form>
        </article>
    <?php endif; ?>

    <?php if ($settingsView === 'privacy'): ?>
        <?php $settingsVisibility = privacy_normalize((string) ($currentUser['profile_visibility'] ?? 'public')); ?>
        <article class="panel settings-privacy-card">
            <h2><?= e(t('privacy.title')) ?></h2>
            <p class="muted small"><?= e(t('privacy.subtitle')) ?></p>
            <form method="post" action="/?page=settings&amp;view=privacy" class="stack settings-dirty-form" data-settings-dirty-form>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="update_settings_privacy">
                <div class="privacy-options">
                    <?php foreach (['public' => 'privacy.public', 'friends' => 'privacy.friends', 'private' => 'privacy.private'] as $value => $labelKey): ?>
                        <label class="privacy-option<?= $settingsVisibility === $value ? ' is-selected' : '' ?>">
                            <input type="radio" name="profile_visibility" value="<?= e($value) ?>" <?= $settingsVisibility === $value ? 'checked' : '' ?>>
                            <span class="privacy-option-label"><?= e(t($labelKey)) ?></span>
                            <span class="privacy-option-hint muted"><?= e(t($labelKey . '_hint')) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
            </form>
        </article>
    <?php endif; ?>

    <?php if ($settingsView === 'goals'): ?>
    <article class="panel settings-goals-card">
        <div class="panel-head compact-head">
            <div>
                <p class="eyebrow"><?= e(t('goals.personal')) ?></p>
                <h2><?= e(t('settings.goals_title')) ?></h2>
                <p class="muted small"><?= e(t('settings.goals_subtitle')) ?></p>
            </div>
            <div class="inline-actions-mini">
                <a class="btn btn-primary small" href="/?page=profile&section=goals&goal_new=1"><?= e(t('profile.new_goal')) ?></a>
                <a class="btn btn-ghost small" href="/?page=profile&section=goals"><?= e(t('settings.edit_goals')) ?></a>
            </div>
        </div>
        <?php if ($settingsGoalCards === []): ?>
            <p class="muted panel-inline-empty"><?= e(t('goals.empty')) ?></p>
        <?php else: ?>
            <div class="settings-goal-summary">
                <span><strong><?= count($settingsActiveGoals) ?></strong><?= e(t('common.active')) ?></span>
                <span><strong><?= count($settingsCompletedGoals) ?></strong><?= e(t('settings.completed_goals')) ?></span>
            </div>
            <?php if ($settingsActiveGoals !== []): ?>
                <div class="settings-goal-list" aria-label="<?= e(t('settings.active_goals')) ?>">
                    <?php foreach (array_slice($settingsActiveGoals, 0, 4) as $goal): ?>
                        <a class="settings-goal-row" href="/?page=profile&section=goals&goal_id=<?= (int) ($goal['id'] ?? 0) ?>">
                            <span>
                                <strong><?= e((string) ($goal['title'] ?? '')) ?></strong>
                                <small><?= e((string) ($goal['type_label'] ?? '')) ?> · <?= e((string) ($goal['current_label'] ?? '0')) ?> / <?= e((string) ($goal['target_label'] ?? '0')) ?></small>
                                <?php if ((string) ($goal['due_label'] ?? '') !== ''): ?>
                                    <small><?= e(t('goals.due_date')) ?>: <?= e((string) $goal['due_label']) ?></small>
                                <?php endif; ?>
                            </span>
                            <span class="settings-goal-progress" aria-hidden="true"><span style="width: <?= e((string) ($goal['progress_pct'] ?? 0)) ?>%"></span></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($settingsCompletedGoals !== []): ?>
                <details class="settings-completed-goals">
                    <summary><?= e(t('settings.completed_goals')) ?> · <?= count($settingsCompletedGoals) ?></summary>
                    <div class="settings-goal-list">
                        <?php foreach (array_slice($settingsCompletedGoals, 0, 5) as $goal): ?>
                            <a class="settings-goal-row is-complete" href="/?page=profile&section=goals&goal_id=<?= (int) ($goal['id'] ?? 0) ?>">
                                <span>
                                    <strong><?= e((string) ($goal['title'] ?? '')) ?></strong>
                                    <small><?= e((string) ($goal['type_label'] ?? '')) ?> · <?= e((string) ($goal['status_label'] ?? t('common.complete'))) ?></small>
                                </span>
                                <span class="badge"><?= e(t('common.complete')) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endif; ?>
        <?php endif; ?>
    </article>
    <?php endif; ?>

    <?php if ($settingsView === 'preferences'): ?>
    <article class="panel settings-preferences-card">
        <h2><?= e(t('settings.preferences')) ?></h2>
        <p class="eyebrow"><?= e(t('common.language')) ?></p>
        <?php
        $localeScope = 'settings';
        $localeFormClass = 'stack compact-form';
        $localeSelectId = 'locale-select-settings';
        $localeRedirectTo = '/?page=settings&view=preferences';
        $localeShowSaveButton = true;
        $localeAsync = false;
        require __DIR__ . '/components/locale_selector.php';
        ?>

        <form method="post" action="/?page=settings&amp;view=preferences" class="stack compact-form settings-preferences-form settings-dirty-form" data-settings-dirty-form>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_preferences">
            <div class="grid-inline">
                <label><?= e(t('settings.primary_goal')) ?><select name="primary_goal_type"><option value="steps" <?= ($currentUser['primary_goal_type'] ?? 'steps') === 'steps' ? 'selected' : '' ?>><?= e(t('metric.steps')) ?></option><option value="km" <?= ($currentUser['primary_goal_type'] ?? 'steps') === 'km' ? 'selected' : '' ?>><?= e(t('metric.distance_km')) ?></option></select></label>
                <label><?= e(t('settings.primary_goal_value')) ?><input type="number" step="0.01" name="primary_goal_value" value="<?= e((string) ($currentUser['primary_goal_value'] ?? '')) ?>"></label>
                <label><?= e(t('settings.calorie_burn_goal')) ?><input type="number" min="0" step="1" name="calorie_burn_goal" value="<?= e((string) ($currentUser['calorie_burn_goal'] ?? '')) ?>"></label>
                <label><?= e(t('settings.calorie_consumed_max')) ?><input type="number" min="0" step="1" name="calorie_consumed_max" value="<?= e((string) ($currentUser['calorie_consumed_max'] ?? '')) ?>"></label>
                <label><?= e(t('settings.theme_mode')) ?><select name="theme_mode"><option value="auto" <?= ($currentUser['theme_mode'] ?? 'auto') === 'auto' ? 'selected' : '' ?>><?= e(t('settings.theme_auto')) ?></option><option value="light" <?= ($currentUser['theme_mode'] ?? 'auto') === 'light' ? 'selected' : '' ?>><?= e(t('settings.theme_light')) ?></option><option value="dark" <?= ($currentUser['theme_mode'] ?? 'auto') === 'dark' ? 'selected' : '' ?>><?= e(t('settings.theme_dark')) ?></option></select></label>
                <label><?= e(t('dashboard.viewing')) ?><select name="dashboard_view"><option value="current_week" <?= ($currentUser['dashboard_view'] ?? '') === 'current_week' ? 'selected' : '' ?>><?= e(t('dashboard.current_week')) ?></option><option value="total" <?= ($currentUser['dashboard_view'] ?? '') === 'total' ? 'selected' : '' ?>><?= e(t('metric.total')) ?></option></select></label>
            </div>
            <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
        </form>
    </article>
    <?php endif; ?>

    <?php
    $telegram = is_array($telegramSettings ?? null) ? (array) $telegramSettings : [];
    $telegramAvailable = !empty($telegram['enabled']) && ($telegram['token'] ?? '') !== '';
    $telegramChatId = trim((string) ($currentUser['telegram_chat_id'] ?? ''));
    $telegramLinked = $telegramChatId !== '';
    $telegramLinkCode = trim((string) ($currentUser['telegram_link_code'] ?? ''));
    $telegramDeepLink = telegram_deep_link($telegram, $telegramLinkCode);
    ?>
    <?php if ($settingsView === 'integrations'): ?>
    <article class="panel settings-telegram-card" id="telegram">
        <h2><?= e(t('settings.telegram_title')) ?></h2>
        <p class="muted small"><?= e(t('settings.telegram_hint')) ?></p>

        <?php if (!$telegramAvailable): ?>
            <p class="muted"><?= e(t('settings.telegram_unavailable')) ?></p>
        <?php elseif (!$telegramLinked): ?>
            <?php if ($telegramLinkCode === ''): ?>
                <form method="post" action="/?page=settings&amp;view=integrations" class="stack compact-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="telegram_generate_link">
                    <button class="btn btn-primary" type="submit"><?= e(t('settings.telegram_link')) ?></button>
                </form>
            <?php else: ?>
                <p class="small"><?= e(t('settings.telegram_link_steps')) ?></p>
                <?php if ($telegramDeepLink !== ''): ?>
                    <p><a class="btn btn-primary" href="<?= e($telegramDeepLink) ?>" target="_blank" rel="noopener"><?= e(t('settings.telegram_open_bot')) ?></a></p>
                <?php else: ?>
                    <p class="small"><?= e(t('settings.telegram_code_label')) ?> <code>/start <?= e($telegramLinkCode) ?></code></p>
                <?php endif; ?>
                <p class="muted small"><?= e(t('settings.telegram_link_wait')) ?></p>
            <?php endif; ?>
        <?php else: ?>
            <p class="small"><strong><?= e(t('settings.telegram_linked')) ?></strong></p>
            <form method="post" action="/?page=settings&amp;view=integrations" class="stack compact-form settings-dirty-form" data-settings-dirty-form>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="telegram_update_prefs">
                <div class="toggle-row">
                    <label class="check standalone-check">
                        <input type="checkbox" name="telegram_reminders_enabled" value="1" <?= (int) ($currentUser['telegram_reminders_enabled'] ?? 0) === 1 ? 'checked' : '' ?>>
                        <?= e(t('settings.telegram_reminders')) ?>
                    </label>
                </div>
                <div class="toggle-row">
                    <label class="check standalone-check">
                        <input type="checkbox" name="telegram_motivation_enabled" value="1" <?= (int) ($currentUser['telegram_motivation_enabled'] ?? 0) === 1 ? 'checked' : '' ?>>
                        <?= e(t('settings.telegram_motivation')) ?>
                    </label>
                </div>
                <label><?= e(t('settings.telegram_time')) ?><input type="time" name="telegram_reminder_time" value="<?= e((string) ($currentUser['telegram_reminder_time'] ?? '20:00')) ?>"></label>
                <div class="grid-inline two">
                    <label><?= e(t('settings.telegram_quiet_start')) ?><input type="time" name="telegram_quiet_start" value="<?= e((string) ($currentUser['telegram_quiet_start'] ?? '')) ?>"></label>
                    <label><?= e(t('settings.telegram_quiet_end')) ?><input type="time" name="telegram_quiet_end" value="<?= e((string) ($currentUser['telegram_quiet_end'] ?? '')) ?>"></label>
                </div>
                <p class="muted small"><?= e(t('settings.telegram_quiet_hint')) ?></p>
                <div class="toggle-row">
                    <label class="check standalone-check">
                        <input type="checkbox" name="telegram_weekends_off" value="1" <?= (int) ($currentUser['telegram_weekends_off'] ?? 0) === 1 ? 'checked' : '' ?>>
                        <?= e(t('settings.telegram_weekends_off')) ?>
                    </label>
                </div>

                <label><?= e(t('settings.telegram_tz')) ?>
                    <select name="telegram_tz">
                        <option value=""><?= e(t('common.none')) ?></option>
                        <?php $userTz = (string) ($currentUser['telegram_tz'] ?? ''); ?>
                        <?php foreach (timezone_identifiers_list() as $tzId): ?>
                            <option value="<?= e($tzId) ?>" <?= $userTz === $tzId ? 'selected' : '' ?>><?= e($tzId) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <p class="muted small"><?= e(t('settings.telegram_tz_hint')) ?></p>

                <p class="quests-group-label"><?= e(t('settings.telegram_events')) ?></p>
                <div class="toggle-row">
                    <label class="check standalone-check">
                        <input type="checkbox" name="telegram_notify_duel" value="1" <?= (int) ($currentUser['telegram_notify_duel'] ?? 1) === 1 ? 'checked' : '' ?>>
                        <?= e(t('settings.telegram_notify_duel')) ?>
                    </label>
                </div>
                <div class="toggle-row">
                    <label class="check standalone-check">
                        <input type="checkbox" name="telegram_notify_streak" value="1" <?= (int) ($currentUser['telegram_notify_streak'] ?? 1) === 1 ? 'checked' : '' ?>>
                        <?= e(t('settings.telegram_notify_streak')) ?>
                    </label>
                </div>

                <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
            </form>
            <div class="inline-actions">
                <form method="post" action="/?page=settings&amp;view=integrations" class="stack compact-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="telegram_test">
                    <button class="btn btn-ghost small" type="submit"><?= e(t('settings.telegram_test')) ?></button>
                </form>
                <form method="post" action="/?page=settings&amp;view=integrations" class="stack compact-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="telegram_unlink">
                    <button class="btn btn-ghost small" type="submit"><?= e(t('settings.telegram_unlink')) ?></button>
                </form>
            </div>
        <?php endif; ?>
    </article>
    <?php endif; ?>
</section>
