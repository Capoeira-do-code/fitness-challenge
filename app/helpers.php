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

function calendar_date_from_request(array $source, string $calendarView, ?string $fallback = null): string
{
    if ($calendarView === 'month') {
        $month = trim((string) ($source['calendar_month'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
            return to_date($month . '-01', $fallback);
        }
    }

    if ($calendarView === 'week') {
        $week = trim((string) ($source['calendar_week'] ?? ''));
        if (preg_match('/^\d{4}-W\d{2}$/', $week) === 1) {
            return week_to_monday($week, $fallback);
        }
    }

    return to_date(isset($source['date']) ? (string) $source['date'] : null, $fallback);
}

function date_to_iso_week(string $date): string
{
    try {
        return (new DateTimeImmutable($date))->format('o-\WW');
    } catch (Throwable) {
        return (new DateTimeImmutable('monday this week'))->format('o-\WW');
    }
}

function localized_month_label(string $date): string
{
    try {
        $dt = new DateTimeImmutable($date);
    } catch (Throwable) {
        $dt = new DateTimeImmutable('today');
    }

    $month = (int) $dt->format('n');
    $year = $dt->format('Y');
    $locale = current_locale();
    $months = str_starts_with($locale, 'es')
        ? [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre']
        : (
            str_starts_with($locale, 'it')
                ? [1 => 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre']
                : [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']
        );

    return ($months[$month] ?? $dt->format('F')) . ' ' . $year;
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

function media_thumbnail_supported(?string $mime = null): bool
{
    if (!function_exists('imagecreatetruecolor') || (!function_exists('imagewebp') && !function_exists('imagejpeg'))) {
        return false;
    }

    if ($mime === null || $mime === '') {
        return true;
    }

    $loaders = [
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png' => 'imagecreatefrompng',
        'image/webp' => 'imagecreatefromwebp',
        'image/gif' => 'imagecreatefromgif',
    ];

    return isset($loaders[$mime]) && function_exists($loaders[$mime]);
}

function media_thumbnail_url(?string $path, int $width = 360): string
{
    $normalized = normalize_media_reference($path);
    if (($normalized['kind'] ?? '') !== 'media') {
        return media_url($path);
    }

    $version = null;
    if (
        function_exists('resolve_media_storage_path')
        && isset($GLOBALS['config'])
        && is_array($GLOBALS['config'])
    ) {
        $resolvedPath = resolve_media_storage_path((array) $GLOBALS['config'], (string) ($normalized['normalized'] ?? ''));
        if ($resolvedPath !== null && is_file($resolvedPath)) {
            $mtime = @filemtime($resolvedPath);
            if ($mtime !== false) {
                $version = (string) $mtime;
            }
            $mime = function_exists('detect_media_mime_type') ? detect_media_mime_type($resolvedPath) : null;
            if (!media_thumbnail_supported($mime)) {
                return media_url((string) ($normalized['normalized'] ?? ''), $version);
            }
        }
    }

    if (!media_thumbnail_supported()) {
        return media_url((string) ($normalized['normalized'] ?? ''), $version);
    }

    $url = '/?page=media_thumb&path=' . rawurlencode((string) ($normalized['normalized'] ?? '')) . '&w=' . max(80, min(1200, $width));

    return with_cache_buster($url, $version);
}

function media_thumbnail_srcset(?string $path, array $widths = [200, 400, 800]): string
{
    $items = [];
    foreach ($widths as $width) {
        $safeWidth = max(80, min(1200, (int) $width));
        if ($safeWidth <= 0 || isset($items[$safeWidth])) {
            continue;
        }
        $url = media_thumbnail_url($path, $safeWidth);
        if ($url !== '') {
            $items[$safeWidth] = $url . ' ' . $safeWidth . 'w';
        }
    }

    return implode(', ', array_values($items));
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

function achievement_icon_options(): array
{
    return [
        'trophy' => 'Trophy',
        'medal' => 'Medal',
        'target' => 'Target',
        'footprints' => 'Footprints',
        'dumbbell' => 'Dumbbell',
        'flame' => 'Flame',
        'camera' => 'Camera',
        'calendar-check' => 'Calendar check',
        'shield-check' => 'Shield check',
        'users' => 'Users',
        'flag' => 'Flag',
        'sparkles' => 'Sparkles',
        'star' => 'Star',
        'crown' => 'Crown',
        'heart' => 'Heart',
        'bolt' => 'Lightning',
        'mountain' => 'Mountain',
        'timer' => 'Timer',
        'rocket' => 'Rocket',
        'gem' => 'Gem',
        'leaf' => 'Leaf',
        'bike' => 'Bike',
        'award' => 'Award ribbon',
        'moon' => 'Moon',
        'sunrise' => 'Sunrise',
        'apple' => 'Apple',
    ];
}

function normalize_achievement_icon_key(?string $key): string
{
    $key = trim((string) $key);

    return array_key_exists($key, achievement_icon_options()) ? $key : 'trophy';
}

function achievement_icon_svg(?string $key): string
{
    $key = normalize_achievement_icon_key($key);
    $common = 'viewBox="0 0 24 24" aria-hidden="true" focusable="false"';

    return match ($key) {
        'medal' => '<svg ' . $common . '><path d="m8 2 4 7 4-7"/><path d="M12 9a6 6 0 1 0 0 12 6 6 0 0 0 0-12Z"/><path d="m10.2 14.1 1.8-.95 1.8.95-.34-2 1.46-1.42-2.02-.3L12 8.55l-.9 1.83-2.02.3 1.46 1.42-.34 2Z"/></svg>',
        'target' => '<svg ' . $common . '><circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="4"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/></svg>',
        'footprints' => '<svg ' . $common . '><path d="M4.5 12.5c1.8.2 3.1 1.6 2.8 3.2-.2 1.3-1.4 2.2-2.7 2-1.5-.2-2.5-1.6-2.2-3 .2-1.1.9-2.2 2.1-2.2Z"/><path d="M9.2 4.4c1.6.2 2.7 1.5 2.5 2.9-.2 1.2-1.2 2-2.4 1.9C8 9 7.1 7.8 7.3 6.5c.2-1 1-2.2 1.9-2.1Z"/><path d="M19.5 11.5c-1.8.2-3.1 1.6-2.8 3.2.2 1.3 1.4 2.2 2.7 2 1.5-.2 2.5-1.6 2.2-3-.2-1.1-.9-2.2-2.1-2.2Z"/><path d="M14.8 3.4c-1.6.2-2.7 1.5-2.5 2.9.2 1.2 1.2 2 2.4 1.9C16 8 16.9 6.8 16.7 5.5c-.2-1-1-2.2-1.9-2.1Z"/></svg>',
        'dumbbell' => '<svg ' . $common . '><path d="M6 6v12M18 6v12M3 9v6M21 9v6M6 12h12"/></svg>',
        'flame' => '<svg ' . $common . '><path d="M12 22c4 0 7-2.8 7-6.7 0-2.5-1.4-4.8-3.4-6.6-.9 2.2-2.4 3.1-2.4 3.1.4-3.1-1.2-6.2-4-8.8.2 3-1.3 4.9-2.7 6.6C5.1 11.4 5 13 5 15.3 5 19.2 8 22 12 22Z"/></svg>',
        'camera' => '<svg ' . $common . '><path d="M5 7h3l1.5-2h5L16 7h3a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2Z"/><circle cx="12" cy="13" r="3"/></svg>',
        'calendar-check' => '<svg ' . $common . '><path d="M8 2v4M16 2v4M3 10h18"/><rect x="3" y="4" width="18" height="18" rx="2"/><path d="m8 16 2.5 2.5L16 13"/></svg>',
        'shield-check' => '<svg ' . $common . '><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m8.5 12.5 2.2 2.2 4.8-5"/></svg>',
        'users' => '<svg ' . $common . '><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'flag' => '<svg ' . $common . '><path d="M4 22V4"/><path d="M4 5h11l-.8 4 5.8 1v8H4"/></svg>',
        'sparkles' => '<svg ' . $common . '><path d="m12 3 1.7 4.3L18 9l-4.3 1.7L12 15l-1.7-4.3L6 9l4.3-1.7L12 3Z"/><path d="m19 14 .9 2.1L22 17l-2.1.9L19 20l-.9-2.1L16 17l2.1-.9L19 14Z"/><path d="m5 14 .9 2.1L8 17l-2.1.9L5 20l-.9-2.1L2 17l2.1-.9L5 14Z"/></svg>',
        'star' => '<svg ' . $common . '><path d="m12 2 3.1 6.3 6.9 1-5 4.9 1.2 6.8-6.2-3.3-6.2 3.3L7 14.2l-5-4.9 6.9-1L12 2Z"/></svg>',
        'crown' => '<svg ' . $common . '><path d="M3 7l4 4 5-6 5 6 4-4v11H3V7Z"/><path d="M3 20h18"/></svg>',
        'heart' => '<svg ' . $common . '><path d="M12 21s-7-4.5-9.5-9C1 9 2.5 5.5 6 5.5c2 0 3.2 1.2 4 2.3.8-1.1 2-2.3 4-2.3 3.5 0 5 3.5 3.5 6.5C19 16.5 12 21 12 21Z"/></svg>',
        'bolt' => '<svg ' . $common . '><path d="M13 2 4 14h7l-1 8 9-12h-7l1-8Z"/></svg>',
        'mountain' => '<svg ' . $common . '><path d="m3 20 6-11 4 6 2-3 6 8H3Z"/><circle cx="17" cy="6" r="2"/></svg>',
        'timer' => '<svg ' . $common . '><path d="M9 2h6"/><path d="M12 8v6l4 2"/><circle cx="12" cy="14" r="8"/></svg>',
        'rocket' => '<svg ' . $common . '><path d="M5 15c-1.5 1.3-2 5-2 5s3.7-.5 5-2c.8-.9.8-2.2 0-3-.8-.8-2.1-.8-3 0Z"/><path d="M9 15 4.5 10.5C6 5 10 3 15 3c0 5-2 9-7.5 10.5L9 15Z"/><circle cx="14" cy="8" r="1.5"/></svg>',
        'gem' => '<svg ' . $common . '><path d="M6 3h12l3 6-9 12L3 9l3-6Z"/><path d="M3 9h18M9 3 6 9l6 12M15 3l3 6-6 12"/></svg>',
        'leaf' => '<svg ' . $common . '><path d="M4 20c8 2 16-4 16-16C10 4 4 10 4 20Z"/><path d="M4 20C8 14 12 11 18 8"/></svg>',
        'bike' => '<svg ' . $common . '><circle cx="6" cy="17" r="3.5"/><circle cx="18" cy="17" r="3.5"/><path d="M6 17 10 8h4l3 9M9 8h5"/></svg>',
        'award' => '<svg ' . $common . '><circle cx="12" cy="9" r="6"/><path d="m9 14-2 8 5-3 5 3-2-8"/></svg>',
        'moon' => '<svg ' . $common . '><path d="M21 12.8A8 8 0 1 1 11.2 3 6.2 6.2 0 0 0 21 12.8Z"/></svg>',
        'sunrise' => '<svg ' . $common . '><path d="M12 3v5M5.6 10.6 4 9M18.4 10.6 20 9M2 18h20M4 14a8 8 0 0 1 16 0"/><path d="m8 6 4-4 4 4"/></svg>',
        'apple' => '<svg ' . $common . '><path d="M12 7c-1.5-2-5-2-6.5 0S4 13 6 17c1 2 2.5 3 3.5 2.3 1-.7 1.5-.7 2.5 0s2.5.3 3.5-1.7c2-4 1-8-.5-10.6S13.5 5 12 7Z"/><path d="M12 7c0-2 1-3.5 3-4"/></svg>',
        default => '<svg ' . $common . '><path d="M8 21h8"/><path d="M12 17v4"/><path d="M7 4h10v4a5 5 0 0 1-10 0V4Z"/><path d="M17 6h2a2 2 0 0 1 0 4h-2"/><path d="M7 6H5a2 2 0 0 0 0 4h2"/></svg>',
    };
}

function achievement_visual_html(array $achievement, string $className = 'achievement-visual'): string
{
    $name = (string) ($achievement['name'] ?? '');
    $imageUrl = '';
    if (!empty($achievement['image_path'])) {
        $imageUrl = media_url((string) ($achievement['image_path'] ?? ''));
    }

    if ($imageUrl !== '') {
        return '<div class="' . e($className) . ' achievement-visual-image"><img src="' . e($imageUrl) . '" alt="' . e($name) . '"></div>';
    }

    return '<div class="' . e($className) . ' achievement-visual-icon">' . achievement_icon_svg((string) ($achievement['icon_key'] ?? 'trophy')) . '</div>';
}

function achievement_modal_attrs(array $achievement): string
{
    $isUnlocked = array_key_exists('is_unlocked', $achievement)
        ? !empty($achievement['is_unlocked'])
        : (!empty($achievement['awarded_at']) || !empty($achievement['award_id']));
    $status = $isUnlocked ? (string) t('achievements.unlocked') : (string) t('achievements.locked');
    $date = !empty($achievement['awarded_at']) ? format_date_eu((string) $achievement['awarded_at']) : '';
    $progressPct = is_numeric($achievement['progress_pct'] ?? null) ? (float) $achievement['progress_pct'] : null;
    $progressText = trim((string) ($achievement['progress_text'] ?? ''));

    $attrs = [
        'data-achievement-modal' => '1',
        'data-achievement-name' => (string) ($achievement['name'] ?? ''),
        'data-achievement-description' => (string) ($achievement['description'] ?? ''),
        'data-achievement-reward' => (string) ($achievement['reward_text'] ?? ''),
        'data-achievement-status' => $status,
        'data-achievement-date' => $date,
        'data-achievement-progress' => $progressPct !== null ? '1' : '',
        'data-achievement-progress-pct' => $progressPct !== null ? (string) max(0, min(100, $progressPct)) : '',
        'data-achievement-progress-text' => $progressText,
    ];

    $out = ' role="button" tabindex="0"';
    foreach ($attrs as $name => $value) {
        $out .= ' ' . $name . '="' . e($value) . '"';
    }

    return $out;
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

/**
 * Render a reusable three-dot (kebab) dropdown menu built on native <details>.
 *
 * Each item is an associative array:
 *   - 'label'  (string, required)
 *   - 'href'   (string) renders an <a>; omit for a <button>
 *   - 'attrs'  (array<string,string>) extra attributes (e.g. data-*)
 *   - 'danger' (bool) styles the item as destructive
 *   - 'type'   (string) button type, defaults to 'button'
 *   - 'children' (array) renders a drill-down submenu instead of an action
 *
 * $opts:
 *   - 'label'         (string) accessible label for the trigger
 *   - 'align'         (string) 'end' (default) or 'start'
 *   - 'class'         (string) extra classes on the <details> wrapper
 *   - 'trigger_class' (string) extra classes on the <summary>
 *   - 'title'         (string) heading shown inside the popover/sheet
 *
 * @param array<int,array<string,mixed>> $items
 * @param array<string,mixed> $opts
 */
function render_kebab_menu(array $items, array $opts = []): string
{
    $items = array_values(array_filter($items, static fn ($i) => is_array($i) && ($i['label'] ?? '') !== ''));
    if ($items === []) {
        return '';
    }

    $triggerLabel = (string) ($opts['label'] ?? (function_exists('t') ? t('common.actions') : 'Actions'));
    $align = ($opts['align'] ?? 'end') === 'start' ? 'start' : 'end';
    $wrapClass = trim('kebab-menu ' . (string) ($opts['class'] ?? ''));
    $triggerClass = trim('kebab-menu-trigger btn btn-ghost small ' . (string) ($opts['trigger_class'] ?? ''));
    $panelTitle = trim((string) ($opts['title'] ?? $triggerLabel));
    $panelTitle = $panelTitle !== '' ? $panelTitle : $triggerLabel;
    $backLabel = function_exists('t') ? t('common.back') : 'Back';
    $closeLabel = function_exists('t') ? t('menu.close') : 'Close menu';
    static $menuCounter = 0;
    $menuCounter++;
    $menuId = 'kebab-menu-' . $menuCounter;

    $renderAction = static function (array $item): string {
        $label = (string) ($item['label'] ?? '');
        $danger = !empty($item['danger']);
        $itemClass = 'kebab-menu-item' . ($danger ? ' is-danger' : '');
        $attrParts = '';
        foreach ((array) ($item['attrs'] ?? []) as $attrKey => $attrVal) {
            $attrParts .= ' ' . e((string) $attrKey) . '="' . e((string) $attrVal) . '"';
        }
        $description = trim((string) ($item['description'] ?? ''));
        $content = '<span class="kebab-menu-item-copy"><span>' . e($label) . '</span>';
        if ($description !== '') {
            $content .= '<small>' . e($description) . '</small>';
        }
        $content .= '</span>';

        if (($item['href'] ?? '') !== '') {
            return '<a class="' . e($itemClass) . '" role="menuitem" href="' . e((string) $item['href']) . '"' . $attrParts . '>' . $content . '</a>';
        }
        $type = (string) ($item['type'] ?? 'button');

        return '<button class="' . e($itemClass) . '" role="menuitem" type="' . e($type) . '"' . $attrParts . '>' . $content . '</button>';
    };

    $renderHeader = static function (string $title, bool $withBack) use ($backLabel, $closeLabel): string {
        $leading = $withBack
            ? '<button class="kebab-menu-sheet-back" type="button" data-menu-back aria-label="' . e($backLabel) . '"><span aria-hidden="true">&larr;</span></button>'
            : '<span class="kebab-menu-sheet-spacer" aria-hidden="true"></span>';

        return '<div class="kebab-menu-sheet-head" role="presentation">'
            . $leading
            . '<strong>' . e($title) . '</strong>'
            . '<button class="kebab-menu-sheet-close" type="button" data-menu-close aria-label="' . e($closeLabel) . '">&times;</button>'
            . '</div>';
    };

    $html = '<details id="' . e($menuId) . '" class="' . e($wrapClass) . '" data-kebab-menu data-align="' . e($align) . '">';
    $html .= '<summary class="' . e($triggerClass) . '" aria-label="' . e($triggerLabel) . '" aria-haspopup="menu" aria-expanded="false">';
    $html .= '<span class="kebab-menu-dots" aria-hidden="true"><span></span><span></span><span></span></span>';
    $html .= '</summary>';
    $html .= '<div class="kebab-menu-panel" role="menu" aria-label="' . e($panelTitle) . '" data-menu-stack>';
    $html .= '<div class="kebab-menu-view" data-menu-view="main">' . $renderHeader($panelTitle, false);

    $submenuViews = '';
    foreach ($items as $index => $item) {
        $children = array_values(array_filter((array) ($item['children'] ?? []), static fn ($child) => is_array($child) && ($child['label'] ?? '') !== ''));
        if ($children === []) {
            $html .= $renderAction($item);
            continue;
        }
        $submenuKey = 'submenu-' . $index;
        $submenuLabel = (string) $item['label'];
        $html .= '<button class="kebab-menu-item has-submenu" role="menuitem" type="button" data-menu-open="' . e($submenuKey) . '" aria-haspopup="menu">'
            . '<span class="kebab-menu-item-copy"><span>' . e($submenuLabel) . '</span></span>'
            . '<span class="kebab-menu-chevron" aria-hidden="true">&rsaquo;</span></button>';
        $submenuViews .= '<div class="kebab-menu-view" data-menu-view="' . e($submenuKey) . '" hidden>' . $renderHeader($submenuLabel, true);
        foreach ($children as $child) {
            $submenuViews .= $renderAction($child);
        }
        $submenuViews .= '</div>';
    }
    $html .= '</div>' . $submenuViews . '</div></details>';

    return $html;
}

/**
 * Render a compact "status summary" card (used for Duels / Competitions
 * overviews on Profile and Dashboard). Reusable so both pages stay in sync.
 *
 * @param array<int,array{label:string,value:int|string,tone?:string}> $stats
 */
function render_status_summary_card(string $eyebrow, string $title, array $stats, string $href, string $linkLabel, string $extraClass = ''): string
{
    $wrapClass = trim('panel status-summary-card ' . $extraClass);
    $html = '<article class="' . e($wrapClass) . '">';
    $html .= '<div class="panel-head"><div>';
    $html .= '<p class="eyebrow">' . e($eyebrow) . '</p>';
    $html .= '<h2>' . e($title) . '</h2>';
    $html .= '</div>';
    if ($href !== '') {
        $html .= '<a class="btn btn-ghost small" href="' . e($href) . '">' . e($linkLabel) . '</a>';
    }
    $html .= '</div>';
    $html .= '<div class="status-summary-stats">';
    foreach ($stats as $stat) {
        $tone = (string) ($stat['tone'] ?? '');
        $statClass = 'status-summary-stat' . ($tone !== '' ? ' is-' . preg_replace('/[^a-z0-9\-]/', '', $tone) : '');
        $html .= '<div class="' . e($statClass) . '">';
        $html .= '<span class="status-summary-value">' . e((string) ($stat['value'] ?? '0')) . '</span>';
        $html .= '<span class="status-summary-label">' . e((string) ($stat['label'] ?? '')) . '</span>';
        $html .= '</div>';
    }
    $html .= '</div></article>';

    return $html;
}

/**
 * Relative "time ago" label for an ISO datetime (localized, coarse buckets).
 */
function human_time_ago(string $isoDatetime): string
{
    $ts = strtotime($isoDatetime);
    if ($ts === false) {
        return '';
    }
    $diff = time() - $ts;
    if ($diff < 0) {
        $diff = 0;
    }
    if ($diff < 60) {
        return t('time.just_now');
    }
    if ($diff < 3600) {
        return t('time.minutes_ago', ['n' => (int) floor($diff / 60)]);
    }
    if ($diff < 86400) {
        return t('time.hours_ago', ['n' => (int) floor($diff / 3600)]);
    }
    if ($diff < 604800) {
        return t('time.days_ago', ['n' => (int) floor($diff / 86400)]);
    }

    return function_exists('format_date_eu') ? format_date_eu(date('Y-m-d', $ts)) : date('Y-m-d', $ts);
}

/**
 * Turn a raw audit-log row into a human activity item for end users.
 * Returns null for noise/technical events that shouldn't surface to users.
 *
 * @param array<string,mixed> $item
 * @return array{icon:string,text:string,when:string}|null
 */
function humanize_activity_item(array $item, int $viewerId): ?array
{
    $action = (string) ($item['action'] ?? '');
    $entity = (string) ($item['entity_type'] ?? '');
    $actorId = (int) ($item['actor_user_id'] ?? 0);
    $isSelf = $actorId === $viewerId;
    $actorName = trim((string) ($item['actor_name'] ?? ''));
    // Locale-aware subject so the perfect-tense templates agree ("You created"
    // / "Roberto created"; "Has creado" / "Roberto ha creado").
    $who = $isSelf
        ? t('activity.subject_self')
        : t('activity.subject_other', ['name' => $actorName !== '' ? $actorName : t('common.user')]);

    // Security / pure-preference noise: never surface to end users.
    static $hidden = [
        'password_changed', 'user_preferences_updated', 'dashboard_preferences_updated',
        'dashboard_view', 'app_setting_updated', 'challenge_settings_updated',
    ];
    if (in_array($action, $hidden, true)) {
        return null;
    }

    // action prefix => [icon, i18n key]. Keys receive {who}.
    $map = [
        'goal_created' => ['target', 'activity.goal_created'],
        'goal_completed' => ['trophy', 'activity.goal_completed'],
        'goal_updated' => ['target', 'activity.goal_updated'],
        'goal_deleted' => ['target', 'activity.goal_deleted'],
        'achievement' => ['medal', 'activity.achievement'],
        'daily_log' => ['check', 'activity.logged_day'],
        'log_' => ['check', 'activity.logged_day'],
        'photo' => ['image', 'activity.photo_added'],
        'profile_tagline_updated' => ['user', 'activity.profile_updated'],
        'team_join_request' => ['users', 'activity.team_join'],
        'team_membership' => ['users', 'activity.team_join'],
        'duel' => ['sword', 'activity.duel'],
        'workout_type_created' => ['dumbbell', 'activity.workout_added'],
    ];

    $icon = 'spark';
    $key = '';
    foreach ($map as $prefix => $meta) {
        if ($action === $prefix || str_starts_with($action, $prefix)) {
            [$icon, $key] = $meta;
            break;
        }
    }

    if ($key === '') {
        // Fallback: reuse the stored human summary but strip trailing period and
        // any bracketed/technical id noise; skip if it looks like raw JSON/ids.
        $summary = trim((string) ($item['summary'] ?? ''));
        if ($summary === '' || str_contains($summary, '{') || str_contains($summary, '#')) {
            return null;
        }
        $text = rtrim($summary, '.');
    } else {
        $text = t($key, ['who' => $who]);
    }

    return [
        'icon' => $icon,
        'text' => $text,
        'when' => human_time_ago((string) ($item['created_at'] ?? '')),
    ];
}

/** Small inline SVG for activity icons. */
function activity_icon_svg(string $name): string
{
    $paths = [
        'target' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/>',
        'trophy' => '<path d="M8 21h8M12 17v4M7 4h10v4a5 5 0 0 1-10 0Z"/><path d="M5 5H3v2a3 3 0 0 0 3 3M19 5h2v2a3 3 0 0 1-3 3"/>',
        'medal' => '<circle cx="12" cy="14" r="6"/><path d="M9 8 7 3h10l-2 5"/>',
        'check' => '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
        'image' => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="1.5"/><path d="m21 16-5-5L5 20"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2 21a7 7 0 0 1 14 0"/><path d="M17 11a3.5 3.5 0 0 0 0-6M22 21a6 6 0 0 0-4-5.6"/>',
        'sword' => '<path d="M14.5 4 20 4v5.5L9 20.5 3.5 15z"/><path d="m13 11 2 2M4 21l3-3"/>',
        'dumbbell' => '<path d="M6 7v10M18 7v10M3 9v6M21 9v6M6 12h12"/>',
        'bolt' => '<path d="m13 2-9 12h7l-1 8 9-12h-7z"/>',
        'flame' => '<path d="M12 22c4 0 7-2.8 7-7 0-3-1.7-5.7-5-8.5.2 2-1 3.3-2 4.1C11.7 7.8 10 5 7.7 3 8 7 5 9.3 5 14.8 5 19 8 22 12 22Z"/><path d="M9.5 18.5c-1.2-2 .1-3.7 2.5-5.8-.1 1.8.8 2.7 1.5 3.5.7.8.5 1.8 0 2.5"/>',
        'run' => '<circle cx="15" cy="4" r="2"/><path d="m13 8-3 4 4 2 2 6M10 12l-4-2M14 14l4-4 3 2M9 20l3-4"/>',
        'cycle' => '<circle cx="6" cy="17" r="4"/><circle cx="18" cy="17" r="4"/><path d="m6 17 4-8h4l4 8M9 12h7M10 9 8 6h3"/>',
        'shield' => '<path d="M12 3 20 6v6c0 5-3.4 8-8 9-4.6-1-8-4-8-9V6z"/><path d="m9 12 2 2 4-5"/>',
        'sliders' => '<path d="M4 6h10M18 6h2M4 12h2M10 12h10M4 18h7M15 18h5"/><circle cx="16" cy="6" r="2"/><circle cx="8" cy="12" r="2"/><circle cx="13" cy="18" r="2"/>',
        'link' => '<path d="M10 13a5 5 0 0 0 7.5.5l2-2a5 5 0 0 0-7-7l-1.2 1.2"/><path d="M14 11a5 5 0 0 0-7.5-.5l-2 2a5 5 0 0 0 7 7l1.2-1.2"/>',
        'bell' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/>',
        'spark' => '<path d="M12 3v4M12 17v4M3 12h4M17 12h4M6 6l2.5 2.5M15.5 15.5 18 18M18 6l-2.5 2.5M8.5 15.5 6 18"/>',
    ];
    $p = $paths[$name] ?? $paths['spark'];

    return '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $p . '</svg>';
}

/**
 * Notifications split in two: a handful carry a pending decision, the rest are news.
 * Returning null means "nothing to do here" - which is what stops an informational
 * notification from showing anything that could be mistaken for an accept button.
 */
function notification_pending_action(string $kind): ?string
{
    return match ($kind) {
        'friend_request' => 'notifications.cta_friend_request',
        'duel_challenge' => 'notifications.cta_duel',
        'comp_invite' => 'notifications.cta_competition',
        'strike_review_request' => 'notifications.cta_review',
        default => null,
    };
}

/** Icon per notification kind, so the list is scannable without reading every title. */
function notification_icon(string $kind): string
{
    return match ($kind) {
        'friend_request', 'friend_accepted' => 'users',
        'duel_challenge', 'duel_accepted', 'duel_finished' => 'sword',
        'comp_invite', 'comp_accepted', 'comp_finished', 'squad_added' => 'trophy',
        'team_goal_completed' => 'target',
        'strike_review_request', 'strike_review_resolved' => 'check',
        default => 'spark',
    };
}
