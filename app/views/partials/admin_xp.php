<?php

declare(strict_types=1);

$xpAmounts = is_array($xpAmounts ?? null) ? (array) $xpAmounts : xp_default_action_amounts();
$xpUsers = is_array($xpUsers ?? null) ? array_values((array) $xpUsers) : [];
$xpActionStats = is_array($xpActionStats ?? null) ? (array) $xpActionStats : [];
$xpRecentEvents = is_array($xpRecentEvents ?? null) ? array_values((array) $xpRecentEvents) : [];
$xpActions = [
    'daily_log' => ['label' => t('admin.xp_action_daily_log'), 'hint' => t('admin.xp_action_daily_log_hint'), 'icon' => 'check'],
    'workout' => ['label' => t('admin.xp_action_workout'), 'hint' => t('admin.xp_action_workout_hint'), 'icon' => 'dumbbell'],
    'photo' => ['label' => t('admin.xp_action_photo'), 'hint' => t('admin.xp_action_photo_hint'), 'icon' => 'image'],
    'achievement' => ['label' => t('admin.xp_action_achievement'), 'hint' => t('admin.xp_action_achievement_hint'), 'icon' => 'trophy'],
    'goal' => ['label' => t('admin.xp_action_goal'), 'hint' => t('admin.xp_action_goal_hint'), 'icon' => 'target'],
    'duel_win' => ['label' => t('admin.xp_action_duel_win'), 'hint' => t('admin.xp_action_duel_win_hint'), 'icon' => 'sword'],
];
$xpReasonLabels = array_map(static fn(array $action): string => (string) $action['label'], $xpActions);
$xpReasonLabels['admin_grant'] = t('admin.xp_reason_admin_grant');
$xpReasonLabels['admin_remove'] = t('admin.xp_reason_admin_remove');
$xpTotalDistributed = array_sum(array_map(static fn(array $row): int => (int) ($row['total_xp'] ?? 0), $xpUsers));
$xpUsersWithProgress = count(array_filter($xpUsers, static fn(array $row): bool => (int) ($row['total_xp'] ?? 0) > 0));
$xpAverageLevel = $xpUsers === [] ? 1 : round(array_sum(array_map(static fn(array $row): int => (int) ($row['level'] ?? 1), $xpUsers)) / count($xpUsers), 1);
$xpHighestLevel = $xpUsers === [] ? 1 : max(array_map(static fn(array $row): int => (int) ($row['level'] ?? 1), $xpUsers));
$xpDisabledRules = count(array_filter($xpAmounts, static fn(mixed $amount): bool => (int) $amount === 0));
$xpFirstLevelCost = xp_threshold_for_level(2);
$formatXpDateTime = static function (string $date): string {
    if (trim($date) === '') {
        return '';
    }
    try {
        return (new DateTimeImmutable($date))->format('d/m/Y · H:i');
    } catch (Throwable) {
        return $date;
    }
};
$xpAvatar = static function (array $user, string $class): string {
    $name = trim((string) ($user['display_name'] ?? $user['username'] ?? '?'));
    $url = avatar_url($user);
    if ($url !== '') {
        return '<img class="' . e($class) . '" src="' . e($url) . '" alt="" loading="lazy">';
    }
    $initial = function_exists('mb_substr') ? mb_substr($name !== '' ? $name : '?', 0, 1) : substr($name !== '' ? $name : '?', 0, 1);
    return '<span class="' . e($class) . ' is-fallback" aria-hidden="true">' . e(strtoupper($initial)) . '</span>';
};
?>

<article class="panel settings-panel active admin-xp-page" data-spa-section="xp">
    <section class="admin-xp-overview">
        <div class="admin-xp-overview-head">
            <span class="admin-xp-overview-icon" aria-hidden="true"><?= activity_icon_svg('bolt') ?></span>
            <div>
                <p class="eyebrow"><?= e(t('admin.section_xp')) ?></p>
                <h2><?= e(t('admin.xp_control_title')) ?></h2>
                <p><?= e(t('admin.xp_control_hint')) ?></p>
            </div>
            <?php $renderAdminBack('/?page=admin&group=system', t('admin.group_system')); ?>
        </div>
        <div class="admin-xp-kpis">
            <span><strong><?= e(number_format($xpTotalDistributed)) ?></strong><small><?= e(t('admin.xp_total_distributed')) ?></small></span>
            <span><strong><?= $xpUsersWithProgress ?>/<?= count($xpUsers) ?></strong><small><?= e(t('admin.xp_users_with_progress')) ?></small></span>
            <span><strong><?= e((string) $xpAverageLevel) ?></strong><small><?= e(t('admin.xp_average_level')) ?></small></span>
            <span><strong><?= $xpHighestLevel ?></strong><small><?= e(t('admin.xp_highest_level')) ?></small></span>
        </div>
    </section>

    <section class="admin-xp-section" id="xp-rules">
        <div class="admin-xp-section-head">
            <div><h3><?= e(t('admin.xp_amounts_title')) ?></h3><p><?= e(t('admin.xp_amounts_help')) ?></p></div>
            <form method="post" action="/?page=admin&amp;section=xp" onsubmit="return confirm('<?= e(t('admin.xp_reset_confirm')) ?>');">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="reset_xp_amounts">
                <button class="btn btn-ghost small" type="submit"><?= e(t('admin.xp_reset_defaults')) ?></button>
            </form>
        </div>
        <form method="post" action="/?page=admin&amp;section=xp" class="admin-xp-rules-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_xp_amounts">
            <div class="admin-xp-rules-grid">
                <?php foreach ($xpActions as $actionKey => $action): ?>
                    <?php
                    $amount = max(0, (int) ($xpAmounts[$actionKey] ?? 0));
                    $usage = (array) ($xpActionStats[$actionKey] ?? []);
                    $firstLevelPct = $xpFirstLevelCost > 0 ? (int) round(($amount / $xpFirstLevelCost) * 100) : 0;
                    ?>
                    <label class="admin-xp-rule-card<?= $amount === 0 ? ' is-disabled' : '' ?>">
                        <span class="admin-xp-rule-icon" aria-hidden="true"><?= activity_icon_svg((string) $action['icon']) ?></span>
                        <span class="admin-xp-rule-copy"><strong><?= e((string) $action['label']) ?></strong><small><?= e((string) $action['hint']) ?></small></span>
                        <span class="admin-xp-rule-input"><input type="number" name="xp_amounts[<?= e($actionKey) ?>]" min="0" max="100000" inputmode="numeric" value="<?= $amount ?>" aria-label="<?= e((string) $action['label']) ?>"><b>XP</b></span>
                        <span class="admin-xp-rule-meta">
                            <i class="<?= $amount === 0 ? 'is-off' : 'is-on' ?>"><?= e($amount === 0 ? t('admin.xp_rule_disabled') : t('admin.xp_rule_enabled')) ?></i>
                            <small><?= e(t('admin.xp_rule_usage', ['count' => (int) ($usage['event_count'] ?? 0)])) ?> · <?= $firstLevelPct ?>% <?= e(t('admin.xp_of_first_level')) ?></small>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
            <div class="admin-xp-rules-footer">
                <p><?= e(t('admin.xp_zero_disables')) ?><?php if ($xpDisabledRules > 0): ?> <strong><?= e(t('admin.xp_disabled_count', ['count' => $xpDisabledRules])) ?></strong><?php endif; ?></p>
                <button class="btn btn-primary" type="submit"><?= e(t('admin.xp_save_rules')) ?></button>
            </div>
        </form>
    </section>

    <div class="admin-xp-workspace">
        <section class="admin-xp-section admin-xp-ranking-section">
            <div class="admin-xp-section-head">
                <div><h3><?= e(t('admin.xp_ranking_title')) ?></h3><p><?= e(t('admin.xp_ranking_hint')) ?></p></div>
                <label class="admin-xp-search"><span aria-hidden="true"><?= activity_icon_svg('search') ?></span><input type="search" autocomplete="off" placeholder="<?= e(t('admin.xp_search_users')) ?>" data-xp-user-search></label>
            </div>
            <div class="admin-xp-user-list" data-xp-user-list>
                <?php foreach ($xpUsers as $index => $xpUser): ?>
                    <?php
                    $searchValue = trim((string) ($xpUser['display_name'] ?? '') . ' ' . (string) ($xpUser['username'] ?? ''));
                    $searchValue = function_exists('mb_strtolower') ? mb_strtolower($searchValue) : strtolower($searchValue);
                    ?>
                    <article class="admin-xp-user-row<?= (int) ($xpUser['active'] ?? 1) === 1 ? '' : ' is-inactive' ?>" data-xp-user-row data-xp-user-search-value="<?= e($searchValue) ?>">
                        <span class="admin-xp-position"><?= $index + 1 ?></span>
                        <?= $xpAvatar($xpUser, 'admin-xp-avatar') ?>
                        <span class="admin-xp-user-copy">
                            <span><strong><?= e((string) ($xpUser['display_name'] ?? '')) ?></strong><?php if (trim((string) ($xpUser['username'] ?? '')) !== ''): ?><small>@<?= e((string) $xpUser['username']) ?></small><?php endif; ?></span>
                            <span class="admin-xp-progress"><i style="--xp-progress: <?= max(0, min(100, (int) ($xpUser['progress_pct'] ?? 0))) ?>%"></i></span>
                            <small><?= e(t('admin.xp_to_next', ['current' => (int) ($xpUser['into_level'] ?? 0), 'span' => (int) ($xpUser['level_span'] ?? 1), 'remaining' => (int) ($xpUser['xp_to_next'] ?? 0)])) ?></small>
                        </span>
                        <span class="admin-xp-user-total"><b><?= e(t('xp.level_short')) ?> <?= (int) ($xpUser['level'] ?? 1) ?></b><strong><?= e(number_format((int) ($xpUser['total_xp'] ?? 0))) ?> XP</strong></span>
                    </article>
                <?php endforeach; ?>
                <p class="admin-xp-empty" data-xp-user-empty <?= $xpUsers === [] ? '' : 'hidden' ?>><?= e(t('admin.xp_no_users_found')) ?></p>
            </div>
        </section>

        <aside class="admin-xp-side">
            <details class="admin-xp-disclosure" open>
                <summary><span aria-hidden="true"><?= activity_icon_svg('sliders') ?></span><span><strong><?= e(t('admin.xp_adjust_title')) ?></strong><small><?= e(t('admin.xp_adjust_help')) ?></small></span><b aria-hidden="true">⌄</b></summary>
                <form method="post" action="/?page=admin&amp;section=xp" class="admin-xp-adjust-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="adjust_user_xp">
                    <label><span><?= e(t('common.user')) ?></span><select name="user_id" required><option value=""><?= e(t('admin.xp_select_user')) ?></option><?php foreach ($xpUsers as $xpUser): ?><option value="<?= (int) $xpUser['id'] ?>"><?= e((string) $xpUser['display_name']) ?> · <?= e(number_format((int) ($xpUser['total_xp'] ?? 0))) ?> XP</option><?php endforeach; ?></select></label>
                    <label><span><?= e(t('admin.xp_amount')) ?></span><input type="number" name="amount" step="1" value="10" min="-100000" max="100000" required data-xp-adjust-amount></label>
                    <div class="admin-xp-quick-values" aria-label="<?= e(t('admin.xp_quick_amounts')) ?>"><?php foreach ([-50, -10, 10, 50] as $quickAmount): ?><button type="button" data-xp-quick-amount="<?= $quickAmount ?>"><?= $quickAmount > 0 ? '+' : '' ?><?= $quickAmount ?></button><?php endforeach; ?></div>
                    <label><span><?= e(t('admin.xp_note')) ?></span><input type="text" name="note" maxlength="160" placeholder="<?= e(t('admin.xp_note_placeholder')) ?>"></label>
                    <p><?= e(t('admin.xp_amount_hint')) ?></p>
                    <button class="btn btn-primary" type="submit"><?= e(t('admin.xp_apply')) ?></button>
                </form>
            </details>

            <details class="admin-xp-disclosure">
                <summary><span aria-hidden="true"><?= activity_icon_svg('bolt') ?></span><span><strong><?= e(t('admin.xp_curve_title')) ?></strong><small><?= e(t('admin.xp_curve_hint')) ?></small></span><b aria-hidden="true">⌄</b></summary>
                <div class="admin-xp-level-curve">
                    <?php for ($level = 1; $level <= 10; $level++): ?>
                        <?php $threshold = xp_threshold_for_level($level); $nextCost = xp_threshold_for_level($level + 1) - $threshold; ?>
                        <span><b><?= e(t('xp.level_short')) ?> <?= $level ?></b><strong><?= e(number_format($threshold)) ?> XP</strong><small><?= e(t('admin.xp_next_level_cost', ['amount' => $nextCost])) ?></small></span>
                    <?php endfor; ?>
                </div>
            </details>
        </aside>
    </div>

    <details class="admin-xp-disclosure admin-xp-history">
        <summary><span aria-hidden="true"><?= activity_icon_svg('list') ?></span><span><strong><?= e(t('admin.xp_history_title')) ?></strong><small><?= e(t('admin.xp_history_hint', ['count' => count($xpRecentEvents)])) ?></small></span><b aria-hidden="true">⌄</b></summary>
        <div class="admin-xp-history-list">
            <?php foreach ($xpRecentEvents as $event): ?>
                <?php $eventReason = (string) ($event['reason'] ?? ''); $eventAmount = (int) ($event['amount'] ?? 0); ?>
                <article class="admin-xp-history-row<?= $eventAmount < 0 ? ' is-negative' : '' ?>">
                    <?= $xpAvatar($event, 'admin-xp-history-avatar') ?>
                    <span><strong><?= e((string) ($event['display_name'] ?? $event['username'] ?? t('common.user'))) ?></strong><small><?= e((string) ($xpReasonLabels[$eventReason] ?? $eventReason)) ?> · <?= e($formatXpDateTime((string) ($event['created_at'] ?? ''))) ?></small><?php if (trim((string) ($event['note'] ?? '')) !== ''): ?><em><?= e((string) $event['note']) ?></em><?php endif; ?></span>
                    <?php if (trim((string) ($event['actor_name'] ?? '')) !== ''): ?><small class="admin-xp-history-actor"><?= e(t('admin.xp_by_actor', ['name' => (string) $event['actor_name']])) ?></small><?php endif; ?>
                    <b><?= $eventAmount > 0 ? '+' : '' ?><?= e(number_format($eventAmount)) ?> XP</b>
                </article>
            <?php endforeach; ?>
            <?php if ($xpRecentEvents === []): ?><p class="admin-xp-empty"><?= e(t('admin.xp_history_empty')) ?></p><?php endif; ?>
        </div>
    </details>
</article>
