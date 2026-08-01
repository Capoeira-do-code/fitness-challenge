#!/usr/bin/env php
<?php

declare(strict_types=1);

putenv('DB_PATH=:memory:');
putenv('SEED_PASSWORD=qa-admin-notifications-password');
putenv('REQUEST_SCHEDULERS_ENABLED=0');

require dirname(__DIR__) . '/app/bootstrap.php';

$failures = [];
$check = static function (bool $condition, string $label) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . '  ' . $label . PHP_EOL;
    if (!$condition) {
        $failures[] = $label;
    }
};

$createQaUser = static function (string $username, string $displayName, bool $active) use ($pdo): int {
    create_user($pdo, [
        'username' => $username,
        'password' => 'qa-user-password',
        'display_name' => $displayName,
        'role' => 'user',
        'step_goal' => 10000,
        'step_days_mask' => '1111111',
        'workout_target' => 3,
        'workout_days_mask' => '0000000',
        'workout_strict' => 0,
        'ideal_weight' => null,
        'active' => $active ? 1 : 0,
    ]);

    return (int) $pdo->lastInsertId();
};

$admin = db_fetch_one(
    $pdo,
    'SELECT id FROM users WHERE role = "admin" AND active = 1 ORDER BY id ASC LIMIT 1'
);
$adminId = (int) ($admin['id'] ?? 0);
$firstUserId = $createQaUser('qa_notify_active_one', 'QA Active One', true);
$secondUserId = $createQaUser('qa_notify_active_two', 'QA Active Two', true);
$inactiveUserId = $createQaUser('qa_notify_inactive', 'QA Inactive', false);
$activeCount = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE active = 1')->fetchColumn();

$check($adminId > 0, 'hay un administrador activo que puede publicar avisos');

$globalResult = send_admin_notification(
    $pdo,
    $adminId,
    'all',
    'admin_announcement',
    'Novedad global de prueba',
    'Este aviso debe aparecer en la sección de notificaciones.'
);

$campaignKey = (string) ($globalResult['campaign_key'] ?? '');
$globalRows = db_fetch_all(
    $pdo,
    'SELECT * FROM user_notifications WHERE unique_key = :campaign_key ORDER BY user_id ASC',
    [':campaign_key' => $campaignKey]
);
$globalRecipientIds = array_map(
    static fn(array $row): int => (int) ($row['user_id'] ?? 0),
    $globalRows
);

$check((int) ($globalResult['recipient_count'] ?? 0) === $activeCount, 'el envío global alcanza todas las cuentas activas');
$check(count($globalRows) === $activeCount, 'cada destinatario recibe exactamente una notificación');
$check(in_array($firstUserId, $globalRecipientIds, true), 'el primer usuario activo recibe el aviso');
$check(in_array($secondUserId, $globalRecipientIds, true), 'el segundo usuario activo recibe el aviso');
$check(!in_array($inactiveUserId, $globalRecipientIds, true), 'las cuentas inactivas quedan excluidas');
$check(
    count(array_filter($globalRows, static fn(array $row): bool => (int) ($row['is_read'] ?? 1) === 0)) === $activeCount,
    'los avisos globales aparecen inicialmente como no leídos'
);

$firstUserNotifications = user_notifications($pdo, $firstUserId, 20, true);
$firstGlobalNotification = array_values(array_filter(
    $firstUserNotifications,
    static fn(array $row): bool => (string) ($row['unique_key'] ?? '') === $campaignKey
))[0] ?? null;

$check(
    is_array($firstGlobalNotification)
        && (string) ($firstGlobalNotification['title'] ?? '') === 'Novedad global de prueba'
        && (string) ($firstGlobalNotification['message'] ?? '') === 'Este aviso debe aparecer en la sección de notificaciones.',
    'el título y el mensaje se muestran en la bandeja del usuario'
);
$check(user_unread_notifications_count($pdo, $firstUserId) >= 1, 'la campana contabiliza el aviso global');

$notificationId = (int) ($firstGlobalNotification['id'] ?? 0);
$destination = open_user_notification($pdo, $notificationId, $firstUserId);
$openedNotification = fetch_user_notification($pdo, $notificationId, $firstUserId);
$check(
    $destination === '/?page=notifications&notification_id=' . $notificationId,
    'abrir un anuncio entra en su lector dentro de Notificaciones'
);
$check((int) ($openedNotification['is_read'] ?? 0) === 1, 'abrir el anuncio lo marca como leído');

$singleResult = send_admin_notification(
    $pdo,
    $adminId,
    'user:' . $secondUserId,
    'admin_update',
    'Actualización individual',
    'Este mensaje sólo corresponde al segundo usuario.'
);
$singleCampaign = (string) ($singleResult['campaign_key'] ?? '');
$singleRecipients = db_fetch_all(
    $pdo,
    'SELECT user_id FROM user_notifications WHERE unique_key = :campaign_key',
    [':campaign_key' => $singleCampaign]
);
$check(
    (int) ($singleResult['recipient_count'] ?? 0) === 1
        && count($singleRecipients) === 1
        && (int) ($singleRecipients[0]['user_id'] ?? 0) === $secondUserId,
    'el modo individual entrega el mensaje sólo al usuario elegido'
);

$audit = db_fetch_one(
    $pdo,
    'SELECT * FROM audit_logs
     WHERE action = "admin_notification_sent" AND entity_id = :campaign_key
     ORDER BY id DESC LIMIT 1',
    [':campaign_key' => $campaignKey]
);
$check(is_array($audit), 'el envío global queda registrado en la auditoría administrativa');

$invalidRejected = false;
try {
    send_admin_notification($pdo, $adminId, 'all', 'unsafe_kind', 'Título', 'Mensaje');
} catch (InvalidArgumentException) {
    $invalidRejected = true;
}
$check($invalidRejected, 'se rechazan tipos de notificación no permitidos');

if ($failures !== []) {
    fwrite(STDERR, PHP_EOL . count($failures) . ' comprobación(es) de notificaciones fallaron.' . PHP_EOL);
    exit(1);
}

echo PHP_EOL . 'Admin notifications QA passed.' . PHP_EOL;
