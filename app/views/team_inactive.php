<?php

declare(strict_types=1);

$team = is_array($team ?? null) ? $team : [];
$challengeSettings = is_array($challengeSettings ?? null) ? $challengeSettings : [];
$teamName = trim((string) ($team['name'] ?? ''));
$challengeName = trim((string) ($challengeSettings['challenge_name'] ?? ''));
?>
<section class="screen stack-lg">
    <div class="hero-panel">
        <div>
            <p class="eyebrow"><?= e(t('nav.team')) ?></p>
            <h1><?= e($teamName !== '' ? $teamName : t('team.no_active_challenge_title')) ?></h1>
            <p class="muted"><?= e($challengeName !== '' ? $challengeName : t('team.no_active_challenge_subtitle')) ?></p>
        </div>
    </div>

    <article class="panel">
        <div class="stack">
            <strong><?= e(t('team.no_active_challenge_title')) ?></strong>
            <p class="muted"><?= e(t('team.no_active_challenge_subtitle')) ?></p>
            <div class="inline-actions">
                <a class="btn btn-primary" href="/?page=challenges"><?= e(t('team.previous_challenges')) ?></a>
                <?php if (is_admin($currentUser)): ?>
                    <a class="btn btn-ghost" href="/?page=admin"><?= e(t('admin.challenge_settings')) ?></a>
                <?php endif; ?>
            </div>
        </div>
    </article>
</section>
