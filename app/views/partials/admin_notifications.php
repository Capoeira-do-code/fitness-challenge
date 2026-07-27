<?php

declare(strict_types=1);

$adminNotificationUsers = array_values(array_filter(
    (array) ($users ?? []),
    static fn(array $user): bool => (int) ($user['active'] ?? 1) === 1
));
$adminNotificationKinds = [
    'admin_update' => [
        'label' => t('admin.notifications_kind_update'),
        'hint' => t('admin.notifications_kind_update_hint'),
    ],
    'admin_announcement' => [
        'label' => t('admin.notifications_kind_announcement'),
        'hint' => t('admin.notifications_kind_announcement_hint'),
    ],
    'admin_maintenance' => [
        'label' => t('admin.notifications_kind_maintenance'),
        'hint' => t('admin.notifications_kind_maintenance_hint'),
    ],
];
?>
<article class="panel settings-panel active admin-notifications-page" data-spa-section="notifications">
    <section class="admin-notifications-overview">
        <div class="admin-notifications-overview-head">
            <span class="admin-notifications-mark" aria-hidden="true"><?= activity_icon_svg('bell') ?></span>
            <div>
                <p class="eyebrow"><?= e(t('admin.group_people')) ?></p>
                <h2><?= e(t('admin.notifications_title')) ?></h2>
                <p><?= e(t('admin.notifications_hint')) ?></p>
            </div>
            <?php $renderAdminBack('/?page=admin&group=people', t('admin.group_people')); ?>
        </div>

        <div class="admin-notifications-kpis" aria-label="<?= e(t('admin.notifications_summary')) ?>">
            <span><strong><?= count($adminNotificationUsers) ?></strong><small><?= e(t('admin.notifications_active_recipients')) ?></small></span>
            <span><strong><?= count($adminNotificationKinds) ?></strong><small><?= e(t('admin.notifications_message_types')) ?></small></span>
            <span><strong><?= e(t('admin.notifications_delivery_now')) ?></strong><small><?= e(t('admin.notifications_delivery')) ?></small></span>
        </div>
    </section>

    <form method="post" action="/?page=admin&amp;section=notifications" class="admin-notifications-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="send_admin_notification">

        <section class="admin-notifications-block">
            <header>
                <span aria-hidden="true"><?= activity_icon_svg('users') ?></span>
                <div><h3><?= e(t('admin.notifications_audience')) ?></h3><p><?= e(t('admin.notifications_audience_hint')) ?></p></div>
            </header>
            <label>
                <span><?= e(t('admin.notifications_recipient')) ?></span>
                <select name="notification_target" required>
                    <option value="all" selected><?= e(t('admin.notifications_all_users', ['count' => count($adminNotificationUsers)])) ?></option>
                    <optgroup label="<?= e(t('admin.notifications_one_user')) ?>">
                        <?php foreach ($adminNotificationUsers as $notificationUser): ?>
                            <option value="user:<?= (int) ($notificationUser['id'] ?? 0) ?>"><?= e((string) ($notificationUser['display_name'] ?? '')) ?> (@<?= e((string) ($notificationUser['username'] ?? '')) ?>)</option>
                        <?php endforeach; ?>
                    </optgroup>
                </select>
                <small><?= e(t('admin.notifications_active_only')) ?></small>
            </label>
        </section>

        <section class="admin-notifications-block">
            <header>
                <span aria-hidden="true"><?= activity_icon_svg('spark') ?></span>
                <div><h3><?= e(t('admin.notifications_content')) ?></h3><p><?= e(t('admin.notifications_content_hint')) ?></p></div>
            </header>
            <div class="admin-notifications-fields">
                <label>
                    <span><?= e(t('admin.notifications_type')) ?></span>
                    <select name="notification_kind" required>
                        <?php foreach ($adminNotificationKinds as $notificationKind => $notificationKindMeta): ?>
                            <option value="<?= e($notificationKind) ?>"><?= e((string) $notificationKindMeta['label']) ?> &mdash; <?= e((string) $notificationKindMeta['hint']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span><?= e(t('admin.notifications_subject')) ?></span>
                    <input type="text" name="notification_title" maxlength="120" autocomplete="off" placeholder="<?= e(t('admin.notifications_subject_placeholder')) ?>" required>
                    <small><?= e(t('admin.notifications_subject_limit')) ?></small>
                </label>
                <label class="admin-notifications-message">
                    <span><?= e(t('admin.notifications_message')) ?></span>
                    <textarea name="notification_message" maxlength="1200" rows="7" placeholder="<?= e(t('admin.notifications_message_placeholder')) ?>" required></textarea>
                    <small><?= e(t('admin.notifications_message_limit')) ?></small>
                </label>
            </div>
        </section>

        <aside class="admin-notifications-delivery-note">
            <span aria-hidden="true"><?= activity_icon_svg('bell') ?></span>
            <div><strong><?= e(t('admin.notifications_delivery_note_title')) ?></strong><p><?= e(t('admin.notifications_delivery_note_hint')) ?></p></div>
        </aside>

        <div class="admin-notifications-actions">
            <a class="btn btn-ghost" href="/?page=admin&amp;group=people"><?= e(t('common.cancel')) ?></a>
            <button class="btn btn-primary" type="submit" data-confirm-action="<?= e(t('admin.notifications_confirm')) ?>">
                <?= activity_icon_svg('bell') ?><span><?= e(t('admin.notifications_send')) ?></span>
            </button>
        </div>
    </form>
</article>
