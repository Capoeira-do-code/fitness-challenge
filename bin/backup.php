#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command is CLI-only.\n");
    exit(2);
}

require dirname(__DIR__) . '/app/bootstrap.php';

/** @return never */
function backup_cli_fail(Throwable|string $error, int $exitCode = 1): never
{
    $message = $error instanceof Throwable ? $error->getMessage() : $error;
    fwrite(STDERR, json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit($exitCode);
}

function backup_cli_latest_archive(PDO $pdo, array $config): ?string
{
    foreach (list_system_backups($pdo, $config, 200) as $backup) {
        $path = system_backup_absolute_path($config, (string) ($backup['file_path'] ?? ''));
        if (is_string($path) && is_file($path)) {
            return $path;
        }
    }
    return null;
}

$command = strtolower(trim((string) ($argv[1] ?? 'status')));

try {
    if ($command === 'create') {
        $backup = create_system_backup($pdo, $config, 'manual', null);
        fwrite(STDOUT, json_encode(['ok' => true, 'backup' => $backup], JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(0);
    }

    if ($command === 'scheduled') {
        run_system_backup_scheduler($pdo, $config, null);
        fwrite(STDOUT, json_encode(['ok' => true, 'settings' => system_backup_settings($pdo)], JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(0);
    }

    if ($command === 'verify') {
        $archivePath = trim((string) ($argv[2] ?? ''));
        if ($archivePath === '') {
            $archivePath = backup_cli_latest_archive($pdo, $config) ?? '';
        }
        if ($archivePath === '') {
            backup_cli_fail('No backup archive is available.');
        }
        $manifest = validate_system_backup_archive($archivePath);
        fwrite(STDOUT, json_encode(['ok' => true, 'archive' => $archivePath, 'manifest' => $manifest], JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(0);
    }

    if ($command === 'drill') {
        $archivePath = trim((string) ($argv[2] ?? ''));
        if ($archivePath === '') {
            $archivePath = backup_cli_latest_archive($pdo, $config) ?? '';
        }
        if ($archivePath === '') {
            backup_cli_fail('No backup archive is available.');
        }
        validate_system_backup_archive($archivePath);

        $drillRoot = rtrim(sys_get_temp_dir(), '/\\') . '/fitness_restore_drill_' . bin2hex(random_bytes(6));
        $drillConfig = $config;
        $drillConfig['db_path'] = $drillRoot . '/fitness.sqlite';
        $drillConfig['upload_dir'] = $drillRoot . '/uploads';
        if (!mkdir($drillRoot . '/uploads', 0775, true) && !is_dir($drillRoot . '/uploads')) {
            throw new RuntimeException('Could not create restore drill workspace.');
        }

        try {
            system_backup_create_sqlite_snapshot($pdo, (string) $drillConfig['db_path']);
            $drillPdo = new PDO('sqlite:' . (string) $drillConfig['db_path']);
            $drillPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $result = restore_system_backup_archive($drillPdo, $drillConfig, $archivePath);
            $drillPdo = null;
            system_backup_assert_sqlite_integrity((string) $drillConfig['db_path']);
            set_app_setting_silent($pdo, 'backup_last_drill_at', now_iso(), null);
            set_app_setting_silent($pdo, 'backup_last_error', '', null);
            fwrite(STDOUT, json_encode(['ok' => true, 'archive' => $archivePath, 'result' => $result], JSON_UNESCAPED_SLASHES) . PHP_EOL);
        } finally {
            system_backup_recursive_delete($drillRoot);
        }
        exit(0);
    }

    if ($command === 'status') {
        fwrite(STDOUT, json_encode([
            'ok' => true,
            'settings' => system_backup_settings($pdo),
            'backups' => array_slice(list_system_backups($pdo, $config, 5), 0, 5),
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(0);
    }

    backup_cli_fail('Usage: php bin/backup.php [status|create|scheduled|verify [archive]|drill [archive]]', 2);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO) {
        set_app_setting_silent($pdo, 'backup_last_error', $error->getMessage(), null);
    }
    backup_cli_fail($error);
}
