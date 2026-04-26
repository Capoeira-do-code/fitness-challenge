<?php

declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function now_iso(): string
{
    return (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
}

function to_date(?string $date, ?string $fallback = null): string
{
    if ($date !== null) {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if ($parsed !== false) {
            return $parsed->format('Y-m-d');
        }
    }

    if ($fallback !== null) {
        return $fallback;
    }

    return (new DateTimeImmutable('now'))->format('Y-m-d');
}

function week_to_monday(?string $week, ?string $fallback = null): string
{
    $value = trim((string) $week);
    if ($value !== '' && preg_match('/^(\d{4})-W(\d{2})$/', $value, $matches) === 1) {
        $year = (int) $matches[1];
        $isoWeek = (int) $matches[2];
        $date = (new DateTimeImmutable())->setISODate($year, $isoWeek, 1);

        return $date->format('Y-m-d');
    }

    return to_date(null, $fallback);
}

function date_to_iso_week(string $date): string
{
    try {
        return (new DateTimeImmutable($date))->format('o-\WW');
    } catch (Throwable) {
        return (new DateTimeImmutable('monday this week'))->format('o-\WW');
    }
}

function random_motivation_quote(): string
{
    $quotes = [
        'Consistency is the shortcut.',
        'Do what your future self will thank you for.',
        'Small wins compound faster than motivation fades.',
        'Discipline builds the life motivation dreams about.',
        'Show up first, improve second.',
        'Momentum loves repetition.',
        'Keep promises to yourself.',
        'Progress prefers patience.',
        'A hard day still counts.',
        'One clean decision changes the whole day.',
        'Done beats perfect every single time.',
        'You are building evidence, not just results.',
    ];

    return $quotes[array_rand($quotes)];
}

function format_date_eu(?string $date): string
{
    if ($date === null || $date === '') {
        return '';
    }

    try {
        return (new DateTimeImmutable($date))->format('d/m/Y');
    } catch (Throwable) {
        return $date;
    }
}

function initials_for(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $initials = '';
    foreach ($parts as $part) {
        if ($part !== '') {
            $initials .= strtoupper(substr($part, 0, 1));
        }
        if (strlen($initials) >= 2) {
            break;
        }
    }

    return $initials !== '' ? $initials : '?';
}

function with_cache_buster(string $path, mixed $version = null): string
{
    $cleanPath = trim($path);
    if ($cleanPath === '') {
        return '';
    }

    if ($version === null || $version === '') {
        return $cleanPath;
    }

    $separator = str_contains($cleanPath, '?') ? '&' : '?';

    return $cleanPath . $separator . 'v=' . rawurlencode((string) $version);
}

function media_url(?string $path, mixed $version = null): string
{
    $rawPath = trim((string) $path);
    if ($rawPath === '') {
        return '';
    }

    if (str_starts_with($rawPath, '/?page=media&path=')) {
        return with_cache_buster($rawPath, $version);
    }

    $url = '/?page=media&path=' . rawurlencode($rawPath);

    return with_cache_buster($url, $version);
}

function avatar_url(array $user): string
{
    $avatarPath = trim((string) ($user['avatar_path'] ?? ''));
    if ($avatarPath === '') {
        return '';
    }

    $version = $user['updated_at'] ?? null;
    if (is_string($version) && $version !== '') {
        $timestamp = strtotime($version);
        if ($timestamp !== false) {
            $version = (string) $timestamp;
        }
    }

    return media_url($avatarPath, $version);
}

function weekday_index(string $date): int
{
    $d = new DateTimeImmutable($date);

    return (int) $d->format('N') - 1;
}

function mask_allows_day(string $mask, string $date): bool
{
    $idx = weekday_index($date);

    return isset($mask[$idx]) && $mask[$idx] === '1';
}

function bool_from_form(string $key): int
{
    return isset($_POST[$key]) && $_POST[$key] === '1' ? 1 : 0;
}

function flash_set(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function flash_get(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $flash;
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_verify(): bool
{
    $token = $_POST['csrf_token'] ?? '';
    $stored = $_SESSION['csrf_token'] ?? '';

    return is_string($token) && is_string($stored) && hash_equals($stored, $token);
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
