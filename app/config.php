<?php

declare(strict_types=1);

function env_value(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }

    return $value;
}

$basePath = dirname(__DIR__);

return [
    'app_name' => env_value('APP_NAME', 'Fitness Challenge Tracker'),
    'timezone' => env_value('APP_TIMEZONE', 'Europe/Madrid'),
    'session_name' => env_value('SESSION_NAME', 'fitness_challenge_session'),
    'default_locale' => env_value('APP_DEFAULT_LOCALE', 'en'),
    'db_path' => env_value('DB_PATH', $basePath . '/storage/fitness.sqlite'),
    'upload_dir' => env_value('UPLOAD_DIR', $basePath . '/storage/uploads'),
    'upload_web_path' => env_value('UPLOAD_WEB_PATH', '/uploads'),
    'photo_upload_max_bytes' => (int) env_value('PHOTO_UPLOAD_MAX_BYTES', '15728640'),
    'media_debug' => env_value('MEDIA_DEBUG', '0'),
    'challenge_start' => env_value('CHALLENGE_START', '2026-04-13'),
    'challenge_end' => env_value('CHALLENGE_END', '2026-06-07'),
    'seed_password' => env_value('SEED_PASSWORD', 'ChangeMe123!'),
];
