#!/usr/bin/env php
<?php

declare(strict_types=1);

$qaRoot = rtrim(sys_get_temp_dir(), '/\\') . '/fitness_stabilization_' . bin2hex(random_bytes(6));
$qaDb = $qaRoot . '/fitness.sqlite';
$qaUploads = $qaRoot . '/uploads';
mkdir($qaUploads, 0775, true);
putenv('DB_PATH=' . $qaDb);
putenv('UPLOAD_DIR=' . $qaUploads);
putenv('SEED_PASSWORD=qa-only-password');
putenv('REQUEST_SCHEDULERS_ENABLED=0');

require dirname(__DIR__) . '/app/bootstrap.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    fwrite(STDOUT, "[ok] {$message}\n");
};

$probeSeedUserCount = static function (string $dbPath, string $uploadDir, string $password): int {
    $probe = 'require ' . var_export(dirname(__DIR__) . '/app/bootstrap.php', true)
        . '; echo (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();';
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, '-r', $probe],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__),
        [
            'DB_PATH' => $dbPath,
            'UPLOAD_DIR' => $uploadDir,
            'SEED_PASSWORD' => $password,
            'REQUEST_SCHEDULERS_ENABLED' => '0',
        ]
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not launch isolated seed probe.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException('Seed probe failed: ' . trim((string) $stderr));
    }

    return (int) trim((string) $stdout);
};

try {
    $requestLock = system_maintenance_acquire($config, false, true);
    $assert(is_resource($requestLock), 'web-style shared maintenance lock can be acquired');
    $GLOBALS['request_maintenance_handle'] = $requestLock;
    $GLOBALS['request_maintenance_exclusive'] = false;
    $upgradedLock = system_maintenance_acquire($config, true, true);
    $assert($upgradedLock === $requestLock, 'request maintenance lock upgrades in place for backup and restore');
    $competingRead = system_maintenance_acquire($config, false, true);
    $assert($competingRead === false, 'exclusive maintenance blocks another web request before SQLite opens');
    system_maintenance_release($upgradedLock);
    $competingRead = system_maintenance_acquire($config, false, true);
    $assert(is_resource($competingRead), 'maintenance lock downgrades after the exclusive operation');
    system_maintenance_release($competingRead);
    system_request_maintenance_release();

    $emptySeedCount = $probeSeedUserCount($qaRoot . '/seed-empty.sqlite', $qaRoot . '/seed-empty-uploads', '');
    $assert($emptySeedCount === 0, 'a fresh database creates no fixed seed users without SEED_PASSWORD');
    $explicitSeedCount = $probeSeedUserCount($qaRoot . '/seed-explicit.sqlite', $qaRoot . '/seed-explicit-uploads', 'explicit-qa-password');
    $assert($explicitSeedCount === 2, 'seed users are created only when SEED_PASSWORD is explicitly configured');

    $schemaGuardPdo = new PDO('sqlite::memory:');
    $schemaGuardPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $schemaGuardPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $schemaGuardPdo->exec('PRAGMA foreign_keys = ON');
    initialize_database($schemaGuardPdo, $config);
    $schemaGuardPdo->beginTransaction();
    friends_ensure_schema($schemaGuardPdo);
    $schemaGuardPdo->rollBack();
    $assert(
        db_fetch_one($schemaGuardPdo, "SELECT 1 FROM sqlite_master WHERE type='table' AND name='social_feed_likes'") === null,
        'social schema state is not cached when an outer transaction rolls its DDL back'
    );
    friends_ensure_schema($schemaGuardPdo);
    $assert(
        db_fetch_one($schemaGuardPdo, "SELECT 1 FROM sqlite_master WHERE type='table' AND name='social_feed_likes'") !== null
            && db_fetch_one($schemaGuardPdo, "SELECT 1 FROM sqlite_master WHERE type='trigger' AND name='trg_social_feed_cleanup_workout_delete'") === null,
        'social schema initializes once without marking a missing lazy workout source ready'
    );
    workouts_ensure_schema($schemaGuardPdo);
    friends_ensure_schema($schemaGuardPdo);
    $assert(
        db_fetch_one($schemaGuardPdo, "SELECT 1 FROM sqlite_master WHERE type='trigger' AND name='trg_social_feed_cleanup_workout_delete'") !== null,
        'social schema guard installs the workout cleanup trigger after its lazy table appears'
    );

    $expandedAchievementCodes = [
        'steps_2m_total', 'steps_5m_total', 'steps_70k_week', 'distance_1000k_total',
        'workouts_200_total', 'workouts_365_total', 'workouts_5_week', 'reading_50_total',
        'reading_100_total', 'team_2m_steps_total', 'team_5m_steps_total', 'team_500k_steps_week',
        'team_2500km_total', 'team_100_workouts_total', 'team_250_workouts_total',
    ];
    $achievementPlaceholders = implode(',', array_fill(0, count($expandedAchievementCodes), '?'));
    $achievementQuery = $pdo->prepare('SELECT COUNT(*) FROM achievements WHERE code IN (' . $achievementPlaceholders . ')');
    $achievementQuery->execute($expandedAchievementCodes);
    $seededExpandedAchievementCount = (int) $achievementQuery->fetchColumn();
    $achievementQuery->closeCursor();
    $assert($seededExpandedAchievementCount === count($expandedAchievementCodes), 'expanded achievement catalog seeds every new personal and team milestone');
    $achievementRuleQuery = $pdo->prepare(
        'SELECT COUNT(*) FROM achievement_rules ar JOIN achievements a ON a.id = ar.achievement_id WHERE ar.active = 1 AND a.code IN (' . $achievementPlaceholders . ')'
    );
    $achievementRuleQuery->execute($expandedAchievementCodes);
    $seededExpandedRuleCount = (int) $achievementRuleQuery->fetchColumn();
    $achievementRuleQuery->closeCursor();
    $assert($seededExpandedRuleCount === count($expandedAchievementCodes), 'expanded achievement milestones all have working automatic rules');
    $pausedAchievementId = create_manual_achievement(
        $pdo,
        'Paused QA achievement',
        'Visible only to administrators.',
        'user',
        1,
        'achievements/qa-custom.webp',
        '',
        'qa_paused_achievement',
        false,
        null,
        'star',
        ['en' => ['name' => 'Paused QA achievement', 'description' => 'Visible only to administrators.', 'reward_text' => '']]
    );
    $adminAchievementRows = list_achievements_for_admin($pdo);
    $pausedAdminAchievement = array_values(array_filter(
        $adminAchievementRows,
        static fn(array $row): bool => (int) ($row['id'] ?? 0) === $pausedAchievementId
    ));
    $assert($pausedAdminAchievement !== [] && (int) ($pausedAdminAchievement[0]['active'] ?? 1) === 0, 'admin achievement library keeps paused achievements visible and editable');
    $assert((string) ($pausedAdminAchievement[0]['image_path'] ?? '') === 'achievements/qa-custom.webp', 'custom achievement image references survive the admin read path');
    $challengePdo = new PDO('sqlite::memory:');
    $challengePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $challengePdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    initialize_database($challengePdo, $config);
    update_challenge_settings($challengePdo, 'First QA challenge', '2026-01-01', '2026-01-31', 1);
    db_execute(
        $challengePdo,
        'INSERT INTO daily_logs (user_id, log_date, steps, distance_km, workout_done, created_at, updated_at)
         VALUES (1, "2026-01-10", 12345, 8.5, 1, :created_at, :updated_at)',
        [':created_at' => now_iso(), ':updated_at' => now_iso()]
    );
    archive_challenge($challengePdo, 1);
    update_challenge_settings($challengePdo, 'Second QA challenge', '2026-02-01', '2026-02-28', 1);
    $challengeArchivesBeforeRestore = list_challenge_archives($challengePdo);
    $firstChallengeArchive = array_values(array_filter(
        $challengeArchivesBeforeRestore,
        static fn(array $row): bool => (string) ($row['challenge_name'] ?? '') === 'First QA challenge'
    ));
    $assert(count($firstChallengeArchive) === 1, 'challenge archive stores the previous active period');
    $assert((int) ($firstChallengeArchive[0]['summary']['steps'] ?? 0) === 12345, 'challenge archive summary reports the preserved period data');
    $restoredChallengeArchiveId = (int) ($firstChallengeArchive[0]['id'] ?? 0);
    $assert(reactivate_challenge($challengePdo, $restoredChallengeArchiveId, 1), 'previous challenge can be restored');
    $challengeArchivesAfterRestore = list_challenge_archives($challengePdo);
    $restoredChallengeArchive = array_values(array_filter(
        $challengeArchivesAfterRestore,
        static fn(array $row): bool => (int) ($row['id'] ?? 0) === $restoredChallengeArchiveId
    ));
    $restoredChallengeSettings = challenge_settings($challengePdo, $config);
    $assert((string) ($restoredChallengeSettings['challenge_name'] ?? '') === 'First QA challenge', 'restored challenge becomes the active range');
    $assert(count($challengeArchivesAfterRestore) === 2, 'restoring preserves the selected snapshot and archives the displaced challenge');
    $assert((int) ($restoredChallengeArchive[0]['restore_count'] ?? 0) === 1, 'challenge archive records restoration history instead of being deleted');

    xp_set_action_amounts($challengePdo, ['daily_log' => -5, 'workout' => 999999], 1);
    $qaXpAmounts = xp_action_amounts($challengePdo);
    $assert((int) $qaXpAmounts['daily_log'] === 0 && (int) $qaXpAmounts['workout'] === 100000, 'admin XP rewards are clamped to safe supported values');
    $assert(xp_adjust($challengePdo, 1, 75, 'QA manual grant', 2) === 75, 'manual XP can be granted from admin');
    $assert(xp_adjust($challengePdo, 1, -1000, 'QA clamped removal', 2) === -75, 'manual XP removal never drops a user below zero');
    $assert(xp_adjust($challengePdo, 99999, 20, 'Invalid user', 2) === 0, 'manual XP rejects an unknown user');
    $qaXpEvents = xp_recent_events($challengePdo, 10);
    $qaXpGrant = array_values(array_filter($qaXpEvents, static fn(array $row): bool => (string) ($row['note'] ?? '') === 'QA manual grant'));
    $assert(count($qaXpGrant) === 1 && (int) ($qaXpGrant[0]['actor_user_id'] ?? 0) === 2, 'XP ledger preserves the manual note and administrator');
    $qaXpUsers = xp_admin_user_rows($challengePdo, db_fetch_all($challengePdo, 'SELECT * FROM users ORDER BY id'));
    $assert(count($qaXpUsers) === 2 && isset($qaXpUsers[0]['progress_pct'], $qaXpUsers[0]['xp_to_next']), 'admin XP progression is produced in one complete user view');

    $phpTelegramSecret = '123456:php-qa-secret';
    $phpTelegramError = telegram_redact_secrets(
        'network failure at https://api.telegram.org/bot' . $phpTelegramSecret . '/getMe',
        $phpTelegramSecret
    );
    $assert(!str_contains($phpTelegramError, $phpTelegramSecret), 'PHP Telegram errors redact the bot token');

    set_app_setting_silent($pdo, 'qa_snapshot_marker', 'before');
    file_put_contents($qaUploads . '/marker.txt', 'before');
    $walPath = $qaDb . '-wal';
    $assert(is_file($walPath) && (int) filesize($walPath) > 0, 'backup fixture includes committed data still represented in WAL');

    $backup = create_system_backup($pdo, $config, 'manual', null);
    $archive = system_backup_absolute_path($config, (string) ($backup['file_path'] ?? ''));
    $assert(is_string($archive) && is_file($archive), 'backup archive is created');
    $manifest = validate_system_backup_archive((string) $archive);
    $assert((int) ($manifest['version'] ?? 0) === 2, 'backup manifest v2 is emitted');
    $assert((string) ($manifest['db']['integrity'] ?? '') === 'ok', 'snapshot integrity is recorded');
    $backupIdForVerification = (int) ($backup['id'] ?? 0);
    mark_system_backup_restore_result($pdo, $backupIdForVerification, 'created', 1);
    validate_system_backup_archive((string) $archive);
    mark_system_backup_restore_result($pdo, $backupIdForVerification, 'verified', 1);
    $verifiedBackupRow = fetch_system_backup($pdo, $backupIdForVerification);
    $assert((string) ($verifiedBackupRow['status'] ?? '') === 'verified', 'an existing backup can be verified without restoring it');
    $assert(system_backup_absolute_path($config, 'fitness.sqlite') === null, 'backup metadata cannot resolve to the live database');
    $assert(system_backup_absolute_path($config, 'backups/../fitness.sqlite') === null, 'backup metadata path traversal is rejected');

    $legacyArchive = null;
    if (class_exists('ZipArchive') && str_ends_with(strtolower((string) $archive), '.zip')) {
        $sourceZip = new ZipArchive();
        if ($sourceZip->open((string) $archive) !== true) {
            throw new RuntimeException('Could not open v2 backup fixture.');
        }
        $legacyDb = $sourceZip->getFromName('fitness.sqlite');
        $legacyUpload = $sourceZip->getFromName('uploads/marker.txt');
        $sourceZip->close();
        if (!is_string($legacyDb) || !is_string($legacyUpload)) {
            throw new RuntimeException('Could not read v1 backup fixture data.');
        }
        $legacyArchive = $qaRoot . '/legacy-v1.zip';
        $legacyManifest = [
            'version' => 1,
            'created_at' => now_iso(),
            'trigger' => 'manual',
            'scope' => 'db_uploads',
            'db' => [
                'path' => 'fitness.sqlite',
                'size_bytes' => strlen($legacyDb),
                'sha256' => hash('sha256', $legacyDb),
            ],
            'uploads' => [[
                'path' => 'uploads/marker.txt',
                'size_bytes' => strlen($legacyUpload),
                'sha256' => hash('sha256', $legacyUpload),
            ]],
        ];
        $legacyZip = new ZipArchive();
        $legacyZip->open($legacyArchive, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $legacyZip->addFromString('fitness.sqlite', $legacyDb);
        $legacyZip->addFromString('uploads/marker.txt', $legacyUpload);
        $legacyZip->addFromString('manifest.json', json_encode($legacyManifest, JSON_UNESCAPED_SLASHES));
        $legacyZip->close();
        $validatedLegacyManifest = validate_system_backup_archive($legacyArchive);
        $assert((int) ($validatedLegacyManifest['version'] ?? 0) === 1, 'legacy manifest v1 remains verifiable');

        $missingChecksumArchive = $qaRoot . '/missing-checksum-v2.zip';
        $missingChecksumZip = new ZipArchive();
        $missingChecksumZip->open($missingChecksumArchive, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $missingChecksumZip->addFromString('fitness.sqlite', $legacyDb);
        $missingChecksumZip->addFromString('manifest.json', json_encode([
            'version' => 2,
            'db' => [
                'path' => 'fitness.sqlite',
                'size_bytes' => strlen($legacyDb),
                'integrity' => 'ok',
                'user_version' => 0,
                'table_count' => 1,
            ],
            'upload_count' => 0,
            'upload_bytes' => 0,
            'uploads' => [],
        ], JSON_UNESCAPED_SLASHES));
        $missingChecksumZip->close();
        $missingChecksumRejected = false;
        try {
            validate_system_backup_archive($missingChecksumArchive);
        } catch (RuntimeException) {
            $missingChecksumRejected = true;
        }
        $assert($missingChecksumRejected, 'backup manifest v2 requires cryptographic checksums');
    }

    set_app_setting_silent($pdo, 'qa_snapshot_marker', 'after');
    file_put_contents($qaUploads . '/marker.txt', 'after');
    restore_system_backup_archive($pdo, $config, (string) $archive);
    $pdo = db_connect($config);
    $GLOBALS['pdo'] = $pdo;
    $assert(app_setting($pdo, 'qa_snapshot_marker', '') === 'before', 'database restore returns to snapshot state');
    $assert(file_get_contents($qaUploads . '/marker.txt') === 'before', 'upload restore returns to snapshot state');
    $assert(system_backup_assert_sqlite_integrity($qaDb)['integrity'] === 'ok', 'restored database passes integrity_check');

    if (is_string($legacyArchive)) {
        set_app_setting_silent($pdo, 'qa_snapshot_marker', 'after-v1');
        file_put_contents($qaUploads . '/marker.txt', 'after-v1');
        restore_system_backup_archive($pdo, $config, $legacyArchive);
        $pdo = db_connect($config);
        $GLOBALS['pdo'] = $pdo;
        $assert(app_setting($pdo, 'qa_snapshot_marker', '') === 'before', 'legacy v1 database can be restored');
        $assert(file_get_contents($qaUploads . '/marker.txt') === 'before', 'legacy v1 uploads can be restored');
    }

    if (class_exists('ZipArchive')) {
        $unsafeArchive = $qaRoot . '/unsafe.zip';
        $zip = new ZipArchive();
        $zip->open($unsafeArchive, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('../escape.txt', 'unsafe');
        $zip->close();
        $rejected = false;
        try {
            validate_system_backup_archive($unsafeArchive);
        } catch (RuntimeException) {
            $rejected = true;
        }
        $assert($rejected, 'unsafe archive paths are rejected');
        $assert(!file_exists(dirname($qaRoot) . '/escape.txt'), 'unsafe archive cannot escape its workspace');

        $invalidDbArchive = $qaRoot . '/invalid-db.zip';
        $zip = new ZipArchive();
        $zip->open($invalidDbArchive, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('fitness.sqlite', 'not a sqlite database');
        $zip->addFromString('manifest.json', json_encode([
            'version' => 2,
            'db' => ['path' => 'fitness.sqlite', 'size_bytes' => 21, 'sha256' => hash('sha256', 'not a sqlite database')],
            'uploads' => [],
        ], JSON_UNESCAPED_SLASHES));
        $zip->close();
        $invalidRejected = false;
        try {
            validate_system_backup_archive($invalidDbArchive);
        } catch (Throwable) {
            $invalidRejected = true;
        }
        $assert($invalidRejected, 'corrupt SQLite data is rejected even with a matching checksum');

        $incompatibleDb = $qaRoot . '/incompatible.sqlite';
        $incompatiblePdo = new PDO('sqlite:' . $incompatibleDb);
        $incompatiblePdo->exec('CREATE TABLE unrelated (id INTEGER PRIMARY KEY)');
        $incompatiblePdo = null;
        $rollbackArchive = $qaRoot . '/rollback-test.zip';
        $zip = new ZipArchive();
        $zip->open($rollbackArchive, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFile($incompatibleDb, 'fitness.sqlite');
        $zip->addFromString('manifest.json', json_encode([
            'version' => 2,
            'db' => [
                'path' => 'fitness.sqlite',
                'size_bytes' => filesize($incompatibleDb),
                'sha256' => hash_file('sha256', $incompatibleDb),
            ],
            'uploads' => [],
        ], JSON_UNESCAPED_SLASHES));
        $zip->close();
        $rollbackTriggered = false;
        try {
            restore_system_backup_archive($pdo, $config, $rollbackArchive);
        } catch (RuntimeException) {
            $rollbackTriggered = true;
        }
        $pdo = db_connect($config);
        $GLOBALS['pdo'] = $pdo;
        $assert($rollbackTriggered, 'post-restore health failure triggers rollback');
        $assert(app_setting($pdo, 'qa_snapshot_marker', '') === 'before', 'rollback preserves the pre-restore database');
        $assert(file_get_contents($qaUploads . '/marker.txt') === 'before', 'rollback preserves the pre-restore uploads');
    }

    workouts_ensure_schema($pdo);
    squads_ensure_schema($pdo);
    $users = db_fetch_all($pdo, 'SELECT * FROM users ORDER BY id LIMIT 2');
    $assert(count($users) === 2, 'competition fixture has two users');
    $firstUserId = (int) $users[0]['id'];
    $secondUserId = (int) $users[1]['id'];
    $onboardingChallengeId = create_goal($pdo, [
        'scope' => 'user',
        'team_id' => null,
        'user_id' => $firstUserId,
        'title' => 'QA onboarding challenge',
        'target_type' => 'steps',
        'target_value' => 12000,
        'current_value' => 0,
        'due_date' => null,
        'metric_targets' => [
            ['metric_key' => 'steps', 'target_value' => 12000, 'weight_percent' => 70],
            ['metric_key' => 'workouts', 'target_value' => 3, 'weight_percent' => 30],
        ],
    ], $firstUserId);
    $onboardingChallengeTargets = goal_metric_targets($pdo, $onboardingChallengeId);
    $assert(
        count($onboardingChallengeTargets) === 2
            && (int) (db_fetch_one($pdo, 'SELECT user_id FROM goals WHERE id = :id', [':id' => $onboardingChallengeId])['user_id'] ?? 0) === $firstUserId,
        'onboarding Step 5 creates a weighted challenge through goals.user_id'
    );
    delete_goal($pdo, $onboardingChallengeId, $firstUserId);
    $qaGoogleMediaKey = 'qa-google-media-key-123456';
    $qaYoutubeMediaKey = 'qa-youtube-media-key-654321';
    media_search_update_credentials($pdo, [
        'google_api_key' => $qaGoogleMediaKey,
        'google_cx' => 'qa-search-engine-cx',
        'youtube_api_key' => $qaYoutubeMediaKey,
    ], $firstUserId);
    $qaMediaConfig = media_search_effective_config($pdo, $config);
    $qaMediaStatus = media_search_credentials_status($pdo, $config);
    $assert(
        (string) ($qaMediaConfig['media_search_google_api_key'] ?? '') === $qaGoogleMediaKey
            && (string) ($qaMediaConfig['media_search_google_cx'] ?? '') === 'qa-search-engine-cx'
            && (string) ($qaMediaConfig['media_search_youtube_api_key'] ?? '') === $qaYoutubeMediaKey
            && !empty($qaMediaStatus['google_ready'])
            && !empty($qaMediaStatus['youtube_ready']),
        'admin media credentials configure Google Images and YouTube providers'
    );
    media_search_update_credentials($pdo, ['google_api_key' => '', 'google_cx' => '', 'youtube_api_key' => ''], $firstUserId);
    $qaMediaConfigAfterBlankSave = media_search_effective_config($pdo, $config);
    $assert(
        (string) ($qaMediaConfigAfterBlankSave['media_search_google_api_key'] ?? '') === $qaGoogleMediaKey
            && (string) ($qaMediaConfigAfterBlankSave['media_search_youtube_api_key'] ?? '') === $qaYoutubeMediaKey,
        'blank admin media credential fields preserve existing secrets'
    );
    $qaMediaAudit = db_fetch_one($pdo, 'SELECT after_json FROM audit_logs WHERE action = "media_search_credentials_updated" ORDER BY id DESC LIMIT 1');
    $qaMediaAuditJson = (string) ($qaMediaAudit['after_json'] ?? '');
    $assert(
        !str_contains($qaMediaAuditJson, $qaGoogleMediaKey)
            && !str_contains($qaMediaAuditJson, $qaYoutubeMediaKey)
            && str_contains($qaMediaAuditJson, 'google_key'),
        'media provider audit logs record status without exposing API keys'
    );
    $quoteLimitRejected = false;
    try {
        create_motivational_quote($pdo, str_repeat('a', 281), $firstUserId, 'any');
    } catch (InvalidArgumentException) {
        $quoteLimitRejected = true;
    }
    $assert($quoteLimitRejected, 'motivational quotes enforce the 280 character limit on the server');
    $qaQuoteId = create_motivational_quote($pdo, 'QA motivation', $firstUserId, 'es');
    $qaQuote = db_fetch_one($pdo, 'SELECT quote_text, locale, active FROM motivational_quotes WHERE id = :id', [':id' => $qaQuoteId]);
    $assert($qaQuote !== null && (string) $qaQuote['locale'] === 'es' && (int) $qaQuote['active'] === 1, 'motivational quote creation preserves language and active state');
    update_motivational_quote($pdo, $qaQuoteId, 'QA motivation updated', 'it', false, $firstUserId);
    $qaQuote = db_fetch_one($pdo, 'SELECT quote_text, locale, active FROM motivational_quotes WHERE id = :id', [':id' => $qaQuoteId]);
    $assert($qaQuote !== null && (string) $qaQuote['locale'] === 'it' && (int) $qaQuote['active'] === 0, 'motivational quote editing can pause and retag a quote');
    delete_motivational_quote($pdo, $qaQuoteId, $firstUserId);
    $assert(db_fetch_one($pdo, 'SELECT id FROM motivational_quotes WHERE id = :id', [':id' => $qaQuoteId]) === null, 'motivational quote deletion removes the selected quote');
    $spanishSeedHabits = list_habit_definitions($pdo, false, 'es');
    $spanishWalkHabit = array_values(array_filter($spanishSeedHabits, static fn(array $habit): bool => (string) ($habit['code'] ?? '') === 'morning_walk'))[0] ?? null;
    $assert((string) ($spanishWalkHabit['label'] ?? '') === 'Caminar / correr', 'seeded habit names resolve in the selected language');
    save_habit_definition($pdo, null, 'qa_hydration', 'Hydration', true, 95, $firstUserId, [
        'en' => ['label' => 'Hydration'],
        'es' => ['label' => 'Hidratacion'],
        'it' => ['label' => 'Idratazione'],
    ]);
    $qaHabit = db_fetch_one($pdo, 'SELECT id, code FROM habit_definitions WHERE code = :code', [':code' => 'qa_hydration']);
    $qaHabitId = (int) ($qaHabit['id'] ?? 0);
    $qaHabitTranslations = fetch_habit_translations($pdo, [$qaHabitId]);
    $assert(count((array) ($qaHabitTranslations[$qaHabitId] ?? [])) === 3, 'habit creation stores every submitted language');
    $spanishQaHabits = list_habit_definitions($pdo, false, 'es');
    $spanishQaHabit = array_values(array_filter($spanishQaHabits, static fn(array $habit): bool => (string) ($habit['code'] ?? '') === 'qa_hydration'))[0] ?? null;
    $assert((string) ($spanishQaHabit['label'] ?? '') === 'Hidratacion', 'habit lists expose the localized name used across the app');
    save_habit_definition($pdo, $qaHabitId, 'changed_code', 'Hydration updated', true, 96, $firstUserId, [
        'en' => ['label' => 'Hydration updated'],
        'es' => ['label' => 'Hidratacion actualizada'],
        'it' => ['label' => ''],
    ]);
    $qaHabitAfterUpdate = db_fetch_one($pdo, 'SELECT code FROM habit_definitions WHERE id = :id', [':id' => $qaHabitId]);
    $assert((string) ($qaHabitAfterUpdate['code'] ?? '') === 'qa_hydration', 'habit codes remain stable after creation');
    $italianQaHabits = list_habit_definitions($pdo, false, 'it');
    $italianQaHabit = array_values(array_filter($italianQaHabits, static fn(array $habit): bool => (string) ($habit['code'] ?? '') === 'qa_hydration'))[0] ?? null;
    $assert((string) ($italianQaHabit['label'] ?? '') === 'Hydration updated', 'missing habit translations fall back to the English name');
    db_execute($pdo, 'DELETE FROM habit_definitions WHERE id = :id', [':id' => $qaHabitId]);
    $qaWorkoutTypeId = save_workout_type_if_needed($pdo, 'QA Trail walk', $firstUserId, [
        'en' => ['name' => 'QA Trail walk'],
        'es' => ['name' => 'Caminata de sendero QA'],
        'it' => ['name' => 'Camminata su sentiero QA'],
    ]);
    $assert($qaWorkoutTypeId !== null && $qaWorkoutTypeId > 0, 'workout type creation accepts multilingual names');
    $qaWorkoutTypeTranslations = fetch_workout_type_translations($pdo, [$qaWorkoutTypeId]);
    $assert(count((array) ($qaWorkoutTypeTranslations[$qaWorkoutTypeId] ?? [])) === 3, 'workout type creation stores every submitted language');
    $spanishQaWorkoutTypes = list_workout_types($pdo, false, 'es');
    $spanishQaWorkoutType = array_values(array_filter($spanishQaWorkoutTypes, static fn(array $type): bool => (int) ($type['id'] ?? 0) === $qaWorkoutTypeId))[0] ?? null;
    $assert((string) ($spanishQaWorkoutType['name'] ?? '') === 'Caminata de sendero QA', 'workout type lists expose the selected language across the app');
    save_workout_type_field($pdo, $qaWorkoutTypeId, null, 'Elevation gain', 'number', '', true, true, 1, $firstUserId, [
        'en' => ['label' => 'Elevation gain'],
        'es' => ['label' => 'Desnivel positivo'],
        'it' => ['label' => 'Dislivello positivo'],
    ]);
    $qaWorkoutField = db_fetch_one($pdo, 'SELECT id FROM workout_type_fields WHERE workout_type_id = :type_id ORDER BY id DESC LIMIT 1', [':type_id' => $qaWorkoutTypeId]);
    $qaWorkoutFieldId = (int) ($qaWorkoutField['id'] ?? 0);
    $spanishQaWorkoutFields = list_workout_type_fields($pdo, $qaWorkoutTypeId, false, 'es');
    $assert($qaWorkoutFieldId > 0 && (string) ($spanishQaWorkoutFields[0]['label'] ?? '') === 'Desnivel positivo', 'custom workout fields use their localized label');
    rename_workout_type($pdo, $qaWorkoutTypeId, 'QA Trail walk updated', true, $firstUserId, [
        'en' => ['name' => 'QA Trail walk updated'],
        'es' => ['name' => 'Caminata de sendero QA actualizada'],
        'it' => ['name' => ''],
    ]);
    $italianQaWorkoutTypes = list_workout_types($pdo, false, 'it');
    $italianQaWorkoutType = array_values(array_filter($italianQaWorkoutTypes, static fn(array $type): bool => (int) ($type['id'] ?? 0) === $qaWorkoutTypeId))[0] ?? null;
    $assert((string) ($italianQaWorkoutType['name'] ?? '') === 'QA Trail walk updated', 'missing workout type translations fall back to English');
    db_execute($pdo, 'DELETE FROM workout_types WHERE id = :id', [':id' => $qaWorkoutTypeId]);
    $assert(
        db_fetch_one($pdo, 'SELECT id FROM workout_type_translations WHERE workout_type_id = :id', [':id' => $qaWorkoutTypeId]) === null
            && db_fetch_one($pdo, 'SELECT id FROM workout_type_field_translations WHERE field_id = :id', [':id' => $qaWorkoutFieldId]) === null,
        'workout type deletion cascades through type and field translations'
    );
    $firstSquadId = squad_create($pdo, $firstUserId, 'QA Alpha');
    $secondSquadId = squad_create($pdo, $secondUserId, 'QA Beta');
    db_execute(
        $pdo,
        'INSERT OR IGNORE INTO squad_members (squad_id, user_id, created_at) VALUES (:squad_id, :user_id, :created_at)',
        [':squad_id' => $secondSquadId, ':user_id' => $firstUserId, ':created_at' => now_iso()]
    );
    $secondSquad = squad_get($pdo, $secondSquadId);
    squad_link_core_team($pdo, (array) $secondSquad);

    $today = to_date(null);
    $firstTeamId = (int) ((squad_get($pdo, $firstSquadId)['team_id'] ?? 0));
    $secondTeamId = (int) ((squad_get($pdo, $secondSquadId)['team_id'] ?? 0));
    db_execute(
        $pdo,
        'UPDATE team_membership_periods SET joined_at = :joined_at, updated_at = :updated_at
         WHERE user_id = :user_id AND team_id IN (:first_team, :second_team)',
        [
            ':joined_at' => $today . ' 08:00:00',
            ':updated_at' => now_iso(),
            ':user_id' => $firstUserId,
            ':first_team' => $firstTeamId,
            ':second_team' => $secondTeamId,
        ]
    );
    db_execute(
        $pdo,
        'INSERT INTO workout_sessions (user_id, routine_id, daily_log_id, title, status, started_at, ended_at, notes, created_at, updated_at)
         VALUES (:user_id, NULL, NULL, "QA walk", "completed", :started_at, :ended_at, "", :created_at, :updated_at)',
        [
            ':user_id' => $firstUserId,
            ':started_at' => $today . ' 09:00:00',
            ':ended_at' => $today . ' 10:00:00',
            ':created_at' => now_iso(),
            ':updated_at' => now_iso(),
        ]
    );
    $alphaValue = comp_squad_value($pdo, $config, $firstSquadId, 'wk_sessions', $today, $today);
    $betaValue = comp_squad_value($pdo, $config, $secondSquadId, 'wk_sessions', $today, $today);
    $assert($alphaValue === 1.0, 'one workout counts once for the first team');
    $assert($betaValue === 1.0, 'the same workout also counts once for the second team');

    set_team_membership($pdo, $secondTeamId, $firstUserId, false, $firstUserId);
    set_team_membership($pdo, $secondTeamId, $firstUserId, true, $firstUserId);
    $membershipPeriods = db_fetch_all(
        $pdo,
        'SELECT * FROM team_membership_periods WHERE team_id = :team_id AND user_id = :user_id ORDER BY id',
        [':team_id' => $secondTeamId, ':user_id' => $firstUserId]
    );
    $assert(count($membershipPeriods) >= 2, 'leaving and rejoining preserves separate membership periods');
    $firstPeriodId = (int) ($membershipPeriods[0]['id'] ?? 0);
    $secondPeriodId = (int) ($membershipPeriods[count($membershipPeriods) - 1]['id'] ?? 0);
    db_execute(
        $pdo,
        'UPDATE team_membership_periods SET removed_at = :removed_at, updated_at = :updated_at WHERE id = :id',
        [':removed_at' => $today . ' 10:00:00', ':updated_at' => now_iso(), ':id' => $firstPeriodId]
    );
    db_execute(
        $pdo,
        'UPDATE team_membership_periods SET joined_at = :joined_at, updated_at = :updated_at WHERE id = :id',
        [':joined_at' => $today . ' 12:00:00', ':updated_at' => now_iso(), ':id' => $secondPeriodId]
    );
    db_execute(
        $pdo,
        'INSERT INTO team_membership_periods (team_id, user_id, joined_at, removed_at, created_at, updated_at)
         VALUES (:team_id, :user_id, :joined_at, :removed_at, :created_at, :updated_at)',
        [
            ':team_id' => $secondTeamId,
            ':user_id' => $firstUserId,
            ':joined_at' => $today . ' 08:30:00',
            ':removed_at' => $today . ' 09:30:00',
            ':created_at' => now_iso(),
            ':updated_at' => now_iso(),
        ]
    );
    $assert(
        comp_squad_value($pdo, $config, $secondSquadId, 'wk_sessions', $today, $today) === 1.0,
        'overlapping exact membership periods never duplicate a workout inside one team'
    );
    db_execute(
        $pdo,
        'INSERT INTO workout_sessions (user_id, routine_id, daily_log_id, title, status, started_at, ended_at, notes, created_at, updated_at)
         VALUES (:user_id, NULL, NULL, "QA gap walk", "completed", :started_at, :ended_at, "", :created_at, :updated_at)',
        [
            ':user_id' => $firstUserId,
            ':started_at' => $today . ' 11:00:00',
            ':ended_at' => $today . ' 11:30:00',
            ':created_at' => now_iso(),
            ':updated_at' => now_iso(),
        ]
    );
    $assert(
        comp_squad_value($pdo, $config, $secondSquadId, 'wk_sessions', $today, $today) === 1.0,
        'a workout between membership periods does not count for that team'
    );
    $assert(
        comp_squad_value($pdo, $config, $firstSquadId, 'wk_sessions', $today, $today) === 2.0,
        'the same gap workout still counts for another team with continuous membership'
    );
    db_execute(
        $pdo,
        'INSERT INTO workout_sessions (user_id, routine_id, daily_log_id, title, status, started_at, ended_at, notes, created_at, updated_at)
         VALUES (:user_id, NULL, NULL, "QA afternoon walk", "completed", :started_at, :ended_at, "", :created_at, :updated_at)',
        [
            ':user_id' => $firstUserId,
            ':started_at' => $today . ' 13:00:00',
            ':ended_at' => $today . ' 13:30:00',
            ':created_at' => now_iso(),
            ':updated_at' => now_iso(),
        ]
    );
    $assert(
        comp_squad_value($pdo, $config, $secondSquadId, 'wk_sessions', $today, $today) === 2.0,
        'separate valid membership periods on one day count both sessions'
    );
    $assert(
        comp_squad_value($pdo, $config, $secondSquadId, 'wk_days', $today, $today) === 1.0,
        'multiple valid membership periods on one day count only one training day'
    );
    $assert(
        comp_squad_value($pdo, $config, $firstSquadId, 'wk_sessions', $today, $today) === 3.0,
        'the continuously joined team counts every valid workout once'
    );
    $betaActivity = comp_squad_recent_workouts($pdo, $secondSquadId, $today, $today, 10);
    $betaActivityTitles = array_map(static fn(array $row): string => (string) ($row['title'] ?? ''), $betaActivity);
    $assert(
        in_array('QA walk', $betaActivityTitles, true)
        && in_array('QA afternoon walk', $betaActivityTitles, true)
        && !in_array('QA gap walk', $betaActivityTitles, true),
        'recent competition activity respects exact membership windows'
    );
    db_execute($pdo, 'UPDATE users SET active = 0 WHERE id = :id', [':id' => $firstUserId]);
    $assert(
        comp_squad_value($pdo, $config, $secondSquadId, 'wk_sessions', $today, $today) === 2.0,
        'historical competition contributions survive later user deactivation'
    );
    db_execute($pdo, 'UPDATE users SET active = 1 WHERE id = :id', [':id' => $firstUserId]);

    $assert(!comp_create($pdo, 0, $secondSquadId, $firstUserId, 'wk_sessions', 7), 'competition creation rejects a missing challenger team');
    $assert(comp_create($pdo, $firstSquadId, $secondSquadId, $firstUserId, 'wk_sessions', 7), 'competition creation accepts one valid selected team and opponent');
    $assert(!comp_create($pdo, $firstSquadId, $secondSquadId, $firstUserId, 'wk_sessions', 7), 'duplicate open competitions are rejected');

    $privacySearchPdo = new PDO('sqlite::memory:');
    $privacySearchPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $privacySearchPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    initialize_database($privacySearchPdo, $config);
    privacy_ensure_schema($privacySearchPdo);
    $privacySearchSeedUsers = db_fetch_all($privacySearchPdo, 'SELECT * FROM users ORDER BY id LIMIT 2');
    $privacySearchAdmin = (array) ($privacySearchSeedUsers[0] ?? []);
    $privacySearchViewer = (array) ($privacySearchSeedUsers[1] ?? []);
    $createPrivacySearchUser = static function (string $username, string $visibility) use ($privacySearchPdo): int {
        create_user($privacySearchPdo, [
            'username' => $username,
            'password' => 'privacy-search-password',
            'display_name' => 'QA Privacy Search ' . ucfirst($visibility),
            'role' => 'user',
            'step_goal' => 0,
            'step_days_mask' => '0000000',
            'workout_target' => 0,
            'workout_days_mask' => '0000000',
            'workout_strict' => 0,
            'ideal_weight' => null,
            'primary_goal_type' => 'none',
            'primary_goal_value' => null,
            'active' => 1,
        ]);
        $userId = (int) $privacySearchPdo->lastInsertId();
        privacy_set_preferences($privacySearchPdo, $userId, $visibility, []);

        return $userId;
    };
    $privacyPublicId = $createPrivacySearchUser('qa_search_public', 'public');
    $privacyFriendsId = $createPrivacySearchUser('qa_search_friends', 'friends');
    $privacyPrivateId = $createPrivacySearchUser('qa_search_private', 'private');
    friends_send_request($privacySearchPdo, (int) ($privacySearchViewer['id'] ?? 0), $privacyFriendsId);
    friends_respond($privacySearchPdo, $privacyFriendsId, (int) ($privacySearchViewer['id'] ?? 0), true);
    $privacyVisibleResults = privacy_search_visible_users($privacySearchPdo, $privacySearchViewer, 'QA Privacy Search', 12);
    $privacyVisibleIds = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $privacyVisibleResults);
    $assert(
        in_array($privacyPublicId, $privacyVisibleIds, true)
            && in_array($privacyFriendsId, $privacyVisibleIds, true)
            && !in_array($privacyPrivateId, $privacyVisibleIds, true),
        'global people search includes public and friend profiles without leaking private profiles'
    );
    $privacyAdminResults = privacy_search_visible_users($privacySearchPdo, $privacySearchAdmin, 'QA Privacy Search', 12);
    $privacyAdminIds = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $privacyAdminResults);
    $assert(
        in_array($privacyPublicId, $privacyAdminIds, true)
            && in_array($privacyFriendsId, $privacyAdminIds, true)
            && in_array($privacyPrivateId, $privacyAdminIds, true),
        'administrators retain complete people search visibility'
    );

    friends_ensure_schema($pdo);
    $searchBefore = friends_search_addable_users($pdo, $firstUserId, (string) $users[1]['username'], 10);
    $assert(count($searchBefore) === 1 && (int) $searchBefore[0]['id'] === $secondUserId, 'friend search returns an eligible suggestion');
    duels_ensure_schema($pdo);
    $assert(!duels_create($pdo, $firstUserId, $secondUserId, 'steps', 7), 'duel creation rejects users who are not friends');
    friends_send_request($pdo, $firstUserId, $secondUserId);
    $searchAfter = friends_search_addable_users($pdo, $firstUserId, (string) $users[1]['username'], 10);
    $assert($searchAfter === [], 'friend search excludes users with an existing relationship');
    $assert(friends_respond($pdo, $secondUserId, $firstUserId, true), 'friend request can be accepted');

    $sharedCustomExerciseId = wk_user_save_exercise($pdo, $secondUserId, null, [
        'name' => 'QA shared custom press',
        'muscle_group' => 'chest',
        'exercise_type' => 'strength',
        'equipment' => 'dumbbell',
    ]);
    $importedCustomExerciseId = wk_user_clone_exercise($pdo, $sharedCustomExerciseId, $firstUserId, true, false);
    $importedCustomExercise = wk_exercise_get_for_user($pdo, $importedCustomExerciseId, $firstUserId);
    $importTargetRoutineId = wk_routine_create($pdo, $firstUserId, 'QA imported routine');
    $importedRoutineExerciseId = wk_routine_add_exercise($pdo, $importTargetRoutineId, $importedCustomExerciseId);
    $assert(
        $importedCustomExerciseId > 0
            && $importedRoutineExerciseId > 0
            && (int) ($importedCustomExercise['user_id'] ?? 0) === $firstUserId
            && (string) ($importedCustomExercise['display_name'] ?? '') === 'QA shared custom press',
        'a custom exercise from a shared workout is safely copied into my routine'
    );

    $friendRoutineId = wk_routine_create($pdo, $secondUserId, 'QA friend routine');
    $friendRoutineExerciseId = wk_routine_add_exercise($pdo, $friendRoutineId, $sharedCustomExerciseId, [
        'target_sets' => 4,
        'target_reps' => 8,
        'target_weight' => 22.5,
    ]);
    $copiedFriendRoutineId = wk_routine_copy_from_user($pdo, $friendRoutineId, $secondUserId, $firstUserId);
    $copiedFriendRoutineExercises = wk_routine_exercises($pdo, $copiedFriendRoutineId);
    $assert(
        $copiedFriendRoutineId > 0
            && count($copiedFriendRoutineExercises) === 1
            && (int) ($copiedFriendRoutineExercises[0]['exercise_owner_id'] ?? 0) === $firstUserId
            && (int) ($copiedFriendRoutineExercises[0]['target_sets'] ?? 0) === 4,
        'copying a friend routine clones custom exercises and preserves its targets'
    );
    $assert(
        wk_routine_copy_from_user($pdo, $friendRoutineId, $secondUserId, $firstUserId) === $copiedFriendRoutineId,
        'copying the same friend routine twice reopens the existing import instead of duplicating it'
    );
    $partialTargetRoutineId = wk_routine_create($pdo, $firstUserId, 'QA partial friend import');
    $assert(
        wk_routine_copy_exercises_from_user($pdo, $friendRoutineId, $secondUserId, $partialTargetRoutineId, $firstUserId, [$friendRoutineExerciseId]) === 1
            && wk_routine_copy_exercises_from_user($pdo, $friendRoutineId, $secondUserId, $partialTargetRoutineId, $firstUserId, [$friendRoutineExerciseId]) === 0,
        'selected friend exercises can be imported once into an explicit destination routine'
    );

    db_execute(
        $pdo,
        'INSERT INTO workout_sessions (user_id,routine_id,title,status,started_at,ended_at,notes,created_at,updated_at)
         VALUES (:user,NULL,"QA copied workout","completed",:started,:ended,"",:now,:now)',
        [':user' => $secondUserId, ':started' => $today . ' 17:00:00', ':ended' => $today . ' 18:00:00', ':now' => now_iso()]
    );
    $copySourceSessionId = (int) $pdo->lastInsertId();
    db_execute($pdo, 'INSERT INTO session_exercises (session_id,exercise_def_id,sort_order,unit,notes) VALUES (:session,:exercise,1,"kg","")', [':session' => $copySourceSessionId, ':exercise' => $sharedCustomExerciseId]);
    $copySourceSessionExerciseId = (int) $pdo->lastInsertId();
    db_execute($pdo, 'INSERT INTO workout_sets (session_exercise_id,set_index,reps,weight,completed,created_at) VALUES (:exercise,1,10,20,1,:now),(:exercise,2,8,22.5,1,:now)', [':exercise' => $copySourceSessionExerciseId, ':now' => now_iso()]);
    $copiedSessionRoutineId = wk_routine_copy_from_session($pdo, $copySourceSessionId, $secondUserId, $firstUserId);
    $copiedSessionExercises = wk_routine_exercises($pdo, $copiedSessionRoutineId);
    $assert(
        $copiedSessionRoutineId > 0
            && (int) ($copiedSessionExercises[0]['target_sets'] ?? 0) === 2
            && (int) ($copiedSessionExercises[0]['target_reps'] ?? 0) === 8
            && (float) ($copiedSessionExercises[0]['target_weight'] ?? 0) === 22.5
            && wk_routine_copy_from_session($pdo, $copySourceSessionId, $secondUserId, $firstUserId) === $copiedSessionRoutineId,
        'copying a feed workout creates one reusable routine from completed sets without duplicate imports'
    );

    $qaMeal = nutrition_create_entry($pdo, $firstUserId, [
        'entry_date' => $today,
        'entry_time' => '12:30',
        'meal_type' => 'lunch',
        'calories' => '640',
        'protein_g' => '38',
        'notes' => 'QA editable meal',
    ]);
    $qaMealUpdated = nutrition_update_entry($pdo, $config, (int) ($qaMeal['id'] ?? 0), $firstUserId, [
        'entry_date' => $today,
        'entry_time' => '13:15',
        'meal_type' => 'snack',
        'calories' => '420',
        'protein_g' => '24',
        'notes' => 'QA updated meal',
    ]);
    $assert(
        (string) ($qaMealUpdated['meal_type'] ?? '') === 'snack'
            && (float) ($qaMealUpdated['calories'] ?? 0) === 420.0
            && (string) ($qaMealUpdated['entry_time'] ?? '') === '13:15',
        'a meal owner can edit the registered meal and its nutrition values'
    );
    $deletedQaMeal = nutrition_delete_entry($pdo, $config, (int) ($qaMeal['id'] ?? 0), $firstUserId);
    $assert(
        (int) ($deletedQaMeal['id'] ?? 0) === (int) ($qaMeal['id'] ?? 0)
            && db_fetch_one($pdo, 'SELECT id FROM nutrition_entries WHERE id=:id', [':id' => (int) ($qaMeal['id'] ?? 0)]) === null,
        'a meal owner can permanently delete their registered meal'
    );

    $socialNow = now_iso();
    db_execute(
        $pdo,
        'INSERT INTO nutrition_entries (user_id,photo_entry_id,entry_date,entry_time,meal_type,notes,photo_path,calories,protein_g,carbs_g,fat_g,fiber_g,sugar_g,sodium_mg,version,created_at,updated_at)
         VALUES (:user,NULL,:date,NULL,"lunch","QA social meal",NULL,500,30,50,15,NULL,NULL,NULL,1,:now,:now)',
        [':user' => $secondUserId, ':date' => to_date(null), ':now' => $socialNow]
    );
    $socialMealId = (int) $pdo->lastInsertId();
    // The first seed account is an administrator. Use the same accepted-friend
    // identity without its admin override to exercise the ordinary viewer rule.
    $socialMealRegularViewer = array_merge($users[0], ['role' => 'user']);
    $visibleSocialMeal = nutrition_public_entry_for_viewer($pdo, $socialMealId, $socialMealRegularViewer);
    $assert(
        (int) ($visibleSocialMeal['id'] ?? 0) === $socialMealId
            && (int) ($visibleSocialMeal['user_id'] ?? 0) === $secondUserId,
        'a viewer allowed to see nutrition can open a friend meal detail'
    );
    privacy_set_preferences($pdo, $secondUserId, 'public', ['nutrition' => 'private']);
    $assert(
        nutrition_public_entry_for_viewer($pdo, $socialMealId, $socialMealRegularViewer) === null,
        'a direct meal link cannot bypass the owner nutrition privacy setting'
    );
    privacy_set_preferences($pdo, $secondUserId, 'public', ['nutrition' => 'public']);
    db_execute($pdo, 'UPDATE nutrition_entries SET archived_at=:now WHERE id=:id', [':now' => now_iso(), ':id' => $socialMealId]);
    $assert(
        nutrition_public_entry_for_viewer($pdo, $socialMealId, $socialMealRegularViewer) === null
            && nutrition_public_entry_for_viewer($pdo, 999999, $socialMealRegularViewer) === null,
        'archived and missing meals are both unavailable through a direct detail link'
    );
    db_execute($pdo, 'UPDATE nutrition_entries SET archived_at=NULL WHERE id=:id', [':id' => $socialMealId]);
    $focusedMealFeed = social_feed_items($pdo, $firstUserId, 'friends', 1, 'meal', $socialMealId);
    $assert(
        count($focusedMealFeed) === 1
            && (string) ($focusedMealFeed[0]['type'] ?? '') === 'meal'
            && (int) ($focusedMealFeed[0]['id'] ?? 0) === $socialMealId,
        'a shared meal link loads only its exact feed post'
    );
    $renderMealFeedItem = static function (array $item, array $viewer): string {
        $dashboardFeedItems = [$item];
        $dashboardFeedScope = 'friends';
        $dashboardFeedFocused = true;
        $currentUser = $viewer;
        ob_start();
        require dirname(__DIR__) . '/app/views/partials/dashboard_social_feed.php';
        return (string) ob_get_clean();
    };
    $friendMealFeedHtml = $renderMealFeedItem($focusedMealFeed[0], $users[0]);
    $ownerMealFeedHtml = $renderMealFeedItem($focusedMealFeed[0], $users[1]);
    preg_match('/data-share-url="([^"]+)"/', $friendMealFeedHtml, $mealShareMatch);
    $mealShareUrl = html_entity_decode((string) ($mealShareMatch[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $assert(
        str_contains($friendMealFeedHtml, 'href="/?page=meal&amp;meal_id=' . $socialMealId . '&amp;origin=home_feed')
            && str_contains($ownerMealFeedHtml, 'href="/?page=meal&amp;meal_id=' . $socialMealId . '&amp;origin=home_feed')
            && $mealShareUrl === '/?page=meal&meal_id=' . $socialMealId,
        'meal cards use one canonical detail URL while keeping feed return context out of the shared URL'
    );
    $assert(social_feed_toggle_like($pdo, $firstUserId, 'meal', $socialMealId), 'liking a friend activity succeeds');
    $mealLikeNotification = db_fetch_one(
        $pdo,
        'SELECT * FROM user_notifications WHERE user_id=:user AND kind="social_like" ORDER BY id DESC LIMIT 1',
        [':user' => $secondUserId]
    );
    $mealLikePayload = json_decode((string) ($mealLikeNotification['payload_json'] ?? ''), true);
    $assert(
        is_array($mealLikeNotification)
            && (string) ($mealLikePayload['entity_type'] ?? '') === 'meal'
            && (int) ($mealLikePayload['entity_id'] ?? 0) === $socialMealId,
        'a like notifies the activity owner with its exact target'
    );
    $mealLikeDestination = open_user_notification($pdo, (int) ($mealLikeNotification['id'] ?? 0), $secondUserId);
    $assert(
        str_contains($mealLikeDestination, 'page=dashboard')
            && str_contains($mealLikeDestination, 'post_type=meal')
            && str_contains($mealLikeDestination, 'post_id=' . $socialMealId)
            && str_contains($mealLikeDestination, '#feed-meal-' . $socialMealId),
        'clicking a meal like notification opens that feed item'
    );
    $assert(!social_feed_toggle_like($pdo, $firstUserId, 'meal', $socialMealId), 'removing a like succeeds without a new notification');
    $assert(social_feed_toggle_like($pdo, $firstUserId, 'meal', $socialMealId), 'the same activity can be liked again');
    $mealLikeNotificationCount = (int) (db_fetch_one(
        $pdo,
        'SELECT COUNT(*) AS n FROM user_notifications WHERE user_id=:user AND kind="social_like" AND unique_key=:key',
        [':user' => $secondUserId, ':key' => 'social_like:meal:' . $socialMealId . ':' . $firstUserId]
    )['n'] ?? 0);
    $assert($mealLikeNotificationCount === 1, 're-liking does not duplicate the same notification');
    $assert(
        social_feed_add_comment($pdo, $firstUserId, 'meal', $socialMealId, 'Gran comida para recuperar'),
        'commenting on a friend activity succeeds'
    );
    $mealCommentNotification = db_fetch_one(
        $pdo,
        'SELECT * FROM user_notifications WHERE user_id=:user AND kind="social_comment" ORDER BY id DESC LIMIT 1',
        [':user' => $secondUserId]
    );
    $mealCommentDestination = open_user_notification($pdo, (int) ($mealCommentNotification['id'] ?? 0), $secondUserId);
    $assert(
        str_contains((string) ($mealCommentNotification['message'] ?? ''), 'Gran comida para recuperar')
            && str_contains($mealCommentDestination, 'comments=meal-' . $socialMealId)
            && str_contains($mealCommentDestination, '#feed-meal-' . $socialMealId),
        'a comment notification opens the exact activity with comments expanded'
    );
    $mealTopComment = db_fetch_one(
        $pdo,
        'SELECT * FROM social_feed_comments WHERE entity_type="meal" AND entity_id=:id AND user_id=:user ORDER BY id DESC LIMIT 1',
        [':id' => $socialMealId, ':user' => $firstUserId]
    );
    $mealTopCommentId = (int) ($mealTopComment['id'] ?? 0);
    $likedMealComment = social_comment_toggle_like(
        $pdo,
        $secondUserId,
        'meal',
        $socialMealId,
        $mealTopCommentId
    );
    db_execute(
        $pdo,
        'INSERT OR IGNORE INTO social_comment_likes (user_id,entity_type,comment_id,created_at)
         VALUES (:user,"meal",:comment,:now)',
        [':user' => $secondUserId, ':comment' => $mealTopCommentId, ':now' => now_iso()]
    );
    $mealCommentLikeCount = (int) (db_fetch_one(
        $pdo,
        'SELECT COUNT(*) AS n FROM social_comment_likes
         WHERE user_id=:user AND entity_type="meal" AND comment_id=:comment',
        [':user' => $secondUserId, ':comment' => $mealTopCommentId]
    )['n'] ?? 0);
    $ownerHydratedComments = social_comments_for_entity($pdo, 'meal', $socialMealId, 250, $secondUserId);
    $authorHydratedComments = social_comments_for_entity($pdo, 'meal', $socialMealId, 250, $firstUserId);
    $ownerHydratedComment = null;
    $authorHydratedComment = null;
    foreach ($ownerHydratedComments as $hydratedComment) {
        if ((int) ($hydratedComment['id'] ?? 0) === $mealTopCommentId) $ownerHydratedComment = $hydratedComment;
    }
    foreach ($authorHydratedComments as $hydratedComment) {
        if ((int) ($hydratedComment['id'] ?? 0) === $mealTopCommentId) $authorHydratedComment = $hydratedComment;
    }
    $assert(
        (int) ($likedMealComment['comment_liked'] ?? 0) === 1
            && (int) ($likedMealComment['comment_like_count'] ?? 0) === 1
            && $mealCommentLikeCount === 1,
        'a persistent comment like is unique even when the same row is inserted again'
    );
    $assert(
        (int) ($ownerHydratedComment['comment_liked'] ?? 0) === 1
            && (int) ($ownerHydratedComment['comment_like_count'] ?? 0) === 1
            && (int) ($authorHydratedComment['comment_liked'] ?? 0) === 0
            && (int) ($authorHydratedComment['comment_like_count'] ?? 0) === 1,
        'comment hydration exposes the shared count and viewer-specific liked state'
    );
    $unlikedMealComment = social_comment_toggle_like(
        $pdo,
        $secondUserId,
        'meal',
        $socialMealId,
        $mealTopCommentId
    );
    $assert(
        (int) ($unlikedMealComment['comment_liked'] ?? 1) === 0
            && (int) ($unlikedMealComment['comment_like_count'] ?? -1) === 0
            && (int) (db_fetch_one(
                $pdo,
                'SELECT COUNT(*) AS n FROM social_comment_likes WHERE entity_type="meal" AND comment_id=:comment',
                [':comment' => $mealTopCommentId]
            )['n'] ?? -1) === 0,
        'comment likes toggle off without leaving a duplicate row'
    );
    social_comment_toggle_like($pdo, $secondUserId, 'meal', $socialMealId, $mealTopCommentId);
    $mealOwnerReply = social_comment_create(
        $pdo,
        $secondUserId,
        'meal',
        $socialMealId,
        'Gracias por comentar',
        $mealTopCommentId
    );
    $replyNotification = db_fetch_one(
        $pdo,
        'SELECT * FROM user_notifications WHERE user_id=:user AND kind="social_reply" ORDER BY id DESC LIMIT 1',
        [':user' => $firstUserId]
    );
    $assert(
        (int) ($mealOwnerReply['parent_comment_id'] ?? 0) === (int) ($mealTopComment['id'] ?? 0)
            && str_contains((string) ($replyNotification['message'] ?? ''), 'Gracias por comentar')
            && str_contains(resolve_notification_destination($pdo, (array) $replyNotification), 'comments=meal-' . $socialMealId),
        'a threaded reply stays one level deep, notifies the replied-to author and opens the activity'
    );
    $nestedReply = social_comment_create(
        $pdo,
        $firstUserId,
        'meal',
        $socialMealId,
        'De nada',
        (int) ($mealOwnerReply['id'] ?? 0)
    );
    $assert(
        (int) ($nestedReply['parent_comment_id'] ?? 0) === (int) ($mealTopComment['id'] ?? 0),
        'replying to a reply is normalized to the single supported thread level'
    );
    $editedReply = social_comment_update($pdo, $firstUserId, 'meal', $socialMealId, (int) ($nestedReply['id'] ?? 0), 'De nada, gran sesión');
    $assert((string) ($editedReply['comment'] ?? '') === 'De nada, gran sesión', 'a comment author can edit their own reply');
    $ownerCouldEditAnotherComment = true;
    try {
        social_comment_update($pdo, $secondUserId, 'meal', $socialMealId, (int) ($nestedReply['id'] ?? 0), 'Owner rewrite');
    } catch (Throwable) {
        $ownerCouldEditAnotherComment = false;
    }
    $assert(!$ownerCouldEditAnotherComment, 'a post owner cannot edit another author comment');
    $deletedThread = social_comment_delete($pdo, $users[1], 'meal', $socialMealId, $mealTopCommentId);
    $commentLikesAfterThreadDelete = (int) (db_fetch_one(
        $pdo,
        'SELECT COUNT(*) AS n FROM social_comment_likes WHERE entity_type="meal" AND comment_id=:comment',
        [':comment' => $mealTopCommentId]
    )['n'] ?? 0);
    $assert(
        (int) ($deletedThread['id'] ?? 0) === $mealTopCommentId
            && social_comment_count($pdo, 'meal', $socialMealId) === 0
            && $commentLikesAfterThreadDelete === 0,
        'the post owner can delete another author thread and its persistent comment likes'
    );
    $notificationCountBeforeSelfLike = (int) (db_fetch_one(
        $pdo,
        'SELECT COUNT(*) AS n FROM user_notifications WHERE user_id=:user AND kind="social_like"',
        [':user' => $secondUserId]
    )['n'] ?? 0);
    $assert(social_feed_toggle_like($pdo, $secondUserId, 'meal', $socialMealId), 'liking your own activity remains valid');
    $notificationCountAfterSelfLike = (int) (db_fetch_one(
        $pdo,
        'SELECT COUNT(*) AS n FROM user_notifications WHERE user_id=:user AND kind="social_like"',
        [':user' => $secondUserId]
    )['n'] ?? 0);
    $assert($notificationCountAfterSelfLike === $notificationCountBeforeSelfLike, 'self likes do not create notifications');

    $mealSocialNotificationsBeforeDelete = (int) (db_fetch_one(
        $pdo,
        'SELECT COUNT(*) AS n FROM user_notifications
         WHERE kind IN ("social_like", "social_comment", "social_reply")
           AND json_extract(CASE WHEN json_valid(payload_json) THEN payload_json ELSE "{}" END, "$.entity_type") = "meal"
           AND CAST(json_extract(CASE WHEN json_valid(payload_json) THEN payload_json ELSE "{}" END, "$.entity_id") AS INTEGER) = :meal',
        [':meal' => $socialMealId]
    )['n'] ?? 0);
    $deletedSocialMeal = nutrition_delete_entry($pdo, $config, $socialMealId, $secondUserId);
    $mealSocialNotificationsAfterDelete = (int) (db_fetch_one(
        $pdo,
        'SELECT COUNT(*) AS n FROM user_notifications
         WHERE kind IN ("social_like", "social_comment", "social_reply")
           AND json_extract(CASE WHEN json_valid(payload_json) THEN payload_json ELSE "{}" END, "$.entity_type") = "meal"
           AND CAST(json_extract(CASE WHEN json_valid(payload_json) THEN payload_json ELSE "{}" END, "$.entity_id") AS INTEGER) = :meal',
        [':meal' => $socialMealId]
    )['n'] ?? 0);
    $assert(
        (int) ($deletedSocialMeal['id'] ?? 0) === $socialMealId
            && $mealSocialNotificationsBeforeDelete >= 3
            && $mealSocialNotificationsAfterDelete === 0
            && db_fetch_one($pdo, 'SELECT 1 FROM social_feed_likes WHERE entity_type="meal" AND entity_id=:meal', [':meal' => $socialMealId]) === null,
        'deleting a meal clears its activity state and every social notification that targeted it'
    );

    // A meal captured with a photo is stored in both Nutrition and Gallery, but
    // must behave as one Meal Update in the feed. This is the historical shape
    // that used to survive after its nutrition row was archived or deleted.
    db_execute(
        $pdo,
        'INSERT INTO photo_entries (user_id,log_date,category,caption,file_path,has_photo,calories,protein_g,created_at,updated_at)
         VALUES (:user,:date,"meal","QA linked meal photo","qa/linked-meal.jpg",1,610,36,:now,:now)',
        [':user' => $secondUserId, ':date' => to_date(null), ':now' => now_iso()]
    );
    $linkedMealPhotoId = (int) $pdo->lastInsertId();
    db_execute(
        $pdo,
        'INSERT INTO nutrition_entries (user_id,photo_entry_id,entry_date,entry_time,meal_type,notes,photo_path,calories,protein_g,carbs_g,fat_g,version,created_at,updated_at)
         VALUES (:user,:photo,:date,"20:15","dinner","QA linked meal","qa/linked-meal.jpg",610,36,72,18,1,:now,:now)',
        [':user' => $secondUserId, ':photo' => $linkedMealPhotoId, ':date' => to_date(null), ':now' => now_iso()]
    );
    $linkedMealId = (int) $pdo->lastInsertId();
    $linkedMealFeed = social_feed_items($pdo, $firstUserId, 'friends', 1, 'photo', $linkedMealPhotoId);
    $linkedMealOwnerHtml = $linkedMealFeed !== [] ? $renderMealFeedItem($linkedMealFeed[0], $users[1]) : '';
    $assert(
        count($linkedMealFeed) === 1
            && (int) ($linkedMealFeed[0]['meal_entry_id'] ?? 0) === $linkedMealId
            && str_contains($linkedMealOwnerHtml, 'href="/?page=meal&amp;meal_id=' . $linkedMealId . '&amp;origin=home_feed')
            && str_contains($linkedMealOwnerHtml, 'name="entry_id" value="' . $linkedMealId . '"'),
        'a photo-backed Meal Update opens its meal and exposes the owner delete action'
    );
    $assert(social_feed_toggle_like($pdo, $firstUserId, 'photo', $linkedMealPhotoId), 'a visible photo-backed meal accepts social actions');
    $linkedMealComment = social_comment_create($pdo, $firstUserId, 'photo', $linkedMealPhotoId, 'QA linked meal comment');
    nutrition_update_entry($pdo, $config, $linkedMealId, $secondUserId, ['nutrition_entry_state' => 'archive']);
    $linkedMealArchiveRejectedComment = false;
    try {
        social_comment_create($pdo, $firstUserId, 'photo', $linkedMealPhotoId, 'Must not be stored');
    } catch (SocialActionException) {
        $linkedMealArchiveRejectedComment = true;
    }
    $assert(
        social_feed_items($pdo, $firstUserId, 'friends', 1, 'photo', $linkedMealPhotoId) === []
            && !social_feed_entity_visible($pdo, $firstUserId, 'photo', $linkedMealPhotoId)
            && $linkedMealArchiveRejectedComment,
        'archiving a photo-backed meal removes it from the feed and blocks direct mutations'
    );
    nutrition_update_entry($pdo, $config, $linkedMealId, $secondUserId, ['nutrition_entry_state' => 'unarchive']);
    $assert(
        count(social_feed_items($pdo, $firstUserId, 'friends', 1, 'photo', $linkedMealPhotoId)) === 1,
        'unarchiving a photo-backed meal restores its single feed activity'
    );
    $linkedMealNotificationCount = (int) (db_fetch_one(
        $pdo,
        'SELECT COUNT(*) AS n FROM user_notifications
         WHERE kind IN ("social_like", "social_comment", "social_reply")
           AND json_extract(CASE WHEN json_valid(payload_json) THEN payload_json ELSE "{}" END, "$.entity_type")="photo"
           AND CAST(json_extract(CASE WHEN json_valid(payload_json) THEN payload_json ELSE "{}" END, "$.entity_id") AS INTEGER)=:photo',
        [':photo' => $linkedMealPhotoId]
    )['n'] ?? 0);
    $deletedLinkedMeal = nutrition_delete_entry($pdo, $config, $linkedMealId, $secondUserId);
    $assert(
        (int) ($deletedLinkedMeal['id'] ?? 0) === $linkedMealId
            && (int) ($linkedMealComment['id'] ?? 0) > 0
            && $linkedMealNotificationCount >= 2
            && db_fetch_one($pdo, 'SELECT 1 FROM nutrition_entries WHERE id=:id', [':id' => $linkedMealId]) === null
            && db_fetch_one($pdo, 'SELECT 1 FROM photo_entries WHERE id=:id', [':id' => $linkedMealPhotoId]) === null
            && db_fetch_one($pdo, 'SELECT 1 FROM social_feed_likes WHERE entity_type="photo" AND entity_id=:id', [':id' => $linkedMealPhotoId]) === null
            && db_fetch_one($pdo, 'SELECT 1 FROM photo_comments WHERE photo_id=:id', [':id' => $linkedMealPhotoId]) === null
            && (int) (db_fetch_one(
                $pdo,
                'SELECT COUNT(*) AS n FROM user_notifications
                 WHERE kind IN ("social_like", "social_comment", "social_reply")
                   AND json_extract(CASE WHEN json_valid(payload_json) THEN payload_json ELSE "{}" END, "$.entity_type")="photo"
                   AND CAST(json_extract(CASE WHEN json_valid(payload_json) THEN payload_json ELSE "{}" END, "$.entity_id") AS INTEGER)=:photo',
                [':photo' => $linkedMealPhotoId]
            )['n'] ?? -1) === 0,
        'deleting a photo-backed meal removes its gallery row, activity, conversation and notifications'
    );

    db_execute(
        $pdo,
        'INSERT INTO photo_entries (user_id,log_date,category,caption,file_path,has_photo,created_at,updated_at)
         VALUES (:user,:date,"meal","QA social photo","qa/social-photo.jpg",1,:now,:now)',
        [':user' => $secondUserId, ':date' => to_date(null), ':now' => now_iso()]
    );
    $socialPhotoId = (int) $pdo->lastInsertId();
    $assert(social_feed_toggle_like($pdo, $firstUserId, 'photo', $socialPhotoId), 'liking a friend photo succeeds');
    $photoNotification = db_fetch_one(
        $pdo,
        'SELECT * FROM user_notifications WHERE user_id=:user AND kind="social_like" AND payload_json LIKE :photo ORDER BY id DESC LIMIT 1',
        [':user' => $secondUserId, ':photo' => '%"entity_type":"photo"%']
    );
    $assert(
        resolve_notification_destination($pdo, (array) $photoNotification) === '/?page=photo&photo_id=' . $socialPhotoId,
        'clicking a photo notification opens that photo'
    );
    $assert(
        create_photo_comment($pdo, $socialPhotoId, $firstUserId, 'Comentario desde el detalle de foto') !== [],
        'commenting from the photo detail succeeds'
    );
    $photoCommentNotification = db_fetch_one(
        $pdo,
        'SELECT * FROM user_notifications WHERE user_id=:user AND kind="social_comment" AND payload_json LIKE :photo ORDER BY id DESC LIMIT 1',
        [':user' => $secondUserId, ':photo' => '%"entity_type":"photo"%']
    );
    $assert(
        str_contains((string) ($photoCommentNotification['message'] ?? ''), 'Comentario desde el detalle de foto')
            && resolve_notification_destination($pdo, (array) $photoCommentNotification) === '/?page=photo&photo_id=' . $socialPhotoId,
        'a comment from the photo detail notifies its owner and links back to it'
    );
    $photoTopComment = db_fetch_one($pdo, 'SELECT * FROM photo_comments WHERE photo_id=:photo ORDER BY id DESC LIMIT 1', [':photo' => $socialPhotoId]);
    $photoReply = social_comment_create($pdo, $secondUserId, 'photo', $socialPhotoId, 'Respuesta en la foto', (int) ($photoTopComment['id'] ?? 0));
    $photoReplyNotification = db_fetch_one(
        $pdo,
        'SELECT * FROM user_notifications WHERE user_id=:user AND kind="social_reply" ORDER BY id DESC LIMIT 1',
        [':user' => $firstUserId]
    );
    $assert(
        (int) ($photoReply['parent_comment_id'] ?? 0) === (int) ($photoTopComment['id'] ?? 0)
            && str_contains(resolve_notification_destination($pdo, (array) $photoReplyNotification), '#comment-photo-' . (int) ($photoReply['id'] ?? 0)),
        'photo replies use the same thread model and notification deep link as the feed'
    );
    $photoSocialNotificationsBeforeDelete = (int) (db_fetch_one(
        $pdo,
        'SELECT COUNT(*) AS n FROM user_notifications
         WHERE kind IN ("social_like", "social_comment", "social_reply")
           AND json_extract(CASE WHEN json_valid(payload_json) THEN payload_json ELSE "{}" END, "$.entity_type") = "photo"
           AND CAST(json_extract(CASE WHEN json_valid(payload_json) THEN payload_json ELSE "{}" END, "$.entity_id") AS INTEGER) = :photo',
        [':photo' => $socialPhotoId]
    )['n'] ?? 0);
    $deletedSocialPhoto = delete_photo_entry($pdo, $config, $socialPhotoId);
    $photoLikesAfterDelete = (int) (db_fetch_one(
        $pdo,
        'SELECT COUNT(*) AS n FROM social_feed_likes WHERE entity_type="photo" AND entity_id=:photo',
        [':photo' => $socialPhotoId]
    )['n'] ?? 0);
    $photoSocialNotificationsAfterDelete = (int) (db_fetch_one(
        $pdo,
        'SELECT COUNT(*) AS n FROM user_notifications
         WHERE kind IN ("social_like", "social_comment", "social_reply")
           AND json_extract(CASE WHEN json_valid(payload_json) THEN payload_json ELSE "{}" END, "$.entity_type") = "photo"
           AND CAST(json_extract(CASE WHEN json_valid(payload_json) THEN payload_json ELSE "{}" END, "$.entity_id") AS INTEGER) = :photo',
        [':photo' => $socialPhotoId]
    )['n'] ?? 0);
    $assert(
        (int) ($deletedSocialPhoto['id'] ?? 0) === $socialPhotoId
            && $photoSocialNotificationsBeforeDelete >= 3
            && $photoLikesAfterDelete === 0
            && $photoSocialNotificationsAfterDelete === 0
            && db_fetch_one($pdo, 'SELECT id FROM photo_comments WHERE photo_id=:photo', [':photo' => $socialPhotoId]) === null,
        'deleting a photo clears its likes, threaded comments and social notifications'
    );
    $workoutDestination = resolve_notification_destination($pdo, [
        'kind' => 'social_comment',
        'payload_json' => json_encode(['entity_type' => 'workout', 'entity_id' => 321], JSON_UNESCAPED_SLASHES),
    ]);
    $assert(
        $workoutDestination === '/?page=workouts&view=stats&detail_session=321',
        'clicking a workout notification opens that workout summary'
    );

    $assert(!duels_create($pdo, $firstUserId, $firstUserId, 'steps', 7), 'duel creation rejects challenging yourself');
    $assert(duels_create($pdo, $firstUserId, $secondUserId, 'steps', 7), 'duel creation accepts a valid friend and metric');
    $duel = db_fetch_one($pdo, 'SELECT * FROM user_duels ORDER BY id DESC LIMIT 1');
    $assert($duel !== null && duels_respond($pdo, (int) $duel['id'], $secondUserId, true), 'duel opponent can accept in-page flow');
    $activeDuel = db_fetch_one($pdo, 'SELECT * FROM user_duels WHERE id = :id', [':id' => (int) ($duel['id'] ?? 0)]);
    $assert((string) ($activeDuel['status'] ?? '') === 'active', 'accepted duel starts with a bounded date range');
    $assert(!duels_create($pdo, $firstUserId, $secondUserId, 'steps', 7), 'duplicate open duels are rejected');

    db_execute(
        $pdo,
        "INSERT INTO integration_runtime_leases (service, owner_id, heartbeat_at, lease_until, last_success_at, last_error)
         VALUES ('notion', 'qa-worker', datetime('now', '-121 seconds'), datetime('now', '+30 seconds'), NULL, NULL)
         ON CONFLICT(service) DO UPDATE SET heartbeat_at = excluded.heartbeat_at, lease_until = excluded.lease_until, last_error = NULL"
    );
    $assert((integration_runtime_statuses($pdo)['notion']['state'] ?? '') === 'delayed', 'admin status identifies a delayed integration lease');
    db_execute($pdo, "UPDATE integration_runtime_leases SET last_error = 'qa failure' WHERE service = 'notion'");
    $assert((integration_runtime_statuses($pdo)['notion']['state'] ?? '') === 'error', 'admin status identifies an integration error');

    set_app_setting_silent($pdo, 'notion_enabled', '1', null);
    set_app_setting_silent($pdo, 'notion_token', 'secret_partial_save', null);
    set_app_setting_silent($pdo, 'notion_database_id', 'database_partial_save', null);
    set_app_setting_silent($pdo, 'notion_sync_frequency', 'daily', null);
    set_app_setting_silent($pdo, 'notion_sync_direction', 'two_way', null);
    notion_update_settings($pdo, [
        'notion_oauth_client_id' => 'qa-oauth-id',
        'notion_oauth_client_secret' => 'qa-oauth-secret',
    ], $firstUserId);
    $partialNotionSettings = notion_settings($pdo);
    $assert(
        !empty($partialNotionSettings['enabled'])
            && (string) $partialNotionSettings['token'] === 'secret_partial_save'
            && (string) $partialNotionSettings['database_id'] === 'database_partial_save'
            && (string) $partialNotionSettings['frequency'] === 'daily'
            && (string) $partialNotionSettings['direction'] === 'two_way',
        'saving Notion OAuth credentials preserves connection and automation settings'
    );
    notion_update_settings($pdo, [
        'notion_enabled_present' => '1',
        'notion_sync_frequency' => 'off',
        'notion_sync_direction' => 'push_only',
        'notion_sync_run_time' => '03:15',
    ], $firstUserId);
    $partialNotionAutomation = notion_settings($pdo);
    $assert(
        empty($partialNotionAutomation['enabled'])
            && (string) $partialNotionAutomation['token'] === 'secret_partial_save'
            && (string) $partialNotionAutomation['database_id'] === 'database_partial_save',
        'saving Notion automation preserves credentials and supports disabling the integration'
    );

    set_app_setting_silent($pdo, 'telegram_enabled', '1', null);
    set_app_setting_silent($pdo, 'telegram_bot_token', 'telegram_secret_partial_save', null);
    set_app_setting_silent($pdo, 'telegram_bot_username', 'qa_fitness_bot', null);
    set_app_setting_silent($pdo, 'app_base_url', 'https://qa.example.test', null);
    telegram_update_settings($pdo, [
        'telegram_bot_token' => '',
        'telegram_bot_username' => 'qa_fitness_bot_updated',
    ], $firstUserId);
    $partialTelegramConnection = telegram_settings($pdo);
    $assert(
        !empty($partialTelegramConnection['enabled'])
            && (string) $partialTelegramConnection['token'] === 'telegram_secret_partial_save'
            && (string) $partialTelegramConnection['username'] === 'qa_fitness_bot_updated'
            && (string) $partialTelegramConnection['configured_base_url'] === 'https://qa.example.test',
        'saving Telegram connection preserves its secret, activation and public URL'
    );
    telegram_update_settings($pdo, ['app_base_url' => 'https://qa-new.example.test/'], $firstUserId);
    $partialTelegramUrl = telegram_settings($pdo);
    $assert(
        !empty($partialTelegramUrl['enabled'])
            && (string) $partialTelegramUrl['token'] === 'telegram_secret_partial_save'
            && (string) $partialTelegramUrl['username'] === 'qa_fitness_bot_updated'
            && (string) $partialTelegramUrl['configured_base_url'] === 'https://qa-new.example.test',
        'saving Telegram public URL preserves bot connection and activation'
    );
    telegram_update_settings($pdo, ['telegram_enabled_present' => '1'], $firstUserId);
    $partialTelegramAutomation = telegram_settings($pdo);
    $assert(
        empty($partialTelegramAutomation['enabled'])
            && (string) $partialTelegramAutomation['token'] === 'telegram_secret_partial_save'
            && (string) $partialTelegramAutomation['username'] === 'qa_fitness_bot_updated'
            && (string) $partialTelegramAutomation['configured_base_url'] === 'https://qa-new.example.test',
        'saving Telegram automation preserves connection data and supports disabling the integration'
    );

    $runtimeSecret = 'secret_php_runtime_qa';
    set_app_setting_silent($pdo, 'notion_token', $runtimeSecret, null);
    db_execute(
        $pdo,
        "UPDATE integration_runtime_leases SET last_error = :error WHERE service = 'notion'",
        [':error' => 'request failed with ' . $runtimeSecret]
    );
    $safeRuntimeError = (string) (integration_runtime_statuses($pdo)['notion']['last_error'] ?? '');
    $assert(
        !str_contains($safeRuntimeError, $runtimeSecret) && str_contains($safeRuntimeError, '[redacted]'),
        'admin integration status redacts stored secrets before rendering'
    );

    fwrite(STDOUT, "All {$checks} stabilization checks passed.\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[fail] ' . $error->getMessage() . PHP_EOL);
    exit(1);
} finally {
    db_reset_connection();
    system_backup_recursive_delete($qaRoot);
}
