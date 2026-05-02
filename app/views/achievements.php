<?php

declare(strict_types=1);

$achievementsAll = array_values((array) ($achievementsAll ?? []));
$unlockedAchievements = array_values(array_filter($achievementsAll, static fn(array $achievement): bool => !empty($achievement['is_unlocked'])));
$lockedAchievements = array_values(array_filter($achievementsAll, static fn(array $achievement): bool => empty($achievement['is_unlocked'])));
$achievementScope = (string) ($achievementScope ?? 'user');
$achievementOwner = is_array($achievementOwner ?? null) ? (array) $achievementOwner : [];
$ownerName = $achievementScope === 'team'
    ? (string) ($achievementOwner['name'] ?? t('nav.team'))
    : (string) ($achievementOwner['display_name'] ?? t('common.user'));
$achievementsUrl = (string) ($achievementsUrl ?? '/?page=achievements');
$backHref = (string) ($backHref ?? '/?page=profile');
$canDeleteAchievements = !empty($canDeleteAchievements);

$renderAchievementCard = static function (array $achievement) use ($achievementsUrl, $canDeleteAchievements): void {
    $isUnlocked = !empty($achievement['is_unlocked']);
    $awardId = (int) ($achievement['award_id'] ?? 0);
    $deleteFormId = 'delete-achievement-page-' . (int) ($achievement['id'] ?? 0) . '-' . $awardId;
    $progressPct = is_numeric($achievement['progress_pct'] ?? null) ? max(0.0, min(100.0, (float) $achievement['progress_pct'])) : null;
    ?>
    <article class="achievement-card achievement-list-card <?= $isUnlocked ? 'is-unlocked' : 'is-locked' ?>" <?= achievement_modal_attrs($achievement) ?>>
        <?= achievement_visual_html($achievement, 'achievement-visual') ?>
        <div class="achievement-list-content">
            <div class="achievement-list-title-row">
                <h3><?= e((string) ($achievement['name'] ?? '')) ?></h3>
                <span class="achievement-chip <?= $isUnlocked ? 'achievement-chip-ok' : 'achievement-chip-muted' ?>">
                    <?= e($isUnlocked ? t('achievements.unlocked') : t('achievements.locked')) ?>
                </span>
            </div>
            <p><?= e((string) ($achievement['description'] ?? '')) ?></p>
            <div class="achievement-card-meta">
                <?php if (!empty($achievement['reward_text'])): ?>
                    <span class="achievement-chip"><?= e(t('achievements.reward')) ?>: <?= e((string) $achievement['reward_text']) ?></span>
                <?php endif; ?>
                <?php if ($isUnlocked && !empty($achievement['awarded_at'])): ?>
                    <span class="achievement-chip"><?= e(t('profile.unlocked_on')) ?> <?= e(format_date_eu((string) $achievement['awarded_at'])) ?></span>
                <?php endif; ?>
            </div>
            <?php if ($progressPct !== null): ?>
                <div class="achievement-progress">
                    <div class="goal-progress"><span style="width: <?= e((string) $progressPct) ?>%"></span></div>
                    <small><?= e((string) ($achievement['progress_text'] ?? '')) ?></small>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($isUnlocked && $canDeleteAchievements && $awardId > 0): ?>
            <form method="post" action="<?= e($achievementsUrl) ?>" class="achievement-remove" id="<?= e($deleteFormId) ?>">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete_achievement_award">
                <input type="hidden" name="award_id" value="<?= $awardId ?>">
                <button class="achievement-delete-btn" type="button" aria-label="<?= e(t('achievements.delete_award')) ?>" data-achievement-delete-trigger data-form-id="<?= e($deleteFormId) ?>">x</button>
            </form>
        <?php endif; ?>
    </article>
    <?php
};
?>

<section class="page-hero achievements-page-hero">
    <div>
        <p class="eyebrow"><?= e($achievementScope === 'team' ? t('nav.team') : t('common.user')) ?></p>
        <h1><?= e(t('achievements.all_title')) ?></h1>
        <p class="muted"><?= e($ownerName) ?></p>
    </div>
    <a class="btn btn-ghost" href="<?= e($backHref) ?>"><?= e(t('common.back')) ?></a>
</section>

<section class="achievement-summary-strip">
    <span class="badge"><?= count($unlockedAchievements) ?> <?= e(t('achievements.unlocked')) ?></span>
    <span class="badge"><?= count($lockedAchievements) ?> <?= e(t('achievements.locked')) ?></span>
</section>

<article class="panel achievements-page-panel">
    <div class="panel-head">
        <div>
            <p class="eyebrow"><?= e(t('achievements.title')) ?></p>
            <h2><?= e(t('achievements.unlocked_title')) ?></h2>
        </div>
        <span class="badge"><?= count($unlockedAchievements) ?></span>
    </div>
    <div class="achievement-page-grid">
        <?php foreach ($unlockedAchievements as $achievement): ?>
            <?php $renderAchievementCard($achievement); ?>
        <?php endforeach; ?>
        <?php if ($unlockedAchievements === []): ?>
            <p class="muted panel-inline-empty"><?= e(t('achievements.no_unlocked')) ?></p>
        <?php endif; ?>
    </div>
</article>

<article class="panel achievements-page-panel">
    <div class="panel-head">
        <div>
            <p class="eyebrow"><?= e(t('achievements.progress')) ?></p>
            <h2><?= e(t('achievements.locked_title')) ?></h2>
        </div>
        <span class="badge"><?= count($lockedAchievements) ?></span>
    </div>
    <div class="achievement-page-grid">
        <?php foreach ($lockedAchievements as $achievement): ?>
            <?php $renderAchievementCard($achievement); ?>
        <?php endforeach; ?>
        <?php if ($lockedAchievements === []): ?>
            <p class="muted panel-inline-empty"><?= e(t('achievements.no_locked')) ?></p>
        <?php endif; ?>
    </div>
</article>
