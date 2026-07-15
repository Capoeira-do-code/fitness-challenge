<?php

declare(strict_types=1);

$section = (string) ($socialSection ?? '');
$teams = array_values((array) ($socialTeams ?? []));
$friends = array_values((array) ($socialFriends ?? []));
$duels = (array) ($socialDuelsSummary ?? []);
$competitions = (array) ($socialCompetitionsSummary ?? []);
$galleryPreview = array_values((array) ($socialGalleryPreview ?? []));

$row = static function (string $href, string $glyph, string $title, string $description, string $meta = ''): void {
    ?>
    <a class="hierarchy-nav-row" href="<?= e($href) ?>">
        <span class="hierarchy-nav-icon" aria-hidden="true"><?= e($glyph) ?></span>
        <span class="hierarchy-nav-copy"><strong><?= e($title) ?></strong><small><?= e($description) ?></small></span>
        <?php if ($meta !== ''): ?><span class="hierarchy-nav-meta"><?= e($meta) ?></span><?php endif; ?>
        <span class="hierarchy-nav-chevron" aria-hidden="true">&rsaquo;</span>
    </a>
    <?php
};
?>
<section class="screen hierarchy-screen social-hub-screen">
    <header class="hierarchy-page-header">
        <?php if ($section !== ''): ?>
            <button class="hierarchy-back" type="button" data-hierarchy-back data-fallback="/?page=social" aria-label="<?= e(t('common.back')) ?>">&larr;</button>
        <?php endif; ?>
        <div>
            <p class="eyebrow"><?= e($section === '' ? t('social_hub.eyebrow') : t('social_hub.title')) ?></p>
            <h1><?= e($section === '' ? t('social_hub.title') : t('social_hub.section_' . $section)) ?></h1>
            <p><?= e(t($section === '' ? 'social_hub.subtitle' : 'social_hub.section_' . $section . '_hint')) ?></p>
        </div>
    </header>

    <?php if ($section === ''): ?>
        <div class="hierarchy-status-strip" aria-label="<?= e(t('social_hub.status')) ?>">
            <span><strong><?= count($teams) ?></strong><small><?= e(t('social_hub.teams')) ?></small></span>
            <span><strong><?= count($friends) ?></strong><small><?= e(t('nav.friends')) ?></small></span>
            <span><strong><?= (int) ($duels['active'] ?? 0) + (int) ($competitions['active'] ?? 0) ?></strong><small><?= e(t('social_hub.active')) ?></small></span>
        </div>
        <nav class="hierarchy-nav-list" aria-label="<?= e(t('social_hub.title')) ?>">
            <?php $row('/?page=social&section=team', '&#9673;', t('social_hub.section_team'), t('social_hub.section_team_hint'), count($teams) > 0 ? (string) count($teams) : ''); ?>
            <?php $row('/?page=social&section=community', '&#9825;', t('social_hub.section_community'), t('social_hub.section_community_hint'), count($friends) > 0 ? (string) count($friends) : ''); ?>
            <?php $row('/?page=social&section=competition', '&#9733;', t('social_hub.section_competition'), t('social_hub.section_competition_hint'), (string) ((int) ($duels['active'] ?? 0) + (int) ($competitions['active'] ?? 0))); ?>
        </nav>
    <?php elseif ($section === 'team'): ?>
        <nav class="hierarchy-nav-list" aria-label="<?= e(t('social_hub.section_team')) ?>">
            <?php $row('/?page=team', '&#9673;', t('nav.team'), t('social_hub.team_hint'), count($teams) > 0 ? (string) count($teams) : ''); ?>
            <?php $row('/?page=challenges', '&#9873;', t('nav.challenges'), t('social_hub.challenges_hint')); ?>
            <?php if (!empty($socialCanManageTeam)): ?>
                <?php $row('/?page=team_settings', '&#9881;', t('team.settings'), t('social_hub.team_settings_hint')); ?>
            <?php endif; ?>
        </nav>
    <?php elseif ($section === 'community'): ?>
        <?php if ($galleryPreview !== []): ?>
            <div class="social-preview-grid" aria-label="<?= e(t('gallery.title')) ?>">
                <?php foreach ($galleryPreview as $photo): ?>
                    <?php $photoPath = (string) ($photo['file_path'] ?? ''); ?>
                    <a href="/?page=photo&photo_id=<?= (int) ($photo['id'] ?? 0) ?>"><img src="<?= e(media_thumbnail_url($photoPath, 400)) ?>" alt="" loading="lazy"></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <nav class="hierarchy-nav-list" aria-label="<?= e(t('social_hub.section_community')) ?>">
            <?php $row('/?page=gallery&gallery_view=recent', '&#9638;', t('gallery.title'), t('social_hub.gallery_hint')); ?>
            <?php $row('/?page=friends', '&#9825;', t('nav.friends'), t('social_hub.friends_hint'), count($friends) > 0 ? (string) count($friends) : ''); ?>
        </nav>
    <?php else: ?>
        <nav class="hierarchy-nav-list" aria-label="<?= e(t('social_hub.section_competition')) ?>">
            <?php $row('/?page=duels', '&#9876;', t('nav.duels'), t('social_hub.duels_hint'), (string) ((int) ($duels['active'] ?? 0))); ?>
            <?php $row('/?page=competitions', '&#9733;', t('nav.competitions'), t('social_hub.competitions_hint'), (string) ((int) ($competitions['active'] ?? 0))); ?>
            <?php $row('/?page=challenges', '&#9873;', t('nav.challenges'), t('social_hub.explore_challenges_hint')); ?>
        </nav>
    <?php endif; ?>
</section>
