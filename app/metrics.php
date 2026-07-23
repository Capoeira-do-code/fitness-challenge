<?php

declare(strict_types=1);

/**
 * User-selectable metrics are deliberately kept separate from the legacy goal
 * columns. The columns remain the canonical targets while this table controls
 * visibility, scoring and quest generation.
 */

function metric_preference_base_keys(): array
{
    return [
        'steps',
        'distance',
        'workouts',
        'calories_burned',
        'calories_consumed',
        'weight',
        'discipline',
    ];
}

function metric_preference_is_valid_key(PDO $pdo, string $key): bool
{
    if (in_array($key, metric_preference_base_keys(), true)) {
        return true;
    }
    if (!str_starts_with($key, 'habit:')) {
        return false;
    }
    $code = substr($key, 6);
    if ($code === '') {
        return false;
    }

    return db_fetch_one(
        $pdo,
        'SELECT id FROM habit_definitions WHERE code = :code AND active = 1',
        [':code' => $code]
    ) !== null;
}

function metric_preference_definitions(PDO $pdo, array $user): array
{
    $definitions = [
        'steps' => ['label' => t('metric.steps'), 'icon' => 'footsteps', 'period' => 'daily', 'target_required' => true],
        'distance' => ['label' => t('metric.distance_km'), 'icon' => 'run', 'period' => 'daily', 'target_required' => true],
        'workouts' => ['label' => t('metric.workouts'), 'icon' => 'dumbbell', 'period' => 'weekly', 'target_required' => true],
        'calories_burned' => ['label' => t('dashboard.calories_burned'), 'icon' => 'bolt', 'period' => 'daily', 'target_required' => true],
        'calories_consumed' => ['label' => t('dashboard.calories_consumed'), 'icon' => 'flame', 'period' => 'daily', 'target_required' => true],
        'weight' => ['label' => t('metric.weight'), 'icon' => 'target', 'period' => 'weekly', 'target_required' => true],
        'discipline' => ['label' => t('metric.discipline'), 'icon' => 'shield', 'period' => 'weekly', 'target_required' => false],
    ];
    foreach (list_habit_definitions($pdo, true) as $habit) {
        $code = trim((string) ($habit['code'] ?? ''));
        if ($code === '') {
            continue;
        }
        $definitions['habit:' . $code] = [
            'label' => (string) ($habit['label'] ?? $code),
            'icon' => 'check',
            'period' => 'daily',
            'target_required' => false,
            'habit_id' => (int) ($habit['id'] ?? 0),
            'habit_code' => $code,
        ];
    }
    foreach ($definitions as $key => &$definition) {
        $definition['key'] = $key;
        $definition['target'] = metric_target_for_user($user, $key);
    }
    unset($definition);

    return $definitions;
}

function metric_target_for_user(array $user, string $key): ?float
{
    if ($key === 'steps') {
        return (float) max(0, (int) ($user['step_goal'] ?? 0));
    }
    if ($key === 'workouts') {
        return (float) max(0, (int) ($user['workout_target'] ?? 0));
    }
    if ($key === 'calories_burned') {
        return isset($user['calorie_burn_goal']) ? max(0.0, (float) $user['calorie_burn_goal']) : null;
    }
    if ($key === 'calories_consumed') {
        return isset($user['calorie_consumed_max']) ? max(0.0, (float) $user['calorie_consumed_max']) : null;
    }
    if ($key === 'weight') {
        return isset($user['ideal_weight']) ? max(0.0, (float) $user['ideal_weight']) : null;
    }
    if ($key === 'distance') {
        foreach (user_primary_goals($user) as $goal) {
            if ((string) ($goal['type'] ?? '') === 'km') {
                return max(0.0, (float) ($goal['value'] ?? 0));
            }
        }

        return null;
    }
    if ($key === 'discipline' || str_starts_with($key, 'habit:')) {
        return 1.0;
    }

    return null;
}

function metric_preferences_for_user(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }
    $rows = db_fetch_all(
        $pdo,
        'SELECT metric_key, enabled, enabled_from, updated_at
         FROM user_metric_preferences WHERE user_id = :user_id',
        [':user_id' => $userId]
    );
    $preferences = [];
    foreach ($rows as $row) {
        $preferences[(string) $row['metric_key']] = [
            'enabled' => (int) ($row['enabled'] ?? 0) === 1,
            'enabled_from' => trim((string) ($row['enabled_from'] ?? '')),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    return $preferences;
}

function metric_enabled_keys(PDO $pdo, array $user): array
{
    $definitions = metric_preference_definitions($pdo, $user);
    $preferences = metric_preferences_for_user($pdo, (int) ($user['id'] ?? 0));
    $enabled = [];
    foreach ($preferences as $key => $preference) {
        if (!isset($definitions[$key]) || empty($preference['enabled'])) {
            continue;
        }
        $definition = $definitions[$key];
        if (!empty($definition['target_required']) && (float) ($definition['target'] ?? 0) <= 0) {
            continue;
        }
        $enabled[] = $key;
    }

    return $enabled;
}

function metric_is_enabled(PDO $pdo, array $user, string $key): bool
{
    return in_array($key, metric_enabled_keys($pdo, $user), true);
}

function save_user_metric_preferences(PDO $pdo, array $user, array $requestedKeys, ?string $enabledFrom = null): array
{
    $userId = (int) ($user['id'] ?? 0);
    if ($userId <= 0) {
        throw new InvalidArgumentException('Invalid user.');
    }
    $definitions = metric_preference_definitions($pdo, $user);
    $requested = array_values(array_unique(array_filter(
        array_map(static fn(mixed $value): string => trim((string) $value), $requestedKeys),
        static fn(string $key): bool => $key !== ''
    )));
    $invalidTargets = [];
    foreach ($requested as $key) {
        if (!isset($definitions[$key])) {
            continue;
        }
        if (!empty($definitions[$key]['target_required']) && (float) ($definitions[$key]['target'] ?? 0) <= 0) {
            $invalidTargets[] = (string) $definitions[$key]['label'];
        }
    }
    if ($invalidTargets !== []) {
        throw new InvalidArgumentException(t('settings.metric_target_required', ['metrics' => implode(', ', $invalidTargets)]));
    }
    $requested = array_values(array_intersect($requested, array_keys($definitions)));
    $existing = metric_preferences_for_user($pdo, $userId);
    $today = to_date($enabledFrom);
    $now = now_iso();
    foreach ($definitions as $key => $_definition) {
        $enable = in_array($key, $requested, true);
        $wasEnabled = !empty($existing[$key]['enabled']);
        $from = trim((string) ($existing[$key]['enabled_from'] ?? ''));
        if ($enable && !$wasEnabled) {
            $from = $today;
        }
        db_execute(
            $pdo,
            'INSERT INTO user_metric_preferences (user_id, metric_key, enabled, enabled_from, updated_at)
             VALUES (:user_id, :metric_key, :enabled, :enabled_from, :updated_at)
             ON CONFLICT(user_id, metric_key) DO UPDATE SET
                enabled = excluded.enabled,
                enabled_from = excluded.enabled_from,
                updated_at = excluded.updated_at',
            [
                ':user_id' => $userId,
                ':metric_key' => $key,
                ':enabled' => $enable ? 1 : 0,
                ':enabled_from' => $from !== '' ? $from : null,
                ':updated_at' => $now,
            ]
        );
    }

    return $requested;
}

function metric_preferences_backfill(PDO $pdo): void
{
    $settings = db_fetch_one($pdo, 'SELECT challenge_start FROM challenge_settings WHERE id = 1');
    $enabledFrom = to_date((string) ($settings['challenge_start'] ?? null));
    $users = db_fetch_all($pdo, 'SELECT * FROM users');
    foreach ($users as $user) {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            continue;
        }
        $existing = (int) (db_fetch_one(
            $pdo,
            'SELECT COUNT(*) AS c FROM user_metric_preferences WHERE user_id = :user_id',
            [':user_id' => $userId]
        )['c'] ?? 0);
        if ($existing > 0) {
            continue;
        }
        $enabled = [];
        foreach (metric_preference_base_keys() as $key) {
            $target = metric_target_for_user($user, $key);
            if ($key !== 'discipline' && $target !== null && $target > 0) {
                $enabled[] = $key;
            }
        }
        $habitRows = db_fetch_all(
            $pdo,
            'SELECT DISTINCT hd.code
             FROM daily_log_habits dlh
             JOIN daily_logs dl ON dl.id = dlh.log_id
             JOIN habit_definitions hd ON hd.id = dlh.habit_id
             WHERE dl.user_id = :user_id AND dlh.value = 1 AND hd.active = 1',
            [':user_id' => $userId]
        );
        foreach ($habitRows as $habitRow) {
            $enabled[] = 'habit:' . (string) $habitRow['code'];
        }
        $disciplineHistory = db_fetch_one(
            $pdo,
            'SELECT
                (SELECT COUNT(*) FROM strike_review_requests WHERE target_user_id = :user_id) +
                (SELECT COUNT(*) FROM approval_requests WHERE user_id = :user_id) AS c',
            [':user_id' => $userId]
        );
        if ((int) ($disciplineHistory['c'] ?? 0) > 0) {
            $enabled[] = 'discipline';
        }
        save_user_metric_preferences($pdo, $user, $enabled, $enabledFrom);
    }
}

function parse_localized_positive_integer(mixed $value, int $min = 1, int $max = 500000): ?int
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return null;
    }
    $normalized = preg_replace('/[\s.\x{00A0}\x{202F}]+/u', '', $raw);
    if (!is_string($normalized) || preg_match('/^\d+$/', $normalized) !== 1) {
        return null;
    }
    $number = (int) $normalized;

    return $number >= $min && $number <= $max ? $number : null;
}

function metric_view_date_range(PDO $pdo, string $view): array
{
    $settings = db_fetch_one($pdo, 'SELECT challenge_start, challenge_end FROM challenge_settings WHERE id = 1') ?? [];
    $today = to_date(null);
    if ($view === 'total') {
        $start = to_date((string) ($settings['challenge_start'] ?? $today));
        $end = min($today, to_date((string) ($settings['challenge_end'] ?? $today)));

        return [$start, $end < $start ? $start : $end];
    }
    $start = to_date($view, $today);
    try {
        $start = week_start_for(new DateTimeImmutable($start))->format('Y-m-d');
        $end = (new DateTimeImmutable($start))->modify('+6 days')->format('Y-m-d');
    } catch (Throwable) {
        $end = $start;
    }

    return [$start, min($today, $end)];
}

function metric_date_count(string $start, string $end): int
{
    try {
        return max(0, ((new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days ?? -1) + 1);
    } catch (Throwable) {
        return 0;
    }
}

function metric_preferences_apply_snapshot(array $metric, string $view, array $snapshot): array
{
    $pdo = db_current();
    $user = is_array($metric['user'] ?? null) ? (array) $metric['user'] : [];
    if (!$pdo instanceof PDO || (int) ($user['id'] ?? 0) <= 0) {
        return $snapshot;
    }
    $definitions = metric_preference_definitions($pdo, $user);
    $preferences = metric_preferences_for_user($pdo, (int) $user['id']);
    [$viewStart, $viewEnd] = metric_view_date_range($pdo, $view);
    $components = [];
    $details = [];
    foreach ($preferences as $key => $preference) {
        if (empty($preference['enabled']) || !isset($definitions[$key])) {
            continue;
        }
        $definition = $definitions[$key];
        $target = (float) ($definition['target'] ?? 0);
        if (!empty($definition['target_required']) && $target <= 0) {
            continue;
        }
        $start = $viewStart;
        $enabledFrom = trim((string) ($preference['enabled_from'] ?? ''));
        if ($enabledFrom !== '' && $enabledFrom > $start) {
            $start = $enabledFrom;
        }
        if ($start > $viewEnd) {
            continue;
        }
        $progress = metric_progress_between($pdo, $user, $metric, $snapshot, $key, $target, $start, $viewEnd, $view);
        $progress = max(0.0, min(100.0, $progress));
        $details[$key] = [
            'key' => $key,
            'label' => (string) $definition['label'],
            'progress' => round($progress, 1),
            'target' => $target,
            'period' => (string) $definition['period'],
        ];
    }
    $count = count($details);
    foreach ($details as $key => $detail) {
        $components[$key] = $count > 0 ? round((float) $detail['progress'] / $count, 2) : 0.0;
    }
    $score = $count > 0 ? round(array_sum($components), 1) : null;
    $snapshot['score'] = $score;
    $snapshot['score_components'] = $components;
    $snapshot['score_components_detailed'] = $details;
    $snapshot['active_metric_keys'] = array_keys($details);
    $snapshot['has_active_metrics'] = $count > 0;

    return $snapshot;
}

function metric_progress_between(
    PDO $pdo,
    array $user,
    array $metric,
    array $snapshot,
    string $key,
    float $target,
    string $start,
    string $end,
    string $view
): float {
    $userId = (int) $user['id'];
    $days = max(1, metric_date_count($start, $end));
    if ($key === 'steps' || $key === 'distance') {
        $column = $key === 'steps' ? 'steps' : 'distance_km';
        $rows = db_fetch_all(
            $pdo,
            'SELECT log_date, COALESCE(' . $column . ', 0) AS value
             FROM daily_logs WHERE user_id = :user_id AND log_date BETWEEN :start AND :end',
            [':user_id' => $userId, ':start' => $start, ':end' => $end]
        );
        $byDate = [];
        foreach ($rows as $row) {
            $byDate[(string) $row['log_date']] = (float) $row['value'];
        }
        $eligible = 0;
        $total = 0.0;
        foreach (day_sequence(new DateTimeImmutable($start), new DateTimeImmutable($end)) as $date) {
            $dateKey = $date->format('Y-m-d');
            if ($key === 'steps' && !mask_allows_day((string) ($user['step_days_mask'] ?? '1111111'), $dateKey)) {
                continue;
            }
            $eligible++;
            $total += max(0.0, (float) ($byDate[$dateKey] ?? 0));
        }

        return $eligible > 0 && $target > 0 ? ($total / ($target * $eligible)) * 100 : 0.0;
    }
    if ($key === 'workouts') {
        $row = db_fetch_one(
            $pdo,
            'SELECT COALESCE(SUM(
                CASE
                    WHEN COALESCE(workouts.workout_count, 0) > 0 THEN workouts.workout_count
                    ELSE (CASE WHEN dl.workout_done = 1 THEN 1 ELSE 0 END)
                       + (CASE WHEN dl.extra_workout = 1 THEN 1 ELSE 0 END)
                END
             ), 0) AS c
             FROM daily_logs dl
             LEFT JOIN (
                SELECT log_id, COUNT(*) AS workout_count
                FROM daily_log_workouts
                GROUP BY log_id
             ) workouts ON workouts.log_id = dl.id
             WHERE dl.user_id = :user_id AND dl.log_date BETWEEN :start AND :end',
            [':user_id' => $userId, ':start' => $start, ':end' => $end]
        );
        $weeks = max(1.0, $days / 7);

        return $target > 0 ? ((float) ($row['c'] ?? 0) / ($target * $weeks)) * 100 : 0.0;
    }
    if ($key === 'calories_burned') {
        $row = db_fetch_one(
            $pdo,
            'SELECT COALESCE(SUM(COALESCE(training_calories_burned, 0)), 0) AS total
             FROM daily_logs WHERE user_id = :user_id AND log_date BETWEEN :start AND :end',
            [':user_id' => $userId, ':start' => $start, ':end' => $end]
        );

        return $target > 0 ? ((float) ($row['total'] ?? 0) / ($target * $days)) * 100 : 0.0;
    }
    if ($key === 'calories_consumed') {
        $rows = db_fetch_all(
            $pdo,
            'SELECT log_date, SUM(COALESCE(calories, 0)) AS total, COUNT(*) AS meal_count
             FROM photo_entries
             WHERE user_id = :user_id AND log_date BETWEEN :start AND :end
             GROUP BY log_date',
            [':user_id' => $userId, ':start' => $start, ':end' => $end]
        );
        $valid = 0;
        foreach ($rows as $row) {
            $total = (float) ($row['total'] ?? 0);
            if ((int) ($row['meal_count'] ?? 0) > 0 && $total <= $target) {
                $valid++;
            }
        }

        return ($valid / $days) * 100;
    }
    if (str_starts_with($key, 'habit:')) {
        $code = substr($key, 6);
        $row = db_fetch_one(
            $pdo,
            'SELECT COUNT(DISTINCT dl.log_date) AS c
             FROM daily_log_habits dlh
             JOIN daily_logs dl ON dl.id = dlh.log_id
             JOIN habit_definitions hd ON hd.id = dlh.habit_id
             WHERE dl.user_id = :user_id AND dl.log_date BETWEEN :start AND :end
               AND hd.code = :code AND dlh.value = 1',
            [':user_id' => $userId, ':start' => $start, ':end' => $end, ':code' => $code]
        );

        return ((float) ($row['c'] ?? 0) / $days) * 100;
    }
    if ($key === 'weight') {
        return is_numeric($snapshot['weight_progress'] ?? null) ? (float) $snapshot['weight_progress'] : 0.0;
    }
    if ($key === 'discipline') {
        $strikes = 0;
        $warnings = 0;
        $foundDatedEvents = false;
        foreach ((array) ($metric['weekly'] ?? []) as $week) {
            foreach ((array) ($week['failure_events'] ?? []) as $event) {
                $eventDate = (string) ($event['date'] ?? '');
                if ($eventDate >= $start && $eventDate <= $end) {
                    $strikes++;
                    $foundDatedEvents = true;
                }
            }
            foreach ((array) ($week['warning_events'] ?? []) as $event) {
                $eventDate = (string) ($event['date'] ?? '');
                if ($eventDate >= $start && $eventDate <= $end) {
                    $warnings++;
                    $foundDatedEvents = true;
                }
            }
        }
        if (!$foundDatedEvents && $start === metric_view_date_range($pdo, $view)[0]) {
            return (float) ($snapshot['discipline_score'] ?? 100);
        }

        return max(0.0, 100.0 - min(100.0, ($strikes * 10) + ($warnings * 3)));
    }

    return 0.0;
}
