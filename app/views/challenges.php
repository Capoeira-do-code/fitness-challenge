<?php

declare(strict_types=1);

$archives = is_array($archives ?? null) ? array_values((array) $archives) : [];
?>
<section class="screen stack-lg">
    <div class="hero-panel">
        <div>
            <p class="eyebrow"><?= e(t('nav.team')) ?></p>
            <h1><?= e(t('challenges.title')) ?></h1>
            <p class="muted"><?= e(t('challenges.subtitle')) ?></p>
        </div>
    </div>

    <article class="panel">
        <?php if ($archives === []): ?>
            <div class="empty-state challenges-empty-state">
                <span class="empty-state-icon"><?= activity_icon_svg('target') ?></span>
                <p><strong><?= e(t('challenges.empty')) ?></strong></p>
                <a class="btn btn-primary small" href="/?page=team"><?= e(t('profile.back_to_team')) ?></a>
            </div>
        <?php else: ?>
            <div class="challenge-archive-grid">
                <?php foreach ($archives as $archive): ?>
                    <?php
                    $archiveId = (int) ($archive['id'] ?? 0);
                    $start = (string) ($archive['challenge_start'] ?? '');
                    $end = (string) ($archive['challenge_end'] ?? '');
                    $archivedAt = trim((string) ($archive['archived_at'] ?? ''));
                    $archivedBy = trim((string) ($archive['archived_by_name'] ?? ''));
                    $archivedAtDate = $archivedAt !== '' ? format_date_eu(substr($archivedAt, 0, 10)) : '';
                    $archivedAtTime = strlen($archivedAt) >= 16 ? substr($archivedAt, 11, 5) : '';
                    $archiveHref = $archiveId > 0
                        ? '/?' . http_build_query(['page' => 'profile', 'challenge' => 'archive:' . $archiveId])
                        : '';
                    $archiveTag = $archiveHref !== '' ? 'a' : 'article';
                    ?>
                    <<?= $archiveTag ?> class="challenge-archive-card<?= $archiveHref !== '' ? ' mini-card-link' : '' ?>"<?= $archiveHref !== '' ? ' href="' . e($archiveHref) . '"' : '' ?>>
                        <span class="challenge-archive-head">
                            <strong><?= e((string) ($archive['challenge_name'] ?? t('challenges.unnamed'))) ?></strong>
                            <span class="badge"><?= e(t('goals.archive')) ?></span>
                        </span>
                        <span class="challenge-archive-dates">
                            <span><small><?= e(t('goals.start_date')) ?></small><strong><?= e($start !== '' ? format_date_eu($start) : '-') ?></strong></span>
                            <span aria-hidden="true">→</span>
                            <span><small><?= e(t('goals.due_date')) ?></small><strong><?= e($end !== '' ? format_date_eu($end) : '-') ?></strong></span>
                        </span>
                        <small class="muted challenge-archive-meta">
                            <?= e(t('challenges.archived_at')) ?>: <?= e(trim($archivedAtDate . ' ' . $archivedAtTime)) ?>
                            <?php if ($archivedBy !== ''): ?> · <?= e(t('challenges.archived_by', ['name' => $archivedBy])) ?><?php endif; ?>
                        </small>
                        <?php if ($archiveHref !== ''): ?>
                            <span class="mini-card-cta"><?= e(t('challenges.view_details')) ?> →</span>
                        <?php endif; ?>
                    </<?= $archiveTag ?>>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>
</section>
