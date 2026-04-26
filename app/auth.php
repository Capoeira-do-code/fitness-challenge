<?php

declare(strict_types=1);

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
