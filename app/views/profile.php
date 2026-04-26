<?php

declare(strict_types=1);
?>
<section class="screen stack-lg">
    <div class="hero-panel profile-hero">
        <div class="profile-title">
            <?php if (!empty($currentUser['avatar_path'])): ?>
                <img class="profile-avatar" src="<?= e((string) $currentUser['avatar_path']) ?>" alt="<?= e((string) $currentUser['display_name']) ?>">
            <?php else: ?>
                <span class="profile-avatar initials"><?= e(initials_for((string) $currentUser['display_name'])) ?></span>
            <?php endif; ?>
            <div>
                <p class="eyebrow"><?= e(t('nav.profile')) ?></p>
                <h1><?= e((string) $currentUser['display_name']) ?></h1>
                <p class="muted"><?= e(t('profile.subtitle')) ?></p>
            </div>
        </div>
        <div class="chip-group">
            <?php if (is_admin($currentUser)): ?>
                <a class="btn btn-secondary" href="/?page=admin"><?= e(t('nav.admin')) ?></a>
            <?php endif; ?>
            <a class="btn btn-primary" href="/?page=settings"><?= e(t('nav.settings')) ?></a>
        </div>
    </div>

    <div class="grid-two">
        <article class="panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow"><?= e(t('goals.personal')) ?></p>
                    <h2><?= e(t('goals.title')) ?></h2>
                </div>
            </div>
            <form method="post" action="/?page=profile" class="stack compact-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="create_goal">
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

            <div class="card-list">
                <?php if (($personalGoals ?? []) === []): ?>
                    <p class="muted"><?= e(t('goals.empty')) ?></p>
                <?php else: ?>
                    <?php foreach ($personalGoals as $goal): ?>
                        <form method="post" action="/?page=profile" class="mini-card editable-card">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="update_goal">
                            <input type="hidden" name="goal_id" value="<?= (int) $goal['id'] ?>">
                            <input type="text" name="title" value="<?= e((string) $goal['title']) ?>">
                            <input type="text" name="target_type" value="<?= e((string) $goal['target_type']) ?>">
                            <input type="number" step="0.1" name="target_value" value="<?= e((string) ($goal['target_value'] ?? '')) ?>">
                            <input type="date" name="due_date" value="<?= e((string) ($goal['due_date'] ?? '')) ?>">
                            <button class="btn small btn-primary" type="submit"><?= e(t('common.save')) ?></button>
                            <button class="btn small btn-ghost" name="action" value="goal_status" type="submit" formaction="/?page=profile" onclick="this.form.status.value='complete'"><?= e(t('common.complete')) ?></button>
                            <input type="hidden" name="status" value="active">
                        </form>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </article>

        <article class="panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow"><?= e(t('achievements.title')) ?></p>
                    <h2><?= e(t('profile.achievements')) ?></h2>
                </div>
                <span class="badge"><?= count($userAchievements ?? []) ?></span>
            </div>
            <div class="achievement-grid">
                <?php if (($userAchievements ?? []) === []): ?>
                    <p class="muted"><?= e(t('achievements.empty')) ?></p>
                <?php else: ?>
                    <?php foreach ($userAchievements as $achievement): ?>
                        <article class="achievement-card">
                            <?php if (!empty($achievement['image_path'])): ?><img src="<?= e((string) $achievement['image_path']) ?>" alt="<?= e((string) $achievement['name']) ?>"><?php else: ?><span>*</span><?php endif; ?>
                            <strong><?= e((string) $achievement['name']) ?></strong>
                            <p><?= e((string) $achievement['description']) ?></p>
                            <?php if (!empty($achievement['reward_text'])): ?><small><?= e(t('achievements.reward')) ?>: <?= e((string) $achievement['reward_text']) ?></small><?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </article>
    </div>

    <div class="grid-two">
        <article class="panel">
            <h2><?= e(t('profile.current_config')) ?></h2>
            <ul class="facts">
                <li><strong><?= e(t('common.username')) ?>:</strong> <?= e((string) $currentUser['username']) ?></li>
                <li><strong><?= e(t('settings.primary_goal')) ?>:</strong> <?= e((string) ($currentUser['primary_goal_type'] ?? 'steps')) ?> <?= e((string) ($currentUser['primary_goal_value'] ?? $currentUser['step_goal'])) ?></li>
                <li><strong><?= e(t('profile.workout_target')) ?>:</strong> <?= e((string) $currentUser['workout_target']) ?>/<?= e(strtolower(t('common.week'))) ?></li>
                <li><strong><?= e(t('metric.ideal_weight')) ?>:</strong> <?= $currentUser['ideal_weight'] !== null ? e((string) $currentUser['ideal_weight']) . ' kg' : '-' ?></li>
            </ul>
        </article>
        <article class="panel">
            <h2><?= e(t('profile.recent_activity')) ?></h2>
            <div class="audit-list">
                <?php foreach (($recentActivity ?? []) as $item): ?>
                    <article><strong><?= e((string) $item['summary']) ?></strong><span><?= e((string) $item['action']) ?> · <?= e(format_date_eu((string) $item['created_at'])) ?></span></article>
                <?php endforeach; ?>
                <?php if (($recentActivity ?? []) === []): ?><p class="muted"><?= e(t('audit.empty')) ?></p><?php endif; ?>
            </div>
        </article>
    </div>

    <a class="btn btn-ghost btn-block" href="/?page=logout"><?= e(t('nav.logout')) ?></a>
</section>
