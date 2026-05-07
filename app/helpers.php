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
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
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
