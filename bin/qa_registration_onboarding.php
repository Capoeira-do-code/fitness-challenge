#!/usr/bin/env php
<?php

declare(strict_types=1);

putenv('DB_PATH=:memory:');
putenv('SEED_PASSWORD=qa-registration-password');
putenv('REQUEST_SCHEDULERS_ENABLED=0');

require dirname(__DIR__) . '/app/bootstrap.php';

$failures = [];
$check = static function (bool $condition, string $label) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . '  ' . $label . PHP_EOL;
    if (!$condition) {
        $failures[] = $label;
    }
};

$admin = db_fetch_one($pdo, 'SELECT * FROM users WHERE role = "admin" ORDER BY id LIMIT 1');
$check($admin !== null, 'the QA database has an administrator');
$adminId = (int) ($admin['id'] ?? 0);

$serverOriginSnapshot = [
    'HTTP_HOST' => $_SERVER['HTTP_HOST'] ?? null,
    'HTTP_X_FORWARDED_HOST' => $_SERVER['HTTP_X_FORWARDED_HOST'] ?? null,
    'HTTP_X_FORWARDED_PROTO' => $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null,
    'HTTP_X_FORWARDED_PORT' => $_SERVER['HTTP_X_FORWARDED_PORT'] ?? null,
];
$_SERVER['HTTP_HOST'] = 'localhost:8080';
$_SERVER['HTTP_X_FORWARDED_HOST'] = 'qa-tunnel-8080.uks1.devtunnels.ms';
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
$_SERVER['HTTP_X_FORWARDED_PORT'] = '443';
set_app_setting_silent($pdo, 'app_base_url', 'http://localhost:8080', $adminId);
$check(request_app_base_url() === 'https://qa-tunnel-8080.uks1.devtunnels.ms', 'proxy headers resolve the public application origin');
$check(remember_detected_app_base_url($pdo, $adminId) === 'https://qa-tunnel-8080.uks1.devtunnels.ms', 'an administrator visit remembers the public origin for workers');
$check(integration_app_base_url($pdo) === 'https://qa-tunnel-8080.uks1.devtunnels.ms', 'Telegram replaces a stale localhost URL with the detected tunnel');
$check(registration_app_base_url($pdo) === 'https://qa-tunnel-8080.uks1.devtunnels.ms', 'registration links use the origin of the current request');
set_app_setting_silent($pdo, 'app_base_url', 'https://fitness.example.com', $adminId);
$check(integration_app_base_url($pdo) === 'https://fitness.example.com', 'a stable custom domain can explicitly override the detected tunnel for workers');
$check(registration_app_base_url($pdo) === 'https://qa-tunnel-8080.uks1.devtunnels.ms', 'registration still follows the browser origin when opened through a tunnel');
set_app_setting_silent($pdo, 'app_base_url', '', $adminId);
foreach ($serverOriginSnapshot as $serverKey => $serverValue) {
    if ($serverValue === null) {
        unset($_SERVER[$serverKey]);
    } else {
        $_SERVER[$serverKey] = $serverValue;
    }
}

$created = create_registration_invite($pdo, $adminId, 'QA one use', 7, 1);
$rawToken = (string) ($created['token'] ?? '');
$storedInvite = (array) ($created['invite'] ?? []);
$check((bool) preg_match('/^[a-f0-9]{64}$/', $rawToken), 'registration URLs use a high-entropy token');
$check((string) ($storedInvite['token_hash'] ?? '') === hash('sha256', $rawToken), 'only the token hash is stored');
$check(!str_contains(json_encode($storedInvite, JSON_THROW_ON_ERROR), $rawToken), 'the raw token is not persisted with the invitation');

$registered = register_user_with_invite($pdo, $rawToken, [
    'username' => 'qa.new.user',
    'display_name' => 'QA New User',
    'password' => 'StrongPass123!',
    'locale' => 'es',
]);
$registeredId = (int) ($registered['id'] ?? 0);
$check($registeredId > 0, 'an active invitation creates a user');
$check(onboarding_is_pending($registered), 'invited users start with onboarding pending');
$check((string) ($registered['onboarding_step'] ?? '') === 'goals', 'new setup starts on the goals step');
$check((string) ($registered['locale'] ?? '') === 'es', 'the selected registration language is persisted');
$check((int) ($registered['step_goal'] ?? -1) === 0, 'a step goal is not created implicitly');
$check((int) ($registered['workout_target'] ?? -1) === 0, 'a workout goal is not created implicitly');
$check((string) ($registered['primary_goal_type'] ?? '') === 'none', 'the primary goal is optional by default');
$check(user_primary_goals($registered) === [], 'a user can have no primary goals');
$check(date_input_to_iso('31/12/2026') === '2026-12-31', 'European calendar dates are converted to ISO storage');
$check(date_input_to_iso('31/02/2026') === null, 'invalid European calendar dates are rejected');
$check(date_input_to_iso('') === null, 'an optional blank deadline remains empty');
initialize_database($pdo, $config);
$check(list_user_teams($pdo, $registeredId) === [], 'schema initialization does not silently add a new user to the default team');
$usedInvite = registration_invite_from_token($pdo, $rawToken);
$check($usedInvite !== null && registration_invite_status($usedInvite) === 'exhausted', 'a single-use invitation is exhausted atomically');

$reuseRejected = false;
try {
    register_user_with_invite($pdo, $rawToken, [
        'username' => 'qa.reuse',
        'display_name' => 'QA Reuse',
        'password' => 'StrongPass123!',
        'locale' => 'en',
    ]);
} catch (InvalidArgumentException) {
    $reuseRejected = true;
}
$check($reuseRejected, 'an exhausted invitation cannot be reused');

$duplicateInvite = create_registration_invite($pdo, $adminId, 'QA duplicate', 7, 2);
$duplicateRejected = false;
try {
    register_user_with_invite($pdo, (string) $duplicateInvite['token'], [
        'username' => 'QA.NEW.USER',
        'display_name' => 'Duplicate',
        'password' => 'StrongPass123!',
        'locale' => 'en',
    ]);
} catch (InvalidArgumentException) {
    $duplicateRejected = true;
}
$check($duplicateRejected, 'usernames remain unique regardless of case');
$duplicateAfterFailure = registration_invite_from_token($pdo, (string) $duplicateInvite['token']);
$check((int) ($duplicateAfterFailure['used_count'] ?? -1) === 0, 'failed registration does not consume an invitation use');
$noTeamUser = register_user_with_invite($pdo, (string) $duplicateInvite['token'], [
    'username' => 'qa.no.team',
    'display_name' => 'QA No Team',
    'password' => 'StrongPass123!',
    'locale' => 'en',
]);
mark_user_onboarding_skipped($pdo, (int) $noTeamUser['id']);
complete_user_onboarding($pdo, (int) $noTeamUser['id']);
$check(list_user_teams($pdo, (int) $noTeamUser['id']) === [], 'setup can be completed without joining a team');
$skippedUser = db_fetch_one($pdo, 'SELECT * FROM users WHERE id = :id', [':id' => (int) $noTeamUser['id']]);
$check($skippedUser !== null && user_should_show_onboarding_prompt($skippedUser), 'skipping setup creates a Home reminder after completion');
dismiss_user_onboarding_prompt($pdo, (int) $noTeamUser['id']);
$dismissedUser = db_fetch_one($pdo, 'SELECT * FROM users WHERE id = :id', [':id' => (int) $noTeamUser['id']]);
$check($dismissedUser !== null && !user_should_show_onboarding_prompt($dismissedUser), 'the user can permanently hide the Home reminder');
restart_user_onboarding($pdo, (int) $noTeamUser['id']);
$restartedUser = db_fetch_one($pdo, 'SELECT * FROM users WHERE id = :id', [':id' => (int) $noTeamUser['id']]);
$check($restartedUser !== null && onboarding_is_pending($restartedUser), 'Settings can restart the setup flow');
$check((string) ($restartedUser['onboarding_step'] ?? '') === 'goals', 'a restarted setup begins on goals');
$check((int) ($restartedUser['onboarding_skipped'] ?? 1) === 0 && (int) ($restartedUser['onboarding_prompt_dismissed'] ?? 1) === 0, 'restarting clears the skipped reminder state');

$revocable = create_registration_invite($pdo, $adminId, 'QA revoke', 7, 1);
$revocableId = (int) (($revocable['invite']['id'] ?? 0));
$check(revoke_registration_invite($pdo, $revocableId, $adminId), 'an administrator can revoke an active invitation');
$revoked = registration_invite_from_token($pdo, (string) $revocable['token']);
$check($revoked !== null && registration_invite_status($revoked) === 'revoked', 'revoked invitations are rejected by status');

$expired = create_registration_invite($pdo, $adminId, 'QA expired', 1, 1);
db_execute($pdo, 'UPDATE registration_invites SET expires_at = :past WHERE id = :id', [
    ':past' => '2000-01-01 00:00:00',
    ':id' => (int) ($expired['invite']['id'] ?? 0),
]);
$expiredRow = registration_invite_from_token($pdo, (string) $expired['token']);
$check($expiredRow !== null && registration_invite_status($expiredRow) === 'expired', 'expired invitations are rejected by status');

set_user_onboarding_step($pdo, $registeredId, 'profile');
$resumable = db_fetch_one($pdo, 'SELECT onboarding_step FROM users WHERE id = :id', [':id' => $registeredId]);
$check((string) ($resumable['onboarding_step'] ?? '') === 'profile', 'setup progress is stored so the next login can resume it');

set_user_onboarding_step($pdo, $registeredId, 'telegram');
$telegramStep = db_fetch_one($pdo, 'SELECT onboarding_step FROM users WHERE id = :id', [':id' => $registeredId]);
$check((string) ($telegramStep['onboarding_step'] ?? '') === 'telegram', 'Telegram is a resumable setup step');
telegram_update_user_prefs($pdo, $registeredId, [
    'telegram_reminders_enabled' => 1,
    'telegram_reminder_time' => '19:45',
    'telegram_tz' => 'Europe/Madrid',
    'telegram_notify_duel' => 1,
    'telegram_notify_social' => 1,
]);
$telegramPrefs = db_fetch_one(
    $pdo,
    'SELECT telegram_reminders_enabled, telegram_reminder_time, telegram_tz,
            telegram_notify_duel, telegram_notify_streak, telegram_notify_social
     FROM users WHERE id = :id',
    [':id' => $registeredId]
);
$check(
    (int) ($telegramPrefs['telegram_reminders_enabled'] ?? 0) === 1
        && (string) ($telegramPrefs['telegram_reminder_time'] ?? '') === '19:45'
        && (string) ($telegramPrefs['telegram_tz'] ?? '') === 'Europe/Madrid'
        && (int) ($telegramPrefs['telegram_notify_duel'] ?? 0) === 1
        && (int) ($telegramPrefs['telegram_notify_streak'] ?? 1) === 0
        && (int) ($telegramPrefs['telegram_notify_social'] ?? 0) === 1,
    'setup stores the selected Telegram notification categories and schedule'
);

$now = now_iso();
db_execute(
    $pdo,
    'INSERT INTO teams (name, description, slug, join_mode, visibility, active, created_at, updated_at)
     VALUES ("QA Open", "Open onboarding team", "qa-open", "open", "visible", 1, :created_at, :updated_at)',
    [':created_at' => $now, ':updated_at' => $now]
);
$openTeam = db_fetch_one($pdo, 'SELECT * FROM teams WHERE slug = "qa-open"');
$joinableIds = array_map(static fn(array $team): int => (int) $team['id'], list_joinable_teams($pdo, $registeredId));
$check(in_array((int) ($openTeam['id'] ?? 0), $joinableIds, true), 'the setup lists visible teams the user has not joined');
$check(request_or_join_team($pdo, (int) ($openTeam['id'] ?? 0), $registeredId) === 'joined', 'an open team can be joined during setup');
$check(count(list_user_teams($pdo, $registeredId)) === 1, 'team choice creates exactly one active membership');

create_goal($pdo, [
    'scope' => 'user',
    'team_id' => null,
    'user_id' => $registeredId,
    'title' => 'QA first goal',
    'target_type' => 'km',
    'target_value' => 25,
    'current_value' => 0,
    'due_date' => (new DateTimeImmutable('+30 days'))->format('Y-m-d'),
], $registeredId);
$goalCount = db_fetch_one($pdo, 'SELECT COUNT(*) AS total FROM goals WHERE user_id = :user_id AND title = "QA first goal"', [':user_id' => $registeredId]);
$check((int) ($goalCount['total'] ?? 0) === 1, 'the setup can create a first personal challenge');

complete_user_onboarding($pdo, $registeredId);
$completed = db_fetch_one($pdo, 'SELECT * FROM users WHERE id = :id', [':id' => $registeredId]);
$check($completed !== null && !onboarding_is_pending($completed), 'finishing setup marks onboarding complete');
$check(trim((string) ($completed['onboarding_completed_at'] ?? '')) !== '', 'onboarding completion time is recorded');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " registration/onboarding regression(s) failed.\n");
    exit(1);
}

echo "Registration and onboarding QA: all checks passed.\n";
