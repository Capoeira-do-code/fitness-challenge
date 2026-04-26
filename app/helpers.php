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
