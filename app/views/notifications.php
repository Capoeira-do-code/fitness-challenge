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
?>
<section class="screen stack-lg">
    <div class="hero-panel">
        <div>
            <p class="eyebrow"><?= e(t('nav.notifications')) ?></p>
            <h1><?= e(t('notifications.title')) ?></h1>
            <p class="muted"><?= e(t('notifications.subtitle')) ?></p>
        </div>
    </div>

    <article class="panel">
        <div class="panel-head">
            <div>
                <h2><?= e(t('notifications.title')) ?></h2>
                <p class="muted"><?= e(t('notifications.unread_count', ['count' => (string) $unreadCount])) ?></p>
            </div>
            <span class="badge"><?= (int) $unreadCount ?></span>
        </div>
        <div class="notifications-toolbar">
            <form method="post" action="/?page=notifications">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="mark_all_notifications_read">
                <button class="btn btn-ghost small" type="submit"<?= $unreadCount <= 0 ? ' disabled' : '' ?>><?= e(t('notifications.mark_all_read')) ?></button>
            </form>
            <form method="post" action="/?page=notifications">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete_read_notifications">
                <button class="btn btn-ghost small" type="submit"<?= $readCount <= 0 ? ' disabled' : '' ?>><?= e(t('notifications.delete_read')) ?></button>
            </form>
            <form method="post" action="/?page=notifications">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete_all_notifications">
                <button class="btn btn-ghost small notification-delete-all-btn" type="submit" onclick="return window.confirm('<?= e(t('notifications.delete_all_confirm')) ?>');"<?= $totalCount <= 0 ? ' disabled' : '' ?>><?= e(t('notifications.delete_all')) ?></button>
            </form>
        </div>

        <?php if ($notifications === []): ?>
            <p class="muted"><?= e(t('notifications.empty')) ?></p>
        <?php else: ?>
            <div class="card-list notifications-list">
                <?php foreach ($notifications as $notification): ?>
                    <?php
                    $isRead = (int) ($notification['is_read'] ?? 0) === 1;
                    $createdAt = trim((string) ($notification['created_at'] ?? ''));
                    $createdDate = $createdAt !== '' ? format_date_eu(substr($createdAt, 0, 10)) : '';
                    $createdTime = strlen($createdAt) >= 16 ? substr($createdAt, 11, 5) : '';
                    ?>
                    <article class="mini-card notification-card<?= $isRead ? ' is-read' : '' ?>">
                        <a class="notification-main" href="/?page=notifications&amp;open_notification_id=<?= (int) ($notification['id'] ?? 0) ?>">
                            <strong><?= e((string) ($notification['title'] ?? '')) ?></strong>
                            <p><?= e((string) ($notification['message'] ?? '')) ?></p>
                            <?php if ($createdDate !== ''): ?>
                                <small class="muted"><?= e(trim($createdDate . ' ' . $createdTime)) ?></small>
                            <?php endif; ?>
                        </a>
                        <div class="notification-actions">
                            <?php if (!$isRead): ?>
                                <form method="post" action="/?page=notifications">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="mark_notification_read">
                                    <input type="hidden" name="notification_id" value="<?= (int) ($notification['id'] ?? 0) ?>">
                                    <button class="btn btn-ghost small" type="submit"><?= e(t('notifications.mark_read')) ?></button>
                                </form>
                            <?php endif; ?>
                            <form method="post" action="/?page=notifications">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete_notification">
                                <input type="hidden" name="notification_id" value="<?= (int) ($notification['id'] ?? 0) ?>">
                                <button class="btn btn-ghost small" type="submit"><?= e(t('common.delete')) ?></button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>
</section>
