<?php

declare(strict_types=1);

$allNotifications = is_array($notifications ?? null) ? array_values((array) $notifications) : [];
$notificationFilter = in_array((string) ($notificationFilter ?? 'all'), ['all', 'unread', 'action'], true)
    ? (string) ($notificationFilter ?? 'all')
    : 'all';
$unreadCount = 0;
$actionCount = 0;
foreach ($allNotifications as $notification) {
    if ((int) ($notification['is_read'] ?? 0) !== 1) {
        $unreadCount++;
    }
    if (notification_pending_action((string) ($notification['kind'] ?? 'info')) !== null) {
        $actionCount++;
    }
}
$totalCount = count($allNotifications);
$readCount = max(0, $totalCount - $unreadCount);
$notifications = array_values(array_filter($allNotifications, static function (array $notification) use ($notificationFilter): bool {
    if ($notificationFilter === 'unread') {
        return (int) ($notification['is_read'] ?? 0) !== 1;
    }
    if ($notificationFilter === 'action') {
        return notification_pending_action((string) ($notification['kind'] ?? 'info')) !== null;
    }

    return true;
}));
$trashIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v5M14 11v5"/></svg>';
$checkIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 13 4 4L19 7"/></svg>';
?>
<section class="screen notifications-page" data-notifications-page>
    <article class="panel notifications-panel">
        <div class="notifications-header">
            <div>
                <p class="eyebrow"><?= e(t('nav.notifications')) ?></p>
                <h1><?= e(t('notifications.title')) ?></h1>
                <p class="muted"><?= e(t('notifications.subtitle')) ?></p>
            </div>
            <div class="notifications-header-actions">
                <span class="notifications-unread-badge"><?= e(t('notifications.unread_count', ['count' => (string) $unreadCount])) ?></span>
                <a class="notifications-settings-link" href="/?page=settings&amp;view=integrations#telegram" aria-label="<?= e(t('notifications.delivery_settings')) ?>" title="<?= e(t('notifications.delivery_settings')) ?>"><?= activity_icon_svg('sliders') ?></a>
            </div>
        </div>

        <nav class="notifications-filter-tabs" aria-label="<?= e(t('notifications.title')) ?>">
            <a href="/?page=notifications"<?= $notificationFilter === 'all' ? ' aria-current="page"' : '' ?>><span><?= e(t('notifications.filter_all')) ?></span><strong><?= $totalCount ?></strong></a>
            <a href="/?page=notifications&amp;filter=unread"<?= $notificationFilter === 'unread' ? ' aria-current="page"' : '' ?>><span><?= e(t('notifications.filter_unread')) ?></span><strong><?= $unreadCount ?></strong></a>
            <a href="/?page=notifications&amp;filter=action"<?= $notificationFilter === 'action' ? ' aria-current="page"' : '' ?>><span><?= e(t('notifications.filter_action')) ?></span><strong><?= $actionCount ?></strong></a>
        </nav>

        <details class="notifications-tools">
            <summary><?= e(t('notifications.manage')) ?> <span aria-hidden="true">&rsaquo;</span></summary>
            <div class="notifications-toolbar">
            <form method="post" action="/?page=notifications" data-notification-form>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="mark_all_notifications_read">
                <input type="hidden" name="notification_filter" value="<?= e($notificationFilter) ?>">
                <button class="btn btn-ghost small" type="submit"<?= $unreadCount <= 0 ? ' disabled' : '' ?>><?= e(t('notifications.mark_all_read')) ?></button>
            </form>
            <form method="post" action="/?page=notifications" data-notification-form>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete_read_notifications">
                <input type="hidden" name="notification_filter" value="<?= e($notificationFilter) ?>">
                <button class="btn btn-ghost small" type="submit"<?= $readCount <= 0 ? ' disabled' : '' ?>><?= e(t('notifications.delete_read')) ?></button>
            </form>
            <form method="post" action="/?page=notifications" data-notification-form data-confirm="<?= e(t('notifications.delete_all_confirm')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete_all_notifications">
                <input type="hidden" name="notification_filter" value="<?= e($notificationFilter) ?>">
                <button class="btn btn-ghost small notification-delete-all-btn" type="submit"<?= $totalCount <= 0 ? ' disabled' : '' ?>><?= e(t('notifications.delete_all')) ?></button>
            </form>
            </div>
        </details>

        <?php if ($notifications === []): ?>
            <div class="notifications-empty-state">
                <span aria-hidden="true"><?= activity_icon_svg($totalCount === 0 ? 'bell' : 'check') ?></span>
                <strong><?= e(t($totalCount === 0 ? 'notifications.empty' : 'notifications.filtered_empty')) ?></strong>
                <p class="muted"><?= e(t('notifications.empty_hint')) ?></p>
                <div class="inline-actions">
                    <?php if ($notificationFilter !== 'all'): ?><a class="btn btn-ghost small" href="/?page=notifications"><?= e(t('notifications.show_all')) ?></a><?php endif; ?>
                    <a class="btn btn-primary small" href="/?page=settings&amp;view=integrations#telegram"><?= e(t('notifications.delivery_settings')) ?></a>
                </div>
            </div>
        <?php else: ?>
            <div class="notifications-list">
                <?php foreach ($notifications as $notification): ?>
                    <?php
                    $isRead = (int) ($notification['is_read'] ?? 0) === 1;
                    $createdAt = trim((string) ($notification['created_at'] ?? ''));
                    $kind = (string) ($notification['kind'] ?? 'info');
                    $kindToken = strtolower((string) (preg_split('/[_:.\-]+/', $kind)[0] ?? 'info'));
                    $categoryLabel = match ($kindToken) {
                        'duel' => t('nav.duels'),
                        'competition' => t('nav.competitions'),
                        'team', 'squad' => t('nav.team'),
                        'friend', 'social' => t('nav.friends'),
                        'achievement' => t('achievements.title'),
                        'workout', 'training' => t('nav.workouts'),
                        default => t('nav.notifications'),
                    };
                    // Only a few kinds carry a pending decision. The rest are news, and news
                    // gets no call to action - nothing on the card can then be mistaken for a
                    // button that accepts something.
                    $pendingCta = notification_pending_action($kind);
                    ?>
                    <article class="notification-card kind-<?= e($kind) ?><?= $isRead ? ' is-read' : ' is-unread' ?><?= $pendingCta !== null ? ' needs-action' : '' ?>">
                        <span class="notification-icon" aria-hidden="true"><?= activity_icon_svg(notification_icon($kind)) ?></span>
                        <a class="notification-main" href="/?page=notifications&amp;open_notification_id=<?= (int) ($notification['id'] ?? 0) ?>">
                            <span class="notification-meta-row"><em><?= e($categoryLabel) ?></em><em class="notification-state"><?= e(t($isRead ? 'notifications.state_read' : 'notifications.state_unread')) ?></em></span>
                            <strong><?= e((string) ($notification['title'] ?? '')) ?></strong>
                            <p><?= e((string) ($notification['message'] ?? '')) ?></p>
                            <?php if ($createdAt !== ''): ?>
                                <small class="notification-time muted"><?= e(human_time_ago($createdAt)) ?></small>
                            <?php endif; ?>
                        </a>
                        <div class="notification-actions">
                            <?php if ($pendingCta !== null): ?>
                                <?php // Sends you to the page where the decision is actually made. The
                                      // notification carries no duel/request id, so an accept button
                                      // here would have nothing to act on. ?>
                                <a class="btn btn-primary small notification-cta" href="/?page=notifications&amp;open_notification_id=<?= (int) ($notification['id'] ?? 0) ?>"><?= e(t($pendingCta)) ?></a>
                            <?php endif; ?>
                            <?php if (!$isRead): ?>
                                <form method="post" action="/?page=notifications" data-notification-form data-allow-multi-submit>
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="mark_notification_read">
                                    <input type="hidden" name="notification_id" value="<?= (int) ($notification['id'] ?? 0) ?>">
                                    <input type="hidden" name="notification_filter" value="<?= e($notificationFilter) ?>">
                                    <button class="notification-action-btn notification-action-read" type="submit" aria-label="<?= e(t('notifications.mark_read')) ?>" title="<?= e(t('notifications.mark_read')) ?>"><?= $checkIcon ?></button>
                                </form>
                            <?php endif; ?>
                            <form method="post" action="/?page=notifications" data-notification-form data-allow-multi-submit>
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete_notification">
                                <input type="hidden" name="notification_id" value="<?= (int) ($notification['id'] ?? 0) ?>">
                                <input type="hidden" name="notification_filter" value="<?= e($notificationFilter) ?>">
                                <button class="notification-action-btn notification-action-delete" type="submit" aria-label="<?= e(t('common.delete')) ?>" title="<?= e(t('common.delete')) ?>"><?= $trashIcon ?></button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>
</section>
