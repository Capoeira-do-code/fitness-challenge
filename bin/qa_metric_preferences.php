<?php

declare(strict_types=1);

/**
 * Isolated regression for user-selected metrics, dynamic score/quests and
 * nutrition entries without media. It never opens the application database.
 */

$root = dirname(__DIR__);
putenv('DB_PATH=:memory:');
putenv('SEED_PASSWORD=Verify123!');
putenv('APP_DEFAULT_LOCALE=es');

require $root . '/app/bootstrap.php';

set_current_locale('es');
quests_ensure_schema($pdo);

$failures = [];
$check = static function (bool $ok, string $name, string $detail = '') use (&$failures): void {
    echo ($ok ? 'PASS' : 'FAIL') . '  ' . $name . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
    if (!$ok) {
        $failures[] = $name;
    }
};

$user = db_fetch_one($pdo, 'SELECT * FROM users WHERE active = 1 ORDER BY id LIMIT 1');
if ($user === null) {
    throw new RuntimeException('The QA database has no active user.');
}
$userId = (int) $user['id'];
$today = date('Y-m-d');
$now = now_iso();
$habit = db_fetch_one($pdo, 'SELECT * FROM habit_definitions WHERE active = 1 ORDER BY sort_order, id LIMIT 1');
if ($habit === null) {
    throw new RuntimeException('The QA database has no active habit.');
}
$habitKey = 'habit:' . (string) $habit['code'];

db_execute(
    $pdo,
    'UPDATE users
     SET step_goal = 1000, workout_target = 2, primary_goal_type = "steps",
         primary_goal_value = 1000, primary_goals_spec = :spec,
         calorie_burn_goal = 500, calorie_consumed_max = 2000, updated_at = :updated_at
     WHERE id = :id',
    [
        ':spec' => format_primary_goals_spec([
            ['type' => 'steps', 'value' => 1000],
            ['type' => 'km', 'value' => 2],
        ]),
        ':updated_at' => $now,
        ':id' => $userId,
    ]
);
$user = db_fetch_one($pdo, 'SELECT * FROM users WHERE id = :id', [':id' => $userId]) ?? $user;
save_user_metric_preferences($pdo, $user, [], $today);
save_user_metric_preferences($pdo, $user, ['steps', $habitKey, 'calories_consumed'], $today);

db_execute(
    $pdo,
    'INSERT INTO daily_logs (
        user_id, log_date, log_time, steps, workout_done, junk_food, extra_workout,
        distance_km, training_calories_burned, morning_walk, journaling,
        evening_chores, reading, created_at, updated_at
     ) VALUES (
        :user_id, :log_date, "08:00", 500, 0, 0, 0,
        1, 250, 0, 0, 0, 0, :created_at, :updated_at
     )',
    [':user_id' => $userId, ':log_date' => $today, ':created_at' => $now, ':updated_at' => $now]
);
$logId = (int) (db_fetch_one(
    $pdo,
    'SELECT id FROM daily_logs WHERE user_id = :user_id AND log_date = :log_date',
    [':user_id' => $userId, ':log_date' => $today]
)['id'] ?? 0);
db_execute(
    $pdo,
    'INSERT INTO daily_log_habits (log_id, habit_id, value, created_at, updated_at)
     VALUES (:log_id, :habit_id, 1, :created_at, :updated_at)',
    [
        ':log_id' => $logId,
        ':habit_id' => (int) $habit['id'],
        ':created_at' => $now,
        ':updated_at' => $now,
    ]
);

$meal = save_photo_entry(
    $pdo,
    $config,
    $userId,
    $today,
    'breakfast',
    'Desayuno registrado sin fotografía',
    ['error' => UPLOAD_ERR_NO_FILE],
    ['calories' => 1500, 'protein_g' => 90]
);
$check((int) ($meal['has_photo'] ?? 1) === 0 && (string) ($meal['file_path'] ?? 'x') === '', 'comida sin fotografía conserva un registro sin medio');

$metrics = compute_challenge_metrics($pdo, [$user], $today, $today, $today);
$metric = (array) ($metrics[$userId] ?? []);
$snapshot = metric_snapshot_for_view($metric, $today);
$componentKeys = array_keys((array) ($snapshot['score_components_detailed'] ?? []));
sort($componentKeys);
$expectedKeys = ['calories_consumed', $habitKey, 'steps'];
sort($expectedKeys);
$check($componentKeys === $expectedKeys, 'solo las métricas activas forman el score', implode(', ', $componentKeys));
$check(abs((float) ($snapshot['score'] ?? 0) - 83.3) < 0.11, 'media aritmética con el mismo peso', (string) ($snapshot['score'] ?? 'null'));

$calendar = fetch_meal_calendar($pdo, $today, $userId, 'day');
$calendarDay = (array) ($calendar[$today] ?? []);
$check(
    (int) ($calendarDay['meal_count'] ?? 0) === 1 && (int) ($calendarDay['photo_count'] ?? 0) === 0,
    'calendario diferencia comidas y fotografías'
);
$recentMeals = fetch_recent_meals($pdo, 10, $userId);
$gallery = fetch_gallery_photos($pdo, 10, 0, $userId, $userId, false);
$check(count($recentMeals) === 1 && $gallery === [], 'nutrición incluye la comida y Galería la excluye');

$questCatalogue = quests_catalogue_for_user($pdo, $user);
$check(
    isset($questCatalogue['daily_steps'], $questCatalogue['daily_habit:' . (string) $habit['code']])
        && !isset($questCatalogue['weekly_workouts']),
    'misiones derivadas únicamente de métricas activas'
);
$firstQuestBoard = quests_for_user($pdo, $user);
$stepQuest = null;
foreach ($firstQuestBoard as $quest) {
    if (($quest['key'] ?? '') === 'daily_steps') {
        $stepQuest = $quest;
        break;
    }
}
$check((float) ($stepQuest['target'] ?? 0) === 1000.0, 'misión guarda el objetivo del periodo');

db_execute($pdo, 'UPDATE users SET step_goal = 2000, primary_goal_value = 2000 WHERE id = :id', [':id' => $userId]);
$updatedUser = db_fetch_one($pdo, 'SELECT * FROM users WHERE id = :id', [':id' => $userId]) ?? $user;
$secondQuestBoard = quests_for_user($pdo, $updatedUser);
$updatedStepQuest = null;
foreach ($secondQuestBoard as $quest) {
    if (($quest['key'] ?? '') === 'daily_steps') {
        $updatedStepQuest = $quest;
        break;
    }
}
$check((float) ($updatedStepQuest['target'] ?? 0) === 1000.0, 'cambiar una meta no altera la misión iniciada');

save_user_metric_preferences($pdo, $updatedUser, [], $today);
$emptySnapshot = metric_snapshot_for_view($metric, $today);
$check(array_key_exists('score', $emptySnapshot) && $emptySnapshot['score'] === null, 'sin métricas activas el score es null');

$invalidTargetRejected = false;
db_execute($pdo, 'UPDATE users SET step_goal = 0 WHERE id = :id', [':id' => $userId]);
$invalidUser = db_fetch_one($pdo, 'SELECT * FROM users WHERE id = :id', [':id' => $userId]) ?? $updatedUser;
try {
    save_user_metric_preferences($pdo, $invalidUser, ['steps'], $today);
} catch (InvalidArgumentException) {
    $invalidTargetRejected = true;
}
$check($invalidTargetRejected, 'una métrica con objetivo inválido no se puede activar');

if ($failures !== []) {
    fwrite(STDERR, PHP_EOL . count($failures) . ' checks failed: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo PHP_EOL . 'Metric preferences, quests and nutrition QA: all checks passed.' . PHP_EOL;
