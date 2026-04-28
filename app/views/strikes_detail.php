<?php

declare(strict_types=1);

$selectedMetric = is_array($selectedMetric ?? null) ? $selectedMetric : [];
$selectedUser = is_array($selectedMetric['user'] ?? null) ? $selectedMetric['user'] : [];
$selectedUserId = (int) ($selectedUser['id'] ?? 0);
$snapshot = is_array($selectedSnapshot ?? null) ? $selectedSnapshot : [];
$rows = array_values((array) ($strikeRows ?? []));
$pendingVotes = array_values((array) ($pendingStrikeVotes ?? []));
$dashboardView = (string) ($dashboardView ?? 'current_week');
$weekOptions = array_values((array) ($weekOptions ?? []));
$backUrl = (string) ($backUrl ?? '/?page=dashboard');
$currentUserId = (int) ($currentUser['id'] ?? 0);

$statusClass = static function (string $status): string {
    return match ($status) {
        'accepted' => 'badge-ok',
        'pending' => 'badge-warn',
        'rejected' => 'badge-bad',
        default => '',
    };
};
?>
<section class="screen stack-lg">
    <div class="hero-panel">
        <div>
            <p class="eyebrow"><?= e(t('metric.strikes')) ?></p>
            <h1><?= e(t('strikes.detail_title')) ?></h1>
            <p class="muted"><?= e(t('strikes.detail_subtitle')) ?></p>
        </div>
        <a class="btn btn-ghost" href="<?= e($backUrl) ?>"><?= e(t('metric.back_dashboard')) ?></a>
    </div>

    <article class="panel">
        <form method="get" class="control-strip wrap">
            <input type="hidden" name="page" value="strikes_detail">
            <label>
                <?= e(t('dashboard.viewing')) ?>
                <select name="user_id" onchange="this.form.submit()">
                    <?php foreach ((array) ($users ?? []) as $user): ?>
                        <option value="<?= (int) ($user['id'] ?? 0) ?>" <?= (int) ($user['id'] ?? 0) === $selectedUserId ? 'selected' : '' ?>>
                            <?= e((string) ($user['display_name'] ?? t('common.user'))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <?= e(t('dashboard.view_mode')) ?>
                <select name="view" onchange="this.form.submit()">
                    <option value="current_week" <?= $dashboardView === 'current_week' ? 'selected' : '' ?>><?= e(t('dashboard.current_week')) ?></option>
                    <option value="total" <?= $dashboardView === 'total' ? 'selected' : '' ?>><?= e(t('metric.total')) ?></option>
                    <?php foreach ($weekOptions as $weekStart): ?>
                        <option value="<?= e((string) $weekStart) ?>" <?= $dashboardView === (string) $weekStart ? 'selected' : '' ?>>
                            <?= e(format_date_eu((string) $weekStart)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>
    </article>

    <article class="panel">
        <div class="metric-grid dashboard-kpis">
            <article class="metric-card"><div><span><?= e(t('metric.strikes')) ?></span><strong><?= e((string) ((int) ($snapshot['strikes'] ?? 0))) ?></strong></div></article>
            <article class="metric-card"><div><span><?= e(t('strikes.economic_impact')) ?></span><strong>€<?= e(number_format((float) ($snapshot['penalty'] ?? 0), 2, '.', '')) ?></strong></div></article>
            <article class="metric-card"><div><span><?= e(t('strikes.events')) ?></span><strong><?= e((string) count($rows)) ?></strong></div></article>
        </div>
    </article>

    <?php if ($pendingVotes !== []): ?>
        <article class="panel">
            <h2><?= e(t('strikes.pending_votes_title')) ?></h2>
            <div class="table-wrap">
                <table class="table compact">
                    <thead>
                    <tr>
                        <th><?= e(t('common.user')) ?></th>
                        <th><?= e(t('common.date')) ?></th>
                        <th><?= e(t('common.category')) ?></th>
                        <th><?= e(t('common.status')) ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pendingVotes as $pending): ?>
                        <tr>
                            <td><?= e((string) ($pending['target_name'] ?? t('common.user'))) ?></td>
                            <td><?= e(format_date_eu((string) ($pending['event_date'] ?? ''))) ?></td>
                            <td><?= e(strike_review_reason_label((string) ($pending['reason'] ?? 'step_miss'))) ?></td>
                            <td><span class="badge badge-warn"><?= e(strike_review_status_label((string) ($pending['status'] ?? 'pending'))) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </article>
    <?php endif; ?>

    <?php if ($rows === []): ?>
        <article class="panel">
            <p class="muted"><?= e(t('penalties.no_data')) ?></p>
        </article>
    <?php else: ?>
        <article class="panel">
            <div class="table-wrap">
                <table class="table compact">
                    <thead>
                    <tr>
                        <th><?= e(t('common.date')) ?></th>
                        <th><?= e(t('common.category')) ?></th>
                        <th><?= e(t('strikes.generated_amount')) ?></th>
                        <th><?= e(t('common.status')) ?></th>
                        <th><?= e(t('common.notes')) ?></th>
                        <th><?= e(t('photo.actions')) ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $status = (string) ($row['status'] ?? 'confirmed');
                        $requestId = (int) ($row['request_id'] ?? 0);
                        $eligibleVoters = array_values((array) ($row['eligible_voters'] ?? []));
                        $canVote = $status === 'pending' && in_array($currentUserId, $eligibleVoters, true) && (int) ($row['requested_by'] ?? 0) !== $currentUserId;
                        ?>
                        <tr>
                            <td><?= e(format_date_eu((string) ($row['event_date'] ?? ''))) ?></td>
                            <td><?= e((string) ($row['reason_label'] ?? '')) ?></td>
                            <td>€<?= e(number_format((float) ($row['amount'] ?? 0), 2, '.', '')) ?></td>
                            <td><span class="badge <?= e($statusClass($status)) ?>"><?= e((string) ($row['status_label'] ?? $status)) ?></span></td>
                            <td>
                                <?php if (!empty($row['request_comment'])): ?>
                                    <small><?= e((string) $row['request_comment']) ?></small>
                                <?php else: ?>
                                    <small class="muted">-</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($requestId <= 0): ?>
                                    <form method="post" action="/?page=strikes_detail" class="stack strike-review-form">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="create_strike_review_request">
                                        <input type="hidden" name="target_user_id" value="<?= $selectedUserId ?>">
                                        <input type="hidden" name="week_start" value="<?= e((string) ($row['week_start'] ?? '')) ?>">
                                        <input type="hidden" name="event_date" value="<?= e((string) ($row['event_date'] ?? '')) ?>">
                                        <input type="hidden" name="reason" value="<?= e((string) ($row['reason'] ?? 'step_miss')) ?>">
                                        <input type="hidden" name="redirect_user_id" value="<?= $selectedUserId ?>">
                                        <input type="hidden" name="redirect_view" value="<?= e($dashboardView) ?>">
                                        <input type="text" name="request_comment" placeholder="<?= e(t('strikes.request_comment_placeholder')) ?>" required>
                                        <button type="submit" class="btn btn-ghost small"><?= e(t('strikes.request_review')) ?></button>
                                    </form>
                                <?php else: ?>
                                    <div class="stack">
                                        <?php if ($status === 'pending'): ?>
                                            <small class="muted"><?= e(t('strikes.request_sent')) ?></small>
                                        <?php endif; ?>
                                        <form method="post" action="/?page=strikes_detail" class="stack strike-review-form">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="resend_strike_review_request">
                                            <input type="hidden" name="request_id" value="<?= $requestId ?>">
                                            <input type="hidden" name="redirect_user_id" value="<?= $selectedUserId ?>">
                                            <input type="hidden" name="redirect_view" value="<?= e($dashboardView) ?>">
                                            <input type="text" name="request_comment" placeholder="<?= e(t('strikes.request_comment_placeholder')) ?>" required>
                                            <button type="submit" class="btn btn-ghost small"><?= e(t('strikes.resend_request')) ?></button>
                                        </form>
                                        <?php if ($canVote): ?>
                                            <form method="post" action="/?page=strikes_detail" class="inline-actions strike-review-vote">
                                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="vote_strike_review_request">
                                                <input type="hidden" name="request_id" value="<?= $requestId ?>">
                                                <input type="hidden" name="redirect_user_id" value="<?= $selectedUserId ?>">
                                                <input type="hidden" name="redirect_view" value="<?= e($dashboardView) ?>">
                                                <button type="submit" class="btn btn-primary small" name="decision" value="accept"><?= e(t('common.approve')) ?></button>
                                                <button type="submit" class="btn btn-ghost small" name="decision" value="reject"><?= e(t('common.reject')) ?></button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </article>
    <?php endif; ?>
</section>
