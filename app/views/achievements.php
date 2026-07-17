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
$achievementTotalCount = count($achievementsAll);
$achievementUnlockedCount = count($unlockedAchievements);
$achievementLockedCount = count($lockedAchievements);
$achievementCompletionPct = $achievementTotalCount > 0 ? round(($achievementUnlockedCount / $achievementTotalCount) * 100) : 0.0;
$achievementCompletionText = number_format($achievementCompletionPct, 0);
$achievementFilter = strtolower(trim((string) ($_GET['filter'] ?? 'all')));
if (!in_array($achievementFilter, ['all', 'unlocked', 'locked'], true)) {
    $achievementFilter = 'all';
}
$achievementFilterHref = static function (string $filter) use ($achievementsUrl): string {
    if ($filter === 'all') {
        return $achievementsUrl;
    }

    return $achievementsUrl . (str_contains($achievementsUrl, '?') ? '&' : '?') . 'filter=' . rawurlencode($filter);
};
$renderAchievementCard = static function (array $achievement): void {
    $isUnlocked = !empty($achievement['is_unlocked']);
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
    </article>
    <?php
};
?>

<section class="achievements-page-screen">
<header class="hierarchy-page-header achievements-page-header<?= $achievementScope === 'team' ? ' achievements-page-header-team' : '' ?>">
    <a class="hierarchy-back" href="<?= e($backHref) ?>" data-hierarchy-back data-fallback="<?= e($backHref) ?>" aria-label="<?= e(t('common.back')) ?>">&larr;</a>
    <div>
        <p class="eyebrow"><?= e($ownerName) ?></p>
        <h1 data-navigation-focus tabindex="-1"><?= e(t('achievements.all_title')) ?></h1>
        <p><?= e(t('achievements.completion')) ?> · <?= e($achievementCompletionText) ?>%</p>
    </div>
</header>

<nav class="achievement-filter-tabs" aria-label="<?= e(t('achievements.title')) ?>">
    <a href="<?= e($achievementFilterHref('all')) ?>"<?= $achievementFilter === 'all' ? ' aria-current="page"' : '' ?>><span><?= e(t('notifications.filter_all')) ?></span><strong><?= $achievementTotalCount ?></strong></a>
    <a href="<?= e($achievementFilterHref('unlocked')) ?>"<?= $achievementFilter === 'unlocked' ? ' aria-current="page"' : '' ?>><span><?= e(t('achievements.unlocked')) ?></span><strong><?= $achievementUnlockedCount ?></strong></a>
    <a href="<?= e($achievementFilterHref('locked')) ?>"<?= $achievementFilter === 'locked' ? ' aria-current="page"' : '' ?>><span><?= e(t('achievements.locked')) ?></span><strong><?= $achievementLockedCount ?></strong></a>
</nav>

<?php if ($achievementFilter !== 'locked'): ?>
<article class="panel achievements-page-panel">
    <div class="panel-head">
        <div>
            <p class="eyebrow"><?= e(t('achievements.title')) ?></p>
            <h2><?= e(t('achievements.unlocked_title')) ?></h2>
        </div>
        <span class="badge"><?= $achievementUnlockedCount ?></span>
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
<?php endif; ?>

<?php if ($achievementFilter !== 'unlocked'): ?>
<article class="panel achievements-page-panel">
    <div class="panel-head">
        <div>
            <p class="eyebrow"><?= e(t('achievements.progress')) ?></p>
            <h2><?= e(t('achievements.locked_title')) ?></h2>
        </div>
        <span class="badge"><?= $achievementLockedCount ?></span>
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
<?php endif; ?>
</section>
