<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';

if (!is_dir((string) $config['upload_dir'])) {
    mkdir((string) $config['upload_dir'], 0775, true);
}

if (!is_dir(dirname((string) $config['db_path']))) {
    mkdir(dirname((string) $config['db_path']), 0775, true);
}

date_default_timezone_set((string) $config['timezone']);

$rememberCookieName = trim((string) ($config['remember_me_cookie'] ?? 'fitness_challenge_remember'));
if ($rememberCookieName === '') {
    $rememberCookieName = 'fitness_challenge_remember';
}
$rememberCookieValue = strtolower(trim((string) ($_COOKIE[$rememberCookieName] ?? '')));
$rememberMeEnabled = in_array($rememberCookieValue, ['1', 'true', 'yes', 'on'], true);
$rememberLifetime = max(1, (int) ($config['remember_me_lifetime'] ?? (60 * 60 * 24 * 30)));
$sessionLifetime = $rememberMeEnabled ? $rememberLifetime : 0;
$forwardedProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
$cookieSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ($forwardedProto !== '' && trim(explode(',', $forwardedProto)[0]) === 'https');

session_name((string) $config['session_name']);
session_set_cookie_params([
    'lifetime' => $sessionLifetime,
    'path' => '/',
    'secure' => $cookieSecure,
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/services.php';
require_once __DIR__ . '/metrics.php';
require_once __DIR__ . '/dashboard_preferences.php';
require_once __DIR__ . '/profile_widgets.php';
require_once __DIR__ . '/media_search.php';
require_once __DIR__ . '/challenge.php';
require_once __DIR__ . '/notion.php';
require_once __DIR__ . '/telegram.php';
require_once __DIR__ . '/friends.php';
require_once __DIR__ . '/duels.php';
require_once __DIR__ . '/squads.php';
require_once __DIR__ . '/workouts.php';
require_once __DIR__ . '/privacy.php';
require_once __DIR__ . '/xp.php';
require_once __DIR__ . '/quests.php';
require_once __DIR__ . '/view.php';

// Acquire the request-side shared lock before opening SQLite or running schema
// checks. During backup/restore this same handle is upgraded to exclusive, then
// downgraded for the remainder of the request.
if (PHP_SAPI !== 'cli') {
    $requestMaintenanceHandle = system_maintenance_acquire($config, false, true);
    if ($requestMaintenanceHandle === false) {
        http_response_code(503);
        header('Retry-After: 5');
        echo 'Maintenance in progress. Please try again.';
        exit;
    }
    $GLOBALS['request_maintenance_handle'] = $requestMaintenanceHandle;
    $GLOBALS['request_maintenance_exclusive'] = false;
    register_shutdown_function('system_request_maintenance_release');
}

$pdo = db_connect($config);
metric_preferences_backfill($pdo);
privacy_ensure_schema($pdo);
profile_custom_widgets_ensure_schema($pdo);
// Social achievements are evaluated from several routes, including dashboard
// and team. Their backing tables must exist before route-level work starts.
friends_ensure_schema($pdo);
duels_ensure_schema($pdo);
squads_ensure_schema($pdo);
