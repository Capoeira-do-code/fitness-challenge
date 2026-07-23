<?php

declare(strict_types=1);

$questsBoard = is_array($questsBoard ?? null) ? array_values((array) $questsBoard) : [];
$questsRank = is_array($questsRank ?? null) ? (array) $questsRank : [];
$questsLevelValue = max(1, (int) ($questsLevel ?? 1));
$questsStreakValue = max(0, (int) ($questsStreak ?? 0));
$questsCompletionTotalValue = max(0, (int) ($questsCompletionTotal ?? 0));
$questGroups = ['daily' => [], 'weekly' => []];
foreach ($questsBoard as $quest) {
    $period = (string) ($quest['period'] ?? 'daily');
    $questGroups[array_key_exists($period, $questGroups) ? $period : 'daily'][] = $quest;
}
?>
<section class="screen stack-lg quests-page-screen">
    <header class="quests-page-hero">
        <span class="quests-page-hero-icon" aria-hidden="true"><?= activity_icon_svg('check') ?></span>
        <div>
            <p class="eyebrow"><?= e(t('quests.rank')) ?> · <?= e((string) ($questsRank['label'] ?? '')) ?></p>
            <h1><?= e(t('quests.all_missions')) ?></h1>
            <p class="muted"><?= e(t('quests.page_hint')) ?></p>
        </div>
    </header>

    <div class="quests-page-summary" aria-label="<?= e(t('quests.title')) ?>">
        <article class="quests-rank-summary">
            <span class="quests-rank-mark" aria-hidden="true"><?= activity_icon_svg('trophy') ?></span>
            <span><small><?= e(t('quests.rank')) ?></small><strong><?= e((string) ($questsRank['label'] ?? '')) ?></strong></span>
            <b><?= e(t('xp.level')) ?> <?= $questsLevelValue ?></b>
            <?php if (($questsRank['next_level'] ?? null) !== null): ?>
                <p><?= e(t('quests.next_rank', ['n' => (int) $questsRank['next_level']])) ?></p>
            <?php endif; ?>
        </article>
        <article class="quests-summary-stat">
            <span aria-hidden="true"><?= activity_icon_svg('check') ?></span>
            <small><?= e(t('quests.total_completed')) ?></small>
            <strong><?= $questsCompletionTotalValue ?></strong>
        </article>
        <article class="quests-summary-stat is-streak">
            <span aria-hidden="true"><?= activity_icon_svg('spark') ?></span>
            <small><?= e(t('quests.streak')) ?></small>
            <strong><?= $questsStreakValue ?></strong>
        </article>
    </div>

    <?php foreach ($questGroups as $period => $groupQuests): ?>
        <?php if ($groupQuests === []) { continue; } ?>
        <?php $groupCompleted = count(array_filter($groupQuests, static fn(array $quest): bool => !empty($quest['completed']))); ?>
        <article class="panel quests-page-group compact-panel glass-panel">
            <div class="quests-page-group-head">
                <span class="quests-page-group-icon" aria-hidden="true"><?= activity_icon_svg($period === 'weekly' ? 'list' : 'spark') ?></span>
                <span><p class="eyebrow"><?= e(t('quests.period')) ?></p><h2><?= e(t('quests.' . $period)) ?></h2></span>
                <small><?= $groupCompleted ?> / <?= count($groupQuests) ?></small>
            </div>
            <div class="quests-catalogue-grid">
                <?php foreach ($groupQuests as $quest): ?>
                    <?php
                    $completionCount = max(0, (int) ($quest['completion_count'] ?? 0));
                    $progress = (float) ($quest['progress'] ?? 0);
                    $target = max(0.0, (float) ($quest['target'] ?? 0));
                    $progressPct = max(0, min(100, (int) ($quest['pct'] ?? 0)));
                    $lastCompletedAt = trim((string) ($quest['last_completed_at'] ?? ''));
                    ?>
                    <article class="quest-catalogue-card<?= !empty($quest['completed']) ? ' is-completed' : '' ?>" data-state="<?= !empty($quest['completed']) ? 'completed' : 'available' ?>">
                        <span class="quest-catalogue-icon" aria-hidden="true"><?= activity_icon_svg((string) ($quest['icon'] ?? 'check')) ?></span>
                        <span class="quest-catalogue-copy">
                            <strong><?= e((string) ($quest['label'] ?? '')) ?></strong>
                            <small><?= e(!empty($quest['completed']) ? t('quests.status_completed') : t('quests.status_available')) ?></small>
                        </span>
                        <span class="quest-completion-count"><strong><?= $completionCount ?></strong><small><?= e(t('quests.completed_times')) ?></small></span>
                        <span class="quest-catalogue-progress">
                            <span class="goal-progress" role="progressbar" aria-label="<?= e(t('metric.progress')) ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $progressPct ?>"><span style="width: <?= $progressPct ?>%"></span></span>
                            <small><?= e(number_format($progress, 0, '.', ' ')) ?> / <?= e(number_format($target, 0, '.', ' ')) ?></small>
                        </span>
                        <span class="quest-catalogue-meta">
                            <small><b>+<?= (int) ($quest['xp'] ?? 0) ?> XP</b></small>
                            <?php if ($lastCompletedAt !== ''): ?><small><?= e(t('quests.last_completed')) ?> · <?= e(format_date_eu(substr($lastCompletedAt, 0, 10))) ?></small><?php endif; ?>
                        </span>
                    </article>
                <?php endforeach; ?>
            </div>
        </article>
    <?php endforeach; ?>
</section>
