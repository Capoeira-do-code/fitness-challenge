<?php

declare(strict_types=1);

$days = [];
for ($i = 0; $i < 7; $i++) {
    $days[$i] = t('weekday.' . $i);
}
$entityTypes = ['daily_log', 'approval_request', 'user', 'team_membership', 'goal', 'achievement', 'workout_type', 'photo_entry'];
$activeSection = (string) ($_GET['section'] ?? '');
$allowedSections = ['users', 'challenge', 'app', 'habits', 'workout_types', 'achievements', 'audit'];
if (!in_array($activeSection, $allowedSections, true)) {
    $activeSection = '';
}
$selectedUserId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
$createUserMode = (string) ($_GET['create_user'] ?? '') === '1';
$selectedHabitId = (string) ($_GET['habit_id'] ?? '');
$selectedTypeId = (string) ($_GET['type_id'] ?? '');
$sectionRows = [
    'users' => 'Users',
    'challenge' => 'Challenge',
    'app' => 'App',
    'habits' => 'Habits',
    'workout_types' => 'Workout Types',
    'achievements' => 'Achievements',
    'audit' => 'Audit Log',
];
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
        <div class="panel-head">
            <h2>Users</h2>
            <a class="btn btn-ghost" href="/?page=admin" data-spa-back><?= e(t('common.cancel')) ?></a>
        </div>

        <div class="settings-list compact-list">
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

        <div class="stack" data-spa-param-show="create_user" data-spa-value="1" <?= $createUserMode ? '' : 'hidden' ?>>
            <h3><?= e(t('users.create')) ?></h3>
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
            <div class="stack" data-spa-param-show="user_id" data-spa-value="<?= (int) $user['id'] ?>" <?= $selectedUserId === (int) $user['id'] ? '' : 'hidden' ?>>
                <h3><?= e((string) $user['display_name']) ?></h3>
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
            <a class="btn btn-ghost" href="/?page=admin" data-spa-back><?= e(t('common.cancel')) ?></a>
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
            <a class="btn btn-ghost" href="/?page=admin" data-spa-back><?= e(t('common.cancel')) ?></a>
        </div>
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

    <article class="panel settings-panel<?= $activeSection === 'habits' ? ' active' : '' ?>" data-spa-section="habits" <?= $activeSection === 'habits' ? '' : 'hidden' ?>>
        <div class="panel-head">
            <h2>Habits</h2>
            <a class="btn btn-ghost" href="/?page=admin" data-spa-back><?= e(t('common.cancel')) ?></a>
        </div>

        <div class="settings-list compact-list">
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

        <form method="post" action="/?page=admin" class="mini-card editable-card compact-form" data-spa-param-show="habit_id" data-spa-value="new" <?= $selectedHabitId === 'new' ? '' : 'hidden' ?>>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_habit">
            <input type="text" name="code" placeholder="custom_habit">
            <input type="text" name="label" placeholder="<?= e(t('admin.habit_label')) ?>">
            <input type="number" name="sort_order" value="50">
            <label class="check"><input type="checkbox" name="active" value="1" checked><?= e(t('common.active')) ?></label>
            <button class="btn small btn-primary" type="submit"><?= e(t('common.save')) ?></button>
        </form>

        <?php foreach (($habits ?? []) as $habit): ?>
            <form method="post" action="/?page=admin" class="mini-card editable-card" data-spa-param-show="habit_id" data-spa-value="<?= (int) $habit['id'] ?>" <?= $selectedHabitId === (string) ((int) $habit['id']) ? '' : 'hidden' ?>>
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
    </article>

    <article class="panel settings-panel<?= $activeSection === 'workout_types' ? ' active' : '' ?>" data-spa-section="workout_types" <?= $activeSection === 'workout_types' ? '' : 'hidden' ?>>
        <div class="panel-head">
            <h2>Workout Types</h2>
            <a class="btn btn-ghost" href="/?page=admin" data-spa-back><?= e(t('common.cancel')) ?></a>
        </div>

        <div class="settings-list compact-list">
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

        <form method="post" action="/?page=admin" class="mini-card editable-card" data-spa-param-show="type_id" data-spa-value="new" <?= $selectedTypeId === 'new' ? '' : 'hidden' ?>>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="create_workout_type">
            <input type="text" name="name" placeholder="<?= e(t('entries.workout_type')) ?>" required>
            <button class="btn small btn-primary" type="submit"><?= e(t('common.save')) ?></button>
        </form>

        <?php foreach (($workoutTypes ?? []) as $type): ?>
            <form method="post" action="/?page=admin" class="mini-card editable-card" data-spa-param-show="type_id" data-spa-value="<?= (int) $type['id'] ?>" <?= $selectedTypeId === (string) ((int) $type['id']) ? '' : 'hidden' ?>>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="update_workout_type">
                <input type="hidden" name="type_id" value="<?= (int) $type['id'] ?>">
                <input type="text" name="name" value="<?= e((string) $type['name']) ?>">
                <label class="check"><input type="checkbox" name="active" value="1" <?= (int) $type['active'] === 1 ? 'checked' : '' ?>><?= e(t('common.active')) ?></label>
                <button class="btn small btn-primary" type="submit"><?= e(t('common.save')) ?></button>
            </form>
        <?php endforeach; ?>
    </article>

    <article class="panel settings-panel<?= $activeSection === 'achievements' ? ' active' : '' ?>" data-spa-section="achievements" <?= $activeSection === 'achievements' ? '' : 'hidden' ?>>
        <div class="panel-head">
            <h2>Achievements</h2>
            <a class="btn btn-ghost" href="/?page=admin" data-spa-back><?= e(t('common.cancel')) ?></a>
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
    </article>

    <article class="panel settings-panel<?= $activeSection === 'audit' ? ' active' : '' ?>" data-spa-section="audit" <?= $activeSection === 'audit' ? '' : 'hidden' ?>>
        <div class="panel-head">
            <h2>Audit Log</h2>
            <a class="btn btn-ghost" href="/?page=admin" data-spa-back><?= e(t('common.cancel')) ?></a>
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
