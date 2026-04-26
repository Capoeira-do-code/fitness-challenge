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
                        <div class="member-card-title">
                            <?php if (!empty($member['avatar_path'])): ?>
                                <img class="member-avatar" src="<?= e(avatar_url($member)) ?>" alt="<?= e((string) $member['display_name']) ?>">
                            <?php else: ?>
                                <span class="member-avatar member-avatar-initials"><?= e(initials_for((string) $member['display_name'])) ?></span>
                            <?php endif; ?>
                        </div>
                        <div>
                            <strong><?= e((string) $member['display_name']) ?></strong>
                            <span><?= e((string) $member['username']) ?> · <?= e((string) $member['role']) ?> · <?= (int) $member['active'] === 1 ? e(t('common.active')) : e(t('team.removed')) ?></span>
                        </div>
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

    <article class="panel">
        <div class="panel-head"><div><p class="eyebrow"><?= e(t('common.pending')) ?></p><h2><?= e(t('team.join_requests')) ?></h2></div><span class="badge"><?= count($joinRequests ?? []) ?></span></div>
        <div class="card-list">
            <?php if (($joinRequests ?? []) === []): ?><p class="muted"><?= e(t('dashboard.approvals_empty')) ?></p><?php endif; ?>
            <?php foreach (($joinRequests ?? []) as $request): ?>
                <form method="post" action="/?page=team_settings&team_id=<?= (int) $team['id'] ?>" class="mini-card">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="resolve_join_request">
                    <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                    <div><strong><?= e((string) $request['display_name']) ?></strong><span><?= e(format_date_eu((string) $request['requested_at'])) ?></span></div>
                    <button class="btn small btn-primary" name="decision" value="approve" type="submit"><?= e(t('common.approve')) ?></button>
                    <button class="btn small btn-ghost" name="decision" value="reject" type="submit"><?= e(t('common.reject')) ?></button>
                </form>
            <?php endforeach; ?>
        </div>
    </article>
</section>
