<?php

declare(strict_types=1);

$profileUser = $profileUser ?? $currentUser;
$isOwnProfile = (bool) ($isOwnProfile ?? ((int) ($profileUser['id'] ?? 0) === (int) ($currentUser['id'] ?? 0)));
$canEditProfile = (bool) ($canEditProfile ?? $isOwnProfile);
$profileBaseUrl = (string) ($profileBaseUrl ?? '/?page=profile');

$activeSection = (string) ($_GET['section'] ?? '');
$allowedSections = ['goals', 'achievements', 'config', 'activity'];
if (!in_array($activeSection, $allowedSections, true)) {
    $activeSection = '';
}

$goalCreateMode = (string) ($_GET['goal_new'] ?? '') === '1';
$goalDetailId = isset($_GET['goal_id']) ? (int) $_GET['goal_id'] : 0;
$configEditMode = $canEditProfile && (string) ($_GET['edit'] ?? '') === '1';

$profileQueryBase = ['page' => 'profile'];
if (!$isOwnProfile) {
    $profileQueryBase['user_id'] = (int) ($profileUser['id'] ?? 0);
}
$profileUrl = static function (string $section = '', array $extra = []) use ($profileQueryBase): string {
    $query = array_merge($profileQueryBase, $extra);
    if ($section !== '') {
        $query['section'] = $section;
    }

    return '/?' . http_build_query($query);
};

$activeGoals = array_values(array_filter((array) ($personalGoals ?? []), static fn(array $goal): bool => (string) ($goal['status'] ?? 'active') === 'active'));
$achievementCount = count($userAchievements ?? []);
$activityCount = count($recentActivity ?? []);
$goalPreview = array_slice(array_map(static fn(array $goal): string => (string) $goal['title'], $activeGoals), 0, 2);
$achievementPreview = array_slice(array_map(static fn(array $achievement): string => (string) $achievement['name'], (array) ($userAchievements ?? [])), 0, 3);
$activityPreview = array_slice(array_map(static fn(array $item): string => (string) $item['summary'], (array) ($recentActivity ?? [])), 0, 3);
?>
<section class="screen stack-lg spa-shell" data-spa-page="profile">
    <div class="hero-panel profile-hero">
        <div class="profile-title">
            <?php if (!empty($profileUser['avatar_path'])): ?>
                <img class="profile-avatar" src="<?= e((string) $profileUser['avatar_path']) ?>" alt="<?= e((string) $profileUser['display_name']) ?>">
            <?php else: ?>
                <span class="profile-avatar initials"><?= e(initials_for((string) $profileUser['display_name'])) ?></span>
            <?php endif; ?>
            <div>
                <p class="eyebrow"><?= e(t('nav.profile')) ?></p>
                <h1><?= e((string) $profileUser['display_name']) ?></h1>
                <p class="muted">@<?= e((string) $profileUser['username']) ?> · <?= e(t('profile.subtitle')) ?><?= !$isOwnProfile ? ' · Solo lectura' : '' ?></p>
            </div>
        </div>
        <div class="chip-group">
            <?php if (is_admin($currentUser)): ?>
                <a class="btn btn-secondary" href="/?page=admin"><?= e(t('nav.admin')) ?></a>
            <?php endif; ?>
            <a class="btn btn-primary" href="/?page=settings"><?= e(t('nav.settings')) ?></a>
            <a class="btn btn-ghost" href="/?page=settings#avatar"><?= e(t('settings.change_avatar')) ?></a>
            <a class="btn btn-ghost" href="/?page=logout"><?= e(t('nav.logout')) ?></a>
        </div>
    </div>

    <article class="panel settings-list<?= $activeSection !== '' ? ' hidden' : '' ?>" data-spa-main <?= $activeSection !== '' ? 'hidden' : '' ?>>
        <a class="settings-row" href="<?= e($profileUrl('goals')) ?>" data-spa-link>
            <span>
                <strong><?= e(t('goals.personal')) ?></strong>
                <small class="muted"><?= count($activeGoals) ?> activos · <?= e($goalPreview !== [] ? implode(' · ', $goalPreview) : t('goals.empty')) ?></small>
            </span>
            <span class="settings-chevron" aria-hidden="true">›</span>
        </a>
        <a class="settings-row" href="<?= e($profileUrl('achievements')) ?>" data-spa-link>
            <span>
                <strong><?= e(t('profile.achievements')) ?></strong>
                <small class="muted"><?= $achievementCount ?> desbloqueados · <?= e($achievementPreview !== [] ? implode(' · ', $achievementPreview) : t('achievements.empty')) ?></small>
            </span>
            <span class="settings-chevron" aria-hidden="true">›</span>
        </a>
        <a class="settings-row" href="<?= e($profileUrl('config')) ?>" data-spa-link>
            <span>
                <strong><?= e(t('profile.current_config')) ?></strong>
                <small class="muted"><?= e((string) $profileUser['username']) ?> · <?= e((string) ($profileUser['primary_goal_type'] ?? 'steps')) ?></small>
            </span>
            <span class="settings-chevron" aria-hidden="true">›</span>
        </a>
        <a class="settings-row" href="<?= e($profileUrl('activity')) ?>" data-spa-link>
            <span>
                <strong><?= e(t('profile.recent_activity')) ?></strong>
                <small class="muted"><?= $activityCount ?> eventos · <?= e($activityPreview !== [] ? implode(' · ', $activityPreview) : t('audit.empty')) ?></small>
            </span>
            <span class="settings-chevron" aria-hidden="true">›</span>
        </a>
    </article>

    <article class="panel settings-panel<?= $activeSection === 'goals' ? ' active' : '' ?>" data-spa-section="goals" <?= $activeSection === 'goals' ? '' : 'hidden' ?>>
        <div class="panel-head">
            <h2><?= e(t('goals.personal')) ?></h2>
            <div class="inline-actions-mini">
                <?php if ($canEditProfile): ?>
                    <a class="btn btn-primary" href="<?= e($profileUrl('goals', ['goal_new' => 1])) ?>" data-spa-link>Nuevo objetivo</a>
                <?php endif; ?>
                <a class="btn btn-ghost" href="<?= e($profileUrl()) ?>" data-spa-back><?= e(t('common.cancel')) ?></a>
            </div>
        </div>

        <div class="settings-list" data-profile-goals-list data-spa-show-when-no-param="goal_id,goal_new" <?= $goalCreateMode || $goalDetailId > 0 ? 'hidden' : '' ?>>
            <?php if (($personalGoals ?? []) === []): ?>
                <p class="muted panel-inline-empty"><?= e(t('goals.empty')) ?></p>
            <?php else: ?>
                <?php foreach ($personalGoals as $goal): ?>
                    <a class="settings-row goal-row" href="<?= e($profileUrl('goals', ['goal_id' => (int) $goal['id']])) ?>" data-spa-link>
                        <span>
                            <strong><?= e((string) $goal['title']) ?></strong>
                            <small class="muted"><?= e((string) $goal['target_type']) ?> · <?= e((string) ($goal['target_value'] ?? '-')) ?> · <?= e((string) ($goal['status'] ?? 'active')) ?></small>
                        </span>
                        <span class="settings-chevron" aria-hidden="true">›</span>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if ($canEditProfile): ?>
            <div class="stack goal-subview" data-spa-param-show="goal_new" data-spa-value="1" <?= $goalCreateMode ? '' : 'hidden' ?>>
                <div class="panel-head compact-head">
                    <h3>Nuevo objetivo</h3>
                    <a class="btn btn-ghost" href="<?= e($profileUrl('goals')) ?>" data-spa-link><?= e(t('common.cancel')) ?></a>
                </div>
                <form method="post" action="<?= e($profileUrl('goals')) ?>" class="stack compact-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="create_goal">
                    <input type="hidden" name="profile_user_id" value="<?= (int) $profileUser['id'] ?>">
                    <label><?= e(t('goals.goal_name')) ?><input type="text" name="title" placeholder="<?= e(t('goals.placeholder')) ?>" required></label>
                    <div class="grid-inline two">
                        <label>
                            <?= e(t('goals.type')) ?>
                            <select name="target_type">
                                <option value="steps"><?= e(t('metric.steps')) ?></option>
                                <option value="km"><?= e(t('metric.distance_km')) ?></option>
                                <option value="workouts"><?= e(t('metric.workouts')) ?></option>
                                <option value="weight"><?= e(t('metric.weight')) ?></option>
                                <?php foreach (($habits ?? []) as $habit): ?>
                                    <option value="habit:<?= e((string) $habit['code']) ?>"><?= e((string) $habit['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label><?= e(t('goals.target')) ?><input type="number" step="0.1" name="target_value"></label>
                        <label><?= e(t('goals.due_date')) ?><input type="date" name="due_date"></label>
                    </div>
                    <button class="btn btn-primary" type="submit"><?= e(t('goals.add')) ?></button>
                </form>
            </div>
        <?php endif; ?>

        <?php foreach (($personalGoals ?? []) as $goal): ?>
            <?php $isActiveGoalDetail = $goalDetailId === (int) $goal['id']; ?>
            <?php $editFormId = 'goal-edit-form-' . (int) $goal['id']; ?>
            <div class="stack goal-subview" data-spa-param-show="goal_id" data-spa-value="<?= (int) $goal['id'] ?>" <?= $isActiveGoalDetail ? '' : 'hidden' ?>>
                <article class="mini-card goal-detail-card">
                    <div class="goal-detail-meta">
                        <strong><?= e((string) $goal['title']) ?></strong>
                        <span><?= e((string) $goal['target_type']) ?> · <?= e((string) ($goal['target_value'] ?? '-')) ?></span>
                        <span><?= e(t('common.status')) ?>: <?= e((string) ($goal['status'] ?? 'active')) ?><?php if (!empty($goal['due_date'])): ?> · <?= e(t('goals.due_date')) ?>: <?= e(format_date_eu((string) $goal['due_date'])) ?><?php endif; ?></span>
                    </div>
                    <div class="goal-detail-actions">
                        <?php if ($canEditProfile): ?>
                            <button class="btn small btn-ghost" type="button" data-goal-edit-toggle data-target="<?= e($editFormId) ?>"><?= e(t('common.edit')) ?></button>
                            <form method="post" action="<?= e($profileUrl('goals')) ?>">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="goal_status">
                                <input type="hidden" name="goal_id" value="<?= (int) $goal['id'] ?>">
                                <input type="hidden" name="status" value="complete">
                                <input type="hidden" name="profile_user_id" value="<?= (int) $profileUser['id'] ?>">
                                <button class="btn small btn-ghost" type="submit"><?= e(t('common.complete')) ?></button>
                            </form>
                            <form method="post" action="<?= e($profileUrl('goals')) ?>">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete_goal">
                                <input type="hidden" name="goal_id" value="<?= (int) $goal['id'] ?>">
                                <input type="hidden" name="profile_user_id" value="<?= (int) $profileUser['id'] ?>">
                                <button class="btn small btn-ghost" type="submit" data-goal-delete-confirm><?= e(t('common.delete')) ?></button>
                            </form>
                        <?php endif; ?>
                        <a class="btn small btn-ghost" href="<?= e($profileUrl('goals')) ?>" data-spa-link>Volver</a>
                    </div>
                </article>

                <?php if ($canEditProfile): ?>
                    <form method="post" action="<?= e($profileUrl('goals')) ?>" class="mini-card editable-card goal-editor" id="<?= e($editFormId) ?>" data-goal-edit-form hidden>
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="update_goal">
                        <input type="hidden" name="goal_id" value="<?= (int) $goal['id'] ?>">
                        <input type="hidden" name="profile_user_id" value="<?= (int) $profileUser['id'] ?>">
                        <div class="goal-editor-grid">
                            <label><?= e(t('goals.goal_name')) ?><input type="text" name="title" value="<?= e((string) $goal['title']) ?>"></label>
                            <label><?= e(t('goals.type')) ?><input type="text" name="target_type" value="<?= e((string) $goal['target_type']) ?>"></label>
                            <label><?= e(t('goals.target')) ?><input type="number" step="0.1" name="target_value" value="<?= e((string) ($goal['target_value'] ?? '')) ?>"></label>
                            <label><?= e(t('goals.due_date')) ?><input type="date" name="due_date" value="<?= e((string) ($goal['due_date'] ?? '')) ?>"></label>
                        </div>
                        <div class="goal-editor-actions">
                            <button class="btn small btn-primary" type="submit"><?= e(t('common.save')) ?></button>
                            <button class="btn small btn-ghost" type="button" data-goal-edit-cancel data-target="<?= e($editFormId) ?>"><?= e(t('common.cancel')) ?></button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </article>

    <article class="panel settings-panel<?= $activeSection === 'achievements' ? ' active' : '' ?>" data-spa-section="achievements" <?= $activeSection === 'achievements' ? '' : 'hidden' ?>>
        <div class="panel-head">
            <h2><?= e(t('profile.achievements')) ?></h2>
            <a class="btn btn-ghost" href="<?= e($profileUrl()) ?>" data-spa-back><?= e(t('common.cancel')) ?></a>
        </div>
        <div class="achievement-grid achievement-grid-collapsible" data-achievement-grid>
            <?php if (($userAchievements ?? []) === []): ?>
                <p class="muted"><?= e(t('achievements.empty')) ?></p>
            <?php else: ?>
                <?php foreach ($userAchievements as $achievement): ?>
                    <?php $deleteFormId = 'delete-achievement-profile-' . (int) $achievement['id']; ?>
                    <article class="achievement-card">
                        <?php if (!empty($achievement['image_path'])): ?><img src="<?= e((string) $achievement['image_path']) ?>" alt="<?= e((string) $achievement['name']) ?>"><?php else: ?><span>*</span><?php endif; ?>
                        <strong><?= e((string) $achievement['name']) ?></strong>
                        <p><?= e((string) $achievement['description']) ?></p>
                        <?php if (!empty($achievement['reward_text'])): ?><small><?= e(t('achievements.reward')) ?>: <?= e((string) $achievement['reward_text']) ?></small><?php endif; ?>
                        <?php if (!empty($canDeleteAchievements)): ?>
                            <form method="post" action="<?= e($profileUrl('achievements')) ?>" class="achievement-remove" id="<?= e($deleteFormId) ?>">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete_achievement_award">
                                <input type="hidden" name="award_id" value="<?= (int) $achievement['id'] ?>">
                                <button class="achievement-delete-btn" type="button" aria-label="Eliminar logro" data-achievement-delete-trigger data-form-id="<?= e($deleteFormId) ?>">×</button>
                            </form>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php if (count($userAchievements ?? []) > 4): ?>
            <button class="btn btn-ghost btn-block js-toggle-achievements" type="button" data-expand-label="<?= e(t('common.view_all')) ?>" data-collapse-label="<?= e(t('common.view_less')) ?>"><?= e(t('common.view_all')) ?></button>
        <?php endif; ?>
    </article>

    <article class="panel settings-panel<?= $activeSection === 'config' ? ' active' : '' ?>" data-spa-section="config" <?= $activeSection === 'config' ? '' : 'hidden' ?>>
        <div class="panel-head">
            <h2><?= e(t('profile.current_config')) ?></h2>
            <div class="inline-actions-mini">
                <?php if ($canEditProfile): ?>
                    <a class="btn btn-ghost" href="<?= e($profileUrl('config', ['edit' => 1])) ?>" data-config-edit-link><?= e(t('common.edit')) ?></a>
                <?php endif; ?>
                <a class="btn btn-ghost" href="<?= e($profileUrl()) ?>" data-spa-back><?= e(t('common.cancel')) ?></a>
            </div>
        </div>

        <div class="profile-config" data-config-editor>
            <ul class="facts" data-config-readonly data-spa-show-when-no-param="edit" <?= $configEditMode ? 'hidden' : '' ?>>
                <li><strong><?= e(t('common.username')) ?>:</strong> <?= e((string) $profileUser['username']) ?></li>
                <li><strong><?= e(t('settings.primary_goal')) ?>:</strong> <?= e((string) ($profileUser['primary_goal_type'] ?? 'steps')) ?> <?= e((string) ($profileUser['primary_goal_value'] ?? $profileUser['step_goal'])) ?></li>
                <li><strong><?= e(t('profile.workout_target')) ?>:</strong> <?= e((string) $profileUser['workout_target']) ?>/<?= e(strtolower(t('common.week'))) ?></li>
                <li><strong><?= e(t('metric.ideal_weight')) ?>:</strong> <?= $profileUser['ideal_weight'] !== null ? e((string) $profileUser['ideal_weight']) . ' kg' : '-' ?></li>
            </ul>

            <?php if ($canEditProfile): ?>
                <form method="post" action="<?= e($profileUrl('config')) ?>" class="stack" data-config-form data-spa-param-show="edit" data-spa-value="1" <?= $configEditMode ? '' : 'hidden' ?>>
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="update_profile_config">
                    <input type="hidden" name="profile_user_id" value="<?= (int) $profileUser['id'] ?>">
                    <div class="grid-inline two">
                        <label><?= e(t('common.username')) ?><input type="text" value="<?= e((string) $profileUser['username']) ?>" disabled></label>
                        <label>
                            <?= e(t('settings.primary_goal')) ?>
                            <select name="primary_goal_type">
                                <option value="steps" <?= ($profileUser['primary_goal_type'] ?? 'steps') === 'steps' ? 'selected' : '' ?>><?= e(t('metric.steps')) ?></option>
                                <option value="km" <?= ($profileUser['primary_goal_type'] ?? 'steps') === 'km' ? 'selected' : '' ?>><?= e(t('metric.distance_km')) ?></option>
                                <option value="workouts" <?= ($profileUser['primary_goal_type'] ?? 'steps') === 'workouts' ? 'selected' : '' ?>><?= e(t('metric.workouts')) ?></option>
                            </select>
                        </label>
                        <label><?= e(t('settings.primary_goal_value')) ?><input type="number" step="0.1" name="primary_goal_value" value="<?= e((string) ($profileUser['primary_goal_value'] ?? '')) ?>"></label>
                        <label><?= e(t('profile.workout_target')) ?><input type="number" min="0" name="workout_target" value="<?= e((string) ($profileUser['workout_target'] ?? 0)) ?>"></label>
                        <label><?= e(t('metric.ideal_weight')) ?><input type="number" step="0.1" name="ideal_weight" value="<?= e((string) ($profileUser['ideal_weight'] ?? '')) ?>"></label>
                    </div>
                    <div class="goal-editor-actions">
                        <button class="btn btn-primary" type="submit">Guardar</button>
                        <a class="btn btn-ghost" href="<?= e($profileUrl('config')) ?>" data-config-cancel-link>Cancelar</a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </article>

    <article class="panel settings-panel<?= $activeSection === 'activity' ? ' active' : '' ?>" data-spa-section="activity" <?= $activeSection === 'activity' ? '' : 'hidden' ?>>
        <div class="panel-head">
            <h2><?= e(t('profile.recent_activity')) ?></h2>
            <a class="btn btn-ghost" href="<?= e($profileUrl()) ?>" data-spa-back><?= e(t('common.cancel')) ?></a>
        </div>
        <div class="audit-list">
            <?php foreach (($recentActivity ?? []) as $item): ?>
                <article><strong><?= e((string) $item['summary']) ?></strong><span><?= e((string) $item['action']) ?> · <?= e(format_date_eu((string) $item['created_at'])) ?></span></article>
            <?php endforeach; ?>
            <?php if (($recentActivity ?? []) === []): ?><p class="muted"><?= e(t('audit.empty')) ?></p><?php endif; ?>
        </div>
    </article>
</section>
