<?php

declare(strict_types=1);

$settingsView = (string) ($settingsView ?? '');
$settingsGoalCards = array_values((array) ($settingsGoalCards ?? []));
$settingsActiveGoals = array_values(array_filter($settingsGoalCards, static fn(array $goal): bool => (string) ($goal['status'] ?? 'active') === 'active'));
$settingsCompletedGoals = array_values(array_filter($settingsGoalCards, static fn(array $goal): bool => (string) ($goal['status'] ?? '') === 'complete'));
$settingsAvatarUrl = avatar_url($currentUser);
$settingsMetric = is_array($settingsMetric ?? null) ? (array) $settingsMetric : [];
$settingsWeightHistory = array_values(array_filter(
    (array) ($settingsWeightHistory ?? []),
    static fn($row): bool => is_array($row) && isset($row['weight']) && is_numeric($row['weight'])
));
$settingsWeightSeries = $settingsWeightHistory !== []
    ? array_reverse($settingsWeightHistory)
    : array_values(array_filter(
        (array) ($settingsMetric['weight_series'] ?? []),
        static fn($row): bool => is_array($row) && isset($row['weight']) && is_numeric($row['weight'])
    ));
$settingsLatestWeight = $settingsWeightSeries !== [] ? (float) ($settingsWeightSeries[count($settingsWeightSeries) - 1]['weight'] ?? 0) : null;
$settingsFirstWeight = $settingsWeightSeries !== [] ? (float) ($settingsWeightSeries[0]['weight'] ?? 0) : null;
$settingsWeightChange = $settingsLatestWeight !== null && $settingsFirstWeight !== null ? round($settingsLatestWeight - $settingsFirstWeight, 1) : null;
$settingsRecentWeights = $settingsWeightHistory !== [] ? array_slice($settingsWeightHistory, 0, 8) : array_slice(array_reverse($settingsWeightSeries), 0, 8);
$settingsWeightTrendRows = array_slice($settingsWeightSeries, -12);
$settingsWeightTrendPoints = '';
if (count($settingsWeightTrendRows) >= 2) {
    $trendValues = array_map(static fn(array $row): float => (float) ($row['weight'] ?? 0), $settingsWeightTrendRows);
    $trendMin = min($trendValues);
    $trendMax = max($trendValues);
    $trendRange = max(0.5, $trendMax - $trendMin);
    $trendLastIndex = count($trendValues) - 1;
    $trendPoints = [];
    foreach ($trendValues as $trendIndex => $trendValue) {
        $trendX = 6 + (($trendIndex / $trendLastIndex) * 228);
        $trendY = 62 - ((($trendValue - $trendMin) / $trendRange) * 50);
        $trendPoints[] = number_format($trendX, 1, '.', '') . ',' . number_format($trendY, 1, '.', '');
    }
    $settingsWeightTrendPoints = implode(' ', $trendPoints);
}
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
            <div class="settings-avatar-preview-stage">
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
                <span class="settings-avatar-status<?= $settingsAvatarUrl !== '' ? ' has-photo' : '' ?>"><?= e(t($settingsAvatarUrl !== '' ? 'settings.avatar_current' : 'settings.avatar_missing')) ?></span>
            </div>
            <div class="settings-avatar-source-actions">
                <label class="settings-avatar-source-option settings-avatar-upload-trigger">
                    <span class="settings-avatar-source-icon" aria-hidden="true"><?= activity_icon_svg('image') ?></span>
                    <span><strong><?= e(t('settings.avatar_file')) ?></strong><small><?= e(t('settings.avatar_file_hint')) ?></small></span>
                    <span aria-hidden="true">&rsaquo;</span>
                    <input class="sr-only" type="file" name="avatar" accept="image/*" data-image-crop-input>
                </label>
                <label class="settings-avatar-source-option settings-avatar-upload-trigger">
                    <span class="settings-avatar-source-icon" aria-hidden="true"><?= activity_icon_svg('user') ?></span>
                    <span><strong><?= e(t('settings.avatar_camera')) ?></strong><small><?= e(t('settings.avatar_camera_hint')) ?></small></span>
                    <span aria-hidden="true">&rsaquo;</span>
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
                <button class="btn btn-primary" type="submit" data-image-crop-submit disabled><?= e(t('settings.avatar_save_new')) ?></button>
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
    'avatar' => ['title' => t('settings.nav_profile'), 'hint' => t('settings.nav_profile_hint'), 'icon' => 'user', 'tone' => 'blue'],
    'body' => ['title' => t('settings.body_title'), 'hint' => t('settings.body_hint'), 'icon' => 'target', 'tone' => 'green'],
    'goals' => ['title' => t('settings.goals_title'), 'hint' => t('settings.nav_goals_hint'), 'icon' => 'target', 'tone' => 'amber'],
    'preferences' => ['title' => t('settings.preferences'), 'hint' => t('settings.nav_preferences_hint'), 'icon' => 'sliders', 'tone' => 'violet'],
    'privacy' => ['title' => t('privacy.title'), 'hint' => t('privacy.subtitle'), 'icon' => 'shield', 'tone' => 'green'],
    'integrations' => ['title' => t('settings.integrations'), 'hint' => t('settings.nav_integrations_hint'), 'icon' => 'link', 'tone' => 'cyan'],
    'account' => ['title' => t('settings.account'), 'hint' => t('settings.nav_account_hint'), 'icon' => 'shield', 'tone' => 'red'],
];

if ($settingsView === '') {
    ?>
    <section class="screen stack-lg settings-page settings-index-screen">
        <header class="hierarchy-page-header hierarchy-page-header-root settings-compact-header">
            <div>
                <p class="eyebrow"><?= e(t('nav.profile')) ?></p>
                <h1><?= e(t('settings.title')) ?></h1>
                <p class="muted"><?= e(t('settings.subtitle')) ?></p>
            </div>
        </header>
        <nav class="settings-nav-grid" aria-label="<?= e(t('settings.title')) ?>">
            <?php foreach ($settingsSections as $sectionKey => $section): ?>
                <a class="settings-nav-item" data-tone="<?= e((string) $section['tone']) ?>" href="/?page=settings&amp;view=<?= e($sectionKey) ?>">
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
        <header class="hierarchy-page-header settings-focused-head settings-section-head">
            <a class="hierarchy-back destination-back" href="/?page=settings" aria-label="<?= e(t('common.back')) ?>: <?= e(t('nav.settings')) ?>"><span aria-hidden="true">&larr;</span><strong><?= e(t('nav.settings')) ?></strong></a>
            <div>
                <p class="eyebrow"><?= e(t('nav.settings')) ?></p>
                <h1><?= e(t('settings.avatar_focus_title')) ?></h1>
                <p class="muted"><?= e(t('settings.avatar_focus_subtitle')) ?></p>
            </div>
        </header>
        <?php $renderAvatarEditor($currentUser, $settingsView, $settingsAvatarUrl, true); ?>
    </section>
    <?php
    return;
}
?>
<section class="screen stack-lg settings-page" data-settings-section data-unsaved-message="<?= e(t('settings.unsaved_confirm')) ?>">
    <header class="hierarchy-page-header settings-focused-head settings-section-head">
        <a class="hierarchy-back destination-back" href="/?page=settings" aria-label="<?= e(t('common.back')) ?>: <?= e(t('nav.settings')) ?>"><span aria-hidden="true">&larr;</span><strong><?= e(t('nav.settings')) ?></strong></a>
        <div>
            <p class="eyebrow"><?= e(t('nav.settings')) ?></p>
            <h1><?= e((string) ($settingsSections[$settingsView]['title'] ?? t('settings.title'))) ?></h1>
            <p class="muted"><?= e((string) ($settingsSections[$settingsView]['hint'] ?? t('settings.subtitle'))) ?></p>
        </div>
    </header>

    <?php if ($settingsView === 'body'): ?>
        <article class="settings-body-card">
            <div class="settings-weight-summary" aria-label="<?= e(t('settings.body_title')) ?>">
                <span class="settings-weight-stat" data-tone="green"><span class="settings-weight-stat-icon" aria-hidden="true"><?= activity_icon_svg('target') ?></span><span><small><?= e(t('settings.weight_latest')) ?></small><strong><?= $settingsLatestWeight !== null ? e(number_format($settingsLatestWeight, 1, '.', '')) . ' kg' : '—' ?></strong></span></span>
                <span class="settings-weight-stat" data-tone="blue"><span class="settings-weight-stat-icon" aria-hidden="true"><?= activity_icon_svg('check') ?></span><span><small><?= e(t('metric.ideal_weight')) ?></small><strong><?= ($currentUser['ideal_weight'] ?? null) !== null ? e(number_format((float) $currentUser['ideal_weight'], 1, '.', '')) . ' kg' : '—' ?></strong></span></span>
                <span class="settings-weight-stat" data-tone="violet"><span class="settings-weight-stat-icon" aria-hidden="true"><?= activity_icon_svg('bolt') ?></span><span><small><?= e(t('settings.weight_change')) ?></small><strong><?= $settingsWeightChange !== null ? e(($settingsWeightChange > 0 ? '+' : '') . number_format($settingsWeightChange, 1, '.', '')) . ' kg' : '—' ?></strong></span></span>
            </div>

            <section class="settings-preference-group settings-weight-log-group">
                <div class="settings-group-head">
                    <span class="settings-group-icon" aria-hidden="true"><?= activity_icon_svg('target') ?></span>
                    <div><h2><?= e(t('settings.weight_log_title')) ?></h2><p class="muted small"><?= e(t('settings.weight_log_hint')) ?></p></div>
                </div>
                <div class="settings-weight-actions">
                    <a class="btn btn-primary" href="/?page=entries&amp;mode=data#entry-weight"><span aria-hidden="true"><?= activity_icon_svg('plus') ?></span><strong><?= e(t('settings.weight_log_action')) ?></strong></a>
                    <a class="btn btn-ghost" href="/?page=analytics&amp;section=body"><span aria-hidden="true"><?= activity_icon_svg('sliders') ?></span><strong><?= e(t('settings.weight_open_analytics')) ?></strong></a>
                </div>
            </section>

            <form method="post" action="/?page=settings&amp;view=body" class="settings-preference-group settings-dirty-form" data-settings-dirty-form>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="update_body_settings">
                <div class="settings-group-head">
                    <span class="settings-group-icon" aria-hidden="true"><?= activity_icon_svg('sliders') ?></span>
                    <div><h2><?= e(t('settings.body_profile_title')) ?></h2><p class="muted small"><?= e(t('settings.body_profile_hint')) ?></p></div>
                </div>
                <div class="settings-body-profile-grid">
                    <label><span><?= e(t('metric.ideal_weight')) ?></span><span class="settings-weight-goal-input"><input type="number" name="ideal_weight" min="25" max="400" step="0.1" inputmode="decimal" value="<?= e((string) ($currentUser['ideal_weight'] ?? '')) ?>"><span aria-hidden="true">kg</span></span></label>
                    <label><span><?= e(t('settings.height_cm')) ?></span><span class="settings-weight-goal-input"><input type="number" name="height_cm" min="100" max="250" step="0.1" inputmode="decimal" value="<?= e((string) ($currentUser['height_cm'] ?? '')) ?>"><span aria-hidden="true">cm</span></span></label>
                    <label><span><?= e(t('settings.competitive_division')) ?></span><select name="competitive_division"><option value="open"<?= (string) ($currentUser['competitive_division'] ?? 'open') === 'open' ? ' selected' : '' ?>><?= e(t('workouts.rank_division_open')) ?></option><option value="women"<?= (string) ($currentUser['competitive_division'] ?? '') === 'women' ? ' selected' : '' ?>><?= e(t('workouts.rank_division_women')) ?></option><option value="men"<?= (string) ($currentUser['competitive_division'] ?? '') === 'men' ? ' selected' : '' ?>><?= e(t('workouts.rank_division_men')) ?></option></select></label>
                </div>
                <p class="settings-body-profile-note muted small"><?= e(t('settings.body_profile_privacy')) ?></p>
                <div class="settings-body-profile-actions">
                    <button class="btn btn-primary" type="submit"><span aria-hidden="true"><?= activity_icon_svg('check') ?></span><strong><?= e(t('common.save')) ?></strong></button>
                </div>
            </form>

            <section class="settings-preference-group settings-weight-history">
                <div class="settings-weight-history-head"><div><h2><?= e(t('settings.weight_history')) ?></h2><p class="muted small"><?= e(t('settings.weight_history_hint')) ?></p></div><span class="badge"><?= count($settingsWeightSeries) ?></span></div>
                <?php if ($settingsWeightTrendPoints !== ''): ?>
                    <div class="settings-weight-trend" aria-hidden="true">
                        <svg viewBox="0 0 240 70" preserveAspectRatio="none"><path d="M6 62H234"/><polyline points="<?= e($settingsWeightTrendPoints) ?>"/></svg>
                    </div>
                <?php endif; ?>
                <?php if ($settingsRecentWeights === []): ?>
                    <div class="settings-weight-empty"><span aria-hidden="true"><?= activity_icon_svg('target') ?></span><p><?= e(t('settings.weight_empty')) ?></p><a href="/?page=entries&amp;mode=data#entry-weight"><?= e(t('settings.weight_log_action')) ?></a></div>
                <?php else: ?>
                    <div class="settings-weight-list">
                        <?php foreach ($settingsRecentWeights as $index => $weightRow): ?>
                            <?php
                            $weightValue = (float) ($weightRow['weight'] ?? 0);
                            $previousValue = isset($settingsRecentWeights[$index + 1]) ? (float) ($settingsRecentWeights[$index + 1]['weight'] ?? 0) : null;
                            $weightDelta = $previousValue !== null ? round($weightValue - $previousValue, 1) : null;
                            $weightDeltaClass = $weightDelta === null || abs($weightDelta) < 0.05 ? 'is-steady' : ($weightDelta > 0 ? 'is-up' : 'is-down');
                            ?>
                            <a href="/?page=entries&amp;mode=data&amp;date=<?= e((string) ($weightRow['date'] ?? '')) ?>#entry-weight">
                                <span class="settings-weight-list-date"><span class="settings-weight-list-dot" aria-hidden="true"></span><span><strong><?= e(format_date_eu((string) ($weightRow['date'] ?? ''))) ?></strong><small><?= e(t('settings.weight_edit_entry')) ?></small></span></span>
                                <span class="settings-weight-list-value"><strong><?= e(number_format($weightValue, 1, '.', '')) ?> <small>kg</small></strong><?php if ($weightDelta !== null): ?><small class="settings-weight-delta <?= e($weightDeltaClass) ?>"><?= e(($weightDelta > 0 ? '+' : '') . number_format($weightDelta, 1, '.', '')) ?> kg</small><?php endif; ?></span>
                                <span class="settings-weight-list-arrow" aria-hidden="true">›</span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </article>
    <?php endif; ?>

    <?php if ($settingsView === 'account'): ?>
        <article class="panel settings-setup-card">
            <span class="settings-setup-icon" aria-hidden="true"><?= activity_icon_svg('spark') ?></span>
            <div><p class="eyebrow"><?= e(t('onboarding.title')) ?></p><h2><?= e(t('settings.setup_again_title')) ?></h2><p class="muted small"><?= e(t('settings.setup_again_hint')) ?></p></div>
            <form method="post" action="/?page=settings&amp;view=account">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="restart_onboarding">
                <button class="btn btn-primary" type="submit"><?= e(t('settings.setup_again_action')) ?></button>
            </form>
        </article>
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
        <?php $settingsDataVisibility = privacy_data_preferences($currentUser); ?>
        <article class="panel settings-privacy-card">
            <h2><?= e(t('privacy.title')) ?></h2>
            <p class="muted small"><?= e(t('privacy.subtitle')) ?></p>
            <form method="post" action="/?page=settings&amp;view=privacy" class="stack settings-dirty-form" data-settings-dirty-form data-privacy-controls>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="update_settings_privacy">
                <div class="privacy-options">
                    <?php foreach (['public' => 'privacy.public', 'friends' => 'privacy.friends', 'private' => 'privacy.private'] as $value => $labelKey): ?>
                        <label class="privacy-option<?= $settingsVisibility === $value ? ' is-selected' : '' ?>">
                            <input type="radio" name="profile_visibility" value="<?= e($value) ?>" <?= $settingsVisibility === $value ? 'checked' : '' ?> data-privacy-default>
                            <span class="privacy-option-label"><?= e(t($labelKey)) ?></span>
                            <span class="privacy-option-hint muted"><?= e(t($labelKey . '_hint')) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="settings-data-privacy">
                    <div class="settings-data-privacy-head"><strong><?= e(t('privacy.data_controls')) ?></strong><small class="muted"><?= e(t('privacy.data_controls_hint')) ?></small></div>
                    <?php foreach (['weight', 'steps', 'distance', 'workouts', 'nutrition'] as $privacyKey): ?>
                        <label class="settings-data-privacy-row"><span><strong><?= e(t('privacy.data_' . $privacyKey)) ?></strong><small class="muted"><?= e(t('privacy.data_' . $privacyKey . '_hint')) ?></small></span><select name="data_visibility[<?= e($privacyKey) ?>]" data-privacy-data><?php foreach (['public', 'friends', 'private'] as $privacyValue): ?><option value="<?= e($privacyValue) ?>" <?= ($settingsDataVisibility[$privacyKey] ?? $settingsVisibility) === $privacyValue ? 'selected' : '' ?>><?= e(t('privacy.' . $privacyValue)) ?></option><?php endforeach; ?></select></label>
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
        <section class="settings-preference-group settings-language-group">
            <div class="settings-group-head">
                <span class="settings-group-icon" aria-hidden="true"><?= activity_icon_svg('spark') ?></span>
                <div><h2><?= e(t('common.language')) ?></h2><p class="muted small"><?= e(t('settings.preferences_language_hint')) ?></p></div>
            </div>
            <?php
            $localeScope = 'settings';
            $localeFormClass = 'stack compact-form';
            $localeSelectId = 'locale-select-settings';
            $localeRedirectTo = '/?page=settings&view=preferences';
            $localeShowSaveButton = true;
            $localeAsync = false;
            require __DIR__ . '/components/locale_selector.php';
            ?>
        </section>

        <form method="post" action="/?page=settings&amp;view=preferences" class="stack compact-form settings-preferences-form settings-dirty-form" data-settings-dirty-form>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_preferences">
            <fieldset class="settings-preference-group">
                <legend><?= e(t('settings.preferences_goals_title')) ?></legend>
                <p class="muted small"><?= e(t('settings.preferences_goals_hint')) ?></p>
                <div class="grid-inline two settings-preference-fields">
                    <label><?= e(t('settings.primary_goal')) ?><select name="primary_goal_type" data-optional-primary-goal><option value="none" <?= ($currentUser['primary_goal_type'] ?? 'none') === 'none' ? 'selected' : '' ?>><?= e(t('onboarding.no_primary_goal')) ?></option><option value="steps" <?= ($currentUser['primary_goal_type'] ?? 'none') === 'steps' ? 'selected' : '' ?>><?= e(t('metric.steps')) ?></option><option value="km" <?= ($currentUser['primary_goal_type'] ?? 'none') === 'km' ? 'selected' : '' ?>><?= e(t('metric.distance_km')) ?></option><option value="workouts" <?= ($currentUser['primary_goal_type'] ?? 'none') === 'workouts' ? 'selected' : '' ?>><?= e(t('metric.workouts')) ?></option></select></label>
                    <label data-optional-primary-value <?= ($currentUser['primary_goal_type'] ?? 'none') === 'none' ? 'hidden' : '' ?>><?= e(t('settings.primary_goal_value')) ?><input type="number" min="0.1" step="0.01" name="primary_goal_value" value="<?= e((string) ($currentUser['primary_goal_value'] ?? '')) ?>" <?= ($currentUser['primary_goal_type'] ?? 'none') === 'none' ? 'disabled' : '' ?>></label>
                    <label><?= e(t('onboarding.daily_steps')) ?><input type="number" min="0" max="500000" step="500" name="step_goal" value="<?= (int) ($currentUser['step_goal'] ?? 0) ?>"><small><?= e(t('settings.zero_disables_goal')) ?></small></label>
                    <label><?= e(t('onboarding.weekly_workouts')) ?><input type="number" min="0" max="14" name="workout_target" value="<?= (int) ($currentUser['workout_target'] ?? 0) ?>"><small><?= e(t('settings.zero_disables_goal')) ?></small></label>
                    <label><?= e(t('settings.calorie_burn_goal')) ?><input type="number" min="0" step="1" name="calorie_burn_goal" value="<?= e((string) ($currentUser['calorie_burn_goal'] ?? '')) ?>"></label>
                    <label><?= e(t('settings.calorie_consumed_max')) ?><input type="number" min="0" step="1" name="calorie_consumed_max" value="<?= e((string) ($currentUser['calorie_consumed_max'] ?? '')) ?>"></label>
                </div>
            </fieldset>
            <fieldset class="settings-preference-group">
                <legend><?= e(t('settings.preferences_display_title')) ?></legend>
                <p class="muted small"><?= e(t('settings.preferences_display_hint')) ?></p>
                <div class="grid-inline two settings-preference-fields">
                    <label><?= e(t('settings.theme_mode')) ?><select name="theme_mode"><option value="auto" <?= ($currentUser['theme_mode'] ?? 'auto') === 'auto' ? 'selected' : '' ?>><?= e(t('settings.theme_auto')) ?></option><option value="light" <?= ($currentUser['theme_mode'] ?? 'auto') === 'light' ? 'selected' : '' ?>><?= e(t('settings.theme_light')) ?></option><option value="dark" <?= ($currentUser['theme_mode'] ?? 'auto') === 'dark' ? 'selected' : '' ?>><?= e(t('settings.theme_dark')) ?></option></select></label>
                    <label><?= e(t('dashboard.viewing')) ?><select name="dashboard_view"><option value="current_week" <?= ($currentUser['dashboard_view'] ?? '') === 'current_week' ? 'selected' : '' ?>><?= e(t('dashboard.current_week')) ?></option><option value="total" <?= ($currentUser['dashboard_view'] ?? '') === 'total' ? 'selected' : '' ?>><?= e(t('metric.total')) ?></option></select></label>
                </div>
            </fieldset>
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
    $telegramBotUsername = ltrim(trim((string) ($telegram['username'] ?? '')), '@');
    $telegramBotUrl = $telegramBotUsername !== '' ? 'https://t.me/' . rawurlencode($telegramBotUsername) : '';
    ?>
    <?php if ($settingsView === 'integrations'): ?>
    <article class="panel settings-telegram-card" id="telegram">
        <div class="settings-integration-head">
            <span class="settings-integration-icon" aria-hidden="true"><?= activity_icon_svg('link') ?></span>
            <div><h2><?= e(t('settings.telegram_title')) ?></h2><p class="muted small"><?= e(t('settings.telegram_hint')) ?></p></div>
            <span class="settings-integration-status<?= $telegramLinked ? ' is-linked' : ($telegramAvailable ? ' is-ready' : ' is-offline') ?>">
                <?= e(t($telegramLinked ? 'settings.integration_linked' : ($telegramAvailable ? 'settings.integration_ready' : 'settings.integration_unavailable'))) ?>
            </span>
        </div>

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
            <div class="telegram-delivery-summary" aria-label="<?= e(t('settings.telegram_title')) ?>">
                <span class="telegram-delivery-time"><small><?= e(t('settings.telegram_time')) ?></small><strong><?= e((string) ($currentUser['telegram_reminder_time'] ?? '20:00')) ?></strong></span>
                <span class="telegram-delivery-channel"><small><?= e(t('settings.telegram_events')) ?></small><strong><?= (int) ($currentUser['telegram_notify_duel'] ?? 1) + (int) ($currentUser['telegram_notify_streak'] ?? 1) + (int) ($currentUser['telegram_notify_social'] ?? 1) ?></strong></span>
                <span class="telegram-delivery-state"><span aria-hidden="true"></span><?= e(t('settings.integration_linked')) ?></span>
            </div>
            <form method="post" action="/?page=settings&amp;view=integrations" class="stack compact-form settings-dirty-form" data-settings-dirty-form>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="telegram_update_prefs">
                <fieldset class="settings-preference-group">
                    <legend><?= e(t('settings.telegram_reminders')) ?></legend>
                    <div class="toggle-row"><label class="check standalone-check"><input type="checkbox" name="telegram_reminders_enabled" value="1" <?= (int) ($currentUser['telegram_reminders_enabled'] ?? 0) === 1 ? 'checked' : '' ?>><?= e(t('settings.telegram_reminders')) ?></label></div>
                    <div class="toggle-row"><label class="check standalone-check"><input type="checkbox" name="telegram_motivation_enabled" value="1" <?= (int) ($currentUser['telegram_motivation_enabled'] ?? 0) === 1 ? 'checked' : '' ?>><?= e(t('settings.telegram_motivation')) ?></label></div>
                    <label><?= e(t('settings.telegram_time')) ?><input type="time" name="telegram_reminder_time" value="<?= e((string) ($currentUser['telegram_reminder_time'] ?? '20:00')) ?>"></label>
                    <div class="grid-inline two">
                        <label><?= e(t('settings.telegram_quiet_start')) ?><input type="time" name="telegram_quiet_start" value="<?= e((string) ($currentUser['telegram_quiet_start'] ?? '')) ?>"></label>
                        <label><?= e(t('settings.telegram_quiet_end')) ?><input type="time" name="telegram_quiet_end" value="<?= e((string) ($currentUser['telegram_quiet_end'] ?? '')) ?>"></label>
                    </div>
                    <p class="muted small"><?= e(t('settings.telegram_quiet_hint')) ?></p>
                    <div class="toggle-row"><label class="check standalone-check"><input type="checkbox" name="telegram_weekends_off" value="1" <?= (int) ($currentUser['telegram_weekends_off'] ?? 0) === 1 ? 'checked' : '' ?>><?= e(t('settings.telegram_weekends_off')) ?></label></div>
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
                </fieldset>

                <fieldset class="settings-preference-group">
                    <legend><?= e(t('settings.telegram_events')) ?></legend>
                    <div class="toggle-row"><label class="check standalone-check"><input type="checkbox" name="telegram_notify_duel" value="1" <?= (int) ($currentUser['telegram_notify_duel'] ?? 1) === 1 ? 'checked' : '' ?>><?= e(t('settings.telegram_notify_duel')) ?></label></div>
                    <div class="toggle-row"><label class="check standalone-check"><input type="checkbox" name="telegram_notify_streak" value="1" <?= (int) ($currentUser['telegram_notify_streak'] ?? 1) === 1 ? 'checked' : '' ?>><?= e(t('settings.telegram_notify_streak')) ?></label></div>
                    <div class="toggle-row"><label class="check standalone-check"><input type="checkbox" name="telegram_notify_social" value="1" <?= (int) ($currentUser['telegram_notify_social'] ?? 1) === 1 ? 'checked' : '' ?>><?= e(t('settings.telegram_notify_social')) ?></label></div>
                </fieldset>

                <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
            </form>
            <?php if ($telegramBotUrl !== ''): ?>
                <aside class="telegram-manage-card">
                    <span class="telegram-manage-icon" aria-hidden="true"><?= activity_icon_svg('bell') ?></span>
                    <span><strong><?= e(t('settings.telegram_manage_bot')) ?></strong><small><?= e(t('settings.telegram_manage_bot_hint')) ?></small></span>
                    <a class="btn btn-primary small" href="<?= e($telegramBotUrl) ?>" target="_blank" rel="noopener"><?= e(t('settings.telegram_open_bot')) ?></a>
                </aside>
            <?php endif; ?>
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
