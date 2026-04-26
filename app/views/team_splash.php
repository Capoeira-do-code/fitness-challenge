<?php

declare(strict_types=1);
?>
<section class="screen stack-lg">
    <div class="hero-panel">
        <div>
            <p class="eyebrow"><?= e(t('nav.team')) ?></p>
            <h1><?= e(t('team.join_team')) ?></h1>
            <p class="muted"><?= e(t('team.join_splash')) ?></p>
        </div>
    </div>

    <div class="card-list">
        <?php foreach (($teams ?? []) as $team): ?>
            <form method="post" action="/?page=team" class="panel mini-card">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="join_team">
                <input type="hidden" name="team_id" value="<?= (int) $team['id'] ?>">
                <div>
                    <strong><?= e((string) $team['name']) ?></strong>
                    <span><?= e((string) $team['join_mode']) ?> · <?= e((string) ($team['request_status'] ?? '')) ?></span>
                </div>
                <button class="btn btn-primary" type="submit"><?= (string) $team['join_mode'] === 'open' ? e(t('team.join')) : e(t('team.request_join')) ?></button>
            </form>
        <?php endforeach; ?>
        <?php if (($teams ?? []) === []): ?>
            <article class="panel"><p class="muted"><?= e(t('team.no_joinable')) ?></p></article>
        <?php endif; ?>
    </div>
</section>
