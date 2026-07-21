<?php

declare(strict_types=1);

$teamSettingsSection = (string) ($teamSettingsSection ?? '');
$teamId = (int) ($team['id'] ?? 0);
$teamSettingsBaseUrl = '/?' . http_build_query(['page' => 'team_settings', 'team_id' => $teamId]);
$teamSettingsUrl = static function (string $section) use ($teamId): string {
    return '/?' . http_build_query(['page' => 'team_settings', 'team_id' => $teamId, 'section' => $section]);
};
$teamMembers = array_values((array) ($teamMembers ?? []));
$availableUsers = array_values((array) ($availableUsers ?? []));
$joinRequests = array_values((array) ($joinRequests ?? []));
$activeMembers = array_values(array_filter($teamMembers, static fn(array $member): bool => (int) ($member['active'] ?? 0) === 1));
$activeMemberCount = count($activeMembers);

$teamSettingsSections = [
    'general' => [
        'title' => t('team.settings_general'),
        'hint' => t('team.settings_general_hint'),
        'icon' => 'sliders',
        'tone' => 'blue',
        'meta' => t('common.edit'),
    ],
    'members' => [
        'title' => t('team.members'),
        'hint' => t('team.settings_members_hint'),
        'icon' => 'users',
        'tone' => 'green',
        'meta' => (string) $activeMemberCount,
    ],
    'requests' => [
        'title' => t('team.join_requests'),
        'hint' => t('team.settings_requests_hint'),
        'icon' => 'check',
        'tone' => 'amber',
        'meta' => (string) count($joinRequests),
    ],
    'danger' => [
        'title' => t('team.danger_zone'),
        'hint' => t('team.settings_danger_hint'),
        'icon' => 'shield',
        'tone' => 'red',
        'meta' => '',
    ],
];

$activeSettingsMeta = $teamSettingsSections[$teamSettingsSection] ?? null;
?>
<section class="screen stack-lg settings-page team-settings-screen" data-team-settings-section="<?= e($teamSettingsSection) ?>">
    <header class="hierarchy-page-header<?= $teamSettingsSection === '' ? ' hierarchy-page-header-root settings-compact-header' : ' settings-focused-head settings-section-head' ?>">
        <?php if ($teamSettingsSection !== ''): ?>
            <a class="hierarchy-back destination-back" href="<?= e($teamSettingsBaseUrl) ?>" aria-label="<?= e(t('common.back')) ?>: <?= e(t('team.settings')) ?>"><span aria-hidden="true">&larr;</span><strong><?= e(t('team.settings')) ?></strong></a>
        <?php endif; ?>
        <div>
            <p class="eyebrow"><?= e((string) $team['name']) ?></p>
            <h1><?= e((string) ($activeSettingsMeta['title'] ?? t('team.settings'))) ?></h1>
            <p class="muted"><?= e((string) ($activeSettingsMeta['hint'] ?? t('team.settings_hub_hint'))) ?></p>
        </div>
        <?php if ($teamSettingsSection === ''): ?>
            <a class="btn btn-ghost team-settings-team-link" href="/?page=team&amp;team_id=<?= $teamId ?>"><?= e(t('nav.team')) ?></a>
        <?php endif; ?>
    </header>

    <?php if ($teamSettingsSection === ''): ?>
        <nav class="settings-nav-grid team-settings-nav-grid" aria-label="<?= e(t('team.settings')) ?>">
            <?php foreach ($teamSettingsSections as $sectionKey => $section): ?>
                <a class="settings-nav-item" data-tone="<?= e((string) $section['tone']) ?>" href="<?= e($teamSettingsUrl($sectionKey)) ?>">
                    <span class="settings-nav-icon" aria-hidden="true"><?= activity_icon_svg((string) $section['icon']) ?></span>
                    <span class="settings-nav-copy">
                        <strong><?= e((string) $section['title']) ?></strong>
                        <small><?= e((string) $section['hint']) ?></small>
                    </span>
                    <?php if ((string) $section['meta'] !== ''): ?><span class="settings-nav-meta"><?= e((string) $section['meta']) ?></span><?php endif; ?>
                    <span class="settings-nav-arrow" aria-hidden="true">&rsaquo;</span>
                </a>
            <?php endforeach; ?>
        </nav>
    <?php elseif ($teamSettingsSection === 'general'): ?>
        <article class="panel team-settings-section-card">
            <div class="panel-head"><div><p class="eyebrow"><?= e(t('team.configure')) ?></p><h2><?= e(t('team.settings_general')) ?></h2></div></div>
            <form method="post" action="<?= e($teamSettingsUrl('general')) ?>" class="stack">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="team_settings">
                <label><?= e(t('team.name')) ?><input type="text" name="name" value="<?= e((string) $team['name']) ?>" required></label>
                <label><?= e(t('team.description')) ?><textarea name="description" rows="4" placeholder="<?= e(t('team.description_placeholder')) ?>"><?= e((string) ($team['description'] ?? '')) ?></textarea></label>
                <div class="grid-inline two">
                    <label><?= e(t('team.join_mode')) ?><select name="join_mode"><option value="open" <?= ($team['join_mode'] ?? '') === 'open' ? 'selected' : '' ?>><?= e(t('team.open')) ?></option><option value="closed" <?= ($team['join_mode'] ?? '') !== 'open' ? 'selected' : '' ?>><?= e(t('team.closed')) ?></option></select></label>
                    <label><?= e(t('team.visibility')) ?><select name="visibility"><option value="visible" <?= ($team['visibility'] ?? '') !== 'private' ? 'selected' : '' ?>><?= e(t('team.visible')) ?></option><option value="private" <?= ($team['visibility'] ?? '') === 'private' ? 'selected' : '' ?>><?= e(t('team.private')) ?></option></select></label>
                </div>
                <div class="settings-section-actions"><button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button></div>
            </form>
        </article>
    <?php elseif ($teamSettingsSection === 'members'): ?>
        <article class="panel team-settings-section-card team-settings-members-card">
            <div class="panel-head"><div><p class="eyebrow"><?= e(t('team.add_member')) ?></p><h2><?= e(t('team.members')) ?></h2></div><span class="badge"><?= $activeMemberCount ?></span></div>
            <?php if ($availableUsers !== []): ?>
                <form method="post" action="<?= e($teamSettingsUrl('members')) ?>" class="team-member-add-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="team_membership">
                    <input type="hidden" name="member_action" value="add">
                    <label><?= e(t('common.user')) ?><select name="user_id"><?php foreach ($availableUsers as $user): ?><option value="<?= (int) $user['id'] ?>"><?= e((string) $user['display_name']) ?></option><?php endforeach; ?></select></label>
                    <button class="btn btn-primary" type="submit"><?= e(t('team.add')) ?></button>
                </form>
            <?php else: ?>
                <p class="muted team-settings-inline-note"><?= e(t('team.settings_no_available_users')) ?></p>
            <?php endif; ?>
            <div class="team-settings-member-list">
                <?php foreach ($teamMembers as $member): ?>
                    <article class="team-settings-member-row<?= (int) ($member['active'] ?? 0) !== 1 ? ' is-inactive' : '' ?>">
                        <a class="team-settings-member-profile user-profile-link" href="/?page=profile&amp;user_id=<?= (int) ($member['user_id'] ?? 0) ?>&amp;back=team&amp;team_id=<?= $teamId ?>">
                            <?php $teamSettingsAvatarUrl = avatar_url($member); ?>
                            <?php if ($teamSettingsAvatarUrl !== ''): ?>
                                <img class="member-avatar" src="<?= e($teamSettingsAvatarUrl) ?>" alt="<?= e((string) $member['display_name']) ?>">
                            <?php else: ?>
                                <span class="member-avatar member-avatar-initials"><?= e(initials_for((string) $member['display_name'])) ?></span>
                            <?php endif; ?>
                            <span><strong><?= e((string) $member['display_name']) ?></strong><small>@<?= e((string) $member['username']) ?> · <?= (int) ($member['active'] ?? 0) === 1 ? e(t('common.active')) : e(t('team.removed')) ?></small></span>
                        </a>
                        <div class="team-settings-member-actions">
                            <form method="post" action="<?= e($teamSettingsUrl('members')) ?>">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="team_role">
                                <input type="hidden" name="user_id" value="<?= (int) $member['user_id'] ?>">
                                <label><span class="sr-only"><?= e(t('common.role')) ?></span><select name="role"><option value="member" <?= $member['role'] === 'member' ? 'selected' : '' ?>><?= e(t('team.member_role')) ?></option><option value="admin" <?= $member['role'] === 'admin' ? 'selected' : '' ?>><?= e(t('common.admin')) ?></option></select></label>
                                <button class="btn small btn-primary" type="submit"><?= e(t('common.save')) ?></button>
                            </form>
                            <form method="post" action="<?= e($teamSettingsUrl('members')) ?>">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="team_membership">
                                <input type="hidden" name="user_id" value="<?= (int) $member['user_id'] ?>">
                                <button class="btn small btn-ghost" name="member_action" value="<?= (int) ($member['active'] ?? 0) === 1 ? 'remove' : 'add' ?>" type="submit"><?= (int) ($member['active'] ?? 0) === 1 ? e(t('team.remove')) : e(t('team.add')) ?></button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </article>
    <?php elseif ($teamSettingsSection === 'requests'): ?>
        <article class="panel team-settings-section-card join-requests-panel">
            <div class="panel-head"><div><p class="eyebrow"><?= e(t('common.pending')) ?></p><h2><?= e(t('team.join_requests')) ?></h2></div><span class="badge"><?= count($joinRequests) ?></span></div>
            <div class="join-requests-list">
                <?php if ($joinRequests === []): ?>
                    <div class="empty-state empty-state-compact"><span class="empty-state-icon"><?= activity_icon_svg('check') ?></span><p class="muted"><?= e(t('dashboard.approvals_empty')) ?></p></div>
                <?php endif; ?>
                <?php foreach ($joinRequests as $request): ?>
                    <form method="post" action="<?= e($teamSettingsUrl('requests')) ?>" class="join-request-row">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="resolve_join_request">
                        <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                        <a class="join-request-meta user-profile-link" href="/?page=profile&amp;user_id=<?= (int) ($request['user_id'] ?? 0) ?>">
                            <?php $joinReqAvatar = avatar_url($request); ?>
                            <?php if ($joinReqAvatar !== ''): ?><img class="member-avatar" src="<?= e($joinReqAvatar) ?>" alt="<?= e((string) $request['display_name']) ?>"><?php else: ?><span class="member-avatar member-avatar-initials"><?= e(initials_for((string) $request['display_name'])) ?></span><?php endif; ?>
                            <span class="join-request-text"><strong><?= e((string) $request['display_name']) ?></strong><span><?= e(format_date_eu((string) $request['requested_at'])) ?></span></span>
                        </a>
                        <span class="join-request-actions"><button class="btn small btn-primary" name="decision" value="approve" type="submit"><?= e(t('common.approve')) ?></button><button class="btn small btn-ghost" name="decision" value="reject" type="submit"><?= e(t('common.reject')) ?></button></span>
                    </form>
                <?php endforeach; ?>
            </div>
        </article>
    <?php elseif ($teamSettingsSection === 'danger'): ?>
        <?php
        $isDefaultTeam = (string) ($team['slug'] ?? '') === 'main';
        $otherActiveMembers = array_values(array_filter($activeMembers, static fn(array $member): bool => (int) $member['user_id'] !== (int) $currentUser['id']));
        $activeAdminCount = 0;
        $iAmTeamAdmin = false;
        foreach ($activeMembers as $member) {
            $isAdminRole = in_array((string) $member['role'], ['admin', 'owner'], true);
            if ($isAdminRole) {
                $activeAdminCount++;
            }
            if ((int) $member['user_id'] === (int) $currentUser['id'] && $isAdminRole) {
                $iAmTeamAdmin = true;
            }
        }
        ?>
        <article class="panel team-settings-section-card team-danger-zone">
            <div class="panel-head"><div><p class="eyebrow"><?= e(t('team.danger_zone')) ?></p><h2><?= e(t('team.danger_zone')) ?></h2></div></div>
            <?php if (($iAmTeamAdmin || is_admin($currentUser)) && $activeAdminCount <= 1 && $otherActiveMembers !== []): ?>
                <div class="team-danger-row">
                    <div><strong><?= e(t('team.transfer_admin')) ?></strong><p class="muted"><?= e(t('team.transfer_admin_help')) ?></p></div>
                    <form method="post" action="<?= e($teamSettingsUrl('danger')) ?>" class="team-danger-action-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="transfer_admin">
                        <select name="user_id" aria-label="<?= e(t('team.select_member')) ?>" required><option value=""><?= e(t('team.select_member')) ?></option><?php foreach ($otherActiveMembers as $member): ?><option value="<?= (int) $member['user_id'] ?>"><?= e((string) $member['display_name']) ?></option><?php endforeach; ?></select>
                        <button class="btn small btn-primary" type="submit"><?= e(t('team.make_admin')) ?></button>
                    </form>
                </div>
            <?php endif; ?>
            <?php if (!$isDefaultTeam): ?>
                <div class="team-danger-row">
                    <div><strong><?= e(t('team.delete')) ?></strong><p class="muted"><?= e(t('team.delete_help')) ?></p></div>
                    <form method="post" action="<?= e($teamSettingsUrl('danger')) ?>" onsubmit="return confirm('<?= e(t('team.delete_confirm')) ?>');">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="delete_team">
                        <button class="btn small btn-danger" type="submit"><?= e(t('team.delete')) ?></button>
                    </form>
                </div>
            <?php else: ?>
                <p class="muted team-settings-inline-note"><?= e(t('team.default_no_delete')) ?></p>
            <?php endif; ?>
        </article>
    <?php endif; ?>
</section>
