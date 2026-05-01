<?php

declare(strict_types=1);

$days = [];
for ($i = 0; $i < 7; $i++) {
    $days[$i] = t('weekday.' . $i);
}
$entityTypes = ['daily_log', 'approval_request', 'user', 'team_membership', 'goal', 'achievement', 'workout_type', 'photo_entry', 'app_setting', 'system_backup'];
$activeSection = (string) ($_GET['section'] ?? '');
$allowedSections = ['users', 'challenge', 'app', 'backups', 'habits', 'workout_types', 'achievements', 'audit'];
if (!in_array($activeSection, $allowedSections, true)) {
    $activeSection = '';
}
$selectedUserId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
$createUserMode = (string) ($_GET['create_user'] ?? '') === '1';
$selectedHabitId = (string) ($_GET['habit_id'] ?? '');
$selectedTypeId = (string) ($_GET['type_id'] ?? '');
$selectedAchievementId = isset($_GET['achievement_id']) ? (int) $_GET['achievement_id'] : 0;
$sectionRows = [
    'users' => 'Users',
    'challenge' => 'Challenge',
    'app' => 'App',
    'backups' => 'Backups',
    'habits' => 'Habits',
    'workout_types' => 'Workout Types',
    'achievements' => 'Achievements',
    'audit' => 'Audit Log',
];
$activeLoginBackgroundPath = trim((string) ($loginBackgroundPath ?? ''));
$activeLoginBackgroundUrl = $activeLoginBackgroundPath !== '' ? media_url($activeLoginBackgroundPath) : '';
$backupSettings = is_array($backupSettings ?? null) ? (array) $backupSettings : [];
$backupAutoEnabled = !empty($backupSettings['enabled']);
$backupFrequency = normalize_backup_frequency((string) ($backupSettings['frequency'] ?? 'daily'));
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
                <a class="btn btn-ghost" href="/?page=admin" data-spa-back aria-label="Volver">← Volver</a>
            </div>
        </div>

        <div class="settings-list compact-list admin-section-list" data-spa-show-when-no-param="create_user,user_id" <?= ($createUserMode || $selectedUserId > 0) ? 'hidden' : '' ?>>
            <a class="settings-row" href="/?page=admin&section=users&create_user=1" data-spa-link>
                <span><?= e(t('users.create')) ?></span>
                <span class="settings-chevron" aria-hidden="true">›</span>
            </a>
            <?php foreach ($users as $user): ?>
                <a class="settings-row" href="/?page=admin&section=users&user_id=<?= (int) $user['id'] ?>" data-spa-link>
                    <span><?= e((string) $user['display_name']) ?> <small class="muted">@<?= e((string) $user['username']) ?></small></span>
                    <span class="settings-chevron" aria-hidden="true">›</span>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="stack admin-create-view" data-spa-param-show="create_user" data-spa-value="1" <?= $createUserMode ? '' : 'hidden' ?>>
            <div class="panel-head">
                <h3><?= e(t('users.create')) ?></h3>
                <a class="btn btn-ghost" href="/?page=admin&section=users" data-spa-back aria-label="Volver">← Volver</a>
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
                <label><?= e(t('users.motivation_quote')) ?><input type="text" name="motivation_quote" placeholder="<?= e(t('users.motivation_placeholder')) ?>"></label>
                <label class="check standalone-check"><input type="checkbox" name="active" value="1" checked><?= e(t('users.active_user')) ?></label>
                <button type="submit" class="btn btn-primary"><?= e(t('users.create')) ?></button>
            </form>
        </div>

        <?php foreach ($users as $user): ?>
            <div class="stack admin-detail-view" data-spa-param-show="user_id" data-spa-value="<?= (int) $user['id'] ?>" <?= $selectedUserId === (int) $user['id'] ? '' : 'hidden' ?>>
                <div class="panel-head">
                    <h3><?= e((string) $user['display_name']) ?></h3>
                    <a class="btn btn-ghost" href="/?page=admin&section=users" data-spa-back aria-label="Volver">← Volver</a>
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
                    <label><?= e(t('users.motivation_quote')) ?><input type="text" name="motivation_quote" value="<?= e((string) $user['motivation_quote']) ?>"></label>
                    <label class="check standalone-check"><input type="checkbox" name="active" value="1" <?= (int) $user['active'] === 1 ? 'checked' : '' ?>><?= e(t('common.active')) ?></label>
                    <button type="submit" class="btn btn-primary"><?= e(t('users.save_user', ['name' => (string) $user['display_name']])) ?></button>
                </form>
            </div>
        <?php endforeach; ?>
    </article>

    <article class="panel settings-panel<?= $activeSection === 'challenge' ? ' active' : '' ?>" data-spa-section="challenge" <?= $activeSection === 'challenge' ? '' : 'hidden' ?>>
        <div class="panel-head">
            <h2>Challenge</h2>
            <a class="btn btn-ghost" href="/?page=admin" data-spa-back aria-label="Volver">← Volver</a>
        </div>
        <form method="post" action="/?page=admin" class="stack compact-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_challenge_settings">
            <label><?= e(t('admin.challenge_name')) ?><input type="text" name="challenge_name" value="<?= e((string) ($challengeSettings['challenge_name'] ?? 'Fitness Challenge')) ?>" required></label>
            <div class="grid-inline two">
                <label><?= e(t('audit.from')) ?><input type="date" name="challenge_start" value="<?= e((string) ($challengeSettings['challenge_start'] ?? '')) ?>" required></label>
                <label><?= e(t('audit.to')) ?><input type="date" name="challenge_end" value="<?= e((string) ($challengeSettings['challenge_end'] ?? '')) ?>" required></label>
            </div>
            <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
        </form>

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

        <form method="post" action="/?page=admin" class="stack">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="archive_challenge">
            <label><?= e(t('admin.archive_confirm')) ?><input type="text" name="confirm_archive" placeholder="ARCHIVE"></label>
            <button class="btn btn-ghost" type="submit"><?= e(t('admin.archive_challenge')) ?></button>
        </form>
    </article>

    <article class="panel settings-panel<?= $activeSection === 'app' ? ' active' : '' ?>" data-spa-section="app" <?= $activeSection === 'app' ? '' : 'hidden' ?>>
        <div class="panel-head">
            <h2>App</h2>
            <a class="btn btn-ghost" href="/?page=admin" data-spa-back aria-label="Volver">← Volver</a>
        </div>
        <form method="post" action="/?page=admin" class="stack compact-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_app_name">
            <label><?= e(t('admin.app_name')) ?><input type="text" name="app_name" value="<?= e((string) ($appNameSetting ?? 'Fitness Challenge Tracker')) ?>" required></label>
            <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
        </form>

        <form method="post" action="/?page=admin" enctype="multipart/form-data" class="stack" data-image-cropper-form>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="upload_app_icon">
            <input type="hidden" name="app_icon_cropped" value="" data-image-crop-output>
            <?php if (!empty($appIconPath)): ?><img class="settings-avatar-preview" src="<?= e(with_cache_buster('/?page=app_icon', $appIconVersion ?? null)) ?>" alt="<?= e(t('admin.app_icon')) ?>"><?php endif; ?>
            <div class="image-cropper" data-image-cropper>
                <canvas width="320" height="320" data-image-crop-canvas></canvas>
                <p class="muted small" data-image-crop-empty>Selecciona una imagen para recortarla en formato 1:1.</p>
                <label>
                    Zoom
                    <input type="range" min="1" max="3" step="0.01" value="1" data-image-crop-zoom>
                </label>
            </div>
            <label><?= e(t('common.photo')) ?><input type="file" name="app_icon" accept="image/*" required data-image-crop-input></label>
            <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
        </form>

        <section class="stack compact-form admin-login-background">
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
    </article>

    <article class="panel settings-panel<?= $activeSection === 'backups' ? ' active' : '' ?>" data-spa-section="backups" <?= $activeSection === 'backups' ? '' : 'hidden' ?>>
        <div class="panel-head">
            <h2><?= e(t('admin.backups_title')) ?></h2>
            <a class="btn btn-ghost" href="/?page=admin" data-spa-back aria-label="Volver">← Volver</a>
        </div>
        <p class="muted"><?= e(t('admin.backups_subtitle')) ?></p>

        <form method="post" action="/?page=admin" class="stack compact-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_backup_settings">
            <label class="check standalone-check">
                <input type="checkbox" name="backup_auto_enabled" value="1" <?= $backupAutoEnabled ? 'checked' : '' ?>>
                <?= e(t('admin.backup_auto_enabled')) ?>
            </label>
            <div class="grid-inline two">
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
            </div>
            <p class="muted small"><?= e(t('admin.backup_last_auto', ['value' => $backupLastAutoLabel])) ?></p>
            <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
        </form>

        <form method="post" action="/?page=admin">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="create_backup_now">
            <button class="btn btn-secondary" type="submit"><?= e(t('admin.backup_create_now')) ?></button>
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
            <h2>Habits</h2>
            <div class="inline-actions-mini">
                <a class="btn btn-primary small" href="/?page=admin&section=habits&habit_id=new" data-spa-link><?= e(t('common.create')) ?></a>
                <a class="btn btn-ghost" href="/?page=admin" data-spa-back aria-label="Volver">← Volver</a>
            </div>
        </div>

        <div class="settings-list compact-list admin-section-list" data-spa-show-when-no-param="habit_id" <?= $selectedHabitId !== '' ? 'hidden' : '' ?>>
            <a class="settings-row" href="/?page=admin&section=habits&habit_id=new" data-spa-link>
                <span><?= e(t('common.save')) ?> <?= e(t('admin.habits')) ?></span>
                <span class="settings-chevron" aria-hidden="true">›</span>
            </a>
            <?php foreach (($habits ?? []) as $habit): ?>
                <a class="settings-row" href="/?page=admin&section=habits&habit_id=<?= (int) $habit['id'] ?>" data-spa-link>
                    <span><?= e((string) $habit['label']) ?></span>
                    <span class="settings-chevron" aria-hidden="true">›</span>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="stack admin-create-view" data-spa-param-show="habit_id" data-spa-value="new" <?= $selectedHabitId === 'new' ? '' : 'hidden' ?>>
            <div class="panel-head">
                <h3><?= e(t('common.save')) ?> <?= e(t('admin.habits')) ?></h3>
                <a class="btn btn-ghost" href="/?page=admin&section=habits" data-spa-back aria-label="Volver">← Volver</a>
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
                    <a class="btn btn-ghost" href="/?page=admin&section=habits" data-spa-back aria-label="Volver">← Volver</a>
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
                <form method="post" action="/?page=admin" onsubmit="return confirm('¿Eliminar este hábito?');">
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
            <h2>Workout Types</h2>
            <div class="inline-actions-mini">
                <a class="btn btn-primary small" href="/?page=admin&section=workout_types&type_id=new" data-spa-link><?= e(t('common.create')) ?></a>
                <a class="btn btn-ghost" href="/?page=admin" data-spa-back aria-label="Volver">← Volver</a>
            </div>
        </div>

        <div class="settings-list compact-list admin-section-list" data-spa-show-when-no-param="type_id" <?= $selectedTypeId !== '' ? 'hidden' : '' ?>>
            <a class="settings-row" href="/?page=admin&section=workout_types&type_id=new" data-spa-link>
                <span><?= e(t('workout_types.title')) ?> +</span>
                <span class="settings-chevron" aria-hidden="true">›</span>
            </a>
            <?php foreach (($workoutTypes ?? []) as $type): ?>
                <a class="settings-row" href="/?page=admin&section=workout_types&type_id=<?= (int) $type['id'] ?>" data-spa-link>
                    <span><?= e((string) $type['name']) ?></span>
                    <span class="settings-chevron" aria-hidden="true">›</span>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="stack admin-create-view" data-spa-param-show="type_id" data-spa-value="new" <?= $selectedTypeId === 'new' ? '' : 'hidden' ?>>
            <div class="panel-head">
                <h3><?= e(t('workout_types.title')) ?> +</h3>
                <a class="btn btn-ghost" href="/?page=admin&section=workout_types" data-spa-back aria-label="Volver">← Volver</a>
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
                    <a class="btn btn-ghost" href="/?page=admin&section=workout_types" data-spa-back aria-label="Volver">← Volver</a>
                </div>
                <form method="post" action="/?page=admin" class="mini-card editable-card">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="update_workout_type">
                    <input type="hidden" name="type_id" value="<?= (int) $type['id'] ?>">
                    <input type="text" name="name" value="<?= e((string) $type['name']) ?>">
                    <label class="check"><input type="checkbox" name="active" value="1" <?= (int) $type['active'] === 1 ? 'checked' : '' ?>><?= e(t('common.active')) ?></label>
                    <button class="btn small btn-primary" type="submit"><?= e(t('common.save')) ?></button>
                </form>
                <form method="post" action="/?page=admin" onsubmit="return confirm('¿Eliminar este tipo de entreno?');">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete_workout_type">
                    <input type="hidden" name="type_id" value="<?= (int) $type['id'] ?>">
                    <button class="btn small btn-ghost" type="submit"><?= e(t('common.delete')) ?></button>
                </form>
            </div>
        <?php endforeach; ?>
    </article>

    <article class="panel settings-panel<?= $activeSection === 'achievements' ? ' active' : '' ?>" data-spa-section="achievements" <?= $activeSection === 'achievements' ? '' : 'hidden' ?>>
        <div class="panel-head admin-section-list" data-spa-show-when-no-param="achievement_id" <?= $selectedAchievementId > 0 ? 'hidden' : '' ?>>
            <h2>Achievements</h2>
            <div class="inline-actions-mini">
                <a class="btn btn-primary small" href="/?page=admin&section=achievements#achievement-create" data-spa-link><?= e(t('common.create')) ?></a>
                <a class="btn btn-ghost" href="/?page=admin" data-spa-back aria-label="Volver">← Volver</a>
            </div>
        </div>

        <section id="achievement-create" class="stack compact-form admin-section-list" data-spa-show-when-no-param="achievement_id" <?= $selectedAchievementId > 0 ? 'hidden' : '' ?>>
            <h3>Crear achievement</h3>
            <form method="post" action="/?page=admin" enctype="multipart/form-data" class="stack" data-achievement-form>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="create_achievement">
                <div class="grid-inline">
                    <label>Code <input type="text" name="code" placeholder="my_achievement_code"></label>
                    <label><?= e(t('achievements.name')) ?><input type="text" name="name" required></label>
                    <label><?= e(t('achievements.scope')) ?><select name="scope"><option value="user"><?= e(t('common.user')) ?></option><option value="team"><?= e(t('nav.team')) ?></option></select></label>
                    <label><?= e(t('achievements.description')) ?><input type="text" name="description"></label>
                </div>
                <div class="grid-inline">
                    <label><?= e(t('achievements.reward')) ?><input type="text" name="reward_text"></label>
                    <label><?= e(t('common.photo')) ?><input type="file" name="image" accept="image/*"></label>
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
                            <option value="penalties">Penalties</option>
                            <option value="weight">Weight</option>
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
        </section>

        <section class="stack compact-form admin-section-list" data-spa-show-when-no-param="achievement_id" <?= $selectedAchievementId > 0 ? 'hidden' : '' ?>>
            <h3>Grant achievement</h3>
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

        <section class="stack admin-section-list" data-spa-show-when-no-param="achievement_id" <?= $selectedAchievementId > 0 ? 'hidden' : '' ?>>
            <h3>Achievements creados</h3>
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
                            $conditionSummary = $triggerKey . ' · pendiente';
                        }
                    }
                    $status = (int) ($achievement['active'] ?? 1) === 1 ? 'active' : 'inactive';
                    ?>
                    <a class="settings-row" href="/?page=admin&section=achievements&achievement_id=<?= (int) $achievement['id'] ?>" data-spa-link>
                        <span>
                            <strong><?= e((string) $achievement['name']) ?></strong>
                            <small class="muted"><?= e((string) $achievement['scope']) ?> · <?= e($conditionSummary) ?> · <?= e($status) ?></small>
                        </span>
                        <span class="settings-chevron" aria-hidden="true">›</span>
                    </a>
                <?php endforeach; ?>
                <?php if (($adminAchievements ?? []) === []): ?>
                    <p class="muted panel-inline-empty"><?= e(t('achievements.empty')) ?></p>
                <?php endif; ?>
            </div>
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
            ?>
            <div class="stack admin-detail-view" data-spa-param-show="achievement_id" data-spa-value="<?= (int) $achievement['id'] ?>" <?= $selectedAchievementId === (int) $achievement['id'] ? '' : 'hidden' ?>>
                <div class="panel-head">
                    <h3><?= e((string) $achievement['name']) ?></h3>
                    <a class="btn btn-ghost" href="/?page=admin&section=achievements" data-spa-back aria-label="Volver">← Volver</a>
                </div>
                <form method="post" action="/?page=admin" enctype="multipart/form-data" class="stack compact-form" data-achievement-form>
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="update_achievement">
                    <input type="hidden" name="achievement_id" value="<?= (int) $achievement['id'] ?>">
                    <div class="grid-inline">
                        <label>Code <input type="text" name="code" value="<?= e((string) $achievement['code']) ?>"></label>
                        <label><?= e(t('achievements.name')) ?><input type="text" name="name" value="<?= e((string) $achievement['name']) ?>" required></label>
                        <label><?= e(t('achievements.scope')) ?><select name="scope"><option value="user" <?= (string) $achievement['scope'] === 'user' ? 'selected' : '' ?>><?= e(t('common.user')) ?></option><option value="team" <?= (string) $achievement['scope'] === 'team' ? 'selected' : '' ?>><?= e(t('nav.team')) ?></option></select></label>
                        <label><?= e(t('achievements.description')) ?><input type="text" name="description" value="<?= e((string) $achievement['description']) ?>"></label>
                    </div>
                    <div class="grid-inline">
                        <label><?= e(t('achievements.reward')) ?><input type="text" name="reward_text" value="<?= e((string) ($achievement['reward_text'] ?? '')) ?>"></label>
                        <label><?= e(t('common.photo')) ?><input type="file" name="image" accept="image/*"></label>
                        <label class="check"><input type="checkbox" name="active" value="1" <?= (int) ($achievement['active'] ?? 1) === 1 ? 'checked' : '' ?>><?= e(t('common.active')) ?></label>
                        <label class="check"><input type="checkbox" name="conditional_enabled" value="1" data-achievement-conditional-toggle <?= $achievementConditional ? 'checked' : '' ?>><?= e(t('achievements.conditional')) ?></label>
                    </div>
                    <?php if (!empty($achievement['image_path'])): ?>
                        <?php $achievementImageUrl = media_url((string) ($achievement['image_path'] ?? '')); ?>
                        <?php if ($achievementImageUrl !== ''): ?>
                            <img class="settings-avatar-preview" src="<?= e($achievementImageUrl) ?>" alt="<?= e((string) $achievement['name']) ?>">
                        <?php else: ?>
                            <div class="entries-calendar-empty">Sin foto</div>
                        <?php endif; ?>
                    <?php endif; ?>
                    <div class="grid-inline" data-achievement-conditional-fields <?= $achievementConditional ? '' : 'hidden' ?>>
                        <label>
                            <?= e(t('achievements.metric')) ?>
                            <select name="metric" data-achievement-metric>
                                <option value="steps" <?= $achievementMetric === 'steps' ? 'selected' : '' ?>>Steps</option>
                                <option value="distance_km" <?= $achievementMetric === 'distance_km' ? 'selected' : '' ?>>Distance km</option>
                                <option value="workouts" <?= $achievementMetric === 'workouts' ? 'selected' : '' ?>>Workouts</option>
                                <option value="score" <?= $achievementMetric === 'score' ? 'selected' : '' ?>>Score</option>
                                <option value="strikes" <?= $achievementMetric === 'strikes' ? 'selected' : '' ?>>Strikes</option>
                                <option value="penalties" <?= $achievementMetric === 'penalties' ? 'selected' : '' ?>>Penalties</option>
                                <option value="weight" <?= $achievementMetric === 'weight' ? 'selected' : '' ?>>Weight</option>
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
                <form method="post" action="/?page=admin" onsubmit="return confirm('¿Desactivar este achievement?');">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="deactivate_achievement">
                    <input type="hidden" name="achievement_id" value="<?= (int) $achievement['id'] ?>">
                    <button class="btn btn-ghost" type="submit"><?= e(t('common.delete')) ?></button>
                </form>
            </div>
        <?php endforeach; ?>

        <section class="stack compact-form admin-section-list" data-spa-show-when-no-param="achievement_id" <?= $selectedAchievementId > 0 ? 'hidden' : '' ?>>
            <h3>Achievements awards</h3>
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

    <article class="panel settings-panel<?= $activeSection === 'audit' ? ' active' : '' ?>" data-spa-section="audit" <?= $activeSection === 'audit' ? '' : 'hidden' ?>>
        <div class="panel-head">
            <h2>Audit Log</h2>
            <a class="btn btn-ghost" href="/?page=admin" data-spa-back aria-label="Volver">← Volver</a>
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
