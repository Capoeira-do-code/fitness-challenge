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
    'remember_me_cookie' => env_value('REMEMBER_ME_COOKIE', 'fitness_challenge_remember'),
    'remember_me_lifetime' => (int) env_value('REMEMBER_ME_LIFETIME', (string) (60 * 60 * 24 * 30)),
    'default_locale' => env_value('APP_DEFAULT_LOCALE', 'en'),
    'db_path' => env_value('DB_PATH', $basePath . '/storage/fitness.sqlite'),
    'upload_dir' => env_value('UPLOAD_DIR', $basePath . '/storage/uploads'),
    'upload_web_path' => env_value('UPLOAD_WEB_PATH', '/uploads'),
    'photo_upload_max_bytes' => (int) env_value('PHOTO_UPLOAD_MAX_BYTES', '15728640'),
    'media_debug' => env_value('MEDIA_DEBUG', '0'),
    'app_cache_enabled' => env_value('APP_CACHE_ENABLED', '1'),
    'app_profile_enabled' => env_value('APP_PROFILE', '0'),
    'db_slow_query_ms' => (float) env_value('DB_SLOW_QUERY_MS', '50'),
    'challenge_start' => env_value('CHALLENGE_START', '2026-04-13'),
    'challenge_end' => env_value('CHALLENGE_END', '2026-06-07'),
    // Seed accounts are opt-in. Existing databases are unaffected.
    'seed_password' => env_value('SEED_PASSWORD', ''),
    'request_schedulers_enabled' => env_value('REQUEST_SCHEDULERS_ENABLED', '0') === '1',
];
