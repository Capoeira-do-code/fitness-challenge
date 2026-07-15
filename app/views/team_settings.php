<?php

declare(strict_types=1);
?>
<section class="screen stack-lg">
    <div class="hero-panel">
        <div>
            <p class="eyebrow"><?= e(t('nav.team')) ?></p>
            <h1><?= e(t('team.settings')) ?></h1>
            <p class="muted"><?= e((string) $team['name']) ?></p>
        </div>
        <a class="btn btn-ghost" href="/?page=team&team_id=<?= (int) $team['id'] ?>"><?= e(t('nav.team')) ?></a>
    </div>

    <div class="grid-two">
        <article class="panel">
            <div class="panel-head"><div><p class="eyebrow"><?= e(t('team.configure')) ?></p><h2><?= e(t('team.settings')) ?></h2></div></div>
            <form method="post" action="/?page=team_settings&team_id=<?= (int) $team['id'] ?>" class="stack">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="team_settings">
                <label><?= e(t('team.name')) ?><input type="text" name="name" value="<?= e((string) $team['name']) ?>" required></label>
                <label><?= e(t('team.description')) ?><textarea name="description" rows="3" placeholder="<?= e(t('team.description_placeholder')) ?>"><?= e((string) ($team['description'] ?? '')) ?></textarea></label>
                <div class="grid-inline two">
                    <label><?= e(t('team.join_mode')) ?><select name="join_mode"><option value="open" <?= ($team['join_mode'] ?? '') === 'open' ? 'selected' : '' ?>>Open</option><option value="closed" <?= ($team['join_mode'] ?? '') !== 'open' ? 'selected' : '' ?>>Closed</option></select></label>
                    <label><?= e(t('team.visibility')) ?><select name="visibility"><option value="visible" <?= ($team['visibility'] ?? '') !== 'private' ? 'selected' : '' ?>>Visible</option><option value="private" <?= ($team['visibility'] ?? '') === 'private' ? 'selected' : '' ?>>Private</option></select></label>
                </div>
                <button class="btn btn-primary" type="submit"><?= e(t('common.save')) ?></button>
            </form>
        </article>

        <article class="panel">
            <div class="panel-head"><div><p class="eyebrow"><?= e(t('team.add_member')) ?></p><h2><?= e(t('team.members')) ?></h2></div></div>
            <form method="post" action="/?page=team_settings&team_id=<?= (int) $team['id'] ?>" class="stack compact-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="team_membership">
                <input type="hidden" name="member_action" value="add">
                <label><?= e(t('common.user')) ?><select name="user_id"><?php foreach (($availableUsers ?? []) as $user): ?><option value="<?= (int) $user['id'] ?>"><?= e((string) $user['display_name']) ?></option><?php endforeach; ?></select></label>
                <button class="btn btn-primary" type="submit"><?= e(t('team.add')) ?></button>
            </form>
            <div class="card-list">
                <?php foreach (($teamMembers ?? []) as $member): ?>
                    <article class="mini-card">
                        <a class="member-card-title user-profile-link" href="/?page=profile&amp;user_id=<?= (int) ($member['user_id'] ?? 0) ?>&amp;back=team&amp;team_id=<?= (int) $team['id'] ?>">
                            <?php $teamSettingsAvatarUrl = avatar_url($member); ?>
                            <?php if ($teamSettingsAvatarUrl !== ''): ?>
                                <img class="member-avatar" src="<?= e($teamSettingsAvatarUrl) ?>" alt="<?= e((string) $member['display_name']) ?>">
                            <?php else: ?>
                                <span class="member-avatar member-avatar-initials"><?= e(initials_for((string) $member['display_name'])) ?></span>
                            <?php endif; ?>
                        </a>
                        <a class="user-profile-link" href="/?page=profile&amp;user_id=<?= (int) ($member['user_id'] ?? 0) ?>&amp;back=team&amp;team_id=<?= (int) $team['id'] ?>">
                            <strong><?= e((string) $member['display_name']) ?></strong>
                            <span><?= e((string) $member['username']) ?> · <?= e((string) $member['role']) ?> · <?= (int) $member['active'] === 1 ? e(t('common.active')) : e(t('team.removed')) ?></span>
                        </a>
                        <form method="post" action="/?page=team_settings&team_id=<?= (int) $team['id'] ?>" class="inline-actions-mini">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="team_role">
                            <input type="hidden" name="user_id" value="<?= (int) $member['user_id'] ?>">
                            <select name="role"><option value="member" <?= $member['role'] === 'member' ? 'selected' : '' ?>>Member</option><option value="admin" <?= $member['role'] === 'admin' ? 'selected' : '' ?>>Admin</option></select>
                            <button class="btn small btn-primary" type="submit"><?= e(t('common.save')) ?></button>
                        </form>
                        <form method="post" action="/?page=team_settings&team_id=<?= (int) $team['id'] ?>">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="team_membership">
                            <input type="hidden" name="user_id" value="<?= (int) $member['user_id'] ?>">
                            <button class="btn small btn-ghost" name="member_action" value="<?= (int) $member['active'] === 1 ? 'remove' : 'add' ?>" type="submit"><?= (int) $member['active'] === 1 ? e(t('team.remove')) : e(t('team.add')) ?></button>
                        </form>
                    </article>
                <?php endforeach; ?>
            </div>
        </article>
    </div>

    <div class="grid-two team-settings-secondary">
    <article class="panel join-requests-panel">
        <div class="panel-head"><div><p class="eyebrow"><?= e(t('common.pending')) ?></p><h2><?= e(t('team.join_requests')) ?></h2></div><span class="badge"><?= count($joinRequests ?? []) ?></span></div>
        <div class="join-requests-list">
            <?php if (($joinRequests ?? []) === []): ?><p class="muted join-requests-empty"><?= e(t('dashboard.approvals_empty')) ?></p><?php endif; ?>
            <?php foreach (($joinRequests ?? []) as $request): ?>
                <form method="post" action="/?page=team_settings&team_id=<?= (int) $team['id'] ?>" class="join-request-row">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="resolve_join_request">
                    <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                    <a class="join-request-meta user-profile-link" href="/?page=profile&amp;user_id=<?= (int) ($request['user_id'] ?? 0) ?>">
                        <?php $joinReqAvatar = avatar_url($request); ?>
                        <?php if ($joinReqAvatar !== ''): ?>
                            <img class="member-avatar" src="<?= e($joinReqAvatar) ?>" alt="<?= e((string) $request['display_name']) ?>">
                        <?php else: ?>
                            <span class="member-avatar member-avatar-initials"><?= e(initials_for((string) $request['display_name'])) ?></span>
                        <?php endif; ?>
                        <div class="join-request-text">
                            <strong><?= e((string) $request['display_name']) ?></strong>
                            <span><?= e(format_date_eu((string) $request['requested_at'])) ?></span>
                        </div>
                    </a>
                    <div class="join-request-actions">
                        <button class="btn small btn-primary" name="decision" value="approve" type="submit" aria-label="<?= e(t('common.approve')) ?>"><?= e(t('common.approve')) ?></button>
                        <button class="btn small btn-ghost" name="decision" value="reject" type="submit" aria-label="<?= e(t('common.reject')) ?>"><?= e(t('common.reject')) ?></button>
                    </div>
                </form>
            <?php endforeach; ?>
        </div>
    </article>

    <?php
    $isDefaultTeam = (string) ($team['slug'] ?? '') === 'main';
    $activeMembers = array_values(array_filter($teamMembers ?? [], static fn($m): bool => (int) ($m['active'] ?? 0) === 1));
    $otherActiveMembers = array_values(array_filter($activeMembers, static fn($m): bool => (int) $m['user_id'] !== (int) $currentUser['id']));
    $activeAdminCount = 0;
    $iAmTeamAdmin = false;
    foreach ($activeMembers as $m) {
        $isAdminRole = in_array((string) $m['role'], ['admin', 'owner'], true);
        if ($isAdminRole) {
            $activeAdminCount++;
        }
        if ((int) $m['user_id'] === (int) $currentUser['id'] && $isAdminRole) {
            $iAmTeamAdmin = true;
        }
    }
    ?>
    <article class="panel team-danger-zone">
        <div class="panel-head"><div><p class="eyebrow"><?= e(t('team.danger_zone')) ?></p><h2><?= e(t('team.danger_zone')) ?></h2></div></div>

        <?php if (($iAmTeamAdmin || is_admin($currentUser)) && $activeAdminCount <= 1 && $otherActiveMembers !== []): ?>
            <div class="team-danger-row">
                <div>
                    <strong><?= e(t('team.transfer_admin')) ?></strong>
                    <p class="muted"><?= e(t('team.transfer_admin_help')) ?></p>
                </div>
                <form method="post" action="/?page=team_settings&team_id=<?= (int) $team['id'] ?>" class="inline-actions-mini">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="transfer_admin">
                    <select name="user_id" required>
                        <option value=""><?= e(t('team.select_member')) ?></option>
                        <?php foreach ($otherActiveMembers as $m): ?>
                            <option value="<?= (int) $m['user_id'] ?>"><?= e((string) $m['display_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn small btn-primary" type="submit"><?= e(t('team.make_admin')) ?></button>
                </form>
            </div>
        <?php endif; ?>

        <?php if (!$isDefaultTeam): ?>
            <div class="team-danger-row">
                <div>
                    <strong><?= e(t('team.delete')) ?></strong>
                    <p class="muted"><?= e(t('team.delete_help')) ?></p>
                </div>
                <form method="post" action="/?page=team_settings&team_id=<?= (int) $team['id'] ?>" onsubmit="return confirm('<?= e(t('team.delete_confirm')) ?>');">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete_team">
                    <button class="btn small btn-danger" type="submit"><?= e(t('team.delete')) ?></button>
                </form>
            </div>
        <?php else: ?>
            <p class="muted"><?= e(t('team.default_no_delete')) ?></p>
        <?php endif; ?>
    </article>
    </div>
</section>
