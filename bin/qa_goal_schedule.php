#!/usr/bin/env php
<?php

declare(strict_types=1);

putenv('DB_PATH=:memory:');
putenv('SEED_PASSWORD=');
putenv('REQUEST_SCHEDULERS_ENABLED=0');

require dirname(__DIR__) . '/app/bootstrap.php';

$failures = [];
$check = static function (bool $condition, string $label) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . '  ' . $label . PHP_EOL;
    if (!$condition) {
        $failures[] = $label;
    }
};

$now = new DateTimeImmutable('2026-07-21 12:00:00');
$pastStart = new DateTimeImmutable('2026-07-20 08:00:00');
$pastDue = new DateTimeImmutable('2026-07-20 23:59:00');
$futureStart = new DateTimeImmutable('2026-07-24 08:00:00');
$futureDue = new DateTimeImmutable('2026-07-25 23:59:00');

$check(
    goal_schedule_error_key($pastStart, $pastDue, true, $now) === 'goals.due_in_past',
    'a new challenge whose deadline passed is rejected'
);
$check(
    goal_schedule_error_key($pastStart, $futureDue, true, $now) === null,
    'a historical start remains valid while the deadline is still open'
);
$check(
    goal_schedule_error_key($futureDue, $futureStart, true, $now) === 'goals.start_after_due',
    'start after deadline keeps its specific validation error'
);
$check(
    goal_schedule_error_key($pastStart, $pastDue, false, $now) === null,
    'an already expired challenge can still be edited without changing its schedule'
);
$check(
    goal_schedule_error_key(null, $pastDue, true, $now) === 'goals.due_in_past',
    'profile goal creation uses the same past-deadline validation'
);

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " schedule regression(s) failed.\n");
    exit(1);
}

echo "Goal schedule QA: all checks passed.\n";
