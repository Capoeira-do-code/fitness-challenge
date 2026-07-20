<?php

declare(strict_types=1);

$mySquads = is_array($mySquads ?? null) ? array_values((array) $mySquads) : [];
$competitions = is_array($competitions ?? null) ? array_values((array) $competitions) : [];
$compFriends = is_array($compFriends ?? null) ? array_values((array) $compFriends) : [];
$challengeableSquads = is_array($challengeableSquads ?? null) ? array_values((array) $challengeableSquads) : [];
$compMetrics = is_array($compMetrics ?? null) ? (array) $compMetrics : [];
$selectedSquadId = (int) ($selectedSquadId ?? 0);
$participatingSquadIds = is_array($participatingSquadIds ?? null) ? array_map('intval', $participatingSquadIds) : [];
$meId = (int) ($currentUser['id'] ?? 0);

$ownedSquadIds = [];
$mySquadIds = [];
$selectedSquadView = null;
foreach ($mySquads as $squadView) {
    $squadId = (int) (($squadView['squad'] ?? [])['id'] ?? 0);
    $ownedSquadIds[$squadId] = true;
    if ($squadId === $selectedSquadId) {
        $selectedSquadView = $squadView;
    }
}
$mySquadIds = array_fill_keys($participatingSquadIds, true);

$incoming = [];
$active = [];
$outgoing = [];
$participatingPending = [];
$past = [];
foreach ($competitions as $view) {
    $competition = (array) ($view['comp'] ?? []);
    $status = (string) ($competition['status'] ?? 'pending');
    if ($status === 'active') {
        $active[] = $view;
    } elseif ($status === 'pending') {
        if (isset($ownedSquadIds[(int) ($competition['opponent_squad_id'] ?? 0)])) {
            $incoming[] = $view;
        } elseif (isset($ownedSquadIds[(int) ($competition['challenger_squad_id'] ?? 0)])) {
            $outgoing[] = $view;
        } else {
            $participatingPending[] = $view;
        }
    } elseif (in_array($status, ['completed', 'cancelled', 'declined'], true)) {
        $past[] = $view;
    }
}

$completed = array_values(array_filter($past, static fn(array $view): bool => (string) (($view['comp'] ?? [])['status'] ?? '') === 'completed'));
$wins = count(array_filter($completed, static fn(array $view): bool => isset($mySquadIds[(int) (($view['comp'] ?? [])['winner_squad_id'] ?? 0)])));
$pendingCount = count($incoming) + count($outgoing) + count($participatingPending);
$membersCountLabel = static fn(int $count): string => t(
    $count === 1 ? 'competitions.member_count_one' : 'competitions.members_count',
    ['count' => $count]
);

$renderAvatar = static function (array $user, string $className = ''): void {
    $name = trim((string) ($user['display_name'] ?? $user['username'] ?? '?'));
    $url = avatar_url($user);
    $classes = trim('competition-member-avatar ' . cosmetic_frame_class($user) . ' ' . $className);
    if ($url !== '') {
        ?><img class="<?= e($classes) ?>" src="<?= e($url) ?>" alt="<?= e($name) ?>" loading="lazy"><?php
        return;
    }
    ?><span class="<?= e($classes) ?>" aria-hidden="true"><?= e(initials_for($name)) ?></span><?php
};

$statusLabel = static function (string $status): string {
    return match ($status) {
        'active' => t('common.active'),
        'completed' => t('competitions.completed'),
        'cancelled' => t('competitions.cancelled'),
        'declined' => t('competitions.declined'),
        default => t('common.pending'),
    };
};

$duelActionForm = static function (string $action, string $field, int $id, string $label, string $cls): void {
    ?>
    <form method="post" action="/?page=competitions" class="inline-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="<?= e($action) ?>">
        <input type="hidden" name="<?= e($field) ?>" value="<?= $id ?>">
        <button class="btn <?= e($cls) ?> small" type="submit"><?= e($label) ?></button>
    </form>
    <?php
};

$renderVersus = static function (array $view, bool $showValues = true) use ($mySquadIds): void {
    $competition = (array) ($view['comp'] ?? []);
    $standing = (array) ($view['standing'] ?? []);
    $metric = (string) ($competition['metric'] ?? '');
    $challenger = (array) ($standing['challenger_squad'] ?? []);
    $opponent = (array) ($standing['opponent_squad'] ?? []);
    $challengerId = (int) ($challenger['id'] ?? 0);
    $opponentId = (int) ($opponent['id'] ?? 0);
    $challengerName = (string) ($challenger['name'] ?? '?');
    $opponentName = (string) ($opponent['name'] ?? '?');
    $challengerValue = (float) ($standing['challenger_value'] ?? 0);
    $opponentValue = (float) ($standing['opponent_value'] ?? 0);
    ?>
    <div class="duel-vs competition-versus">
        <div class="duel-side<?= $showValues && $challengerValue > $opponentValue ? ' is-lead' : '' ?><?= isset($mySquadIds[$challengerId]) ? ' is-my-team' : '' ?>">
            <?php if (isset($mySquadIds[$challengerId])): ?><span class="competition-my-team-tag"><?= e(t('competitions.your_team')) ?></span><?php endif; ?>
            <span class="comp-squad-badge"><?= e(squad_badge($challengerName)) ?></span>
            <strong><?= e($challengerName) ?></strong>
            <?php if ($showValues): ?><span class="duel-value"><?= e(duels_format_value($metric, $challengerValue)) ?></span><?php endif; ?>
        </div>
        <div class="duel-mid">
            <span class="duel-metric-tag"><?= e(duels_metric_label($metric)) ?></span>
            <span class="duel-vs-label"><?= e(t('friends.vs')) ?></span>
        </div>
        <div class="duel-side<?= $showValues && $opponentValue > $challengerValue ? ' is-lead' : '' ?><?= isset($mySquadIds[$opponentId]) ? ' is-my-team' : '' ?>">
            <?php if (isset($mySquadIds[$opponentId])): ?><span class="competition-my-team-tag"><?= e(t('competitions.your_team')) ?></span><?php endif; ?>
            <span class="comp-squad-badge"><?= e(squad_badge($opponentName)) ?></span>
            <strong><?= e($opponentName) ?></strong>
            <?php if ($showValues): ?><span class="duel-value"><?= e(duels_format_value($metric, $opponentValue)) ?></span><?php endif; ?>
        </div>
    </div>
    <?php
};

$renderRoster = static function (array $memberRows, string $metric, string $teamName, bool $showValues = true) use ($renderAvatar, $membersCountLabel): void {
    ?>
    <section class="competition-roster">
        <div class="competition-roster-head">
            <strong><?= e($teamName) ?></strong>
            <span><?= e($membersCountLabel(count($memberRows))) ?></span>
        </div>
        <div class="competition-contribution-list">
            <?php foreach ($memberRows as $memberRow): ?>
                <?php $user = (array) ($memberRow['user'] ?? []); $name = (string) ($user['display_name'] ?? $user['username'] ?? '?'); ?>
                <a class="competition-contributor" href="/?page=profile&amp;user_id=<?= (int) ($user['id'] ?? 0) ?>">
                    <?php $renderAvatar($user); ?>
                    <span><strong><?= e($name) ?></strong><small>@<?= e((string) ($user['username'] ?? '')) ?></small></span>
                    <?php if ($showValues && $memberRow['value'] !== null): ?><b><?= e(duels_format_value($metric, (float) $memberRow['value'])) ?></b><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php
};

$renderCompetitionDetails = static function (array $competition) use ($statusLabel): void {
    $detailId = 'competition-card-details-' . (int) ($competition['id'] ?? 0);
    $status = (string) ($competition['status'] ?? 'pending');
    ?>
    <button class="versus-details-toggle" type="button" data-versus-details-toggle aria-expanded="false" aria-controls="<?= e($detailId) ?>">
        <span data-versus-details-label data-label-open="<?= e(t('duels.card_details')) ?>" data-label-close="<?= e(t('duels.card_details_hide')) ?>"><?= e(t('duels.card_details')) ?></span>
        <span aria-hidden="true">&rsaquo;</span>
    </button>
    <div class="versus-details" id="<?= e($detailId) ?>" data-versus-details hidden>
        <span><small><?= e(t('common.status')) ?></small><strong><?= e($statusLabel($status)) ?></strong></span>
        <span><small><?= e(t('duels.metric')) ?></small><strong><?= e(duels_metric_label((string) ($competition['metric'] ?? ''))) ?></strong></span>
        <span><small><?= e(t('duels.days')) ?></small><strong><?= e(t('duels.duration', ['days' => (int) ($competition['duration_days'] ?? 0)])) ?></strong></span>
        <?php if (trim((string) ($competition['start_date'] ?? '')) !== ''): ?><span><small><?= e(t('duels.start_date')) ?></small><strong><?= e(format_date_eu((string) $competition['start_date'])) ?></strong></span><?php endif; ?>
        <?php if (trim((string) ($competition['end_date'] ?? '')) !== ''): ?><span><small><?= e(t('duels.end_date')) ?></small><strong><?= e(format_date_eu((string) $competition['end_date'])) ?></strong></span><?php endif; ?>
    </div>
    <?php
};

$selectedSquad = (array) ($selectedSquadView['squad'] ?? []);
$selectedSquadMembers = array_values((array) ($selectedSquadView['members'] ?? []));
$defaultOpponent = count($challengeableSquads) === 1 ? (array) $challengeableSquads[0] : [];
$defaultMetric = (string) (array_key_first($compMetrics) ?? 'steps');
$hasAnySquad = $participatingSquadIds !== [] || $mySquads !== [];
?>
<section class="screen competitions-screen">
    <header class="competition-page-header">
        <div>
            <p class="eyebrow"><?= e(t('friends.eyebrow')) ?></p>
            <h1><?= e(t('competitions.title')) ?></h1>
            <p><?= e(t('competitions.subtitle')) ?></p>
        </div>
        <span class="competition-page-mark" aria-hidden="true"><?= activity_icon_svg('trophy') ?></span>
    </header>

    <nav class="competition-summary-strip" aria-label="<?= e(t('competitions.overview')) ?>">
        <a href="#competition-active" data-tone="green"><strong><?= count($active) ?></strong><small><?= e(t('competitions.active_now')) ?></small></a>
        <a href="#competition-pending" data-tone="orange"><strong><?= $pendingCount ?></strong><small><?= e(t('competitions.pending')) ?></small></a>
        <a href="#competition-history" data-tone="amber"><strong><?= $wins ?></strong><small><?= e(t('competitions.wins')) ?></small></a>
        <a href="#competition-history" data-tone="violet"><strong><?= count($past) ?></strong><small><?= e(t('competitions.past')) ?></small></a>
    </nav>

    <?php if ($incoming !== []): ?>
        <article class="panel competition-section competition-incoming" id="competition-pending">
            <div class="competition-section-head"><div><p class="eyebrow"><?= e(t('competitions.action_required')) ?></p><h2><?= e(t('competitions.incoming')) ?></h2></div><span class="badge"><?= count($incoming) ?></span></div>
            <div class="competition-card-list">
                <?php foreach ($incoming as $view): $competition = (array) $view['comp']; ?>
                    <div class="duel-card competition-card is-pending" data-state="pending-incoming">
                        <div class="competition-card-top"><span class="competition-state-chip is-pending"><?= e(t('competitions.invitation')) ?></span><span><?= e(t('competitions.accept_to_start')) ?></span></div>
                        <?php $renderVersus($view, false); ?>
                        <?php $renderCompetitionDetails($competition); ?>
                        <div class="inline-actions competition-primary-actions">
                            <?php $duelActionForm('comp_accept', 'comp_id', (int) $competition['id'], t('friends.accept'), 'btn-primary'); ?>
                            <?php $duelActionForm('comp_decline', 'comp_id', (int) $competition['id'], t('friends.reject'), 'btn-ghost'); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>
    <?php endif; ?>

    <article class="panel competition-section competition-active-section" id="competition-active">
        <div class="competition-section-head"><div><p class="eyebrow"><?= e(t('competitions.live')) ?></p><h2><?= e(t('competitions.active')) ?></h2></div><span class="badge"><?= count($active) ?></span></div>
        <?php if ($active === []): ?>
            <div class="empty-state competition-empty-state">
                <span class="empty-state-icon"><?= activity_icon_svg('trophy') ?></span>
                <p><strong><?= e(t('competitions.no_active')) ?></strong></p>
                <p class="muted small"><?= e($mySquads !== [] ? t('competitions.no_active_hint') : ($hasAnySquad ? t('competitions.owner_required_hint') : t('competitions.no_teams'))) ?></p>
                <a class="btn btn-primary small" href="<?= !$hasAnySquad ? '#competition-create-team' : ($mySquads !== [] && $challengeableSquads !== [] ? '#competition-teams' : '/?page=team') ?>">
                    <?= e(!$hasAnySquad ? t('competitions.create_team') : ($mySquads !== [] && $challengeableSquads !== [] ? t('competitions.create_competition') : t('profile.back_to_team'))) ?>
                </a>
            </div>
        <?php else: ?>
            <div class="competition-card-list">
                <?php foreach ($active as $view): ?>
                    <?php
                    $competition = (array) $view['comp'];
                    $standing = (array) $view['standing'];
                    $metric = (string) ($competition['metric'] ?? '');
                    $challengerValue = (float) ($standing['challenger_value'] ?? 0);
                    $opponentValue = (float) ($standing['opponent_value'] ?? 0);
                    $combinedValue = $challengerValue + $opponentValue;
                    $challengerShare = $combinedValue > 0 ? max(8, min(92, ($challengerValue / $combinedValue) * 100)) : 50;
                    $opponentShare = 100 - $challengerShare;
                    $challengerName = (string) (($standing['challenger_squad'] ?? [])['name'] ?? '?');
                    $opponentName = (string) (($standing['opponent_squad'] ?? [])['name'] ?? '?');
                    if ($challengerValue > $opponentValue) {
                        $leaderText = t('competitions.leads_by', ['name' => $challengerName, 'value' => duels_format_value($metric, $challengerValue - $opponentValue)]);
                    } elseif ($opponentValue > $challengerValue) {
                        $leaderText = t('competitions.leads_by', ['name' => $opponentName, 'value' => duels_format_value($metric, $opponentValue - $challengerValue)]);
                    } else {
                        $leaderText = t('competitions.tied_now');
                    }
                    try {
                        $competitionDaysLeft = max(0, (int) (new DateTimeImmutable('today'))->diff(new DateTimeImmutable((string) ($competition['end_date'] ?? 'today')))->days);
                    } catch (Throwable) {
                        $competitionDaysLeft = 0;
                    }
                    ?>
                    <div class="duel-card competition-card competition-live-card" data-state="active">
                        <div class="competition-card-top"><span class="competition-state-chip is-live"><i aria-hidden="true"></i><?= e(t('competitions.live_now')) ?></span><strong><?= $competitionDaysLeft > 0 ? e(t('season.days_left', ['n' => $competitionDaysLeft])) : e(t('season.ends_today')) ?></strong></div>
                        <?php $renderVersus($view, true); ?>
                        <div class="competition-balance" aria-label="<?= e($leaderText) ?>" style="--challenger-share: <?= e(number_format($challengerShare, 2, '.', '')) ?>%; --opponent-share: <?= e(number_format($opponentShare, 2, '.', '')) ?>%">
                            <span></span>
                            <span></span>
                        </div>
                        <p class="competition-leader-copy"><?= e($leaderText) ?></p>
                        <div class="competition-rosters">
                            <?php $renderRoster((array) ($standing['challenger_members'] ?? []), $metric, $challengerName); ?>
                            <?php $renderRoster((array) ($standing['opponent_members'] ?? []), $metric, $opponentName); ?>
                        </div>
                        <?php $renderCompetitionDetails($competition); ?>
                        <div class="competition-card-danger"><?php $duelActionForm('comp_cancel', 'comp_id', (int) $competition['id'], t('duels.cancel'), 'btn-ghost'); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>

    <article class="panel competition-section competition-command-center" id="competition-teams">
        <div class="competition-section-head"><div><p class="eyebrow"><?= e(t('competitions.new_challenge')) ?></p><h2><?= e(t('competitions.create_competition')) ?></h2><p><?= e(t('competitions.builder_hint')) ?></p></div></div>

        <?php if (!$hasAnySquad): ?>
            <details class="competition-inline-team-create" id="competition-create-team" open>
                <summary><span aria-hidden="true"><?= activity_icon_svg('users') ?></span><?= e(t('competitions.create_team')) ?><span aria-hidden="true">+</span></summary>
                <div class="competition-inline-team-create-body">
                    <form method="post" action="/?page=competitions" class="competition-create-team-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="squad_create">
                        <label><span><?= e(t('competitions.team_name')) ?></span><input type="text" name="name" maxlength="60" placeholder="<?= e(t('competitions.team_name')) ?>" required></label>
                        <button class="btn btn-primary" type="submit"><?= e(t('competitions.create')) ?></button>
                    </form>
                </div>
            </details>
        <?php endif; ?>

        <?php if ($mySquads !== [] && $challengeableSquads !== []): ?>
            <details class="competition-new-challenge">
                <summary><span aria-hidden="true"><?= activity_icon_svg('trophy') ?></span><?= e(t('competitions.new_challenge')) ?><span aria-hidden="true">+</span></summary>
                <div class="competition-new-challenge-body">
            <form method="post" action="/?page=competitions" class="competition-challenge-builder" data-competition-builder>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="comp_create">
                <div class="competition-team-selectors">
                    <label class="competition-team-selector is-own">
                        <span><b>1</b><strong><?= e(t('competitions.your_team')) ?></strong></span>
                        <select name="challenger_squad_id" required data-competition-team-select="own">
                            <?php if (count($mySquads) > 1): ?>
                                <option value="" data-name="<?= e(t('competitions.select_your_team')) ?>" data-badge="?" data-members-label=""<?= $selectedSquadId === 0 ? ' selected' : '' ?>><?= e(t('competitions.select_your_team')) ?></option>
                            <?php endif; ?>
                            <?php foreach ($mySquads as $squadView): $squad = (array) $squadView['squad']; $members = (array) ($squadView['members'] ?? []); ?>
                                <option value="<?= (int) ($squad['id'] ?? 0) ?>" data-name="<?= e((string) ($squad['name'] ?? '')) ?>" data-badge="<?= e(squad_badge((string) ($squad['name'] ?? ''))) ?>" data-members-label="<?= e($membersCountLabel(count($members))) ?>"<?= (int) ($squad['id'] ?? 0) === $selectedSquadId ? ' selected' : '' ?>><?= e((string) ($squad['name'] ?? '')) ?> · <?= e($membersCountLabel(count($members))) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <span class="competition-selector-arrow" aria-hidden="true">→</span>
                    <label class="competition-team-selector is-rival">
                        <span><b>2</b><strong><?= e(t('competitions.rival_team')) ?></strong></span>
                        <select name="opponent_squad_id" required data-competition-team-select="rival">
                            <?php if (count($challengeableSquads) > 1): ?>
                                <option value="" data-name="<?= e(t('competitions.select_rival_team')) ?>" data-badge="?" data-members-label="" selected><?= e(t('competitions.select_rival_team')) ?></option>
                            <?php endif; ?>
                            <?php foreach ($challengeableSquads as $opponentSquad): ?>
                                <option value="<?= (int) ($opponentSquad['id'] ?? 0) ?>" data-name="<?= e((string) ($opponentSquad['name'] ?? '')) ?>" data-badge="<?= e(squad_badge((string) ($opponentSquad['name'] ?? ''))) ?>" data-members-label="<?= e($membersCountLabel((int) ($opponentSquad['member_count'] ?? 0))) ?>"><?= e((string) ($opponentSquad['name'] ?? '')) ?><?= ($opponentSquad['owner_name'] ?? '') !== '' ? ' · ' . e((string) $opponentSquad['owner_name']) : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>

                <div class="competition-match-preview" aria-live="polite">
                    <div><span data-competition-preview-badge="own"><?= e(squad_badge((string) ($selectedSquad['name'] ?? ''))) ?></span><strong data-competition-preview-name="own"><?= e((string) ($selectedSquad['name'] ?? '')) ?></strong><small data-competition-preview-members="own"><?= e($membersCountLabel(count($selectedSquadMembers))) ?></small></div>
                    <b><small><?= e(t('competitions.match_preview')) ?></small><?= e(t('friends.vs')) ?></b>
                    <div><span data-competition-preview-badge="rival"><?= e(squad_badge((string) ($defaultOpponent['name'] ?? ''))) ?></span><strong data-competition-preview-name="rival"><?= e((string) ($defaultOpponent['name'] ?? '')) ?></strong><small data-competition-preview-members="rival"><?= e($membersCountLabel((int) ($defaultOpponent['member_count'] ?? 0))) ?></small></div>
                </div>

                <div class="competition-builder-settings">
                    <label><span><?= e(t('duels.metric')) ?></span><select name="metric" required data-competition-metric-select><?php foreach ($compMetrics as $key => $label): ?><option value="<?= e((string) $key) ?>"<?= (string) $key === $defaultMetric ? ' selected' : '' ?>><?= e((string) $label) ?></option><?php endforeach; ?></select></label>
                    <label><span><?= e(t('duels.days')) ?></span><input type="number" name="duration_days" min="1" max="60" value="7" required></label>
                    <button class="btn btn-primary" type="submit"><span aria-hidden="true"><?= activity_icon_svg('trophy') ?></span><?= e(t('competitions.challenge')) ?></button>
                </div>
            </form>
                </div>
            </details>
        <?php elseif (!$hasAnySquad): ?>
            <div class="competition-builder-empty"><span><?= activity_icon_svg('users') ?></span><div><strong><?= e(t('competitions.no_teams')) ?></strong><small><?= e(t('competitions.create_team_first')) ?></small></div></div>
        <?php elseif ($mySquads === []): ?>
            <div class="competition-builder-empty"><span><?= activity_icon_svg('users') ?></span><div><strong><?= e(t('competitions.owner_required')) ?></strong><small><?= e(t('competitions.owner_required_hint')) ?></small></div><a class="btn btn-ghost small" href="/?page=team"><?= e(t('profile.back_to_team')) ?></a></div>
        <?php else: ?>
            <div class="competition-builder-empty"><span><?= activity_icon_svg('trophy') ?></span><div><strong><?= e(t('competitions.no_rivals')) ?></strong><small><?= e(t('competitions.no_rivals_hint')) ?></small></div><a class="btn btn-ghost small" href="/?page=team"><?= e(t('profile.back_to_team')) ?></a></div>
        <?php endif; ?>

        <?php if ($mySquads !== []): ?>
            <div class="competition-team-management" id="competition-manage-teams">
                <div class="competition-subsection-head"><div><h3><?= e(t('competitions.manage_teams')) ?></h3><p><?= e(t('competitions.manage_teams_hint')) ?></p></div><span class="badge"><?= count($mySquads) ?></span></div>
                <div class="competition-squad-grid">
                    <?php foreach ($mySquads as $squadView): ?>
                        <?php
                        $squad = (array) ($squadView['squad'] ?? []);
                        $squadId = (int) ($squad['id'] ?? 0);
                        $members = array_values((array) ($squadView['members'] ?? []));
                        $memberIds = (array) ($squadView['member_ids'] ?? []);
                        $addable = array_values(array_filter($compFriends, static fn(array $friend): bool => !in_array((int) ($friend['id'] ?? 0), $memberIds, true)));
                        $isSelected = $squadId === $selectedSquadId;
                        ?>
                        <section class="squad-card<?= $isSelected ? ' is-selected' : '' ?>" data-competition-squad-id="<?= $squadId ?>">
                            <div class="squad-card-head">
                                <div class="competition-squad-identity"><span><?= e(squad_badge((string) ($squad['name'] ?? ''))) ?></span><div><strong><?= e((string) ($squad['name'] ?? '')) ?></strong><small><?= e($membersCountLabel(count($members))) ?></small></div></div>
                                <?php if ($isSelected): ?><span class="competition-selected-indicator" aria-label="<?= e(t('competitions.selected_team')) ?>">✓</span><?php endif; ?>
                            </div>
                            <div class="competition-squad-avatars" aria-label="<?= e($membersCountLabel(count($members))) ?>">
                                <?php foreach ($members as $member): ?>
                                    <?php $memberName = (string) ($member['display_name'] ?? $member['username'] ?? ''); ?>
                                    <a href="/?page=profile&amp;user_id=<?= (int) ($member['id'] ?? 0) ?>" title="<?= e($memberName) ?>" aria-label="<?= e($memberName) ?>"><?php $renderAvatar($member); ?></a>
                                <?php endforeach; ?>
                            </div>
                            <div class="competition-squad-actions">
                                <a class="btn <?= $isSelected ? 'btn-secondary' : 'btn-ghost' ?> small" href="/?page=competitions&amp;squad_id=<?= $squadId ?>#competition-teams"><?= e($isSelected ? t('competitions.selected_team') : t('competitions.use_team')) ?></a>
                                <?php if ($addable !== []): ?>
                                    <details class="squad-secondary-actions"><summary><?= e(t('competitions.add_member')) ?> <span aria-hidden="true">&rsaquo;</span></summary><div class="squad-secondary-actions-body"><form method="post" action="/?page=competitions" class="control-strip wrap squad-add-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="squad_add_member"><input type="hidden" name="squad_id" value="<?= $squadId ?>"><label class="friends-add-select"><span class="sr-only"><?= e(t('competitions.add_member')) ?></span><select name="user_id" required><option value=""><?= e(t('competitions.add_member')) ?></option><?php foreach ($addable as $friend): ?><option value="<?= (int) ($friend['id'] ?? 0) ?>"><?= e((string) ($friend['display_name'] ?? $friend['username'] ?? '')) ?></option><?php endforeach; ?></select></label><button class="btn btn-ghost small" type="submit"><?= e(t('competitions.add')) ?></button></form></div></details>
                                <?php endif; ?>
                            </div>
                        </section>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </article>

    <?php if ($outgoing !== []): ?>
        <article class="panel competition-section competition-outgoing"<?= $incoming === [] ? ' id="competition-pending"' : '' ?>>
            <div class="competition-section-head"><div><p class="eyebrow"><?= e(t('competitions.awaiting_response')) ?></p><h2><?= e(t('duels.sent')) ?></h2></div><span class="badge"><?= count($outgoing) ?></span></div>
            <div class="competition-card-list">
                <?php foreach ($outgoing as $view): $competition = (array) $view['comp']; ?>
                    <div class="duel-card competition-card is-pending" data-state="pending-outgoing">
                        <div class="competition-card-top"><span class="competition-state-chip is-pending"><?= e(t('common.pending')) ?></span><span><?= e(t('duels.waiting')) ?></span></div>
                        <?php $renderVersus($view, false); ?>
                        <?php $renderCompetitionDetails($competition); ?>
                        <div class="competition-card-danger"><?php $duelActionForm('comp_cancel', 'comp_id', (int) $competition['id'], t('duels.cancel'), 'btn-ghost'); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>
    <?php endif; ?>

    <?php if ($participatingPending !== []): ?>
        <article class="panel competition-section competition-outgoing"<?= $incoming === [] && $outgoing === [] ? ' id="competition-pending"' : '' ?>>
            <div class="competition-section-head"><div><p class="eyebrow"><?= e(t('competitions.awaiting_response')) ?></p><h2><?= e(t('competitions.pending')) ?></h2></div><span class="badge"><?= count($participatingPending) ?></span></div>
            <div class="competition-card-list">
                <?php foreach ($participatingPending as $view): $competition = (array) $view['comp']; ?>
                    <div class="duel-card competition-card is-pending" data-state="pending-participant">
                        <div class="competition-card-top"><span class="competition-state-chip is-pending"><?= e(t('common.pending')) ?></span><span><?= e(t('duels.waiting')) ?></span></div>
                        <?php $renderVersus($view, false); ?>
                        <?php $renderCompetitionDetails($competition); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>
    <?php endif; ?>

    <article class="panel competition-section competition-history" id="competition-history">
        <div class="competition-section-head"><div><p class="eyebrow"><?= e(t('competitions.archive')) ?></p><h2><?= e(t('competitions.history')) ?></h2><p><?= e(t('competitions.history_hint')) ?></p></div><span class="badge"><?= count($past) ?></span></div>
        <?php if ($past === []): ?>
            <div class="competition-history-empty"><span><?= activity_icon_svg('trophy') ?></span><p><?= e(t('competitions.no_history')) ?></p></div>
        <?php else: ?>
            <div class="competition-history-list">
                <?php foreach ($past as $view): ?>
                    <?php
                    $competition = (array) $view['comp'];
                    $standing = (array) $view['standing'];
                    $status = (string) ($competition['status'] ?? 'completed');
                    $winnerId = (int) ($competition['winner_squad_id'] ?? 0);
                    $winnerName = $winnerId === (int) ($competition['challenger_squad_id'] ?? 0)
                        ? (string) (($standing['challenger_squad'] ?? [])['name'] ?? '')
                        : ($winnerId === (int) ($competition['opponent_squad_id'] ?? 0) ? (string) (($standing['opponent_squad'] ?? [])['name'] ?? '') : '');
                    ?>
                    <div class="duel-card competition-card competition-history-card is-done" data-state="<?= e($status) ?>">
                        <div class="competition-card-top"><span class="competition-state-chip is-<?= e($status) ?>"><?= e($statusLabel($status)) ?></span><?php if (($competition['end_date'] ?? '') !== ''): ?><span><?= e(format_date_eu((string) $competition['end_date'])) ?></span><?php endif; ?></div>
                        <?php $renderVersus($view, $status === 'completed'); ?>
                        <?php if ($status === 'completed'): ?><p class="duel-result"><?= isset($mySquadIds[$winnerId]) ? '🏆 ' : '' ?><?= $winnerName !== '' ? e(t('duels.winner', ['name' => $winnerName])) : e(t('duels.tie')) ?></p><?php endif; ?>
                        <?php $renderCompetitionDetails($competition); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>
</section>
