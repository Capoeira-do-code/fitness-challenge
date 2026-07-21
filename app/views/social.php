<?php

declare(strict_types=1);

$section = (string) ($socialSection ?? '');
$teams = array_values((array) ($socialTeams ?? []));
$teamMembers = array_values((array) ($socialTeamMembers ?? []));
$friends = array_values((array) ($socialFriends ?? []));
$friendRequests = array_values((array) ($socialFriendRequests ?? []));
$duels = (array) ($socialDuelsSummary ?? []);
$competitions = (array) ($socialCompetitionsSummary ?? []);
$galleryPreview = array_values((array) ($socialGalleryPreview ?? []));
$socialActivity = array_values((array) ($socialActivity ?? []));
$compactNames = static function (array $items, string $field): string {
    $names = array_values(array_filter(array_map(
        static fn (array $item): string => trim((string) ($item[$field] ?? '')),
        array_slice($items, 0, 2)
    )));
    $remaining = max(0, count($items) - count($names));
    if ($remaining > 0) {
        $names[] = '+' . $remaining;
    }

    return implode(' · ', $names);
};
$teamPreview = $compactNames($teams, 'name');
$friendPreview = $compactNames($friends, 'display_name');
$competitionActive = (int) ($duels['active'] ?? 0) + (int) ($competitions['active'] ?? 0);
$competitionPending = (int) ($duels['pending'] ?? 0) + (int) ($competitions['pending'] ?? 0);
$teamCount = count($teams);
$competitionPreview = $competitionActive . ' ' . t('social_hub.active') . ' · '
    . $competitionPending . ' ' . t('common.pending');
$avatar = static function (array $person, string $class = ''): void {
    $name = trim((string) ($person['display_name'] ?? $person['name'] ?? t('common.user')));
    $url = avatar_url($person);
    $className = trim('social-person-avatar ' . cosmetic_frame_class($person) . ' ' . $class);
    if ($url !== '') {
        ?><img class="<?= e($className) ?>" src="<?= e($url) ?>" alt="<?= e($name) ?>" loading="lazy"><?php
    } else {
        ?><span class="<?= e($className) ?>" aria-label="<?= e($name) ?>"><?= e(initials_for($name)) ?></span><?php
    }
};

$row = static function (string $href, string $icon, string $title, string $description, string $meta = '', string $tone = ''): void {
    ?>
    <a class="hierarchy-nav-row"<?= $tone !== '' ? ' data-tone="' . e($tone) . '"' : '' ?> href="<?= e($href) ?>">
        <span class="hierarchy-nav-icon" aria-hidden="true"><?= activity_icon_svg($icon) ?></span>
        <span class="hierarchy-nav-copy"><strong><?= e($title) ?></strong><small><?= e($description) ?></small></span>
        <?php if ($meta !== ''): ?><span class="hierarchy-nav-meta"><?= e($meta) ?></span><?php endif; ?>
        <span class="hierarchy-nav-chevron" aria-hidden="true">&rsaquo;</span>
    </a>
    <?php
};
?>
<section class="screen hierarchy-screen social-hub-screen">
    <header class="hierarchy-page-header<?= $section === '' ? ' hierarchy-page-header-root' : '' ?>">
        <?php if ($section !== ''): ?>
            <button class="hierarchy-back destination-back" type="button" data-hierarchy-back data-fallback="/?page=social" aria-label="<?= e(t('common.back')) ?>: <?= e(t('nav.social')) ?>"><span aria-hidden="true">&larr;</span><strong><?= e(t('nav.social')) ?></strong></button>
        <?php endif; ?>
        <div>
            <p class="eyebrow"><?= e($section === '' ? t('social_hub.eyebrow') : t('social_hub.title')) ?></p>
            <h1><?= e($section === '' ? t('social_hub.title') : t('social_hub.section_' . $section)) ?></h1>
            <p><?= e(t($section === '' ? 'social_hub.subtitle' : 'social_hub.section_' . $section . '_hint')) ?></p>
        </div>
    </header>

    <?php if ($section === ''): ?>
        <nav class="social-quick-actions" aria-label="<?= e(t('social_hub.quick_actions')) ?>">
            <a href="/?page=entries&amp;mode=meal" data-tone="green"><span aria-hidden="true"><?= activity_icon_svg('image') ?></span><strong><?= e(t('social_hub.quick_share')) ?></strong></a>
            <a href="/?page=friends" data-tone="blue"><span aria-hidden="true"><?= activity_icon_svg('users') ?></span><strong><?= e(t('social_hub.quick_friend')) ?></strong></a>
            <a href="/?page=duels" data-tone="violet"><span aria-hidden="true"><?= activity_icon_svg('sword') ?></span><strong><?= e(t('social_hub.quick_duel')) ?></strong></a>
            <a href="/?page=challenges" data-tone="orange"><span aria-hidden="true"><?= activity_icon_svg('target') ?></span><strong><?= e(t('social_hub.quick_challenge')) ?></strong></a>
        </nav>
        <nav class="social-section-grid hierarchy-nav-list mobile-hub-section-grid" aria-label="<?= e(t('social_hub.title')) ?>">
            <?php $row('/?page=social&section=team', 'users', t('social_hub.section_team'), $teamPreview !== '' ? $teamPreview : t('social_hub.section_team_hint'), $teamCount > 0 ? (string) $teamCount : '', 'blue'); ?>
            <?php $row('/?page=social&section=community', 'image', t('social_hub.section_community'), $friendPreview !== '' ? $friendPreview : t('social_hub.section_community_hint'), count($friends) > 0 ? (string) count($friends) : '', 'green'); ?>
            <?php $row('/?page=social&section=competition', 'trophy', t('social_hub.section_competition'), $competitionPreview, (string) $competitionActive, 'violet'); ?>
        </nav>

        <div class="social-dashboard-grid">
            <?php $primaryTeam = $teams[0] ?? null; ?>
            <article class="social-overview-card compact-panel glass-panel social-team-overview">
                <div class="social-card-head"><div><p class="eyebrow"><?= e(t('social_hub.your_team')) ?></p><h2><?= e(is_array($primaryTeam) ? (string) ($primaryTeam['name'] ?? t('social_hub.teams')) : t('social_hub.teams')) ?></h2></div><a href="/?page=social&amp;section=team"><?= e(t('common.view_all')) ?></a></div>
                <?php if ($teamCount > 1): ?>
                    <div class="social-team-list">
                        <?php foreach ($teams as $socialTeam): ?>
                            <a href="/?page=team&amp;team_id=<?= (int) ($socialTeam['id'] ?? 0) ?>">
                                <span class="social-team-list-mark" aria-hidden="true"><?= e(initials_for((string) ($socialTeam['name'] ?? t('nav.team')))) ?></span>
                                <span><strong><?= e((string) ($socialTeam['name'] ?? '')) ?></strong><small><?= e(t('social_hub.members_count', ['count' => (string) ((int) ($socialTeam['member_count'] ?? 0))])) ?></small></span>
                                <span aria-hidden="true">&rsaquo;</span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php elseif (is_array($primaryTeam)): ?>
                    <a class="social-featured-row" href="/?page=team&amp;team_id=<?= (int) ($primaryTeam['id'] ?? 0) ?>">
                        <span class="social-featured-icon" aria-hidden="true"><?= activity_icon_svg('users') ?></span>
                        <span><strong><?= e(t('social_hub.members_count', ['count' => (string) ((int) ($primaryTeam['member_count'] ?? count($teamMembers)))])) ?></strong><small><?= e(t('social_hub.team_hint')) ?></small></span>
                        <span aria-hidden="true">&rsaquo;</span>
                    </a>
                    <?php if ($teamMembers !== []): ?>
                        <div class="social-avatar-list" aria-label="<?= e(t('team.members')) ?>">
                            <?php foreach (array_slice($teamMembers, 0, 4) as $member): ?>
                                <a href="/?page=profile&amp;user_id=<?= (int) ($member['user_id'] ?? 0) ?>&amp;back=social" title="<?= e((string) ($member['display_name'] ?? '')) ?>"><?php $avatar($member); ?></a>
                            <?php endforeach; ?>
                            <?php if (count($teamMembers) > 4): ?><span class="social-avatar-more">+<?= count($teamMembers) - 4 ?></span><?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="social-inline-empty"><span aria-hidden="true"><?= activity_icon_svg('users') ?></span><p><?= e(t('social_hub.team_empty')) ?></p><a href="/?page=team"><?= e(t('common.open')) ?></a></div>
                <?php endif; ?>
            </article>

            <article class="social-overview-card compact-panel glass-panel social-competition-overview">
                <div class="social-card-head"><div><p class="eyebrow"><?= e(t('social_hub.competition_status')) ?></p><h2><?= e(t('social_hub.section_competition')) ?></h2></div><a href="/?page=social&amp;section=competition"><?= e(t('common.view_all')) ?></a></div>
                <div class="social-competition-metrics">
                    <a href="/?page=duels"><strong><?= (int) ($duels['active'] ?? 0) ?></strong><small><?= e(t('duels.active')) ?></small></a>
                    <a href="/?page=competitions"><strong><?= (int) ($competitions['active'] ?? 0) ?></strong><small><?= e(t('competitions.active')) ?></small></a>
                    <a href="/?page=social&amp;section=competition"><strong><?= $competitionPending ?></strong><small><?= e(t('common.pending')) ?></small></a>
                </div>
            </article>

            <article class="social-overview-card compact-panel glass-panel social-community-overview">
                <div class="social-card-head"><div><p class="eyebrow"><?= e(t('social_hub.community_updates')) ?></p><h2><?= e(t('gallery.title')) ?></h2></div><a href="/?page=gallery&amp;gallery_view=recent"><?= e(t('common.view_all')) ?></a></div>
                <?php if ($galleryPreview !== []): ?>
                    <div class="social-preview-grid social-preview-grid-root" aria-label="<?= e(t('gallery.title')) ?>">
                        <?php foreach ($galleryPreview as $photo): ?>
                            <?php $photoPath = (string) ($photo['file_path'] ?? ''); ?>
                            <a href="/?page=photo&amp;photo_id=<?= (int) ($photo['id'] ?? 0) ?>"><img src="<?= e(media_thumbnail_url($photoPath, 400)) ?>" alt="" loading="lazy"></a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="social-inline-empty is-wide"><span aria-hidden="true"><?= activity_icon_svg('image') ?></span><p><?= e(t('social_hub.community_empty')) ?></p><a href="/?page=entries&amp;mode=meal"><?= e(t('social_hub.share_update')) ?></a></div>
                <?php endif; ?>
            </article>

            <article class="social-overview-card compact-panel glass-panel social-circle-overview">
                <div class="social-card-head"><div><p class="eyebrow"><?= e(t('social_hub.your_circle')) ?></p><h2><?= e(t('nav.friends')) ?></h2></div><?php if ($friendRequests !== []): ?><a class="social-request-badge" href="/?page=friends"><?= count($friendRequests) ?> <?= e(t('social_hub.requests')) ?></a><?php else: ?><a href="/?page=friends"><?= e(t('common.view_all')) ?></a><?php endif; ?></div>
                <?php if ($friends !== []): ?>
                    <div class="social-people-list">
                        <?php foreach (array_slice($friends, 0, 3) as $friend): ?>
                            <a href="/?page=profile&amp;user_id=<?= (int) ($friend['id'] ?? 0) ?>&amp;back=social"><?php $avatar($friend); ?><span><strong><?= e((string) ($friend['display_name'] ?? '')) ?></strong><small>@<?= e((string) ($friend['username'] ?? '')) ?></small></span><span aria-hidden="true">&rsaquo;</span></a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="social-inline-empty"><span aria-hidden="true"><?= activity_icon_svg('users') ?></span><p><?= e(t('social_hub.circle_empty')) ?></p><a href="/?page=friends"><?= e(t('social_hub.quick_friend')) ?></a></div>
                <?php endif; ?>
            </article>

            <article class="social-overview-card compact-panel glass-panel social-activity-overview">
                <div class="social-card-head"><div><p class="eyebrow"><?= e(t('social_hub.recent_activity')) ?></p><h2><?= e(t('social_hub.activity')) ?></h2></div><a href="/?page=notifications"><?= e(t('common.view_all')) ?></a></div>
                <?php if ($socialActivity !== []): ?>
                    <div class="social-activity-list">
                        <?php foreach ($socialActivity as $activity): ?>
                            <a href="/?page=notifications&amp;open_notification_id=<?= (int) ($activity['id'] ?? 0) ?>">
                                <span class="social-activity-icon" aria-hidden="true"><?= activity_icon_svg(notification_icon((string) ($activity['kind'] ?? 'info'))) ?></span>
                                <span><strong><?= e((string) ($activity['title'] ?? '')) ?></strong><small><?= e((string) ($activity['message'] ?? '')) ?></small></span>
                                <time datetime="<?= e((string) ($activity['created_at'] ?? '')) ?>"><?= e(human_time_ago((string) ($activity['created_at'] ?? ''))) ?></time>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="social-inline-empty is-wide"><span aria-hidden="true"><?= activity_icon_svg('sparkles') ?></span><p><?= e(t('social_hub.activity_empty')) ?></p><a href="/?page=notifications"><?= e(t('nav.notifications')) ?></a></div>
                <?php endif; ?>
            </article>
        </div>
    <?php elseif ($section === 'team'): ?>
        <nav class="hierarchy-nav-list" aria-label="<?= e(t('social_hub.section_team')) ?>">
            <?php foreach ($teams as $socialTeam): ?>
                <?php $row('/?page=team&team_id=' . (int) ($socialTeam['id'] ?? 0), 'users', (string) ($socialTeam['name'] ?? t('nav.team')), t('social_hub.members_count', ['count' => (string) ((int) ($socialTeam['member_count'] ?? 0))]), '', 'blue'); ?>
            <?php endforeach; ?>
            <?php if ($teams === []): ?>
                <?php $row('/?page=team', 'users', t('nav.team'), t('social_hub.team_empty')); ?>
            <?php endif; ?>
            <?php $challengeTeamId = (int) (($teams[0] ?? [])['id'] ?? 0); ?>
            <?php $row($challengeTeamId > 0 ? '/?page=team&team_id=' . $challengeTeamId . '&section=challenge' : '/?page=challenges', 'target', t('team.mobile_challenge'), t('social_hub.challenges_hint'), '', 'orange'); ?>
            <?php if (!empty($socialCanManageTeam)): ?>
                <?php $row('/?page=team_settings&team_id=' . (int) ($socialManageableTeamId ?? 0), 'sliders', t('team.settings'), t('social_hub.team_settings_hint')); ?>
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
            <?php $row('/?page=gallery&gallery_view=recent', 'image', t('gallery.title'), t('social_hub.gallery_hint')); ?>
            <?php $row('/?page=friends', 'users', t('nav.friends'), t('social_hub.friends_hint'), count($friends) > 0 ? (string) count($friends) : ''); ?>
        </nav>
    <?php else: ?>
        <nav class="hierarchy-nav-list" aria-label="<?= e(t('social_hub.section_competition')) ?>">
            <?php $row('/?page=duels', 'sword', t('nav.duels'), t('social_hub.duels_hint'), (string) ((int) ($duels['active'] ?? 0))); ?>
            <?php $row('/?page=competitions', 'trophy', t('nav.competitions'), t('social_hub.competitions_hint'), (string) ((int) ($competitions['active'] ?? 0))); ?>
            <?php $row('/?page=challenges', 'target', t('team.mobile_challenge'), t('social_hub.explore_challenges_hint')); ?>
        </nav>
    <?php endif; ?>
</section>
