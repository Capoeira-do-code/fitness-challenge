<?php

declare(strict_types=1);

function auth_cookie_secure_flag(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }

    $forwardedProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    return $forwardedProto !== '' && trim(explode(',', $forwardedProto)[0]) === 'https';
}

function remember_me_cookie_name(array $config): string
{
    $cookieName = trim((string) ($config['remember_me_cookie'] ?? 'fitness_challenge_remember'));
    return $cookieName !== '' ? $cookieName : 'fitness_challenge_remember';
}

function remember_me_lifetime(array $config): int
{
    return max(1, (int) ($config['remember_me_lifetime'] ?? (60 * 60 * 24 * 30)));
}

function remember_me_cookie_is_enabled(array $config): bool
{
    $cookieName = remember_me_cookie_name($config);
    $cookieValue = strtolower(trim((string) ($_COOKIE[$cookieName] ?? '')));
    return in_array($cookieValue, ['1', 'true', 'yes', 'on'], true);
}

function set_remember_me_cookie(array $config, bool $enabled): void
{
    $cookieName = remember_me_cookie_name($config);
    $options = [
        'path' => '/',
        'secure' => auth_cookie_secure_flag(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];

    if ($enabled) {
        $options['expires'] = time() + remember_me_lifetime($config);
        setcookie($cookieName, '1', $options);
        return;
    }

    $options['expires'] = time() - 42000;
    setcookie($cookieName, '', $options);
}

function sync_session_cookie_lifetime(array $config, bool $rememberMe): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $options = [
        'path' => '/',
        'secure' => auth_cookie_secure_flag(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    if ($rememberMe) {
        $options['expires'] = time() + remember_me_lifetime($config);
    } else {
        $options['expires'] = 0;
    }

    setcookie(session_name(), session_id(), $options);
}

function current_user(PDO $pdo): ?array
{
    $userId = $_SESSION['user_id'] ?? null;
    if (!is_int($userId) && !is_string($userId)) {
        return null;
    }

    $row = db_fetch_one(
        $pdo,
        'SELECT * FROM users WHERE id = :id AND active = 1',
        [':id' => (int) $userId]
    );

    return $row;
}

function login_user(PDO $pdo, string $username, string $password): bool
{
    $user = db_fetch_one(
        $pdo,
        'SELECT * FROM users WHERE username = :username AND active = 1',
        [':username' => $username]
    );

    if ($user === null) {
        return false;
    }

    if (!password_verify($password, $user['password_hash'])) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['locale'] = normalize_locale((string) ($user['locale'] ?? 'en'), 'en');

    return true;
}

function request_ip_address(): string
{
    $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    return $remote !== '' ? $remote : 'unknown';
}

function login_attempt_is_blocked(PDO $pdo, string $username, string $ipAddress, int $maxAttempts = 5, int $windowMinutes = 15): bool
{
    $cutoff = (new DateTimeImmutable('-' . $windowMinutes . ' minutes'))->format('Y-m-d H:i:s');
    db_execute(
        $pdo,
        'DELETE FROM login_attempts WHERE attempted_at < :cutoff',
        [':cutoff' => $cutoff]
    );
    $row = db_fetch_one(
        $pdo,
        'SELECT COUNT(*) AS total
         FROM login_attempts
         WHERE username = :username
           AND ip_address = :ip_address
           AND attempted_at >= :cutoff',
        [
            ':username' => strtolower(trim($username)),
            ':ip_address' => $ipAddress,
            ':cutoff' => $cutoff,
        ]
    );

    return (int) ($row['total'] ?? 0) >= $maxAttempts;
}

function register_failed_login_attempt(PDO $pdo, string $username, string $ipAddress): void
{
    db_execute(
        $pdo,
        'INSERT INTO login_attempts (username, ip_address, attempted_at)
         VALUES (:username, :ip_address, :attempted_at)',
        [
            ':username' => strtolower(trim($username)),
            ':ip_address' => $ipAddress,
            ':attempted_at' => now_iso(),
        ]
    );
}

function clear_login_attempts(PDO $pdo, string $username, string $ipAddress): void
{
    db_execute(
        $pdo,
        'DELETE FROM login_attempts WHERE username = :username AND ip_address = :ip_address',
        [
            ':username' => strtolower(trim($username)),
            ':ip_address' => $ipAddress,
        ]
    );
}

function logout_user(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }

    session_destroy();
}

function require_login(PDO $pdo): array
{
    $user = current_user($pdo);
    if ($user === null) {
        flash_set('error', t('auth.login_required'));
        redirect('/?page=login');
    }

    return $user;
}

function require_admin(array $user): void
{
    if (($user['role'] ?? 'user') !== 'admin') {
        flash_set('error', t('auth.admin_required'));
        redirect('/?page=dashboard');
    }
}

function is_admin(array $user): bool
{
    return ($user['role'] ?? 'user') === 'admin';
}
