<?php

declare(strict_types=1);

$notifications = is_array($notifications ?? null) ? array_values((array) $notifications) : [];
$unreadCount = 0;
foreach ($notifications as $notification) {
    if ((int) ($notification['is_read'] ?? 0) !== 1) {
        $unreadCount++;
    }
}
$totalCount = count($notifications);
$readCount = max(0, $totalCount - $unreadCount);
$trashIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v5M14 11v5"/></svg>';
$checkIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 13 4 4L19 7"/></svg>';
?>
<section class="screen notifications-page" data-notifications-page>
    <article class="panel notifications-panel">
        <div class="notifications-header">
            <div>
                <p class="eyebrow"><?= e(t('nav.notifications')) ?></p>
                <h1><?= e(t('notifications.title')) ?></h1>
            </div>
            <span class="notifications-unread-badge"><?= e(t('notifications.unread_count', ['count' => (string) $unreadCount])) ?></span>
        </div>

        <div class="notifications-toolbar">
            <form method="post" action="/?page=notifications" data-notification-form>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="mark_all_notifications_read">
                <button class="btn btn-ghost small" type="submit"<?= $unreadCount <= 0 ? ' disabled' : '' ?>><?= e(t('notifications.mark_all_read')) ?></button>
            </form>
            <form method="post" action="/?page=notifications" data-notification-form>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete_read_notifications">
                <button class="btn btn-ghost small" type="submit"<?= $readCount <= 0 ? ' disabled' : '' ?>><?= e(t('notifications.delete_read')) ?></button>
            </form>
            <form method="post" action="/?page=notifications" data-notification-form data-confirm="<?= e(t('notifications.delete_all_confirm')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete_all_notifications">
                <button class="btn btn-ghost small notification-delete-all-btn" type="submit"<?= $totalCount <= 0 ? ' disabled' : '' ?>><?= e(t('notifications.delete_all')) ?></button>
            </form>
        </div>

        <?php if ($notifications === []): ?>
            <p class="notifications-empty muted"><?= e(t('notifications.empty')) ?></p>
        <?php else: ?>
            <div class="notifications-list">
                <?php foreach ($notifications as $notification): ?>
                    <?php
                    $isRead = (int) ($notification['is_read'] ?? 0) === 1;
                    $createdAt = trim((string) ($notification['created_at'] ?? ''));
                    $createdDate = $createdAt !== '' ? format_date_eu(substr($createdAt, 0, 10)) : '';
                    $createdTime = strlen($createdAt) >= 16 ? substr($createdAt, 11, 5) : '';
                    ?>
                    <article class="notification-card<?= $isRead ? ' is-read' : ' is-unread' ?>">
                        <a class="notification-main" href="/?page=notifications&amp;open_notification_id=<?= (int) ($notification['id'] ?? 0) ?>">
                            <strong><?= e((string) ($notification['title'] ?? '')) ?></strong>
                            <p><?= e((string) ($notification['message'] ?? '')) ?></p>
                            <?php if ($createdDate !== ''): ?>
                                <small class="notification-time muted"><?= e(trim($createdDate . ' ' . $createdTime)) ?></small>
                            <?php endif; ?>
                        </a>
                        <div class="notification-actions">
                            <?php if (!$isRead): ?>
                                <form method="post" action="/?page=notifications" data-notification-form>
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="mark_notification_read">
                                    <input type="hidden" name="notification_id" value="<?= (int) ($notification['id'] ?? 0) ?>">
                                    <button class="notification-action-btn notification-action-read" type="submit" aria-label="<?= e(t('notifications.mark_read')) ?>" title="<?= e(t('notifications.mark_read')) ?>"><?= $checkIcon ?></button>
                                </form>
                            <?php endif; ?>
                            <form method="post" action="/?page=notifications" data-notification-form>
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete_notification">
                                <input type="hidden" name="notification_id" value="<?= (int) ($notification['id'] ?? 0) ?>">
                                <button class="notification-action-btn notification-action-delete" type="submit" aria-label="<?= e(t('common.delete')) ?>" title="<?= e(t('common.delete')) ?>"><?= $trashIcon ?></button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>
</section>
