<?php

declare(strict_types=1);

const PROFILE_CUSTOM_WIDGET_LIMIT = 8;
const PROFILE_CUSTOM_WIDGET_ACHIEVEMENT_LIMIT = 6;

function profile_custom_widgets_ensure_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS profile_custom_widgets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            widget_type TEXT NOT NULL DEFAULT "media",
            title TEXT NOT NULL,
            body TEXT NOT NULL DEFAULT "",
            media_path TEXT,
            media_mime TEXT,
            external_url TEXT,
            link_url TEXT,
            accent_color TEXT NOT NULL DEFAULT "#7c3aed",
            achievement_ids_json TEXT,
            sort_order INTEGER NOT NULL DEFAULT 0,
            is_visible INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_profile_custom_widgets_user_order ON profile_custom_widgets(user_id, sort_order, id)');
}

function profile_custom_widgets_for_user(PDO $pdo, int $userId, bool $includeHidden = false): array
{
    if ($userId <= 0) {
        return [];
    }

    $sql = 'SELECT * FROM profile_custom_widgets WHERE user_id = :user_id';
    if (!$includeHidden) {
        $sql .= ' AND is_visible = 1';
    }
    $sql .= ' ORDER BY sort_order ASC, id ASC';

    return db_fetch_all($pdo, $sql, [':user_id' => $userId]);
}

function profile_custom_widget_for_owner(PDO $pdo, int $widgetId, int $userId): ?array
{
    if ($widgetId <= 0 || $userId <= 0) {
        return null;
    }

    return db_fetch_one(
        $pdo,
        'SELECT * FROM profile_custom_widgets WHERE id = :id AND user_id = :user_id LIMIT 1',
        [':id' => $widgetId, ':user_id' => $userId]
    );
}

function profile_custom_widget_key(int $widgetId): string
{
    return 'custom_widget_' . max(0, $widgetId);
}

function profile_custom_widget_type(string $value): string
{
    $value = strtolower(trim($value));
    return in_array($value, ['media', 'text', 'achievements'], true) ? $value : 'media';
}

function profile_custom_widget_accent(string $value): string
{
    $value = strtolower(trim($value));
    return preg_match('/^#[0-9a-f]{6}$/', $value) === 1 ? $value : '#7c3aed';
}

function profile_custom_widget_url(string $value): string
{
    $value = trim($value);
    if ($value === '' || strlen($value) > 1000 || filter_var($value, FILTER_VALIDATE_URL) === false) {
        return '';
    }

    $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true) ? $value : '';
}

function profile_custom_widget_achievement_ids(mixed $value): array
{
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        $value = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($value)) {
        return [];
    }

    $ids = [];
    foreach ($value as $id) {
        $id = (int) $id;
        if ($id > 0 && !in_array($id, $ids, true)) {
            $ids[] = $id;
        }
        if (count($ids) >= PROFILE_CUSTOM_WIDGET_ACHIEVEMENT_LIMIT) {
            break;
        }
    }

    return $ids;
}

function save_profile_custom_widget_media(array $config, array $file, int $userId): array
{
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'video/quicktime' => 'mov',
    ];
    // Keep this aligned with the bundled PHP runtime upload_max_filesize.
    $maxBytes = 20 * 1024 * 1024;
    $path = save_uploaded_image(
        $config,
        $file,
        'profile_widgets/user_' . $userId,
        'showcase',
        [
            'allowed_mimes' => $allowed,
            'max_bytes' => $maxBytes,
            'invalid_format_message' => t('profile.widget_media_invalid'),
        ]
    );
    $absolute = resolve_media_storage_path($config, $path);
    $mime = $absolute !== null ? detect_uploaded_image_mime($absolute) : '';
    if (!isset($allowed[$mime])) {
        if ($absolute !== null && is_file($absolute)) {
            @unlink($absolute);
        }
        throw new RuntimeException(t('profile.widget_media_invalid'));
    }

    return ['path' => $path, 'mime' => $mime];
}

function profile_custom_widget_video_embed(string $url): string
{
    $url = profile_custom_widget_url($url);
    if ($url === '') {
        return '';
    }

    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $host = preg_replace('/^www\./', '', $host) ?? $host;
    $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
    $query = [];
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $videoId = '';
    if ($host === 'youtu.be') {
        $videoId = explode('/', $path)[0] ?? '';
    } elseif (in_array($host, ['youtube.com', 'm.youtube.com', 'youtube-nocookie.com'], true)) {
        if (str_starts_with($path, 'shorts/') || str_starts_with($path, 'embed/')) {
            $videoId = explode('/', $path)[1] ?? '';
        } else {
            $videoId = (string) ($query['v'] ?? '');
        }
    }

    if (preg_match('/^[A-Za-z0-9_-]{6,20}$/', $videoId) !== 1) {
        return '';
    }

    return 'https://www.youtube-nocookie.com/embed/' . rawurlencode($videoId);
}
