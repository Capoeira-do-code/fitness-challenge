<?php

declare(strict_types=1);

function weekly_report_escape_pdf(string $text): string
{
    $text = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text) ?: $text;
    return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $text);
}

/** Small dependency-free PDF renderer for the scheduled server-side report. */
function weekly_report_render_pdf(array $lines): string
{
    $pages = array_chunk(array_values($lines), 28);
    if ($pages === []) {
        $pages = [['Weekly fitness report', 'No data available']];
    }
    $pageCount = count($pages);
    $fontObjectId = 3 + ($pageCount * 2);
    $pageObjectIds = [];
    for ($pageIndex = 0; $pageIndex < $pageCount; $pageIndex++) {
        $pageObjectIds[] = 3 + ($pageIndex * 2);
    }
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [' . implode(' ', array_map(static fn(int $id): string => $id . ' 0 R', $pageObjectIds)) . '] /Count ' . $pageCount . ' >>',
    ];
    foreach ($pages as $pageIndex => $pageLines) {
        $contentObjectId = 4 + ($pageIndex * 2);
        $content = "BT\n/F1 18 Tf\n50 790 Td\n";
        foreach ($pageLines as $lineIndex => $line) {
            if ($lineIndex > 0) {
                $content .= "0 -24 Td\n";
            }
            if ($lineIndex === 1 || $pageIndex > 0 && $lineIndex === 0) {
                $content .= "/F1 10 Tf\n";
            }
            $content .= '(' . weekly_report_escape_pdf((string) $line) . ") Tj\n";
        }
        $content .= "0 -28 Td\n/F1 8 Tf\n(Page " . ($pageIndex + 1) . ' / ' . $pageCount . ") Tj\nET\n";
        $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 '
            . $fontObjectId . ' 0 R >> >> /Contents ' . $contentObjectId . ' 0 R >>';
        $objects[] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "endstream";
    }
    $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    foreach (array_slice($offsets, 1) as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    return $pdf . "trailer << /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
}

function weekly_report_build(PDO $pdo, array $config, array $user, string $start, string $end): string
{
    $logs = db_fetch_one($pdo, 'SELECT COALESCE(SUM(steps),0) steps, COALESCE(SUM(distance_km),0) distance, COALESCE(SUM(training_calories_burned),0) burned, COALESCE(SUM(workout_done),0) workouts FROM daily_logs WHERE user_id = :user AND log_date BETWEEN :start AND :end', [
        ':user' => (int) $user['id'], ':start' => $start, ':end' => $end,
    ]) ?? [];
    $nutrition = nutrition_daily_summary($pdo, $user, $start, $end);
    $nutritionWithData = array_values(array_filter($nutrition, static fn(array $row): bool => !empty($row['has_meal_data'])));
    $consumed = array_sum(array_map(static fn(array $row): float => (float) ($row['consumed'] ?? 0), $nutritionWithData));
    $balances = array_values(array_filter(array_map(static fn(array $row): ?float => isset($row['balance']) ? (float) $row['balance'] : null, $nutritionWithData), static fn(?float $value): bool => $value !== null));
    $balance = array_sum($balances);
    $lines = [
        'Weekly fitness report',
        (string) $user['display_name'] . ' | ' . $start . ' - ' . $end,
        'Steps: ' . number_format((float) ($logs['steps'] ?? 0), 0, '.', ','),
        'Distance: ' . number_format((float) ($logs['distance'] ?? 0), 2) . ' km',
        'Workouts: ' . (int) ($logs['workouts'] ?? 0),
        'Calories consumed: ' . number_format($consumed, 0) . ' kcal',
        'Exercise calories: ' . number_format((float) ($logs['burned'] ?? 0), 0) . ' kcal',
        'Energy balance: ' . ($balances === [] ? 'Not available' : number_format($balance, 0) . ' kcal'),
    ];
    foreach (custom_metrics_for_user($pdo, (int) $user['id']) as $metric) {
        $metricTotal = db_fetch_one($pdo, 'SELECT COUNT(*) entries, AVG(value) average, MIN(value) minimum, MAX(value) maximum FROM custom_metric_entries WHERE metric_id = :metric AND user_id = :user AND entry_date BETWEEN :start AND :end', [
            ':metric' => (int) $metric['id'], ':user' => (int) $user['id'], ':start' => $start, ':end' => $end,
        ]) ?? [];
        if ((int) ($metricTotal['entries'] ?? 0) > 0) {
            $lines[] = (string) $metric['name'] . ': avg ' . number_format((float) $metricTotal['average'], 1)
                . ' ' . (string) $metric['unit'] . ' | range '
                . number_format((float) $metricTotal['minimum'], 1) . '-' . number_format((float) $metricTotal['maximum'], 1);
        }
    }
    foreach (list_habit_definitions($pdo, true, null, (int) $user['id']) as $habit) {
        $habitStats = db_fetch_one($pdo, 'SELECT COUNT(DISTINCT l.log_date) total_days, COUNT(DISTINCT CASE WHEN h.value = 1 THEN l.log_date END) completed_days
            FROM daily_logs l LEFT JOIN daily_log_habits h ON h.log_id = l.id
            LEFT JOIN habit_definitions d ON d.id = h.habit_id
            WHERE l.user_id = :user AND l.log_date BETWEEN :start AND :end AND d.code = :code', [
            ':user' => (int) $user['id'], ':start' => $start, ':end' => $end, ':code' => (string) $habit['code'],
        ]) ?? [];
        if ((int) ($habitStats['total_days'] ?? 0) > 0) {
            $rate = ((int) $habitStats['completed_days'] / max(1, (int) $habitStats['total_days'])) * 100;
            $lines[] = (string) $habit['label'] . ': ' . (int) $habitStats['completed_days'] . '/' . (int) $habitStats['total_days'] . ' days (' . number_format($rate, 0) . '%)';
        }
    }
    $goalMetric = compute_challenge_metrics($pdo, [$user], $start, $end);
    $goalMetric = array_values($goalMetric)[0] ?? [];
    $reportGoals = hydrate_user_goal_metric_targets(
        $pdo,
        list_goals($pdo, 'user', (int) $user['id']),
        (int) $user['id'],
        $start,
        $end,
        is_array($goalMetric) ? $goalMetric : []
    );
    foreach ($reportGoals as $goal) {
        if ((string) ($goal['status'] ?? '') === 'archived') {
            continue;
        }
        $pct = isset($goal['_weighted_progress_pct'])
            ? (float) $goal['_weighted_progress_pct']
            : goal_progress_percent_for_metric($goal, goal_progress_value_from_metric($goal, $goalMetric), $goalMetric);
        $lines[] = 'Goal · ' . (string) $goal['title'] . ': ' . number_format($pct, 0) . '%';
    }
    $reportDir = rtrim((string) $config['upload_dir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'reports';
    if (!is_dir($reportDir)) {
        mkdir($reportDir, 0775, true);
    }
    $relative = 'reports/weekly_' . (int) $user['id'] . '_' . str_replace('-', '', $start) . '.pdf';
    file_put_contents(rtrim((string) $config['upload_dir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relative, weekly_report_render_pdf($lines), LOCK_EX);
    return $relative;
}

function weekly_reports_run_due(PDO $pdo, array $config, array $telegramSettings): void
{
    foreach (db_fetch_all($pdo, 'SELECT * FROM users WHERE active = 1 AND weekly_report_enabled = 1') as $user) {
        $reportUserClock = $user;
        if (trim((string) ($user['weekly_report_tz'] ?? '')) !== '') {
            $reportUserClock['telegram_tz'] = (string) $user['weekly_report_tz'];
        }
        $now = telegram_user_now($reportUserClock);
        $day = (int) ($user['weekly_report_day'] ?? 1);
        $time = telegram_normalize_time((string) ($user['weekly_report_time'] ?? '09:00'));
        if ((int) $now['dow'] !== $day || strcmp((string) $now['hm'], $time) < 0) {
            continue;
        }
        if (telegram_in_quiet_hours((string) ($user['telegram_quiet_start'] ?? ''), (string) ($user['telegram_quiet_end'] ?? ''), (string) $now['hm'])) {
            continue;
        }
        $today = new DateTimeImmutable((string) $now['date']);
        $end = $today->modify('-1 day')->format('Y-m-d');
        $start = $today->modify('-7 days')->format('Y-m-d');
        $existing = db_fetch_one($pdo, 'SELECT * FROM weekly_report_runs WHERE user_id = :user AND period_start = :start AND period_end = :end', [
            ':user' => (int) $user['id'], ':start' => $start, ':end' => $end,
        ]);
        if ($existing !== null) {
            $status = (string) ($existing['status'] ?? '');
            $updatedAt = strtotime((string) ($existing['updated_at'] ?? '')) ?: 0;
            $ageSeconds = max(0, time() - $updatedAt);
            if (in_array($status, ['sent', 'ready'], true)) {
                continue;
            }
            if ($status === 'running' && $ageSeconds < 1800) {
                continue;
            }
            if ($status === 'failed' && ((int) ($existing['attempts'] ?? 0) >= 3 || $ageSeconds < 3600)) {
                continue;
            }
        }
        db_execute($pdo, 'INSERT INTO weekly_report_runs (user_id, period_start, period_end, status, attempts, created_at, updated_at) VALUES (:user,:start,:end,"running",1,:now,:now)
            ON CONFLICT(user_id,period_start,period_end) DO UPDATE SET status="running", attempts=weekly_report_runs.attempts+1, updated_at=excluded.updated_at', [
            ':user' => (int) $user['id'], ':start' => $start, ':end' => $end, ':now' => now_iso(),
        ]);
        try {
            $path = weekly_report_build($pdo, $config, $user, $start, $end);
            $absolute = rtrim((string) $config['upload_dir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
            $hasTelegram = trim((string) ($user['telegram_chat_id'] ?? '')) !== '';
            $sent = $hasTelegram
                && telegram_send_document($telegramSettings, (string) $user['telegram_chat_id'], $absolute, 'Weekly report · ' . $start . ' – ' . $end);
            create_user_notification(
                $pdo,
                (int) $user['id'],
                'weekly_report',
                'Your weekly report is ready',
                'Review your progress from ' . $start . ' to ' . $end . '.',
                'weekly_report:' . (int) $user['id'] . ':' . $start,
                ['report_start' => $start, 'report_end' => $end, 'report_path' => $path]
            );
            db_execute($pdo, 'UPDATE weekly_report_runs SET status = :status, file_path = :path, sent_at = :sent, updated_at = :now WHERE user_id = :user AND period_start = :start AND period_end = :end', [
                ':status' => $sent ? 'sent' : ($hasTelegram ? 'failed' : 'ready'), ':path' => $path, ':sent' => $sent ? now_iso() : null,
                ':now' => now_iso(), ':user' => (int) $user['id'], ':start' => $start, ':end' => $end,
            ]);
        } catch (Throwable $error) {
            db_execute($pdo, 'UPDATE weekly_report_runs SET status = "failed", error_message = :error, updated_at = :now WHERE user_id = :user AND period_start = :start AND period_end = :end', [
                ':error' => app_text_substr($error->getMessage(), 0, 500), ':now' => now_iso(), ':user' => (int) $user['id'], ':start' => $start, ':end' => $end,
            ]);
        }
    }
}
