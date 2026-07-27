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
    'media_search_google_api_key' => env_value('MEDIA_SEARCH_GOOGLE_API_KEY', ''),
    'media_search_google_cx' => env_value('MEDIA_SEARCH_GOOGLE_CX', ''),
    'media_search_youtube_api_key' => env_value('MEDIA_SEARCH_YOUTUBE_API_KEY', env_value('MEDIA_SEARCH_GOOGLE_API_KEY', '')),
    'media_search_image_max_bytes' => (int) env_value('MEDIA_SEARCH_IMAGE_MAX_BYTES', '8388608'),
    'media_debug' => env_value('MEDIA_DEBUG', '0'),
    'app_cache_enabled' => env_value('APP_CACHE_ENABLED', '1'),
    'app_profile_enabled' => env_value('APP_PROFILE', '0'),
    'db_slow_query_ms' => (float) env_value('DB_SLOW_QUERY_MS', '50'),
    // Forwarded headers are honored only when the immediate peer is trusted.
    // Docker adds its private proxy range explicitly in docker-compose.yml.
    'security_trusted_proxies' => env_value('SECURITY_TRUSTED_PROXIES', '127.0.0.1,::1'),
    // A comma-separated allowlist enables strict Host-header enforcement.
    // It can also be managed from Admin > Security when this env value is empty.
    'security_allowed_hosts' => env_value('APP_ALLOWED_HOSTS', ''),
    'security_auto_block' => env_value('SECURITY_AUTO_BLOCK', null),
    'security_log_retention_days' => (int) env_value('SECURITY_LOG_RETENTION_DAYS', '0'),
    'security_rate_limit_per_minute' => (int) env_value('SECURITY_RATE_LIMIT_PER_MINUTE', '240'),
    'security_scan_threshold' => (int) env_value('SECURITY_SCAN_THRESHOLD', '5'),
    'security_block_minutes' => (int) env_value('SECURITY_BLOCK_MINUTES', '60'),
    'challenge_start' => env_value('CHALLENGE_START', '2026-04-13'),
    'challenge_end' => env_value('CHALLENGE_END', '2026-06-07'),
    // Seed accounts are opt-in. Existing databases are unaffected.
    'seed_password' => env_value('SEED_PASSWORD', ''),
    'request_schedulers_enabled' => env_value('REQUEST_SCHEDULERS_ENABLED', '0') === '1',
];
