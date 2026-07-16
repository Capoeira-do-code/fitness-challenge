<?php

declare(strict_types=1);

$mySquads = is_array($mySquads ?? null) ? array_values((array) $mySquads) : [];
$competitions = is_array($competitions ?? null) ? array_values((array) $competitions) : [];
$compFriends = is_array($compFriends ?? null) ? array_values((array) $compFriends) : [];
$challengeableSquads = is_array($challengeableSquads ?? null) ? array_values((array) $challengeableSquads) : [];
$compMetrics = is_array($compMetrics ?? null) ? (array) $compMetrics : [];
$meId = (int) ($currentUser['id'] ?? 0);

$mySquadIds = [];
foreach ($mySquads as $sv) {
    $mySquadIds[(int) (($sv['squad'] ?? [])['id'] ?? 0)] = true;
}

$compVs = static function (array $view): void {
    $c = (array) $view['comp'];
    $s = (array) $view['standing'];
    $metric = (string) $c['metric'];
    $cName = (string) (($s['challenger_squad'] ?? [])['name'] ?? '?');
    $oName = (string) (($s['opponent_squad'] ?? [])['name'] ?? '?');
    $cVal = (float) ($s['challenger_value'] ?? 0);
    $oVal = (float) ($s['opponent_value'] ?? 0);
    $showVals = in_array((string) $c['status'], ['active', 'completed'], true);
    ?>
    <div class="duel-vs">
        <div class="duel-side<?= $showVals && $cVal > $oVal ? ' is-lead' : '' ?>">
            <span class="comp-squad-badge"><?= e(squad_badge($cName)) ?></span>
            <strong><?= e($cName) ?></strong>
            <?php if ($showVals): ?><span class="duel-value"><?= e(duels_format_value($metric, $cVal)) ?></span><?php endif; ?>
        </div>
        <div class="duel-mid">
            <span class="duel-metric-tag"><?= e(duels_metric_label($metric)) ?></span>
            <span class="duel-vs-label"><?= e(t('friends.vs')) ?></span>
        </div>
        <div class="duel-side<?= $showVals && $oVal > $cVal ? ' is-lead' : '' ?>">
            <span class="comp-squad-badge"><?= e(squad_badge($oName)) ?></span>
            <strong><?= e($oName) ?></strong>
            <?php if ($showVals): ?><span class="duel-value"><?= e(duels_format_value($metric, $oVal)) ?></span><?php endif; ?>
        </div>
    </div>
    <?php
};

$incoming = [];
$active = [];
$outgoing = [];
$done = [];
foreach ($competitions as $view) {
    $c = (array) $view['comp'];
    $status = (string) $c['status'];
    if ($status === 'active') {
        $active[] = $view;
    } elseif ($status === 'completed') {
        $done[] = $view;
    } elseif ($status === 'pending') {
        if (isset($mySquadIds[(int) $c['opponent_squad_id']])) {
            $incoming[] = $view;
        } else {
            $outgoing[] = $view;
        }
    }
}

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
$renderCompetitionDetails = static function (array $competition): void {
    $detailId = 'competition-card-details-' . (int) ($competition['id'] ?? 0);
    $status = (string) ($competition['status'] ?? 'pending');
    $statusLabel = match ($status) {
        'active' => t('common.active'),
        'completed' => t('workouts.done'),
        default => t('common.pending'),
    };
    ?>
    <button class="versus-details-toggle" type="button" data-versus-details-toggle aria-expanded="false" aria-controls="<?= e($detailId) ?>">
        <span data-versus-details-label data-label-open="<?= e(t('duels.card_details')) ?>" data-label-close="<?= e(t('duels.card_details_hide')) ?>"><?= e(t('duels.card_details')) ?></span>
        <span aria-hidden="true">&rsaquo;</span>
    </button>
    <div class="versus-details" id="<?= e($detailId) ?>" data-versus-details hidden>
        <span><small><?= e(t('common.status')) ?></small><strong><?= e($statusLabel) ?></strong></span>
        <span><small><?= e(t('duels.days')) ?></small><strong><?= e(t('duels.duration', ['days' => (int) ($competition['duration_days'] ?? 0)])) ?></strong></span>
        <?php if (trim((string) ($competition['start_date'] ?? '')) !== ''): ?><span><small><?= e(t('duels.start_date')) ?></small><strong><?= e(format_date_eu((string) $competition['start_date'])) ?></strong></span><?php endif; ?>
        <?php if (trim((string) ($competition['end_date'] ?? '')) !== ''): ?><span><small><?= e(t('duels.end_date')) ?></small><strong><?= e(format_date_eu((string) $competition['end_date'])) ?></strong></span><?php endif; ?>
    </div>
    <?php
};
?>
<section class="screen stack-lg">
    <div class="hero-panel app-page-hero">
        <div class="hero-copy hero-copy-page-title">
            <p class="eyebrow"><?= e(t('friends.eyebrow')) ?></p>
            <h1><?= e(t('competitions.title')) ?></h1>
            <p class="muted"><?= e(t('competitions.subtitle')) ?></p>
        </div>
    </div>

    <?php if ($incoming !== []): ?>
        <article class="panel">
            <div class="panel-head"><h2><?= e(t('competitions.incoming')) ?></h2><span class="badge"><?= count($incoming) ?></span></div>
            <div class="stack-md">
                <?php foreach ($incoming as $view): $c = (array) $view['comp']; ?>
                    <div class="duel-card compact-list-item is-pending" data-state="pending-incoming">
                        <?php $compVs($view); ?>
                        <?php $renderCompetitionDetails($c); ?>
                        <div class="inline-actions">
                            <?php $duelActionForm('comp_accept', 'comp_id', (int) $c['id'], t('friends.accept'), 'btn-primary'); ?>
                            <?php $duelActionForm('comp_decline', 'comp_id', (int) $c['id'], t('friends.reject'), 'btn-ghost'); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>
    <?php endif; ?>

    <article class="panel">
        <div class="panel-head"><h2><?= e(t('competitions.active')) ?></h2><span class="badge"><?= count($active) ?></span></div>
        <?php if ($active === []): ?>
            <div class="empty-state competition-empty-state">
                <span class="empty-state-icon"><?= activity_icon_svg('trophy') ?></span>
                <p><strong><?= e(t('competitions.no_active')) ?></strong></p>
                <p class="muted small"><?= e($mySquads !== [] ? t('competitions.no_active_hint') : t('competitions.no_teams')) ?></p>
                <a class="btn btn-primary small" href="<?= $mySquads === [] ? '#competition-create-team' : ($challengeableSquads !== [] ? '#competition-teams' : '/?page=team') ?>">
                    <?= e($mySquads === [] ? t('competitions.create_team') : ($challengeableSquads !== [] ? t('competitions.create_competition') : t('profile.back_to_team'))) ?>
                </a>
            </div>
        <?php else: ?>
            <div class="stack-md">
                <?php foreach ($active as $view): $c = (array) $view['comp']; ?>
                    <?php
                    try {
                        $competitionDaysLeft = max(0, (int) (new DateTimeImmutable('today'))->diff(new DateTimeImmutable((string) ($c['end_date'] ?? 'today')))->days);
                    } catch (Throwable) {
                        $competitionDaysLeft = 0;
                    }
                    ?>
                    <div class="duel-card compact-list-item is-active" data-state="active">
                        <?php $compVs($view); ?>
                        <p class="duel-standing"><span class="duel-standing-time"><?= $competitionDaysLeft > 0 ? e(t('season.days_left', ['n' => $competitionDaysLeft])) : e(t('season.ends_today')) ?></span></p>
                        <?php $renderCompetitionDetails($c); ?>
                        <?php $duelActionForm('comp_cancel', 'comp_id', (int) $c['id'], t('duels.cancel'), 'btn-ghost'); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>

    <?php if ($outgoing !== []): ?>
        <article class="panel">
            <div class="panel-head"><h2><?= e(t('duels.sent')) ?></h2><span class="badge"><?= count($outgoing) ?></span></div>
            <div class="stack-md">
                <?php foreach ($outgoing as $view): $c = (array) $view['comp']; $s = (array) $view['standing']; ?>
                    <div class="duel-card compact-list-item is-pending" data-state="pending-outgoing">
                        <p><strong><?= e((string) (($s['opponent_squad'] ?? [])['name'] ?? '')) ?></strong> · <?= e(duels_metric_label((string) $c['metric'])) ?> · <?= e(t('duels.duration', ['days' => (int) $c['duration_days']])) ?></p>
                        <p class="muted small"><?= e(t('duels.waiting')) ?></p>
                        <?php $renderCompetitionDetails($c); ?>
                        <?php $duelActionForm('comp_cancel', 'comp_id', (int) $c['id'], t('duels.cancel'), 'btn-ghost'); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>
    <?php endif; ?>

    <article class="panel" id="competition-teams">
        <div class="panel-head"><h2><?= e(t('competitions.my_teams')) ?></h2><span class="badge"><?= count($mySquads) ?></span></div>
        <?php if ($mySquads === []): ?>
            <p class="muted"><?= e(t('competitions.no_teams')) ?></p>
        <?php else: ?>
            <div class="stack-md">
                <?php foreach ($mySquads as $sv): ?>
                    <?php
                    $squad = (array) $sv['squad'];
                    $squadId = (int) $squad['id'];
                    $members = array_values((array) ($sv['members'] ?? []));
                    $memberIds = (array) ($sv['member_ids'] ?? []);
                    $addable = array_values(array_filter($compFriends, static fn(array $f): bool => !in_array((int) $f['id'], $memberIds, true)));
                    ?>
                    <div class="squad-card">
                        <div class="squad-card-head">
                            <strong><?= e((string) $squad['name']) ?></strong>
                            <?php
                            $squadDeleteFormId = 'squad-delete-' . $squadId;
                            echo render_kebab_menu([[
                                'label' => t('competitions.delete_team'),
                                'danger' => true,
                                'type' => 'submit',
                                'attrs' => [
                                    'form' => $squadDeleteFormId,
                                    'data-confirm-action' => t('team.delete_confirm'),
                                ],
                            ]], ['label' => t('common.actions')]);
                            ?>
                            <form id="<?= e($squadDeleteFormId) ?>" method="post" action="/?page=competitions" hidden>
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="squad_delete">
                                <input type="hidden" name="squad_id" value="<?= $squadId ?>">
                            </form>
                        </div>
                        <div class="squad-members">
                            <?php foreach ($members as $member): ?>
                                <span class="squad-member-chip">
                                    <a class="squad-member-profile" href="/?page=profile&amp;user_id=<?= (int) ($member['id'] ?? 0) ?>">
                                        <?= e((string) ($member['display_name'] ?? $member['username'] ?? '')) ?>
                                    </a>
                                    <?php if ((int) $member['id'] !== (int) $squad['owner_id']): ?>
                                        <form method="post" action="/?page=competitions" class="inline-form">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="squad_remove_member">
                                            <input type="hidden" name="squad_id" value="<?= $squadId ?>">
                                            <input type="hidden" name="user_id" value="<?= (int) $member['id'] ?>">
                                            <button class="squad-member-x" type="submit" aria-label="<?= e(t('friends.remove')) ?>">&times;</button>
                                        </form>
                                    <?php endif; ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($addable !== [] || $challengeableSquads !== []): ?>
                        <details class="squad-secondary-actions">
                            <summary><?= e(t('common.actions')) ?> <span aria-hidden="true">&rsaquo;</span></summary>
                            <div class="squad-secondary-actions-body">
                        <?php if ($addable !== []): ?>
                            <form method="post" action="/?page=competitions" class="control-strip wrap squad-add-form">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="squad_add_member">
                                <input type="hidden" name="squad_id" value="<?= $squadId ?>">
                                <label class="friends-add-select">
                                    <span class="sr-only"><?= e(t('competitions.add_member')) ?></span>
                                    <select name="user_id" required>
                                        <option value=""><?= e(t('competitions.add_member')) ?></option>
                                        <?php foreach ($addable as $f): ?>
                                            <option value="<?= (int) $f['id'] ?>"><?= e((string) ($f['display_name'] ?? $f['username'] ?? '')) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <button class="btn btn-ghost small" type="submit"><?= e(t('competitions.add')) ?></button>
                            </form>
                        <?php endif; ?>
                        <?php if ($challengeableSquads !== []): ?>
                            <form method="post" action="/?page=competitions" class="control-strip wrap squad-challenge-form">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="comp_create">
                                <input type="hidden" name="challenger_squad_id" value="<?= $squadId ?>">
                                <label class="friends-add-select">
                                    <span class="sr-only"><?= e(t('competitions.opponent_team')) ?></span>
                                    <select name="opponent_squad_id" required>
                                        <option value=""><?= e(t('competitions.opponent_team')) ?></option>
                                        <?php foreach ($challengeableSquads as $os): ?>
                                            <option value="<?= (int) $os['id'] ?>"><?= e((string) $os['name']) ?><?= ($os['owner_name'] ?? '') !== '' ? ' (' . e((string) $os['owner_name']) . ')' : '' ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <select name="metric" required>
                                    <?php foreach ($compMetrics as $key => $label): ?>
                                        <option value="<?= e((string) $key) ?>"><?= e((string) $label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="number" name="duration_days" min="1" max="60" value="7" aria-label="<?= e(t('duels.days')) ?>">
                                <button class="btn btn-primary small" type="submit"><?= e(t('competitions.challenge')) ?></button>
                            </form>
                        <?php endif; ?>
                            </div>
                        </details>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>

    <?php // Creating a team is the main action only when you have none. Once you belong
          // to a team it becomes a deliberate, secondary step, so the page stops looking
          // like it forgot you already have one. ?>
    <?php if ($mySquads === []): ?>
        <article class="panel" id="competition-create-team">
            <div class="panel-head"><h2><?= e(t('competitions.create_team')) ?></h2></div>
            <form method="post" action="/?page=competitions" class="control-strip wrap">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="squad_create">
                <label class="friends-add-select">
                    <span class="sr-only"><?= e(t('competitions.team_name')) ?></span>
                    <input type="text" name="name" maxlength="60" placeholder="<?= e(t('competitions.team_name')) ?>" required>
                </label>
                <button class="btn btn-primary" type="submit"><?= e(t('competitions.create')) ?></button>
            </form>
        </article>
    <?php endif; ?>

    <?php if ($done !== []): ?>
        <article class="panel">
            <div class="panel-head"><h2><?= e(t('duels.history')) ?></h2></div>
            <div class="stack-md">
                <?php foreach ($done as $view): $c = (array) $view['comp']; $s = (array) $view['standing']; ?>
                    <?php
                    $winnerId = (int) ($c['winner_squad_id'] ?? 0);
                    $winnerName = $winnerId === (int) $c['challenger_squad_id']
                        ? (string) (($s['challenger_squad'] ?? [])['name'] ?? '')
                        : ($winnerId === (int) $c['opponent_squad_id'] ? (string) (($s['opponent_squad'] ?? [])['name'] ?? '') : '');
                    ?>
                    <div class="duel-card compact-list-item is-done" data-state="completed">
                        <?php $compVs($view); ?>
                        <p class="duel-result">
                            <?= isset($mySquadIds[$winnerId]) ? '🏆 ' : '' ?>
                            <?= $winnerName !== '' ? e(t('duels.winner', ['name' => $winnerName])) : e(t('duels.tie')) ?>
                        </p>
                        <?php $renderCompetitionDetails($c); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>
    <?php endif; ?>
</section>
