<?php

declare(strict_types=1);

function nutrition_activity_factor(string $level): float
{
    return match ($level) {
        'sedentary' => 1.2,
        'light' => 1.375,
        'active' => 1.725,
        'very_active' => 1.9,
        default => 1.55,
    };
}

function nutrition_tdee(array $user, ?float $weight = null, ?string $onDate = null): ?array
{
    if (($user['tdee_override'] ?? '') !== '' && (float) $user['tdee_override'] > 0) {
        return ['value' => round((float) $user['tdee_override']), 'estimated' => false, 'source' => 'manual'];
    }
    $height = (float) ($user['height_cm'] ?? 0);
    $weight = $weight ?? (float) ($user['ideal_weight'] ?? 0);
    $birthDate = trim((string) ($user['birth_date'] ?? ''));
    $sex = trim((string) ($user['tdee_sex'] ?? ''));
    if ($height <= 0 || $weight <= 0 || $birthDate === '' || !in_array($sex, ['female', 'male'], true)) {
        return null;
    }
    try {
        $age = (new DateTimeImmutable($birthDate))->diff(new DateTimeImmutable($onDate ?: 'today'))->y;
    } catch (Throwable) {
        return null;
    }
    $bmr = (10 * $weight) + (6.25 * $height) - (5 * $age) + ($sex === 'male' ? 5 : -161);
    return [
        'value' => round($bmr * nutrition_activity_factor((string) ($user['activity_level'] ?? 'moderate'))),
        'estimated' => true,
        'source' => 'mifflin',
    ];
}

function nutrition_latest_weight(PDO $pdo, int $userId): ?float
{
    $row = db_fetch_one(
        $pdo,
        'SELECT weight FROM daily_logs
         WHERE user_id = :user AND weight IS NOT NULL AND weight > 0
         ORDER BY log_date DESC, id DESC LIMIT 1',
        [':user' => $userId]
    );
    $weight = (float) ($row['weight'] ?? 0);
    return $weight > 0 ? $weight : null;
}

function nutrition_create_entry(PDO $pdo, int $userId, array $input, ?string $photoPath = null): array
{
    $date = to_date((string) ($input['entry_date'] ?? null));
    $mealType = in_array(($input['meal_type'] ?? ''), ['breakfast', 'lunch', 'dinner', 'snack', 'other'], true)
        ? (string) $input['meal_type'] : 'other';
    $numeric = static fn(string $key): ?float => ($input[$key] ?? '') !== '' ? max(0.0, (float) $input[$key]) : null;
    $calories = $numeric('calories') ?? 0.0;
    if ($calories <= 0 && trim((string) ($input['notes'] ?? '')) === '' && $photoPath === null) {
        throw new InvalidArgumentException(t('metric.invalid'));
    }
    db_execute(
        $pdo,
        'INSERT INTO nutrition_entries
            (user_id, entry_date, entry_time, meal_type, notes, photo_path, calories, protein_g, carbs_g, fat_g,
             fiber_g, sugar_g, sodium_mg, version, created_at, updated_at)
         VALUES (:user, :date, :time, :type, :notes, :photo, :calories, :protein, :carbs, :fat,
                 :fiber, :sugar, :sodium, 1, :now, :now)',
        [
            ':user' => $userId, ':date' => $date,
            ':time' => normalize_log_time($input['entry_time'] ?? '', (new DateTimeImmutable())->format('H:i')),
            ':type' => $mealType, ':notes' => trim((string) ($input['notes'] ?? '')), ':photo' => $photoPath,
            ':calories' => $calories, ':protein' => $numeric('protein_g'), ':carbs' => $numeric('carbs_g'),
            ':fat' => $numeric('fat_g'), ':fiber' => $numeric('fiber_g'), ':sugar' => $numeric('sugar_g'),
            ':sodium' => $numeric('sodium_mg'), ':now' => now_iso(),
        ]
    );
    return db_fetch_one($pdo, 'SELECT * FROM nutrition_entries WHERE id = :id AND user_id = :user', [
        ':id' => (int) $pdo->lastInsertId(), ':user' => $userId,
    ]) ?? [];
}

function nutrition_daily_summary(PDO $pdo, array $user, string $from, string $to): array
{
    $mealRows = db_fetch_all(
        $pdo,
        'SELECT n.entry_date, COALESCE(SUM(n.calories), 0) AS consumed,
                COALESCE(SUM(n.protein_g), 0) AS protein_g,
                COALESCE(SUM(n.carbs_g), 0) AS carbs_g,
                COALESCE(SUM(n.fat_g), 0) AS fat_g
         FROM nutrition_entries n
         WHERE n.user_id = :user AND n.entry_date BETWEEN :from AND :to
         GROUP BY n.entry_date ORDER BY n.entry_date',
        [':user' => (int) $user['id'], ':from' => to_date($from), ':to' => to_date($to)]
    );
    $mealsByDate = [];
    foreach ($mealRows as $mealRow) {
        $mealsByDate[(string) $mealRow['entry_date']] = $mealRow;
    }
    $logs = db_fetch_all(
        $pdo,
        'SELECT log_date, COALESCE(training_calories_burned, 0) AS exercise, weight
         FROM daily_logs WHERE user_id = :user AND log_date BETWEEN :from AND :to',
        [':user' => (int) $user['id'], ':from' => to_date($from), ':to' => to_date($to)]
    );
    $logsByDate = [];
    $latestWeight = nutrition_latest_weight($pdo, (int) $user['id']);
    foreach ($logs as $log) {
        $logsByDate[(string) $log['log_date']] = $log;
    }
    $rows = [];
    $cursor = new DateTimeImmutable(to_date($from));
    $lastDate = new DateTimeImmutable(to_date($to));
    while ($cursor <= $lastDate) {
        $date = $cursor->format('Y-m-d');
        $hasMealData = isset($mealsByDate[$date]);
        $row = $mealsByDate[$date] ?? [
            'entry_date' => $date,
            'consumed' => 0,
            'protein_g' => 0,
            'carbs_g' => 0,
            'fat_g' => 0,
        ];
        $log = $logsByDate[$date] ?? [];
        $tdee = nutrition_tdee($user, ($log['weight'] ?? '') !== '' ? (float) $log['weight'] : $latestWeight, $date);
        $row['has_meal_data'] = $hasMealData;
        $row['exercise'] = (float) ($log['exercise'] ?? 0);
        $row['tdee'] = $tdee['value'] ?? null;
        $row['estimated'] = $tdee['estimated'] ?? null;
        $row['balance'] = !$hasMealData || $tdee === null
            ? null
            : (float) $row['consumed'] - ((float) $tdee['value'] + $row['exercise']);
        $rows[] = $row;
        $cursor = $cursor->modify('+1 day');
    }
    return $rows;
}
