<?php

declare(strict_types=1);

putenv('DB_PATH=:memory:');
putenv('UPLOAD_DIR=' . dirname(__DIR__) . '/storage/qa_dashboard_panel_uploads');

require dirname(__DIR__) . '/app/bootstrap.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo 'PASS  ' . $message . PHP_EOL;
};

$now = now_iso();
db_execute(
    $pdo,
    'INSERT INTO users (username, password_hash, display_name, role, created_at, updated_at)
     VALUES (:username, :password_hash, :display_name, :role, :created_at, :updated_at)',
    [
        ':username' => 'panel_owner',
        ':password_hash' => password_hash('Panel123!', PASSWORD_DEFAULT),
        ':display_name' => 'Panel Owner',
        ':role' => 'user',
        ':created_at' => $now,
        ':updated_at' => $now,
    ]
);
$ownerId = (int) $pdo->lastInsertId();
db_execute(
    $pdo,
    'INSERT INTO users (username, password_hash, display_name, role, created_at, updated_at)
     VALUES (:username, :password_hash, :display_name, :role, :created_at, :updated_at)',
    [
        ':username' => 'panel_other',
        ':password_hash' => password_hash('Panel123!', PASSWORD_DEFAULT),
        ':display_name' => 'Panel Other',
        ':role' => 'user',
        ':created_at' => $now,
        ':updated_at' => $now,
    ]
);
$otherId = (int) $pdo->lastInsertId();

$initial = dashboard_panel_preferences($pdo, $ownerId);
$assert(count($initial) === count(dashboard_panel_preference_keys()), 'se exponen todas las claves estables');
$assert(!in_array(true, $initial, true), 'los paneles nuevos empiezan cerrados');

save_dashboard_panel_preference($pdo, $ownerId, 'dashboard.training-progress', true);
$saved = dashboard_panel_preferences($pdo, $ownerId);
$assert($saved['dashboard.training-progress'] === true, 'un panel abierto se guarda en la base de datos');
$assert($saved['dashboard.quests-panel'] === false, 'guardar un panel no abre los demás');

$other = dashboard_panel_preferences($pdo, $otherId);
$assert($other['dashboard.training-progress'] === false, 'el estado está aislado por usuario');

save_dashboard_panel_preference($pdo, $ownerId, 'dashboard.training-progress', false);
$closed = dashboard_panel_preferences($pdo, $ownerId);
$assert($closed['dashboard.training-progress'] === false, 'el mismo registro se actualiza al cerrar');
$rowCountResult = db_fetch_one(
    $pdo,
    'SELECT COUNT(*) AS preference_count FROM user_dashboard_panel_preferences
     WHERE user_id = :user_id AND panel_key = :panel_key',
    [':user_id' => $ownerId, ':panel_key' => 'dashboard.training-progress']
);
$rowCount = (int) ($rowCountResult['preference_count'] ?? 0);
$assert($rowCount === 1, 'la preferencia usa una única fila por usuario y panel');

$invalidRejected = false;
try {
    save_dashboard_panel_preference($pdo, $ownerId, 'dashboard.unknown', true);
} catch (InvalidArgumentException) {
    $invalidRejected = true;
}
$assert($invalidRejected, 'el servidor rechaza claves de panel desconocidas');

echo PHP_EOL . 'Dashboard panel preferences QA: all checks passed.' . PHP_EOL;
