<?php

declare(strict_types=1);

/**
 * Isolated domain regression for the ranked training hub.
 *
 * Usage:
 *   php bin/qa_workouts.php
 *   php bin/qa_workouts.php --keep
 *
 * It never opens storage/fitness.sqlite. With --keep, the populated QA database
 * remains at storage/qa_workouts.sqlite for browser verification.
 */

$root = dirname(__DIR__);
$keepDatabase = in_array('--keep', $argv, true);
$persistedDbPath = $root . '/storage/qa_workouts.sqlite';
$dbPath = $keepDatabase ? $persistedDbPath : ':memory:';
$productionPath = realpath($root . '/storage/fitness.sqlite') ?: $root . '/storage/fitness.sqlite';
if (str_replace('\\', '/', strtolower($dbPath)) === str_replace('\\', '/', strtolower($productionPath))) {
    throw new RuntimeException('Refusing to run against the application database.');
}
if ($keepDatabase) {
    foreach ([$persistedDbPath, $persistedDbPath . '-wal', $persistedDbPath . '-shm'] as $candidate) {
        if (is_file($candidate)) {
            unlink($candidate);
        }
    }
}

putenv('DB_PATH=' . $dbPath);
putenv('SEED_PASSWORD=Verify123!');
putenv('APP_DEFAULT_LOCALE=es');

require $root . '/app/bootstrap.php';

set_current_locale('es');
workouts_ensure_schema($pdo);

$failures = [];
$check = static function (bool $ok, string $name, string $detail = '') use (&$failures): void {
    echo ($ok ? 'PASS' : 'FAIL') . '  ' . $name . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
    if (!$ok) {
        $failures[] = $name;
    }
};

$users = db_fetch_all($pdo, 'SELECT id, username FROM users ORDER BY id');
$ids = [];
foreach ($users as $user) {
    $ids[(string) $user['username']] = (int) $user['id'];
}
$me = $ids['roberto'] ?? 0;
$other = $ids['catalina'] ?? 0;
$check($me > 0 && $other > 0, 'usuarios fixture');

// Browser regressions reuse the kept database, so its persisted cover must be a
// real disposable asset instead of a deliberately broken URL that pollutes the
// console on every routine screen.
if ($keepDatabase && $me > 0) {
    $qaCoverDirectory = rtrim((string) $config['upload_dir'], '/\\') . '/workouts/routines/user_' . $me;
    if (!is_dir($qaCoverDirectory)) {
        mkdir($qaCoverDirectory, 0775, true);
    }
    file_put_contents(
        $qaCoverDirectory . '/qa-cover.png',
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFElEQVR42mP8z8AARAwMjDAGAAANHQEDasKb6QAAAABJRU5ErkJggg==', true)
    );
}

$sourceCatalog = wk_builtin_exercise_catalog();
$catalogSlugs = array_map(static fn(array $exercise): string => (string) ($exercise['slug'] ?? ''), $sourceCatalog);
$catalogComplete = count($sourceCatalog) >= 120 && count(array_unique($catalogSlugs)) === count($sourceCatalog);
foreach ($sourceCatalog as $catalogExercise) {
    foreach (['en', 'es'] as $locale) {
        $guide = (array) ($catalogExercise['guide'][$locale] ?? []);
        $catalogComplete = $catalogComplete
            && trim((string) ($catalogExercise['names'][$locale] ?? '')) !== ''
            && trim((string) ($guide['summary'] ?? '')) !== ''
            && count((array) ($guide['steps'] ?? [])) === 3
            && count((array) ($guide['tips'] ?? [])) >= 1
            && count((array) ($guide['mistakes'] ?? [])) >= 1;
    }
}
$check($catalogComplete, 'guías completas para todo el catálogo', count($sourceCatalog) . ' ejercicios · EN/ES');
$missingPresetSlugs = [];
foreach (wk_builtin_plan_presets() as $preset) {
    foreach ((array) ($preset['routines'] ?? []) as $presetRoutine) {
        foreach ((array) ($presetRoutine['exercises'] ?? []) as $exerciseSpec) {
            $slug = (string) ($exerciseSpec[0] ?? '');
            if (!in_array($slug, $catalogSlugs, true)) {
                $missingPresetSlugs[] = $slug;
            }
        }
    }
}
$check($missingPresetSlugs === [], 'plantillas referencian ejercicios válidos', implode(', ', $missingPresetSlugs));

$catalogCount = (int) (db_fetch_one($pdo, 'SELECT COUNT(*) AS c FROM exercise_definitions WHERE is_system = 1')['c'] ?? 0);
$check($catalogCount >= 120, 'catálogo integrado', $catalogCount . ' ejercicios');
$catalogAccentRows = db_fetch_all($pdo, 'SELECT accent_color, visual_mark FROM exercise_definitions WHERE is_system = 1');
$catalogAccents = array_values(array_unique(array_map(static fn(array $row): string => (string) ($row['accent_color'] ?? ''), $catalogAccentRows)));
$catalogAccentsSafe = count($catalogAccents) >= 8;
$catalogMarks = [];
foreach ($catalogAccents as $catalogAccent) {
    $catalogAccentsSafe = $catalogAccentsSafe && preg_match('/^#[0-9a-f]{6}$/', $catalogAccent) === 1;
}
foreach ($catalogAccentRows as $catalogAccentRow) {
    $catalogMark = (string) ($catalogAccentRow['visual_mark'] ?? '');
    $catalogAccentsSafe = $catalogAccentsSafe && $catalogMark !== '' && wk_normalize_exercise_mark($catalogMark) === $catalogMark;
    $catalogMarks[] = $catalogMark;
}
$catalogAccentsSafe = $catalogAccentsSafe && count(array_unique($catalogMarks)) >= 8;
$check($catalogAccentsSafe, 'catalogo usa acentos seguros por grupo muscular', implode(', ', $catalogAccents));
$rankTierCount = (int) (db_fetch_one($pdo, 'SELECT COUNT(*) AS c FROM workout_rank_tiers')['c'] ?? 0);
$check($rankTierCount === 8, 'tiers ranked configurables sembrados', (string) $rankTierCount);
$rankAchievementCount = (int) (db_fetch_one(
    $pdo,
    'SELECT COUNT(*) AS c FROM achievements WHERE code IN ("rank_bronze", "rank_silver", "rank_gold", "rank_platinum", "rank_diamond", "rank_elite")'
)['c'] ?? 0);
$rankAchievementRuleCount = (int) (db_fetch_one(
    $pdo,
    'SELECT COUNT(*) AS c FROM achievement_rules ar
     JOIN achievements a ON a.id = ar.achievement_id
     WHERE a.code IN ("rank_bronze", "rank_silver", "rank_gold", "rank_platinum", "rank_diamond", "rank_elite")
       AND ar.metric_key = "strength_rank" AND ar.active = 1'
)['c'] ?? 0);
$check($rankAchievementCount === 6 && $rankAchievementRuleCount === 6, 'logros ranked conectados a reglas', $rankAchievementCount . ' logros / ' . $rankAchievementRuleCount . ' reglas');
$check(normalize_achievement_rule_metric('strength_rank') === 'strength_rank', 'métrica strength_rank aceptada');
$bench = db_fetch_one($pdo, 'SELECT * FROM exercise_definitions WHERE slug = :slug', [':slug' => 'bench_press']);
$benchId = (int) ($bench['id'] ?? 0);
$spanishGuide = $bench !== null ? wk_exercise_content($bench, 'es') : [];
$check(
    ($spanishGuide['name'] ?? '') === 'Press banca con barra' && count($spanishGuide['steps'] ?? []) === 3,
    'guía pre-cargada bilingüe'
);
$chestCatalogueCount = count(wk_exercise_library($pdo, $me, ['muscle' => 'chest']));
$check($chestCatalogueCount >= 4, 'filtro por parte corporal', 'pecho=' . $chestCatalogueCount);

$routineId = wk_routine_create(
    $pdo,
    $me,
    'QA Lunes Miércoles',
    'dumbbell',
    'Flujo aislado',
    wk_days_mask(['mon', 'wed']),
    '#8b5cf6',
    'workouts/routines/user_' . $me . '/qa-cover.png',
    'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    'photo',
    'top'
);
$check($routineId > 0, 'crear rutina');
$storedRoutine = wk_routine_get($pdo, $routineId, $me);
$check(
    ($storedRoutine['recommended_days_mask'] ?? '') === '1010000',
    'persistir días seleccionados',
    (string) ($storedRoutine['recommended_days_mask'] ?? '')
);
$check(
    ($storedRoutine['icon'] ?? '') === 'dumbbell' && ($storedRoutine['accent_color'] ?? '') === '#8b5cf6',
    'persistir identidad visual de rutina',
    (string) ($storedRoutine['icon'] ?? '') . ' · ' . (string) ($storedRoutine['accent_color'] ?? '')
);
$check(
    ($storedRoutine['image_path'] ?? '') === 'workouts/routines/user_' . $me . '/qa-cover.png'
        && ($storedRoutine['video_url'] ?? '') === 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'
        && ($storedRoutine['cover_mode'] ?? '') === 'photo'
        && ($storedRoutine['image_position'] ?? '') === 'top',
    'persistir portada multimedia y encuadre de rutina'
);
$invalidRoutineVideoRejected = false;
try {
    wk_routine_update($pdo, $routineId, $me, ['video_url' => 'javascript:alert(1)']);
} catch (InvalidArgumentException) {
    $invalidRoutineVideoRejected = true;
}
$check($invalidRoutineVideoRejected, 'rechazar vídeo inseguro de rutina');
$check(
    !wk_routine_update($pdo, $routineId, $other, ['cover_mode' => 'simple', 'image_path' => 'other.png']),
    'multimedia de rutina respeta propiedad'
);
$firstAdd = wk_routine_add_exercise($pdo, $routineId, $benchId, ['target_sets' => 3, 'target_reps' => 5]);
$secondAdd = wk_routine_add_exercise($pdo, $routineId, $benchId, ['target_sets' => 4, 'target_reps' => 8]);
$routineExerciseCount = (int) (db_fetch_one(
    $pdo,
    'SELECT COUNT(*) AS c FROM routine_exercises WHERE routine_id = :routine',
    [':routine' => $routineId]
)['c'] ?? 0);
$check($firstAdd === $secondAdd && $routineExerciseCount === 1, 'evitar ejercicios duplicados');
$targetUpdated = wk_routine_exercise_update($pdo, $firstAdd, $routineId, $me, [
    'target_sets' => 3,
    'target_reps' => 6,
    'target_weight' => 72.5,
    'rest_seconds' => 90,
    'unit' => 'kg',
    'notes' => 'Pausa breve en el pecho',
]);
$updatedTarget = wk_routine_exercise_get($pdo, $firstAdd, $routineId, $me);
$check(
    $targetUpdated
        && (int) ($updatedTarget['target_sets'] ?? 0) === 3
        && (int) ($updatedTarget['target_reps'] ?? 0) === 6
        && abs((float) ($updatedTarget['target_weight'] ?? 0) - 72.5) < 0.01
        && (int) ($updatedTarget['rest_seconds'] ?? 0) === 90
        && (string) ($updatedTarget['notes'] ?? '') === 'Pausa breve en el pecho',
    'personalizar objetivo de ejercicio en rutina'
);
$check(
    wk_routine_exercise_get($pdo, $firstAdd, $routineId, $other) === null
        && !wk_routine_exercise_update($pdo, $firstAdd, $routineId, $other, ['target_sets' => 20]),
    'objetivo de rutina respeta propiedad'
);
$runningForOrder = db_fetch_one($pdo, 'SELECT id FROM exercise_definitions WHERE slug = :slug', [':slug' => 'running']);
$plankForOrder = db_fetch_one($pdo, 'SELECT id FROM exercise_definitions WHERE slug = :slug', [':slug' => 'plank']);
$organizeRoutineId = wk_routine_create($pdo, $me, 'QA Orden', 'dumbbell', '', '0000000', '#14b8a6');
$otherRoutineId = wk_routine_create($pdo, $other, 'QA Orden ajeno', 'dumbbell', '', '0000000', '#14b8a6');
$organizeBenchRow = wk_routine_add_exercise($pdo, $organizeRoutineId, $benchId);
$organizeRunRow = wk_routine_add_exercise($pdo, $organizeRoutineId, (int) ($runningForOrder['id'] ?? 0));
$organizePlankRow = wk_routine_add_exercise($pdo, $organizeRoutineId, (int) ($plankForOrder['id'] ?? 0));
$otherRoutineRow = wk_routine_add_exercise($pdo, $otherRoutineId, $benchId);
$craftedOrderSaved = wk_routine_exercises_reorder(
    $pdo,
    $organizeRoutineId,
    $me,
    [$organizePlankRow, $otherRoutineRow, $organizePlankRow]
);
$craftedOrder = array_map(
    static fn(array $exercise): int => (int) $exercise['id'],
    wk_routine_exercises($pdo, $organizeRoutineId)
);
$check(
    $craftedOrderSaved && $craftedOrder === [$organizePlankRow, $organizeBenchRow, $organizeRunRow],
    'reorden seguro conserva filas omitidas e ignora IDs ajenos',
    implode(',', $craftedOrder)
);
$orderedExerciseRows = [$organizeRunRow, $organizeBenchRow, $organizePlankRow];
$orderSaved = wk_routine_exercises_reorder($pdo, $organizeRoutineId, $me, $orderedExerciseRows);
$storedExerciseOrder = array_map(
    static fn(array $exercise): int => (int) $exercise['id'],
    wk_routine_exercises($pdo, $organizeRoutineId)
);
$check($orderSaved && $storedExerciseOrder === $orderedExerciseRows, 'persistir orden de ejercicios de rutina');
$check(
    !wk_routine_exercises_reorder($pdo, $organizeRoutineId, $other, array_reverse($orderedExerciseRows))
        && !wk_routine_exercises_reorder($pdo, $otherRoutineId, $me, [$otherRoutineRow]),
    'reorden de ejercicios respeta propiedad'
);
wk_routine_delete($pdo, $organizeRoutineId, $me);
wk_routine_delete($pdo, $otherRoutineId, $other);
$routinesByDay = wk_routines_by_day($pdo, $me);
$check(
    count($routinesByDay['mon']) === 1 && count($routinesByDay['wed']) === 1 && count($routinesByDay['tue']) === 0,
    'agenda semanal'
);
$identityUpdated = wk_routine_update($pdo, $routineId, $me, ['icon' => 'cycle', 'accent_color' => '#12A4D9', 'image_position' => 'bottom']);
$updatedRoutine = wk_routine_get($pdo, $routineId, $me);
$check(
    $identityUpdated
        && ($updatedRoutine['icon'] ?? '') === 'cycle'
        && ($updatedRoutine['accent_color'] ?? '') === '#12a4d9'
        && ($updatedRoutine['image_position'] ?? '') === 'bottom',
    'editar identidad y encuadre de rutina'
);
$duplicateRoutineId = wk_routine_duplicate($pdo, $routineId, $me);
$duplicateRoutine = wk_routine_get($pdo, $duplicateRoutineId, $me);
$check(
    $duplicateRoutineId > 0
        && ($duplicateRoutine['icon'] ?? '') === 'cycle'
        && ($duplicateRoutine['accent_color'] ?? '') === '#12a4d9'
        && ($duplicateRoutine['image_path'] ?? '') === ($updatedRoutine['image_path'] ?? '')
        && ($duplicateRoutine['video_url'] ?? '') === ($updatedRoutine['video_url'] ?? '')
        && ($duplicateRoutine['cover_mode'] ?? '') === ($updatedRoutine['cover_mode'] ?? '')
        && ($duplicateRoutine['image_position'] ?? '') === ($updatedRoutine['image_position'] ?? '')
        && count(wk_routine_exercises($pdo, $duplicateRoutineId)) === 1,
    'duplicado conserva identidad, multimedia y ejercicios'
);
wk_routine_delete($pdo, $duplicateRoutineId, $me);
wk_routine_update($pdo, $routineId, $me, ['icon' => 'javascript:alert(1)', 'accent_color' => 'red;position:fixed', 'cover_mode' => 'iframe', 'image_position' => 'url(javascript:1)']);
$normalizedRoutine = wk_routine_get($pdo, $routineId, $me);
$check(
    ($normalizedRoutine['icon'] ?? '') === 'dumbbell'
        && ($normalizedRoutine['accent_color'] ?? '') === '#14b8a6'
        && ($normalizedRoutine['cover_mode'] ?? '') === 'auto'
        && ($normalizedRoutine['image_position'] ?? '') === 'center',
    'normalizar identidad visual no permitida'
);
$check(
    wk_normalize_routine_color('#ABCDEF') === '#abcdef'
        && wk_normalize_routine_color('var(--danger)') === '#14b8a6',
    'color libre acepta solo hexadecimal seguro'
);

$planRoutines = wk_create_plan_from_preset($pdo, $me, 'upper_lower');
$planExerciseCount = 0;
foreach ($planRoutines as $planRoutineId) {
    $planExerciseCount += count(wk_routine_exercises($pdo, $planRoutineId));
}
$check(
    count($planRoutines) === 2 && $planExerciseCount === 11,
    'plantilla editable Upper/Lower',
    count($planRoutines) . ' rutinas, ' . $planExerciseCount . ' ejercicios'
);
$planIdentityIsValid = true;
foreach ($planRoutines as $planRoutineId) {
    $planRoutine = wk_routine_get($pdo, $planRoutineId, $me);
    $planIdentityIsValid = $planIdentityIsValid
        && array_key_exists((string) ($planRoutine['icon'] ?? ''), wk_routine_icon_options())
        && in_array((string) ($planRoutine['accent_color'] ?? ''), wk_routine_color_options(), true);
}
$check($planIdentityIsValid, 'plantillas usan identidades visuales seguras');

$sessionId = wk_session_start($pdo, $me, $routineId);
$sessionWithRoutineMedia = wk_session_get($pdo, $sessionId, $me);
$check(
    ($sessionWithRoutineMedia['routine_image_path'] ?? '') === 'workouts/routines/user_' . $me . '/qa-cover.png'
        && ($sessionWithRoutineMedia['routine_video_url'] ?? '') === 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'
        && ($sessionWithRoutineMedia['routine_image_position'] ?? '') === 'center',
    'portada de rutina disponible durante la sesión'
);
$sessionExercises = wk_session_exercises($pdo, $sessionId);
$check(
    count($sessionExercises) === 1
        && count($sessionExercises[0]['sets'] ?? []) === 3
        && (int) ($sessionExercises[0]['sets'][0]['reps'] ?? 0) === 6
        && abs((float) ($sessionExercises[0]['sets'][0]['weight'] ?? 0) - 72.5) < 0.01
        && (int) ($sessionExercises[0]['rest_seconds'] ?? 0) === 90
        && (string) ($sessionExercises[0]['notes'] ?? '') === 'Pausa breve en el pecho',
    'rutina conectada a sesión real'
);
$check(count($sessionExercises[0]['content']['steps'] ?? []) === 3, 'guía disponible durante la sesión');
$setId = (int) ($sessionExercises[0]['sets'][0]['id'] ?? 0);
$check(
    wk_set_update($pdo, $setId, ['reps' => 5, 'weight' => 80, 'completed' => 1], $me),
    'registrar serie'
);
$check(wk_session_finish($pdo, $sessionId, $me, true), 'finalizar sesión');
$personalRecord = db_fetch_one(
    $pdo,
    'SELECT value FROM personal_records WHERE user_id = :user AND exercise_def_id = :exercise AND metric = :metric',
    [':user' => $me, ':exercise' => $benchId, ':metric' => 'est_1rm']
);
$check(
    abs((float) ($personalRecord['value'] ?? 0) - 93.3) < 0.11,
    'cálculo de 1RM',
    (string) ($personalRecord['value'] ?? 'sin PR')
);
$linkedSession = db_fetch_one($pdo, 'SELECT daily_log_id FROM workout_sessions WHERE id = :session', [':session' => $sessionId]);
$check((int) ($linkedSession['daily_log_id'] ?? 0) > 0, 'sesión cuenta para el reto');

$draftSessionId = wk_session_start($pdo, $me, $routineId);
$draftSessionExercises = wk_session_exercises($pdo, $draftSessionId);
$draftSessionExerciseId = (int) ($draftSessionExercises[0]['id'] ?? 0);
$draftFirstSetId = (int) ($draftSessionExercises[0]['sets'][0]['id'] ?? 0);
$draftSecondSetId = (int) ($draftSessionExercises[0]['sets'][1]['id'] ?? 0);
$draftUpdated = wk_session_update_draft_sets($pdo, $draftSessionId, $me, [
    $draftFirstSetId => ['weight' => '82.5', 'reps' => '5', 'completed' => '1'],
    $draftSecondSetId => ['weight' => '77.5', 'reps' => '7', 'completed' => '0'],
    999999 => ['weight' => '999', 'reps' => '999', 'completed' => '1'],
]);
$draftAddedSetId = wk_set_add($pdo, $draftSessionExerciseId, $me);
$draftSavedExercises = wk_session_exercises($pdo, $draftSessionId);
$draftSavedSets = (array) ($draftSavedExercises[0]['sets'] ?? []);
$check(
    $draftUpdated === 2
        && abs((float) ($draftSavedSets[0]['weight'] ?? 0) - 82.5) < 0.01
        && (int) ($draftSavedSets[0]['reps'] ?? 0) === 5
        && abs((float) ($draftSavedSets[1]['weight'] ?? 0) - 77.5) < 0.01
        && (int) ($draftSavedSets[1]['reps'] ?? 0) === 7
        && $draftAddedSetId > 0
        && abs((float) ($draftSavedSets[count($draftSavedSets) - 1]['weight'] ?? 0) - 72.5) < 0.01,
    'guardar todas las series antes de añadir conserva el borrador completo'
);
$deleteCandidateId = (int) ($draftSavedSets[1]['id'] ?? 0);
$draftDeleteSaved = wk_set_delete_for_user($pdo, $deleteCandidateId, $me);
$afterDeleteSets = (array) (wk_session_exercises($pdo, $draftSessionId)[0]['sets'] ?? []);
$check(
    $draftDeleteSaved
        && !wk_set_delete_for_user($pdo, (int) ($afterDeleteSets[0]['id'] ?? 0), $other)
        && array_map(static fn(array $set): int => (int) ($set['set_index'] ?? 0), $afterDeleteSets) === [1, 2, 3],
    'eliminar serie guarda propiedad y reordena las restantes'
);
wk_set_delete_for_user($pdo, (int) ($afterDeleteSets[2]['id'] ?? 0), $me);
wk_set_delete_for_user($pdo, (int) ($afterDeleteSets[1]['id'] ?? 0), $me);
$lastDraftSet = (array) (wk_session_exercises($pdo, $draftSessionId)[0]['sets'][0] ?? []);
$check(
    !wk_set_delete_for_user($pdo, (int) ($lastDraftSet['id'] ?? 0), $me),
    'no se puede eliminar la última serie de un ejercicio'
);
$lastCompletedSets = wk_last_completed_sets_for_exercises($pdo, $me, [$benchId], $draftSessionId);
$check(
    abs((float) ($lastCompletedSets[$benchId][1]['weight'] ?? 0) - 80.0) < 0.01
        && (int) ($lastCompletedSets[$benchId][1]['reps'] ?? 0) === 5,
    'segunda sesión recupera la última ejecución completada como referencia'
);
wk_session_cancel($pdo, $draftSessionId, $me);

$runningExercise = db_fetch_one($pdo, 'SELECT id FROM exercise_definitions WHERE slug = :slug', [':slug' => 'running']);
$plankExercise = db_fetch_one($pdo, 'SELECT id FROM exercise_definitions WHERE slug = :slug', [':slug' => 'plank']);
$organizeSessionId = wk_session_start($pdo, $me, null, 'QA Session order');
$organizeBenchRow = wk_session_add_exercise($pdo, $organizeSessionId, $benchId, $me);
$organizeRunRow = wk_session_add_exercise($pdo, $organizeSessionId, (int) ($runningExercise['id'] ?? 0), $me);
$organizePlankRow = wk_session_add_exercise($pdo, $organizeSessionId, (int) ($plankExercise['id'] ?? 0), $me);
$otherSessionId = wk_session_start($pdo, $other, null, 'QA Other session');
$otherSessionRow = wk_session_add_exercise($pdo, $otherSessionId, $benchId, $other);
$protectedSet = (int) (wk_session_exercises($pdo, $organizeSessionId)[0]['sets'][0]['id'] ?? 0);
wk_set_update($pdo, $protectedSet, ['reps' => 5, 'weight' => 50, 'completed' => 1], $me);
$organizedSessionSaved = wk_session_exercises_organize(
    $pdo,
    $organizeSessionId,
    $me,
    [$organizePlankRow, $otherSessionRow, $organizeBenchRow, $organizePlankRow],
    [$organizeRunRow, $organizeBenchRow, $otherSessionRow]
);
$organizedSessionRows = wk_session_exercises($pdo, $organizeSessionId);
$organizedSessionOrder = array_map(static fn(array $exercise): int => (int) $exercise['id'], $organizedSessionRows);
$check(
    $organizedSessionSaved
        && $organizedSessionOrder === [$organizePlankRow, $organizeBenchRow]
        && count((array) ($organizedSessionRows[1]['sets'] ?? [])) === 3
        && (int) ($organizedSessionRows[1]['sets'][0]['completed'] ?? 0) === 1,
    'organizar sesion reordena, quita pendientes y protege series completadas',
    implode(',', $organizedSessionOrder)
);
$check(
    !wk_session_exercises_organize($pdo, $organizeSessionId, $other, array_reverse($organizedSessionOrder))
        && !wk_session_exercises_organize($pdo, $otherSessionId, $me, [$otherSessionRow]),
    'organizar sesion respeta propiedad'
);
wk_session_cancel($pdo, $organizeSessionId, $me);
$check(
    !wk_session_exercises_organize($pdo, $organizeSessionId, $me, $organizedSessionOrder),
    'una sesion cerrada no se puede reorganizar'
);
wk_session_cancel($pdo, $otherSessionId, $other);

$cardioRoutineId = wk_routine_create($pdo, $me, 'QA Cardio target');
$cardioRoutineExerciseId = wk_routine_add_exercise($pdo, $cardioRoutineId, (int) ($runningExercise['id'] ?? 0));
wk_routine_exercise_update($pdo, $cardioRoutineExerciseId, $cardioRoutineId, $me, [
    'target_sets' => 2,
    'target_duration' => 1500,
    'target_distance' => 5.25,
    'rest_seconds' => 60,
]);
$cardioSessionId = wk_session_start($pdo, $me, $cardioRoutineId);
$cardioSessionExercises = wk_session_exercises($pdo, $cardioSessionId);
$check(
    count($cardioSessionExercises) === 1
        && count($cardioSessionExercises[0]['sets'] ?? []) === 2
        && (int) ($cardioSessionExercises[0]['sets'][0]['duration'] ?? 0) === 1500
        && abs((float) ($cardioSessionExercises[0]['sets'][0]['distance'] ?? 0) - 5.25) < 0.01
        && (int) ($cardioSessionExercises[0]['rest_seconds'] ?? 0) === 60,
    'objetivos cardio pasan a la sesión'
);
wk_session_cancel($pdo, $cardioSessionId, $me);
wk_routine_delete($pdo, $cardioRoutineId, $me);

db_execute(
    $pdo,
    'UPDATE users SET ideal_weight = 55, height_cm = 170, competitive_division = "men" WHERE id = :user',
    [':user' => $me]
);
$rankWithoutMeasurement = null;
foreach (wk_exercise_ranks_for_user($pdo, $me) as $rankedExercise) {
    if ((int) $rankedExercise['id'] === $benchId) {
        $rankWithoutMeasurement = (array) $rankedExercise['rank'];
        break;
    }
}
$check(
    (float) ($rankWithoutMeasurement['score'] ?? 0) === 0.0
        && !empty($rankWithoutMeasurement['requires_weight'])
        && wk_user_bodyweight($pdo, $me) === null,
    'el peso objetivo no sustituye un pesaje real'
);
db_execute(
    $pdo,
    'UPDATE daily_logs SET weight = 75, log_date = :old_date, updated_at = :updated WHERE id = :id',
    [
        ':old_date' => (new DateTimeImmutable('today'))->modify('-181 days')->format('Y-m-d'),
        ':updated' => now_iso(),
        ':id' => (int) ($linkedSession['daily_log_id'] ?? 0),
    ]
);
$staleWeightRecord = wk_user_bodyweight_record($pdo, $me);
$check(
    $staleWeightRecord !== null && !$staleWeightRecord['recent'] && wk_user_bodyweight($pdo, $me) === null,
    'un pesaje de más de 180 días deja el rango provisional'
);
db_execute(
    $pdo,
    'UPDATE daily_logs SET weight = 75, log_date = :today, updated_at = :updated WHERE id = :id',
    [':today' => (new DateTimeImmutable('today'))->format('Y-m-d'), ':updated' => now_iso(), ':id' => (int) ($linkedSession['daily_log_id'] ?? 0)]
);
$benchRank = null;
foreach (wk_exercise_ranks_for_user($pdo, $me) as $rankedExercise) {
    if ((int) $rankedExercise['id'] === $benchId) {
        $benchRank = (array) $rankedExercise['rank'];
        break;
    }
}
$check(
    (float) ($benchRank['score'] ?? 0) > 120
        && ($benchRank['key'] ?? '') !== 'unranked'
        && ($benchRank['calculation']['method'] ?? '') === 'allometric_1rm'
        && abs((float) ($benchRank['calculation']['adjustment'] ?? 0) - 1.0) < 0.001,
    'rango por ejercicio con cálculo alométrico explicable',
    (string) ($benchRank['key'] ?? 'none') . ' ' . (string) ($benchRank['score'] ?? 0)
);
$scoreBeforeHeightChange = (float) ($benchRank['score'] ?? 0);
db_execute($pdo, 'UPDATE users SET height_cm = 210 WHERE id = :user', [':user' => $me]);
$benchAfterHeightChange = null;
foreach (wk_exercise_ranks_for_user($pdo, $me) as $rankedExercise) {
    if ((int) $rankedExercise['id'] === $benchId) {
        $benchAfterHeightChange = (array) $rankedExercise['rank'];
        break;
    }
}
$check(
    abs((float) ($benchAfterHeightChange['score'] ?? 0) - $scoreBeforeHeightChange) < 0.001,
    'la altura aporta contexto pero no altera la score'
);
db_execute($pdo, 'UPDATE users SET height_cm = 170 WHERE id = :user', [':user' => $me]);

$pullUp = db_fetch_one($pdo, 'SELECT id FROM exercise_definitions WHERE slug = "pull_up"');
$pullUpId = (int) ($pullUp['id'] ?? 0);
db_execute(
    $pdo,
    'INSERT INTO personal_records (user_id, exercise_def_id, metric, value, achieved_at, session_id)
     VALUES (:user, :exercise, "max_reps", 10, :achieved, NULL)',
    [':user' => $me, ':exercise' => $pullUpId, ':achieved' => now_iso()]
);
$pullUpRank = null;
foreach (wk_exercise_ranks_for_user($pdo, $me) as $rankedExercise) {
    if ((int) $rankedExercise['id'] === $pullUpId) {
        $pullUpRank = (array) $rankedExercise['rank'];
        break;
    }
}
$check(
    ($pullUpRank['calculation']['method'] ?? '') === 'bodyweight_reps'
        && abs((float) ($pullUpRank['score'] ?? 0) - 50.0) < 0.1,
    'calistenia usa repeticiones, peso real y factor del ejercicio'
);
db_execute(
    $pdo,
    'DELETE FROM personal_records WHERE user_id = :user AND exercise_def_id = :exercise AND metric = "max_reps"',
    [':user' => $me, ':exercise' => $pullUpId]
);
$chestRank = null;
foreach (wk_muscle_ranks_for_user($pdo, $me) as $muscleRank) {
    if ($muscleRank['muscle'] === 'chest') {
        $chestRank = $muscleRank;
        break;
    }
}
$check(
    (int) ($chestRank['ranked_count'] ?? 0) === 1 && ($chestRank['rank']['key'] ?? '') !== 'unranked',
    'rango por parte corporal'
);
$overallRank = wk_overall_rank_for_user($pdo, $me);
$check(
    ($overallRank['key'] ?? '') === 'rookie'
        && (int) ($overallRank['body_parts_ranked'] ?? 0) === 1
        && (int) ($overallRank['body_parts_total'] ?? 0) === 10,
    'rango global pondera equilibrio corporal',
    (string) ($overallRank['key'] ?? 'none') . ' · ' . (string) ($overallRank['score'] ?? 0)
);
$leaderboard = wk_rank_leaderboard($pdo);
$check((int) ($leaderboard[0]['id'] ?? 0) === $me, 'clasificación de equipo');
db_execute($pdo, 'UPDATE users SET competitive_division = "women" WHERE id = :user', [':user' => $other]);
$menLeaderboard = wk_rank_leaderboard($pdo, 20, 'men');
$womenLeaderboard = wk_rank_leaderboard($pdo, 20, 'women');
$openLeaderboard = wk_rank_leaderboard($pdo, 20, 'open');
$check(
    array_map(static fn(array $row): int => (int) $row['id'], $menLeaderboard) === [$me]
        && array_map(static fn(array $row): int => (int) $row['id'], $womenLeaderboard) === [$other]
        && count($openLeaderboard) === 2,
    'las categorías filtran la clasificación pero no la fórmula'
);

$bronzeDefault = wk_default_rank_tiers()['bronze'];
wk_admin_save_rank_tiers($pdo, [
    'bronze' => ['threshold' => 30, 'color' => '#bb6a3c', 'sort_order' => 20, 'active' => 1],
], $me);
$check((wk_rank_from_score(26)['key'] ?? '') === 'rookie', 'admin cambia umbrales ranked en vivo');
wk_admin_save_rank_tiers($pdo, [
    'bronze' => ['threshold' => $bronzeDefault['threshold'], 'color' => $bronzeDefault['color'], 'sort_order' => $bronzeDefault['sort_order'], 'active' => 1],
], $me);

$check(wk_exercise_set_favorite($pdo, $benchId, $me, true), 'marcar ejercicio favorito');
$favoriteIds = array_map(
    static fn(array $exercise): int => (int) $exercise['id'],
    wk_exercise_library($pdo, $me, ['scope' => 'favorites'])
);
$otherFavoriteIds = array_map(
    static fn(array $exercise): int => (int) $exercise['id'],
    wk_exercise_library($pdo, $other, ['scope' => 'favorites'])
);
$check(in_array($benchId, $favoriteIds, true) && !in_array($benchId, $otherFavoriteIds, true), 'favoritos aislados por usuario');

$runningFavoriteId = (int) ($runningForOrder['id'] ?? 0);
$check(wk_exercise_set_favorite($pdo, $runningFavoriteId, $me, true), 'marcar segundo favorito para ordenar');
wk_exercise_favorites_reorder($pdo, $me, [$runningFavoriteId, 999999, $benchId, $runningFavoriteId]);
$orderedFavoriteIds = array_map(
    static fn(array $exercise): int => (int) $exercise['id'],
    wk_exercise_library($pdo, $me, ['scope' => 'favorites'])
);
$check(
    array_slice($orderedFavoriteIds, 0, 2) === [$runningFavoriteId, $benchId],
    'orden personal de favoritos ignora IDs invalidos y duplicados',
    implode(',', $orderedFavoriteIds)
);
wk_exercise_favorites_reorder($pdo, $other, [$benchId, $runningFavoriteId]);
$check(
    wk_exercise_library($pdo, $other, ['scope' => 'favorites']) === [],
    'orden de favoritos respeta propiedad'
);

$catalogCopyId = wk_user_clone_exercise($pdo, $benchId, $me);
$catalogCopy = wk_user_exercise_get($pdo, $catalogCopyId, $me);
$copyFromLibrary = null;
foreach (wk_exercise_library($pdo, $me, ['scope' => 'mine']) as $candidate) {
    if ((int) ($candidate['id'] ?? 0) === $catalogCopyId) {
        $copyFromLibrary = $candidate;
        break;
    }
}
$check(
    $catalogCopyId > 0
        && (int) ($catalogCopy['source_exercise_id'] ?? 0) === $benchId
        && (int) ($catalogCopy['rankable'] ?? 1) === 0
        && (string) ($catalogCopy['cover_mode'] ?? '') === 'auto'
        && (string) ($catalogCopy['accent_color'] ?? '') === (string) ($bench['accent_color'] ?? '')
        && (string) ($catalogCopy['visual_mark'] ?? '') === (string) ($bench['visual_mark'] ?? '')
        && (int) ($catalogCopy['default_sets'] ?? 0) === 3
        && (int) ($catalogCopy['default_reps'] ?? 0) === 10
        && (int) ($catalogCopy['default_rest_seconds'] ?? 0) === 90
        && count((array) ($catalogCopy['content']['steps'] ?? [])) === 3
        && (int) ($copyFromLibrary['is_favorite'] ?? 0) === 1,
    'personalizar catálogo crea copia privada completa y favorita'
);
$check(wk_user_clone_exercise($pdo, $benchId, $me) === $catalogCopyId, 'personalizar dos veces reutiliza la copia activa');
$check(wk_user_exercise_get($pdo, $catalogCopyId, $other) === null, 'copia del catálogo mantiene propiedad privada');
$duplicateCopyRow = wk_routine_add_exercise($pdo, $routineId, $catalogCopyId, ['target_sets' => 9]);
$copyReplacedInPlace = wk_routine_exercise_replace_definition($pdo, $firstAdd, $routineId, $me, $catalogCopyId);
$replacedRoutineTarget = wk_routine_exercise_get($pdo, $firstAdd, $routineId, $me);
$routineRowsAfterReplace = (int) (db_fetch_one(
    $pdo,
    'SELECT COUNT(*) AS c FROM routine_exercises WHERE routine_id = :routine',
    [':routine' => $routineId]
)['c'] ?? 0);
$check(
    $duplicateCopyRow > 0
        && $copyReplacedInPlace
        && $routineRowsAfterReplace === 1
        && (int) ($replacedRoutineTarget['exercise_def_id'] ?? 0) === $catalogCopyId
        && (int) ($replacedRoutineTarget['target_sets'] ?? 0) === 3
        && (int) ($replacedRoutineTarget['target_reps'] ?? 0) === 6
        && (string) ($replacedRoutineTarget['notes'] ?? '') === 'Pausa breve en el pecho',
    'personalizar desde rutina sustituye sin duplicar ni perder objetivos'
);
$check(
    !wk_routine_exercise_replace_definition($pdo, $firstAdd, $routineId, $other, $benchId),
    'sustitución contextual respeta propiedad'
);
$check(wk_user_delete_exercise($pdo, $catalogCopyId, $me), 'copia de catálogo se puede eliminar');
$check(wk_exercise_set_favorite($pdo, $benchId, $me, false), 'quitar ejercicio favorito');

$check(wk_exercise_set_favorite($pdo, $runningFavoriteId, $me, false), 'quitar segundo ejercicio favorito');

$adminExerciseId = wk_admin_save_exercise($pdo, null, [
    'name' => 'QA Cable Press',
    'muscle_group' => 'chest',
    'secondary_muscles' => ['triceps', 'shoulders'],
    'exercise_type' => 'strength',
    'equipment' => 'cable',
    'difficulty' => 'intermediate',
    'rank_factor' => '1.15',
    'rankable' => '1',
    'sort_order' => '404',
    'active' => '1',
    'summary' => 'QA guide summary',
    'steps' => "Set the cable\nPress forward",
    'tips' => 'Keep ribs down',
    'mistakes' => 'Shrugging',
    'image_path' => 'workouts/exercises/qa-cable.webp',
    'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    'visual_mark' => 'QX',
    'accent_color' => '#12a4d9',
    'default_sets' => '5',
    'default_reps' => '6',
    'default_weight' => '32.5',
    'default_rest_seconds' => '75',
    'default_unit' => 'lb',
    'default_notes' => 'Admin default cue',
], $me);
$adminExercise = wk_exercise_get($pdo, $adminExerciseId);
$check(
    $adminExerciseId > 0
        && (string) ($adminExercise['image_path'] ?? '') === 'workouts/exercises/qa-cable.webp'
        && (string) ($adminExercise['video_url'] ?? '') !== ''
        && (string) ($adminExercise['visual_mark'] ?? '') === 'QX'
        && (string) ($adminExercise['accent_color'] ?? '') === '#12a4d9'
        && (int) ($adminExercise['default_sets'] ?? 0) === 5
        && (int) ($adminExercise['default_reps'] ?? 0) === 6
        && (float) ($adminExercise['default_weight'] ?? 0) === 32.5
        && (int) ($adminExercise['default_rest_seconds'] ?? 0) === 75
        && (string) ($adminExercise['default_unit'] ?? '') === 'lb'
        && count((array) ($adminExercise['content']['steps'] ?? [])) === 2,
    'admin crea ejercicio con guía y multimedia'
);
wk_admin_save_exercise($pdo, $adminExerciseId, [
    'name' => 'QA Cable Press Updated',
    'muscle_group' => 'chest',
    'exercise_type' => 'strength',
    'equipment' => 'cable',
    'difficulty' => 'advanced',
    'rank_factor' => '1.2',
    'rankable' => '1',
    'sort_order' => '405',
    'active' => '1',
    'summary' => 'Updated summary',
    'steps' => 'Press under control',
    'image_path' => 'workouts/exercises/qa-cable-v2.webp',
    'video_url' => 'https://vimeo.com/123456789',
], $me);
$updatedAdminExercise = wk_exercise_get($pdo, $adminExerciseId);
$check(
    (string) ($updatedAdminExercise['name'] ?? '') === 'QA Cable Press Updated'
        && (int) ($updatedAdminExercise['admin_override'] ?? 0) === 1
        && (string) ($updatedAdminExercise['image_path'] ?? '') === 'workouts/exercises/qa-cable-v2.webp'
        && (string) ($updatedAdminExercise['visual_mark'] ?? '') === 'QX'
        && (string) ($updatedAdminExercise['accent_color'] ?? '') === '#12a4d9'
        && (int) ($updatedAdminExercise['default_sets'] ?? 0) === 5
        && (string) ($updatedAdminExercise['default_notes'] ?? '') === 'Admin default cue',
    'admin actualiza ejercicio sin que el seed lo pise'
);
workouts_seed_system_exercises($pdo);
$seedSafeExercise = wk_exercise_get($pdo, $adminExerciseId);
$check((string) ($seedSafeExercise['name'] ?? '') === 'QA Cable Press Updated', 'override admin persiste tras resembrar');
$check(wk_admin_delete_exercise($pdo, $adminExerciseId, $me), 'admin elimina ejercicio');
$visibleExerciseIds = array_map(static fn(array $exercise): int => (int) $exercise['id'], wk_exercises_for_user($pdo, $me));
$deletedAdminExercise = wk_exercise_get($pdo, $adminExerciseId);
$check((int) ($deletedAdminExercise['active'] ?? 1) === 0 && !in_array($adminExerciseId, $visibleExerciseIds, true), 'ejercicio eliminado queda oculto sin romper historial');

$personalExerciseId = wk_user_save_exercise($pdo, $me, null, [
    'name' => 'QA Press personal',
    'muscle_group' => 'chest',
    'secondary_muscles' => ['triceps', 'chest', 'invalid'],
    'exercise_type' => 'strength',
    'equipment' => 'dumbbell',
    'difficulty' => 'intermediate',
    'summary' => 'Mi guía personal',
    'steps_items' => ['Prepara el banco', 'Controla la bajada'],
    'tips_items' => ['Mantén los pies firmes'],
    'mistakes_items' => ['Abrir demasiado los codos'],
    'image_path' => 'workouts/exercises/user_' . $me . '/qa.webp',
    'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    'cover_mode' => 'video',
    'image_position' => 'right',
    'visual_mark' => '🐉',
    'accent_color' => '#12a4d9',
    'default_sets' => '4',
    'default_reps' => '8',
    'default_weight' => '24',
    'default_rest_seconds' => '75',
    'default_unit' => 'lb',
    'default_notes' => 'Pausa y empuja estable',
]);
$personalExercise = wk_user_exercise_get($pdo, $personalExerciseId, $me);
$personalLibraryIds = array_map(
    static fn(array $exercise): int => (int) $exercise['id'],
    wk_exercise_library($pdo, $me, ['scope' => 'mine'])
);
$check(
    $personalExerciseId > 0
        && (int) ($personalExercise['rankable'] ?? 1) === 0
        && (string) ($personalExercise['image_path'] ?? '') !== ''
        && (string) ($personalExercise['cover_mode'] ?? '') === 'video'
        && (string) ($personalExercise['image_position'] ?? '') === 'right'
        && (string) ($personalExercise['visual_mark'] ?? '') === '🐉'
        && (string) ($personalExercise['accent_color'] ?? '') === '#12a4d9'
        && (int) ($personalExercise['default_sets'] ?? 0) === 4
        && (int) ($personalExercise['default_reps'] ?? 0) === 8
        && (float) ($personalExercise['default_weight'] ?? 0) === 24.0
        && (int) ($personalExercise['default_rest_seconds'] ?? 0) === 75
        && (string) ($personalExercise['default_unit'] ?? '') === 'lb'
        && count((array) ($personalExercise['content']['steps'] ?? [])) === 2
        && (string) ($personalExercise['content']['steps'][1] ?? '') === 'Controla la bajada'
        && in_array($personalExerciseId, $personalLibraryIds, true),
    'usuario crea ejercicio personal con guía, foto y YouTube'
);
$personalCoverPath = 'workouts/exercises/user_' . $me . '/qa.webp';
$personalGalleryPaths = [
    $personalCoverPath,
    'workouts/exercises/user_' . $me . '/qa-side.webp',
    'workouts/exercises/user_' . $me . '/qa-setup.webp',
];
wk_exercise_media_replace($pdo, $personalExerciseId, [
    ['path' => $personalGalleryPaths[0], 'position' => 'right', 'caption' => 'Posición inicial'],
    ['path' => $personalGalleryPaths[1], 'position' => 'focal:31:66', 'caption' => 'Vista lateral'],
    ['path' => $personalGalleryPaths[2], 'position' => 'top', 'caption' => 'Bloqueo final'],
]);
$personalGallery = wk_exercise_media_list($pdo, $personalExerciseId);
$check(
    count($personalGallery) === 3
        && array_column($personalGallery, 'path') === $personalGalleryPaths
        && (bool) ($personalGallery[0]['is_cover'] ?? false)
        && (string) ($personalGallery[1]['position'] ?? '') === 'focal:31:66'
        && (string) ($personalGallery[2]['position'] ?? '') === 'top'
        && array_column($personalGallery, 'caption') === ['Posición inicial', 'Vista lateral', 'Bloqueo final'],
    'galería conserva orden, portada, encuadre y títulos de hasta cuatro fotos'
);
$resolvedGallery = wk_exercise_media_resolve_submission(
    $personalGallery,
    [$personalGalleryPaths[2], $personalCoverPath, 'new:0', 'new:1', 'new:2', 'workouts/exercises/foreign.webp'],
    [
        'workouts/exercises/user_' . $me . '/qa-back.webp',
        'workouts/exercises/user_' . $me . '/qa-detail.webp',
        'workouts/exercises/user_' . $me . '/qa-extra.webp',
    ],
    'new:0',
    'left',
    ['top', 'right', 'focal:37:64', 'left', 'center', 'center'],
    ['  Posición   final ', ' Preparación   estable ', 'Empuje', str_repeat('x', 160), 'Extra', 'Ajena']
);
$check(
    count($resolvedGallery['items']) === 4
        && (string) ($resolvedGallery['cover_path'] ?? '') === 'workouts/exercises/user_' . $me . '/qa-back.webp'
        && (string) ($resolvedGallery['cover_position'] ?? '') === 'focal:37:64'
        && (string) ($resolvedGallery['items'][0]['position'] ?? '') === 'focal:37:64'
        && (string) ($resolvedGallery['items'][1]['position'] ?? '') === 'top'
        && (string) ($resolvedGallery['items'][2]['position'] ?? '') === 'right'
        && (string) ($resolvedGallery['items'][0]['caption'] ?? '') === 'Empuje'
        && (string) ($resolvedGallery['items'][1]['caption'] ?? '') === 'Posición final'
        && (string) ($resolvedGallery['items'][2]['caption'] ?? '') === 'Preparación estable'
        && strlen((string) ($resolvedGallery['items'][3]['caption'] ?? '')) === 120
        && !in_array('workouts/exercises/foreign.webp', array_column($resolvedGallery['items'], 'path'), true),
    'galería valida tokens, encuadres, títulos, límite y portada'
);
$personalGalleryCloneId = wk_user_clone_exercise($pdo, $personalExerciseId, $me);
$personalGalleryClone = wk_exercise_media_list($pdo, $personalGalleryCloneId);
$check(
    count($personalGalleryClone) === 3
        && array_column($personalGalleryClone, 'path') === $personalGalleryPaths
        && (string) ($personalGalleryClone[1]['position'] ?? '') === 'focal:31:66'
        && array_column($personalGalleryClone, 'caption') === ['Posición inicial', 'Vista lateral', 'Bloqueo final'],
    'personalizar ejercicio reutiliza también su galería ordenada y titulada'
);
wk_user_delete_exercise($pdo, $personalGalleryCloneId, $me);
wk_exercise_media_replace($pdo, $personalExerciseId, [
    ['path' => $personalCoverPath, 'position' => 'right'],
]);
$cardioDefaults = wk_exercise_training_defaults([
    'exercise_type' => 'cardio',
    'default_sets' => '2',
    'default_duration_minutes' => '12.5',
    'default_distance' => '3.2',
    'default_rest_seconds' => '0',
]);
$check(
    $cardioDefaults['target_sets'] === 2
        && $cardioDefaults['target_duration'] === 750
        && (float) $cardioDefaults['target_distance'] === 3.2
        && $cardioDefaults['rest_seconds'] === null,
    'objetivo inicial adapta cardio y permite descanso cero'
);
$check(
    wk_user_exercise_get($pdo, $personalExerciseId, $other) === null
        && wk_user_delete_exercise($pdo, $personalExerciseId, $other) === false,
    'ejercicio personal queda aislado por propietario'
);
$foreignUpdateRejected = false;
try {
    wk_user_save_exercise($pdo, $other, $personalExerciseId, ['name' => 'Secuestrado']);
} catch (InvalidArgumentException) {
    $foreignUpdateRejected = true;
}
$check($foreignUpdateRejected, 'otro usuario no puede editar ejercicio personal');
$unsafeVideoRejected = false;
try {
    wk_user_save_exercise($pdo, $me, null, [
        'name' => 'QA video inseguro',
        'muscle_group' => 'core',
        'video_url' => 'javascript:alert(1)',
    ]);
} catch (InvalidArgumentException) {
    $unsafeVideoRejected = true;
}
$check($unsafeVideoRejected, 'enlaces de vídeo inseguros se rechazan');
$youtubeMedia = wk_exercise_video_source('https://www.youtube.com/shorts/dQw4w9WgXcQ');
$vimeoMedia = wk_exercise_video_source('https://vimeo.com/123456789');
$check(
    ($youtubeMedia['provider'] ?? '') === 'youtube'
        && str_contains((string) ($youtubeMedia['url'] ?? ''), 'youtube-nocookie.com/embed/dQw4w9WgXcQ')
        && str_contains((string) ($youtubeMedia['thumbnail_url'] ?? ''), 'i.ytimg.com/vi/dQw4w9WgXcQ')
        && ($vimeoMedia['provider'] ?? '') === 'vimeo',
    'proveedores de vídeo generan embeds y miniatura seguros'
);
$check(
    wk_normalize_exercise_cover_mode('simple') === 'simple'
        && wk_normalize_exercise_cover_mode('url(javascript:1)') === 'auto',
    'modo de portada usa una lista segura'
);
$check(
    wk_normalize_image_position('left') === 'left'
        && wk_normalize_image_position('focal:37:64') === 'focal:37:64'
        && wk_normalize_image_position('focal:101:64') === 'center'
        && wk_normalize_image_position('url(javascript:1)') === 'center'
        && wk_image_position_css('bottom') === '50% 82%'
        && wk_image_position_css('focal:37:64') === '37% 64%'
        && wk_image_position_coordinates('focal:37:64') === ['x' => 37, 'y' => 64],
    'encuadre de foto usa valores y CSS seguros'
);
$check(
    wk_normalize_exercise_color('#ABCDEF') === '#abcdef'
        && wk_normalize_exercise_color('red;position:fixed') === '#14b8a6',
    'color del ejercicio acepta hex libre y bloquea CSS inseguro'
);
$check(
    wk_normalize_exercise_mark('ABCD') === 'ABC'
        && wk_normalize_exercise_mark('🔥') === '🔥'
        && wk_normalize_exercise_mark("A\0B") === 'AB'
        && wk_normalize_exercise_mark('<>&') === '',
    'marca visual admite emoji o iniciales y elimina contenido inseguro'
);

wk_user_save_exercise($pdo, $me, $personalExerciseId, [
    'name' => 'QA Press personal actualizado',
    'muscle_group' => 'shoulders',
    'secondary_muscles' => ['triceps'],
    'exercise_type' => 'strength',
    'equipment' => 'dumbbell',
    'difficulty' => 'advanced',
    'summary' => 'Guía actualizada',
    'steps' => 'Empuja sin perder control',
    'image_path' => 'workouts/exercises/user_' . $me . '/qa-v2.webp',
    'video_url' => 'https://vimeo.com/123456789',
    'cover_mode' => 'simple',
    'image_position' => 'bottom',
    'visual_mark' => 'ZEN',
    'accent_color' => '#d946ef',
]);
$updatedPersonalExercise = wk_user_exercise_get($pdo, $personalExerciseId, $me);
$check(
    (string) ($updatedPersonalExercise['name'] ?? '') === 'QA Press personal actualizado'
        && (string) ($updatedPersonalExercise['muscle_group'] ?? '') === 'shoulders'
        && (string) ($updatedPersonalExercise['video_url'] ?? '') === 'https://vimeo.com/123456789'
        && (string) ($updatedPersonalExercise['cover_mode'] ?? '') === 'simple'
        && (string) ($updatedPersonalExercise['image_position'] ?? '') === 'bottom'
        && (string) ($updatedPersonalExercise['visual_mark'] ?? '') === 'ZEN'
        && (string) ($updatedPersonalExercise['accent_color'] ?? '') === '#d946ef'
        && (int) ($updatedPersonalExercise['default_sets'] ?? 0) === 4
        && (int) ($updatedPersonalExercise['default_reps'] ?? 0) === 8
        && (string) ($updatedPersonalExercise['default_notes'] ?? '') === 'Pausa y empuja estable',
    'propietario actualiza su ejercicio y multimedia'
);

$personalRoutineId = wk_routine_create($pdo, $me, 'QA rutina personal');
$personalRoutineExerciseId = wk_routine_add_exercise($pdo, $personalRoutineId, $personalExerciseId);
$personalRoutineTarget = wk_routine_exercise_get($pdo, $personalRoutineExerciseId, $personalRoutineId, $me);
$check(
    (int) ($personalRoutineTarget['target_sets'] ?? 0) === 4
        && (int) ($personalRoutineTarget['target_reps'] ?? 0) === 8
        && (float) ($personalRoutineTarget['target_weight'] ?? 0) === 24.0
        && (int) ($personalRoutineTarget['rest_seconds'] ?? 0) === 75
        && (string) ($personalRoutineTarget['unit'] ?? '') === 'lb'
        && (string) ($personalRoutineTarget['notes'] ?? '') === 'Pausa y empuja estable',
    'rutina nueva hereda el objetivo inicial del ejercicio'
);
$personalPresetSessionId = wk_session_start($pdo, $me, null, 'QA preset directo');
$personalPresetSessionExerciseId = wk_session_add_exercise($pdo, $personalPresetSessionId, $personalExerciseId, $me);
$personalPresetSessionExercise = null;
foreach (wk_session_exercises($pdo, $personalPresetSessionId) as $sessionExerciseCandidate) {
    if ((int) ($sessionExerciseCandidate['id'] ?? 0) === $personalPresetSessionExerciseId) {
        $personalPresetSessionExercise = $sessionExerciseCandidate;
        break;
    }
}
$check(
    $personalPresetSessionExercise !== null
        && count((array) ($personalPresetSessionExercise['sets'] ?? [])) === 4
        && count(array_filter((array) ($personalPresetSessionExercise['sets'] ?? []), static fn(array $set): bool => (int) ($set['reps'] ?? 0) === 8 && (float) ($set['weight'] ?? 0) === 24.0)) === 4
        && (int) ($personalPresetSessionExercise['rest_seconds'] ?? 0) === 75
        && (string) ($personalPresetSessionExercise['unit'] ?? '') === 'lb'
        && (string) ($personalPresetSessionExercise['notes'] ?? '') === 'Pausa y empuja estable',
    'sesion directa crea todas las series con el objetivo personal'
);
wk_session_cancel($pdo, $personalPresetSessionId, $me);
$check(wk_user_delete_exercise($pdo, $personalExerciseId, $me), 'propietario elimina ejercicio personal');
$deletedPersonalExercise = wk_exercise_get($pdo, $personalExerciseId);
$personalRoutineExercises = wk_routine_exercises($pdo, $personalRoutineId);
$check(
    (int) ($deletedPersonalExercise['active'] ?? 1) === 0
        && (int) ($personalRoutineExercises[0]['exercise_def_id'] ?? 0) === $personalExerciseId
        && (string) ($personalRoutineExercises[0]['image_position'] ?? '') === 'bottom'
        && (string) ($personalRoutineExercises[0]['visual_mark'] ?? '') === 'ZEN'
        && (string) ($personalRoutineExercises[0]['accent_color'] ?? '') === '#d946ef',
    'borrado personal conserva rutinas e historial'
);

$customSeasonId = season_admin_save($pdo, null, 'qa-summer-2032', 'QA Summer Strength', '2032-04-15', '2032-07-15', $me, 'fire', 'seasons/covers/qa.webp', '#db2777');
$customSeason = seasons_current($pdo, '2032-05-10');
$check(
    $customSeasonId > 0
        && (int) ($customSeason['id'] ?? 0) === $customSeasonId
        && (string) ($customSeason['icon_key'] ?? '') === 'fire'
        && (string) ($customSeason['cover_path'] ?? '') === 'seasons/covers/qa.webp'
        && (string) ($customSeason['accent_color'] ?? '') === '#db2777',
    'season admin guarda icono, portada y color'
);
season_admin_save($pdo, $customSeasonId, 'qa-summer-2032', 'QA Summer Updated', '2032-04-01', '2032-07-31', $me, 'bolt', '', '#2563eb');
$updatedSeason = db_fetch_one($pdo, 'SELECT * FROM seasons WHERE id = :id', [':id' => $customSeasonId]);
$check((string) ($updatedSeason['name'] ?? '') === 'QA Summer Updated' && (string) ($updatedSeason['icon_key'] ?? '') === 'bolt' && !empty($updatedSeason['updated_at']), 'season admin se edita');
$check(season_admin_delete($pdo, $customSeasonId, $me), 'season admin se elimina');
$automaticSeason = seasons_current($pdo, '2032-05-10');
$check((string) ($automaticSeason['season_key'] ?? '') === '2032-Q2', 'season trimestral vuelve como fallback');
db_execute($pdo, 'DELETE FROM seasons');
seasons_automation_update($pdo, true, 6, 3, $me);
$generatedSeasons = seasons_generate_upcoming($pdo, $me, '2033-01-01', true);
$automaticRows = db_fetch_all($pdo, 'SELECT * FROM seasons ORDER BY start_date ASC, id ASC');
$automaticRowsContinuous = count($automaticRows) === 3;
for ($seasonIndex = 1; $seasonIndex < count($automaticRows); $seasonIndex++) {
    $expectedStart = (new DateTimeImmutable((string) $automaticRows[$seasonIndex - 1]['end_date']))->modify('+1 day')->format('Y-m-d');
    $automaticRowsContinuous = $automaticRowsContinuous && (string) $automaticRows[$seasonIndex]['start_date'] === $expectedStart;
}
$automaticScheduleStatus = seasons_schedule_status($pdo, '2033-01-01');
$check(
    (int) ($generatedSeasons['created'] ?? 0) === 3
        && $automaticRowsContinuous
        && count(array_filter($automaticRows, static fn(array $row): bool => (string) ($row['generation_source'] ?? '') === 'automatic')) === 3
        && (int) ($automaticScheduleStatus['gaps'] ?? -1) === 0
        && (int) ($automaticScheduleStatus['overlaps'] ?? -1) === 0,
    'temporadas automaticas se crean por adelantado y sin dias vacios'
);

$victimRoutineId = wk_routine_create($pdo, $other, 'Privada Catalina');
$victimRoutineExerciseId = wk_routine_add_exercise($pdo, $victimRoutineId, $benchId);
$removed = wk_routine_remove_exercise($pdo, $victimRoutineExerciseId, $victimRoutineId, $me);
$victimExerciseStillExists = db_fetch_one(
    $pdo,
    'SELECT id FROM routine_exercises WHERE id = :id',
    [':id' => $victimRoutineExerciseId]
);
$check($removed === false && $victimExerciseStillExists !== null, 'aislamiento entre usuarios');

$workoutsViewSource = (string) file_get_contents(dirname(__DIR__) . '/app/views/workouts.php');
$workoutsCssSource = (string) file_get_contents(dirname(__DIR__) . '/public/assets/workouts.css');
$globalCssSource = (string) file_get_contents(dirname(__DIR__) . '/public/assets/styles.css');
$check(
    str_contains($workoutsViewSource, 'workouts-resume-cta-label')
        && str_contains($workoutsCssSource, 'grid-template-columns: 8px minmax(0, 1fr) 44px')
        && str_contains($workoutsCssSource, '.workouts-active-session-progress > .goal-progress')
        && !str_contains($globalCssSource, '.workouts-active-session-card.compact-panel'),
    'tarjeta de sesion activa usa layout movil aislado sin parches globales'
);
$mainJsSource = (string) file_get_contents(dirname(__DIR__) . '/public/assets/main.js');
$check(
    str_contains($workoutsViewSource, 'data-clear-workout-suggestion')
        && str_contains($workoutsViewSource, 'target_session_id=<?= $sid ?>')
        && str_contains($workoutsCssSource, '.workouts-session-head-actions')
        && str_contains($mainJsSource, 'draft_sets[${setId}]')
        && str_contains($mainJsSource, 'fc.workout.session.draft.'),
    'sesion activa conserva borradores, referencias y retorno desde la guia'
);

if ($failures !== []) {
    fwrite(STDERR, PHP_EOL . count($failures) . ' comprobaciones fallaron: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo PHP_EOL . 'Todas las comprobaciones de dominio pasaron.' . PHP_EOL;
if ($keepDatabase) {
    echo 'Base QA conservada en ' . $persistedDbPath . PHP_EOL;
}
