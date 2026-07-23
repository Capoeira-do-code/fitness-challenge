<?php

declare(strict_types=1);

$friendsList = is_array($friendsList ?? null) ? array_values((array) $friendsList) : [];
$friendsIncoming = is_array($friendsIncoming ?? null) ? array_values((array) $friendsIncoming) : [];
$friendsOutgoing = is_array($friendsOutgoing ?? null) ? array_values((array) $friendsOutgoing) : [];
$friendsAddable = is_array($friendsAddable ?? null) ? array_values((array) $friendsAddable) : [];
$friendsAddableCount = max(0, (int) ($friendsAddableCount ?? count($friendsAddable)));
$friendCompare = is_array($friendCompare ?? null) ? (array) $friendCompare : null;
$friendsBackUrl = trim((string) ($friendsBackUrl ?? ''));
$friendsBackLabel = trim((string) ($friendsBackLabel ?? ''));

$friendProfileUrl = static function (array $user): string {
    return '/?' . http_build_query([
        'page' => 'profile',
        'user_id' => (int) ($user['id'] ?? 0),
    ]);
};

$friendActionForm = static function (string $action, int $userId, string $label, string $btnClass): void {
    ?>
    <form method="post" action="/?page=friends" class="inline-form friends-action-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="<?= e($action) ?>">
        <input type="hidden" name="user_id" value="<?= $userId ?>">
        <button class="btn <?= e($btnClass) ?> small" type="submit"><?= e($label) ?></button>
    </form>
    <?php
};
$friendSecondaryMenu = static function (string $action, int $userId, string $label, bool $danger = false): void {
    static $menuCounter = 0;
    $menuCounter++;
    $formId = 'friend-secondary-' . $userId . '-' . $menuCounter;
    ?>
    <form id="<?= e($formId) ?>" method="post" action="/?page=friends" hidden>
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="user_id" value="<?= $userId ?>">
    </form>
    <?php
    echo render_kebab_menu([[
        'label' => $label,
        'danger' => $danger,
        'type' => 'submit',
        'attrs' => array_filter([
            'form' => $formId,
            'name' => 'action',
            'value' => $action,
            'data-confirm-action' => $danger ? t('friends.remove_confirm') : '',
        ], static fn(string $value): bool => $value !== ''),
    ]], ['label' => t('common.actions'), 'class' => 'friends-secondary-menu']);
};

$friendAvatar = static function (array $user): void {
    $url = avatar_url($user);
    $classes = trim('member-avatar ' . cosmetic_frame_class($user));
    if ($url !== '') {
        echo '<img class="' . e($classes) . '" src="' . e($url) . '" alt="' . e((string) ($user['display_name'] ?? '')) . '">';
    } else {
        echo '<span class="' . e($classes . ' member-avatar-initials') . '">' . e(initials_for((string) ($user['display_name'] ?? '?'))) . '</span>';
    }
};
$friendCoverUrl = static function (array $user): string {
    $path = trim((string) ($user['profile_cover_path'] ?? ''));
    return $path !== '' ? media_thumbnail_url($path, 720) : '';
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
<section class="screen stack-lg friends-screen">
    <article class="panel friends-panel friends-list-panel">
        <div class="panel-head friends-list-panel-head">
            <div class="friends-list-heading">
                <?php if ($friendsBackUrl !== '' && $friendsBackLabel !== ''): ?>
                    <a class="hierarchy-back destination-back friends-page-back" href="<?= e($friendsBackUrl) ?>" data-spa-history aria-label="<?= e(t('common.back')) ?>: <?= e($friendsBackLabel) ?>"><span aria-hidden="true">&larr;</span><strong><?= e($friendsBackLabel) ?></strong></a>
                <?php endif; ?>
                <h1 data-navigation-focus tabindex="-1"><?= e(t('friends.your_friends')) ?></h1>
            </div>
            <span class="badge" aria-label="<?= count($friendsList) ?> <?= e(t('friends.count_label')) ?>"><?= count($friendsList) ?></span>
        </div>
        <?php if ($friendsList === []): ?>
            <p class="muted"><?= e(t('friends.none')) ?></p>
        <?php else: ?>
            <div class="card-list">
                <?php foreach ($friendsList as $friend): ?>
                    <?php $rowCoverUrl = $friendCoverUrl($friend); ?>
                    <div class="mini-card friends-row<?= $rowCoverUrl !== '' ? ' has-cover' : '' ?>">
                        <?php if ($rowCoverUrl !== ''): ?><img class="friends-row-cover" src="<?= e($rowCoverUrl) ?>" alt="" loading="lazy" aria-hidden="true"><?php endif; ?>
                        <a class="friends-row-id" href="<?= e($friendProfileUrl($friend)) ?>" data-spa-link>
                            <?php $friendAvatar($friend); ?>
                            <span>
                                <strong><?= e((string) ($friend['display_name'] ?? '')) ?></strong>
                                <small class="muted">@<?= e((string) ($friend['username'] ?? '')) ?></small>
                            </span>
                        </a>
                        <div class="inline-actions friends-row-actions">
                            <a class="btn btn-primary small" href="/?page=friends&compare=<?= (int) $friend['id'] ?>" data-spa-link><?= e(t('friends.compare')) ?></a>
                            <?php $friendSecondaryMenu('friend_remove', (int) $friend['id'], t('friends.remove'), true); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>

    <?php if ($friendCompare !== null): ?>
        <?php
        $cmpUser = (array) ($friendCompare['user'] ?? []);
        $cmpMe = (array) ($friendCompare['me'] ?? []);
        $cmpFriend = (array) ($friendCompare['friend'] ?? []);
        ?>
        <article class="panel friends-compare-panel">
            <div class="panel-head">
                <h2><?= e(t('friends.compare_title')) ?></h2>
                <a class="hierarchy-back destination-back" href="/?page=friends" data-spa-link aria-label="<?= e(t('common.back')) ?>: <?= e(t('nav.friends')) ?>"><span aria-hidden="true">&larr;</span><strong><?= e(t('nav.friends')) ?></strong></a>
            </div>
            <div class="friends-compare-head">
                <a class="friends-compare-person user-profile-link" href="/?page=profile&amp;user_id=<?= (int) ($currentUser['id'] ?? 0) ?>">
                    <?php $friendAvatar((array) $currentUser); ?>
                    <strong><?= e(t('friends.you')) ?></strong>
                </a>
                <span class="friends-compare-vs"><?= e(t('friends.vs')) ?></span>
                <a class="friends-compare-person user-profile-link" href="<?= e($friendProfileUrl($cmpUser)) ?>">
                    <?php $friendAvatar($cmpUser); ?>
                    <strong><?= e((string) ($cmpUser['display_name'] ?? '')) ?></strong>
                </a>
            </div>
            <?php
            $meWins = 0;
            $friendWins = 0;
            foreach ($compareMetricRows as $row) {
                $mine = (float) ($cmpMe[$row['key']] ?? 0);
                $theirs = (float) ($cmpFriend[$row['key']] ?? 0);
                if ($mine > $theirs) {
                    $meWins++;
                } elseif ($theirs > $mine) {
                    $friendWins++;
                }
            }
            $leadClass = $meWins > $friendWins ? 'is-me' : ($friendWins > $meWins ? 'is-friend' : 'is-tie');
            ?>
            <div class="friends-compare-lead <?= e($leadClass) ?>">
                <span class="friends-compare-lead-score"><?= (int) $meWins ?></span>
                <span class="friends-compare-lead-label">
                    <?php if ($meWins > $friendWins): ?>
                        <?= e(t('friends.you_lead')) ?>
                    <?php elseif ($friendWins > $meWins): ?>
                        <?= e(t('friends.friend_leads', ['name' => (string) ($cmpUser['display_name'] ?? '')])) ?>
                    <?php else: ?>
                        <?= e(t('friends.tied')) ?>
                    <?php endif; ?>
                </span>
                <span class="friends-compare-lead-score"><?= (int) $friendWins ?></span>
            </div>
            <div class="friends-compare-grid">
                <?php foreach ($compareMetricRows as $row): ?>
                    <?php
                    $mine = (float) ($cmpMe[$row['key']] ?? 0);
                    $theirs = (float) ($cmpFriend[$row['key']] ?? 0);
                    $meWin = $mine > $theirs;
                    $friendWin = $theirs > $mine;
                    $barMax = max($mine, $theirs, 0.0001);
                    $mePct = max(0, min(100, ($mine / $barMax) * 100));
                    $theirPct = max(0, min(100, ($theirs / $barMax) * 100));
                    ?>
                    <?php
                    // The two numbers alone made you do the subtraction in your head. State
                    // the gap, and who it belongs to.
                    $gap = abs($mine - $theirs);
                    $gapText = $gap > 0 ? $fmtVal($row['fmt'], $gap) : '';
                    ?>
                    <div class="friends-compare-row<?= $meWin ? ' me-leads' : ($friendWin ? ' them-lead' : ' tied') ?>">
                        <div class="friends-compare-line">
                            <span class="friends-compare-val<?= $meWin ? ' is-win' : '' ?>"><?= e($fmtVal($row['fmt'], $mine)) ?></span>
                            <span class="friends-compare-metric">
                                <?= e($row['label']) ?>
                                <?php if ($gapText !== ''): ?>
                                    <small class="friends-compare-gap"><?= e($meWin ? '+' . $gapText : '-' . $gapText) ?></small>
                                <?php else: ?>
                                    <small class="friends-compare-gap"><?= e(t('duels.tie')) ?></small>
                                <?php endif; ?>
                            </span>
                            <span class="friends-compare-val<?= $friendWin ? ' is-win' : '' ?>"><?= e($fmtVal($row['fmt'], $theirs)) ?></span>
                        </div>
                        <div class="friends-compare-bars" aria-hidden="true">
                            <div class="friends-compare-bar-track friends-compare-bar-me">
                                <span class="friends-compare-bar-fill<?= $meWin ? ' is-win' : '' ?>" style="width: <?= e((string) round($mePct, 1)) ?>%"></span>
                            </div>
                            <div class="friends-compare-bar-track friends-compare-bar-them">
                                <span class="friends-compare-bar-fill<?= $friendWin ? ' is-win' : '' ?>" style="width: <?= e((string) round($theirPct, 1)) ?>%"></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="friends-compare-cta">
                <p class="muted small"><?= e(t('friends.compare_cta_hint')) ?></p>
                <a class="btn btn-primary small" href="/?page=duels"><?= e(t('friends.compare_cta')) ?></a>
            </div>
        </article>
    <?php endif; ?>

    <?php if ($friendsIncoming !== []): ?>
        <article class="panel friends-panel">
            <div class="panel-head"><h2><?= e(t('friends.incoming')) ?></h2><span class="badge"><?= count($friendsIncoming) ?></span></div>
            <div class="card-list">
                <?php foreach ($friendsIncoming as $req): ?>
                    <?php $rowCoverUrl = $friendCoverUrl($req); ?>
                    <div class="mini-card friends-row<?= $rowCoverUrl !== '' ? ' has-cover' : '' ?>">
                        <?php if ($rowCoverUrl !== ''): ?><img class="friends-row-cover" src="<?= e($rowCoverUrl) ?>" alt="" loading="lazy" aria-hidden="true"><?php endif; ?>
                        <a class="friends-row-id" href="<?= e($friendProfileUrl($req)) ?>" data-spa-link>
                            <?php $friendAvatar($req); ?>
                            <span>
                                <strong><?= e((string) ($req['display_name'] ?? $req['username'] ?? '')) ?></strong>
                                <small class="muted">@<?= e((string) ($req['username'] ?? '')) ?></small>
                            </span>
                        </a>
                        <div class="inline-actions friends-row-actions">
                            <?php $friendActionForm('friend_accept', (int) $req['id'], t('friends.accept'), 'btn-primary'); ?>
                            <?php $friendSecondaryMenu('friend_reject', (int) $req['id'], t('friends.reject')); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>
    <?php endif; ?>

    <?php if ($friendsOutgoing !== []): ?>
        <article class="panel friends-panel">
            <div class="panel-head"><h2><?= e(t('friends.outgoing')) ?></h2><span class="badge"><?= count($friendsOutgoing) ?></span></div>
            <div class="card-list">
                <?php foreach ($friendsOutgoing as $req): ?>
                    <?php $rowCoverUrl = $friendCoverUrl($req); ?>
                    <div class="mini-card friends-row<?= $rowCoverUrl !== '' ? ' has-cover' : '' ?>">
                        <?php if ($rowCoverUrl !== ''): ?><img class="friends-row-cover" src="<?= e($rowCoverUrl) ?>" alt="" loading="lazy" aria-hidden="true"><?php endif; ?>
                        <a class="friends-row-id" href="<?= e($friendProfileUrl($req)) ?>" data-spa-link>
                            <?php $friendAvatar($req); ?>
                            <span>
                                <strong><?= e((string) ($req['display_name'] ?? $req['username'] ?? '')) ?></strong>
                                <small class="muted">@<?= e((string) ($req['username'] ?? '')) ?></small>
                            </span>
                        </a>
                        <div class="inline-actions friends-row-actions">
                            <?php $friendSecondaryMenu('friend_remove', (int) $req['id'], t('friends.cancel_request')); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>
    <?php endif; ?>

    <article class="panel friends-panel friend-discovery-panel" data-friend-discovery data-friend-search-endpoint="/?page=api_friend_search" data-friend-csrf="<?= e(csrf_token()) ?>" data-friend-add-label="<?= e(t('friends.add')) ?>" data-friend-empty-label="<?= e(t('friends.no_search_results')) ?>">
        <div class="friend-discovery-head">
            <div><p class="eyebrow"><?= e(t('friends.discover')) ?></p><h2><?= e(t('friends.add_title')) ?></h2><p><?= e(t('friends.search_hint')) ?></p></div>
            <span class="badge"><?= $friendsAddableCount ?></span>
        </div>
        <?php if ($friendsAddableCount === 0): ?>
            <p class="muted friend-discovery-empty"><?= e(t('friends.add_none')) ?></p>
        <?php else: ?>
            <label class="friend-search-box" role="search">
                <span aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-4-4"></path></svg></span>
                <span class="sr-only"><?= e(t('friends.search_placeholder')) ?></span>
                <input type="search" name="friend_directory_search" autocomplete="off" autocapitalize="none" autocorrect="off" spellcheck="false" inputmode="search" enterkeyhint="search" aria-autocomplete="list" placeholder="<?= e(t('friends.search_placeholder')) ?>" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-protonpass-ignore="true" data-form-type="other" data-friend-search>
            </label>
            <div class="friend-suggestions-head"><strong><?= e(t('friends.suggestions')) ?></strong><small><?= e(t('friends.search_tip')) ?></small></div>
            <div class="friend-suggestion-list" data-friend-suggestion-list>
                <?php foreach ($friendsAddable as $candidate): ?>
                    <?php
                    $candidateName = (string) ($candidate['display_name'] ?? $candidate['username'] ?? '');
                    $candidateUsername = (string) ($candidate['username'] ?? '');
                    ?>
                    <div class="friend-suggestion-row" data-friend-candidate data-friend-search-value="<?= e($candidateName . ' @' . $candidateUsername) ?>">
                        <a class="friend-suggestion-profile" href="<?= e($friendProfileUrl($candidate)) ?>" data-spa-link>
                            <?php $friendAvatar($candidate); ?>
                            <span><strong><?= e($candidateName) ?></strong><small>@<?= e($candidateUsername) ?></small></span>
                        </a>
                        <form method="post" action="/?page=friends" class="inline-form">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="friend_request">
                            <input type="hidden" name="user_id" value="<?= (int) ($candidate['id'] ?? 0) ?>">
                            <button class="btn btn-primary small" type="submit"><?= e(t('friends.add')) ?></button>
                        </form>
                    </div>
                <?php endforeach; ?>
                <p class="friend-search-empty" data-friend-search-empty hidden><?= e(t('friends.no_search_results')) ?></p>
            </div>
        <?php endif; ?>
    </article>

</section>
