<?php

declare(strict_types=1);

$days = [];
for ($i = 0; $i < 7; $i++) {
    $days[$i] = t('weekday.' . $i);
}
$entityTypes = ['daily_log', 'approval_request', 'user', 'team_membership', 'goal', 'achievement', 'workout_type', 'exercise_definition', 'workout_rank_tier', 'season', 'photo_entry', 'app_setting', 'system_backup', 'motivational_quote'];
$activeSection = (string) ($_GET['section'] ?? '');
$allowedSections = ['users', 'challenge', 'app', 'notion', 'telegram', 'backups', 'habits', 'workout_types', 'training', 'achievements', 'motivational_quotes', 'xp', 'audit'];
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
$achievementLocales = locale_options();
$achievementIconOptions = achievement_icon_options();
$sectionRows = [
    'users' => 'Users',
    'challenge' => 'Challenge',
    'app' => 'App',
    'notion' => 'Notion',
    'telegram' => 'Telegram',
    'backups' => 'Backups',
    'habits' => 'Habits',
    'workout_types' => 'Workout Types',
    'training' => 'Training & ranked',
    'achievements' => 'Achievements',
    'motivational_quotes' => t('admin.motivational_quotes'),
    'xp' => t('admin.xp_title'),
    'audit' => 'Audit Log',
];
$activeLoginBackgroundPath = trim((string) ($loginBackgroundPath ?? ''));
$activeLoginBackgroundUrl = $activeLoginBackgroundPath !== '' ? media_url($activeLoginBackgroundPath) : '';
$backupSettings = is_array($backupSettings ?? null) ? (array) $backupSettings : [];
$backupAutoEnabled = !empty($backupSettings['enabled']);
$backupFrequency = normalize_backup_frequency((string) ($backupSettings['frequency'] ?? 'daily'));
$backupRunTime = normalize_backup_run_time((string) ($backupSettings['run_time'] ?? '00:00'));
$backupRetentionCount = max(1, (int) ($backupSettings['retention_count'] ?? 20));
$backupLastAutoAt = trim((string) ($backupSettings['last_auto_at'] ?? ''));
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
        default => t('admin.backup_trigger_manual'),
    };
};
$backupStatusLabel = static function (string $status): string {
    return match ($status) {
        'restored' => t('admin.backup_status_restored'),
        'error' => t('common.error'),
        default => t('common.saved'),
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
<section class="screen stack-lg spa-shell" data-spa-page="admin">
    <div class="hero-panel">
        <div>
            <p class="eyebrow"><?= e(t('nav.admin')) ?></p>
            <h1><?= e(t('admin.title')) ?></h1>
            <p class="muted"><?= e(t('admin.subtitle')) ?></p>
        </div>
    </div>

    <article class="panel settings-list<?= $activeSection !== '' ? ' hidden' : '' ?>" data-spa-main <?= $activeSection !== '' ? 'hidden' : '' ?>>
        <h2>Admin</h2>
        <?php foreach ($sectionRows as $sectionKey => $label): ?>
            <a class="settings-row" href="/?page=admin&section=<?= e($sectionKey) ?>" data-spa-link>
                <span><?= e($label) ?></span>
                <span class="settings-chevron" aria-hidden="true">›</span>
            </a>
        <?php endforeach; ?>
    </article>

    <article class="panel settings-panel<?= $activeSection === 'users' ? ' active' : '' ?>" data-spa-section="users" <?= $activeSection === 'users' ? '' : 'hidden' ?>>
        <div class="panel-head admin-section-list" data-spa-show-when-no-param="create_user,user_id" <?= ($createUserMode || $selectedUserId > 0) ? 'hidden' : '' ?>>
            <h2>Users</h2>
            <div class="inline-actions-mini">
                <a class="btn btn-primary small" href="/?page=admin&section=users&create_user=1" data-spa-link><?= e(t('common.create')) ?></a>
                <a class="btn btn-ghost" href="/?page=admin" data-spa-back aria-label="<?= e(t('common.back')) ?>">← <?= e(t('common.back')) ?></a>
            </div>
        </div>

        <div class="settings-list compact-list admin-section-list" data-spa-show-when-no-param="create_user,user_id" <?= ($createUserMode || $selectedUserId > 0) ? 'hidden' : '' ?>>
            <a class="settings-row" href="/?page=admin&section=users&create_user=1" data-spa-link>
                <span><?= e(t('users.create')) ?></span>
                <span class="settings-chevron" aria-hidden="true">›</span>
            </a>
            <?php foreach ($users as $user): ?>
                <?php $adminUserAvatarUrl = avatar_url($user); ?>
                <a class="settings-row admin-user-row" href="/?page=admin&section=users&user_id=<?= (int) $user['id'] ?>" data-spa-link>
                    <?php if ($adminUserAvatarUrl !== ''): ?>
                        <img class="admin-user-avatar" src="<?= e($adminUserAvatarUrl) ?>" alt="<?= e((string) $user['display_name']) ?>">
                    <?php else: ?>
                        <span class="admin-user-avatar admin-user-avatar-initials"><?= e(initials_for((string) $user['display_name'])) ?></span>
                    <?php endif; ?>
                    <span class="admin-user-main">
                        <strong><?= e((string) $user['display_name']) ?></strong>
                        <small class="muted">@<?= e((string) $user['username']) ?> · <?= e((string) $user['role']) ?></small>
                    </span>
                    <span class="badge <?= (int) ($user['active'] ?? 1) === 1 ? 'badge-ok' : 'badge-warn' ?>"><?= (int) ($user['active'] ?? 1) === 1 ? e(t('common.active')) : e(t('workout_types.inactive')) ?></span>
                    <span class="settings-chevron" aria-hidden="true">›</span>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="stack admin-create-view" data-spa-param-show="create_user" data-spa-value="1" <?= $createUserMode ? '' : 'hidden' ?>>
            <div class="panel-head">
                <h3><?= e(t('users.create')) ?></h3>
                <a class="btn btn-ghost" href="/?page=admin&section=users" data-spa-back aria-label="<?= e(t('common.back')) ?>">← <?= e(t('common.back')) ?></a>
            </div>
            <form method="post" action="/?page=admin" class="stack">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="create_user">
                <div class="grid-inline">
                    <label><?= e(t('common.username')) ?><input type="text" name="username" required></label>
                    <label><?= e(t('common.display_name')) ?><input type="text" name="display_name" required></label>
                    <label><?= e(t('users.initial_password')) ?><input type="password" name="password" minlength="8" required></label>
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
                <button type="submit" class="btn btn-primary"><?= e(t('users.create')) ?></button>
            </form>
        </div>

        <?php foreach ($users as $user): ?>
            <div class="stack admin-detail-view" data-spa-param-show="user_id" data-spa-value="<?= (int) $user['id'] ?>" <?= $selectedUserId === (int) $user['id'] ? '' : 'hidden' ?>>
                <div class="panel-head">
                    <h3><?= e((string) $user['display_name']) ?></h3>
                    <a class="btn btn-ghost" href="/?page=admin&section=users" data-spa-back aria-label="<?= e(t('common.back')) ?>">← <?= e(t('common.back')) ?></a>
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

    <article class="panel settings-panel admin-challenge-panel<?= $activeSection === 'challenge' ? ' active' : '' ?>" data-spa-section="challenge" <?= $activeSection === 'challenge' ? '' : 'hidden' ?>>
        <div class="panel-head">
            <div>
                <p class="eyebrow"><?= e(t('admin.challenge')) ?></p>
                <h2><?= e(t('admin.challenge_settings')) ?></h2>
                <p class="muted small"><?= e(t('admin.start_new_challenge_hint')) ?></p>
            </div>
            <a class="btn btn-ghost" href="/?page=admin" data-spa-back aria-label="<?= e(t('common.back')) ?>">← <?= e(t('common.back')) ?></a>
        </div>
        <div class="admin-challenge-layout">
            <form method="post" action="/?page=admin" class="stack compact-form admin-challenge-card admin-challenge-current">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="update_challenge_settings">
                <div class="admin-challenge-card-head">
                    <div>
                        <h3><?= e(t('admin.challenge_current')) ?></h3>
                        <p class="muted small"><?= e($challengeRangeLabel) ?></p>
                    </div>
                    <span class="badge <?= $challengeIsActive ? 'badge-ok' : 'badge-warn' ?>"><?= e($challengeIsActive ? t('common.active') : t('common.closed_week')) ?></span>
                </div>
                <div class="admin-challenge-meta">
                    <span><small><?= e(t('admin.challenge')) ?></small><strong><?= e($challengeName) ?></strong></span>
                    <span><small><?= e(t('admin.archived_challenges')) ?></small><strong><?= e((string) $challengeArchiveCount) ?></strong></span>
                </div>
                <label><?= e(t('admin.challenge_name')) ?><input type="text" name="challenge_name" value="<?= e($challengeName) ?>" required></label>
                <div class="grid-inline two">
                    <label><?= e(t('audit.from')) ?><input type="date" name="challenge_start" value="<?= e($challengeStart) ?>" required></label>
                    <label><?= e(t('audit.to')) ?><input type="date" name="challenge_end" value="<?= e($challengeEnd) ?>" required></label>
                </div>
                <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
            </form>

            <form method="post" action="/?page=admin" class="stack compact-form admin-challenge-card admin-challenge-new">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="start_new_challenge">
                <div class="admin-challenge-card-head">
                    <div>
                        <h3><?= e(t('admin.start_new_challenge')) ?></h3>
                        <p class="muted small"><?= e(t('admin.challenge_new_hint')) ?></p>
                    </div>
                    <span class="badge"><?= e(t('profile.challenge_current')) ?></span>
                </div>
                <label><?= e(t('admin.new_challenge_name')) ?><input type="text" name="new_challenge_name" value="" placeholder="<?= e($challengeName) ?>" required></label>
                <div class="grid-inline two">
                    <label><?= e(t('audit.from')) ?><input type="date" name="new_challenge_start" value="<?= e($nextChallengeStart) ?>" required></label>
                    <label><?= e(t('audit.to')) ?><input type="date" name="new_challenge_end" value="<?= e($nextChallengeEnd) ?>" required></label>
                </div>
                <button class="btn btn-secondary" type="submit"><?= e(t('admin.start_new_challenge')) ?></button>
            </form>
        </div>

        <form method="post" action="/?page=admin" class="stack compact-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="team_settings">
            <input type="hidden" name="team_id" value="<?= (int) $team['id'] ?>">
            <label><?= e(t('team.name')) ?><input type="text" name="name" value="<?= e((string) ($team['name'] ?? '')) ?>"></label>
            <label><?= e(t('team.description')) ?><textarea name="description" rows="3"><?= e((string) ($team['description'] ?? '')) ?></textarea></label>
            <div class="grid-inline two">
                <label><?= e(t('team.join_mode')) ?><select name="join_mode"><option value="open" <?= ($team['join_mode'] ?? 'closed') === 'open' ? 'selected' : '' ?>><?= e(t('team.open')) ?></option><option value="request" <?= ($team['join_mode'] ?? 'closed') === 'request' ? 'selected' : '' ?>><?= e(t('team.request')) ?></option><option value="closed" <?= ($team['join_mode'] ?? 'closed') === 'closed' ? 'selected' : '' ?>><?= e(t('team.closed')) ?></option></select></label>
                <label><?= e(t('team.visibility')) ?><select name="visibility"><option value="visible" <?= ($team['visibility'] ?? 'visible') === 'visible' ? 'selected' : '' ?>><?= e(t('team.visible')) ?></option><option value="hidden" <?= ($team['visibility'] ?? 'visible') === 'hidden' ? 'selected' : '' ?>><?= e(t('team.hidden')) ?></option></select></label>
            </div>
            <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
        </form>

        <form method="post" action="/?page=admin" class="stack admin-challenge-archive">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="archive_challenge">
            <div>
                <h3><?= e(t('admin.archive_challenge')) ?></h3>
                <p class="muted small"><?= e(t('admin.challenge_archive_hint')) ?></p>
            </div>
            <label><?= e(t('admin.archive_confirm')) ?><input type="text" name="confirm_archive" placeholder="ARCHIVE"></label>
            <button class="btn btn-ghost" type="submit"><?= e(t('admin.archive_challenge')) ?></button>
        </form>

        <?php if (!empty($challengeArchives)): ?>
            <div class="admin-archived-challenges">
                <h3><?= e(t('admin.archived_challenges')) ?></h3>
                <ul class="card-list">
                    <?php foreach ($challengeArchives as $archive): ?>
                        <li class="mini-card">
                            <div>
                                <strong><?= e((string) ($archive['challenge_name'] ?? '')) ?></strong>
                                <p class="muted small">
                                    <?= e((string) ($archive['challenge_start'] ?? '')) ?> – <?= e((string) ($archive['challenge_end'] ?? '')) ?>
                                    · <?= e(t('admin.archived_on', ['date' => (string) ($archive['archived_at'] ?? '')])) ?>
                                </p>
                            </div>
                            <div class="inline-actions-mini">
                                <a class="btn btn-ghost small" href="/?<?= e(http_build_query(['page' => 'profile', 'challenge' => 'archive:' . (int) $archive['id']])) ?>"><?= e(t('profile.open_challenge')) ?></a>
                                <form method="post" action="/?page=admin" class="inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="reactivate_challenge">
                                    <input type="hidden" name="archive_id" value="<?= (int) $archive['id'] ?>">
                                    <button class="btn btn-ghost small" type="submit"><?= e(t('admin.reactivate_challenge')) ?></button>
                                </form>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </article>

    <article class="panel settings-panel<?= $activeSection === 'app' ? ' active' : '' ?>" data-spa-section="app" <?= $activeSection === 'app' ? '' : 'hidden' ?>>
        <div class="panel-head">
            <div>
                <h2><?= e(t('admin.app_settings')) ?></h2>
                <p class="muted admin-section-help"><?= e(t('admin.app_help')) ?></p>
            </div>
            <a class="btn btn-ghost" href="/?page=admin" data-spa-back aria-label="<?= e(t('common.back')) ?>">← <?= e(t('common.back')) ?></a>
        </div>

        <section class="admin-subsection">
            <h3><?= e(t('admin.app_branding')) ?></h3>
            <p class="muted small"><?= e(t('admin.app_branding_help')) ?></p>
            <form method="post" action="/?page=admin" class="stack compact-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="update_app_name">
                <label><?= e(t('admin.app_name')) ?><input type="text" name="app_name" value="<?= e((string) ($appNameSetting ?? 'Fitness Challenge Tracker')) ?>" required></label>
                <span class="muted small"><?= e(t('admin.app_name_help')) ?></span>
                <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
            </form>
        </section>

        <section class="admin-subsection">
            <h3><?= e(t('admin.app_features')) ?></h3>
            <p class="muted small"><?= e(t('admin.app_features_help')) ?></p>
            <form method="post" action="/?page=admin" class="stack compact-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="update_penalties_feature">
                <div class="toggle-row">
                    <label class="check standalone-check">
                        <input type="checkbox" name="penalties_enabled" value="1" <?= !empty($penaltiesEnabled) ? 'checked' : '' ?>>
                        <?= e(t('admin.penalties_enabled')) ?>
                    </label>
                    <p class="muted small"><?= e(t('admin.penalties_feature_hint')) ?></p>
                </div>
                <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
            </form>
        </section>
    </article>

    <article class="panel settings-panel<?= $activeSection === 'notion' ? ' active' : '' ?>" data-spa-section="notion" <?= $activeSection === 'notion' ? '' : 'hidden' ?>>
        <div class="panel-head">
            <h2>Notion</h2>
            <a class="btn btn-ghost" href="/?page=admin" data-spa-back aria-label="<?= e(t('common.back')) ?>">← <?= e(t('common.back')) ?></a>
        </div>
        <?php
        $notion = is_array($notionSettings ?? null) ? (array) $notionSettings : [];
        $notionConfigured = ($notion['token'] ?? '') !== '' && ($notion['database_id'] ?? '') !== '';
        ?>
        <?php
        $nStatus = is_array($notionStatus ?? null) ? (array) $notionStatus : [];
        $nCounts = (array) ($nStatus['counts'] ?? []);
        $nError = trim((string) ($nStatus['error'] ?? ''));
        $nLast = trim((string) ($nStatus['last_sync_at'] ?? ''));
        $nState = $nError !== '' ? 'error' : ($nLast !== '' ? 'ok' : 'never');
        ?>
        <section class="notion-status-card is-<?= e($nState) ?>">
            <div class="notion-status-head">
                <span class="notion-status-dot" aria-hidden="true"></span>
                <div>
                    <strong><?= e(t('admin.notion_status_' . $nState)) ?></strong>
                    <span class="muted small">
                        <?php if ($nLast !== ''): ?>
                            <?= e(t('admin.notion_last_sync')) ?>: <?= e(human_time_ago($nLast)) ?>
                        <?php else: ?>
                            <?= e(t('admin.notion_never_synced')) ?>
                        <?php endif; ?>
                        &middot; <?= e(t('admin.notion_direction')) ?>: <?= e((string) ($notionSettings['direction'] ?? 'push')) ?>
                    </span>
                </div>
                <form method="post" action="/?page=admin" class="inline-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="notion_sync_now">
                    <button class="btn btn-primary small" type="submit"><?= e(t('admin.notion_sync_now')) ?></button>
                </form>
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

        <div class="admin-notion-panel">
            <h3><?= e(t('admin.notion_title')) ?></h3>
            <p class="muted small"><?= e(t('admin.notion_hint')) ?></p>

            <?php
            $notionOauthConfigured = ($notion['oauth_client_id'] ?? '') !== '' && ($notion['oauth_client_secret'] ?? '') !== '';
            $notionBaseUrl = (string) ($notion['base_url'] ?? '');
            $notionRedirectUri = $notionBaseUrl !== '' ? rtrim($notionBaseUrl, '/') . '/?page=notion_oauth_callback' : '';
            $notionConnected = ($notion['token'] ?? '') !== '';
            ?>
            <div class="admin-notion-oauth">
                <h4><?= e(t('admin.notion_oauth_title')) ?></h4>
                <?php if ($notionConnected && ($notion['workspace_name'] ?? '') !== ''): ?>
                    <p class="small">✓ <?= e(t('admin.notion_oauth_connected', ['workspace' => (string) $notion['workspace_name']])) ?></p>
                    <form method="post" action="/?page=admin" class="stack compact-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="notion_oauth_disconnect">
                        <button class="btn btn-ghost small" type="submit"><?= e(t('admin.notion_oauth_disconnect')) ?></button>
                    </form>
                <?php else: ?>
                    <p class="muted small"><?= e(t('admin.notion_oauth_hint')) ?></p>
                    <?php if ($notionRedirectUri !== ''): ?>
                        <p class="muted small"><?= e(t('admin.notion_oauth_redirect')) ?>: <code><?= e($notionRedirectUri) ?></code></p>
                    <?php else: ?>
                        <p class="muted small"><?= e(t('admin.notion_oauth_need_base_url')) ?></p>
                    <?php endif; ?>
                    <?php if ($notionOauthConfigured && $notionRedirectUri !== ''): ?>
                        <form method="post" action="/?page=admin" class="stack compact-form">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="notion_oauth_start">
                            <button class="btn btn-primary" type="submit"><?= e(t('admin.notion_oauth_connect')) ?></button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
                <form method="post" action="/?page=admin" class="stack compact-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="update_notion_settings">
                    <label>
                        <?= e(t('admin.notion_oauth_client_id')) ?>
                        <input type="text" name="notion_oauth_client_id" value="<?= e((string) ($notion['oauth_client_id'] ?? '')) ?>" placeholder="client id">
                    </label>
                    <label>
                        <?= e(t('admin.notion_oauth_client_secret')) ?>
                        <input type="password" name="notion_oauth_client_secret" autocomplete="off" placeholder="<?= ($notion['oauth_client_secret'] ?? '') !== '' ? '•••••••• (guardado)' : 'client secret' ?>">
                    </label>
                    <button class="btn btn-ghost small" type="submit"><?= e(t('common.save')) ?></button>
                </form>
            </div>

            <form method="post" action="/?page=admin" class="stack compact-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="update_notion_settings">
                <div class="toggle-row">
                    <label class="check standalone-check">
                        <input type="checkbox" name="notion_enabled" value="1" <?= !empty($notion['enabled']) ? 'checked' : '' ?>>
                        <?= e(t('admin.notion_enabled')) ?>
                    </label>
                </div>
                <div class="toggle-row">
                    <label class="check standalone-check">
                        <input type="checkbox" name="notion_external_sync" value="1" <?= !empty($notion['external']) ? 'checked' : '' ?>>
                        <?= e(t('admin.notion_external')) ?>
                    </label>
                    <p class="muted small"><?= e(t('admin.notion_external_hint')) ?></p>
                </div>
                <label>
                    <?= e(t('admin.notion_token')) ?>
                    <input type="password" name="notion_token" autocomplete="off" placeholder="<?= $notionConfigured ? '••••••••' : 'secret_...' ?>">
                    <span class="muted small"><?= e(t('admin.notion_token_hint')) ?></span>
                </label>
                <label>
                    <?= e(t('admin.notion_database')) ?>
                    <input type="text" name="notion_database_id" value="<?= e((string) ($notion['database_id'] ?? '')) ?>" placeholder="database id">
                </label>
                <label>
                    <?= e(t('admin.notion_direction')) ?>
                    <select name="notion_sync_direction">
                        <option value="push_only" <?= ($notion['direction'] ?? 'push_only') === 'push_only' ? 'selected' : '' ?>><?= e(t('admin.notion_dir_push')) ?></option>
                        <option value="two_way" <?= ($notion['direction'] ?? 'push_only') === 'two_way' ? 'selected' : '' ?>><?= e(t('admin.notion_dir_two_way')) ?></option>
                    </select>
                    <span class="muted small"><?= e(t('admin.notion_direction_hint')) ?></span>
                </label>
                <label>
                    <?= e(t('admin.notion_frequency')) ?>
                    <select name="notion_sync_frequency">
                        <option value="off" <?= ($notion['frequency'] ?? 'off') === 'off' ? 'selected' : '' ?>><?= e(t('admin.notion_freq_off')) ?></option>
                        <option value="daily" <?= ($notion['frequency'] ?? 'off') === 'daily' ? 'selected' : '' ?>><?= e(t('admin.notion_freq_daily')) ?></option>
                    </select>
                </label>
                <label>
                    <?= e(t('admin.notion_run_time')) ?>
                    <input type="time" name="notion_sync_run_time" value="<?= e((string) ($notion['run_time'] ?? '03:00')) ?>">
                </label>
                <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
            </form>

            <div class="admin-notion-create">
                <h4><?= e(t('admin.notion_create_title')) ?></h4>
                <p class="muted small"><?= e(t('admin.notion_create_hint')) ?></p>
                <form method="post" action="/?page=admin" class="stack compact-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="notion_create_database">
                    <label>
                        <?= e(t('admin.notion_parent_page')) ?>
                        <input type="text" name="notion_parent_page_id" value="<?= e((string) ($notion['parent_page_id'] ?? '')) ?>" placeholder="parent page id">
                    </label>
                    <button class="btn btn-ghost small" type="submit"><?= e(t('admin.notion_create_button')) ?></button>
                </form>
            </div>
            <?php
            $notionFieldLabels = is_array($notionFieldLabels ?? null) ? (array) $notionFieldLabels : [];
            $notionFieldMap = is_array($notionFieldMap ?? null) ? (array) $notionFieldMap : [];
            $notionSchemaCache = is_array($notionSchemaCache ?? null) ? (array) $notionSchemaCache : [];
            $notionSchemaNames = array_keys($notionSchemaCache);
            ?>
            <div class="admin-notion-mapping">
                <h4><?= e(t('admin.notion_mapping_title')) ?></h4>
                <p class="muted small"><?= e(t('admin.notion_mapping_hint')) ?></p>
                <form method="post" action="/?page=admin" class="stack compact-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="notion_load_schema">
                    <div class="inline-actions">
                        <button class="btn btn-ghost small" type="submit" <?= $notionConfigured ? '' : 'disabled' ?>><?= e(t('admin.notion_load_schema')) ?></button>
                        <?php if ($notionSchemaNames !== []): ?>
                            <span class="muted small"><?= e(t('admin.notion_schema_count', ['count' => count($notionSchemaNames)])) ?></span>
                        <?php endif; ?>
                    </div>
                </form>
                <form method="post" action="/?page=admin" class="stack compact-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="update_notion_field_map">
                    <datalist id="notion-property-options">
                        <?php foreach ($notionSchemaNames as $propName): ?>
                            <option value="<?= e((string) $propName) ?>"><?= e((string) $propName . ' (' . (string) ($notionSchemaCache[$propName] ?? '') . ')') ?></option>
                        <?php endforeach; ?>
                    </datalist>
                    <div class="notion-map-grid">
                        <?php foreach ($notionFieldLabels as $fieldKey => $fieldLabel): ?>
                            <label class="notion-map-row">
                                <span><?= e((string) $fieldLabel) ?></span>
                                <input type="text" name="notion_map[<?= e((string) $fieldKey) ?>]" list="notion-property-options" value="<?= e((string) ($notionFieldMap[$fieldKey] ?? '')) ?>" placeholder="<?= e(t('admin.notion_map_placeholder')) ?>">
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
                </form>
            </div>

            <form method="post" action="/?page=admin" class="stack compact-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="notion_sync_now">
                <button class="btn btn-ghost" type="submit" <?= $notionConfigured ? '' : 'disabled' ?>><?= e(t('admin.notion_sync_now')) ?></button>
                <?php if (($notion['last_status'] ?? '') !== ''): ?>
                    <p class="muted small">
                        <?= e(t('admin.notion_last_run')) ?>:
                        <?= e((string) ($notion['last_status'] ?? '')) ?>
                        <?php if (($notion['last_sync_at'] ?? '') !== ''): ?>· <?= e(format_date_eu((string) $notion['last_sync_at'])) ?><?php endif; ?>
                    </p>
                    <?php if (($notion['last_summary'] ?? '') !== ''): ?>
                        <p class="muted small"><?= e((string) $notion['last_summary']) ?></p>
                    <?php endif; ?>
                <?php endif; ?>
            </form>
        </div>

    </article>

    <article class="panel settings-panel<?= $activeSection === 'telegram' ? ' active' : '' ?>" data-spa-section="telegram" <?= $activeSection === 'telegram' ? '' : 'hidden' ?>>
        <div class="panel-head">
            <h2>Telegram</h2>
            <a class="btn btn-ghost" href="/?page=admin" data-spa-back aria-label="<?= e(t('common.back')) ?>">← <?= e(t('common.back')) ?></a>
        </div>
        <?php
        $telegram = is_array($telegramSettings ?? null) ? (array) $telegramSettings : [];
        $telegramConfigured = ($telegram['token'] ?? '') !== '';
        ?>
        <div class="admin-telegram-panel">
            <h3><?= e(t('admin.telegram_title')) ?></h3>
            <p class="muted small"><?= e(t('admin.telegram_hint')) ?></p>
            <form method="post" action="/?page=admin" class="stack compact-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="update_telegram_settings">
                <div class="toggle-row">
                    <label class="check standalone-check">
                        <input type="checkbox" name="telegram_enabled" value="1" <?= !empty($telegram['enabled']) ? 'checked' : '' ?>>
                        <?= e(t('admin.telegram_enabled')) ?>
                    </label>
                </div>
                <label>
                    <?= e(t('admin.telegram_token')) ?>
                    <?php if ($telegramConfigured): ?><span class="muted small">✓ <?= e(t('admin.telegram_token_saved')) ?></span><?php endif; ?>
                    <input type="password" name="telegram_bot_token" autocomplete="off" placeholder="<?= $telegramConfigured ? '•••••••• (guardado)' : '123456:ABC...' ?>">
                    <span class="muted small"><?= e(t('admin.telegram_token_hint')) ?></span>
                </label>
                <label>
                    <?= e(t('admin.telegram_username')) ?>
                    <input type="text" name="telegram_bot_username" value="<?= e((string) ($telegram['username'] ?? '')) ?>" placeholder="my_fitness_bot">
                </label>
                <label>
                    <?= e(t('admin.telegram_base_url')) ?>
                    <input type="text" name="app_base_url" value="<?= e((string) ($telegram['base_url'] ?? '')) ?>" placeholder="http://localhost:8080">
                    <span class="muted small"><?= e(t('admin.telegram_base_url_hint')) ?></span>
                </label>
                <div class="toggle-row">
                    <label class="check standalone-check">
                        <input type="checkbox" name="telegram_external_bot" value="1" <?= !empty($telegram['external_bot']) ? 'checked' : '' ?>>
                        <?= e(t('admin.telegram_external')) ?>
                    </label>
                    <p class="muted small"><?= e(t('admin.telegram_external_hint')) ?></p>
                </div>
                <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
            </form>
            <form method="post" action="/?page=admin" class="stack compact-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="telegram_verify_bot">
                <button class="btn btn-ghost small" type="submit" <?= $telegramConfigured ? '' : 'disabled' ?>><?= e(t('admin.telegram_verify')) ?></button>
                <?php if (($telegram['username'] ?? '') !== ''): ?>
                    <span class="muted small">@<?= e((string) $telegram['username']) ?></span>
                <?php endif; ?>
            </form>

            <?php $telegramLinkedUsers = is_array($telegramLinkedUsers ?? null) ? (array) $telegramLinkedUsers : []; ?>
            <div class="admin-telegram-linked">
                <h4><?= e(t('admin.telegram_linked_title')) ?></h4>
                <?php if ($telegramLinkedUsers === []): ?>
                    <p class="muted small"><?= e(t('admin.telegram_no_linked')) ?></p>
                <?php else: ?>
                    <ul class="admin-telegram-user-list">
                        <?php foreach ($telegramLinkedUsers as $linkedUser): ?>
                            <li class="admin-telegram-user-row">
                                <span>
                                    <strong><?= e((string) ($linkedUser['display_name'] ?? $linkedUser['username'] ?? '')) ?></strong>
                                    <span class="muted small">
                                        · <?= e((string) ($linkedUser['telegram_reminder_time'] ?? '')) ?>
                                        <?= (int) ($linkedUser['telegram_reminders_enabled'] ?? 0) === 1 ? '🔔' : '' ?>
                                        <?= (int) ($linkedUser['telegram_motivation_enabled'] ?? 0) === 1 ? '💪' : '' ?>
                                    </span>
                                </span>
                                <form method="post" action="/?page=admin" class="inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="telegram_admin_unlink">
                                    <input type="hidden" name="user_id" value="<?= (int) ($linkedUser['id'] ?? 0) ?>">
                                    <button class="btn btn-ghost small" type="submit"><?= e(t('admin.telegram_unlink_user')) ?></button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </article>

    <article class="panel settings-panel<?= $activeSection === 'app' ? ' active' : '' ?>" data-spa-section="app" <?= $activeSection === 'app' ? '' : 'hidden' ?>>
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

    <article class="panel settings-panel<?= $activeSection === 'backups' ? ' active' : '' ?>" data-spa-section="backups" <?= $activeSection === 'backups' ? '' : 'hidden' ?>>
        <div class="panel-head">
            <h2><?= e(t('admin.backups_title')) ?></h2>
            <a class="btn btn-ghost" href="/?page=admin" data-spa-back aria-label="<?= e(t('common.back')) ?>">← <?= e(t('common.back')) ?></a>
        </div>
        <p class="muted"><?= e(t('admin.backups_subtitle')) ?></p>

        <form method="post" action="/?page=admin" class="stack compact-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_backup_settings">
            <label class="check standalone-check">
                <input type="checkbox" name="backup_auto_enabled" value="1" <?= $backupAutoEnabled ? 'checked' : '' ?>>
                <?= e(t('admin.backup_auto_enabled')) ?>
            </label>
            <div class="grid-inline three backup-settings-grid">
                <label>
                    <?= e(t('admin.backup_frequency')) ?>
                    <select name="backup_frequency">
                        <option value="daily" <?= $backupFrequency === 'daily' ? 'selected' : '' ?>><?= e(t('admin.backup_frequency_daily')) ?></option>
                        <option value="weekly" <?= $backupFrequency === 'weekly' ? 'selected' : '' ?>><?= e(t('admin.backup_frequency_weekly')) ?></option>
                        <option value="monthly" <?= $backupFrequency === 'monthly' ? 'selected' : '' ?>><?= e(t('admin.backup_frequency_monthly')) ?></option>
                    </select>
                </label>
                <label>
                    <?= e(t('admin.backup_retention_count')) ?>
                    <input type="number" name="backup_retention_count" min="1" max="200" value="<?= (int) $backupRetentionCount ?>" required>
                </label>
                <label>
                    <?= e(t('admin.backup_run_time')) ?>
                    <input type="time" name="backup_run_time" value="<?= e($backupRunTime) ?>" required>
                </label>
            </div>
            <p class="muted small"><?= e(t('admin.backup_last_auto', ['value' => $backupLastAutoLabel])) ?></p>
            <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
        </form>

        <form method="post" action="/?page=admin">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="create_backup_now">
            <button class="btn btn-secondary" type="submit"><?= e(t('admin.backup_create_now')) ?></button>
        </form>

        <form method="post" action="/?page=admin" class="admin-thumbnail-regenerate">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="regenerate_photo_thumbnails">
            <div>
                <strong><?= e(t('admin.photo_thumbnails_title')) ?></strong>
                <p class="muted small"><?= e(t('admin.photo_thumbnails_subtitle')) ?></p>
            </div>
            <button class="btn btn-ghost" type="submit"><?= e(t('admin.photo_thumbnails_regenerate')) ?></button>
        </form>

        <?php if (($systemBackups ?? []) === []): ?>
            <p class="muted"><?= e(t('admin.backup_empty')) ?></p>
        <?php else: ?>
            <div class="card-list admin-backup-list">
                <?php foreach ((array) $systemBackups as $backup): ?>
                    <?php
                    $backupId = (int) ($backup['id'] ?? 0);
                    if ($backupId <= 0) {
                        continue;
                    }
                    $backupFilePath = trim((string) ($backup['file_path'] ?? ''));
                    $backupFileName = $backupFilePath !== '' ? basename($backupFilePath) : ('backup_' . $backupId . '.zip');
                    $backupCreatedAtRaw = trim((string) ($backup['created_at'] ?? ''));
                    $backupCreatedAtLabel = $backupCreatedAtRaw;
                    if ($backupCreatedAtRaw !== '') {
                        try {
                            $backupDate = new DateTimeImmutable($backupCreatedAtRaw);
                            $backupCreatedAtLabel = format_date_eu($backupDate->format('Y-m-d')) . ' ' . $backupDate->format('H:i');
                        } catch (Throwable) {
                            $backupCreatedAtLabel = $backupCreatedAtRaw;
                        }
                    }
                    $backupTrigger = trim((string) ($backup['trigger_type'] ?? 'manual'));
                    $backupStatus = trim((string) ($backup['status'] ?? 'created'));
                    $backupSizeLabel = trim((string) ($backup['size_label'] ?? ''));
                    $backupExists = (int) ($backup['file_exists'] ?? 0) === 1;
                    $restorePlaceholder = 'RESTORE';
                    ?>
                    <article class="mini-card admin-backup-item<?= $backupExists ? '' : ' is-missing' ?>">
                        <div class="admin-backup-meta">
                            <strong><?= e($backupFileName) ?></strong>
                            <span><?= e(t('admin.backup_created_at')) ?>: <?= e($backupCreatedAtLabel) ?></span>
                            <span><?= e(t('admin.backup_trigger')) ?>: <?= e($backupTriggerLabel($backupTrigger)) ?></span>
                            <span><?= e(t('admin.backup_size')) ?>: <?= e($backupSizeLabel !== '' ? $backupSizeLabel : '0 B') ?></span>
                            <span><?= e(t('admin.backup_status')) ?>: <?= e($backupStatusLabel($backupStatus)) ?></span>
                            <?php if (!$backupExists): ?>
                                <small class="muted"><?= e(t('admin.backup_missing_file')) ?></small>
                            <?php endif; ?>
                        </div>
                        <div class="admin-backup-actions">
                            <form method="post" action="/?page=admin">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="download_backup">
                                <input type="hidden" name="backup_id" value="<?= $backupId ?>">
                                <button class="btn btn-ghost small" type="submit"<?= $backupExists ? '' : ' disabled' ?>><?= e(t('admin.backup_download')) ?></button>
                            </form>
                            <form method="post" action="/?page=admin" onsubmit="return window.confirm('<?= e(t('admin.backup_delete_confirm')) ?>');">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete_backup">
                                <input type="hidden" name="backup_id" value="<?= $backupId ?>">
                                <button class="btn btn-ghost small photo-delete-text-btn" type="submit"><?= e(t('common.delete')) ?></button>
                            </form>
                            <form method="post" action="/?page=admin" class="admin-backup-restore-form">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="restore_backup">
                                <input type="hidden" name="backup_id" value="<?= $backupId ?>">
                                <label>
                                    <?= e(t('admin.backup_restore_confirm_label')) ?>
                                    <input type="text" name="confirm_restore" placeholder="<?= e($restorePlaceholder) ?>" autocomplete="off" required>
                                </label>
                                <button class="btn btn-ghost small" type="submit" onclick="return window.confirm('<?= e(t('admin.backup_restore_confirm_dialog')) ?>');"<?= $backupExists ? '' : ' disabled' ?>><?= e(t('admin.backup_restore')) ?></button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>

    <article class="panel settings-panel<?= $activeSection === 'habits' ? ' active' : '' ?>" data-spa-section="habits" <?= $activeSection === 'habits' ? '' : 'hidden' ?>>
        <div class="panel-head admin-section-list" data-spa-show-when-no-param="habit_id" <?= $selectedHabitId !== '' ? 'hidden' : '' ?>>
            <div>
                <h2>Habits</h2>
                <p class="muted admin-section-help"><?= e(t('admin.habits_help')) ?></p>
            </div>
            <div class="inline-actions-mini">
                <a class="btn btn-primary small" href="/?page=admin&section=habits&habit_id=new" data-spa-link><?= e(t('common.create')) ?></a>
                <a class="btn btn-ghost" href="/?page=admin" data-spa-back aria-label="<?= e(t('common.back')) ?>">← <?= e(t('common.back')) ?></a>
            </div>
        </div>

        <div class="settings-list compact-list admin-section-list" data-spa-show-when-no-param="habit_id" <?= $selectedHabitId !== '' ? 'hidden' : '' ?>>
            <?php foreach (($habits ?? []) as $habit): ?>
                <a class="settings-row" href="/?page=admin&section=habits&habit_id=<?= (int) $habit['id'] ?>" data-spa-link>
                    <span><?= e((string) $habit['label']) ?></span>
                    <span class="settings-chevron" aria-hidden="true">›</span>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="stack admin-create-view" data-spa-param-show="habit_id" data-spa-value="new" <?= $selectedHabitId === 'new' ? '' : 'hidden' ?>>
            <div class="panel-head">
                <h3><?= e(t('admin.create_habit')) ?></h3>
                <a class="btn btn-ghost" href="/?page=admin&section=habits" data-spa-back aria-label="<?= e(t('common.back')) ?>">← <?= e(t('common.back')) ?></a>
            </div>
            <form method="post" action="/?page=admin" class="mini-card editable-card compact-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="save_habit">
                <input type="text" name="code" placeholder="custom_habit">
                <input type="text" name="label" placeholder="<?= e(t('admin.habit_label')) ?>">
                <input type="number" name="sort_order" value="50">
                <label class="check"><input type="checkbox" name="active" value="1" checked><?= e(t('common.active')) ?></label>
                <button class="btn small btn-primary" type="submit"><?= e(t('common.save')) ?></button>
            </form>
        </div>

        <?php foreach (($habits ?? []) as $habit): ?>
            <div class="stack admin-detail-view" data-spa-param-show="habit_id" data-spa-value="<?= (int) $habit['id'] ?>" <?= $selectedHabitId === (string) ((int) $habit['id']) ? '' : 'hidden' ?>>
                <div class="panel-head">
                    <h3><?= e((string) $habit['label']) ?></h3>
                    <a class="btn btn-ghost" href="/?page=admin&section=habits" data-spa-back aria-label="<?= e(t('common.back')) ?>">← <?= e(t('common.back')) ?></a>
                </div>
                <form method="post" action="/?page=admin" class="mini-card editable-card">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="save_habit">
                    <input type="hidden" name="habit_id" value="<?= (int) $habit['id'] ?>">
                    <input type="text" name="code" value="<?= e((string) $habit['code']) ?>">
                    <input type="text" name="label" value="<?= e((string) $habit['label']) ?>">
                    <input type="number" name="sort_order" value="<?= e((string) $habit['sort_order']) ?>">
                    <label class="check"><input type="checkbox" name="active" value="1" <?= (int) $habit['active'] === 1 ? 'checked' : '' ?>><?= e(t('common.active')) ?></label>
                    <button class="btn small btn-primary" type="submit"><?= e(t('common.save')) ?></button>
                </form>
                <form method="post" action="/?page=admin" onsubmit="return confirm('<?= e(t('admin.delete_habit_confirm')) ?>');">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete_habit">
                    <input type="hidden" name="habit_id" value="<?= (int) $habit['id'] ?>">
                    <button class="btn small btn-ghost" type="submit"><?= e(t('common.delete')) ?></button>
                </form>
            </div>
        <?php endforeach; ?>
    </article>

    <article class="panel settings-panel<?= $activeSection === 'workout_types' ? ' active' : '' ?>" data-spa-section="workout_types" <?= $activeSection === 'workout_types' ? '' : 'hidden' ?>>
        <div class="panel-head admin-section-list" data-spa-show-when-no-param="type_id" <?= $selectedTypeId !== '' ? 'hidden' : '' ?>>
            <div>
                <h2>Workout Types</h2>
                <p class="muted admin-section-help"><?= e(t('admin.workout_types_help')) ?></p>
            </div>
            <div class="inline-actions-mini">
                <a class="btn btn-primary small" href="/?page=admin&section=workout_types&type_id=new" data-spa-link><?= e(t('common.create')) ?></a>
                <a class="btn btn-ghost" href="/?page=admin" data-spa-back aria-label="<?= e(t('common.back')) ?>">← <?= e(t('common.back')) ?></a>
            </div>
        </div>

        <div class="settings-list compact-list admin-section-list" data-spa-show-when-no-param="type_id" <?= $selectedTypeId !== '' ? 'hidden' : '' ?>>
            <?php foreach (($workoutTypes ?? []) as $type): ?>
                <a class="settings-row" href="/?page=admin&section=workout_types&type_id=<?= (int) $type['id'] ?>" data-spa-link>
                    <span><?= e((string) $type['name']) ?></span>
                    <span class="settings-chevron" aria-hidden="true">›</span>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="stack admin-create-view" data-spa-param-show="type_id" data-spa-value="new" <?= $selectedTypeId === 'new' ? '' : 'hidden' ?>>
            <div class="panel-head">
                <h3><?= e(t('admin.create_workout_type')) ?></h3>
                <a class="btn btn-ghost" href="/?page=admin&section=workout_types" data-spa-back aria-label="<?= e(t('common.back')) ?>">← <?= e(t('common.back')) ?></a>
            </div>
            <form method="post" action="/?page=admin" class="mini-card editable-card">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="create_workout_type">
                <input type="text" name="name" placeholder="<?= e(t('entries.workout_type')) ?>" required>
                <button class="btn small btn-primary" type="submit"><?= e(t('common.save')) ?></button>
            </form>
        </div>

        <?php foreach (($workoutTypes ?? []) as $type): ?>
            <div class="stack admin-detail-view" data-spa-param-show="type_id" data-spa-value="<?= (int) $type['id'] ?>" <?= $selectedTypeId === (string) ((int) $type['id']) ? '' : 'hidden' ?>>
                <div class="panel-head">
                    <h3><?= e((string) $type['name']) ?></h3>
                    <a class="btn btn-ghost" href="/?page=admin&section=workout_types" data-spa-back aria-label="<?= e(t('common.back')) ?>">← <?= e(t('common.back')) ?></a>
                </div>
                <form method="post" action="/?page=admin" class="mini-card editable-card">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="update_workout_type">
                    <input type="hidden" name="type_id" value="<?= (int) $type['id'] ?>">
                    <input type="text" name="name" value="<?= e((string) $type['name']) ?>">
                    <label class="check"><input type="checkbox" name="active" value="1" <?= (int) $type['active'] === 1 ? 'checked' : '' ?>><?= e(t('common.active')) ?></label>
                    <button class="btn small btn-primary" type="submit"><?= e(t('common.save')) ?></button>
                </form>
                <section class="admin-workout-fields">
                    <div class="panel-head compact-head">
                        <div>
                            <h4><?= e(t('workout_fields.title')) ?></h4>
                            <p class="muted small"><?= e(t('workout_fields.help')) ?></p>
                        </div>
                    </div>
                    <div class="card-list compact-list">
                        <?php foreach ((array) ($workoutTypeFieldsByType[(int) $type['id']] ?? []) as $field): ?>
                            <form method="post" action="/?page=admin" class="mini-card editable-card workout-field-card">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="save_workout_type_field">
                                <input type="hidden" name="type_id" value="<?= (int) $type['id'] ?>">
                                <input type="hidden" name="field_id" value="<?= (int) $field['id'] ?>">
                                <label>
                                    <?= e(t('workout_fields.label')) ?>
                                    <input type="text" name="label" value="<?= e((string) $field['label']) ?>" required>
                                </label>
                                <label>
                                    <?= e(t('workout_fields.input_kind')) ?>
                                    <select name="input_kind">
                                        <option value="number" <?= (string) ($field['input_kind'] ?? 'number') === 'number' ? 'selected' : '' ?>><?= e(t('workout_fields.kind_number')) ?></option>
                                        <option value="text" <?= (string) ($field['input_kind'] ?? 'number') === 'text' ? 'selected' : '' ?>><?= e(t('workout_fields.kind_text')) ?></option>
                                    </select>
                                </label>
                                <label>
                                    <?= e(t('workout_fields.data_key')) ?>
                                    <select name="data_key">
                                        <?php foreach ($workoutFieldDataKeyLabels as $fieldKey => $fieldLabel): ?>
                                            <option value="<?= e((string) $fieldKey) ?>" <?= normalize_workout_field_data_key($field['data_key'] ?? '') === (string) $fieldKey ? 'selected' : '' ?>><?= e((string) $fieldLabel) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>
                                    <?= e(t('common.order')) ?>
                                    <input type="number" name="sort_order" value="<?= (int) ($field['sort_order'] ?? 0) ?>" min="0">
                                </label>
                                <label class="check"><input type="checkbox" name="required" value="1" <?= (int) ($field['required'] ?? 0) === 1 ? 'checked' : '' ?>><?= e(t('workout_fields.required')) ?></label>
                                <label class="check"><input type="checkbox" name="active" value="1" <?= (int) ($field['active'] ?? 1) === 1 ? 'checked' : '' ?>><?= e(t('common.active')) ?></label>
                                <button class="btn small btn-primary" type="submit"><?= e(t('common.save')) ?></button>
                            </form>
                            <form method="post" action="/?page=admin" onsubmit="return confirm('<?= e(t('workout_fields.delete_confirm')) ?>');">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete_workout_type_field">
                                <input type="hidden" name="type_id" value="<?= (int) $type['id'] ?>">
                                <input type="hidden" name="field_id" value="<?= (int) $field['id'] ?>">
                                <button class="btn small btn-ghost" type="submit"><?= e(t('common.delete')) ?></button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                    <form method="post" action="/?page=admin" class="mini-card editable-card workout-field-card">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="save_workout_type_field">
                        <input type="hidden" name="type_id" value="<?= (int) $type['id'] ?>">
                        <label>
                            <?= e(t('workout_fields.label')) ?>
                            <input type="text" name="label" placeholder="<?= e(t('workout_fields.label_placeholder')) ?>" required>
                        </label>
                        <label>
                            <?= e(t('workout_fields.input_kind')) ?>
                            <select name="input_kind">
                                <option value="number"><?= e(t('workout_fields.kind_number')) ?></option>
                                <option value="text"><?= e(t('workout_fields.kind_text')) ?></option>
                            </select>
                        </label>
                        <label>
                            <?= e(t('workout_fields.data_key')) ?>
                            <select name="data_key">
                                <?php foreach ($workoutFieldDataKeyLabels as $fieldKey => $fieldLabel): ?>
                                    <option value="<?= e((string) $fieldKey) ?>"><?= e((string) $fieldLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <?= e(t('common.order')) ?>
                            <input type="number" name="sort_order" value="<?= count((array) ($workoutTypeFieldsByType[(int) $type['id']] ?? [])) + 1 ?>" min="0">
                        </label>
                        <label class="check"><input type="checkbox" name="required" value="1"><?= e(t('workout_fields.required')) ?></label>
                        <input type="hidden" name="active" value="1">
                        <button class="btn small btn-secondary" type="submit"><?= e(t('workout_fields.add')) ?></button>
                    </form>
                </section>
                <form method="post" action="/?page=admin" onsubmit="return confirm('<?= e(t('admin.delete_workout_type_confirm')) ?>');">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete_workout_type">
                    <input type="hidden" name="type_id" value="<?= (int) $type['id'] ?>">
                    <button class="btn small btn-ghost" type="submit"><?= e(t('common.delete')) ?></button>
                </form>
            </div>
        <?php endforeach; ?>
    </article>

    <article class="panel settings-panel<?= $activeSection === 'training' ? ' active' : '' ?>" data-spa-section="training" <?= $activeSection === 'training' ? '' : 'hidden' ?>>
        <div class="panel-head admin-section-list" data-spa-show-when-no-param="exercise_id" <?= $selectedTrainingExerciseId !== '' ? 'hidden' : '' ?>>
            <div>
                <p class="eyebrow">Training</p>
                <h2>Training &amp; ranked</h2>
                <p class="muted admin-section-help">Manage strength tiers, seasons, exercise guides and example media in one place.</p>
            </div>
            <a class="btn btn-ghost" href="/?page=admin" data-spa-back aria-label="<?= e(t('common.back')) ?>">&larr; <?= e(t('common.back')) ?></a>
        </div>

        <div class="stack-lg admin-section-list admin-training-dashboard" data-spa-show-when-no-param="exercise_id" <?= $selectedTrainingExerciseId !== '' ? 'hidden' : '' ?>>
            <details class="admin-training-block" open>
                <summary>
                    <span><strong>Rank tiers</strong><small>Thresholds and visual identity</small></span>
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

            <details class="admin-training-block">
                <summary>
                    <span><strong>Seasons</strong><small>Dates used by seasonal XP and leaderboards</small></span>
                    <span class="badge"><?= count((array) ($adminSeasons ?? [])) ?></span>
                </summary>
                <div class="stack admin-season-manager">
                    <form method="post" action="/?page=admin" class="mini-card editable-card admin-season-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="save_season">
                        <div>
                            <strong>Create season</strong>
                            <p class="muted small">Custom seasons take priority over the automatic quarterly season for their date range.</p>
                        </div>
                        <label>Key <input type="text" name="season_key" pattern="[A-Za-z0-9_-]+" placeholder="2026-summer" required></label>
                        <label>Name <input type="text" name="season_name" placeholder="Summer Strength" required></label>
                        <label>Starts <input type="date" name="start_date" value="<?= e(date('Y-m-d')) ?>" required></label>
                        <label>Ends <input type="date" name="end_date" value="<?= e(date('Y-m-d', strtotime('+3 months -1 day'))) ?>" required></label>
                        <button class="btn btn-secondary small" type="submit"><?= e(t('common.create')) ?></button>
                    </form>
                    <div class="stack admin-season-list">
                        <?php foreach ((array) ($adminSeasons ?? []) as $season): ?>
                            <?php $seasonIsCurrent = (string) ($season['start_date'] ?? '') <= date('Y-m-d') && (string) ($season['end_date'] ?? '') >= date('Y-m-d'); ?>
                            <details class="mini-card admin-season-item">
                                <summary>
                                    <span><strong><?= e((string) ($season['name'] ?? '')) ?></strong><small><?= e(format_date_eu((string) ($season['start_date'] ?? ''))) ?> &ndash; <?= e(format_date_eu((string) ($season['end_date'] ?? ''))) ?></small></span>
                                    <?= $seasonIsCurrent ? '<span class="badge badge-ok">Current</span>' : '' ?>
                                </summary>
                                <form method="post" action="/?page=admin" class="grid-inline compact-form admin-season-form">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="save_season">
                                    <input type="hidden" name="season_id" value="<?= (int) ($season['id'] ?? 0) ?>">
                                    <label>Key <input type="text" name="season_key" pattern="[A-Za-z0-9_-]+" value="<?= e((string) ($season['season_key'] ?? '')) ?>" required></label>
                                    <label>Name <input type="text" name="season_name" value="<?= e((string) ($season['name'] ?? '')) ?>" required></label>
                                    <label>Starts <input type="date" name="start_date" value="<?= e((string) ($season['start_date'] ?? '')) ?>" required></label>
                                    <label>Ends <input type="date" name="end_date" value="<?= e((string) ($season['end_date'] ?? '')) ?>" required></label>
                                    <button class="btn btn-primary small" type="submit"><?= e(t('common.save')) ?></button>
                                </form>
                                <form method="post" action="/?page=admin" class="admin-danger-zone" onsubmit="return confirm('Delete this season? XP history will remain, but this date window will no longer be used.');">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="delete_season">
                                    <input type="hidden" name="season_id" value="<?= (int) ($season['id'] ?? 0) ?>">
                                    <button class="btn btn-ghost small" type="submit"><?= e(t('common.delete')) ?></button>
                                </form>
                            </details>
                        <?php endforeach; ?>
                    </div>
                </div>
            </details>

            <details class="admin-training-block admin-training-exercises">
                <summary>
                    <span><strong>Exercise library</strong><small>Edit guides, ranking rules and example media</small></span>
                    <span class="badge"><?= count((array) ($adminTrainingExercises ?? [])) ?></span>
                </summary>
                <div class="admin-training-exercises-body">
                <div class="panel-head compact-head">
                    <div>
                        <p class="muted small">Choose an exercise or create a new guide with technique photos and video.</p>
                    </div>
                    <a class="btn btn-primary small" href="/?page=admin&section=training&exercise_id=new" data-spa-link><?= e(t('common.create')) ?></a>
                </div>
                <div class="settings-list compact-list admin-training-exercise-list">
                    <?php foreach ((array) ($adminTrainingExercises ?? []) as $exercise): ?>
                        <?php $exerciseImageUrl = trim((string) ($exercise['image_path'] ?? '')) !== '' ? media_url((string) $exercise['image_path']) : ''; ?>
                        <a class="settings-row admin-training-exercise-row" href="/?page=admin&section=training&exercise_id=<?= (int) ($exercise['id'] ?? 0) ?>" data-spa-link>
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

        <div class="stack admin-create-view" data-spa-param-show="exercise_id" data-spa-value="new" <?= $selectedTrainingExerciseId === 'new' ? '' : 'hidden' ?>>
            <div class="panel-head">
                <div><p class="eyebrow">Exercise library</p><h3>Create exercise</h3></div>
                <a class="btn btn-ghost" href="/?page=admin&section=training" data-spa-back aria-label="<?= e(t('common.back')) ?>">&larr; <?= e(t('common.back')) ?></a>
            </div>
            <?php
            $trainingExerciseFormItem = [];
            $trainingExerciseIsNew = true;
            require __DIR__ . '/partials/admin_training_exercise_form.php';
            ?>
        </div>

        <?php foreach ((array) ($adminTrainingExercises ?? []) as $exercise): ?>
            <div class="stack admin-detail-view" data-spa-param-show="exercise_id" data-spa-value="<?= (int) ($exercise['id'] ?? 0) ?>" <?= $selectedTrainingExerciseId === (string) ((int) ($exercise['id'] ?? 0)) ? '' : 'hidden' ?>>
                <div class="panel-head">
                    <div><p class="eyebrow">Exercise library</p><h3><?= e((string) ($exercise['name'] ?? '')) ?></h3></div>
                    <a class="btn btn-ghost" href="/?page=admin&section=training" data-spa-back aria-label="<?= e(t('common.back')) ?>">&larr; <?= e(t('common.back')) ?></a>
                </div>
                <?php
                $trainingExerciseFormItem = $exercise;
                $trainingExerciseIsNew = false;
                require __DIR__ . '/partials/admin_training_exercise_form.php';
                ?>
            </div>
        <?php endforeach; ?>
    </article>

    <article class="panel settings-panel<?= $activeSection === 'achievements' ? ' active' : '' ?>" data-spa-section="achievements" <?= $activeSection === 'achievements' ? '' : 'hidden' ?>>
        <div class="panel-head admin-section-list" data-spa-show-when-no-param="achievement_id" <?= $selectedAchievementId !== '' ? 'hidden' : '' ?>>
            <div>
                <h2>Achievements</h2>
                <p class="muted admin-section-help"><?= e(t('admin.achievements_help')) ?></p>
            </div>
            <div class="inline-actions-mini">
                <a class="btn btn-primary small" href="/?page=admin&section=achievements&achievement_id=new" data-spa-link><?= e(t('common.create')) ?></a>
                <a class="btn btn-ghost" href="/?page=admin" data-spa-back aria-label="<?= e(t('common.back')) ?>">← <?= e(t('common.back')) ?></a>
            </div>
        </div>

        <section class="stack admin-section-list" data-spa-show-when-no-param="achievement_id" <?= $selectedAchievementId !== '' ? 'hidden' : '' ?>>
            <?php if (is_array($selectedAdminAchievement)): ?>
                <article class="admin-achievement-spotlight">
                    <div class="admin-achievement-spotlight-main">
                        <?= achievement_visual_html($selectedAdminAchievement, 'achievement-visual admin-achievement-spotlight-visual') ?>
                        <div>
                            <p class="eyebrow"><?= e(t('admin.achievement_selected')) ?></p>
                            <h3><?= e((string) ($selectedAdminAchievement['name'] ?? '')) ?></h3>
                            <p class="muted small"><?= e((string) ($selectedAdminAchievement['description'] ?? '')) ?></p>
                        </div>
                    </div>
                    <div class="admin-achievement-stats">
                        <span><strong><?= (int) ($adminAchievementStats['unlocked'] ?? 0) ?></strong><?= e(t('admin.achievement_unlocked')) ?></span>
                        <span><strong><?= (int) ($adminAchievementStats['in_progress'] ?? 0) ?></strong><?= e(t('admin.achievement_in_progress')) ?></span>
                        <span><strong><?= (int) ($adminAchievementStats['locked'] ?? 0) ?></strong><?= e(t('admin.achievement_locked')) ?></span>
                        <span><strong><?= e((string) ($adminAchievementStats['avg_progress'] ?? 0)) ?>%</strong><?= e(t('admin.achievement_avg_progress')) ?></span>
                    </div>
                    <?php if (!empty($adminAchievementStats['recent_unlocks'])): ?>
                        <div class="admin-achievement-recent">
                            <strong><?= e(t('admin.recent_unlocks')) ?></strong>
                            <?php foreach (array_slice((array) $adminAchievementStats['recent_unlocks'], 0, 3) as $unlock): ?>
                                <span><?= e((string) ($unlock['owner'] ?? '')) ?> · <?= e(format_date_eu((string) ($unlock['awarded_at'] ?? ''))) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <a class="btn btn-ghost small" href="/?page=admin&section=achievements&achievement_id=<?= (int) ($selectedAdminAchievement['id'] ?? 0) ?>" data-spa-link><?= e(t('common.edit')) ?></a>
                </article>
            <?php endif; ?>

            <details class="admin-achievement-selector" <?= is_array($selectedAdminAchievement) ? '' : 'open' ?>>
                <summary><?= e(t('admin.achievement_select')) ?></summary>
                <div class="settings-list compact-list">
                <?php foreach (($adminAchievements ?? []) as $achievement): ?>
                    <?php
                    $triggerKey = trim((string) ($achievement['trigger_key'] ?? ''));
                    $conditionSummary = 'Manual';
                    if ($triggerKey !== '') {
                        if (!empty($achievement['rule_id'])) {
                            $conditionSummary = $triggerKey
                                . ' '
                                . (string) ($achievement['trigger_operator'] ?? '>=')
                                . ' '
                                . (string) ($achievement['trigger_target'] ?? '1')
                                . ' · '
                                . (string) ($achievement['trigger_window'] ?? 'total');
                        } else {
                            $conditionSummary = $triggerKey . ' · ' . t('admin.pending');
                        }
                    }
                    ?>
                    <a class="settings-row admin-achievement-row<?= (int) ($achievement['id'] ?? 0) === $selectedAdminAchievementId ? ' is-selected' : '' ?>" href="/?page=admin&section=achievements&achievement_id=<?= (int) $achievement['id'] ?>" data-spa-link>
                        <span>
                            <strong><?= e((string) $achievement['name']) ?></strong>
                            <small class="muted"><?= e((string) $achievement['scope']) ?> · <?= e($conditionSummary) ?></small>
                        </span>
                        <span class="badge badge-ok"><?= e(t('common.active')) ?></span>
                        <span class="settings-chevron" aria-hidden="true">›</span>
                    </a>
                <?php endforeach; ?>
                <?php if (($adminAchievements ?? []) === []): ?>
                    <p class="muted panel-inline-empty"><?= e(t('achievements.empty')) ?></p>
                <?php endif; ?>
                </div>
            </details>
        </section>

        <div class="stack admin-create-view" data-spa-param-show="achievement_id" data-spa-value="new" <?= $selectedAchievementId === 'new' ? '' : 'hidden' ?>>
            <div class="panel-head">
                <h3><?= e(t('achievements.create')) ?></h3>
                <a class="btn btn-ghost" href="/?page=admin&section=achievements" data-spa-back aria-label="<?= e(t('common.back')) ?>">← <?= e(t('common.back')) ?></a>
            </div>
            <form method="post" action="/?page=admin" enctype="multipart/form-data" class="stack compact-form" data-achievement-form>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="create_achievement">
                <div class="grid-inline">
                    <label>Code <input type="text" name="code" placeholder="my_achievement_code"></label>
                    <label><?= e(t('achievements.scope')) ?><select name="scope"><option value="user"><?= e(t('common.user')) ?></option><option value="team"><?= e(t('nav.team')) ?></option></select></label>
                </div>
                <fieldset class="achievement-visual-fieldset">
                    <legend><?= e(t('achievements.visual')) ?></legend>
                    <div class="achievement-icon-picker" role="radiogroup" aria-label="<?= e(t('achievements.icon')) ?>">
                        <?php foreach ($achievementIconOptions as $iconKey => $iconLabel): ?>
                            <label class="achievement-icon-option">
                                <input type="radio" name="icon_key" value="<?= e((string) $iconKey) ?>" <?= $iconKey === 'trophy' ? 'checked' : '' ?>>
                                <span class="achievement-icon-option-media"><?= achievement_icon_svg((string) $iconKey) ?></span>
                                <span><?= e((string) $iconLabel) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <label><?= e(t('achievements.custom_image')) ?><input type="file" name="image" accept="image/*"></label>
                </fieldset>
                <div class="achievement-translation-fields">
                    <?php foreach ($achievementLocales as $localeCode => $localeLabel): ?>
                        <fieldset class="achievement-translation-card">
                            <legend><?= e((string) $localeLabel) ?><?php if ($localeCode === 'en'): ?> <span class="badge">Base</span><?php endif; ?></legend>
                            <label><?= e(t('achievements.name')) ?><input type="text" name="translations[<?= e((string) $localeCode) ?>][name]" <?= $localeCode === 'en' ? 'required' : '' ?>></label>
                            <label><?= e(t('achievements.description')) ?><input type="text" name="translations[<?= e((string) $localeCode) ?>][description]"></label>
                            <label><?= e(t('achievements.reward')) ?><input type="text" name="translations[<?= e((string) $localeCode) ?>][reward_text]"></label>
                        </fieldset>
                    <?php endforeach; ?>
                </div>
                <div class="grid-inline">
                    <label class="check"><input type="checkbox" name="active" value="1" checked><?= e(t('common.active')) ?></label>
                    <label class="check"><input type="checkbox" name="conditional_enabled" value="1" data-achievement-conditional-toggle><?= e(t('achievements.conditional')) ?></label>
                </div>
                <div class="grid-inline" data-achievement-conditional-fields hidden>
                    <label>
                        <?= e(t('achievements.metric')) ?>
                        <select name="metric" data-achievement-metric>
                            <option value="steps">Steps</option>
                            <option value="distance_km">Distance km</option>
                            <option value="workouts">Workouts</option>
                            <option value="score">Score</option>
                            <option value="strikes">Strikes</option>
                            <?php if ($penaltiesEnabled): ?>
                            <option value="penalties">Penalties</option>
                            <?php endif; ?>
                            <option value="weight">Weight</option>
                            <option value="strength_rank">Strength rank points</option>
                            <option value="habit_completion">Habit completion</option>
                        </select>
                    </label>
                    <label data-achievement-habit-wrap hidden>
                        Habit
                        <select name="habit_code">
                            <?php foreach (($habits ?? []) as $habit): ?>
                                <option value="<?= e((string) $habit['code']) ?>"><?= e((string) $habit['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label><?= e(t('achievements.operator')) ?><select name="operator"><option value=">=">&gt;=</option><option value="<=">&lt;=</option><option value="=">=</option><option value=">">&gt;</option><option value="<">&lt;</option></select></label>
                    <label><?= e(t('achievements.target')) ?><input type="number" step="0.1" name="target_amount" value="1"></label>
                    <label><?= e(t('achievements.window')) ?><select name="window"><option value="total">total</option><option value="current_week">current_week</option><option value="current_month">current_month</option><option value="current_challenge">current_challenge</option></select></label>
                </div>
                <button class="btn btn-primary" type="submit"><?= e(t('achievements.create')) ?></button>
            </form>
        </div>

        <section class="stack compact-form admin-section-list" data-spa-show-when-no-param="achievement_id" <?= $selectedAchievementId !== '' ? 'hidden' : '' ?>>
            <h3><?= e(t('achievements.grant')) ?></h3>
            <form method="post" action="/?page=admin" class="stack">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="grant_achievement">
                <div class="grid-inline">
                    <label><?= e(t('achievements.title')) ?><select name="achievement_id"><?php foreach (($achievements ?? []) as $achievement): ?><option value="<?= (int) $achievement['id'] ?>"><?= e((string) $achievement['name']) ?> (<?= e((string) $achievement['scope']) ?>)</option><?php endforeach; ?></select></label>
                    <label><?= e(t('achievements.scope')) ?><select name="scope"><option value="user"><?= e(t('common.user')) ?></option><option value="team"><?= e(t('nav.team')) ?></option></select></label>
                    <label><?= e(t('common.user')) ?><select name="user_id"><?php foreach ($users as $user): ?><option value="<?= (int) $user['id'] ?>"><?= e((string) $user['display_name']) ?></option><?php endforeach; ?></select></label>
                    <input type="hidden" name="team_id" value="<?= (int) $team['id'] ?>">
                </div>
                <label><?= e(t('common.notes')) ?><input type="text" name="note"></label>
                <button class="btn btn-secondary" type="submit"><?= e(t('achievements.grant')) ?></button>
            </form>
        </section>

        <?php foreach (($adminAchievements ?? []) as $achievement): ?>
            <?php
            $achievementTrigger = trim((string) ($achievement['trigger_key'] ?? ''));
            $achievementConditional = $achievementTrigger !== '' && !empty($achievement['rule_id']);
            $achievementMetric = str_starts_with($achievementTrigger, 'habit:') ? 'habit_completion' : $achievementTrigger;
            if ($achievementMetric === 'km') {
                $achievementMetric = 'distance_km';
            }
            $achievementHabitCode = str_starts_with($achievementTrigger, 'habit:') ? substr($achievementTrigger, 6) : '';
            $achievementOperator = (string) ($achievement['trigger_operator'] ?? '>=');
            $achievementTarget = (string) ($achievement['trigger_target'] ?? '1');
            $achievementWindow = (string) ($achievement['trigger_window'] ?? 'total');
            if ($achievementWindow === 'week') {
                $achievementWindow = 'current_week';
            }
            $achievementIconKey = normalize_achievement_icon_key((string) ($achievement['icon_key'] ?? 'trophy'));
            $achievementTranslations = is_array($achievement['translations_by_locale'] ?? null) ? (array) $achievement['translations_by_locale'] : [];
            ?>
            <div class="stack admin-detail-view" data-spa-param-show="achievement_id" data-spa-value="<?= (int) $achievement['id'] ?>" <?= $selectedAchievementId === (string) ((int) $achievement['id']) ? '' : 'hidden' ?>>
                <div class="panel-head">
                    <h3><?= e((string) $achievement['name']) ?></h3>
                    <a class="btn btn-ghost" href="/?page=admin&section=achievements" data-spa-back aria-label="<?= e(t('common.back')) ?>">← <?= e(t('common.back')) ?></a>
                </div>
                <form method="post" action="/?page=admin" enctype="multipart/form-data" class="stack compact-form" data-achievement-form>
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="update_achievement">
                    <input type="hidden" name="achievement_id" value="<?= (int) $achievement['id'] ?>">
                    <div class="grid-inline">
                        <label>Code <input type="text" name="code" value="<?= e((string) $achievement['code']) ?>"></label>
                        <label><?= e(t('achievements.scope')) ?><select name="scope"><option value="user" <?= (string) $achievement['scope'] === 'user' ? 'selected' : '' ?>><?= e(t('common.user')) ?></option><option value="team" <?= (string) $achievement['scope'] === 'team' ? 'selected' : '' ?>><?= e(t('nav.team')) ?></option></select></label>
                    </div>
                    <fieldset class="achievement-visual-fieldset">
                        <legend><?= e(t('achievements.visual')) ?></legend>
                        <div class="achievement-icon-picker" role="radiogroup" aria-label="<?= e(t('achievements.icon')) ?>">
                            <?php foreach ($achievementIconOptions as $iconKey => $iconLabel): ?>
                                <label class="achievement-icon-option">
                                    <input type="radio" name="icon_key" value="<?= e((string) $iconKey) ?>" <?= $achievementIconKey === (string) $iconKey ? 'checked' : '' ?>>
                                    <span class="achievement-icon-option-media"><?= achievement_icon_svg((string) $iconKey) ?></span>
                                    <span><?= e((string) $iconLabel) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <label><?= e(t('achievements.custom_image')) ?><input type="file" name="image" accept="image/*"></label>
                        <?php if (!empty($achievement['image_path'])): ?>
                            <?php $achievementImageUrl = media_url((string) ($achievement['image_path'] ?? '')); ?>
                            <?php if ($achievementImageUrl !== ''): ?>
                                <img class="settings-avatar-preview" src="<?= e($achievementImageUrl) ?>" alt="<?= e((string) $achievement['name']) ?>">
                            <?php else: ?>
                                <div class="entries-calendar-empty"><?= e(t('admin.no_photo')) ?></div>
                            <?php endif; ?>
                            <label class="check"><input type="checkbox" name="remove_image" value="1"><?= e(t('achievements.remove_image')) ?></label>
                        <?php endif; ?>
                    </fieldset>
                    <div class="achievement-translation-fields">
                        <?php foreach ($achievementLocales as $localeCode => $localeLabel): ?>
                            <?php
                            $translation = is_array($achievementTranslations[$localeCode] ?? null) ? (array) $achievementTranslations[$localeCode] : [];
                            if ($localeCode === 'en') {
                                $translation = [
                                    'name' => (string) ($translation['name'] ?? $achievement['name'] ?? ''),
                                    'description' => (string) ($translation['description'] ?? $achievement['description'] ?? ''),
                                    'reward_text' => (string) ($translation['reward_text'] ?? $achievement['reward_text'] ?? ''),
                                ];
                            }
                            ?>
                            <fieldset class="achievement-translation-card">
                                <legend><?= e((string) $localeLabel) ?><?php if ($localeCode === 'en'): ?> <span class="badge">Base</span><?php endif; ?></legend>
                                <label><?= e(t('achievements.name')) ?><input type="text" name="translations[<?= e((string) $localeCode) ?>][name]" value="<?= e((string) ($translation['name'] ?? '')) ?>" <?= $localeCode === 'en' ? 'required' : '' ?>></label>
                                <label><?= e(t('achievements.description')) ?><input type="text" name="translations[<?= e((string) $localeCode) ?>][description]" value="<?= e((string) ($translation['description'] ?? '')) ?>"></label>
                                <label><?= e(t('achievements.reward')) ?><input type="text" name="translations[<?= e((string) $localeCode) ?>][reward_text]" value="<?= e((string) ($translation['reward_text'] ?? '')) ?>"></label>
                            </fieldset>
                        <?php endforeach; ?>
                    </div>
                    <div class="grid-inline">
                        <label class="check"><input type="checkbox" name="active" value="1" <?= (int) ($achievement['active'] ?? 1) === 1 ? 'checked' : '' ?>><?= e(t('common.active')) ?></label>
                        <label class="check"><input type="checkbox" name="conditional_enabled" value="1" data-achievement-conditional-toggle <?= $achievementConditional ? 'checked' : '' ?>><?= e(t('achievements.conditional')) ?></label>
                    </div>
                    <div class="grid-inline" data-achievement-conditional-fields <?= $achievementConditional ? '' : 'hidden' ?>>
                        <label>
                            <?= e(t('achievements.metric')) ?>
                            <select name="metric" data-achievement-metric>
                                <option value="steps" <?= $achievementMetric === 'steps' ? 'selected' : '' ?>>Steps</option>
                                <option value="distance_km" <?= $achievementMetric === 'distance_km' ? 'selected' : '' ?>>Distance km</option>
                                <option value="workouts" <?= $achievementMetric === 'workouts' ? 'selected' : '' ?>>Workouts</option>
                                <option value="score" <?= $achievementMetric === 'score' ? 'selected' : '' ?>>Score</option>
                                <option value="strikes" <?= $achievementMetric === 'strikes' ? 'selected' : '' ?>>Strikes</option>
                                <?php if ($penaltiesEnabled || $achievementMetric === 'penalties'): ?>
                                <option value="penalties" <?= $achievementMetric === 'penalties' ? 'selected' : '' ?>>Penalties</option>
                                <?php endif; ?>
                                <option value="weight" <?= $achievementMetric === 'weight' ? 'selected' : '' ?>>Weight</option>
                                <option value="strength_rank" <?= $achievementMetric === 'strength_rank' ? 'selected' : '' ?>>Strength rank points</option>
                                <option value="habit_completion" <?= $achievementMetric === 'habit_completion' ? 'selected' : '' ?>>Habit completion</option>
                            </select>
                        </label>
                        <label data-achievement-habit-wrap <?= $achievementMetric === 'habit_completion' ? '' : 'hidden' ?>>
                            Habit
                            <select name="habit_code">
                                <?php foreach (($habits ?? []) as $habit): ?>
                                    <option value="<?= e((string) $habit['code']) ?>" <?= (string) $habit['code'] === $achievementHabitCode ? 'selected' : '' ?>><?= e((string) $habit['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label><?= e(t('achievements.operator')) ?><select name="operator"><option value=">=" <?= $achievementOperator === '>=' ? 'selected' : '' ?>>&gt;=</option><option value="<=" <?= $achievementOperator === '<=' ? 'selected' : '' ?>>&lt;=</option><option value="=" <?= $achievementOperator === '=' ? 'selected' : '' ?>>=</option><option value=">" <?= $achievementOperator === '>' ? 'selected' : '' ?>>&gt;</option><option value="<" <?= $achievementOperator === '<' ? 'selected' : '' ?>>&lt;</option></select></label>
                        <label><?= e(t('achievements.target')) ?><input type="number" step="0.1" name="target_amount" value="<?= e($achievementTarget) ?>"></label>
                        <label><?= e(t('achievements.window')) ?><select name="window"><option value="total" <?= $achievementWindow === 'total' ? 'selected' : '' ?>>total</option><option value="current_week" <?= $achievementWindow === 'current_week' ? 'selected' : '' ?>>current_week</option><option value="current_month" <?= $achievementWindow === 'current_month' ? 'selected' : '' ?>>current_month</option><option value="current_challenge" <?= $achievementWindow === 'current_challenge' ? 'selected' : '' ?>>current_challenge</option></select></label>
                    </div>
                    <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
                </form>
                <form method="post" action="/?page=admin" class="admin-danger-zone" onsubmit="return confirm('<?= e(t('admin.deactivate_achievement_confirm')) ?>');">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="deactivate_achievement">
                    <input type="hidden" name="achievement_id" value="<?= (int) $achievement['id'] ?>">
                    <button class="btn btn-ghost" type="submit"><?= e(t('common.delete')) ?></button>
                </form>
            </div>
        <?php endforeach; ?>

        <section class="stack compact-form admin-section-list" data-spa-show-when-no-param="achievement_id" <?= $selectedAchievementId !== '' ? 'hidden' : '' ?>>
            <h3><?= e(t('admin.achievement_awards')) ?></h3>
            <div class="card-list">
                <?php foreach (($achievementAwards ?? []) as $award): ?>
                    <form method="post" action="/?page=admin" class="mini-card">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="delete_achievement_award">
                        <input type="hidden" name="award_id" value="<?= (int) $award['id'] ?>">
                        <div>
                            <strong><?= e((string) $award['name']) ?></strong>
                            <span><?= e((string) ($award['owner_name'] ?? $award['team_name'] ?? '')) ?> · <?= e(format_date_eu((string) $award['awarded_at'])) ?></span>
                        </div>
                        <button class="btn small btn-ghost" type="submit"><?= e(t('common.delete')) ?></button>
                    </form>
                <?php endforeach; ?>
                <?php if (($achievementAwards ?? []) === []): ?>
                    <p class="muted"><?= e(t('achievements.empty')) ?></p>
                <?php endif; ?>
            </div>
        </section>
    </article>

    <article class="panel settings-panel<?= $activeSection === 'motivational_quotes' ? ' active' : '' ?>" data-spa-section="motivational_quotes" <?= $activeSection === 'motivational_quotes' ? '' : 'hidden' ?>>
        <div class="panel-head">
            <div>
                <h2><?= e(t('admin.motivational_quotes')) ?></h2>
                <p class="muted admin-section-help"><?= e(t('admin.motivational_quotes_help')) ?></p>
            </div>
            <a class="btn btn-ghost" href="/?page=admin" data-spa-back aria-label="<?= e(t('common.back')) ?>">← <?= e(t('common.back')) ?></a>
        </div>

        <?php
        $quoteLocales = function_exists('motivational_quote_locales') ? motivational_quote_locales() : ['any', 'en', 'es', 'it'];
        $quoteLocaleLabel = static fn(string $loc): string => $loc === 'any' ? t('admin.quote_lang_any') : t('admin.quote_lang_' . $loc);
        ?>
        <form method="post" action="/?page=admin" class="stack compact-form admin-quote-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="create_motivational_quote">
            <label><?= e(t('admin.quote_text')) ?><textarea name="quote_text" rows="2" maxlength="280" required></textarea></label>
            <label><?= e(t('admin.quote_language')) ?>
                <select name="quote_locale">
                    <?php foreach ($quoteLocales as $loc): ?>
                        <option value="<?= e($loc) ?>"><?= e($quoteLocaleLabel($loc)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="btn btn-primary" type="submit"><?= e(t('common.create')) ?></button>
        </form>

        <section class="stack">
            <h3><?= e(t('admin.motivational_quotes_all')) ?></h3>
            <div class="card-list admin-quote-list">
                <?php foreach (($motivationalQuotes ?? []) as $quote): ?>
                    <?php
                    $quoteId = (int) ($quote['id'] ?? 0);
                    $quoteActive = (int) ($quote['active'] ?? 1) === 1;
                    $quoteLoc = function_exists('normalize_quote_locale') ? normalize_quote_locale((string) ($quote['locale'] ?? 'any')) : 'any';
                    ?>
                    <article class="mini-card admin-quote-item">
                        <form method="post" action="/?page=admin" class="stack admin-quote-edit-form">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="update_motivational_quote">
                            <input type="hidden" name="quote_id" value="<?= $quoteId ?>">
                            <textarea name="quote_text" rows="2" maxlength="280" required><?= e((string) $quote['quote_text']) ?></textarea>
                            <div class="admin-quote-edit-controls">
                                <label class="admin-quote-lang"><?= e(t('admin.quote_language')) ?>
                                    <select name="quote_locale">
                                        <?php foreach ($quoteLocales as $loc): ?>
                                            <option value="<?= e($loc) ?>" <?= $quoteLoc === $loc ? 'selected' : '' ?>><?= e($quoteLocaleLabel($loc)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label class="admin-quote-active checkbox-inline">
                                    <input type="checkbox" name="quote_active" value="1" <?= $quoteActive ? 'checked' : '' ?>>
                                    <span><?= e(t('common.active')) ?></span>
                                </label>
                                <span class="badge badge-soft admin-quote-lang-badge"><?= e($quoteLoc === 'any' ? t('admin.quote_lang_any') : strtoupper($quoteLoc)) ?></span>
                            </div>
                            <div class="admin-quote-meta muted"><?= e(format_date_eu((string) ($quote['created_at'] ?? ''))) ?><?php if (!empty($quote['created_by_name'])): ?> · <?= e((string) $quote['created_by_name']) ?><?php endif; ?></div>
                            <div class="admin-quote-actions">
                                <button class="btn btn-primary small" type="submit"><?= e(t('common.save')) ?></button>
                            </div>
                        </form>
                        <form method="post" action="/?page=admin" class="admin-quote-delete-form" onsubmit="return confirm('<?= e(t('admin.quote_delete_confirm')) ?>');">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete_motivational_quote">
                            <input type="hidden" name="quote_id" value="<?= $quoteId ?>">
                            <button class="btn btn-ghost small btn-danger-ghost" type="submit"><?= e(t('common.delete')) ?></button>
                        </form>
                    </article>
                <?php endforeach; ?>
                <?php if (($motivationalQuotes ?? []) === []): ?>
                    <p class="muted"><?= e(t('common.none')) ?></p>
                <?php endif; ?>
            </div>
        </section>
    </article>

    <article class="panel settings-panel<?= $activeSection === 'xp' ? ' active' : '' ?>" data-spa-section="xp" <?= $activeSection === 'xp' ? '' : 'hidden' ?>>
        <div class="panel-head">
            <div>
                <h2><?= e(t('admin.xp_title')) ?></h2>
                <p class="muted admin-section-help"><?= e(t('admin.xp_help')) ?></p>
            </div>
            <a class="btn btn-ghost" href="/?page=admin" data-spa-back aria-label="<?= e(t('common.back')) ?>">← <?= e(t('common.back')) ?></a>
        </div>

        <?php
        $xpAmounts = is_array($xpAmounts ?? null) ? (array) $xpAmounts : [];
        $xpActionLabels = [
            'daily_log' => t('admin.xp_action_daily_log'),
            'workout' => t('admin.xp_action_workout'),
            'photo' => t('admin.xp_action_photo'),
            'achievement' => t('admin.xp_action_achievement'),
            'goal' => t('admin.xp_action_goal'),
            'duel_win' => t('admin.xp_action_duel_win'),
        ];
        ?>
        <div class="grid-two admin-xp-forms">
        <section class="admin-subsection admin-xp-card">
            <h3><?= e(t('admin.xp_amounts_title')) ?></h3>
            <p class="muted small"><?= e(t('admin.xp_amounts_help')) ?></p>
            <form method="post" action="/?page=admin" class="stack compact-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="update_xp_amounts">
                <div class="xp-amounts-grid xp-input-grid">
                    <?php foreach ($xpActionLabels as $action => $label): ?>
                        <label><?= e($label) ?><input type="number" name="xp_amounts[<?= e($action) ?>]" min="0" max="100000" value="<?= (int) ($xpAmounts[$action] ?? 0) ?>"></label>
                    <?php endforeach; ?>
                </div>
                <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
            </form>
        </section>

        <section class="admin-subsection admin-xp-card">
            <h3><?= e(t('admin.xp_adjust_title')) ?></h3>
            <p class="muted small"><?= e(t('admin.xp_adjust_help')) ?></p>
            <form method="post" action="/?page=admin" class="stack compact-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="adjust_user_xp">
                <div class="xp-adjust-grid xp-input-grid">
                    <label><?= e(t('common.user')) ?>
                        <select name="user_id" required>
                            <option value=""><?= e(t('admin.xp_select_user')) ?></option>
                            <?php foreach ((array) ($xpUsers ?? []) as $xpUser): ?>
                                <option value="<?= (int) $xpUser['id'] ?>"><?= e((string) $xpUser['display_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label><?= e(t('admin.xp_amount')) ?><input type="number" name="amount" step="1" value="0" required></label>
                    <label><?= e(t('admin.xp_note')) ?><input type="text" name="note" maxlength="120" placeholder="<?= e(t('admin.xp_note_placeholder')) ?>"></label>
                </div>
                <p class="muted small"><?= e(t('admin.xp_amount_hint')) ?></p>
                <button class="btn btn-primary" type="submit"><?= e(t('admin.xp_apply')) ?></button>
            </form>
        </section>
        </div>

        <section class="admin-subsection">
            <h3><?= e(t('admin.xp_users_title')) ?></h3>
            <div class="card-list xp-user-list xp-user-grid">
                <?php foreach ((array) ($xpUsers ?? []) as $xpUser): ?>
                    <article class="mini-card xp-user-item">
                        <div>
                            <strong><?= e((string) $xpUser['display_name']) ?></strong>
                            <span class="muted small"><?= e(number_format((int) ($xpUser['xp_total'] ?? 0))) ?> <?= e(t('xp.points')) ?></span>
                        </div>
                        <span class="profile-level-badge"><?= e(t('xp.level_short')) ?> <?= (int) ($xpUser['level'] ?? 1) ?></span>
                    </article>
                <?php endforeach; ?>
                <?php if (($xpUsers ?? []) === []): ?>
                    <p class="muted"><?= e(t('common.none')) ?></p>
                <?php endif; ?>
            </div>
        </section>
    </article>

    <article class="panel settings-panel<?= $activeSection === 'audit' ? ' active' : '' ?>" data-spa-section="audit" <?= $activeSection === 'audit' ? '' : 'hidden' ?>>
        <div class="panel-head">
            <h2>Audit Log</h2>
            <a class="btn btn-ghost" href="/?page=admin" data-spa-back aria-label="<?= e(t('common.back')) ?>">← <?= e(t('common.back')) ?></a>
        </div>

        <form method="get" action="/" class="control-strip audit-filter">
            <input type="hidden" name="page" value="admin">
            <input type="hidden" name="section" value="audit">
            <label><?= e(t('audit.actor')) ?><select name="actor_user_id"><option value=""><?= e(t('common.none')) ?></option><?php foreach ($users as $user): ?><option value="<?= (int) $user['id'] ?>" <?= (int) ($auditFilters['actor_user_id'] ?? 0) === (int) $user['id'] ? 'selected' : '' ?>><?= e((string) $user['display_name']) ?></option><?php endforeach; ?></select></label>
            <label><?= e(t('audit.entity')) ?><select name="entity_type"><option value=""><?= e(t('common.none')) ?></option><?php foreach ($entityTypes as $type): ?><option value="<?= e($type) ?>" <?= ($auditFilters['entity_type'] ?? '') === $type ? 'selected' : '' ?>><?= e($type) ?></option><?php endforeach; ?></select></label>
            <label><?= e(t('audit.from')) ?><input type="date" name="date_from" value="<?= e((string) ($auditFilters['date_from'] ?? '')) ?>"></label>
            <label><?= e(t('audit.to')) ?><input type="date" name="date_to" value="<?= e((string) ($auditFilters['date_to'] ?? '')) ?>"></label>
            <button class="btn btn-primary" type="submit"><?= e(t('audit.filter')) ?></button>
        </form>

        <div class="audit-list audit-list-admin">
            <?php if (($auditLogs ?? []) === []): ?>
                <p class="muted"><?= e(t('audit.empty')) ?></p>
            <?php else: ?>
                <?php foreach ($auditLogs as $log): ?>
                    <article>
                        <strong><?= e((string) $log['summary']) ?></strong>
                        <span><?= e((string) ($log['actor_name'] ?? t('common.none'))) ?> · <?= e((string) $log['action']) ?> · <?= e((string) $log['entity_type']) ?> · <?= e((string) $log['created_at']) ?></span>
                        <details>
                            <summary><?= e(t('audit.diff')) ?></summary>
                            <pre><?= e((string) $log['before_json']) ?></pre>
                            <pre><?= e((string) $log['after_json']) ?></pre>
                        </details>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </article>
</section>
