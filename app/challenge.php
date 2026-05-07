<?php

declare(strict_types=1);

function challenge_settings(PDO $pdo, array $config): array
{
    $row = db_fetch_one($pdo, 'SELECT * FROM challenge_settings WHERE id = 1');
    if ($row === null) {
        return [
            'challenge_name' => 'Fitness Challenge',
            'challenge_start' => $config['challenge_start'],
            'challenge_end' => $config['challenge_end'],
            'active' => 1,
            'deleted_at' => null,
        ];
    }

    return $row;
}

function challenge_is_active(array $settings): bool
{
    return (int) ($settings['active'] ?? 1) === 1 && empty($settings['deleted_at']);
}

function list_active_users(PDO $pdo): array
{
    return db_fetch_all($pdo, 'SELECT * FROM users WHERE active = 1 ORDER BY display_name ASC');
}

function find_user_by_id(array $users, int $userId): ?array
{
    foreach ($users as $user) {
        if ((int) $user['id'] === $userId) {
            return $user;
        }
    }

    return null;
}

function penalty_for_strike(int $strike): int
{
    if ($strike <= 4) {
        return 10;
    }
    if ($strike <= 6) {
        return 50;
    }
    if ($strike <= 8) {
        return 100;
    }
    if ($strike === 9) {
        return 200;
    }

    return 300;
}

function load_logs_by_user(PDO $pdo, string $startDate, string $endDate): array
{
    $rows = db_fetch_all(
        $pdo,
        'SELECT * FROM daily_logs WHERE log_date BETWEEN :start AND :end ORDER BY log_date ASC',
        [':start' => $startDate, ':end' => $endDate]
    );

    $result = [];
    foreach ($rows as $row) {
        $userId = (int) $row['user_id'];
        $date = (string) $row['log_date'];
        if (!isset($result[$userId])) {
            $result[$userId] = [];
        }
        $result[$userId][$date] = $row;
    }

    if ($rows !== []) {
        $logIds = [];
        foreach ($rows as $row) {
            $logId = (int) ($row['id'] ?? 0);
            if ($logId > 0) {
                $logIds[$logId] = true;
            }
        }
        if ($logIds !== []) {
            $countParams = [];
            $placeholders = [];
            foreach (array_keys($logIds) as $index => $logId) {
                $key = ':log_id_' . $index;
                $placeholders[] = $key;
                $countParams[$key] = $logId;
            }
            $workoutCounts = db_fetch_all(
                $pdo,
                'SELECT log_id, COUNT(*) AS total
                 FROM daily_log_workouts
                 WHERE log_id IN (' . implode(',', $placeholders) . ')
                 GROUP BY log_id',
                $countParams
            );
            $countsByLogId = [];
            foreach ($workoutCounts as $countRow) {
                $countsByLogId[(int) ($countRow['log_id'] ?? 0)] = max(0, (int) ($countRow['total'] ?? 0));
            }
            foreach ($result as &$logsByDate) {
                foreach ($logsByDate as &$log) {
                    $logId = (int) ($log['id'] ?? 0);
                    $rowCount = $countsByLogId[$logId] ?? 0;
                    $log['workout_entry_count'] = $rowCount > 0
                        ? $rowCount
                        : ((int) ($log['workout_done'] ?? 0) === 1 ? 1 : 0);
                }
                unset($log);
            }
            unset($logsByDate);
        }

        $habitRows = db_fetch_all(
            $pdo,
            'SELECT dl.user_id, dl.log_date, hd.code, dlh.value
             FROM daily_log_habits dlh
             JOIN daily_logs dl ON dl.id = dlh.log_id
             JOIN habit_definitions hd ON hd.id = dlh.habit_id
             WHERE dl.log_date BETWEEN :start AND :end',
            [':start' => $startDate, ':end' => $endDate]
        );
        foreach ($habitRows as $habitRow) {
            $userId = (int) $habitRow['user_id'];
            $date = (string) $habitRow['log_date'];
            if (!isset($result[$userId][$date])) {
                continue;
            }
            if (!isset($result[$userId][$date]['habits'])) {
                $result[$userId][$date]['habits'] = [];
            }
            $result[$userId][$date]['habits'][(string) $habitRow['code']] = (int) $habitRow['value'];
        }
    }

    return $result;
}

function day_sequence(DateTimeImmutable $start, DateTimeImmutable $end): array
{
    $days = [];
    $cursor = $start;
    while ($cursor <= $end) {
        $days[] = $cursor;
        $cursor = $cursor->modify('+1 day');
    }

    return $days;
}

function week_start_for(DateTimeImmutable $date): DateTimeImmutable
{
    return $date->modify('monday this week');
}

function week_sequence(DateTimeImmutable $start, DateTimeImmutable $end): array
{
    $weeks = [];
    $cursor = week_start_for($start);
    while ($cursor <= $end) {
        $weeks[] = $cursor;
        $cursor = $cursor->modify('+1 week');
    }

    return $weeks;
}

function mask_to_label(string $mask): string
{
    $enabled = [];

    foreach (str_split($mask) as $idx => $flag) {
        if ($flag === '1') {
            $enabled[] = t('weekday.' . $idx);
        }
    }

    if ($enabled === []) {
        return t('common.none');
    }

    return implode(' ', $enabled);
}

function is_approval_approved(array $approvalsByDate, string $date, string $type): bool
{
    return (($approvalsByDate[$date][$type] ?? '') === APPROVAL_STATUS_APPROVED);
}

function is_approval_pending(array $approvalsByDate, string $date, string $type): bool
{
    return (($approvalsByDate[$date][$type] ?? '') === APPROVAL_STATUS_PENDING);
}

function is_counted_workout(?array $log, array $approvalsByDate, string $date): bool
{
    if ($log === null || (int) ($log['workout_done'] ?? 0) !== 1) {
        return false;
    }

    if ((int) ($log['junk_food'] ?? 0) !== 1) {
        return true;
    }

    if ((int) ($log['extra_workout'] ?? 0) !== 1) {
        return false;
    }

    return is_approval_approved($approvalsByDate, $date, APPROVAL_TYPE_EXTRA_WORKOUT_OVERRIDE);
}

function counted_workout_total(?array $log, array $approvalsByDate, string $date): int
{
    if (!is_counted_workout($log, $approvalsByDate, $date)) {
        return 0;
    }

    return max(1, (int) ($log['workout_entry_count'] ?? 1));
}

function compute_user_metrics(
    array $user,
    array $logs,
    array $approvalsByDate,
    DateTimeImmutable $start,
    DateTimeImmutable $end,
    DateTimeImmutable $today
): array {
    $allDays = day_sequence($start, $end);
    $weeklyStarts = week_sequence($start, $end);

    $stepsRequired = 0;
    $stepsSuccess = 0;
    $workoutTarget = 0;
    $workoutSuccess = 0;

    $stepFailures = 0;
    $workoutFailures = 0;

    $strikes = 0;
    $totalPenalty = 0;
    $perfectWeekStreak = 0;

    $stepsSeries = [];
    $workoutSeries = [];
    $weightSeries = [];
    $firstWeight = null;
    $latestWeight = null;
    $skipStreak = 0;
    $maxSkipStreak = 0;
    $skipWarningEvents = 0;
    $totalSteps = 0;
    $totalKm = 0.0;
    $workoutCount = 0;
    $habitCounts = [];
    $primaryGoals = function_exists('user_primary_goals') ? user_primary_goals($user) : [[
        'type' => 'steps',
        'value' => (float) max(1, (int) ($user['step_goal'] ?? 0)),
    ]];
    $hasKmPrimaryGoal = false;
    foreach ($primaryGoals as $goalDef) {
        if ((string) ($goalDef['type'] ?? '') === 'km' && (float) ($goalDef['value'] ?? 0) > 0) {
            $hasKmPrimaryGoal = true;
            break;
        }
    }
    $primaryGoalType = (string) ($primaryGoals[0]['type'] ?? 'steps');
    $primaryGoalValue = (float) ($primaryGoals[0]['value'] ?? (float) ($user['step_goal'] ?? 0));
    $did_hit_primary_goals = static function (array $goals, ?array $log, int $countedWorkouts): bool {
        if ($goals === [] || $log === null) {
            return false;
        }

        $steps = (int) ($log['steps'] ?? 0);
        $km = (float) ($log['distance_km'] ?? 0);
        $workouts = (float) max(0, $countedWorkouts);
        foreach ($goals as $goal) {
            $type = (string) ($goal['type'] ?? '');
            $value = (float) ($goal['value'] ?? 0);
            if ($value <= 0) {
                return false;
            }

            $isMet = match ($type) {
                'steps' => (float) $steps >= $value,
                'km' => $km >= $value,
                'workouts' => $workouts >= $value,
                default => false,
            };
            if (!$isMet) {
                return false;
            }
        }

        return true;
    };

    foreach ($allDays as $day) {
        $date = $day->format('Y-m-d');
        $log = $logs[$date] ?? null;
        $daySteps = $log !== null ? (int) $log['steps'] : 0;
        $dayKm = $log !== null ? (float) ($log['distance_km'] ?? 0) : 0.0;
        $totalSteps += $daySteps;
        $totalKm += $dayKm;
        foreach (($log['habits'] ?? []) as $code => $habitValue) {
            if (is_array($habitValue)) {
                $habitValue = $habitValue['value'] ?? 0;
            }
            if ((int) $habitValue === 1) {
                $habitCounts[(string) $code] = ($habitCounts[(string) $code] ?? 0) + 1;
            }
        }

        $stepsSeries[] = [
            'date' => $date,
            'steps' => $daySteps,
            'goal' => (int) $user['step_goal'],
            'km' => $dayKm,
        ];

        if ($log !== null && $log['weight'] !== null && $log['weight'] !== '') {
            $weight = (float) $log['weight'];
            $weightSeries[] = ['date' => $date, 'weight' => $weight];
            if ($firstWeight === null) {
                $firstWeight = $weight;
            }
            $latestWeight = $weight;
        }

        $countedWorkoutTotal = counted_workout_total($log, $approvalsByDate, $date);
        $didWorkoutCounted = $countedWorkoutTotal > 0;
        $workoutSeries[] = [
            'date' => $date,
            'workouts' => $countedWorkoutTotal,
        ];
        if ($countedWorkoutTotal > 0) {
            $workoutCount += $countedWorkoutTotal;
        }
        $hitPrimaryGoals = $did_hit_primary_goals($primaryGoals, $log, $countedWorkoutTotal);
        $didSomething = $didWorkoutCounted || $hitPrimaryGoals;

        if ($didSomething) {
            $skipStreak = 0;
        } else {
            $skipStreak++;
            if ($skipStreak === 2) {
                $skipWarningEvents++;
            }
            $maxSkipStreak = max($maxSkipStreak, $skipStreak);
        }
    }

    $weekly = [];

    foreach ($weeklyStarts as $weekStart) {
        $weekEnd = $weekStart->modify('+6 days');
        $effectiveEnd = $weekEnd > $end ? $end : $weekEnd;
        $isComplete = $weekEnd <= $today;
        $weekPenalty = 0;
        $weekReduction = 0;

        $weekFailureEvents = [];
        $weekStepFailures = 0;
        $weekWorkoutFailures = 0;
        $weekCountedWorkouts = 0;
        $weekExcusedWorkouts = 0;
        $weekSkipStreak = 0;
        $weekSkipWarnings = 0;
        $weekSteps = 0;
        $weekKm = 0.0;
        $weekHabitCounts = [];
        $weekWorkoutTarget = 0;
        $weekWorkoutSuccess = 0;
        $weekStepRequired = 0;
        $weekStepSuccess = 0;
        $weekWarningEvents = [];

        foreach (day_sequence($weekStart, $effectiveEnd) as $day) {
            if ($day < $start || $day > $today) {
                continue;
            }

            $date = $day->format('Y-m-d');
            $log = $logs[$date] ?? null;
            $weekSteps += $log !== null ? (int) ($log['steps'] ?? 0) : 0;
            $weekKm += $log !== null ? (float) ($log['distance_km'] ?? 0) : 0.0;
            foreach (($log['habits'] ?? []) as $code => $habitValue) {
                if (is_array($habitValue)) {
                    $habitValue = $habitValue['value'] ?? 0;
                }
                if ((int) $habitValue === 1) {
                    $weekHabitCounts[(string) $code] = ($weekHabitCounts[(string) $code] ?? 0) + 1;
                }
            }
            $stepReason = trim((string) ($log['step_exception_reason'] ?? ''));
            $distanceReason = trim((string) ($log['distance_exception_reason'] ?? ''));
            $workoutReason = trim((string) ($log['workout_exception_reason'] ?? ''));

            $countedWorkoutTotal = counted_workout_total($log, $approvalsByDate, $date);
            $countedWorkout = $countedWorkoutTotal > 0;
            $hitPrimaryGoals = $did_hit_primary_goals($primaryGoals, $log, $countedWorkoutTotal);

            if ($countedWorkout || $hitPrimaryGoals) {
                $weekSkipStreak = 0;
            } else {
                $weekSkipStreak++;
                if ($weekSkipStreak === 2) {
                    $weekSkipWarnings++;
                    $weekWarningEvents[] = [
                        'date' => $date,
                        'reason' => 'warning',
                    ];
                }
            }

            if (mask_allows_day((string) $user['step_days_mask'], $date)) {
                $stepsRequired++;
                $weekStepRequired++;

                $stepExceptionApproved = $stepReason !== '' && is_approval_approved($approvalsByDate, $date, APPROVAL_TYPE_STEP_EXCEPTION);
                $distanceExceptionApproved = $hasKmPrimaryGoal
                    && $distanceReason !== ''
                    && is_approval_approved($approvalsByDate, $date, APPROVAL_TYPE_DISTANCE_EXCEPTION);
                $stepOk = $hitPrimaryGoals || $stepExceptionApproved || $distanceExceptionApproved;

                if ($stepOk) {
                    $stepsSuccess++;
                    $weekStepSuccess++;
                } else {
                    $stepFailures++;
                    $weekStepFailures++;
                    $weekFailureEvents[] = ['date' => $date, 'type' => 'steps'];
                }
            }

            if ($countedWorkout) {
                $weekCountedWorkouts += $countedWorkoutTotal;
            }

            $workoutExceptionApproved = $workoutReason !== '' && is_approval_approved($approvalsByDate, $date, APPROVAL_TYPE_WORKOUT_EXCEPTION);
            $isStrictWorkoutDay = (int) $user['workout_strict'] === 1
                && mask_allows_day((string) $user['workout_days_mask'], $date);
            if (
                $workoutExceptionApproved
                && !$countedWorkout
                && (((int) $user['workout_strict'] !== 1) || $isStrictWorkoutDay)
            ) {
                $weekExcusedWorkouts++;
            }

            if ($isStrictWorkoutDay) {
                $workoutTarget++;
                $weekWorkoutTarget++;
                $workoutOk = $countedWorkout || $workoutExceptionApproved;

                if ($workoutOk) {
                    // Compliance remains day-based; totals below use the actual counted workout rows.
                } else {
                    $workoutFailures++;
                    $weekWorkoutFailures++;
                    $weekFailureEvents[] = ['date' => $date, 'type' => 'workout'];
                }
            }
        }

        $weekWorkoutSuccess = $weekCountedWorkouts + $weekExcusedWorkouts;
        if ((int) $user['workout_strict'] !== 1) {
            $weekTarget = (int) $user['workout_target'];
            $weekWorkoutTarget = $weekTarget;
            if ($isComplete) {
                $workoutTarget += $weekTarget;
                $workoutSuccess += $weekWorkoutSuccess;

                $missing = max(0, $weekTarget - $weekCountedWorkouts - $weekExcusedWorkouts);
                $workoutFailures += $missing;
                $weekWorkoutFailures += $missing;
                for ($i = 0; $i < $missing; $i++) {
                    $weekFailureEvents[] = ['date' => $weekEnd->format('Y-m-d'), 'type' => 'workout'];
                }
            }
        } else {
            $workoutSuccess += $weekWorkoutSuccess;
        }

        usort(
            $weekFailureEvents,
            static function (array $a, array $b): int {
                if ($a['date'] === $b['date']) {
                    return strcmp($a['type'], $b['type']);
                }

                return strcmp($a['date'], $b['date']);
            }
        );

        $failureEventsDetailed = [];
        foreach ($weekFailureEvents as $event) {
            $strikes++;
            $penalty = penalty_for_strike($strikes);
            $totalPenalty += $penalty;
            $weekPenalty += $penalty;
            $failureEventsDetailed[] = [
                'date' => (string) ($event['date'] ?? ''),
                'reason' => (string) ($event['type'] ?? ''),
                'strike_number' => $strikes,
                'amount' => $penalty,
            ];
        }

        if ($isComplete) {
            if (count($weekFailureEvents) === 0) {
                $perfectWeekStreak++;
                if ($perfectWeekStreak === 2 && $strikes > 0) {
                    $strikes--;
                    $weekReduction = 1;
                    $perfectWeekStreak = 0;
                }
            } else {
                $perfectWeekStreak = 0;
            }
        }

        $weekly[] = [
            'week_start' => $weekStart->format('Y-m-d'),
            'week_end' => $weekEnd->format('Y-m-d'),
            'status' => $isComplete ? 'complete' : 'in_progress',
            'step_failures' => $weekStepFailures,
            'workout_failures' => $weekWorkoutFailures,
            'steps' => $weekSteps,
            'km' => round($weekKm, 2),
            'workouts' => $weekCountedWorkouts,
            'workout_target_week' => $weekWorkoutTarget,
            'workout_success_week' => $weekWorkoutSuccess,
            'step_days_required_week' => $weekStepRequired,
            'step_days_success_week' => $weekStepSuccess,
            'habit_counts' => $weekHabitCounts,
            'total_failures' => count($weekFailureEvents),
            'skip_warnings' => $weekSkipWarnings,
            'penalty' => $weekPenalty,
            'strike_reduction' => $weekReduction,
            'strikes_after_week' => $strikes,
            'failure_events' => $failureEventsDetailed,
            'warning_events' => $weekWarningEvents,
        ];
    }

    $stepCompletionPct = $stepsRequired > 0 ? round(($stepsSuccess / $stepsRequired) * 100, 1) : 0.0;
    $workoutSuccess = max($workoutSuccess, $workoutCount);
    $workoutCompletionPct = $workoutTarget > 0 ? round(($workoutSuccess / $workoutTarget) * 100, 1) : 0.0;

    $idealWeight = $user['ideal_weight'] !== null ? (float) $user['ideal_weight'] : null;
    $weightProgressPct = null;

    if ($idealWeight !== null && $firstWeight !== null && $latestWeight !== null) {
        $startGap = abs($firstWeight - $idealWeight);
        $currentGap = abs($latestWeight - $idealWeight);
        if ($startGap == 0.0) {
            $weightProgressPct = 100.0;
        } else {
            $weightProgressPct = round((($startGap - $currentGap) / $startGap) * 100, 1);
        }
    }

    $disciplinePenalty = min(100, ($strikes * 10) + ($skipWarningEvents * 3));
    $disciplineScore = max(0.0, 100.0 - $disciplinePenalty);
    $scoreComponents = function_exists('score_components_from_progress')
        ? score_components_from_progress($stepCompletionPct, $workoutCompletionPct, $disciplineScore, $weightProgressPct)
        : [
            'steps' => round($stepCompletionPct * 0.4, 2),
            'workouts' => round($workoutCompletionPct * 0.4, 2),
            'discipline' => round($disciplineScore * 0.2, 2),
        ];
    $score = function_exists('score_value_from_components')
        ? score_value_from_components($scoreComponents)
        : round(array_sum($scoreComponents), 1);

    return [
        'user' => $user,
        'steps_required' => $stepsRequired,
        'steps_success' => $stepsSuccess,
        'primary_goal_type' => $primaryGoalType,
        'primary_goal_value' => $primaryGoalValue,
        'primary_goals' => $primaryGoals,
        'total_steps' => $totalSteps,
        'total_km' => round($totalKm, 2),
        'habit_counts' => $habitCounts,
        'step_completion_pct' => $stepCompletionPct,
        'workout_target' => $workoutTarget,
        'workout_success' => $workoutSuccess,
        'workout_count' => $workoutCount,
        'workout_completion_pct' => $workoutCompletionPct,
        'step_failures' => $stepFailures,
        'workout_failures' => $workoutFailures,
        'total_failures' => $stepFailures + $workoutFailures,
        'current_strikes' => $strikes,
        'total_penalty' => $totalPenalty,
        'weekly' => $weekly,
        'steps_series' => $stepsSeries,
        'workout_series' => $workoutSeries,
        'weight_series' => $weightSeries,
        'first_weight' => $firstWeight,
        'latest_weight' => $latestWeight,
        'ideal_weight' => $idealWeight,
        'weight_progress_pct' => $weightProgressPct,
        'max_skip_streak' => $maxSkipStreak,
        'skip_warning_events' => $skipWarningEvents,
        'discipline_score' => round($disciplineScore, 1),
        'score_components' => $scoreComponents,
        'score' => $score,
    ];
}

function compute_challenge_metrics(PDO $pdo, array $users, string $startDate, string $endDate, ?string $todayDate = null): array
{
    $today = $todayDate !== null ? new DateTimeImmutable($todayDate) : new DateTimeImmutable('today');
    $start = new DateTimeImmutable($startDate);
    $configuredEnd = new DateTimeImmutable($endDate);
    $end = $today < $configuredEnd ? $today : $configuredEnd;

    if ($end < $start) {
        $end = $start;
    }

    $startKey = $start->format('Y-m-d');
    $endKey = $end->format('Y-m-d');
    $cacheKey = null;
    if (function_exists('app_cache_get') && function_exists('app_cache_set')) {
        $cacheKey = 'challenge_metrics:' . hash('sha256', json_encode([
            'users' => array_values(array_map(static fn(array $user): int => (int) ($user['id'] ?? 0), $users)),
            'start' => $startKey,
            'end' => $endKey,
            'today' => $today->format('Y-m-d'),
        ], JSON_UNESCAPED_SLASHES) ?: '');
        $cached = app_cache_get($cacheKey, 300);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $logsByUser = load_logs_by_user($pdo, $startKey, $endKey);
    $approvalsByUser = load_approval_status_by_user_date($pdo, $startKey, $endKey);

    $results = [];
    foreach ($users as $user) {
        $userId = (int) $user['id'];
        $userLogs = $logsByUser[$userId] ?? [];
        $userApprovals = $approvalsByUser[$userId] ?? [];
        $results[$userId] = compute_user_metrics($user, $userLogs, $userApprovals, $start, $end, $today);
    }

    uasort(
        $results,
        static function (array $a, array $b): int {
            $scoreOrder = $b['score'] <=> $a['score'];
            if ($scoreOrder !== 0) {
                return $scoreOrder;
            }

            $penaltyOrder = ((int) ($a['total_penalty'] ?? 0)) <=> ((int) ($b['total_penalty'] ?? 0));
            if ($penaltyOrder !== 0) {
                return $penaltyOrder;
            }

            return strcmp(
                strtolower((string) ($a['user']['display_name'] ?? '')),
                strtolower((string) ($b['user']['display_name'] ?? ''))
            );
        }
    );

    if ($cacheKey !== null) {
        app_cache_set($cacheKey, $results);
    }

    return $results;
}

function latest_week_summary(array $metrics): ?array
{
    $weeks = $metrics['weekly'] ?? [];
    if ($weeks === []) {
        return null;
    }

    return $weeks[count($weeks) - 1];
}

function week_starts_from_metrics(array $metric): array
{
    $starts = [];
    foreach (($metric['weekly'] ?? []) as $week) {
        $starts[] = (string) $week['week_start'];
    }

    return $starts;
}

function weekly_settlement_summary(array $metricsOrdered, string $selectedWeekStart): array
{
    $entries = [];
    $totalPenalty = 0;
    $isProvisional = false;

    foreach ($metricsOrdered as $metric) {
        foreach (($metric['weekly'] ?? []) as $week) {
            if ((string) $week['week_start'] !== $selectedWeekStart) {
                continue;
            }

            $entry = [
                'user_id' => (int) $metric['user']['id'],
                'display_name' => (string) $metric['user']['display_name'],
                'penalty' => (int) $week['penalty'],
                'step_failures' => (int) $week['step_failures'],
                'workout_failures' => (int) $week['workout_failures'],
                'skip_warnings' => (int) ($week['skip_warnings'] ?? 0),
                'status' => (string) $week['status'],
            ];
            $entries[] = $entry;

            $totalPenalty += $entry['penalty'];
            if ($entry['status'] === 'in_progress') {
                $isProvisional = true;
            }

            break;
        }
    }

    usort(
        $entries,
        static function (array $a, array $b): int {
            return $b['penalty'] <=> $a['penalty'];
        }
    );

    return [
        'week_start' => $selectedWeekStart,
        'entries' => $entries,
        'total_penalty' => $totalPenalty,
        'is_provisional' => $isProvisional,
    ];
}
