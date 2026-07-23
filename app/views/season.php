<?php

declare(strict_types=1);

$season = is_array($season ?? null) ? (array) $season : [];
$seasonBoard = is_array($seasonBoard ?? null) ? array_values((array) $seasonBoard) : [];
$seasonTopXp = 0;
foreach ($seasonBoard as $seasonRow) {
    $seasonTopXp = max($seasonTopXp, (int) ($seasonRow['xp'] ?? 0));
}
$seasonUserXpValue = (int) ($seasonUserXp ?? 0);
$seasonUserPositionValue = ($seasonUserPosition ?? null) !== null ? (int) $seasonUserPosition : null;
$seasonXpGap = max(0, $seasonTopXp - $seasonUserXpValue);
$seasonUserProgress = $seasonTopXp > 0 ? min(100, (int) round(($seasonUserXpValue / $seasonTopXp) * 100)) : 0;
$seasonCoverUrl = trim((string) ($season['cover_path'] ?? '')) !== '' ? media_url((string) $season['cover_path']) : '';
$seasonIconKey = season_normalize_icon_key($season['icon_key'] ?? 'trophy');
$seasonAccentColor = season_normalize_accent_color($season['accent_color'] ?? '#8b5cf6');
?>
<section class="screen stack-lg season-ranking-page">
    <header class="season-ranking-hero<?= $seasonCoverUrl !== '' ? ' has-season-cover' : '' ?>" style="--season-accent: <?= e($seasonAccentColor) ?>">
        <span class="season-ranking-identity" aria-hidden="true"><?php if ($seasonCoverUrl !== ''): ?><img src="<?= e($seasonCoverUrl) ?>" alt=""><?php else: ?><?= activity_icon_svg($seasonIconKey) ?><?php endif; ?></span>
        <div>
            <p class="eyebrow"><?= e(t('season.title')) ?></p>
            <h1><?= e((string) ($season['name'] ?? t('season.leaderboard'))) ?></h1>
            <p class="muted"><?= e(t('season.hint')) ?></p>
        </div>
        <span class="season-countdown">
            <?= (int) ($seasonDaysLeft ?? 0) > 0
                ? e(t('season.days_left', ['n' => (int) $seasonDaysLeft]))
                : e(t('season.ends_today')) ?>
        </span>
    </header>

    <div class="season-ranking-summary compact-metrics-row glass-panel">
        <span><small><?= e(t('season.your_xp')) ?></small><strong><?= e(number_format($seasonUserXpValue, 0, '.', ' ')) ?> XP</strong></span>
        <span><small><?= e(t('common.position')) ?></small><strong><?= $seasonUserPositionValue !== null ? '#' . $seasonUserPositionValue : '&mdash;' ?></strong></span>
        <span><small><?= e(t('season.remaining')) ?></small><strong><?= (int) ($seasonDaysLeft ?? 0) ?></strong></span>
        <span><small><?= e(t('season.next_milestone')) ?></small><strong><?= e($seasonUserPositionValue === 1 ? t('season.leader') : t('season.xp_to_leader', ['xp' => number_format($seasonXpGap, 0, '.', ' ')])) ?></strong></span>
    </div>
    <div class="season-user-progress compact-progress-panel">
        <span class="goal-progress" role="progressbar" aria-label="<?= e(t('season.progress_to_leader')) ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $seasonUserProgress ?>"><span style="width: <?= $seasonUserProgress ?>%"></span></span>
    </div>

    <article class="panel season-ranking-panel compact-panel glass-panel">
        <div class="panel-head"><div><p class="eyebrow"><?= e(t('season.title')) ?></p><h2><?= e(t('season.leaderboard')) ?></h2></div><span class="badge"><?= count($seasonBoard) ?></span></div>
        <?php if ($seasonBoard === []): ?>
            <p class="muted panel-inline-empty"><?= e(t('common.none')) ?></p>
        <?php else: ?>
            <ol class="season-ranking-list">
                <?php foreach ($seasonBoard as $seasonRow): ?>
                    <?php
                    $isCurrentUser = (int) ($seasonRow['user_id'] ?? 0) === (int) ($currentUser['id'] ?? 0);
                    $rowXp = (int) ($seasonRow['xp'] ?? 0);
                    $rowPct = $seasonTopXp > 0 ? max(2, (int) round(($rowXp / $seasonTopXp) * 100)) : 0;
                    $rowName = (string) ($seasonRow['name'] ?? t('common.user'));
                    ?>
                    <li class="season-ranking-row<?= $isCurrentUser ? ' is-me' : '' ?>">
                        <strong class="season-ranking-position">#<?= (int) ($seasonRow['rank'] ?? 0) ?></strong>
                        <span class="dashboard-training-board-avatar"><?= e(initials_for($rowName)) ?></span>
                        <a href="/?page=profile&amp;user_id=<?= (int) ($seasonRow['user_id'] ?? 0) ?>"><strong><?= e($rowName) ?></strong><span class="season-ranking-bar"><span style="width: <?= $rowPct ?>%"></span></span></a>
                        <strong class="season-ranking-xp"><?= e(number_format($rowXp, 0, '.', ' ')) ?> XP</strong>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </article>
</section>
