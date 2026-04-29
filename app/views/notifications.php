<?php

declare(strict_types=1);

$notifications = is_array($notifications ?? null) ? array_values((array) $notifications) : [];
$unreadCount = 0;
foreach ($notifications as $notification) {
    if ((int) ($notification['is_read'] ?? 0) !== 1) {
        $unreadCount++;
    }
}
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
                        <div class="notification-main">
                            <strong><?= e((string) ($notification['title'] ?? '')) ?></strong>
                            <p><?= e((string) ($notification['message'] ?? '')) ?></p>
                            <?php if ($createdDate !== ''): ?>
                                <small class="muted"><?= e(trim($createdDate . ' ' . $createdTime)) ?></small>
                            <?php endif; ?>
                        </div>
                        <?php if (!$isRead): ?>
                            <form method="post" action="/?page=notifications" class="notification-actions">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="mark_notification_read">
                                <input type="hidden" name="notification_id" value="<?= (int) ($notification['id'] ?? 0) ?>">
                                <button class="btn btn-ghost small" type="submit"><?= e(t('notifications.mark_read')) ?></button>
                            </form>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>
</section>
