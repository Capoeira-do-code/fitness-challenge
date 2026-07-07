<?php

declare(strict_types=1);

$friendsList = is_array($friendsList ?? null) ? array_values((array) $friendsList) : [];
$friendsIncoming = is_array($friendsIncoming ?? null) ? array_values((array) $friendsIncoming) : [];
$friendsOutgoing = is_array($friendsOutgoing ?? null) ? array_values((array) $friendsOutgoing) : [];
$friendsAddable = is_array($friendsAddable ?? null) ? array_values((array) $friendsAddable) : [];
$friendCompare = is_array($friendCompare ?? null) ? (array) $friendCompare : null;

$friendActionForm = static function (string $action, int $userId, string $label, string $btnClass) use (&$csrfPrinted): void {
    ?>
    <form method="post" action="/?page=friends" class="inline-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="<?= e($action) ?>">
        <input type="hidden" name="user_id" value="<?= $userId ?>">
        <button class="btn <?= e($btnClass) ?> small" type="submit"><?= e($label) ?></button>
    </form>
    <?php
};

$friendAvatar = static function (array $user): void {
    $url = avatar_url($user);
    if ($url !== '') {
        echo '<img class="member-avatar" src="' . e($url) . '" alt="' . e((string) ($user['display_name'] ?? '')) . '">';
    } else {
        echo '<span class="member-avatar member-avatar-initials">' . e(initials_for((string) ($user['display_name'] ?? '?'))) . '</span>';
    }
};

$compareMetricRows = [
    ['key' => 'steps', 'label' => t('metric.steps'), 'higher' => true, 'fmt' => 'int'],
    ['key' => 'distance_km', 'label' => t('metric.distance_km'), 'higher' => true, 'fmt' => 'km'],
    ['key' => 'workouts', 'label' => t('metric.workouts'), 'higher' => true, 'fmt' => 'int'],
    ['key' => 'score', 'label' => t('metric.score'), 'higher' => true, 'fmt' => 'num'],
    ['key' => 'step_completion_pct', 'label' => t('metric.steps') . ' %', 'higher' => true, 'fmt' => 'pct'],
    ['key' => 'workout_completion_pct', 'label' => t('metric.workouts') . ' %', 'higher' => true, 'fmt' => 'pct'],
];
$fmtVal = static function (string $fmt, $v): string {
    return match ($fmt) {
        'int' => number_format((float) $v, 0, '.', '.'),
        'km' => number_format((float) $v, 2, '.', '') . ' km',
        'pct' => (string) (int) $v . '%',
        default => rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.'),
    };
};
?>
<section class="screen stack-lg">
    <div class="hero-panel">
        <div>
            <p class="eyebrow"><?= e(t('friends.eyebrow')) ?></p>
            <h1><?= e(t('friends.title')) ?></h1>
            <p class="muted"><?= e(t('friends.subtitle')) ?></p>
        </div>
        <span class="badge"><?= count($friendsList) ?> <?= e(t('friends.count_label')) ?></span>
    </div>

    <?php if ($friendCompare !== null): ?>
        <?php
        $cmpUser = (array) ($friendCompare['user'] ?? []);
        $cmpMe = (array) ($friendCompare['me'] ?? []);
        $cmpFriend = (array) ($friendCompare['friend'] ?? []);
        ?>
        <article class="panel friends-compare-panel">
            <div class="panel-head">
                <h2><?= e(t('friends.compare_title')) ?></h2>
                <a class="btn btn-ghost small" href="/?page=friends"><?= e(t('common.back')) ?></a>
            </div>
            <div class="friends-compare-head">
                <div class="friends-compare-person">
                    <?php $friendAvatar((array) $currentUser); ?>
                    <strong><?= e(t('friends.you')) ?></strong>
                </div>
                <span class="friends-compare-vs"><?= e(t('friends.vs')) ?></span>
                <div class="friends-compare-person">
                    <?php $friendAvatar($cmpUser); ?>
                    <strong><?= e((string) ($cmpUser['display_name'] ?? '')) ?></strong>
                </div>
            </div>
            <div class="friends-compare-grid">
                <?php foreach ($compareMetricRows as $row): ?>
                    <?php
                    $mine = (float) ($cmpMe[$row['key']] ?? 0);
                    $theirs = (float) ($cmpFriend[$row['key']] ?? 0);
                    $meWin = $mine > $theirs;
                    $friendWin = $theirs > $mine;
                    ?>
                    <div class="friends-compare-row">
                        <span class="friends-compare-val<?= $meWin ? ' is-win' : '' ?>"><?= e($fmtVal($row['fmt'], $mine)) ?></span>
                        <span class="friends-compare-metric"><?= e($row['label']) ?></span>
                        <span class="friends-compare-val<?= $friendWin ? ' is-win' : '' ?>"><?= e($fmtVal($row['fmt'], $theirs)) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>
    <?php endif; ?>

    <?php if ($friendsIncoming !== []): ?>
        <article class="panel">
            <div class="panel-head"><h2><?= e(t('friends.incoming')) ?></h2><span class="badge"><?= count($friendsIncoming) ?></span></div>
            <div class="card-list">
                <?php foreach ($friendsIncoming as $req): ?>
                    <div class="mini-card friends-row">
                        <div class="friends-row-id"><?php $friendAvatar($req); ?><strong><?= e((string) ($req['display_name'] ?? $req['username'] ?? '')) ?></strong></div>
                        <div class="inline-actions">
                            <?php $friendActionForm('friend_accept', (int) $req['id'], t('friends.accept'), 'btn-primary'); ?>
                            <?php $friendActionForm('friend_reject', (int) $req['id'], t('friends.reject'), 'btn-ghost'); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>
    <?php endif; ?>

    <article class="panel">
        <div class="panel-head"><h2><?= e(t('friends.your_friends')) ?></h2><span class="badge"><?= count($friendsList) ?></span></div>
        <?php if ($friendsList === []): ?>
            <p class="muted"><?= e(t('friends.none')) ?></p>
        <?php else: ?>
            <div class="card-list">
                <?php foreach ($friendsList as $friend): ?>
                    <div class="mini-card friends-row">
                        <div class="friends-row-id">
                            <?php $friendAvatar($friend); ?>
                            <span>
                                <strong><?= e((string) ($friend['display_name'] ?? '')) ?></strong>
                                <small class="muted">@<?= e((string) ($friend['username'] ?? '')) ?></small>
                            </span>
                        </div>
                        <div class="inline-actions">
                            <a class="btn btn-primary small" href="/?page=friends&compare=<?= (int) $friend['id'] ?>"><?= e(t('friends.compare')) ?></a>
                            <?php $friendActionForm('friend_remove', (int) $friend['id'], t('friends.remove'), 'btn-ghost'); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>

    <?php if ($friendsOutgoing !== []): ?>
        <article class="panel">
            <div class="panel-head"><h2><?= e(t('friends.outgoing')) ?></h2><span class="badge"><?= count($friendsOutgoing) ?></span></div>
            <div class="card-list">
                <?php foreach ($friendsOutgoing as $req): ?>
                    <div class="mini-card friends-row">
                        <div class="friends-row-id"><?php $friendAvatar($req); ?><strong><?= e((string) ($req['display_name'] ?? '')) ?></strong></div>
                        <?php $friendActionForm('friend_remove', (int) $req['id'], t('friends.cancel_request'), 'btn-ghost'); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>
    <?php endif; ?>

    <article class="panel">
        <div class="panel-head"><h2><?= e(t('friends.add_title')) ?></h2></div>
        <?php if ($friendsAddable === []): ?>
            <p class="muted"><?= e(t('friends.add_none')) ?></p>
        <?php else: ?>
            <form method="post" action="/?page=friends" class="control-strip wrap">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="friend_request">
                <label class="friends-add-select">
                    <span class="sr-only"><?= e(t('friends.add_title')) ?></span>
                    <select name="user_id" required>
                        <option value=""><?= e(t('friends.add_placeholder')) ?></option>
                        <?php foreach ($friendsAddable as $candidate): ?>
                            <option value="<?= (int) $candidate['id'] ?>"><?= e((string) ($candidate['display_name'] ?? $candidate['username'] ?? '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="btn btn-primary" type="submit"><?= e(t('friends.send_request')) ?></button>
            </form>
        <?php endif; ?>
    </article>
</section>
