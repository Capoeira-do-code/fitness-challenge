<?php

declare(strict_types=1);
?>
<section class="screen stack-lg team-join-screen">
    <div class="hero-panel app-page-hero">
        <div class="hero-copy hero-copy-page-title">
            <p class="eyebrow"><?= e(t('nav.team')) ?></p>
            <h1><?= e(t('team.join_team')) ?></h1>
            <p class="muted"><?= e(t('team.join_splash')) ?></p>
        </div>
    </div>

    <div class="card-list">
        <?php foreach (($teams ?? []) as $team): ?>
            <?php $accessRequested = (string) ($team['request_status'] ?? '') === 'pending'; ?>
            <form method="post" action="/?page=team" class="panel mini-card team-join-card<?= $accessRequested ? ' is-requested' : '' ?>">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="join_team">
                <input type="hidden" name="team_id" value="<?= (int) $team['id'] ?>">
                <div class="team-join-card-copy">
                    <strong><?= e((string) $team['name']) ?></strong>
                    <span><?= e(t((string) $team['join_mode'] === 'open' ? 'team.open' : 'team.request')) ?></span>
                </div>
                <button class="btn <?= $accessRequested ? 'btn-ghost team-access-requested' : 'btn-primary' ?> team-join-card-action" type="<?= $accessRequested ? 'button' : 'submit' ?>"<?= $accessRequested ? ' disabled aria-disabled="true"' : '' ?>><?= $accessRequested ? e(t('team.access_requested')) : ((string) $team['join_mode'] === 'open' ? e(t('team.join')) : e(t('team.request_join'))) ?></button>
            </form>
        <?php endforeach; ?>
        <?php if (($teams ?? []) === []): ?>
            <article class="panel"><p class="muted"><?= e(t('team.no_joinable')) ?></p></article>
        <?php endif; ?>
    </div>
</section>
