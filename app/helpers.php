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

function default_motivation_quotes(): array
{
    return [
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
}

function random_motivation_quote(): string
{
    $quotes = default_motivation_quotes();

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

function media_debug_enabled(): bool
{
    $configFlag = $GLOBALS['config']['media_debug'] ?? null;
    if (is_bool($configFlag)) {
        return $configFlag;
    }

    $raw = strtolower(trim((string) ($configFlag ?? getenv('MEDIA_DEBUG') ?: '0')));

    return in_array($raw, ['1', 'true', 'yes', 'on'], true);
}

function media_debug_template_from_trace(array $trace): string
{
    foreach ($trace as $frame) {
        $file = (string) ($frame['file'] ?? '');
        if ($file !== '' && str_contains(str_replace('\\', '/', $file), '/app/views/')) {
            return $file;
        }
    }

    return (string) ($trace[0]['file'] ?? '');
}

function media_debug_log(string $helper, array $payload): void
{
    if (!media_debug_enabled()) {
        return;
    }

    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20);
    $template = media_debug_template_from_trace($trace);
    $record = array_merge(
        [
            'helper' => $helper,
            'template' => $template,
        ],
        $payload
    );

    $encoded = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        $encoded = print_r($record, true);
    }

    error_log('[media-debug] ' . $encoded);
}

function media_decode_reference_value(string $value, int $maxIterations = 3): string
{
    $decoded = $value;
    $max = max(1, min(5, $maxIterations));
    for ($i = 0; $i < $max; $i++) {
        $next = rawurldecode($decoded);
        if ($next === $decoded) {
            break;
        }
        $decoded = $next;
    }

    return $decoded;
}

function media_extract_query_path(string $queryPart): string
{
    $query = [];
    parse_str($queryPart, $query);
    $innerPath = isset($query['path']) && is_string($query['path']) ? trim((string) $query['path']) : '';
    if ($innerPath === '') {
        return '';
    }

    return media_decode_reference_value($innerPath);
}

function media_has_upload_marker(string $value): bool
{
    $normalized = strtolower(str_replace('\\', '/', $value));
    $markers = [
        '/storage/uploads/',
        '/public/uploads/',
        '/var/www/storage/uploads/',
        '/var/www/public/uploads/',
        '/uploads/',
        'storage/uploads/',
        'public/uploads/',
        'uploads/',
    ];
    foreach ($markers as $marker) {
        if (str_contains($normalized, $marker)) {
            return true;
        }
    }

    return false;
}

function media_extract_relative_path(string $value): string
{
    $normalizedValue = str_replace('\\', '/', $value);
    $normalizedValue = preg_replace('~/+~', '/', $normalizedValue) ?? $normalizedValue;
    $normalizedValue = media_decode_reference_value($normalizedValue);
    $lowerValue = strtolower($normalizedValue);
    $markers = [
        '/var/www/storage/uploads/',
        '/var/www/public/uploads/',
        '/storage/uploads/',
        '/public/uploads/',
        '/uploads/',
        'storage/uploads/',
        'public/uploads/',
        'uploads/',
    ];

    $bestPos = null;
    $bestMarker = '';
    foreach ($markers as $marker) {
        $pos = strpos($lowerValue, strtolower($marker));
        if ($pos === false) {
            continue;
        }
        if ($bestPos === null || $pos < $bestPos || ($pos === $bestPos && strlen($marker) > strlen($bestMarker))) {
            $bestPos = $pos;
            $bestMarker = $marker;
        }
    }

    if ($bestPos !== null) {
        return (string) substr($normalizedValue, (int) $bestPos + strlen($bestMarker));
    }

    return ltrim($normalizedValue, '/');
}

function media_is_absolute_filesystem_path(string $value): bool
{
    return preg_match('~^(?:[a-zA-Z]:[\\\\/]|/)~', $value) === 1;
}

function normalize_media_reference(?string $reference): array
{
    $raw = trim((string) $reference);
    if ($raw === '') {
        return [
            'kind' => 'empty',
            'raw' => $raw,
            'normalized' => '',
            'url' => '',
        ];
    }

    $candidate = media_decode_reference_value(str_replace('\\', '/', $raw));
    $isAbsoluteUrlInput = preg_match('~^https?://~i', $candidate) === 1;
    if ($isAbsoluteUrlInput) {
        $absoluteUrl = $candidate;
        $parsed = parse_url($absoluteUrl);
        if (is_array($parsed)) {
            $queryPart = (string) ($parsed['query'] ?? '');
            $query = [];
            if ($queryPart !== '') {
                parse_str($queryPart, $query);
            }
            $queryPage = strtolower(trim((string) ($query['page'] ?? '')));
            $innerPath = $queryPart !== '' ? media_extract_query_path($queryPart) : '';
            if ($innerPath !== '') {
                $candidate = $innerPath;
            } elseif ($queryPage === 'media') {
                $candidate = '';
            } else {
                $pathPart = media_decode_reference_value((string) ($parsed['path'] ?? ''));
                $pathPart = str_replace('\\', '/', $pathPart);
                $assetLike = str_starts_with(ltrim($pathPart, '/'), 'assets/');
                if ($pathPart === '' || (!media_has_upload_marker($pathPart) && !$assetLike)) {
                    return [
                        'kind' => 'absolute_url',
                        'raw' => $raw,
                        'normalized' => $absoluteUrl,
                        'url' => $absoluteUrl,
                    ];
                }
                $candidate = $pathPart;
            }
        }
    }

    if (str_starts_with($candidate, '/?page=media') || str_starts_with($candidate, '?page=media')) {
        $queryPart = (string) parse_url($candidate, PHP_URL_QUERY);
        $innerPath = media_extract_query_path($queryPart);
        $candidate = $innerPath;
    }

    if (preg_match('~^https?://~i', $candidate) === 1) {
        return [
            'kind' => 'absolute_url',
            'raw' => $raw,
            'normalized' => $candidate,
            'url' => $candidate,
        ];
    }

    $clean = preg_replace('/[#?].*$/', '', $candidate) ?? '';
    $clean = trim(media_decode_reference_value(str_replace('\\', '/', $clean)));
    if ($clean === '') {
        return [
            'kind' => 'empty',
            'raw' => $raw,
            'normalized' => '',
            'url' => '',
        ];
    }

    $assetPath = ltrim(media_extract_relative_path($clean), '/');
    if (str_starts_with($assetPath, 'assets/')) {
        return [
            'kind' => 'asset',
            'raw' => $raw,
            'normalized' => $assetPath,
            'url' => '/' . $assetPath,
        ];
    }

    if (media_is_absolute_filesystem_path($clean) && !media_has_upload_marker($clean)) {
        return [
            'kind' => 'invalid',
            'raw' => $raw,
            'normalized' => '',
            'url' => '',
        ];
    }

    $normalized = ltrim(media_extract_relative_path($clean), '/');
    $normalized = trim($normalized);
    if ($normalized !== '') {
        $segments = explode('/', str_replace('\\', '/', $normalized));
        $safeSegments = [];
        foreach ($segments as $segment) {
            $piece = trim($segment);
            if ($piece === '') {
                continue;
            }
            if ($piece === '.' || $piece === '..' || str_contains($piece, ':')) {
                return [
                    'kind' => 'invalid',
                    'raw' => $raw,
                    'normalized' => '',
                    'url' => '',
                ];
            }
            $safeSegments[] = $piece;
        }
        $normalized = implode('/', $safeSegments);
    }

    if ($normalized === '' || str_contains($normalized, '..')) {
        if ($isAbsoluteUrlInput) {
            return [
                'kind' => 'absolute_url',
                'raw' => $raw,
                'normalized' => $candidate,
                'url' => $candidate,
            ];
        }

        if (media_is_absolute_filesystem_path($clean)) {
            return [
                'kind' => 'invalid',
                'raw' => $raw,
                'normalized' => '',
                'url' => '',
            ];
        }

        return [
            'kind' => 'invalid',
            'raw' => $raw,
            'normalized' => '',
            'url' => '',
        ];
    }

    return [
        'kind' => 'media',
        'raw' => $raw,
        'normalized' => $normalized,
        'url' => '/?page=media&path=' . rawurlencode($normalized),
    ];
}

function media_url(?string $path, mixed $version = null): string
{
    $normalized = normalize_media_reference($path);
    $url = '';
    if (($normalized['kind'] ?? '') === 'media' || ($normalized['kind'] ?? '') === 'asset' || ($normalized['kind'] ?? '') === 'absolute_url') {
        $url = with_cache_buster((string) ($normalized['url'] ?? ''), $version);
    }

    media_debug_log('media_url', [
        'stored_value' => (string) $path,
        'helper_input' => (string) $path,
        'normalized_value' => (string) ($normalized['normalized'] ?? ''),
        'normalized_kind' => (string) ($normalized['kind'] ?? ''),
        'final_url' => $url,
    ]);

    return $url;
}

function avatar_url(array $user): string
{
    $avatarPath = trim((string) ($user['avatar_path'] ?? ''));
    if ($avatarPath === '') {
        return '';
    }

    if (
        function_exists('resolve_media_storage_path')
        && isset($GLOBALS['config'])
        && is_array($GLOBALS['config'])
    ) {
        $resolvedPath = resolve_media_storage_path((array) $GLOBALS['config'], $avatarPath);
        if ($resolvedPath === null || !is_file($resolvedPath)) {
            media_debug_log('avatar_url', [
                'stored_value' => $avatarPath,
                'helper_input' => $avatarPath,
                'normalized_value' => (string) (normalize_media_reference($avatarPath)['normalized'] ?? ''),
                'final_url' => '',
                'reason' => 'resolved_path_missing',
            ]);

            return '';
        }
    }

    $version = $user['updated_at'] ?? null;
    if (is_string($version) && $version !== '') {
        $timestamp = strtotime($version);
        if ($timestamp !== false) {
            $version = (string) $timestamp;
        }
    }

    $avatarUrl = media_url($avatarPath, $version);
    media_debug_log('avatar_url', [
        'stored_value' => $avatarPath,
        'helper_input' => $avatarPath,
        'normalized_value' => (string) (normalize_media_reference($avatarPath)['normalized'] ?? ''),
        'final_url' => $avatarUrl,
    ]);

    return $avatarUrl;
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
