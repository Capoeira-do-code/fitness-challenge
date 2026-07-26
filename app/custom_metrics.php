<?php

declare(strict_types=1);

function custom_metrics_for_user(PDO $pdo, int $userId, bool $includeInactive = false): array
{
    return db_fetch_all(
        $pdo,
        'SELECT d.*,
                (SELECT e.value FROM custom_metric_entries e
                 WHERE e.metric_id = d.id AND e.user_id = d.owner_user_id
                 ORDER BY e.entry_date DESC LIMIT 1) AS latest_value,
                (SELECT e.entry_date FROM custom_metric_entries e
                 WHERE e.metric_id = d.id AND e.user_id = d.owner_user_id
                 ORDER BY e.entry_date DESC LIMIT 1) AS latest_date
         FROM custom_metric_definitions d
         WHERE d.owner_user_id = :user' . ($includeInactive ? '' : ' AND d.active = 1') . '
         ORDER BY d.active DESC, d.name COLLATE NOCASE',
        [':user' => $userId]
    );
}

function custom_metric_get(PDO $pdo, int $metricId, int $userId): ?array
{
    return db_fetch_one(
        $pdo,
        'SELECT * FROM custom_metric_definitions WHERE id = :id AND owner_user_id = :user',
        [':id' => $metricId, ':user' => $userId]
    );
}

function custom_metric_create(PDO $pdo, int $userId, array $input): array
{
    $name = trim((string) ($input['name'] ?? ''));
    $unit = trim((string) ($input['unit'] ?? ''));
    $frequency = in_array(($input['frequency'] ?? ''), ['daily', 'weekly', 'monthly'], true)
        ? (string) $input['frequency'] : 'daily';
    $direction = in_array(($input['direction'] ?? ''), ['increase', 'decrease', 'maintain'], true)
        ? (string) $input['direction'] : 'increase';
    $target = ($input['target_value'] ?? '') !== '' ? (float) $input['target_value'] : null;
    $color = preg_match('/^#[0-9a-f]{6}$/i', (string) ($input['color'] ?? '')) === 1
        ? strtolower((string) $input['color']) : '#18a999';
    if ($name === '' || mb_strlen($name) > 60 || mb_strlen($unit) > 20) {
        throw new InvalidArgumentException(t('metric.invalid'));
    }
    db_execute(
        $pdo,
        'INSERT INTO custom_metric_definitions
            (owner_user_id, name, unit, frequency, target_value, direction, color, icon, active, created_at, updated_at)
         VALUES (:user, :name, :unit, :frequency, :target, :direction, :color, :icon, 1, :now, :now)',
        [
            ':user' => $userId, ':name' => $name, ':unit' => $unit, ':frequency' => $frequency,
            ':target' => $target, ':direction' => $direction, ':color' => $color,
            ':icon' => trim((string) ($input['icon'] ?? 'chart')) ?: 'chart', ':now' => now_iso(),
        ]
    );
    $metric = custom_metric_get($pdo, (int) $pdo->lastInsertId(), $userId);
    if ($metric === null) {
        throw new RuntimeException('Metric could not be created.');
    }
    return $metric;
}

function custom_metric_save_value(PDO $pdo, int $metricId, int $userId, string $date, mixed $value, ?int $expectedVersion = null): array
{
    $metric = custom_metric_get($pdo, $metricId, $userId);
    if ($metric === null || (int) ($metric['active'] ?? 0) !== 1 || !is_numeric($value)) {
        throw new InvalidArgumentException(t('metric.invalid'));
    }
    $date = to_date($date);
    $existing = db_fetch_one(
        $pdo,
        'SELECT * FROM custom_metric_entries WHERE metric_id = :metric AND user_id = :user AND entry_date = :date',
        [':metric' => $metricId, ':user' => $userId, ':date' => $date]
    );
    if ($existing !== null && $expectedVersion !== null && (int) $existing['version'] !== $expectedVersion) {
        throw new RuntimeException('sync_conflict');
    }
    db_execute(
        $pdo,
        'INSERT INTO custom_metric_entries (metric_id, user_id, entry_date, value, version, created_at, updated_at)
         VALUES (:metric, :user, :date, :value, 1, :now, :now)
         ON CONFLICT(metric_id, user_id, entry_date) DO UPDATE SET
            value = excluded.value, version = custom_metric_entries.version + 1, updated_at = excluded.updated_at',
        [':metric' => $metricId, ':user' => $userId, ':date' => $date, ':value' => (float) $value, ':now' => now_iso()]
    );
    return db_fetch_one(
        $pdo,
        'SELECT * FROM custom_metric_entries WHERE metric_id = :metric AND user_id = :user AND entry_date = :date',
        [':metric' => $metricId, ':user' => $userId, ':date' => $date]
    ) ?? [];
}

function custom_metric_series(PDO $pdo, int $metricId, int $userId, string $from, string $to): array
{
    if (custom_metric_get($pdo, $metricId, $userId) === null) {
        return [];
    }
    return db_fetch_all(
        $pdo,
        'SELECT entry_date, value, version FROM custom_metric_entries
         WHERE metric_id = :metric AND user_id = :user AND entry_date BETWEEN :from AND :to
         ORDER BY entry_date',
        [':metric' => $metricId, ':user' => $userId, ':from' => to_date($from), ':to' => to_date($to)]
    );
}

function custom_metric_key(int $id): string
{
    return 'custom:' . $id;
}

