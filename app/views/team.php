<?php

declare(strict_types=1);

$summary = $teamSummary ?? [];
?>
<section class="screen stack-lg">
    <div class="hero-panel">
        <div>
            <p class="eyebrow"><?= e(t('nav.team')) ?></p>
            <h1><?= e((string) $team['name']) ?></h1>
            <p class="muted"><?= e(t('team.subtitle')) ?></p>
        </div>
        <?php if (!empty($canManageTeam)): ?>
            <a class="btn btn-secondary icon-btn" href="/?page=team_settings&team_id=<?= (int) $team['id'] ?>" aria-label="<?= e(t('team.settings')) ?>"><?= e(t('team.settings_short')) ?></a>
        <?php endif; ?>
    </div>

    <div class="metric-grid">
        <article class="metric-card">
            <div class="progress-ring" style="--value: <?= e((string) min(100, (float) ($summary['score_avg'] ?? 0))) ?>;"><span><?= e((string) ($summary['score_avg'] ?? 0)) ?></span></div>
            <div><span><?= e(t('team.avg_score')) ?></span><strong><?= e((string) ($summary['score_avg'] ?? 0)) ?></strong><p><?= e(t('metric.score')) ?></p></div>
        </article>
        <article class="metric-card">
            <div class="progress-ring" style="--value: <?= e((string) (($summary['steps_required'] ?? 0) > 0 ? min(100, round(((int) $summary['steps_success'] / (int) $summary['steps_required']) * 100)) : 0)) ?>;"><span><?= e((string) ($summary['steps_success'] ?? 0)) ?></span></div>
            <div><span><?= e(t('metric.steps')) ?></span><strong><?= e((string) ($summary['steps_success'] ?? 0)) ?>/<?= e((string) ($summary['steps_required'] ?? 0)) ?></strong><p><?= e(t('metric.progress')) ?></p></div>
        </article>
        <article class="metric-card">
            <div class="progress-ring" style="--value: <?= e((string) (($summary['workout_target'] ?? 0) > 0 ? min(100, round(((int) $summary['workout_success'] / (int) $summary['workout_target']) * 100)) : 0)) ?>;"><span><?= e((string) ($summary['workout_success'] ?? 0)) ?></span></div>
            <div><span><?= e(t('metric.workouts')) ?></span><strong><?= e((string) ($summary['workout_success'] ?? 0)) ?>/<?= e((string) ($summary['workout_target'] ?? 0)) ?></strong><p><?= e(t('metric.progress')) ?></p></div>
        </article>
        <article class="metric-card">
            <div class="progress-ring" style="--value: <?= e((string) max(0, 100 - ((int) ($summary['strikes'] ?? 0) * 10))) ?>;"><span>€<?= e((string) ($summary['penalty'] ?? 0)) ?></span></div>
            <div><span><?= e(t('metric.penalty')) ?></span><strong>€<?= e((string) ($summary['penalty'] ?? 0)) ?></strong><p><?= e(t('metric.strikes')) ?>: <?= e((string) ($summary['strikes'] ?? 0)) ?></p></div>
        </article>
    </div>

    <div class="grid-two">
        <article class="panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow"><?= e(t('team.members')) ?></p>
                    <h2><?= e(t('team.active_members')) ?></h2>
                </div>
                <span class="badge"><?= count($members ?? []) ?></span>
            </div>
            <div class="card-list">
                <?php foreach (($members ?? []) as $member): ?>
                    <article class="mini-card member-card">
                        <div>
                            <strong><?= e((string) $member['display_name']) ?></strong>
                            <span><?= e((string) $member['username']) ?> · <?= e((string) $member['role']) ?></span>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </article>

        <article class="panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow"><?= e(t('goals.team')) ?></p>
                    <h2><?= e(t('goals.title')) ?></h2>
                </div>
            </div>
            <form method="post" action="/?page=team" class="stack compact-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="create_goal">
                <label>
                    <?= e(t('goals.goal_name')) ?>
                    <input type="text" name="title" placeholder="<?= e(t('goals.team_placeholder')) ?>" required>
                </label>
                <div class="grid-inline two">
                    <label>
                        <?= e(t('goals.type')) ?>
                        <select name="target_type">
                            <option value="steps"><?= e(t('metric.steps')) ?></option>
                            <option value="workouts"><?= e(t('metric.workouts')) ?></option>
                            <option value="score"><?= e(t('metric.score')) ?></option>
                            <option value="custom"><?= e(t('common.other')) ?></option>
                        </select>
                    </label>
                    <label>
                        <?= e(t('goals.target')) ?>
                        <input type="number" step="0.1" name="target_value">
                    </label>
                    <label>
                        <?= e(t('goals.due_date')) ?>
                        <input type="date" name="due_date">
                    </label>
                </div>
                <button class="btn btn-primary" type="submit"><?= e(t('goals.add')) ?></button>
            </form>
            <div class="card-list">
                <?php
                $activeTeamGoals = array_values(array_filter($teamGoals ?? [], static fn(array $goal): bool => (string) $goal['status'] !== 'complete'));
                $completedTeamGoals = array_values(array_filter($teamGoals ?? [], static fn(array $goal): bool => (string) $goal['status'] === 'complete'));
                ?>
                <?php if ($activeTeamGoals === []): ?>
                    <p class="muted"><?= e(t('goals.empty')) ?></p>
                <?php else: ?>
                    <?php foreach ($activeTeamGoals as $goal): ?>
                        <article class="mini-card">
                            <div>
                                <strong><?= e((string) $goal['title']) ?></strong>
                                <span><?= e((string) $goal['status']) ?> · <?= e((string) $goal['target_type']) ?> <?= e((string) ($goal['target_value'] ?? '')) ?></span>
                            </div>
                            <form method="post" action="/?page=team" class="inline-actions-mini">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="goal_status">
                                <input type="hidden" name="goal_id" value="<?= (int) $goal['id'] ?>">
                                <button class="btn small btn-ghost" name="status" value="complete" type="submit"><?= e(t('common.complete')) ?></button>
                                <button class="btn small btn-ghost" name="status" value="archived" type="submit"><?= e(t('goals.archive')) ?></button>
                            </form>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <?php if ($completedTeamGoals !== []): ?>
                <h3><?= e(t('goals.completed_team')) ?></h3>
                <div class="card-list">
                    <?php foreach ($completedTeamGoals as $goal): ?>
                        <article class="mini-card">
                            <div>
                                <strong><?= e((string) $goal['title']) ?></strong>
                                <span><?= e((string) $goal['target_type']) ?> <?= e((string) ($goal['target_value'] ?? '')) ?></span>
                            </div>
                            <span class="badge badge-ok"><?= e(t('common.complete')) ?></span>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </article>
    </div>

    <div class="grid-two">
        <article class="panel">
            <h2><?= e(t('dashboard.ranking')) ?></h2>
            <div class="stat-list">
                <?php foreach (($metricsOrdered ?? []) as $metric): ?>
                    <article>
                        <strong><?= e((string) $metric['user']['display_name']) ?></strong>
                        <span><?= e(t('metric.score')) ?> <?= e((string) $metric['score']) ?> · <?= e(t('metric.strikes')) ?> <?= e((string) $metric['current_strikes']) ?> · €<?= e((string) $metric['total_penalty']) ?></span>
                    </article>
                <?php endforeach; ?>
            </div>
        </article>

        <article class="panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow"><?= e(t('achievements.title')) ?></p>
                    <h2><?= e(t('team.achievements')) ?></h2>
                </div>
                <span class="badge"><?= count($teamAchievements ?? []) ?></span>
            </div>
            <?php if (!empty($canManageTeam)): ?>
            <details class="inline-create">
                <summary class="btn btn-primary">+ <?= e(t('achievements.create')) ?></summary>
                <form method="post" action="/?page=team" enctype="multipart/form-data" class="stack">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="create_team_achievement">
                    <label><?= e(t('achievements.name')) ?><input type="text" name="name" required></label>
                    <label><?= e(t('achievements.description')) ?><input type="text" name="description"></label>
                    <label><?= e(t('achievements.reward')) ?><input type="text" name="reward_text"></label>
                    <label><?= e(t('common.photo')) ?><input type="file" name="image" accept="image/*"></label>
                    <button class="btn btn-secondary" type="submit"><?= e(t('achievements.create')) ?></button>
                </form>
            </details>
            <?php endif; ?>
            <div class="achievement-grid">
                <?php if (($teamAchievements ?? []) === []): ?>
                    <p class="muted"><?= e(t('achievements.empty')) ?></p>
                <?php else: ?>
                    <?php foreach ($teamAchievements as $achievement): ?>
                        <article class="achievement-card">
                            <?php if (!empty($achievement['image_path'])): ?><img src="<?= e((string) $achievement['image_path']) ?>" alt="<?= e((string) $achievement['name']) ?>"><?php else: ?><span>*</span><?php endif; ?>
                            <strong><?= e((string) $achievement['name']) ?></strong>
                            <p><?= e((string) $achievement['description']) ?></p>
                            <?php if (!empty($achievement['reward_text'])): ?><small><?= e(t('achievements.reward')) ?>: <?= e((string) $achievement['reward_text']) ?></small><?php endif; ?>
                            <small><?= e((string) $achievement['awarded_at']) ?></small>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </article>
    </div>
</section>
