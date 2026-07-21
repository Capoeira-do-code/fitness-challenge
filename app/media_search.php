<?php

declare(strict_types=1);

/**
 * Server-side media search for the exercise editor.
 *
 * Provider keys never reach the browser. Image results receive a short-lived,
 * user-bound token so the import endpoint never accepts an arbitrary URL.
 */

function media_search_text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function media_search_text_slice(string $value, int $length): string
{
    return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
}

function media_search_enabled(PDO $pdo): bool
{
    $value = strtolower(trim((string) (app_setting($pdo, 'workout_media_search_enabled', '0') ?? '0')));

    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function media_search_set_enabled(PDO $pdo, bool $enabled, int $actorUserId): void
{
    set_app_setting($pdo, 'workout_media_search_enabled', $enabled ? '1' : '0', $actorUserId);
}

function media_search_provider_available(array $config, string $type): bool
{
    if ($type === 'image') {
        return trim((string) ($config['media_search_google_api_key'] ?? '')) !== ''
            && trim((string) ($config['media_search_google_cx'] ?? '')) !== '';
    }

    return $type === 'video'
        && trim((string) ($config['media_search_youtube_api_key'] ?? '')) !== '';
}

/** @return array<string,mixed> */
function media_search_http_json(string $url): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException(t('workouts.media_search_unavailable'));
    }

    $lastStatus = 0;
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $body = '';
        $tooLarge = false;
        $curl = curl_init($url);
        if ($curl === false) {
            break;
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'FitnessChallenge/1.0 media-search',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > 1572864) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($curl);
        $lastStatus = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        if ($tooLarge) {
            throw new RuntimeException(t('workouts.media_search_provider_error'));
        }
        if ($ok !== false && $lastStatus >= 200 && $lastStatus < 300) {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                return $decoded;
            }
            throw new RuntimeException(t('workouts.media_search_provider_error'));
        }
        if ($attempt === 0 && ($lastStatus === 429 || $lastStatus >= 500)) {
            usleep(180000);
            continue;
        }
        break;
    }

    throw new RuntimeException($lastStatus === 429
        ? t('workouts.media_search_rate_limited')
        : t('workouts.media_search_provider_error'));
}

/** @return array<int,array<string,mixed>> */
function media_search_normalize_google_images(array $payload, int $userId): array
{
    $results = [];
    foreach ((array) ($payload['items'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $imageUrl = trim((string) ($item['link'] ?? ''));
        $thumbnail = trim((string) ($item['image']['thumbnailLink'] ?? ''));
        if (!str_starts_with($imageUrl, 'https://') || !str_starts_with($thumbnail, 'https://')) {
            continue;
        }
        $title = trim(strip_tags((string) ($item['title'] ?? '')));
        $sourceUrl = trim((string) ($item['image']['contextLink'] ?? ''));
        if (!str_starts_with($sourceUrl, 'https://')) {
            $sourceUrl = '';
        }
        $selection = media_search_store_image_selection($userId, [
            'url' => $imageUrl,
            'title' => $title,
            'source_url' => $sourceUrl,
        ]);
        $results[] = [
            'id' => $selection,
            'title' => $title !== '' ? media_search_text_slice($title, 160) : t('workouts.media_search_untitled'),
            'thumbnail' => $thumbnail,
            'source_name' => media_search_text_slice(trim((string) ($item['displayLink'] ?? '')), 100),
            'source_url' => $sourceUrl,
            'width' => max(0, (int) ($item['image']['width'] ?? 0)),
            'height' => max(0, (int) ($item['image']['height'] ?? 0)),
        ];
        if (count($results) >= 8) {
            break;
        }
    }

    return $results;
}

/** @return array<int,array<string,mixed>> */
function media_search_normalize_youtube(array $payload): array
{
    $results = [];
    foreach ((array) ($payload['items'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $videoId = trim((string) ($item['id']['videoId'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId)) {
            continue;
        }
        $snippet = is_array($item['snippet'] ?? null) ? $item['snippet'] : [];
        $thumbnail = trim((string) ($snippet['thumbnails']['medium']['url'] ?? $snippet['thumbnails']['default']['url'] ?? ''));
        if (!str_starts_with($thumbnail, 'https://')) {
            $thumbnail = 'https://i.ytimg.com/vi/' . rawurlencode($videoId) . '/mqdefault.jpg';
        }
        $title = html_entity_decode(strip_tags((string) ($snippet['title'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $channel = html_entity_decode(strip_tags((string) ($snippet['channelTitle'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $results[] = [
            'id' => $videoId,
            'title' => media_search_text_slice(trim($title), 160),
            'thumbnail' => $thumbnail,
            'channel' => media_search_text_slice(trim($channel), 100),
            'published_at' => trim((string) ($snippet['publishedAt'] ?? '')),
            'url' => 'https://www.youtube.com/watch?v=' . rawurlencode($videoId),
        ];
        if (count($results) >= 8) {
            break;
        }
    }

    return $results;
}

/** @return array<int,array<string,mixed>> */
function media_search_query(array $config, string $type, string $query, int $userId, string $locale = 'en', ?callable $httpGet = null): array
{
    $type = in_array($type, ['image', 'video'], true) ? $type : '';
    $query = trim(preg_replace('/\s+/u', ' ', $query) ?? '');
    if ($type === '' || media_search_text_length($query) < 2 || media_search_text_length($query) > 80) {
        throw new InvalidArgumentException(t('workouts.media_search_query_invalid'));
    }
    if (!media_search_provider_available($config, $type)) {
        throw new RuntimeException(t('workouts.media_search_not_configured'));
    }

    $cacheKey = hash('sha256', $type . '|' . strtolower($locale) . '|' . strtolower($query));
    $cache = is_array($_SESSION['workout_media_search_cache'] ?? null) ? $_SESSION['workout_media_search_cache'] : [];
    $cached = $cache[$cacheKey] ?? null;
    if (is_array($cached) && (int) ($cached['expires_at'] ?? 0) >= time() && is_array($cached['payload'] ?? null)) {
        $payload = $cached['payload'];
    } else {
        $language = in_array($locale, ['en', 'es', 'it'], true) ? $locale : 'en';
        if ($type === 'image') {
            $url = 'https://customsearch.googleapis.com/customsearch/v1?' . http_build_query([
                'key' => (string) $config['media_search_google_api_key'],
                'cx' => (string) $config['media_search_google_cx'],
                'q' => $query,
                'searchType' => 'image',
                'safe' => 'active',
                'num' => 8,
                'hl' => $language,
                'imgType' => 'photo',
                'fields' => 'items(title,link,displayLink,image(contextLink,thumbnailLink,width,height))',
            ], '', '&', PHP_QUERY_RFC3986);
        } else {
            $url = 'https://www.googleapis.com/youtube/v3/search?' . http_build_query([
                'key' => (string) $config['media_search_youtube_api_key'],
                'part' => 'snippet',
                'type' => 'video',
                'maxResults' => 8,
                'safeSearch' => 'strict',
                'relevanceLanguage' => $language,
                'videoEmbeddable' => 'true',
                'videoSyndicated' => 'true',
                'q' => $query,
                'fields' => 'items(id/videoId,snippet(title,channelTitle,publishedAt,thumbnails/default/url,thumbnails/medium/url))',
            ], '', '&', PHP_QUERY_RFC3986);
        }
        $payload = ($httpGet ?? 'media_search_http_json')($url);
        $cache[$cacheKey] = ['expires_at' => time() + 300, 'payload' => $payload];
        if (count($cache) > 12) {
            uasort($cache, static fn(array $a, array $b): int => (int) ($a['expires_at'] ?? 0) <=> (int) ($b['expires_at'] ?? 0));
            $cache = array_slice($cache, -12, null, true);
        }
        $_SESSION['workout_media_search_cache'] = $cache;
    }

    return $type === 'image'
        ? media_search_normalize_google_images($payload, $userId)
        : media_search_normalize_youtube($payload);
}

function media_search_store_image_selection(int $userId, array $item): string
{
    $selections = is_array($_SESSION['workout_media_search_selections'] ?? null)
        ? $_SESSION['workout_media_search_selections']
        : [];
    foreach ($selections as $key => $selection) {
        if (!is_array($selection) || (int) ($selection['expires_at'] ?? 0) < time()) {
            unset($selections[$key]);
        }
    }
    if (count($selections) >= 24) {
        uasort($selections, static fn(array $a, array $b): int => (int) ($a['expires_at'] ?? 0) <=> (int) ($b['expires_at'] ?? 0));
        $selections = array_slice($selections, -20, null, true);
    }
    $token = bin2hex(random_bytes(18));
    $selections[$token] = [
        'user_id' => $userId,
        'url' => trim((string) ($item['url'] ?? '')),
        'title' => media_search_text_slice(trim((string) ($item['title'] ?? '')), 160),
        'source_url' => trim((string) ($item['source_url'] ?? '')),
        'expires_at' => time() + 600,
    ];
    $_SESSION['workout_media_search_selections'] = $selections;

    return $token;
}

/** @return array<string,mixed> */
function media_search_get_image_selection(int $userId, string $token): array
{
    if (!preg_match('/^[a-f0-9]{36}$/', $token)) {
        throw new InvalidArgumentException(t('workouts.media_search_selection_expired'));
    }
    $selection = $_SESSION['workout_media_search_selections'][$token] ?? null;
    if (!is_array($selection)
        || (int) ($selection['user_id'] ?? 0) !== $userId
        || (int) ($selection['expires_at'] ?? 0) < time()
    ) {
        unset($_SESSION['workout_media_search_selections'][$token]);
        throw new InvalidArgumentException(t('workouts.media_search_selection_expired'));
    }

    return $selection;
}

function media_search_forget_image_selection(string $token): void
{
    unset($_SESSION['workout_media_search_selections'][$token]);
}

function media_search_ip_is_public(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

/** @return array{url:string,host:string,resolve:string} */
function media_search_validate_public_image_url(string $url): array
{
    $parts = parse_url(trim($url));
    if (!is_array($parts)
        || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
        || trim((string) ($parts['host'] ?? '')) === ''
        || isset($parts['user'])
        || isset($parts['pass'])
        || (isset($parts['port']) && (int) $parts['port'] !== 443)
    ) {
        throw new RuntimeException(t('workouts.media_search_image_invalid'));
    }
    $host = strtolower(rtrim((string) $parts['host'], '.'));
    if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
        throw new RuntimeException(t('workouts.media_search_image_invalid'));
    }
    if (filter_var($host, FILTER_VALIDATE_IP) === false
        && (strlen($host) > 253 || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host))
    ) {
        throw new RuntimeException(t('workouts.media_search_image_invalid'));
    }

    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        $ips[] = $host;
    } elseif (function_exists('dns_get_record')) {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        foreach (is_array($records) ? $records : [] as $record) {
            $ip = trim((string) ($record['ip'] ?? $record['ipv6'] ?? ''));
            if ($ip !== '') {
                $ips[] = $ip;
            }
        }
    }
    if ($ips === []) {
        foreach ((array) @gethostbynamel($host) as $ip) {
            if (is_string($ip) && $ip !== '') {
                $ips[] = $ip;
            }
        }
    }
    $ips = array_values(array_unique($ips));
    if ($ips === [] || array_filter($ips, static fn(string $ip): bool => !media_search_ip_is_public($ip)) !== []) {
        throw new RuntimeException(t('workouts.media_search_image_invalid'));
    }
    $selectedIp = $ips[0];
    $resolveIp = str_contains($selectedIp, ':') ? '[' . $selectedIp . ']' : $selectedIp;

    return ['url' => $url, 'host' => $host, 'resolve' => $host . ':443:' . $resolveIp];
}

function media_search_redirect_url(string $baseUrl, string $location): string
{
    $location = trim($location);
    if (str_starts_with($location, 'https://')) {
        return $location;
    }
    $base = parse_url($baseUrl);
    if (!is_array($base) || trim((string) ($base['host'] ?? '')) === '') {
        return '';
    }
    if (str_starts_with($location, '//')) {
        return 'https:' . $location;
    }
    $origin = 'https://' . $base['host'] . (isset($base['port']) ? ':' . (int) $base['port'] : '');
    if (str_starts_with($location, '/')) {
        return $origin . $location;
    }
    $path = (string) ($base['path'] ?? '/');
    $directory = rtrim(str_replace('\\', '/', dirname($path)), '/');

    return $origin . ($directory === '' ? '' : $directory) . '/' . $location;
}

/** @return array{bytes:string,mime:string,extension:string,width:int,height:int,title:string} */
function media_search_download_selected_image(array $config, int $userId, string $token): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException(t('workouts.media_search_unavailable'));
    }
    $selection = media_search_get_image_selection($userId, $token);
    $url = (string) ($selection['url'] ?? '');
    $configuredMax = (int) ($config['media_search_image_max_bytes'] ?? 8388608);
    $photoMax = (int) ($config['photo_upload_max_bytes'] ?? 15728640);
    if ($configuredMax <= 0) {
        $configuredMax = 8388608;
    }
    if ($photoMax <= 0) {
        $photoMax = 15728640;
    }
    $maxBytes = max(262144, min(15728640, $configuredMax, $photoMax));

    for ($redirect = 0; $redirect <= 3; $redirect++) {
        $target = media_search_validate_public_image_url($url);
        $body = '';
        $location = '';
        $tooLarge = false;
        $curl = curl_init($target['url']);
        if ($curl === false) {
            throw new RuntimeException(t('workouts.media_search_image_failed'));
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 18,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RESOLVE => [$target['resolve']],
            CURLOPT_USERAGENT => 'FitnessChallenge/1.0 image-import',
            CURLOPT_HTTPHEADER => ['Accept: image/avif,image/webp,image/png,image/jpeg;q=0.9', 'Accept-Encoding: identity'],
            CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$location): int {
                if (stripos($header, 'Location:') === 0) {
                    $location = trim(substr($header, 9));
                }
                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge, $maxBytes): int {
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $contentType = strtolower(trim((string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE)));

        if ($tooLarge) {
            throw new RuntimeException(t('workouts.media_search_image_too_large', ['max' => format_upload_size($maxBytes)]));
        }
        if ($status >= 300 && $status < 400 && $location !== '' && $redirect < 3) {
            $url = media_search_redirect_url($url, $location);
            if ($url === '') {
                break;
            }
            continue;
        }
        if ($ok === false || $status < 200 || $status >= 300 || $body === '') {
            break;
        }

        $info = @getimagesizefromstring($body);
        $mime = is_array($info) ? strtolower((string) ($info['mime'] ?? '')) : '';
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($allowed[$mime]) || ($contentType !== '' && !str_starts_with($contentType, 'image/'))) {
            throw new RuntimeException(t('workouts.media_search_image_invalid'));
        }
        $width = max(0, (int) ($info[0] ?? 0));
        $height = max(0, (int) ($info[1] ?? 0));
        if ($width < 80 || $height < 80 || ($width * $height) > 40000000) {
            throw new RuntimeException(t('workouts.media_search_image_invalid'));
        }
        media_search_forget_image_selection($token);

        return [
            'bytes' => $body,
            'mime' => $mime,
            'extension' => $allowed[$mime],
            'width' => $width,
            'height' => $height,
            'title' => media_search_text_slice(trim((string) ($selection['title'] ?? 'exercise')), 100),
        ];
    }

    throw new RuntimeException(t('workouts.media_search_image_failed'));
}
