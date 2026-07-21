<?php

declare(strict_types=1);

$days = [];
for ($i = 0; $i < 7; $i++) {
    $days[$i] = t('weekday.' . $i);
}
?>
<section class="screen stack-lg">
    <div class="hero-panel app-page-hero">
        <div class="hero-copy hero-copy-page-title">
            <p class="eyebrow"><?= e(t('nav.users')) ?></p>
            <h1><?= e(t('users.title')) ?></h1>
            <p class="muted"><?= e(t('users.subtitle')) ?></p>
        </div>
    </div>

    <article class="panel">
        <div class="panel-head">
            <div>
                <p class="eyebrow"><?= e(t('common.active')) ?></p>
                <h2><?= e(t('users.create')) ?></h2>
            </div>
        </div>
        <form method="post" action="/?page=users" class="stack">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="create_user">

            <div class="grid-inline">
                <label>
                    <?= e(t('common.username')) ?>
                    <input type="text" name="username" required>
                </label>
                <label>
                    <?= e(t('common.display_name')) ?>
                    <input type="text" name="display_name" required>
                </label>
                <label>
                    <?= e(t('users.initial_password')) ?>
                    <input type="password" name="password" required>
                </label>
                <label>
                    <?= e(t('common.role')) ?>
                    <select name="role">
                        <option value="user">User</option>
                        <option value="admin"><?= e(t('common.admin')) ?></option>
                    </select>
                </label>
            </div>

            <div class="grid-inline">
                <label>
                    <?= e(t('users.step_goal')) ?>
                    <input type="number" min="0" name="step_goal" value="10000" required>
                </label>
                <label>
                    <?= e(t('users.workout_target_week')) ?>
                    <input type="number" min="0" name="workout_target" value="3" required>
                </label>
                <label>
                    <?= e(t('metric.ideal_weight')) ?> (kg)
                    <input type="number" step="0.1" name="ideal_weight">
                </label>
                <label>
                    <?= e(t('users.workout_strict_days')) ?>
                    <select name="workout_strict">
                        <option value="0"><?= e(t('common.no')) ?></option>
                        <option value="1"><?= e(t('common.yes')) ?></option>
                    </select>
                </label>
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

            <label class="check standalone-check">
                <input type="checkbox" name="active" value="1" checked>
                <?= e(t('users.active_user')) ?>
            </label>

            <button type="submit" class="btn btn-primary"><?= e(t('users.create')) ?></button>
        </form>
    </article>

    <article class="panel">
        <div class="panel-head">
            <div>
                <p class="eyebrow"><?= e(t('common.user')) ?></p>
                <h2><?= e(t('users.edit_existing')) ?></h2>
            </div>
        </div>
        <div class="stack">
            <?php foreach ($users as $user): ?>
                <form method="post" action="/?page=users" class="user-edit-card">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="update_user">
                    <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">

                    <div class="grid-inline">
                        <label>
                            <?= e(t('common.username')) ?>
                            <input type="text" value="<?= e((string) $user['username']) ?>" disabled>
                        </label>
                        <label>
                            <?= e(t('common.display_name')) ?>
                            <input type="text" name="display_name" value="<?= e((string) $user['display_name']) ?>" required>
                        </label>
                        <label>
                            <?= e(t('common.role')) ?>
                            <select name="role">
                                <option value="user" <?= $user['role'] === 'user' ? 'selected' : '' ?>>User</option>
                                <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>><?= e(t('common.admin')) ?></option>
                            </select>
                        </label>
                        <label>
                            <?= e(t('users.new_password_optional')) ?>
                            <input type="password" name="password" minlength="8">
                        </label>
                    </div>

                    <div class="grid-inline">
                        <label>
                            <?= e(t('users.step_goal')) ?>
                            <input type="number" min="0" name="step_goal" value="<?= e((string) $user['step_goal']) ?>" required>
                        </label>
                        <label>
                            <?= e(t('profile.workout_target')) ?>
                            <input type="number" min="0" name="workout_target" value="<?= e((string) $user['workout_target']) ?>" required>
                        </label>
                        <label>
                            <?= e(t('metric.ideal_weight')) ?>
                            <input type="number" step="0.1" name="ideal_weight" value="<?= e((string) ($user['ideal_weight'] ?? '')) ?>">
                        </label>
                        <label>
                            <?= e(t('profile.workout_strict')) ?>
                            <select name="workout_strict">
                                <option value="0" <?= (int) $user['workout_strict'] === 0 ? 'selected' : '' ?>><?= e(t('common.no')) ?></option>
                                <option value="1" <?= (int) $user['workout_strict'] === 1 ? 'selected' : '' ?>><?= e(t('common.yes')) ?></option>
                            </select>
                        </label>
                    </div>

                    <div class="chip-group">
                        <span><?= e(t('profile.step_days')) ?>:</span>
                        <?php foreach ($days as $idx => $label): ?>
                            <label class="chip">
                                <input
                                    type="checkbox"
                                    name="step_days[]"
                                    value="<?= $idx ?>"
                                    <?= isset($user['step_days_mask'][$idx]) && $user['step_days_mask'][$idx] === '1' ? 'checked' : '' ?>
                                > <?= e($label) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div class="chip-group">
                        <span><?= e(t('profile.workout_days')) ?>:</span>
                        <?php foreach ($days as $idx => $label): ?>
                            <label class="chip">
                                <input
                                    type="checkbox"
                                    name="workout_days[]"
                                    value="<?= $idx ?>"
                                    <?= isset($user['workout_days_mask'][$idx]) && $user['workout_days_mask'][$idx] === '1' ? 'checked' : '' ?>
                                > <?= e($label) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <label class="check standalone-check">
                        <input type="checkbox" name="active" value="1" <?= (int) $user['active'] === 1 ? 'checked' : '' ?>>
                        <?= e(t('common.active')) ?>
                    </label>

                    <button type="submit" class="btn btn-secondary"><?= e(t('users.save_user', ['name' => (string) $user['display_name']])) ?></button>
                </form>
            <?php endforeach; ?>
        </div>
    </article>
</section>
