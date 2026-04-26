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

session_name((string) $config['session_name']);
session_set_cookie_params([
    'lifetime' => 60 * 60 * 24 * 30,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
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
require_once __DIR__ . '/challenge.php';
require_once __DIR__ . '/view.php';

$pdo = db_connect($config);
