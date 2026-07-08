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
?>
<section class="screen stack-lg">
    <div class="hero-panel">
        <div>
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
                    <div class="duel-card">
                        <?php $compVs($view); ?>
                        <p class="muted small"><?= e(t('duels.duration', ['days' => (int) $c['duration_days']])) ?></p>
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
            <p class="muted"><?= e(t('competitions.no_active')) ?></p>
        <?php else: ?>
            <div class="stack-md">
                <?php foreach ($active as $view): $c = (array) $view['comp']; ?>
                    <div class="duel-card">
                        <?php $compVs($view); ?>
                        <p class="muted small"><?= e(t('duels.ends_on', ['date' => format_date_eu((string) ($c['end_date'] ?? ''))])) ?></p>
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
                    <div class="duel-card">
                        <p><strong><?= e((string) (($s['opponent_squad'] ?? [])['name'] ?? '')) ?></strong> · <?= e(duels_metric_label((string) $c['metric'])) ?> · <?= e(t('duels.duration', ['days' => (int) $c['duration_days']])) ?></p>
                        <p class="muted small"><?= e(t('duels.waiting')) ?></p>
                        <?php $duelActionForm('comp_cancel', 'comp_id', (int) $c['id'], t('duels.cancel'), 'btn-ghost'); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>
    <?php endif; ?>

    <article class="panel">
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
                            <?php $duelActionForm('squad_delete', 'squad_id', $squadId, t('competitions.delete_team'), 'btn-ghost'); ?>
                        </div>
                        <div class="squad-members">
                            <?php foreach ($members as $member): ?>
                                <span class="squad-member-chip">
                                    <?= e((string) ($member['display_name'] ?? $member['username'] ?? '')) ?>
                                    <?php if ((int) $member['id'] !== (int) $squad['owner_id']): ?>
                                        <form method="post" action="/?page=competitions" class="inline-form">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="squad_remove_member">
                                            <input type="hidden" name="squad_id" value="<?= $squadId ?>">
                                            <input type="hidden" name="user_id" value="<?= (int) $member['id'] ?>">
                                            <button class="squad-member-x" type="submit" aria-label="remove">×</button>
                                        </form>
                                    <?php endif; ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
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
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>

    <article class="panel">
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
                    <div class="duel-card is-done">
                        <?php $compVs($view); ?>
                        <p class="duel-result">
                            <?= isset($mySquadIds[$winnerId]) ? '🏆 ' : '' ?>
                            <?= $winnerName !== '' ? e(t('duels.winner', ['name' => $winnerName])) : e(t('duels.tie')) ?>
                        </p>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>
    <?php endif; ?>
</section>
