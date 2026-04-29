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
            <p class="muted"><?= e(t('challenges.empty')) ?></p>
        <?php else: ?>
            <div class="card-list">
                <?php foreach ($archives as $archive): ?>
                    <?php
                    $start = (string) ($archive['challenge_start'] ?? '');
                    $end = (string) ($archive['challenge_end'] ?? '');
                    $archivedAt = trim((string) ($archive['archived_at'] ?? ''));
                    $archivedBy = trim((string) ($archive['archived_by_name'] ?? ''));
                    $archivedAtDate = $archivedAt !== '' ? format_date_eu(substr($archivedAt, 0, 10)) : '';
                    $archivedAtTime = strlen($archivedAt) >= 16 ? substr($archivedAt, 11, 5) : '';
                    ?>
                    <article class="mini-card">
                        <div>
                            <strong><?= e((string) ($archive['challenge_name'] ?? t('challenges.unnamed'))) ?></strong>
                            <span>
                                <?= e($start !== '' ? format_date_eu($start) : '-') ?>
                                →
                                <?= e($end !== '' ? format_date_eu($end) : '-') ?>
                            </span>
                            <small class="muted">
                                <?= e(t('challenges.archived_at')) ?>:
                                <?= e(trim($archivedAtDate . ' ' . $archivedAtTime)) ?>
                                <?php if ($archivedBy !== ''): ?>
                                    · <?= e(t('challenges.archived_by', ['name' => $archivedBy])) ?>
                                <?php endif; ?>
                            </small>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>
</section>
