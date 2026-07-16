<?php

declare(strict_types=1);

$duels = is_array($duels ?? null) ? array_values((array) $duels) : [];
$duelFriends = is_array($duelFriends ?? null) ? array_values((array) $duelFriends) : [];
$duelMetrics = is_array($duelMetrics ?? null) ? (array) $duelMetrics : [];
$meId = (int) ($currentUser['id'] ?? 0);

$duelAvatar = static function (?array $user): void {
    $user = is_array($user) ? $user : [];
    $url = avatar_url($user);
    if ($url !== '') {
        echo '<img class="member-avatar" src="' . e($url) . '" alt="' . e((string) ($user['display_name'] ?? '')) . '">';
    } else {
        echo '<span class="member-avatar member-avatar-initials">' . e(initials_for((string) ($user['display_name'] ?? '?'))) . '</span>';
    }
};

$incoming = [];
$active = [];
$outgoing = [];
$done = [];
foreach ($duels as $vm) {
    $d = (array) $vm['duel'];
    $status = (string) $d['status'];
    if ($status === 'active') {
        $active[] = $vm;
    } elseif ($status === 'completed') {
        $done[] = $vm;
    } elseif ($status === 'pending') {
        if ((int) $d['opponent_id'] === $meId) {
            $incoming[] = $vm;
        } else {
            $outgoing[] = $vm;
        }
    }
}

$renderVs = static function (array $vm) use ($duelAvatar): void {
    $d = (array) $vm['duel'];
    $v = (array) $vm['values'];
    $metric = (string) $d['metric'];
    $cVal = (float) ($v['challenger'] ?? 0);
    $oVal = (float) ($v['opponent'] ?? 0);
    ?>
    <div class="duel-vs">
        <a class="duel-side user-profile-link<?= $cVal > $oVal ? ' is-lead' : '' ?>" href="/?page=profile&amp;user_id=<?= (int) (($v['challenger_user'] ?? [])['id'] ?? 0) ?>">
            <?php $duelAvatar($v['challenger_user'] ?? []); ?>
            <strong><?= e((string) (($v['challenger_user'] ?? [])['display_name'] ?? '')) ?></strong>
            <span class="duel-value"><?= e(duels_format_value($metric, $cVal)) ?></span>
        </a>
        <div class="duel-mid">
            <span class="duel-metric-tag"><?= e(duels_metric_label($metric)) ?></span>
            <span class="duel-vs-label"><?= e(t('friends.vs')) ?></span>
        </div>
        <a class="duel-side user-profile-link<?= $oVal > $cVal ? ' is-lead' : '' ?>" href="/?page=profile&amp;user_id=<?= (int) (($v['opponent_user'] ?? [])['id'] ?? 0) ?>">
            <?php $duelAvatar($v['opponent_user'] ?? []); ?>
            <strong><?= e((string) (($v['opponent_user'] ?? [])['display_name'] ?? '')) ?></strong>
            <span class="duel-value"><?= e(duels_format_value($metric, $oVal)) ?></span>
        </a>
    </div>
    <?php
};
$renderDuelDetails = static function (array $duel): void {
    $detailId = 'duel-card-details-' . (int) ($duel['id'] ?? 0);
    $status = (string) ($duel['status'] ?? 'pending');
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
        <span><small><?= e(t('duels.days')) ?></small><strong><?= e(t('duels.duration', ['days' => (int) ($duel['duration_days'] ?? 0)])) ?></strong></span>
        <?php if (trim((string) ($duel['start_date'] ?? '')) !== ''): ?><span><small><?= e(t('duels.start_date')) ?></small><strong><?= e(format_date_eu((string) $duel['start_date'])) ?></strong></span><?php endif; ?>
        <?php if (trim((string) ($duel['end_date'] ?? '')) !== ''): ?><span><small><?= e(t('duels.end_date')) ?></small><strong><?= e(format_date_eu((string) $duel['end_date'])) ?></strong></span><?php endif; ?>
    </div>
    <?php
};
?>
<section class="screen stack-lg duels-page">
    <div class="hero-panel app-page-hero">
        <div class="hero-copy hero-copy-page-title">
            <p class="eyebrow"><?= e(t('friends.eyebrow')) ?></p>
            <h1><?= e(t('duels.title')) ?></h1>
            <p class="muted"><?= e(t('duels.subtitle')) ?></p>
        </div>
        <a class="btn btn-ghost small" href="/?page=friends"><?= e(t('nav.friends')) ?></a>
    </div>

    <ol class="duels-how">
        <li>
            <span class="duels-how-step">1</span>
            <span><strong><?= e(t('duels.how_1_title')) ?></strong><small><?= e(t('duels.how_1_body')) ?></small></span>
        </li>
        <li>
            <span class="duels-how-step">2</span>
            <span><strong><?= e(t('duels.how_2_title')) ?></strong><small><?= e(t('duels.how_2_body')) ?></small></span>
        </li>
        <li>
            <span class="duels-how-step">3</span>
            <span><strong><?= e(t('duels.how_3_title')) ?></strong><small><?= e(t('duels.how_3_body')) ?></small></span>
        </li>
    </ol>

    <div class="duels-columns">
    <?php if ($incoming !== []): ?>
        <article class="panel duels-col-main">
            <div class="panel-head"><h2><?= e(t('duels.incoming')) ?></h2><span class="badge"><?= count($incoming) ?></span></div>
            <div class="stack-md">
                <?php foreach ($incoming as $vm): $d = (array) $vm['duel']; ?>
                    <div class="duel-card compact-list-item is-pending" data-state="pending-incoming">
                        <?php $renderVs($vm); ?>
                        <?php $renderDuelDetails($d); ?>
                        <div class="inline-actions">
                            <form method="post" action="/?page=duels" class="inline-form">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="duel_accept">
                                <input type="hidden" name="duel_id" value="<?= (int) $d['id'] ?>">
                                <button class="btn btn-primary small" type="submit"><?= e(t('friends.accept')) ?></button>
                            </form>
                            <form method="post" action="/?page=duels" class="inline-form">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="duel_decline">
                                <input type="hidden" name="duel_id" value="<?= (int) $d['id'] ?>">
                                <button class="btn btn-ghost small" type="submit"><?= e(t('friends.reject')) ?></button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>
    <?php endif; ?>

    <article class="panel duels-col-main">
        <div class="panel-head"><h2><?= e(t('duels.active')) ?></h2><span class="badge"><?= count($active) ?></span></div>
        <?php if ($active === []): ?>
            <p class="muted"><?= e(t('duels.no_active')) ?></p>
        <?php else: ?>
            <div class="stack-md">
                <?php foreach ($active as $vm): $d = (array) $vm['duel']; ?>
                    <?php
                    // Who is ahead, and by how much, stated in words. The colour cue alone
                    // did not answer "am I winning?" at a glance.
                    $v = (array) $vm['values'];
                    $metric = (string) $d['metric'];
                    $cVal = (float) ($v['challenger'] ?? 0);
                    $oVal = (float) ($v['opponent'] ?? 0);
                    $leaderName = $cVal === $oVal
                        ? ''
                        : (string) (($cVal > $oVal ? ($v['challenger_user'] ?? []) : ($v['opponent_user'] ?? []))['display_name'] ?? '');
                    $gap = duels_format_value($metric, abs($cVal - $oVal));
                    try {
                        $daysLeft = max(0, (int) (new DateTimeImmutable('today'))->diff(new DateTimeImmutable((string) ($d['end_date'] ?? 'today')))->days);
                    } catch (Throwable) {
                        $daysLeft = 0;
                    }
                    ?>
                    <div class="duel-card compact-list-item is-active" data-state="active">
                        <?php $renderVs($vm); ?>
                        <p class="duel-standing">
                            <span class="duel-standing-lead">
                                <?= $leaderName === ''
                                    ? e(t('duels.tie'))
                                    : e(t('duels.leading_by', ['name' => $leaderName, 'gap' => $gap])) ?>
                            </span>
                            <span class="duel-standing-time">
                                <?= $daysLeft > 0 ? e(t('season.days_left', ['n' => $daysLeft])) : e(t('season.ends_today')) ?>
                            </span>
                        </p>
                        <?php $renderDuelDetails($d); ?>
                        <form method="post" action="/?page=duels" class="inline-form">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="duel_cancel">
                            <input type="hidden" name="duel_id" value="<?= (int) $d['id'] ?>">
                            <button class="btn btn-ghost small" type="submit"><?= e(t('duels.cancel')) ?></button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>

    <?php if ($outgoing !== []): ?>
        <article class="panel">
            <div class="panel-head"><h2><?= e(t('duels.sent')) ?></h2><span class="badge"><?= count($outgoing) ?></span></div>
            <div class="stack-md">
                <?php foreach ($outgoing as $vm): $d = (array) $vm['duel']; $v = (array) $vm['values']; ?>
                    <div class="duel-card compact-list-item is-pending" data-state="pending-outgoing">
                        <p><strong><?= e((string) (($v['opponent_user'] ?? [])['display_name'] ?? '')) ?></strong> · <?= e(duels_metric_label((string) $d['metric'])) ?> · <?= e(t('duels.duration', ['days' => (int) $d['duration_days']])) ?></p>
                        <p class="muted small"><?= e(t('duels.waiting')) ?></p>
                        <?php $renderDuelDetails($d); ?>
                        <form method="post" action="/?page=duels" class="inline-form">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="duel_cancel">
                            <input type="hidden" name="duel_id" value="<?= (int) $d['id'] ?>">
                            <button class="btn btn-ghost small" type="submit"><?= e(t('duels.cancel')) ?></button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>
    <?php endif; ?>

    <article class="panel duels-col-side">
        <div class="panel-head">
            <div>
                <h2><?= e(t('duels.create_title')) ?></h2>
                <p class="muted small"><?= e(t('duels.create_hint')) ?></p>
            </div>
        </div>
        <?php if ($duelFriends === []): ?>
            <p class="muted"><?= e(t('duels.need_friends')) ?> <a href="/?page=friends"><?= e(t('nav.friends')) ?></a>.</p>
        <?php else: ?>
            <form method="post" action="/?page=duels" class="stack compact-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="duel_create">
                <label>
                    <?= e(t('duels.opponent')) ?>
                    <select name="opponent_id" required>
                        <option value=""><?= e(t('friends.add_placeholder')) ?></option>
                        <?php foreach ($duelFriends as $friend): ?>
                            <option value="<?= (int) $friend['id'] ?>"><?= e((string) ($friend['display_name'] ?? $friend['username'] ?? '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <?= e(t('duels.metric')) ?>
                    <select name="metric" required>
                        <?php foreach ($duelMetrics as $key => $label): ?>
                            <option value="<?= e((string) $key) ?>"><?= e((string) $label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <?= e(t('duels.days')) ?>
                    <input type="number" name="duration_days" min="1" max="60" value="7" required>
                </label>
                <button class="btn btn-primary" type="submit"><?= e(t('duels.send')) ?></button>
            </form>
        <?php endif; ?>
    </article>

    </div>

    <?php if ($done !== []): ?>
        <article class="panel">
            <div class="panel-head"><h2><?= e(t('duels.history')) ?></h2></div>
            <div class="stack-md">
                <?php foreach ($done as $vm): $d = (array) $vm['duel']; $v = (array) $vm['values']; ?>
                    <?php
                    $winnerId = (int) ($d['winner_id'] ?? 0);
                    $winnerName = $winnerId === (int) $d['challenger_id']
                        ? (string) (($v['challenger_user'] ?? [])['display_name'] ?? '')
                        : ($winnerId === (int) $d['opponent_id'] ? (string) (($v['opponent_user'] ?? [])['display_name'] ?? '') : '');
                    ?>
                    <div class="duel-card compact-list-item is-done" data-state="completed">
                        <?php $renderVs($vm); ?>
                        <p class="duel-result">
                            <?= $winnerId === $meId ? '🏆 ' : '' ?>
                            <?= $winnerName !== '' ? e(t('duels.winner', ['name' => $winnerName])) : e(t('duels.tie')) ?>
                        </p>
                        <?php $renderDuelDetails($d); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>
    <?php endif; ?>
</section>
