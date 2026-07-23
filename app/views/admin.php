<?php

declare(strict_types=1);

$days = [];
for ($i = 0; $i < 7; $i++) {
    $days[$i] = t('weekday.' . $i);
}
$entityTypes = ['daily_log', 'approval_request', 'user', 'team_membership', 'goal', 'achievement', 'workout_type', 'exercise_definition', 'workout_rank_tier', 'season', 'photo_entry', 'app_setting', 'system_backup', 'motivational_quote'];
$activeSection = (string) ($_GET['section'] ?? '');
$allowedSections = ['users', 'registration_links', 'challenge', 'app', 'appearance', 'notion', 'telegram', 'backups', 'habits', 'workout_types', 'training', 'achievements', 'motivational_quotes', 'xp', 'audit'];
if (!in_array($activeSection, $allowedSections, true)) {
    $activeSection = '';
}
$selectedUserId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
$createUserMode = (string) ($_GET['create_user'] ?? '') === '1';
$selectedHabitId = (string) ($_GET['habit_id'] ?? '');
$selectedTypeId = (string) ($_GET['type_id'] ?? '');
$selectedAchievementId = (string) ($_GET['achievement_id'] ?? '');
$selectedTrainingExerciseId = trim((string) ($_GET['exercise_id'] ?? ''));
$selectedAdminAchievementId = (int) ($selectedAdminAchievementId ?? 0);
$adminAchievementStats = is_array($adminAchievementStats ?? null) ? (array) $adminAchievementStats : [];
$selectedAdminAchievement = null;
foreach ((array) ($adminAchievements ?? []) as $adminAchievementCandidate) {
    if ((int) ($adminAchievementCandidate['id'] ?? 0) === $selectedAdminAchievementId) {
        $selectedAdminAchievement = $adminAchievementCandidate;
        break;
    }
}
$penaltiesEnabled = !empty($penaltiesEnabled);
$mediaSearchEnabled = !empty($mediaSearchEnabled);
$mediaSearchGoogleReady = !empty($mediaSearchGoogleReady);
$mediaSearchYoutubeReady = !empty($mediaSearchYoutubeReady);
$achievementLocales = locale_options();
$achievementIconOptions = achievement_icon_options();
$sectionRows = [];
foreach ($allowedSections as $sectionKey) {
    $sectionRows[$sectionKey] = t('admin.section_' . $sectionKey);
}
$adminGroups = [
    'people' => ['title' => t('admin.group_people'), 'hint' => t('admin.group_people_hint'), 'icon' => 'users', 'tone' => 'blue', 'sections' => ['users', 'audit']],
    'experience' => ['title' => t('admin.group_experience'), 'hint' => t('admin.group_experience_hint'), 'icon' => 'spark', 'tone' => 'violet', 'sections' => ['app', 'appearance', 'notion', 'telegram', 'motivational_quotes']],
    'training' => ['title' => t('admin.group_training'), 'hint' => t('admin.group_training_hint'), 'icon' => 'dumbbell', 'tone' => 'green', 'sections' => ['habits', 'workout_types', 'training', 'achievements']],
    'system' => ['title' => t('admin.group_system'), 'hint' => t('admin.group_system_hint'), 'icon' => 'shield', 'tone' => 'orange', 'sections' => ['challenge', 'xp', 'backups']],
];
$adminGroup = trim((string) ($_GET['group'] ?? ''));
if (!array_key_exists($adminGroup, $adminGroups)) {
    $adminGroup = '';
}
$activeSectionGroup = '';
if ($activeSection !== '') {
    foreach ($adminGroups as $groupKey => $group) {
        if (in_array($activeSection, (array) $group['sections'], true)) {
            $activeSectionGroup = $groupKey;
            break;
        }
    }
}
if ($activeSection === 'registration_links') {
    $activeSectionGroup = 'people';
}
$adminHeaderTitle = $activeSection !== ''
    ? (string) ($sectionRows[$activeSection] ?? t('admin.title'))
    : ($adminGroup !== '' ? (string) $adminGroups[$adminGroup]['title'] : t('admin.title'));
$adminHeaderHint = match (true) {
    $activeSection === 'registration_links' => t('admin.registration_links_hint'),
    $activeSection === 'users' => t('admin.group_people_hint'),
    $activeSection === 'audit' => t('audit.subtitle'),
    $activeSection === 'app' => t('admin.app_help'),
    $activeSection === 'notion' => t('admin.notion_page_hint'),
    $activeSection === 'telegram' => t('admin.telegram_page_hint'),
    $activeSection === 'motivational_quotes' => t('admin.motivational_quotes_page_hint'),
    $activeSection === 'habits' => t('admin.habits_page_hint'),
    $activeSection === 'workout_types' => t('admin.workout_types_page_hint'),
    $activeSection === 'xp' => t('admin.xp_control_hint'),
    $activeSection === 'backups' => t('admin.backups_control_hint'),
    $activeSection !== '' => t('admin.subtitle'),
    $adminGroup !== '' => (string) $adminGroups[$adminGroup]['hint'],
    default => t('admin.hub_hint'),
};
$adminBackDestination = $activeSection === 'registration_links'
    ? t('admin.section_users')
    : ($activeSection !== '' && $activeSectionGroup !== ''
    ? (string) ($adminGroups[$activeSectionGroup]['title'] ?? t('nav.admin'))
    : t('nav.admin'));
$adminHeaderBackHref = $activeSection === 'registration_links'
    ? '/?page=admin&amp;section=users'
    : ($activeSection !== '' && $activeSectionGroup !== '' ? '/?page=admin&amp;group=' . e($activeSectionGroup) : '/?page=admin');
$renderAdminBack = static function (string $href, string $destination): void {
    ?>
    <a class="hierarchy-back destination-back" href="<?= e($href) ?>" data-spa-back aria-label="<?= e(t('common.back')) ?>: <?= e($destination) ?>"><span aria-hidden="true">&larr;</span><strong><?= e($destination) ?></strong></a>
    <?php
};
$visibleSectionRows = [];
if ($adminGroup !== '') {
    foreach ((array) $adminGroups[$adminGroup]['sections'] as $sectionKey) {
        $visibleSectionRows[$sectionKey] = (string) ($sectionRows[$sectionKey] ?? $sectionKey);
    }
}
$appIconPath = trim((string) ($appIconPath ?? ''));
$activeLoginBackgroundPath = trim((string) ($loginBackgroundPath ?? ''));
$activeLoginBackgroundUrl = $activeLoginBackgroundPath !== '' ? media_url($activeLoginBackgroundPath) : '';
$backupSettings = is_array($backupSettings ?? null) ? (array) $backupSettings : [];
$backupAutoEnabled = !empty($backupSettings['enabled']);
$backupFrequency = normalize_backup_frequency((string) ($backupSettings['frequency'] ?? 'daily'));
$backupRunTime = normalize_backup_run_time((string) ($backupSettings['run_time'] ?? '00:00'));
$backupRetentionCount = max(1, (int) ($backupSettings['retention_count'] ?? 20));
$backupLastAutoAt = trim((string) ($backupSettings['last_auto_at'] ?? ''));
$backupLastDrillAt = trim((string) ($backupSettings['last_drill_at'] ?? ''));
$backupNextRunAt = trim((string) ($backupSettings['next_run_at'] ?? ''));
$backupLastError = trim((string) ($backupSettings['last_error'] ?? ''));
$integrationStatuses = is_array($integrationStatuses ?? null) ? (array) $integrationStatuses : [];
$integrationState = static function (array $worker): string {
    $state = (string) ($worker['state'] ?? 'stopped');
    return in_array($state, ['active', 'stopped', 'delayed', 'error'], true) ? $state : 'stopped';
};
$integrationStateLabel = static fn(string $state): string => t('admin.integration_worker_' . $state);
$formatRuntimeDate = static function (string $value): string {
    if ($value === '') {
        return t('admin.backup_last_auto_never');
    }
    try {
        $date = new DateTimeImmutable($value);
        return format_date_eu($date->format('Y-m-d')) . ' ' . $date->format('H:i');
    } catch (Throwable) {
        return $value;
    }
};
$backupLastAutoLabel = t('admin.backup_last_auto_never');
if ($backupLastAutoAt !== '') {
    try {
        $backupLastAutoDate = new DateTimeImmutable($backupLastAutoAt);
        $backupLastAutoLabel = format_date_eu($backupLastAutoDate->format('Y-m-d')) . ' ' . $backupLastAutoDate->format('H:i');
    } catch (Throwable) {
        $backupLastAutoLabel = $backupLastAutoAt;
    }
}
$backupTriggerLabel = static function (string $trigger): string {
    return match ($trigger) {
        'auto' => t('admin.backup_trigger_auto'),
        'pre_restore' => t('admin.backup_trigger_pre_restore'),
        default => t('admin.backup_trigger_manual'),
    };
};
$backupStatusLabel = static function (string $status): string {
    return match ($status) {
        'restored' => t('admin.backup_status_restored'),
        'verified' => t('admin.backup_status_verified'),
        'error' => t('common.error'),
        default => t('admin.backups_status_created'),
    };
};
$workoutTypeFieldsByType = is_array($workoutTypeFields ?? null) ? (array) $workoutTypeFields : [];
$workoutFieldDataKeyLabels = [
    '' => t('workout_fields.data_informational'),
    'distance_km' => t('metric.distance_km'),
    'training_calories_burned' => t('entries.training_calories_burned'),
    'steps' => t('metric.steps'),
];
$challengeSettings = is_array($challengeSettings ?? null) ? (array) $challengeSettings : [];
$challengeName = trim((string) ($challengeSettings['challenge_name'] ?? 'Fitness Challenge'));
if ($challengeName === '') {
    $challengeName = 'Fitness Challenge';
}
$challengeStart = to_date((string) ($challengeSettings['challenge_start'] ?? null), to_date(null));
$challengeEnd = to_date((string) ($challengeSettings['challenge_end'] ?? null), $challengeStart);
$challengeRangeLabel = format_date_eu($challengeStart) . ' - ' . format_date_eu($challengeEnd);
$challengeIsActive = challenge_is_active($challengeSettings);
$challengeArchiveCount = count((array) ($challengeArchives ?? []));
$nextChallengeStart = to_date(null);
try {
    $candidateStart = (new DateTimeImmutable($challengeEnd))->modify('+1 day')->format('Y-m-d');
    if ($candidateStart > $nextChallengeStart) {
        $nextChallengeStart = $candidateStart;
    }
} catch (Throwable) {
    $nextChallengeStart = to_date(null);
}
try {
    $nextChallengeEnd = (new DateTimeImmutable($nextChallengeStart))->modify('+55 days')->format('Y-m-d');
} catch (Throwable) {
    $nextChallengeEnd = $nextChallengeStart;
}
?>
<section class="screen stack-lg spa-shell admin-settings-screen" data-spa-page="admin" data-admin-group="<?= e($adminGroup) ?>">
    <header class="hierarchy-page-header<?= $activeSection === '' && $adminGroup === '' ? ' hierarchy-page-header-root settings-compact-header' : ' settings-focused-head settings-section-head' ?>">
        <?php if ($activeSection !== '' || $adminGroup !== ''): ?>
            <a class="hierarchy-back destination-back" href="<?= $adminHeaderBackHref ?>" aria-label="<?= e(t('common.back')) ?>: <?= e($adminBackDestination) ?>"><span aria-hidden="true">&larr;</span><strong><?= e($adminBackDestination) ?></strong></a>
        <?php endif; ?>
        <div>
            <p class="eyebrow"><?= e(t('nav.admin')) ?></p>
            <h1><?= e($adminHeaderTitle) ?></h1>
            <p class="muted"><?= e($adminHeaderHint) ?></p>
        </div>
    </header>

    <?php if ($activeSection === ''): ?>
    <nav class="admin-settings-hub" data-spa-main aria-label="<?= e(t('admin.title')) ?>">
        <?php if ($adminGroup === ''): ?>
            <div class="settings-nav-grid admin-group-grid">
                <?php foreach ($adminGroups as $groupKey => $group): ?>
                    <a class="settings-nav-item" data-tone="<?= e((string) $group['tone']) ?>" href="/?page=admin&amp;group=<?= e($groupKey) ?>">
                        <span class="settings-nav-icon" aria-hidden="true"><?= activity_icon_svg((string) $group['icon']) ?></span>
                        <span class="settings-nav-copy"><strong><?= e((string) $group['title']) ?></strong><small><?= e((string) $group['hint']) ?></small></span>
                        <span class="settings-nav-meta"><?= count((array) $group['sections']) ?></span>
                        <span class="settings-nav-arrow" aria-hidden="true">&rsaquo;</span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
        <div class="settings-list admin-section-list-menu">
        <?php foreach ($visibleSectionRows as $sectionKey => $label): ?>
            <a class="settings-row" href="/?page=admin&section=<?= e($sectionKey) ?>" data-spa-link>
                <span><?= e($label) ?></span>
                <span class="settings-chevron" aria-hidden="true">›</span>
            </a>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </nav>
    <?php endif; ?>

    <?php if ($activeSection === ''): ?>
</section>
<?php return; ?>
    <?php endif; ?>

    <?php if ($activeSection === 'registration_links'): ?>
    <article class="panel settings-panel active admin-registration-links-page" data-spa-section="registration_links">
        <section class="admin-invite-panel">
            <div class="admin-invite-head">
                <span aria-hidden="true"><?= activity_icon_svg('link') ?></span>
                <div><h2><?= e(t('admin.invite_new_title')) ?></h2><p><?= e(t('admin.invite_new_hint')) ?></p></div>
            </div>
            <?php if (trim((string) ($registrationInviteUrl ?? '')) !== ''): ?>
                <div class="admin-invite-created" role="status">
                    <strong><?= e(t('admin.invite_latest')) ?></strong>
                    <div><input type="text" value="<?= e((string) $registrationInviteUrl) ?>" readonly data-registration-invite-url><button class="btn btn-primary" type="button" data-copy-registration-url data-copy-label="<?= e(t('admin.invite_copy')) ?>" data-copied-label="<?= e(t('admin.invite_copied')) ?>"><?= e(t('admin.invite_copy')) ?></button></div>
                </div>
            <?php endif; ?>
            <form method="post" action="/?page=admin&amp;section=registration_links" class="admin-invite-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="create_registration_invite">
                <label><span><?= e(t('admin.invite_label')) ?></span><input type="text" name="invite_label" maxlength="80" placeholder="<?= e(t('admin.invite_label_placeholder')) ?>"></label>
                <label><span><?= e(t('admin.invite_expiry')) ?></span><input type="number" name="expires_in_days" min="1" max="365" value="7" required></label>
                <label><span><?= e(t('admin.invite_max_uses')) ?></span><input type="number" name="max_uses" min="1" max="100" value="1" required></label>
                <button class="btn btn-primary" type="submit"><?= e(t('admin.invite_generate')) ?></button>
            </form>
            <div class="admin-invite-list">
                <h3><?= e(t('admin.invite_history_title')) ?></h3>
                <?php if ((array) ($registrationInvites ?? []) === []): ?>
                    <p class="admin-invite-empty"><?= e(t('admin.invite_empty')) ?></p>
                <?php else: ?>
                    <?php foreach ((array) $registrationInvites as $invite): ?>
                        <?php $inviteStatus = (string) ($invite['status'] ?? 'revoked'); ?>
                        <article class="admin-invite-row" data-status="<?= e($inviteStatus) ?>">
                            <span class="admin-invite-token">#<?= e((string) ($invite['token_hint'] ?? '')) ?></span>
                            <span class="admin-invite-main"><strong><?= e(trim((string) ($invite['label'] ?? '')) !== '' ? (string) $invite['label'] : t('admin.registration_links_title')) ?></strong><small><?= e(t('admin.invite_uses', ['used' => (int) ($invite['used_count'] ?? 0), 'max' => (int) ($invite['max_uses'] ?? 1)])) ?> · <?= e(t('admin.invite_expires', ['date' => format_date_eu(substr((string) ($invite['expires_at'] ?? ''), 0, 10))])) ?></small></span>
                            <span class="admin-invite-status"><?= e(t('admin.invite_status_' . $inviteStatus)) ?></span>
                            <?php if ($inviteStatus === 'active'): ?>
                                <form method="post" action="/?page=admin&amp;section=registration_links" class="inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="revoke_registration_invite"><input type="hidden" name="invite_id" value="<?= (int) $invite['id'] ?>">
                                    <button class="btn btn-ghost small" type="submit"><?= e(t('admin.invite_revoke')) ?></button>
                                </form>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    </article>
    <?php endif; ?>

    <?php if ($activeSection === 'users'): ?>
    <?php
    $adminUsersTotal = count((array) $users);
    $adminUsersActive = count(array_filter((array) $users, static fn(array $user): bool => (int) ($user['active'] ?? 1) === 1));
    $adminUsersAdmins = count(array_filter((array) $users, static fn(array $user): bool => (string) ($user['role'] ?? 'user') === 'admin'));
    $adminUsersPending = count(array_filter((array) $users, static fn(array $user): bool => (string) ($user['onboarding_status'] ?? 'complete') === 'pending'));
    $adminActiveInvites = count(array_filter((array) ($registrationInvites ?? []), static fn(array $invite): bool => (string) ($invite['status'] ?? '') === 'active'));
    ?>
    <article class="panel settings-panel active" data-spa-section="users">
        <section class="admin-users-overview admin-section-list" data-spa-show-when-no-param="create_user,user_id" <?= ($createUserMode || $selectedUserId > 0) ? 'hidden' : '' ?>>
            <div class="admin-users-toolbar">
                <div>
                    <p class="eyebrow"><?= e(t('admin.group_people')) ?></p>
                    <h2><?= e(t('admin.section_users')) ?> <span><?= $adminUsersTotal ?></span></h2>
                </div>
                <a class="btn btn-primary small" href="/?page=admin&section=users&create_user=1" data-spa-link><span aria-hidden="true">+</span><?= e(t('users.create')) ?></a>
            </div>
            <div class="admin-users-stats" aria-label="<?= e(t('admin.users_summary')) ?>">
                <span><strong><?= $adminUsersActive ?></strong><small><?= e(t('common.active')) ?></small></span>
                <span><strong><?= $adminUsersAdmins ?></strong><small><?= e(t('common.admin')) ?></small></span>
                <span><strong><?= $adminUsersPending ?></strong><small><?= e(t('admin.users_pending_setup')) ?></small></span>
            </div>
            <a class="admin-users-registration-link" href="/?page=admin&amp;section=registration_links" data-spa-link>
                <span class="admin-users-registration-icon" aria-hidden="true"><?= activity_icon_svg('link') ?></span>
                <span><strong><?= e(t('admin.registration_links_title')) ?></strong><small><?= e(t('admin.registration_links_short_hint')) ?></small></span>
                <span class="admin-users-registration-count"><?= e(t('admin.invites_active_count', ['count' => $adminActiveInvites])) ?></span>
                <span aria-hidden="true">›</span>
            </a>
            <label class="admin-users-search">
                <span aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg></span>
                <span class="sr-only"><?= e(t('admin.users_search')) ?></span>
                <input type="search" autocomplete="off" placeholder="<?= e(t('admin.users_search_placeholder')) ?>" data-admin-user-search>
            </label>
        </section>

        <div class="settings-list compact-list admin-section-list admin-users-list" data-admin-users-list data-spa-show-when-no-param="create_user,user_id" <?= ($createUserMode || $selectedUserId > 0) ? 'hidden' : '' ?>>
            <?php foreach ($users as $user): ?>
                <?php $adminUserAvatarUrl = avatar_url($user); ?>
                <a class="settings-row admin-user-row" href="/?page=admin&section=users&user_id=<?= (int) $user['id'] ?>" data-admin-user-row data-admin-user-search-value="<?= e((string) $user['display_name'] . ' ' . (string) $user['username'] . ' ' . (string) $user['role']) ?>" data-spa-link>
                    <?php if ($adminUserAvatarUrl !== ''): ?>
                        <img class="admin-user-avatar" src="<?= e($adminUserAvatarUrl) ?>" alt="<?= e((string) $user['display_name']) ?>">
                    <?php else: ?>
                        <span class="admin-user-avatar admin-user-avatar-initials"><?= e(initials_for((string) $user['display_name'])) ?></span>
                    <?php endif; ?>
                    <span class="admin-user-main">
                        <strong><?= e((string) $user['display_name']) ?></strong>
                        <small class="muted">@<?= e((string) $user['username']) ?> · <?= e((string) $user['role']) ?></small>
                    </span>
                    <span class="admin-user-badges">
                        <?php if (($user['onboarding_status'] ?? 'complete') === 'pending'): ?><span class="badge badge-warn"><?= e(t('onboarding.title')) ?></span><?php endif; ?>
                        <span class="badge <?= (int) ($user['active'] ?? 1) === 1 ? 'badge-ok' : 'badge-warn' ?>"><?= (int) ($user['active'] ?? 1) === 1 ? e(t('common.active')) : e(t('workout_types.inactive')) ?></span>
                    </span>
                    <span class="settings-chevron" aria-hidden="true">›</span>
                </a>
            <?php endforeach; ?>
        </div>
        <p class="admin-users-empty" data-admin-users-empty hidden><?= e(t('admin.users_no_results')) ?></p>

        <div class="stack admin-create-view" data-spa-param-show="create_user" data-spa-value="1" <?= $createUserMode ? '' : 'hidden' ?>>
            <div class="panel-head">
                <h3><?= e(t('users.create')) ?></h3>
                <?php $renderAdminBack('/?page=admin&section=users', t('admin.section_users')); ?>
            </div>
            <form method="post" action="/?page=admin" class="stack">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="create_user">
                <div class="grid-inline">
                    <label><?= e(t('common.username')) ?><input type="text" name="username" required></label>
                    <label><?= e(t('common.display_name')) ?><input type="text" name="display_name" required></label>
                    <label><?= e(t('users.initial_password')) ?><input type="password" name="password" required></label>
                    <label><?= e(t('common.role')) ?><select name="role"><option value="user">User</option><option value="admin"><?= e(t('common.admin')) ?></option></select></label>
                </div>
                <div class="grid-inline">
                    <label><?= e(t('users.step_goal')) ?><input type="number" min="0" name="step_goal" value="10000" required></label>
                    <label><?= e(t('settings.primary_goal')) ?><select name="primary_goal_type"><option value="steps"><?= e(t('metric.steps')) ?></option><option value="km"><?= e(t('metric.distance_km')) ?></option></select></label>
                    <label><?= e(t('settings.primary_goal_value')) ?><input type="number" step="0.01" name="primary_goal_value"></label>
                    <label><?= e(t('users.workout_target_week')) ?><input type="number" min="0" name="workout_target" value="3" required></label>
                    <label><?= e(t('metric.ideal_weight')) ?> (kg)<input type="number" step="0.1" name="ideal_weight"></label>
                    <label><?= e(t('users.workout_strict_days')) ?><select name="workout_strict"><option value="0"><?= e(t('common.no')) ?></option><option value="1"><?= e(t('common.yes')) ?></option></select></label>
                </div>
                <div class="chip-group">
                    <span><?= e(t('users.step_goal_days')) ?>:</span>
                    <?php foreach ($days as $idx => $label): ?>
                        <label class="chip"><input type="checkbox" name="step_days[]" value="<?= $idx ?>" checked> <?= e($label) ?></label>
                    <?php endforeach; ?>
                </div>
                <div class="chip-group">
                    <span><?= e(t('users.workout_goal_days')) ?>:</span>
                    <?php foreach ($days as $idx => $label): ?>
                        <label class="chip"><input type="checkbox" name="workout_days[]" value="<?= $idx ?>"> <?= e($label) ?></label>
                    <?php endforeach; ?>
                </div>
                <label class="check standalone-check"><input type="checkbox" name="active" value="1" checked><?= e(t('users.active_user')) ?></label>
                <label class="check standalone-check"><input type="checkbox" name="require_onboarding" value="1" checked><?= e(t('admin.require_onboarding')) ?></label>
                <button type="submit" class="btn btn-primary"><?= e(t('users.create')) ?></button>
            </form>
        </div>

        <?php foreach ($users as $user): ?>
            <div class="stack admin-detail-view" data-spa-param-show="user_id" data-spa-value="<?= (int) $user['id'] ?>" <?= $selectedUserId === (int) $user['id'] ? '' : 'hidden' ?>>
                <div class="panel-head">
                    <h3><?= e((string) $user['display_name']) ?></h3>
                    <?php $renderAdminBack('/?page=admin&section=users', t('admin.section_users')); ?>
                </div>
                <form method="post" action="/?page=admin" class="stack">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="update_user">
                    <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                    <div class="grid-inline">
                        <label><?= e(t('common.username')) ?><input type="text" value="<?= e((string) $user['username']) ?>" disabled></label>
                        <label><?= e(t('common.display_name')) ?><input type="text" name="display_name" value="<?= e((string) $user['display_name']) ?>" required></label>
                        <label><?= e(t('common.role')) ?><select name="role"><option value="user" <?= $user['role'] === 'user' ? 'selected' : '' ?>>User</option><option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>><?= e(t('common.admin')) ?></option></select></label>
                        <label><?= e(t('users.new_password_optional')) ?><input type="password" name="password" minlength="8"></label>
                    </div>
                    <div class="grid-inline">
                        <label><?= e(t('users.step_goal')) ?><input type="number" min="0" name="step_goal" value="<?= e((string) $user['step_goal']) ?>" required></label>
                        <label><?= e(t('settings.primary_goal')) ?><select name="primary_goal_type"><option value="steps" <?= ($user['primary_goal_type'] ?? 'steps') === 'steps' ? 'selected' : '' ?>><?= e(t('metric.steps')) ?></option><option value="km" <?= ($user['primary_goal_type'] ?? 'steps') === 'km' ? 'selected' : '' ?>><?= e(t('metric.distance_km')) ?></option></select></label>
                        <label><?= e(t('settings.primary_goal_value')) ?><input type="number" step="0.01" name="primary_goal_value" value="<?= e((string) ($user['primary_goal_value'] ?? '')) ?>"></label>
                        <label><?= e(t('profile.workout_target')) ?><input type="number" min="0" name="workout_target" value="<?= e((string) $user['workout_target']) ?>" required></label>
                        <label><?= e(t('metric.ideal_weight')) ?><input type="number" step="0.1" name="ideal_weight" value="<?= e((string) ($user['ideal_weight'] ?? '')) ?>"></label>
                        <label><?= e(t('profile.workout_strict')) ?><select name="workout_strict"><option value="0" <?= (int) $user['workout_strict'] === 0 ? 'selected' : '' ?>><?= e(t('common.no')) ?></option><option value="1" <?= (int) $user['workout_strict'] === 1 ? 'selected' : '' ?>><?= e(t('common.yes')) ?></option></select></label>
                    </div>
                    <div class="chip-group">
                        <span><?= e(t('profile.step_days')) ?>:</span>
                        <?php foreach ($days as $idx => $label): ?>
                            <label class="chip"><input type="checkbox" name="step_days[]" value="<?= $idx ?>" <?= isset($user['step_days_mask'][$idx]) && $user['step_days_mask'][$idx] === '1' ? 'checked' : '' ?>> <?= e($label) ?></label>
                        <?php endforeach; ?>
                    </div>
                    <div class="chip-group">
                        <span><?= e(t('profile.workout_days')) ?>:</span>
                        <?php foreach ($days as $idx => $label): ?>
                            <label class="chip"><input type="checkbox" name="workout_days[]" value="<?= $idx ?>" <?= isset($user['workout_days_mask'][$idx]) && $user['workout_days_mask'][$idx] === '1' ? 'checked' : '' ?>> <?= e($label) ?></label>
                        <?php endforeach; ?>
                    </div>
                    <label class="check standalone-check"><input type="checkbox" name="active" value="1" <?= (int) $user['active'] === 1 ? 'checked' : '' ?>><?= e(t('common.active')) ?></label>
                    <button type="submit" class="btn btn-primary"><?= e(t('users.save_user', ['name' => (string) $user['display_name']])) ?></button>
                </form>
            </div>
        <?php endforeach; ?>
    </article>

    <?php endif; ?>
    <?php if ($activeSection === 'challenge'): ?>
        <?php require __DIR__ . '/partials/admin_challenge.php'; ?>
    <?php endif; ?>
    <?php if ($activeSection === 'app'): ?>
    <?php
    $adminAppName = trim((string) ($appNameSetting ?? 'Fitness Challenge Tracker'));
    if ($adminAppName === '') {
        $adminAppName = 'Fitness Challenge Tracker';
    }
    $adminAppLoginStyle = login_style_normalize($loginStyle ?? 'split');
    $adminAppLoginStyleLabel = t('admin.login_style_' . $adminAppLoginStyle);
    ?>
    <article class="panel settings-panel active admin-app-page" data-spa-section="app">
        <section class="admin-app-identity">
            <div class="admin-app-mark" aria-hidden="true">
                <?php if ($appIconPath !== ''): ?>
                    <img src="<?= e(with_cache_buster('/?page=app_icon', $appIconVersion ?? null)) ?>" alt="">
                <?php else: ?>
                    <span><?= e(initials_for($adminAppName)) ?></span>
                <?php endif; ?>
            </div>
            <div class="admin-app-identity-copy">
                <p class="eyebrow"><?= e(t('admin.app_current_identity')) ?></p>
                <h2><?= e($adminAppName) ?></h2>
                <p><?= e(t('admin.app_name_help')) ?></p>
            </div>
            <span class="badge <?= $appIconPath !== '' ? 'badge-ok' : 'badge-warn' ?>"><?= e($appIconPath !== '' ? t('admin.app_icon_configured') : t('admin.app_icon_default')) ?></span>
        </section>

        <div class="admin-app-status-grid">
            <span><small><?= e(t('admin.penalties_feature')) ?></small><strong><?= e($penaltiesEnabled ? t('common.active') : t('workout_types.inactive')) ?></strong></span>
            <span><small><?= e(t('admin.login_style_title')) ?></small><strong><?= e($adminAppLoginStyleLabel) ?></strong></span>
            <span><small><?= e(t('admin.login_background_title')) ?></small><strong><?= e($activeLoginBackgroundPath !== '' ? t('common.active') : t('admin.app_default_visual')) ?></strong></span>
        </div>

        <div class="admin-app-settings-grid">
            <section class="admin-app-card">
                <header>
                    <span class="admin-app-card-icon" aria-hidden="true"><?= activity_icon_svg('spark') ?></span>
                    <div><h3><?= e(t('admin.app_branding')) ?></h3><p><?= e(t('admin.app_branding_help')) ?></p></div>
                </header>
                <form method="post" action="/?page=admin&amp;section=app" class="admin-app-name-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="update_app_name">
                    <label><span><?= e(t('admin.app_name')) ?></span><input type="text" name="app_name" maxlength="80" value="<?= e($adminAppName) ?>" required></label>
                    <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
                </form>
            </section>

            <section class="admin-app-card">
                <header>
                    <span class="admin-app-card-icon is-feature" aria-hidden="true"><?= activity_icon_svg('shield') ?></span>
                    <div><h3><?= e(t('admin.app_features')) ?></h3><p><?= e(t('admin.app_features_help')) ?></p></div>
                </header>
                <form method="post" action="/?page=admin&amp;section=app" class="admin-app-feature-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="update_penalties_feature">
                    <label class="admin-app-feature-row">
                        <span><strong><?= e(t('admin.penalties_enabled')) ?></strong><small><?= e(t('admin.penalties_feature_hint')) ?></small></span>
                        <span class="admin-app-switch"><input type="checkbox" name="penalties_enabled" value="1" <?= $penaltiesEnabled ? 'checked' : '' ?>><i aria-hidden="true"></i></span>
                    </label>
                    <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
                </form>
            </section>
        </div>

        <a class="admin-app-appearance-link" href="/?page=admin&amp;section=appearance" data-spa-link>
            <span class="admin-app-card-icon" aria-hidden="true"><?= activity_icon_svg('image') ?></span>
            <span><strong><?= e(t('admin.app_manage_appearance')) ?></strong><small><?= e(t('admin.app_manage_appearance_hint')) ?></small></span>
            <span aria-hidden="true">›</span>
        </a>
    </article>

    <?php endif; ?>
    <?php if ($activeSection === 'notion'): ?>
    <article class="panel settings-panel active admin-notion-page" data-spa-section="notion">
        <?php
        $notion = is_array($notionSettings ?? null) ? (array) $notionSettings : [];
        $notionConfigured = ($notion['token'] ?? '') !== '' && ($notion['database_id'] ?? '') !== '';
        $notionOauthConfigured = ($notion['oauth_client_id'] ?? '') !== '' && ($notion['oauth_client_secret'] ?? '') !== '';
        $notionBaseUrl = (string) ($notion['base_url'] ?? '');
        $notionRedirectUri = $notionBaseUrl !== '' ? rtrim($notionBaseUrl, '/') . '/?page=notion_oauth_callback' : '';
        $notionConnected = ($notion['token'] ?? '') !== '';
        $notionWorker = (array) ($integrationStatuses['notion'] ?? []);
        $notionWorkerState = $integrationState($notionWorker);
        $nStatus = is_array($notionStatus ?? null) ? (array) $notionStatus : [];
        $nCounts = (array) ($nStatus['counts'] ?? []);
        $nError = trim((string) ($nStatus['error'] ?? ''));
        $nLast = trim((string) ($nStatus['last_sync_at'] ?? ''));
        $nState = $nError !== '' ? 'error' : ($nLast !== '' ? 'ok' : 'never');
        ?>
        <section class="notion-status-card is-<?= e($nState) ?>">
            <div class="notion-status-head">
                <span class="admin-notion-logo" aria-hidden="true">N<span class="notion-status-dot"></span></span>
                <div>
                    <strong><?= e(t('admin.notion_status_' . $nState)) ?></strong>
                    <span class="muted small">
                        <?php if ($nLast !== ''): ?>
                            <?= e(t('admin.notion_last_sync')) ?>: <?= e(human_time_ago($nLast)) ?>
                        <?php else: ?>
                            <?= e(t('admin.notion_never_synced')) ?>
                        <?php endif; ?>
                        &middot; <?= e(t('admin.notion_direction')) ?>: <?= e(t('admin.notion_dir_' . (($notion['direction'] ?? 'push_only') === 'two_way' ? 'two_way' : 'push'))) ?>
                    </span>
                </div>
                <div class="admin-notion-status-actions">
                    <span class="badge <?= !empty($notion['enabled']) ? 'badge-ok' : 'badge-warn' ?>"><?= e(!empty($notion['enabled']) ? t('common.active') : t('workout_types.inactive')) ?></span>
                    <form method="post" action="/?page=admin&amp;section=notion" class="inline-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="notion_sync_now">
                        <button class="btn btn-primary small" type="submit" <?= $notionConfigured ? '' : 'disabled' ?>><?= e(t('admin.notion_sync_now')) ?></button>
                    </form>
                </div>
            </div>

            <?php if ($nCounts !== []): ?>
                <div class="notion-status-counts">
                    <?php foreach (['created', 'updated', 'pulled', 'skipped', 'failed', 'remaining'] as $ck): ?>
                        <span class="notion-count<?= $ck === 'failed' && (int) ($nCounts[$ck] ?? 0) > 0 ? ' is-bad' : '' ?>">
                            <strong><?= (int) ($nCounts[$ck] ?? 0) ?></strong>
                            <small><?= e(t('admin.notion_count_' . $ck)) ?></small>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <p class="muted small notion-status-records">
                <?= e(t('admin.notion_synced_records', ['n' => (int) ($nStatus['synced_records'] ?? 0)])) ?>
                <?php if ((int) ($nStatus['pending_records'] ?? 0) > 0): ?>
                    &middot; <?= e(t('admin.notion_pending_records', ['n' => (int) $nStatus['pending_records']])) ?>
                <?php endif; ?>
            </p>

            <?php $nFields = (array) ($nStatus['fields'] ?? []); ?>
            <?php if ($nFields !== []): ?>
                <details class="notion-field-detail">
                    <summary><?= e(t('admin.notion_fields_title')) ?></summary>
                    <?php if (empty($nStatus['schema_known'])): ?>
                        <p class="muted small"><?= e(t('admin.notion_schema_unknown')) ?></p>
                    <?php endif; ?>
                    <ul class="notion-field-list">
                        <?php foreach ($nFields as $nf): ?>
                            <li class="notion-field-row<?= !empty($nf['missing_property']) ? ' is-bad' : '' ?>">
                                <span class="notion-field-name"><?= e((string) $nf['label']) ?></span>
                                <span class="notion-field-prop">
                                    <?= (string) $nf['property'] !== '' ? e((string) $nf['property']) : '&mdash;' ?>
                                </span>
                                <span class="notion-field-dir dir-<?= e((string) $nf['direction']) ?>">
                                    <?= e(t('admin.notion_dir_' . (string) $nf['direction'])) ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="muted small"><?= e(t('admin.notion_fields_hint')) ?></p>
                </details>
            <?php endif; ?>

            <?php if ($nError !== ''): ?>
                <p class="notion-status-error"><?= e($nError) ?></p>
            <?php endif; ?>
        </section>

        <?php
        $notionFieldLabels = is_array($notionFieldLabels ?? null) ? (array) $notionFieldLabels : [];
        $notionFieldMap = is_array($notionFieldMap ?? null) ? (array) $notionFieldMap : [];
        $notionSchemaCache = is_array($notionSchemaCache ?? null) ? (array) $notionSchemaCache : [];
        $notionSchemaNames = array_keys($notionSchemaCache);
        ?>
        <div class="admin-notion-sections">
            <details class="admin-notion-section" <?= !$notionConfigured ? 'open' : '' ?>>
                <summary>
                    <span class="admin-notion-section-icon" aria-hidden="true"><?= activity_icon_svg('link') ?></span>
                    <span><strong><?= e(t('admin.notion_connection_title')) ?></strong><small><?= e(t('admin.notion_connection_hint')) ?></small></span>
                    <span class="badge <?= $notionConfigured ? 'badge-ok' : 'badge-warn' ?>"><?= e($notionConfigured ? t('admin.notion_configured') : t('admin.notion_incomplete')) ?></span>
                    <span aria-hidden="true">⌄</span>
                </summary>
                <div class="admin-notion-section-body admin-notion-connection-grid">
                    <section class="admin-notion-inner-card admin-notion-oauth">
                        <h4><?= e(t('admin.notion_oauth_title')) ?></h4>
                        <?php if ($notionConnected && ($notion['workspace_name'] ?? '') !== ''): ?>
                            <p class="admin-notion-connected">✓ <?= e(t('admin.notion_oauth_connected', ['workspace' => (string) $notion['workspace_name']])) ?></p>
                            <form method="post" action="/?page=admin&amp;section=notion">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="notion_oauth_disconnect">
                                <button class="btn btn-ghost small" type="submit"><?= e(t('admin.notion_oauth_disconnect')) ?></button>
                            </form>
                        <?php else: ?>
                            <p class="muted small"><?= e(t('admin.notion_oauth_hint')) ?></p>
                            <?php if ($notionRedirectUri !== ''): ?><p class="admin-notion-redirect"><span><?= e(t('admin.notion_oauth_redirect')) ?></span><code><?= e($notionRedirectUri) ?></code></p><?php else: ?><p class="muted small"><?= e(t('admin.notion_oauth_need_base_url')) ?></p><?php endif; ?>
                            <?php if ($notionOauthConfigured && $notionRedirectUri !== ''): ?>
                                <form method="post" action="/?page=admin&amp;section=notion"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="notion_oauth_start"><button class="btn btn-primary" type="submit"><?= e(t('admin.notion_oauth_connect')) ?></button></form>
                            <?php endif; ?>
                        <?php endif; ?>
                        <form method="post" action="/?page=admin&amp;section=notion" class="admin-notion-fields">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update_notion_settings">
                            <label><span><?= e(t('admin.notion_oauth_client_id')) ?></span><input type="text" name="notion_oauth_client_id" value="<?= e((string) ($notion['oauth_client_id'] ?? '')) ?>" placeholder="client id"></label>
                            <label><span><?= e(t('admin.notion_oauth_client_secret')) ?></span><input type="password" name="notion_oauth_client_secret" autocomplete="off" placeholder="<?= ($notion['oauth_client_secret'] ?? '') !== '' ? '••••••••' : 'client secret' ?>"></label>
                            <button class="btn btn-ghost small" type="submit"><?= e(t('common.save')) ?></button>
                        </form>
                    </section>

                    <section class="admin-notion-inner-card">
                        <h4><?= e(t('admin.notion_manual_title')) ?></h4>
                        <p class="muted small"><?= e(t('admin.notion_manual_hint')) ?></p>
                        <form method="post" action="/?page=admin&amp;section=notion" class="admin-notion-fields">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update_notion_settings">
                            <label><span><?= e(t('admin.notion_token')) ?></span><input type="password" name="notion_token" autocomplete="off" placeholder="<?= $notionConnected ? '••••••••' : 'secret_...' ?>"><small><?= e(t('admin.notion_token_hint')) ?></small></label>
                            <label><span><?= e(t('admin.notion_database')) ?></span><input type="text" name="notion_database_id" value="<?= e((string) ($notion['database_id'] ?? '')) ?>" placeholder="database id"></label>
                            <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
                        </form>
                    </section>
                </div>
            </details>

            <details class="admin-notion-section">
                <summary>
                    <span class="admin-notion-section-icon" aria-hidden="true"><?= activity_icon_svg('sliders') ?></span>
                    <span><strong><?= e(t('admin.notion_automation_title')) ?></strong><small><?= e(t('admin.notion_automation_hint')) ?></small></span>
                    <span class="badge <?= !empty($notion['enabled']) ? 'badge-ok' : 'badge-warn' ?>"><?= e(!empty($notion['enabled']) ? t('common.active') : t('workout_types.inactive')) ?></span>
                    <span aria-hidden="true">⌄</span>
                </summary>
                <div class="admin-notion-section-body">
                    <form method="post" action="/?page=admin&amp;section=notion" class="admin-notion-automation-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update_notion_settings"><input type="hidden" name="notion_enabled_present" value="1">
                        <label class="admin-notion-enable-row"><span><strong><?= e(t('admin.notion_enabled')) ?></strong><small><?= e(t('admin.notion_automation_enable_hint')) ?></small></span><span class="admin-app-switch"><input type="checkbox" name="notion_enabled" value="1" <?= !empty($notion['enabled']) ? 'checked' : '' ?>><i aria-hidden="true"></i></span></label>
                        <div class="integration-runtime-status is-<?= e($notionWorkerState) ?>"><span aria-hidden="true"></span><div><strong><?= e(t('admin.integration_worker_managed')) ?></strong><small><?= e($integrationStateLabel($notionWorkerState)) ?><?php if (trim((string) ($notionWorker['last_error'] ?? '')) !== ''): ?> · <?= e(mb_substr(trim((string) $notionWorker['last_error']), 0, 180)) ?><?php endif; ?></small></div></div>
                        <div class="admin-notion-automation-grid">
                            <label><span><?= e(t('admin.notion_direction')) ?></span><select name="notion_sync_direction"><option value="push_only" <?= ($notion['direction'] ?? 'push_only') === 'push_only' ? 'selected' : '' ?>><?= e(t('admin.notion_dir_push')) ?></option><option value="two_way" <?= ($notion['direction'] ?? 'push_only') === 'two_way' ? 'selected' : '' ?>><?= e(t('admin.notion_dir_two_way')) ?></option></select><small><?= e(t('admin.notion_direction_hint')) ?></small></label>
                            <label><span><?= e(t('admin.notion_frequency')) ?></span><select name="notion_sync_frequency"><option value="off" <?= ($notion['frequency'] ?? 'off') === 'off' ? 'selected' : '' ?>><?= e(t('admin.notion_freq_off')) ?></option><option value="daily" <?= ($notion['frequency'] ?? 'off') === 'daily' ? 'selected' : '' ?>><?= e(t('admin.notion_freq_daily')) ?></option></select></label>
                            <label><span><?= e(t('admin.notion_run_time')) ?></span><input type="time" name="notion_sync_run_time" value="<?= e((string) ($notion['run_time'] ?? '03:00')) ?>"></label>
                        </div>
                        <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
                    </form>
                </div>
            </details>

            <details class="admin-notion-section">
                <summary><span class="admin-notion-section-icon" aria-hidden="true"><?= activity_icon_svg('plus') ?></span><span><strong><?= e(t('admin.notion_create_title')) ?></strong><small><?= e(t('admin.notion_create_hint')) ?></small></span><span aria-hidden="true">⌄</span></summary>
                <div class="admin-notion-section-body"><form method="post" action="/?page=admin&amp;section=notion" class="admin-notion-create-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="notion_create_database"><label><span><?= e(t('admin.notion_parent_page')) ?></span><input type="text" name="notion_parent_page_id" value="<?= e((string) ($notion['parent_page_id'] ?? '')) ?>" placeholder="parent page id"></label><button class="btn btn-primary" type="submit" <?= $notionConnected ? '' : 'disabled' ?>><?= e(t('admin.notion_create_button')) ?></button></form></div>
            </details>

            <details class="admin-notion-section">
                <summary><span class="admin-notion-section-icon" aria-hidden="true"><?= activity_icon_svg('grid') ?></span><span><strong><?= e(t('admin.notion_mapping_title')) ?></strong><small><?= e(t('admin.notion_mapping_hint')) ?></small></span><?php if ($notionSchemaNames !== []): ?><span class="badge badge-ok"><?= count($notionSchemaNames) ?></span><?php endif; ?><span aria-hidden="true">⌄</span></summary>
                <div class="admin-notion-section-body admin-notion-mapping">
                    <form method="post" action="/?page=admin&amp;section=notion"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="notion_load_schema"><button class="btn btn-ghost small" type="submit" <?= $notionConfigured ? '' : 'disabled' ?>><?= e(t('admin.notion_load_schema')) ?></button><?php if ($notionSchemaNames !== []): ?><span class="muted small"><?= e(t('admin.notion_schema_count', ['count' => count($notionSchemaNames)])) ?></span><?php endif; ?></form>
                    <form method="post" action="/?page=admin&amp;section=notion" class="admin-notion-map-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update_notion_field_map"><datalist id="notion-property-options"><?php foreach ($notionSchemaNames as $propName): ?><option value="<?= e((string) $propName) ?>"><?= e((string) $propName . ' (' . (string) ($notionSchemaCache[$propName] ?? '') . ')') ?></option><?php endforeach; ?></datalist><div class="notion-map-grid"><?php foreach ($notionFieldLabels as $fieldKey => $fieldLabel): ?><label class="notion-map-row"><span><?= e((string) $fieldLabel) ?></span><input type="text" name="notion_map[<?= e((string) $fieldKey) ?>]" list="notion-property-options" value="<?= e((string) ($notionFieldMap[$fieldKey] ?? '')) ?>" placeholder="<?= e(t('admin.notion_map_placeholder')) ?>"></label><?php endforeach; ?></div><button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button></form>
                </div>
            </details>
        </div>

    </article>

    <?php endif; ?>
    <?php if ($activeSection === 'telegram'): ?>
    <article class="panel settings-panel active admin-telegram-page" data-spa-section="telegram">
        <?php
        $telegram = is_array($telegramSettings ?? null) ? (array) $telegramSettings : [];
        $telegramConfigured = ($telegram['token'] ?? '') !== '';
        $telegramEnabled = !empty($telegram['enabled']);
        $telegramEffectiveEnabled = $telegramConfigured && $telegramEnabled;
        $telegramActiveBaseUrl = (string) ($telegram['base_url'] ?? '');
        $telegramManualBaseUrl = (string) ($telegram['configured_base_url'] ?? '');
        if (app_base_url_is_ephemeral($telegramManualBaseUrl)) {
            $telegramManualBaseUrl = '';
        }
        $telegramWorker = (array) ($integrationStatuses['telegram'] ?? []);
        $telegramWorkerState = $integrationState($telegramWorker);
        $telegramWorkerError = mb_substr(trim((string) ($telegramWorker['last_error'] ?? '')), 0, 180);
        $telegramLinkedUsers = is_array($telegramLinkedUsers ?? null) ? (array) $telegramLinkedUsers : [];
        $telegramBotName = trim((string) ($telegram['username'] ?? ''));
        ?>
        <section class="admin-telegram-status" aria-label="<?= e(t('admin.telegram_title')) ?>">
            <div class="admin-telegram-status-head">
                <span class="admin-telegram-logo" aria-hidden="true"><?= activity_icon_svg('bell') ?><i class="is-<?= e($telegramWorkerState) ?>"></i></span>
                <div class="admin-telegram-status-copy">
                    <p class="eyebrow">Telegram</p>
                    <h2><?= $telegramBotName !== '' ? '@' . e($telegramBotName) : e(t('admin.telegram_title')) ?></h2>
                    <div class="admin-telegram-badges">
                        <span class="badge <?= $telegramConfigured ? 'badge-ok' : 'badge-warn' ?>"><?= e($telegramConfigured ? t('admin.telegram_configured') : t('admin.telegram_incomplete')) ?></span>
                        <span class="badge <?= $telegramEffectiveEnabled ? 'badge-ok' : '' ?>"><?= e($telegramEffectiveEnabled ? t('common.active') : t('workout_types.inactive')) ?></span>
                    </div>
                </div>
                <form method="post" action="/?page=admin&amp;section=telegram" class="admin-telegram-verify-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="telegram_verify_bot">
                    <button class="btn btn-ghost small" type="submit" <?= $telegramConfigured ? '' : 'disabled' ?>><?= e(t('admin.telegram_verify')) ?></button>
                </form>
            </div>
            <div class="admin-telegram-metrics">
                <span><strong><?= count($telegramLinkedUsers) ?></strong><small><?= e(t('admin.telegram_linked_title')) ?></small></span>
                <span><strong><?= e($integrationStateLabel($telegramWorkerState)) ?></strong><small><?= e(t('admin.integration_worker_managed')) ?></small></span>
                <span><strong><?= $telegramActiveBaseUrl !== '' ? e(t('admin.telegram_url_ready')) : e(t('admin.telegram_url_missing')) ?></strong><small><?= e(t('admin.telegram_base_url')) ?></small></span>
            </div>
        </section>

        <div class="admin-telegram-sections">
            <details class="admin-telegram-section" <?= !$telegramConfigured ? 'open' : '' ?>>
                <summary>
                    <span class="admin-telegram-section-icon" aria-hidden="true"><?= activity_icon_svg('link') ?></span>
                    <span><strong><?= e(t('admin.telegram_connection_title')) ?></strong><small><?= e(t('admin.telegram_connection_hint')) ?></small></span>
                    <span class="badge <?= $telegramConfigured ? 'badge-ok' : 'badge-warn' ?>"><?= e($telegramConfigured ? t('admin.telegram_configured') : t('admin.telegram_incomplete')) ?></span>
                    <span aria-hidden="true">⌄</span>
                </summary>
                <div class="admin-telegram-section-body">
                    <form method="post" action="/?page=admin&amp;section=telegram" class="admin-telegram-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="update_telegram_settings">
                        <label><span><?= e(t('admin.telegram_token')) ?></span><input type="password" name="telegram_bot_token" autocomplete="new-password" placeholder="<?= $telegramConfigured ? '••••••••' : '123456:ABC...' ?>"><small><?= e($telegramConfigured ? t('admin.telegram_token_saved') : t('admin.telegram_token_hint')) ?></small></label>
                        <label><span><?= e(t('admin.telegram_username')) ?></span><input type="text" name="telegram_bot_username" value="<?= e($telegramBotName) ?>" autocomplete="off" autocapitalize="none" spellcheck="false" placeholder="my_fitness_bot"></label>
                        <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
                    </form>
                </div>
            </details>

            <details class="admin-telegram-section">
                <summary>
                    <span class="admin-telegram-section-icon" aria-hidden="true"><?= activity_icon_svg('link') ?></span>
                    <span><strong><?= e(t('admin.telegram_url_section_title')) ?></strong><small><?= e(t('admin.telegram_url_section_hint')) ?></small></span>
                    <?php if ($telegramActiveBaseUrl !== ''): ?><span class="badge badge-ok"><?= e(t('admin.telegram_url_ready')) ?></span><?php endif; ?>
                    <span aria-hidden="true">⌄</span>
                </summary>
                <div class="admin-telegram-section-body">
                    <form method="post" action="/?page=admin&amp;section=telegram" class="admin-telegram-form admin-telegram-url-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="update_telegram_settings">
                        <label><span><?= e(t('admin.telegram_base_url')) ?></span><input type="url" name="app_base_url" value="<?= e($telegramManualBaseUrl) ?>" inputmode="url" autocapitalize="none" spellcheck="false" placeholder="<?= e(t('admin.telegram_base_url_auto')) ?>"><small><?= e(t('admin.telegram_base_url_hint')) ?></small></label>
                        <?php if ($telegramActiveBaseUrl !== ''): ?><p class="admin-telegram-active-url"><span><?= e(t('admin.telegram_base_url_active')) ?></span><code><?= e($telegramActiveBaseUrl) ?></code></p><?php endif; ?>
                        <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
                    </form>
                </div>
            </details>

            <details class="admin-telegram-section">
                <summary>
                    <span class="admin-telegram-section-icon" aria-hidden="true"><?= activity_icon_svg('sliders') ?></span>
                    <span><strong><?= e(t('admin.telegram_automation_title')) ?></strong><small><?= e(t('admin.telegram_automation_hint')) ?></small></span>
                    <span class="badge <?= $telegramWorkerState === 'active' ? 'badge-ok' : ($telegramWorkerState === 'error' ? 'badge-warn' : '') ?>"><?= e($integrationStateLabel($telegramWorkerState)) ?></span>
                    <span aria-hidden="true">⌄</span>
                </summary>
                <div class="admin-telegram-section-body">
                    <form method="post" action="/?page=admin&amp;section=telegram" class="admin-telegram-automation-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="update_telegram_settings">
                        <input type="hidden" name="telegram_enabled_present" value="1">
                        <label class="admin-telegram-enable-row"><span><strong><?= e(t('admin.telegram_enabled')) ?></strong><small><?= e(t('admin.telegram_enable_hint')) ?></small></span><span class="admin-app-switch"><input type="checkbox" name="telegram_enabled" value="1" <?= $telegramEnabled ? 'checked' : '' ?>><i aria-hidden="true"></i></span></label>
                        <div class="integration-runtime-status is-<?= e($telegramWorkerState) ?>"><span aria-hidden="true"></span><div><strong><?= e(t('admin.integration_worker_managed')) ?></strong><small><?= e($integrationStateLabel($telegramWorkerState)) ?><?php if ($telegramWorkerError !== ''): ?> · <?= e($telegramWorkerError) ?><?php endif; ?></small></div></div>
                        <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
                    </form>
                </div>
            </details>

            <details class="admin-telegram-section">
                <summary>
                    <span class="admin-telegram-section-icon" aria-hidden="true"><?= activity_icon_svg('users') ?></span>
                    <span><strong><?= e(t('admin.telegram_linked_title')) ?></strong><small><?= e(t('admin.telegram_linked_hint')) ?></small></span>
                    <span class="badge"><?= count($telegramLinkedUsers) ?></span>
                    <span aria-hidden="true">⌄</span>
                </summary>
                <div class="admin-telegram-section-body admin-telegram-linked">
                    <?php if ($telegramLinkedUsers === []): ?>
                        <p class="admin-telegram-empty"><?= e(t('admin.telegram_no_linked')) ?></p>
                    <?php else: ?>
                        <ul class="admin-telegram-user-list">
                            <?php foreach ($telegramLinkedUsers as $linkedUser): ?>
                                <?php $linkedAvatar = avatar_url((array) $linkedUser); $linkedName = (string) ($linkedUser['display_name'] ?? $linkedUser['username'] ?? ''); ?>
                                <li class="admin-telegram-user-row">
                                    <?php if ($linkedAvatar !== ''): ?><img class="admin-telegram-user-avatar" src="<?= e($linkedAvatar) ?>" alt="" loading="lazy"><?php else: ?><span class="admin-telegram-user-avatar is-initials" aria-hidden="true"><?= e(initials_for($linkedName)) ?></span><?php endif; ?>
                                    <span class="admin-telegram-user-copy"><strong><?= e($linkedName) ?></strong><small>@<?= e((string) ($linkedUser['username'] ?? '')) ?> · <?= e((string) ($linkedUser['telegram_reminder_time'] ?? '')) ?></small><span class="admin-telegram-user-prefs"><?php if ((int) ($linkedUser['telegram_reminders_enabled'] ?? 0) === 1): ?><i><?= e(t('admin.telegram_reminders_on')) ?></i><?php endif; ?><?php if ((int) ($linkedUser['telegram_motivation_enabled'] ?? 0) === 1): ?><i><?= e(t('admin.telegram_motivation_on')) ?></i><?php endif; ?><?php if ((int) ($linkedUser['telegram_reminders_enabled'] ?? 0) !== 1 && (int) ($linkedUser['telegram_motivation_enabled'] ?? 0) !== 1): ?><i><?= e(t('admin.telegram_no_preferences')) ?></i><?php endif; ?></span></span>
                                    <form method="post" action="/?page=admin&amp;section=telegram" class="inline-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="telegram_admin_unlink"><input type="hidden" name="user_id" value="<?= (int) ($linkedUser['id'] ?? 0) ?>"><button class="btn btn-ghost small" type="submit"><?= e(t('admin.telegram_unlink_user')) ?></button></form>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </details>
        </div>
    </article>

    <?php endif; ?>
    <?php if ($activeSection === 'appearance'): ?>
    <article class="panel settings-panel active" data-spa-section="appearance">
        <div class="panel-head">
            <div>
                <h2><?= e(t('admin.appearance_settings')) ?></h2>
                <p class="muted admin-section-help"><?= e(t('admin.appearance_settings_hint')) ?></p>
            </div>
            <?php $renderAdminBack('/?page=admin', t('nav.admin')); ?>
        </div>
        <section class="admin-subsection">
        <h3><?= e(t('admin.app_icon')) ?></h3>
        <p class="muted small"><?= e(t('admin.app_icon_help')) ?></p>
        <form method="post" action="/?page=admin" enctype="multipart/form-data" class="stack" data-image-cropper-form>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="upload_app_icon">
            <input type="hidden" name="app_icon_cropped" value="" data-image-crop-output>
            <?php if (!empty($appIconPath)): ?><img class="settings-avatar-preview" src="<?= e(with_cache_buster('/?page=app_icon', $appIconVersion ?? null)) ?>" alt="<?= e(t('admin.app_icon')) ?>"><?php endif; ?>
            <div class="image-cropper" data-image-cropper>
                <canvas width="320" height="320" data-image-crop-canvas></canvas>
                <p class="muted small" data-image-crop-empty><?= e(t('admin.image_crop_hint')) ?></p>
                <label>
                    <?= e(t('common.zoom')) ?>
                    <input type="range" min="1" max="3" step="0.01" value="1" data-image-crop-zoom>
                </label>
            </div>
            <label><?= e(t('common.photo')) ?><input type="file" name="app_icon" accept="image/*" required data-image-crop-input></label>
            <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
        </form>
        </section>

        <section class="stack compact-form admin-login-background admin-subsection">
            <div class="panel-head">
                <div>
                    <h3><?= e(t('admin.login_background_title')) ?></h3>
                    <p class="muted"><?= e(t('admin.login_background_subtitle')) ?></p>
                </div>
            </div>

            <form method="post" action="/?page=admin" enctype="multipart/form-data" class="stack compact-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="upload_login_background">
                <label>
                    <?= e(t('admin.login_background_upload')) ?>
                    <input type="file" name="login_background" accept="image/*" required>
                </label>
                <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
            </form>

            <?php if ($activeLoginBackgroundUrl !== ''): ?>
                <figure class="admin-login-bg-active">
                    <img src="<?= e($activeLoginBackgroundUrl) ?>" alt="<?= e(t('admin.login_background_active')) ?>">
                    <figcaption><?= e(t('admin.login_background_active')) ?></figcaption>
                </figure>
            <?php else: ?>
                <p class="muted"><?= e(t('admin.login_background_none')) ?></p>
            <?php endif; ?>

            <?php if (($loginBackgroundLibrary ?? []) === []): ?>
                <p class="muted"><?= e(t('admin.login_background_empty')) ?></p>
            <?php else: ?>
                <div class="admin-login-bg-library">
                    <?php foreach ((array) $loginBackgroundLibrary as $background): ?>
                        <?php
                        $backgroundPath = trim((string) ($background['path'] ?? ''));
                        if ($backgroundPath === '') {
                            continue;
                        }
                        $backgroundUrl = media_url($backgroundPath);
                        if ($backgroundUrl === '') {
                            continue;
                        }
                        $isActiveBackground = $activeLoginBackgroundPath !== '' && $activeLoginBackgroundPath === $backgroundPath;
                        ?>
                        <form method="post" action="/?page=admin" class="admin-login-bg-item<?= $isActiveBackground ? ' is-active' : '' ?>">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="set_login_background">
                            <input type="hidden" name="login_background_path" value="<?= e($backgroundPath) ?>">
                            <img src="<?= e($backgroundUrl) ?>" alt="<?= e((string) ($background['name'] ?? '')) ?>">
                            <div class="admin-login-bg-item-meta">
                                <strong><?= e((string) ($background['name'] ?? '')) ?></strong>
                                <button class="btn btn-ghost small" type="submit"><?= e($isActiveBackground ? t('common.active') : t('admin.login_background_select')) ?></button>
                            </div>
                        </form>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post" action="/?page=admin">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="clear_login_background">
                <button class="btn btn-ghost small" type="submit"><?= e(t('admin.login_background_clear')) ?></button>
            </form>
        </section>

        <section class="stack compact-form admin-login-style admin-subsection">
            <div class="panel-head">
                <div>
                    <h3><?= e(t('admin.login_style_title')) ?></h3>
                    <p class="muted"><?= e(t('admin.login_style_subtitle')) ?></p>
                </div>
            </div>

            <?php
            $currentLoginStyle = login_style_normalize($loginStyle ?? 'split');
            $loginStyleChoices = [
                'split' => ['label' => t('admin.login_style_split'), 'hint' => t('admin.login_style_split_hint')],
                'centered' => ['label' => t('admin.login_style_centered'), 'hint' => t('admin.login_style_centered_hint')],
                'spotlight' => ['label' => t('admin.login_style_spotlight'), 'hint' => t('admin.login_style_spotlight_hint')],
            ];
            ?>
            <div class="admin-login-style-grid">
                <?php foreach ($loginStyleChoices as $styleKey => $styleMeta): ?>
                    <?php $isActiveStyle = $currentLoginStyle === $styleKey; ?>
                    <form method="post" action="/?page=admin" class="admin-login-style-item<?= $isActiveStyle ? ' is-active' : '' ?>">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="set_login_style">
                        <input type="hidden" name="login_style" value="<?= e($styleKey) ?>">
                        <span class="admin-login-style-preview admin-login-style-preview-<?= e($styleKey) ?>" aria-hidden="true">
                            <span class="admin-login-style-preview-panel"></span>
                            <span class="admin-login-style-preview-card"></span>
                        </span>
                        <span class="admin-login-style-meta">
                            <strong><?= e($styleMeta['label']) ?></strong>
                            <span class="muted"><?= e($styleMeta['hint']) ?></span>
                        </span>
                        <button class="btn <?= $isActiveStyle ? 'btn-primary' : 'btn-ghost' ?> small" type="submit"<?= $isActiveStyle ? ' aria-current="true"' : '' ?>>
                            <?= e($isActiveStyle ? t('common.active') : t('admin.login_style_apply')) ?>
                        </button>
                    </form>
                <?php endforeach; ?>
            </div>
        </section>
    </article>

    <?php endif; ?>
    <?php if ($activeSection === 'backups'): ?>
        <?php require __DIR__ . '/partials/admin_backups.php'; ?>

    <?php endif; ?>
    <?php if ($activeSection === 'habits'): ?>
    <?php
    $habitRows = is_array($habits ?? null) ? (array) $habits : [];
    $habitTranslationRows = is_array($habitTranslations ?? null) ? (array) $habitTranslations : [];
    $habitLocaleOptions = locale_options();
    $habitActiveCount = count(array_filter($habitRows, static fn(array $habit): bool => (int) ($habit['active'] ?? 0) === 1));
    $habitCompleteTranslationCount = 0;
    foreach ($habitRows as $habitRow) {
        $translatedRows = (array) ($habitTranslationRows[(int) ($habitRow['id'] ?? 0)] ?? []);
        $translatedCount = count(array_filter($habitLocaleOptions, static fn(string $localeName, string $locale) => trim((string) ($translatedRows[$locale]['label'] ?? '')) !== '', ARRAY_FILTER_USE_BOTH));
        if ($translatedCount === count($habitLocaleOptions)) {
            $habitCompleteTranslationCount++;
        }
    }
    $habitEditors = array_merge([['id' => 0, 'code' => '', 'base_label' => '', 'label' => '', 'active' => 1, 'sort_order' => 50]], $habitRows);
    ?>
    <article class="panel settings-panel active admin-habits-page" data-spa-section="habits">
        <div class="admin-habits-list-view" data-spa-show-when-no-param="habit_id" <?= $selectedHabitId !== '' ? 'hidden' : '' ?>>
            <section class="admin-habits-overview">
                <div class="admin-habits-overview-head"><span aria-hidden="true"><?= activity_icon_svg('check') ?></span><div><p class="eyebrow"><?= e(t('admin.habits')) ?></p><h2><?= e(t('admin.habits_library_title')) ?></h2><p><?= e(t('admin.habits_help')) ?></p></div><a class="btn btn-primary small" href="/?page=admin&amp;section=habits&amp;habit_id=new" data-spa-link><span aria-hidden="true">+</span><?= e(t('admin.create_habit')) ?></a></div>
                <div class="admin-habits-stats"><span><strong><?= count($habitRows) ?></strong><small><?= e(t('admin.habits_total')) ?></small></span><span class="is-active"><strong><?= $habitActiveCount ?></strong><small><?= e(t('admin.habits_active')) ?></small></span><span><strong><?= count($habitRows) - $habitActiveCount ?></strong><small><?= e(t('admin.habits_paused')) ?></small></span><span><strong><?= $habitCompleteTranslationCount ?></strong><small><?= e(t('admin.habits_translated')) ?></small></span></div>
            </section>

            <section class="admin-habits-library">
                <div class="admin-habits-library-head"><div><h3><?= e(t('admin.habit_definitions')) ?></h3><p><?= e(t('admin.habits_list_hint')) ?></p></div><span><?= count($habitRows) ?></span></div>
                <div class="admin-habits-list">
                    <?php foreach ($habitRows as $habit): ?>
                        <?php $habitId = (int) $habit['id']; $habitTranslationsForRow = (array) ($habitTranslationRows[$habitId] ?? []); ?>
                        <a class="admin-habit-row" href="/?page=admin&amp;section=habits&amp;habit_id=<?= $habitId ?>" data-spa-link data-status="<?= (int) $habit['active'] === 1 ? 'active' : 'inactive' ?>">
                            <span class="admin-habit-row-icon" aria-hidden="true"><?= activity_icon_svg('check') ?></span>
                            <span class="admin-habit-row-copy"><strong><?= e((string) $habit['label']) ?></strong><small><?= e((string) $habit['code']) ?> · <?= e(t('admin.habits_order_short', ['order' => (int) $habit['sort_order']])) ?></small></span>
                            <span class="admin-habit-language-progress" aria-label="<?= e(t('admin.habits_translation_status')) ?>"><?php foreach ($habitLocaleOptions as $locale => $localeName): ?><i class="<?= trim((string) ($habitTranslationsForRow[$locale]['label'] ?? '')) !== '' ? 'is-complete' : '' ?>" title="<?= e($localeName) ?>"><?= e(strtoupper($locale)) ?></i><?php endforeach; ?></span>
                            <span class="admin-habit-row-state"><i aria-hidden="true"></i><?= e((int) $habit['active'] === 1 ? t('admin.habits_active_single') : t('admin.habits_paused_single')) ?></span>
                            <span aria-hidden="true">›</span>
                        </a>
                    <?php endforeach; ?>
                    <?php if ($habitRows === []): ?><div class="admin-habits-empty"><span aria-hidden="true"><?= activity_icon_svg('check') ?></span><strong><?= e(t('admin.habits_empty_title')) ?></strong><p><?= e(t('admin.habits_empty_hint')) ?></p><a class="btn btn-primary" href="/?page=admin&amp;section=habits&amp;habit_id=new" data-spa-link><?= e(t('admin.create_habit')) ?></a></div><?php endif; ?>
                </div>
            </section>
        </div>

        <?php foreach ($habitEditors as $habit): ?>
            <?php
            $habitId = (int) ($habit['id'] ?? 0);
            $habitIsNew = $habitId === 0;
            $habitEditorKey = $habitIsNew ? 'new' : (string) $habitId;
            $habitTranslationsForEditor = (array) ($habitTranslationRows[$habitId] ?? []);
            if (!$habitIsNew && trim((string) ($habitTranslationsForEditor['en']['label'] ?? '')) === '') {
                $habitTranslationsForEditor['en']['label'] = (string) ($habit['base_label'] ?? $habit['label'] ?? '');
            }
            ?>
            <div class="admin-habit-editor-view" data-spa-param-show="habit_id" data-spa-value="<?= e($habitEditorKey) ?>" <?= $selectedHabitId === $habitEditorKey ? '' : 'hidden' ?>>
                <div class="admin-habit-editor-head"><?php $renderAdminBack('/?page=admin&section=habits', t('admin.section_habits')); ?><div><p class="eyebrow"><?= e($habitIsNew ? t('admin.habits_new_eyebrow') : t('admin.habits_edit_eyebrow')) ?></p><h2><?= e($habitIsNew ? t('admin.create_habit') : (string) $habit['label']) ?></h2><p><?= e(t('admin.habits_editor_hint')) ?></p></div></div>
                <form method="post" action="/?page=admin&amp;section=habits" class="admin-habit-editor-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_habit"><?php if (!$habitIsNew): ?><input type="hidden" name="habit_id" value="<?= $habitId ?>"><?php endif; ?>
                    <section class="admin-habit-form-section"><div class="admin-habit-form-section-head"><span aria-hidden="true"><?= activity_icon_svg('sliders') ?></span><div><h3><?= e(t('admin.habits_identity_title')) ?></h3><p><?= e(t('admin.habits_identity_hint')) ?></p></div></div><div class="admin-habit-identity-grid"><label><span><?= e(t('admin.habits_code')) ?></span><input type="text" name="code" value="<?= e((string) $habit['code']) ?>" pattern="[a-z0-9_]+" maxlength="60" autocapitalize="none" spellcheck="false" placeholder="drink_water" <?= $habitIsNew ? 'required' : 'readonly' ?>><small><?= e($habitIsNew ? t('admin.habits_code_hint') : t('admin.habits_code_locked')) ?></small></label><label><span><?= e(t('admin.habits_sort_order')) ?></span><input type="number" name="sort_order" value="<?= (int) $habit['sort_order'] ?>" min="0" max="9999" inputmode="numeric"></label><label class="admin-habit-active-row"><span><strong><?= e(t('admin.habits_show_label')) ?></strong><small><?= e(t('admin.habits_show_hint')) ?></small></span><span class="admin-app-switch"><input type="checkbox" name="active" value="1" <?= (int) $habit['active'] === 1 ? 'checked' : '' ?>><i aria-hidden="true"></i></span></label></div></section>
                    <section class="admin-habit-form-section"><div class="admin-habit-form-section-head"><span aria-hidden="true"><?= activity_icon_svg('grid') ?></span><div><h3><?= e(t('admin.habits_names_title')) ?></h3><p><?= e(t('admin.habits_names_hint')) ?></p></div></div><div class="admin-habit-translation-grid"><?php foreach ($habitLocaleOptions as $locale => $localeName): ?><label class="admin-habit-translation-card"><span><i><?= e(strtoupper($locale)) ?></i><strong><?= e($localeName) ?></strong><?php if ($locale === 'en'): ?><small><?= e(t('admin.habits_fallback_badge')) ?></small><?php endif; ?></span><input type="text" name="translations[<?= e($locale) ?>][label]" value="<?= e((string) ($habitTranslationsForEditor[$locale]['label'] ?? '')) ?>" maxlength="100" placeholder="<?= e(t('admin.habits_name_placeholder')) ?>" <?= $locale === 'en' ? 'required' : '' ?>></label><?php endforeach; ?></div></section>
                    <div class="admin-habit-form-actions"><a class="btn btn-ghost" href="/?page=admin&amp;section=habits" data-spa-link><?= e(t('common.cancel')) ?></a><button class="btn btn-primary" type="submit"><?= e($habitIsNew ? t('admin.habits_create_action') : t('common.save')) ?></button></div>
                </form>
            </div>
        <?php endforeach; ?>
    </article>

    <?php endif; ?>
    <?php if ($activeSection === 'workout_types'): ?>
    <?php
    $workoutTypeRows = is_array($workoutTypes ?? null) ? (array) $workoutTypes : [];
    $workoutTypeTranslationRows = is_array($workoutTypeTranslations ?? null) ? (array) $workoutTypeTranslations : [];
    $workoutFieldTranslationRows = is_array($workoutFieldTranslations ?? null) ? (array) $workoutFieldTranslations : [];
    $workoutLocaleOptions = locale_options();
    $workoutTypeActiveCount = count(array_filter($workoutTypeRows, static fn(array $type): bool => (int) ($type['active'] ?? 0) === 1));
    $workoutFieldTotal = array_sum(array_map('count', $workoutTypeFieldsByType));
    $workoutTypeCompleteTranslations = 0;
    foreach ($workoutTypeRows as $workoutTypeRow) {
        $translationRows = (array) ($workoutTypeTranslationRows[(int) ($workoutTypeRow['id'] ?? 0)] ?? []);
        $complete = true;
        foreach (array_keys($workoutLocaleOptions) as $locale) {
            if (trim((string) ($translationRows[$locale]['name'] ?? '')) === '') { $complete = false; break; }
        }
        if ($complete) { $workoutTypeCompleteTranslations++; }
    }
    $workoutTypeEditors = array_merge([['id' => 0, 'name' => '', 'base_name' => '', 'active' => 1]], $workoutTypeRows);
    ?>
    <article class="panel settings-panel active admin-workout-types-page" data-spa-section="workout_types">
        <div class="admin-workout-types-list-view" data-spa-show-when-no-param="type_id" <?= $selectedTypeId !== '' ? 'hidden' : '' ?>>
            <section class="admin-workout-types-overview"><div class="admin-workout-types-overview-head"><span aria-hidden="true"><?= activity_icon_svg('dumbbell') ?></span><div><p class="eyebrow"><?= e(t('admin.section_workout_types')) ?></p><h2><?= e(t('admin.workout_types_library_title')) ?></h2><p><?= e(t('admin.workout_types_help')) ?></p></div><a class="btn btn-primary small" href="/?page=admin&amp;section=workout_types&amp;type_id=new" data-spa-link><span aria-hidden="true">+</span><?= e(t('admin.create_workout_type')) ?></a></div><div class="admin-workout-types-stats"><span><strong><?= count($workoutTypeRows) ?></strong><small><?= e(t('admin.workout_types_total')) ?></small></span><span class="is-active"><strong><?= $workoutTypeActiveCount ?></strong><small><?= e(t('admin.workout_types_active')) ?></small></span><span><strong><?= $workoutFieldTotal ?></strong><small><?= e(t('admin.workout_types_fields')) ?></small></span><span><strong><?= $workoutTypeCompleteTranslations ?></strong><small><?= e(t('admin.workout_types_translated')) ?></small></span></div></section>
            <section class="admin-workout-types-library"><div class="admin-workout-types-library-head"><div><h3><?= e(t('admin.section_workout_types')) ?></h3><p><?= e(t('admin.workout_types_list_hint')) ?></p></div><span><?= count($workoutTypeRows) ?></span></div><div class="admin-workout-types-list">
                <?php foreach ($workoutTypeRows as $type): ?><?php $typeId = (int) $type['id']; $typeTranslationsForRow = (array) ($workoutTypeTranslationRows[$typeId] ?? []); $typeFields = (array) ($workoutTypeFieldsByType[$typeId] ?? []); ?>
                    <a class="admin-workout-type-row" href="/?page=admin&amp;section=workout_types&amp;type_id=<?= $typeId ?>" data-spa-link data-status="<?= (int) $type['active'] === 1 ? 'active' : 'inactive' ?>"><span class="admin-workout-type-row-icon" aria-hidden="true"><?= activity_icon_svg('dumbbell') ?></span><span class="admin-workout-type-row-copy"><strong><?= e((string) $type['name']) ?></strong><small><?= e(t('admin.workout_types_field_count', ['count' => count($typeFields)])) ?></small></span><span class="admin-workout-type-languages"><?php foreach ($workoutLocaleOptions as $locale => $localeName): ?><i class="<?= trim((string) ($typeTranslationsForRow[$locale]['name'] ?? '')) !== '' ? 'is-complete' : '' ?>" title="<?= e($localeName) ?>"><?= e(strtoupper($locale)) ?></i><?php endforeach; ?></span><span class="admin-workout-type-state"><i aria-hidden="true"></i><?= e((int) $type['active'] === 1 ? t('admin.workout_types_active_single') : t('admin.workout_types_paused_single')) ?></span><span aria-hidden="true">›</span></a>
                <?php endforeach; ?>
                <?php if ($workoutTypeRows === []): ?><div class="admin-workout-types-empty"><span aria-hidden="true"><?= activity_icon_svg('dumbbell') ?></span><strong><?= e(t('admin.workout_types_empty_title')) ?></strong><p><?= e(t('admin.workout_types_empty_hint')) ?></p><a class="btn btn-primary" href="/?page=admin&amp;section=workout_types&amp;type_id=new" data-spa-link><?= e(t('admin.create_workout_type')) ?></a></div><?php endif; ?>
            </div></section>
        </div>

        <?php foreach ($workoutTypeEditors as $type): ?><?php
            $typeId = (int) ($type['id'] ?? 0); $typeIsNew = $typeId === 0; $typeEditorKey = $typeIsNew ? 'new' : (string) $typeId;
            $typeTranslationsForEditor = (array) ($workoutTypeTranslationRows[$typeId] ?? []);
            if (!$typeIsNew && trim((string) ($typeTranslationsForEditor['en']['name'] ?? '')) === '') { $typeTranslationsForEditor['en']['name'] = (string) ($type['base_name'] ?? $type['name'] ?? ''); }
            $typeFields = (array) ($workoutTypeFieldsByType[$typeId] ?? []);
        ?>
        <div class="admin-workout-type-editor" data-spa-param-show="type_id" data-spa-value="<?= e($typeEditorKey) ?>" <?= $selectedTypeId === $typeEditorKey ? '' : 'hidden' ?>>
            <div class="admin-workout-type-editor-head"><?php $renderAdminBack('/?page=admin&section=workout_types', t('admin.section_workout_types')); ?><div><p class="eyebrow"><?= e($typeIsNew ? t('admin.workout_types_new_eyebrow') : t('admin.workout_types_edit_eyebrow')) ?></p><h2><?= e($typeIsNew ? t('admin.create_workout_type') : (string) $type['name']) ?></h2><p><?= e(t('admin.workout_types_editor_hint')) ?></p></div></div>
            <form method="post" action="/?page=admin&amp;section=workout_types" class="admin-workout-type-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="<?= $typeIsNew ? 'create_workout_type' : 'update_workout_type' ?>"><?php if (!$typeIsNew): ?><input type="hidden" name="type_id" value="<?= $typeId ?>"><?php endif; ?>
                <section class="admin-workout-type-section"><div class="admin-workout-type-section-head"><span aria-hidden="true"><?= activity_icon_svg('grid') ?></span><div><h3><?= e(t('admin.workout_types_names_title')) ?></h3><p><?= e(t('admin.workout_types_names_hint')) ?></p></div></div><div class="admin-workout-type-translation-grid"><?php foreach ($workoutLocaleOptions as $locale => $localeName): ?><label class="admin-workout-translation-card"><span><i><?= e(strtoupper($locale)) ?></i><strong><?= e($localeName) ?></strong><?php if ($locale === 'en'): ?><small><?= e(t('admin.workout_types_fallback_badge')) ?></small><?php endif; ?></span><input type="text" name="translations[<?= e($locale) ?>][name]" value="<?= e((string) ($typeTranslationsForEditor[$locale]['name'] ?? '')) ?>" maxlength="120" placeholder="<?= e(t('admin.workout_types_name_placeholder')) ?>" <?= $locale === 'en' ? 'required' : '' ?>></label><?php endforeach; ?></div><?php if (!$typeIsNew): ?><label class="admin-workout-type-active-row"><span><strong><?= e(t('admin.workout_types_show_label')) ?></strong><small><?= e(t('admin.workout_types_show_hint')) ?></small></span><span class="admin-app-switch"><input type="checkbox" name="active" value="1" <?= (int) $type['active'] === 1 ? 'checked' : '' ?>><i aria-hidden="true"></i></span></label><?php endif; ?></section>
                <div class="admin-workout-type-form-actions"><a class="btn btn-ghost" href="/?page=admin&amp;section=workout_types" data-spa-link><?= e(t('common.cancel')) ?></a><button class="btn btn-primary" type="submit"><?= e($typeIsNew ? t('admin.workout_types_create_action') : t('common.save')) ?></button></div>
            </form>

            <?php if (!$typeIsNew): ?><section class="admin-workout-fields-section"><div class="admin-workout-fields-head"><div><p class="eyebrow"><?= e(t('workout_fields.title')) ?></p><h3><?= e(t('admin.workout_fields_config_title')) ?></h3><p><?= e(t('workout_fields.help')) ?></p></div><span><?= count($typeFields) ?></span></div><div class="admin-workout-fields-list">
                <?php foreach ($typeFields as $field): ?><?php $fieldId = (int) $field['id']; $fieldTranslationsForEditor = (array) ($workoutFieldTranslationRows[$fieldId] ?? []); if (trim((string) ($fieldTranslationsForEditor['en']['label'] ?? '')) === '') { $fieldTranslationsForEditor['en']['label'] = (string) ($field['base_label'] ?? $field['label'] ?? ''); } ?>
                    <details class="admin-workout-field-item"><summary><span aria-hidden="true"><?= activity_icon_svg('sliders') ?></span><span><strong><?= e((string) $field['label']) ?></strong><small><?= e(t('workout_fields.kind_' . normalize_workout_field_input_kind($field['input_kind'] ?? 'number'))) ?> · <?= e((string) ($workoutFieldDataKeyLabels[normalize_workout_field_data_key($field['data_key'] ?? '')] ?? t('workout_fields.data_informational'))) ?></small></span><?php if ((int) ($field['required'] ?? 0) === 1): ?><i><?= e(t('workout_fields.required')) ?></i><?php endif; ?><b class="is-<?= (int) ($field['active'] ?? 1) === 1 ? 'active' : 'inactive' ?>"><?= e((int) ($field['active'] ?? 1) === 1 ? t('common.active') : t('workout_types.inactive')) ?></b><span aria-hidden="true">⌄</span></summary><form method="post" action="/?page=admin&amp;section=workout_types&amp;type_id=<?= $typeId ?>" class="admin-workout-field-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_workout_type_field"><input type="hidden" name="type_id" value="<?= $typeId ?>"><input type="hidden" name="field_id" value="<?= $fieldId ?>"><div class="admin-workout-field-translation-grid"><?php foreach ($workoutLocaleOptions as $locale => $localeName): ?><label class="admin-workout-translation-card"><span><i><?= e(strtoupper($locale)) ?></i><strong><?= e($localeName) ?></strong></span><input type="text" name="translations[<?= e($locale) ?>][label]" value="<?= e((string) ($fieldTranslationsForEditor[$locale]['label'] ?? '')) ?>" maxlength="100" <?= $locale === 'en' ? 'required' : '' ?>></label><?php endforeach; ?></div><div class="admin-workout-field-settings"><label><span><?= e(t('workout_fields.input_kind')) ?></span><select name="input_kind"><option value="number" <?= normalize_workout_field_input_kind($field['input_kind'] ?? '') === 'number' ? 'selected' : '' ?>><?= e(t('workout_fields.kind_number')) ?></option><option value="text" <?= normalize_workout_field_input_kind($field['input_kind'] ?? '') === 'text' ? 'selected' : '' ?>><?= e(t('workout_fields.kind_text')) ?></option></select></label><label><span><?= e(t('workout_fields.data_key')) ?></span><select name="data_key"><?php foreach ($workoutFieldDataKeyLabels as $fieldKey => $fieldLabel): ?><option value="<?= e((string) $fieldKey) ?>" <?= normalize_workout_field_data_key($field['data_key'] ?? '') === (string) $fieldKey ? 'selected' : '' ?>><?= e((string) $fieldLabel) ?></option><?php endforeach; ?></select></label><label><span><?= e(t('common.order')) ?></span><input type="number" name="sort_order" value="<?= (int) ($field['sort_order'] ?? 0) ?>" min="0" max="9999"></label><label class="admin-workout-field-toggle"><span><strong><?= e(t('workout_fields.required')) ?></strong></span><span class="admin-app-switch"><input type="checkbox" name="required" value="1" <?= (int) ($field['required'] ?? 0) === 1 ? 'checked' : '' ?>><i aria-hidden="true"></i></span></label><label class="admin-workout-field-toggle"><span><strong><?= e(t('common.active')) ?></strong></span><span class="admin-app-switch"><input type="checkbox" name="active" value="1" <?= (int) ($field['active'] ?? 1) === 1 ? 'checked' : '' ?>><i aria-hidden="true"></i></span></label></div><button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button></form></details>
                <?php endforeach; ?>
                <details class="admin-workout-field-item admin-workout-field-create"><summary><span aria-hidden="true"><?= activity_icon_svg('plus') ?></span><span><strong><?= e(t('workout_fields.add')) ?></strong><small><?= e(t('admin.workout_fields_add_hint')) ?></small></span><span aria-hidden="true">⌄</span></summary><form method="post" action="/?page=admin&amp;section=workout_types&amp;type_id=<?= $typeId ?>" class="admin-workout-field-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_workout_type_field"><input type="hidden" name="type_id" value="<?= $typeId ?>"><input type="hidden" name="active" value="1"><div class="admin-workout-field-translation-grid"><?php foreach ($workoutLocaleOptions as $locale => $localeName): ?><label class="admin-workout-translation-card"><span><i><?= e(strtoupper($locale)) ?></i><strong><?= e($localeName) ?></strong></span><input type="text" name="translations[<?= e($locale) ?>][label]" maxlength="100" placeholder="<?= e(t('workout_fields.label_placeholder')) ?>" <?= $locale === 'en' ? 'required' : '' ?>></label><?php endforeach; ?></div><div class="admin-workout-field-settings"><label><span><?= e(t('workout_fields.input_kind')) ?></span><select name="input_kind"><option value="number"><?= e(t('workout_fields.kind_number')) ?></option><option value="text"><?= e(t('workout_fields.kind_text')) ?></option></select></label><label><span><?= e(t('workout_fields.data_key')) ?></span><select name="data_key"><?php foreach ($workoutFieldDataKeyLabels as $fieldKey => $fieldLabel): ?><option value="<?= e((string) $fieldKey) ?>"><?= e((string) $fieldLabel) ?></option><?php endforeach; ?></select></label><label><span><?= e(t('common.order')) ?></span><input type="number" name="sort_order" value="<?= count($typeFields) + 1 ?>" min="0" max="9999"></label><label class="admin-workout-field-toggle"><span><strong><?= e(t('workout_fields.required')) ?></strong></span><span class="admin-app-switch"><input type="checkbox" name="required" value="1"><i aria-hidden="true"></i></span></label></div><button class="btn btn-primary" type="submit"><?= e(t('workout_fields.add')) ?></button></form></details>
            </div></section><?php endif; ?>
        </div>
        <?php endforeach; ?>
    </article>

    <?php endif; ?>
    <?php if ($activeSection === 'training'): ?>
    <?php
    $mediaCredentialState = is_array($mediaSearchCredentials ?? null) ? (array) $mediaSearchCredentials : [];
    $seasonAutomationState = is_array($seasonAutomation ?? null) ? (array) $seasonAutomation : ['enabled' => false, 'duration_weeks' => 12, 'ahead_count' => 4];
    $seasonScheduleState = is_array($seasonSchedule ?? null) ? (array) $seasonSchedule : ['current' => null, 'next' => null, 'gaps' => 0, 'overlaps' => 0];
    $seasonIconRows = season_icon_options();
    $currentAdminSeason = is_array($seasonScheduleState['current'] ?? null) ? (array) $seasonScheduleState['current'] : null;
    $nextAdminSeason = is_array($seasonScheduleState['next'] ?? null) ? (array) $seasonScheduleState['next'] : null;
    $adminSeasonRows = array_values((array) ($adminSeasons ?? []));
    ?>
    <article class="panel settings-panel active admin-training-page" data-spa-section="training">
        <div class="panel-head admin-section-list" data-spa-show-when-no-param="exercise_id" <?= $selectedTrainingExerciseId !== '' ? 'hidden' : '' ?>>
            <div>
                <p class="eyebrow"><?= e(t('admin.section_training')) ?></p>
                <h2><?= e(t('admin.section_training')) ?></h2>
                <p class="muted admin-section-help"><?= e(t('admin.training_help')) ?></p>
            </div>
            <?php $renderAdminBack('/?page=admin', t('nav.admin')); ?>
        </div>

        <div class="stack-lg admin-section-list admin-training-dashboard" data-spa-show-when-no-param="exercise_id" <?= $selectedTrainingExerciseId !== '' ? 'hidden' : '' ?>>
            <section class="admin-training-overview">
                <div class="admin-training-overview-head"><span aria-hidden="true"><?= activity_icon_svg('dumbbell') ?></span><div><p class="eyebrow"><?= e(t('admin.section_training')) ?></p><h2><?= e(t('admin.training_control_title')) ?></h2><p><?= e(t('admin.training_control_hint')) ?></p></div></div>
                <nav class="admin-training-shortcuts" aria-label="<?= e(t('admin.training_shortcuts')) ?>">
                    <a href="#season-planner"><span aria-hidden="true"><?= activity_icon_svg('trophy') ?></span><strong><?= e(t('admin.seasons')) ?></strong><small><?= count($adminSeasonRows) ?></small></a>
                    <a href="#media-providers"><span aria-hidden="true"><?= activity_icon_svg('image') ?></span><strong><?= e(t('admin.media_search_providers')) ?></strong><small><?= ($mediaSearchGoogleReady ? 1 : 0) + ($mediaSearchYoutubeReady ? 1 : 0) ?>/2</small></a>
                    <a href="#rank-settings"><span aria-hidden="true"><?= activity_icon_svg('bolt') ?></span><strong><?= e(t('admin.rank_tiers')) ?></strong><small><?= count((array) ($adminRankTiers ?? [])) ?></small></a>
                    <a href="#exercise-library"><span aria-hidden="true"><?= activity_icon_svg('grid') ?></span><strong><?= e(t('admin.exercise_library')) ?></strong><small><?= count((array) ($adminTrainingExercises ?? [])) ?></small></a>
                </nav>
            </section>

            <details class="admin-training-block admin-training-feature admin-media-config" id="media-providers" <?= (!$mediaSearchGoogleReady || !$mediaSearchYoutubeReady) ? 'open' : '' ?>>
                <summary><span><strong id="admin-media-search-title"><?= e(t('admin.media_search_title')) ?></strong><small><?= e(t('admin.media_search_hint')) ?></small></span><span class="badge"><?= ($mediaSearchGoogleReady ? 1 : 0) + ($mediaSearchYoutubeReady ? 1 : 0) ?>/2</span></summary>
                <div class="admin-media-config-body">
                    <div class="admin-media-search-providers" aria-label="<?= e(t('admin.media_search_providers')) ?>">
                        <article class="<?= $mediaSearchGoogleReady ? 'is-ready' : 'is-missing' ?>"><b>G</b><span><strong><?= e(t('workouts.media_search_google_provider')) ?></strong><small><?= e($mediaSearchGoogleReady ? t('admin.media_search_configured') : t('admin.media_search_not_configured')) ?></small></span><?php if ($mediaSearchGoogleReady): ?><form method="post" action="/?page=admin&amp;section=training"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="test_media_search_provider"><input type="hidden" name="provider" value="google"><button class="btn btn-ghost small" type="submit"><?= e(t('common.verify')) ?></button></form><?php endif; ?></article>
                        <article class="<?= $mediaSearchYoutubeReady ? 'is-ready' : 'is-missing' ?>"><b aria-hidden="true">▶</b><span><strong>YouTube</strong><small><?= e($mediaSearchYoutubeReady ? t('admin.media_search_configured') : t('admin.media_search_not_configured')) ?></small></span><?php if ($mediaSearchYoutubeReady): ?><form method="post" action="/?page=admin&amp;section=training"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="test_media_search_provider"><input type="hidden" name="provider" value="youtube"><button class="btn btn-ghost small" type="submit"><?= e(t('common.verify')) ?></button></form><?php endif; ?></article>
                    </div>
                    <form method="post" action="/?page=admin&amp;section=training" class="admin-media-credentials-form" autocomplete="off">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_media_search_credentials">
                        <div class="admin-media-provider-fields"><div><p class="eyebrow">Google Images</p><label><span><?= e(t('admin.media_search_api_key')) ?></span><input type="password" name="google_api_key" value="" placeholder="<?= e((string) ($mediaCredentialState['google_key_masked'] ?? t('admin.media_search_secret_placeholder'))) ?>" autocomplete="new-password" spellcheck="false"></label><label><span><?= e(t('admin.media_search_engine_id')) ?></span><input type="password" name="google_cx" value="" placeholder="<?= e((string) ($mediaCredentialState['google_cx_masked'] ?? t('admin.media_search_secret_placeholder'))) ?>" autocomplete="new-password" spellcheck="false"></label></div><div><p class="eyebrow">YouTube Data API</p><label><span><?= e(t('admin.media_search_api_key')) ?></span><input type="password" name="youtube_api_key" value="" placeholder="<?= e((string) ($mediaCredentialState['youtube_key_masked'] ?? t('admin.media_search_secret_placeholder'))) ?>" autocomplete="new-password" spellcheck="false"></label><p><?= e(t('admin.media_search_secret_hint')) ?></p></div></div>
                        <button class="btn btn-primary" type="submit"><?= e(t('admin.media_search_save_credentials')) ?></button>
                    </form>
                    <form method="post" action="/?page=admin&amp;section=training" class="admin-media-enable-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_media_search_settings"><input type="hidden" name="media_search_enabled" value="0"><label><span><strong><?= e(t('admin.media_search_enabled')) ?></strong><small><?= e(t('admin.media_search_enabled_hint')) ?></small></span><span class="admin-app-switch"><input type="checkbox" name="media_search_enabled" value="1" <?= $mediaSearchEnabled ? 'checked' : '' ?>><i aria-hidden="true"></i></span></label><button class="btn btn-ghost" type="submit"><?= e(t('common.save')) ?></button></form>
                    <details class="admin-media-tutorial"><summary><span aria-hidden="true"><?= activity_icon_svg('info') ?></span><span><strong><?= e(t('admin.media_search_tutorial_title')) ?></strong><small><?= e(t('admin.media_search_tutorial_hint')) ?></small></span><span aria-hidden="true">⌄</span></summary><div><ol><li><?= e(t('admin.media_search_tutorial_project')) ?></li><li><?= e(t('admin.media_search_tutorial_youtube')) ?></li><li><?= e(t('admin.media_search_tutorial_google')) ?></li><li><?= e(t('admin.media_search_tutorial_restrict')) ?></li><li><?= e(t('admin.media_search_tutorial_paste')) ?></li></ol><p class="admin-media-api-warning"><?= e(t('admin.media_search_google_deprecation')) ?></p><div class="admin-media-doc-links"><a href="https://developers.google.com/youtube/v3/getting-started" target="_blank" rel="noopener noreferrer">YouTube Data API</a><a href="https://developers.google.com/custom-search/v1/overview" target="_blank" rel="noopener noreferrer">Custom Search JSON API</a><a href="https://docs.cloud.google.com/api-keys/docs/add-restrictions-api-keys" target="_blank" rel="noopener noreferrer"><?= e(t('admin.media_search_key_security')) ?></a></div></div></details>
                </div>
            </details>

            <details class="admin-training-block" id="rank-settings">
                <summary>
                    <span><strong><?= e(t('admin.rank_tiers')) ?></strong><small><?= e(t('admin.rank_tiers_hint')) ?></small></span>
                    <span class="badge"><?= count((array) ($adminRankTiers ?? [])) ?></span>
                </summary>
                <form method="post" action="/?page=admin" class="stack compact-form admin-rank-tier-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="save_rank_tiers">
                    <div class="admin-rank-tier-list">
                        <?php foreach ((array) ($adminRankTiers ?? []) as $tier): ?>
                            <?php $tierKey = (string) ($tier['tier_key'] ?? ''); ?>
                            <fieldset class="admin-rank-tier-row">
                                <legend><span class="rank-dot" style="--rank-color:<?= e((string) ($tier['color'] ?? '#64748b')) ?>"></span><?= e(ucwords($tierKey)) ?></legend>
                                <label>Points <input type="number" step="0.1" min="0" name="tiers[<?= e($tierKey) ?>][threshold]" value="<?= e((string) ($tier['threshold'] ?? 0)) ?>"></label>
                                <label>Colour <input type="color" name="tiers[<?= e($tierKey) ?>][color]" value="<?= e((string) ($tier['color'] ?? '#64748b')) ?>"></label>
                                <label>Order <input type="number" name="tiers[<?= e($tierKey) ?>][sort_order]" value="<?= (int) ($tier['sort_order'] ?? 0) ?>"></label>
                                <input type="hidden" name="tiers[<?= e($tierKey) ?>][active]" value="0">
                                <label class="check"><input type="checkbox" name="tiers[<?= e($tierKey) ?>][active]" value="1" <?= (int) ($tier['active'] ?? 0) === 1 ? 'checked' : '' ?> <?= $tierKey === 'unranked' ? 'disabled' : '' ?>><?= e(t('common.active')) ?></label>
                                <?php if ($tierKey === 'unranked'): ?><input type="hidden" name="tiers[<?= e($tierKey) ?>][active]" value="1"><?php endif; ?>
                            </fieldset>
                        <?php endforeach; ?>
                    </div>
                    <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
                </form>
            </details>

            <details class="admin-training-block admin-season-planner" id="season-planner" open>
                <summary><span><strong><?= e(t('admin.seasons')) ?></strong><small><?= e(t('admin.seasons_hint')) ?></small></span><span class="badge"><?= count($adminSeasonRows) ?></span></summary>
                <div class="admin-season-manager">
                    <div class="admin-season-schedule-summary">
                        <span><small><?= e(t('admin.season_current')) ?></small><strong><?= e((string) ($currentAdminSeason['name'] ?? t('common.none'))) ?></strong></span>
                        <span><small><?= e(t('admin.season_next')) ?></small><strong><?= e((string) ($nextAdminSeason['name'] ?? t('common.none'))) ?></strong></span>
                        <span class="<?= (int) ($seasonScheduleState['gaps'] ?? 0) === 0 ? 'is-ok' : 'is-warn' ?>"><small><?= e(t('admin.season_gaps')) ?></small><strong><?= (int) ($seasonScheduleState['gaps'] ?? 0) ?></strong></span>
                        <span class="<?= (int) ($seasonScheduleState['overlaps'] ?? 0) === 0 ? 'is-ok' : 'is-warn' ?>"><small><?= e(t('admin.season_overlaps')) ?></small><strong><?= (int) ($seasonScheduleState['overlaps'] ?? 0) ?></strong></span>
                    </div>

                    <form method="post" action="/?page=admin&amp;section=training" class="admin-season-automation-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_season_automation"><input type="hidden" name="season_auto_enabled" value="0">
                        <div class="admin-season-automation-copy"><span aria-hidden="true"><?= activity_icon_svg('bolt') ?></span><div><strong><?= e(t('admin.season_automation_title')) ?></strong><small><?= e(t('admin.season_automation_hint')) ?></small></div></div>
                        <label><span><?= e(t('admin.season_duration_weeks')) ?></span><input type="number" name="duration_weeks" value="<?= (int) ($seasonAutomationState['duration_weeks'] ?? 12) ?>" min="4" max="26" inputmode="numeric"></label>
                        <label><span><?= e(t('admin.season_ahead_count')) ?></span><input type="number" name="ahead_count" value="<?= (int) ($seasonAutomationState['ahead_count'] ?? 4) ?>" min="1" max="8" inputmode="numeric"></label>
                        <label class="admin-season-auto-toggle"><span><strong><?= e(!empty($seasonAutomationState['enabled']) ? t('common.active') : t('common.inactive')) ?></strong></span><span class="admin-app-switch"><input type="checkbox" name="season_auto_enabled" value="1" <?= !empty($seasonAutomationState['enabled']) ? 'checked' : '' ?>><i aria-hidden="true"></i></span></label>
                        <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
                    </form>

                    <details class="admin-season-create">
                        <summary><span aria-hidden="true"><?= activity_icon_svg('plus') ?></span><span><strong><?= e(t('admin.season_create')) ?></strong><small><?= e(t('admin.season_create_hint')) ?></small></span><span aria-hidden="true">⌄</span></summary>
                        <form method="post" action="/?page=admin&amp;section=training" enctype="multipart/form-data" class="admin-season-form">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_season">
                            <div class="admin-season-core-fields"><label><span><?= e(t('admin.season_name')) ?></span><input type="text" name="season_name" maxlength="100" placeholder="Summer Strength" required></label><label><span><?= e(t('admin.season_key')) ?></span><input type="text" name="season_key" pattern="[A-Za-z0-9_-]+" maxlength="80" value="season-<?= e(str_replace('-', '', (string) ($nextSeasonStart ?? date('Y-m-d')))) ?>" required></label><label><span><?= e(t('admin.season_starts')) ?></span><input type="date" name="start_date" value="<?= e((string) ($nextSeasonStart ?? date('Y-m-d'))) ?>" required></label><label><span><?= e(t('admin.season_ends')) ?></span><input type="date" name="end_date" value="<?= e((string) ($nextSeasonEnd ?? date('Y-m-d', strtotime('+12 weeks -1 day')))) ?>" required></label></div>
                            <div class="admin-season-identity"><div><span><?= e(t('admin.season_icon')) ?></span><div class="admin-season-icon-options"><?php foreach ($seasonIconRows as $iconKey => $iconLabel): ?><label title="<?= e($iconLabel) ?>"><input type="radio" name="icon_key" value="<?= e($iconKey) ?>" <?= $iconKey === 'trophy' ? 'checked' : '' ?>><span><?= activity_icon_svg($iconKey) ?></span></label><?php endforeach; ?></div></div><label class="admin-season-color"><span><?= e(t('admin.season_color')) ?></span><input type="color" name="accent_color" value="#8b5cf6"></label><label class="admin-season-cover-upload"><span><?= e(t('admin.season_cover')) ?></span><input type="file" name="season_cover" accept="image/jpeg,image/png,image/webp"><small><?= e(t('admin.season_cover_hint')) ?></small></label></div>
                            <button class="btn btn-primary" type="submit"><?= e(t('admin.season_create')) ?></button>
                        </form>
                    </details>

                    <div class="admin-season-list">
                        <?php foreach ($adminSeasonRows as $season): ?>
                            <?php
                            $seasonIsCurrent = (string) ($season['start_date'] ?? '') <= date('Y-m-d') && (string) ($season['end_date'] ?? '') >= date('Y-m-d');
                            $seasonIsUpcoming = (string) ($season['start_date'] ?? '') > date('Y-m-d');
                            $seasonStateLabel = $seasonIsCurrent ? t('admin.season_current') : ($seasonIsUpcoming ? t('admin.season_upcoming') : t('admin.season_finished'));
                            $seasonCoverUrl = trim((string) ($season['cover_path'] ?? '')) !== '' ? media_url((string) $season['cover_path']) : '';
                            $seasonIcon = season_normalize_icon_key($season['icon_key'] ?? 'trophy');
                            $seasonAccent = season_normalize_accent_color($season['accent_color'] ?? '#8b5cf6');
                            ?>
                            <details class="admin-season-item" style="--season-accent: <?= e($seasonAccent) ?>">
                                <summary><span class="admin-season-visual<?= $seasonCoverUrl !== '' ? ' has-cover' : '' ?>" aria-hidden="true"><?php if ($seasonCoverUrl !== ''): ?><img src="<?= e($seasonCoverUrl) ?>" alt="" loading="lazy"><?php else: ?><?= activity_icon_svg($seasonIcon) ?><?php endif; ?></span><span class="admin-season-summary-copy"><strong><?= e((string) ($season['name'] ?? '')) ?></strong><small><?= e(format_date_eu((string) ($season['start_date'] ?? ''))) ?> – <?= e(format_date_eu((string) ($season['end_date'] ?? ''))) ?></small></span><span class="admin-season-source"><?= e((string) ($season['generation_source'] ?? 'manual') === 'automatic' ? t('admin.season_auto_badge') : t('admin.season_manual_badge')) ?></span><b class="is-<?= $seasonIsCurrent ? 'current' : ($seasonIsUpcoming ? 'upcoming' : 'finished') ?>"><?= e($seasonStateLabel) ?></b><span aria-hidden="true">⌄</span></summary>
                                <form method="post" action="/?page=admin&amp;section=training" enctype="multipart/form-data" class="admin-season-form is-edit">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_season"><input type="hidden" name="season_id" value="<?= (int) ($season['id'] ?? 0) ?>">
                                    <div class="admin-season-core-fields"><label><span><?= e(t('admin.season_name')) ?></span><input type="text" name="season_name" maxlength="100" value="<?= e((string) ($season['name'] ?? '')) ?>" required></label><label><span><?= e(t('admin.season_key')) ?></span><input type="text" name="season_key" pattern="[A-Za-z0-9_-]+" maxlength="80" value="<?= e((string) ($season['season_key'] ?? '')) ?>" required></label><label><span><?= e(t('admin.season_starts')) ?></span><input type="date" name="start_date" value="<?= e((string) ($season['start_date'] ?? '')) ?>" required></label><label><span><?= e(t('admin.season_ends')) ?></span><input type="date" name="end_date" value="<?= e((string) ($season['end_date'] ?? '')) ?>" required></label></div>
                                    <div class="admin-season-identity"><div><span><?= e(t('admin.season_icon')) ?></span><div class="admin-season-icon-options"><?php foreach ($seasonIconRows as $iconKey => $iconLabel): ?><label title="<?= e($iconLabel) ?>"><input type="radio" name="icon_key" value="<?= e($iconKey) ?>" <?= $seasonIcon === $iconKey ? 'checked' : '' ?>><span><?= activity_icon_svg($iconKey) ?></span></label><?php endforeach; ?></div></div><label class="admin-season-color"><span><?= e(t('admin.season_color')) ?></span><input type="color" name="accent_color" value="<?= e($seasonAccent) ?>"></label><div class="admin-season-cover-upload"><label><span><?= e(t('admin.season_cover')) ?></span><input type="file" name="season_cover" accept="image/jpeg,image/png,image/webp"></label><?php if ($seasonCoverUrl !== ''): ?><label class="admin-season-remove-cover"><input type="checkbox" name="remove_season_cover" value="1"> <?= e(t('admin.season_remove_cover')) ?></label><?php else: ?><small><?= e(t('admin.season_cover_hint')) ?></small><?php endif; ?></div></div>
                                    <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
                                </form>
                                <form method="post" action="/?page=admin&amp;section=training" class="admin-season-delete" onsubmit="return confirm('<?= e(t('admin.season_delete_confirm')) ?>');"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_season"><input type="hidden" name="season_id" value="<?= (int) ($season['id'] ?? 0) ?>"><button class="btn btn-ghost small" type="submit"><?= e(t('common.delete')) ?></button></form>
                            </details>
                        <?php endforeach; ?>
                    </div>
                </div>
            </details>

            <details class="admin-training-block admin-training-exercises" id="exercise-library">
                <summary>
                    <span><strong><?= e(t('admin.exercise_library')) ?></strong><small><?= e(t('admin.exercise_library_hint')) ?></small></span>
                    <span class="badge"><?= count((array) ($adminTrainingExercises ?? [])) ?></span>
                </summary>
                <div class="admin-training-exercises-body">
                <div class="panel-head compact-head">
                    <div>
                        <p class="muted small"><?= e(t('admin.exercise_library_choose')) ?></p>
                    </div>
                    <a class="btn btn-primary small" href="/?page=admin&amp;section=training&amp;exercise_id=new"><?= e(t('common.create')) ?></a>
                </div>
                <div class="settings-list compact-list admin-training-exercise-list">
                    <?php foreach ((array) ($adminTrainingExercises ?? []) as $exercise): ?>
                        <?php $exerciseImageUrl = trim((string) ($exercise['image_path'] ?? '')) !== '' ? media_url((string) $exercise['image_path']) : ''; ?>
                        <a class="settings-row admin-training-exercise-row" href="/?page=admin&amp;section=training&amp;exercise_id=<?= (int) ($exercise['id'] ?? 0) ?>">
                            <?php if ($exerciseImageUrl !== ''): ?>
                                <img src="<?= e($exerciseImageUrl) ?>" alt="" loading="lazy">
                            <?php else: ?>
                                <span class="admin-training-exercise-icon" aria-hidden="true">&#x1F3CB;</span>
                            <?php endif; ?>
                            <span class="admin-training-exercise-copy">
                                <strong><?= e((string) ($exercise['name'] ?? '')) ?></strong>
                                <small class="muted"><?= e(ucwords((string) ($exercise['muscle_group'] ?? ''))) ?> &middot; <?= e(ucwords((string) ($exercise['equipment'] ?? ''))) ?> &middot; <?= (int) (($exercise['routine_uses'] ?? 0) + ($exercise['session_uses'] ?? 0)) ?> uses</small>
                            </span>
                            <span class="badge <?= (int) ($exercise['active'] ?? 0) === 1 ? 'badge-ok' : 'badge-warn' ?>"><?= (int) ($exercise['active'] ?? 0) === 1 ? e(t('common.active')) : 'Hidden' ?></span>
                            <span class="settings-chevron" aria-hidden="true">&rsaquo;</span>
                        </a>
                    <?php endforeach; ?>
                </div>
                </div>
            </details>
        </div>

        <?php if ($selectedTrainingExerciseId === 'new'): ?>
        <div class="stack admin-create-view">
            <div class="panel-head">
                <div><p class="eyebrow">Exercise library</p><h3>Create exercise</h3></div>
                <?php $renderAdminBack('/?page=admin&section=training', t('admin.section_training')); ?>
            </div>
            <?php
            $trainingExerciseFormItem = [];
            $trainingExerciseIsNew = true;
            require __DIR__ . '/partials/admin_training_exercise_form.php';
            ?>
        </div>
        <?php endif; ?>

        <?php foreach ((array) ($adminTrainingExercises ?? []) as $exercise): ?>
            <?php if ($selectedTrainingExerciseId !== (string) ((int) ($exercise['id'] ?? 0))): ?><?php continue; ?><?php endif; ?>
            <div class="stack admin-detail-view">
                <div class="panel-head">
                    <div><p class="eyebrow">Exercise library</p><h3><?= e((string) ($exercise['name'] ?? '')) ?></h3></div>
                    <?php $renderAdminBack('/?page=admin&section=training', t('admin.section_training')); ?>
                </div>
                <?php
                $trainingExerciseFormItem = $exercise;
                $trainingExerciseIsNew = false;
                require __DIR__ . '/partials/admin_training_exercise_form.php';
                ?>
            </div>
        <?php endforeach; ?>
    </article>

    <?php endif; ?>
    <?php if ($activeSection === 'achievements'): ?>
        <?php require __DIR__ . '/partials/admin_achievements.php'; ?>
    <?php endif; ?>
    <?php if ($activeSection === 'motivational_quotes'): ?>
    <article class="panel settings-panel active admin-quotes-page" data-spa-section="motivational_quotes">
        <?php
        $quoteLocales = function_exists('motivational_quote_locales') ? motivational_quote_locales() : ['any', 'en', 'es', 'it'];
        $quoteLocaleLabel = static fn(string $loc): string => $loc === 'any' ? t('admin.quote_lang_any') : t('admin.quote_lang_' . $loc);
        $quoteRows = is_array($motivationalQuotes ?? null) ? (array) $motivationalQuotes : [];
        $quoteActiveCount = 0;
        $quoteInactiveCount = 0;
        $quoteUsedLocales = [];
        foreach ($quoteRows as $quoteRow) {
            (int) ($quoteRow['active'] ?? 1) === 1 ? $quoteActiveCount++ : $quoteInactiveCount++;
            $quoteUsedLocales[normalize_quote_locale((string) ($quoteRow['locale'] ?? 'any'))] = true;
        }
        ?>
        <section class="admin-quotes-overview">
            <div class="admin-quotes-overview-head">
                <span class="admin-quotes-overview-icon" aria-hidden="true"><?= activity_icon_svg('spark') ?></span>
                <div><p class="eyebrow"><?= e(t('admin.motivational_quotes')) ?></p><h2><?= e(t('admin.quotes_library_title')) ?></h2><p><?= e(t('admin.motivational_quotes_help')) ?></p></div>
            </div>
            <div class="admin-quotes-stats">
                <span><strong><?= count($quoteRows) ?></strong><small><?= e(t('admin.motivational_quotes_all')) ?></small></span>
                <span class="is-active"><strong><?= $quoteActiveCount ?></strong><small><?= e(t('admin.quotes_active')) ?></small></span>
                <span><strong><?= $quoteInactiveCount ?></strong><small><?= e(t('admin.quotes_inactive')) ?></small></span>
                <span><strong><?= count($quoteUsedLocales) ?></strong><small><?= e(t('admin.quotes_languages')) ?></small></span>
            </div>
        </section>

        <details class="admin-quotes-create" <?= $quoteRows === [] ? 'open' : '' ?>>
            <summary><span aria-hidden="true"><?= activity_icon_svg('plus') ?></span><span><strong><?= e(t('admin.quotes_create_title')) ?></strong><small><?= e(t('admin.quotes_create_hint')) ?></small></span><span aria-hidden="true">⌄</span></summary>
            <form method="post" action="/?page=admin&amp;section=motivational_quotes" class="admin-quote-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="create_motivational_quote">
                <label><span><?= e(t('admin.quote_text')) ?></span><textarea name="quote_text" rows="3" maxlength="280" required placeholder="<?= e(t('admin.quotes_text_placeholder')) ?>"></textarea><small><?= e(t('admin.quotes_text_limit')) ?></small></label>
                <label><span><?= e(t('admin.quote_language')) ?></span><select name="quote_locale"><?php foreach ($quoteLocales as $loc): ?><option value="<?= e($loc) ?>"><?= e($quoteLocaleLabel($loc)) ?></option><?php endforeach; ?></select></label>
                <button class="btn btn-primary" type="submit"><?= e(t('admin.quotes_create_action')) ?></button>
            </form>
        </details>

        <section class="admin-quotes-library">
            <div class="admin-quotes-library-head"><div><h3><?= e(t('admin.motivational_quotes_all')) ?></h3><p><?= e(t('admin.quotes_list_hint')) ?></p></div><span><?= count($quoteRows) ?></span></div>
            <div class="admin-quote-list">
                <?php foreach ($quoteRows as $quote): ?>
                    <?php
                    $quoteId = (int) ($quote['id'] ?? 0);
                    $quoteActive = (int) ($quote['active'] ?? 1) === 1;
                    $quoteLoc = function_exists('normalize_quote_locale') ? normalize_quote_locale((string) ($quote['locale'] ?? 'any')) : 'any';
                    $quoteAuthor = trim((string) ($quote['created_by_name'] ?? ''));
                    $quoteDate = format_date_eu((string) ($quote['created_at'] ?? ''));
                    ?>
                    <details class="admin-quote-item" data-status="<?= $quoteActive ? 'active' : 'inactive' ?>">
                        <summary>
                            <span class="admin-quote-mark" aria-hidden="true">“</span>
                            <span class="admin-quote-summary-copy"><strong><?= e((string) $quote['quote_text']) ?></strong><small><?= e($quoteDate) ?><?php if ($quoteAuthor !== ''): ?> · <?= e($quoteAuthor) ?><?php endif; ?></small></span>
                            <span class="admin-quote-locale"><?= e($quoteLocaleLabel($quoteLoc)) ?></span>
                            <span class="admin-quote-state"><i aria-hidden="true"></i><?= e($quoteActive ? t('admin.quotes_active_single') : t('admin.quotes_inactive_single')) ?></span>
                            <span class="admin-quote-chevron" aria-hidden="true">⌄</span>
                        </summary>
                        <div class="admin-quote-editor">
                        <form method="post" action="/?page=admin&amp;section=motivational_quotes" class="admin-quote-edit-form">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="update_motivational_quote">
                            <input type="hidden" name="quote_id" value="<?= $quoteId ?>">
                            <label class="admin-quote-text-field"><span><?= e(t('admin.quote_text')) ?></span><textarea name="quote_text" rows="3" maxlength="280" required><?= e((string) $quote['quote_text']) ?></textarea><small><?= e(t('admin.quotes_text_limit')) ?></small></label>
                            <div class="admin-quote-edit-controls">
                                <label class="admin-quote-lang"><span><?= e(t('admin.quote_language')) ?></span>
                                    <select name="quote_locale">
                                        <?php foreach ($quoteLocales as $loc): ?>
                                            <option value="<?= e($loc) ?>" <?= $quoteLoc === $loc ? 'selected' : '' ?>><?= e($quoteLocaleLabel($loc)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label class="admin-quote-active"><span><strong><?= e(t('admin.quotes_publish_label')) ?></strong><small><?= e(t('admin.quotes_publish_hint')) ?></small></span><span class="admin-app-switch"><input type="checkbox" name="quote_active" value="1" <?= $quoteActive ? 'checked' : '' ?>><i aria-hidden="true"></i></span></label>
                            </div>
                            <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
                        </form>
                        <form method="post" action="/?page=admin&amp;section=motivational_quotes" class="admin-quote-delete-form" onsubmit="return confirm('<?= e(t('admin.quote_delete_confirm')) ?>');">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete_motivational_quote">
                            <input type="hidden" name="quote_id" value="<?= $quoteId ?>">
                            <button class="btn btn-ghost small btn-danger-ghost" type="submit"><?= e(t('common.delete')) ?></button>
                        </form>
                        </div>
                    </details>
                <?php endforeach; ?>
                <?php if ($quoteRows === []): ?>
                    <div class="admin-quotes-empty"><span aria-hidden="true"><?= activity_icon_svg('spark') ?></span><strong><?= e(t('admin.quotes_empty_title')) ?></strong><p><?= e(t('admin.quotes_empty_hint')) ?></p></div>
                <?php endif; ?>
            </div>
        </section>
    </article>

    <?php endif; ?>
    <?php if ($activeSection === 'xp'): ?>
        <?php require __DIR__ . '/partials/admin_xp.php'; ?>

    <?php endif; ?>
    <?php if ($activeSection === 'audit'): ?>
    <?php
    $auditRows = (array) ($auditLogs ?? []);
    $auditActiveFilterCount = count(array_filter([
        $auditFilters['actor_user_id'] ?? null,
        $auditFilters['entity_type'] ?? '',
        $auditFilters['date_from'] ?? null,
        $auditFilters['date_to'] ?? null,
    ], static fn(mixed $value): bool => $value !== null && $value !== ''));
    $auditUniqueActors = [];
    $auditUniqueEntities = [];
    foreach ($auditRows as $auditRow) {
        $auditUniqueActors[(string) ($auditRow['actor_name'] ?? t('common.none'))] = true;
        $auditUniqueEntities[(string) ($auditRow['entity_type'] ?? '')] = true;
    }
    unset($auditUniqueEntities['']);
    $formatAuditJson = static function (mixed $value): string {
        $raw = trim((string) $value);
        if ($raw === '' || in_array($raw, ['null', '[]', '{}'], true)) {
            return '';
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            return (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return $raw;
        }
    };
    ?>
    <article class="panel settings-panel active admin-audit-page" data-spa-section="audit">
        <section class="admin-audit-overview">
            <div class="admin-audit-overview-head">
                <div><p class="eyebrow"><?= e(t('admin.group_people')) ?></p><h2><?= e(t('audit.recent_activity')) ?></h2></div>
                <?php if ($auditActiveFilterCount > 0): ?><a class="btn btn-ghost small" href="/?page=admin&amp;section=audit" data-spa-link><?= e(t('audit.clear_filters')) ?></a><?php endif; ?>
            </div>
            <div class="admin-audit-stats">
                <span><strong><?= count($auditRows) ?></strong><small><?= e(t('audit.events_loaded')) ?></small></span>
                <span><strong><?= count($auditUniqueActors) ?></strong><small><?= e(t('audit.unique_actors')) ?></small></span>
                <span><strong><?= count($auditUniqueEntities) ?></strong><small><?= e(t('audit.entity_types')) ?></small></span>
            </div>
        </section>

        <details class="admin-audit-filters" <?= $auditActiveFilterCount > 0 ? 'open' : '' ?>>
            <summary><span aria-hidden="true"><?= activity_icon_svg('sliders') ?></span><strong><?= e(t('audit.filters')) ?></strong><?php if ($auditActiveFilterCount > 0): ?><small><?= $auditActiveFilterCount ?></small><?php endif; ?><span aria-hidden="true">⌄</span></summary>
            <form method="get" action="/" class="audit-filter">
                <input type="hidden" name="page" value="admin">
                <input type="hidden" name="section" value="audit">
                <label><span><?= e(t('audit.actor')) ?></span><select name="actor_user_id"><option value=""><?= e(t('audit.all')) ?></option><?php foreach ($users as $user): ?><option value="<?= (int) $user['id'] ?>" <?= (int) ($auditFilters['actor_user_id'] ?? 0) === (int) $user['id'] ? 'selected' : '' ?>><?= e((string) $user['display_name']) ?></option><?php endforeach; ?></select></label>
                <label><span><?= e(t('audit.entity')) ?></span><select name="entity_type"><option value=""><?= e(t('audit.all')) ?></option><?php foreach ($entityTypes as $type): ?><option value="<?= e($type) ?>" <?= ($auditFilters['entity_type'] ?? '') === $type ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $type))) ?></option><?php endforeach; ?></select></label>
                <label><span><?= e(t('audit.from')) ?></span><input type="date" name="date_from" value="<?= e((string) ($auditFilters['date_from'] ?? '')) ?>"></label>
                <label><span><?= e(t('audit.to')) ?></span><input type="date" name="date_to" value="<?= e((string) ($auditFilters['date_to'] ?? '')) ?>"></label>
                <div class="admin-audit-filter-actions">
                    <button class="btn btn-primary" type="submit"><?= e(t('audit.filter')) ?></button>
                    <?php if ($auditActiveFilterCount > 0): ?><a class="btn btn-ghost" href="/?page=admin&amp;section=audit" data-spa-link><?= e(t('audit.clear_filters')) ?></a><?php endif; ?>
                </div>
            </form>
        </details>

        <div class="audit-list audit-list-admin">
            <?php if ($auditRows === []): ?>
                <div class="admin-audit-empty"><span aria-hidden="true"><?= activity_icon_svg('shield') ?></span><strong><?= e(t('audit.empty')) ?></strong><?php if ($auditActiveFilterCount > 0): ?><a href="/?page=admin&amp;section=audit" data-spa-link><?= e(t('audit.clear_filters')) ?></a><?php endif; ?></div>
            <?php else: ?>
                <?php foreach ($auditRows as $log): ?>
                    <?php
                    $auditAction = (string) ($log['action'] ?? '');
                    $auditActionLabel = ucwords(str_replace('_', ' ', $auditAction));
                    $auditEntityLabel = ucwords(str_replace('_', ' ', (string) ($log['entity_type'] ?? '')));
                    $auditTone = preg_match('/delete|remove|revoke|archive|reject/i', $auditAction) === 1
                        ? 'danger'
                        : (preg_match('/create|add|approve|restore|reactivate|complete/i', $auditAction) === 1 ? 'success' : 'neutral');
                    $auditBefore = $formatAuditJson($log['before_json'] ?? '');
                    $auditAfter = $formatAuditJson($log['after_json'] ?? '');
                    $auditCreatedRaw = trim((string) ($log['created_at'] ?? ''));
                    $auditCreatedLabel = $auditCreatedRaw;
                    try {
                        $auditCreatedDate = new DateTimeImmutable($auditCreatedRaw);
                        $auditCreatedLabel = format_date_eu($auditCreatedDate->format('Y-m-d')) . ' · ' . $auditCreatedDate->format('H:i');
                    } catch (Throwable) {
                    }
                    ?>
                    <article class="admin-audit-event" data-tone="<?= e($auditTone) ?>">
                        <span class="admin-audit-marker" aria-hidden="true"><span></span></span>
                        <div class="admin-audit-event-body">
                            <header><strong><?= e((string) ($log['summary'] ?? $auditActionLabel)) ?></strong><time datetime="<?= e($auditCreatedRaw) ?>"><?= e($auditCreatedLabel) ?></time></header>
                            <div class="admin-audit-meta">
                                <span class="admin-audit-actor"><i aria-hidden="true"><?= e(initials_for((string) ($log['actor_name'] ?? '?'))) ?></i><b><?= e((string) ($log['actor_name'] ?? t('common.none'))) ?></b></span>
                                <span><?= e($auditActionLabel !== '' ? $auditActionLabel : t('common.none')) ?></span>
                                <span><?= e($auditEntityLabel !== '' ? $auditEntityLabel : t('common.none')) ?></span>
                            </div>
                            <?php if ($auditBefore !== '' || $auditAfter !== ''): ?>
                                <details class="admin-audit-diff">
                                    <summary><span><?= e(t('audit.diff')) ?></span><span aria-hidden="true">⌄</span></summary>
                                    <div class="admin-audit-diff-grid">
                                        <?php if ($auditBefore !== ''): ?><section><h4><?= e(t('audit.before')) ?></h4><pre><?= e($auditBefore) ?></pre></section><?php endif; ?>
                                        <?php if ($auditAfter !== ''): ?><section><h4><?= e(t('audit.after')) ?></h4><pre><?= e($auditAfter) ?></pre></section><?php endif; ?>
                                    </div>
                                </details>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </article>
    <?php endif; ?>
</section>
