<?php

declare(strict_types=1);

$days = [];
for ($i = 0; $i < 7; $i++) {
    $days[$i] = t('weekday.' . $i);
}
$entityTypes = ['daily_log', 'approval_request', 'user', 'team_membership', 'goal', 'achievement', 'workout_type', 'photo_entry'];
?>
<section class="screen stack-lg">
    <div class="hero-panel">
        <div>
            <p class="eyebrow"><?= e(t('nav.admin')) ?></p>
            <h1><?= e(t('admin.title')) ?></h1>
            <p class="muted"><?= e(t('admin.subtitle')) ?></p>
        </div>
    </div>

    <div class="grid-two" id="team-settings">
        <article class="panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow"><?= e(t('common.user')) ?></p>
                    <h2><?= e(t('users.create')) ?></h2>
                </div>
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
        </article>

        <article class="panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow"><?= e(t('admin.challenge')) ?></p>
                    <h2><?= e(t('admin.challenge_settings')) ?></h2>
                </div>
                <?php if (!challenge_is_active($challengeSettings ?? [])): ?><span class="badge badge-warn"><?= e(t('common.closed_week')) ?></span><?php endif; ?>
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
            <form method="post" action="/?page=admin" class="stack">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="archive_challenge">
                <label><?= e(t('admin.archive_confirm')) ?><input type="text" name="confirm_archive" placeholder="ARCHIVE"></label>
                <button class="btn btn-ghost" type="submit"><?= e(t('admin.archive_challenge')) ?></button>
            </form>
            <a class="btn btn-secondary" href="/?page=team_settings&team_id=<?= (int) $team['id'] ?>"><?= e(t('team.settings')) ?></a>
        </article>
    </div>

    <div class="grid-two">
        <article class="panel">
            <div class="panel-head"><div><p class="eyebrow"><?= e(t('admin.app')) ?></p><h2><?= e(t('admin.app_settings')) ?></h2></div></div>
            <form method="post" action="/?page=admin" class="stack compact-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="update_app_name">
                <label><?= e(t('admin.app_name')) ?><input type="text" name="app_name" value="<?= e((string) ($appNameSetting ?? 'Fitness Challenge Tracker')) ?>" required></label>
                <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
            </form>
            <form method="post" action="/?page=admin" enctype="multipart/form-data" class="stack">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="upload_app_icon">
                <?php if (!empty($appIconPath)): ?><img class="settings-avatar-preview" src="<?= e((string) $appIconPath) ?>" alt="<?= e(t('admin.app_icon')) ?>"><?php endif; ?>
                <label><?= e(t('common.photo')) ?><input type="file" name="app_icon" accept="image/*" required></label>
                <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
            </form>
        </article>

        <article class="panel">
            <div class="panel-head"><div><p class="eyebrow"><?= e(t('admin.habits')) ?></p><h2><?= e(t('admin.habit_definitions')) ?></h2></div></div>
            <form method="post" action="/?page=admin" class="mini-card editable-card compact-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="save_habit">
                <input type="text" name="code" placeholder="custom_habit">
                <input type="text" name="label" placeholder="<?= e(t('admin.habit_label')) ?>">
                <input type="number" name="sort_order" value="50">
                <label class="check"><input type="checkbox" name="active" value="1" checked><?= e(t('common.active')) ?></label>
                <button class="btn small btn-primary" type="submit"><?= e(t('common.save')) ?></button>
            </form>
            <div class="card-list">
                <?php foreach (($habits ?? []) as $habit): ?>
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
                <?php endforeach; ?>
            </div>
        </article>
    </div>

    <article class="panel">
        <div class="panel-head">
            <div>
                <p class="eyebrow"><?= e(t('users.edit_existing')) ?></p>
                <h2><?= e(t('admin.users_rules')) ?></h2>
            </div>
        </div>
        <div class="stack">
            <?php foreach ($users as $user): ?>
                <details class="user-edit-card user-edit-modal">
                    <summary class="mini-card">
                        <div>
                            <strong><?= e((string) $user['display_name']) ?></strong>
                            <span><?= e((string) $user['username']) ?> · <?= e((string) $user['role']) ?> · <?= (int) $user['active'] === 1 ? e(t('common.active')) : e(t('team.removed')) ?></span>
                        </div>
                        <span class="btn small btn-ghost"><?= e(t('common.edit')) ?></span>
                    </summary>
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
                    <button type="submit" class="btn btn-secondary"><?= e(t('users.save_user', ['name' => (string) $user['display_name']])) ?></button>
                </form>
                </details>
            <?php endforeach; ?>
        </div>
    </article>

    <div class="grid-two">
        <article class="panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow"><?= e(t('workout_types.title')) ?></p>
                    <h2><?= e(t('workout_types.saved')) ?></h2>
                </div>
            </div>
            <div class="card-list">
                <?php foreach (($workoutTypes ?? []) as $type): ?>
                    <form method="post" action="/?page=admin" class="mini-card editable-card">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="update_workout_type">
                        <input type="hidden" name="type_id" value="<?= (int) $type['id'] ?>">
                        <input type="text" name="name" value="<?= e((string) $type['name']) ?>">
                        <label class="check"><input type="checkbox" name="active" value="1" <?= (int) $type['active'] === 1 ? 'checked' : '' ?>><?= e(t('common.active')) ?></label>
                        <button class="btn small btn-primary" type="submit"><?= e(t('common.save')) ?></button>
                    </form>
                <?php endforeach; ?>
            </div>
        </article>

        <article class="panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow"><?= e(t('achievements.manual')) ?></p>
                    <h2><?= e(t('achievements.manage')) ?></h2>
                </div>
            </div>
            <form method="post" action="/?page=admin" enctype="multipart/form-data" class="stack compact-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="create_achievement">
                <div class="grid-inline">
                    <label><?= e(t('achievements.name')) ?><input type="text" name="name" required></label>
                    <label><?= e(t('achievements.scope')) ?><select name="scope"><option value="user"><?= e(t('common.user')) ?></option><option value="team"><?= e(t('nav.team')) ?></option></select></label>
                    <label><?= e(t('achievements.description')) ?><input type="text" name="description"></label>
                </div>
                <div class="grid-inline">
                    <label><?= e(t('achievements.reward')) ?><input type="text" name="reward_text"></label>
                    <label><?= e(t('common.photo')) ?><input type="file" name="image" accept="image/*"></label>
                    <label class="check"><input type="checkbox" name="conditional" value="1"><?= e(t('achievements.conditional')) ?></label>
                </div>
                <div class="grid-inline">
                    <label><?= e(t('achievements.metric')) ?><input type="text" name="metric_key" placeholder="steps / km / workouts / habit:journaling"></label>
                    <label><?= e(t('achievements.operator')) ?><select name="operator"><option value=">=">&gt;=</option><option value="<=">&lt;=</option><option value="=">=</option></select></label>
                    <label><?= e(t('achievements.target')) ?><input type="number" step="0.1" name="target_value" value="1"></label>
                    <label><?= e(t('achievements.window')) ?><select name="window"><option value="total">total</option><option value="week">week</option><option value="current_challenge">challenge</option></select></label>
                </div>
                <button class="btn btn-primary" type="submit"><?= e(t('achievements.create')) ?></button>
            </form>
            <form method="post" action="/?page=admin" class="stack compact-form">
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
        </article>
    </div>

    <article class="panel">
        <div class="panel-head">
            <div>
                <p class="eyebrow"><?= e(t('audit.title')) ?></p>
                <h2><?= e(t('audit.subtitle')) ?></h2>
            </div>
        </div>
        <form method="get" action="/" class="control-strip audit-filter">
            <input type="hidden" name="page" value="admin">
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
